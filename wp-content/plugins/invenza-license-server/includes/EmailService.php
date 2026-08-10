<?php
namespace InvenzaLicenseServer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles transactional emails containing licenses and receipts.
 */
class EmailService {

	/**
	 * Configure PHPMailer to use custom SMTP settings if configured.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public static function configure_smtp( $phpmailer ) {
		$enabled = get_option( 'INVENZA_SERVER_smtp_enabled', 'no' );
		if ( 'yes' !== $enabled ) {
			return;
		}

		$host       = get_option( 'INVENZA_SERVER_smtp_host', '' );
		$port       = get_option( 'INVENZA_SERVER_smtp_port', '587' );
		$encryption = get_option( 'INVENZA_SERVER_smtp_encryption', 'tls' );
		$auth       = get_option( 'INVENZA_SERVER_smtp_auth', 'yes' );
		$username   = get_option( 'INVENZA_SERVER_smtp_username', '' );
		$password   = get_option( 'INVENZA_SERVER_smtp_password', '' );
		$from_email = get_option( 'INVENZA_SERVER_smtp_from_email', '' );
		$from_name  = get_option( 'INVENZA_SERVER_smtp_from_name', 'Invenza License Server' );

		if ( empty( $host ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = $host;
		$phpmailer->Port       = absint( $port );
		$phpmailer->SMTPAuth   = 'yes' === $auth;
		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = $username;
			$phpmailer->Password = $password;
		}

		if ( 'ssl' === $encryption || 'tls' === $encryption ) {
			$phpmailer->SMTPSecure = $encryption;
		} else {
			$phpmailer->SMTPSecure = '';
			$phpmailer->SMTPAutoTLS = false;
		}

		if ( ! empty( $from_email ) ) {
			$phpmailer->From     = $from_email;
			$phpmailer->FromName = $from_name;
		}
	}

	/**
	 * Send the license delivery email.
	 *
	 * @param string $to Recipient email.
	 * @param string $name Recipient name.
	 * @param string $key Activated license key.
	 * @param string $plan Plan tier description.
	 * @param int    $max_activations Limit of activations.
	 * @param string $expires Expiry date or 'Never'.
	 * @return bool True if mail sent successfully, false otherwise.
	 */
	public static function send_license_email( string $to, string $name, string $key, string $plan, int $max_activations, string $expires ): bool {
		$subject = __( 'Your Advanced CAPTCHA for All Forms License is Ready!', 'invenza-license-server' );

		// Render Premium HTML template.
		ob_start();
		?>
		<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php echo esc_html( $subject ); ?></title>
			<style>
				/* Reset & Base Settings */
				body, table, td, div, p, a {
					-webkit-text-size-adjust: 100%;
					-ms-text-size-adjust: 100%;
				}
				table, td {
					mso-table-lspace: 0pt;
					mso-table-rspace: 0pt;
				}
				img {
					-ms-interpolation-mode: bicubic;
					border: 0;
					height: auto;
					line-height: 100%;
					outline: none;
					text-decoration: none;
				}
				body {
					margin: 0;
					padding: 0;
					width: 100% !important;
					font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
					background-color: #f3f4f6;
					color: #1f2937;
				}
				/* Layout & Styling */
				.email-container {
					max-width: 600px;
					margin: 40px auto;
					background-color: #ffffff;
					border-radius: 16px;
					overflow: hidden;
					box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
				}
				.header {
					background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
					padding: 50px 40px;
					text-align: center;
				}
				.header h1 {
					color: #ffffff;
					font-size: 28px;
					font-weight: 800;
					margin: 0;
					letter-spacing: -0.5px;
				}
				.header p {
					color: #e0e7ff;
					font-size: 16px;
					margin: 10px 0 0 0;
					font-weight: 500;
				}
				.body-content {
					padding: 40px;
					background-color: #ffffff;
				}
				.greeting {
					font-size: 20px;
					font-weight: 700;
					color: #111827;
					margin-top: 0;
					margin-bottom: 16px;
				}
				.message {
					font-size: 16px;
					line-height: 1.6;
					color: #4b5563;
					margin-bottom: 30px;
				}
				.license-box {
					background: linear-gradient(to right, #111827, #1f2937);
					border-radius: 12px;
					padding: 24px;
					text-align: center;
					margin-bottom: 32px;
					box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
					position: relative;
				}
				.license-label {
					font-size: 12px;
					text-transform: uppercase;
					letter-spacing: 1.5px;
					color: #9ca3af;
					font-weight: 600;
					margin-bottom: 12px;
					display: block;
				}
				.license-key {
					font-family: 'Fira Code', Consolas, Monaco, monospace;
					font-size: 22px;
					color: #10b981;
					font-weight: 700;
					letter-spacing: 2px;
					word-break: break-all;
					margin: 0;
				}
				.details-table {
					width: 100%;
					border-collapse: collapse;
					margin-bottom: 32px;
				}
				.details-table td {
					padding: 16px 0;
					border-bottom: 1px solid #f3f4f6;
				}
				.details-table tr:last-child td {
					border-bottom: none;
				}
				.detail-label {
					font-weight: 600;
					color: #6b7280;
					font-size: 15px;
					width: 40%;
				}
				.detail-value {
					font-weight: 700;
					color: #111827;
					font-size: 15px;
					text-align: right;
					text-transform: capitalize;
				}
				.steps-section {
					background-color: #f9fafb;
					border-radius: 12px;
					padding: 30px;
					margin-bottom: 32px;
					border: 1px solid #f3f4f6;
				}
				.steps-title {
					font-size: 16px;
					font-weight: 700;
					color: #111827;
					margin-top: 0;
					margin-bottom: 16px;
					text-transform: uppercase;
					letter-spacing: 0.5px;
				}
				.steps-list {
					margin: 0;
					padding-left: 20px;
					color: #4b5563;
					font-size: 15px;
					line-height: 1.6;
				}
				.steps-list li {
					margin-bottom: 10px;
				}
				.steps-list li:last-child {
					margin-bottom: 0;
				}
				.btn-container {
					text-align: center;
					margin-top: 10px;
				}
				.btn {
					display: inline-block;
					background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
					color: #ffffff !important;
					text-decoration: none;
					padding: 16px 32px;
					border-radius: 50px;
					font-weight: 700;
					font-size: 16px;
					box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.3);
					transition: transform 0.2s, box-shadow 0.2s;
				}
				.btn:hover {
					box-shadow: 0 6px 8px -1px rgba(79, 70, 229, 0.4);
				}
				.footer {
					background-color: #f9fafb;
					padding: 30px 40px;
					text-align: center;
					border-top: 1px solid #e5e7eb;
				}
				.footer p {
					color: #9ca3af;
					font-size: 13px;
					margin: 8px 0;
					line-height: 1.5;
				}
				.footer a {
					color: #6366f1;
					text-decoration: none;
					font-weight: 500;
				}
			</style>
		</head>
		<body>
			<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #f3f4f6; padding: 40px 20px;">
				<tr>
					<td align="center">
						<div class="email-container">
							<!-- Header -->
							<div class="header">
								<h1><?php esc_html_e( 'Advanced CAPTCHA for All Forms', 'invenza-license-server' ); ?></h1>
								<p><?php esc_html_e( 'Your Premium License is Ready', 'invenza-license-server' ); ?></p>
							</div>
							
							<!-- Body -->
							<div class="body-content">
								<h2 class="greeting"><?php printf( esc_html__( 'Hello %s,', 'invenza-license-server' ), esc_html( $name ) ); ?></h2>
								<p class="message"><?php esc_html_e( 'Thank you for upgrading to Advanced CAPTCHA for All Forms Enterprise. Your purchase was successful, and your premium license key is ready to use.', 'invenza-license-server' ); ?></p>
								
								<!-- License Key Block -->
								<div class="license-box">
									<span class="license-label"><?php esc_html_e( 'Your Activation Key', 'invenza-license-server' ); ?></span>
									<p class="license-key"><?php echo esc_html( $key ); ?></p>
								</div>

								<!-- Subscription Details -->
								<table class="details-table" role="presentation">
									<tr>
										<td class="detail-label"><?php esc_html_e( 'Billing Plan', 'invenza-license-server' ); ?></td>
										<td class="detail-value"><?php echo esc_html( $plan ); ?></td>
									</tr>
									<tr>
										<td class="detail-label"><?php esc_html_e( 'Site Capacity', 'invenza-license-server' ); ?></td>
										<td class="detail-value"><?php echo absint( $max_activations ) . esc_html__( ' Site(s)', 'invenza-license-server' ); ?></td>
									</tr>
									<tr>
										<td class="detail-label"><?php esc_html_e( 'Expiration Date', 'invenza-license-server' ); ?></td>
										<td class="detail-value"><?php echo esc_html( $expires ); ?></td>
									</tr>
								</table>

								<!-- Activation Steps -->
								<div class="steps-section">
									<h3 class="steps-title"><?php esc_html_e( 'Quick Start Guide', 'invenza-license-server' ); ?></h3>
									<ol class="steps-list">
										<li><?php esc_html_e( 'Download the extension zip from your account dashboard.', 'invenza-license-server' ); ?></li>
										<li><?php esc_html_e( 'In WordPress, navigate to Plugins > Add New and upload the zip.', 'invenza-license-server' ); ?></li>
										<li><?php esc_html_e( 'Go to Advanced CAPTCHA > License, paste your key, and click Activate.', 'invenza-license-server' ); ?></li>
									</ol>
								</div>

								<!-- Call to Action -->
								<div class="btn-container">
									<a href="<?php echo esc_url( home_url( '/account' ) ); ?>" class="btn"><?php esc_html_e( 'Access Your Dashboard', 'invenza-license-server' ); ?></a>
								</div>
							</div>

							<!-- Footer -->
							<div class="footer">
								<p><?php esc_html_e( 'Need assistance? Reach out to our dedicated support team at', 'invenza-license-server' ); ?> <br>
								<a href="mailto:support@formcraft-captcha.com">support@formcraft-captcha.com</a></p>
								<p>&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php esc_html_e( 'Advanced CAPTCHA for All Forms Enterprise. All rights reserved.', 'invenza-license-server' ); ?></p>
							</div>
						</div>
					</td>
				</tr>
			</table>
		</body>
		</html>
		<?php
		$message = ob_get_clean();

		// Configure transactional mail formats.
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: Advanced CAPTCHA for All Forms <sales@formcraft-captcha.com>',
		);

