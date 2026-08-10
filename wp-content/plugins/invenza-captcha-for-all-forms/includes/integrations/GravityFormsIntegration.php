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
 * Gravity Forms Integration.
 *
 * Registers a custom GF_Field subclass of type 'invcaf_captcha' that renders
 * the CAPTCHA widget and validates it via the gform_validation filter.
 */
class GravityFormsIntegration {

	/**
	 * Constructor. Registers Gravity Forms hooks.
	 */
	public function __construct() {
		// Register the custom field type after GF classes are available.
		add_action( 'gform_loaded', array( $this, 'register_field' ), 5 );

		// Validate the entire form submission.
		add_filter( 'gform_validation', array( $this, 'validate_submission' ) );

		// Enqueue our assets on front-end GF pages (action hook - no return value needed).
		add_action( 'gform_enqueue_scripts', array( $this, 'enqueue_assets' ), 10, 2 );

		// Auto-inject CAPTCHA before the submit button.
		add_filter( 'gform_submit_button', array( $this, 'inject_captcha' ), 10, 2 );

		// Global validation message if CAPTCHA was auto-injected.
		add_filter( 'gform_validation_message', array( $this, 'global_validation_message' ), 10, 2 );
	}

	/**
	 * Register the GF_Field subclass.
	 */
	public function register_field() {
		if ( ! class_exists( 'GF_Fields' ) ) {
			return;
		}
		require_once __DIR__ . '/gf/class-gf-field-invcaf-captcha.php';
		if ( class_exists( 'GF_Field_Invcaf_Captcha' ) ) {
			\GF_Fields::register( new \GF_Field_Invcaf_Captcha() );
		}
	}

	/**
	 * Validate CAPTCHA on form submission.
	 *
	 * @param array $validation_result Gravity Forms validation data.
	 * @return array
	 */
	public function validate_submission( $validation_result ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'gf_enabled' ) !== '1' ) {
			return $validation_result;
		}

		$form    = $validation_result['form'];
		$form_id = absint( $form['id'] );

		if ( ! $this->is_form_targeted( $form_id ) ) {
			return $validation_result;
		}

		$has_captcha = false;
		foreach ( $form['fields'] as $field ) {
			if ( 'invcaf_captcha' === $field->type || 'invenza_captcha_captcha' === $field->type ) {
				$has_captcha = true;
				break;
			}
		}

		$code        = isset( $_POST['invcaf_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_code'] ) ) : ( isset( $_POST['invenza_captcha_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_code'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_key = isset( $_POST['invcaf_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invcaf_session_key'] ) ) : ( isset( $_POST['invenza_captcha_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invenza_captcha_session_key'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$honeypot    = isset( $_POST['invcaf_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_honeypot'] ) ) : ( isset( $_POST['invenza_captcha_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_honeypot'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$validation = CaptchaValidator::validate( $form_id, $session_key, $code, $honeypot );

		if ( true !== $validation ) {
			$validation_result['is_valid']   = false;
			$form['invcaf_validation_error'] = $validation;

			if ( $has_captcha ) {
				// Mark the explicit captcha field as failed.
				foreach ( $form['fields'] as &$field ) {
					if ( 'invcaf_captcha' === $field->type || 'invenza_captcha_captcha' === $field->type ) {
						$field->failed_validation  = true;
						$field->validation_message = $validation;
						break;
					}
				}
				unset( $field );
			}
			$validation_result['form'] = $form;
		}

		return $validation_result;
	}

	/**
	 * Enqueue CAPTCHA styles/scripts alongside GF assets.
	 *
	 * @param array $form    Current form data.
	 * @param bool  $is_ajax Whether AJAX submission is active.
	 */
	public function enqueue_assets( $form, $is_ajax ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'gf_enabled' ) !== '1' ) {
			return;
		}
		wp_enqueue_style( 'invcaf-captcha-style' );
		wp_enqueue_script( 'invcaf-captcha-script' );
	}

	/**
	 * Check whether this form is targeted by integration settings.
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	private function is_form_targeted( int $form_id ): bool {
		if ( Settings::get( 'gf_all_forms' ) === '1' ) {
			return true;
		}

		$forms_list     = Settings::get( 'gf_forms', '' );
		$selected_forms = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $forms_list ) ) );

		return in_array( $form_id, $selected_forms, true );
	}

	/**
	 * Auto-inject CAPTCHA HTML before the GF submit button.
	 *
	 * @param string $button_html Submit button HTML.
	 * @param array  $form Form array.
	 * @return string
	 */
	public function inject_captcha( $button_html, $form ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'gf_enabled' ) !== '1' ) {
			return $button_html;
		}

		$form_id = absint( $form['id'] );
		if ( ! $this->is_form_targeted( $form_id ) ) {
			return $button_html;
		}

		// Check if the form already has the field
		foreach ( $form['fields'] as $field ) {
			if ( 'invcaf_captcha' === $field->type || 'invenza_captcha_captcha' === $field->type ) {
				return $button_html; // Field exists manually, don't inject
			}
		}

		$captcha_html = Renderer::render( $form_id );
		return $captcha_html . $button_html;
	}

	/**
	 * Append global validation message if CAPTCHA was auto-injected.
	 *
	 * @param string $message Existing validation message.
	 * @param array  $form Form array.
	 * @return string
	 */
	public function global_validation_message( $message, $form ) {
		if ( isset( $form['invcaf_validation_error'] ) ) {
			return '<div class="validation_error gform_validation_error">' . esc_html( $form['invcaf_validation_error'] ) . '</div>';
		}
		if ( isset( $form['invenza_captcha_validation_error'] ) ) {
			return '<div class="validation_error gform_validation_error">' . esc_html( $form['invenza_captcha_validation_error'] ) . '</div>';
		}
		return $message;
	}
}
