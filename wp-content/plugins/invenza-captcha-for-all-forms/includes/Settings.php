<?php
namespace Invcaf\Includes;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Helper Class.
 */
class Settings {

	/**
	 * In-memory cache of settings for the current request.
	 * Prevents repeated get_option() calls within a single page load.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Load all settings from the database (internal — use get_all() instead).
	 *
	 * @return array
	 */
	private static function load(): array {
		$defaults = array(
			'enabled'               => '1',
			'length'                => 5,
			'expiration'            => 5, // minutes
			'char_set'              => array( 'letters', 'small_letters', 'numbers' ),
			'width'                 => 150,
			'height'                => 50,
			'bg_noise'              => '1',
			'lines'                 => '1',
			'dots'                  => '1',
			'rate_limit'            => 50, // requests per minute
			'max_attempts'          => 5,
			'block_duration'        => 10, // minutes
			'fc_enabled'            => '1',
			'fc_all_forms'          => '1',
			'fc_forms'              => '',
			'position'              => 'before_submit',
			'msg_invalid'           => 'Invalid security code. Please try again.',
			'msg_expired'           => 'Security code expired. Please refresh and try again.',
			'logging_enabled'       => '1',
			'delete_on_uninstall'   => '0',
			'theme'                 => 'light',
			'license_key'           => '',

			// Contact Form 7 integration.
			'cf7_enabled'           => '1',
			'cf7_all_forms'         => '1',
			'cf7_forms'             => '',

			// WPForms integration.
			'wpforms_enabled'       => '1',
			'wpforms_all_forms'     => '1',
			'wpforms_forms'         => '',

			// Forminator integration.
			'forminator_enabled'    => '1',
			'forminator_all_forms'  => '1',
			'forminator_forms'      => '',

			// Gravity Forms integration.
			'gf_enabled'            => '1',
			'gf_all_forms'          => '1',
			'gf_forms'              => '',

			// Fluent Forms integration.
			'fluentforms_enabled'   => '1',
			'fluentforms_all_forms' => '1',
			'fluentforms_forms'     => '',
		);

		$saved = get_option( 'invcaf_settings', null );
		if ( null === $saved ) {
			// Migrate legacy setting if exists
			$legacy = get_option( 'invenza_captcha_settings', null );
			if ( null !== $legacy ) {
				$saved = $legacy;
				update_option( 'invcaf_settings', $saved );
			} else {
				$saved = array();
			}
		}

		// Merge options and sanitize them.
		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Retrieve all plugin settings with request-level caching.
	 *
	 * @return array
	 */
	public static function get_all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		self::$cache = self::load();
		return self::$cache;
	}

	/**
	 * Retrieve a single plugin setting by key.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value if setting does not exist.
	 * @return mixed Setting value.
	 */
	public static function get( string $key, $default = null ) {
		$settings = self::get_all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Flush the in-memory settings cache.
	 * Call this after saving new settings to ensure fresh values.
	 */
	public static function flush(): void {
		self::$cache = null;
	}
}
