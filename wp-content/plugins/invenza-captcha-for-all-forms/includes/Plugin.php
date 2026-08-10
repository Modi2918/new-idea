<?php
namespace Invcaf\Includes;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Plugin Loader and Bootstrapper.
 */
class Plugin {

	/**
	 * Single instance of the class.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Initializes standard hooks.
	 */
	private function __construct() {
		// Enqueue scripts and styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_if_formcraft_enqueued' ), 9999 );
		add_action( 'wp_footer', array( $this, 'enqueue_assets_in_footer' ), 15 );

		// Hook to capture CAPTCHA image generation.
		add_action( 'init', array( $this, 'intercept_image_request' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_invcaf_refresh_captcha', array( $this, 'ajax_refresh_captcha' ) );
		add_action( 'wp_ajax_nopriv_invcaf_refresh_captcha', array( $this, 'ajax_refresh_captcha' ) );

		// Initialize FormCraft integration.
		new FormCraftIntegration();

		// Initialize third-party form plugin integrations.
		$this->init_third_party_integrations();

		// Initialize admin components.
		if ( is_admin() ) {
			new \Invcaf\Admin\SettingsPage();
		}

		// Initialize frontend components.
		new \Invcaf\Frontend\Shortcode();

		// Support newly created sites in multisite.
		add_action( 'wpmu_new_blog', array( $this, 'new_blog_created' ) );
		add_action( 'wp_initialize_site', array( $this, 'site_initialized' ), 10, 2 );

		// Register REST API routes.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Register Admin assets hook.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Hook WP-Cron actions.
		add_action( 'invcaf_cleanup_logs', array( $this, 'cleanup_logs_callback' ) );
		add_action( 'invcaf_cleanup_expired_captcha', array( $this, 'cleanup_expired_captcha_callback' ) );

		// WP-CLI command registration.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'invcaf', __NAMESPACE__ . '\\CLICommand' );
		}
	}

	/**
	 * Plugin Activation callback.
	 *
	 * @param bool $network_wide True if network activated.
	 */
	public static function activate( $network_wide ) {
		global $wpdb;

		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			if ( ! empty( $site_ids ) ) {
				foreach ( $site_ids as $site_id ) {
					switch_to_blog( $site_id );
					self::create_events_table();
					restore_current_blog();
				}
			}
		} else {
			self::create_events_table();
		}

		self::schedule_cron_tasks();
	}

	/**
	 * Plugin Deactivation callback.
	 */
	public static function deactivate() {
		// Do not run raw SQL DELETE ... LIKE queries against wp_options here.
		// WordPress VIP utilizes external object caching (Memcached/Redis).
		// Transients will naturally evict based on their TTL.

		self::clear_cron_tasks();
	}

	/**
	 * Create the logs table in the database.
	 */
	public static function create_events_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'invcaf_events';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			form_id bigint(20) unsigned NOT NULL,
			session_hash varchar(64) NOT NULL,
			ip_hash varchar(64) NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY form_id (form_id),
			KEY ip_hash_event_created (ip_hash, event_type, created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Handles log table creation for legacy Multisite site creations.
	 *
	 * @param int $blog_id New blog ID.
	 */
	public function new_blog_created( $blog_id ) {
		if ( is_plugin_active_for_network( plugin_basename( INVCAF_PLUGIN_FILE ) ) ) {
			switch_to_blog( $blog_id );
			self::create_events_table();
			restore_current_blog();
		}
	}

	/**
	 * Handles log table creation for modern Multisite site creations (WP 5.1+).
	 *
	 * @param \WP_Site $site New site object.
	 * @param array    $args Site creation arguments.
	 */
	public function site_initialized( $site, $args ) {
		if ( is_a( $site, 'WP_Site' ) && is_plugin_active_for_network( plugin_basename( INVCAF_PLUGIN_FILE ) ) ) {
			switch_to_blog( $site->id );
			self::create_events_table();
			restore_current_blog();
		}
	}

	/**
	 * Register styles and scripts for lazy loading.
	 */
	public function register_assets() {
		$css_ver = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( INVCAF_PLUGIN_DIR . 'assets/css/captcha.css' ) ) ? filemtime( INVCAF_PLUGIN_DIR . 'assets/css/captcha.css' ) : INVCAF_VERSION;
		$js_ver  = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( INVCAF_PLUGIN_DIR . 'assets/js/captcha.js' ) ) ? filemtime( INVCAF_PLUGIN_DIR . 'assets/js/captcha.js' ) : INVCAF_VERSION;

