<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPB_Installer {

	const DB_VERSION = '3';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'upb_log';
	}

	public static function activate() {
		self::create_table();
		add_option( 'upb_services', array(), '', 'no' );
		add_option( 'upb_next_id', 1, '', 'no' );
		add_option( 'upb_settings', UPB_Settings::defaults(), '', 'no' );
		update_option( 'upb_db_version', self::DB_VERSION );
		UPB_Cron::schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( UPB_Cron::HOOK );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'upb_db_version' ) !== self::DB_VERSION ) {
			self::create_table();
			// 1.1 → 1.2: у збережених листах про блокування «логін» замінюється на {id_word} (логін або домен)
			$all = get_option( 'upb_services', array() );
			if ( is_array( $all ) ) {
				foreach ( $all as $k => $svc ) {
					if ( ! empty( $svc['block_body'] ) ) {
						$all[ $k ]['block_body'] = str_replace( 'вкажіть ваш логін: {username}', 'вкажіть {id_word}: {username}', $svc['block_body'] );
					}
					if ( ! isset( $svc['id_mode'] ) ) {
						$all[ $k ]['id_mode'] = ( ! empty( $svc['slug'] ) && 0 === strpos( $svc['slug'], 'voice-add-' ) ) ? 'domain' : 'login';
					}
				}
				update_option( 'upb_services', $all, false );
			}
			update_option( 'upb_db_version', self::DB_VERSION );
		}
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			service_id BIGINT(20) UNSIGNED NOT NULL,
			service_name VARCHAR(255) NOT NULL DEFAULT '',
			clicked_at DATETIME NOT NULL,
			expires_at BIGINT(20) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			domain VARCHAR(191) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY user_service (user_id,service_id),
			KEY clicked_at (clicked_at)
		) {$charset};";
		dbDelta( $sql );

		$remote = $wpdb->prefix . 'upb_remote';
		$sql2   = "CREATE TABLE {$remote} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			service_id BIGINT(20) UNSIGNED NOT NULL,
			domain VARCHAR(191) NOT NULL,
			email VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			expires BIGINT(20) NOT NULL DEFAULT 0,
			notified TINYINT(1) NOT NULL DEFAULT 0,
			log_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY svc_domain (service_id,domain)
		) {$charset};";
		dbDelta( $sql2 );

		$licenses = $wpdb->prefix . 'upb_licenses';
		$sql3 = "CREATE TABLE {$licenses} (
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
			KEY status (status)
		) {$charset};";
		dbDelta( $sql3 );
	}
}
