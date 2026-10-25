<?php
/**
 * Интеграция с Forminator.
 *
 * Поле: Hidden → Default value «Query parameter» → ключ wpa_<key> (например wpa_source).
 * Почему не «Custom value»: Forminator при сабмите перезаписывает custom value сохранённым значением,
 * а значения типа query (и refer_url) берёт из POST — их и заполняет наш JS.
 *
 * 1. При выводе формы в пустое поле ставится маркер __wpa_<key>__ (страница может кешироваться — это ок).
 * 2. JS заменяет маркер реальным значением (при загрузке, при появлении формы и перед отправкой).
 * 3. При сабмите forminator_prepared_data (до сохранения записи и писем): если пришёл маркер или пусто —
 *    подставляем значение из серверной cookie; маркер в заявку не попадает никогда.
 */

namespace Webport\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MARKER_RE = '/^__wpa_([a-z0-9_]+)__$/';

/** Ключ атрибуции для настроек скрытого поля или null. */
function field_key( array $field ): ?string {
	if ( 'query' !== ( $field['default_value'] ?? '' ) ) {
		return null;
	}
	$p = strtolower( trim( (string) ( $field['prefill'] ?? '' ) ) );
	if ( 0 === strpos( $p, 'wpa_' ) ) {
		$k = substr( $p, 4 );
		return in_array( $k, KEYS, true ) ? $k : null;
	}
	if ( settings()['alias_utm'] && isset( ALIASES[ $p ] ) ) {
		return ALIASES[ $p ];
	}
	return null;
}

function is_forminator_submit(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	return wp_doing_ajax() && 0 === strpos( $action, 'forminator_submit' );
}

add_filter(
	'forminator_field_hidden_field_value',
	static function ( $value, $saved_value, $field ) {
		if ( 'query' !== $saved_value || ! is_array( $field ) || is_forminator_submit() ) {
			return $value;
		}
		$key = field_key( $field );
		if ( ! $key || '' !== (string) $value ) {
			return $value;
		}
		return '__wpa_' . $key . '__';
	},
	10,
	3
);

add_filter(
	'forminator_prepared_data',
	static function ( $data, $module ) {
		if ( ! is_array( $data ) || ! is_object( $module ) || ! method_exists( $module, 'get_fields' ) ) {
			return $data;
		}
		$server = null;
		foreach ( (array) $module->get_fields() as $field ) {
			if ( ! is_object( $field ) || ! method_exists( $field, 'to_formatted_array' ) ) {
				continue;
			}
			$f = $field->to_formatted_array();
			if ( 'hidden' !== ( $f['type'] ?? '' ) || empty( $f['element_id'] ) ) {
				continue;
			}
			$key = field_key( $f );
			if ( ! $key ) {
				continue;
			}
			$id  = $f['element_id'];
			$cur = isset( $data[ $id ] ) && is_scalar( $data[ $id ] ) ? (string) $data[ $id ] : '';

			if ( '' === $cur || preg_match( MARKER_RE, $cur ) ) {
				if ( null === $server ) {
					$server = server_values();
				}
				$data[ $id ] = $server[ $key ] ?? '';
			} else {
				$data[ $id ] = cut( $cur, 'path' === $key ? 3000 : 300 );
			}
		}
		return $data;
	},
	20,
	2
);

/* --------------------------------------------------------------------------
 * Письма (с 1.0.1)
 * 1. В письмах клиенту поля атрибуции вырезаются из {all_fields}: клиенту не нужно видеть свой источник и ClientID.
 * 2. В письмах администратору страницы в «Пути к заявке» становятся ссылками — видно, что человек читал.
 * Фильтр forminator_custom_form_mail_admin_message вызывается для каждого письма формы и получает его настройки.
 * ------------------------------------------------------------------------ */

/** Поля атрибуции формы: element_id => [ key, label ]. */
function attribution_fields( $form ): array {
	$out    = array();
	$fields = is_object( $form ) && method_exists( $form, 'get_fields' ) ? $form->get_fields() : array();
	foreach ( (array) $fields as $field ) {
		if ( ! is_object( $field ) || ! method_exists( $field, 'to_formatted_array' ) ) {
			continue;
		}
		$f = $field->to_formatted_array();
		if ( 'hidden' !== ( $f['type'] ?? '' ) || empty( $f['element_id'] ) ) {
			continue;
		}
		$key = field_key( $f );
		if ( $key ) {
			$out[ $f['element_id'] ] = array(
				'key'   => $key,
				'label' => (string) ( $f['field_label'] ?? '' ),
			);
		}
	}
	return $out;
}

