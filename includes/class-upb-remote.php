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

	public static function activate( array $service, $domain, $email ) {
		global $wpdb;
		$sid = (int) $service['id'];
		$st  = self::state( $sid, $domain );
		if ( 'blocked' === $st['state'] ) {
			return new WP_Error( 'upb_blocked', 'Доступ для цього домену заблоковано адміністратором. Дочекайтесь підтвердження оплати.' );
		}
		if ( 'active' === $st['state'] || 'unlimited' === $st['state'] ) {
			return new WP_Error( 'upb_already', 'Для цього домену PRO вже активний.' );
		}
		$days = max( 1, (int) $service['days'] );
		$now  = time();
		if ( $days >= 9999 ) {
			$exp = 0;
		} else {
			$base = ( 'expiring' === $st['state'] ) ? $st['expires'] : $now;
			$exp  = $base + $days * DAY_IN_SECONDS;
		}
		$log = UPB_Installer::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$log} SET status='renewed' WHERE user_id=0 AND domain=%s AND service_id=%d AND status='active'", $domain, $sid ) );
		$wpdb->insert(
			$log,
			array(
				'user_id'      => 0,
				'service_id'   => $sid,
				'service_name' => $service['name'],
				'clicked_at'   => gmdate( 'Y-m-d H:i:s' ),
				'expires_at'   => $exp,
				'status'       => 'active',
				'domain'       => $domain,
				'email'        => $email,
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$log_id = (int) $wpdb->insert_id;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . " (service_id,domain,email,status,expires,notified,log_id) VALUES (%d,%s,%s,'active',%d,0,%d)
				ON DUPLICATE KEY UPDATE email=VALUES(email), status='active', expires=VALUES(expires), notified=0, log_id=VALUES(log_id)",
				$sid,
				$domain,
				$email,
				$exp,
				$log_id
			)
		);
		do_action( 'upb_remote_activated', $domain, $sid, $exp );
		return array( 'expires' => $exp, 'log_id' => $log_id );
	}

	public static function block( $service_id, $domain ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status='blocked' WHERE service_id=%d AND domain=%s", (int) $service_id, $domain ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . UPB_Installer::table() . " SET status='blocked' WHERE user_id=0 AND service_id=%d AND domain=%s AND status='active'", (int) $service_id, $domain ) );
		UPB_Mail::send_blocked_remote( (int) $service_id, $domain );
		do_action( 'upb_remote_blocked', $domain, (int) $service_id );
	}

	/** Оплату підтверджено: PRO повертається. */
	public static function unblock( $service_id, $domain ) {
		global $wpdb;
		$r   = self::get( $service_id, $domain );
		$svc = UPB_Services::get( $service_id );
		if ( ! $r ) {
			return;
		}
		$exp = (int) $r->expires;
		if ( $exp > 0 && $exp <= time() ) {
			$days = $svc ? max( 1, (int) $svc['days'] ) : 30;
			$exp  = $days >= 9999 ? 0 : time() + $days * DAY_IN_SECONDS;
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status='active', expires=%d, notified=0 WHERE service_id=%d AND domain=%s", $exp, (int) $service_id, $domain ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . UPB_Installer::table() . " SET status='active', expires_at=%d WHERE user_id=0 AND service_id=%d AND domain=%s AND status='blocked'", $exp, (int) $service_id, $domain ) );
		do_action( 'upb_remote_unblocked', $domain, (int) $service_id );
	}

	public static function expire( $row ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status='expired' WHERE id=%d", (int) $row->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . UPB_Installer::table() . " SET status='expired' WHERE user_id=0 AND service_id=%d AND domain=%s AND status='active'", (int) $row->service_id, $row->domain ) );
	}
}
