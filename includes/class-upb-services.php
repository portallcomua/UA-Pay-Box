<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Зберігання "Послуг" (товарів) в опції upb_services.
 */
class UPB_Services {

	public static function all() {
		$all = get_option( 'upb_services', array() );
		return is_array( $all ) ? $all : array();
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	public static function defaults() {
		return array(
			'slug'            => '',
			'id_mode'         => 'login',
			'name'            => '',
			'price'           => '',
			'description'     => '',
			'url'             => '',
			'days'            => 30,
			'success_message' => 'Вітаю! Ваш PRO-тариф активовано.',
			'email_subject'   => 'Ваш тариф «{service}» скоро закінчується',
			'email_body'      => "Вітаємо, {username}!\n\nВаш тариф «{service}» закінчується через {days} (до {expires}).\nЩоб не втратити доступ, продовжіть його на сайті {site}.\n\nДякуємо, що ви з нами!",
			'block_subject'   => 'Послугу PRO «{service}» не сплачено — доступ заблоковано',
			'block_body'      => "Вітаємо, {username}!\n\nПослугу PRO для плагіна «{service}» не сплачено, тому її заблоковано.\nДля сплати перейдіть за посиланням:\n{pay_url}\n\nУ коментарі до платежу обов'язково вкажіть {id_word}: {username}\nПісля надходження коштів доступ буде відновлено.\n\n{site}",
			'enabled'         => 1,
		);
	}

	/** Санітизація даних з форми. */
	public static function sanitize( $in ) {
		$in   = wp_unslash( $in );
		$days = isset( $in['days'] ) ? absint( $in['days'] ) : 0;
		if ( $days < 1 ) {
			$days = 30;
		}
		if ( $days > 9999 ) {
			$days = 9999;
		}
		return array(
			'slug'            => sanitize_title( isset( $in['slug'] ) ? $in['slug'] : '' ),
			'id_mode'         => ( isset( $in['id_mode'] ) && 'domain' === $in['id_mode'] ) ? 'domain' : 'login',
			'name'            => sanitize_text_field( isset( $in['name'] ) ? $in['name'] : '' ),
			'price'           => sanitize_text_field( isset( $in['price'] ) ? $in['price'] : '' ),
			'description'     => sanitize_textarea_field( isset( $in['description'] ) ? $in['description'] : '' ),
			'url'             => esc_url_raw( trim( isset( $in['url'] ) ? $in['url'] : '' ), array( 'http', 'https' ) ),
			'days'            => $days,
			'success_message' => wp_kses_post( isset( $in['success_message'] ) ? $in['success_message'] : '' ),
			'email_subject'   => sanitize_text_field( isset( $in['email_subject'] ) ? $in['email_subject'] : '' ),
			'email_body'      => sanitize_textarea_field( isset( $in['email_body'] ) ? $in['email_body'] : '' ),
			'block_subject'   => sanitize_text_field( isset( $in['block_subject'] ) ? $in['block_subject'] : '' ),
			'block_body'      => sanitize_textarea_field( isset( $in['block_body'] ) ? $in['block_body'] : '' ),
			'enabled'         => ! empty( $in['enabled'] ) ? 1 : 0,
		);
	}

	/** Пошук проекту за slug плагіна. */
	public static function by_slug( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		foreach ( self::all() as $svc ) {
			if ( isset( $svc['slug'] ) && $svc['slug'] === $slug ) {
				return $svc;
			}
		}
		return null;
	}

	/** Автореєстрація проекту іншим плагіном (ідемпотентно). Повертає ID. */
	public static function register( $slug, $name, array $extra = array() ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return 0;
		}
		$exists = self::by_slug( $slug );
		if ( $exists ) {
			return (int) $exists['id'];
		}
		$data = wp_parse_args( $extra, self::defaults() );
		$data['slug'] = $slug;
		$data['name'] = sanitize_text_field( $name );
		return self::save( 0, $data );
	}

	/** Створення / оновлення. Повертає ID. */
	public static function save( $id, array $data ) {
		$all = self::all();
		$id  = (int) $id;
		if ( $id < 1 ) {
			$id = max( 1, (int) get_option( 'upb_next_id', 1 ) );
			while ( isset( $all[ $id ] ) ) {
				$id++;
			}
			update_option( 'upb_next_id', $id + 1, false );
		}
		$data['id'] = $id;
		$all[ $id ] = $data;
		update_option( 'upb_services', $all, false );
		return $id;
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ (int) $id ] );
		update_option( 'upb_services', $all, false );
	}
}
