<?php
declare(strict_types=1);

namespace Invcaf\Includes;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enterprise Bot Detection and Risk Assessment Manager.
 */
class CaptchaRiskManager {

	/**
	 * Compute risk score (0 - 100) dynamically.
	 *
	 * @param int    $form_id Associated Form ID.
	 * @param string $session_key CAPTCHA session key.
	 * @param int    $form_loaded_time Unix timestamp when form was generated.
	 * @param int    $attempt_count Current failed submission count.
	 * @param string $honeypot Honeypot field content.
	 * @return int Risk score from 0 to 100.
	 */
	public static function calculate_score( int $form_id, string $session_key, int $form_loaded_time, int $attempt_count, string $honeypot ): int {
		// 1. Honeypot filled indicates 100% spam bot.
		if ( ! empty( $honeypot ) ) {
			return 100;
		}

		$score = 0;

		// 2. Submission Velocity Check.
		$submission_duration = time() - $form_loaded_time;
		if ( $submission_duration < 3 ) {
			$score += 50; // Super fast automated bot submission.
		} elseif ( $submission_duration < 5 ) {
			$score += 25; // Suspiciously fast user submission.
		}

		// 3. Repeated Verification Failures Check.
		if ( $attempt_count > 0 ) {
			$score += $attempt_count * 15; // Incremental risk on repeated failures.
		}

		// 4. IP Reputation Assessment (failed events in last 60 minutes).
		$failed_history = SecurityManager::get_failed_attempts_count( 60 );
		if ( $failed_history > 5 ) {
			$score += 40; // Known block history.
		} elseif ( $failed_history > 2 ) {
			$score += 20; // Moderate threat background.
		}

		// 5. CAPTCHA Refresh Frequency Check.
		global $wpdb;
		$table_name = esc_sql( $wpdb->prefix . 'invcaf_events' );
		$ip_hash    = SecurityManager::get_ip_hash();
		$cache_key  = 'invcaf_refreshes_' . $ip_hash;
		$refreshes  = wp_cache_get( $cache_key, 'invcaf' );

		if ( false === $refreshes ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'invcaf_events' ) ) === $wpdb->prefix . 'invcaf_events' ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				// Direct query required because table name must be interpolated. Caching implemented above.
				$query = $wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table_name}` WHERE ip_hash = %s AND event_type = 'generated' AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ip_hash
				);

				$refreshes = (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				wp_cache_set( $cache_key, $refreshes, 'invcaf', 300 );
			} else {
				$refreshes = 0;
			}
		}

		if ( $refreshes > 8 ) {
			$score += 30; // Heavy refresh abuse.
		} elseif ( $refreshes > 4 ) {
			$score += 15; // Unusual request rates.
		}

		// Apply developer hooks before bounding.
		$score = apply_filters( 'invcaf_risk_score', $score, $form_id, $session_key );

		return (int) max( 0, min( 100, $score ) );
	}
}