		wp_register_style(
			'invcaf-captcha-style',
			INVCAF_PLUGIN_URL . 'assets/css/captcha.css',
			array(),
			$css_ver
		);

		wp_register_script(
			'invcaf-captcha-script',
			INVCAF_PLUGIN_URL . 'assets/js/captcha.js',
			array( 'jquery' ),
			$js_ver,
			true
		);

		wp_localize_script(
			'invcaf-captcha-script',
			'invcaf_vars',
			array(
				'ajaxurl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'invcaf_refresh_nonce' ),
				'position'     => Settings::get( 'position', 'before_submit' ),
				'enabled'      => Settings::get( 'enabled', '1' ),
				'fc_enabled'   => Settings::get( 'fc_enabled', '1' ),
				'fc_all_forms' => Settings::get( 'fc_all_forms', '1' ),
				'fc_forms'     => Settings::get( 'fc_forms', '' ),
				'theme'        => Settings::get( 'theme', 'light' ),
			)
		);
	}

	/**
	 * Intercept URL query parameter for rendering CAPTCHA.
	 */
	public function intercept_image_request() {
		// Early DDoS blocking check.
		if ( CaptchaStorage::is_ip_blocked( SecurityManager::get_ip_hash() ) ) {
			wp_die( 'Access Denied: IP Blocked', 'Access Denied', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['fcaptcha'] ) && sanitize_key( wp_unslash( $_GET['fcaptcha'] ) ) === 'generate' ) {
			$form_id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
			$session_key = isset( $_GET['c_id'] ) ? sanitize_key( wp_unslash( $_GET['c_id'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			CaptchaGenerator::generate( $form_id, $session_key );
			exit;
		}
	}

	/**
	 * AJAX endpoint handler to refresh/fetch a new CAPTCHA challenge.
	 */
	public function ajax_refresh_captcha() {
		// Early DDoS blocking check.
		if ( CaptchaStorage::is_ip_blocked( SecurityManager::get_ip_hash() ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many failed attempts. Submissions blocked.', 'invenza-captcha-for-all-forms' ) ) );
		}

		// Nonce verification.
		check_ajax_referer( 'invcaf_refresh_nonce', 'security' );

		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		if ( ! $form_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid Form ID', 'invenza-captcha-for-all-forms' ) ) );
		}

		$session_key = SecurityManager::generate_session_key();

		// Construct image URL pointing to interceptor.
		$image_url = add_query_arg(
			array(
				'fcaptcha' => 'generate',
				'id'       => $form_id,
				'c_id'     => $session_key,
			),
			home_url( '/' )
		);

		wp_send_json_success(
			array(
				'session_key' => $session_key,
				'image_url'   => esc_url_raw( $image_url ),
			)
		);
	}

	/**
	 * Register WP REST API Endpoints.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'invcaf/v1',
			'/captcha',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_captcha' ),
				'permission_callback' => array( $this, 'rest_permission_check' ),
			)
		);

		register_rest_route(
			'invcaf/v1',
			'/validate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_validate_captcha' ),
				'permission_callback' => array( $this, 'rest_permission_check' ),
			)
		);
	}

	/**
	 * Public access permission check for REST API.
	 *
	 * @return bool Always true.
	 */
	public function rest_permission_check(): bool {
		return true;
	}

	/**
	 * REST API Callback: GET /invcaf/v1/captcha
	 *
	 * @param \WP_REST_Request $request Request details.
	 * @return \WP_REST_Response REST Response.
	 */
	public function rest_get_captcha( $request ) {
		// Early DDoS blocking check.
		if ( CaptchaStorage::is_ip_blocked( SecurityManager::get_ip_hash() ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Too many failed attempts. Submissions blocked.', 'invenza-captcha-for-all-forms' ),
				),
				403
			);
		}

		$form_id = (int) $request->get_param( 'form_id' );
		if ( ! $form_id ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid or missing form_id parameter.', 'invenza-captcha-for-all-forms' ),
				),
				400
			);
		}

		// Rate Limiting check.
		$fingerprint = SecurityManager::get_session_fingerprint();
		if ( ! CaptchaStorage::check_rate_limit( $fingerprint ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Too many CAPTCHA requests. Please wait a moment.', 'invenza-captcha-for-all-forms' ),
				),
				429
			);
		}

		$session_key = SecurityManager::generate_session_key();
		$image_url   = add_query_arg(
			array(
				'fcaptcha' => 'generate',
				'id'       => $form_id,
				'c_id'     => $session_key,
			),
			home_url( '/' )
		);

		return new \WP_REST_Response(
			array(
				'success'     => true,
				'session_key' => $session_key,
				'image_url'   => esc_url_raw( $image_url ),
			),
			200
		);
	}

	/**
	 * REST API Callback: POST /invcaf/v1/validate
	 *
	 * @param \WP_REST_Request $request Request details.
	 * @return \WP_REST_Response REST Response.
	 */
	public function rest_validate_captcha( $request ) {
		$form_id     = (int) $request->get_param( 'form_id' );
		$session_key = sanitize_key( (string) $request->get_param( 'session_key' ) );
		$code        = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$honeypot    = sanitize_text_field( (string) $request->get_param( 'honeypot' ) );

		if ( ! $form_id || empty( $session_key ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Missing required validation arguments.', 'invenza-captcha-for-all-forms' ),
				),
				400
			);
		}

		$validation_result = CaptchaValidator::validate( $form_id, $session_key, $code, $honeypot );

		if ( true === $validation_result ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Verification passed.', 'invenza-captcha-for-all-forms' ),
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => $validation_result,
			),
			400
		);
	}

	/**
	 * Schedule hourly / daily background cron tasks.
	 */
	public static function schedule_cron_tasks() {
		if ( ! wp_next_scheduled( 'invcaf_cleanup_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'invcaf_cleanup_logs' );
		}
		if ( ! wp_next_scheduled( 'invcaf_cleanup_expired_captcha' ) ) {
			wp_schedule_event( time(), 'daily', 'invcaf_cleanup_expired_captcha' );
		}
	}

	/**
	 * Clear scheduled cron tasks on deactivation.
	 */
	public static function clear_cron_tasks() {
		$timestamp = wp_next_scheduled( 'invcaf_cleanup_logs' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'invcaf_cleanup_logs' );
		}

		$timestamp_expired = wp_next_scheduled( 'invcaf_cleanup_expired_captcha' );
		if ( $timestamp_expired ) {
			wp_unschedule_event( $timestamp_expired, 'invcaf_cleanup_expired_captcha' );
		}
	}

	/**
	 * WP-Cron callback: Delete database logs older than 30 days.
	 */
	public function cleanup_logs_callback() {
		global $wpdb;
		$invcaf_table_name = esc_sql( $wpdb->prefix . 'invcaf_events' );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'invcaf_events' ) ) === $wpdb->prefix . 'invcaf_events' ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$invcaf_cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is escaped, delete query has no cache result
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$invcaf_table_name}` WHERE created_at < %s LIMIT 500", $invcaf_cutoff ) );
		}
	}

	/**
	 * WP-Cron callback: Clean up expired transients and rate limits from options table.
	 */
	public function cleanup_expired_captcha_callback() {
		// Intentionally left empty for VIP compatibility.
		// Transients are automatically evicted by the object cache (Memcached/Redis) via TTL.
		// Raw SQL DELETE ... LIKE queries against wp_options cause massive full table scans and locks.
	}

	/**
	 * Enqueue assets during main queue if FormCraft script is enqueued/registered.
	 */
	public function enqueue_if_formcraft_enqueued() {
		if ( wp_script_is( 'fc-form', 'enqueued' ) || wp_script_is( 'fc-form', 'done' ) ) {
			wp_enqueue_style( 'invcaf-captcha-style' );
			wp_enqueue_script( 'invcaf-captcha-script' );
		}
	}

	/**
	 * Conditionally instantiate each third-party form plugin integration.
	 * Each integration is guarded by a class/function existence check so it only
	 * loads when the target plugin is active.
	 */
	private function init_third_party_integrations() {
		// Contact Form 7.
		if ( Settings::get( 'cf7_enabled' ) === '1' && ( function_exists( 'wpcf7' ) || class_exists( 'WPCF7' ) ) ) {
			new \Invcaf\Includes\Integrations\ContactForm7Integration();
		}

		// WPForms.
		if ( Settings::get( 'wpforms_enabled' ) === '1' && function_exists( 'wpforms' ) ) {
			new \Invcaf\Includes\Integrations\WPFormsIntegration();
		}

		// Forminator.
		if ( Settings::get( 'forminator_enabled' ) === '1' && ( class_exists( 'Forminator' ) || function_exists( 'forminator_api' ) ) ) {
			new \Invcaf\Includes\Integrations\ForminatorIntegration();
		}

		// Gravity Forms.
		if ( Settings::get( 'gf_enabled' ) === '1' && ( class_exists( 'GFForms' ) || class_exists( 'GF_Fields' ) ) ) {
			new \Invcaf\Includes\Integrations\GravityFormsIntegration();
		}

		// Fluent Forms.
		if ( Settings::get( 'fluentforms_enabled' ) === '1' && ( function_exists( 'wpFluentForm' ) || defined( 'FLUENTFORM' ) ) ) {
			new \Invcaf\Includes\Integrations\FluentFormsIntegration();
		}
	}

	/**
	 * Dynamic fallback printing of scripts in footer if FormCraft is found.
	 */
	public function enqueue_assets_in_footer() {
		if ( wp_script_is( 'fc-form', 'enqueued' ) || wp_script_is( 'fc-form', 'done' ) ) {
			wp_enqueue_style( 'invcaf-captcha-style' );
			wp_enqueue_script( 'invcaf-captcha-script' );
		}
	}

	/**
	 * Enqueue admin-only CSS/JS styles on the settings page.
	 *
	 * @param string $hook Page hook string.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_invenza-captcha-for-all-forms' !== $hook ) {
			return;
		}

		$admin_css_ver = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( INVCAF_PLUGIN_DIR . 'assets/css/admin.css' ) ) ? filemtime( INVCAF_PLUGIN_DIR . 'assets/css/admin.css' ) : INVCAF_VERSION;
		$admin_js_ver  = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( INVCAF_PLUGIN_DIR . 'assets/js/admin.js' ) ) ? filemtime( INVCAF_PLUGIN_DIR . 'assets/js/admin.js' ) : INVCAF_VERSION;

		wp_enqueue_style(
			'invcaf-admin-style',
			INVCAF_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			$admin_css_ver
		);

		wp_enqueue_script(
			'invcaf-admin-script',
			INVCAF_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			$admin_js_ver,
			true
		);

		wp_localize_script(
			'invcaf-admin-script',
			'invcafAdmin',
			array(
				'domain'    => home_url(),
				'nonce'     => wp_create_nonce( 'invcaf_admin_nonce' ),
				'error_msg' => __( 'An error occurred.', 'invenza-captcha-for-all-forms' ),
			)
		);
		wp_localize_script(
			'invcaf-admin-script',
			'invcaf_admin_vars',
			array(
				'domain'    => home_url(),
				'nonce'     => wp_create_nonce( 'invcaf_admin_nonce' ),
				'error_msg' => __( 'An error occurred.', 'invenza-captcha-for-all-forms' ),
			)
		);
	}
}
