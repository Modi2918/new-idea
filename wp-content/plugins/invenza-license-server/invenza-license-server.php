<?php
/**
 * Plugin Name: Invenza License Server
 * Description: A secure administration companion plugin to generate, manage, and validate license keys for the FormCraft Advanced CAPTCHA extension.
 * Version: 1.0.0
 * Author: Sahil Modi
 * License: GPL-2.0+
 * Text Domain: invenza-license-server
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Constants.
define( 'INVENZA_SERVER_VERSION', '1.0.0' );
define( 'INVENZA_SERVER_PATH', plugin_dir_path( __FILE__ ) );
define( 'INVENZA_SERVER_URL', plugin_dir_url( __FILE__ ) );

// Require Composer Autoloader.
if ( file_exists( INVENZA_SERVER_PATH . 'vendor/autoload.php' ) ) {
	require_once INVENZA_SERVER_PATH . 'vendor/autoload.php';
}

// Autoload Class Files.
spl_autoload_register( function( $class ) {
	$prefix = 'InvenzaLicenseServer\\';
	$len    = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file = INVENZA_SERVER_PATH . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Initialize the plugin.
register_activation_hook( __FILE__, array( 'InvenzaLicenseServer\Database', 'create_tables' ) );
add_action( 'plugins_loaded', array( 'InvenzaLicenseServer\Init', 'start' ) );
