<?php
namespace {
define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400); define('YEAR_IN_SECONDS', 31536000);
$GLOBALS['filters'] = [];
function add_filter($n, $cb, $p = 10, $a = 1) { $GLOBALS['filters'][$n][] = $cb; }
function add_action($n, $cb, $p = 10, $a = 1) { $GLOBALS['filters'][$n][] = $cb; }
function apply_filters($n, $v) { return $v; }
function get_option($k, $d = false) { return $k === 'WPLANG' ? 'ru_RU' : $d; }
function get_locale() { return 'ru_RU'; }
function sanitize_text_field($s) { return trim(strip_tags((string)$s)); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string)$v); }
function wp_slash($v) { return is_array($v) ? array_map('wp_slash', $v) : addslashes((string)$v); }
function wp_doing_ajax() { return true; }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return $s; }
function home_url() { return 'https://toplakuca.rs'; }
function wp_parse_url($u) { return parse_url($u); }
function wp_json_encode($d) { return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function wp_date($f, $ts) { return gmdate($f, $ts); }
}
namespace Webport\Attribution {
require __DIR__ . '/../includes/core.php';
require __DIR__ . '/../includes/forminator.php';
require __DIR__ . '/../includes/generic.php';

// Серверная cookie: заход из instagram/cpc.
$now = time();
$st = ['f'=>['s'=>'instagram','m'=>'cpc','c'=>'oct','ts'=>$now-86400,'lp'=>'/kuce/'],'l'=>['s'=>'instagram','m'=>'cpc','c'=>'oct','ts'=>$now-86400,'lp'=>'/kuce/'],'n'=>1,'la'=>$now,'p'=>[['/kuce/',$now-86400,1],['/kontakti/',$now,1]]];
$_COOKIE[COOKIE] = b64u_encode(wp_json_encode($st));

// 1. Bricks: поля form-field-*; один маркер, одно уже заполненное JS значение.
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action'=>'bricks_form_submit','form-field-src'=>'__wpa_source__','form-field-med'=>'cpc_from_js','form-field-path'=>'__wpa_path__','form-field-name'=>"O\\'Brien"];
$_REQUEST = $_POST;
scrub_request();
assert($_POST['form-field-src'] === 'instagram', 'bricks marker → server value');
assert($_POST['form-field-med'] === 'cpc_from_js', 'JS value untouched');
assert(strpos(wp_unslash($_POST['form-field-path']), 'Путь: /kuce/ → /kontakti/') !== false, 'path from cookie');
assert($_POST['form-field-name'] === "O\\'Brien", 'other fields untouched (still slashed)');
assert($_REQUEST['form-field-src'] === 'instagram', '$_REQUEST synced');

// 2. Fluent Forms: всё в строке data.
$_POST = ['action'=>'fluentform_submit','form_id'=>'1','data'=>wp_slash(http_build_query(['names'=>['first_name'=>'Ana'],'email'=>'a@b.rs','wpa_src'=>'__wpa_source__','wpa_cmp'=>'__wpa_campaign__','message'=>"it's ok"]))];
$_REQUEST = $_POST;
scrub_request();
parse_str(wp_unslash($_POST['data']), $d);
assert($d['wpa_src'] === 'instagram' && $d['wpa_cmp'] === 'oct', 'fluent packed markers replaced');
assert($d['names']['first_name'] === 'Ana' && $d['message'] === "it's ok", 'fluent other fields intact');

// 3. Без маркеров — ничего не меняется.
$_POST = ['data'=>'email=a%40b.rs']; $before = $_POST; scrub_request(); assert($_POST === $before, 'no markers → untouched');

// 4. Письма: HTML (Bricks htmlEmail) и текст.
$mail = $GLOBALS['filters']['wp_mail'][0];
$sum = summary(clean_state($st), $now);
$h = $mail(['to'=>'info@toplakuca.rs','subject'=>'x','message'=>'<strong>Путь к заявке</strong>: '.esc_html($sum).'<br>','headers'=>['Content-Type: text/html; charset=UTF-8']]);
assert(substr_count($h['message'], 'class="wpa-link"') === 3, 'html links: '.substr_count($h['message'], 'class="wpa-link"'));
assert(strpos($h['message'], 'href="https://toplakuca.rs/kontakti/"') !== false, 'html link target');
$t = $mail(['to'=>'info@toplakuca.rs','subject'=>'x','message'=>"Name: Ana\nPath: ".$sum."\nend",'headers'=>'']);
assert(strpos($t['message'], 'https://toplakuca.rs/kuce/ → https://toplakuca.rs/kontakti/') !== false, 'text absolute urls: '.$t['message']);
assert(strpos($t['message'], '<a') === false, 'no html in text mail');
$again = $mail($h); assert($again['message'] === $h['message'], 'idempotent');
$plain = $mail(['to'=>'x','message'=>'Hello','headers'=>'']); assert($plain['message'] === 'Hello', 'unrelated mail untouched');
echo "generic OK\n";
}
