<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy click-to-activate AJAX actions were removed.
 * Freemius Checkout and signed license webhooks are the only PRO activation flow.
 */
class UPB_Ajax {
	public static function init() {
		// No public activation endpoint is registered here.
	}
}
