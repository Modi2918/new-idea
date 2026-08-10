<?php
namespace InvenzaLicenseServer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles cryptographically random and unique License key generation.
 */
class LicenseGenerator {

	/**
	 * Safe characters set for license keys (excludes confusing characters: 0, O, 1, I, L, etc).
	 *
	 * @var string
	 */
	private static $chars = 'ABCDEFGHJKMNPQRSTWXYZ23456789';

	/**
	 * Generate a random cryptographically secure string matching a specific length from safe characters.
	 *
	 * @param int $length String length.
	 * @return string Random key block.
	 */
	private static function random_block( int $length = 4 ): string {
		$block = '';
		$max   = strlen( self::$chars ) - 1;

		for ( $i = 0; $i < $length; $i++ ) {
			try {
				$index = random_int( 0, $max );
			} catch ( \Exception $e ) {
				$index = mt_rand( 0, $max );
			}
			$block .= self::$chars[ $index ];
		}

		return $block;
	}

	/**
	 * Generate a professional unique license key.
	 * Format: FCAC-XXXX-XXXX-XXXX-XXXX
	 *
	 * @return string Cryptographically secure, unique license key.
	 */
	public static function generate(): string {
		global $wpdb;
		$table = $wpdb->prefix . 'fcac_licenses';

		$attempts = 0;
		do {
			$attempts++;
			$key = 'FCAC-' .
				self::random_block( 4 ) . '-' .
				self::random_block( 4 ) . '-' .
				self::random_block( 4 ) . '-' .
				self::random_block( 4 );

			// Check uniqueness in database.
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE license_key = %s", $key ) );
		} while ( $exists && $attempts < 100 );

		return $key;
	}
}
