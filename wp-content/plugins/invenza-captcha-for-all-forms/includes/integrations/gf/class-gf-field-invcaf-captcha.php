<?php
// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Invcaf\Frontend\Renderer;
use Invcaf\Includes\Settings;

/**
 * Gravity Forms custom field type for the CAPTCHA widget.
 *
 * Registered via GF_Fields::register(). Renders the CAPTCHA image widget
 * inside GF forms when the field is added from the field picker.
 */
class GF_Field_Invcaf_Captcha extends \GF_Field { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

	/** @var string $type Field type identifier. */
	public $type = 'invcaf_captcha';

	/**
	 * Return the field title (shown in field picker).
	 *
	 * @return string
	 */
	public function get_form_editor_field_title() {
		return esc_attr__( 'Invcaf CAPTCHA', 'invenza-captcha-for-all-forms' );
	}

	/**
	 * Return field button settings (icon + group in editor).
	 *
	 * @return array
	 */
	public function get_form_editor_button() {
		return array(
			'group' => 'advanced_fields',
			'text'  => $this->get_form_editor_field_title(),
		);
	}

	/**
	 * Return the allowed field settings for the editor panel.
	 *
	 * @return array
	 */
	public function get_form_editor_field_settings() {
		return array(
			'label_setting',
			'description_setting',
			'css_class_setting',
		);
	}

	/**
	 * Return true — this field can always have a value (captcha code).
	 *
	 * @return bool
	 */
	public function is_conditional_logic_supported() {
		return false;
	}

	/**
	 * Render the CAPTCHA widget on the front end.
	 *
	 * @param array      $form       Form data.
	 * @param string     $value      Current field value (unused).
	 * @param array|null $entry      Entry data (null on front end).
	 * @return string
	 */
	public function get_field_input( $form, $value = '', $entry = null ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'gf_enabled' ) !== '1' ) {
			return '';
		}

		$form_id = absint( $form['id'] );

		return Renderer::render( $form_id );
	}
}

class_alias( 'GF_Field_Invcaf_Captcha', 'INVENZA_CAPTCHA_GF_Field_Captcha' );
class_alias( 'GF_Field_Invcaf_Captcha', 'GF_Field_Fcac_Captcha' );
