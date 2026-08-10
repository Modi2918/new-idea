<?php
declare(strict_types=1);

namespace Invcaf\Includes;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enterprise-Grade Security Manager.
 */
class SecurityManager {

	/**
	 * Get GDPR-compliant, salted SHA-256 IP hash.
	 *
	 * @return string IP address hash.
	 */
	public static function get_ip_hash(): string {
		$ip = '';
		// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		// phpcs:enable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders

		$salt = wp_salt( 'auth' );
		return hash( 'sha256', $ip . '|' . $salt );
	}

	/**
	 * Get salted, secure session environment fingerprint.
	 *
	 * @return string Fingerprint hash.
	 */
	public static function get_session_fingerprint(): string {
		$ip_hash = self::get_ip_hash();
		$ua      = '';
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
		}

		$salt = wp_salt( 'logged_in' );
		return hash( 'sha256', $ip_hash . '|' . $ua . '|' . $salt );
	}

	/**
	 * Create unique CAPTCHA session key.
	 *
	 * @return string Session verification key.
	 */
	public static function generate_session_key(): string {
		$user_id       = get_current_user_id();
		$session_token = function_exists( 'wp_get_session_token' ) ? wp_get_session_token() : '';
		$random_nonce  = wp_generate_password( 24, false );

		return hash( 'sha256', $user_id . '|' . $session_token . '|' . $random_nonce );
	}

	/**
	 * Log a security event to custom wp_invcaf_events database.
	 *
	 * @param string $event_type E.g. 'generated', 'passed', 'failed', 'blocked'
	 * @param int    $form_id Associated Form ID.
	 * @param string $session_key Session key string.
	 * @return bool True on success, false on failure.
	 */
	public static function log_event( string $event_type, int $form_id, string $session_key ): bool {
		if ( Settings::get( 'logging_enabled' ) !== '1' ) {
			return false;
		}

		$ip_hash = self::get_ip_hash();

		// Prevent log table inflation during active DDoS/Brute-force attacks.
		if ( CaptchaStorage::is_ip_blocked( $ip_hash ) ) {
			return false;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'invcaf_events';

		$session_hash = hash( 'sha256', $session_key );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table_name,
			array(
				'event_type'   => sanitize_key( $event_type ),
				'form_id'      => $form_id,
				'session_hash' => $session_hash,
				'ip_hash'      => $ip_hash,
				'created_at'   => current_time( 'mysql' ),
			),
			array(
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false !== $result && 'failed' === $event_type ) {
			wp_cache_delete( 'invcaf_failed_attempts_' . $ip_hash, 'invcaf' );
		}
		if ( false !== $result && 'generated' === $event_type ) {
			wp_cache_delete( 'invcaf_refreshes_' . $ip_hash, 'invcaf' );
		}

		return false !== $result;
	}

	/**
	 * Retrieve count of failed events from the same IP hash within a duration.
	 *
	 * @param int $minutes Lookback interval in minutes.
	 * @return int Number of failed events.
	 */
	public static function get_failed_attempts_count( int $minutes = 60 ): int {
		global $wpdb;
		$table_name = esc_sql( $wpdb->prefix . 'invcaf_events' );
		$ip_hash    = self::get_ip_hash();

		$cache_key = 'invcaf_failed_attempts_' . $ip_hash;
		$cached    = wp_cache_get( $cache_key, 'invcaf' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'invcaf_events' ) ) !== $wpdb->prefix . 'invcaf_events' ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return 0;
		}

		// Direct query required because table name must be interpolated. Caching implemented above.
		$query = $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$table_name}` WHERE ip_hash = %s AND event_type = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL %d MINUTE)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ip_hash,
			$minutes
		);

		$count = (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_set( $cache_key, $count, 'invcaf', 300 );

		return $count;
	}
}
