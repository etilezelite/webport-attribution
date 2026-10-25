<?php
/**
 * Настройки → Webport Attribution: режим согласия, длина пути, срок cookie, справочник ключей и диагностика форм.
 */

namespace Webport\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KEY_HELP = array(
	'source'         => 'Источник последнего касания (instagram, google, (direct)…)',
	'medium'         => 'Канал последнего касания (cpc, organic, social, ai, referral…)',
	'campaign'       => 'Кампания (utm_campaign)',
	'content'        => 'utm_content',
	'term'           => 'utm_term',
	'first_source'   => 'Источник первого касания',
	'first_medium'   => 'Канал первого касания',
	'first_campaign' => 'Кампания первого касания',
	'landing'        => 'Страница входа при первом заходе',
	'last_landing'   => 'Страница входа при последнем касании',
	'referrer'       => 'Домен-реферер последнего касания',
	'first_referrer' => 'Домен-реферер первого касания',
	'click_id'       => 'Click-ID рекламы (gclid:…, yclid:…, fbclid:…)',
	'gclid'          => 'Google Ads click ID',
	'yclid'          => 'Яндекс Директ click ID',
	'fbclid'         => 'Meta click ID',
	'ym_uid'         => 'ClientID Яндекс Метрики (_ym_uid)',
	'ga_cid'         => 'client_id GA4 (из _ga)',
	'fbp'            => 'Meta _fbp (браузер)',
	'fbc'            => 'Meta _fbc (клик)',
	'visits'         => 'Число визитов',
	'days'           => 'Дней от первого захода до заявки',
	'first_date'     => 'Дата первого захода',
	'path'           => 'Сводка: источник, первый заход, визиты, путь по страницам',
);

/** Рекомендуемый набор для каждой формы (п. 9 скилла forminator-forms). */
const RECOMMENDED = array( 'source', 'medium', 'campaign', 'click_id', 'ym_uid', 'ga_cid', 'path' );

add_action(
	'admin_menu',
	static function () {
		add_options_page( 'Webport Attribution', 'Webport Attribution', 'manage_options', 'webport-attribution', __NAMESPACE__ . '\\render_page' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( WPA_FILE ),
	static function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=webport-attribution' ) ) . '">Настройки</a>' );
		return $links;
	}
);

add_action(
	'admin_init',
	static function () {
		register_setting(
			'wpa',
			'wpa_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => static function ( $in ) {
					$in = is_array( $in ) ? $in : array();
					return array(
						'consent'   => in_array( $in['consent'] ?? '', array( 'auto', 'none', 'moove:thirdparty', 'moove:advanced', 'manual' ), true ) ? $in['consent'] : 'auto',
						'path_len'  => max( 3, min( 40, (int) ( $in['path_len'] ?? 15 ) ) ),
						'days'      => max( 7, min( 730, (int) ( $in['days'] ?? 365 ) ) ),
						'alias_utm' => empty( $in['alias_utm'] ) ? 0 : 1,
					);
				},
			)
		);
	}
);

/** Поля атрибуции по формам Forminator: [ id, name, fields => [element_id => key] ]. */
function forms_report(): array {
	if ( ! class_exists( 'Forminator_API' ) ) {
		return array();
	}
	$out   = array();
	$forms = \Forminator_API::get_forms( null, 1, 100 );
	foreach ( (array) $forms as $form ) {
		$found = array();
		$utm   = array();
		foreach ( (array) $form->fields as $field ) {
			$f = $field->to_formatted_array();
			if ( 'hidden' !== ( $f['type'] ?? '' ) ) {
				continue;
			}
			$key = field_key( $f );
			if ( $key ) {
				$found[ $f['element_id'] ] = $key;
			} elseif ( 'query' === ( $f['default_value'] ?? '' ) && isset( ALIASES[ strtolower( (string) ( $f['prefill'] ?? '' ) ) ] ) ) {
				$utm[ $f['element_id'] ] = $f['prefill'];
			}
		}
		$out[] = array(
			'id'     => $form->id,
			'name'   => $form->settings['formName'] ?? $form->name,
			'fields' => $found,
			'utm'    => $utm,
		);
	}
	return $out;
}

