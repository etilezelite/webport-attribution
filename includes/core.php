<?php
/**
 * Ядро: настройки, состояние посетителя, серверная cookie, расчёт значений полей.
 * Логика расчёта значений повторяет assets/wpa.js (функции values/summary) — менять синхронно.
 */

namespace Webport\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const COOKIE      = 'wpa_s';   // HttpOnly, состояние на год.
const COOKIE_FLAG = 'wpa_on';  // Видимый JS флаг «серверная cookie есть» — чтобы удалить её при отзыве согласия.
const COOKIE_MAX  = 3800;      // Байт, с запасом до лимита 4096.

/** Ключи полей. В Forminator: Hidden → Query parameter → ключ wpa_<key>. */
const KEYS = array(
	'source', 'medium', 'campaign', 'content', 'term',
	'first_source', 'first_medium', 'first_campaign',
	'landing', 'last_landing', 'referrer', 'first_referrer',
	'click_id', 'gclid', 'yclid', 'fbclid',
	'ym_uid', 'ga_cid', 'fbp', 'fbc',
	'visits', 'days', 'first_date', 'path',
);

/** Существующие поля «Query parameter» с такими ключами можно подхватить (опция alias_utm). */
const ALIASES = array(
	'utm_source'   => 'source',
	'utm_medium'   => 'medium',
	'utm_campaign' => 'campaign',
	'utm_content'  => 'content',
	'utm_term'     => 'term',
	'gclid'        => 'gclid',
	'yclid'        => 'yclid',
	'fbclid'       => 'fbclid',
);

function settings(): array {
	$defaults = array(
		'consent'   => 'auto',  // auto | none | moove:thirdparty | moove:advanced | manual
		'path_len'  => 15,
		'days'      => 365,
		'alias_utm' => 0,
	);
	$saved = get_option( 'wpa_settings', array() );
	$s     = array_merge( $defaults, is_array( $saved ) ? $saved : array() );

	$s['path_len']  = max( 3, min( 40, (int) $s['path_len'] ) );
	$s['days']      = max( 7, min( 730, (int) $s['days'] ) );
	$s['alias_utm'] = (int) ! empty( $s['alias_utm'] );
	return $s;
}

function moove_active(): bool {
	return defined( 'MOOVE_GDPR_VERSION' ) || function_exists( 'gdpr_cookie_is_accepted' ) || class_exists( 'Moove_GDPR_Controller' );
}

/** Итоговый режим согласия: none | moove:<категория> | manual. */
function consent_mode(): string {
	$mode = (string) settings()['consent'];
	if ( 'auto' === $mode ) {
		return moove_active() ? 'moove:thirdparty' : 'none';
	}
	return in_array( $mode, array( 'none', 'moove:thirdparty', 'moove:advanced', 'manual' ), true ) ? $mode : 'none';
}

/** Есть ли согласие по данным запроса (для серверной cookie). */
function server_consent_ok(): bool {
	$mode = consent_mode();
	if ( 'none' === $mode ) {
		return true;
	}
	if ( 0 === strpos( $mode, 'moove:' ) ) {
		$cat = substr( $mode, 6 );
		if ( empty( $_COOKIE['moove_gdpr_popup'] ) ) {
			return false;
		}
		$data = json_decode( wp_unslash( (string) $_COOKIE['moove_gdpr_popup'] ), true );
		if ( ! is_array( $data ) ) {
			$data = json_decode( rawurldecode( wp_unslash( (string) $_COOKIE['moove_gdpr_popup'] ) ), true );
		}
		return is_array( $data ) && isset( $data[ $cat ] ) && 1 === (int) $data[ $cat ];
	}
	// manual: клиент сообщает согласие флагом в запросе (см. rest.php).
	return ! empty( $GLOBALS['wpa_manual_consent'] );
}

