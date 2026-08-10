<?php
// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Invcaf\Includes\Settings;
use Invcaf\Includes\FormCraftIntegration;
use Invcaf\Includes\DependencyChecker;

// Fetch settings.
$invcaf_settings = Settings::get_all();

// Detect which supported form plugins are active (purely informational — none are required).
$invcaf_supported_plugins_status = array(
	array(
		'name'   => __( 'Contact Form 7', 'invenza-captcha-for-all-forms' ),
		'active' => function_exists( 'wpcf7' ) || class_exists( 'WPCF7' ),
	),
	array(
		'name'   => __( 'WPForms', 'invenza-captcha-for-all-forms' ),
		'active' => function_exists( 'wpforms' ),
	),
	array(
		'name'   => __( 'Forminator', 'invenza-captcha-for-all-forms' ),
		'active' => class_exists( 'Forminator' ) || function_exists( 'forminator_api' ),
	),
	array(
		'name'   => __( 'Gravity Forms', 'invenza-captcha-for-all-forms' ),
		'active' => class_exists( 'GFForms' ) || class_exists( 'GF_Fields' ),
	),
	array(
		'name'   => __( 'Fluent Forms', 'invenza-captcha-for-all-forms' ),
		'active' => function_exists( 'wpFluentForm' ) || defined( 'FLUENTFORM' ),
	),
	array(
		'name'   => __( 'FormCraft', 'invenza-captcha-for-all-forms' ),
		'active' => class_exists( 'FormCraft' ) || defined( 'FORMCRAFT_VERSION' ) || \Invcaf\Includes\DependencyChecker::is_installed(),
	),
);
$invcaf_any_active               = ! empty( array_filter( array_column( $invcaf_supported_plugins_status, 'active' ) ) );
$invcaf_gd_enabled               = extension_loaded( 'gd' );
$invcaf_freetype_enabled         = function_exists( 'imagettftext' );

$invcaf_last_verify = get_option(
	'invcaf_last_verification',
	get_option(
		'invenza_captcha_last_verification',
		array(
			'time'    => 0,
			'success' => false,
			'reason'  => '',
		)
	)
);

