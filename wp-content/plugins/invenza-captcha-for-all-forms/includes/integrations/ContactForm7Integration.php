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
 * Contact Form 7 Integration.
 *
 * Registers a [invcaf_captcha] CF7 form tag that renders the CAPTCHA widget,
 * and validates it on submission via the wpcf7_validate_* filter.
 */
class ContactForm7Integration {

	/**
	 * Constructor. Registers CF7 hooks.
	 */
	public function __construct() {
		// Register the [invcaf_captcha] form tag when CF7 initialises.
		add_action( 'wpcf7_init', array( $this, 'register_form_tag' ) );

		// Enqueue assets alongside CF7 scripts.
		add_action( 'wpcf7_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Block submissions flagged as spam.
		add_filter( 'wpcf7_spam', array( $this, 'check_spam' ), 10, 2 );

		// Auto-inject CAPTCHA if manual tag is missing.
		add_filter( 'wpcf7_form_elements', array( $this, 'auto_inject_captcha' ), 10, 1 );

		// Global validation handler for all targeted forms.
		add_filter( 'wpcf7_validate', array( $this, 'global_validate' ), 10, 2 );
	}

	/**
	 * Register the [invcaf_captcha] shortcode tag with CF7.
	 */
	public function register_form_tag() {
		if ( ! function_exists( 'wpcf7_add_form_tag' ) ) {
			return;
		}

		wpcf7_add_form_tag(
			array( 'invcaf_captcha', 'invcaf_captcha*', 'invenza_captcha_captcha', 'invenza_captcha_captcha*' ),
			array( $this, 'render_tag' ),
			array( 'name-attr' => true )
		);
	}

	/**
	 * Render CAPTCHA widget when the [invcaf_captcha] tag is encountered.
	 *
	 * @param \WPCF7_FormTag $tag CF7 form tag object.
	 * @return string HTML markup.
	 */
	public function render_tag( $tag ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'cf7_enabled' ) !== '1' ) {
			return '';
		}

		// Derive a pseudo form ID from the CF7 form tag name or post ID.
		$form_id = absint( $this->get_current_form_id() );

		if ( ! $this->is_form_targeted( $form_id ) ) {
			return '';
		}

		$captcha_html = Renderer::render( $form_id );
		return '<span class="wpcf7-form-control-wrap" data-name="invcaf_code">' . $captcha_html . '</span>';
	}

	/**
	 * Automatically inject the CAPTCHA into the form HTML if it wasn't manually added.
	 *
	 * @param string $content Form HTML content.
	 * @return string Modified Form HTML content.
	 */
	public function auto_inject_captcha( $content ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'cf7_enabled' ) !== '1' ) {
			return $content;
		}

		$form_id = absint( $this->get_current_form_id() );

		if ( ! $this->is_form_targeted( $form_id ) ) {
			return $content;
		}

		// If the manual tag was already processed, the wrapper class will be present.
		if ( strpos( $content, 'fc-captcha-wrapper' ) !== false || strpos( $content, 'invcaf-captcha-wrapper' ) !== false ) {
			return $content;
		}

		// Generate the widget HTML and wrap it for inline errors.
		$captcha_html = '<span class="wpcf7-form-control-wrap" data-name="invcaf_code">' . Renderer::render( $form_id ) . '</span>';

		// Inject before the submit button if found, otherwise append to the end.
		$pattern = '/(<input[^>]+type=[\'"]submit[\'"][^>]*>|<button[^>]+type=[\'"]submit[\'"][^>]*>|<button[^>]+class=[\'"][^\'"]*wpcf7-submit[^\'"]*[\'"][^>]*>.*<\/button>)/i';

		if ( preg_match( $pattern, $content ) ) {
			$content = preg_replace( $pattern, $captcha_html . '$1', $content, 1 );
		} else {
			$content .= $captcha_html;
		}

		return $content;
	}

	/**
	 * Validate CAPTCHA on global form validation.
	 *
	 * @param \WPCF7_Validation $result Validation object.
	 * @param array             $tags   Array of form tags.
	 * @return \WPCF7_Validation
	 */
	public function global_validate( $result, $tags ) {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'cf7_enabled' ) !== '1' ) {
			return $result;
		}

		$form_id = absint( $this->get_current_form_id() );

		if ( ! $this->is_form_targeted( $form_id ) ) {
			return $result;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$code        = isset( $_POST['invcaf_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_code'] ) ) : ( isset( $_POST['invenza_captcha_code'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_code'] ) ) : '' );
		$session_key = isset( $_POST['invcaf_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invcaf_session_key'] ) ) : ( isset( $_POST['invenza_captcha_session_key'] ) ? sanitize_key( wp_unslash( $_POST['invenza_captcha_session_key'] ) ) : '' );
		$honeypot    = isset( $_POST['invcaf_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_honeypot'] ) ) : ( isset( $_POST['invenza_captcha_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_honeypot'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$validation = CaptchaValidator::validate( $form_id, $session_key, $code, $honeypot );

		if ( true !== $validation ) {
			$result->invalidate(
				array(
					'type' => 'invcaf_captcha',
					'name' => 'invcaf_code',
				),
				$validation
			);
		}

		return $result;
	}

	/**
	 * Additional spam filter - blocks at the spam-check stage if CAPTCHA already failed.
	 *
	 * @param bool              $spam    Current spam flag.
	 * @param \WPCF7_Submission $submission Submission object.
	 * @return bool
	 */
	public function check_spam( $spam, $submission ) {
		if ( $spam ) {
			return $spam;
		}

		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'cf7_enabled' ) !== '1' ) {
			return $spam;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$honeypot = isset( $_POST['invcaf_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invcaf_honeypot'] ) ) : ( isset( $_POST['invenza_captcha_honeypot'] ) ? sanitize_text_field( wp_unslash( $_POST['invenza_captcha_honeypot'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! empty( $honeypot ) ) {
			return true;
		}

		return $spam;
	}

	/**
	 * Enqueue CAPTCHA styles/scripts when CF7 assets are loaded.
	 */
	public function enqueue_assets() {
		if ( Settings::get( 'enabled' ) !== '1' || Settings::get( 'cf7_enabled' ) !== '1' ) {
			return;
		}
		wp_enqueue_style( 'invcaf-captcha-style' );
		wp_enqueue_script( 'invcaf-captcha-script' );
	}

	/**
	 * Check whether this form is targeted by the integration settings.
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	private function is_form_targeted( int $form_id ): bool {
		if ( Settings::get( 'cf7_all_forms' ) === '1' ) {
			return true;
		}

		$forms_list     = Settings::get( 'cf7_forms', '' );
		$selected_forms = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $forms_list ) ) );

		return in_array( $form_id, $selected_forms, true );
	}

	/**
	 * Derive the current CF7 form ID from the active submission or query.
	 *
	 * @return int
	 */
	private function get_current_form_id(): int {
		// During submission, the CF7 contact form object is available.
		if ( function_exists( 'wpcf7_get_current_contact_form' ) ) {
			$cf7 = wpcf7_get_current_contact_form();
			if ( $cf7 ) {
				return absint( $cf7->id() );
			}
		}

		// Fallback: read from POST.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['_wpcf7'] ) ) {
			return absint( wp_unslash( $_POST['_wpcf7'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return 0;
	}
}
