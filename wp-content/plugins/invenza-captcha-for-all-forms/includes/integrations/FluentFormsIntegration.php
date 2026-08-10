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
 * Fluent Forms Integration.
 *
 * Registers a custom Fluent Forms input type 'invcaf_captcha' that renders
 * the CAPTCHA widget and validates it on submission.
 */
class FluentFormsIntegration {

	/**
	 * Constructor. Registers Fluent Forms hooks.
	 */
	public function __construct() {
		// Register the custom input type component.
		add_filter( 'fluentform/editor_components', array( $this, 'register_component' ) );

		// Render the custom field on the front end.
		add_action( 'fluentform/render_field_invcaf_captcha', array( $this, 'render_field' ), 10, 3 );
		add_action( 'fluentform/render_field_invenza_captcha_captcha', array( $this, 'render_field' ), 10, 3 );

		// Validate before inserting the submission.
		add_filter( 'fluentform/validate_input_item_invcaf_captcha', array( $this, 'validate_field' ), 10, 5 );
		add_filter( 'fluentform/validate_input_item_invenza_captcha_captcha', array( $this, 'validate_field' ), 10, 5 );

		// Enqueue assets on front-end form render.
		add_action( 'fluentform/before_form_render', array( $this, 'enqueue_assets' ), 10, 1 );

		// Validate submission globally for auto-injected CAPTCHA.
		add_action( 'fluentform/before_insert_submission', array( $this, 'global_validate' ), 10, 3 );
	}

