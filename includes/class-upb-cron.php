<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Щоденна перевірка термінів (WP-Cron): прострочення + лист-нагадування.
 */
class UPB_Cron {

	const HOOK = 'upb_daily_check';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 120, 'daily', self::HOOK );
		}
	}

	public static function run() {
		global $wpdb;
		$warn = (int) UPB_Settings::get( 'warn_days' );
		$now  = time();

		// Віддалені сайти (PRO за доменом)
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . UPB_Remote::table() . " WHERE status='active' AND expires>0" ) as $rr ) {
			if ( (int) $rr->expires <= $now ) {
				UPB_Remote::expire( $rr );
				continue;
			}
			$d = (int) ceil( ( (int) $rr->expires - $now ) / DAY_IN_SECONDS );
			if ( $d <= $warn && ! $rr->notified && UPB_Mail::send_reminder_remote( $rr, $d ) ) {
				$wpdb->update( UPB_Remote::table(), array( 'notified' => 1 ), array( 'id' => (int) $rr->id ) );
			}
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( UPB_Premium::META_PREFIX ) . '%'
			)
		);
		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $row ) {
			$uid = (int) $row->user_id;
			$sid = (int) substr( $row->meta_key, strlen( UPB_Premium::META_PREFIX ) );
			$m   = UPB_Premium::get( $uid, $sid );

			if ( ! $m || ( isset( $m['status'] ) ? $m['status'] : '' ) !== 'active' ) {
				continue;
			}
			$exp = isset( $m['expires'] ) ? (int) $m['expires'] : 0;
			if ( 0 === $exp ) {
				continue; // безстроково
			}

			if ( $exp <= $now ) {
				UPB_Premium::expire( $uid, $sid );
				continue;
			}

			$days = (int) ceil( ( $exp - $now ) / DAY_IN_SECONDS );
			if ( $days <= $warn && empty( $m['notified'] ) ) {
				if ( self::send_reminder( $uid, $sid, $days, $exp ) ) {
					$m['notified'] = 1;
					update_user_meta( $uid, UPB_Premium::key( $sid ), $m );
				}
			}
		}
	}

	public static function send_reminder( $user_id, $service_id, $days, $expires ) {
		return UPB_Mail::send_reminder( $user_id, $service_id, $days, $expires );
	}
}
