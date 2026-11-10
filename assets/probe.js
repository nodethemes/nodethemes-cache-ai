/* NodeThemes Cache AI - preview probe. Runs only inside the admin's preview test. */
(function () {
	'use strict';
	if (window.parent === window) { return; }
	var N = window.__ntca || { e: [], r: [] };
	function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
	function key(el) {
		var parts = [];
		while (el && el.nodeType === 1 && el !== document.body && parts.length < 8) {
			var tag = el.tagName.toLowerCase();
			if (el.id && !/\d{3,}/.test(el.id)) { parts.unshift(tag + '#' + el.id); break; }
			var i = 1, s = el;
			while ((s = s.previousElementSibling)) { if (s.tagName === el.tagName) { i++; } }
			parts.unshift(tag + ':' + i);
			el = el.parentElement;
		}
		return parts.join('>');
	}
	var SEL = 'header,nav,main,footer,aside,h1,h2,h3,img,picture,video,iframe,button,form,input,select,textarea,table,ul,' +
		'.site-header,.site-footer,[class*="menu"],[class*="slider"],[class*="carousel"],[class*="hero"],[class*="banner"],' +
		'[class*="product"],[class*="woocommerce"],[class*="cart"],[class*="gallery"],[class*="popup"],[class*="modal"]';
	function measure() {
		var list = Array.prototype.slice.call(document.querySelectorAll(SEL), 0, 500);
		var items = list.map(function (el) {
			var r = el.getBoundingClientRect(), cs = getComputedStyle(el);
			return {
				k: key(el),
				w: Math.round(r.width), h: Math.round(r.height),
				v: cs.display !== 'none' && cs.visibility !== 'hidden' && parseFloat(cs.opacity || '1') > 0.05 && r.width > 0 && r.height > 0 ? 1 : 0
			};
		});
		var broken = [];
		Array.prototype.forEach.call(document.images, function (im) {
			if (im.complete && im.naturalWidth === 0 && (im.currentSrc || im.src)) {
				broken.push(String(im.currentSrc || im.src).slice(0, 300));
			}
		});
		var scripts = Array.prototype.map.call(document.querySelectorAll('script[src]'), function (s) {
			return { src: String(s.src).slice(0, 300), id: s.id || '', defer: s.defer ? 1 : 0, async: s.async ? 1 : 0 };
		}).filter(function (s) { return !/assets\/probe\.js/.test(s.src); });
		return {
			url: location.href.replace(/[?&]ntca_preview=[^&]*/, ''),
			height: Math.round(document.documentElement.scrollHeight),
			items: items,
			broken: broken.slice(0, 40),
			scripts: scripts.slice(0, 200),
			errors: N.e.slice(0, 40),
			failed: N.r.slice(0, 40),
			time: Math.round(performance.now())
		};
	}
	async function run() {
		await wait(700);
		var y = 0, guard = 0;
		while (y < document.documentElement.scrollHeight && guard < 60) {
			window.scrollTo(0, y);
			y += Math.max(300, Math.round(window.innerHeight * 0.8));
			guard++;
			await wait(110);
		}
		window.scrollTo(0, 0);
		await wait(900);
		var data = measure();
		// Add-ons can run extra checks after the measurement (for example clicking menus).
		var hooks = window.__ntcaHooks || [];
		for (var i = 0; i < hooks.length; i++) {
			try { await hooks[i](data); } catch (x) { /* a failing check must not stop the test */ }
		}
		data.errors = N.e.slice(0, 40);
		data.failed = N.r.slice(0, 40);
		data.ntca = 1;
		data.run = (location.search.match(/[?&]ntca_run=([a-z0-9]+)/) || [])[1] || '';
		window.parent.postMessage(data, location.origin);
	}
	if (document.readyState === 'complete') { run(); } else { window.addEventListener('load', run); }
})();