		$sent = wp_mail( $to, $subject, $message, $headers );

		if ( ! $sent ) {
			error_log( sprintf( '[FCAC License Server] Failed to send license email to: %s via wp_mail.', $to ) );
		}

		// Also notify site admin that a key was created/purchased
		self::send_admin_notification( $to, $name, $key, $plan, $max_activations, $expires );

		return $sent;
	}

	/**
	 * Send admin notification email when a new key is purchased/generated.
	 *
	 * @param string $customer_email Customer email.
	 * @param string $customer_name Customer name.
	 * @param string $key Generated license key.
	 * @param string $plan Plan tier.
	 * @param int    $max_activations Activation limit.
	 * @param string $expires Expiry date.
	 * @return bool
	 */
	public static function send_admin_notification( string $customer_email, string $customer_name, string $key, string $plan, int $max_activations, string $expires ): bool {
		$admin_email = get_option( 'admin_email' );
		if ( empty( $admin_email ) ) {
			return false;
		}

		$subject = sprintf( __( '[License Server] New License Purchased / Generated: %s', 'invenza-license-server' ), $key );

		ob_start();
		?>
		<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="utf-8">
			<title><?php echo esc_html( $subject ); ?></title>
			<style>
				body { font-family: 'Inter', -apple-system, sans-serif; background-color: #f3f4f6; color: #1f2937; margin: 0; padding: 20px; }
				.card { max-width: 550px; margin: 20px auto; background: #ffffff; border-radius: 12px; padding: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
				.title { font-size: 20px; font-weight: 700; color: #111827; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; }
				.key-box { background: #111827; color: #10b981; font-family: monospace; font-size: 18px; padding: 14px; border-radius: 8px; text-align: center; margin: 20px 0; font-weight: bold; }
				table { width: 100%; border-collapse: collapse; margin-top: 15px; }
				td { padding: 10px 0; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
				.label { font-weight: 600; color: #6b7280; width: 40%; }
				.val { font-weight: 700; color: #111827; text-align: right; }
			</style>
		</head>
		<body>
			<div class="card">
				<div class="title"><?php esc_html_e( 'New License Order Notification', 'invenza-license-server' ); ?></div>
				<p><?php esc_html_e( 'A new license key was just created/purchased on your Invenza License Server:', 'invenza-license-server' ); ?></p>
				
				<div class="key-box"><?php echo esc_html( $key ); ?></div>

				<table>
					<tr>
						<td class="label"><?php esc_html_e( 'Customer Name', 'invenza-license-server' ); ?></td>
						<td class="val"><?php echo esc_html( $customer_name ); ?></td>
					</tr>
					<tr>
						<td class="label"><?php esc_html_e( 'Customer Email', 'invenza-license-server' ); ?></td>
						<td class="val"><?php echo esc_html( $customer_email ); ?></td>
					</tr>
					<tr>
						<td class="label"><?php esc_html_e( 'Plan / Tier', 'invenza-license-server' ); ?></td>
						<td class="val"><?php echo esc_html( ucfirst( $plan ) ); ?></td>
					</tr>
					<tr>
						<td class="label"><?php esc_html_e( 'Site Limit', 'invenza-license-server' ); ?></td>
						<td class="val"><?php echo absint( $max_activations ); ?> Site(s)</td>
					</tr>
					<tr>
						<td class="label"><?php esc_html_e( 'Expires On', 'invenza-license-server' ); ?></td>
						<td class="val"><?php echo esc_html( $expires ); ?></td>
					</tr>
				</table>
			</div>
		</body>
		</html>
		<?php
		$message = ob_get_clean();

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: Invenza License Server <noreply@' . ( parse_url( home_url(), PHP_URL_HOST ) ?: 'isweb.in' ) . '>',
		);

		return wp_mail( $admin_email, $subject, $message, $headers );
	}
}
