<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPB_Frontend {

	private static $needed = false;
	private static $toast  = '';

	public static function init() {
		add_shortcode( 'universal_pay_box', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 5 );
		// Кнопки «Купити PRO» працюють і в wp-admin (плагіни з панеллю в адмінці).
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'admin_footer', array( __CLASS__, 'footer' ), 5 );
	}

	/** Підключити CSS/JS (викликається шорткодом або іншими плагінами). */
	public static function need() {
		if ( self::$needed ) {
			return;
		}
		self::$needed = true;
		wp_enqueue_style( 'upb-frontend', UPB_URL . 'assets/css/frontend.css', array(), UPB_VERSION );
		wp_enqueue_script( 'upb-frontend', UPB_URL . 'assets/js/frontend.js', array(), UPB_VERSION, true );
	}

	public static function maybe_enqueue() {
		if ( UPB_Settings::get( 'load_everywhere' ) ) {
			self::need();
			return;
		}
		if ( is_user_logged_in() && get_transient( 'upb_msg_' . get_current_user_id() ) ) {
			self::need();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Шорткод [universal_pay_box id="1"]                                  */
	/* ------------------------------------------------------------------ */
	public static function shortcode( $atts ) {
		$a   = shortcode_atts( array( 'id' => 0, 'button' => 'Купити Преміум' ), $atts, 'universal_pay_box' );
		$id  = (int) $a['id'];
		$svc = UPB_Services::get( $id );

		if ( ! $svc || empty( $svc['enabled'] ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<div class="upb-card upb-card--error">Pay Box: послугу з ID ' . esc_html( $id ) . ' не знайдено або вимкнено.</div>';
			}
			return '';
		}

		self::need();

		$uid   = get_current_user_id();
		$st    = $uid ? UPB_Premium::state( $uid, $id ) : array( 'state' => 'guest', 'expires' => 0, 'days_left' => 0 );
		$days  = (int) $svc['days'];
		$term  = $days >= 9999 ? 'Безстроково' : UPB_Util::days_word( $days );

		ob_start();
		?>
		<div class="upb-card" data-upb-service="<?php echo esc_attr( $id ); ?>">

			<?php if ( 'expiring' === $st['state'] ) : ?>
				<div class="upb-warning">
					⚠️ Ваш тариф закінчується через <?php echo esc_html( UPB_Util::days_word( $st['days_left'] ) ); ?>.
					Для продовження доступу, будь ласка,
					<a href="#" class="upb-link" data-upb-buy="<?php echo esc_attr( $id ); ?>">натисніть сюди для повторної оплати</a>.
				</div>
			<?php endif; ?>

			<h3 class="upb-title"><?php echo esc_html( $svc['name'] ); ?></h3>
			<?php if ( '' !== $svc['price'] ) : ?>
				<div class="upb-price"><?php echo esc_html( $svc['price'] ); ?></div>
			<?php endif; ?>
			<div class="upb-term">Термін доступу: <strong><?php echo esc_html( $term ); ?></strong></div>
			<?php if ( ! empty( $svc['description'] ) ) : ?>
				<div class="upb-desc"><?php echo nl2br( esc_html( $svc['description'] ) ); ?></div>
			<?php endif; ?>

			<?php if ( 'blocked' === $st['state'] ) : ?>
				<div class="upb-status upb-status--blocked">⛔ Доступ заблоковано адміністратором. Зверніться до адміністрації сайту.</div>

			<?php elseif ( 'unlimited' === $st['state'] ) : ?>
				<div class="upb-status upb-status--ok">✅ PRO активний — безстроково</div>

			<?php elseif ( 'active' === $st['state'] ) : ?>
				<div class="upb-status upb-status--ok">✅ PRO активний до <?php echo esc_html( UPB_Util::fmt_ts( $st['expires'] ) ); ?></div>

			<?php elseif ( 'expiring' === $st['state'] ) : ?>
				<div class="upb-status upb-status--ok">✅ PRO активний до <?php echo esc_html( UPB_Util::fmt_ts( $st['expires'] ) ); ?></div>
				<button type="button" class="upb-btn" data-upb-buy="<?php echo esc_attr( $id ); ?>">Продовжити Преміум</button>

			<?php elseif ( 'guest' === $st['state'] ) : ?>
				<a class="upb-btn" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">Увійдіть, щоб купити</a>

			<?php else : ?>
				<button type="button" class="upb-btn" data-upb-buy="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $a['button'] ); ?></button>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return esc_url_raw( set_url_scheme( '//' . $host . $uri ) );
	}

	/* ------------------------------------------------------------------ */
	/* Футер: дані для JS, модальне вікно, повідомлення про активацію      */
	/* ------------------------------------------------------------------ */
	public static function footer() {
		if ( ! self::$needed ) {
			return;
		}

		$uid  = get_current_user_id();
		$user = $uid ? wp_get_current_user() : null;

		$services = array();
		foreach ( UPB_Services::all() as $id => $svc ) {
			if ( empty( $svc['enabled'] ) ) {
				continue;
			}
			$st      = $uid ? UPB_Premium::state( $uid, $id ) : array( 'state' => 'guest', 'days_left' => 0 );
			$can_buy = in_array( $st['state'], array( 'free', 'expiring' ), true );
			$services[ $id ] = array(
				'name'     => $svc['name'],
				'price'    => $svc['price'],
				'state'    => $st['state'],
				'idMode'   => ( isset( $svc['id_mode'] ) && 'domain' === $svc['id_mode'] ) ? 'domain' : 'login',
				'daysLeft' => (int) $st['days_left'],
				'url'      => $can_buy ? $svc['url'] : '',
				'checkoutUrl' => $can_buy && ! empty( $svc['freemius_checkout_url'] ) && ! empty( $svc['freemius_product_id'] ) && ! empty( $svc['freemius_plan_id'] ) && ! empty( $svc['freemius_secret_key'] ) ? $svc['freemius_checkout_url'] : '',
			);
		}

		wp_localize_script(
			'upb-frontend',
			'UPB',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'upb_activate' ),
				'loggedIn' => (bool) $uid,
				'loginUrl' => wp_login_url( self::current_url() ),
				'login'    => $user ? $user->user_login : '',
				'domain'   => UPB_Util::site_domain(),
				'services' => (object) $services,
			)
		);

		if ( $uid ) {
			$msg = get_transient( 'upb_msg_' . $uid );
			if ( $msg ) {
				delete_transient( 'upb_msg_' . $uid );
				self::$toast = $msg;
			}
		}
		?>
		<div class="upb-modal" id="upb-modal" hidden>
			<div class="upb-modal__backdrop" data-upb-close></div>
			<div class="upb-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="upb-modal-title">
				<button type="button" class="upb-modal__x" data-upb-close aria-label="Закрити">&times;</button>
				<h3 class="upb-modal__title" id="upb-modal-title">Оплата: <span data-upb-svc></span></h3>

				<div class="upb-modal__msg" data-upb-msg hidden></div>

				<div class="upb-modal__body" data-upb-body>
					<div class="upb-modal__price" data-upb-price></div>
					<div data-upb-envelope>
					<p class="upb-modal__label" data-upb-idlabel>Ваш логін на сайті:</p>
					<div class="upb-login-row">
						<strong class="upb-login" data-upb-login></strong>
						<button type="button" class="upb-copy" data-upb-copy>📋 Скопіювати логін</button>
					</div>
					<p class="upb-modal__note">
						<strong>Обов'язково</strong> вставте скопійований <span data-upb-idword>логін</span> у поле «Коментар» або «Призначення платежу» на сторінці банку (ПриватБанк / Монобанк).
						Це необхідно для верифікації вашого платежу адміном. Платежі без вказаного <span data-upb-idword2>логіна</span> будуть заблоковані.
					</p>
					<p class="upb-modal__hint">PRO активується одразу. Якщо платіж не надійде — адмін вимкне доступ.</p>
					<button type="button" class="upb-go" data-upb-go disabled>Перейти до Конверта →</button>
					</div>
					<a class="upb-go upb-go--freemius" data-upb-freemius href="#" target="_blank" rel="noopener noreferrer" hidden>Оплатити через Freemius →</a>
				</div>
			</div>
		</div>
		<?php if ( self::$toast ) : ?>
			<div class="upb-toast" role="status">
				<span class="upb-toast__text"><?php echo wp_kses_post( self::$toast ); ?></span>
				<button type="button" class="upb-toast__x" data-upb-toast-close aria-label="Закрити">&times;</button>
			</div>
		<?php endif;
	}
}
