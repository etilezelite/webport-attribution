/*! Webport Attribution 1.2.0 | Webport.Studio */
/* Логика values/summary повторяет includes/core.php — менять синхронно. */
(function (root, factory) {
	var core = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = core;
	} else if (root && root.document) {
		core.boot(root);
	}
})(typeof window !== 'undefined' ? window : this, function () {
	'use strict';

	var LS_KEY = 'wpa_state';
	var MARKER = /^__wpa_([a-z0-9_]+)__$/;
	var UTM = { utm_source: 's', utm_medium: 'm', utm_campaign: 'c', utm_content: 'co', utm_term: 't' };
	var CLICK = ['gclid', 'gbraid', 'wbraid', 'yclid', 'fbclid', 'msclkid', 'ttclid'];
	var CLICK_SRC = { gclid: 'google', gbraid: 'google', wbraid: 'google', yclid: 'yandex', msclkid: 'bing', ttclid: 'tiktok' };
	var STRIP = /^(utm_[a-z_]+|gclid|gbraid|wbraid|yclid|ysclid|fbclid|msclkid|ttclid|_openstat|from|_gl)$/i;

	// Порядок важен: почта и ИИ проверяются раньше поисковиков (mail.google.com, gemini.google.com).
	var RULES = [
		['email', /(^|\.)(mail\.google\.com|outlook\.(live|office)\.com|e\.mail\.ru|mail\.yandex\.[a-z]+|mail\.yahoo\.com)$/, null],
		['ai', /(^|\.)(chatgpt\.com|chat\.openai\.com)$/, 'chatgpt'],
		['ai', /(^|\.)perplexity\.ai$/, 'perplexity'],
		['ai', /(^|\.)claude\.ai$/, 'claude'],
		['ai', /(^|\.)gemini\.google\.com$/, 'gemini'],
		['ai', /(^|\.)copilot\.microsoft\.com$/, 'copilot'],
		['ai', /(^|\.)(chat\.)?deepseek\.com$/, 'deepseek'],
		['ai', /(^|\.)alice\.yandex\.ru$/, 'alice'],
		['organic', /(^|\.)google\.[a-z.]+$/, 'google'],
		['organic', /(^|\.)(yandex\.[a-z.]+|ya\.ru)$/, 'yandex'],
		['organic', /(^|\.)bing\.com$/, 'bing'],
		['organic', /(^|\.)duckduckgo\.com$/, 'duckduckgo'],
		['organic', /(^|\.)(search\.)?yahoo\.[a-z.]+$/, 'yahoo'],
		['organic', /(^|\.)go\.mail\.ru$/, 'mail.ru'],
		['organic', /(^|\.)search\.brave\.com$/, 'brave'],
		['organic', /(^|\.)ecosia\.org$/, 'ecosia'],
		['organic', /(^|\.)baidu\.com$/, 'baidu'],
		['organic', /(^|\.)rambler\.ru$/, 'rambler'],
		['social', /(^|\.)instagram\.com$/, 'instagram'],
		['social', /(^|\.)(facebook\.com|fb\.me|fb\.com)$/, 'facebook'],
		['social', /(^|\.)(t\.co|twitter\.com|x\.com)$/, 'twitter'],
		['social', /(^|\.)(vk\.com|vk\.ru)$/, 'vk'],
		['social', /(^|\.)ok\.ru$/, 'ok'],
		['social', /(^|\.)(linkedin\.com|lnkd\.in)$/, 'linkedin'],
		['social', /(^|\.)pinterest\.[a-z.]+$/, 'pinterest'],
		['social', /(^|\.)(youtube\.com|youtu\.be)$/, 'youtube'],
		['social', /(^|\.)tiktok\.com$/, 'tiktok'],
		['social', /(^|\.)(t\.me|telegram\.org|telegram\.me)$/, 'telegram'],
		['social', /(^|\.)threads\.(net|com)$/, 'threads'],
		['social', /(^|\.)(whatsapp\.com|wa\.me)$/, 'whatsapp'],
		['social', /(^|\.)viber\.com$/, 'viber'],
		['social', /(^|\.)reddit\.com$/, 'reddit'],
		['social', /(^|\.)dzen\.ru$/, 'dzen']
	];

	function cut(v, n) {
		return String(v == null ? '' : v).replace(/[\u0000-\u001f<>]/g, '').trim().slice(0, n);
	}

	function normHost(h) {
		return String(h || '').toLowerCase().replace(/^www\./, '');
	}

	function classifyRef(host) {
		for (var i = 0; i < RULES.length; i++) {
			if (RULES[i][1].test(host)) {
				return { s: RULES[i][2] || host, m: RULES[i][0] };
			}
		}
		return { s: host, m: 'referral' };
	}

	function cleanPath(url) {
		var path = url.pathname || '/';
		try { path = decodeURIComponent(path); } catch (e) {}
		var q = [];
		url.searchParams.forEach(function (v, k) {
			if (!STRIP.test(k)) { q.push(k + '=' + v); }
		});
		return cut(path + (q.length ? '?' + q.join('&') : ''), 300) || '/';
	}

	/** Внешний заход с этой страницы (UTM, click-ID или чужой referrer) или null. */
	function detectTouch(href, referrer, now) {
		var url;
		try { url = new URL(href); } catch (e) { return null; }
		var p = url.searchParams, t = { ts: now, lp: cleanPath(url) }, click = '';
		for (var k in UTM) {
			if (p.get(k)) { t[UTM[k]] = cut(p.get(k), 200); }
		}
		for (var i = 0; i < CLICK.length; i++) {
			if (p.get(CLICK[i])) { click = CLICK[i]; t.k = click + ':' + cut(p.get(click), 240); break; }
		}
		var ref = '';
		if (referrer) {
			try { ref = normHost(new URL(referrer).hostname); } catch (e) {}
			if (ref === normHost(url.hostname)) { ref = ''; }
		}
		if (ref) { t.r = ref; }

		if (t.s) {
			if (!t.m) {
				// utm_source без utm_medium (ChatGPT ставит utm_source=chatgpt.com): канал по источнику или рефереру.
				var bySrc = classifyRef(normHost(t.s));
				t.m = bySrc.m !== 'referral' ? bySrc.m : (ref ? classifyRef(ref).m : '(not set)');
			}
		} else if (click && CLICK_SRC[click]) {
			t.s = CLICK_SRC[click]; t.m = 'cpc';
		} else if (ref) {
			var c = classifyRef(ref); t.s = c.s; t.m = c.m;
		} else if (click === 'fbclid') {
			t.s = 'facebook'; t.m = 'social';
		} else {
			return null;
		}
		return t;
	}

	function sameTouch(a, b) {
		if (!a || !b) { return false; }
		var keys = ['s', 'm', 'c', 'co', 't', 'k'];
		for (var i = 0; i < keys.length; i++) {
			if ((a[keys[i]] || '') !== (b[keys[i]] || '')) { return false; }
		}
		return true;
	}

	/** Обновить состояние на просмотр страницы. Чистая функция — тестируется в node. */
	function update(state, href, referrer, now, cfg) {
		cfg = cfg || {};
		var len = cfg.pathLen || 15, sessionSec = (cfg.sessionMin || 30) * 60;
		var touch = detectTouch(href, referrer, now);
		var fresh = false, changed = false, newVisit = false;
		var cur = '/';
		try { cur = cleanPath(new URL(href)); } catch (e) {}

		if (!state || !state.f || !state.l) {
			var t = touch || { s: '(direct)', m: '(none)', ts: now, lp: cur };
			state = { v: 1, f: t, l: t, n: 1, la: now, p: [], pt: 0 };
			fresh = changed = newVisit = true;
		} else {
			newVisit = (now - (state.la || 0)) > sessionSec;
			if (touch && !sameTouch(touch, state.l)) {
				state.l = touch; changed = true; newVisit = true;
			} else if (touch && newVisit) {
				state.l = touch; // тот же источник снова, но новый визит — обновляем дату и вход
			}
			if (newVisit) { state.n = (state.n || 1) + 1; }
		}

		state.p = state.p || [];
		var last = state.p[state.p.length - 1];
		if (!last || last[0] !== cur || newVisit) {
			state.p.push([cur, now, state.n]);
		}
		if (state.p.length > len) {
			state.p = state.p.slice(-len);
			state.pt = 1;
		}
		state.la = now;
		return { state: state, fresh: fresh, changed: changed, newVisit: newVisit };
	}

	function fmtDate(ts, tz) {
		if (!ts) { return ''; }
		var d = new Date(ts * 1000), y, m, day;
		try {
			var parts = new Intl.DateTimeFormat('en-GB', { timeZone: tz || undefined, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(d);
			parts.forEach(function (p) {
				if (p.type === 'year') { y = p.value; }
				if (p.type === 'month') { m = p.value; }
				if (p.type === 'day') { day = p.value; }
			});
		} catch (e) {}
		if (!y) {
			y = d.getFullYear(); m = ('0' + (d.getMonth() + 1)).slice(-2); day = ('0' + d.getDate()).slice(-2);
		}
		return day + '.' + m + '.' + y;
	}

	function fmtTouch(t) {
		return [t.s, t.m, t.c].filter(function (x) { return x; }).join(' / ');
	}

	function days(st, now) {
		return Math.max(0, Math.floor((now - st.f.ts) / 86400));
	}

	function summary(st, now, cfg) {
		if (!st) { return ''; }
		var lab = (cfg && cfg.labels) || { source: 'Source', first: 'First visit', entry: 'landing', visits: 'Visits', days: 'days to lead', path: 'Path' };
		var tz = cfg && cfg.tz, f = st.f, l = st.l, parts = [];
		parts.push(lab.source + ': ' + fmtTouch(l) + ' (' + fmtDate(l.ts, tz) + ', ' + lab.entry + ' ' + l.lp + ')');
		if (!(f.ts === l.ts && f.s === l.s)) {
			parts.push(lab.first + ': ' + fmtTouch(f) + ' (' + fmtDate(f.ts, tz) + ', ' + lab.entry + ' ' + f.lp + ')');
		}
		parts.push(lab.visits + ': ' + st.n + ', ' + lab.days + ': ' + days(st, now));
		if (st.p && st.p.length) {
			var path = st.pt ? '… → ' : '', prev = null;
			st.p.forEach(function (e, i) {
				if (i > 0) { path += (e[2] !== prev) ? ' ‖ ' : ' → '; }
				path += e[0]; prev = e[2];
			});
			parts.push(lab.path + ': ' + path);
		}
		return parts.join(' | ');
	}

	function clickPart(t, name) {
		if (!t || !t.k) { return ''; }
		var i = t.k.indexOf(':');
		return (i > 0 && t.k.slice(0, i) === name) ? t.k.slice(i + 1) : '';
	}

	var KEYS = ['source', 'medium', 'campaign', 'content', 'term', 'first_source', 'first_medium', 'first_campaign',
		'landing', 'last_landing', 'referrer', 'first_referrer', 'click_id', 'gclid', 'yclid', 'fbclid',
		'ym_uid', 'ga_cid', 'fbp', 'fbc', 'visits', 'days', 'first_date', 'path'];

	function values(st, cookies, now, cfg) {
		var v = {};
		KEYS.forEach(function (k) { v[k] = ''; });
		cookies = cookies || {};
		var ga = String(cookies._ga || '').split('.');
		if (ga.length >= 4) { v.ga_cid = cut(ga.slice(-2).join('.'), 100); }
		v.ym_uid = cut(cookies._ym_uid, 100);
		v.fbp = cut(cookies._fbp, 200);
		v.fbc = cut(cookies._fbc, 300);
		if (!st) { return v; }
		var f = st.f, l = st.l;
		v.source = l.s; v.medium = l.m; v.campaign = l.c || ''; v.content = l.co || ''; v.term = l.t || '';
		v.first_source = f.s; v.first_medium = f.m; v.first_campaign = f.c || '';
		v.landing = f.lp; v.last_landing = l.lp; v.referrer = l.r || ''; v.first_referrer = f.r || '';
		v.click_id = l.k || '';
		v.gclid = clickPart(l, 'gclid'); v.yclid = clickPart(l, 'yclid'); v.fbclid = clickPart(l, 'fbclid');
		if (!v.fbc && v.fbclid) { v.fbc = 'fb.1.' + (l.ts * 1000) + '.' + v.fbclid; }
		v.visits = String(st.n);
		v.days = String(days(st, now));
		v.first_date = fmtDate(f.ts, cfg && cfg.tz);
		v.path = summary(st, now, cfg);
		return v;
	}

	/* ---------------------------------------------------------------- браузер */

	function boot(w) {
		var d = w.document, cfg = w.wpaCfg || {}, mem = null, consent = null;
		var nowSec = function () { return Math.floor(Date.now() / 1000); };

		function cookies() {
			var out = {};
			(d.cookie || '').split(';').forEach(function (c) {
				var i = c.indexOf('=');
				if (i > 0) {
					var k = c.slice(0, i).trim(), val = c.slice(i + 1).trim();
					try { val = decodeURIComponent(val); } catch (e) {}
					out[k] = val;
				}
			});
			return out;
		}

		function hasConsent() {
			var mode = cfg.consent || 'none';
			if (mode === 'none') { return true; }
			if (mode === 'manual') { return w.wpaConsentGiven === true; }
			if (mode.indexOf('moove:') === 0) {
				try {
					var data = JSON.parse(cookies().moove_gdpr_popup || 'null');
					return !!data && parseInt(data[mode.slice(6)], 10) === 1;
				} catch (e) { return false; }
			}
			return true;
		}

		function load() {
			try { return JSON.parse(w.localStorage.getItem(LS_KEY) || 'null'); } catch (e) { return null; }
		}
		function save() {
			try { w.localStorage.setItem(LS_KEY, JSON.stringify(mem)); } catch (e) {}
		}
		function drop() {
			try { w.localStorage.removeItem(LS_KEY); } catch (e) {}
		}

		function post(route, body) {
			if (!cfg.rest || !w.fetch) { return null; }
			return w.fetch(cfg.rest + route, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(body || {}),
				keepalive: true
			}).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
		}

		function sync(fresh) {
			var p = post('s', { state: mem, fresh: fresh ? 1 : 0, consent: 1 });
			if (p) {
				p.then(function (j) {
					if (j && j.ok && j.state && j.state.f) { mem = j.state; save(); fill(d); }
				});
			}
		}

		function fill(scope) {
			var list = (scope && scope.querySelectorAll ? scope : d).querySelectorAll('input[type="hidden"]');
			var vals = null;
			for (var i = 0; i < list.length; i++) {
				var inp = list[i], key = inp.getAttribute('data-wpa-key');
				if (!key) {
					var m = MARKER.exec(inp.value || '');
					if (!m) { continue; }
					key = m[1];
					inp.setAttribute('data-wpa-key', key);
				}
				if (!vals) { vals = values(mem, cookies(), nowSec(), cfg); }
				inp.value = vals[key] != null ? vals[key] : '';
			}
		}

		function start() {
			consent = hasConsent();
			var r = update(consent ? load() : null, w.location.href, d.referrer, nowSec(), cfg);
			mem = r.state;
			if (consent) {
				save();
				if (r.fresh || r.changed || r.newVisit) { sync(r.fresh); }
			} else if (cookies().wpa_on) {
				post('x');
			}
			fill(d);
		}

		// Согласие могли дать или отозвать на этой странице.
		function recheck() {
			var c = hasConsent();
			if (c === consent) { return; }
			consent = c;
			if (c) { save(); sync(true); } else { drop(); post('x'); }
		}

		w.wpaConsent = function (given) { w.wpaConsentGiven = !!given; recheck(); };

		// Перед отправкой — заполнить свежими значениями (фаза перехвата, раньше обработчика Forminator).
		d.addEventListener('submit', function (e) { fill(e.target); }, true);

		var t = null;
		if (w.MutationObserver) {
			new w.MutationObserver(function () {
				clearTimeout(t);
				t = setTimeout(function () { fill(d); }, 250);
			}).observe(d.documentElement, { childList: true, subtree: true });
		}
		w.addEventListener('pageshow', function () { fill(d); });
		if ((cfg.consent || 'none') !== 'none') { setInterval(recheck, 2000); }

		if (d.readyState === 'loading') {
			d.addEventListener('DOMContentLoaded', start);
		} else {
			start();
		}
	}

	return {
		boot: boot, update: update, values: values, summary: summary,
		detectTouch: detectTouch, classifyRef: classifyRef, fmtDate: fmtDate
	};
});
