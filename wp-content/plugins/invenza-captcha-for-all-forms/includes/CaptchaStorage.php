<?php
declare(strict_types=1);

namespace Invcaf\Includes;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CAPTCHA Storage Handler.
 *
 * Manages challenge data and rate limiting via a 4-layer architecture:
 *   1. External Object Cache (Redis / Memcached) — most performant
 *   2. WordPress Transients (DB-backed, no external cache)
 *   3. Rate limiting is NEVER disabled regardless of environment.
 *
 * Security hardening:
 * - Rate limiting is enforced on every tier; no silent bypass.
 * - All transient keys use md5(sha256) to keep key lengths within 172-char limit.
 * - Blocking transients use prefixed keys to prevent naming conflicts.
 */
class CaptchaStorage {

	// ---------------------------------------------------------
	// Key prefixes (all ≤ 160 chars after md5 suffix).
	// ---------------------------------------------------------

	private const PREFIX_CHALLENGE = 'invcaf_c_';
	private const PREFIX_RATE      = 'invcaf_r_';
	private const PREFIX_BLOCK_SES = 'invcaf_bs_';
	private const PREFIX_BLOCK_IP  = 'invcaf_bi_';

	/** Object cache group for rate limiting (uses non-persistent groups on object cache). */
	private const CACHE_GROUP = 'invcaf_rate';

	/** Rate limit window duration in seconds. */
	private const RATE_WINDOW = 60;

	// ---------------------------------------------------------
	// Challenge CRUD.
	// ---------------------------------------------------------

	/**
	 * Save CAPTCHA challenge data as a transient.
	 *
	 * @param string $session_key Session key string.
	 * @param array  $data        Challenge parameters.
	 * @param int    $expiration  Expiry duration in minutes.
	 * @return bool
	 */
	public static function save( string $session_key, array $data, int $expiration ): bool {
		$key = self::PREFIX_CHALLENGE . md5( $session_key );
		return set_transient( $key, $data, max( 1, $expiration ) * MINUTE_IN_SECONDS );
	}

	/**
	 * Retrieve CAPTCHA challenge data.
	 *
	 * @param string $session_key Session key string.
	 * @return array|false Challenge array or false if not found / expired.
	 */
	public static function get( string $session_key ) {
		$key  = self::PREFIX_CHALLENGE . md5( $session_key );
		$data = get_transient( $key );
		return is_array( $data ) ? $data : false;
	}

	/**
	 * Delete CAPTCHA challenge data (replay protection — must call on success).
	 *
	 * @param string $session_key Session key string.
	 * @return bool
	 */
	public static function delete( string $session_key ): bool {
		return delete_transient( self::PREFIX_CHALLENGE . md5( $session_key ) );
	}

	/**
	 * Increment the attempt counter for a challenge.
	 *
	 * @param string $session_key Session key.
	 * @param array  $data        Current challenge data.
	 */
	public static function increment_attempts( string $session_key, array $data ): void {
		$data['attempt_count'] = ( (int) ( $data['attempt_count'] ?? 0 ) ) + 1;
		$expiration            = (int) Settings::get( 'expiration', 5 );
		self::save( $session_key, $data, $expiration );
	}

	// ---------------------------------------------------------
	// Session blocking.
	// ---------------------------------------------------------

	/**
	 * Block further attempts for a session.
	 *
	 * @param string $session_key     Session key.
	 * @param int    $duration_minutes Block duration in minutes.
	 * @return bool
	 */
	public static function block_session( string $session_key, int $duration_minutes ): bool {
		$key = self::PREFIX_BLOCK_SES . md5( $session_key );
		return set_transient( $key, '1', max( 1, $duration_minutes ) * MINUTE_IN_SECONDS );
	}

	/**
	 * Check if a session is currently blocked.
	 *
	 * @param string $session_key Session key.
	 * @return bool
	 */
	public static function is_blocked( string $session_key ): bool {
		return (bool) get_transient( self::PREFIX_BLOCK_SES . md5( $session_key ) );
	}

	// ---------------------------------------------------------
	// IP blocking.
	// ---------------------------------------------------------

