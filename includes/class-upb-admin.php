<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPB_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_upb_save_service', array( __CLASS__, 'save_service' ) );
		add_action( 'admin_post_upb_delete_service', array( __CLASS__, 'delete_service' ) );
		add_action( 'admin_post_upb_block', array( __CLASS__, 'block' ) );
		add_action( 'admin_post_upb_unblock', array( __CLASS__, 'unblock' ) );
		add_action( 'admin_post_upb_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'missing_url_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( UPB_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=upb-services' ) ) . '">Послуги</a>' );
		return $links;
	}

	/** Нагадування: проекти, створені плагінами автоматично, без посилання на Конверт. */
	public static function missing_url_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$missing = array();
		foreach ( UPB_Services::all() as $s ) {
			$freemius_ready = ! empty( $s['freemius_checkout_url'] ) && ! empty( $s['freemius_product_id'] ) && ! empty( $s['freemius_plan_id'] ) && ! empty( $s['freemius_secret_key'] );
			if ( ! empty( $s['enabled'] ) && empty( $s['url'] ) && ! $freemius_ready ) {
				$missing[] = esc_html( $s['name'] );
			}
		}
		if ( $missing ) {
			printf(
				'<div class="notice notice-warning"><p><strong>UA Pay Box:</strong> вкажіть посилання на Конверт або Freemius Checkout та вартість для: %s. <a href="%s">Відкрити послуги</a></p></div>',
				implode( ', ', $missing ), // phpcs:ignore -- уже екрановано вище
				esc_url( admin_url( 'admin.php?page=upb-services' ) )
			);
		}
	}

	public static function menu() {
		add_menu_page( 'Контроль Преміумів', 'UA Pay Box', 'manage_options', 'upb-premiums', array( __CLASS__, 'page_premiums' ), 'dashicons-awards', 58 );
		add_submenu_page( 'upb-premiums', 'Контроль Преміумів', 'Контроль Преміумів', 'manage_options', 'upb-premiums', array( __CLASS__, 'page_premiums' ) );
		add_submenu_page( 'upb-premiums', 'Послуги', 'Послуги', 'manage_options', 'upb-services', array( __CLASS__, 'page_services' ) );
		add_submenu_page( 'upb-premiums', 'Налаштування', 'Налаштування', 'manage_options', 'upb-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'upb' ) ) {
			return;
		}
		wp_enqueue_style( 'upb-admin', UPB_URL . 'assets/css/admin.css', array(), UPB_VERSION );
	}

	private static function url( $page, array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) );
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостатньо прав.' );
		}
	}

	private static function redirect_back( $fallback_page, $notice, array $extra = array() ) {
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = self::url( $fallback_page );
		}
		$back = remove_query_arg( array( 'upb_notice', 'action', 'id' ), $back );
		wp_safe_redirect( add_query_arg( array_merge( array( 'upb_notice' => $notice ), $extra ), $back ) );
		exit;
	}

	private static function notice() {
		$map = array(
			'blocked'       => array( 'updated', 'PRO вимкнено, користувача заблоковано.' ),
			'unblocked'     => array( 'updated', 'Користувача розблоковано, PRO відновлено.' ),
			'saved'         => array( 'updated', 'Послугу збережено.' ),
			'deleted'       => array( 'updated', 'Послугу видалено.' ),
			'settings'      => array( 'updated', 'Налаштування збережено.' ),
			'err_required'  => array( 'error', 'Заповніть назву послуги та хоча б одне посилання для оплати: Конверт або Freemius Checkout.' ),
			'save_failed'  => array( 'error', 'Помилка збереження послуги. Дані не підтверджені після запису в базу WordPress. Перевірте права бази даних або журнал помилок.' ),
		);
		$key = isset( $_GET['upb_notice'] ) ? sanitize_key( wp_unslash( $_GET['upb_notice'] ) ) : '';
		if ( isset( $map[ $key ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( 'error' === $map[ $key ][0] ? 'error' : 'success' ), esc_html( $map[ $key ][1] ) );
		}
	}

	/* ================================================================== */
	/* Контроль Преміумів                                                  */
	/* ================================================================== */
	public static function page_premiums() {
		self::guard();
		global $wpdb;
		$table = UPB_Installer::table();
		$per   = 50;
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$s     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$where = '1=1';
		if ( '' !== $s ) {
			$ids   = get_users( array( 'search' => '*' . $s . '*', 'search_columns' => array( 'user_login', 'user_email', 'display_name' ), 'fields' => 'ID', 'number' => 500 ) );
			$ids   = array_map( 'absint', $ids );
			$like  = '%' . $wpdb->esc_like( $s ) . '%';
			$where = $ids ? 'user_id IN (' . implode( ',', $ids ) . ')' : '1=0';
			$where = '(' . $where . $wpdb->prepare( ' OR domain LIKE %s OR email LIKE %s', $like, $like ) . ')';
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY clicked_at DESC, id DESC LIMIT %d OFFSET %d", $per, ( $paged - 1 ) * $per ) );
		$pages = (int) ceil( $total / $per );

		$labels = array(
			'active'    => array( 'Активний', 'upb-b-active' ),
			'blocked'   => array( 'Заблоковано', 'upb-b-blocked' ),
			'expired'   => array( 'Закінчився', 'upb-b-grey' ),
			'renewed'   => array( 'Продовжено', 'upb-b-grey' ),
			'unblocked' => array( 'Розблоковано', 'upb-b-grey' ),
		);
		?>
		<div class="wrap upb-wrap">
			<h1>Контроль Преміумів</h1>
			<?php self::notice(); ?>
			<p>Нові кліки зверху. Перевірте надходження в Приват24 / Monobank: якщо коштів за логін немає — натисніть червону кнопку, і користувач одразу повернеться на FREE.</p>

			<form method="get" class="upb-search">
				<input type="hidden" name="page" value="upb-premiums">
				<input type="search" name="s" value="<?php echo esc_attr( $s ); ?>" placeholder="Логін або email">
				<button class="button">Пошук</button>
				<?php if ( '' !== $s ) : ?><a class="button-link" href="<?php echo esc_url( self::url( 'upb-premiums' ) ); ?>">Скинути</a><?php endif; ?>
			</form>

			<table class="widefat striped upb-table">
				<thead>
					<tr>
						<th>Дата кліку</th>
						<th>Логін</th>
						<th>Email</th>
						<th>Домен</th>
						<th>Послуга</th>
						<th>Діє до</th>
						<th>Статус</th>
						<th>Дія</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="8">Записів поки немає.</td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) :
					$u = get_userdata( (int) $r->user_id );
					$l = isset( $labels[ $r->status ] ) ? $labels[ $r->status ] : array( $r->status, 'upb-b-grey' );
					$is_remote = ! (int) $r->user_id && '' !== $r->domain;
					$can_act   = $u || $is_remote;
					?>
					<tr>
						<td><?php echo esc_html( UPB_Util::fmt_gmt( $r->clicked_at ) ); ?></td>
						<td>
							<?php if ( $u ) : ?>
								<strong><a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>"><?php echo esc_html( $u->user_login ); ?></a></strong>
							<?php elseif ( $is_remote ) : ?>
								<em>🌐 віддалений сайт</em>
							<?php else : ?>
								<em>(користувача видалено)</em>
							<?php endif; ?>
						</td>
						<td><?php echo $u ? esc_html( $u->user_email ) : esc_html( $r->email ? $r->email : '—' ); ?></td>
						<td><strong><?php echo esc_html( $r->domain ? $r->domain : '—' ); ?></strong></td>
						<td><?php echo esc_html( $r->service_name ); ?> <span class="upb-id">ID <?php echo (int) $r->service_id; ?></span></td>
						<td><?php echo (int) $r->expires_at ? esc_html( UPB_Util::fmt_ts( $r->expires_at ) ) : 'Безстроково'; ?></td>
						<td><span class="upb-badge <?php echo esc_attr( $l[1] ); ?>"><?php echo esc_html( $l[0] ); ?></span></td>
						<td>
							<?php if ( $can_act && 'active' === $r->status ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Вимкнути PRO і заблокувати цього користувача?');">
									<input type="hidden" name="action" value="upb_block">
									<input type="hidden" name="log_id" value="<?php echo (int) $r->id; ?>">
									<?php wp_nonce_field( 'upb_block_' . (int) $r->id ); ?>
									<button type="submit" class="upb-btn-block">❌ Відключити PRO / Заблокувати</button>
								</form>
							<?php elseif ( $can_act && 'blocked' === $r->status ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="upb_unblock">
									<input type="hidden" name="log_id" value="<?php echo (int) $r->id; ?>">
									<?php wp_nonce_field( 'upb_unblock_' . (int) $r->id ); ?>
									<button type="submit" class="upb-btn-unblock">✅ Розблокувати (повернути PRO)</button>
								</form>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '«',
								'next_text' => '»',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function block() {
		self::guard();
		$log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		check_admin_referer( 'upb_block_' . $log_id );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id, service_id, domain FROM ' . UPB_Installer::table() . ' WHERE id=%d', $log_id ) );
		if ( $row && (int) $row->user_id ) {
			UPB_Premium::block( (int) $row->user_id, (int) $row->service_id );
		} elseif ( $row && $row->domain ) {
			UPB_Remote::block( (int) $row->service_id, $row->domain );
		}
		self::redirect_back( 'upb-premiums', 'blocked' );
	}

	public static function unblock() {
		self::guard();
		$log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		check_admin_referer( 'upb_unblock_' . $log_id );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id, service_id, domain FROM ' . UPB_Installer::table() . ' WHERE id=%d', $log_id ) );
		if ( $row && (int) $row->user_id ) {
			UPB_Premium::unblock( (int) $row->user_id, (int) $row->service_id );
		} elseif ( $row && $row->domain ) {
			UPB_Remote::unblock( (int) $row->service_id, $row->domain );
		}
		self::redirect_back( 'upb-premiums', 'unblocked' );
	}

	/* ================================================================== */
	/* Послуги                                                             */
	/* ================================================================== */
	public static function page_services() {
		self::guard();
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'add' === $action || 'edit' === $action ) {
			self::service_form( 'edit' === $action && isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
			return;
		}
		$services = UPB_Services::all();
		ksort( $services );
		?>
		<div class="wrap upb-wrap">
			<h1 class="wp-heading-inline">Послуги</h1>
			<a href="<?php echo esc_url( self::url( 'upb-services', array( 'action' => 'add' ) ) ); ?>" class="page-title-action">Додати послугу</a>
			<hr class="wp-header-end">
			<?php self::notice(); ?>

			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Назва / плагін</th><th>Вартість</th><th>Термін</th><th>Шорткод</th><th>PHP-перевірка</th><th>Статус</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $services ) : ?>
					<tr><td colspan="8">Послуг ще немає. Натисніть «Додати послугу».</td></tr>
				<?php endif; ?>
				<?php foreach ( $services as $id => $s ) : ?>
					<tr>
						<td><strong><?php echo (int) $id; ?></strong></td>
						<td><?php echo esc_html( $s['name'] ); ?><?php if ( ! empty( $s['slug'] ) ) : ?><br><span class="upb-id"><?php echo esc_html( $s['slug'] ); ?></span><?php endif; ?><?php if ( empty( $s['url'] ) ) : ?><br><strong style="color:#b32d2e">⚠️ немає посилання на Конверт</strong><?php endif; ?></td>
						<td><?php echo esc_html( $s['price'] ); ?></td>
						<td><?php echo (int) $s['days'] >= 9999 ? 'Безстроково' : esc_html( UPB_Util::days_word( $s['days'] ) ); ?></td>
						<td><input type="text" readonly class="upb-code" value="[universal_pay_box id=&quot;<?php echo (int) $id; ?>&quot;]" onclick="this.select()"></td>
						<td><input type="text" readonly class="upb-code" value="wcc_check_user_premium($user_id, <?php echo (int) $id; ?>)" onclick="this.select()"></td>
						<td><?php echo ! empty( $s['enabled'] ) ? '🟢 Увімкнено' : '⚪ Вимкнено'; ?></td>
						<td class="upb-actions">
							<a href="<?php echo esc_url( self::url( 'upb-services', array( 'action' => 'edit', 'id' => $id ) ) ); ?>">Редагувати</a> |
							<a class="upb-del" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=upb_delete_service&id=' . (int) $id ), 'upb_delete_' . (int) $id ) ); ?>" onclick="return confirm('Видалити послугу? Користувачі, які вже мають PRO, збережуть його до кінця терміну.');">Видалити</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function service_form( $id ) {
		$svc = $id ? UPB_Services::get( $id ) : null;
		if ( $id && ! $svc ) {
			echo '<div class="wrap"><p>Послугу не знайдено.</p></div>';
			return;
		}
		$v = $svc ? wp_parse_args( $svc, UPB_Services::defaults() ) : UPB_Services::defaults();
		?>
		<div class="wrap upb-wrap">
			<h1><?php echo $id ? 'Редагувати послугу #' . (int) $id : 'Нова послуга'; ?></h1>
			<?php self::notice(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="upb_save_service">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'upb_save_service_' . (int) $id ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="upb-slug">Slug плагіна</label></th>
						<td><input id="upb-slug" name="svc[slug]" type="text" class="regular-text code" value="<?php echo esc_attr( $v['slug'] ); ?>" placeholder="voice-add-woocommerce">
						<p class="description">Ідентифікатор для підключення плагіна (латиницею). Плагіни Voice ADD реєструють проект самі — тут він уже буде заповнений.</p></td>
					</tr>
					<tr>
						<th><label for="upb-idmode">Що клієнт вказує в коментарі до платежу</label></th>
						<td><select id="upb-idmode" name="svc[id_mode]">
							<option value="login" <?php selected( $v['id_mode'], 'login' ); ?>>Логін на сайті (плагіни цього сайту)</option>
							<option value="domain" <?php selected( $v['id_mode'], 'domain' ); ?>>Домен сайту (плагіни на чужих сайтах)</option>
						</select>
						<p class="description">Для плагінів Voice ADD, які ставляться на сайти клієнтів, обирайте «Домен» — логіни там майже завжди «admin».</p></td>
					</tr>
					<tr>
						<th><label for="upb-name">Назва послуги *</label></th>
						<td><input id="upb-name" name="svc[name]" type="text" class="regular-text" required value="<?php echo esc_attr( $v['name'] ); ?>" placeholder="Шахи ПРО: Пожиттєвий"></td>
					</tr>
					<tr>
						<th><label for="upb-price">Вартість (текст)</label></th>
						<td><input id="upb-price" name="svc[price]" type="text" class="regular-text" value="<?php echo esc_attr( $v['price'] ); ?>" placeholder="100 грн"></td>
					</tr>
					<tr>
						<th><label for="upb-desc">Короткий опис (на картці)</label></th>
						<td><textarea id="upb-desc" name="svc[description]" rows="3" class="large-text"><?php echo esc_textarea( $v['description'] ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="upb-url">Посилання на Конверт</label></th>
						<td><input id="upb-url" name="svc[url]" type="url" class="large-text" value="<?php echo esc_attr( $v['url'] ); ?>" placeholder="https://www.privat24.ua/send/…">
						<p class="description">Необов'язково, якщо для цього проекту використовується Freemius. При переході до Конверта PRO активується одразу, як і раніше.</p></td>
					</tr>
					<tr>
						<th colspan="2"><h2>Freemius Checkout (без SDK)</h2><p class="description">Платіж проходить у Freemius. Після підтвердження Freemius надсилає підписаний webhook до Pay Box, а ліцензія стає доступною для перевірки у WordPress, Android та Chrome.</p></th>
					</tr>
					<tr>
						<th><label for="upb-fs-product">Freemius Product ID</label></th>
						<td><input id="upb-fs-product" name="svc[freemius_product_id]" type="text" class="regular-text code" value="<?php echo esc_attr( $v['freemius_product_id'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="upb-fs-plan">Freemius Plan ID</label></th>
						<td><input id="upb-fs-plan" name="svc[freemius_plan_id]" type="text" class="regular-text code" value="<?php echo esc_attr( $v['freemius_plan_id'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="upb-fs-checkout">Freemius Checkout URL</label></th>
						<td><input id="upb-fs-checkout" name="svc[freemius_checkout_url]" type="url" class="large-text" value="<?php echo esc_attr( $v['freemius_checkout_url'] ); ?>" placeholder="https://checkout.freemius.com/..."></td>
					</tr>
					<tr>
						<th><label for="upb-fs-secret">Freemius Product Secret Key</label></th>
						<td><input id="upb-fs-secret" name="svc[freemius_secret_key]" type="password" class="large-text code" value="" autocomplete="new-password" placeholder="<?php echo ! empty( $v['freemius_secret_key'] ) ? 'Ключ збережено — залиште порожнім, щоб не змінювати' : 'Вставте Product Secret Key'; ?>">
						<p class="description">Зберігається тільки на сервері Pay Box. Ніколи не потрапляє в код для плагінів або додатків.</p></td>
					</tr>
					<tr>
						<th><label for="upb-days">Термін дії (днів)</label></th>
						<td><input id="upb-days" name="svc[days]" type="number" min="1" max="9999" value="<?php echo (int) $v['days']; ?>">
						<p class="description">30 = місяць, 365 = рік, <strong>9999 = безліміт</strong>.</p></td>
					</tr>
					<tr>
						<th><label for="upb-msg">Повідомлення після активації</label></th>
						<td><textarea id="upb-msg" name="svc[success_message]" rows="3" class="large-text"><?php echo esc_textarea( $v['success_message'] ); ?></textarea>
						<p class="description">Можна використовувати {service} і {expires}. Дозволено простий HTML.</p></td>
					</tr>
					<tr>
						<th><label for="upb-subj">Email: тема нагадування</label></th>
						<td><input id="upb-subj" name="svc[email_subject]" type="text" class="large-text" value="<?php echo esc_attr( $v['email_subject'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="upb-body">Email: текст нагадування</label></th>
						<td><textarea id="upb-body" name="svc[email_body]" rows="7" class="large-text"><?php echo esc_textarea( $v['email_body'] ); ?></textarea>
						<p class="description">Плейсхолдери: <code>{username}</code> <code>{service}</code> <code>{days}</code> <code>{expires}</code> <code>{site}</code>. Лист надходить один раз, коли до кінця тарифу лишається не більше N днів (див. «Налаштування»).</p></td>
					</tr>
					<tr>
						<th><label for="upb-bsubj">Лист про блокування: тема</label></th>
						<td><input id="upb-bsubj" name="svc[block_subject]" type="text" class="large-text" value="<?php echo esc_attr( $v['block_subject'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="upb-bbody">Лист про блокування: текст</label></th>
						<td><textarea id="upb-bbody" name="svc[block_body]" rows="8" class="large-text"><?php echo esc_textarea( $v['block_body'] ); ?></textarea>
						<p class="description">Надсилається автоматично, коли ви натискаєте «Відключити PRO / Заблокувати». Плейсхолдери: <code>{username}</code> (логін або домен) <code>{id_word}</code> («логін»/«домен») <code>{domain}</code> <code>{service}</code> <code>{pay_url}</code> (посилання на Конверт) <code>{site}</code>.</p></td>
					</tr>
					<tr>
						<th>Статус</th>
						<td><label><input type="checkbox" name="svc[enabled]" value="1" <?php checked( ! empty( $v['enabled'] ) ); ?>> Послуга увімкнена (доступна для купівлі)</label></td>
					</tr>
				</table>
				<?php submit_button( 'Зберегти послугу' ); ?>
				<a href="<?php echo esc_url( self::url( 'upb-services' ) ); ?>">← До списку</a>
			</form>

			<?php if ( $id && $svc ) : $svc['id'] = $id; ?>
				<hr>
				<h2>🔌 Код інтеграції для цього проекту</h2>
				<p>Вставте в головний файл будь-якого вашого WordPress-плагіна (плагіни Voice ADD вже мають його вбудованим). Код сформовано автоматично саме для «<?php echo esc_html( $svc['name'] ); ?>».</p>
				<textarea readonly rows="22" class="large-text code" onclick="this.select()"><?php echo esc_textarea( UPB_Snippet::php( $svc ) ); ?></textarea>
				<h3>HTML / JavaScript (гра, фронтенд)</h3>
				<textarea readonly rows="4" class="large-text code" onclick="this.select()"><?php echo esc_textarea( UPB_Snippet::html( $svc ) ); ?></textarea>
				<h3>REST (Android, Chrome-розширення, інші сайти)</h3>
				<textarea readonly rows="2" class="large-text code" onclick="this.select()"><?php echo esc_textarea( rest_url( 'upb/v1/check' ) . '?service=' . ( ! empty( $svc['slug'] ) ? $svc['slug'] : $id ) . '&username=LOGIN&api_key=' . UPB_Rest::api_key() ); ?></textarea>
				<?php if ( ! empty( $svc['freemius_checkout_url'] ) && ! empty( $svc['freemius_product_id'] ) && ! empty( $svc['freemius_plan_id'] ) && ! empty( $svc['freemius_secret_key'] ) ) : ?>
					<hr>
					<h2>🔐 Freemius — код перевірки ліцензії</h2>
					<p>Ці фрагменти не містять Product Secret Key. Користувач вводить ліцензійний ключ, який отримав після купівлі у Freemius.</p>
					<h3>WordPress / PHP</h3>
					<textarea readonly rows="22" class="large-text code" onclick="this.select()"><?php echo esc_textarea( UPB_Snippet::freemius_php( $svc ) ); ?></textarea>
					<h3>Android / Kotlin</h3>
					<textarea readonly rows="18" class="large-text code" onclick="this.select()"><?php echo esc_textarea( UPB_Snippet::android_kotlin( $svc ) ); ?></textarea>
					<h3>Chrome Extension / JavaScript</h3>
					<textarea readonly rows="14" class="large-text code" onclick="this.select()"><?php echo esc_textarea( UPB_Snippet::chrome_js( $svc ) ); ?></textarea>
					<p><strong>REST URL:</strong> <code><?php echo esc_html( rest_url( 'upb/v1/license/status' ) ); ?></code></p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $id && ! empty( $svc['freemius_product_id'] ) && ! empty( $svc['freemius_plan_id'] ) && ! empty( $svc['freemius_secret_key'] ) ) : ?>
				<h3>Freemius Webhook URL</h3>
				<p><input type="text" readonly class="large-text code" onclick="this.select()" value="<?php echo esc_attr( rest_url( 'upb/v1/freemius/webhook/' . (int) $id ) ); ?>"></p>
				<p class="description">Додайте цей URL у Webhooks Freemius для подій ліцензій. Pay Box перевіряє підпис x-signature через Product Secret Key.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function save_service() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'upb_save_service_' . $id );

		$raw = isset( $_POST['svc'] ) && is_array( $_POST['svc'] ) ? wp_unslash( $_POST['svc'] ) : array();
		$old = $id ? UPB_Services::get( $id ) : null;
		$data = UPB_Services::sanitize( $raw );

		// Preserve existing values when a browser or proxy omits an unchanged field.
		if ( $old ) {
			$data = array_merge( UPB_Services::defaults(), $old, $data );
		}
		if ( '' === $data['freemius_secret_key'] && $old && ! empty( $old['freemius_secret_key'] ) ) {
			$data['freemius_secret_key'] = $old['freemius_secret_key'];
		}

		$freemius_ready = '' !== $data['freemius_checkout_url'] && '' !== $data['freemius_product_id'] && '' !== $data['freemius_plan_id'] && '' !== $data['freemius_secret_key'];
		if ( '' === $data['name'] || ( '' === $data['url'] && ! $freemius_ready ) ) {
			wp_safe_redirect( self::url( 'upb-services', $id ? array( 'action' => 'edit', 'id' => $id, 'upb_notice' => 'err_required' ) : array( 'action' => 'add', 'upb_notice' => 'err_required' ) ) );
			exit;
		}

		if ( $id && ! $old ) {
			$id = 0;
		}
		$saved_id = UPB_Services::save( $id, $data );
		$saved = UPB_Services::get( $saved_id );

		// Verify that the critical Freemius fields survived the database round-trip.
		$verified = is_array( $saved )
			&& (string) $saved['freemius_product_id'] === (string) $data['freemius_product_id']
			&& (string) $saved['freemius_plan_id'] === (string) $data['freemius_plan_id']
			&& (string) $saved['freemius_checkout_url'] === (string) $data['freemius_checkout_url']
			&& (string) $saved['freemius_secret_key'] === (string) $data['freemius_secret_key'];

		if ( ! $verified ) {
			wp_safe_redirect( self::url( 'upb-services', array( 'action' => 'edit', 'id' => $saved_id, 'upb_notice' => 'save_failed' ) ) );
			exit;
		}

		// Return to the same service so the administrator can immediately confirm saved values.
		wp_safe_redirect( self::url( 'upb-services', array( 'action' => 'edit', 'id' => $saved_id, 'upb_notice' => 'saved' ) ) );
		exit;
	}

	public static function delete_service() {
		self::guard();
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'upb_delete_' . $id );
		UPB_Services::delete( $id );
		wp_safe_redirect( self::url( 'upb-services', array( 'upb_notice' => 'deleted' ) ) );
		exit;
	}

	/* ================================================================== */
	/* Налаштування + довідка                                              */
	/* ================================================================== */
	public static function page_settings() {
		self::guard();
		$o = UPB_Settings::all();
		?>
		<div class="wrap upb-wrap">
			<h1>Налаштування Pay Box</h1>
			<?php self::notice(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="upb_save_settings">
				<?php wp_nonce_field( 'upb_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="upb-warn">Попереджати за (днів)</label></th>
						<td><input id="upb-warn" type="number" min="1" max="60" name="opt[warn_days]" value="<?php echo (int) $o['warn_days']; ?>">
						<p class="description">Жовте попередження в картці та лист-нагадування за стільки днів до кінця тарифу.</p></td>
					</tr>
					<tr>
						<th>Лист адміну</th>
						<td><label><input type="checkbox" name="opt[notify_admin]" value="1" <?php checked( ! empty( $o['notify_admin'] ) ); ?>> Надсилати мені лист при кожній активації (щоб перевірити оплату)</label></td>
					</tr>
					<tr>
						<th>Скрипти на всіх сторінках</th>
						<td><label><input type="checkbox" name="opt[load_everywhere]" value="1" <?php checked( ! empty( $o['load_everywhere'] ) ); ?>> Завантажувати JS/CSS на всьому сайті</label>
						<p class="description">Потрібно, якщо кнопки <code>data-upb-buy</code> стоять усередині гри/іншого плагіна, а не в шорткоді. Інакше можна викликати <code>upb_enqueue_assets()</code> у коді.</p></td>
					</tr>
					<tr>
						<th>Видалення плагіна</th>
						<td><label><input type="checkbox" name="opt[delete_data]" value="1" <?php checked( ! empty( $o['delete_data'] ) ); ?>> Видалити ВСІ дані (послуги, журнал, PRO-статуси) при видаленні плагіна</label></td>
					</tr>
				</table>
				<?php submit_button( 'Зберегти' ); ?>
			</form>

			<hr>
			<h2>Ключ REST API</h2>
			<p><input type="text" readonly class="regular-text code" value="<?php echo esc_attr( UPB_Rest::api_key() ); ?>" onclick="this.select()"> — для перевірки PRO зовнішніми клієнтами: <code>/wp-json/upb/v1/check</code>.</p>
			<hr>
			<h2>Довідка для розробників</h2>
			<p><strong>Перевірка PRO в іншому плагіні (PHP):</strong></p>
			<pre class="upb-pre">if ( function_exists( 'wcc_check_user_premium' ) &amp;&amp; wcc_check_user_premium( get_current_user_id(), 1 ) ) {
    // користувач має PRO по послузі ID 1
}
// Кнопка «Купити PRO» (працює і в wp-admin):
echo upb_buy_button( 1, 'Купити PRO' );</pre>
			<p>Готовий код для кожного проекту: <strong>UA Pay Box → Послуги → Редагувати</strong> (блок «Код інтеграції»).</p>
			<p><strong>Кнопка «Купити» всередині гри / будь-якого HTML:</strong></p>
			<pre class="upb-pre">&lt;button data-upb-buy="1"&gt;Купити PRO&lt;/button&gt;
&lt;script&gt; UPB.open(1); &lt;/script&gt;   // або викликом з JS</pre>
			<p>Хуки: <code>upb_premium_activated</code>, <code>upb_premium_blocked</code>, <code>upb_premium_unblocked</code>, <code>upb_premium_expired</code>, фільтр <code>upb_user_is_premium</code>. Також є <code>wcc_get_user_premium_expiry($user_id, $service_id)</code>.</p>
		</div>
		<?php
	}

	public static function save_settings() {
		self::guard();
		check_admin_referer( 'upb_save_settings' );
		$in   = isset( $_POST['opt'] ) && is_array( $_POST['opt'] ) ? wp_unslash( $_POST['opt'] ) : array();
		$warn = isset( $in['warn_days'] ) ? absint( $in['warn_days'] ) : 10;
		$warn = min( 60, max( 1, $warn ) );
		update_option(
			'upb_settings',
			array(
				'warn_days'       => $warn,
				'notify_admin'    => ! empty( $in['notify_admin'] ) ? 1 : 0,
				'load_everywhere' => ! empty( $in['load_everywhere'] ) ? 1 : 0,
				'delete_data'     => ! empty( $in['delete_data'] ) ? 1 : 0,
			),
			false
		);
		wp_safe_redirect( self::url( 'upb-settings', array( 'upb_notice' => 'settings' ) ) );
		exit;
	}
}
