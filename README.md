# Modracx Admin Dev Tools

![Packagist version](https://img.shields.io/packagist/v/modracx/mage-admin-dev-tools.svg)
![License](https://img.shields.io/packagist/l/modracx/mage-admin-dev-tools.svg)

## Overview

A **single, unified developer toolbar** for Magento 2 that lives on the **top‑center** of every admin page. It provides instant access to the most frequently used admin utilities – cache management with flush verification, indexer execution and mode toggling, log tailing, exception reports, cron health & on-demand execution, developer switches, dependency & event wiring inspection, environment diagnostics, mail catching, URL rewrite lookup, database size auditing, configuration inspection, activity auditing, grid resetting, module toggling, and CLI shortcuts – all from a sleek, responsive drawer that never interferes with Magento’s native UI.

The toolbar is **stand‑alone**: it does not depend on any legacy extensions and can be installed on a fresh Magento installation or added to an existing project without removing other modules.

---

## Features

| Feature | What it does | Key UI cue |
|---|---|---|
| **Cache Management** | Flush any cache type or run bulk storage actions. Verified via probe entries to confirm backend clean execution. Disabled caches appear as `off`, stale caches as `stale`. | Cache tab |
| **Indexer Runner & Insight** | List registered indexers, run on‑demand, toggle mode between *Update on Save* and *Update by Schedule*, and monitor mview changelog backlog (`_cl`). | Index tab |
| **Developer Flags** | Toggle key `dev/*` configuration switches directly from the admin UI (Template Hints for storefront/admin, Block Names, Debug Logging, Inline Translation, Minification, Static Signing) with IP restriction & mode warnings. | Flags tab |
| **Wiring Inspector** | Inspect Dependency Injection preferences, virtual types, and active plugins (with intercepted methods like `beforeSave`/`aroundSave`/`afterSave` and sort order) for any class, as well as event observers across all areas. | Wiring tab |
| **Environment Summary** | Comprehensive overview of Magento edition/mode, PHP version, memory limits, OPcache, Xdebug, database schema/host, Redis/file storage, Elasticsearch/OpenSearch health cluster ping, and queue runner state. | Env tab |
| **Mail Catcher** | Intercept and view rendered outgoing emails (subject, recipient, sender, CC/BCC, HTML/text body, delivery status/errors). Supports dev suppression (`dev/modracx/mail_suppress`) and auto-pruning. | Mail tab |
| **URL Rewrite Lookup** | Lookup paths in `url_rewrite` table. Supports pasting full URLs or request paths, resolves store views, inspects multi-hop redirect chains, and detects circular redirect loops. | URLs tab |
| **Database Size** | Breakdown of database disk usage via `information_schema`. Lists top 25 tables, row counts, data/index sizes, percentage shares, and flags disposable bloat tables with actionable guidance. | DB tab |
| **Log Tail** | View the last 50 / 100 / 250 / 500 / 1000 lines of `system.log`, `exception.log` or `debug.log` with severity colors; clear log (requires permission). | Logs tab |
| **Exception Reports** | Browse recent crash reports from `var/report`, open the full stack trace. | Reports tab |
| **Cron Health & Runner** | Launcher badge shows health; panel displays group status counts, last successful run, queue backlog, and allows **on-demand execution of individual cron jobs** with runtime & output tracking. | Cron tab & badge |
| **Config Inspector & Lookup** | Show the 10 most recent `core_config_data` rows; perform path look‑up across all scopes with automatic sensitive value masking. | Config / Lookup tabs |
| **Activity Audit** | Detailed audit of every backend change (admin, REST, SOAP, GraphQL) – who, what, from‑value, to‑value, timestamp. Filterable and clearable. | Activity tab |
| **Grid Bookmarks** | Reset your personal grid state (views, columns, filters) per UI component. | Grids tab |
| **Module Overview** | List declared modules, show disabled ones, display schema versions. Two-step enable/disable module toggle with DI compilation and conflict checks. | Modules tab |
| **CLI Shortcut** | `bin/magento modracx:cache:config` flushes the config cache in one command. | Toolbar footer (info) |

---

## UI Design

* **Launcher** – A dimmed `MODRACX` pill centered on the top‑center. It brightens on hover, and a tiny **cron‑health dot** sits on the pill itself.
* **Drawer** – Slides down a horizontal tab strip. Each tab loads its panel lazily (no DB queries until the tab is opened).
* **Z‑Index strategy** – Launcher (`z-index: 650`) is above Magento’s pinned action bar (`501`) but below the admin menu (`700`). The drawer (`880`) sits above the menu yet below modal overlays (`899`). This guarantees the toolbar never obscures native dialogs.
* **Responsive** – Position is persisted in `localStorage`. Drag the launcher horizontally if it ever collides with a long page title; the new X‑position is remembered across sessions.
* **Keyboard navigation** – `Esc` closes the drawer, `Tab` is trapped inside while open, arrow keys / `Home` / `End` switch tabs, focus returns to the launcher on close.

---

## Installation

```bash
composer require modracx/mage-admin-dev-tools
php bin/magento module:enable Modracx_AdminDevTools
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:flush
```

> **Tip:** After enabling/disabling the module, run `setup:upgrade` manually – the UI only shows a reminder.

---

## Permissions

Grant the following resources under **System → Permissions → User Roles → Role Resources → Modracx Admin Dev Tools**:

| Resource | Description |
|---|---|
| `Modracx_AdminDevTools::cache_flush` | Access Cache panel and flush cache types |
| `Modracx_AdminDevTools::flush_config` | Flush config cache |
| `Modracx_AdminDevTools::flush_block` | Flush block HTML cache |
| `Modracx_AdminDevTools::flush_fpc` | Flush full‑page cache |
| `Modracx_AdminDevTools::flush_other` | Flush all other cache types |
| `Modracx_AdminDevTools::reindex` | Access Index panel and run indexers |
| `Modracx_AdminDevTools::reindex_mode` | Change indexer mode (realtime / schedule) |
| `Modracx_AdminDevTools::flags` | View Developer Flags panel |
| `Modracx_AdminDevTools::flags_write` | Change developer flags |
| `Modracx_AdminDevTools::wiring` | Inspect DI preferences, plugins & event observers |
| `Modracx_AdminDevTools::environment` | View Environment Summary |
| `Modracx_AdminDevTools::mail` | View Captured Mail log |
| `Modracx_AdminDevTools::mail_clear` | Clear captured mail log |
| `Modracx_AdminDevTools::rewrites` | Perform URL Rewrite lookups |
| `Modracx_AdminDevTools::database` | View Database Size breakdown |
| `Modracx_AdminDevTools::logs` | View log tail |
| `Modracx_AdminDevTools::logs_clear` | Clear log files |
| `Modracx_AdminDevTools::reports` | Read exception reports |
| `Modracx_AdminDevTools::cron_health` | View cron health and queue status |
| `Modracx_AdminDevTools::cron_run` | Run individual cron jobs on-demand |
| `Modracx_AdminDevTools::modules` | View module status |
| `Modracx_AdminDevTools::modules_toggle` | Enable/disable modules |
| `Modracx_AdminDevTools::config_inspect` | Inspect recent config changes & path lookup |
| `Modracx_AdminDevTools::activity` | Read activity audit |
| `Modracx_AdminDevTools::activity_clear` | Clear activity log |
| `Modracx_AdminDevTools::grid_bookmarks` | Reset own grid bookmarks |

Only grant the permissions you actually need; the toolbar hides actions you lack access to.

---

## Routes

| Action | URL |
|---|---|
| Cache panel | `modracx_devtools/cache/panel` |
| Flush a cache type | `modracx_devtools/cache/flush?type=<cache_type_code>` |
| Run a cache action | `modracx_devtools/cache/run?action=<action_id>` |
| Index panel | `modracx_devtools/indexer/panel` |
| Run an indexer | `modracx_devtools/indexer/run?indexer_id=<id>` |
| Toggle indexer mode | `modracx_devtools/indexer/mode?indexer_id=<id>&scheduled=0|1` |
| Developer Flags panel | `modracx_devtools/flags/index` |
| Toggle developer flag | `modracx_devtools/flags/toggle?flag=<key>&enable=0|1` |
| Wiring Inspector panel | `modracx_devtools/wiring/index?type=…|event=…` |
| Environment panel | `modracx_devtools/environment/index` |
| Mail Catcher panel | `modracx_devtools/mail/index` |
| View captured email | `modracx_devtools/mail/view?id=<mail_id>` |
| Clear mail log | `modracx_devtools/mail/clear` |
| URL Rewrite Lookup panel | `modracx_devtools/rewrite/index?path=…` |
| Database Size panel | `modracx_devtools/database/index` |
| Log tail view | `modracx_devtools/log/view?file=system|exception|debug` |
| Clear log file | `modracx_devtools/log/clear?file=…` |
| Exception reports | `modracx_devtools/report/index`, `modracx_devtools/report/view?id=…` |
| Cron panel / badge | `modracx_devtools/cron/status`, `modracx_devtools/cron/badge` |
| Run cron job | `modracx_devtools/cron/run?job_code=<code_name>` |
| Recent config | `modracx_devtools/config/recent` |
| Config lookup | `modracx_devtools/config/lookup?path=web/secure/base_url` |
| Grid bookmarks | `modracx_devtools/bookmark/index`, `modracx_devtools/bookmark/reset?namespace=…` |
| Module status | `modracx_devtools/module/index` |
| Enable/disable module | `modracx_devtools/module/toggle?module=Vendor_Name&enable=0|1` |
| Activity log | `modracx_devtools/activity/index`, `modracx_devtools/activity/clear` |

`action_id` values: `magento_cache`, `cache_storage`, `catalog_images`, `js_css`, `static_files` (see `Model/CacheAction`).

---

## Safety Notes

* **Flush Verification** – Flushes write a temporary probe key before clearing and check its absence afterwards, warning if file permissions or cache storage backends silently ignored the flush request.
* **Log access** – Files are selected by a safe ID (`system`, `exception`, `debug`). No path traversal is possible.
* **Config masking** – Any config value whose path contains `pass|secret|key|token|salt|private|credential|license|signature|cipher` is masked before being displayed.
* **Mail Catcher & Suppression** – Outgoing mail capture never interrupts email delivery. Delivery suppression (`dev/modracx/mail_suppress`) is strictly disabled in production mode. Log entries are auto-pruned after 14 days (`Cron/PruneMailLog.php`).
* **Database Inspector** – The DB tab is strictly read-only to prevent accidental database loss; table truncation must be performed intentionally via terminal/SQL client.
* **Bookmark reset** – Scoped to the signed‑in user; one user cannot affect another’s grid state.
* **Report IDs** – Resolved against a vetted directory listing; traversal is prevented.
* **Module toggling** – Edits `app/etc/config.php`. In *production mode* the action is disabled; in *developer mode* the module runs Magento’s DI and conflict checks, then clears generated code and cache.
* **Activity log** – Only model‑layer changes are recorded; raw DB updates are ignored.
* **Log pruning** – Nightly cleanup retains only the last 60 days of activity.
* **Cron queries** – Bounded to recent windows (24 h for counts, 7 d for last‑successful) to avoid full‑table scans.

---

## User Guide (full walkthrough)

### Accessing the Toolbar

1. Log into the Magento admin panel.
2. Locate the **dimmed “MODRACX” pill** centered on the very top edge of the page.
3. Hover – the pill brightens, indicating it’s interactive.
4. Click to *slide* the drawer down.

If the pill overlaps a long page title, click‑drag it left or right; the new X‑position is saved in `localStorage` and persists across sessions.

### Navigating the Drawer

The drawer contains a **horizontal tab strip** (Cache → Index → Flags → Wiring → Env → Mail → URLs → DB → Logs → Reports → Activity → Cron → Config → Lookup → Grids → Modules).

*Use the keyboard*:

| Key | Action |
|---|---|
| `Esc` | Close drawer |
| `Tab` | Cycle focus within the open drawer |
| Arrow left / right | Move between tabs |
| `Home` / `End` | Jump to first / last tab |
| `Enter` (on a list item) | Execute the selected action (e.g., run an indexer) |

### Using Each Panel

#### Cache Panel
* **Flush a single cache type** – Click the *Flush* button next to the cache you want to clear.
* **Flush Verification** – The panel seeds a probe key and verifies its deletion after the flush operation. If the cache directory or backend failed to clear, an explicit warning is displayed.
* **Bulk actions** – Open the *Additional Cache Management* dropdown (Magento Cache, Cache Storage, Catalog Images, JS/CSS, Static Files).
* Disabled caches appear as `off`; stale caches (invalidated but not yet flushed) appear as `stale`.

#### Index Panel
* Displays registered indexers (e.g., `catalogsearch_fulltext`, `customer_grid`).
* Highlights stale indexers (status ≠ `valid`) in orange.
* **Indexer Mode Switch** – Toggle an indexer between *Update on Save* (realtime) and *Update by Schedule*.
* **Backlog Monitoring** – View unresolved mview changelog counts (`_cl` table version delta) for scheduled indexers.
* Click **Run** to re‑index a specific indexer.

#### Developer Flags Panel
* Toggle key developer settings on the default scope without running `bin/magento config:set`:
  * Storefront / Admin / Block Name Template Hints.
  * Require URL parameter (`?templatehints=…`) for storefront hints.
  * Debug Logging (`var/log/debug.log`).
  * Storefront / Admin Inline Translation.
  * JS / CSS Merging, Minification & JS Bundling.
  * Static file signing & HTML minification.
* Displays warnings if IP restriction (`dev/restrict/allow_ips`) is active or if production mode requires static content re-deployment.

#### Wiring Inspector Panel
* **Type Inspection** – Search any class, interface, or virtual type (e.g., `Magento\Catalog\Api\ProductRepositoryInterface`) to view:
  * Resolved DI preferences per area (`global`, `frontend`, `adminhtml`, etc.).
  * Active plugins, including area, sort order, disabled status, and intercepted methods (`beforeSave`, `aroundSave`, `afterSave`).
  * Virtual type declarations built on the class.
  * Full class ancestry hierarchy.
* **Event Observer Inspection** – Search any event name (e.g., `sales_order_place_after`) to see registered observers across all Magento areas, their instance classes, and disabled flags.

#### Environment Summary Panel
* Overview of runtime state: Magento edition & version, application mode, PHP version, memory limit, OPcache & Xdebug status, enabled modules count, static asset version, admin URL path, and timezone.
* Database server version, host, schema name, table prefix, and active connection names.
* Storage backends (Redis vs File for cache and sessions) and `var/` directory writability.
* **Search Engine Health** – Active search engine (Elasticsearch/OpenSearch), configured host/port, and live ping status (cluster name, health status, node count).
* **Queue Configuration** – Message queue transport (AMQP / MySQL) and consumer runner state.

#### Mail Catcher Panel
* Displays a log of all outgoing transactional emails rendered by Magento.
* Shows sent timestamp, subject, recipient (`mail_to`), delivery status, and errors.
* **Drilldown View** – Open any email to inspect headers (From, To, CC, BCC, Content-Type) and full rendered HTML or text body.
* **Delivery Suppression** – Support for suppressing outgoing mail delivery in dev environments via `dev/modracx/mail_suppress`.
* Single-click **Clear Log** button and automated 14-day background cron cleanup (`Cron/PruneMailLog`).

#### URL Rewrite Lookup Panel
* Search any path or paste a full URL (e.g., `my-product.html`).
* Displays all matching entries from `url_rewrite` across store views, entity types, and IDs.
* Traces multi-hop redirect chains (e.g., Request → Target A → Target B).
* Detects and highlights **circular redirect loops** to quickly diagnose 404/redirect errors.

#### Database Size Panel
* Analyzes database disk usage via `information_schema`.
* Shows overall database name, total byte size, and table count.
* Lists top 25 tables sorted by size, detailing row counts, data size, index size, and total percentage share.
* Identifies **bloat tables** (e.g., `cron_schedule`, `customer_visitor`, `report_event`, `queue_message`, `_cl` index changelogs) with helpful cleanup recommendations. Read-only for safety.

#### Log Tail Panel
* Pick `system.log`, `exception.log`, or `debug.log`.
* Select lines count (50 – 1000).
* Severity color highlighting (`ERROR` in red, `WARN` in orange).
* Truncate log file via **Clear** button (requires permission `Modracx_AdminDevTools::logs_clear`).

#### Exception Reports Panel
* Lists crash report files from `var/report`.
* Click any row to view the full stack trace in a popup.

#### Cron Health & Runner Panel
* Launcher badge glows green (healthy) or red (issues).
* Displays summary table per cron group: last successful run, failures in last 24h, and MySQL queue backlog.
* Filter cron run history by status (Running, Success, Error, Missed).
* **On-Demand Cron Job Runner** – Click **Run** next to any job code to execute it immediately within the request, capturing execution time and output or error traces.

#### Config Inspector & Lookup
* Shows 10 most recent entries in `core_config_data`.
* Path lookup field (e.g., `web/secure/base_url`) shows values per scope (default, website, store). Sensitive values (passwords, tokens, keys) are automatically masked.

#### Activity Log
* Chronological audit of backend changes (product edits, config updates).
* Displays User, Entity, Action, From → To, Scope, Timestamp, Origin (admin, REST, SOAP, GraphQL).
* Filterable search box and clear log option.

#### Grid Bookmarks
* Lists saved UI grid states owned by the current user.
* Click **Reset** to restore default grid columns and filters.

#### Modules Panel
* Lists all declared modules in `app/code` or `vendor` with status and `module.xml` schema version.
* **Toggle Button** – Enables or disables a module with two-step confirmation, running DI compilation, conflict checks, and cache clearing. Disabled in production mode.

#### CLI Shortcut
* Footer info line shows CLI shortcut:

```bash
bin/magento modracx:cache:config
```

---

## Security & Best Practices

1. **Assign permissions sparingly** – only give the needed resources to each admin role.
2. **Never enable the module toggler in production** – the UI disables it automatically, but double‑check your role permissions.
3. **Log pruning** – Activity log and mail logs are automatically pruned nightly; retention periods can be adjusted via `di.xml` if required.
4. **Avoid clearing logs in production** unless you have a compliance reason; the log‑clear button respects the permission you grant.

---

## Uninstalling the Extension

```bash
php bin/magento module:disable Modracx_AdminDevTools
php bin/magento setup:upgrade
# Optionally remove the codebase:
rm -rf vendor/modracx/mage-admin-dev-tools
composer dump-autoload
```

or

```bash
composer remove modracx/mage-admin-dev-tools
```

After disabling, clear caches to ensure no remnants of the launcher remain.

---

## Happy hacking!

You now have a **premium, production‑ready developer toolbar** that elevates Magento admin productivity while staying safe, lightweight, and visually polished. For updates, documentation, or to open an issue, visit the [Modracx Portal](https://modracx.dpdns.org/) or check the GitHub repository.

