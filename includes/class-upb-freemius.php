<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Freemius bridge without Freemius SDK.
 * Freemius sends signed webhooks; clients validate license keys against this Pay Box.
 */
class UPB_Freemius {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'license_cors' ), 10, 4 );
	}

	/** Allow browser extensions to call the public license-status endpoint. */
	public static function license_cors( $served, $result, $request, $server ) {
		if ( '/upb/v1/license/status' === $request->get_route() ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type' );
		}
		return $served;
	}

	public static function routes() {
		register_rest_route( 'upb/v1', '/freemius/webhook/(?P<service_id>\\d+)', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'webhook' ),
		) );
		register_rest_route( 'upb/v1', '/license/status', array(
			'methods'             => array( 'GET', 'POST' ),
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'license_status' ),
		) );
	}

	private static function license_table() {
		global $wpdb;
		return $wpdb->prefix . 'upb_licenses';
	}

	private static function value( $array, $path, $default = '' ) {
		$parts = explode( '.', $path );
		$node  = $array;
		foreach ( $parts as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return $default;
			}
			$node = $node[ $part ];
		}
		return $node;
	}

	private static function timestamp( $value ) {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$time = strtotime( $value );
			return $time ? $time : 0;
		}
		return 0;
	}

	/** Signed webhook endpoint. Product Secret Key is never sent to client apps. */
	public static function webhook( WP_REST_Request $request ) {
		$service_id = absint( $request['service_id'] );
		$service    = UPB_Services::get( $service_id );
		if ( ! $service || empty( $service['enabled'] ) || empty( $service['freemius_secret_key'] ) ) {
			return new WP_REST_Response( array( 'message' => 'Freemius integration is not configured.' ), 404 );
		}

		$raw       = $request->get_body();
		$signature = trim( (string) $request->get_header( 'x-signature' ) );
		$signature = preg_replace( '/^sha256=/i', '', $signature );
		$expected  = hash_hmac( 'sha256', $raw, $service['freemius_secret_key'] );
		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid webhook signature.' ), 401 );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid JSON payload.' ), 400 );
		}

		$product_id = (string) self::value( $data, 'plugin_id', self::value( $data, 'plugin.id', '' ) );
		$event_data = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
		$plan_id    = (string) self::value( $event_data, 'plan_id', self::value( $event_data, 'plan.id', self::value( $event_data, 'license.plan_id', self::value( $data, 'plan_id', '' ) ) ) );
		if ( ! empty( $service['freemius_product_id'] ) && $product_id !== (string) $service['freemius_product_id'] ) {
			return new WP_REST_Response( array( 'message' => 'Product ID does not match this Pay Box service.' ), 403 );
		}
		if ( ! empty( $service['freemius_plan_id'] ) && $plan_id !== (string) $service['freemius_plan_id'] ) {
			return new WP_REST_Response( array( 'message' => 'Plan ID does not match this Pay Box service.' ), 403 );
		}

		$event   = strtolower( (string) self::value( $data, 'type', self::value( $data, 'event.type', self::value( $data, 'action', $request->get_header( 'x-event' ) ) ) ) );
		$license = isset( $event_data['license'] ) && is_array( $event_data['license'] ) ? $event_data['license'] : $event_data;
		$license_id = (string) self::value( $license, 'id', self::value( $event_data, 'license_id', self::value( $data, 'license_id', '' ) ) );
		if ( '' === $license_id ) {
			return new WP_REST_Response( array( 'message' => 'License ID is missing.' ), 400 );
		}

		$revoked = ( false !== strpos( $event, 'cancel' ) || false !== strpos( $event, 'delete' ) || false !== strpos( $event, 'expir' ) || false !== strpos( $event, 'refund' ) || false !== strpos( $event, 'deactiv' ) || false !== strpos( $event, 'dispute.lost' ) || ! empty( $license['is_cancelled'] ) || ! empty( $license['is_deleted'] ) );
		$expires = self::timestamp( self::value( $license, 'expiration', self::value( $license, 'expiration_date', self::value( $license, 'expires_at', 0 ) ) ) );
		if ( $expires && $expires <= time() ) {
			$revoked = true;
		}
		$key = (string) self::value( $license, 'secret_key', self::value( $license, 'license_key', self::value( $license, 'key', '' ) ) );
		$email = sanitize_email( (string) self::value( $data, 'user.email', self::value( $event_data, 'user.email', self::value( $event_data, 'email', self::value( $license, 'email', '' ) ) ) ) );
		$status = $revoked ? 'revoked' : 'active';

		global $wpdb;
		$table = self::license_table();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE service_id=%d AND license_id=%s", $service_id, $license_id ) );
		$record = array(
			'service_id' => $service_id,
			'license_id' => sanitize_text_field( $license_id ),
			'email' => $email,
			'status' => $status,
			'expires' => $expires,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( $key ) {
			$record['license_hash'] = hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
		} elseif ( ! $existing ) {
			$record['license_hash'] = '';
		}
		if ( $existing ) {
			$wpdb->update( $table, $record, array( 'id' => (int) $existing ) );
		} else {
			$record['created_at'] = current_time( 'mysql', true );
			$wpdb->insert( $table, $record );
		}

		return new WP_REST_Response( array( 'received' => true, 'license_status' => $status ), 200 );
	}

	/** Public status check: the license key itself is the credential. */
	public static function license_status( WP_REST_Request $request ) {
		$service_param = sanitize_text_field( (string) $request->get_param( 'service' ) );
		$service_id    = upb_service_id( $service_param );
		$key           = trim( (string) $request->get_param( 'license_key' ) );
		if ( ! $service_id || '' === $key ) {
			return new WP_REST_Response( array( 'valid' => false, 'message' => 'Service and license_key are required.' ), 400 );
		}
		$hash = hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
		global $wpdb;
		$table = self::license_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, expires FROM {$table} WHERE service_id=%d AND license_hash=%s ORDER BY id DESC LIMIT 1", $service_id, $hash ) );
		$valid = $row && 'active' === $row->status && ( ! (int) $row->expires || (int) $row->expires > time() );
		return new WP_REST_Response( array(
			'valid' => (bool) $valid,
			'status' => $row ? $row->status : 'not_found',
			'expires' => $row && (int) $row->expires ? (int) $row->expires : 0,
		), 200 );
	}
}
