<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST: перевірка PRO для зовнішніх клієнтів (Android, Chrome, інші сайти).
 * GET /wp-json/upb/v1/check?service=<id|slug>&username=<login>&api_key=<ключ>
 */
class UPB_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function api_key() {
		$k = get_option( 'upb_api_key' );
		if ( ! $k ) {
			$k = wp_generate_password( 32, false, false );
			update_option( 'upb_api_key', $k, false );
		}
		return $k;
	}

	public static function routes() {
		register_rest_route( 'upb/v1', '/service', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'service' ) ) );
		register_rest_route( 'upb/v1', '/status', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'status' ) ) );
		register_rest_route( 'upb/v1', '/activate', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'activate' ) ) );
		register_rest_route(
			'upb/v1',
			'/check',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'check' ),
			)
		);
	}

	private static function svc_by_param( WP_REST_Request $req ) {
		$slug = sanitize_text_field( (string) $req->get_param( 'service' ) );
		$sid  = upb_service_id( $slug );
		return $sid ? UPB_Services::get( $sid ) : null;
	}

	/** GET service — публічні дані проекту для вікна оплати. */
	public static function service( WP_REST_Request $req ) {
		$svc = self::svc_by_param( $req );
		if ( ! $svc || empty( $svc['enabled'] ) ) {
			return new WP_REST_Response( array( 'message' => 'Проект не знайдено на сервері Pay Box.' ), 404 );
		}
		$pricing = UPB_Freemius::service_pricing( $svc );
		$checkout = UPB_Freemius::checkout_url( $svc );
		$locale = sanitize_text_field( (string) $req->get_param( 'locale' ) );
		$is_uk = 0 === strpos( strtolower( $locale ), 'uk' );
		$price_display = $is_uk && ! empty( $pricing['uah_price'] )
			? number_format( (float) $pricing['uah_price'], 0, '.', ' ' ) . ' грн'
			: ( ! empty( $pricing['usd_price'] ) ? chr( 36 ) . number_format( (float) $pricing['usd_price'], 2, '.', '' ) : ( ! empty( $svc['freemius_product_id'] ) ? '' : (string) $svc['price'] ) );
		return new WP_REST_Response(
			array(
				'id' => (int) $svc['id'],
				'slug' => $svc['slug'],
				'name' => $svc['name'],
				'price' => $price_display,
				'price_usd' => (float) $pricing['usd_price'],
				'price_uah' => (int) $pricing['uah_price'],
				'currency' => $pricing['currency'],
				'exchange_rate' => (float) $pricing['exchange_rate'],
				'price_updated' => (int) $pricing['updated_at'],
				'url' => $checkout ? $checkout : $svc['url'],
				'checkout_url' => $checkout,
				'days' => (int) $svc['days'],
				'lifetime' => ! empty( $svc['freemius_product_id'] ) && ! empty( $svc['freemius_plan_id'] ),
				'id_mode' => $svc['id_mode'],
			),
			200
		);
	}

	/** GET status — стан PRO домену. */
	public static function status( WP_REST_Request $req ) {
		$svc    = self::svc_by_param( $req );
		$domain = UPB_Util::clean_domain( $req->get_param( 'domain' ) );
		if ( ! $svc || ! $domain ) {
			return new WP_REST_Response( array( 'message' => 'Невірний проект або домен.' ), 400 );
		}
		$st = UPB_Remote::state( (int) $svc['id'], $domain );
		return new WP_REST_Response(
			array(
				'state'     => $st['state'],
				'premium'   => in_array( $st['state'], array( 'active', 'expiring', 'unlimited' ), true ),
				'expires'   => (int) $st['expires'],
				'days_left' => (int) $st['days_left'],
				'url'       => $svc['url'],
			),
			200
		);
	}

	/** POST activate — миттєва активація PRO для домену (оплату перевіряє адмін). */
	public static function activate( WP_REST_Request $req ) {
		$svc    = self::svc_by_param( $req );
		$domain = UPB_Util::clean_domain( $req->get_param( 'domain' ) );
		if ( ! $svc || empty( $svc['enabled'] ) || ! $domain ) {
			return new WP_REST_Response( array( 'message' => 'Невірний проект або домен.' ), 400 );
		}
		if ( empty( $svc['url'] ) ) {
			return new WP_REST_Response( array( 'message' => 'Адміністратор ще не вказав посилання на оплату.' ), 409 );
		}
		$rl = 'upb_rl_' . md5( $domain . $svc['id'] );
		if ( get_transient( $rl ) ) {
			return new WP_REST_Response( array( 'message' => 'Забагато запитів. Спробуйте за хвилину.' ), 429 );
		}
		set_transient( $rl, 1, 20 );
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		$res   = UPB_Remote::activate( $svc, $domain, $email );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'message' => $res->get_error_message() ), 409 );
		}
		if ( UPB_Settings::get( 'notify_admin' ) ) {
			wp_mail(
				get_option( 'admin_email' ),
				'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Новий PRO: ' . $domain,
				"Сайт «{$domain}» ({$email}) натиснув оплату послуги «{$svc['name']}» ({$svc['price']}).\nPRO активовано миттєво. Перевірте надходження в банку (в коментарі має бути домен) і за потреби заблокуйте: " . admin_url( 'admin.php?page=upb-premiums' )
			);
		}
		$st = UPB_Remote::state( (int) $svc['id'], $domain );
		return new WP_REST_Response(
			array(
				'state'   => $st['state'],
				'premium' => true,
				'expires' => (int) $res['expires'],
				'url'     => $svc['url'],
			),
			200
		);
	}

	public static function check( WP_REST_Request $req ) {
		$key = (string) $req->get_param( 'api_key' );
		if ( ! $key || ! hash_equals( self::api_key(), $key ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid api_key' ), 403 );
		}
		$sid = upb_service_id( sanitize_text_field( (string) $req->get_param( 'service' ) ) );
		if ( ! $sid ) {
			return new WP_REST_Response( array( 'error' => 'service not found' ), 404 );
		}
		$user = get_user_by( 'login', sanitize_user( (string) $req->get_param( 'username' ), true ) );
		if ( ! $user ) {
			return new WP_REST_Response( array( 'error' => 'user not found' ), 404 );
		}
		$st = UPB_Premium::state( $user->ID, $sid );
		return new WP_REST_Response(
			array(
				'premium'    => in_array( $st['state'], array( 'active', 'expiring', 'unlimited' ), true ),
				'state'      => $st['state'],
				'expires_at' => $st['expires'] ? gmdate( 'c', $st['expires'] ) : null,
				'days_left'  => $st['days_left'],
			),
			200
		);
	}
}
 . number_format( (float) $pricing['usd_price'], 2, '.', '' ) : (string) $svc['price'] );
		return new WP_REST_Response(
			array(
				'id' => (int) $svc['id'], 'slug' => $svc['slug'], 'name' => $svc['name'],
				'price' => $price_display, 'price_usd' => (float) $pricing['usd_price'],
				'price_uah' => (int) $pricing['uah_price'], 'currency' => $pricing['currency'],
				'exchange_rate' => (float) $pricing['exchange_rate'], 'price_updated' => (int) $pricing['updated_at'],
				'url' => $checkout ? $checkout : $svc['url'], 'checkout_url' => $checkout,
				'days' => (int) $svc['days'], 'lifetime' => ! empty( $svc['freemius_product_id'] ) && ! empty( $svc['freemius_plan_id'] ),
				'id_mode' => $svc['id_mode'],
			), 200
		);
	}

	/** GET status — стан PRO домену. */
	public static function status( WP_REST_Request $req ) {
		$svc    = self::svc_by_param( $req );
		$domain = UPB_Util::clean_domain( $req->get_param( 'domain' ) );
		if ( ! $svc || ! $domain ) {
			return new WP_REST_Response( array( 'message' => 'Невірний проект або домен.' ), 400 );
		}
		$st = UPB_Remote::state( (int) $svc['id'], $domain );
		return new WP_REST_Response(
			array(
				'state'     => $st['state'],
				'premium'   => in_array( $st['state'], array( 'active', 'expiring', 'unlimited' ), true ),
				'expires'   => (int) $st['expires'],
				'days_left' => (int) $st['days_left'],
				'url'       => $svc['url'],
			),
			200
		);
	}

	/** POST activate — миттєва активація PRO для домену (оплату перевіряє адмін). */
	public static function activate( WP_REST_Request $req ) {
		$svc    = self::svc_by_param( $req );
		$domain = UPB_Util::clean_domain( $req->get_param( 'domain' ) );
		if ( ! $svc || empty( $svc['enabled'] ) || ! $domain ) {
			return new WP_REST_Response( array( 'message' => 'Невірний проект або домен.' ), 400 );
		}
		if ( empty( $svc['url'] ) ) {
			return new WP_REST_Response( array( 'message' => 'Адміністратор ще не вказав посилання на оплату.' ), 409 );
		}
		$rl = 'upb_rl_' . md5( $domain . $svc['id'] );
		if ( get_transient( $rl ) ) {
			return new WP_REST_Response( array( 'message' => 'Забагато запитів. Спробуйте за хвилину.' ), 429 );
		}
		set_transient( $rl, 1, 20 );
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		$res   = UPB_Remote::activate( $svc, $domain, $email );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'message' => $res->get_error_message() ), 409 );
		}
		if ( UPB_Settings::get( 'notify_admin' ) ) {
			wp_mail(
				get_option( 'admin_email' ),
				'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Новий PRO: ' . $domain,
				"Сайт «{$domain}» ({$email}) натиснув оплату послуги «{$svc['name']}» ({$svc['price']}).\nPRO активовано миттєво. Перевірте надходження в банку (в коментарі має бути домен) і за потреби заблокуйте: " . admin_url( 'admin.php?page=upb-premiums' )
			);
		}
		$st = UPB_Remote::state( (int) $svc['id'], $domain );
		return new WP_REST_Response(
			array(
				'state'   => $st['state'],
				'premium' => true,
				'expires' => (int) $res['expires'],
				'url'     => $svc['url'],
			),
			200
		);
	}

	public static function check( WP_REST_Request $req ) {
		$key = (string) $req->get_param( 'api_key' );
		if ( ! $key || ! hash_equals( self::api_key(), $key ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid api_key' ), 403 );
		}
		$sid = upb_service_id( sanitize_text_field( (string) $req->get_param( 'service' ) ) );
		if ( ! $sid ) {
			return new WP_REST_Response( array( 'error' => 'service not found' ), 404 );
		}
		$user = get_user_by( 'login', sanitize_user( (string) $req->get_param( 'username' ), true ) );
		if ( ! $user ) {
			return new WP_REST_Response( array( 'error' => 'user not found' ), 404 );
		}
		$st = UPB_Premium::state( $user->ID, $sid );
		return new WP_REST_Response(
			array(
				'premium'    => in_array( $st['state'], array( 'active', 'expiring', 'unlimited' ), true ),
				'state'      => $st['state'],
				'expires_at' => $st['expires'] ? gmdate( 'c', $st['expires'] ) : null,
				'days_left'  => $st['days_left'],
			),
			200
		);
	}
}
 . number_format( (float) $pricing['usd_price'], 2, '.', '' ) : ( ( ! empty( $svc['freemius_product_id'] ) ) ? '' : (string) $svc['price'] ) );
		return new WP_REST_Response(
			array(
				'id' => (int) $svc['id'],
				'slug' => $svc['slug'],
				'name' => $svc['name'],
				'price' => $price_display,
				'price_usd' => (float) $pricing['usd_price'],
				'price_uah' => (int) $pricing['uah_price'],
				'currency' => $pricing['currency'],
				'exchange_rate' => (float) $pricing['exchange_rate'],
				'price_updated' => (int) $pricing['updated_at'],
				'url' => $checkout ? $checkout : $svc['url'],
				'checkout_url' => $checkout,
				'days' => (int) $svc['days'],
				'lifetime' => ! empty( $svc['freemius_product_id'] ) && ! empty( $svc['freemius_plan_id'] ),
				'id_mode' => $svc['id_mode'],
			),
			200
		);
	}

	/** GET status — стан PRO домену. */
	public static function status( WP_REST_Request $req ) {
		$svc    = self::svc_by_param( $req );
		$domain = UPB_Util::clean_domain( $req->get_param( 'domain' ) );
		if ( ! $svc || ! $domain ) {
			return new WP_REST_Response( array( 'message' => 'Невірний проект або домен.' ), 400 );
		}
		$st = UPB_Remote::state( (int) $svc['id'], $domain );
		return new WP_REST_Response(
			array(
				'state'     => $st['state'],
				'premium'   => in_array( $st['state'], array( 'active', 'expiring', 'unlimited' ), true ),
				'expires'   => (int) $st['expires'],
				'days_left' => (int) $st['days_left'],
				'url'       => $svc['url'],
			),
			200
		);
	}

	/** POST activate — миттєва активація PRO для домену (оплату перевіряє адмін). */
	public static function activate( WP_REST_Request $req ) {
		$svc    = self::svc_by_param( $req );
		$domain = UPB_Util::clean_domain( $req->get_param( 'domain' ) );
		if ( ! $svc || empty( $svc['enabled'] ) || ! $domain ) {
			return new WP_REST_Response( array( 'message' => 'Невірний проект або домен.' ), 400 );
		}
		if ( empty( $svc['url'] ) ) {
			return new WP_REST_Response( array( 'message' => 'Адміністратор ще не вказав посилання на оплату.' ), 409 );
		}
		$rl = 'upb_rl_' . md5( $domain . $svc['id'] );
		if ( get_transient( $rl ) ) {
			return new WP_REST_Response( array( 'message' => 'Забагато запитів. Спробуйте за хвилину.' ), 429 );
		}
		set_transient( $rl, 1, 20 );
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		$res   = UPB_Remote::activate( $svc, $domain, $email );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'message' => $res->get_error_message() ), 409 );
		}
		if ( UPB_Settings::get( 'notify_admin' ) ) {
			wp_mail(
				get_option( 'admin_email' ),
				'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Новий PRO: ' . $domain,
				"Сайт «{$domain}» ({$email}) натиснув оплату послуги «{$svc['name']}» ({$svc['price']}).\nPRO активовано миттєво. Перевірте надходження в банку (в коментарі має бути домен) і за потреби заблокуйте: " . admin_url( 'admin.php?page=upb-premiums' )
			);
		}
		$st = UPB_Remote::state( (int) $svc['id'], $domain );
		return new WP_REST_Response(
			array(
				'state'   => $st['state'],
				'premium' => true,
				'expires' => (int) $res['expires'],
				'url'     => $svc['url'],
			),
			200
		);
	}

	public static function check( WP_REST_Request $req ) {
		$key = (string) $req->get_param( 'api_key' );
		if ( ! $key || ! hash_equals( self::api_key(), $key ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid api_key' ), 403 );
		}
		$sid = upb_service_id( sanitize_text_field( (string) $req->get_param( 'service' ) ) );
		if ( ! $sid ) {
			return new WP_REST_Response( array( 'error' => 'service not found' ), 404 );
		}
		$user = get_user_by( 'login', sanitize_user( (string) $req->get_param( 'username' ), true ) );
		if ( ! $user ) {
			return new WP_REST_Response( array( 'error' => 'user not found' ), 404 );
		}
		$st = UPB_Premium::state( $user->ID, $sid );
		return new WP_REST_Response(
			array(
				'premium'    => in_array( $st['state'], array( 'active', 'expiring', 'unlimited' ), true ),
				'state'      => $st['state'],
				'expires_at' => $st['expires'] ? gmdate( 'c', $st['expires'] ) : null,
				'days_left'  => $st['days_left'],
			),
			200
		);
	}
}
 . number_format( (float) $pricing['usd_price'], 2, '.', '' ) : (string) $svc['price'] );
		return new WP_REST_Response(
			array(
				'id' => (int) $svc['id'], 'slug' => $svc['slug'], 'name' => $svc['name'],
				'price' => $price_display, 'price_usd' => (float) $pricing['usd_price'],
				'price_uah' => (int) $pricing['uah_price'], 'currency' => $pricing['currency'],
				'exchange_rate' => (float) $pricing['exchange_rate'], 'price_updated' => (int) $pricing['updated_at'],
				'url' => $checkout ? $checkout : $svc['url'], 'checkout_url' => $checkout,
				'days' => (int) $svc['days'], 'lifetime' => ! empty( $svc['freemius_product_id'] ) && ! empty( $svc['freemius_plan_id'] ),
				'id_mode' => $svc['id_mode'],
			), 200
		);
	}

	/** GET status — стан PRO домену. */
	public static function status( WP_REST_Request $req ) {
		$svc    = self::svc_by_param( $req );
		$domain = UPB_Util::clean_domain( $req->get_param( 'domain' ) );
		if ( ! $svc || ! $domain ) {
			return new WP_REST_Response( array( 'message' => 'Невірний проект або домен.' ), 400 );
		}
		$st = UPB_Remote::state( (int) $svc['id'], $domain );
		return new WP_REST_Response(
			array(
				'state'     => $st['state'],
				'premium'   => in_array( $st['state'], array( 'active', 'expiring', 'unlimited' ), true ),
				'expires'   => (int) $st['expires'],
				'days_left' => (int) $st['days_left'],
				'url'       => $svc['url'],
			),
			200
		);
	}

	/** POST activate — миттєва активація PRO для домену (оплату перевіряє адмін). */
	public static function activate( WP_REST_Request $req ) {
		$svc    = self::svc_by_param( $req );
		$domain = UPB_Util::clean_domain( $req->get_param( 'domain' ) );
		if ( ! $svc || empty( $svc['enabled'] ) || ! $domain ) {
			return new WP_REST_Response( array( 'message' => 'Невірний проект або домен.' ), 400 );
		}
		if ( empty( $svc['url'] ) ) {
			return new WP_REST_Response( array( 'message' => 'Адміністратор ще не вказав посилання на оплату.' ), 409 );
		}
		$rl = 'upb_rl_' . md5( $domain . $svc['id'] );
		if ( get_transient( $rl ) ) {
			return new WP_REST_Response( array( 'message' => 'Забагато запитів. Спробуйте за хвилину.' ), 429 );
		}
		set_transient( $rl, 1, 20 );
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		$res   = UPB_Remote::activate( $svc, $domain, $email );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'message' => $res->get_error_message() ), 409 );
		}
		if ( UPB_Settings::get( 'notify_admin' ) ) {
			wp_mail(
				get_option( 'admin_email' ),
				'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Новий PRO: ' . $domain,
				"Сайт «{$domain}» ({$email}) натиснув оплату послуги «{$svc['name']}» ({$svc['price']}).\nPRO активовано миттєво. Перевірте надходження в банку (в коментарі має бути домен) і за потреби заблокуйте: " . admin_url( 'admin.php?page=upb-premiums' )
			);
		}
		$st = UPB_Remote::state( (int) $svc['id'], $domain );
		return new WP_REST_Response(
			array(
				'state'   => $st['state'],
				'premium' => true,
				'expires' => (int) $res['expires'],
				'url'     => $svc['url'],
			),
			200
		);
	}

	public static function check( WP_REST_Request $req ) {
		$key = (string) $req->get_param( 'api_key' );
		if ( ! $key || ! hash_equals( self::api_key(), $key ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid api_key' ), 403 );
		}
		$sid = upb_service_id( sanitize_text_field( (string) $req->get_param( 'service' ) ) );
		if ( ! $sid ) {
			return new WP_REST_Response( array( 'error' => 'service not found' ), 404 );
		}
		$user = get_user_by( 'login', sanitize_user( (string) $req->get_param( 'username' ), true ) );
		if ( ! $user ) {
			return new WP_REST_Response( array( 'error' => 'user not found' ), 404 );
		}
		$st = UPB_Premium::state( $user->ID, $sid );
		return new WP_REST_Response(
			array(
				'premium'    => in_array( $st['state'], array( 'active', 'expiring', 'unlimited' ), true ),
				'state'      => $st['state'],
				'expires_at' => $st['expires'] ? gmdate( 'c', $st['expires'] ) : null,
				'days_left'  => $st['days_left'],
			),
			200
		);
	}
}