// Retrieve Logs if table exists.
global $wpdb;
$invcaf_table_name       = esc_sql( $wpdb->prefix . 'invcaf_events' );
$invcaf_logs             = array();
$invcaf_total_attempts   = 0;
$invcaf_success_attempts = 0;
$invcaf_fail_attempts    = 0;
$invcaf_success_rate     = 0;

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'invcaf_events' ) ) === $wpdb->prefix . 'invcaf_events' ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$invcaf_paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$invcaf_per_page = 100;
	$invcaf_offset   = ( $invcaf_paged - 1 ) * $invcaf_per_page;
	// Direct query required: custom table with interpolated (esc_sql) table name, paginated. Caching not applicable for live log display.
	$invcaf_logs = $wpdb->get_results( $wpdb->prepare( "SELECT id, event_type, form_id, session_hash, ip_hash, created_at FROM `{$invcaf_table_name}` ORDER BY id DESC LIMIT %d OFFSET %d", $invcaf_per_page, $invcaf_offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// Cache verification statistics to avoid unbounded COUNT(*) queries on every page load.
	$invcaf_stats = get_transient( 'invcaf_verification_stats' );
	if ( false === $invcaf_stats ) {
		// Direct queries required: custom table aggregate counts, cached via transient above.
		$invcaf_stats = array(
			'total'   => absint( $wpdb->get_var( "SELECT COUNT(*) FROM `{$invcaf_table_name}`" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'success' => absint( $wpdb->get_var( "SELECT COUNT(*) FROM `{$invcaf_table_name}` WHERE event_type = 'passed'" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'fail'    => absint( $wpdb->get_var( "SELECT COUNT(*) FROM `{$invcaf_table_name}` WHERE event_type IN ('failed', 'blocked')" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);
		set_transient( 'invcaf_verification_stats', $invcaf_stats, 15 * MINUTE_IN_SECONDS );
	}

	$invcaf_total_attempts   = $invcaf_stats['total'];
	$invcaf_success_attempts = $invcaf_stats['success'];
	$invcaf_fail_attempts    = $invcaf_stats['fail'];

	if ( $invcaf_total_attempts > 0 ) {
		$invcaf_success_rate = round( ( $invcaf_success_attempts / $invcaf_total_attempts ) * 100, 1 );
	}
}

// Current active tab.
$invcaf_active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="invcaf-admin-wrap" id="invcaf_app_root" data-theme="light">
	<div class="invcaf-header">
		<h1 class="invcaf-header-title">
			<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--invcaf-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
				<polyline points="9 12 11 14 15 10"></polyline>
			</svg>
			<?php esc_html_e( 'Invcaf CAPTCHA Enterprise', 'invenza-captcha-for-all-forms' ); ?>
		</h1>
		<div class="invcaf-header-actions">
			<button class="invcaf-theme-toggle" id="invcaf_theme_toggle" type="button" aria-label="Toggle Dark Mode" title="Toggle Dark Mode">
				<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
					<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
				</svg>
			</button>
		</div>
	</div>
	<?php
	$invcaf_wizard_dismissed = get_option( 'invcaf_wizard_dismissed', get_option( 'invenza_captcha_wizard_dismissed', '0' ) );
	if ( '1' !== $invcaf_wizard_dismissed ) :
		$invcaf_dismiss_url = wp_nonce_url( add_query_arg( 'invcaf_action', 'dismiss_wizard' ), 'invcaf_dismiss_wizard' );
		?>
		<div class="invcaf-card invcaf-wizard-banner" style="margin: 0 0 20px; border-left: 4px solid var(--invcaf-primary); background: #f0f7ff;">
			<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
				<div>
					<h3 style="margin:0 0 4px; font-size:16px; color:#1e40af;">👋 <?php esc_html_e( 'Welcome to Invcaf CAPTCHA!', 'invenza-captcha-for-all-forms' ); ?></h3>
					<p style="margin:0; font-size:13px; color:#3b82f6;">
						<?php esc_html_e( 'GD Extension: ', 'invenza-captcha-for-all-forms' ); ?>
						<strong><?php echo $invcaf_gd_enabled ? '✅ Active' : '❌ Missing'; ?></strong> &nbsp;|&nbsp;
						<?php esc_html_e( 'FreeType: ', 'invenza-captcha-for-all-forms' ); ?>
						<strong><?php echo $invcaf_freetype_enabled ? '✅ Active' : '⚠️ Disabled'; ?></strong> &nbsp;|&nbsp;
						<?php esc_html_e( 'Supported Form Builders Detected: ', 'invenza-captcha-for-all-forms' ); ?>
						<strong><?php echo esc_html( count( array_filter( array_column( $invcaf_supported_plugins_status, 'active' ) ) ) ); ?></strong>
					</p>
				</div>
				<div>
					<a href="?page=invenza-captcha-for-all-forms&tab=settings" class="button button-primary"><?php esc_html_e( '⚙️ Quick Setup', 'invenza-captcha-for-all-forms' ); ?></a>
					<a href="<?php echo esc_url( $invcaf_dismiss_url ); ?>" class="button button-secondary" style="margin-left:6px;"><?php esc_html_e( 'Dismiss', 'invenza-captcha-for-all-forms' ); ?></a>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<div class="invcaf-body">
		<div class="invcaf-sidebar">
			<a href="?page=invenza-captcha-for-all-forms&tab=settings" class="invcaf-nav-item <?php echo 'settings' === $invcaf_active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Configuration', 'invenza-captcha-for-all-forms' ); ?></a>
			<a href="?page=invenza-captcha-for-all-forms&tab=dashboard" class="invcaf-nav-item <?php echo 'dashboard' === $invcaf_active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Compatibility Dashboard', 'invenza-captcha-for-all-forms' ); ?></a>
			<a href="?page=invenza-captcha-for-all-forms&tab=logs" class="invcaf-nav-item <?php echo 'logs' === $invcaf_active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Logs', 'invenza-captcha-for-all-forms' ); ?></a>
			<a href="?page=invenza-captcha-for-all-forms&tab=system" class="invcaf-nav-item <?php echo 'system' === $invcaf_active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'System & Health', 'invenza-captcha-for-all-forms' ); ?></a>
			<a href="?page=invenza-captcha-for-all-forms&tab=help" class="invcaf-nav-item <?php echo 'help' === $invcaf_active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Help & Docs', 'invenza-captcha-for-all-forms' ); ?></a>
			<a href="?page=invenza-captcha-for-all-forms&tab=pro" class="invcaf-nav-item invcaf-nav-pro <?php echo 'pro' === $invcaf_active_tab ? 'active' : ''; ?>" style="background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%); color: #ffffff; font-weight: 600; margin-top: 10px; border-radius: 6px; text-align: center; text-shadow: 0 1px 2px rgba(0,0,0,0.2);"><?php esc_html_e( '⭐ Upgrade to Pro', 'invenza-captcha-for-all-forms' ); ?></a>
		</div>
		<div class="invcaf-content">
			<?php if ( 'settings' === $invcaf_active_tab ) : ?>
				<?php settings_errors( 'invcaf_tools' ); ?>
			
			<!-- Live CAPTCHA Preview & Diagnostic Testing Card -->
			<div class="invcaf-card invcaf-preview-card" style="border-left: 4px solid #10b981; background: #f0fdf4;">
				<div class="invcaf-card-header"><h3 class="invcaf-card-title"><?php esc_html_e( 'Live CAPTCHA Preview & Server Diagnostic', 'invenza-captcha-for-all-forms' ); ?></h3></div>
				<div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap; margin-top:10px;">
					<div id="invcaf_live_preview_wrapper" style="padding:10px; background:#ffffff; border:1px solid #cbd5e1; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; min-width:150px; min-height:50px;">
						<img id="invcaf_live_preview_img" src="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'fcaptcha' => 'generate',
									'id'       => 0,
									'c_id'     => 'preview_' . wp_generate_password( 8, false ),
									't'        => time(),
								),
								home_url( '/' )
							)
						);
						?>
																" alt="Live CAPTCHA Preview" style="display:block; max-width:100%; height:auto;" />
					</div>
					<div>
						<button type="button" id="invcaf_btn_test_captcha" class="button button-secondary">
							🔄 <?php esc_html_e( 'Generate Test CAPTCHA', 'invenza-captcha-for-all-forms' ); ?>
						</button>
						<div id="invcaf_test_captcha_result" style="margin-top:8px; font-size:13px;"></div>
					</div>
				</div>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'invcaf_settings_group' );
				?>
			<!-- General Settings -->
			<div class="invcaf-card"><div class="invcaf-card-header"><h3 class="invcaf-card-title"><?php esc_html_e( 'General Settings', 'invenza-captcha-for-all-forms' ); ?></h3></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Enable CAPTCHA', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><label class="invcaf-toggle-label"><span class="invcaf-toggle-switch"><input type="checkbox" name="invcaf_settings[enabled]" value="1" <?php checked( $invcaf_settings['enabled'], '1' ); ?> /><span class="invcaf-toggle-slider"></span></span><span>
							<?php esc_html_e( 'Turn on custom image CAPTCHA functionality.', 'invenza-captcha-for-all-forms' ); ?></span></label></div></div>

				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Character Set', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><label class="invcaf-toggle-label"><span class="invcaf-toggle-switch"><input type="checkbox" name="invcaf_settings[char_set][]" value="letters" <?php checked( in_array( 'letters', $invcaf_settings['char_set'], true ) ); ?> /><span class="invcaf-toggle-slider"></span></span><span>
							<?php esc_html_e( 'Capital Letters (excluding: O, I, L)', 'invenza-captcha-for-all-forms' ); ?></span></label><br />
						<label class="invcaf-toggle-label"><span class="invcaf-toggle-switch"><input type="checkbox" name="invcaf_settings[char_set][]" value="small_letters" <?php checked( in_array( 'small_letters', $invcaf_settings['char_set'], true ) ); ?> /><span class="invcaf-toggle-slider"></span></span><span>
							<?php esc_html_e( 'Small Letters (excluding: o, i, l)', 'invenza-captcha-for-all-forms' ); ?></span></label><br />
						<label class="invcaf-toggle-label"><span class="invcaf-toggle-switch"><input type="checkbox" name="invcaf_settings[char_set][]" value="numbers" <?php checked( in_array( 'numbers', $invcaf_settings['char_set'], true ) ); ?> /><span class="invcaf-toggle-slider"></span></span><span>
							<?php esc_html_e( 'Numbers (excluding: 0, 1)', 'invenza-captcha-for-all-forms' ); ?></span></label></div></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'CAPTCHA Length', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><input type="number" name="invcaf_settings[length]" value="<?php echo esc_attr( $invcaf_settings['length'] ); ?>" min="4" max="8" class="invcaf-input" />
						<span class="invcaf-help-text"><?php esc_html_e( 'Configure number of code characters (4-8) - applicable only for standard image mode.', 'invenza-captcha-for-all-forms' ); ?></span></div></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Expiration (Minutes)', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><input type="number" name="invcaf_settings[expiration]" value="<?php echo esc_attr( $invcaf_settings['expiration'] ); ?>" min="1" max="60" class="invcaf-input" />
						<span class="invcaf-help-text"><?php esc_html_e( 'Duration of CAPTCHA code validity (Default: 5).', 'invenza-captcha-for-all-forms' ); ?></span></div></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'CAPTCHA Visual Theme', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><select class="invcaf-select" name="invcaf_settings[theme]">
							<option value="light" <?php selected( $invcaf_settings['theme'], 'light' ); ?>><?php esc_html_e( 'Light Minimal', 'invenza-captcha-for-all-forms' ); ?></option>
							<option value="dark" <?php selected( $invcaf_settings['theme'], 'dark' ); ?>><?php esc_html_e( 'Sleek Dark', 'invenza-captcha-for-all-forms' ); ?></option>
							<option value="glass" <?php selected( $invcaf_settings['theme'], 'glass' ); ?>><?php esc_html_e( 'Glassmorphism', 'invenza-captcha-for-all-forms' ); ?></option>
							<option value="neon" <?php selected( $invcaf_settings['theme'], 'neon' ); ?>><?php esc_html_e( 'Neon Security', 'invenza-captcha-for-all-forms' ); ?></option>
							<option value="sunset" <?php selected( $invcaf_settings['theme'], 'sunset' ); ?>><?php esc_html_e( 'Sunset Glow', 'invenza-captcha-for-all-forms' ); ?></option>
						</select>
						<span class="invcaf-help-text"><?php esc_html_e( 'Choose the visual wrapper skin for frontend form rendering.', 'invenza-captcha-for-all-forms' ); ?></span></div></div>

				<!-- Image Rendering -->
				</div><div class="invcaf-card"><div class="invcaf-card-header"><h3 class="invcaf-card-title"><?php esc_html_e( 'Image Customizations', 'invenza-captcha-for-all-forms' ); ?></h3></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Dimensions (Width x Height)', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><input type="number" name="invcaf_settings[width]" value="<?php echo esc_attr( $invcaf_settings['width'] ); ?>" min="100" max="500" class="invcaf-input invcaf-inline-input" /> px &times;
						<input type="number" name="invcaf_settings[height]" value="<?php echo esc_attr( $invcaf_settings['height'] ); ?>" min="30" max="200" class="invcaf-input invcaf-inline-input" /> px</div></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Noise Background', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><label class="invcaf-toggle-label"><span class="invcaf-toggle-switch"><input type="checkbox" name="invcaf_settings[dots]" value="1" <?php checked( $invcaf_settings['dots'], '1' ); ?> /><span class="invcaf-toggle-slider"></span></span><span>
							<?php esc_html_e( 'Render background dots.', 'invenza-captcha-for-all-forms' ); ?></span></label><br />
						<label class="invcaf-toggle-label"><span class="invcaf-toggle-switch"><input type="checkbox" name="invcaf_settings[lines]" value="1" <?php checked( $invcaf_settings['lines'], '1' ); ?> /><span class="invcaf-toggle-slider"></span></span><span>
							<?php esc_html_e( 'Render random noise lines.', 'invenza-captcha-for-all-forms' ); ?></span></label></div></div>

				<!-- Security Settings -->
				</div><div class="invcaf-card"><div class="invcaf-card-header"><h3 class="invcaf-card-title"><?php esc_html_e( 'Security & Anti-Bot Options', 'invenza-captcha-for-all-forms' ); ?></h3></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'CAPTCHA Generation Rate Limit', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><input type="number" name="invcaf_settings[rate_limit]" value="<?php echo esc_attr( $invcaf_settings['rate_limit'] ); ?>" min="10" max="1000" class="invcaf-input" />
						<span class="invcaf-help-text"><?php esc_html_e( 'Maximum number of CAPTCHA generations allowed per minute per IP. Increase this if legitimate users see a "Rate Limit" error (Default: 50).', 'invenza-captcha-for-all-forms' ); ?></span></div></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Maximum Attempts', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><input type="number" name="invcaf_settings[max_attempts]" value="<?php echo esc_attr( $invcaf_settings['max_attempts'] ); ?>" min="1" max="20" class="invcaf-input" />
						<span class="invcaf-help-text"><?php esc_html_e( 'Maximum failed inputs allowed per session before locking (Default: 5).', 'invenza-captcha-for-all-forms' ); ?></span></div></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Lockout Duration (Minutes)', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><input type="number" name="invcaf_settings[block_duration]" value="<?php echo esc_attr( $invcaf_settings['block_duration'] ); ?>" min="1" max="1440" class="invcaf-input" />
						<span class="invcaf-help-text"><?php esc_html_e( 'Time user is blocked after exceeding max validation failures (Default: 10).', 'invenza-captcha-for-all-forms' ); ?></span></div></div>

				</div><div class="invcaf-card"><div class="invcaf-card-header"><h3 class="invcaf-card-title"><?php esc_html_e( 'FormCraft Integration', 'invenza-captcha-for-all-forms' ); ?></h3><p class="invcaf-card-desc"><p class="description"><?php esc_html_e( 'Settings below apply to FormCraft only. For other form plugins (CF7, WPForms, etc.) use the Third-Party Form Integrations section below.', 'invenza-captcha-for-all-forms' ); ?></p></p></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'FormCraft Hook Enabled', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><label class="invcaf-toggle-label"><span class="invcaf-toggle-switch"><input type="checkbox" name="invcaf_settings[fc_enabled]" value="1" <?php checked( $invcaf_settings['fc_enabled'], '1' ); ?> /><span class="invcaf-toggle-slider"></span></span><span>
							<?php esc_html_e( 'Integrate CAPTCHA directly into FormCraft form save sequences.', 'invenza-captcha-for-all-forms' ); ?></span></label></div></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Apply to Forms', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><label class="invcaf-toggle-label"><span class="invcaf-toggle-switch"><input type="checkbox" name="invcaf_settings[fc_all_forms]" value="1" <?php checked( $invcaf_settings['fc_all_forms'], '1' ); ?> /><span class="invcaf-toggle-slider"></span></span><span>
							<?php esc_html_e( 'Enable CAPTCHA for all FormCraft forms.', 'invenza-captcha-for-all-forms' ); ?></span></label></div></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Specific Form IDs', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><input type="text" name="invcaf_settings[fc_forms]" value="<?php echo esc_attr( $invcaf_settings['fc_forms'] ); ?>" class="invcaf-input" />
						<span class="invcaf-help-text"><?php esc_html_e( 'Comma-separated list of form IDs (e.g. 1, 4, 12) if "All Forms" is unchecked.', 'invenza-captcha-for-all-forms' ); ?></span></div></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Placement Position', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><select class="invcaf-select" name="invcaf_settings[position]">
							<option value="before_submit" <?php selected( $invcaf_settings['position'], 'before_submit' ); ?>><?php esc_html_e( 'Before Submit Button', 'invenza-captcha-for-all-forms' ); ?></option>
							<option value="form_bottom" <?php selected( $invcaf_settings['position'], 'form_bottom' ); ?>><?php esc_html_e( 'Form Bottom', 'invenza-captcha-for-all-forms' ); ?></option>
						</select>
						<span class="invcaf-help-text"><?php esc_html_e( 'Position to auto-inject the CAPTCHA block in FormCraft forms (does not apply to other form builders).', 'invenza-captcha-for-all-forms' ); ?></span></div></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Error Message: Invalid Code', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><input type="text" name="invcaf_settings[msg_invalid]" value="<?php echo esc_attr( $invcaf_settings['msg_invalid'] ); ?>" class="invcaf-input" /></div></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Error Message: Expired Session', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><input type="text" name="invcaf_settings[msg_expired]" value="<?php echo esc_attr( $invcaf_settings['msg_expired'] ); ?>" class="invcaf-input" /></div></div>

				<!-- Logging & Advanced -->
				</div><div class="invcaf-card"><div class="invcaf-card-header"><h3 class="invcaf-card-title"><?php esc_html_e( 'Data & Logging', 'invenza-captcha-for-all-forms' ); ?></h3></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Enable Event Logging', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><label class="invcaf-toggle-label"><span class="invcaf-toggle-switch"><input type="checkbox" name="invcaf_settings[logging_enabled]" value="1" <?php checked( $invcaf_settings['logging_enabled'], '1' ); ?> /><span class="invcaf-toggle-slider"></span></span><span>
							<?php esc_html_e( 'Save events (passed, failed, blocked) to database table. Recommended for dashboard analytics.', 'invenza-captcha-for-all-forms' ); ?>
							<br/>
								<?php esc_html_e( '[Not Recommended for VIP/High-Traffic Sites - may cause database replication lag]', 'invenza-captcha-for-all-forms' ); ?>
							</span></span></label></div></div>
				<div class="invcaf-form-row"><div class="invcaf-label"><?php esc_html_e( 'Delete data on uninstall', 'invenza-captcha-for-all-forms' ); ?></div><div class="invcaf-input-group"><label class="invcaf-toggle-label"><span class="invcaf-toggle-switch"><input type="checkbox" name="invcaf_settings[delete_on_uninstall]" value="1" <?php checked( $invcaf_settings['delete_on_uninstall'], '1' ); ?> /><span class="invcaf-toggle-slider"></span></span><span>
							<?php esc_html_e( 'Wipe out settings, transient records, and database log tables when deleting plugin.', 'invenza-captcha-for-all-forms' ); ?></span></label></div></div>
				<!-- Third-Party Form Integrations -->
			</div><div class="invcaf-card"><div class="invcaf-card-header"><h3 class="invcaf-card-title"><?php esc_html_e( 'Third-Party Form Integrations', 'invenza-captcha-for-all-forms' ); ?></h3></div>
			<div class="invcaf-card-desc invcaf-desc-italic">
						<?php esc_html_e( 'Enable CAPTCHA protection for the following form builders. Each plugin must be installed and active for its integration to load. Use [invcaf_captcha] tag (CF7), add the Invcaf CAPTCHA field from the field picker (WPForms / Gravity Forms), or embed the [invcaf_captcha id=0] shortcode in an HTML field (Forminator / Fluent Forms).', 'invenza-captcha-for-all-forms' ); ?>
			</div>			
				<?php
				$invcaf_third_party_plugins = array(
					array(
						'key'    => 'cf7',
						'label'  => __( 'Contact Form 7', 'invenza-captcha-for-all-forms' ),
						'hint'   => __( 'Add [invcaf_captcha] tag inside your CF7 form template to display the CAPTCHA widget.', 'invenza-captcha-for-all-forms' ),
						'active' => function_exists( 'wpcf7' ) || class_exists( 'WPCF7' ),
					),
					array(
						'key'    => 'wpforms',
						'label'  => __( 'WPForms', 'invenza-captcha-for-all-forms' ),
						'hint'   => __( 'Add the "Invcaf CAPTCHA" field from the WPForms field picker to any form.', 'invenza-captcha-for-all-forms' ),
						'active' => function_exists( 'wpforms' ),
					),
					array(
						'key'    => 'forminator',
						'label'  => __( 'Forminator', 'invenza-captcha-for-all-forms' ),
						'hint'   => __( 'Insert an HTML field inside your Forminator form and add [invcaf_captcha id=0] inside it.', 'invenza-captcha-for-all-forms' ),
						'active' => class_exists( 'Forminator' ) || function_exists( 'forminator_api' ),
					),
					array(
						'key'    => 'gf',
						'label'  => __( 'Gravity Forms', 'invenza-captcha-for-all-forms' ),
						'hint'   => __( 'Add the "Invcaf CAPTCHA" field from the Gravity Forms Advanced Fields panel.', 'invenza-captcha-for-all-forms' ),
						'active' => class_exists( 'GFForms' ) || class_exists( 'GF_Fields' ),
					),
					array(
						'key'    => 'fluentforms',
						'label'  => __( 'Fluent Forms', 'invenza-captcha-for-all-forms' ),
						'hint'   => __( 'Add the "Invcaf CAPTCHA" component from the Fluent Forms component panel.', 'invenza-captcha-for-all-forms' ),
						'active' => function_exists( 'wpFluentForm' ) || defined( 'FLUENTFORM' ),
					),
				);

				foreach ( $invcaf_third_party_plugins as $invcaf_plugin ) :
					$invcaf_key           = sanitize_key( $invcaf_plugin['key'] );
					$invcaf_label         = $invcaf_plugin['label'];
					$invcaf_hint          = $invcaf_plugin['hint'];
					$invcaf_is_active     = $invcaf_plugin['active'];
					$invcaf_enabled_key   = $invcaf_key . '_enabled';
					$invcaf_all_forms_key = $invcaf_key . '_all_forms';
					$invcaf_forms_key     = $invcaf_key . '_forms';
					?>
			<div class="invcaf-form-row">
				<div class="invcaf-label">
					<?php echo esc_html( $invcaf_label ); ?>
					<?php if ( $invcaf_is_active ) : ?>
						<span class="invcaf-badge invcaf-badge-success invcaf-ml-8"><?php esc_html_e( 'Active', 'invenza-captcha-for-all-forms' ); ?></span>
					<?php else : ?>
						<span class="invcaf-badge invcaf-badge-muted invcaf-ml-8"><?php esc_html_e( 'Not Installed', 'invenza-captcha-for-all-forms' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="invcaf-input-group">
					<label class="invcaf-toggle-label">
						<span class="invcaf-toggle-switch">
							<input type="checkbox" name="invcaf_settings[<?php echo esc_attr( $invcaf_enabled_key ); ?>]" value="1" <?php checked( $invcaf_settings[ $invcaf_enabled_key ] ?? '0', '1' ); ?> <?php disabled( ! $invcaf_is_active ); ?> />
							<span class="invcaf-toggle-slider"></span>
						</span>
						<span>
						<?php
						/* translators: %s: form plugin name */
						printf( esc_html__( 'Enable CAPTCHA for %s forms.', 'invenza-captcha-for-all-forms' ), esc_html( $invcaf_label ) );
						?>
						</span>
					</label>
					<span class="invcaf-help-text"><?php echo esc_html( $invcaf_hint ); ?></span>

					<div class="invcaf-pl-20-mt-10">
						<label class="invcaf-toggle-label">
							<span class="invcaf-toggle-switch">
								<input type="checkbox" name="invcaf_settings[<?php echo esc_attr( $invcaf_all_forms_key ); ?>]" value="1" <?php checked( $invcaf_settings[ $invcaf_all_forms_key ] ?? '1', '1' ); ?> <?php disabled( ! $invcaf_is_active ); ?> />
								<span class="invcaf-toggle-slider"></span>
							</span>
							<span><?php esc_html_e( 'Apply to all forms', 'invenza-captcha-for-all-forms' ); ?></span>
						</label>
						<br /><br />
						<label class="invcaf-label"><?php esc_html_e( 'Specific Form IDs:', 'invenza-captcha-for-all-forms' ); ?></label>
						<input type="text" name="invcaf_settings[<?php echo esc_attr( $invcaf_forms_key ); ?>]" value="<?php echo esc_attr( $invcaf_settings[ $invcaf_forms_key ] ?? '' ); ?>" class="invcaf-input invcaf-ml-8" <?php disabled( ! $invcaf_is_active ); ?> />
						<span class="invcaf-help-text"><?php esc_html_e( 'Comma-separated form IDs (e.g. 1, 4, 12). Only used when "Apply to all forms" is unchecked.', 'invenza-captcha-for-all-forms' ); ?></span>
					</div>
				</div>
			</div>
				<?php endforeach; ?>
			<!-- Tools & Data Management Card -->
			<div class="invcaf-card"><div class="invcaf-card-header"><h3 class="invcaf-card-title"><?php esc_html_e( 'Tools & Data Management', 'invenza-captcha-for-all-forms' ); ?></h3></div>
				<div class="invcaf-form-row">
					<div class="invcaf-label"><?php esc_html_e( 'Export Settings', 'invenza-captcha-for-all-forms' ); ?></div>
					<div class="invcaf-input-group">
						<button type="submit" name="invcaf_action" value="export_settings" class="button button-secondary">
							📥 <?php esc_html_e( 'Export Settings (JSON)', 'invenza-captcha-for-all-forms' ); ?>
						</button>
						<?php wp_nonce_field( 'invcaf_tools_action', 'invcaf_tools_nonce' ); ?>
						<span class="invcaf-help-text"><?php esc_html_e( 'Download a backup copy of your current CAPTCHA configuration.', 'invenza-captcha-for-all-forms' ); ?></span>
					</div>
				</div>
				<div class="invcaf-form-row">
					<div class="invcaf-label"><?php esc_html_e( 'Import Settings', 'invenza-captcha-for-all-forms' ); ?></div>
					<div class="invcaf-input-group">
						<input type="file" name="import_file" accept=".json" class="invcaf-input" style="width:auto;" />
						<button type="submit" name="invcaf_action" value="import_settings" class="button button-secondary" style="margin-left:8px;">
							📤 <?php esc_html_e( 'Import JSON File', 'invenza-captcha-for-all-forms' ); ?>
						</button>
						<span class="invcaf-help-text"><?php esc_html_e( 'Upload a previously exported settings JSON file.', 'invenza-captcha-for-all-forms' ); ?></span>
					</div>
				</div>
				<div class="invcaf-form-row">
					<div class="invcaf-label"><?php esc_html_e( 'Factory Reset', 'invenza-captcha-for-all-forms' ); ?></div>
					<div class="invcaf-input-group">
						<button type="submit" name="invcaf_action" value="reset_settings" class="button button-secondary invcaf-btn-danger" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to reset all settings to default values?', 'invenza-captcha-for-all-forms' ); ?>');">
							⚠️ <?php esc_html_e( 'Reset to Factory Defaults', 'invenza-captcha-for-all-forms' ); ?>
						</button>
						<span class="invcaf-help-text"><?php esc_html_e( 'Reset all plugin settings back to default values.', 'invenza-captcha-for-all-forms' ); ?></span>
					</div>
				</div>
			</div>
			
			<div class="invcaf-sticky-save"><button type="submit" name="submit" class="invcaf-btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> <?php esc_html_e( 'Save Changes', 'invenza-captcha-for-all-forms' ); ?></button></div>
		</form>
	<?php elseif ( 'dashboard' === $invcaf_active_tab ) : ?>
		<!-- Supported Form Plugins Status Panel -->
		<div class="invcaf-card invcaf-dashboard-status-card">
			<div class="invcaf-card-header"><h3 class="invcaf-card-title"><?php esc_html_e( 'Supported Form Plugins Status', 'invenza-captcha-for-all-forms' ); ?></h3></div>
			<p class="invcaf-dashboard-desc">
				<?php esc_html_e( 'This plugin works with any of the form builders listed below. None of them are required — activate whichever you use. CAPTCHA protection is automatically enabled for each active plugin.', 'invenza-captcha-for-all-forms' ); ?>
			</p>
			<?php if ( ! $invcaf_any_active ) : ?>
				<div class="notice notice-warning inline invcaf-m-0-mb-15">
					<p><?php esc_html_e( 'No supported form plugin is currently active. Install and activate at least one to start using CAPTCHA protection.', 'invenza-captcha-for-all-forms' ); ?></p>
				</div>
			<?php endif; ?>
			<table class="invcaf-table">
				<thead>
					<tr>
						<th><strong><?php esc_html_e( 'Form Plugin', 'invenza-captcha-for-all-forms' ); ?></strong></th>
						<th><strong><?php esc_html_e( 'Status', 'invenza-captcha-for-all-forms' ); ?></strong></th>
						<th><strong><?php esc_html_e( 'Required?', 'invenza-captcha-for-all-forms' ); ?></strong></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $invcaf_supported_plugins_status as $invcaf_sp ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $invcaf_sp['name'] ); ?></strong></td>
						<td>
							<?php if ( $invcaf_sp['active'] ) : ?>
								<span class="invcaf-status-active">&#10004; <?php esc_html_e( 'Active', 'invenza-captcha-for-all-forms' ); ?></span>
							<?php else : ?>
								<span class="invcaf-status-inactive">&#8212; <?php esc_html_e( 'Not Installed / Inactive', 'invenza-captcha-for-all-forms' ); ?></span>
							<?php endif; ?>
						</td>
						<td><span class="invcaf-status-optional"><?php esc_html_e( 'Optional', 'invenza-captcha-for-all-forms' ); ?></span></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- Verification Stats Panel -->
		<div class="invcaf-card invcaf-stats-panel">
			<div class="invcaf-card-header"><h3 class="invcaf-card-title"><?php esc_html_e( 'Verification Statistics', 'invenza-captcha-for-all-forms' ); ?></h3></div>

			<?php if ( isset( $invcaf_settings['logging_enabled'] ) && $invcaf_settings['logging_enabled'] !== '1' ) : ?>
				<div class="invcaf-notice-error">
					<strong>Notice:</strong> Security Event Logging is currently disabled. To view live verification statistics, please scroll down and enable <strong>"Enable Security Event Logging"</strong> in the Configuration settings.
				</div>
			<?php endif; ?>
			<div class="invcaf-stats-grid">
				<div class="invcaf-stat-box">
					<span class="invcaf-stat-value invcaf-stat-blue"><?php echo esc_html( $invcaf_total_attempts ); ?></span>
					<div class="invcaf-stat-label"><?php esc_html_e( 'Total Submissions', 'invenza-captcha-for-all-forms' ); ?></div>
				</div>
				<div class="invcaf-stat-box">
					<span class="invcaf-stat-value invcaf-stat-green"><?php echo esc_html( $invcaf_success_attempts ); ?></span>
					<div class="invcaf-stat-label"><?php esc_html_e( 'Passed CAPTCHAs', 'invenza-captcha-for-all-forms' ); ?></div>
				</div>
				<div class="invcaf-stat-box">
					<span class="invcaf-stat-value invcaf-stat-red"><?php echo esc_html( $invcaf_fail_attempts ); ?></span>
					<div class="invcaf-stat-label"><?php esc_html_e( 'Blocked Attacks', 'invenza-captcha-for-all-forms' ); ?></div>
				</div>
				<div class="invcaf-stat-box">
					<span class="invcaf-stat-value invcaf-stat-yellow"><?php echo esc_html( $invcaf_success_rate ); ?>%</span>
					<div class="invcaf-stat-label"><?php esc_html_e( 'Legitimate Ratio', 'invenza-captcha-for-all-forms' ); ?></div>
				</div>
			</div>
			
			<?php if ( $invcaf_total_attempts > 0 ) : ?>
				<div class="invcaf-stats-bar-container">
					<div class="invcaf-stats-bar-passed" style="width:<?php echo esc_attr( $invcaf_success_rate ); ?>%;" title="<?php echo esc_attr( $invcaf_success_rate ); ?>% Passed"></div>
					<div class="invcaf-stats-bar-blocked" style="width:<?php echo esc_attr( 100 - $invcaf_success_rate ); ?>%;" title="<?php echo esc_attr( 100 - $invcaf_success_rate ); ?>% Blocked"></div>
				</div>
				<div class="invcaf-stats-legend">
					<span><?php esc_html_e( '&#10003; Passed (Green)', 'invenza-captcha-for-all-forms' ); ?></span>
					<span><?php esc_html_e( '&#10008; Blocked / Bot Attacks (Red)', 'invenza-captcha-for-all-forms' ); ?></span>
				</div>
			<?php endif; ?>
		</div>

		<div class="invcaf-card invcaf-maxw-800-mt-20">
			<h2><?php esc_html_e( 'System Compatibility Checklist', 'invenza-captcha-for-all-forms' ); ?></h2>
			<table class="invcaf-table">
				<thead>
					<tr>
						<th><strong><?php esc_html_e( 'Requirement', 'invenza-captcha-for-all-forms' ); ?></strong></th>
						<th><strong><?php esc_html_e( 'Status', 'invenza-captcha-for-all-forms' ); ?></strong></th>
						<th><strong><?php esc_html_e( 'Details', 'invenza-captcha-for-all-forms' ); ?></strong></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'PHP Version', 'invenza-captcha-for-all-forms' ); ?></strong></td>
						<td>
							<?php if ( version_compare( PHP_VERSION, '7.2.0', '>=' ) ) : ?>
								<span class="invcaf-text-green-bold">&#10004; <?php echo esc_html( PHP_VERSION ); ?></span>
							<?php else : ?>
								<span class="invcaf-text-red-bold">&#10008; <?php echo esc_html( PHP_VERSION ); ?> (<?php esc_html_e( 'Upgrade recommended', 'invenza-captcha-for-all-forms' ); ?>)</span>
							<?php endif; ?>
						</td>
						<td><?php esc_html_e( 'Plugin requires PHP 7.2.0 or higher for cryptographically secure random values and type assertions.', 'invenza-captcha-for-all-forms' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'GD Extension', 'invenza-captcha-for-all-forms' ); ?></strong></td>
						<td>
							<?php if ( $invcaf_gd_enabled ) : ?>
								<span class="invcaf-text-green-bold">&#10004; <?php esc_html_e( 'Enabled', 'invenza-captcha-for-all-forms' ); ?></span>
							<?php else : ?>
								<span class="invcaf-text-red-bold">&#10008; <?php esc_html_e( 'Disabled', 'invenza-captcha-for-all-forms' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $invcaf_gd_enabled ) : ?>
								<?php esc_html_e( 'Required for custom CAPTCHA image rendering.', 'invenza-captcha-for-all-forms' ); ?>
							<?php else : ?>
								<strong><?php esc_html_e( 'Action Required: Enable GD extension in php.ini (remove semicolon from ";extension=gd") and restart Apache.', 'invenza-captcha-for-all-forms' ); ?></strong>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'GD FreeType Support', 'invenza-captcha-for-all-forms' ); ?></strong></td>
						<td>
							<?php if ( $invcaf_freetype_enabled ) : ?>
								<span class="invcaf-text-green-bold">&#10004; <?php esc_html_e( 'Enabled', 'invenza-captcha-for-all-forms' ); ?></span>
							<?php else : ?>
								<span class="invcaf-text-orange-bold">&#9888; <?php esc_html_e( 'Disabled', 'invenza-captcha-for-all-forms' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							if ( $invcaf_freetype_enabled ) {
								esc_html_e( 'FreeType detected. Advanced character rotation/spacing enabled.', 'invenza-captcha-for-all-forms' );
							} else {
								esc_html_e( 'FreeType missing. Falling back to built-in basic font characters (no rotation).', 'invenza-captcha-for-all-forms' );
							}
							?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Active CAPTCHA Forms', 'invenza-captcha-for-all-forms' ); ?></strong></td>
						<td>
							<span class="invcaf-text-bold">
								<?php echo Settings::get( 'fc_all_forms' ) === '1' ? esc_html__( 'All Forms', 'invenza-captcha-for-all-forms' ) : esc_html__( 'Selected Forms Only', 'invenza-captcha-for-all-forms' ); ?>
							</span>
						</td>
						<td>
							<?php
							if ( Settings::get( 'fc_all_forms' ) !== '1' ) {
								$invcaf_forms = Settings::get( 'fc_forms', '' );
								echo esc_html( ! empty( $invcaf_forms ) ? $invcaf_forms : __( 'None configured', 'invenza-captcha-for-all-forms' ) );
							} else {
								esc_html_e( 'All FormCraft forms will automatically require CAPTCHA.', 'invenza-captcha-for-all-forms' );
							}
							?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Last CAPTCHA Verification', 'invenza-captcha-for-all-forms' ); ?></strong></td>
						<td>
							<?php if ( $invcaf_last_verify['time'] > 0 ) : ?>
								<?php if ( $invcaf_last_verify['success'] ) : ?>
									<span class="invcaf-text-green-bold">&#10004; <?php esc_html_e( 'Success', 'invenza-captcha-for-all-forms' ); ?></span>
								<?php else : ?>
									<span class="invcaf-text-red-bold">&#10008; <?php esc_html_e( 'Failed', 'invenza-captcha-for-all-forms' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="invcaf-text-gray"><?php esc_html_e( 'No attempts logged', 'invenza-captcha-for-all-forms' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							if ( $invcaf_last_verify['time'] > 0 ) {
								$invcaf_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $invcaf_last_verify['time'] );
								/* translators: %s: Date and time of the attempt */
								$invcaf_desc = sprintf( __( 'Attempted on %s.', 'invenza-captcha-for-all-forms' ), $invcaf_date );
								if ( ! $invcaf_last_verify['success'] && ! empty( $invcaf_last_verify['reason'] ) ) {
									/* translators: %s: Reason for failure */
									$invcaf_desc .= ' ' . sprintf( __( 'Reason: %s.', 'invenza-captcha-for-all-forms' ), $invcaf_last_verify['reason'] );
								}
								echo esc_html( $invcaf_desc );
							} else {
								esc_html_e( 'No form submission attempts have been captured since activation.', 'invenza-captcha-for-all-forms' );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Support & Help Options Card -->
			<div class="invcaf-card invcaf-mt-20" style="border-left: 4px solid var(--invcaf-primary);">
				<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
					<div>
						<h3 style="margin:0 0 6px; font-size:16px;"><?php esc_html_e( 'Need Help or Have Questions?', 'invenza-captcha-for-all-forms' ); ?></h3>
						<p class="description" style="margin:0;">
							<?php esc_html_e( 'Get help and report questions on the WordPress.org Community Support Forum.', 'invenza-captcha-for-all-forms' ); ?>
						</p>
					</div>
					<div>
						<a href="https://wordpress.org/support/plugin/invenza-captcha-for-all-forms/" target="_blank" rel="noopener noreferrer" class="button button-secondary">
							<?php esc_html_e( '🌐 Visit WordPress.org Forum Support', 'invenza-captcha-for-all-forms' ); ?> ↗
						</a>
					</div>
				</div>
			</div>
		</div>
	<?php elseif ( 'logs' === $invcaf_active_tab ) : ?>
		<div class="invcaf-mt-20">
			<h2><?php esc_html_e( 'CAPTCHA Verification Attempt Logs', 'invenza-captcha-for-all-forms' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Showing the last 100 attempts. IP addresses and User Agents are irreversibly hashed before storage for GDPR compliance.', 'invenza-captcha-for-all-forms' ); ?></p>
			
			<table class="invcaf-table">
				<thead>
					<tr>
						<th class="invcaf-w-50"><strong>ID</strong></th>
						<th class="invcaf-w-120"><strong>Event Type</strong></th>
						<th class="invcaf-w-80"><strong>Form ID</strong></th>
						<th><strong>Session Hash</strong></th>
						<th><strong>Salted IP Hash</strong></th>
						<th class="invcaf-w-180"><strong>Timestamp</strong></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $invcaf_logs ) ) : ?>
						<?php foreach ( $invcaf_logs as $invcaf_log ) : ?>
							<tr>
								<td><?php echo esc_html( $invcaf_log->id ); ?></td>
								<td>
									<?php if ( 'passed' === $invcaf_log->event_type ) : ?>
										<span class="invcaf-text-green-bold">&#10004; <?php esc_html_e( 'Passed', 'invenza-captcha-for-all-forms' ); ?></span>
									<?php elseif ( 'failed' === $invcaf_log->event_type ) : ?>
										<span class="invcaf-text-red-bold">&#10008; <?php esc_html_e( 'Failed', 'invenza-captcha-for-all-forms' ); ?></span>
									<?php elseif ( 'blocked' === $invcaf_log->event_type ) : ?>
										<span class="invcaf-text-darkred-bold">&#9940; <?php esc_html_e( 'Blocked', 'invenza-captcha-for-all-forms' ); ?></span>
									<?php else : ?>
										<span class="invcaf-text-gray"><?php echo esc_html( ucfirst( $invcaf_log->event_type ) ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $invcaf_log->form_id ); ?></td>
								<td><code><?php echo esc_html( $invcaf_log->session_hash ); ?></code></td>
								<td><code><?php echo esc_html( $invcaf_log->ip_hash ); ?></code></td>
								<td><?php echo esc_html( $invcaf_log->created_at ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="6" class="invcaf-table-empty">
								<?php esc_html_e( 'No verification events logged. Make sure Logging is enabled in the configuration.', 'invenza-captcha-for-all-forms' ); ?>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		
		<?php if ( $invcaf_total_attempts > 0 ) : ?>
			<?php
			$invcaf_total_pages = ceil( $invcaf_total_attempts / $invcaf_per_page );
			if ( $invcaf_total_pages > 1 ) :
				?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php /* translators: %s: number of items */ ?>
						<span class="displaying-num"><?php printf( esc_html__( '%s items', 'invenza-captcha-for-all-forms' ), esc_html( number_format_i18n( $invcaf_total_attempts ) ) ); ?></span>
						<span class="pagination-links">
							<?php if ( $invcaf_paged > 1 ) : ?>
								<a class="prev-page button" href="?page=invenza-captcha-for-all-forms&tab=logs&paged=<?php echo esc_attr( $invcaf_paged - 1 ); ?>">&lsaquo; <?php esc_html_e( 'Previous', 'invenza-captcha-for-all-forms' ); ?></a>
							<?php endif; ?>
							<span class="paging-input">
								<?php
								/* translators: %1$s: current page, %2$s: total pages */
								printf( esc_html__( '%1$s of %2$s', 'invenza-captcha-for-all-forms' ), esc_html( $invcaf_paged ), esc_html( $invcaf_total_pages ) );
								?>
							</span>
							<?php if ( $invcaf_paged < $invcaf_total_pages ) : ?>
								<a class="next-page button" href="?page=invenza-captcha-for-all-forms&tab=logs&paged=<?php echo esc_attr( $invcaf_paged + 1 ); ?>"><?php esc_html_e( 'Next', 'invenza-captcha-for-all-forms' ); ?> &rsaquo;</a>
							<?php endif; ?>
						</span>
			<?php endif; ?>
		<?php endif; ?>

	<?php elseif ( 'system' === $invcaf_active_tab ) : ?>
		<!-- System Info & Health Diagnostics Panel -->
		<div class="invcaf-card invcaf-mt-20">
			<div class="invcaf-card-header" style="display:flex; justify-content:space-between; align-items:center;">
				<h3 class="invcaf-card-title"><?php esc_html_e( 'System Information & Health Diagnostics', 'invenza-captcha-for-all-forms' ); ?></h3>
				<button type="button" id="invcaf_btn_copy_sysinfo" class="button button-secondary">
					📋 <?php esc_html_e( 'Copy System Info to Clipboard', 'invenza-captcha-for-all-forms' ); ?>
				</button>
			</div>

			<div class="invcaf-health-checks" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin: 15px 0 20px;">
				<div class="invcaf-stat-box">
					<strong style="display:block; font-size:13px; color:#64748b;"><?php esc_html_e( 'GD Extension', 'invenza-captcha-for-all-forms' ); ?></strong>
					<span class="<?php echo $invcaf_gd_enabled ? 'invcaf-stat-green' : 'invcaf-stat-red'; ?>" style="font-size:18px; font-weight:bold;">
						<?php echo $invcaf_gd_enabled ? '✅ PASS' : '❌ FAIL'; ?>
					</span>
				</div>
				<div class="invcaf-stat-box">
					<strong style="display:block; font-size:13px; color:#64748b;"><?php esc_html_e( 'FreeType Support', 'invenza-captcha-for-all-forms' ); ?></strong>
					<span class="<?php echo $invcaf_freetype_enabled ? 'invcaf-stat-green' : 'invcaf-stat-yellow'; ?>" style="font-size:18px; font-weight:bold;">
						<?php echo $invcaf_freetype_enabled ? '✅ PASS' : '⚠️ WARNING'; ?>
					</span>
				</div>
				<div class="invcaf-stat-box">
					<strong style="display:block; font-size:13px; color:#64748b;"><?php esc_html_e( 'PHP Version', 'invenza-captcha-for-all-forms' ); ?></strong>
					<span class="invcaf-stat-blue" style="font-size:18px; font-weight:bold;"><?php echo esc_html( PHP_VERSION ); ?></span>
				</div>
				<div class="invcaf-stat-box">
					<strong style="display:block; font-size:13px; color:#64748b;"><?php esc_html_e( 'Memory Limit', 'invenza-captcha-for-all-forms' ); ?></strong>
					<span class="invcaf-stat-blue" style="font-size:18px; font-weight:bold;"><?php echo esc_html( ini_get( 'memory_limit' ) ); ?></span>
				</div>
			</div>

			<textarea id="invcaf_sysinfo_text" class="large-text code" rows="14" readonly style="font-size:12px; background:#f8fafc; color:#334155; font-family:monospace;">
### Invcaf CAPTCHA System Information ###
Plugin Version:      <?php echo esc_html( INVCAF_VERSION ); ?> 
WordPress Version:   <?php echo esc_html( get_bloginfo( 'version' ) ); ?> 
PHP Version:         <?php echo esc_html( PHP_VERSION ); ?> 
Server Software:     <?php echo esc_html( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'N/A' ); ?> 
GD Library:          <?php echo $invcaf_gd_enabled ? 'Enabled' : 'Missing'; ?> 
FreeType Support:    <?php echo $invcaf_freetype_enabled ? 'Enabled' : 'Missing'; ?> 
Memory Limit:        <?php echo esc_html( ini_get( 'memory_limit' ) ); ?> 
Upload Max Size:     <?php echo esc_html( ini_get( 'upload_max_filesize' ) ); ?> 
Active Theme:        
		<?php
		$invcaf_theme_info = wp_get_theme();
		echo esc_html( $invcaf_theme_info->get( 'Name' ) . ' v' . $invcaf_theme_info->get( 'Version' ) );
		?>
Active Plugins:
		<?php
		$invcaf_active_plugins_list = get_option( 'active_plugins', array() );
		foreach ( $invcaf_active_plugins_list as $invcaf_plugin_file ) {
			$invcaf_plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $invcaf_plugin_file, false, false );
			if ( ! empty( $invcaf_plugin_data['Name'] ) ) {
				echo ' - ' . esc_html( $invcaf_plugin_data['Name'] . ' v' . $invcaf_plugin_data['Version'] ) . "\n";
			}
		}
		?>
