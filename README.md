# 123Admin – Fast Control Panel for WooCommerce

پنل مدیریت فوق‌سریع، مستقل و موبایل‌محور برای فروشگاه‌های ووکامرس — بدون نیاز به ورود به wp-admin.

A blazing-fast, standalone, mobile-first control panel for WooCommerce stores. It replaces day-to-day wp-admin / WooCommerce Admin operations with a dedicated SPA served at **`site.com/123admin`** (address is configurable: `/store`, `/panel`, `/control`, `/manager`, …).

![Requires PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb3) ![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b) ![WooCommerce 8+](https://img.shields.io/badge/WooCommerce-8%2B-96588a) ![License GPL-2.0](https://img.shields.io/badge/license-GPLv2-green)

## Why it's fast

The panel is rendered **completely outside the theme and wp-admin**:

- No admin header/footer, no Gutenberg, no widgets, no emoji scripts, no theme CSS/JS.
- One small CSS file + two small Vanilla-JS files (no frameworks, no build step), cache-busted by file mtime and cached by a PWA service worker.
- All data flows through a dedicated REST API (`wfcp/v1`) returning lean JSON; heavy aggregates (dashboard, reports) are server-cached for a couple of minutes.
- Lazy-loaded thumbnails, debounced instant search, skeleton/spinner states.

## Features

| Area | Capabilities |
|---|---|
| **Dashboard** | Today/week/month sales, total revenue, order counts per status, customers, low/out-of-stock counters, 30-day sales & orders charts, recent orders, recent activity, live new-order toast |
| **Products** | Instant search (name/SKU), filter views (low stock, out of stock, no image, on sale, best/slow sellers), quick create, full edit (prices, scheduled sale, SKU, stock, categories, tags, weight, dimensions, descriptions, status), duplicate, variations editor, bulk publish/draft/delete, bulk price %, bulk stock, CSV export, print list |
| **Orders** | Live list, smart search (order #, phone, email, name), status/today/yesterday filters with counts, quick status change, line-item editing (add/remove/qty), notes & history, invoice + shipping-label print, bulk status/delete, CSV export |
| **Customers** | Search, profile with lifetime stats & order history, edit profile/role, block/unblock (sessions revoked instantly), internal notes, last login, CSV export |
| **Reports** | Daily/weekly/monthly/yearly sales (gross/net/AOV/items), top products, top customers, categories, stock valuation, audit log, CSV export |
| **Settings** | Panel slug, allowed roles, granular per-role permission matrix, default theme, rows per page, low-stock threshold |

## Access control

Administrators always have full access. For every other role (including custom roles) you can toggle each permission individually:

- **Products:** view · create · edit · delete · stock · price
- **Orders:** view · create · edit · delete · change status · print
- **Users:** view · edit · block
- **Reports:** view · export

Capabilities (`wfcp_*`) are resolved at runtime via `user_has_cap` — nothing is written to role tables, and changes take effect immediately.

## Security

- WordPress cookie authentication + `wp_rest` nonce on every request (CSRF protection)
- Capability check in every REST `permission_callback`, plus per-field checks (price/stock)
- All output escaped (XSS), all SQL through `$wpdb->prepare` (SQLi)
- Rate limiting on write and export endpoints
- Full audit log of every mutating action (user, action, object, IP, time)
- Blocking a user destroys all of their active sessions instantly
- Hardened response headers on the panel page; HPOS-compatible CRUD only

## Internationalisation

Text domain **`wfcp`** with full POT/PO/MO support (`/languages`). Ships with:

- **English** (LTR)
- **فارسی / Persian** (RTL) — the whole UI flips automatically via CSS logical properties

Regenerate translation files after changing strings: `python3 bin/build-i18n.py`

## For developers

REST namespace: `wfcp/v1` (`/dashboard`, `/products`, `/orders`, `/customers`, `/reports`, `/settings`).

Key hooks:

| Hook | Type | Purpose |
|---|---|---|
| `wfcp_loaded` | action | Plugin fully loaded |
| `wfcp_rest_controllers` | filter | Register your own panel REST controllers |
| `wfcp_boot_config` | filter | Extend the SPA boot config (add JS/CSS, i18n, flags) |
| `wfcp_dashboard_data` | filter | Add custom dashboard KPIs |
| `wfcp_user_caps` | filter | Adjust granted panel capabilities per user |
| `wfcp_allowed_roles` | filter | Adjust roles allowed into the panel |
| `wfcp_product_apply_fields` | action | Handle custom product fields on save |
| `wfcp_settings_updated` | action | React to panel settings changes |
| `wfcp_audit_recorded` | action | Stream audit events elsewhere |
| `wfcp_rate_limit` | filter | Tune rate-limit thresholds |
| `wfcp_panel_template` | filter | White-label the panel shell |

The JS app exposes `window.WFCPApp` (`api`, `route`, `modal`, `toast`, …) so extensions can add panel pages without touching core files.

## Installation

1. Copy this repository into `wp-content/plugins/123admin` and activate **123Admin** in WordPress.
2. Open `site.com/123admin` while logged in as an administrator or shop manager.
3. Configure the address, roles and permissions under **Settings** inside the panel.

If the panel URL returns 404, re-save *Settings → Permalinks* once to flush rewrite rules.

## Requirements

- WordPress 6.4+, WooCommerce 8+ (HPOS supported)
- PHP 8.1+, MySQL 8+ / MariaDB
