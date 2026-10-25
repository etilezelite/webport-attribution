const assert = require('assert');
const { JSDOM } = require('jsdom');
const src = require('fs').readFileSync(__dirname + '/../assets/wpa.js', 'utf8');
async function page(url, ref, consentMode, cookie) {
  const dom = new JSDOM(`<form id="f"><input type="hidden" name="hidden-1" value="__wpa_source__"><input type="hidden" name="hidden-2" value="__wpa_path__"><input type="hidden" name="hidden-3" value="keep"></form>`,
    { url, referrer: ref || undefined, runScripts: 'outside-only' });
  const w = dom.window; const calls = [];
  w.fetch = (u, o) => { calls.push([u, JSON.parse(o.body)]); return Promise.resolve({ ok: true, json: () => ({ ok: false }) }); };
  if (cookie) w.document.cookie = cookie;
  w.wpaCfg = { rest: '/wp-json/wpa/v1/', consent: consentMode, pathLen: 15, tz: 'Europe/Podgorica', labels: { source: 'Источник', first: 'Первый', entry: 'вход', visits: 'Визитов', days: 'дней', path: 'Путь' } };
  w.eval(src); await new Promise(r => setTimeout(r, 50));
  return { w, calls, q: n => w.document.querySelector(`[name=${n}]`) };
}
(async () => {
  // 1. Без требований к согласию
  let p = await page('https://neverend.wedding/ceny/?utm_source=instagram&utm_medium=cpc', 'https://l.instagram.com/', 'none');
  assert.equal(p.q('hidden-1').value, 'instagram');
  assert.ok(p.q('hidden-2').value.includes('Путь: /ceny/'));
  assert.equal(p.q('hidden-3').value, 'keep');
  assert.equal(p.calls[0][0], '/wp-json/wpa/v1/s'); assert.equal(p.calls[0][1].fresh, 1);
  assert.ok(p.w.localStorage.getItem('wpa_state'));
  // перед отправкой обновляет значение, даже после замены маркера
  p.q('hidden-1').value = 'x'; p.w.document.getElementById('f').dispatchEvent(new p.w.Event('submit', { cancelable: true }));
  assert.equal(p.q('hidden-1').value, 'instagram');
  // форма появилась позже (попап/AJAX)
  const extra = p.w.document.createElement('div'); extra.innerHTML = '<input type="hidden" name="late" value="__wpa_medium__">';
  p.w.document.body.appendChild(extra); await new Promise(r => setTimeout(r, 400));
  assert.equal(p.q('late').value, 'cpc');

  // 2. GDPR Cookie Compliance без согласия: в форму источник текущей страницы, но ничего не сохраняем
  p = await page('https://neverend.wedding/?utm_source=google&utm_medium=cpc', '', 'moove:thirdparty');
  assert.equal(p.q('hidden-1').value, 'google');
  assert.equal(p.w.localStorage.getItem('wpa_state'), null); assert.equal(p.calls.length, 0);
  // согласие дали на этой странице
  p.w.document.cookie = 'moove_gdpr_popup=' + encodeURIComponent('{"strict":"1","thirdparty":"1","advanced":"0"}');
  await new Promise(r => setTimeout(r, 2200));
  assert.ok(p.w.localStorage.getItem('wpa_state')); assert.equal(p.calls[0][0], '/wp-json/wpa/v1/s');
  // отозвали
  p.w.document.cookie = 'moove_gdpr_popup=' + encodeURIComponent('{"strict":"1","thirdparty":"0","advanced":"0"}');
  await new Promise(r => setTimeout(r, 2200));
  assert.equal(p.w.localStorage.getItem('wpa_state'), null); assert.equal(p.calls[1][0], '/wp-json/wpa/v1/x');
  console.log('DOM OK'); process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