/** Подписи для поля «Путь» — по языку сайта (его читает владелец). */
function labels(): array {
	$locale = (string) get_option( 'WPLANG' );
	$lang   = strtolower( substr( $locale ? $locale : get_locale(), 0, 2 ) );
	$all    = array(
		'ru' => array(
			'source' => 'Источник',
			'first'  => 'Первый заход',
			'entry'  => 'вход',
			'visits' => 'Визитов',
			'days'   => 'дней до заявки',
			'path'   => 'Путь',
		),
		'en' => array(
			'source' => 'Source',
			'first'  => 'First visit',
			'entry'  => 'landing',
			'visits' => 'Visits',
			'days'   => 'days to lead',
			'path'   => 'Path',
		),
		'sr' => array(
			'source' => 'Izvor',
			'first'  => 'Prva poseta',
			'entry'  => 'ulaz',
			'visits' => 'Poseta',
			'days'   => 'dana do upita',
			'path'   => 'Putanja',
		),
	);
	$all['uk'] = $all['ru'];
	$all['be'] = $all['ru'];
	return $all[ $lang ] ?? $all['en'];
}

/* --------------------------------------------------------------------------
 * Состояние
 * ------------------------------------------------------------------------ */

function cut( $v, int $len ): string {
	$v = is_scalar( $v ) ? (string) $v : '';
	$v = sanitize_text_field( $v );
	return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $len ) : substr( $v, 0, $len );
}

function clean_touch( $t ): ?array {
	if ( ! is_array( $t ) || empty( $t['s'] ) ) {
		return null;
	}
	$out = array(
		's'  => cut( $t['s'], 200 ),
		'm'  => cut( $t['m'] ?? '', 200 ),
		'ts' => max( 0, (int) ( $t['ts'] ?? 0 ) ),
		'lp' => cut( $t['lp'] ?? '/', 300 ),
	);
	foreach ( array( 'c', 'co', 't', 'k', 'r' ) as $k ) {
		if ( isset( $t[ $k ] ) && '' !== (string) $t[ $k ] ) {
			$out[ $k ] = cut( $t[ $k ], 'k' === $k ? 250 : 200 );
		}
	}
	if ( '' === $out['lp'] ) {
		$out['lp'] = '/';
	}
	return $out;
}

function clean_state( $s ): ?array {
	if ( ! is_array( $s ) ) {
		return null;
	}
	$f = clean_touch( $s['f'] ?? null );
	$l = clean_touch( $s['l'] ?? null );
	if ( ! $f && ! $l ) {
		return null;
	}
	$f   = $f ? $f : $l;
	$l   = $l ? $l : $f;
	$max = settings()['path_len'];
	$p   = array();
	if ( isset( $s['p'] ) && is_array( $s['p'] ) ) {
		foreach ( array_slice( $s['p'], -50 ) as $e ) {
			if ( is_array( $e ) && isset( $e[0] ) ) {
				$p[] = array( cut( $e[0], 300 ), max( 0, (int) ( $e[1] ?? 0 ) ), max( 1, (int) ( $e[2] ?? 1 ) ) );
			}
		}
	}
	$pt = ! empty( $s['pt'] );
	if ( count( $p ) > $max ) {
		$p  = array_slice( $p, -$max );
		$pt = true;
	}
	return array(
		'v'  => 1,
		'f'  => $f,
		'l'  => $l,
		'n'  => max( 1, min( 100000, (int) ( $s['n'] ?? 1 ) ) ),
		'la' => max( 0, (int) ( $s['la'] ?? 0 ) ),
		'p'  => $p,
		'pt' => $pt ? 1 : 0,
	);
}

function is_direct( ?array $t ): bool {
	return ! $t || '(direct)' === ( $t['s'] ?? '' );
}

/**
 * Слияние состояния браузера ($c) с серверной cookie ($s).
 * $fresh — браузер создал состояние заново (Safari стёр хранилище или впервые дано согласие).
 */
