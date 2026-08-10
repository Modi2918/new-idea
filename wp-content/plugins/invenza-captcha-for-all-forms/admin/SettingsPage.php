<?php
namespace Invcaf\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress Admin Settings Page registration.
 */
class SettingsPage {

	/**
	 * Constructor. Registers hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_tools_actions' ) );
		add_action( 'admin_notices', array( $this, 'display_gd_notice' ) );
		add_action( 'wp_ajax_invcaf_test_captcha', array( $this, 'ajax_test_captcha' ) );
		add_action( 'wp_ajax_invcaf_verify_license', array( $this, 'ajax_verify_license' ) );
	}

	/**
	 * Output GD missing notice
	 */
	public function display_gd_notice() {
		if ( ! extension_loaded( 'gd' ) ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'CRITICAL ERROR: GD Library Missing', 'invenza-captcha-for-all-forms' ); ?></strong><br>
					<?php esc_html_e( 'The Invcaf CAPTCHA plugin requires the PHP GD extension to securely generate challenge images. Please contact your hosting provider to enable the GD extension on your server, otherwise forms will show a static error image and cannot be submitted.', 'invenza-captcha-for-all-forms' ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Register option page.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Invcaf CAPTCHA Settings', 'invenza-captcha-for-all-forms' ),
			__( 'Invcaf CAPTCHA', 'invenza-captcha-for-all-forms' ),
			'manage_options',
			'invenza-captcha-for-all-forms',
			array( $this, 'render_settings_page' ),
			'dashicons-shield-alt',
			80
		);
	}

	/**
	 * Register Settings with dynamic sanitization callback.
	 */
	public function register_settings() {
		register_setting(
			'invcaf_settings_group',
			'invcaf_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Render settings page view.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'invenza-captcha-for-all-forms' ) );
		}

		// Load template file.
		$view_path = INVCAF_PLUGIN_DIR . 'admin/views/settings.php';
		if ( file_exists( $view_path ) ) {
			include $view_path;
		}
	}

