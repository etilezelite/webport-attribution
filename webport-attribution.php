<?php
/**
 * Plugin Name:       Webport Attribution
 * Description:       Путь посетителя до заявки: первое и последнее касание, UTM, click-ID, ClientID Метрики и GA4, страницы визитов — в скрытые поля Forminator.
 * Version:           1.0.1
 * Author:            Webport.Studio
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Text Domain:       webport-attribution
 * Update URI:        https://github.com/etilezelite/webport-attribution
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPA_VERSION', '1.0.1' );
define( 'WPA_FILE', __FILE__ );
define( 'WPA_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPA_URL', plugin_dir_url( __FILE__ ) );

/*
 * Репозиторий для автообновлений. Можно переопределить в wp-config.php:
 *   define( 'WPA_UPDATE_REPO', 'https://github.com/owner/webport-attribution/' );
 *   define( 'WPA_UPDATE_TOKEN', 'github_pat_...' ); // только для приватного репозитория
 */
if ( ! defined( 'WPA_UPDATE_REPO' ) ) {
	define( 'WPA_UPDATE_REPO', 'https://github.com/etilezelite/webport-attribution/' );
}

require_once WPA_DIR . 'includes/core.php';
require_once WPA_DIR . 'includes/rest.php';
require_once WPA_DIR . 'includes/frontend.php';
require_once WPA_DIR . 'includes/forminator.php';

if ( is_admin() ) {
	require_once WPA_DIR . 'includes/admin.php';
}

// Автообновление с GitHub (релизы).
add_action(
	'plugins_loaded',
	static function () {
		$lib = WPA_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( false !== strpos( WPA_UPDATE_REPO, '/OWNER/' ) || ! file_exists( $lib ) ) {
			return;
		}
		require_once $lib;
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			WPA_UPDATE_REPO,
			WPA_FILE,
			'webport-attribution'
		);
		if ( defined( 'WPA_UPDATE_TOKEN' ) && WPA_UPDATE_TOKEN ) {
			$checker->setAuthentication( WPA_UPDATE_TOKEN );
		}
		// Берём zip, приложенный к релизу (собирается GitHub Action), иначе — архив исходников тега.
		$checker->getVcsApi()->enableReleaseAssets( '/webport-attribution\.zip/' );
	}
);
