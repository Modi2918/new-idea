<?php
namespace InvenzaLicenseServer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles database operations for the SaaS License Server.
 */
class Database {

	/**
	 * Setup database tables on activation.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table_customers   = $wpdb->prefix . 'fcac_customers';
		$table_licenses    = $wpdb->prefix . 'fcac_licenses';
		$table_activations = $wpdb->prefix . 'fcac_license_activations';
		$table_logs        = $wpdb->prefix . 'fcac_license_logs';
		$table_tickets     = $wpdb->prefix . 'fcac_support_tickets';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Customers Table
		$sql_customers = "CREATE TABLE {$table_customers} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			email varchar(100) NOT NULL UNIQUE,
			name varchar(255) DEFAULT NULL,
			total_licenses int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY email (email)
		) {$charset_collate};";
		dbDelta( $sql_customers );

		// Priority Support Tickets Table
		$sql_tickets = "CREATE TABLE {$table_tickets} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			license_key varchar(100) NOT NULL,
			domain varchar(255) NOT NULL,
			email varchar(100) NOT NULL,
			subject varchar(255) NOT NULL,
			message text NOT NULL,
			environment_info text DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			priority varchar(20) NOT NULL DEFAULT 'high',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY license_key (license_key),
			KEY email (email),
			KEY status (status)
		) {$charset_collate};";
		dbDelta( $sql_tickets );

		// 2. Licenses Table
		$sql_licenses = "CREATE TABLE {$table_licenses} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			customer_id bigint(20) NOT NULL,
			license_key varchar(100) NOT NULL UNIQUE,
			product_name varchar(100) NOT NULL,
			client_email varchar(100) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			max_activations int(11) NOT NULL DEFAULT 1,
			expiry_date datetime DEFAULT NULL,
			plan varchar(50) NOT NULL DEFAULT 'lifetime',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY customer_id (customer_id),
			KEY client_email (client_email),
			KEY status (status)
		) {$charset_collate};";
		dbDelta( $sql_licenses );

		// 3. License Activations Table
		$sql_activations = "CREATE TABLE {$table_activations} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			license_id bigint(20) NOT NULL,
			domain varchar(255) NOT NULL,
			ip_hash varchar(64) NOT NULL,
			activated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_checkin datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY license_id (license_id),
			KEY domain (domain(191)),
			KEY license_id_domain (license_id, domain(191)),
			KEY last_checkin (last_checkin)
		) {$charset_collate};";
		dbDelta( $sql_activations );

		// 4. License Logs Table
		$sql_logs = "CREATE TABLE {$table_logs} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			license_id bigint(20) NOT NULL,
			action varchar(50) NOT NULL,
			message text NOT NULL,
			ip_hash varchar(64) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY license_id (license_id),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta( $sql_logs );
	}

	/**
	 * Create a customer record if not exists, and return Customer ID.
	 *
	 * @param string $email Customer email.
	 * @param string $name Customer name.
	 * @return int Customer ID.
	 */
	public static function create_customer_if_not_exists( string $email, string $name ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'fcac_customers';
		$email = sanitize_email( trim( strtolower( $email ) ) );
		$name  = sanitize_text_field( $name );

		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $email ) );
		if ( $id ) {
			return (int) $id;
		}

		$wpdb->insert(
			$table,
			array(
				'email'          => $email,
				'name'           => $name,
				'total_licenses' => 0,
			),
			array( '%s', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Log a license validation audit event.
	 *
	 * @param int    $license_id License ID.
	 * @param string $action Action string (activate/deactivate/validate/fail).
	 * @param string $message Audit descriptive log message.
	 * @param string $ip_hash Client GDPR IP hash.
	 */
	public static function log_event( int $license_id, string $action, string $message, string $ip_hash = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'fcac_license_logs';

		$wpdb->insert(
			$table,
			array(
				'license_id' => $license_id,
				'action'     => sanitize_key( $action ),
				'message'    => sanitize_text_field( $message ),
				'ip_hash'    => sanitize_text_field( $ip_hash ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get activation count for a license ID.
	 *
	 * @param int $license_id License ID.
	 * @return int Count of active domains.
	 */
	public static function get_activations_count( int $license_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'fcac_license_activations';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE license_id = %d", $license_id ) );
	}

	/**
	 * Add domain activation row mapping.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain Normalized domain.
	 * @param string $ip_hash Client IP Hash.
	 * @return bool
	 */
	public static function add_activation( int $license_id, string $domain, string $ip_hash ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'fcac_license_activations';
		$result = $wpdb->insert(
			$table,
			array(
				'license_id' => $license_id,
				'domain'     => sanitize_text_field( $domain ),
				'ip_hash'    => sanitize_text_field( $ip_hash ),
			),
			array( '%d', '%s', '%s' )
		);
		return false !== $result;
	}

	/**
	 * Remove domain activation mapping.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain Normalized domain.
	 * @return bool
	 */
	public static function remove_activation( int $license_id, string $domain ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'fcac_license_activations';
		$result = $wpdb->delete(
			$table,
			array(
				'license_id' => $license_id,
				'domain'     => sanitize_text_field( $domain ),
			),
			array( '%d', '%s' )
		);
		return false !== $result;
	}

	/**
	 * Update the last checkin timestamp for a paired domain activation.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain Normalized domain.
	 */
	public static function update_checkin( int $license_id, string $domain ) {
		global $wpdb;
		$table = $wpdb->prefix . 'fcac_license_activations';
		$wpdb->update(
			$table,
			array( 'last_checkin' => current_time( 'mysql' ) ),
			array(
				'license_id' => $license_id,
				'domain'     => sanitize_text_field( $domain ),
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Check if a domain is already registered for this license.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain Normalized domain.
	 * @return bool
	 */
	public static function is_domain_active( int $license_id, string $domain ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'fcac_license_activations';
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE license_id = %d AND domain = %s", $license_id, sanitize_text_field( $domain ) ) );
		return ! empty( $id );
	}

	/**
	 * Retrieve a license row by its key.
	 *
	 * @param string $key License key string.
	 * @return object|null Row object or null.
	 */
	public static function get_license_by_key( string $key ) {
		global $wpdb;
		$table = $wpdb->prefix . 'fcac_licenses';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_key = %s", sanitize_text_field( $key ) ) );
	}

	/**
	 * Fetch all license rows.
	 *
	 * @return array
	 */
	public static function get_all_licenses(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'fcac_licenses';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from trusted prefix
		return $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY id DESC" );
	}

	/**
	 * Fetch all customer rows.
	 *
	 * @return array
	 */
	public static function get_all_customers(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'fcac_customers';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from trusted prefix
		return $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY id DESC" );
	}

	/**
	 * Fetch all logs rows.
	 *
	 * @return array
	 */
	public static function get_all_logs(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'fcac_license_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from trusted prefix
		return $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT 200" );
	}

	/**
	 * Insert a license directly (manual admin addition).
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $key Generated key.
	 * @param string $product Product name/slug.
	 * @param string $email Client email.
	 * @param int    $max_activations Limit of domains.
	 * @param string $expires Expiration datetime string.
	 * @param string $plan Plan string (monthly/yearly/lifetime).
	 * @return bool|int ID on success, false otherwise.
	 */
	public static function insert_license( int $customer_id, string $key, string $product, string $email, int $max_activations, string $expires, string $plan ) {
		global $wpdb;
		$table = $wpdb->prefix . 'fcac_licenses';

		$expires_val = empty( $expires ) ? null : date( 'Y-m-d H:i:s', strtotime( $expires ) );

		$result = $wpdb->insert(
			$table,
			array(
				'customer_id'     => $customer_id,
				'license_key'     => sanitize_text_field( $key ),
				'product_name'    => sanitize_text_field( $product ),
				'client_email'    => sanitize_email( $email ),
				'max_activations' => absint( $max_activations ),
				'expiry_date'     => $expires_val,
				'plan'            => sanitize_key( $plan ),
				'status'          => 'active',
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( $result ) {
			// Increment customer total licenses count.
			$table_cust = $wpdb->prefix . 'fcac_customers';
			$wpdb->query( $wpdb->prepare( "UPDATE {$table_cust} SET total_licenses = total_licenses + 1 WHERE id = %d", $customer_id ) );
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Toggle status of a license key.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public static function toggle_status( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'fcac_licenses';

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );
		if ( ! $row ) {
			return false;
		}

		$new_status = 'active' === $row->status ? 'suspended' : 'active';
		$result     = $wpdb->update(
			$table,
			array( 'status' => $new_status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete a license record.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public static function delete_license( int $id ): bool {
		global $wpdb;
		$table_lic = $wpdb->prefix . 'fcac_licenses';
		$table_act = $wpdb->prefix . 'fcac_license_activations';
		$table_log = $wpdb->prefix . 'fcac_license_logs';

		// Get customer ID first.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT customer_id FROM {$table_lic} WHERE id = %d", $id ) );

		// Delete all activations and logs mapped to this key.
		$wpdb->delete( $table_act, array( 'license_id' => $id ), array( '%d' ) );
		$wpdb->delete( $table_log, array( 'license_id' => $id ), array( '%d' ) );

		// Delete license.
		$result = $wpdb->delete( $table_lic, array( 'id' => $id ), array( '%d' ) );

		if ( $result && $row ) {
			$table_cust = $wpdb->prefix . 'fcac_customers';
			$wpdb->query( $wpdb->prepare( "UPDATE {$table_cust} SET total_licenses = GREATEST(0, total_licenses - 1) WHERE id = %d", $row->customer_id ) );
		}

		return false !== $result;
	}

	/**
	 * Get the server's private key for signing validation tokens.
	 *
	 * @return string Private key PEM.
	 */
	public static function get_private_key(): string {
		$pkey = get_option( 'INVENZA_SERVER_private_key', '' );
		if ( ! empty( $pkey ) ) {
			return $pkey;
		}

		$fallback_private = "-----BEGIN PRIVATE KEY-----\n" .
			"MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDNEet9eNUv3bVA\n" .
			"ACi/yA1he5pQ0MVzWn2XyaTVQkd12tJeLhBxL8F1laDalmoWI5qwZXYdHZ6rNPYj\n" .
			"7h0Aepdmw20xMsfl+mKmXwHwh1f3XJwUJ12KiuF14Qz6Dt1Q2aAG4JSNS6w+ZY+H\n" .
			"J++mvTXW8VYfDuZ1JmVXXQIo13KKBQr/3c6zAnIUqkTBUTQMpC83oS8Fk4Cl5oKJ\n" .
			"swjpqBTwzI+Xw5D6sOWsUMLxbolYwe5zlzCu+rp+wVh8ZTS7BJwf+H0eaJViYzQc\n" .
			"CkBHgUogQvHixSaqGrb0VzUqCnD8ooXdejA+WKxpSyPJHarEo1r5LyhSpApMe1Nr\n" .
			"NKzUZD0jAgMBAAECggEAIpa5L6qn2rD8l1TvipmFmEGu3563DyPeNzHtuYK6ZuiH\n" .
			"vxbp8w1pBho8zWG9dwp+Vu5mI7cRQjNmqNzKy3/h9ZVU341/Jg07gnBX9Wf+sFxQ\n" .
			"fx28q1eNe8J/29WSAscSNNbAd6yh2sxqjxNvWqJjaGPAcPCkcnINedTYPmdIjHrL\n" .
			"rhtoallyQJ+1lyJ6+6/Ft+VNM0N8yXmU1bqIwyEFiFsd0ouepmK759VU6qDUYP0k\n" .
			"c2DVG+UMZ1mqachOS6qLaCFYZ7+TseUH2+5jNlrjI+is9BroxhGBlug9Keh+1y6J\n" .
			"HD6L7KOtL9oyPenlSnV+76kbpTQ0DOx1dbZkIUEUMQKBgQD6QKa9/EtIbERmQnzV\n" .
			"5X0q3qcYyC3L2x9mBR2DkUcshUUBpWFkx3xVMHXiTyAA+MI4h2+PoSNniuvQcK6S\n" .
			"OZ9dFrBodFv0YHPxuT8Zgh9XvHY3BitggDhc1OL89qM6fc0Ej3G78JaZSufSIB2B\n" .
			"bmU1dXPhkaq0hrmMg40bGU9ETQKBgQDRx56qrj4Sxmm1ZEmkdbyXgf24ep0l1Q1G\n" .
			"ru7zIHpGXhUNVFAUt3Ur3ijcGIQxKYo289w2RikFr0gguRC4vSBYrkuigt1Z4A8t\n" .
			"nPaeHZHl0bd/DAiXxs2n4Z+SVRWVRQsieUF3y+Q3Eot5fbyCWh2BnUxQeS84YXdt\n" .
			"dfJ4IAb/LwKBgAYxj27oTZyvQUoenyRUF7L168DLQ4bmF3LY8ZAOCmrpqXmO9Egg\n" .
			"P82D84b0WmBrx7LKd1JgtJWddJSmFUv9LRqKszcCmjwEHxp+cTdaZxguy+Y0uuIa\n" .
			"ikqR4kRMfmG2N1rDihcSr0d/+RjUPqazasHV9FZC2qy5IOJ/ZwCisbmJAoGBAIBH\n" .
			"C8wdDlIWyA8miy2o3TDcSVTkjXHT7PFbCTzckEi1QTRMaUexw1GW4O9tgnA5kY32\n" .
			"4qLBllYmj/mKkSIWbFAuDIwMb+SEcWOYBuo69LtO2WEz75E3/Qv6mMQ3iSIk/SEQ\n" .
			"eqsRn7TZfzZEX+Bp0H8wu7i90dUtLIVfyWwRUZX7AoGAIMExs0nUi3Gm2VYlolGT\n" .
			"c6m5mdH4Uk4lF9dt2AWTT+CAq3KD7Y2oGiNWlCBdlZZLe75JQlP1xVtp+m3l4JYE\n" .
			"46JQoY/j0gjTNo8334RKBF1OZCQucqwWJQzZwDOmPs2oPTlDYa+M/5n6QK5AQqEu\n" .
			"pJAUvWNSYb0eksFrjqqOwC8=\n" .
			"-----END PRIVATE KEY-----\n";

		update_option( 'INVENZA_SERVER_private_key', $fallback_private );
		update_option( 'INVENZA_SERVER_public_key', self::get_fallback_public() );

		return $fallback_private;
	}

	/**
	 * Get the static fallback public key.
	 */
	private static function get_fallback_public(): string {
		return "-----BEGIN PUBLIC KEY-----\n" .
			"MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAzRHrfXjVL921QAAov8gN\n" .
			"YXuaUNDFc1p9l8mk1UJHddrSXi4QcS/BdZWg2pZqFiOasGV2HR2eqzT2I+4dAHqX\n" .
			"ZsNtMTLH5fpipl8B8IdX91ycFCddiorhdeEM+g7dUNmgBuCUjUusPmWPhyfvpr01\n" .
			"1vFWHw7mdSZlV10CKNdyigUK/93OswJyFKpEwVE0DKQvN6EvBZOApeaCibMI6agU\n" .
			"8MyPl8OQ+rDlrFDC8W6JWMHuc5cwrvq6fsFYfGU0uwScH/h9HmiVYmM0HApAR4FK\n" .
			"IELx4sUmqhq29Fc1Kgpw/KKF3XowPlisaUsjyR2qxKNa+S8oUqQKTHtTazSs1GQ9\n" .
			"IwIDAQAB\n" .
			"-----END PUBLIC KEY-----\n";
	}

	/**
	 * Get the server's public key for token verification.
	 *
	 * @return string Public key PEM.
	 */
	public static function get_public_key(): string {
		$pub = get_option( 'INVENZA_SERVER_public_key', '' );
		if ( ! empty( $pub ) ) {
			return $pub;
		}
		$pub = self::get_fallback_public();
		update_option( 'INVENZA_SERVER_public_key', $pub );
		return $pub;
	}
}