function render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s    = settings();
	$mode = consent_mode();
	?>
	<div class="wrap">
		<h1>Webport Attribution</h1>
		<p>Запоминает путь посетителя до заявки и передаёт его в скрытые поля Forminator. Поле: <strong>Hidden → Default value: Query parameter → ключ <code>wpa_…</code></strong>.</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'wpa' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpa-consent">Согласие на cookie</label></th>
					<td>
						<select id="wpa-consent" name="wpa_settings[consent]">
							<?php
							$opts = array(
								'auto'             => 'Авто (GDPR Cookie Compliance → «сторонние»; иначе без согласия)',
								'none'             => 'Не ждать согласия',
								'moove:thirdparty' => 'GDPR Cookie Compliance: категория «Third party / Аналитика»',
								'moove:advanced'   => 'GDPR Cookie Compliance: категория «Advanced»',
								'manual'           => 'Вручную: CMP вызывает window.wpaConsent(true|false)',
							);
							foreach ( $opts as $val => $label ) {
								printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $s['consent'], $val, false ), esc_html( $label ) );
							}
							?>
						</select>
						<p class="description">Сейчас действует: <code><?php echo esc_html( $mode ); ?></code>. Без согласия путь не сохраняется между страницами, но источник текущей страницы всё равно попадёт в заявку.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpa-len">Страниц в пути</label></th>
					<td><input id="wpa-len" type="number" min="3" max="40" name="wpa_settings[path_len]" value="<?php echo esc_attr( $s['path_len'] ); ?>" class="small-text"> последних</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpa-days">Срок серверной cookie</label></th>
					<td><input id="wpa-days" type="number" min="7" max="730" name="wpa_settings[days]" value="<?php echo esc_attr( $s['days'] ); ?>" class="small-text"> дней</td>
				</tr>
				<tr>
					<th scope="row">Старые поля utm_*</th>
					<td><label><input type="checkbox" name="wpa_settings[alias_utm]" value="1" <?php checked( $s['alias_utm'], 1 ); ?>> Заполнять существующие поля «Query parameter» с ключами utm_source, utm_medium, utm_campaign, utm_content, utm_term, gclid, yclid, fbclid тем же способом (последнее касание вместо только текущего URL)</label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2>Формы на сайте</h2>
		<?php $report = forms_report(); ?>
		<?php if ( ! $report ) : ?>
			<p>Forminator не активен или форм нет.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1000px">
				<thead><tr><th>Форма</th><th>Поля атрибуции</th><th>Не хватает из рекомендуемых</th><th>Старые utm-поля</th></tr></thead>
				<tbody>
				<?php foreach ( $report as $row ) : ?>
					<?php $missing = array_diff( RECOMMENDED, array_values( $row['fields'] ) ); ?>
					<tr>
						<td><?php echo esc_html( $row['name'] ); ?> <span style="color:#888">#<?php echo (int) $row['id']; ?></span></td>
						<td><?php echo $row['fields'] ? esc_html( implode( ', ', array_map( static function ( $id, $k ) { return $id . ' → ' . $k; }, array_keys( $row['fields'] ), $row['fields'] ) ) ) : '—'; ?></td>
						<td><?php echo $missing ? implode( ' ', array_map( static function ( $k ) { return '<code>wpa_' . esc_html( $k ) . '</code>'; }, $missing ) ) : '✓'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo $row['utm'] ? esc_html( implode( ', ', array_map( static function ( $id, $p ) { return $id . ' (' . $p . ')'; }, array_keys( $row['utm'] ), $row['utm'] ) ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2>Ключи полей</h2>
		<table class="widefat striped" style="max-width:1000px">
			<thead><tr><th>Ключ в Forminator</th><th>Что попадёт в поле</th></tr></thead>
			<tbody>
			<?php foreach ( KEY_HELP as $k => $help ) : ?>
				<tr><td><code>wpa_<?php echo esc_html( $k ); ?></code><?php echo in_array( $k, RECOMMENDED, true ) ? ' <strong>★</strong>' : ''; ?></td><td><?php echo esc_html( $help ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p>★ — рекомендуемый набор для каждой формы.</p>
	</div>
	<?php
}
