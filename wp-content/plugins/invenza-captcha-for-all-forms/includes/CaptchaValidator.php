<?php
declare(strict_types=1);

namespace Invcaf\Includes;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enterprise-Grade CAPTCHA Validation Engine.
 */
class CaptchaValidator {

	/**
	 * Validate user's CAPTCHA entry.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $session_key Secure session token key.
	 * @param string $code User submitted code string.
	 * @param string $honeypot Honeypot field content.
	 * @return bool|string True on success, error message string on failure.
	 */
	public static function validate( int $form_id, string $session_key, string $code, string $honeypot ) {
		// Developer Action Hook.
		do_action( 'invcaf_before_validate', $form_id, $session_key, $code, $honeypot );

		// 1. Honeypot check.
		if ( ! empty( $honeypot ) ) {
			SecurityManager::log_event( 'failed', $form_id, $session_key );
			return __( 'Spam protection triggered.', 'invenza-captcha-for-all-forms' );
		}

		if ( empty( $session_key ) ) {
			SecurityManager::log_event( 'failed', $form_id, $session_key );
			return __( 'Missing security session validation token.', 'invenza-captcha-for-all-forms' );
		}

		// 2. Brute-force check: Is session blocked?
		if ( CaptchaStorage::is_blocked( $session_key ) ) {
			SecurityManager::log_event( 'blocked', $form_id, $session_key );
			return __( 'This form submission is temporarily blocked. Please try again later.', 'invenza-captcha-for-all-forms' );
		}

		// 3. Retrieve challenge transient data.
		$transient_data = CaptchaStorage::get( $session_key );

		if ( ! is_array( $transient_data ) ) {
			SecurityManager::log_event( 'failed', $form_id, $session_key );
			$msg = Settings::get( 'msg_expired' );
			return ! empty( $msg ) ? $msg : __( 'Security code expired. Please refresh and try again.', 'invenza-captcha-for-all-forms' );
		}

		// Verify form ID matches.
		if ( (int) $transient_data['form_id'] !== $form_id ) {
			SecurityManager::log_event( 'failed', $form_id, $session_key );
			return __( 'Invalid security token for this form.', 'invenza-captcha-for-all-forms' );
		}

		// 4. Calculate Visitor Risk Score.
		$form_loaded_time = isset( $transient_data['form_loaded_time'] ) ? (int) $transient_data['form_loaded_time'] : 0;
		$attempt_count    = isset( $transient_data['attempt_count'] ) ? (int) $transient_data['attempt_count'] : 0;

		$risk_score = CaptchaRiskManager::calculate_score(
			$form_id,
			$session_key,
			$form_loaded_time,
			$attempt_count,
			$honeypot
		);

		// 5. Brute-force protection: check attempt count limit.
		$max_attempts   = (int) Settings::get( 'max_attempts', 5 );
		$block_duration = (int) Settings::get( 'block_duration', 10 );

		if ( $attempt_count >= $max_attempts ) {
			CaptchaStorage::block_session( $session_key, $block_duration );
			CaptchaStorage::block_ip( SecurityManager::get_ip_hash(), $block_duration );
			CaptchaStorage::delete( $session_key );
			SecurityManager::log_event( 'blocked', $form_id, $session_key );
			return __( 'Too many failed attempts. Submissions blocked.', 'invenza-captcha-for-all-forms' );
		}

		// 6. Anti-bot timing checks.
		$now             = time();
		$submit_duration = $now - $form_loaded_time;

		if ( $submit_duration < 3 ) {
			CaptchaStorage::increment_attempts( $session_key, $transient_data );
			SecurityManager::log_event( 'failed', $form_id, $session_key );
			return __( 'Submission is too fast. Please verify you are human.', 'invenza-captcha-for-all-forms' );
		}

		if ( $submit_duration > 1800 ) { // 30 minutes
			CaptchaStorage::delete( $session_key );
			SecurityManager::log_event( 'failed', $form_id, $session_key );
			$msg = Settings::get( 'msg_expired' );
			return ! empty( $msg ) ? $msg : __( 'Security code expired. Please refresh and try again.', 'invenza-captcha-for-all-forms' );
		}

		// 7. Expiration check.
		if ( $now > (int) $transient_data['expiry_time'] ) {
			CaptchaStorage::delete( $session_key );
			SecurityManager::log_event( 'failed', $form_id, $session_key );
			$msg = Settings::get( 'msg_expired' );
			return ! empty( $msg ) ? $msg : __( 'Security code expired. Please refresh and try again.', 'invenza-captcha-for-all-forms' );
		}

		// 8. Verify CAPTCHA match.
		$user_hash   = self::hash_code( $code );
		$stored_hash = isset( $transient_data['captcha_hash'] ) ? $transient_data['captcha_hash'] : '';

		if ( empty( $code ) || ! hash_equals( $stored_hash, $user_hash ) ) {
			CaptchaStorage::increment_attempts( $session_key, $transient_data );

			// If we just hit the limit on this attempt, block them.
			if ( $attempt_count + 1 >= $max_attempts ) {
				CaptchaStorage::block_session( $session_key, $block_duration );
				CaptchaStorage::block_ip( SecurityManager::get_ip_hash(), $block_duration );
				CaptchaStorage::delete( $session_key );
				SecurityManager::log_event( 'blocked', $form_id, $session_key );
			} else {
				SecurityManager::log_event( 'failed', $form_id, $session_key );
			}

			$result = Settings::get( 'msg_invalid' );
			if ( empty( $result ) ) {
				$result = __( 'Invalid security code. Please try again.', 'invenza-captcha-for-all-forms' );
			}
			return apply_filters( 'invcaf_validation_result', $result, $form_id, false );
		}

		// 9. Replay Protection: Success requires immediate challenge removal.
		CaptchaStorage::delete( $session_key );

		// Update analytics verification stats.
		update_option(
			'invcaf_last_verification',
			array(
				'time'    => time(),
				'success' => true,
			)
		);

		// Log success event in custom security table.
		SecurityManager::log_event( 'passed', $form_id, $session_key );

		// Developer Hooks.
		do_action( 'invcaf_after_validate', $form_id, $session_key, true );

		$result = true;
		return apply_filters( 'invcaf_validation_result', $result, $form_id, true );
	}

	/**
	 * Compute secure double HMAC + WP Hash of lowercase characters.
	 *
	 * @param string $code Challenge text code.
	 * @return string Computed signature hash.
	 */
	public static function hash_code( string $code ): string {
		$code = trim( $code );
		$hmac = hash_hmac( 'sha256', $code, wp_salt( 'secure_auth' ) );
		return wp_hash( $hmac );
	}
}
