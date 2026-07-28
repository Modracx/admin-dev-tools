# Modracx Admin Dev Tools

![Packagist version](https://img.shields.io/packagist/v/modracx/mage-admin-dev-tools.svg)
![License](https://img.shields.io/packagist/l/modracx/mage-admin-dev-tools.svg)

## Overview

A **single, unified developer toolbar** for Magento 2 that lives on the **top‑center** of every admin page. It provides instant access to the most frequently used admin utilities – cache management, indexer execution, log tailing, cron health, configuration inspection, activity auditing, grid resetting, module toggling, and a few handy CLI shortcuts – all from a sleek, responsive drawer that never interferes with Magento’s native UI.

The toolbar is **stand‑alone**: it does not depend on any legacy extensions and can be installed on a fresh Magento installation or added to an existing project without removing other modules.

---

## Features

| Feature | What it does | Key UI cue |
|---|---|---|
| **Cache Management** | Flush any cache type, run bulk actions (Magento cache, storage, catalog images, JS/CSS, static files). Disabled caches appear as `off`, stale caches as `stale`. | Cache tab |
| **Indexer Runner** | List all registered indexers, run any on‑demand, highlight stale ones. | Index tab |
| **Log Tail** | View the last 50 / 100 / 250 / 500 / 1000 lines of `system.log`, `exception.log` or `debug.log` with severity colours; clear log (requires permission). | Logs tab |
| **Exception Reports** | Browse recent crash reports from `var/report`, open the full stack trace. | Reports tab |
| **Cron Health** | Mini‑badge on the launcher shows overall health; the *Cron* panel displays status counts, last successful run per group, recent failures, MySQL queue backlog. | Cron badge |
| **Config Inspector** | Show the most recent `core_config_data` rows; perform path look‑up across all scopes. | Config tab |
| **Activity Audit** | Detailed audit of every backend change (admin, REST, SOAP, GraphQL) – who, what, from‑value, to‑value, timestamp. Filterable and clearable. | Activity tab |
| **Grid Bookmarks** | Reset your personal grid state (views, columns, filters) per UI component. | Grids tab |
| **Module Overview** | List every declared module, show disabled ones, display the schema version (from `module.xml`). Enable/disable modules with a dedicated permission. | Modules tab |
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
| `Modracx_AdminDevTools::cache_flush` | Flush any cache type |
| `Modracx_AdminDevTools::flush_config` | Flush config cache |
| `Modracx_AdminDevTools::flush_block` | Flush block cache |
| `Modracx_AdminDevTools::flush_fpc` | Flush full‑page cache |
| `Modracx_AdminDevTools::flush_other` | Flush all other cache types |
| `Modracx_AdminDevTools::reindex` | Run indexers |
| `Modracx_AdminDevTools::logs` | View log tail |
| `Modracx_AdminDevTools::logs_clear` | Clear log file |
| `Modracx_AdminDevTools::reports` | Read exception reports |
| `Modracx_AdminDevTools::cron_health` | View cron health |
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
| Flush a cache type | `modracx_devtools/cache/flush?type=<cache_type_code>` |
| Run a cache action | `modracx_devtools/cache/run?action=<action_id>` |
| Run an indexer | `modracx_devtools/indexer/run?indexer_id=<id>` |
| Cache panel | `modracx_devtools/cache/panel` |
| Index panel | `modracx_devtools/indexer/panel` |
| Log tail | `modracx_devtools/log/view?file=system|exception|debug` |
| Clear log | `modracx_devtools/log/clear?file=…` |
| Cron panel / badge | `modracx_devtools/cron/status`, `modracx_devtools/cron/badge` |
| Recent config | `modracx_devtools/config/recent` |
| Config lookup | `modracx_devtools/config/lookup?path=web/secure/base_url` |
| Grid bookmarks | `modracx_devtools/bookmark/index`, `modracx_devtools/bookmark/reset?namespace=…` |
| Exception reports | `modracx_devtools/report/index`, `modracx_devtools/report/view?id=…` |
| Module status | `modracx_devtools/module/index` |
| Activity log | `modracx_devtools/activity/index`, `modracx_devtools/activity/clear` |
| Enable/disable module | `modracx_devtools/module/toggle?module=Vendor_Name&enable=0|1` |

`action_id` values: `magento_cache`, `cache_storage`, `catalog_images`, `js_css`, `static_files` (see `Model/CacheAction`).

---

## Safety Notes

* **Log access** – Files are selected by a safe ID (`system`, `exception`, `debug`). No path traversal is possible.
* **Config masking** – Any config value whose path contains `pass|secret|key|token|salt|private|credential|license|signature|cipher` is masked before being displayed.
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

The drawer contains a **horizontal tab strip**. The order of tabs is fixed (Cache → Index → Logs → Reports → Cron → Config → Activity → Grids → Modules → CLI).

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
* **Bulk actions** – Open the *Additional Cache Management* dropdown. Choose any of the following:
  * Magento Cache – Flushes the default Magento cache (`cache_type = all`).
  * Cache Storage – Flushes the cache storage backend (Redis, Varnish, etc.).
  * Catalog Images – Clears generated product image thumbnails.
  * JS/CSS – Clears merged JavaScript/CSS files.
  * Static Files – Flushes static assets generated by the static‑content deploy.
* Disabled caches appear as `off`; stale caches (invalidated but not yet flushed) appear as `stale`.

#### Index Panel
* The table lists **all registered indexers** (e.g., `catalogsearch_fulltext`, `customer_grid`).
* Stale indexers (status ≠ `valid`) are highlighted in orange.
* Click **Run** to re‑index that specific indexer.

#### Log Tail Panel
* Use the dropdown to pick `system.log`, `exception.log`, or `debug.log`.
* Choose the number of lines to display (50 – 1000).
* The tail highlights severity (`ERROR` in red, `WARN` in orange).
* If you have `Modracx_AdminDevTools::logs_clear` permission, a **Clear** button appears to truncate the file (use with caution!).

#### Exception Reports Panel
* Lists recent files from `var/report`.
* Clicking a row opens the full stack trace in a modal.
* The UI **never** displays raw file paths; IDs are resolved safely against a vetted directory listing.

#### Cron Health Panel
* The **badge** on the launcher glows green (healthy) or red (issues).
* Opening the tab shows a summary table per cron group: last successful run, failures in the last 24 h, and MySQL message‑queue backlog.

#### Config Inspector
* Shows the **10 most recent** entries in `core_config_data`.
* Use the *Path lookup* field to enter a configuration path (e.g., `web/secure/base_url`).
* The result shows the **value per scope** (default, website, store). Sensitive values are automatically masked.

#### Activity Log
* Displays a chronological list of admin‑side changes (e.g., product price updates, settings changes).
* Columns: User, Entity, Action, From → To, Scope, Timestamp, Origin (admin, REST, SOAP, GraphQL).
* Use the filter box to search by user, entity, or free text.
* With `Modracx_AdminDevTools::activity_clear` permission you can truncate the activity log.

#### Grid Bookmarks
* Lists saved **grid states** for UI components you own (e.g., Order grid filters).
* Click **Reset** next to a grid to restore the default view.

#### Modules Panel
* Shows every module declared in `app/code` or `vendor`.
* Columns: Name, Status (enabled/disabled), Schema version (from `module.xml`).
* If you have `Modracx_AdminDevTools::modules_toggle` permission, a **Toggle** button lets you enable or disable a module directly from the UI.
* The toggle performs **DI compilation, conflict checks, and a full cache clean** automatically. In production mode the button is hidden.

#### CLI Shortcut
* At the bottom of the drawer a small info line shows the available CLI shortcut:

```bash
bin/magento modracx:cache:config
```

Running it flushes the config cache – useful for developers who prefer terminal commands.

### Security & Best Practices

1. **Assign permissions sparingly** – only give the needed resources to each admin role.
2. **Never enable the module toggler in production** – the UI disables it automatically, but double‑check your role permissions.
3. **Log pruning** – The extension automatically prunes the activity log nightly; you may adjust the retention period via `di.xml` if required.
4. **Avoid clearing logs in production** unless you have a compliance reason; the log‑clear button respects the permission you grant.

### Advanced Usage (Developers)

* **Programmatic access** – The module registers a service contract (`Modracx\AdminDevTools\Api\CacheManagerInterface`) allowing other modules to invoke cache flushes or indexer runs via dependency injection.
* **Event observers** – You can listen to `modracx_devtools_cache_flush_before` and `modracx_devtools_indexer_run_before` events if you need to perform custom actions before the toolbar triggers them.
* **Extending the UI** – The front‑end uses a lightweight AMD component (`devbar.js`). To add a custom tab, create a new UI component under `view/adminhtml/web/js/` and register it in `etc/adminhtml/di.xml`.

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

