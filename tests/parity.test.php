<?php
namespace { 
define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400); define('YEAR_IN_SECONDS', 31536000);
$GLOBALS['opts'] = ['WPLANG' => 'ru_RU', 'wpa_settings' => []];
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function get_locale() { return 'ru_RU'; }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string)$s))); }
function wp_unslash($s) { return $s; }
function wp_json_encode($d) { return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function wp_date($f, $ts) { $d = new DateTime('@' . $ts); $d->setTimezone(new DateTimeZone('Europe/Podgorica')); return $d->format($f); }
function is_ssl() { return true; }
}
namespace Webport\Attribution {
require __DIR__ . '/../includes/core.php';
$j = json_decode(file_get_contents(__DIR__ . '/state.json'), true);
$st = clean_state($j['st']);
$v = values($st, ['_ga' => 'GA1.1.123456.1791000000', '_ym_uid' => '17920000001234'], $j['now']);
$diff = [];
foreach ($j['v'] as $k => $jsv) { if ((string)$jsv !== (string)$v[$k]) $diff[$k] = [$jsv, $v[$k]]; }
if ($diff) { var_export($diff); exit(1); }
echo "PHP parity OK (" . count($j['v']) . " keys)\n";

// Слияние: Safari стёр localStorage, браузер начал заново прямым заходом.
$client = clean_state(['f' => ['s' => '(direct)', 'm' => '(none)', 'ts' => $j['now'], 'lp' => '/kontakty/'],
  'l' => ['s' => '(direct)', 'm' => '(none)', 'ts' => $j['now'], 'lp' => '/kontakty/'], 'n' => 1, 'la' => $j['now'],
  'p' => [['/kontakty/', $j['now'], 1]]]);
$m = merge($client, $st, true);
assert($m['f']['s'] === 'google', 'first restored');
assert($m['l']['s'] === 'instagram', 'direct does not override last');
assert($m['n'] === 4, 'visits continue: ' . $m['n']);
$last = end($m['p']); assert($last[2] === 4 && $last[0] === '/kontakty/', 'path renumbered');
// Обычная синхронизация не задваивает
$m2 = merge($st, $st, false); assert($m2['n'] === $st['n'] && count($m2['p']) === count($st['p']));
// Пустое состояние → только ID
$e = values(null, ['_ym_uid' => '1'], time()); assert($e['ym_uid'] === '1' && $e['path'] === '');
// cookie roundtrip
$enc = b64u_encode(wp_json_encode($m)); $_COOKIE[COOKIE] = $enc; $back = read_cookie();
assert($back['f']['s'] === 'google' && $back['n'] === 4, 'cookie roundtrip');
echo "merge/cookie OK, cookie size " . strlen($enc) . " bytes\n";
echo summary($m, $j['now']) . "\n";
}
