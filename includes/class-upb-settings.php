<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPB_Settings {

	public static function defaults() {
		return array(
			'warn_days'       => 10,
			'load_everywhere' => 0,
			'notify_admin'    => 1,
			'delete_data'     => 0,
		);
	}

	public static function all() {
		return wp_parse_args( get_option( 'upb_settings', array() ), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}
}
