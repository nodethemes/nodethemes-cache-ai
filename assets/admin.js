/* NodeThemes Cache AI - admin screen. */
(function ($) {
	'use strict';
	var C = window.NTCA, T = window.NTCA_Tester;
	if (!C || !T || !document.getElementById('ntca-speed')) { return; }
	var lastResult = null;

	function esc(s) { return $('<div>').text(String(s == null ? '' : s)).html(); }

	function collect() {
		var s = { opts: {}, defer_exclude: [], lazy_skip: parseInt($('#ntca-lazy-skip').val(), 10) || 0 };
		$('.ntca-opt').each(function () { s.opts[$(this).data('k')] = this.checked ? 1 : 0; });
		s.defer_exclude = String($('#ntca-defer-ex').val() || '').split(/\n+/).map(function (x) { return x.trim(); }).filter(Boolean);
		return s;
	}
	function same(a, b) { return JSON.stringify(a.opts) === JSON.stringify(b.opts) && JSON.stringify(a.defer_exclude) === JSON.stringify(b.defer_exclude) && a.lazy_skip === b.lazy_skip; }

	function setStatus(text, cls) { $('#ntca-status').attr('class', cls || '').text(text); }
	function progress(done, total) { $('#ntca-progress').css('width', Math.round(100 * done / Math.max(1, total)) + '%'); }

	function render(res) {
		var html = '';
		res.pages.forEach(function (p) {
			var label = p.url.replace(location.origin, '') + (p.width < 600 ? ' (' + C.i18n.mobile + ')' : '');
			html += '<div class="ntca-page ' + (p.pass ? 'is-ok' : 'is-bad') + '"><strong><span class="dashicons dashicons-' + (p.pass ? 'yes-alt' : 'warning') + '"></span> ' + esc(label) + '</strong>';
			if (p.skipped) {
				html += ' <span class="description">' + esc(C.i18n.skipped) + '</span>';
			} else if (p.issues.length) {
				html += '<ul>' + p.issues.map(function (i) { return '<li>' + esc(i.text) + '</li>'; }).join('') + '</ul>';
			} else {
				html += ' <span class="description">' + esc(C.i18n.ok) + '</span>';
			}
			html += '</div>';
		});
		var hasDefer = res.pages.some(function (p) { return p.issues.some(function (i) { return i.type === 'js'; }); }) && res.settings.opts.defer_js;
		if (!res.pass && hasDefer && parseInt(C.pro, 10) !== 1) { html += '<p class="description">' + esc(C.i18n.tip_defer) + '</p>'; }
		$('#ntca-pages').html(html);
	}

	function summary(res) {
		return res.pages.map(function (p) {
			return (p.pass ? 'OK ' : 'PROBLEM ') + p.url + (p.width < 600 ? ' [phone]' : '') + (p.issues.length ? ': ' + p.issues.map(function (i) { return i.text; }).join(' | ') : '');
		}).join('\n');
	}

	async function runTest(settings) {
		$('#ntca-result').prop('hidden', false);
		$('#ntca-test, #ntca-apply').prop('disabled', true);
		$('#ntca-pages').empty();
		$('#ntca-stage-slot').show();
		$(document).trigger('ntca:test-start');
		setStatus(C.i18n.running, 'ntca-running');
		progress(0, 1);
		try {
			var res = await T.test(settings, {
				onProgress: function (text, done, total) { setStatus(text, 'ntca-running'); progress(done, total); }
			});
			progress(1, 1);
			lastResult = res;
			render(res);
			await T.post('ntca_test_done', { settings: JSON.stringify(settings), pass: res.pass ? 1 : 0, pages: res.pages.length, summary: summary(res) });
			setStatus(res.pass ? C.i18n.passed : C.i18n.failedTest, res.pass ? 'ntca-ok' : 'ntca-bad');
			$('#ntca-apply').prop('disabled', same(settings, C.live)).toggleClass('button-primary', res.pass).data('force', res.pass ? 0 : 1);
			$(document).trigger('ntca:test-done', [res]);
			return res;
		} catch (e) {
			setStatus(typeof e === 'string' ? e : C.i18n.failed, 'ntca-bad');
		} finally {
			$('#ntca-stage-slot').hide();
			$('#ntca-test').prop('disabled', false);
		}
	}

	$('#ntca-test').on('click', function () {
		var s = collect();
		runTest(s);
	});

	$('#ntca-speed').on('change input', 'input, textarea', function () {
		$('#ntca-apply').prop('disabled', true).removeClass('button-primary');
	});

	$('#ntca-apply').on('click', function () {
		var s = collect();
		var force = !lastResult || !lastResult.pass || !same(s, lastResult.settings) ? 1 : 0;
		if (force && !window.confirm(C.i18n.confirmForce)) { return; }
		var $b = $(this).prop('disabled', true);
		T.post('ntca_apply', { settings: JSON.stringify(s), force: force }).then(function (d) {
			C.live = d.live;
			setStatus(C.i18n.applied, 'ntca-ok');
		}, function (m) {
			setStatus(m, 'ntca-bad');
			$b.prop('disabled', false);
		});
	});

	// For the Pro add-on.
	window.NTCA_UI = { collect: collect, runTest: runTest, render: render, setStatus: setStatus, progress: progress, last: function () { return lastResult; }, setLast: function (r) { lastResult = r; } };
})(jQuery);
