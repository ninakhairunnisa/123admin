=== 123Admin – Fast Control Panel for WooCommerce ===
Contributors: 123admin
Tags: woocommerce, admin, panel, dashboard, orders, products, rtl
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A blazing-fast, standalone, mobile-first control panel for WooCommerce stores — manage products, orders, customers and reports without wp-admin.

== Description ==

123Admin creates a dedicated store-management panel at `site.com/123admin` (the address is configurable: `/store`, `/panel`, `/control`, `/manager`, …). The panel is rendered completely outside wp-admin and the theme: no admin header/footer, no Gutenberg, no widgets, no emoji scripts — only one small CSS file and two small JS files, so it loads in a fraction of the time of the default WooCommerce admin.

= Highlights =

* **Standalone & fast** — custom SPA shell, Vanilla JS, AJAX everywhere, lazy-loaded images, brief server-side caching of heavy aggregates.
* **Material Design 3** — light/dark/auto themes, mobile-first layout with a bottom navigation bar on phones and a navigation rail on desktop.
* **Dashboard** — today/week/month sales, total revenue, order counts per status, customer count, low/out-of-stock counters, 30-day sales & orders charts, recent orders, live "new order" notifications, recent panel activity.
* **Products** — instant search (name & SKU), advanced filter views (low stock, out of stock, no image, on sale, best/slow sellers), quick create, full edit (name, descriptions, prices, scheduled sales, SKU, stock, categories, tags, weight, dimensions, status), duplicate, variations editor, bulk publish/draft/delete, bulk price % update, bulk stock update, CSV export, printable list.
* **Orders** — live list, smart search (order #, phone, email, customer name), status & today/yesterday filters with counts, quick status change, full detail view, edit line items (add/remove/quantity), order & customer notes, invoice and shipping-label printing, bulk status/delete, CSV export.
* **Customers** — quick search, profile with lifetime stats and order history, edit profile/role, block/unblock (sessions are revoked instantly), internal notes, last-login tracking, CSV export.
* **Reports** — daily/weekly/monthly/yearly sales with gross/net/AOV, top products, top customers, categories, stock valuation, full audit log, CSV exports.
* **Access control** — administrators choose which roles may enter the panel and exactly what each role can do via a granular permission matrix (view/create/edit/delete/stock/price for products; view/create/edit/delete/status/print for orders; view/edit/block for users; view/export for reports). Works with custom roles too.
* **Security** — WordPress cookie auth + REST nonces (CSRF), capability checks on every endpoint, output escaping (XSS), prepared statements (SQLi), rate limiting, full audit log, instant session revocation on block.
* **i18n** — text domain `wfcp`, POT/PO/MO included; ships with English (LTR) and Persian فارسی (RTL).
* **PWA** — installable web app with manifest and a small app-shell service worker.
* **Developer-friendly** — REST API under `wfcp/v1`, hooks (`wfcp_loaded`, `wfcp_boot_config`, `wfcp_rest_controllers`, `wfcp_dashboard_data`, `wfcp_user_caps`, …) for extensions.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/123admin` and activate it.
2. Visit **site.com/123admin** (log in with an administrator or shop-manager account).
3. Open **Settings** inside the panel to change the address, allowed roles and per-role permissions.

== Frequently Asked Questions ==

= The panel URL shows a 404. =
Re-save WordPress Settings → Permalinks once, or deactivate/reactivate the plugin to flush rewrite rules.

= Which languages are included? =
English and Persian (RTL). Any other language can be added by translating `languages/wfcp.pot`.

== Changelog ==

= 1.0.0 =
* Initial release.
