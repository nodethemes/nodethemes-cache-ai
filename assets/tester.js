/* NodeThemes Cache AI - preview tester. Loads pages in the admin's browser with and without the
 * new settings and compares JavaScript errors, files that fail to load, broken images and the size
 * of the main page elements. Used by the free plugin and by the Pro add-on. */
(function (w, $) {
	'use strict';
	var C = w.NTCA || {};
	var baseCache = {};

	function post(action, data) {
		return $.post(C.ajax, $.extend({ action: action, nonce: C.nonce }, data || {})).then(function (r) {
			if (!r || !r.success) { return $.Deferred().reject((r && r.data && r.data.message) || C.i18n.failed).promise(); }
			return r.data;
		});
	}

	function stage() {
		var box = document.getElementById('ntca-stage');
		if (!box) {
			box = document.createElement('div');
			box.id = 'ntca-stage';
			box.className = 'ntca-stage';
			(document.getElementById('ntca-stage-slot') || document.body).appendChild(box);
		}
		return box;
	}

	function load(url, pid, width) {
		return new Promise(function (resolve) {
			var run = Math.random().toString(36).slice(2, 10).replace(/[^a-z0-9]/g, 'x');
			var u = url + (url.indexOf('?') > -1 ? '&' : '?') + 'ntca_preview=' + encodeURIComponent(pid) + '&ntca_run=' + run;
			var box = stage();
			var scale = Math.min(1, 440 / width, 330 / 900); // Small preview window, also at phone width.
			box.style.width = Math.round(width * scale) + 'px';
			box.style.height = Math.round(900 * scale) + 'px';
			var f = document.createElement('iframe');
			f.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms');
			f.setAttribute('title', C.i18n.testing);
			f.style.width = width + 'px';
			f.style.height = '900px';
			f.style.transform = 'scale(' + scale + ')';
			f.style.transformOrigin = '0 0';
			var done = false;
			function finish(data) {
				if (done) { return; }
				done = true;
				w.removeEventListener('message', onMsg);
				clearTimeout(t);
				setTimeout(function () { if (f.parentNode) { f.parentNode.removeChild(f); } }, 50);
				resolve(data);
			}
			function onMsg(ev) {
				if (ev.origin !== location.origin || !ev.data || ev.data.ntca !== 1 || ev.data.run !== run) { return; }
				finish(ev.data);
			}
			w.addEventListener('message', onMsg);
			var t = setTimeout(function () { finish({ timeout: 1, url: url, items: [], errors: [], failed: [], broken: [], scripts: [], height: 0 }); }, 35000);
			f.src = u;
			box.innerHTML = '';
			box.appendChild(f);
		});
	}

	function file(u) { return String(u || '').split('?')[0].split('#')[0]; }
	function short(u) { var f = file(u); var p = f.split('/'); return p.slice(-2).join('/'); }
	function sig(e) { return String(e.m || '').replace(/\d+/g, '#').slice(0, 160) + '|' + file(e.f).split('/').pop(); }

	function compare(base, base2, cand) {
		var issues = [];
		if (cand.timeout && !base.timeout) {
			issues.push({ type: 'timeout', text: C.i18n.timeout });
			return { pass: false, issues: issues, newErrors: [], changed: [] };
		}
		if (base.timeout) {
			return { pass: true, issues: [], skipped: 1, newErrors: [], changed: [] };
		}
		var known = {};
		(base.errors || []).concat(base2.errors || []).forEach(function (e) { known[sig(e)] = 1; });
		var newErrors = (cand.errors || []).filter(function (e) { return !known[sig(e)]; });
		newErrors.forEach(function (e) {
			issues.push({ type: 'js', text: C.i18n.jsError + ' ' + e.m + (e.f ? ' (' + short(e.f) + (e.l ? ':' + e.l : '') + ')' : ''), m: e.m, f: e.f });
		});
		var kf = {};
		(base.failed || []).concat(base2.failed || []).forEach(function (r) { kf[file(r.url)] = 1; });
		(cand.failed || []).forEach(function (r) {
			if (!kf[file(r.url)]) { issues.push({ type: 'file', text: C.i18n.fileFailed + ' ' + short(r.url), url: r.url }); }
		});
		var kb = {};
		(base.broken || []).concat(base2.broken || []).forEach(function (u) { kb[file(u)] = 1; });
		(cand.broken || []).forEach(function (u) {
			if (!kb[file(u)]) { issues.push({ type: 'image', text: C.i18n.imageBroken + ' ' + short(u), url: u }); }
		});
		var m1 = {}, m2 = {}, mc = {};
		(base.items || []).forEach(function (i) { m1[i.k] = i; });
		(base2.items || []).forEach(function (i) { m2[i.k] = i; });
		(cand.items || []).forEach(function (i) { mc[i.k] = i; });
		function differs(a, b, tol) {
			if (!a || !b) { return true; }
			if (a.v !== b.v) { return true; }
			if (!a.v) { return false; }
			return Math.abs(a.w - b.w) > Math.max(tol, a.w * 0.15) || Math.abs(a.h - b.h) > Math.max(tol, a.h * 0.15);
		}
		var changed = [], visible = 0;
		Object.keys(m1).forEach(function (k) {
			var a = m1[k];
			if (a.v) { visible++; }
			if (differs(a, m2[k], 4)) { return; } // Changes on its own (slider, random content): ignore.
			var c = mc[k];
			if (!c) { if (a.v) { changed.push({ k: k, why: 'missing' }); } return; }
			if (differs(a, c, 24)) { changed.push({ k: k, why: a.v !== c.v ? (c.v ? 'shown' : 'hidden') : 'size', from: [a.w, a.h], to: [c.w, c.h] }); }
		});
		if (changed.length > Math.max(3, visible * 0.03)) {
			issues.push({ type: 'layout', text: C.i18n.layout.replace('%d', changed.length) });
		}
		if (base.height > 0) {
			var dh = Math.abs(cand.height - base.height) / base.height;
			var noise = Math.abs(base2.height - base.height) / base.height;
			if (dh > 0.12 && noise < 0.04) {
				issues.push({ type: 'height', text: C.i18n.height.replace('%1', base.height).replace('%2', cand.height) });
			}
		}
		return { pass: issues.length === 0, issues: issues, newErrors: newErrors, changed: changed.slice(0, 30) };
	}

	function baseSettings(s) {
		var b = $.extend(true, {}, s);
		Object.keys(b.opts || {}).forEach(function (k) { b.opts[k] = 0; });
		return b;
	}

	/**
	 * Test settings on the test pages.
	 * opts: { urls, mobile (bool), onProgress(text, done, total), keepBase (bool) }
	 */
	async function test(settings, opts) {
		opts = opts || {};
		var urls = (opts.urls || C.urls || []).slice(0, 8);
		var targets = [];
		urls.forEach(function (u, i) {
			targets.push({ url: u, width: 1280 });
			if (i === 0 && opts.mobile !== false) { targets.push({ url: u, width: 390 }); }
		});
		var total = targets.length, step = 0;
		var basePid = await post('ntca_preview', { settings: JSON.stringify(baseSettings(settings)) });
		var candPid = await post('ntca_preview', { settings: JSON.stringify(settings) });
		var pages = [];
		for (var i = 0; i < targets.length; i++) {
			var t = targets[i];
			var bk = t.url + '|' + t.width;
			if (opts.onProgress) { opts.onProgress(C.i18n.page.replace('%1', i + 1).replace('%2', total) + ' ' + t.url + (t.width < 600 ? ' (' + C.i18n.mobile + ')' : ''), step, total); }
			if (!baseCache[bk] || opts.keepBase === false) {
				var b1 = await load(t.url, basePid.id, t.width);
				var b2 = await load(t.url, basePid.id, t.width);
				baseCache[bk] = [b1, b2];
			}
			var cand = await load(t.url, candPid.id, t.width);
			var cmp = compare(baseCache[bk][0], baseCache[bk][1], cand);
			pages.push({ url: t.url, width: t.width, pass: cmp.pass, issues: cmp.issues, newErrors: cmp.newErrors, changed: cmp.changed, scripts: cand.scripts || [], baseScripts: baseCache[bk][0].scripts || [], baseErrors: (baseCache[bk][0].errors || []).concat(baseCache[bk][1].errors || []), skipped: cmp.skipped || 0 });
			step++;
			if (opts.stopOnFail && !cmp.pass) { break; }
		}
		var pass = pages.every(function (p) { return p.pass; });
		return { pass: pass, pages: pages, hash: candPid.hash, settings: settings };
	}

	w.NTCA_Tester = { test: test, compare: compare, load: load, post: post, resetBase: function () { baseCache = {}; } };
})(window, jQuery);
