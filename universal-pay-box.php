<?php
/**
 * Plugin Name: UA Pay Box
 * Plugin URI:  https://uaserver.pp.ua
 * Description: Платіжний центр для WordPress, Android і Chrome: PrivatBank/Monobank Конверт та Freemius Checkout без SDK, миттєва активація Конверта і перевірка ліцензій через webhook.
 * Version:     1.3.6
 * Author:      UAServer
 * Author URI:  https://uaserver.pp.ua
 * License:     GPLv2 or later
 * Requires PHP: 7.4
 * Text Domain: ua-pay-box
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UPB_VERSION', '1.3.6' );
define( 'UPB_GITHUB', 'portallcomua/UA-Pay-Box' );
define( 'UPB_FILE', __FILE__ );
define( 'UPB_DIR', plugin_dir_path( __FILE__ ) );
define( 'UPB_URL', plugin_dir_url( __FILE__ ) );

require_once UPB_DIR . 'includes/class-upb-util.php';
require_once UPB_DIR . 'includes/class-upb-settings.php';
require_once UPB_DIR . 'includes/class-upb-installer.php';
require_once UPB_DIR . 'includes/class-upb-services.php';
require_once UPB_DIR . 'includes/class-upb-premium.php';
require_once UPB_DIR . 'includes/class-upb-remote.php';
require_once UPB_DIR . 'includes/class-upb-mail.php';
require_once UPB_DIR . 'includes/class-upb-cron.php';
require_once UPB_DIR . 'includes/class-upb-snippet.php';
require_once UPB_DIR . 'includes/class-upb-updater.php';
require_once UPB_DIR . 'includes/class-upb-ajax.php';
require_once UPB_DIR . 'includes/class-upb-frontend.php';
require_once UPB_DIR . 'includes/class-upb-rest.php';
require_once UPB_DIR . 'includes/class-upb-freemius.php';
require_once UPB_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'UPB_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'UPB_Installer', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
	UPB_Installer::maybe_upgrade();
	UPB_Cron::init();
	UPB_Ajax::init();
	UPB_Frontend::init();
	UPB_Rest::init();
	UPB_Freemius::init();
	new UPB_Updater( UPB_FILE, UPB_GITHUB, UPB_VERSION );
	if ( is_admin() ) {
		require_once UPB_DIR . 'includes/class-upb-admin.php';
		UPB_Admin::init();
	}
} );
