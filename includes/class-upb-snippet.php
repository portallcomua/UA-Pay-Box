<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Генератор коду інтеграції для кожного проекту.
 */
class UPB_Snippet {

	/** PHP для плагіна на БУДЬ-ЯКОМУ сайті: PRO визначається за доменом через REST вашого сервера Pay Box. */
	public static function php( array $svc ) {
		$id   = (int) $svc['id'];
		$slug = ! empty( $svc['slug'] ) ? $svc['slug'] : 'project-' . $id;
		$fn   = preg_replace( '/[^a-z0-9_]/', '_', strtolower( $slug ) );
		$name = addslashes( $svc['name'] );
		$hub  = untrailingslashit( home_url() );

		$tpl = <<<'TPL'
/* ===== Universal Pay Box — проект «%NAME%» (slug %SLUG%) =====
 * Вставте у головний файл плагіна. Працює на будь-якому сайті: PRO прив'язано до ДОМЕНУ,
 * статус перевіряється через REST вашого сервера Pay Box (%HUB%). */
if ( ! function_exists( '%FN%_is_pro' ) ) {

	function %FN%_hub() { return '%HUB%/wp-json/upb/v1/'; }

	function %FN%_domain() {
		return strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	}

	// Стан PRO цього сайту. Кеш 6 год.; якщо сервер недоступний — діє останній відомий стан до 7 діб.
	function %FN%_state( $force = false ) {
		$c = get_option( '%FN%_pro' );
		$c = is_array( $c ) ? $c : array( 'state' => 'free', 'expires' => 0, 'checked' => 0 );
		if ( $force || time() - (int) $c['checked'] > 6 * HOUR_IN_SECONDS ) {
			$r = wp_remote_get( add_query_arg( array( 'service' => '%SLUG%', 'domain' => %FN%_domain() ), %FN%_hub() . 'status' ), array( 'timeout' => 8 ) );
			if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
				$j = json_decode( wp_remote_retrieve_body( $r ), true );
				if ( is_array( $j ) && isset( $j['state'] ) ) {
					$c = array( 'state' => $j['state'], 'expires' => (int) $j['expires'], 'checked' => time() );
					update_option( '%FN%_pro', $c, false );
				}
			} elseif ( time() - (int) $c['checked'] > 7 * DAY_IN_SECONDS ) {
				$c['state'] = 'free';
			}
		}
		return $c;
	}

	// true — для цього домену є чинний PRO
	function %FN%_is_pro() {
		$c = %FN%_state();
		return in_array( $c['state'], array( 'active', 'expiring', 'unlimited' ), true ) && ( ! $c['expires'] || $c['expires'] > time() );
	}

	// Викличте при кліку «Купити PRO» і відкрийте у новій вкладці посилання на оплату — PRO вмикається одразу.
	// Повертає масив з ключем 'url' (Конверт) або WP_Error. У коментарі до платежу клієнт має вказати ДОМЕН.
	function %FN%_activate_pro() {
		$r = wp_remote_post( %FN%_hub() . 'activate', array( 'timeout' => 15, 'body' => array(
			'service' => '%SLUG%', 'domain' => %FN%_domain(), 'email' => get_option( 'admin_email' ), 'site_url' => home_url(),
		) ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) || ! is_array( $j ) ) {
			return new WP_Error( 'pay', isset( $j['message'] ) ? $j['message'] : 'Помилка сервера оплати' );
		}
		%FN%_state( true );
		return $j;
	}
}
TPL;
		return str_replace( array( '%FN%', '%SLUG%', '%NAME%', '%ID%', '%HUB%' ), array( $fn, $slug, $name, $id, $hub ), $tpl );
	}

	public static function html( array $svc ) {
		$id = (int) $svc['id'];
		return "<!-- Кнопка в HTML/грі (потрібні скрипти Pay Box на сторінці) -->\n<button data-upb-buy=\"{$id}\">Купити PRO</button>\n<script>/* або з JS: */ UPB.open({$id});</script>";
	}
}
