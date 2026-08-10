<?php
declare(strict_types=1);

namespace Invcaf\Includes;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Standard Image CAPTCHA Generator.
 */
class CaptchaGenerator {

	/**
	 * Generate and output CAPTCHA image challenge.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $session_key Secure CAPTCHA session key.
	 */
	public static function generate( int $form_id, string $session_key ) {
		// Output secure anti-caching and security headers.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: image/png' );

		$form_id     = absint( $form_id );
		$session_key = sanitize_key( $session_key );

		if ( empty( $session_key ) ) {
			status_header( 400 );
			self::output_error_image( __( 'Missing Key', 'invenza-captcha-for-all-forms' ) );
			exit;
		}

		// Rate Limiting check.
		$ip_ua_hash = SecurityManager::get_session_fingerprint();
		if ( ! CaptchaStorage::check_rate_limit( $ip_ua_hash ) ) {
			status_header( 429 );
			self::output_error_image( __( 'Rate Limit', 'invenza-captcha-for-all-forms' ) );
			exit;
		}

		// Log the initial generation event in security database.
		SecurityManager::log_event( 'generated', $form_id, $session_key );

		// Establish visual parameters for free image CAPTCHA.
		$char_count = (int) Settings::get( 'length', 6 );
		$char_count = max( 4, min( 8, $char_count ) );
		$angle_max  = 15;

		// Generate challenge text pool (allow extensions to supply custom challenges).
		$display_text = '';
		$answer_code  = '';

		$challenge = apply_filters( 'invcaf_generate_challenge', false, $form_id, $session_key );
		if ( is_array( $challenge ) && isset( $challenge['display_text'], $challenge['answer_code'] ) ) {
			$display_text = $challenge['display_text'];
			$answer_code  = $challenge['answer_code'];
		} else {
			$char_sets          = Settings::get( 'char_set', array( 'letters', 'small_letters', 'numbers' ) );
			$letters_pool       = 'ABCDEFGHJKMNPQRSTUVWXYZ';
			$small_letters_pool = 'abcdefghjkmnpqrstuvwxyz';
			$numbers_pool       = '23456789';

			$pool = '';
			if ( in_array( 'letters', $char_sets, true ) ) {
				$pool .= $letters_pool;
			}
			if ( in_array( 'small_letters', $char_sets, true ) ) {
				$pool .= $small_letters_pool;
			}
			if ( in_array( 'numbers', $char_sets, true ) ) {
				$pool .= $numbers_pool;
			}
			if ( empty( $pool ) ) {
				$pool = $letters_pool . $small_letters_pool . $numbers_pool;
			}

			$pool_length = strlen( $pool );
			for ( $i = 0; $i < $char_count; $i++ ) {
				$display_text .= $pool[ wp_rand( 0, $pool_length - 1 ) ];
			}
			$answer_code = $display_text;
		}

		// Developer filter for code length.
		$char_count = (int) apply_filters( 'invcaf_captcha_length', $char_count, $form_id );

		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'imagecreatetruecolor' ) ) {
			status_header( 500 );
			self::output_error_image( __( 'GD Missing', 'invenza-captcha-for-all-forms' ) );
			exit;
		}

		try {
			// Save challenge transient metadata.
			$expiration  = (int) Settings::get( 'expiration', 5 );
			$expiry_time = time() + ( $expiration * MINUTE_IN_SECONDS );

			$transient_data = array(
				'captcha_hash'     => CaptchaValidator::hash_code( $answer_code ),
				'created_time'     => time(),
				'expiry_time'      => $expiry_time,
				'attempt_count'    => 0,
				'form_id'          => $form_id,
				'session_key'      => $session_key,
				'form_loaded_time' => time(),
			);

			CaptchaStorage::save( $session_key, $transient_data, $expiration );

			// Create GD canvas.
			$width  = (int) Settings::get( 'width', 150 );
			$height = (int) Settings::get( 'height', 50 );

			$image = imagecreatetruecolor( $width, $height );
			if ( ! $image ) {
				status_header( 500 );
				self::output_error_image( __( 'GD Error', 'invenza-captcha-for-all-forms' ) );
				exit;
			}

			$bg_color = imagecolorallocate( $image, 255, 255, 255 );
			imagefill( $image, 0, 0, $bg_color );

			// Draw dots background noise if enabled.
			if ( Settings::get( 'dots' ) === '1' ) {
				$dot_count = 30;
				for ( $i = 0; $i < $dot_count; $i++ ) {
					$dot_color = imagecolorallocate( $image, wp_rand( 200, 240 ), wp_rand( 200, 240 ), wp_rand( 200, 240 ) );
					imagesetpixel( $image, wp_rand( 0, $width ), wp_rand( 0, $height ), $dot_color );
				}
			}

			// Draw lines background noise if enabled.
			if ( Settings::get( 'lines' ) === '1' ) {
				$line_count = 3;
				for ( $i = 0; $i < $line_count; $i++ ) {
					$line_color = imagecolorallocate( $image, wp_rand( 160, 210 ), wp_rand( 160, 210 ), wp_rand( 160, 210 ) );
					imageline( $image, wp_rand( 0, $width ), wp_rand( 0, $height ), wp_rand( 0, $width ), wp_rand( 0, $height ), $line_color );
				}
			}

			// Load TrueType fonts with normalized absolute path.
			$font_dir = wp_normalize_path( INVCAF_PLUGIN_DIR . 'assets/fonts/' );
			$fonts    = array(
				$font_dir . 'Roboto.ttf',
				$font_dir . 'OpenSans.ttf',
			);

			$has_freetype = function_exists( 'imagettftext' );
			$rendered_ttf = false;
			$text_len     = strlen( $display_text );

			if ( $has_freetype ) {
				$step = $width / ( $text_len + 1 );
				for ( $i = 0; $i < $text_len; $i++ ) {
					$char = $display_text[ $i ];
					$font = $fonts[ wp_rand( 0, count( $fonts ) - 1 ) ];
					if ( ! file_exists( $font ) ) {
						$font = $fonts[0];
					}

					if ( file_exists( $font ) ) {
						$font_size  = wp_rand( 16, 20 );
						$angle      = wp_rand( -$angle_max, $angle_max );
						$x          = (int) ( ( $i + 0.6 ) * $step - 5 );
						$y          = (int) ( ( $height / 2 ) + ( $font_size / 2 ) - 2 );
						$text_color = imagecolorallocate( $image, wp_rand( 0, 80 ), wp_rand( 0, 80 ), wp_rand( 0, 80 ) );

						$res = imagettftext( $image, $font_size, $angle, $x, $y, $text_color, $font, $char );
						if ( is_array( $res ) ) {
							$rendered_ttf = true;
						}
					}
				}
			}

			// Fallback to built-in basic GD chars if FreeType or TTF rendering failed.
			if ( ! $rendered_ttf ) {
				$font_width  = imagefontwidth( 5 );
				$font_height = imagefontheight( 5 );

				$step = $width / ( $text_len + 1 );
				for ( $i = 0; $i < $text_len; $i++ ) {
					$char       = $display_text[ $i ];
					$x          = (int) ( ( $i + 0.6 ) * $step - ( $font_width / 2 ) );
					$y          = (int) ( ( $height / 2 ) - ( $font_height / 2 ) + wp_rand( -3, 3 ) );
					$text_color = imagecolorallocate( $image, wp_rand( 0, 60 ), wp_rand( 0, 60 ), wp_rand( 0, 60 ) );
					imagechar( $image, 5, $x, $y, $char, $text_color );
				}
			}

			// Filter to allow post-processing image rendering (e.g. distortion filters by extensions).
			$image = apply_filters( 'invcaf_render_image', $image, $display_text, $form_id, $session_key );

			imagepng( $image );
			imagedestroy( $image );
		} catch ( \Throwable $e ) {
			status_header( 500 );
			self::output_error_image( __( 'GD Exception', 'invenza-captcha-for-all-forms' ) );
		}
	}

	/**
	 * Output an error message directly inside a PNG canvas.
	 *
	 * @param string $message Error message.
	 */
	private static function output_error_image( string $message ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $message );
			return;
		}

		$width  = 150;
		$height = 50;
		$image  = imagecreatetruecolor( $width, $height );
		if ( $image ) {
			$bg_color   = imagecolorallocate( $image, 255, 230, 230 );
			$text_color = imagecolorallocate( $image, 180, 0, 0 );
			imagefill( $image, 0, 0, $bg_color );

			$font_width  = imagefontwidth( 3 );
			$font_height = imagefontheight( 3 );
			$x           = (int) ( ( $width - ( strlen( $message ) * $font_width ) ) / 2 );
			$y           = (int) ( ( $height - $font_height ) / 2 );

			imagestring( $image, 3, max( 2, $x ), $y, $message, $text_color );

			imagepng( $image );
			imagedestroy( $image );
		}
	}
}
