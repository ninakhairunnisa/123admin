/**
 * 123Admin panel core: API client, router, UI primitives, theme, shortcuts.
 * Vanilla JS only — no frameworks, no build step.
 */
(function () {
	'use strict';

	const BOOT = window.WFCP || {};
	const T = BOOT.i18n || {};

	/* ---------- Helpers ---------- */

	const $ = (sel, ctx) => (ctx || document).querySelector(sel);
	const $$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));

	const esc = (value) =>
		String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({
			'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
		}[c]));

	const numberFmt = new Intl.NumberFormat(BOOT.locale ? BOOT.locale.replace('_', '-') : 'en');

	const money = (value) => numberFmt.format(Math.round((Number(value) || 0) * 100) / 100) + ' ' + (BOOT.currency || '');
	const num = (value) => numberFmt.format(Number(value) || 0);

	const debounce = (fn, ms) => {
		let timer;
		return (...args) => {
			clearTimeout(timer);
			timer = setTimeout(() => fn(...args), ms || 300);
		};
	};

	const can = (cap) => !!(BOOT.caps && BOOT.caps[cap]);

	/* ---------- API client ---------- */

	async function api(path, options) {
		const opts = Object.assign({ method: 'GET' }, options || {});
		const headers = { 'X-WP-Nonce': BOOT.nonce };
		if (opts.body && typeof opts.body !== 'string') {
			headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(opts.body);
		}
		opts.headers = Object.assign(headers, opts.headers || {});
		opts.credentials = 'same-origin';

		let response;
		try {
			response = await fetch(BOOT.apiRoot + path, opts);
		} catch (err) {
			toast(T.offline || 'Offline', 'error');
			throw err;
		}

		const data = await response.json().catch(() => ({}));
		if (!response.ok) {
			// Expired wp_rest nonce (panel left open > nonce lifetime): reload
			// to obtain a fresh one instead of failing every request silently.
			if (response.status === 403 && data && data.code === 'rest_cookie_invalid_nonce') {
				toast(T.session_expired || 'Session expired. Reloading…', 'error');
				setTimeout(() => location.reload(), 1200);
			}
			const message = data && data.message ? data.message : (T.error || 'Error');
			toast(message, 'error');
			const error = new Error(message);
			error.status = response.status;
			throw error;
		}
		return data;
	}

	/* ---------- Toast ---------- */

	function toast(message, type, onClick) {
		let host = $('#toasts');
		if (!host) {
			host = document.createElement('div');
			host.id = 'toasts';
			document.body.appendChild(host);
		}
		const el = document.createElement('div');
		el.className = 'toast' + (type ? ' ' + type : '');
		el.textContent = message;
		if (onClick) {
			el.style.pointerEvents = 'auto';
			el.style.cursor = 'pointer';
			el.addEventListener('click', () => {
				el.remove();
				onClick();
			});
		}
		host.appendChild(el);
		setTimeout(() => {
			el.style.opacity = '0';
			el.style.transition = 'opacity .3s';
			setTimeout(() => el.remove(), 320);
		}, onClick ? 6000 : 3200);
	}

	/* ---------- Modal ---------- */

	function modal(title, bodyHtml, footerHtml) {
		closeModal();
		const backdrop = document.createElement('div');
		backdrop.className = 'modal-backdrop';
		backdrop.innerHTML =
			'<div class="modal" role="dialog" aria-modal="true">' +
			'<header><h2>' + esc(title) + '</h2>' +
			'<button class="icon-btn" data-close aria-label="' + esc(T.close || 'Close') + '">✕</button></header>' +
			'<div class="body">' + bodyHtml + '</div>' +
			(footerHtml ? '<footer>' + footerHtml + '</footer>' : '') +
			'</div>';
		backdrop.addEventListener('click', (e) => {
			if (e.target === backdrop || e.target.closest('[data-close]')) closeModal();
		});
		document.body.appendChild(backdrop);
		const first = backdrop.querySelector('input, select, textarea, button:not([data-close])');
		if (first) first.focus();
		return backdrop;
	}

	function closeModal() {
		$$('.modal-backdrop').forEach((el) => el.remove());
	}

	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') closeModal();
	});

	/* ---------- Confirm dialog ---------- */

	function confirmDialog(message) {
		return new Promise((resolve) => {
			const m = modal(T.confirm_delete ? '⚠' : '!', '<p>' + esc(message) + '</p>',
				'<button class="btn outline" data-no>' + esc(T.cancel || 'Cancel') + '</button>' +
				'<button class="btn danger" data-yes>' + esc(T.yes || 'Yes') + '</button>');
			m.querySelector('[data-no]').addEventListener('click', () => { closeModal(); resolve(false); });
			m.querySelector('[data-yes]').addEventListener('click', () => { closeModal(); resolve(true); });
		});
	}

	/* ---------- Theme ---------- */

	function applyTheme(mode) {
		const resolved = mode === 'auto'
			? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
			: mode;
		document.documentElement.setAttribute('data-theme', resolved);
		localStorage.setItem('wfcp-theme', mode);
	}

	function cycleTheme() {
		const order = ['auto', 'light', 'dark'];
		const current = localStorage.getItem('wfcp-theme') || BOOT.theme || 'auto';
		const next = order[(order.indexOf(current) + 1) % order.length];
		applyTheme(next);
		toast((T.theme || 'Theme') + ': ' + (T['theme_' + next] || next));
	}

	/* ---------- Router ---------- */

	const routes = {};

	function route(name, renderer) {
		routes[name] = renderer;
	}

	function navigate(hash) {
		location.hash = hash;
	}

	function currentRoute() {
		const raw = location.hash.replace(/^#\/?/, '') || 'dashboard';
		const [pathPart, queryPart] = raw.split('?');
		const segments = pathPart.split('/').filter(Boolean);
		const params = new URLSearchParams(queryPart || '');
		return { name: segments[0] || 'dashboard', segments, params };
	}

	async function render() {
		const { name, segments, params } = currentRoute();
		const renderer = routes[name] || routes.dashboard;
		const main = $('#view');
		if (!main) return;
		$$('.nav-item').forEach((el) => el.classList.toggle('active', el.dataset.route === name));
		main.innerHTML = '<div class="spinner"></div>';
		try {
			await renderer(main, segments.slice(1), params);
		} catch (err) {
			main.innerHTML = '<div class="empty"><span class="ico">⚠</span>' + esc(err.message || T.error) + '</div>';
		}
		window.scrollTo({ top: 0 });
	}

	window.addEventListener('hashchange', render);

	/* ---------- Shell ---------- */

	const NAV = [
		{ route: 'dashboard', icon: '▦', label: T.dashboard, cap: 'wfcp_access' },
		{ route: 'products', icon: '◫', label: T.products, cap: 'wfcp_products_view' },
		{ route: 'orders', icon: '🛒', label: T.orders, cap: 'wfcp_orders_view' },
		{ route: 'customers', icon: '👤', label: T.customers, cap: 'wfcp_users_view' },
		{ route: 'reports', icon: '📈', label: T.reports, cap: 'wfcp_reports_view' },
		{ route: 'settings', icon: '⚙', label: T.settings, cap: 'wfcp_access', admin: true },
	];

	function buildShell() {
		const app = $('#app');
		document.body.classList.remove('wfcp-loading');

		const navItems = NAV
			.filter((item) => can(item.cap))
			.map((item) =>
				'<button class="nav-item" data-route="' + item.route + '">' +
				'<span class="ico">' + item.icon + '</span><span>' + esc(item.label || item.route) + '</span></button>'
			).join('');

		app.innerHTML =
			'<div class="shell">' +
			'<header class="appbar">' +
			'<div class="brand"><img src="' + esc(BOOT.assets && BOOT.assets.icon ? BOOT.assets.icon : '') + '" alt="">' +
			'<span>' + esc(BOOT.siteName) + '</span></div>' +
			'<div class="grow"></div>' +
			'<div class="gsearch"><span>🔍</span><input id="gsearch" type="search" placeholder="' + esc(T.global_search || 'Search') + '" autocomplete="off"></div>' +
			'<div class="grow"></div>' +
			'<button class="icon-btn" id="theme-toggle" title="' + esc(T.theme || 'Theme') + '">◐</button>' +
			'<button class="icon-btn" id="user-menu" title="' + esc(BOOT.user ? BOOT.user.name : '') + '">' +
			'<img class="avatar" src="' + esc(BOOT.user ? BOOT.user.avatar : '') + '" alt=""></button>' +
			'</header>' +
			'<nav class="nav">' + navItems + '</nav>' +
			'<main class="main" id="view"></main>' +
			'</div><div id="toasts"></div><div id="print-area"></div>';

		$$('.nav-item').forEach((el) =>
			el.addEventListener('click', () => navigate('/' + el.dataset.route))
		);

		$('#theme-toggle').addEventListener('click', cycleTheme);

		$('#user-menu').addEventListener('click', () => {
			modal(esc(BOOT.user.name),
				'<div class="list">' +
				'<div class="list-item" id="mi-wp"><span>🔧</span><div class="grow"><div class="title">' + esc(T.wp_admin || 'WP Admin') + '</div></div></div>' +
				'<div class="list-item" id="mi-out"><span>🚪</span><div class="grow"><div class="title">' + esc(T.logout || 'Log out') + '</div></div></div>' +
				'</div><p class="muted">' + esc(T.shortcut_hint || '') + '</p>');
			$('#mi-wp').addEventListener('click', () => { location.href = BOOT.wpAdminUrl; });
			$('#mi-out').addEventListener('click', () => { location.href = BOOT.logoutUrl; });
		});

		// Global search routes to the most relevant list.
		$('#gsearch').addEventListener('keydown', (e) => {
			if (e.key !== 'Enter') return;
			const q = e.target.value.trim();
			if (!q) return;
			const target = /^\d+$/.test(q) || q.includes('@') ? 'orders' : 'products';
			navigate('/' + target + '?search=' + encodeURIComponent(q));
		});
	}

	/* ---------- Keyboard shortcuts ---------- */

	let chordPending = false;
	document.addEventListener('keydown', (e) => {
		const tag = (e.target.tagName || '').toLowerCase();
		if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

		if (e.key === '/') {
			e.preventDefault();
			const search = $('#gsearch');
			if (search) search.focus();
			return;
		}
		if (e.key === 'g') {
			chordPending = true;
			setTimeout(() => { chordPending = false; }, 900);
			return;
		}
		if (chordPending) {
			const map = { d: 'dashboard', p: 'products', o: 'orders', c: 'customers', r: 'reports', s: 'settings' };
			if (map[e.key]) navigate('/' + map[e.key]);
			chordPending = false;
		}
	});

	/* ---------- Shared UI builders ---------- */

	function pager(data, onPage) {
		if (!data.total_pages || data.total_pages <= 1) return document.createElement('div');
		const el = document.createElement('div');
		el.className = 'pager';
		el.innerHTML =
			'<button class="btn outline sm" data-prev ' + (data.page <= 1 ? 'disabled' : '') + '>' + esc(T.prev || '‹') + '</button>' +
			'<span>' + esc(T.page || 'Page') + ' ' + num(data.page) + ' ' + esc(T.of || '/') + ' ' + num(data.total_pages) + '</span>' +
			'<button class="btn outline sm" data-next ' + (data.page >= data.total_pages ? 'disabled' : '') + '>' + esc(T.next || '›') + '</button>';
		el.querySelector('[data-prev]').addEventListener('click', () => onPage(data.page - 1));
		el.querySelector('[data-next]').addEventListener('click', () => onPage(data.page + 1));
		return el;
	}

	function statusPill(status) {
		const label = (BOOT.statuses && BOOT.statuses['wc-' + status]) || status;
		return '<span class="status-pill ' + esc(status) + '">' + esc(label) + '</span>';
	}

	function downloadCsv(payload) {
		const blob = new Blob([payload.csv], { type: 'text/csv;charset=utf-8' });
		const a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = payload.filename || 'export.csv';
		a.click();
		URL.revokeObjectURL(a.href);
		if (payload.truncated) {
			toast(T.export_truncated || 'Export truncated.', 'error');
		}
	}

	function printHtml(html) {
		$('#print-area').innerHTML = html;
		window.print();
	}

	/* ---------- Tiny SVG charts ---------- */

	function lineChart(series, valueKey) {
		if (!series || !series.length) return '<div class="empty">' + esc(T.no_results || '–') + '</div>';
		const W = 600, H = 180, padX = 6, padY = 16;
		const values = series.map((p) => Number(p[valueKey]) || 0);
		const max = Math.max.apply(null, values.concat([1]));
		const stepX = (W - padX * 2) / Math.max(1, series.length - 1);
		const pts = values.map((v, i) =>
			(padX + i * stepX).toFixed(1) + ',' + (H - padY - (v / max) * (H - padY * 2)).toFixed(1)
		);
		const labelEvery = Math.ceil(series.length / 6);
		const labels = series.map((p, i) => i % labelEvery ? '' :
			'<text class="axis" x="' + (padX + i * stepX).toFixed(1) + '" y="' + (H - 3) + '" text-anchor="middle">' +
			esc(String(p.date || p.label || '').slice(5)) + '</text>'
		).join('');
		return '<div class="chart"><svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none">' +
			'<polygon class="area" points="' + padX + ',' + (H - padY) + ' ' + pts.join(' ') + ' ' + (padX + (series.length - 1) * stepX).toFixed(1) + ',' + (H - padY) + '"/>' +
			'<polyline class="line" points="' + pts.join(' ') + '"/>' + labels + '</svg></div>';
	}

	function barChart(series, valueKey) {
		if (!series || !series.length) return '<div class="empty">' + esc(T.no_results || '–') + '</div>';
		const W = 600, H = 180, padY = 16;
		const values = series.map((p) => Number(p[valueKey]) || 0);
		const max = Math.max.apply(null, values.concat([1]));
		const barW = W / series.length;
		const bars = values.map((v, i) => {
			const h = (v / max) * (H - padY * 2);
			return '<rect class="bar" x="' + (i * barW + 1).toFixed(1) + '" y="' + (H - padY - h).toFixed(1) +
				'" width="' + Math.max(1, barW - 2).toFixed(1) + '" height="' + h.toFixed(1) + '"/>';
		}).join('');
		return '<div class="chart"><svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none">' + bars + '</svg></div>';
	}

	/* ---------- Live new-order notification ---------- */

	let lastOrderId = 0;
	function startOrderPolling() {
		if (!can('wfcp_orders_view')) return;
		setInterval(async () => {
			try {
				const data = await api('/dashboard/ping?last_order=' + lastOrderId);
				if (data.has_new) {
					toast(T.new_order_arrived || 'New order!', 'success', () => navigate('/orders?range=today'));
				}
				lastOrderId = data.last_order || lastOrderId;
			} catch (err) { /* silent */ }
		}, 30000);
		api('/dashboard/ping?last_order=0').then((d) => { lastOrderId = d.last_order || 0; }).catch(() => {});
	}

	/* ---------- PWA ---------- */

	if ('serviceWorker' in navigator && BOOT.panelUrl) {
		navigator.serviceWorker.register(BOOT.panelUrl + 'sw.js', { scope: BOOT.panelUrl }).catch(() => {});
	}

	/* ---------- Boot ---------- */

	applyTheme(localStorage.getItem('wfcp-theme') || BOOT.theme || 'auto');
	window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
		applyTheme(localStorage.getItem('wfcp-theme') || BOOT.theme || 'auto');
	});

	document.addEventListener('DOMContentLoaded', () => {
		buildShell();
		render();
		startOrderPolling();
	});

	// Public namespace used by views.js and third-party extensions.
	window.WFCPApp = {
		api, route, navigate, render, currentRoute,
		modal, closeModal, confirmDialog, toast,
		esc, money, num, debounce, can, pager, statusPill,
		downloadCsv, printHtml, lineChart, barChart,
		T, BOOT, $, $$,
	};
})();
