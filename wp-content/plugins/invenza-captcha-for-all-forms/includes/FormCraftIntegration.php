<?php
namespace Invcaf\Includes;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FormCraft Hook Integration and Compatibility Layer.
 */
class FormCraftIntegration {

	/**
	 * Run-once execution guard flag.
	 *
	 * @var bool
	 */
	private $validated = false;

	/**
	 * Constructor. Registers compatibility hooks.
	 */
	public function __construct() {
		// Hook compatibility adapter system: Register on all potential hooks.
		$compatibility_hooks = array(
			'formcraft_before_save',
			'formcraft_after_validation',
			'formcraft_submission_before_save',
		);

		foreach ( $compatibility_hooks as $hook ) {
			add_action( $hook, array( $this, 'intercept_submission' ), 10, 4 );
		}

		// Admin notices for compatibility.
		add_action( 'admin_notices', array( $this, 'admin_notice_compatibility' ) );
	}

	/**
	 * Check if the FormCraft plugin is installed and active.
	 *
	 * @return bool True if active, false otherwise.
	 */
	public static function is_formcraft_active() {
		if ( class_exists( 'FormCraft' ) || defined( 'FORMCRAFT_VERSION' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Support multiple potential folder/slug names for FormCraft.
		$slugs = array(
			'formcraft/formcraft.php',
			'formcraft/formcraft-main.php',
			'formcraft-form-builder/formcraft.php',
			'formcraft-form-builder/formcraft-main.php',
			'formcraft3/formcraft.php',
			'formcraft3/formcraft-main.php',
			'formcraft-premium/formcraft.php',
			'formcraft-premium/formcraft-main.php',
		);

		foreach ( $slugs as $slug ) {
			if ( is_plugin_active( $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Intercept FormCraft submissions and validate CAPTCHA.
	 *
	 * @param mixed $content Form content or first argument.
	 * @param mixed $meta Form metadata or second argument.
	 * @param mixed $raw_content Raw content or third argument.
	 * @param mixed $integrations Integrations list or fourth argument.
	 */
	public function intercept_submission( $content = null, $meta = null, $raw_content = null, $integrations = null ) {
		// 1. Guard against duplicate execution.
		if ( $this->validated ) {
			return;
		}

		// 2. Check if general integration is enabled.
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'fc_enabled' ) !== '1' ) {
			return;
		}

		// 3. Dynamic Form ID Extraction.
		$form_id = 0;

		// Check parameter $content (typically $content['Form ID'] inside formcraft_before_save).
		if ( is_array( $content ) && isset( $content['Form ID'] ) ) {
			$form_id = absint( $content['Form ID'] );
		} elseif ( is_numeric( $content ) ) {
			$form_id = absint( $content );
		}

		// Fallback to reading parameters from POST request.
		// Third-party form hooks don't carry WP nonces; nonce verification is handled upstream by FormCraft.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! $form_id ) {
			if ( isset( $_POST['id'] ) ) {
				$form_id = absint( wp_unslash( $_POST['id'] ) );
			} elseif ( isset( $_POST['form_id'] ) ) {
				$form_id = absint( wp_unslash( $_POST['form_id'] ) );
			} elseif ( isset( $_POST['form_info']['id'] ) ) {
				$form_id = absint( wp_unslash( $_POST['form_info']['id'] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $form_id ) {
			return; // If form ID cannot be resolved, skip interception.
		}

		// 4. Verify if CAPTCHA is enabled for this form ID.
		$all_forms = Settings::get( 'fc_all_forms' ) === '1';
		if ( ! $all_forms ) {
			$forms_list = Settings::get( 'fc_forms', '' );
			// Split by comma or line breaks.
			$selected_forms = array_map( 'absint', preg_split( '/[\s,]+/', $forms_list ) );

			if ( ! in_array( $form_id, $selected_forms, true ) ) {
				return; // Skip if this form is not targetted.
			}
		}

		// Set the validated flag so we do not run again in this thread.
		$this->validated = true;

		// 5. Retrieve CAPTCHA input values from $_POST payload or raw input stream.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$post_data = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( empty( $post_data ) ) {
			$raw_body = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_get_contents_file_get_contents
			if ( ! empty( $raw_body ) ) {
				if ( strpos( $raw_body, '=' ) !== false && strpos( $raw_body, '{' ) === false ) {
					parse_str( $raw_body, $post_data );
				} else {
					$json_data = json_decode( $raw_body, true );
					if ( is_array( $json_data ) ) {
						$post_data = $json_data;
					}
				}
			}
		}

		$code        = isset( $post_data['invcaf_code'] ) ? sanitize_text_field( wp_unslash( $post_data['invcaf_code'] ) ) : ( isset( $post_data['invenza_captcha_code'] ) ? sanitize_text_field( wp_unslash( $post_data['invenza_captcha_code'] ) ) : '' );
		$session_key = isset( $post_data['invcaf_session_key'] ) ? sanitize_key( wp_unslash( $post_data['invcaf_session_key'] ) ) : ( isset( $post_data['invenza_captcha_session_key'] ) ? sanitize_key( wp_unslash( $post_data['invenza_captcha_session_key'] ) ) : '' );
		$honeypot    = isset( $post_data['invcaf_honeypot'] ) ? sanitize_text_field( wp_unslash( $post_data['invcaf_honeypot'] ) ) : ( isset( $post_data['invenza_captcha_honeypot'] ) ? sanitize_text_field( wp_unslash( $post_data['invenza_captcha_honeypot'] ) ) : '' );

		// 6. Validate CAPTCHA.
		$validation_result = CaptchaValidator::validate( $form_id, $session_key, $code, $honeypot );

		if ( true !== $validation_result ) {
			// Halt execution and return error in FormCraft JSON structure.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- accessing FormCraft global response structure for integration
			global $fc_final_response;

			if ( ! is_array( $fc_final_response ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
				$fc_final_response = array(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			}

			// Format response error.
			$fc_final_response['failed'] = $validation_result; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

			// Clear buffers and exit with json response.
			if ( ! headers_sent() ) {
				header( 'Content-Type: application/json; charset=utf-8' );
			}

			echo wp_json_encode( $fc_final_response ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			exit;
		}
	}

	/**
	 * Check whether any supported form plugin is active.
	 *
	 * @return bool
	 */
	public static function is_any_supported_plugin_active(): bool {
		if ( self::is_formcraft_active() ) {
			return true;
		}

		// Contact Form 7.
		if ( function_exists( 'wpcf7' ) || class_exists( 'WPCF7' ) ) {
			return true;
		}

		// WPForms.
		if ( function_exists( 'wpforms' ) || class_exists( 'WPForms_Field' ) ) {
			return true;
		}

		// Forminator.
		if ( class_exists( 'Forminator' ) || function_exists( 'forminator_api' ) ) {
			return true;
		}

		// Gravity Forms.
		if ( class_exists( 'GFForms' ) || class_exists( 'GF_Fields' ) ) {
			return true;
		}

		// Fluent Forms.
		if ( function_exists( 'wpFluentForm' ) || defined( 'FLUENTFORM' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Render dismissible compatibility admin warning if no supported form plugin is active.
	 */
	public function admin_notice_compatibility() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( self::is_any_supported_plugin_active() ) {
			return;
		}

		$class   = 'notice notice-warning is-dismissible';
		$message = __( 'Invcaf CAPTCHA: No compatible form plugin detected. Please activate Contact Form 7, WPForms, Forminator, Gravity Forms, Fluent Forms, or FormCraft to display CAPTCHA in your forms.', 'invenza-captcha-for-all-forms' );

		printf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}
}
