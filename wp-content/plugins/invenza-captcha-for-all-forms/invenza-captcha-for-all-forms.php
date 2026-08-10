<?php
/**
 * Plugin Name: Invenza CAPTCHA for All Forms
 * Plugin URI: https://wordpress.org/plugins/invenza-captcha-for-all-forms/
 * Description: A highly secure, GDPR-compliant custom image CAPTCHA plugin compatible with Contact Form 7, WPForms, Forminator, Gravity Forms, Fluent Forms, and FormCraft.
 * Version: 1.0.0
 * Author: Sahil Modi
 * Author URI: https://profiles.wordpress.org/modi2918/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: invenza-captcha-for-all-forms
 * Domain Path: /languages
 * Requires PHP: 7.2
 * Requires at least: 6.0
 * Tested up to:      7.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'INVCAF_VERSION', '1.0.0' );

define( 'INVCAF_PLUGIN_FILE', __FILE__ );
define( 'INVCAF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'INVCAF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Register PSR-4 Autoloader for Invcaf namespace.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix   = 'Invcaf\\';
		$base_dir = INVCAF_PLUGIN_DIR;

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
				return;
		}

		$relative_class = substr( $class, $len );
		$parts          = explode( '\\', $relative_class );

		if ( ! isset( $parts[0] ) || '' === $parts[0] ) {
			return;
		}

		// Map all directory parts to lowercase to prevent case sensitivity issues on Linux.
		$file_name = array_pop( $parts );
		$parts     = array_map( 'strtolower', $parts );
		$parts[]   = $file_name;

		$file = $base_dir . implode( '/', $parts ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Plugin Activation Hook.
 */
register_activation_hook(
	__FILE__,
	function ( $network_wide ) {
		Invcaf\Includes\Plugin::activate( $network_wide );
	}
);

/**
 * Plugin Deactivation Hook.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		Invcaf\Includes\Plugin::deactivate();
	}
);

/**
 * Initialize the Plugin.
 */
add_action(
	'plugins_loaded',
	function () {
		Invcaf\Includes\Plugin::get_instance();
	}
);
