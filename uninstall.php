<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'upb_settings', array() );
wp_clear_scheduled_hook( 'upb_daily_check' );

// Дані видаляються ТІЛЬКИ якщо в налаштуваннях увімкнено "Видалити всі дані при видаленні плагіна".
if ( empty( $settings['delete_data'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}upb_log" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_upb_premium_' ) . '%' ) );
delete_option( 'upb_services' );
delete_option( 'upb_next_id' );
delete_option( 'upb_settings' );
delete_option( 'upb_db_version' );
