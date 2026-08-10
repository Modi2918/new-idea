<?php
namespace Invcaf\Includes;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dependency Checker — verifies that at least one supported form plugin is active.
 *
 * Supported plugins: Contact Form 7, WPForms, Forminator, Gravity Forms,
 * Fluent Forms, and FormCraft. The plugin now works standalone without
 * requiring any single specific form builder.
 */
class DependencyChecker {


	/**
	 * Known FormCraft plugin slugs (kept for FormCraft-specific compat checks).
	 *
	 * @var array
	 */
	private static $formcraft_slugs = array(
		'formcraft/formcraft.php',
		'formcraft/formcraft-main.php',
		'formcraft-form-builder/formcraft.php',
		'formcraft-form-builder/formcraft-main.php',
		'formcraft3/formcraft.php',
		'formcraft3/formcraft-main.php',
		'formcraft-premium/formcraft.php',
		'formcraft-premium/formcraft-main.php',
	);

	/**
	 * Check if at least one supported form plugin is active.
	 *
	 * Returns true when any of the following is active:
	 * Contact Form 7, WPForms, Forminator, Gravity Forms, Fluent Forms, or FormCraft.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		// Contact Form 7.
		if ( function_exists( 'wpcf7' ) || class_exists( 'WPCF7' ) ) {
			return true;
		}

		// WPForms.
		if ( function_exists( 'wpforms' ) || class_exists( 'WPForms_Field' ) ) {
			return true;
		}

		// Forminator.
		if ( class_exists( 'Forminator' ) || function_exists( 'forminator_api' ) ) {
			return true;
		}

		// Gravity Forms.
		if ( class_exists( 'GFForms' ) || class_exists( 'GF_Fields' ) ) {
			return true;
		}

		// Fluent Forms.
		if ( function_exists( 'wpFluentForm' ) || defined( 'FLUENTFORM' ) ) {
			return true;
		}

		// FormCraft.
		if ( class_exists( 'FormCraft' ) || defined( 'FORMCRAFT_VERSION' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( self::$formcraft_slugs as $slug ) {
			if ( is_plugin_active( $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if FormCraft is installed (even if inactive).
	 *
	 * @return bool
	 */
	public static function is_installed(): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		foreach ( self::$formcraft_slugs as $slug ) {
			if ( isset( $plugins[ $slug ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the detected FormCraft version string (if installed).
	 *
	 * @return string|null FormCraft version or null if not detected.
	 */
	public static function get_version(): ?string {
		if ( defined( 'FORMCRAFT_VERSION' ) ) {
			return FORMCRAFT_VERSION;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		foreach ( self::$formcraft_slugs as $slug ) {
			if ( isset( $plugins[ $slug ] ) ) {
				return $plugins[ $slug ]['Version'];
			}
		}

		return null;
	}

	/**
	 * Retrieve FormCraft-specific compatibility status string.
	 *
	 * @return string
	 */
	public static function get_compatibility_status(): string {
		$version = self::get_version();
		if ( ! $version ) {
			return __( 'Not Installed', 'invenza-captcha-for-all-forms' );
		}

		// Support FormCraft v3+.
		if ( version_compare( $version, '3.0', '>=' ) ) {
			return __( 'Compatible', 'invenza-captcha-for-all-forms' );
		}

		return __( 'Unknown', 'invenza-captcha-for-all-forms' );
	}
}
