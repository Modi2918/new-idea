<?php
namespace Invcaf\Frontend;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Invcaf\Includes\Settings;

/**
 * Frontend CAPTCHA HTML Renderer.
 */
class Renderer {

	/**
	 * Output accessible HTML markup for the CAPTCHA block.
	 *
	 * @param int $form_id Associated Form ID.
	 * @return string HTML Markup.
	 */
	public static function render( $form_id ) {
		// Verify if CAPTCHA is globally enabled.
		if ( Settings::get( 'enabled' ) !== '1' ) {
			return '';
		}

		$form_id = absint( $form_id );
		$theme   = sanitize_key( Settings::get( 'theme', 'light' ) );

		// Lazy load assets.
		wp_enqueue_style( 'invcaf-captcha-style' );
		wp_enqueue_script( 'invcaf-captcha-script' );

		// Create a cryptographically secure, unique session key.
		$user_id       = get_current_user_id();
		$session_token = wp_get_session_token();
		$random_nonce  = wp_generate_password( 16, false );
		$session_key   = hash( 'sha256', $user_id . '|' . $session_token . '|' . $random_nonce );

		// Construct URL with query parameters pointing to the image generation endpoint.
		$image_url = add_query_arg(
			array(
				'fcaptcha' => 'generate',
				'id'       => $form_id,
				'c_id'     => $session_key,
			),
			home_url( '/' )
		);

		ob_start();
		?>
		<div class="invcaf-outer-container">
		<div class="fc-captcha-wrapper invcaf-captcha-wrapper invcaf-theme-<?php echo esc_attr( $theme ); ?>" data-form-id="<?php echo esc_attr( $form_id ); ?>">
			<!-- Accessible Label -->
			<label for="invcaf_code_<?php echo esc_attr( $form_id ); ?>" class="invcaf-screen-reader-text">
				<?php esc_html_e( 'Security Code (CAPTCHA)', 'invenza-captcha-for-all-forms' ); ?>
			</label>

			<!-- Captcha image + refresh button -> Top Row -->
			<div class="invcaf-image-wrapper">
				<span id="invcaf_desc_<?php echo esc_attr( $form_id ); ?>" class="invcaf-screen-reader-text">
					<?php esc_html_e( 'Visual verification code. Enter these characters into the input box. If you cannot read them, click the refresh button to generate a new one.', 'invenza-captcha-for-all-forms' ); ?>
				</span>

				<div class="invcaf-image-box">
					<img class="invcaf-captcha-image" src="<?php echo esc_url( $image_url ); ?>" alt="<?php esc_attr_e( 'CAPTCHA Image Challenge', 'invenza-captcha-for-all-forms' ); ?>" aria-describedby="invcaf_desc_<?php echo esc_attr( $form_id ); ?>">
				</div>

				<button type="button" class="invcaf-captcha-refresh" aria-label="<?php esc_attr_e( 'Refresh CAPTCHA Image', 'invenza-captcha-for-all-forms' ); ?>" title="<?php esc_attr_e( 'Refresh CAPTCHA Image', 'invenza-captcha-for-all-forms' ); ?>">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"></path>
						<polyline points="21 3 21 8 16 8"></polyline>
					</svg>
				</button>
			</div>

			<!-- Input field -> Bottom Row -->
			<div class="invcaf-input-wrapper">
				<input type="text" id="invcaf_code_<?php echo esc_attr( $form_id ); ?>" class="invcaf-captcha-input" name="invcaf_code" placeholder="<?php esc_attr_e( 'Enter Code', 'invenza-captcha-for-all-forms' ); ?>" aria-required="true" required autocomplete="off">
				<input type="hidden" class="invcaf-captcha-id" name="invcaf_session_key" value="<?php echo esc_attr( $session_key ); ?>">
				<input type="text" name="invcaf_honeypot" class="invcaf-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
				<div class="invcaf-screen-reader-text invcaf-live-status" aria-live="polite"></div>
			</div>
		</div>
		</div>
		<?php
		return ob_get_clean();
	}
}


