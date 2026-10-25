<?php
/**
 * Подключение скрипта на фронте. Конфиг статичен (без nonce), поэтому совместим с кешем страниц.
 */

namespace Webport\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}
		if ( ! apply_filters( 'wpa_enabled', true ) ) {
			return;
		}
		$s   = settings();
		$cfg = array(
			'rest'       => esc_url_raw( rest_url( 'wpa/v1/' ) ),
			'consent'    => consent_mode(),
			'pathLen'    => $s['path_len'],
			'sessionMin' => 30,
			'tz'         => wp_timezone_string(),
			'labels'     => labels(),
		);
		wp_register_script(
			'wpa',
			WPA_URL . 'assets/wpa.js',
			array(),
			WPA_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( 'wpa', 'window.wpaCfg=' . wp_json_encode( $cfg ) . ';', 'before' );
		wp_enqueue_script( 'wpa' );
	}
);
