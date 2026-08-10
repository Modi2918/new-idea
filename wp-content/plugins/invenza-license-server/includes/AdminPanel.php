<?php
namespace InvenzaLicenseServer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles SaaS Admin UI views and actions for License management.
 */
class AdminPanel {

	/**
	 * Register Admin Menu page.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'CAPTCHA License Server', 'invenza-license-server' ),
			__( 'CAPTCHA License Server', 'invenza-license-server' ),
			'manage_options',
			'invenza-license-server',
			array( __CLASS__, 'render_panel' ),
			'dashicons-admin-network',
			85
		);
	}

	/**
	 * Render administrative control panel.
	 */
	public static function render_panel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'invenza-license-server' ) );
		}

		global $wpdb;

		// Actions & Form Handler.
		$msg         = '';
		$msg_success = true;

		// Handle manual key generation.
		if ( isset( $_POST['INVENZA_SERVER_generate'] ) ) {
			check_admin_referer( 'INVENZA_SERVER_generate_nonce', 'INVENZA_SERVER_generate_nonce_field' );

			$email       = sanitize_email( $_POST['client_email'] );
			$name        = sanitize_text_field( $_POST['client_name'] );
			$type        = sanitize_key( $_POST['license_type'] );
			$plan        = sanitize_key( $_POST['plan_type'] );
			$max_sites   = absint( $_POST['max_activations'] );
			$expires     = sanitize_text_field( $_POST['expires_at'] );
			$product     = 'formcraft-captcha-pro';

			if ( empty( $email ) ) {
				$msg         = __( 'Customer email is required for manual generation.', 'invenza-license-server' );
				$msg_success = false;
			} else {
				$display_name = ! empty( $name ) ? $name : 'Valued Customer';
				$customer_id  = Database::create_customer_if_not_exists( $email, $display_name );
				$key          = LicenseGenerator::generate();
				$result       = Database::insert_license( $customer_id, $key, $product, $email, $max_sites, $expires, $plan );

				if ( $result ) {
					$expires_desc = empty( $expires ) ? 'Never' : date( 'Y-m-d', strtotime( $expires ) );
					$mail_sent    = EmailService::send_license_email( $email, $display_name, $key, $plan, $max_sites, $expires_desc );

					if ( $mail_sent ) {
						$msg = sprintf( __( 'Manual license key successfully created and emailed to <strong>%s</strong> and Admin: <code>%s</code>', 'invenza-license-server' ), esc_html( $email ), esc_html( $key ) );
					} else {
						$msg = sprintf( __( 'Manual license key created: <code>%s</code> (Note: Email delivery failed. Check your server SMTP settings).', 'invenza-license-server' ), esc_html( $key ) );
					}
					$msg_success = true;
				} else {
					$msg         = __( 'Error inserting license record into database.', 'invenza-license-server' );
					$msg_success = false;
				}
			}
		}

		// Handle coupon creation saving.
		if ( isset( $_POST['INVENZA_SERVER_create_coupon'] ) ) {
			check_admin_referer( 'INVENZA_SERVER_coupon_nonce', 'INVENZA_SERVER_coupon_nonce_field' );

			// Force uppercase & strip non-alphanumeric (except dash/underscore).
			$coupon_code    = strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) ) ) );
			$discount_val   = absint( $_POST['discount_percent'] ?? 0 );
			$expires_raw    = sanitize_text_field( wp_unslash( $_POST['coupon_expires_at'] ?? '' ) );
			$per_user_limit = absint( $_POST['per_user_limit'] ?? 0 );

			// Validate expiry date.
			$expires_at = '';
			if ( ! empty( $expires_raw ) ) {
				$ts = strtotime( $expires_raw );
				if ( $ts !== false ) {
					$expires_at = date( 'Y-m-d', $ts );
				}
			}

			if ( ! empty( $coupon_code ) && $discount_val > 0 && $discount_val <= 100 ) {
				$existing_coupons = get_option( 'INVENZA_SERVER_coupons', array() );
				if ( ! is_array( $existing_coupons ) ) {
					$existing_coupons = array();
				}

				// Preserve existing usage data if code already exists.
				$existing_usage = array();
				if ( isset( $existing_coupons[ $coupon_code ] ) && is_array( $existing_coupons[ $coupon_code ] ) ) {
					$existing_usage = $existing_coupons[ $coupon_code ]['usage'] ?? array();
				}

				$existing_coupons[ $coupon_code ] = array(
					'discount'       => $discount_val,
					'expires_at'     => $expires_at,
					'per_user_limit' => $per_user_limit,
					'usage'          => $existing_usage,
				);
				update_option( 'INVENZA_SERVER_coupons', $existing_coupons );

				$msg         = sprintf( __( 'Coupon code <code>%s</code> (%d%% OFF) saved successfully.', 'invenza-license-server' ), esc_html( $coupon_code ), $discount_val );
				$msg_success = true;
			} else {
				$msg         = __( 'Invalid coupon code or discount percentage.', 'invenza-license-server' );
				$msg_success = false;
			}
		}

		// Handle coupon edit saving.
		if ( isset( $_POST['INVENZA_SERVER_edit_coupon'] ) ) {
			check_admin_referer( 'INVENZA_SERVER_coupon_nonce', 'INVENZA_SERVER_coupon_nonce_field' );

			$edit_code      = strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', sanitize_text_field( wp_unslash( $_POST['edit_coupon_code'] ?? '' ) ) ) );
			$discount_val   = absint( $_POST['discount_percent'] ?? 0 );
			$expires_raw    = sanitize_text_field( wp_unslash( $_POST['coupon_expires_at'] ?? '' ) );
			$per_user_limit = absint( $_POST['per_user_limit'] ?? 0 );

			$expires_at = '';
			if ( ! empty( $expires_raw ) ) {
				$ts = strtotime( $expires_raw );
				if ( $ts !== false ) {
					$expires_at = date( 'Y-m-d', $ts );
				}
			}

			if ( ! empty( $edit_code ) && $discount_val > 0 && $discount_val <= 100 ) {
				$existing_coupons = get_option( 'INVENZA_SERVER_coupons', array() );
				if ( ! is_array( $existing_coupons ) ) {
					$existing_coupons = array();
				}

				// Preserve usage data.
				$existing_usage = array();
				if ( isset( $existing_coupons[ $edit_code ] ) && is_array( $existing_coupons[ $edit_code ] ) ) {
					$existing_usage = $existing_coupons[ $edit_code ]['usage'] ?? array();
				}

				$existing_coupons[ $edit_code ] = array(
					'discount'       => $discount_val,
					'expires_at'     => $expires_at,
					'per_user_limit' => $per_user_limit,
					'usage'          => $existing_usage,
				);
				update_option( 'INVENZA_SERVER_coupons', $existing_coupons );

				$msg         = sprintf( __( 'Coupon code <code>%s</code> updated successfully.', 'invenza-license-server' ), esc_html( $edit_code ) );
				$msg_success = true;
			} else {
				$msg         = __( 'Invalid data for coupon edit.', 'invenza-license-server' );
				$msg_success = false;
			}
		}

		// Handle coupon deletion.
		if ( isset( $_GET['delete_coupon'] ) ) {
			$del_code = strtoupper( sanitize_text_field( wp_unslash( $_GET['delete_coupon'] ) ) );
			check_admin_referer( 'INVENZA_SERVER_delete_coupon_' . $del_code );

			$existing_coupons = get_option( 'INVENZA_SERVER_coupons', array() );
			if ( ! is_array( $existing_coupons ) ) {
				$existing_coupons = array();
			}

			if ( isset( $existing_coupons[ $del_code ] ) ) {
				unset( $existing_coupons[ $del_code ] );
				update_option( 'INVENZA_SERVER_coupons', $existing_coupons );
				$msg         = sprintf( __( 'Coupon code <code>%s</code> deleted.', 'invenza-license-server' ), esc_html( $del_code ) );
				$msg_success = true;
			}
		}

		// Handle payment settings saving.
		if ( isset( $_POST['INVENZA_SERVER_save_settings'] ) ) {
			check_admin_referer( 'INVENZA_SERVER_settings_nonce', 'INVENZA_SERVER_settings_nonce_field' );

			$rzp_key_id         = sanitize_text_field( wp_unslash( $_POST['razorpay_key_id'] ) );
			$rzp_key_secret     = sanitize_text_field( wp_unslash( $_POST['razorpay_key_secret'] ) );

			update_option( 'INVENZA_SERVER_razorpay_key_id', $rzp_key_id );
			update_option( 'INVENZA_SERVER_razorpay_key_secret', $rzp_key_secret );

			$msg         = __( 'Payment gateway options updated successfully.', 'invenza-license-server' );
			$msg_success = true;
		}

		// Handle Pro plugin ZIP path saving.
		if ( isset( $_POST['INVENZA_SERVER_save_pro_plugin'] ) ) {
			check_admin_referer( 'INVENZA_SERVER_pro_plugin_nonce', 'INVENZA_SERVER_pro_plugin_nonce_field' );

			$zip_path = sanitize_text_field( wp_unslash( $_POST['pro_plugin_zip_path'] ?? '' ) );
			$version  = sanitize_text_field( wp_unslash( $_POST['pro_plugin_version'] ?? '1.0.0' ) );

			update_option( 'INVENZA_SERVER_pro_plugin_zip_path', $zip_path );
			update_option( 'INVENZA_SERVER_pro_plugin_version', $version );

			$msg         = __( 'Pro plugin settings saved successfully.', 'invenza-license-server' );
			$msg_success = true;
		}

		// Handle SMTP settings saving.
		if ( isset( $_POST['INVENZA_SERVER_save_smtp'] ) ) {
			check_admin_referer( 'INVENZA_SERVER_smtp_nonce', 'INVENZA_SERVER_smtp_nonce_field' );

			update_option( 'INVENZA_SERVER_smtp_enabled', isset( $_POST['smtp_enabled'] ) ? 'yes' : 'no' );
			update_option( 'INVENZA_SERVER_smtp_host', sanitize_text_field( wp_unslash( $_POST['smtp_host'] ) ) );
			update_option( 'INVENZA_SERVER_smtp_port', absint( $_POST['smtp_port'] ) );
			update_option( 'INVENZA_SERVER_smtp_encryption', sanitize_key( $_POST['smtp_encryption'] ) );
			update_option( 'INVENZA_SERVER_smtp_auth', isset( $_POST['smtp_auth'] ) ? 'yes' : 'no' );
			update_option( 'INVENZA_SERVER_smtp_username', sanitize_text_field( wp_unslash( $_POST['smtp_username'] ) ) );
			if ( ! empty( $_POST['smtp_password'] ) ) {
				update_option( 'INVENZA_SERVER_smtp_password', sanitize_text_field( wp_unslash( $_POST['smtp_password'] ) ) );
			}
			update_option( 'INVENZA_SERVER_smtp_from_email', sanitize_email( wp_unslash( $_POST['smtp_from_email'] ) ) );
			update_option( 'INVENZA_SERVER_smtp_from_name', sanitize_text_field( wp_unslash( $_POST['smtp_from_name'] ) ) );

			$msg         = __( 'SMTP email settings updated successfully.', 'invenza-license-server' );
			$msg_success = true;
		}

		// Handle table actions.
		if ( isset( $_GET['action'] ) && isset( $_GET['id'] ) ) {
			$action = sanitize_key( $_GET['action'] );
			$id     = absint( $_GET['id'] );

			check_admin_referer( 'INVENZA_SERVER_action_' . $id );

			if ( 'toggle' === $action ) {
				Database::toggle_status( $id );
				$msg         = __( 'License key status toggled.', 'invenza-license-server' );
				$msg_success = true;
			} elseif ( 'reset' === $action ) {
				// Reset all paired domains for this license.
				$table_act = $wpdb->prefix . 'fcac_license_activations';
				$wpdb->delete( $table_act, array( 'license_id' => $id ), array( '%d' ) );
				$msg         = __( 'All registered domain pairings cleared for this key.', 'invenza-license-server' );
				$msg_success = true;
			} elseif ( 'delete' === $action ) {
				Database::delete_license( $id );
				$msg         = __( 'License record permanently deleted.', 'invenza-license-server' );
				$msg_success = true;
			} elseif ( 'close_ticket' === $action ) {
				$table_t = $wpdb->prefix . 'fcac_support_tickets';
				$wpdb->update( $table_t, array( 'status' => 'closed' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
				$msg         = __( 'Support ticket marked as Closed.', 'invenza-license-server' );
				$msg_success = true;
			} elseif ( 'reopen_ticket' === $action ) {
				$table_t = $wpdb->prefix . 'fcac_support_tickets';
				$wpdb->update( $table_t, array( 'status' => 'open' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
				$msg         = __( 'Support ticket reopened.', 'invenza-license-server' );
				$msg_success = true;
			}
		}

		// Current Tab Selection.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'licenses';

		// Fetch Analytics Metrics.
		$table_lic = $wpdb->prefix . 'fcac_licenses';
		$table_act = $wpdb->prefix . 'fcac_license_activations';
		$table_cst = $wpdb->prefix . 'fcac_customers';
		$table_log = $wpdb->prefix . 'fcac_license_logs';

		$total_licenses    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_lic}" );
		$total_activations = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_act}" );
		$total_customers   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_cst}" );
		$total_logs        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_log}" );

		// Fetch Records.
		$licenses  = Database::get_all_licenses();
		$customers = Database::get_all_customers();
		$logs      = Database::get_all_logs();

		// Styling layout.
		?>

		<div class="wrap fcac-server-admin-wrap" id="INVENZA_SERVER_app_root" data-theme="light">
			<div class="fcac-server-header">
				<h1 class="fcac-server-header-title">
					<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--fcac-server-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
						<polyline points="9 12 11 14 15 10"></polyline>
					</svg>
					<?php esc_html_e( 'License Server Enterprise', 'invenza-license-server' ); ?>
				</h1>
				<div class="fcac-server-header-actions">
					<button class="fcac-server-theme-toggle" id="INVENZA_SERVER_theme_toggle" type="button" aria-label="Toggle Dark Mode" title="Toggle Dark Mode">
						<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
							<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
						</svg>
					</button>
				</div>
			</div>
			<div class="fcac-server-body">
				<div class="fcac-server-sidebar">
					<a href="?page=invenza-license-server&tab=licenses" class="fcac-server-nav-item <?php echo 'licenses' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'License Keys', 'invenza-license-server' ); ?></a>
					<a href="?page=invenza-license-server&tab=customers" class="fcac-server-nav-item <?php echo 'customers' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Customers', 'invenza-license-server' ); ?></a>
					<a href="?page=invenza-license-server&tab=coupons" class="fcac-server-nav-item <?php echo 'coupons' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Coupon Codes', 'invenza-license-server' ); ?></a>
					<a href="?page=invenza-license-server&tab=logs" class="fcac-server-nav-item <?php echo 'logs' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Audit Logs', 'invenza-license-server' ); ?></a>
					<a href="?page=invenza-license-server&tab=payment-settings" class="fcac-server-nav-item <?php echo 'payment-settings' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Payment Settings', 'invenza-license-server' ); ?></a>
					<a href="?page=invenza-license-server&tab=pro-plugin" class="fcac-server-nav-item <?php echo 'pro-plugin' === $active_tab ? 'active' : ''; ?>" style="<?php echo 'pro-plugin' === $active_tab ? '' : 'border-left: 3px solid #16a34a;'; ?>"><?php esc_html_e( '⬇ Pro Plugin ZIP', 'invenza-license-server' ); ?></a>
					<a href="?page=invenza-license-server&tab=tickets" class="fcac-server-nav-item <?php echo 'tickets' === $active_tab ? 'active' : ''; ?>" style="<?php echo 'tickets' === $active_tab ? '' : 'border-left: 3px solid #dc2626;'; ?>"><?php esc_html_e( '🎟 Priority Tickets', 'invenza-license-server' ); ?></a>
					<a href="?page=invenza-license-server&tab=smtp-settings" class="fcac-server-nav-item <?php echo 'smtp-settings' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'SMTP Email Setup', 'invenza-license-server' ); ?></a>
				</div>
				<div class="fcac-server-content">

			<!-- Metrics Dashboard Row -->
			<div class="fcac-server-metrics-row">
				<div class="fcac-server-metric-card" style="border-left-color: #3b77d1;">
					<span class="fcac-server-metric-title"><?php esc_html_e( 'Total Sales', 'invenza-license-server' ); ?></span>
					<span class="fcac-server-metric-value"><?php echo absint( $total_licenses ); ?></span>
				</div>
				<div class="fcac-server-metric-card" style="border-left-color: #12b76a;">
					<span class="fcac-server-metric-title"><?php esc_html_e( 'Active Sites', 'invenza-license-server' ); ?></span>
					<span class="fcac-server-metric-value"><?php echo absint( $total_activations ); ?></span>
				</div>
				<div class="fcac-server-metric-card" style="border-left-color: #2f80ed;">
					<span class="fcac-server-metric-title"><?php esc_html_e( 'Total Customers', 'invenza-license-server' ); ?></span>
					<span class="fcac-server-metric-value"><?php echo absint( $total_customers ); ?></span>
				</div>
				<div class="fcac-server-metric-card" style="border-left-color: #f04438;">
					<span class="fcac-server-metric-title"><?php esc_html_e( 'Audited Events', 'invenza-license-server' ); ?></span>
					<span class="fcac-server-metric-value"><?php echo absint( $total_logs ); ?></span>
				</div>
			</div>

			<?php if ( ! empty( $msg ) ) : ?>
				<div class="notice <?php echo $msg_success ? 'notice-success' : 'notice-error'; ?> is-dismissible" style="border-radius:6px; margin: 0 0 20px 0;">
					<div class="fcac-server-form-row"><?php echo wp_kses_post( $msg ); ?></div>
				</div>
			<?php endif; ?>

			

			<!-- Tab Content Areas -->
			<?php if ( 'licenses' === $active_tab ) : ?>
				<div>
					<!-- Generator Form -->
					<div class="fcac-server-card">
						<div class="fcac-server-card-header"><h2 class="fcac-server-card-title"><?php esc_html_e( 'Generate Manual Key', 'invenza-license-server' ); ?></h2></div>
						<form method="post" action="">
							<?php wp_nonce_field( 'INVENZA_SERVER_generate_nonce', 'INVENZA_SERVER_generate_nonce_field' ); ?>

							<div class="fcac-server-form-row">
								<label class="fcac-server-label"><?php esc_html_e( 'Customer Email', 'invenza-license-server' ); ?></label>
								<input type="email" name="client_email" class="fcac-server-input" class="fcac-server-input"  required placeholder="user@domain.com" />
							</div>

							<div class="fcac-server-form-row">
								<label class="fcac-server-label"><?php esc_html_e( 'Customer Name', 'invenza-license-server' ); ?></label>
								<input type="text" name="client_name" class="fcac-server-input" class="fcac-server-input"  placeholder="John Doe" />
							</div>

							<div class="fcac-server-form-row">
								<label class="fcac-server-label"><?php esc_html_e( 'License Tier', 'invenza-license-server' ); ?></label>
								<select name="license_type" class="fcac-server-select">
									<option value="standard"><?php esc_html_e( 'Standard Key', 'invenza-license-server' ); ?></option>
									<option value="enterprise" selected><?php esc_html_e( 'Enterprise Key', 'invenza-license-server' ); ?></option>
								</select>
							</div>

							<div class="fcac-server-form-row">
								<label class="fcac-server-label"><?php esc_html_e( 'Billing Plan', 'invenza-license-server' ); ?></label>
								<select name="plan_type" class="fcac-server-select">
									<option value="lifetime"><?php esc_html_e( 'Lifetime Plan', 'invenza-license-server' ); ?></option>
									<option value="yearly" selected><?php esc_html_e( 'Yearly Plan', 'invenza-license-server' ); ?></option>
									<option value="monthly"><?php esc_html_e( 'Monthly Plan', 'invenza-license-server' ); ?></option>
									<option value="5-site"><?php esc_html_e( '5-Site Plan', 'invenza-license-server' ); ?></option>
									<option value="unlimited"><?php esc_html_e( 'Unlimited Plan', 'invenza-license-server' ); ?></option>
								</select>
							</div>

							<div class="fcac-server-form-row">
								<label class="fcac-server-label"><?php esc_html_e( 'Max Activations', 'invenza-license-server' ); ?></label>
								<input type="number" name="max_activations" class="fcac-server-input" min="1" value="1"  />
							</div>

							<div class="fcac-server-form-row">
								<label class="fcac-server-label"><?php esc_html_e( 'Expiration Date', 'invenza-license-server' ); ?></label>
								<input type="date" name="expires_at" style="width:100%; height:32px;" min="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>" />
							</div>

							<div class="fcac-server-form-row" style="margin-top:20px;">
								<input type="submit" name="INVENZA_SERVER_generate" class="fcac-server-btn fcac-server-btn-primary" value="<?php esc_attr_e( 'Generate & Email Key', 'invenza-license-server' ); ?>" />
							</div>
						</form>
					</div>

					<!-- Licenses List Table -->
					<div class="fcac-server-card">
						<div class="fcac-server-card-header"><h2 class="fcac-server-card-title"><?php esc_html_e( 'Generated Key Database', 'invenza-license-server' ); ?></h2></div>
						<div class="fcac-server-table-container"><table class="fcac-server-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'License Key', 'invenza-license-server' ); ?></th>
									<th><?php esc_html_e( 'Customer Email', 'invenza-license-server' ); ?></th>
									<th><?php esc_html_e( 'Plan / Tier', 'invenza-license-server' ); ?></th>
									<th style="width:120px;"><?php esc_html_e( 'Sites Mapped', 'invenza-license-server' ); ?></th>
									<th><?php esc_html_e( 'Expires On', 'invenza-license-server' ); ?></th>
									<th><?php esc_html_e( 'Status', 'invenza-license-server' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'invenza-license-server' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( ! empty( $licenses ) ) : ?>
									<?php foreach ( $licenses as $row ) : ?>
										<?php
										$count = Database::get_activations_count( $row->id );
										?>
										<tr>
											<td><strong><code><?php echo esc_html( $row->license_key ); ?></code></strong></td>
											<td><code><?php echo esc_html( $row->client_email ); ?></code></td>
											<td>
												<span class="fcac-server-badge fcac-server-badge-neutral <?php echo 'enterprise' === $row->license_type ? 'fcac-server-badge-info' : ''; ?>">
													<?php echo esc_html( $row->plan ); ?>
												</span>
											</td>
											<td>
												<strong><?php echo absint( $count ); ?></strong> / <?php echo (int) $row->max_activations > 5000 ? 'Unlimited' : absint( $row->max_activations ); ?>
											</td>
											<td>
												<?php
												if ( ! empty( $row->expiry_date ) ) {
													echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->expiry_date ) ) );
												} else {
													esc_html_e( 'Never', 'invenza-license-server' );
												}
												?>
											</td>
											<td>
												<span class="fcac-server-badge fcac-status-<?php echo esc_attr( $row->status ); ?>">
													<?php echo esc_html( $row->status ); ?>
												</span>
											</td>
											<td>
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=invenza-license-server&action=toggle&id=' . $row->id ), 'INVENZA_SERVER_action_' . $row->id ) ); ?>" class="fcac-server-action-link" style="color:#3b77d1;">
													<?php 'active' === $row->status ? esc_html_e( 'Suspend', 'invenza-license-server' ) : esc_html_e( 'Unsuspend', 'invenza-license-server' ); ?>
												</a>
												
												<?php if ( $count > 0 ) : ?>
													<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=invenza-license-server&action=reset&id=' . $row->id ), 'INVENZA_SERVER_action_' . $row->id ) ); ?>" class="fcac-server-action-link" style="color:#d39e00;">
														<?php esc_html_e( 'Clear Domains', 'invenza-license-server' ); ?>
													</a>
												<?php endif; ?>

												<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=invenza-license-server&action=delete&id=' . $row->id ), 'INVENZA_SERVER_action_' . $row->id ) ); ?>" class="fcac-server-action-link" style="color:#f04438;" onclick="return confirm('<?php echo esc_attr( __( 'Permanently delete this license along with all site connections and logs?', 'invenza-license-server' ) ); ?>')">
													<?php esc_html_e( 'Delete', 'invenza-license-server' ); ?>
												</a>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php else : ?>
									<tr>
										<td colspan="7" style="text-align:center; padding:30px; color:gray; font-style:italic;">
											<?php esc_html_e( 'No generated license codes found.', 'invenza-license-server' ); ?>
										</td>
									</tr>
								<?php endif; ?>
							</tbody>
						</table></div>
					</div>
				</div>

			<?php elseif ( 'customers' === $active_tab ) : ?>
				<div class="fcac-server-card">
					<div class="fcac-server-card-header"><h2 class="fcac-server-card-title"><?php esc_html_e( 'SaaS Customers Directory', 'invenza-license-server' ); ?></h2></div>
					<div class="fcac-server-table-container"><table class="fcac-server-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Customer Name', 'invenza-license-server' ); ?></th>
								<th><?php esc_html_e( 'Email Address', 'invenza-license-server' ); ?></th>
								<th><?php esc_html_e( 'Licenses Generated', 'invenza-license-server' ); ?></th>
								<th><?php esc_html_e( 'Joined Date', 'invenza-license-server' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! empty( $customers ) ) : ?>
								<?php foreach ( $customers as $cust ) : ?>
									<tr>
										<td><strong><?php echo esc_html( $cust->name ); ?></strong></td>
										<td><code><?php echo esc_html( $cust->email ); ?></code></td>
										<td><span class="fcac-server-badge fcac-server-badge-neutral"><?php echo absint( $cust->total_licenses ); ?></span></td>
										<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $cust->created_at ) ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr>
									<td colspan="4" style="text-align:center; padding:30px; color:gray; font-style:italic;">
										<?php esc_html_e( 'No registered SaaS customers found.', 'invenza-license-server' ); ?>
									</td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table></div>
				</div>

			<?php elseif ( 'coupons' === $active_tab ) : ?>
			<?php
			$all_coupons = get_option( 'INVENZA_SERVER_coupons', array() );
			if ( ! is_array( $all_coupons ) ) { $all_coupons = array(); }

			// Normalize old flat-integer format to new rich format transparently.
			$needs_migration = false;
			foreach ( $all_coupons as $code => $data ) {
				if ( ! is_array( $data ) ) {
					$all_coupons[ $code ] = array(
						'discount'       => absint( $data ),
						'expires_at'     => '',
						'per_user_limit' => 0,
						'usage'          => array(),
					);
					$needs_migration = true;
				}
			}
			if ( $needs_migration ) {
				update_option( 'INVENZA_SERVER_coupons', $all_coupons );
			}

			// Which coupon are we editing (via ?edit_coupon=CODE).
			$editing_code   = isset( $_GET['edit_coupon'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['edit_coupon'] ) ) ) : '';
			$editing_data   = ( $editing_code && isset( $all_coupons[ $editing_code ] ) ) ? $all_coupons[ $editing_code ] : null;
			?>

			<!-- ================================================================
			     CREATE / EDIT COUPON FORM
			     ================================================================ -->
			<div class="fcac-server-card" style="max-width: 760px;">
				<div class="fcac-server-card-header">
					<h2 class="fcac-server-card-title">
						<?php echo $editing_data
							? sprintf( esc_html__( 'Edit Coupon: %s', 'invenza-license-server' ), '<code>' . esc_html( $editing_code ) . '</code>' )
							: esc_html__( 'Create Discount Coupon Code', 'invenza-license-server' ); ?>
					</h2>
					<?php if ( $editing_data ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=invenza-license-server&tab=coupons' ) ); ?>" class="fcac-server-btn fcac-server-btn-secondary" style="margin-left:auto;">
							<?php esc_html_e( '← Back to Create', 'invenza-license-server' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<form method="post" action="">
					<?php wp_nonce_field( 'INVENZA_SERVER_coupon_nonce', 'INVENZA_SERVER_coupon_nonce_field' ); ?>
					<?php if ( $editing_data ) : ?>
						<input type="hidden" name="edit_coupon_code" value="<?php echo esc_attr( $editing_code ); ?>" />
					<?php endif; ?>

					<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">

						<!-- Coupon Code -->
						<div class="fcac-server-form-row" style="margin:0;" <?php echo $editing_data ? 'style="grid-column:1/-1;"' : ''; ?>>
							<label class="fcac-server-label"><?php esc_html_e( 'Coupon Code', 'invenza-license-server' ); ?></label>
							<?php if ( $editing_data ) : ?>
								<input type="text" class="fcac-server-input" value="<?php echo esc_attr( $editing_code ); ?>" disabled style="background:#f1f5f9; font-family:monospace; font-weight:700; letter-spacing:1px; text-transform:uppercase;" />
								<p style="font-size:12px;color:#6b7280;margin-top:4px;"><?php esc_html_e( 'Code cannot be changed when editing. Delete and recreate to change it.', 'invenza-license-server' ); ?></p>
							<?php else : ?>
								<input type="text" name="coupon_code" id="invenza_coupon_code_input" class="fcac-server-input" placeholder="SAVE20" required
									oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9_-]/g,'')"
									style="font-family:monospace; font-weight:700; letter-spacing:1px; text-transform:uppercase;" />
								<p style="font-size:12px;color:#6b7280;margin-top:4px;"><?php esc_html_e( 'Only letters, numbers, hyphens and underscores. Auto-uppercased.', 'invenza-license-server' ); ?></p>
							<?php endif; ?>
						</div>

						<!-- Discount % -->
						<div class="fcac-server-form-row" style="margin:0;">
							<label class="fcac-server-label"><?php esc_html_e( 'Discount (%)', 'invenza-license-server' ); ?></label>
							<input type="number" name="discount_percent" class="fcac-server-input" placeholder="20" min="1" max="100" required
								value="<?php echo $editing_data ? absint( $editing_data['discount'] ) : ''; ?>" />
						</div>

						<!-- Expiry Date -->
						<div class="fcac-server-form-row" style="margin:0;">
							<label class="fcac-server-label"><?php esc_html_e( 'Valid Till (Expiry Date)', 'invenza-license-server' ); ?></label>
							<input type="date" name="coupon_expires_at" class="fcac-server-input"
								value="<?php echo $editing_data && ! empty( $editing_data['expires_at'] ) ? esc_attr( $editing_data['expires_at'] ) : ''; ?>"
								min="<?php echo date( 'Y-m-d' ); ?>" />
							<p style="font-size:12px;color:#6b7280;margin-top:4px;"><?php esc_html_e( 'Leave blank for no expiry (valid forever).', 'invenza-license-server' ); ?></p>
						</div>

						<!-- Per-User Usage Limit -->
						<div class="fcac-server-form-row" style="margin:0;">
							<label class="fcac-server-label"><?php esc_html_e( 'Per-User Usage Limit', 'invenza-license-server' ); ?></label>
							<input type="number" name="per_user_limit" class="fcac-server-input" placeholder="0" min="0"
								value="<?php echo $editing_data ? absint( $editing_data['per_user_limit'] ) : '0'; ?>" />
							<p style="font-size:12px;color:#6b7280;margin-top:4px;"><?php esc_html_e( '0 = unlimited. 1 = one-time use per email. 3 = max 3 uses per email.', 'invenza-license-server' ); ?></p>
						</div>

					</div><!-- /grid -->

					<div class="fcac-server-form-row" style="margin-top:20px;">
						<?php if ( $editing_data ) : ?>
							<input type="submit" name="INVENZA_SERVER_edit_coupon" class="fcac-server-btn fcac-server-btn-primary" value="<?php esc_attr_e( 'Save Changes', 'invenza-license-server' ); ?>" />
						<?php else : ?>
							<input type="submit" name="INVENZA_SERVER_create_coupon" class="fcac-server-btn fcac-server-btn-primary" value="<?php esc_attr_e( 'Create Coupon Code', 'invenza-license-server' ); ?>" />
						<?php endif; ?>
					</div>
				</form>
			</div>

			<!-- ================================================================
			     COUPONS LIST TABLE
			     ================================================================ -->
			<div class="fcac-server-card" style="max-width: 900px; margin-top:20px;">
				<div class="fcac-server-card-header"><h2 class="fcac-server-card-title"><?php esc_html_e( 'Promotional Coupons', 'invenza-license-server' ); ?></h2></div>
				<div class="fcac-server-table-container">
					<table class="fcac-server-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Coupon Code', 'invenza-license-server' ); ?></th>
								<th style="text-align:center;"><?php esc_html_e( 'Discount', 'invenza-license-server' ); ?></th>
								<th style="text-align:center;"><?php esc_html_e( 'Expires', 'invenza-license-server' ); ?></th>
								<th style="text-align:center;"><?php esc_html_e( 'Per-User Limit', 'invenza-license-server' ); ?></th>
								<th style="text-align:center;"><?php esc_html_e( 'Usage', 'invenza-license-server' ); ?></th>
								<th style="width:140px; text-align:center;"><?php esc_html_e( 'Actions', 'invenza-license-server' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! empty( $all_coupons ) ) : ?>
								<?php foreach ( $all_coupons as $code => $data ) :
									$discount    = absint( $data['discount'] );
									$expires_at  = $data['expires_at'] ?? '';
									$limit       = absint( $data['per_user_limit'] ?? 0 );
									$usage       = is_array( $data['usage'] ?? null ) ? $data['usage'] : array();
									$total_uses  = array_sum( $usage );

									$is_expired  = ! empty( $expires_at ) && strtotime( $expires_at ) < strtotime( 'today' );
									$days_left   = ! empty( $expires_at ) ? (int) ceil( ( strtotime( $expires_at ) - time() ) / DAY_IN_SECONDS ) : null;
								?>
								<tr style="<?php echo $is_expired ? 'opacity:0.6;' : ''; ?>">
									<td>
										<code style="font-size:14px; font-weight:700; color:<?php echo $is_expired ? '#dc2626' : '#2563eb'; ?>; letter-spacing:0.5px;">
											<?php echo esc_html( $code ); ?>
										</code>
										<?php if ( $is_expired ) : ?>
											<span class="fcac-server-badge fcac-server-badge-suspended" style="margin-left:6px;font-size:10px;">EXPIRED</span>
										<?php endif; ?>
									</td>
									<td style="text-align:center;">
										<span class="fcac-server-badge fcac-server-badge-active"><?php echo $discount; ?>% OFF</span>
									</td>
									<td style="text-align:center; font-size:13px;">
										<?php if ( empty( $expires_at ) ) : ?>
											<span style="color:#6b7280;">Never</span>
										<?php elseif ( $is_expired ) : ?>
											<span style="color:#dc2626; font-weight:600;"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $expires_at ) ) ); ?></span>
										<?php elseif ( $days_left <= 7 ) : ?>
											<span style="color:#f59e0b; font-weight:600;"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $expires_at ) ) ); ?></span>
											<span style="font-size:11px; color:#f59e0b; display:block;">(<?php echo $days_left; ?> days)</span>
										<?php else : ?>
											<span><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $expires_at ) ) ); ?></span>
										<?php endif; ?>
									</td>
									<td style="text-align:center; font-size:13px;">
										<?php if ( $limit === 0 ) : ?>
											<span style="color:#6b7280;">Unlimited</span>
										<?php else : ?>
											<span style="font-weight:600;"><?php echo $limit; ?> / email</span>
										<?php endif; ?>
									</td>
									<td style="text-align:center; font-size:13px;">
										<?php if ( $total_uses > 0 ) : ?>
											<span style="font-weight:700; color:#2563eb;"><?php echo $total_uses; ?></span>
											<span style="color:#6b7280;"> uses</span>
											<span style="font-size:11px;color:#6b7280;display:block;">(<?php echo count( $usage ); ?> unique emails)</span>
										<?php else : ?>
											<span style="color:#6b7280;">0</span>
										<?php endif; ?>
									</td>
									<td style="text-align:center;">
										<div style="display:flex; gap:6px; justify-content:center; flex-wrap:wrap;">
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=invenza-license-server&tab=coupons&edit_coupon=' . urlencode( $code ) ) ); ?>"
												class="fcac-server-btn fcac-server-btn-secondary" style="font-size:12px; padding:4px 10px;">
												<?php esc_html_e( '✏ Edit', 'invenza-license-server' ); ?>
											</a>
											<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=invenza-license-server&tab=coupons&delete_coupon=' . urlencode( $code ) ), 'INVENZA_SERVER_delete_coupon_' . $code ) ); ?>"
												class="fcac-server-btn fcac-server-btn-secondary" style="color:red; font-size:12px; padding:4px 10px;"
												onclick="return confirm('Delete coupon <?php echo esc_js( $code ); ?>?');">
												<?php esc_html_e( '🗑 Delete', 'invenza-license-server' ); ?>
											</a>
										</div>
									</td>
								</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr>
									<td colspan="6" style="text-align:center; padding:20px; color:gray;"><?php esc_html_e( 'No coupon codes created yet.', 'invenza-license-server' ); ?></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<?php elseif ( 'logs' === $active_tab ) : ?>
				<div class="fcac-server-card">
					<div class="fcac-server-card-header"><h2 class="fcac-server-card-title"><?php esc_html_e( 'Audit Transaction & Verification Event Stream (Last 200 Logs)', 'invenza-license-server' ); ?></h2></div>
					<div class="fcac-server-table-container"><table class="fcac-server-table">
						<thead>
							<tr>
								<th style="width:70px;"><?php esc_html_e( 'Log ID', 'invenza-license-server' ); ?></th>
								<th style="width:100px;"><?php esc_html_e( 'Action', 'invenza-license-server' ); ?></th>
								<th><?php esc_html_e( 'Audit Message', 'invenza-license-server' ); ?></th>
								<th style="width:180px;"><?php esc_html_e( 'Client IP (Hashed)', 'invenza-license-server' ); ?></th>
								<th style="width:160px;"><?php esc_html_e( 'Event Timestamp', 'invenza-license-server' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! empty( $logs ) ) : ?>
								<?php foreach ( $logs as $log ) : ?>
									<tr>
										<td>#<?php echo absint( $log->id ); ?></td>
										<td>
											<span class="fcac-server-badge <?php
											if ( 'fail' === $log->action || 'refund' === $log->action ) {
												echo 'fcac-server-badge-suspended';
											} elseif ( 'activate' === $log->action ) {
												echo 'fcac-server-badge-active';
											} else {
												echo 'fcac-server-badge-neutral';
											}
											?>">
												<?php echo esc_html( $log->action ); ?>
											</span>
										</td>
										<td><?php echo esc_html( $log->message ); ?></td>
										<td><code><?php echo esc_html( substr( $log->ip_hash, 0, 16 ) ); ?>...</code></td>
										<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->created_at ) ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr>
									<td colspan="5" style="text-align:center; padding:30px; color:gray; font-style:italic;">
										<?php esc_html_e( 'No audited verification events logged.', 'invenza-license-server' ); ?>
									</td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table></div>
				</div>
			<?php elseif ( 'payment-settings' === $active_tab ) : ?>
				<?php
				$rzp_key_id     = get_option( 'INVENZA_SERVER_razorpay_key_id', '' );
				$rzp_key_secret = get_option( 'INVENZA_SERVER_razorpay_key_secret', '' );
				?>
				<div class="fcac-server-card" style="max-width: 600px;">
					<div class="fcac-server-card-header"><h2 class="fcac-server-card-title"><?php esc_html_e( 'Merchant Gateway Payment Settings', 'invenza-license-server' ); ?></h2></div>
					<p class="description" style="margin-bottom: 20px;">
						<?php esc_html_e( 'Configure Razorpay API credentials to securely receive licensing checkouts via UPI, Netbanking, Wallets, and Cards.', 'invenza-license-server' ); ?>
					</div>

					<form method="post" action="">
						<?php wp_nonce_field( 'INVENZA_SERVER_settings_nonce', 'INVENZA_SERVER_settings_nonce_field' ); ?>

						<div class="fcac-server-form-row">
							<label class="fcac-server-label"><?php esc_html_e( 'Razorpay Key ID (rzp_...)', 'invenza-license-server' ); ?></label>
							<input type="text" name="razorpay_key_id" class="fcac-server-input" value="<?php echo esc_attr( $rzp_key_id ); ?>" class="fcac-server-input"  placeholder="rzp_live_..." required />
						</div>

						<div class="fcac-server-form-row">
							<label class="fcac-server-label"><?php esc_html_e( 'Razorpay Key Secret', 'invenza-license-server' ); ?></label>
							<input type="password" name="razorpay_key_secret" class="fcac-server-input" value="<?php echo esc_attr( $rzp_key_secret ); ?>" class="fcac-server-input"  required />
						</div>

						<div class="fcac-server-form-row" style="margin-top:20px;">
							<input type="submit" name="INVENZA_SERVER_save_settings" class="fcac-server-btn fcac-server-btn-primary" value="<?php esc_attr_e( 'Save Gateway Options', 'invenza-license-server' ); ?>" />
						</div>
					</form>
				</div>

				<!-- How to get API Keys Help Card -->
				<div class="fcac-server-card" style="max-width: 600px; margin-top: 20px; border-left: 4px solid #3b77d1; background: #fafafa;">
					<h3 style="margin-top:0; color:#3b77d1; font-weight:600; font-size:15px;"><?php esc_html_e( 'How to retrieve your Razorpay API keys', 'invenza-license-server' ); ?></h3>
					<ol style="margin: 10px 0 0 20px; padding: 0; font-size:13px; color:#555; line-height:1.7;">
						<li>
							<?php esc_html_e( 'Log in to your ', 'invenza-license-server' ); ?>
							<a href="https://dashboard.razorpay.com/" target="_blank" style="font-weight:600; text-decoration:underline;"><?php esc_html_e( 'Razorpay Dashboard', 'invenza-license-server' ); ?></a>
						</li>
						<li>
							<?php esc_html_e( 'Navigate to ', 'invenza-license-server' ); ?>
							<strong><?php esc_html_e( 'Account & Settings -> API Keys', 'invenza-license-server' ); ?></strong>.
						</li>
						<li>
							<?php esc_html_e( 'Click ', 'invenza-license-server' ); ?><strong><?php esc_html_e( 'Generate Key', 'invenza-license-server' ); ?></strong>
							<?php esc_html_e( ' to retrieve your ', 'invenza-license-server' ); ?><strong><?php esc_html_e( 'Key ID', 'invenza-license-server' ); ?></strong>
							<?php esc_html_e( ' and ', 'invenza-license-server' ); ?><strong><?php esc_html_e( 'Key Secret', 'invenza-license-server' ); ?></strong>.
						</li>
						<li>
							<?php esc_html_e( 'Copy those keys, paste them into the input fields above, and click save.', 'invenza-license-server' ); ?>
						</li>
					</ol>
				</div>
			<?php elseif ( 'pro-plugin' === $active_tab ) : ?>
				<?php
				$zip_path    = get_option( 'INVENZA_SERVER_pro_plugin_zip_path', '' );
				$pro_version = get_option( 'INVENZA_SERVER_pro_plugin_version', '1.0.0' );
				$zip_exists  = ! empty( $zip_path ) && file_exists( $zip_path ) && is_readable( $zip_path );
				?>
				<div class="fcac-server-card" style="max-width: 680px;">
					<div class="fcac-server-card-header"><h2 class="fcac-server-card-title"><?php esc_html_e( 'Pro Plugin Auto-Download & Update Configuration', 'invenza-license-server' ); ?></h2></div>
					<p class="description" style="margin-bottom: 20px;">
						<?php esc_html_e( 'After a successful payment or during automatic plugin update checks, customers will securely receive the ZIP file and version details configured below.', 'invenza-license-server' ); ?>
					</p>

					<?php if ( ! empty( $zip_path ) ) : ?>
					<div style="padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;
						<?php echo $zip_exists ? 'background:#f0fdf4; border: 1.5px solid #86efac;' : 'background:#fef2f2; border: 1.5px solid #fca5a5;'; ?>">
						<strong style="color: <?php echo $zip_exists ? '#15803d' : '#dc2626'; ?>">
							<?php echo $zip_exists ? '✅ ' . esc_html__( 'ZIP file found and readable:', 'invenza-license-server' ) : '❌ ' . esc_html__( 'ZIP file NOT found at path:', 'invenza-license-server' ); ?>
						</strong><br>
						<code style="font-size:12px; word-break:break-all;"><?php echo esc_html( $zip_path ); ?></code>
						<?php if ( $zip_exists ) : ?>
						<br><span style="font-size:12px; color:#6b7280; margin-top:4px; display:block;">
							<?php echo esc_html( sprintf( __( 'File size: %s', 'invenza-license-server' ), size_format( filesize( $zip_path ) ) ) ); ?>
						</span>
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<form method="post" action="">
						<?php wp_nonce_field( 'INVENZA_SERVER_pro_plugin_nonce', 'INVENZA_SERVER_pro_plugin_nonce_field' ); ?>

						<div class="fcac-server-form-row">
							<label class="fcac-server-label"><?php esc_html_e( 'Current Release Version', 'invenza-license-server' ); ?></label>
							<input type="text" name="pro_plugin_version" class="fcac-server-input" value="<?php echo esc_attr( $pro_version ); ?>" placeholder="1.0.0" style="font-family: monospace; font-size: 13px; max-width: 200px;" required />
							<p style="font-size:12px; color:#6b7280; margin-top: 6px;">
								<?php esc_html_e( 'When you upload a new version ZIP file, increment this version number (e.g. 1.0.1). Active customer sites will automatically see the update in their WP Dashboard.', 'invenza-license-server' ); ?>
							</p>
						</div>

						<div class="fcac-server-form-row" style="margin-top: 16px;">
							<label class="fcac-server-label"><?php esc_html_e( 'Absolute Server Path to Pro Plugin ZIP', 'invenza-license-server' ); ?></label>
							<input type="text" name="pro_plugin_zip_path" class="fcac-server-input" value="<?php echo esc_attr( $zip_path ); ?>" placeholder="<?php echo esc_attr( ABSPATH . 'wp-content/uploads/invenza-captcha-pro.zip' ); ?>" style="font-family: monospace; font-size: 13px;" />
							<p style="font-size:12px; color:#6b7280; margin-top: 6px;">
								<?php esc_html_e( 'Enter the full absolute filesystem path (not URL) to the ZIP file on this server.', 'invenza-license-server' ); ?>
							</p>
						</div>

						<div class="fcac-server-form-row" style="margin-top:20px;">
							<input type="submit" name="INVENZA_SERVER_save_pro_plugin" class="fcac-server-btn fcac-server-btn-primary" value="<?php esc_attr_e( 'Save Version & Path', 'invenza-license-server' ); ?>" />
						</div>
					</form>
				</div>

				<!-- How it works info card -->
				<div class="fcac-server-card" style="max-width: 680px; margin-top: 20px; border-left: 4px solid #16a34a; background: #f9fafb;">
					<h3 style="margin-top:0; color:#15803d; font-weight:600; font-size:15px;"><?php esc_html_e( 'How the Secure Auto-Download Works', 'invenza-license-server' ); ?></h3>
					<ol style="margin: 8px 0 0 18px; font-size:13px; color:#374151; line-height: 1.8;">
						<li><?php esc_html_e( 'Customer completes Razorpay payment on the landing page.', 'invenza-license-server' ); ?></li>
						<li><?php esc_html_e( 'A license key is auto-generated and emailed to the customer.', 'invenza-license-server' ); ?></li>
						<li><?php esc_html_e( 'A one-time, 15-minute download token is generated server-side.', 'invenza-license-server' ); ?></li>
						<li><?php esc_html_e( 'The browser automatically triggers the ZIP download instantly.', 'invenza-license-server' ); ?></li>
						<li><?php esc_html_e( 'The token is consumed on first use — it cannot be reused or shared.', 'invenza-license-server' ); ?></li>
					</ol>
					<p style="font-size:12px; color:#6b7280; margin-top:12px;">
						<?php esc_html_e( 'Security: Tokens expire after 15 minutes and are one-use only. The ZIP file is never exposed at a guessable URL.', 'invenza-license-server' ); ?>
					</p>
				</div>
			<?php elseif ( 'tickets' === $active_tab ) : ?>
				<?php
				$table_t = $wpdb->prefix . 'fcac_support_tickets';
				$tickets = $wpdb->get_results( "SELECT * FROM `{$table_t}` ORDER BY id DESC LIMIT 100" );
				?>
				<div class="fcac-server-card">
					<div class="fcac-server-card-header"><h2 class="fcac-server-card-title"><?php esc_html_e( 'Priority Support Tickets (Pro License Subscribers)', 'invenza-license-server' ); ?></h2></div>
					<div class="fcac-server-table-container">
						<table class="fcac-server-table">
							<thead>
								<tr>
									<th style="width:60px;"><?php esc_html_e( 'Ticket', 'invenza-license-server' ); ?></th>
									<th><?php esc_html_e( 'Customer / Domain', 'invenza-license-server' ); ?></th>
									<th><?php esc_html_e( 'Subject & Message', 'invenza-license-server' ); ?></th>
									<th><?php esc_html_e( 'Environment', 'invenza-license-server' ); ?></th>
									<th style="width:90px; text-align:center;"><?php esc_html_e( 'Status', 'invenza-license-server' ); ?></th>
									<th style="width:120px; text-align:center;"><?php esc_html_e( 'Actions', 'invenza-license-server' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( ! empty( $tickets ) ) : ?>
									<?php foreach ( $tickets as $t ) : ?>
										<tr style="<?php echo 'closed' === $t->status ? 'opacity:0.6;' : ''; ?>">
											<td><strong>#<?php echo absint( $t->id ); ?></strong></td>
											<td>
												<strong><?php echo esc_html( $t->email ); ?></strong><br>
												<code style="font-size:11px;"><?php echo esc_html( $t->domain ); ?></code><br>
												<span style="font-size:11px; color:#6b7280;">Key: <?php echo esc_html( substr( $t->license_key, 0, 16 ) ); ?>...</span>
											</td>
											<td>
												<strong style="color:#0f172a; font-size:14px;"><?php echo esc_html( $t->subject ); ?></strong>
												<div style="font-size:12px; color:#475569; margin-top:4px; max-width:400px; white-space:pre-wrap; font-family:sans-serif;"><?php echo esc_html( $t->message ); ?></div>
												<span style="font-size:11px; color:#94a3b8; margin-top:4px; display:block;"><?php echo esc_html( date_i18n( 'M j, Y g:i a', strtotime( $t->created_at ) ) ); ?></span>
											</td>
											<td>
												<code style="font-size:11px; color:#475569; white-space:pre-wrap;"><?php echo esc_html( $t->environment_info ); ?></code>
											</td>
											<td style="text-align:center;">
												<?php if ( 'open' === $t->status ) : ?>
													<span class="fcac-server-badge fcac-server-badge-active" style="background:#dc2626; color:#fff;">OPEN</span>
												<?php else : ?>
													<span class="fcac-server-badge fcac-server-badge-neutral">CLOSED</span>
												<?php endif; ?>
											</td>
											<td style="text-align:center;">
												<?php if ( 'open' === $t->status ) : ?>
													<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=invenza-license-server&tab=tickets&action=close_ticket&id=' . $t->id ), 'INVENZA_SERVER_action_' . $t->id ) ); ?>" class="fcac-server-btn fcac-server-btn-secondary" style="font-size:12px; padding:4px 8px; color:#16a34a; border-color:#86efac;"><?php esc_html_e( '✓ Close', 'invenza-license-server' ); ?></a>
												<?php else : ?>
													<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=invenza-license-server&tab=tickets&action=reopen_ticket&id=' . $t->id ), 'INVENZA_SERVER_action_' . $t->id ) ); ?>" class="fcac-server-btn fcac-server-btn-secondary" style="font-size:12px; padding:4px 8px;"><?php esc_html_e( 'Reopen', 'invenza-license-server' ); ?></a>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php else : ?>
									<tr>
										<td colspan="6" style="text-align:center; padding:30px; color:gray; font-style:italic;"><?php esc_html_e( 'No priority support tickets received yet.', 'invenza-license-server' ); ?></td>
									</tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php elseif ( 'smtp-settings' === $active_tab ) : ?>
				<?php
				$smtp_enabled    = get_option( 'INVENZA_SERVER_smtp_enabled', 'no' );
				$smtp_host       = get_option( 'INVENZA_SERVER_smtp_host', '' );
				$smtp_port       = get_option( 'INVENZA_SERVER_smtp_port', '587' );
				$smtp_encryption = get_option( 'INVENZA_SERVER_smtp_encryption', 'tls' );
				$smtp_auth       = get_option( 'INVENZA_SERVER_smtp_auth', 'yes' );
				$smtp_username   = get_option( 'INVENZA_SERVER_smtp_username', '' );
				$smtp_password   = get_option( 'INVENZA_SERVER_smtp_password', '' );
				$smtp_from_email = get_option( 'INVENZA_SERVER_smtp_from_email', '' );
				$smtp_from_name  = get_option( 'INVENZA_SERVER_smtp_from_name', 'Invenza License Server' );
				?>
				<div class="fcac-server-card" style="max-width: 650px;">
					<div class="fcac-server-card-header"><h2 class="fcac-server-card-title"><?php esc_html_e( 'SMTP Email Server Configuration', 'invenza-license-server' ); ?></h2></div>
					<p class="description" style="margin-bottom: 20px;">
						<?php esc_html_e( 'Configure your custom SMTP mail server to guarantee 100% email delivery for customer license keys and admin alerts.', 'invenza-license-server' ); ?>
					</p>

					<form method="post" action="">
						<?php wp_nonce_field( 'INVENZA_SERVER_smtp_nonce', 'INVENZA_SERVER_smtp_nonce_field' ); ?>

						<div class="fcac-server-form-row">
							<label class="fcac-server-label" style="display:flex; align-items:center; gap:8px;">
								<input type="checkbox" name="smtp_enabled" value="yes" <?php checked( 'yes', $smtp_enabled ); ?> />
								<strong><?php esc_html_e( 'Enable Custom SMTP Delivery', 'invenza-license-server' ); ?></strong>
							</label>
						</div>

						<div class="fcac-server-form-row">
							<label class="fcac-server-label"><?php esc_html_e( 'SMTP Host', 'invenza-license-server' ); ?></label>
							<input type="text" name="smtp_host" class="fcac-server-input" value="<?php echo esc_attr( $smtp_host ); ?>" placeholder="smtp.gmail.com or smtp.mailgun.org" />
						</div>

						<div class="fcac-server-form-row">
							<label class="fcac-server-label"><?php esc_html_e( 'SMTP Port', 'invenza-license-server' ); ?></label>
							<input type="number" name="smtp_port" class="fcac-server-input" value="<?php echo esc_attr( $smtp_port ); ?>" placeholder="587" />
						</div>

						<div class="fcac-server-form-row">
							<label class="fcac-server-label"><?php esc_html_e( 'Encryption Type', 'invenza-license-server' ); ?></label>
							<select name="smtp_encryption" class="fcac-server-select">
								<option value="tls" <?php selected( 'tls', $smtp_encryption ); ?>><?php esc_html_e( 'TLS (Recommended - Port 587)', 'invenza-license-server' ); ?></option>
								<option value="ssl" <?php selected( 'ssl', $smtp_encryption ); ?>><?php esc_html_e( 'SSL (Port 465)', 'invenza-license-server' ); ?></option>
								<option value="none" <?php selected( 'none', $smtp_encryption ); ?>><?php esc_html_e( 'None', 'invenza-license-server' ); ?></option>
							</select>
						</div>

						<div class="fcac-server-form-row">
							<label class="fcac-server-label" style="display:flex; align-items:center; gap:8px;">
								<input type="checkbox" name="smtp_auth" value="yes" <?php checked( 'yes', $smtp_auth ); ?> />
								<?php esc_html_e( 'SMTP Authentication Required', 'invenza-license-server' ); ?>
							</label>
						</div>

						<div class="fcac-server-form-row">
							<label class="fcac-server-label"><?php esc_html_e( 'SMTP Username / Email', 'invenza-license-server' ); ?></label>
							<input type="text" name="smtp_username" class="fcac-server-input" value="<?php echo esc_attr( $smtp_username ); ?>" placeholder="your-email@domain.com" />
						</div>

						<div class="fcac-server-form-row">
							<label class="fcac-server-label"><?php esc_html_e( 'SMTP Password / App Secret', 'invenza-license-server' ); ?></label>
							<input type="password" name="smtp_password" class="fcac-server-input" value="" placeholder="<?php echo ! empty( $smtp_password ) ? '••••••••••••' : 'Enter password'; ?>" />
						</div>

						<div class="fcac-server-form-row">
							<label class="fcac-server-label"><?php esc_html_e( 'From Email Address', 'invenza-license-server' ); ?></label>
							<input type="email" name="smtp_from_email" class="fcac-server-input" value="<?php echo esc_attr( $smtp_from_email ); ?>" placeholder="sales@isweb.in" />
						</div>

						<div class="fcac-server-form-row">
							<label class="fcac-server-label"><?php esc_html_e( 'From Sender Name', 'invenza-license-server' ); ?></label>
							<input type="text" name="smtp_from_name" class="fcac-server-input" value="<?php echo esc_attr( $smtp_from_name ); ?>" placeholder="Invenza License Server" />
						</div>

						<div class="fcac-server-form-row" style="margin-top:20px;">
							<input type="submit" name="INVENZA_SERVER_save_smtp" class="fcac-server-btn fcac-server-btn-primary" value="<?php esc_attr_e( 'Save SMTP Configuration', 'invenza-license-server' ); ?>" />
						</div>
					</form>
				</div>
			<?php endif; ?>
			</div>
		</div>
		</div>
		<?php
	}
}
