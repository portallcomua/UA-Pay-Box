<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Логіка PRO-статусів користувачів (user_meta + журнал активацій).
 *
 * Мета-ключ: _upb_premium_{service_id} => array(
 *   status   => active | blocked | expired
 *   expires  => unix timestamp (0 = безстроково)
 *   activated=> unix timestamp
 *   log_id   => id запису в журналі
 *   notified => 0|1 (чи надіслано лист про закінчення)
 * )
 */
class UPB_Premium {

	const META_PREFIX = '_upb_premium_';

	public static function key( $service_id ) {
		return self::META_PREFIX . (int) $service_id;
	}

	public static function get( $user_id, $service_id ) {
		$m = get_user_meta( (int) $user_id, self::key( $service_id ), true );
		return is_array( $m ) ? $m : null;
	}

	/** Чи має користувач чинний PRO. */
	public static function is_active( $user_id, $service_id ) {
		$m = self::get( $user_id, $service_id );
		if ( ! $m || ( isset( $m['status'] ) ? $m['status'] : '' ) !== 'active' ) {
			return false;
		}
		$exp = isset( $m['expires'] ) ? (int) $m['expires'] : 0;
		return 0 === $exp || $exp > time();
	}

	/**
	 * Детальний стан: free | active | expiring | unlimited | blocked
	 */
	public static function state( $user_id, $service_id ) {
		$out = array( 'state' => 'free', 'expires' => 0, 'days_left' => 0 );
		$m   = self::get( $user_id, $service_id );
		if ( ! $m ) {
			return $out;
		}
		$status = isset( $m['status'] ) ? $m['status'] : '';
		if ( 'blocked' === $status ) {
			$out['state'] = 'blocked';
			return $out;
		}
		if ( 'active' !== $status ) {
			return $out;
		}
		$exp = isset( $m['expires'] ) ? (int) $m['expires'] : 0;
		if ( 0 === $exp ) {
			$out['state'] = 'unlimited';
			return $out;
		}
		if ( $exp <= time() ) {
			return $out; // прострочено, cron ще не встиг позначити
		}
		$days             = (int) ceil( ( $exp - time() ) / DAY_IN_SECONDS );
		$out['expires']   = $exp;
		$out['days_left'] = $days;
		$out['state']     = ( $days <= (int) UPB_Settings::get( 'warn_days' ) ) ? 'expiring' : 'active';
		return $out;
	}

	/**
	 * Миттєва активація PRO. Повертає array або WP_Error.
	 */
	public static function activate( $user_id, array $service ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$sid     = (int) $service['id'];
		$st      = self::state( $user_id, $sid );

		if ( 'blocked' === $st['state'] ) {
			return new WP_Error( 'upb_blocked', 'Ваш доступ до цієї послуги заблоковано адміністратором. Зверніться до адміністрації сайту.' );
		}
		if ( 'active' === $st['state'] || 'unlimited' === $st['state'] ) {
			return new WP_Error( 'upb_already', 'У вас уже активний тариф. Продовжити його можна за кілька днів до закінчення.' );
		}

		$days = max( 1, (int) $service['days'] );
		$now  = time();
		if ( $days >= 9999 ) {
			$exp = 0;
		} else {
			$base = ( 'expiring' === $st['state'] ) ? $st['expires'] : $now; // продовження додається до чинного терміну
			$exp  = $base + $days * DAY_IN_SECONDS;
		}

		$table = UPB_Installer::table();
		// Попередні активні записи цього користувача по послузі — позначаємо як "renewed".
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='renewed' WHERE user_id=%d AND service_id=%d AND status='active'", $user_id, $sid ) );
		$wpdb->insert(
			$table,
			array(
				'user_id'      => $user_id,
				'service_id'   => $sid,
				'service_name' => $service['name'],
				'clicked_at'   => gmdate( 'Y-m-d H:i:s' ),
				'expires_at'   => $exp,
				'status'       => 'active',
				'domain'       => UPB_Util::site_domain(),
				'email'        => (string) ( get_userdata( $user_id ) ? get_userdata( $user_id )->user_email : '' ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$log_id = (int) $wpdb->insert_id;

		update_user_meta(
			$user_id,
			self::key( $sid ),
			array(
				'status'    => 'active',
				'expires'   => $exp,
				'activated' => $now,
				'log_id'    => $log_id,
				'notified'  => 0,
			)
		);

		return array( 'expires' => $exp, 'log_id' => $log_id );
	}

	/** Блокування: PRO вимкнено, повторна самоактивація неможлива. */
	public static function block( $user_id, $service_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$sid     = (int) $service_id;
		$m       = self::get( $user_id, $sid );
		$m       = is_array( $m ) ? $m : array();
		$m['status'] = 'blocked';
		update_user_meta( $user_id, self::key( $sid ), $m );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . UPB_Installer::table() . " SET status='blocked' WHERE user_id=%d AND service_id=%d AND status='active'", $user_id, $sid ) );
		UPB_Mail::send_blocked( $user_id, $sid );
		do_action( 'upb_premium_blocked', $user_id, $sid );
	}

	/**
	 * Розблокування: PRO повертається (оплату підтверджено). Якщо строк уже минув —
	 * відлік іде від сьогодні за терміном послуги.
	 */
	public static function unblock( $user_id, $service_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$sid     = (int) $service_id;
		$m       = self::get( $user_id, $sid );
		$svc     = UPB_Services::get( $sid );
		$exp     = ( $m && isset( $m['expires'] ) ) ? (int) $m['expires'] : 0;
		if ( $exp > 0 && $exp <= time() ) {
			$days = $svc ? max( 1, (int) $svc['days'] ) : 30;
			$exp  = $days >= 9999 ? 0 : time() + $days * DAY_IN_SECONDS;
		}
		$m = is_array( $m ) ? $m : array();
		$m['status']   = 'active';
		$m['expires']  = $exp;
		$m['notified'] = 0;
		update_user_meta( $user_id, self::key( $sid ), $m );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . UPB_Installer::table() . " SET status='active', expires_at=%d WHERE user_id=%d AND service_id=%d AND status='blocked'", $exp, $user_id, $sid ) );
		do_action( 'upb_premium_unblocked', $user_id, $sid );
	}

	/** Позначити як прострочений (викликає cron). */
	public static function expire( $user_id, $service_id ) {
		global $wpdb;
		$m = self::get( $user_id, $service_id );
		if ( ! $m ) {
			return;
		}
		$m['status'] = 'expired';
		update_user_meta( (int) $user_id, self::key( $service_id ), $m );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . UPB_Installer::table() . " SET status='expired' WHERE user_id=%d AND service_id=%d AND status='active'", (int) $user_id, (int) $service_id ) );
		do_action( 'upb_premium_expired', (int) $user_id, (int) $service_id );
	}
}
