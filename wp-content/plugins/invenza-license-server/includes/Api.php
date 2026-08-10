<?php
namespace InvenzaLicenseServer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles REST API endpoints for SaaS activation, validation, and update checks.
 */
class Api {

	/**
	 * Rate limiter using 4-layer architecture.
	 *
	 * @param string $prefix Prefix for rate limiting namespace.
	 * @param int    $limit  Maximum requests within window.
	 * @return bool True if permitted, false if rate limited.
	 */
	public static function check_rate_limit( string $prefix, int $limit ): bool {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$ip  = trim( current( $ips ) );
		} elseif ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip  = $_SERVER['HTTP_CF_CONNECTING_IP'];
		}

		$fingerprint = md5( $prefix . '_' . sanitize_text_field( $ip ) );
		$cache_key   = 'fcac_srv_rate_' . $fingerprint;
		$window      = 60;

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $cache_key, 0, 'INVENZA_SERVER_rate', $window );
			$count = wp_cache_incr( $cache_key, 1, 'INVENZA_SERVER_rate' );
			if ( false !== $count && (int) $count > $limit ) {
				return false;
			}
			return true;
		}

		// Fallback to Transients
		$data = get_transient( $cache_key );
		$now  = time();

		if ( ! is_array( $data )
			|| ! isset( $data['count'], $data['window_start'] )
			|| ( $now - (int) $data['window_start'] ) >= $window
		) {
			$data = array(
				'count'        => 0,
				'window_start' => $now,
			);
		}

		if ( (int) $data['count'] >= $limit ) {
			return false;
		}

		$data['count']++;
		set_transient( $cache_key, $data, $window * 2 );
		return true;
	}

	/**
	 * Permission check: Public Licensing REST API.
	 */
	public static function api_permission_check( \WP_REST_Request $request ): bool {
		return self::check_rate_limit( 'licensing', 60 );
	}

	/**
	 * Permission check: Public Stripe / Paypal webhook endpoints.
	 */
	public static function webhook_permission_check( \WP_REST_Request $request ): bool {
		return self::check_rate_limit( 'webhook', 30 );
	}

	/**
	 * Permission check: Secure Payment Intent checkout endpoint.
	 */
	public static function checkout_permission_check( \WP_REST_Request $request ): bool {
		// Rate limit: allow 30 requests per minute (covers Apply + Submit retries).
		if ( ! self::check_rate_limit( 'checkout', 30 ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Register API routes.
	 */
	public static function register_routes() {
		$namespace = 'fcac-server/v1';

		register_rest_route( $namespace, '/activate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'activate' ),
			'permission_callback' => array( __CLASS__, 'api_permission_check' ),
		) );

		register_rest_route( $namespace, '/deactivate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'deactivate' ),
			'permission_callback' => array( __CLASS__, 'api_permission_check' ),
		) );

		register_rest_route( $namespace, '/validate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'validate_checkin' ),
			'permission_callback' => array( __CLASS__, 'api_permission_check' ),
		) );

		register_rest_route( $namespace, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_status' ),
			'permission_callback' => array( __CLASS__, 'api_permission_check' ),
		) );

		register_rest_route( $namespace, '/update-check', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'update_check' ),
			'permission_callback' => array( __CLASS__, 'api_permission_check' ),
		) );

		register_rest_route( $namespace, '/support/ticket', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'submit_support_ticket' ),
			'permission_callback' => array( __CLASS__, 'api_permission_check' ),
		) );

		// Register webhooks from Stripe & PayPal.
		register_rest_route( $namespace, '/webhook/payment-success', array(
			'methods'             => 'POST',
			'callback'            => array( 'InvenzaLicenseServer\WebhookHandler', 'handle_payment_success' ),
			'permission_callback' => array( __CLASS__, 'webhook_permission_check' ),
		) );

		register_rest_route( $namespace, '/webhook/refund', array(
			'methods'             => 'POST',
			'callback'            => array( 'InvenzaLicenseServer\WebhookHandler', 'handle_refund' ),
			'permission_callback' => array( __CLASS__, 'webhook_permission_check' ),
		) );

		register_rest_route( $namespace, '/checkout/config', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_checkout_config' ),
			'permission_callback' => array( __CLASS__, 'api_permission_check' ),
		) );

		register_rest_route( $namespace, '/checkout/create-intent', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_payment_intent' ),
			'permission_callback' => array( __CLASS__, 'checkout_permission_check' ),
		) );

		register_rest_route( $namespace, '/checkout/apply-coupon', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'validate_and_apply_coupon' ),
			'permission_callback' => array( __CLASS__, 'checkout_permission_check' ),
		) );

		register_rest_route( $namespace, '/checkout/download', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'serve_pro_plugin_download' ),
			'permission_callback' => '__return_true',
		) );

		// Hook pre_serve filters to send CORS headers.
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'send_cors_headers' ), 10, 4 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'bypass_rest_auth_errors' ), 99 );
	}

	/**
	 * Send CORS headers to allow client dashboards to query endpoints directly.
	 */
	public static function send_cors_headers( $served, $result, $request, $server ) {
		if ( strpos( $request->get_route(), 'fcac-server/v1' ) !== false ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Stripe-Signature, X-FCAC-Checkout-Nonce' );
		}
		return $served;
	}

	/**
	 * Bypass REST authentication errors (e.g. cookie nonce failures) for public endpoints.
	 */
	public static function bypass_rest_auth_errors( $errors ) {
		if ( ! empty( $errors ) ) {
			if ( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '/fcac-server/v1/' ) !== false ) {
				return null;
			}
		}
		return $errors;
	}

	/**
	 * Verify HMAC signature on incoming requests with nonce verification.
	 *
	 * @param string $key License Key.
	 * @param string $domain Normalized domain.
	 * @param string $timestamp Timestamp value.
	 * @param string $signature Signature value.
	 * @param string $nonce Request nonce string.
	 * @return bool
	 */
	private static function verify_request_signature( string $key, string $domain, string $timestamp, string $signature, string $nonce ): bool {
		if ( empty( $key ) || empty( $domain ) || empty( $timestamp ) || empty( $signature ) || empty( $nonce ) ) {
			return false;
		}

		// Prevent replay attacks (5 minute validity window).
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return false;
		}

		$secret = defined( 'FCAC_HMAC_SECRET' ) && '' !== FCAC_HMAC_SECRET ? FCAC_HMAC_SECRET : $key;
		$expected = hash_hmac( 'sha256', $domain . '|' . $timestamp . '|' . $nonce, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Sign JSON response payload using the private RSA key.
	 *
	 * @param array $payload Key-value params.
	 * @return array Signed REST envelope.
	 */
	private static function sign_response_payload( array $payload ): array {
		$private_key_pem = Database::get_private_key();
		if ( empty( $private_key_pem ) ) {
			return array(
				'status'  => 'error',
				'code'    => 'SERVER_CONFIG_ERROR',
				'message' => 'Cryptographic signing keys are missing on the server.'
			);
		}

		$json_data = wp_json_encode( $payload );
		$signature = '';

		if ( openssl_sign( $json_data, $signature, $private_key_pem, OPENSSL_ALGO_SHA256 ) ) {
			return array(
				'status'    => 'success',
				'token'     => base64_encode( $json_data ),
				'signature' => base64_encode( $signature ),
			);
		}

		return array(
			'status'  => 'error',
			'code'    => 'SIGNING_FAILED',
			'message' => 'Failed to sign the response token.'
		);
	}

	/**
	 * Normalizes domain format.
	 *
	 * @param string $url URL or domain string.
	 * @return string Cleaned host/domain.
	 */
	private static function normalize_domain( string $url ): string {
		$clean = strtolower( trim( $url ) );
		$clean = str_replace( array( 'http://', 'https://' ), '', $clean );
		$parts = explode( '/', $clean );
		$clean = current( $parts );
		return preg_replace( '/^www\./i', '', $clean );
	}

	/**
	 * Endpoint: POST /wp-json/fcac-server/v1/activate
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function activate( \WP_REST_Request $request ): \WP_REST_Response {
		$key       = sanitize_text_field( trim( (string) $request->get_param( 'license_key' ) ) );
		$domain    = sanitize_text_field( trim( (string) $request->get_param( 'domain' ) ) );
		$timestamp = sanitize_text_field( trim( (string) $request->get_param( 'timestamp' ) ) );
		$signature = sanitize_text_field( trim( (string) $request->get_param( 'signature' ) ) );
		$nonce     = sanitize_text_field( trim( (string) $request->get_param( 'nonce' ) ) );

		$clean_domain = self::normalize_domain( $domain );

		// Validate Signature.
		if ( ! self::verify_request_signature( $key, $clean_domain, $timestamp, $signature, $nonce ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Verification signature verification failed.' ), 401 );
		}

		// Fetch License.
		$row = Database::get_license_by_key( $key );
		if ( ! $row ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'INVALID_LICENSE', 'message' => 'License key is invalid.' ), 404 );
		}

		// Check Status.
		if ( 'active' !== $row->status ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'LICENSE_REVOKED', 'message' => 'This license has been suspended or refunded.' ), 403 );
		}

		// Check Expiry.
		if ( ! empty( $row->expiry_date ) && strtotime( $row->expiry_date ) < time() ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'LICENSE_EXPIRED', 'message' => 'License key has expired.' ), 403 );
		}

		$ip_hash = hash( 'sha256', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' );

		// Check if already active.
		if ( Database::is_domain_active( $row->id, $clean_domain ) ) {
			$count = Database::get_activations_count( $row->id );
			$payload = array(
				'license_key'           => $key,
				'domain'                => $clean_domain,
				'status'                => 'valid',
				'activated'             => true,
				'remaining_activations' => max( 0, (int) $row->max_activations - $count ),
				'expires_at'            => $row->expiry_date ? date( 'Y-m-d', strtotime( $row->expiry_date ) ) : 'Never',
				'nonce'                 => $nonce,
				'timestamp'             => time(),
			);
			return new \WP_REST_Response( self::sign_response_payload( $payload ), 200 );
		}

		// Check activation limits.
		$count = Database::get_activations_count( $row->id );
		if ( $count >= (int) $row->max_activations ) {
			Database::log_event( $row->id, 'fail', sprintf( 'Activation limit reached (%d/%d) for domain: %s', $count, $row->max_activations, $clean_domain ), $ip_hash );
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'LIMIT_EXCEEDED', 'message' => 'Activation limit reached. Deactivate from another domain first.' ), 403 );
		}

		// Add activation.
		$result = Database::add_activation( $row->id, $clean_domain, $ip_hash );
		if ( ! $result ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'DATABASE_ERROR', 'message' => 'Could not store domain pairing.' ), 500 );
		}

		Database::log_event( $row->id, 'activate', 'Activated domain: ' . $clean_domain, $ip_hash );

		$payload = array(
			'license_key'           => $key,
			'domain'                => $clean_domain,
			'status'                => 'valid',
			'activated'             => true,
			'remaining_activations' => max( 0, (int) $row->max_activations - $count - 1 ),
			'expires_at'            => $row->expiry_date ? date( 'Y-m-d', strtotime( $row->expiry_date ) ) : 'Never',
			'nonce'                 => $nonce,
			'timestamp'             => time(),
		);

		return new \WP_REST_Response( self::sign_response_payload( $payload ), 200 );
	}

	/**
	 * Endpoint: POST /wp-json/fcac-server/v1/deactivate
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function deactivate( \WP_REST_Request $request ): \WP_REST_Response {
		$key       = sanitize_text_field( trim( (string) $request->get_param( 'license_key' ) ) );
		$domain    = sanitize_text_field( trim( (string) $request->get_param( 'domain' ) ) );
		$timestamp = sanitize_text_field( trim( (string) $request->get_param( 'timestamp' ) ) );
		$signature = sanitize_text_field( trim( (string) $request->get_param( 'signature' ) ) );
		$nonce     = sanitize_text_field( trim( (string) $request->get_param( 'nonce' ) ) );

		$clean_domain = self::normalize_domain( $domain );

		// Validate Signature.
		if ( ! self::verify_request_signature( $key, $clean_domain, $timestamp, $signature, $nonce ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Verification signature verification failed.' ), 401 );
		}

		// Fetch License.
		$row = Database::get_license_by_key( $key );
		if ( ! $row ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'INVALID_LICENSE', 'message' => 'License key is invalid.' ), 404 );
		}

		$ip_hash = hash( 'sha256', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' );

		if ( ! Database::is_domain_active( $row->id, $clean_domain ) ) {
			$payload = array(
				'license_key' => $key,
				'domain'      => $clean_domain,
				'deactivated' => true,
				'message'     => 'Domain was not paired.',
				'nonce'       => $nonce,
				'timestamp'   => time(),
			);
			return new \WP_REST_Response( self::sign_response_payload( $payload ), 200 );
		}

		// Remove activation.
		Database::remove_activation( $row->id, $clean_domain );
		Database::log_event( $row->id, 'deactivate', 'Deactivated domain: ' . $clean_domain, $ip_hash );

		$payload = array(
			'license_key' => $key,
			'domain'      => $clean_domain,
			'deactivated' => true,
			'nonce'       => $nonce,
			'timestamp'   => time(),
		);

		return new \WP_REST_Response( self::sign_response_payload( $payload ), 200 );
	}

	/**
	 * Endpoint: POST /wp-json/fcac-server/v1/validate
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function validate_checkin( \WP_REST_Request $request ): \WP_REST_Response {
		$key       = sanitize_text_field( trim( (string) $request->get_param( 'license_key' ) ) );
		$domain    = sanitize_text_field( trim( (string) $request->get_param( 'domain' ) ) );
		$timestamp = sanitize_text_field( trim( (string) $request->get_param( 'timestamp' ) ) );
		$signature = sanitize_text_field( trim( (string) $request->get_param( 'signature' ) ) );
		$nonce     = sanitize_text_field( trim( (string) $request->get_param( 'nonce' ) ) );

		$clean_domain = self::normalize_domain( $domain );

		// Validate Signature.
		if ( ! self::verify_request_signature( $key, $clean_domain, $timestamp, $signature, $nonce ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Verification signature verification failed.' ), 401 );
		}

		// Fetch License.
		$row = Database::get_license_by_key( $key );
		if ( ! $row ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'INVALID_LICENSE', 'message' => 'License key is invalid.' ), 404 );
		}

		// Check Status.
		if ( 'active' !== $row->status ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'LICENSE_REVOKED', 'message' => 'This license has been suspended or refunded.' ), 403 );
		}

		// Check Expiry.
		if ( ! empty( $row->expiry_date ) && strtotime( $row->expiry_date ) < time() ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'LICENSE_EXPIRED', 'message' => 'License key has expired.' ), 403 );
		}

		// Check if domain is active.
		if ( ! Database::is_domain_active( $row->id, $clean_domain ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'DOMAIN_UNAUTHORIZED', 'message' => 'This domain is not authorized under this license.' ), 403 );
		}

		// Update checkin.
		Database::update_checkin( $row->id, $clean_domain );

		$ip_hash = hash( 'sha256', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' );
		Database::log_event( $row->id, 'validate', 'Validated checkin domain: ' . $clean_domain, $ip_hash );

		$payload = array(
			'license_key' => $key,
			'domain'      => $clean_domain,
			'status'      => 'valid',
			'valid'       => true,
			'expires_at'  => $row->expiry_date ? date( 'Y-m-d', strtotime( $row->expiry_date ) ) : 'Never',
			'nonce'       => $nonce,
			'timestamp'   => time(),
		);

		return new \WP_REST_Response( self::sign_response_payload( $payload ), 200 );
	}

	/**
	 * Endpoint: GET /wp-json/fcac-server/v1/status
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function get_status( \WP_REST_Request $request ): \WP_REST_Response {
		$key = sanitize_text_field( trim( (string) $request->get_param( 'license_key' ) ) );
		if ( empty( $key ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'MISSING_KEY', 'message' => 'License key is required.' ), 400 );
		}

		$row = Database::get_license_by_key( $key );
		if ( ! $row ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'INVALID_LICENSE', 'message' => 'License key is invalid.' ), 404 );
		}

		$count = Database::get_activations_count( $row->id );

		return new \WP_REST_Response( array(
			'status'           => 'success',
			'license_status'   => $row->status,
			'plan'             => $row->plan,
			'max_activations'  => (int) $row->max_activations,
			'active_sites'     => $count,
			'expires_at'       => $row->expiry_date ? date( 'Y-m-d', strtotime( $row->expiry_date ) ) : 'Never',
		), 200 );
	}

	/**
	 * Endpoint: GET /wp-json/fcac-server/v1/update-check
	 * Handles WordPress native plugin update package generation requests.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function update_check( \WP_REST_Request $request ): \WP_REST_Response {
		$key       = sanitize_text_field( trim( (string) $request->get_param( 'license_key' ) ) );
		$domain    = sanitize_text_field( trim( (string) $request->get_param( 'domain' ) ) );
		$timestamp = sanitize_text_field( trim( (string) $request->get_param( 'timestamp' ) ) );
		$signature = sanitize_text_field( trim( (string) $request->get_param( 'signature' ) ) );
		$nonce     = sanitize_text_field( trim( (string) $request->get_param( 'nonce' ) ) );

		$clean_domain = self::normalize_domain( $domain );

		// Validate Signature.
		if ( ! self::verify_request_signature( $key, $clean_domain, $timestamp, $signature, $nonce ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Verification signature verification failed.' ), 401 );
		}

		// Fetch License.
		$row = Database::get_license_by_key( $key );
		if ( ! $row || 'active' !== $row->status ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'INVALID_LICENSE', 'message' => 'Inactive or invalid license.' ), 403 );
		}

		// Check if domain is active.
		if ( ! Database::is_domain_active( $row->id, $clean_domain ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'code' => 'DOMAIN_UNAUTHORIZED', 'message' => 'Unpaired domain.' ), 403 );
		}

		// Package Update Payload (SaaS versioning).
		$current_version = get_option( 'INVENZA_SERVER_pro_plugin_version', '1.0.0' );
		$download_url    = self::generate_download_token( $key, $row->customer_email );

		$payload = array(
			'new_version'    => $current_version,
			'stable_version' => $current_version,
			'name'           => 'Invenza CAPTCHA Pro Add-on',
			'slug'           => 'invenza-captcha-pro',
			'package'        => $download_url,
			'sections'       => array(
				'description'  => '<p><strong>Invenza CAPTCHA Pro</strong> unlocks advanced CAPTCHA modes (Math, Text, Auto Mode) and higher difficulty levels (Medium, Hard) for Invenza CAPTCHA for All Forms.</p><h4>Key Pro Features:</h4><ul><li><strong>Math CAPTCHA:</strong> Dynamic visual arithmetic formulas.</li><li><strong>Text Challenge:</strong> Custom Q&A pool questions.</li><li><strong>Auto Mode:</strong> Intelligent risk-adaptive challenge generation.</li><li><strong>Advanced Difficulties:</strong> Medium (6 chars) and Hard (8 chars) with sine wave warping and noise.</li></ul>',
				'installation' => '<h4>Automatic Update</h4><p>Click <strong>Update Now</strong> in your WordPress dashboard to automatically update to the latest version.</p>',
				'faq'          => '<h4>Is this plugin 100% self-hosted?</h4><p>Yes! No third-party tracking, cookies, or external servers are used. It is completely GDPR compliant.</p>',
				'changelog'    => '<h4>1.0.0 - August 2026</h4><ul><li>Initial Pro Release</li><li>Added Math, Text Q&A, and Auto Risk modes</li><li>Added Medium and Hard difficulty presets</li><li>Integrated secure license server update checker</li></ul>',
			),
			'nonce'     => $nonce,
			'timestamp' => time(),
		);

		return new \WP_REST_Response( self::sign_response_payload( $payload ), 200 );
	}

	/**
	 * REST API Callback: POST /fcac-server/v1/support/ticket
	 * Handles Priority Support Ticket submission from Pro users.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function submit_support_ticket( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$params = json_decode( $request->get_body(), true );
		if ( ! is_array( $params ) ) {
			$params = $request->get_json_params();
		}

		$key       = sanitize_text_field( trim( (string) ( $params['license_key'] ?? '' ) ) );
		$domain    = sanitize_text_field( trim( (string) ( $params['domain'] ?? '' ) ) );
		$email     = sanitize_email( trim( (string) ( $params['email'] ?? '' ) ) );
		$subject   = sanitize_text_field( trim( (string) ( $params['subject'] ?? '' ) ) );
		$message   = sanitize_textarea_field( trim( (string) ( $params['message'] ?? '' ) ) );
		$env_info  = sanitize_textarea_field( trim( (string) ( $params['environment_info'] ?? '' ) ) );

		if ( empty( $key ) || empty( $email ) || empty( $subject ) || empty( $message ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Please fill in all required ticket fields.' ), 400 );
		}

		// Verify license key
		$license = Database::get_license_by_key( $key );
		if ( ! $license || 'active' !== $license->status ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Invalid or inactive Pro license key. Priority support is exclusive to active Pro subscribers.' ), 403 );
		}

		$table_tickets = $wpdb->prefix . 'fcac_support_tickets';
		$result        = $wpdb->insert(
			$table_tickets,
			array(
				'license_key'      => $key,
				'domain'           => self::normalize_domain( $domain ),
				'email'            => $email,
				'subject'          => $subject,
				'message'          => $message,
				'environment_info' => $env_info,
				'status'           => 'open',
				'priority'         => 'high',
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $result ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Failed to submit support ticket. Please try again.' ), 500 );
		}

		$ticket_id = $wpdb->insert_id;

		// Audit Log
		$ip_hash = hash( 'sha256', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' );
		Database::log_event( $license->id, 'support_ticket', sprintf( 'Priority Ticket #%d created: %s', $ticket_id, $subject ), $ip_hash );

		return new \WP_REST_Response( array(
			'status'    => 'success',
			'message'   => sprintf( 'Priority Support Ticket #%d submitted successfully! Our engineering team will respond shortly.', $ticket_id ),
			'ticket_id' => $ticket_id,
		), 200 );
	}

	/**
	 * REST API Callback: GET /fcac-server/v1/checkout/config
	 */
	public static function get_checkout_config( \WP_REST_Request $request ): \WP_REST_Response {
		$stripe_pub   = self::get_env_val( 'STRIPE_PUBLISHABLE_KEY', 'INVENZA_SERVER_stripe_publishable_key' );
		$razorpay_pub = self::get_env_val( 'RAZORPAY_KEY_ID', 'INVENZA_SERVER_razorpay_key_id' );

		if ( empty( $razorpay_pub ) ) {
			$razorpay_pub = 'test_bypass';
		}
		if ( empty( $stripe_pub ) ) {
			$stripe_pub = 'test_bypass';
		}

		return new \WP_REST_Response( array(
			'stripe_publishable_key' => $stripe_pub,
			'publishable_key'        => $razorpay_pub,
		), 200 );
	}

	/**
	 * Fetches live USD exchange rates for ALL 160+ world currencies.
	 * Caches in transient for 6 hours and persists the last successful API fetch in the DB.
	 *
	 * @return array Map of ISO currency code => exchange rate float.
	 */
	public static function get_exchange_rates(): array {
		$rates = get_transient( 'fcac_usd_exchange_rates' );

		if ( false === $rates || ! is_array( $rates ) || empty( $rates ) ) {
			$response = wp_remote_get( 'https://open.er-api.com/v6/latest/USD', array( 'timeout' => 5 ) );

			if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( isset( $body['rates'] ) && is_array( $body['rates'] ) && ! empty( $body['rates'] ) ) {
					$rates = $body['rates'];

					// Store 6-hour transient cache
					set_transient( 'fcac_usd_exchange_rates', $rates, 6 * HOUR_IN_SECONDS );

					// Persist last successful API rates to database permanently as fallback
					update_option( 'INVENZA_SERVER_last_known_rates', $rates );
					update_option( 'INVENZA_SERVER_last_rate_update', time() );
				}
			}
		}

		// Fallback to last known successful API rates saved in database option
		if ( empty( $rates ) || ! is_array( $rates ) ) {
			$rates = get_option( 'INVENZA_SERVER_last_known_rates', array() );
		}

		// Ultimate baseline fallback if database has never fetched rates yet
		if ( empty( $rates ) || ! is_array( $rates ) ) {
			$rates = array( 'USD' => 1.0, 'INR' => 83.0, 'EUR' => 0.92, 'GBP' => 0.78 );
		}

		return $rates;
	}

	/**
	 * Dynamically gets the currency symbol for any ISO 4217 currency code.
	 *
	 * @param string $currency ISO 3-letter currency code.
	 * @return string Currency symbol or code.
	 */
	public static function get_currency_symbol( string $currency ): string {
		$currency = strtoupper( trim( $currency ) );

		$known_symbols = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'INR' => '₹',
			'JPY' => '¥',
			'CAD' => 'CA$',
			'AUD' => 'A$',
			'CHF' => 'CHF ',
			'CNY' => '¥',
			'HKD' => 'HK$',
			'NZD' => 'NZ$',
			'SEK' => 'kr ',
			'KRW' => '₩',
			'SGD' => 'S$',
			'NOK' => 'kr ',
			'MXN' => 'MX$',
			'BRL' => 'R$',
			'RUB' => '₽',
			'ZAR' => 'R ',
			'TRY' => '₺',
			'AED' => 'AED ',
			'SAR' => 'SAR ',
			'THB' => '฿',
			'IDR' => 'Rp ',
			'MYR' => 'RM ',
			'PHP' => '₱',
			'PKR' => 'Rs ',
			'BDT' => '৳',
			'EGP' => 'E£ ',
			'NGN' => '₦',
			'PLN' => 'zł ',
			'CZK' => 'Kč ',
			'HUF' => 'Ft ',
			'ILS' => '₪',
			'CLP' => 'CLP$',
			'COP' => 'COL$',
			'PEN' => 'S/ ',
			'VND' => '₫',
		);

		if ( isset( $known_symbols[ $currency ] ) ) {
			return $known_symbols[ $currency ];
		}

		// Use PHP Intl NumberFormatter if available for any unlisted country
		if ( class_exists( '\NumberFormatter' ) ) {
			try {
				$fmt    = new \NumberFormatter( 'en_US@currency=' . $currency, \NumberFormatter::CURRENCY );
				$symbol = $fmt->getSymbol( \NumberFormatter::CURRENCY_SYMBOL );
				if ( ! empty( $symbol ) && $symbol !== $currency ) {
					return $symbol . ' ';
				}
			} catch ( \Throwable $e ) {}
		}

		return $currency . ' ';
	}

	/**
	 * Converts a USD amount into any target country currency using live exchange rates.
	 *
	 * @param float  $amount_usd      Price in USD.
	 * @param string $target_currency ISO 3-letter currency code (e.g. 'INR', 'EUR', 'GBP', 'BRL', 'MXN', 'JPY').
	 * @return array Array with keys 'amount', 'currency', 'rate', 'symbol', and 'formatted'.
	 */
	public static function convert_usd_to_currency( float $amount_usd, string $target_currency = 'INR' ): array {
		$target_currency = strtoupper( trim( $target_currency ) );
		$rates           = self::get_exchange_rates();
		$rate            = isset( $rates[ $target_currency ] ) ? (float) $rates[ $target_currency ] : 1.0;

		$converted = round( $amount_usd * $rate, 2 );
		$symbol    = self::get_currency_symbol( $target_currency );

		// Zero-decimal currencies (JPY, KRW, VND, HUF, etc.)
		$zero_decimals = array( 'JPY', 'KRW', 'VND', 'CLP', 'HUF', 'PYG', 'UGX', 'RWFA' );
		$decimals      = in_array( $target_currency, $zero_decimals, true ) || 'INR' === $target_currency ? 0 : 2;

		return array(
			'amount'    => $converted,
			'currency'  => $target_currency,
			'rate'      => $rate,
			'symbol'    => $symbol,
			'formatted' => $symbol . number_format( $converted, $decimals ),
		);
	}

	/**
	 * Backwards compatible helper for fetching USD to INR rate.
	 *
	 * @return float
	 */
	public static function get_usd_to_inr_rate(): float {
		$rates = self::get_exchange_rates();
		return (float) ( $rates['INR'] ?? 83.0 );
	}

	/**
	 * REST API Callback: POST /fcac-server/v1/checkout/create-intent
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function create_payment_intent( \WP_REST_Request $request ) {
		$params  = json_decode( $request->get_body(), true );
		if ( ! is_array( $params ) ) {
			$params = $request->get_json_params();
		}

		$email           = sanitize_email( $params['email'] ?? '' );
		$name            = sanitize_text_field( $params['name'] ?? '' );
		$plan            = sanitize_key( $params['plan'] ?? '' );
		$domain          = sanitize_text_field( $params['domain'] ?? '' );
		$target_currency = strtoupper( sanitize_text_field( $params['currency'] ?? 'INR' ) );
		$coupon          = strtoupper( sanitize_text_field( $params['coupon'] ?? '' ) );

		if ( empty( $email ) || empty( $plan ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Missing email or plan parameter.' ), 400 );
		}

		$key_id     = self::get_env_val( 'RAZORPAY_KEY_ID', 'INVENZA_SERVER_razorpay_key_id' );
		$key_secret = self::get_env_val( 'RAZORPAY_KEY_SECRET', 'INVENZA_SERVER_razorpay_key_secret' );

		if ( empty( $key_id ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Razorpay billing gateway is currently unconfigured on the license server.' ), 500 );
		}

		$usd_prices = array(
			'monthly'   => 6,
			'yearly'    => 29,
			'5-site'    => 59,
			'unlimited' => 89,
			'lifetime'  => 149,
		);

		$plan_key     = array_key_exists( $plan, $usd_prices ) ? $plan : 'yearly';
		$price_in_usd = $usd_prices[ $plan_key ];

		// Valid Coupons from Database options (admin-created only)
		$stored_coupons = get_option( 'INVENZA_SERVER_coupons', array() );
		if ( ! is_array( $stored_coupons ) ) {
			$stored_coupons = array();
		}

		$discount_percent = 0;
		if ( ! empty( $coupon ) && isset( $stored_coupons[ $coupon ] ) ) {
			$coupon_data = $stored_coupons[ $coupon ];

			// Normalise old flat-integer format.
			if ( ! is_array( $coupon_data ) ) {
				$coupon_data = array( 'discount' => absint( $coupon_data ), 'expires_at' => '', 'per_user_limit' => 0, 'usage' => array() );
			}

			// Check expiry.
			if ( ! empty( $coupon_data['expires_at'] ) && strtotime( $coupon_data['expires_at'] ) < strtotime( 'today' ) ) {
				return new \WP_REST_Response( array( 'success' => false, 'message' => 'This coupon code has expired.' ), 400 );
			}

			// Check per-user limit.
			$per_user_limit = absint( $coupon_data['per_user_limit'] ?? 0 );
			if ( $per_user_limit > 0 && ! empty( $email ) ) {
				$usage     = is_array( $coupon_data['usage'] ?? null ) ? $coupon_data['usage'] : array();
				$user_uses = absint( $usage[ strtolower( $email ) ] ?? 0 );
				if ( $user_uses >= $per_user_limit ) {
					return new \WP_REST_Response( array(
						'success' => false,
						'message' => sprintf( 'You have already used this coupon the maximum allowed times (%d).', $per_user_limit ),
					), 400 );
				}
			}

			$discount_percent = absint( $coupon_data['discount'] );
			$price_in_usd     = round( $price_in_usd * ( ( 100 - $discount_percent ) / 100 ), 2 );
		}

		// Calculate converted price using multi-currency engine
		$converted_data  = self::convert_usd_to_currency( $price_in_usd, $target_currency );
		$charge_currency = $converted_data['currency'];
		$charge_amount   = (int) round( $converted_data['amount'] * 100 ); // amount in smallest currency unit (paise/cents)

		// Minimum amount check
		if ( $charge_amount < 100 ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Amount must be at least 100 subunits.' ), 400 );
		}

		// Support local test mock
		if ( strpos( $key_id, 'rzp_test_mock' ) === 0 || empty( $key_secret ) || 'test_bypass' === $key_id ) {
			return new \WP_REST_Response( array(
				'success'  => true,
				'gateway'  => 'razorpay',
				'order_id' => 'order_rzp_mock_' . bin2hex( random_bytes( 6 ) ),
				'key_id'   => $key_id,
				'amount'   => $charge_amount,
				'currency' => $charge_currency,
				'mock'     => true
			), 200 );
		}

		// Call Razorpay Order Creator API via SDK
		try {
			$api   = new \Razorpay\Api\Api( $key_id, $key_secret );
			$order = $api->order->create( array(
				'amount'   => $charge_amount,
				'currency' => $charge_currency,
				'receipt'  => 'rcpt_' . time(),
				'notes'    => array(
					'product'       => 'formcraft-captcha-pro',
					'plan'          => $plan,
					'domain'        => $domain,
					'customer_name' => $name,
				)
			) );

			if ( isset( $order->id ) ) {
				return new \WP_REST_Response( array(
					'success'  => true,
					'gateway'  => 'razorpay',
					'order_id' => $order->id,
					'key_id'   => $key_id,
					'amount'   => $charge_amount,
					'currency' => $charge_currency,
				), 200 );
			}
		} catch ( \Exception $e ) {
			// Check auth failures (Razorpay return status 401)
			if ( strpos( strtolower( $e->getMessage() ), 'unauthorized' ) !== false || strpos( strtolower( $e->getMessage() ), 'auth' ) !== false ) {
				return new \WP_REST_Response( array( 'success' => false, 'message' => 'Razorpay authentication failed.' ), 401 );
			}
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Razorpay Order creation failed: ' . $e->getMessage() ), 500 );
		}

		return new \WP_REST_Response( array( 'success' => false, 'message' => 'Razorpay Order creation failed.' ), 400 );
	}

	/**
	 * Retrieve value from .env file or database option fallback.
	 *
	 * @param string $key Environmental key.
	 * @param string $option_name Database option fallback.
	 * @return string Value.
	 */
	public static function get_env_val( string $key, string $option_name = '' ): string {
		$env_val = getenv( $key );
		if ( false !== $env_val && '' !== $env_val ) {
			return $env_val;
		}

		if ( isset( $_ENV[ $key ] ) ) {
			return $_ENV[ $key ];
		}

		// Check standard .env location in WP Root
		$env_file = ABSPATH . '.env';
		if ( file_exists( $env_file ) ) {
			$lines = file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			foreach ( $lines as $line ) {
				if ( strpos( trim( $line ), '#' ) === 0 ) {
					continue;
				}
				list( $name, $value ) = array_pad( explode( '=', $line, 2 ), 2, null );
				if ( null !== $name && trim( $name ) === $key ) {
					return trim( $value );
				}
			}
		}

		return get_option( $option_name, '' );
	}

	/**
	 * Dynamic REST API handler to validate and calculate instant coupon discount preview.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function validate_and_apply_coupon( \WP_REST_Request $request ) {
		$params  = json_decode( $request->get_body(), true );
		if ( ! is_array( $params ) ) {
			$params = $request->get_json_params();
		}

		$coupon_code = strtoupper( sanitize_text_field( $params['coupon'] ?? '' ) );
		$plan        = sanitize_key( $params['plan'] ?? 'yearly' );

		if ( empty( $coupon_code ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Please enter a coupon code.' ), 400 );
		}

		$stored_coupons = get_option( 'INVENZA_SERVER_coupons', array() );
		if ( ! is_array( $stored_coupons ) ) {
			$stored_coupons = array();
		}

		if ( ! isset( $stored_coupons[ $coupon_code ] ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Invalid or expired coupon code.' ), 404 );
		}

		$coupon_data = $stored_coupons[ $coupon_code ];

		// Normalise old flat-integer format.
		if ( ! is_array( $coupon_data ) ) {
			$coupon_data = array( 'discount' => absint( $coupon_data ), 'expires_at' => '', 'per_user_limit' => 0, 'usage' => array() );
		}

		// Check expiry.
		if ( ! empty( $coupon_data['expires_at'] ) && strtotime( $coupon_data['expires_at'] ) < strtotime( 'today' ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => 'This coupon code has expired on ' . date_i18n( 'M j, Y', strtotime( $coupon_data['expires_at'] ) ) . '.',
			), 400 );
		}

		// Check per-user limit (pass email if provided in payload).
		$email_check    = strtolower( sanitize_email( $params['email'] ?? '' ) );
		$per_user_limit = absint( $coupon_data['per_user_limit'] ?? 0 );
		if ( $per_user_limit > 0 && ! empty( $email_check ) ) {
			$usage     = is_array( $coupon_data['usage'] ?? null ) ? $coupon_data['usage'] : array();
			$user_uses = absint( $usage[ $email_check ] ?? 0 );
			if ( $user_uses >= $per_user_limit ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => sprintf( 'You have already used this coupon %d time(s). Maximum allowed per email: %d.', $user_uses, $per_user_limit ),
				), 400 );
			}
		}

		$discount_percent = absint( $coupon_data['discount'] ?? 0 );
		$target_currency  = strtoupper( sanitize_text_field( $params['currency'] ?? 'INR' ) );

		$usd_prices = array(
			'monthly'   => 6,
			'yearly'    => 29,
			'5-site'    => 59,
			'unlimited' => 89,
			'lifetime'  => 149,
		);

		$plan_key       = array_key_exists( $plan, $usd_prices ) ? $plan : 'yearly';
		$orig_usd       = $usd_prices[ $plan_key ];
		$discounted_usd = round( $orig_usd * ( ( 100 - $discount_percent ) / 100 ), 2 );
		$saved_usd      = round( $orig_usd - $discounted_usd, 2 );

		// Multi-currency calculation
		$orig_local       = self::convert_usd_to_currency( $orig_usd, $target_currency );
		$discounted_local = self::convert_usd_to_currency( $discounted_usd, $target_currency );
		$saved_local      = self::convert_usd_to_currency( $saved_usd, $target_currency );

		// Backwards-compatible INR fields
		$orig_inr       = self::convert_usd_to_currency( $orig_usd, 'INR' )['amount'];
		$discounted_inr = self::convert_usd_to_currency( $discounted_usd, 'INR' )['amount'];

		return new \WP_REST_Response( array(
			'success'          => true,
			'message'          => sprintf( 'Coupon "%s" applied! You save %d%% (%s)', $coupon_code, $discount_percent, $saved_local['formatted'] ),
			'coupon'           => $coupon_code,
			'discount_percent' => $discount_percent,
			'original_price'   => '$' . $orig_usd . ( 'USD' !== $target_currency ? ' (' . $orig_local['formatted'] . ')' : '' ),
			'discounted_price' => '$' . $discounted_usd . ( 'USD' !== $target_currency ? ' (' . $discounted_local['formatted'] . ')' : '' ),
			'saved_amount'     => $saved_local['formatted'],
			'orig_inr'         => $orig_inr,
			'discounted_inr'   => $discounted_inr,
			'local_currency'   => $target_currency,
			'local_discounted' => $discounted_local['amount'],
			'local_formatted'  => $discounted_local['formatted'],
		), 200 );
	}

	/**
	 * Record a coupon usage for a specific email address after a confirmed payment.
	 * Called by WebhookHandler::handle_payment_success() on success.
	 *
	 * @param string $coupon_code  Uppercase coupon code.
	 * @param string $email        Customer email.
	 */
	public static function record_coupon_usage( string $coupon_code, string $email ): void {
		if ( empty( $coupon_code ) || empty( $email ) ) {
			return;
		}

		$coupon_code = strtoupper( $coupon_code );
		$email       = strtolower( sanitize_email( $email ) );

		$stored_coupons = get_option( 'INVENZA_SERVER_coupons', array() );
		if ( ! is_array( $stored_coupons ) || ! isset( $stored_coupons[ $coupon_code ] ) ) {
			return;
		}

		$coupon_data = $stored_coupons[ $coupon_code ];

		// Normalise old flat-integer format.
		if ( ! is_array( $coupon_data ) ) {
			$coupon_data = array( 'discount' => absint( $coupon_data ), 'expires_at' => '', 'per_user_limit' => 0, 'usage' => array() );
		}

		if ( ! is_array( $coupon_data['usage'] ?? null ) ) {
			$coupon_data['usage'] = array();
		}

		// Increment usage count for this email.
		$coupon_data['usage'][ $email ] = absint( $coupon_data['usage'][ $email ] ?? 0 ) + 1;

		$stored_coupons[ $coupon_code ] = $coupon_data;
		update_option( 'INVENZA_SERVER_coupons', $stored_coupons );
	}

	/**
	 * Generate a secure, one-time, time-limited download token for the Pro plugin ZIP.
	 *
	 * @param string $license_key The license key issued after payment.
	 * @param string $email       Customer email address.
	 * @return string The download URL with the embedded token.
	 */
	public static function generate_download_token( string $license_key, string $email ): string {
		$token     = bin2hex( random_bytes( 24 ) ); // 48-char secure token
		$cache_key = 'invenza_dl_token_' . $token;

		// Store token data: expires in 15 minutes, single-use.
		set_transient( $cache_key, array(
			'license_key' => $license_key,
			'email'       => $email,
			'used'        => false,
			'created_at'  => time(),
		), 15 * MINUTE_IN_SECONDS );

		return rest_url( 'fcac-server/v1/checkout/download' ) . '?token=' . urlencode( $token );
	}

	/**
	 * REST API Callback: GET /fcac-server/v1/checkout/download
	 * Validates a one-time download token and serves the Pro plugin ZIP file.
	 *
	 * @param \WP_REST_Request $request
	 * @return void|\WP_REST_Response
	 */
	public static function serve_pro_plugin_download( \WP_REST_Request $request ) {
		$token = sanitize_text_field( trim( (string) $request->get_param( 'token' ) ) );

		if ( empty( $token ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Missing download token.' ), 400 );
		}

		$cache_key  = 'invenza_dl_token_' . $token;
		$token_data = get_transient( $cache_key );

		if ( ! is_array( $token_data ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Invalid or expired download link. Please contact support.' ), 403 );
		}

		if ( ! empty( $token_data['used'] ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'This download link has already been used. Check your email for the download link.' ), 403 );
		}

		// Mark token as used immediately (one-time use).
		$token_data['used'] = true;
		set_transient( $cache_key, $token_data, 5 * MINUTE_IN_SECONDS );

		// Retrieve the configured Pro plugin ZIP path from Admin settings.
		$zip_path = get_option( 'INVENZA_SERVER_pro_plugin_zip_path', '' );

		if ( empty( $zip_path ) ) {
			error_log( '[Invenza License Server] Pro plugin ZIP path is not configured. Admin must set it in Payment Settings tab.' );
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Download package is currently unavailable. Your license key has been emailed to you. Please contact support.' ), 503 );
		}

		if ( ! file_exists( $zip_path ) || ! is_readable( $zip_path ) ) {
			error_log( '[Invenza License Server] Pro plugin ZIP file not found at path: ' . $zip_path );
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Download package file not found. Your license key has been emailed. Please contact support.' ), 503 );
		}

		$file_size = filesize( $zip_path );
		$file_name = basename( $zip_path );

		// Log the download event.
		error_log( sprintf(
			'[Invenza License Server] Download served: license=%s email=%s token=%s',
			$token_data['license_key'],
			$token_data['email'],
			substr( $token, 0, 8 ) . '...'
		) );

		// Stream the ZIP file as a download.
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
		header( 'Content-Length: ' . $file_size );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Disable output buffering before streaming.
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}
}
