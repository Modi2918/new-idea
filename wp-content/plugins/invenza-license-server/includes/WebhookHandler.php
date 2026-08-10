<?php
namespace InvenzaLicenseServer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles payment and refund webhooks from Stripe and PayPal.
 */
class WebhookHandler {


	/**
	 * Verify Stripe webhook signature.
	 *
	 * @param string $payload Raw request body string.
	 * @param string $signature_header Stripe-Signature header.
	 * @return bool
	 */
	private static function verify_stripe_signature( string $payload, string $signature_header ): bool {
		if ( empty( $signature_header ) ) {
			return false;
		}

		$webhook_secret = defined( 'FCAC_STRIPE_WEBHOOK_SECRET' ) && '' !== FCAC_STRIPE_WEBHOOK_SECRET
			? FCAC_STRIPE_WEBHOOK_SECRET
			: get_option( 'INVENZA_SERVER_stripe_webhook_secret', '' );

		if ( empty( $webhook_secret ) ) {
			error_log( '[FCAC License Server] Stripe Webhook Secret is not configured. Webhook rejected.' );
			return false;
		}

		// Extract timestamp and signature.
		$pairs = explode( ',', $signature_header );
		$timestamp = '';
		$signatures = array();

		foreach ( $pairs as $pair ) {
			$parts = explode( '=', $pair, 2 );
			if ( count( $parts ) === 2 ) {
				$key = trim( $parts[0] );
				$val = trim( $parts[1] );
				if ( 't' === $key ) {
					$timestamp = $val;
				} elseif ( 'v1' === $key ) {
					$signatures[] = $val;
				}
			}
		}

		if ( empty( $timestamp ) || empty( $signatures ) ) {
			return false;
		}

		// Prevent replay attacks (5 minutes window).
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return false;
		}

		$signed_payload = $timestamp . '.' . $payload;
		$expected       = hash_hmac( 'sha256', $signed_payload, $webhook_secret );

		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Endpoint: POST /wp-json/fcac-server/v1/webhook/payment-success
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function handle_payment_success( \WP_REST_Request $request ): \WP_REST_Response {
		$raw_body = $request->get_body();
		$data     = json_decode( $raw_body, true );
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Malformed JSON payload.' ), 400 );
		}

		$payment_id = sanitize_text_field( $data['razorpay_payment_id'] ?? '' );
		$order_id   = sanitize_text_field( $data['razorpay_order_id'] ?? '' );
		$signature  = sanitize_text_field( $data['razorpay_signature'] ?? '' );
		$key_secret = \InvenzaLicenseServer\Api::get_env_val( 'RAZORPAY_KEY_SECRET', 'INVENZA_SERVER_razorpay_key_secret' );

