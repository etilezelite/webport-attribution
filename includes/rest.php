<?php
/**
 * REST: синхронизация состояния с серверной cookie на год (защита от урезания хранилища в Safari).
 * POST /wp-json/wpa/v1/s  { state, fresh, consent }  → { ok, state }
 * POST /wp-json/wpa/v1/x  → удалить серверную cookie (отзыв согласия)
 */

namespace Webport\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'wpa/v1',
			'/s',
			array(
				'methods'             => 'POST',
				'callback'            => __NAMESPACE__ . '\\rest_sync',
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'wpa/v1',
			'/x',
			array(
				'methods'             => 'POST',
				'callback'            => __NAMESPACE__ . '\\rest_forget',
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * Только JSON: такой запрос с чужого сайта требует CORS-preflight и браузер его не пропустит.
 */
function rest_guard( \WP_REST_Request $req ) {
	$type = (string) $req->get_header( 'content_type' );
	if ( false === stripos( $type, 'application/json' ) ) {
		return new \WP_Error( 'wpa_type', 'JSON only', array( 'status' => 415 ) );
	}
	if ( strlen( (string) $req->get_body() ) > 16384 ) {
		return new \WP_Error( 'wpa_size', 'Too large', array( 'status' => 413 ) );
	}
	return true;
}

function no_cache( $data ) {
	$res = rest_ensure_response( $data );
	$res->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	return $res;
}

function rest_sync( \WP_REST_Request $req ) {
	$guard = rest_guard( $req );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}
	$body = $req->get_json_params();
	$body = is_array( $body ) ? $body : array();

	$GLOBALS['wpa_manual_consent'] = ! empty( $body['consent'] );
	if ( ! server_consent_ok() ) {
		return no_cache( array( 'ok' => false, 'reason' => 'consent' ) );
	}

	$client = clean_state( $body['state'] ?? null );
	if ( ! $client ) {
		return no_cache( array( 'ok' => false, 'reason' => 'state' ) );
	}
	$server = read_cookie();
	$merged = $server ? merge( $client, $server, ! empty( $body['fresh'] ) ) : $client;
	$merged = write_cookie( $merged );

	return no_cache( array( 'ok' => true, 'state' => $merged ) );
}

function rest_forget( \WP_REST_Request $req ) {
	$guard = rest_guard( $req );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}
	clear_cookie();
	return no_cache( array( 'ok' => true ) );
}