	/**
	 * Sanitize settings inputs before database insertion (WP VIP Compliance).
	 *
	 * @param array $input Raw form settings data.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		// Toggles/Checkboxes.
		$sanitized['enabled']             = isset( $input['enabled'] ) && '1' === $input['enabled'] ? '1' : '0';
		$sanitized['fc_enabled']          = isset( $input['fc_enabled'] ) && '1' === $input['fc_enabled'] ? '1' : '0';
		$sanitized['fc_all_forms']        = isset( $input['fc_all_forms'] ) && '1' === $input['fc_all_forms'] ? '1' : '0';
		$sanitized['bg_noise']            = isset( $input['bg_noise'] ) && '1' === $input['bg_noise'] ? '1' : '0';
		$sanitized['lines']               = isset( $input['lines'] ) && '1' === $input['lines'] ? '1' : '0';
		$sanitized['dots']                = isset( $input['dots'] ) && '1' === $input['dots'] ? '1' : '0';
		$sanitized['logging_enabled']     = isset( $input['logging_enabled'] ) && '1' === $input['logging_enabled'] ? '1' : '0';
		$sanitized['delete_on_uninstall'] = isset( $input['delete_on_uninstall'] ) && '1' === $input['delete_on_uninstall'] ? '1' : '0';

		// Integer values bounding constraints.
		$sanitized['length']         = max( 4, min( 8, absint( $input['length'] ) ) );
		$sanitized['expiration']     = max( 1, min( 60, absint( $input['expiration'] ) ) );
		$sanitized['width']          = max( 100, min( 500, absint( $input['width'] ) ) );
		$sanitized['height']         = max( 30, min( 200, absint( $input['height'] ) ) );
		$sanitized['rate_limit']     = max( 10, min( 1000, absint( isset( $input['rate_limit'] ) ? $input['rate_limit'] : 50 ) ) );
		$sanitized['max_attempts']   = max( 1, min( 20, absint( $input['max_attempts'] ) ) );
		$sanitized['block_duration'] = max( 1, min( 1440, absint( $input['block_duration'] ) ) );

		// String inputs.
		$sanitized['fc_forms']    = isset( $input['fc_forms'] ) ? sanitize_textarea_field( $input['fc_forms'] ) : '';
		$sanitized['msg_invalid'] = isset( $input['msg_invalid'] ) ? sanitize_text_field( $input['msg_invalid'] ) : '';
		$sanitized['msg_expired'] = isset( $input['msg_expired'] ) ? sanitize_text_field( $input['msg_expired'] ) : '';
		$sanitized['theme']       = isset( $input['theme'] ) ? sanitize_key( $input['theme'] ) : 'light';

		// Character sets.
		$char_set           = isset( $input['char_set'] ) && is_array( $input['char_set'] ) ? $input['char_set'] : array();
		$sanitized_char_set = array();
		if ( in_array( 'letters', $char_set, true ) ) {
			$sanitized_char_set[] = 'letters';
		}
		if ( in_array( 'small_letters', $char_set, true ) ) {
			$sanitized_char_set[] = 'small_letters';
		}
		if ( in_array( 'numbers', $char_set, true ) ) {
			$sanitized_char_set[] = 'numbers';
		}
		if ( empty( $sanitized_char_set ) ) {
			$sanitized_char_set = array( 'letters', 'small_letters', 'numbers' ); // default fallback
		}
		$sanitized['char_set'] = $sanitized_char_set;

		// Inject position.
		$position = isset( $input['position'] ) ? sanitize_key( $input['position'] ) : 'before_submit';
		if ( in_array( $position, array( 'before_submit', 'after_fields', 'custom_shortcode' ), true ) ) {
			$sanitized['position'] = $position;
		} else {
			$sanitized['position'] = 'before_submit';
		}

		// Third-party integration toggles.
		$toggle_keys = array(
			'cf7_enabled',
			'cf7_all_forms',
			'wpforms_enabled',
			'wpforms_all_forms',
			'forminator_enabled',
			'forminator_all_forms',
			'gf_enabled',
			'gf_all_forms',
			'fluentforms_enabled',
			'fluentforms_all_forms',
		);
		foreach ( $toggle_keys as $toggle_key ) {
			$sanitized[ $toggle_key ] = isset( $input[ $toggle_key ] ) && '1' === $input[ $toggle_key ] ? '1' : '0';
		}

		// Third-party integration form ID lists.
		$forms_keys = array( 'cf7_forms', 'wpforms_forms', 'forminator_forms', 'gf_forms', 'fluentforms_forms' );
		foreach ( $forms_keys as $forms_key ) {
			$sanitized[ $forms_key ] = isset( $input[ $forms_key ] ) ? sanitize_textarea_field( $input[ $forms_key ] ) : '';
		}

		// License Key
		$sanitized['license_key'] = isset( $input['license_key'] ) ? sanitize_text_field( trim( $input['license_key'] ) ) : '';

		return $sanitized;
	}

	/**
	 * Handle admin tools actions (Export, Import, Reset Settings, Setup Wizard Dismissal).
	 */
	public function handle_tools_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Dismiss Setup Wizard.
		if ( isset( $_GET['invcaf_action'] ) && 'dismiss_wizard' === $_GET['invcaf_action'] ) {
			check_admin_referer( 'invcaf_dismiss_wizard' );
			update_option( 'invcaf_wizard_dismissed', '1' );
			wp_safe_redirect( remove_query_arg( array( 'invcaf_action', '_wpnonce' ) ) );
			exit;
		}

		// Export Settings as JSON.
		if ( isset( $_POST['invcaf_action'] ) && 'export_settings' === $_POST['invcaf_action'] ) {
			check_admin_referer( 'invcaf_tools_action', 'invcaf_tools_nonce' );
			$settings = \Invcaf\Includes\Settings::get_all();
			$data     = array(
				'plugin'   => 'invenza-captcha-for-all-forms',
				'version'  => INVCAF_VERSION,
				'exported' => time(),
				'settings' => $settings,
			);

			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="invcaf-captcha-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( $data, JSON_PRETTY_PRINT );
			exit;
		}