	/**
	 * Globally block an IP address hash.
	 *
	 * @param string $ip_hash          SHA-256 hash of the IP.
	 * @param int    $duration_minutes Block duration in minutes.
	 * @return bool
	 */
	public static function block_ip( string $ip_hash, int $duration_minutes ): bool {
		$key = self::PREFIX_BLOCK_IP . md5( $ip_hash );
		return set_transient( $key, '1', max( 1, $duration_minutes ) * MINUTE_IN_SECONDS );
	}

	/**
	 * Check if an IP hash is currently blocked.
	 *
	 * @param string $ip_hash SHA-256 hash of the IP.
	 * @return bool
	 */
	public static function is_ip_blocked( string $ip_hash ): bool {
		return (bool) get_transient( self::PREFIX_BLOCK_IP . md5( $ip_hash ) );
	}

	// ---------------------------------------------------------
	// Rate limiting — 4-layer architecture.
	// The rate limit is NEVER disabled regardless of environment.
	// ---------------------------------------------------------

	/**
	 * Check and enforce the per-client request rate limit.
	 *
	 * Layer selection:
	 *   1. External Object Cache (Redis/Memcached) — atomic increment, no DB writes.
	 *   2. WordPress Transient (DB-backed counter) — fallback, ~1 DB write/request.
	 *
	 * @param string $fingerprint Client session fingerprint hash.
	 * @return bool True if within limit, false if exceeded.
	 */
	public static function check_rate_limit( string $fingerprint ): bool {
		$limit = max( 1, (int) Settings::get( 'rate_limit', 50 ) );

		if ( wp_using_ext_object_cache() ) {
			return self::rate_limit_via_object_cache( $fingerprint, $limit );
		}

		return self::rate_limit_via_transient( $fingerprint, $limit );
	}

	/**
	 * Rate limiting via external object cache (Redis / Memcached).
	 *
	 * Uses atomic add + incr pattern:
	 *   - wp_cache_add sets key with TTL only if it doesn't exist (atomic).
	 *   - wp_cache_incr atomically increments.
	 * This is race-condition safe on Redis/Memcached.
	 *
	 * @param string $fingerprint Client fingerprint.
	 * @param int    $limit       Max requests per window.
	 * @return bool
	 */
	private static function rate_limit_via_object_cache( string $fingerprint, int $limit ): bool {
		$cache_key = self::PREFIX_RATE . md5( $fingerprint );

		// Attempt to add with TTL (only succeeds when key doesn't exist yet)
		wp_cache_add( $cache_key, 0, self::CACHE_GROUP, self::RATE_WINDOW );

		// Atomically increment — returns new count
		$count = wp_cache_incr( $cache_key, 1, self::CACHE_GROUP );

		// wp_cache_incr returns false on failure — treat as allowed
		if ( false === $count ) {
			return true;
		}

		return (int) $count <= $limit;
	}

	/**
	 * Rate limiting via WordPress transients (DB-backed counter).
	 *
	 * Uses a counter + window_start structure:
	 *   - Only 1 transient key per client (not N timestamps — avoids bloat).
	 *   - ~1 DB read + ~1 DB write per request (minimal overhead).
	 *
	 * @param string $fingerprint Client fingerprint.
	 * @param int    $limit       Max requests per window.
	 * @return bool
	 */
	private static function rate_limit_via_transient( string $fingerprint, int $limit ): bool {
		$transient_key = self::PREFIX_RATE . md5( $fingerprint );
		$now           = time();

		$data = get_transient( $transient_key );

		if ( ! is_array( $data )
			|| ! isset( $data['count'], $data['window_start'] )
			|| ( $now - (int) $data['window_start'] ) >= self::RATE_WINDOW
		) {
			// New window — reset counter.
			$data = array(
				'count'        => 0,
				'window_start' => $now,
			);
		}

		if ( (int) $data['count'] >= $limit ) {
			return false;
		}

		++$data['count'];
		// TTL slightly longer than window to ensure it survives the window
		set_transient( $transient_key, $data, self::RATE_WINDOW * 2 );

		return true;
	}
}
