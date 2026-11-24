<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PRO для ВІДДАЛЕНИХ сайтів (плагіни, встановлені на чужих сайтах).
 * Власник PRO визначається доменом сайту, а не логіном користувача.
 */
class UPB_Remote {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'upb_remote';
	}

	public static function get( $service_id, $domain ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE service_id=%d AND domain=%s', (int) $service_id, $domain ) );
	}

	/** free | active | expiring | unlimited | blocked */
	public static function state( $service_id, $domain ) {
		$out = array( 'state' => 'free', 'expires' => 0, 'days_left' => 0 );
		$row = self::get( $service_id, $domain );
		if ( ! $row || ! is_email( $row->email ) ) {
			return $out;
		}
		$license = UPB_Freemius::get_email_license( (int) $service_id, $row->email );
		if ( ! $license ) {
			return $out;
		}
		$exp = (int) $license->expires;
		if ( 0 === $exp ) {
			$out['state'] = 'unlimited';
			return $out;
		}
		$out['expires'] = $exp;
		$out['days_left'] = (int) ceil( ( $exp - time() ) / DAY_IN_SECONDS );
		$out['state'] = $out['days_left'] <= (int) UPB_Settings::get( 'warn_days' ) ? 'expiring' : 'active';
		return $out;
	}

}
