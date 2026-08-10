<?php
namespace InvenzaLicenseServer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initializes Hooks for the License Server Plugin.
 */
class Init {

	/**
	 * Bootstrap hooks.
	 */
	public static function start() {
		// Ensure database tables exist (run once per version upgrade).
		if ( get_option( 'INVENZA_SERVER_db_version' ) !== INVENZA_SERVER_VERSION ) {
			Database::create_tables();
			update_option( 'INVENZA_SERVER_db_version', INVENZA_SERVER_VERSION );
		}

		// Admin dashboard hooks.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( 'InvenzaLicenseServer\AdminPanel', 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		}

		add_action( 'init', array( __CLASS__, 'handle_legacy_slug_redirect' ) );

		// SMTP email configuration hook
		add_action( 'phpmailer_init', array( 'InvenzaLicenseServer\EmailService', 'configure_smtp' ) );

		// Landing Page & Checkout hook
		LandingPage::register();

		// REST API Hooks.
		add_action( 'rest_api_init', array( 'InvenzaLicenseServer\Api', 'register_routes' ) );
	}

	/**
	 * Enqueue admin assets specifically for the License Server dashboard.
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'invenza-license-server' ) && false === strpos( $hook, 'fcac-license-server' ) ) {
			return;
		}

		wp_enqueue_style( 'fcac-server-admin-css', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/admin.css', array(), INVENZA_SERVER_VERSION );
		wp_enqueue_script( 'fcac-server-admin-js', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/admin.js', array( 'jquery' ), INVENZA_SERVER_VERSION, true );
	}

	/**
	 * Redirect legacy settings page slug requests to prevent permission errors.
	 */
	public static function handle_legacy_slug_redirect() {
		if ( isset( $_GET['page'] ) && 'fcac-license-server' === $_GET['page'] ) {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			$url = admin_url( 'admin.php?page=invenza-license-server' );
			if ( $tab ) {
				$url = add_query_arg( 'tab', $tab, $url );
			}
			wp_safe_redirect( $url );
			exit;
		}
	}
}
