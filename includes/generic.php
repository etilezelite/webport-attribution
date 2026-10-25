<?php
/**
 * Поддержка любых плагинов форм (с 1.2.0): Bricks, Fluent Forms и др.
 *
 * Поле: скрытое поле формы со значением по умолчанию __wpa_<key>__ (например __wpa_source__).
 * - JS (assets/wpa.js) заменяет маркер на значение в любом input[type=hidden] — уже работает без этого файла.
 * - Здесь: если JS не сработал, маркер в отправке заменяется значением из серверной cookie,
 *   до того как плагин форм прочитает данные. Маркер не попадает ни в заявку, ни в письмо.
 * - Письма: сводка «Путь к заявке» в любом письме сайта получает ссылки (HTML) или полные адреса (текст).
 * Forminator обрабатывается своим модулем (forminator.php), этот код ему не мешает.
 */

namespace Webport\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Заменить маркеры в массиве (значения со слешами, как в $_POST). Возвращает число замен. */
function scrub_array( array &$arr, ?array &$server ): int {
	$n = 0;
	foreach ( $arr as $k => $v ) {
		if ( is_array( $v ) ) {
			$n += scrub_array( $arr[ $k ], $server );
			continue;
		}
		if ( ! is_string( $v ) || false === strpos( $v, '__wpa_' ) ) {
			continue;
		}
		if ( preg_match( MARKER_RE, wp_unslash( $v ), $m ) ) {
			if ( null === $server ) {
				$server = server_values();
			}
			$arr[ $k ] = wp_slash( (string) ( $server[ $m[1] ] ?? '' ) );
			$n++;
		}
	}
	return $n;
}

/** Fluent Forms и похожие: все поля формы приходят одной url-кодированной строкой. */
function scrub_packed( string $packed, ?array &$server ): ?string {
	$raw = wp_unslash( $packed );
	if ( false === strpos( $raw, '__wpa_' ) && false === strpos( $raw, '%5F%5Fwpa_' ) ) {
		return null;
	}
	parse_str( $raw, $fields );
	if ( ! is_array( $fields ) ) {
		return null;
	}
	$slashed = wp_slash( $fields );
	if ( ! scrub_array( $slashed, $server ) ) {
		return null;
	}
	return wp_slash( http_build_query( wp_unslash( $slashed ) ) );
}

function scrub_request(): void {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST ) ) {
		return;
	}
	$server = null;
	scrub_array( $_POST, $server );
	if ( isset( $_POST['data'] ) && is_string( $_POST['data'] ) ) {
		$packed = scrub_packed( $_POST['data'], $server );
		if ( null !== $packed ) {
			$_POST['data'] = $packed;
		}
	}
	foreach ( $_POST as $k => $v ) {
		if ( isset( $_REQUEST[ $k ] ) ) {
			$_REQUEST[ $k ] = $v;
		}
	}
}
// Раньше обработчиков admin-ajax и REST любых плагинов форм.
add_action( 'init', __NAMESPACE__ . '\\scrub_request', 0 );

/** Письмо в HTML? */
function mail_is_html( array $args ): bool {
	$headers = $args['headers'] ?? array();
	$headers = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;
	if ( false !== stripos( $headers, 'text/html' ) ) {
		return true;
	}
	if ( false !== stripos( $headers, 'text/plain' ) ) {
		return false;
	}
	return (bool) preg_match( '#<(br|p|li|div|table)\b#i', (string) ( $args['message'] ?? '' ) );
}

add_filter(
	'wp_mail',
	static function ( $args ) {
		if ( ! is_array( $args ) || empty( $args['message'] ) || ! is_string( $args['message'] ) || ! apply_filters( 'wpa_mail_enabled', true ) ) {
			return $args;
		}
		$msg = $args['message'];
		if ( false !== strpos( $msg, 'wpa-link' ) ) {
			return $args; // уже обработано (Forminator)
		}
		$lab = labels();
		$src = preg_quote( $lab['source'], '#' );
		$pth = preg_quote( $lab['path'], '#' );
		// Сводка: «Источник: … | … | Путь: …» до конца строки или тега.
		$re = '#' . $src . ': [^\r\n<]*? \| ' . $pth . ': [^\r\n<]*#u';
		if ( ! preg_match( $re, $msg ) ) {
			return $args;
		}
		$html            = mail_is_html( $args );
		$args['message'] = preg_replace_callback(
			$re,
			static function ( $m ) use ( $html ) {
				return linkify_paths( $m[0], $html );
			},
			$msg
		);
		return $args;
	},
	20
);
