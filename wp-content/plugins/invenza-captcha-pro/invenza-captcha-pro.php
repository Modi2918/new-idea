<?php
/**
 * Plugin Name: Invenza CAPTCHA Pro Add-on
 * Plugin URI: https://license.isweb.in/
 * Description: Unlocks advanced CAPTCHA modes (Math, Text, Auto) and difficulties (Medium, Hard) for Invenza CAPTCHA for All Forms.
 * Version: 1.0.0
 * Author: Invenza
 * Author URI: https://license.isweb.in/
 * Text Domain: invenza-captcha-pro
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InvenzaCaptchaPro {

	const LICENSE_OPTION = 'invenza_captcha_pro_license';
	const STATUS_OPTION  = 'invenza_captcha_pro_status';
	const NOTICE_OPTION  = 'invenza_captcha_pro_notice';
	const EXPIRES_OPTION = 'invenza_captcha_pro_expires_at';
	const LICENSE_SERVER = 'https://license.isweb.in/';
	const CRON_HOOK      = 'invenza_pro_daily_license_check';

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'check_dependencies' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_license_notices' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		// Dismiss notice handler.
		add_action( 'admin_init', array( __CLASS__, 'handle_dismiss_notice' ) );

		// Register cron hook.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily_license_check' ) );

		// If license is valid, activate Pro features & automatic updater.
		if ( get_option( self::STATUS_OPTION ) === 'active' ) {
			add_filter( 'invenza_captcha_available_types', array( __CLASS__, 'register_premium_types' ) );
			add_filter( 'invenza_captcha_available_difficulties', array( __CLASS__, 'register_premium_difficulties' ) );
			add_filter( 'invenza_captcha_generate_challenge', array( __CLASS__, 'generate_premium_challenge' ), 10, 3 );
			add_filter( 'invenza_captcha_render_image', array( __CLASS__, 'render_premium_image' ), 10, 4 );

			// Initialize automatic update checker.
			InvenzaCaptchaProUpdater::init();
		}
	}

	// =========================================================================
	// PRO FEATURE FILTERS
	// =========================================================================

	public static function register_premium_types( $types ) {
		$types['math'] = __( 'Math CAPTCHA (Visual arithmetic formulas)', 'invenza-captcha-pro' );
		$types['text'] = __( 'Text Challenge (Q&A Pool)', 'invenza-captcha-pro' );
		$types['auto'] = __( 'Auto Mode (Bot Risk Adaptive)', 'invenza-captcha-pro' );
		return $types;
	}

	public static function register_premium_difficulties( $difficulties ) {
		$difficulties['medium'] = __( 'Medium (6 Chars, Medium noise & wave distortion)', 'invenza-captcha-pro' );
		$difficulties['hard']   = __( 'Hard (8 Chars, High noise, warp distortion & rotation)', 'invenza-captcha-pro' );
		return $difficulties;
	}

	public static function generate_premium_challenge( $challenge, $form_id, $session_key ) {
		$pro_settings = get_option( 'invenza_captcha_pro_settings', array() );
		$type         = isset( $pro_settings['captcha_type'] ) ? $pro_settings['captcha_type'] : 'image';

		if ( 'image' === $type ) {
			return $challenge;
		}

		$resolved_type = $type;
		if ( 'auto' === $type ) {
			$score = 50;
			if ( class_exists( '\InvenzaCaptcha\Includes\CaptchaRiskManager' ) ) {
				$score = \InvenzaCaptcha\Includes\CaptchaRiskManager::calculate_score( $form_id, $session_key, time(), 0, '' );
			}
			$resolved_type = $score <= 30 ? 'math' : 'image';
		}

		$display_text = '';
		$answer_code  = '';

		if ( 'math' === $resolved_type ) {
			$num1 = wp_rand( 1, 10 );
			$num2 = wp_rand( 1, 9 );
			$op   = wp_rand( 0, 1 ) === 1 ? '+' : '-';

			if ( '-' === $op && $num1 < $num2 ) {
				$temp = $num1;
				$num1 = $num2;
				$num2 = $temp;
			}

			$display_text = "{$num1} {$op} {$num2} = ?";
			$answer_code  = (string) ( '+' === $op ? ( $num1 + $num2 ) : ( $num1 - $num2 ) );
		} elseif ( 'text' === $resolved_type ) {
			$qa           = self::get_random_qa();
			$display_text = $qa['question'];
			$answer_code  = $qa['answer'];
		} else {
			return $challenge;
		}

		return array(
			'display_text'  => $display_text,
			'answer_code'   => $answer_code,
			'resolved_type' => $resolved_type,
		);
	}

	public static function render_premium_image( $image, string $display_text, int $form_id, string $session_key ) {
		$pro_settings = get_option( 'invenza_captcha_pro_settings', array() );
		$filter       = isset( $pro_settings['image_filter'] ) ? $pro_settings['image_filter'] : 'wave';
		$amplitude    = isset( $pro_settings['wave_amplitude'] ) ? (int) $pro_settings['wave_amplitude'] : 6;
		$period       = isset( $pro_settings['wave_period'] ) ? (int) $pro_settings['wave_period'] : 12;

		$width  = imagesx( $image );
		$height = imagesy( $image );

		if ( 'wave' === $filter ) {
			return self::apply_wave_distortion( $image, $width, $height, $amplitude, $period );
		} elseif ( 'pixelate' === $filter ) {
			imagefilter( $image, IMG_FILTER_PIXELATE, 3 );
		} elseif ( 'blur' === $filter ) {
			imagefilter( $image, IMG_FILTER_GAUSSIAN_BLUR );
		} elseif ( 'edge' === $filter ) {
			imagefilter( $image, IMG_FILTER_EDGEDETECT );
		} elseif ( 'invert' === $filter ) {
			imagefilter( $image, IMG_FILTER_NEGATE );
		}

		return $image;
	}

	private static function apply_wave_distortion( $image, int $width, int $height, int $amplitude, int $period ) {
		$distorted = imagecreatetruecolor( $width, $height );
		if ( ! $distorted ) {
			return $image;
		}

		$bg_color = imagecolorallocate( $distorted, 255, 255, 255 );
		imagefill( $distorted, 0, 0, $bg_color );

		$phase = wp_rand( 0, 100 ) / 10.0;

		for ( $x = 0; $x < $width; $x++ ) {
			for ( $y = 0; $y < $height; $y++ ) {
				$dy    = sin( ( $x / $period ) + $phase ) * $amplitude;
				$new_y = (int) ( $y + $dy );

				if ( $new_y >= 0 && $new_y < $height ) {
					$color = imagecolorat( $image, $x, $y );
					imagesetpixel( $distorted, $x, $new_y, $color );
				}
			}
		}

		imagedestroy( $image );
		return $distorted;
	}

	private static function get_random_qa(): array {
		$pro_settings = get_option( 'invenza_captcha_pro_settings', array() );
		$raw          = isset( $pro_settings['text_questions'] ) ? $pro_settings['text_questions'] : '';

		if ( empty( $raw ) ) {
			return array(
				'question' => 'What is 2 + 2?',
				'answer'   => '4',
			);
		}

		$lines = explode( "\n", str_replace( "\r", "", $raw ) );
		$lines = array_filter( array_map( 'trim', $lines ) );

		if ( empty( $lines ) ) {
			return array(
				'question' => 'What is 2 + 2?',
				'answer'   => '4',
			);
		}

		$random_line = $lines[ wp_rand( 0, count( $lines ) - 1 ) ];
		$parts       = explode( '|', $random_line );

		return array(
			'question' => isset( $parts[0] ) ? trim( $parts[0] ) : 'What is 2 + 2?',
			'answer'   => isset( $parts[1] ) ? trim( $parts[1] ) : '4',
		);
	}

	public static function reset_to_free_settings( string $reason = 'deactivated' ) {
		$settings = get_option( 'invenza_captcha_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$pro_types = array( 'math', 'text', 'auto' );
		$current_type       = $settings['captcha_type'] ?? 'image';
		$current_difficulty = $settings['difficulty'] ?? 'easy';
		$changed = false;

		if ( in_array( $current_type, $pro_types, true ) ) {
			$settings['captcha_type'] = 'image';
			$changed = true;
		}

		if ( in_array( $current_difficulty, array( 'medium', 'hard' ), true ) ) {
			$settings['difficulty'] = 'easy';
			$changed = true;
		}

		if ( $changed ) {
			update_option( 'invenza_captcha_settings', $settings );
			if ( class_exists( '\InvenzaCaptcha\Includes\Settings' ) ) {
				\InvenzaCaptcha\Includes\Settings::flush();
			}
		}

		update_option( self::NOTICE_OPTION, $reason );
	}

	public static function schedule_daily_check() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule_daily_check() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public static function run_daily_license_check() {
		$status      = get_option( self::STATUS_OPTION, 'inactive' );
		$license_key = get_option( self::LICENSE_OPTION, '' );

		if ( 'active' !== $status || empty( $license_key ) ) {
			return;
		}

		$domain    = self::get_domain();
		$timestamp = (string) time();
		$nonce     = wp_generate_password( 12, false );
		$secret    = $license_key;
		$signature = hash_hmac( 'sha256', $domain . '|' . $timestamp . '|' . $nonce, $secret );

		$server_url = rtrim( self::LICENSE_SERVER, '/' ) . '/wp-json/fcac-server/v1/validate';

		$response = wp_remote_post( $server_url, array(
			'timeout' => 15,
			'body'    => array(
				'license_key' => $license_key,
				'domain'      => $domain,
				'timestamp'   => $timestamp,
				'nonce'       => $nonce,
				'signature'   => $signature,
			),
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[Invenza Pro] Daily license check skipped: could not reach license server.' );
			return;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $http_code && isset( $body['status'] ) && 'success' === $body['status'] ) {
			if ( ! empty( $body['token'] ) ) {
				$payload = json_decode( base64_decode( $body['token'] ), true );
				if ( isset( $payload['expires_at'] ) ) {
					update_option( self::EXPIRES_OPTION, $payload['expires_at'] );
				}
			}
			return;
		}

		$code   = $body['code'] ?? '';
		$reason = 'expired';

		if ( 'LICENSE_REVOKED' === $code ) {
			$reason = 'revoked';
		} elseif ( 'LICENSE_EXPIRED' === $code ) {
			$reason = 'expired';
		} elseif ( 'DOMAIN_UNAUTHORIZED' === $code ) {
			$reason = 'deactivated';
		}

		update_option( self::STATUS_OPTION, 'inactive' );
		self::reset_to_free_settings( $reason );

		error_log( sprintf( '[Invenza Pro] License auto-revoked during daily check. Code: %s, Reason: %s', $code, $reason ) );
	}

	public static function check_dependencies() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( 'invenza-captcha-for-all-forms/invenza-captcha-for-all-forms.php' ) ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Invenza CAPTCHA Pro requires the base plugin.', 'invenza-captcha-pro' ); ?></strong><br>
					<?php esc_html_e( 'Please install and activate the free "Invenza CAPTCHA for All Forms" plugin.', 'invenza-captcha-pro' ); ?>
				</p>
			</div>
			<?php
		} elseif ( get_option( self::STATUS_OPTION ) !== 'active' && ! get_option( self::NOTICE_OPTION ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Invenza CAPTCHA Pro is almost ready.', 'invenza-captcha-pro' ); ?></strong><br>
					<?php echo sprintf(
						/* translators: %s is the settings page URL */
						__( 'Please <a href="%s">enter your license key</a> to activate the Pro features.', 'invenza-captcha-pro' ),
						esc_url( admin_url( 'admin.php?page=invenza-captcha-pro' ) )
					); ?>
				</p>
			</div>
			<?php
		}
	}

	public static function show_license_notices() {
		$notice = get_option( self::NOTICE_OPTION, '' );
		if ( empty( $notice ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'invenza_pro_dismiss_notice', '1' ), 'invenza_pro_dismiss_notice' );
		$renew_url = admin_url( 'admin.php?page=invenza-captcha-pro' );

		switch ( $notice ) {
			case 'expired':
				$title = __( '⚠️ Invenza CAPTCHA Pro License Expired', 'invenza-captcha-pro' );
				$msg   = __( 'Your Pro license has expired. All Pro features have been automatically disabled, and your CAPTCHA has been reset to the free Image mode. Renew your license to restore Pro features.', 'invenza-captcha-pro' );
				break;
			case 'revoked':
				$title = __( '🚫 Invenza CAPTCHA Pro License Revoked', 'invenza-captcha-pro' );
				$msg   = __( 'Your Pro license has been revoked or refunded. All Pro features have been automatically disabled and settings reset to the free tier.', 'invenza-captcha-pro' );
				break;
			case 'deactivated':
			default:
				$title = __( 'ℹ️ Invenza CAPTCHA Pro Deactivated', 'invenza-captcha-pro' );
				$msg   = __( 'Your Pro license has been deactivated. Pro-only CAPTCHA types and difficulties have been reset to the free defaults.', 'invenza-captcha-pro' );
				break;
		}
		?>
		<div class="notice notice-warning is-dismissible" style="border-left-color: #f59e0b; padding: 12px 16px;">
			<p><strong><?php echo esc_html( $title ); ?></strong></p>
			<p><?php echo esc_html( $msg ); ?></p>
			<p>
				<a href="<?php echo esc_url( $renew_url ); ?>" class="button button-primary"><?php esc_html_e( 'Manage License', 'invenza-captcha-pro' ); ?></a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:10px;"><?php esc_html_e( 'Dismiss', 'invenza-captcha-pro' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function handle_dismiss_notice() {
		if ( isset( $_GET['invenza_pro_dismiss_notice'] ) && check_admin_referer( 'invenza_pro_dismiss_notice' ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				delete_option( self::NOTICE_OPTION );
			}
			wp_safe_redirect( remove_query_arg( array( 'invenza_pro_dismiss_notice', '_wpnonce' ) ) );
			exit;
		}
	}

	public static function add_admin_menu() {
		add_submenu_page(
			'invenza-captcha-for-all-forms',
			__( 'Pro License', 'invenza-captcha-pro' ),
			__( 'Pro License', 'invenza-captcha-pro' ),
			'manage_options',
			'invenza-captcha-pro',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting( 'invenza_captcha_pro_group', self::LICENSE_OPTION );
		if ( isset( $_POST['invenza_pro_activate'] ) || isset( $_POST['invenza_pro_deactivate'] ) ) {
			if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'invenza_pro_nonce' ) ) {
				return;
			}
			$license_key = isset( $_POST[ self::LICENSE_OPTION ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::LICENSE_OPTION ] ) ) : '';
			update_option( self::LICENSE_OPTION, $license_key );
			if ( isset( $_POST['invenza_pro_activate'] ) ) {
				self::activate_license( $license_key );
			} else {
				self::deactivate_license( $license_key );
			}
			set_transient( 'invenza_pro_settings_errors', get_settings_errors( 'invenza_pro_messages' ), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=invenza-captcha-pro' ) );
			exit;
		}
	}

	private static function get_domain() {
		$host = parse_url( home_url(), PHP_URL_HOST );
		if ( empty( $host ) ) {
			$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
		}
		return preg_replace( '/^www\./i', '', strtolower( $host ) );
	}

	private static function activate_license( $license_key ) {
		$domain    = self::get_domain();
		$timestamp = (string) time();
		$nonce     = wp_generate_password( 12, false );
		$secret    = $license_key;
		$signature = hash_hmac( 'sha256', $domain . '|' . $timestamp . '|' . $nonce, $secret );

		$server_url = rtrim( self::LICENSE_SERVER, '/' ) . '/wp-json/fcac-server/v1/activate';
		$response = wp_remote_post( $server_url, array(
			'timeout' => 15,
			'body'    => array(
				'license_key' => $license_key,
				'domain'      => $domain,
				'timestamp'   => $timestamp,
				'nonce'       => $nonce,
				'signature'   => $signature,
			),
		) );

		if ( is_wp_error( $response ) ) {
			update_option( self::STATUS_OPTION, 'inactive' );
			add_settings_error( 'invenza_pro_messages', 'invenza_pro_error', __( 'Connection error to License Server.', 'invenza-captcha-pro' ), 'error' );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && isset( $body['status'] ) && 'success' === $body['status'] && ! empty( $body['token'] ) ) {
			$payload = json_decode( base64_decode( $body['token'] ), true );
			if ( isset( $payload['status'] ) && 'valid' === $payload['status'] ) {
				update_option( self::STATUS_OPTION, 'active' );
				if ( ! empty( $payload['expires_at'] ) ) {
					update_option( self::EXPIRES_OPTION, $payload['expires_at'] );
				}
				delete_option( self::NOTICE_OPTION );
				self::schedule_daily_check();
				add_settings_error( 'invenza_pro_messages', 'invenza_pro_success', __( 'License activated successfully.', 'invenza-captcha-pro' ), 'updated' );
				return;
			}
		}

		update_option( self::STATUS_OPTION, 'inactive' );
		$msg = isset( $body['message'] ) ? $body['message'] : __( 'Invalid or inactive license key.', 'invenza-captcha-pro' );
		add_settings_error( 'invenza_pro_messages', 'invenza_pro_error', $msg, 'error' );
	}

	private static function deactivate_license( $license_key ) {
		$domain    = self::get_domain();
		$timestamp = (string) time();
		$nonce     = wp_generate_password( 12, false );
		$secret    = $license_key;
		$signature = hash_hmac( 'sha256', $domain . '|' . $timestamp . '|' . $nonce, $secret );

		$server_url = rtrim( self::LICENSE_SERVER, '/' ) . '/wp-json/fcac-server/v1/deactivate';
		wp_remote_post( $server_url, array(
			'timeout' => 15,
			'body'    => array(
				'license_key' => $license_key,
				'domain'      => $domain,
				'timestamp'   => $timestamp,
				'nonce'       => $nonce,
				'signature'   => $signature,
			),
		) );
		
		update_option( self::STATUS_OPTION, 'inactive' );
		self::reset_to_free_settings( 'deactivated' );
		self::unschedule_daily_check();
		add_settings_error( 'invenza_pro_messages', 'invenza_pro_success', __( 'License deactivated successfully.', 'invenza-captcha-pro' ), 'updated' );
	}

	public static function render_settings_page() {
		$license = get_option( self::LICENSE_OPTION, '' );
		$status  = get_option( self::STATUS_OPTION, 'inactive' );
		$errors = get_transient( 'invenza_pro_settings_errors' );
		if ( ! empty( $errors ) && is_array( $errors ) ) {
			foreach ( $errors as $error ) {
				add_settings_error( 'invenza_pro_messages', $error['code'], $error['message'], $error['type'] );
			}
			delete_transient( 'invenza_pro_settings_errors' );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Invenza CAPTCHA Pro License', 'invenza-captcha-pro' ); ?></h1>
			<?php settings_errors( 'invenza_pro_messages' ); ?>
			<div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
				<form method="post" action="">
					<?php wp_nonce_field( 'invenza_pro_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="invenza_pro_key"><?php esc_html_e( 'License Key', 'invenza-captcha-pro' ); ?></label></th>
							<td>
								<input type="text" id="invenza_pro_key" name="<?php echo esc_attr( self::LICENSE_OPTION ); ?>" value="<?php echo esc_attr( $license ); ?>" class="regular-text" <?php echo $status === 'active' ? 'readonly' : ''; ?> />
								<p class="description">
									<?php if ( $status === 'active' ) : ?>
										<span style="color: green; font-weight: bold;">&#10003; <?php esc_html_e( 'Active', 'invenza-captcha-pro' ); ?></span>
									<?php else : ?>
										<span style="color: red; font-weight: bold;">&#10007; <?php esc_html_e( 'Inactive', 'invenza-captcha-pro' ); ?></span>
									<?php endif; ?>
								</p>
							</td>
						</tr>
					</table>
					<p class="submit">
						<?php if ( $status !== 'active' ) : ?>
							<input type="submit" name="invenza_pro_activate" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Activate License', 'invenza-captcha-pro' ); ?>">
						<?php else : ?>
							<input type="submit" name="invenza_pro_deactivate" id="submit" class="button" value="<?php esc_attr_e( 'Deactivate License', 'invenza-captcha-pro' ); ?>">
						<?php endif; ?>
					</p>
				</form>
			</div>

			<!-- Priority Support Ticket Section for Pro Users -->
			<div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px; border-left: 4px solid #2563eb;">
				<h2><?php esc_html_e( '🎟 Priority Ticket Support', 'invenza-captcha-pro' ); ?></h2>

				<?php if ( 'active' === $status ) : ?>
					<?php
					if ( isset( $_POST['invenza_pro_submit_ticket'] ) && check_admin_referer( 'invenza_ticket_nonce' ) ) {
						$sub     = sanitize_text_field( wp_unslash( $_POST['ticket_subject'] ?? '' ) );
						$msg     = sanitize_textarea_field( wp_unslash( $_POST['ticket_message'] ?? '' ) );
						$email   = sanitize_email( wp_unslash( $_POST['ticket_email'] ?? get_option( 'admin_email' ) ) );
						$domain  = self::get_domain();
						$env     = sprintf( 'WP: %s | PHP: %s | URL: %s', get_bloginfo( 'version' ), PHP_VERSION, home_url() );

						$resp = wp_remote_post( rtrim( self::LICENSE_SERVER, '/' ) . '/wp-json/fcac-server/v1/support/ticket', array(
							'timeout' => 15,
							'body'    => array(
								'license_key'      => $license,
								'domain'           => $domain,
								'email'            => $email,
								'subject'          => $sub,
								'message'          => $msg,
								'environment_info' => $env,
							),
						) );

						if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
							$res_body = json_decode( wp_remote_retrieve_body( $resp ), true );
							echo '<div class="notice notice-success inline" style="margin:10px 0;"><p>✅ ' . esc_html( $res_body['message'] ?? 'Ticket submitted!' ) . '</p></div>';
						} else {
							echo '<div class="notice notice-error inline" style="margin:10px 0;"><p>❌ Could not submit ticket. Please check your connection.</p></div>';
						}
					}
					?>
					<p style="color:#4b5563; font-size:13px;">
						<?php esc_html_e( 'As an active Pro subscriber, you receive guaranteed 24/7 priority ticket support directly from our core engineering team.', 'invenza-captcha-pro' ); ?>
					</p>
					<form method="post" action="">
						<?php wp_nonce_field( 'invenza_ticket_nonce' ); ?>
						<p>
							<label style="font-weight:600; display:block; margin-bottom:4px;"><?php esc_html_e( 'Contact Email', 'invenza-captcha-pro' ); ?></label>
							<input type="email" name="ticket_email" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text" required />
						</p>
						<p>
							<label style="font-weight:600; display:block; margin-bottom:4px;"><?php esc_html_e( 'Ticket Subject', 'invenza-captcha-pro' ); ?></label>
							<input type="text" name="ticket_subject" placeholder="e.g. Issue with WPForms integration" class="regular-text" required />
						</p>
						<p>
							<label style="font-weight:600; display:block; margin-bottom:4px;"><?php esc_html_e( 'Message Details', 'invenza-captcha-pro' ); ?></label>
							<textarea name="ticket_message" rows="4" class="large-text" placeholder="Describe the issue or question..." required></textarea>
						</p>
						<p class="submit">
							<input type="submit" name="invenza_pro_submit_ticket" class="button button-primary" value="<?php esc_attr_e( '🚀 Submit Priority Ticket', 'invenza-captcha-pro' ); ?>" style="background:#2563eb; border-color:#2563eb;" />
						</p>
					</form>
				<?php else : ?>
					<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px;">
						<strong style="color:#475569;"><?php esc_html_e( 'Priority Ticket Support is locked.', 'invenza-captcha-pro' ); ?></strong>
						<p style="font-size:13px; color:#64748b; margin:6px 0 10px;">
							<?php esc_html_e( 'Enter and activate your Pro license key above to unlock Priority Ticket Support. Free users can get help on the WordPress.org Community Forum.', 'invenza-captcha-pro' ); ?>
						</p>
						<a href="https://wordpress.org/support/plugin/invenza-captcha-for-all-forms/" target="_blank" rel="noopener noreferrer" class="button button-secondary">
							<?php esc_html_e( '🌐 Visit Free Community Support Forum', 'invenza-captcha-pro' ); ?> ↗
						</a>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}

InvenzaCaptchaPro::init();

/**
 * Handles automatic updates for Invenza CAPTCHA Pro via custom license server.
 */
class InvenzaCaptchaProUpdater {

	const PLUGIN_SLUG = 'invenza-captcha-pro/invenza-captcha-pro.php';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api_info' ), 20, 3 );
	}

	private static function get_domain() {
		$host = parse_url( home_url(), PHP_URL_HOST );
		if ( empty( $host ) ) {
			$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
		}
		return preg_replace( '/^www\./i', '', strtolower( $host ) );
	}

	private static function fetch_remote_update_info() {
		$license_key = get_option( InvenzaCaptchaPro::LICENSE_OPTION, '' );
		if ( empty( $license_key ) ) {
			return false;
		}

		$domain    = self::get_domain();
		$timestamp = (string) time();
		$nonce     = wp_generate_password( 12, false );
		$secret    = $license_key;
		$signature = hash_hmac( 'sha256', $domain . '|' . $timestamp . '|' . $nonce, $secret );

		$server_url = add_query_arg(
			array(
				'license_key' => $license_key,
				'domain'      => $domain,
				'timestamp'   => $timestamp,
				'nonce'       => $nonce,
				'signature'   => $signature,
			),
			rtrim( InvenzaCaptchaPro::LICENSE_SERVER, '/' ) . '/wp-json/fcac-server/v1/update-check'
		);

		$response = wp_remote_get( $server_url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['token'] ) ) {
			return false;
		}

		return json_decode( base64_decode( $body['token'] ), true );
	}

	public static function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$info = self::fetch_remote_update_info();
		if ( ! $info || empty( $info['new_version'] ) ) {
			return $transient;
		}

		$current_version = '1.0.0'; // Current installed version

		if ( version_compare( $info['new_version'], $current_version, '>' ) ) {
			$obj              = new \stdClass();
			$obj->slug        = 'invenza-captcha-pro';
			$obj->plugin      = self::PLUGIN_SLUG;
			$obj->new_version = $info['new_version'];
			$obj->url         = InvenzaCaptchaPro::LICENSE_SERVER;
			$obj->package     = $info['package'] ?? '';
			$obj->icons       = array( 'default' => 'https://license.isweb.in/assets/img/icon.png' );

			$transient->response[ self::PLUGIN_SLUG ] = $obj;
		} else {
			$transient->no_update[ self::PLUGIN_SLUG ] = (object) array(
				'slug'        => 'invenza-captcha-pro',
				'plugin'      => self::PLUGIN_SLUG,
				'new_version' => $current_version,
				'url'         => InvenzaCaptchaPro::LICENSE_SERVER,
				'package'     => '',
			);
		}

		return $transient;
	}

	public static function plugins_api_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'invenza-captcha-pro' !== $args->slug ) {
			return $res;
		}

		$info = self::fetch_remote_update_info();
		if ( ! $info ) {
			return $res;
		}

		$res                = new \stdClass();
		$res->name          = $info['name'] ?? 'Invenza CAPTCHA Pro Add-on';
		$res->slug          = 'invenza-captcha-pro';
		$res->version       = $info['new_version'] ?? '1.0.0';
		$res->author        = '<a href="https://license.isweb.in/">Invenza</a>';
		$res->homepage      = 'https://license.isweb.in/';
		$res->download_link = $info['package'] ?? '';

		if ( isset( $info['sections'] ) && is_array( $info['sections'] ) ) {
			$res->sections = $info['sections'];
		}

		return $res;
	}
}