	/**
	 * Register the invcaf_captcha component in the Fluent Forms editor.
	 *
	 * @param array $components Existing editor components.
	 * @return array
	 */
	public function register_component( $components ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'fluentforms_enabled' ) !== '1' ) {
			return $components;
		}

		$components['invcaf_captcha'] = array(
			'index'          => 16,
			'element'        => 'invcaf_captcha',
			'attributes'     => array(
				'name'  => 'invcaf_captcha',
				'label' => __( 'Invcaf CAPTCHA Security', 'invenza-captcha-for-all-forms' ),
			),
			'settings'       => array(
				'label'            => __( 'Invcaf CAPTCHA Security', 'invenza-captcha-for-all-forms' ),
				'label_placement'  => 'top',
				'validation_rules' => array(),
				'container_class'  => '',
				'element'          => 'invcaf_captcha',
			),
			'editor_options' => array(
				'title'      => __( 'Invcaf CAPTCHA', 'invenza-captcha-for-all-forms' ),
				'icon_class' => 'dashicons dashicons-shield-alt',
				'template'   => 'inputText',
				'category'   => 'general',
			),
		);

		return $components;
	}

	/**
	 * Render the CAPTCHA field on the front end.
	 *
	 * @param array  $data        Field element data.
	 * @param array  $form        Form data.
	 * @param object $form_model  Form model object.
	 */
	public function render_field( $data, $form, $form_model ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'fluentforms_enabled' ) !== '1' ) {
			return;
		}

		$form_id = is_object( $form ) ? absint( $form->id ?? 0 ) : absint( is_array( $form ) ? ( $form['id'] ?? 0 ) : $form );

		if ( ! $this->is_form_targeted( $form_id ) ) {
			return;
		}

		echo Renderer::render( $form_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns pre-escaped HTML.
	}

	/**
	 * Validate the CAPTCHA field input.
	 *
	 * @param array  $error_message  Current error messages.
	 * @param array  $field          Field settings.
	 * @param array  $form_data      Submitted form data.
	 * @param array  $fields         All form fields.
	 * @param object $form           Form object.
	 * @return array
	 */
	public function validate_field( $error_message, $field, $form_data, $fields, $form ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'fluentforms_enabled' ) !== '1' ) {
			return $error_message;
		}

		$form_id = absint( $form->id );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$code        = isset( $_POST['invcaf_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_code'] ) ) : ( isset( $_POST['invenza_captcha_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_code'] ) ) : '' );
		$session_key = isset( $_POST['invcaf_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invcaf_session_key'] ) ) : ( isset( $_POST['invenza_captcha_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invenza_captcha_session_key'] ) ) : '' );
		$honeypot    = isset( $_POST['invcaf_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_honeypot'] ) ) : ( isset( $_POST['invenza_captcha_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_honeypot'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$validation = CaptchaValidator::validate( $form_id, $session_key, $code, $honeypot );

		if ( true !== $validation ) {
			$error_message[] = $validation;
		}

		return $error_message;
	}

	/**
	 * Enqueue CAPTCHA styles/scripts and auto-inject via JS before Fluent Forms output.
	 *
	 * @param object $form Form object.
	 */
	public function enqueue_assets( $form ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'fluentforms_enabled' ) !== '1' ) {
			return;
		}

		$form_id = absint( $form->id );
		if ( ! $this->is_form_targeted( $form_id ) ) {
			return;
		}

		wp_enqueue_style( 'invcaf-captcha-style' );
		wp_enqueue_script( 'invcaf-captcha-script' );

		// Auto-inject via inline JS if the user hasn't manually placed the component.
		$fields_json = wp_json_encode( $form );
		if ( strpos( $fields_json, '"element":"invcaf_captcha"' ) === false && strpos( $fields_json, '"element":"invenza_captcha_captcha"' ) === false ) {
			$captcha_html = Renderer::render( $form_id );
			$escaped_html = wp_json_encode( $captcha_html );
			$inline_js    = "
				document.addEventListener('DOMContentLoaded', function() {
					var form = document.getElementById('fluentform_{$form_id}');
					if (form && !form.querySelector('.invcaf-captcha-wrapper') && !form.querySelector('.invcaf-captcha-container')) {
						var submitWrapper = form.querySelector('.ff_submit_btn_wrapper');
						if (submitWrapper) {
							submitWrapper.insertAdjacentHTML('beforebegin', {$escaped_html});
						} else {
							var fieldset = form.querySelector('fieldset');
							if (fieldset) {
								fieldset.insertAdjacentHTML('beforeend', {$escaped_html});
							}
						}
					}
				});
			";
			wp_add_inline_script( 'invcaf-captcha-script', $inline_js );
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Validate submission globally for auto-injected CAPTCHA.
	 *
	 * @param array  $insert_data Current insert data.
	 * @param array  $data        Submitted form data.
	 * @param object $form        Form object.
	 */
	public function global_validate( $insert_data, $data, $form ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'fluentforms_enabled' ) !== '1' ) {
			return;
		}

		$form_id = absint( $form->id );
		if ( ! $this->is_form_targeted( $form_id ) ) {
			return;
		}

		// Prevent double validation if component was manually placed.
		$fields_json = wp_json_encode( $form );
		if ( strpos( $fields_json, '"element":"invcaf_captcha"' ) !== false || strpos( $fields_json, '"element":"invenza_captcha_captcha"' ) !== false ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$code        = isset( $_POST['invcaf_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_code'] ) ) : ( isset( $_POST['invenza_captcha_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_code'] ) ) : '' );
		$session_key = isset( $_POST['invcaf_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invcaf_session_key'] ) ) : ( isset( $_POST['invenza_captcha_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invenza_captcha_session_key'] ) ) : '' );
		$honeypot    = isset( $_POST['invcaf_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_honeypot'] ) ) : ( isset( $_POST['invenza_captcha_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_honeypot'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$validation = CaptchaValidator::validate( $form_id, $session_key, $code, $honeypot );

		if ( true !== $validation ) {
			wp_send_json_error(
				array(
					'errors' => array(
						'invcaf_captcha' => array( $validation ),
					),
				),
				422
			);
		}
	}

	/**
	 * Check whether this form is targeted by integration settings.
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	private function is_form_targeted( int $form_id ): bool {
		if ( Settings::get( 'fluentforms_all_forms' ) === '1' ) {
			return true;
		}

		$forms_list     = Settings::get( 'fluentforms_forms', '' );
		$selected_forms = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $forms_list ) ) );

		return in_array( $form_id, $selected_forms, true );
	}
}
