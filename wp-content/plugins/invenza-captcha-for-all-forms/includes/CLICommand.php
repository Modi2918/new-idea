<?php
declare(strict_types=1);

namespace Invcaf\Includes;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI Command handler for Invcaf CAPTCHA.
 *
 * Usage:
 *   wp invcaf status     — Check plugin/system operational status.
 *   wp invcaf cleanup    — Manually trigger log and transient cleanup.
 */
class CLICommand {

	/**
	 * Output operational system status of the CAPTCHA plugin.
	 *
	 * ## EXAMPLES
	 *
	 *     wp invcaf status
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments (unused).
	 */
	public function status( array $args, array $assoc_args ): void {
		$fc_active = class_exists( 'FormCraft' )
			|| class_exists( 'FormCraft_Plugin' )
			|| function_exists( 'formcraft_init' );

		\WP_CLI::log( 'FormCraft:  ' . ( $fc_active ? 'Active' : 'Inactive' ) );

		$gd_active = extension_loaded( 'gd' );
		\WP_CLI::log( 'GD Library: ' . ( $gd_active ? 'Enabled' : 'Disabled' ) );

		$compat = $gd_active;
		\WP_CLI::log( 'CAPTCHA:    ' . ( $compat ? 'Operational' : 'Errors Detected' ) );

		if ( $compat ) {
			\WP_CLI::success( 'Invcaf CAPTCHA is fully operational.' );
		} else {
			\WP_CLI::error( 'System dependency check failed. Enable the PHP GD extension.' );
		}
	}

	/**
	 * Manually run cleanup schedules to sweep old events and transients.
	 *
	 * ## EXAMPLES
	 *
	 *     wp invcaf cleanup
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments (unused).
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		$plugin = Plugin::get_instance();
		$plugin->cleanup_logs_callback();
		$plugin->cleanup_expired_captcha_callback();

		\WP_CLI::success( 'Database event logs and expired transients purged successfully.' );
	}
}
