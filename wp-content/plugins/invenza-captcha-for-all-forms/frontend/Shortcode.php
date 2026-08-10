<?php
namespace Invcaf\Frontend;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register [invcaf_captcha] Shortcode.
 */
class Shortcode {

	/**
	 * Constructor. Registers shortcode hook.
	 */
	public function __construct() {
		add_shortcode( 'invcaf_captcha', array( $this, 'render_shortcode' ) );
		// Backward compatibility alias
		add_shortcode( 'invenza_captcha_captcha', array( $this, 'render_shortcode' ) );
		add_shortcode( 'captcha', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Shortcode callback handler.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output of CAPTCHA.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'invcaf_captcha'
		);

		$form_id = absint( $atts['id'] );

		return Renderer::render( $form_id );
	}
}