function merge( array $c, array $s, bool $fresh ): array {
	$m = $c;

	// Первое касание — самое раннее.
	if ( ! empty( $s['f'] ) && $s['f']['ts'] < $c['f']['ts'] ) {
		$m['f'] = $s['f'];
	}
	// Последнее касание — самое позднее, но «прямой заход» не перебивает рекламный/органический.
	if ( ! empty( $s['l'] ) ) {
		if ( is_direct( $c['l'] ) && ! is_direct( $s['l'] ) ) {
			$m['l'] = $s['l'];
		} elseif ( ! is_direct( $s['l'] ) && $s['l']['ts'] > $c['l']['ts'] ) {
			$m['l'] = $s['l'];
		}
	}

	if ( $fresh ) {
		$sn = (int) $s['n'];
		$p  = $s['p'];
		foreach ( $c['p'] as $e ) {
			$e[2] = (int) $e[2] + $sn;
			$p[]  = $e;
		}
		$m['n']  = $sn + (int) $c['n'];
		$m['pt'] = ( ! empty( $s['pt'] ) || ! empty( $c['pt'] ) ) ? 1 : 0;
	} else {
		$p      = $c['p'];
		$m['n'] = max( (int) $c['n'], (int) $s['n'] );
	}

	$max = settings()['path_len'];
	if ( count( $p ) > $max ) {
		$p       = array_slice( $p, -$max );
		$m['pt'] = 1;
	}
	$m['p']  = array_values( $p );
	$m['la'] = max( (int) $c['la'], (int) $s['la'] );
	return $m;
}

/* --------------------------------------------------------------------------
 * Серверная cookie
 * ------------------------------------------------------------------------ */

function b64u_encode( string $s ): string {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
}

function b64u_decode( string $s ) {
	return base64_decode( strtr( $s, '-_', '+/' ), true );
}

function read_cookie(): ?array {
	if ( empty( $_COOKIE[ COOKIE ] ) || ! is_string( $_COOKIE[ COOKIE ] ) || strlen( $_COOKIE[ COOKIE ] ) > 6000 ) {
		return null;
	}
	$raw = b64u_decode( wp_unslash( $_COOKIE[ COOKIE ] ) );
	if ( false === $raw ) {
		return null;
	}
	return clean_state( json_decode( $raw, true ) );
}

function cookie_opts( int $expires, bool $httponly ): array {
	return array(
		'expires'  => $expires,
		'path'     => ( defined( 'COOKIEPATH' ) && COOKIEPATH ) ? COOKIEPATH : '/',
		'domain'   => ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '',
		'secure'   => is_ssl(),
		'httponly' => $httponly,
		'samesite' => 'Lax',
	);
}

function write_cookie( array $state ): array {
	$enc = b64u_encode( wp_json_encode( $state ) );
	while ( strlen( $enc ) > COOKIE_MAX && ! empty( $state['p'] ) ) {
		array_shift( $state['p'] );
		$state['pt'] = 1;
		$enc         = b64u_encode( wp_json_encode( $state ) );
	}
	$exp = time() + DAY_IN_SECONDS * settings()['days'];
	if ( ! headers_sent() ) {
		setcookie( COOKIE, $enc, cookie_opts( $exp, true ) );
		setcookie( COOKIE_FLAG, '1', cookie_opts( $exp, false ) );
	}
	return $state;
}

function clear_cookie(): void {
	if ( ! headers_sent() ) {
		setcookie( COOKIE, '', cookie_opts( time() - YEAR_IN_SECONDS, true ) );
		setcookie( COOKIE_FLAG, '', cookie_opts( time() - YEAR_IN_SECONDS, false ) );
	}
}

/* --------------------------------------------------------------------------
 * Значения полей (повторяет wpa.js)
 * ------------------------------------------------------------------------ */

function fmt_touch( array $t ): string {
	return implode( ' / ', array_filter( array( $t['s'] ?? '', $t['m'] ?? '', $t['c'] ?? '' ), 'strlen' ) );
}

function fmt_date( int $ts ): string {
	return $ts ? wp_date( 'd.m.Y', $ts ) : '';
}

