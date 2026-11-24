<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPB_Ajax {

	public static function init() {
// Legacy Privat/Monobank click-to-activate endpoints are intentionally disabled.
	}

	public static function guest() {
		wp_send_json_error( array( 'message' => 'Спочатку увійдіть на сайт.' ), 401 );
	}

	public static function activate() {
		if ( ! check_ajax_referer( 'upb_activate', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Сесія застаріла. Оновіть сторінку і спробуйте ще раз.' ), 403 );
		}
		$uid = get_current_user_id();
		if ( ! $uid ) {
			self::guest();
		}

		$sid     = isset( $_POST['service_id'] ) ? absint( $_POST['service_id'] ) : 0;
		$service = UPB_Services::get( $sid );
		if ( ! $service || empty( $service['enabled'] ) ) {
			wp_send_json_error( array( 'message' => 'Послугу не знайдено.' ), 404 );
		}
		if ( empty( $service['url'] ) ) {
			wp_send_json_error( array( 'message' => 'Адміністратор ще не вказав посилання на оплату для цієї послуги.' ) );
		}

		$res = UPB_Premium::activate( $uid, $service );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		// Повідомлення про успіх — показується після перезавантаження сторінки.
		$user = wp_get_current_user();
		$msg  = ! empty( $service['success_message'] ) ? $service['success_message'] : UPB_Services::defaults()['success_message'];
		$msg  = UPB_Util::replace_tokens(
			$msg,
			array(
				'service' => esc_html( $service['name'] ),
				'expires' => $res['expires'] ? UPB_Util::fmt_ts( $res['expires'] ) : 'безстроково',
			)
		);
		set_transient( 'upb_msg_' . $uid, $msg, 10 * MINUTE_IN_SECONDS );

		// Лист адміну: перевірити оплату.
		if ( UPB_Settings::get( 'notify_admin' ) ) {
			wp_mail(
				get_option( 'admin_email' ),
				'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Новий PRO: ' . $user->user_login,
				"Користувач «{$user->user_login}» ({$user->user_email}) натиснув оплату послуги «{$service['name']}» ({$service['price']}).\nPRO активовано миттєво. Перевірте надходження в банку (логін має бути в коментарі) і за потреби заблокуйте: " . admin_url( 'admin.php?page=upb-premiums' )
			);
		}

		do_action( 'upb_premium_activated', $uid, $sid, $res );

		wp_send_json_success( array( 'url' => $service['url'] ) );
	}
}
