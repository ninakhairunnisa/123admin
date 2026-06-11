/**
 * 123Admin panel views: dashboard, products, orders, customers, reports, settings.
 */
(function () {
	'use strict';

	const A = window.WFCPApp;
	const { api, route, navigate, modal, closeModal, confirmDialog, toast, esc, money, num, debounce, can, pager, statusPill, downloadCsv, printHtml, lineChart, barChart, T, BOOT } = A;

	const q = (sel, ctx) => (ctx || document).querySelector(sel);
	const qa = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));

	/* ============================== DASHBOARD ============================== */

	route('dashboard', async (main) => {
		const d = await api('/dashboard');

		const stats = [
			{ label: T.sales_today, value: money(d.sales.today), cls: 'ok' },
			{ label: T.sales_week, value: money(d.sales.week) },
			{ label: T.sales_month, value: money(d.sales.month) },
			{ label: T.revenue_total, value: money(d.sales.total_revenue) },
			{ label: T.orders_today, value: num(d.orders.today), to: '/orders?range=today' },
			{ label: T.orders_pending, value: num(d.orders.pending), cls: 'warn', to: '/orders?status=pending,on-hold' },
			{ label: T.orders_processing, value: num(d.orders.processing), to: '/orders?status=processing' },
			{ label: T.orders_completed, value: num(d.orders.completed), to: '/orders?status=completed' },
			{ label: T.customers_total, value: num(d.customers), to: '/customers' },
			{ label: T.low_stock, value: num(d.stock.low), cls: 'warn', to: '/products?view=low_stock' },
			{ label: T.out_of_stock, value: num(d.stock.out), cls: 'danger', to: '/products?view=out_of_stock' },
		];

		main.innerHTML =
			'<div class="page-head"><h1>' + esc(T.dashboard) + '</h1></div>' +
			'<div class="grid stats">' + stats.map((s, i) =>
				'<div class="stat ' + (s.cls || '') + (s.to ? ' clickable' : '') + '" data-i="' + i + '">' +
				'<div class="label">' + esc(s.label) + '</div><div class="value">' + s.value + '</div></div>'
			).join('') + '</div>' +
			'<div class="grid two mt">' +
			'<div class="card"><h3>' + esc(T.sales_chart) + '</h3>' + lineChart(d.chart, 'sales') + '</div>' +
			'<div class="card"><h3>' + esc(T.orders_chart) + '</h3>' + barChart(d.chart, 'orders') + '</div>' +
			'</div>' +
			'<div class="grid two mt">' +
			'<div class="card"><h3>' + esc(T.recent_orders) + '</h3><div class="list">' +
			(d.recent.length ? d.recent.map((o) =>
				'<div class="list-item" data-order="' + o.id + '"><div class="grow">' +
				'<div class="title">#' + esc(o.number) + ' – ' + esc(o.customer) + '</div>' +
				'<div class="sub">' + esc(o.date) + '</div></div>' +
				statusPill(o.status) + '<strong class="num">' + money(o.total) + '</strong></div>'
			).join('') : '<div class="empty">' + esc(T.no_results) + '</div>') +
			'</div></div>' +
			'<div class="card"><h3>' + esc(T.recent_activity) + '</h3><div class="list">' +
			(d.activity && d.activity.length ? d.activity.map((a2) =>
				'<div class="list-item"><div class="grow"><div class="title">' + esc(a2.action) + '</div>' +
				'<div class="sub">' + esc(a2.user_name) + ' · ' + esc((a2.created_at || '').replace('T', ' ').slice(0, 16)) + '</div></div></div>'
			).join('') : '<div class="empty">' + esc(T.no_results) + '</div>') +
			'</div></div></div>';

		qa('.stat.clickable', main).forEach((el) =>
			el.addEventListener('click', () => navigate(stats[el.dataset.i].to))
		);
		qa('[data-order]', main).forEach((el) =>
			el.addEventListener('click', () => navigate('/orders/' + el.dataset.order))
		);
	});

	/* ============================== PRODUCTS ============================== */

	const productViews = [
		{ key: '', label: T.all },
		{ key: 'low_stock', label: T.low_stock },
		{ key: 'out_of_stock', label: T.out_of_stock },
		{ key: 'no_image', label: T.no_image },
		{ key: 'on_sale', label: T.on_sale },
		{ key: 'best_sellers', label: T.best_sellers },
		{ key: 'worst_sellers', label: T.worst_sellers },
	];

	route('products', async (main, segments, params) => {
		// Deep links like #/products/123 show the list and open the edit modal
		// on top of it (never leave the boot spinner running).
		if (segments[0]) {
			const deepId = parseInt(segments[0], 10);
			navigate('/products');
			if (deepId) {
				openProduct(deepId, () => A.render()).catch(() => {});
			}
			return;
		}

		const state = {
			search: params.get('search') || '',
			view: params.get('view') || '',
			page: parseInt(params.get('page') || '1', 10),
			selected: new Set(),
		};

		main.innerHTML =
			'<div class="page-head"><h1>' + esc(T.products) + '</h1>' +
			(can('wfcp_products_create') ? '<button class="btn" id="p-add">＋ ' + esc(T.add_product) + '</button>' : '') +
			(can('wfcp_reports_export') ? '<button class="btn tonal sm" id="p-export">⬇ ' + esc(T.export_csv) + '</button>' : '') +
			'<button class="btn outline sm" id="p-print">🖨 ' + esc(T.print_list) + '</button>' +
			'</div>' +
			'<input class="search-input" id="p-search" type="search" placeholder="' + esc(T.search) + '…" value="' + esc(state.search) + '">' +
			'<div class="chips mt" id="p-chips">' + productViews.map((v) =>
				'<button class="chip' + (state.view === v.key ? ' active' : '') + '" data-view="' + v.key + '">' + esc(v.label) + '</button>'
			).join('') + '</div>' +
			'<div id="p-bulk"></div><div id="p-list" class="mt"><div class="spinner"></div></div>';

		let lastData = null;

		const load = async () => {
			q('#p-list').innerHTML = '<div class="spinner"></div>';
			const query = new URLSearchParams({ page: state.page });
			if (state.search) query.set('search', state.search);
			if (state.view) query.set('view', state.view);
			lastData = await api('/products?' + query);
			state.selected.clear();
			renderList();
		};

		const renderList = () => {
			const data = lastData;
			const host = q('#p-list');
			if (!data.items.length) {
				host.innerHTML = '<div class="empty"><span class="ico">◫</span>' + esc(T.no_results) + '</div>';
				renderBulk();
				return;
			}
			host.innerHTML =
				'<div class="table-wrap"><table class="data"><thead><tr>' +
				'<th><input type="checkbox" id="p-all"></th><th></th>' +
				'<th>' + esc(T.name) + '</th><th>' + esc(T.sku) + '</th>' +
				'<th>' + esc(T.regular_price) + '</th><th>' + esc(T.stock) + '</th>' +
				'<th>' + esc(T.status) + '</th><th></th></tr></thead><tbody>' +
				data.items.map((p) =>
					'<tr data-id="' + p.id + '">' +
					'<td><input type="checkbox" class="p-check" data-id="' + p.id + '"></td>' +
					'<td>' + (p.image ? '<img class="thumb" loading="lazy" src="' + esc(p.image) + '">' : '<div class="thumb"></div>') + '</td>' +
					'<td><strong>' + esc(p.name) + '</strong>' + (p.on_sale ? ' <span class="status-pill completed">' + esc(T.on_sale) + '</span>' : '') + '</td>' +
					'<td class="num">' + esc(p.sku || '–') + '</td>' +
					'<td class="num">' + (p.sale_price ? '<s class="muted">' + money(p.regular_price) + '</s> ' + money(p.sale_price) : money(p.regular_price || p.price)) + '</td>' +
					'<td class="num">' + (p.manage_stock ? num(p.stock) : esc(p.stock_status === 'instock' ? (T.in_stock || '✓') : (T.out_of_stock || '✕'))) + '</td>' +
					'<td>' + esc(p.status === 'publish' ? T.publish : T.draft) + '</td>' +
					'<td class="num">' + num(p.total_sales) + ' 🛒</td></tr>'
				).join('') + '</tbody></table></div>';

			host.appendChild(pager(data, (page) => { state.page = page; load(); }));

			q('#p-all').addEventListener('change', (e) => {
				qa('.p-check', host).forEach((c) => { c.checked = e.target.checked; });
				state.selected = new Set(e.target.checked ? data.items.map((p) => p.id) : []);
				renderBulk();
			});
			qa('.p-check', host).forEach((c) => c.addEventListener('click', (e) => {
				e.stopPropagation();
				const id = parseInt(c.dataset.id, 10);
				c.checked ? state.selected.add(id) : state.selected.delete(id);
				renderBulk();
			}));
			qa('tbody tr', host).forEach((tr) => tr.addEventListener('click', (e) => {
				if (e.target.closest('input')) return;
				openProduct(parseInt(tr.dataset.id, 10), load);
			}));
			renderBulk();
		};

		const renderBulk = () => {
			const host = q('#p-bulk');
			if (!state.selected.size) { host.innerHTML = ''; return; }
			host.innerHTML =
				'<div class="bulkbar"><strong>' + num(state.selected.size) + ' ' + esc(T.selected) + '</strong>' +
				(can('wfcp_products_edit') ? '<button class="btn sm tonal" data-act="publish">' + esc(T.publish) + '</button>' +
					'<button class="btn sm tonal" data-act="draft">' + esc(T.draft) + '</button>' : '') +
				(can('wfcp_products_price') ? '<button class="btn sm tonal" data-act="price_pct">' + esc(T.bulk_price) + '</button>' : '') +
				(can('wfcp_products_stock') ? '<button class="btn sm tonal" data-act="stock_set">' + esc(T.bulk_stock) + '</button>' : '') +
				(can('wfcp_products_delete') ? '<button class="btn sm danger" data-act="delete">' + esc(T.delete) + '</button>' : '') +
				'</div>';
			qa('[data-act]', host).forEach((b) => b.addEventListener('click', async () => {
				const action = b.dataset.act;
				let value = null;
				if (action === 'price_pct') {
					value = prompt(T.price_change_pct || '%');
					if (value === null || value === '') return;
				}
				if (action === 'stock_set') {
					value = prompt(T.set_stock_to || 'Stock');
					if (value === null || value === '') return;
				}
				if (action === 'delete' && !(await confirmDialog(T.confirm_bulk))) return;
				await api('/products/bulk', { method: 'POST', body: { ids: Array.from(state.selected), action, value } });
				toast(T.saved, 'success');
				load();
			}));
		};

		q('#p-search').addEventListener('input', debounce((e) => {
			state.search = e.target.value.trim();
			state.page = 1;
			load();
		}, 350));

		qa('#p-chips .chip').forEach((chip) => chip.addEventListener('click', () => {
			state.view = chip.dataset.view;
			state.page = 1;
			qa('#p-chips .chip').forEach((c) => c.classList.toggle('active', c === chip));
			load();
		}));

		if (q('#p-add')) q('#p-add').addEventListener('click', () => quickCreateProduct(load));
		if (q('#p-export')) q('#p-export').addEventListener('click', async () => {
			const query = new URLSearchParams();
			if (state.search) query.set('search', state.search);
			if (state.view) query.set('view', state.view);
			downloadCsv(await api('/products/export?' + query));
		});
		q('#p-print').addEventListener('click', () => {
			if (!lastData) return;
			printHtml('<h2>' + esc(T.products) + '</h2><table><tr><th>' + esc(T.name) + '</th><th>' + esc(T.sku) + '</th><th>' + esc(T.regular_price) + '</th><th>' + esc(T.stock) + '</th></tr>' +
				lastData.items.map((p) => '<tr><td>' + esc(p.name) + '</td><td>' + esc(p.sku) + '</td><td>' + money(p.price) + '</td><td>' + num(p.stock || 0) + '</td></tr>').join('') + '</table>');
		});

		load();
	});

	function quickCreateProduct(onDone) {
		const m = modal(T.add_product,
			'<div class="field"><label>' + esc(T.product_name) + '</label><input id="np-name"></div>' +
			'<div class="row">' +
			'<div class="field"><label>' + esc(T.regular_price) + '</label><input id="np-price" type="number" step="any"></div>' +
			'<div class="field"><label>' + esc(T.stock) + '</label><input id="np-stock" type="number"></div>' +
			'<div class="field"><label>' + esc(T.sku) + '</label><input id="np-sku"></div></div>',
			'<button class="btn outline" data-close>' + esc(T.cancel) + '</button>' +
			'<button class="btn tonal" id="np-draft">' + esc(T.draft) + '</button>' +
			'<button class="btn" id="np-publish">' + esc(T.publish) + '</button>');

		const save = async (status) => {
			const body = {
				name: q('#np-name', m).value,
				status,
				regular_price: q('#np-price', m).value,
				sku: q('#np-sku', m).value,
			};
			if (q('#np-stock', m).value !== '') body.stock_quantity = q('#np-stock', m).value;
			await api('/products', { method: 'POST', body });
			closeModal();
			toast(T.saved, 'success');
			onDone();
		};
		q('#np-draft', m).addEventListener('click', () => save('draft'));
		q('#np-publish', m).addEventListener('click', () => save('publish'));
	}

	async function openProduct(id, onDone) {
		const p = await api('/products/' + id);
		const tax = await api('/products/taxonomies');
		const disabledPrice = can('wfcp_products_price') ? '' : ' disabled';
		const disabledStock = can('wfcp_products_stock') ? '' : ' disabled';

		const m = modal(p.name,
			'<div class="field"><label>' + esc(T.product_name) + '</label><input id="ep-name" value="' + esc(p.name) + '"></div>' +
			'<div class="row">' +
			'<div class="field"><label>' + esc(T.regular_price) + '</label><input id="ep-rprice" type="number" step="any" value="' + esc(p.regular_price) + '"' + disabledPrice + '></div>' +
			'<div class="field"><label>' + esc(T.sale_price) + '</label><input id="ep-sprice" type="number" step="any" value="' + esc(p.sale_price) + '"' + disabledPrice + '></div></div>' +
			'<div class="row">' +
			'<div class="field"><label>' + esc(T.sale_from) + '</label><input id="ep-sfrom" type="date" value="' + esc(p.date_on_sale_from) + '"' + disabledPrice + '></div>' +
			'<div class="field"><label>' + esc(T.sale_to) + '</label><input id="ep-sto" type="date" value="' + esc(p.date_on_sale_to) + '"' + disabledPrice + '></div></div>' +
			'<div class="row">' +
			'<div class="field"><label>' + esc(T.sku) + '</label><input id="ep-sku" value="' + esc(p.sku) + '"></div>' +
			'<div class="field"><label>' + esc(T.stock) + '</label><input id="ep-stock" type="number" value="' + esc(p.stock === null ? '' : p.stock) + '"' + disabledStock + '></div>' +
			'<div class="field"><label>' + esc(T.stock_status) + '</label><select id="ep-sstatus"' + disabledStock + '>' +
			['instock', 'outofstock', 'onbackorder'].map((s) => '<option value="' + s + '"' + (p.stock_status === s ? ' selected' : '') + '>' + s + '</option>').join('') +
			'</select></div></div>' +
			'<div class="field"><label>' + esc(T.categories) + '</label><select id="ep-cats" multiple size="4">' +
			tax.categories.map((c) => '<option value="' + c.id + '"' + (p.categories.includes(c.id) ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('') +
			'</select></div>' +
			'<div class="row">' +
			'<div class="field"><label>' + esc(T.weight) + '</label><input id="ep-weight" value="' + esc(p.weight) + '"></div>' +
			'<div class="field"><label>' + esc(T.dimensions) + '</label><div class="row">' +
			'<input id="ep-l" placeholder="L" value="' + esc(p.length) + '" style="flex:1;min-width:0">' +
			'<input id="ep-w" placeholder="W" value="' + esc(p.width) + '" style="flex:1;min-width:0">' +
			'<input id="ep-h" placeholder="H" value="' + esc(p.height) + '" style="flex:1;min-width:0"></div></div></div>' +
			'<div class="field"><label>' + esc(T.short_desc) + '</label><textarea id="ep-short" rows="2">' + esc(p.short_description) + '</textarea></div>' +
			'<div class="field"><label>' + esc(T.description) + '</label><textarea id="ep-desc" rows="4">' + esc(p.description) + '</textarea></div>' +
			'<div class="field"><label>' + esc(T.status) + '</label><select id="ep-status">' +
			'<option value="publish"' + (p.status === 'publish' ? ' selected' : '') + '>' + esc(T.publish) + '</option>' +
			'<option value="draft"' + (p.status === 'draft' ? ' selected' : '') + '>' + esc(T.draft) + '</option></select></div>' +
			(p.type === 'variable' ? '<button class="btn tonal sm" id="ep-vars">' + esc(T.variations) + '</button>' : ''),
			(can('wfcp_products_delete') ? '<button class="btn danger" id="ep-del">' + esc(T.delete) + '</button>' : '') +
			(can('wfcp_products_create') ? '<button class="btn outline" id="ep-dup">' + esc(T.duplicate) + '</button>' : '') +
			'<button class="btn" id="ep-save">' + esc(T.save) + '</button>');

		q('#ep-save', m).addEventListener('click', async () => {
			const body = {
				name: q('#ep-name', m).value,
				sku: q('#ep-sku', m).value,
				status: q('#ep-status', m).value,
				short_description: q('#ep-short', m).value,
				description: q('#ep-desc', m).value,
				weight: q('#ep-weight', m).value,
				length: q('#ep-l', m).value,
				width: q('#ep-w', m).value,
				height: q('#ep-h', m).value,
				categories: qa('#ep-cats option:checked', m).map((o) => parseInt(o.value, 10)),
			};
			if (can('wfcp_products_price')) {
				body.regular_price = q('#ep-rprice', m).value;
				body.sale_price = q('#ep-sprice', m).value;
				body.date_on_sale_from = q('#ep-sfrom', m).value;
				body.date_on_sale_to = q('#ep-sto', m).value;
			}
			if (can('wfcp_products_stock')) {
				body.stock_quantity = q('#ep-stock', m).value;
				body.stock_status = q('#ep-sstatus', m).value;
			}
			await api('/products/' + id, { method: 'PUT', body });
			closeModal();
			toast(T.saved, 'success');
			onDone();
		});

		if (q('#ep-del', m)) q('#ep-del', m).addEventListener('click', async () => {
			if (!(await confirmDialog(T.confirm_delete))) return;
			await api('/products/' + id, { method: 'DELETE' });
			closeModal();
			toast(T.deleted, 'success');
			onDone();
		});

		if (q('#ep-dup', m)) q('#ep-dup', m).addEventListener('click', async () => {
			await api('/products/' + id + '/duplicate', { method: 'POST' });
			closeModal();
			toast(T.saved, 'success');
			onDone();
		});

		if (q('#ep-vars', m)) q('#ep-vars', m).addEventListener('click', async () => {
			const vars = await api('/products/' + id + '/variations');
			modal(T.variations,
				'<div class="table-wrap"><table class="data"><thead><tr><th>' + esc(T.name) + '</th><th>' + esc(T.regular_price) + '</th><th>' + esc(T.sale_price) + '</th><th>' + esc(T.stock) + '</th><th></th></tr></thead><tbody>' +
				vars.items.map((v) =>
					'<tr><td>' + esc(v.attributes) + '</td>' +
					'<td><input type="number" step="any" style="width:90px" value="' + esc(v.regular_price) + '" data-f="regular_price" data-id="' + v.id + '"></td>' +
					'<td><input type="number" step="any" style="width:90px" value="' + esc(v.sale_price) + '" data-f="sale_price" data-id="' + v.id + '"></td>' +
					'<td><input type="number" style="width:70px" value="' + esc(v.stock === null ? '' : v.stock) + '" data-f="stock_quantity" data-id="' + v.id + '"></td>' +
					'<td><button class="btn sm tonal" data-save="' + v.id + '">' + esc(T.save) + '</button></td></tr>'
				).join('') + '</tbody></table></div>');
			qa('[data-save]').forEach((b) => b.addEventListener('click', async () => {
				const vid = b.dataset.save;
				const body = {};
				qa('input[data-id="' + vid + '"]').forEach((inp) => { body[inp.dataset.f] = inp.value; });
				await api('/products/variations/' + vid, { method: 'PUT', body });
				toast(T.saved, 'success');
			}));
		});
	}

	/* ============================== ORDERS ============================== */

	route('orders', async (main, segments, params) => {
		if (segments[0]) return orderDetail(main, parseInt(segments[0], 10));

		const state = {
			search: params.get('search') || '',
			status: params.get('status') || '',
			range: params.get('range') || '',
			page: 1,
			selected: new Set(),
		};

		main.innerHTML =
			'<div class="page-head"><h1>' + esc(T.orders) + '</h1>' +
			(can('wfcp_reports_export') ? '<button class="btn tonal sm" id="o-export">⬇ ' + esc(T.export_csv) + '</button>' : '') +
			'</div>' +
			'<input class="search-input" id="o-search" type="search" placeholder="' + esc(T.order_search_ph) + '" value="' + esc(state.search) + '">' +
			'<div class="chips mt" id="o-chips"></div>' +
			'<div id="o-bulk"></div><div id="o-list" class="mt"><div class="spinner"></div></div>';

		const counts = await api('/orders/counts').catch(() => ({}));
		const chipDefs = [{ key: '', label: T.all }]
			.concat([{ key: 'range:today', label: T.today }, { key: 'range:yesterday', label: T.yesterday }])
			.concat(Object.keys(BOOT.statuses || {}).map((s) => {
				const key = s.replace(/^wc-/, '');
				return { key: 'status:' + key, label: BOOT.statuses[s] + (counts[key] ? ' (' + num(counts[key]) + ')' : '') };
			}));

		// Combined filters (e.g. status=pending,on-hold from the dashboard)
		// highlight every matching status chip.
		const activeKeys = state.status ? state.status.split(',').map((s) => 'status:' + s) : [];
		if (state.range) activeKeys.push('range:' + state.range);
		if (!activeKeys.length) activeKeys.push('');
		q('#o-chips').innerHTML = chipDefs.map((c) =>
			'<button class="chip' + (activeKeys.includes(c.key) ? ' active' : '') + '" data-key="' + esc(c.key) + '">' + esc(c.label) + '</button>'
		).join('');

		let lastData = null;

		const load = async () => {
			q('#o-list').innerHTML = '<div class="spinner"></div>';
			const query = new URLSearchParams({ page: state.page });
			if (state.search) query.set('search', state.search);
			if (state.status) query.set('status', state.status);
			if (state.range) query.set('range', state.range);
			lastData = await api('/orders?' + query);
			state.selected.clear();
			renderList();
		};

		const renderList = () => {
			const data = lastData;
			const host = q('#o-list');
			if (!data.items.length) {
				host.innerHTML = '<div class="empty"><span class="ico">🛒</span>' + esc(T.no_results) + '</div>';
				renderBulk();
				return;
			}
			host.innerHTML =
				'<div class="table-wrap"><table class="data"><thead><tr>' +
				'<th><input type="checkbox" id="o-all"></th>' +
				'<th>' + esc(T.order) + '</th><th>' + esc(T.customer) + '</th><th>' + esc(T.date) + '</th>' +
				'<th>' + esc(T.status) + '</th><th>' + esc(T.total) + '</th><th>' + esc(T.payment) + '</th></tr></thead><tbody>' +
				data.items.map((o) =>
					'<tr data-id="' + o.id + '">' +
					'<td><input type="checkbox" class="o-check" data-id="' + o.id + '"></td>' +
					'<td><strong>#' + esc(o.number) + '</strong><div class="muted">' + num(o.items_count) + ' ×</div></td>' +
					'<td>' + esc(o.customer) + '<div class="muted num">' + esc(o.phone || o.email || '') + '</div></td>' +
					'<td class="num">' + esc(o.date) + '</td>' +
					'<td>' + statusPill(o.status) + '</td>' +
					'<td class="num tot">' + money(o.total) + '</td>' +
					'<td>' + esc(o.payment_method || '–') + '</td></tr>'
				).join('') + '</tbody></table></div>';

			host.appendChild(pager(data, (page) => { state.page = page; load(); }));

			q('#o-all').addEventListener('change', (e) => {
				qa('.o-check', host).forEach((c) => { c.checked = e.target.checked; });
				state.selected = new Set(e.target.checked ? data.items.map((o) => o.id) : []);
				renderBulk();
			});
			qa('.o-check', host).forEach((c) => c.addEventListener('click', (e) => {
				e.stopPropagation();
				const id = parseInt(c.dataset.id, 10);
				c.checked ? state.selected.add(id) : state.selected.delete(id);
				renderBulk();
			}));
			qa('tbody tr', host).forEach((tr) => tr.addEventListener('click', (e) => {
				if (e.target.closest('input')) return;
				navigate('/orders/' + tr.dataset.id);
			}));
			renderBulk();
		};

		const renderBulk = () => {
			const host = q('#o-bulk');
			if (!state.selected.size || !can('wfcp_orders_status')) { host.innerHTML = ''; return; }
			host.innerHTML =
				'<div class="bulkbar"><strong>' + num(state.selected.size) + ' ' + esc(T.selected) + '</strong>' +
				'<select id="ob-status"><option value="">' + esc(T.change_status) + '…</option>' +
				Object.keys(BOOT.statuses).map((s) => '<option value="' + s.replace(/^wc-/, '') + '">' + esc(BOOT.statuses[s]) + '</option>').join('') +
				'</select><button class="btn sm" id="ob-apply">' + esc(T.apply) + '</button>' +
				(can('wfcp_orders_delete') ? '<button class="btn sm danger" id="ob-del">' + esc(T.delete) + '</button>' : '') +
				'</div>';
			q('#ob-apply').addEventListener('click', async () => {
				const status = q('#ob-status').value;
				if (!status) return;
				await api('/orders/bulk', { method: 'POST', body: { ids: Array.from(state.selected), action: 'status:' + status } });
				toast(T.saved, 'success');
				load();
			});
			if (q('#ob-del')) q('#ob-del').addEventListener('click', async () => {
				if (!(await confirmDialog(T.confirm_bulk))) return;
				await api('/orders/bulk', { method: 'POST', body: { ids: Array.from(state.selected), action: 'delete' } });
				toast(T.deleted, 'success');
				load();
			});
		};

		q('#o-search').addEventListener('input', debounce((e) => {
			state.search = e.target.value.trim();
			state.page = 1;
			load();
		}, 350));

		qa('#o-chips .chip').forEach((chip) => chip.addEventListener('click', () => {
			const key = chip.dataset.key;
			state.status = key.startsWith('status:') ? key.slice(7) : '';
			state.range = key.startsWith('range:') ? key.slice(6) : '';
			state.page = 1;
			qa('#o-chips .chip').forEach((c) => c.classList.toggle('active', c === chip));
			load();
		}));

		if (q('#o-export')) q('#o-export').addEventListener('click', async () => {
			const query = new URLSearchParams();
			if (state.search) query.set('search', state.search);
			if (state.status) query.set('status', state.status);
			if (state.range) query.set('range', state.range);
			downloadCsv(await api('/orders/export?' + query));
		});

		load();
	});

	async function orderDetail(main, id) {
		const o = await api('/orders/' + id);

		const addr = (a) => [a.first_name + ' ' + a.last_name, a.address_1, a.address_2, a.city, a.state, a.postcode, a.phone]
			.filter((x) => x && String(x).trim()).map(esc).join('<br>');

		main.innerHTML =
			'<div class="page-head">' +
			'<button class="icon-btn" id="od-back">←</button>' +
			'<h1>' + esc(T.order) + ' #' + esc(o.number) + '</h1>' + statusPill(o.status) +
			(can('wfcp_orders_print') ? '<button class="btn tonal sm" id="od-print">🖨 ' + esc(T.invoice) + '</button>' +
				'<button class="btn outline sm" id="od-label">🏷 ' + esc(T.shipping_label) + '</button>' : '') +
			'</div>' +
			'<div class="grid two">' +
			'<div class="card"><h3>' + esc(T.order_items) + '</h3><div id="od-items"></div>' +
			(can('wfcp_orders_edit') ? '<button class="btn tonal sm mt" id="od-add">＋ ' + esc(T.add_item) + '</button>' : '') +
			'<div class="mt">' +
			'<div class="muted">' + esc(T.subtotal) + ': <span class="num">' + money(o.subtotal) + '</span></div>' +
			(o.discount ? '<div class="muted">' + esc(T.discount) + ': <span class="num">-' + money(o.discount) + '</span></div>' : '') +
			(o.shipping_total ? '<div class="muted">' + esc(T.shipping) + ': <span class="num">' + money(o.shipping_total) + '</span></div>' : '') +
			(o.tax_total ? '<div class="muted">' + esc(T.tax) + ': <span class="num">' + money(o.tax_total) + '</span></div>' : '') +
			(o.refunded ? '<div class="muted">' + esc(T.refunds) + ': <span class="num">-' + money(o.refunded) + '</span></div>' : '') +
			'<div class="tot">' + esc(T.total) + ': <span class="num">' + money(o.total) + '</span></div></div></div>' +
			'<div>' +
			(can('wfcp_orders_status') ? '<div class="card"><h3>' + esc(T.change_status) + '</h3><div class="row">' +
				'<select id="od-status" style="flex:1">' +
				Object.keys(BOOT.statuses).map((s) => '<option value="' + s.replace(/^wc-/, '') + '"' + (s === 'wc-' + o.status ? ' selected' : '') + '>' + esc(BOOT.statuses[s]) + '</option>').join('') +
				'</select><button class="btn" id="od-setstatus">' + esc(T.save) + '</button></div></div>' : '') +
			'<div class="card mt"><h3>' + esc(T.customer) + '</h3>' +
			'<div><strong>' + esc(o.customer) + '</strong>' +
			(o.customer_id ? ' <a href="#/customers/' + o.customer_id + '">↗</a>' : ' <span class="muted">(' + esc(T.guest) + ')</span>') + '</div>' +
			'<div class="muted num">' + esc(o.email || '') + ' · ' + esc(o.phone || '') + '</div>' +
			'<div class="row mt"><div style="flex:1"><h3>' + esc(T.billing) + '</h3><div class="muted">' + (addr(o.billing) || '–') + '</div></div>' +
			'<div style="flex:1"><h3>' + esc(T.shipping) + '</h3><div class="muted">' + (addr(o.shipping) || '–') + '</div></div></div>' +
			'<div class="mt muted">' + esc(T.payment) + ': ' + esc(o.payment_method || '–') +
			(o.transaction_id ? ' <span class="num">(' + esc(o.transaction_id) + ')</span>' : '') + '</div>' +
			(o.shipping_method ? '<div class="muted">' + esc(T.shipping) + ': ' + esc(o.shipping_method) + '</div>' : '') +
			'</div>' +
			'<div class="card mt"><h3>' + esc(T.notes) + ' / ' + esc(T.history) + '</h3><div id="od-notes"><div class="spinner"></div></div>' +
			(can('wfcp_orders_edit') ? '<div class="row mt"><input id="od-note" style="flex:1" placeholder="' + esc(T.add_note) + '…">' +
				'<button class="btn sm" id="od-addnote">' + esc(T.add_note) + '</button></div>' : '') +
			'</div></div></div>';

		const renderItems = (order) => {
			q('#od-items').innerHTML =
				'<div class="table-wrap"><table class="data" style="min-width:0"><tbody>' +
				order.items.map((it) =>
					'<tr><td><strong>' + esc(it.name) + '</strong><div class="muted num">' + esc(it.sku || '') + '</div></td>' +
					'<td class="num">' + (can('wfcp_orders_edit')
						? '<input type="number" min="1" style="width:64px" value="' + it.quantity + '" data-qty="' + it.id + '">'
						: num(it.quantity)) + '</td>' +
					'<td class="num">' + money(it.total) + '</td>' +
					(can('wfcp_orders_edit') ? '<td><button class="icon-btn" data-rm="' + it.id + '">🗑</button></td>' : '') +
					'</tr>'
				).join('') + '</tbody></table></div>';

			qa('[data-qty]').forEach((inp) => inp.addEventListener('change', async () => {
				const updated = await api('/orders/' + id + '/items/' + inp.dataset.qty, { method: 'PUT', body: { quantity: inp.value } });
				toast(T.saved, 'success');
				renderItems(updated);
			}));
			qa('[data-rm]').forEach((b) => b.addEventListener('click', async () => {
				if (!(await confirmDialog(T.confirm_delete))) return;
				const updated = await api('/orders/' + id + '/items/' + b.dataset.rm, { method: 'DELETE' });
				toast(T.deleted, 'success');
				renderItems(updated);
			}));
		};
		renderItems(o);

		const loadNotes = async () => {
			const notes = await api('/orders/' + id + '/notes');
			q('#od-notes').innerHTML = notes.items.length
				? '<div class="list">' + notes.items.map((n) =>
					'<div class="list-item" style="cursor:default"><div class="grow">' +
					'<div class="title" style="white-space:normal">' + esc(n.content) + '</div>' +
					'<div class="sub">' + esc(n.author || '') + ' · ' + esc(n.date) + '</div></div></div>'
				).join('') + '</div>'
				: '<div class="muted">' + esc(T.no_results) + '</div>';
		};
		loadNotes();

		// Going back preserves the previous list filters when possible.
		q('#od-back').addEventListener('click', () => {
			if (window.history.length > 1) history.back(); else navigate('/orders');
		});

		if (q('#od-setstatus')) q('#od-setstatus').addEventListener('click', async () => {
			await api('/orders/' + id + '/status', { method: 'PUT', body: { status: q('#od-status').value } });
			toast(T.saved, 'success');
			A.render();
		});

		if (q('#od-addnote')) q('#od-addnote').addEventListener('click', async () => {
			const note = q('#od-note').value.trim();
			if (!note) return;
			await api('/orders/' + id + '/notes', { method: 'POST', body: { note } });
			q('#od-note').value = '';
			loadNotes();
		});

		if (q('#od-add')) q('#od-add').addEventListener('click', () => {
			const m = modal(T.add_item,
				'<div class="field"><label>' + esc(T.search) + '</label><input id="oi-search" autocomplete="off"></div><div id="oi-results" class="list"></div>');
			q('#oi-search', m).addEventListener('input', debounce(async (e) => {
				const term = e.target.value.trim();
				if (term.length < 2) return;
				const results = await api('/products?search=' + encodeURIComponent(term) + '&per_page=10');
				q('#oi-results', m).innerHTML = results.items.map((p) =>
					'<div class="list-item" data-pid="' + p.id + '"><div class="grow"><div class="title">' + esc(p.name) + '</div>' +
					'<div class="sub num">' + money(p.price) + '</div></div><button class="btn sm tonal">＋</button></div>'
				).join('');
				qa('[data-pid]', m).forEach((row) => row.addEventListener('click', async () => {
					const updated = await api('/orders/' + id + '/items', { method: 'POST', body: { product_id: row.dataset.pid, quantity: 1 } });
					closeModal();
					toast(T.saved, 'success');
					renderItems(updated);
				}));
			}, 300));
		});

		const invoiceHtml = (kind) => {
			const billing = addr(o.billing) || esc(o.customer);
			if (kind === 'label') {
				return '<h2>' + esc(BOOT.siteName) + '</h2><h3>' + esc(T.order) + ' #' + esc(o.number) + '</h3>' +
					'<p style="font-size:16px;line-height:1.9">' + billing + '</p>';
			}
			return '<h2>' + esc(BOOT.siteName) + ' – ' + esc(T.invoice) + '</h2>' +
				'<p>' + esc(T.order) + ' #' + esc(o.number) + ' · ' + esc(o.date) + '</p><p>' + billing + '</p>' +
				'<table><tr><th>' + esc(T.name) + '</th><th>' + esc(T.quantity) + '</th><th>' + esc(T.total) + '</th></tr>' +
				o.items.map((it) => '<tr><td>' + esc(it.name) + '</td><td>' + num(it.quantity) + '</td><td>' + money(it.total) + '</td></tr>').join('') +
				'<tr><td colspan="2"><strong>' + esc(T.total) + '</strong></td><td><strong>' + money(o.total) + '</strong></td></tr></table>';
		};
		if (q('#od-print')) q('#od-print').addEventListener('click', () => printHtml(invoiceHtml('invoice')));
		if (q('#od-label')) q('#od-label').addEventListener('click', () => printHtml(invoiceHtml('label')));
	}

	/* ============================== CUSTOMERS ============================== */

	route('customers', async (main, segments) => {
		if (segments[0]) return customerDetail(main, parseInt(segments[0], 10));

		const state = { search: '', page: 1 };

		main.innerHTML =
			'<div class="page-head"><h1>' + esc(T.customers) + '</h1>' +
			(can('wfcp_reports_export') ? '<button class="btn tonal sm" id="c-export">⬇ ' + esc(T.export_csv) + '</button>' : '') +
			'</div>' +
			'<input class="search-input" id="c-search" type="search" placeholder="' + esc(T.search) + '…">' +
			'<div id="c-list" class="mt"><div class="spinner"></div></div>';

		const load = async () => {
			q('#c-list').innerHTML = '<div class="spinner"></div>';
			const query = new URLSearchParams({ page: state.page });
			if (state.search) query.set('search', state.search);
			const data = await api('/customers?' + query);
			const host = q('#c-list');
			host.innerHTML = data.items.length
				? '<div class="table-wrap"><table class="data"><thead><tr><th></th><th>' + esc(T.name) + '</th><th>' + esc(T.email) + '</th>' +
				'<th>' + esc(T.role) + '</th><th>' + esc(T.orders_count) + '</th><th>' + esc(T.total_spent) + '</th><th>' + esc(T.last_login) + '</th></tr></thead><tbody>' +
				data.items.map((c) =>
					'<tr data-id="' + c.id + '">' +
					'<td><img class="thumb" style="border-radius:50%" loading="lazy" src="' + esc(c.avatar) + '"></td>' +
					'<td><strong>' + esc(c.name) + '</strong>' + (c.blocked ? ' <span class="status-pill cancelled">' + esc(T.blocked) + '</span>' : '') + '</td>' +
					'<td class="num">' + esc(c.email) + '</td><td>' + esc(c.role) + '</td>' +
					'<td class="num">' + num(c.orders_count) + '</td><td class="num">' + money(c.total_spent) + '</td>' +
					'<td class="num">' + esc(c.last_login || T.never) + '</td></tr>'
				).join('') + '</tbody></table></div>'
				: '<div class="empty"><span class="ico">👤</span>' + esc(T.no_results) + '</div>';
			host.appendChild(pager(data, (page) => { state.page = page; load(); }));
			qa('tbody tr', host).forEach((tr) => tr.addEventListener('click', () => navigate('/customers/' + tr.dataset.id)));
		};

		q('#c-search').addEventListener('input', debounce((e) => {
			state.search = e.target.value.trim();
			state.page = 1;
			load();
		}, 350));

		if (q('#c-export')) q('#c-export').addEventListener('click', async () => downloadCsv(await api('/customers/export')));

		load();
	});

	async function customerDetail(main, id) {
		const c = await api('/customers/' + id);
		const editable = can('wfcp_users_edit');

		main.innerHTML =
			'<div class="page-head"><button class="icon-btn" id="cd-back">←</button>' +
			'<h1>' + esc(c.name) + '</h1>' +
			(c.blocked ? '<span class="status-pill cancelled">' + esc(T.blocked) + '</span>' : '') +
			(can('wfcp_users_block') ? '<button class="btn ' + (c.blocked ? 'tonal' : 'danger') + ' sm" id="cd-block">' +
				esc(c.blocked ? T.unblock : T.block) + '</button>' : '') +
			'</div>' +
			'<div class="grid stats">' +
			'<div class="stat"><div class="label">' + esc(T.total_spent) + '</div><div class="value">' + money(c.total_spent) + '</div></div>' +
			'<div class="stat"><div class="label">' + esc(T.orders_count) + '</div><div class="value">' + num(c.orders_count) + '</div></div>' +
			'<div class="stat"><div class="label">' + esc(T.registered) + '</div><div class="value">' + esc(c.registered) + '</div></div>' +
			'<div class="stat"><div class="label">' + esc(T.last_login) + '</div><div class="value">' + esc(c.last_login || T.never) + '</div></div>' +
			'</div>' +
			'<div class="grid two mt">' +
			'<div class="card"><h3>' + esc(T.edit) + '</h3>' +
			'<div class="row"><div class="field"><label>' + esc(T.name) + '</label><input id="cd-first" value="' + esc(c.first_name) + '"' + (editable ? '' : ' disabled') + '></div>' +
			'<div class="field"><label> </label><input id="cd-last" value="' + esc(c.last_name) + '"' + (editable ? '' : ' disabled') + '></div></div>' +
			'<div class="field"><label>' + esc(T.email) + '</label><input id="cd-email" value="' + esc(c.email) + '"' + (editable ? '' : ' disabled') + '></div>' +
			'<div class="field"><label>' + esc(T.phone) + '</label><input id="cd-phone" value="' + esc(c.phone) + '"' + (editable ? '' : ' disabled') + '></div>' +
			'<div class="field"><label>' + esc(T.role) + '</label><input id="cd-role" value="' + esc(c.role) + '"' + (editable ? '' : ' disabled') + '><div class="hint">customer / shop_manager / …</div></div>' +
			(editable ? '<button class="btn" id="cd-save">' + esc(T.save) + '</button>' : '') +
			'<h3 class="mt">' + esc(T.address) + '</h3>' +
			'<div class="muted">' + esc([c.billing.address_1, c.billing.city, c.billing.postcode].filter(Boolean).join(', ') || '–') + '</div>' +
			'<h3 class="mt">' + esc(T.notes) + '</h3><div id="cd-notes">' +
			(c.notes.length ? c.notes.map((n) => '<div class="list-item" style="cursor:default"><div class="grow"><div class="title" style="white-space:normal">' + esc(n.note) + '</div><div class="sub">' + esc(n.author) + ' · ' + esc(n.date) + '</div></div></div>').join('') : '<div class="muted">–</div>') +
			'</div>' +
			(editable ? '<div class="row mt"><input id="cd-note" style="flex:1" placeholder="' + esc(T.add_note) + '…"><button class="btn sm" id="cd-addnote">' + esc(T.add_note) + '</button></div>' : '') +
			'</div>' +
			'<div class="card"><h3>' + esc(T.orders) + '</h3><div class="list">' +
			(c.orders.length ? c.orders.map((o) =>
				'<div class="list-item" data-order="' + o.id + '"><div class="grow"><div class="title">#' + esc(o.number) + '</div>' +
				'<div class="sub">' + esc(o.date) + '</div></div>' + statusPill(o.status) + '<strong class="num">' + money(o.total) + '</strong></div>'
			).join('') : '<div class="empty">' + esc(T.no_results) + '</div>') +
			'</div></div></div>';

		q('#cd-back').addEventListener('click', () => {
			if (window.history.length > 1) history.back(); else navigate('/customers');
		});
		qa('[data-order]', main).forEach((el) => el.addEventListener('click', () => navigate('/orders/' + el.dataset.order)));

		if (q('#cd-save')) q('#cd-save').addEventListener('click', async () => {
			await api('/customers/' + id, {
				method: 'PUT',
				body: {
					first_name: q('#cd-first').value,
					last_name: q('#cd-last').value,
					email: q('#cd-email').value,
					phone: q('#cd-phone').value,
					role: q('#cd-role').value,
				},
			});
			toast(T.saved, 'success');
		});

		if (q('#cd-block')) q('#cd-block').addEventListener('click', async () => {
			await api('/customers/' + id + '/block', { method: 'POST', body: { blocked: !c.blocked } });
			toast(T.saved, 'success');
			A.render();
		});

		if (q('#cd-addnote')) q('#cd-addnote').addEventListener('click', async () => {
			const note = q('#cd-note').value.trim();
			if (!note) return;
			await api('/customers/' + id + '/notes', { method: 'POST', body: { note } });
			toast(T.saved, 'success');
			A.render();
		});
	}

	/* ============================== REPORTS ============================== */

	route('reports', async (main, segments, params) => {
		const tab = params.get('tab') || 'sales';
		const period = params.get('period') || 'day';

		const tabs = [
			{ key: 'sales', label: T.report_sales },
			{ key: 'products', label: T.report_products },
			{ key: 'customers', label: T.report_customers },
			{ key: 'categories', label: T.report_categories },
			{ key: 'stock', label: T.report_stock },
			{ key: 'audit', label: T.audit_log },
		];

		main.innerHTML =
			'<div class="page-head"><h1>' + esc(T.reports) + '</h1>' +
			(can('wfcp_reports_export') && tab !== 'audit' ? '<button class="btn tonal sm" id="r-export">⬇ ' + esc(T.export_csv) + '</button>' : '') +
			'</div>' +
			'<div class="chips">' + tabs.map((t) =>
				'<button class="chip' + (t.key === tab ? ' active' : '') + '" data-tab="' + t.key + '">' + esc(t.label) + '</button>'
			).join('') + '</div>' +
			'<div id="r-body" class="mt"><div class="spinner"></div></div>';

		qa('[data-tab]', main).forEach((chip) => chip.addEventListener('click', () =>
			navigate('/reports?tab=' + chip.dataset.tab)
		));
		if (q('#r-export')) q('#r-export').addEventListener('click', async () => {
			downloadCsv(await api('/reports/export?type=' + tab + '&period=' + period));
		});

		const body = q('#r-body');

		const table = (headers, rows) =>
			'<div class="table-wrap"><table class="data"><thead><tr>' +
			headers.map((h) => '<th>' + esc(h) + '</th>').join('') + '</tr></thead><tbody>' +
			rows.map((r) => '<tr>' + r.map((cell) => '<td>' + cell + '</td>').join('') + '</tr>').join('') +
			'</tbody></table></div>';

		if (tab === 'sales') {
			const data = await api('/reports/sales?period=' + period);
			const periods = [['day', T.period_day], ['week', T.period_week], ['month', T.period_month], ['year', T.period_year]];
			body.innerHTML =
				'<div class="chips">' + periods.map(([k, label]) =>
					'<button class="chip' + (k === period ? ' active' : '') + '" data-period="' + k + '">' + esc(label) + '</button>'
				).join('') + '</div>' +
				'<div class="grid stats mt">' +
				'<div class="stat"><div class="label">' + esc(T.gross_sales) + '</div><div class="value">' + money(data.summary.gross) + '</div></div>' +
				'<div class="stat"><div class="label">' + esc(T.net_sales) + '</div><div class="value">' + money(data.summary.net) + '</div></div>' +
				'<div class="stat"><div class="label">' + esc(T.orders) + '</div><div class="value">' + num(data.summary.orders) + '</div></div>' +
				'<div class="stat"><div class="label">' + esc(T.items_sold) + '</div><div class="value">' + num(data.summary.items) + '</div></div>' +
				'<div class="stat"><div class="label">' + esc(T.avg_order) + '</div><div class="value">' + money(data.summary.avg_order) + '</div></div>' +
				'</div>' +
				'<div class="card mt">' + lineChart(data.series, 'gross') + '</div>' +
				'<div class="mt">' + table([T.date, T.gross_sales, T.net_sales, T.orders, T.items_sold],
					data.series.map((r) => [esc(r.label), '<span class="num">' + money(r.gross) + '</span>', '<span class="num">' + money(r.net) + '</span>', num(r.orders), num(r.items)])) + '</div>';
			qa('[data-period]', body).forEach((chip) => chip.addEventListener('click', () =>
				navigate('/reports?tab=sales&period=' + chip.dataset.period)
			));
		} else if (tab === 'products') {
			const data = await api('/reports/products');
			body.innerHTML = table(['#', T.name, T.sku, T.items_sold, T.gross_sales, T.stock],
				data.items.map((r, i) => [num(i + 1), esc(r.name), '<span class="num">' + esc(r.sku || '–') + '</span>', num(r.qty), '<span class="num">' + money(r.revenue) + '</span>', r.stock === null ? '–' : num(r.stock)]));
		} else if (tab === 'customers') {
			const data = await api('/reports/customers');
			body.innerHTML = table(['#', T.name, T.email, T.orders_count, T.total_spent],
				data.items.map((r, i) => [num(i + 1), esc(r.name), '<span class="num">' + esc(r.email) + '</span>', num(r.orders), '<span class="num">' + money(r.spent) + '</span>']));
		} else if (tab === 'categories') {
			const data = await api('/reports/categories');
			body.innerHTML = table([T.categories, T.items_sold, T.gross_sales],
				data.items.map((r) => [esc(r.name), num(r.qty), '<span class="num">' + money(r.revenue) + '</span>']));
		} else if (tab === 'stock') {
			const data = await api('/reports/stock');
			body.innerHTML = table([T.name, T.sku, T.stock, T.total],
				data.items.map((r) => [esc(r.name), '<span class="num">' + esc(r.sku || '–') + '</span>', num(r.stock), '<span class="num">' + money(r.value) + '</span>']));
		} else if (tab === 'audit') {
			const data = await api('/reports/audit?per_page=50');
			body.innerHTML = table([T.date, T.name, T.actions, 'IP'],
				data.items.map((r) => ['<span class="num">' + esc((r.created_at || '').replace('T', ' ').slice(0, 16)) + '</span>', esc(r.user_name), esc(r.action + (r.object_id ? ' #' + r.object_id : '')), '<span class="num">' + esc(r.ip) + '</span>']));
		}
	});

	/* ============================== SETTINGS ============================== */

	route('settings', async (main) => {
		let data;
		try {
			data = await api('/settings');
		} catch (err) {
			main.innerHTML = '<div class="empty"><span class="ico">🔒</span>' + esc(err.message) + '</div>';
			return;
		}

		const s = data.settings;
		const groups = data.cap_groups;

		const permRows = data.all_roles
			.filter((r) => r.slug !== 'administrator')
			.map((r) => {
				const rolePerms = s.permissions[r.slug] || [];
				const cells = Object.keys(groups).map((g) =>
					'<td>' + groups[g].map((action) => {
						const cap = 'wfcp_' + g + '_' + action;
						return '<label style="display:block;font-size:11px"><input type="checkbox" class="perm" data-role="' + r.slug + '" value="' + cap + '"' +
							(rolePerms.includes(cap) ? ' checked' : '') + '> ' + esc(T['perm_' + action] || action) + '</label>';
					}).join('') + '</td>'
				).join('');
				return '<tr><td><label><input type="checkbox" class="role-allow" value="' + r.slug + '"' +
					(s.roles.includes(r.slug) ? ' checked' : '') + '> <strong>' + esc(r.name) + '</strong></label></td>' + cells + '</tr>';
			}).join('');

		main.innerHTML =
			'<div class="page-head"><h1>' + esc(T.settings) + '</h1></div>' +
			'<div class="card">' +
			'<div class="field"><label>' + esc(T.panel_slug) + '</label><input id="s-slug" value="' + esc(s.slug) + '" dir="ltr">' +
			'<div class="hint">' + esc(T.panel_slug_help) + ' — ' + esc(data.panel_url) + '</div></div>' +
			'<div class="row">' +
			'<div class="field"><label>' + esc(T.default_theme) + '</label><select id="s-theme">' +
			['auto', 'light', 'dark'].map((t) => '<option value="' + t + '"' + (s.theme === t ? ' selected' : '') + '>' + esc(T['theme_' + t]) + '</option>').join('') +
			'</select></div>' +
			'<div class="field"><label>' + esc(T.rows_per_page) + '</label><input id="s-perpage" type="number" min="5" max="100" value="' + s.per_page + '"></div>' +
			'<div class="field"><label>' + esc(T.low_stock_threshold) + '</label><input id="s-lowstock" type="number" min="0" value="' + s.low_stock + '"></div>' +
			'</div></div>' +
			'<div class="card mt"><h3>' + esc(T.allowed_roles) + ' / ' + esc(T.permissions) + '</h3>' +
			'<div class="table-wrap"><table class="perm-table"><thead><tr><th>' + esc(T.role) + '</th>' +
			Object.keys(groups).map((g) => '<th>' + esc(T['perm_' + g] || g) + '</th>').join('') +
			'</tr></thead><tbody>' + permRows + '</tbody></table></div>' +
			'<p class="muted">Administrator: ' + esc(T.all) + ' ✓</p></div>' +
			'<div class="mt"><button class="btn" id="s-save">' + esc(T.save) + '</button></div>';

		q('#s-save').addEventListener('click', async () => {
			const roles = qa('.role-allow:checked').map((c) => c.value).concat(['administrator']);
			const permissions = {};
			qa('.perm:checked').forEach((c) => {
				(permissions[c.dataset.role] = permissions[c.dataset.role] || []).push(c.value);
			});
			const result = await api('/settings', {
				method: 'PUT',
				body: {
					slug: q('#s-slug').value,
					theme: q('#s-theme').value,
					per_page: q('#s-perpage').value,
					low_stock: q('#s-lowstock').value,
					roles,
					permissions,
				},
			});
			toast(T.settings_saved, 'success');
			if (result.panel_url !== data.panel_url) {
				setTimeout(() => { location.href = result.panel_url; }, 1200);
			}
		});
	});
})();
