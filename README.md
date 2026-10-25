# Webport Attribution

WordPress-плагин Webport.Studio: запоминает путь посетителя до заявки и передаёт его в скрытые поля Forminator.

- Первое и последнее касание: источник, канал, кампания, UTM, страница входа, реферер.
- Click-ID: gclid, yclid, fbclid (+ msclkid, ttclid, gbraid/wbraid для определения источника).
- ID аналитики: ClientID Метрики (`_ym_uid`), client_id GA4, Meta `_fbp`/`_fbc`.
- Визиты, дни до заявки, последние N страниц с разбивкой по визитам.
- Каналы: cpc, organic, social, **ai** (ChatGPT, Perplexity, Gemini, Claude…), email, referral, (direct).
- Хранение: localStorage + серверная HttpOnly cookie `wpa_s` на год (Safari ITP режет JS-хранилище до 7 дней).
- Работает с кешем страниц (WP Fastest Cache, LiteSpeed, WP Rocket) — без nonce.
- Согласие: GDPR Cookie Compliance (авто), ручной режим `window.wpaConsent(true|false)` или без согласия.

## Поле в Forminator

Hidden → Default value: **Query parameter** → ключ `wpa_<key>`. Не «Custom value»: его Forminator перезаписывает на сервере.

Рекомендуемый набор: `wpa_source`, `wpa_medium`, `wpa_campaign`, `wpa_click_id`, `wpa_ym_uid`, `wpa_ga_cid`, `wpa_path`. Полный список — Настройки → Webport Attribution.

## Релиз (автообновление сайтов)

1. Поднять версию в шапке `webport-attribution.php` и в `WPA_VERSION`.
2. Коммит, тег `v1.0.1`, GitHub Release из тега. Action соберёт `webport-attribution.zip` и приложит к релизу.
3. Сайты увидят обновление в «Плагинах» (проверка раз в 12 часов).

Адрес репозитория — константа `WPA_UPDATE_REPO` в `webport-attribution.php` (или в wp-config.php). Для приватного репозитория — `WPA_UPDATE_TOKEN` в wp-config.php.

## Тесты

```
cd tests && npm i jsdom@24 && node scenario.test.js && php -d zend.assertions=1 -d assert.exception=1 parity.test.php && php -d zend.assertions=1 -d assert.exception=1 mail.test.php && node dom.test.js
```

## Письма (с 1.0.1)

- В письмах клиенту (получатель — макрос поля, например `{email-1}`, или «Сохранить и продолжить») поля атрибуции вырезаются из `{all_fields}`.
- В письмах администратору страницы в «Пути к заявке» становятся ссылками на сайт.
- Отключить: `add_filter( 'wpa_mail_enabled', '__return_false' );`

## Другие плагины форм (с 1.2.0): Bricks, Fluent Forms и др.

Скрытое поле формы со **значением по умолчанию** `__wpa_<key>__`, например `__wpa_source__`, `__wpa_path__`.

- JS заменяет маркер в любом `input[type=hidden]` при загрузке, при появлении формы и перед отправкой.
- Если JS не сработал, сервер заменяет маркер значением из cookie до обработки отправки (обычные POST-поля и упакованная строка `data` у Fluent Forms).
- Сводка «Путь к заявке» в любом письме сайта получает ссылки (HTML-письма) или полные адреса (текстовые).
- Письма клиенту: в шаблоне подтверждения перечисляйте поля явно, без `{{all_fields}}` / `{all_data}`, иначе клиент увидит поля источника.