function summary( ?array $st, int $now ): string {
	if ( ! $st ) {
		return '';
	}
	$lab   = labels();
	$f     = $st['f'];
	$l     = $st['l'];
	$parts = array();

	$parts[] = $lab['source'] . ': ' . fmt_touch( $l ) . ' (' . fmt_date( $l['ts'] ) . ', ' . $lab['entry'] . ' ' . $l['lp'] . ')';
	if ( ! ( $f['ts'] === $l['ts'] && $f['s'] === $l['s'] ) ) {
		$parts[] = $lab['first'] . ': ' . fmt_touch( $f ) . ' (' . fmt_date( $f['ts'] ) . ', ' . $lab['entry'] . ' ' . $f['lp'] . ')';
	}
	$parts[] = $lab['visits'] . ': ' . (int) $st['n'] . ', ' . $lab['days'] . ': ' . days( $st, $now );

	if ( ! empty( $st['p'] ) ) {
		$path = ! empty( $st['pt'] ) ? '… → ' : '';
		$prev = null;
		foreach ( $st['p'] as $i => $e ) {
			if ( $i > 0 ) {
				$path .= ( $e[2] !== $prev ) ? ' ‖ ' : ' → ';
			}
			$path .= $e[0];
			$prev  = $e[2];
		}
		$parts[] = $lab['path'] . ': ' . $path;
	}
	return implode( ' | ', $parts );
}

function days( array $st, int $now ): int {
	return max( 0, (int) floor( ( $now - (int) $st['f']['ts'] ) / DAY_IN_SECONDS ) );
}

function click_part( ?array $t, string $name ): string {
	if ( ! $t || empty( $t['k'] ) ) {
		return '';
	}
	$pos = strpos( $t['k'], ':' );
	if ( false === $pos || substr( $t['k'], 0, $pos ) !== $name ) {
		return '';
	}
	return substr( $t['k'], $pos + 1 );
}

/** Все значения. $st может быть null — тогда остаются только ID аналитики из cookie. */
function values( ?array $st, array $cookies, int $now ): array {
	$v = array_fill_keys( KEYS, '' );

	$ga = isset( $cookies['_ga'] ) ? explode( '.', (string) $cookies['_ga'] ) : array();
	if ( count( $ga ) >= 4 ) {
		$v['ga_cid'] = cut( implode( '.', array_slice( $ga, -2 ) ), 100 );
	}
	$v['ym_uid'] = cut( $cookies['_ym_uid'] ?? '', 100 );
	$v['fbp']    = cut( $cookies['_fbp'] ?? '', 200 );
	$v['fbc']    = cut( $cookies['_fbc'] ?? '', 300 );

	if ( ! $st ) {
		return $v;
	}
	$f = $st['f'];
	$l = $st['l'];

	$v['source']         = $l['s'];
	$v['medium']         = $l['m'];
	$v['campaign']       = $l['c'] ?? '';
	$v['content']        = $l['co'] ?? '';
	$v['term']           = $l['t'] ?? '';
	$v['first_source']   = $f['s'];
	$v['first_medium']   = $f['m'];
	$v['first_campaign'] = $f['c'] ?? '';
	$v['landing']        = $f['lp'];
	$v['last_landing']   = $l['lp'];
	$v['referrer']       = $l['r'] ?? '';
	$v['first_referrer'] = $f['r'] ?? '';
	$v['click_id']       = $l['k'] ?? '';
	$v['gclid']          = click_part( $l, 'gclid' );
	$v['yclid']          = click_part( $l, 'yclid' );
	$v['fbclid']         = click_part( $l, 'fbclid' );
	if ( '' === $v['fbc'] && '' !== $v['fbclid'] ) {
		$v['fbc'] = 'fb.1.' . ( (int) $l['ts'] * 1000 ) . '.' . $v['fbclid'];
	}
	$v['visits']     = (string) (int) $st['n'];
	$v['days']       = (string) days( $st, $now );
	$v['first_date'] = fmt_date( (int) $f['ts'] );
	$v['path']       = summary( $st, $now );
	return $v;
}

/** Значения на сервере при сабмите (запасной путь, если JS не отработал). */
function server_values(): array {
	$cookies = array();
	foreach ( array( '_ga', '_ym_uid', '_fbp', '_fbc' ) as $k ) {
		if ( isset( $_COOKIE[ $k ] ) && is_string( $_COOKIE[ $k ] ) ) {
			$cookies[ $k ] = sanitize_text_field( wp_unslash( $_COOKIE[ $k ] ) );
		}
	}
	return values( read_cookie(), $cookies, time() );
}
