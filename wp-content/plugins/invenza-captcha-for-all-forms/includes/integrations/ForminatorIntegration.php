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
 * Forminator Integration.
 *
 * Uses Forminator's custom field hook API to inject and validate CAPTCHA.
 * Falls back to hooking pre-submit filter to block invalid submissions.
 */
class ForminatorIntegration {

	/**
	 * Constructor. Registers Forminator hooks.
	 */
	public function __construct() {
		// Enqueue CAPTCHA assets before form renders (action, not filter - no return value needed).
		add_action( 'forminator_before_form_render', array( $this, 'enqueue_assets' ), 10, 5 );

		// Validate before submission data is processed.
		add_filter( 'forminator_custom_form_submit_before_set_fields', array( $this, 'validate_submission' ), 10, 3 );

		// Block spam via is_submittable filter as a secondary guard.
		add_filter( 'forminator_cform_form_is_submittable', array( $this, 'check_submittable' ), 10, 3 );

		// Enable shortcode processing inside Forminator HTML fields so [invcaf_captcha] works.
		add_filter( 'forminator_replace_variables', 'do_shortcode' );

		// Auto-inject CAPTCHA before the submit button.
		add_filter( 'forminator_render_button_markup', array( $this, 'inject_captcha' ), 10, 2 );
	}

	/**
	 * Enqueue CAPTCHA assets when a Forminator form is rendered.
	 *
	 * @param int    $form_id    Form ID.
	 * @param string $form_type  Form type.
	 * @param int    $post_id    Post ID.
	 * @param array  $form_data  Full form data.
	 * @param object $module     Forminator module.
	 */
	public function enqueue_assets( $form_id, $form_type, $post_id, $form_data, $module ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'forminator_enabled' ) !== '1' ) {
			return;
		}
		wp_enqueue_style( 'invcaf-captcha-style' );
		wp_enqueue_script( 'invcaf-captcha-script' );
	}

	/**
	 * Validate CAPTCHA before Forminator sets submission fields.
	 *
	 * @param array  $response  Current response data.
	 * @param object $module    Forminator module.
	 * @param array  $post_data Submitted POST data.
	 * @return array
	 */
	public function validate_submission( $response, $module, $post_data ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'forminator_enabled' ) !== '1' ) {
			return $response;
		}

		$form_id = isset( $post_data['form_id'] ) ? absint( $post_data['form_id'] ) : 0;

		if ( ! $form_id || ! $this->is_form_targeted( $form_id ) ) {
			return $response;
		}

		$code        = isset( $_POST['invcaf_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_code'] ) ) : ( isset( $_POST['invenza_captcha_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_code'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_key = isset( $_POST['invcaf_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invcaf_session_key'] ) ) : ( isset( $_POST['invenza_captcha_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invenza_captcha_session_key'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$honeypot    = isset( $_POST['invcaf_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_honeypot'] ) ) : ( isset( $_POST['invenza_captcha_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_honeypot'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$validation = CaptchaValidator::validate( $form_id, $session_key, $code, $honeypot );

		if ( true !== $validation ) {
			$response['success'] = false;
			$response['error']   = $validation;
			$response['errors']  = array(
				array(
					'name'    => 'invcaf_captcha',
					'message' => $validation,
				),
			);
		}

		return $response;
	}

	/**
	 * Secondary guard: mark form as non-submittable if CAPTCHA is invalid.
	 *
	 * @param bool   $is_submittable Current submittable flag.
	 * @param object $module         Forminator module.
	 * @param array  $post_data      Submitted POST data.
	 * @return bool
	 */
	public function check_submittable( $is_submittable, $module, $post_data ) {
		if ( ! $is_submittable ) {
			return false;
		}

		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'forminator_enabled' ) !== '1' ) {
			return $is_submittable;
		}

		$honeypot = isset( $_POST['invcaf_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_honeypot'] ) ) : ( isset( $_POST['invenza_captcha_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_honeypot'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $honeypot ) ) {
			return false;
		}

		return $is_submittable;
	}

	/**
	 * Check whether this form is targeted by integration settings.
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	private function is_form_targeted( int $form_id ): bool {
		if ( Settings::get( 'forminator_all_forms' ) === '1' ) {
			return true;
		}

		$forms_list     = Settings::get( 'forminator_forms', '' );
		$selected_forms = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $forms_list ) ) );

		return in_array( $form_id, $selected_forms, true );
	}

	/**
	 * Auto-inject CAPTCHA HTML before the submit button.
	 *
	 * @param string $html Button HTML.
	 * @param int    $form_id Form ID.
	 * @return string
	 */
	public function inject_captcha( $html, $form_id = 0 ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'forminator_enabled' ) !== '1' ) {
			return $html;
		}

		if ( ! $form_id || ! $this->is_form_targeted( (int) $form_id ) ) {
			return $html;
		}

		$captcha_html = Renderer::render( (int) $form_id );

		// Ensure we don't duplicate if shortcode was manually used.
		if ( strpos( $html, 'invcaf-captcha-wrapper' ) !== false || strpos( $html, 'invcaf-captcha-container' ) !== false ) {
			return $html;
		}

		return $captcha_html . $html;
	}
}
