<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Листи користувачам: нагадування про закінчення та повідомлення про блокування.
 */
class UPB_Mail {

	private static function send( $user, $service, $subject, $body, array $extra = array() ) {
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		$map = array_merge(
			array(
				'username' => $user->user_login,
				'service'  => $service['name'],
				'pay_url'  => $service['url'],
				'site'     => home_url( '/' ),
				'id_word'  => ( isset( $service['id_mode'] ) && 'domain' === $service['id_mode'] ) ? 'домен' : 'логін',
			),
			$extra
		);
		return (bool) wp_mail(
			$user->user_email,
			wp_specialchars_decode( UPB_Util::replace_tokens( $subject, $map ), ENT_QUOTES ),
			UPB_Util::replace_tokens( $body, $map )
		);
	}

	public static function send_reminder( $user_id, $service_id, $days, $expires ) {
		$user    = get_userdata( $user_id );
		$service = UPB_Services::get( $service_id );
		if ( ! $user || ! $service ) {
			return false;
		}
		$def     = UPB_Services::defaults();
		$subject = ! empty( $service['email_subject'] ) ? $service['email_subject'] : $def['email_subject'];
		$body    = ! empty( $service['email_body'] ) ? $service['email_body'] : $def['email_body'];
		return self::send(
			$user,
			$service,
			$subject,
			$body,
			array(
				'days'    => UPB_Util::days_word( $days ),
				'expires' => UPB_Util::fmt_ts( $expires ),
			)
		);
	}

	/** Лист віддаленому сайту (за email, указаним при активації). */
	public static function send_blocked_remote( $service_id, $domain ) {
		$svc = UPB_Services::get( $service_id );
		$row = UPB_Remote::get( $service_id, $domain );
		if ( ! $svc || ! $row || ! is_email( $row->email ) ) {
			return false;
		}
		$def     = UPB_Services::defaults();
		$subject = ! empty( $svc['block_subject'] ) ? $svc['block_subject'] : $def['block_subject'];
		$body    = ! empty( $svc['block_body'] ) ? $svc['block_body'] : $def['block_body'];
		$map     = array( 'username' => $domain, 'domain' => $domain, 'service' => $svc['name'], 'pay_url' => $svc['url'], 'site' => $domain, 'id_word' => 'домен' );
		return (bool) wp_mail( $row->email, wp_specialchars_decode( UPB_Util::replace_tokens( $subject, $map ), ENT_QUOTES ), UPB_Util::replace_tokens( $body, $map ) );
	}

	public static function send_reminder_remote( $row, $days ) {
		$svc = UPB_Services::get( $row->service_id );
		if ( ! $svc || ! is_email( $row->email ) ) {
			return false;
		}
		$def     = UPB_Services::defaults();
		$subject = ! empty( $svc['email_subject'] ) ? $svc['email_subject'] : $def['email_subject'];
		$body    = ! empty( $svc['email_body'] ) ? $svc['email_body'] : $def['email_body'];
		$map     = array( 'username' => $row->domain, 'domain' => $row->domain, 'service' => $svc['name'], 'days' => UPB_Util::days_word( $days ), 'expires' => UPB_Util::fmt_ts( $row->expires ), 'site' => $row->domain, 'pay_url' => $svc['url'], 'id_word' => 'домен' );
		return (bool) wp_mail( $row->email, wp_specialchars_decode( UPB_Util::replace_tokens( $subject, $map ), ENT_QUOTES ), UPB_Util::replace_tokens( $body, $map ) );
	}

	/** Лист «послугу не сплачено, доступ заблоковано» з посиланням на Конверт. */
	public static function send_blocked( $user_id, $service_id ) {
		$user    = get_userdata( $user_id );
		$service = UPB_Services::get( $service_id );
		if ( ! $user || ! $service ) {
			return false;
		}
		$def     = UPB_Services::defaults();
		$subject = ! empty( $service['block_subject'] ) ? $service['block_subject'] : $def['block_subject'];
		$body    = ! empty( $service['block_body'] ) ? $service['block_body'] : $def['block_body'];
		return self::send( $user, $service, $subject, $body );
	}
}