</textarea>
		</div>

	<?php elseif ( 'help' === $invcaf_active_tab ) : ?>
		<!-- Help & Developer Documentation Panel -->
		<div class="invcaf-card invcaf-mt-20">
			<h2><?php esc_html_e( 'Help & Developer Documentation', 'invenza-captcha-for-all-forms' ); ?></h2>
			
			<div class="invcaf-docs-section invcaf-mt-15">
				<h3>📖 <?php esc_html_e( 'Quick Start Guide', 'invenza-captcha-for-all-forms' ); ?></h3>
				<p><?php esc_html_e( 'Invcaf CAPTCHA protects forms against automated spam bots using locally generated visual verification codes.', 'invenza-captcha-for-all-forms' ); ?></p>
				<ul>
					<li><strong>FormCraft:</strong> CAPTCHA is auto-injected based on your Configuration settings.</li>
					<li><strong>Contact Form 7:</strong> Add the <code>[invcaf_captcha]</code> shortcode tag into your form template.</li>
					<li><strong>WPForms / Gravity Forms / Fluent Forms:</strong> Select the <strong>Invcaf CAPTCHA</strong> field from the builder picker.</li>
					<li><strong>Forminator:</strong> Embed <code>[invcaf_captcha id=0]</code> inside an HTML field.</li>
				</ul>
			</div>

			<div class="invcaf-docs-section invcaf-mt-20">
				<h3>👨‍💻 <?php esc_html_e( 'Developer Hooks & Filters', 'invenza-captcha-for-all-forms' ); ?></h3>
				<table class="invcaf-table">
					<thead>
						<tr>
							<th>Hook Name</th>
							<th>Type</th>
							<th>Description</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>invcaf_captcha_length</code></td>
							<td>Filter</td>
							<td>Modify the required character code length dynamically.</td>
						</tr>
						<tr>
							<td><code>invcaf_risk_score</code></td>
							<td>Filter</td>
							<td>Modify calculated bot risk assessment score (0 - 100).</td>
						</tr>
						<tr>
							<td><code>invcaf_generate_challenge</code></td>
							<td>Filter</td>
							<td>Override challenge display text and answer code from custom extensions.</td>
						</tr>
						<tr>
							<td><code>invcaf_render_image</code></td>
							<td>Filter</td>
							<td>Filter GD canvas image resource to apply post-processing filters.</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	<?php elseif ( 'pro' === $invcaf_active_tab ) : ?>
		<?php
		$invcaf_lic_status = get_option( 'invcaf_license_status', array() );
		$invcaf_is_active  = is_array( $invcaf_lic_status ) && isset( $invcaf_lic_status['status'] ) && 'active' === $invcaf_lic_status['status'];
		$invcaf_lic_key    = Settings::get( 'license_key', '' );
		?>
		<!-- Upgrade to Pro & License Verification Panel -->
		<div class="invcaf-card invcaf-mt-20" style="border-top: 4px solid #8b5cf6;">
			<div class="invcaf-card-header">
				<h2 class="invcaf-card-title" style="font-size:22px; color:#4c1d95;">
					⚡ <?php esc_html_e( 'Invenza CAPTCHA Pro Edition', 'invenza-captcha-for-all-forms' ); ?>
				</h2>
			</div>
			
			<p style="font-size:15px; color:#475569; line-height:1.6;">
				<?php esc_html_e( 'Unlock advanced security features, AI bot protection analytics, custom audio CAPTCHAs, and premium 24/7 support with Invenza CAPTCHA Pro.', 'invenza-captcha-for-all-forms' ); ?>
			</p>

			<!-- License Key Input Box -->
			<div class="invcaf-card" style="background:#f8fafc; border:1px solid #e2e8f0; margin:20px 0; padding:20px;">
				<h3 style="margin-top:0; font-size:16px; color:#1e293b;"><?php esc_html_e( 'License Key Activation', 'invenza-captcha-for-all-forms' ); ?></h3>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Enter your license key purchased from', 'invenza-captcha-for-all-forms' ); ?>
					<a href="https://license.isweb.in/" target="_blank" rel="noopener noreferrer" style="font-weight:600; color:#6366f1;">license.isweb.in ↗</a>
					<?php esc_html_e( 'to activate Pro features and automated updates.', 'invenza-captcha-for-all-forms' ); ?>
				</p>

				<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
					<input type="text" id="invcaf_license_key_input" value="<?php echo esc_attr( $invcaf_lic_key ); ?>" placeholder="XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX" class="regular-text code" style="padding:8px 12px; font-size:14px; min-width:320px;" />
					<button type="button" id="invcaf_btn_verify_license" class="button button-primary" style="background:#6366f1; border-color:#4f46e5; padding:4px 16px;">
						🔑 <?php esc_html_e( 'Activate License', 'invenza-captcha-for-all-forms' ); ?>
					</button>
				</div>
				<div id="invcaf_verify_license_result" style="margin-top:10px; font-size:14px;">
					<?php if ( $invcaf_is_active ) : ?>
						<span style="color:#10b981; font-weight:600;">
							✅ <?php esc_html_e( 'License is Active & Verified!', 'invenza-captcha-for-all-forms' ); ?>
							(<?php esc_html_e( 'Expires:', 'invenza-captcha-for-all-forms' ); ?> <?php echo esc_html( $invcaf_lic_status['expires'] ?? 'lifetime' ); ?>)
						</span>
					<?php endif; ?>
				</div>
			</div>

			<!-- Pro Feature Grid -->
			<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:20px; margin-top:25px;">
				<div style="padding:16px; border:1px solid #e2e8f0; border-radius:8px; background:#fff;">
					<h4 style="margin:0 0 8px; color:#4f46e5; font-size:16px;">🔊 Audio CAPTCHA</h4>
					<p style="margin:0; font-size:13px; color:#64748b;"><?php esc_html_e( 'Accessible audio challenges for visually impaired users.', 'invenza-captcha-for-all-forms' ); ?></p>
				</div>
				<div style="padding:16px; border:1px solid #e2e8f0; border-radius:8px; background:#fff;">
					<h4 style="margin:0 0 8px; color:#4f46e5; font-size:16px;">🤖 Math & Logical Challenges</h4>
					<p style="margin:0; font-size:13px; color:#64748b;"><?php esc_html_e( 'Smart equation & logic puzzles that confuse headless automated bots.', 'invenza-captcha-for-all-forms' ); ?></p>
				</div>
				<div style="padding:16px; border:1px solid #e2e8f0; border-radius:8px; background:#fff;">
					<h4 style="margin:0 0 8px; color:#4f46e5; font-size:16px;">📊 Real-Time Threat Intelligence</h4>
					<p style="margin:0; font-size:13px; color:#64748b;"><?php esc_html_e( 'Deep IP reputation scoring and automatic country-level geolocation blocking.', 'invenza-captcha-for-all-forms' ); ?></p>
				</div>
				<div style="padding:16px; border:1px solid #e2e8f0; border-radius:8px; background:#fff;">
					<h4 style="margin:0 0 8px; color:#4f46e5; font-size:16px;">💬 Priority 24/7 Support</h4>
					<p style="margin:0; font-size:13px; color:#64748b;"><?php esc_html_e( 'Direct access to core security developers for custom integrations.', 'invenza-captcha-for-all-forms' ); ?></p>
				</div>
			</div>

			<div style="margin-top:30px; text-align:center;">
				<a href="https://license.isweb.in/" target="_blank" rel="noopener noreferrer" class="button button-primary button-hero" style="background:linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%); border:none; padding:10px 30px; font-size:16px; font-weight:600; text-shadow:none; box-shadow: 0 4px 12px rgba(99,102,241,0.3);">
					🛒 <?php esc_html_e( 'Get Invenza CAPTCHA Pro License', 'invenza-captcha-for-all-forms' ); ?> ↗
				</a>
			</div>
		</div>
	<?php endif; ?>
		</div>
	</div>
</div>