/** Письмо клиенту: получатель задан макросом поля ({email-1}) или это письмо «Сохранить и продолжить». */
function is_client_notification( $n ): bool {
	if ( ! is_array( $n ) ) {
		return false;
	}
	if ( 'save_draft' === ( $n['type'] ?? '' ) ) {
		return true;
	}
	if ( 'conditions' === ( $n['email-recipients'] ?? '' ) ) {
		return false; // маршрутизация по условиям — это адреса компании
	}
	return false !== strpos( (string) ( $n['recipients'] ?? '' ), '{' );
}

/** Убрать строки полей с этими подписями из {all_fields}. */
function strip_rows( string $message, array $labels ): string {
	foreach ( array_unique( array_filter( $labels, 'strlen' ) ) as $label ) {
		foreach ( array_unique( array( $label, esc_html( $label ) ) ) as $variant ) {
			$message = preg_replace( '#<li>\s*<b>' . preg_quote( $variant, '#' ) . '</b>.*?</li>#su', '', $message );
		}
	}
	return $message;
}

/**
 * Страницы в сводке «Путь к заявке» → ссылки на сайт.
 * Ссылками становятся страница после «вход» и все страницы в части «Путь: …»
 * (разделитель « / » между источником и каналом не трогаем). $text — уже экранированный.
 */
function linkify_paths( string $text, bool $html = true ): string {
	$home   = wp_parse_url( home_url() );
	$origin = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	$link   = static function ( $p ) use ( $origin, $html ) {
		if ( ! $html ) {
			// Текстовое письмо: полный адрес, почтовые программы сами делают его ссылкой.
			return $origin . $p;
		}
		return '<a class="wpa-link" href="' . esc_url( $origin . html_entity_decode( $p, ENT_QUOTES, 'UTF-8' ) ) . '">' . $p . '</a>';
	};
	$lab    = labels();
	$parts  = explode( ' | ', $text );
	foreach ( $parts as $i => $part ) {
		$path_prefix = esc_html( $lab['path'] ) . ': ';
		if ( 0 === strpos( $part, $path_prefix ) ) {
			$parts[ $i ] = preg_replace_callback(
				'#(^|\s)(/[^\s]*)#u',
				static function ( $m ) use ( $link ) {
					return $m[1] . $link( $m[2] );
				},
				$part
			);
		} else {
			$entry       = preg_quote( esc_html( $lab['entry'] ), '#' );
			$parts[ $i ] = preg_replace_callback(
				'#(' . $entry . ' )(/[^\s)]*)#u',
				static function ( $m ) use ( $link ) {
					return $m[1] . $link( $m[2] );
				},
				$part
			);
		}
	}
	return implode( ' | ', $parts );
}

add_filter(
	'forminator_custom_form_mail_admin_message',
	static function ( $message, $form = null, $data = array(), $entry = null, $mail = null, $notification = array() ) {
		if ( ! is_string( $message ) || '' === $message || ! apply_filters( 'wpa_mail_enabled', true ) ) {
			return $message;
		}
		$fields = attribution_fields( $form );
		if ( ! $fields ) {
			return $message;
		}

		if ( is_client_notification( $notification ) ) {
			return strip_rows( $message, array_column( $fields, 'label' ) );
		}

		foreach ( $fields as $id => $f ) {
			if ( 'path' !== $f['key'] || empty( $data[ $id ] ) || ! is_string( $data[ $id ] ) ) {
				continue;
			}
			$value = $data[ $id ];
			foreach ( array_unique( array( $value, esc_html( $value ) ) ) as $variant ) {
				if ( false !== strpos( $message, $variant ) ) {
					$message = str_replace( $variant, linkify_paths( esc_html( $value ) ), $message );
					break;
				}
			}
		}
		return $message;
	},
	20,
	6
);
