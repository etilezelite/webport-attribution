<?php
namespace {
define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400); define('YEAR_IN_SECONDS', 31536000);
$GLOBALS['filters'] = [];
function add_filter($n, $cb, $p = 10, $a = 1) { $GLOBALS['filters'][$n][] = $cb; }
function apply_filters($n, $v) { return $v; }
function get_option($k, $d = false) { return $d; }
function get_locale() { return 'ru_RU'; }
function sanitize_text_field($s) { return trim(strip_tags((string)$s)); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); }
function wp_unslash($s) { return $s; }
function wp_doing_ajax() { return false; }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return $s; }
function home_url() { return 'https://neverend.wedding'; }
function wp_parse_url($u) { return parse_url($u); }
class F { function __construct(public array $a) {} function to_formatted_array() { return $this->a; } }
class Form { function __construct(public array $f) {} function get_fields() { return $this->f; } }
}
namespace Webport\Attribution {
require __DIR__ . '/../includes/core.php';
require __DIR__ . '/../includes/forminator.php';
$mailf = $GLOBALS['filters']['forminator_custom_form_mail_admin_message'][0];
$form = new \Form([
  new \F(['type'=>'name','element_id'=>'name-1','field_label'=>'Имя']),
  new \F(['type'=>'hidden','element_id'=>'hidden-1','field_label'=>'Источник','default_value'=>'query','prefill'=>'wpa_source']),
  new \F(['type'=>'hidden','element_id'=>'hidden-5','field_label'=>'ClientID Метрики','default_value'=>'query','prefill'=>'wpa_ym_uid']),
  new \F(['type'=>'hidden','element_id'=>'hidden-7','field_label'=>'Путь к заявке','default_value'=>'query','prefill'=>'wpa_path']),
]);
$path = 'Источник: test / cpc / wpa_check2 (25.10.2026, вход /ru/) | Визитов: 1, дней до заявки: 0 | Путь: /ru/ → /ru/kontakty/ ‖ /ru/ceny/?a=1 → / → /x/';
$msg = '<ol><li><b>Имя</b><br/>Тест<br/></li><li><b>Источник</b><br/>test<br/></li><li><b>ClientID Метрики</b><br/></li><li><b>Путь к заявке</b><br/>' . $path . '<br/></li></ol>';
$data = ['hidden-7' => $path];

$client = $mailf($msg, $form, $data, null, null, ['recipients' => '{email-1}']);
assert(strpos($client, 'Имя') !== false, 'client keeps name');
assert(strpos($client, 'Источник') === false && strpos($client, 'Путь') === false && strpos($client, 'Метрики') === false, 'client stripped: ' . $client);

$admin = $mailf($msg, $form, $data, null, null, ['email-recipients' => 'default', 'recipients' => 'neverend.wed@gmail.com']);
assert(strpos($admin, '<a href="https://neverend.wedding/ru/kontakty/">/ru/kontakty/</a>') !== false, 'link kontakty');
assert(strpos($admin, '<a href="https://neverend.wedding/ru/">/ru/</a>)') !== false, 'link in entry with paren');
assert(strpos($admin, 'href="https://neverend.wedding/ru/ceny/?a=1"') !== false, 'link with query');
assert(substr_count($admin, '<a ') === 6, 'six links: ' . substr_count($admin, '<a '));
assert(strpos($admin, 'test / cpc / wpa_check2') !== false, 'text intact');
assert(strpos($admin, '→ <a href="https://neverend.wedding/">/</a> →') !== false, 'homepage link in path');
$draft = $mailf($msg, $form, $data, null, null, ['type' => 'save_draft', 'recipients' => '{save_and_continue_email}']);
assert(strpos($draft, 'Путь') === false, 'draft stripped');
$routing = $mailf($msg, $form, $data, null, null, ['email-recipients' => 'conditions', 'recipients' => '{email-1}']);
assert(strpos($routing, '<a ') !== false, 'routing treated as admin');
echo "mail OK\n", $admin, "\n";
}
