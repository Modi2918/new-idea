<?php
// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Invcaf\Includes\Settings;
use Invcaf\Includes\Integrations\WPFormsIntegration;
use Invcaf\Frontend\Renderer;

/**
 * WPForms custom field class for the CAPTCHA widget.
 *
 * Extends WPForms_Field so it appears in the WPForms field picker
 * and renders the CAPTCHA widget in the form.
 */
class INVCAF_WPForms_Field_Captcha extends \WPForms_Field {

	/**
	 * Initialise field metadata.
	 */
	public function init() {
		$this->name     = __( 'Invcaf CAPTCHA', 'invenza-captcha-for-all-forms' );
		$this->keywords = __( 'captcha, security, spam, bot, image', 'invenza-captcha-for-all-forms' );
		$this->type     = 'invcaf_captcha';
		$this->icon     = 'fa-shield';
		$this->order    = 200;
		$this->group    = 'fancy';
	}

	/**
	 * Render field options panel (editor side - no extra options needed).
	 *
	 * @param array $field      Field data.
	 * @param array $form_data  Full form data.
	 */
	public function field_options( $field ) {
		$this->field_option( 'basic-options', $field, array( 'markup' => 'open' ) );
		$this->field_option( 'label', $field );
		$this->field_option( 'description', $field );
		$this->field_option( 'basic-options', $field, array( 'markup' => 'close' ) );
	}

	/**
	 * Render field preview in the editor.
	 *
	 * @param array $field Field data.
	 */
	public function field_preview( $field ) {
		echo '<label>' . esc_html__( 'Invcaf CAPTCHA Security Widget', 'invenza-captcha-for-all-forms' ) . '</label>';
		echo '<div class="invcaf-wpforms-preview-container">';
		echo '<span class="dashicons dashicons-shield-alt invcaf-wpforms-preview-icon"></span>';
		echo esc_html__( 'CAPTCHA image widget will appear here', 'invenza-captcha-for-all-forms' );
		echo '</div>';
	}

	/**
	 * Render field on the front end.
	 *
	 * @param array $field      Field data.
	 * @param array $field_atts Field attributes.
	 * @param array $form_data  Full form data.
	 */
	public function field_display( $field, $field_atts, $form_data ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'wpforms_enabled' ) !== '1' ) {
			return;
		}

		$form_id = absint( $form_data['id'] );

		if ( ! WPFormsIntegration::is_form_targeted( $form_id ) ) {
			return;
		}

		echo Renderer::render( $form_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns pre-escaped HTML.
	}
}

// Self-instantiate so WPForms registers it.
new INVCAF_WPForms_Field_Captcha();

class_alias( 'INVCAF_WPForms_Field_Captcha', 'INVENZA_CAPTCHA_WPForms_Field_Captcha' );
class_alias( 'INVCAF_WPForms_Field_Captcha', 'WPForms_Field_Fcac_Captcha' );
