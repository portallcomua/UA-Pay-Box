<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPB_Installer {

	const DB_VERSION = '4';

	public static function activate() {
		self::create_table();
		add_option( 'upb_services', array(), '', 'no' );
		add_option( 'upb_next_id', 1, '', 'no' );
		add_option( 'upb_settings', UPB_Settings::defaults(), '', 'no' );
		update_option( 'upb_db_version', self::DB_VERSION );
	}

	public static function deactivate() {
		// Remove the scheduled job from the retired manual-payment system.
		wp_clear_scheduled_hook( 'upb_daily_check' );
	}

	public static function maybe_upgrade() {
		if ( (string) get_option( 'upb_db_version' ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		// The administrator approved retirement of the old manual bank-payment workflow.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}upb_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}upb_remote" );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_upb_premium_' ) . '%' ) );
		wp_clear_scheduled_hook( 'upb_daily_check' );

		$services = UPB_Services::all();
		foreach ( $services as $id => $service ) {
			unset( $services[ $id ]['url'], $services[ $id ]['id_mode'], $services[ $id ]['block_subject'], $services[ $id ]['block_body'], $services[ $id ]['success_message'], $services[ $id ]['email_subject'], $services[ $id ]['email_body'] );
		}
		update_option( 'upb_services', $services, false );

		$settings = get_option( 'upb_settings', array() );
		if ( is_array( $settings ) ) {
			unset( $settings['notify_admin'] );
			update_option( 'upb_settings', $settings, false );
		}

		self::create_table();
		update_option( 'upb_db_version', self::DB_VERSION );
	}

	/** Create only the Freemius license table. Legacy payment tables are no longer used. */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$licenses = $wpdb->prefix . 'upb_licenses';
		$sql = "CREATE TABLE {$licenses} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			service_id BIGINT(20) UNSIGNED NOT NULL,
			license_id VARCHAR(191) NOT NULL,
			license_hash CHAR(64) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			expires BIGINT(20) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY service_license (service_id,license_id),
			KEY license_hash (license_hash),
			KEY status (status),
			KEY service_email (service_id,email)
		) {$charset};";
		dbDelta( $sql );
	}
}