		if ( empty( $payment_id ) || empty( $order_id ) || empty( $signature ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Missing Razorpay parameters.' ), 400 );
		}

		$allow_mock = defined( 'FCAC_TEST_SANDBOX_BYPASS' ) && true === FCAC_TEST_SANDBOX_BYPASS;

		if ( ! $allow_mock || strpos( $order_id, 'order_rzp_mock' ) !== 0 ) {
			try {
				$api = new \Razorpay\Api\Api( \InvenzaLicenseServer\Api::get_env_val( 'RAZORPAY_KEY_ID', 'INVENZA_SERVER_razorpay_key_id' ), $key_secret );
				$api->utility->verifyPaymentSignature( array(
					'razorpay_signature'  => $signature,
					'razorpay_payment_id' => $payment_id,
					'razorpay_order_id'   => $order_id,
				) );
			} catch ( \Exception $e ) {
				return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Invalid Razorpay signature.' ), 400 );
			}
		}

		$email          = sanitize_email( $data['customer_email'] ?? '' );
		$name           = sanitize_text_field( $data['customer_name'] ?? 'Premium Customer' );
		$product        = sanitize_text_field( $data['product'] ?? 'formcraft-captcha-pro' );
		$plan           = sanitize_key( $data['plan'] ?? 'lifetime' );
		$domain         = sanitize_text_field( $data['domain'] ?? '' );
		$coupon_used    = strtoupper( sanitize_text_field( $data['coupon'] ?? '' ) );
		$transaction_id = $payment_id;

		if ( empty( $email ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Customer email is required.' ), 400 );
		}

		// Calculate activations limit and expiry dates.
		$max_activations = 1;
		$expiry_date     = '';

		switch ( $plan ) {
			case 'monthly':
				$max_activations = 1;
				$expiry_date     = date( 'Y-m-d H:i:s', strtotime( '+30 days' ) );
				break;
			case 'yearly':
				$max_activations = 1;
				$expiry_date     = date( 'Y-m-d H:i:s', strtotime( '+1 year' ) );
				break;
			case '5-site':
				$max_activations = 5;
				break;
			case 'unlimited':
				$max_activations = 99999;
				break;
			case 'lifetime':
			default:
				$max_activations = 1;
				break;
		}

		// 1. Create Customer.
		$customer_id = Database::create_customer_if_not_exists( $email, $name );

		// 2. Generate unique license key.
		$key = LicenseGenerator::generate();

		// 3. Insert license record.
		$license_id = Database::insert_license( $customer_id, $key, $product, $email, $max_activations, $expiry_date, $plan );

		if ( ! $license_id ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Could not save license record.' ), 500 );
		}

		// Log audit event.
		$ip_hash = hash( 'sha256', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' );
		Database::log_event( $license_id, 'activate', sprintf( 'License key %s auto-generated post txn: %s', $key, $transaction_id ), $ip_hash );

		// 4. Auto Activation Handler.
		$activated = false;
		if ( ! empty( $domain ) ) {
			$clean_domain = strtolower( trim( $domain ) );
			$clean_domain = str_replace( array( 'http://', 'https://' ), '', $clean_domain );
			$parts        = explode( '/', $clean_domain );
			$clean_domain = current( $parts );
			$clean_domain = preg_replace( '/^www\./i', '', $clean_domain );

			$activated = Database::add_activation( $license_id, $clean_domain, $ip_hash );
			if ( $activated ) {
				Database::log_event( $license_id, 'activate', 'Auto-activated domain pair: ' . $clean_domain, $ip_hash );
			}
		}

		// 5. Send delivery email.
		$expires_desc = empty( $expiry_date ) ? 'Lifetime' : date( 'Y-m-d', strtotime( $expiry_date ) );
		EmailService::send_license_email( $email, $name, $key, $plan, $max_activations, $expires_desc );

		// 6. Record coupon usage (per-user limit tracking).
		if ( ! empty( $coupon_used ) ) {
			Api::record_coupon_usage( $coupon_used, $email );
		}

		// 7. Generate a secure, one-time download token for instant Pro plugin delivery.
		$download_url = Api::generate_download_token( $key, $email );

		return new \WP_REST_Response( array(
			'status'                => 'success',
			'activated'             => $activated,
			'license_key'           => $key,
			'max_activations'       => $max_activations,
			'remaining_activations' => $max_activations - ( $activated ? 1 : 0 ),
			'expires_at'            => $expires_desc,
			'download_url'          => $download_url,
		), 200 );
	}


	/**
	 * Endpoint: POST /wp-json/fcac-server/v1/webhook/refund
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function handle_refund( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$raw_body         = $request->get_body();
		$signature_header = $request->get_header( 'Stripe-Signature' );

		$allow_mock = defined( 'FCAC_TEST_SANDBOX_BYPASS' ) && true === FCAC_TEST_SANDBOX_BYPASS;
		$is_bypass  = $allow_mock && 'test_bypass' === $signature_header;

		if ( ! $is_bypass && ! self::verify_stripe_signature( $raw_body, $signature_header ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Invalid Stripe signature verification.' ), 401 );
		}

		$data = json_decode( $raw_body, true );
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Malformed JSON payload.' ), 400 );
		}

		$key = sanitize_text_field( $data['license_key'] ?? '' );
		if ( empty( $key ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'License key is required for refund processing.' ), 400 );
		}

		$table_name = $wpdb->prefix . 'fcac_licenses';
		$row        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE license_key = %s", $key ) );

		if ( ! $row ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'License not found.' ), 404 );
		}

		// Update status.
		$wpdb->update(
			$table_name,
			array( 'status' => 'refunded' ),
			array( 'id' => $row->id ),
			array( '%s' ),
			array( '%d' )
		);

		// Remove all domain pairings.
		$table_act = $wpdb->prefix . 'fcac_license_activations';
		$wpdb->delete( $table_act, array( 'license_id' => $row->id ), array( '%d' ) );

		// Log audit event.
		$ip_hash = hash( 'sha256', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' );
		Database::log_event( $row->id, 'refund', 'License status updated to Refunded. Activations revoked.', $ip_hash );

		return new \WP_REST_Response( array( 'status' => 'success', 'message' => 'License key successfully marked as refunded.' ), 200 );
	}
}
