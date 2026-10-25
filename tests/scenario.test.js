const assert = require('assert');
const core = require('../assets/wpa.js');
const cfg = { pathLen: 15, sessionMin: 30, tz: 'Europe/Podgorica',
  labels: { source: 'Источник', first: 'Первый заход', entry: 'вход', visits: 'Визитов', days: 'дней до заявки', path: 'Путь' } };
const H = 'https://neverend.wedding';
let st = null, t = 1792000000; // 2026-10-15
const step = (path, ref, dt) => { t += dt; const r = core.update(st, H + path, ref, t, cfg); st = r.state; return r; };

let r = step('/svadby/', 'https://www.google.com/', 0);
assert.ok(r.fresh); assert.equal(st.f.s, 'google'); assert.equal(st.f.m, 'organic');
r = step('/ceny/', H + '/svadby/', 120);
assert.ok(!r.newVisit); assert.equal(st.n, 1);
r = step('/ceny/?utm_source=instagram&utm_medium=cpc&utm_campaign=oct&fbclid=ABC', 'https://l.instagram.com/', 3 * 86400);
assert.ok(r.changed && r.newVisit); assert.equal(st.n, 2); assert.equal(st.l.s, 'instagram'); assert.equal(st.l.lp, '/ceny/');
r = step('/kontakty/', H + '/ceny/', 300);
r = step('/kontakty/', H + '/kontakty/', 10); // перезагрузка — не дублируем
assert.equal(st.p.length, 4);
r = step('/portfolio/', '', 86400); // прямой заход: новый визит, last не меняется
assert.ok(r.newVisit && !r.changed); assert.equal(st.n, 3); assert.equal(st.l.s, 'instagram');

const v = core.values(st, { _ga: 'GA1.1.123456.1791000000', _ym_uid: '17920000001234' }, t + 600, cfg);
assert.equal(v.source, 'instagram'); assert.equal(v.medium, 'cpc'); assert.equal(v.campaign, 'oct');
assert.equal(v.first_source, 'google'); assert.equal(v.landing, '/svadby/');
assert.equal(v.fbclid, 'ABC'); assert.ok(v.fbc.startsWith('fb.1.')); assert.equal(v.ga_cid, '123456.1791000000');
assert.equal(v.visits, '3'); assert.equal(v.days, '4');
console.log(v.path);

// Классификация
const d = (href, ref) => core.detectTouch(href, ref, 1);
assert.deepEqual([d(H+'/', 'https://gemini.google.com/app').s, d(H+'/', 'https://gemini.google.com/').m], ['gemini','ai']);
assert.equal(d(H+'/', 'https://mail.google.com/mail/u/0').m, 'email');
assert.equal(d(H+'/', 'https://yandex.ru/search/?text=x').s, 'yandex');
assert.equal(d(H+'/?utm_source=chatgpt.com', '').m, 'ai');
assert.equal(d(H+'/?yclid=123', '').s, 'yandex');
assert.equal(d(H+'/?gclid=1', 'https://www.google.com/').m, 'cpc');
assert.equal(d(H+'/', 'https://www.neverend.wedding/x'), null);
assert.equal(d(H+'/', ''), null);
assert.equal(d(H+'/', 'https://partner.me/a').m, 'referral');
assert.equal(d(H+'/%D1%86%D0%B5%D0%BD%D1%8B/?utm_source=x&page=2', '').lp, '/цены/?page=2');

require('fs').writeFileSync(__dirname + '/state.json', JSON.stringify({ st, now: t + 600, v }));
console.log('JS OK');
