<?php
namespace Invcaf\Includes\Integrations;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Invcaf\Includes\Settings;
use Invcaf\Includes\CaptchaValidator;
use Invcaf\Frontend\Renderer;

/**
 * WPForms Integration.
 *
 * Registers a custom WPForms field type 'invcaf_captcha' that renders the
 * CAPTCHA widget inside the WPForms field and validates on submission.
 */
class WPFormsIntegration {

	/**
	 * Constructor. Registers WPForms hooks.
	 */
	public function __construct() {
		// Register the custom field class after WPForms is loaded.
		add_action( 'wpforms_loaded', array( $this, 'register_field' ) );

		// Validate the CAPTCHA field on submission.
		add_action( 'wpforms_process_validate_invcaf_captcha', array( $this, 'validate_field' ), 10, 3 );
		add_action( 'wpforms_process_validate_invenza_captcha_captcha', array( $this, 'validate_field' ), 10, 3 );

		// Enqueue assets on front end when WPForms is present.
		add_action( 'wpforms_frontend_output_before', array( $this, 'enqueue_assets' ), 10, 2 );
		add_action( 'wpforms_builder_enqueues', array( $this, 'enqueue_assets' ) );

		// Auto-inject CAPTCHA if manual field is missing.
		add_action( 'wpforms_display_submit_before', array( $this, 'auto_inject_captcha' ), 10, 1 );

		// Global validation for auto-injected CAPTCHA.
		add_filter( 'wpforms_process_initial_errors', array( $this, 'global_validate' ), 10, 2 );
	}

	/**
	 * Register the WPForms field class.
	 */
	public function register_field() {
		if ( ! function_exists( 'wpforms' ) || ! class_exists( 'WPForms_Field' ) ) {
			return;
		}
		require_once __DIR__ . '/wpforms/class-wpforms-field-invcaf-captcha.php';
	}

	/**
	 * Validate CAPTCHA field on form submission.
	 *
	 * @param int   $field_id   Field ID.
	 * @param array $field_data Field settings data.
	 * @param array $form_data  Full form data.
	 */
	public function validate_field( $field_id, $field_data, $form_data ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'wpforms_enabled' ) !== '1' ) {
			return;
		}

		$form_id     = absint( $form_data['id'] );
		$code        = isset( $_POST['invcaf_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_code'] ) ) : ( isset( $_POST['invenza_captcha_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_code'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_key = isset( $_POST['invcaf_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invcaf_session_key'] ) ) : ( isset( $_POST['invenza_captcha_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invenza_captcha_session_key'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$honeypot    = isset( $_POST['invcaf_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_honeypot'] ) ) : ( isset( $_POST['invenza_captcha_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_honeypot'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$validation = CaptchaValidator::validate( $form_id, $session_key, $code, $honeypot );

		if ( true !== $validation ) {
			wpforms()->process->errors[ $form_id ][ $field_id ] = $validation;
		}
	}

	/**
	 * Enqueue CAPTCHA styles/scripts before WPForms output.
	 *
	 * @param array $form_data Form data (optional in builder).
	 * @param mixed $deprecated Deprecated argument.
	 */
	public function enqueue_assets( $form_data = array(), $deprecated = null ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'wpforms_enabled' ) !== '1' ) {
			return;
		}
		wp_enqueue_style( 'invcaf-captcha-style' );
		wp_enqueue_script( 'invcaf-captcha-script' );
	}

	/**
	 * Automatically inject the CAPTCHA before the submit button if missing.
	 *
	 * @param array $form_data Form data.
	 */
	public function auto_inject_captcha( $form_data ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'wpforms_enabled' ) !== '1' ) {
			return;
		}

		$form_id = absint( $form_data['id'] );

		if ( ! self::is_form_targeted( $form_id ) ) {
			return;
		}

		// Check if the field was manually added.
		if ( ! empty( $form_data['fields'] ) ) {
			foreach ( $form_data['fields'] as $field ) {
				if ( isset( $field['type'] ) && ( 'invcaf_captcha' === $field['type'] || 'invenza_captcha_captcha' === $field['type'] ) ) {
					return;
				}
			}
		}

		// Inject the widget inside a standard WPForms field container wrapper.
		$captcha_html = Renderer::render( $form_id );
		echo '<div class="wpforms-field wpforms-field-invcaf_captcha invcaf-auto-injected-wpforms">';
		echo $captcha_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * Validate auto-injected CAPTCHA globally.
	 *
	 * @param array $errors    List of errors.
	 * @param array $form_data Form data.
	 * @return array Modified errors.
	 */
	public function global_validate( $errors, $form_data ) {
		if ( '1' !== Settings::get( 'enabled' ) || '1' !== Settings::get( 'wpforms_enabled' ) ) {
			return $errors;
		}

		$form_id = absint( $form_data['id'] );

		if ( ! self::is_form_targeted( $form_id ) ) {
			return $errors;
		}

		// Check if it was manually added (if so, validate_field will handle it).
		if ( ! empty( $form_data['fields'] ) ) {
			foreach ( $form_data['fields'] as $field ) {
				if ( isset( $field['type'] ) && ( 'invcaf_captcha' === $field['type'] || 'invenza_captcha_captcha' === $field['type'] ) ) {
					return $errors;
				}
			}
		}

		$code        = isset( $_POST['invcaf_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_code'] ) ) : ( isset( $_POST['invenza_captcha_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_code'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_key = isset( $_POST['invcaf_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invcaf_session_key'] ) ) : ( isset( $_POST['invenza_captcha_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invenza_captcha_session_key'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$honeypot    = isset( $_POST['invcaf_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_honeypot'] ) ) : ( isset( $_POST['invenza_captcha_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_honeypot'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$validation = CaptchaValidator::validate( $form_id, $session_key, $code, $honeypot );

		if ( true !== $validation ) {
			$errors[ $form_id ]['header'] = $validation;
		}

		return $errors;
	}

	/**
	 * Check whether this form is targeted by integration settings.
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	public static function is_form_targeted( int $form_id ): bool {
		if ( Settings::get( 'wpforms_all_forms' ) === '1' ) {
			return true;
		}

		$forms_list     = Settings::get( 'wpforms_forms', '' );
		$selected_forms = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $forms_list ) ) );

		return in_array( $form_id, $selected_forms, true );
	}
}