		// Import Settings from JSON.
		if ( isset( $_POST['invcaf_action'] ) && 'import_settings' === $_POST['invcaf_action'] ) {
			check_admin_referer( 'invcaf_tools_action', 'invcaf_tools_nonce' );

			$tmp_file = isset( $_FILES['import_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['import_file']['tmp_name'] ) ) : '';

			if ( empty( $tmp_file ) ) {
				add_settings_error( 'invcaf_tools', 'missing_file', __( 'Please choose a JSON settings file to import.', 'invenza-captcha-for-all-forms' ), 'error' );
				return;
			}

			$raw  = file_get_contents( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$json = json_decode( $raw, true );

			if ( ! is_array( $json ) || empty( $json['settings'] ) || ! is_array( $json['settings'] ) ) {
				add_settings_error( 'invcaf_tools', 'invalid_json', __( 'Invalid settings JSON file format.', 'invenza-captcha-for-all-forms' ), 'error' );
				return;
			}

			$sanitized = $this->sanitize_settings( $json['settings'] );
			update_option( 'invcaf_settings', $sanitized );
			\Invcaf\Includes\Settings::flush();

			add_settings_error( 'invcaf_tools', 'import_success', __( 'Settings imported successfully!', 'invenza-captcha-for-all-forms' ), 'updated' );
		}

		// Reset Settings to Default.
		if ( isset( $_POST['invcaf_action'] ) && 'reset_settings' === $_POST['invcaf_action'] ) {
			check_admin_referer( 'invcaf_tools_action', 'invcaf_tools_nonce' );
			delete_option( 'invcaf_settings' );
			delete_option( 'invenza_captcha_settings' );
			\Invcaf\Includes\Settings::flush();

			add_settings_error( 'invcaf_tools', 'reset_success', __( 'Settings reset to factory defaults.', 'invenza-captcha-for-all-forms' ), 'updated' );
		}
	}

	/**
	 * AJAX endpoint for Test CAPTCHA generation diagnostics.
	 */
	public function ajax_test_captcha() {
		check_ajax_referer( 'invcaf_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'invenza-captcha-for-all-forms' ) ) );
		}

		$gd_active       = extension_loaded( 'gd' ) && function_exists( 'imagecreatetruecolor' );
		$freetype_active = function_exists( 'imagettftext' );

		if ( ! $gd_active ) {
			wp_send_json_error(
				array(
					'message'  => __( 'GD Library extension is missing on server.', 'invenza-captcha-for-all-forms' ),
					'gd'       => false,
					'freetype' => false,
				)
			);
		}

		// Generate sample captcha image URL.
		$test_session_key = 'test_' . wp_generate_password( 10, false );
		$image_url        = add_query_arg(
			array(
				'fcaptcha' => 'generate',
				'id'       => 0,
				'c_id'     => $test_session_key,
				't'        => time(),
			),
			home_url( '/' )
		);

		wp_send_json_success(
			array(
				'message'   => __( 'GD Library is operational and functioning normally!', 'invenza-captcha-for-all-forms' ),
				'gd'        => true,
				'freetype'  => $freetype_active,
				'image_url' => $image_url,
			)
		);
	}

	/**
	 * AJAX endpoint to verify license key via https://license.isweb.in/
	 */
	public function ajax_verify_license() {
		check_ajax_referer( 'invcaf_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'invenza-captcha-for-all-forms' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( trim( wp_unslash( $_POST['license_key'] ) ) ) : '';

		if ( empty( $license_key ) ) {
			delete_option( 'invcaf_license_status' );
			wp_send_json_error( array( 'message' => __( 'Please enter a valid license key.', 'invenza-captcha-for-all-forms' ) ) );
		}

		$api_url  = 'https://license.isweb.in/';
		$response = wp_remote_post(
			$api_url,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => array(
					'action'      => 'verify_license',
					'license_key' => $license_key,
					'domain'      => home_url(),
					'item_name'   => 'Invenza CAPTCHA Pro',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: Error message */
						__( 'Unable to connect to license server (%s).', 'invenza-captcha-for-all-forms' ),
						$response->get_error_message()
					),
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( is_array( $data ) && ! empty( $data['valid'] ) ) {
			$status_data = array(
				'status'      => 'active',
				'license_key' => $license_key,
				'expires'     => isset( $data['expires'] ) ? sanitize_text_field( $data['expires'] ) : 'lifetime',
				'checked_at'  => time(),
			);
			update_option( 'invcaf_license_status', $status_data );

			// Save key in main settings too.
			$settings                = \Invcaf\Includes\Settings::get_all();
			$settings['license_key'] = $license_key;
			update_option( 'invcaf_settings', $settings );
			\Invcaf\Includes\Settings::flush();

			wp_send_json_success(
				array(
					'message' => __( 'License key verified and active! Thank you for supporting Invcaf CAPTCHA Pro.', 'invenza-captcha-for-all-forms' ),
					'status'  => 'active',
					'expires' => $status_data['expires'],
				)
			);
		} else {
			delete_option( 'invcaf_license_status' );
			$msg = ( is_array( $data ) && ! empty( $data['message'] ) ) ? sanitize_text_field( $data['message'] ) : __( 'Invalid or expired license key.', 'invenza-captcha-for-all-forms' );
			wp_send_json_error( array( 'message' => $msg ) );
		}
	}
}


