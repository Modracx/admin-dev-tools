# Modracx_AdminDevTools

**Package:** `modracx/mage-admin-dev-tools`

A single admin developer toolbar for Magento 2, merging what used to be two separate
extensions:

| Was | Now |
|-----|-----|
| `Modracx_AdminCacheButtons` (`modracx/magento2-admin-cache-buttons`) | Cache group |
| `Modracx_QuickReindex` (`modracx/magento2-quick-reindex`) | Index group |

Since then it has grown into a general developer toolbar: a small launcher parked on the
top edge of every admin page, which opens a tabbed drawer.

## Features

- **Cache** — a **Flush** dropdown with two sections:
  - *Additional Cache Management* — Flush Magento Cache, Flush Cache Storage,
    Flush Catalog Images Cache, Flush JavaScript/CSS Cache, Flush Static Files Cache.
    These do exactly what the same-named buttons on System → Cache Management do.
  - *Cache Types* — every declared cache type, each with its own **Flush** button.
    Disabled types are marked `off`, invalidated ones `stale`.
- **Index** — dropdown listing every registered indexer with a per-indexer **Run** button;
  indexers that aren't `valid` are marked `stale`.
- **Logs** — tail `system.log`, `exception.log` or `debug.log` at 50 / 100 / 250 / 500 /
  1000 lines, with severity highlighting and a Clear button (separate permission).
- **Reports** — the exception reports in `var/report` (the files behind "your report id
  is …"), newest first, click through to the full message and stack trace.
- **Cron** — health badge in the toolbar plus a panel with status counts, per-group last
  successful run, recent failures, and MySQL message queue backlog.
- **Config** — the most recently edited `core_config_data` rows, and a path lookup that
  resolves one path in every scope.
- **Activity** — an audit trail of backend changes: who changed what, from which value to
  which, when, and whether it came through the admin, REST, SOAP or GraphQL (with the
  endpoint). Filterable by source, change type and free text; clearable under its own
  permission.
- **Grids** — reset your own saved grid state (views, columns, filters) per UI component.
- **Modules** — every declared module, which are disabled, and which have a schema version
  behind the version in their `module.xml` (i.e. `setup:upgrade` never ran). Filterable,
  with enable/disable behind its own permission (see below).
- **CLI** — `bin/magento modracx:cache:config` flushes the config cache.

### The launcher and drawer

Everything lives behind one launcher pill centred on the top edge — a dimmed `MODRACX`
tab that brightens on hover. Clicking it slides a drawer down with a horizontal tab
strip; each tab loads its own panel. The cron health dot sits on the launcher itself,
because a warning you have to open something to find is not a warning.

**Why top-centre, not the corner.** Magento pins `.page-actions._fixed` at
`top: 0; right: 0` when you scroll a grid, putting Save / Add New in the top-right — the
previous top-right toolbar sat directly on top of it. Top-centre is the one part of that
pinned bar that is reliably empty (title floats left, buttons float right). If a long
page title ever reaches it, **drag the launcher horizontally**; the position is
remembered in `localStorage`.

**Layering** is chosen against Magento's own z-index scale rather than an arbitrary
large number: the parked launcher sits at 650 — above the pinned action bar (501) but
below the admin menu (700), so it can never cover an open flyout — and the drawer at 880,
above the menu but below Magento's modal overlay (899), so a confirm dialog is never
trapped behind it.

Earlier versions gave each tool its own toolbar group. That stopped scaling at six
groups — the bar needed a media query to fit, and every addition made the rest harder
to hit.

### Cost on a normal page load

Only the button, the tab labels and their URLs render with the page. Every tab fetches
its contents when it is selected, so no logs are read and no queries run while an
ordinary admin page is being built — including for Cache and Index, which used to build
their lists inline on every render. The one exception is the cron health indicator,
which fires a single deferred request per page (two bounded aggregate queries).

Switching tabs always refetches rather than showing a cached pane: this is diagnostic
data, and stale diagnostics are worse than slow ones. The drawer reopens on the tab you
used last.

Keyboard: `Esc` closes, `Tab` is trapped inside the open drawer, arrow keys / `Home` /
`End` move between tabs, and focus returns to the launcher on close.

### Nothing is hardcoded

The Cache and Index tabs are built from Magento's own registries — `TypeListInterface`
(merged `cache.xml`) and the indexer `ConfigInterface` (merged `indexer.xml`) — so a cache
type or indexer declared by any enabled third-party module appears automatically, with no
change to this module. (Both registries live in the `config` cache, so flush it after
enabling a new module.) The Modules tab reads `FullModuleList` the same way.

## Installation

```bash
php bin/magento module:enable Modracx_AdminDevTools
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:flush
```

## Permissions

Under **System → Permissions → User Roles → Role Resources → Modracx Admin Dev Tools**:

- `Modracx_AdminDevTools::cache_flush` — access the cache flush controller
  - `Modracx_AdminDevTools::flush_config`
  - `Modracx_AdminDevTools::flush_block`
  - `Modracx_AdminDevTools::flush_fpc`
  - `Modracx_AdminDevTools::flush_other` — every other cache type, including third-party ones
- `Modracx_AdminDevTools::reindex` — access the reindex controller and dropdown
- `Modracx_AdminDevTools::logs` — read the log tail
  - `Modracx_AdminDevTools::logs_clear` — truncate a log file
- `Modracx_AdminDevTools::reports` — read exception reports
- `Modracx_AdminDevTools::cron_health` — cron and queue status
- `Modracx_AdminDevTools::modules` — module and schema-version status
  - `Modracx_AdminDevTools::modules_toggle` — enable/disable modules
- `Modracx_AdminDevTools::config_inspect` — recent config changes and path lookup
- `Modracx_AdminDevTools::activity` — read the activity log
  - `Modracx_AdminDevTools::activity_clear` — empty it
- `Modracx_AdminDevTools::grid_bookmarks` — reset own grid bookmarks

The three core types keep dedicated resources so existing role setups stay meaningful;
any type that can't be known ahead of time falls under `flush_other`. A cache type is only
listed if its resource is granted, and `Controller/Adminhtml/Cache/Flush` re-checks the
same resource server-side (see `Model/CacheTypeAcl`, which both sides share).

The five *Additional Cache Management* actions reuse Magento's own resources rather than
defining new ones, so they honour whatever the role already allows on Cache Management:

| Action | Resource |
|--------|----------|
| Flush Magento Cache | `Magento_Backend::flush_magento_cache` |
| Flush Cache Storage | `Magento_Backend::flush_cache_storage` |
| Flush Catalog Images Cache | `Magento_Backend::flush_catalog_images` |
| Flush JavaScript/CSS Cache | `Magento_Backend::flush_js_css` |
| Flush Static Files Cache | `Magento_Backend::flush_static_files` |

## Routes

| Action | URL |
|--------|-----|
| Flush a cache type | `modracx_devtools/cache/flush?type=<cache_type_code>` |
| Run a cache action | `modracx_devtools/cache/run?action=<action_id>` |
| Run indexer | `modracx_devtools/indexer/run?indexer_id=<id>` |

`action_id` is one of `magento_cache`, `cache_storage`, `catalog_images`, `js_css`,
`static_files` (see `Model/CacheAction`).

| Panel | URL |
|--------|-----|
| Cache tab | `modracx_devtools/cache/panel` |
| Index tab | `modracx_devtools/indexer/panel` |
| Log tail | `modracx_devtools/log/view?file=system\|exception\|debug` |
| Clear log | `modracx_devtools/log/clear?file=…` |
| Cron panel / badge | `modracx_devtools/cron/status`, `modracx_devtools/cron/badge` |
| Recent config | `modracx_devtools/config/recent` |
| Config lookup | `modracx_devtools/config/lookup?path=web/secure/base_url` |
| Grid bookmarks | `modracx_devtools/bookmark/index`, `modracx_devtools/bookmark/reset?namespace=…` |
| Exception reports | `modracx_devtools/report/index`, `modracx_devtools/report/view?id=…` |
| Module status | `modracx_devtools/module/index` |
| Activity log | `modracx_devtools/activity/index`, `modracx_devtools/activity/clear` |
| Enable/disable module | `modracx_devtools/module/toggle?module=Vendor_Name&enable=0\|1` |

## Safety notes on the inspection tools

- **Logs are selected by id, never by path.** The request carries `system`, `exception` or
  `debug`; `Model/LogTail` maps that to a filename inside `var/log`. There is no parameter
  that can express a path, so directory traversal isn't possible. Reads are capped at
  256 KB from the end of the file, so a multi-gigabyte log can't exhaust memory.
- **Config values are masked** when the path matches `pass|secret|key|token|salt|private|
  credential|licence|signature|cipher` or the stored value is Magento ciphertext. Config
  holds real credentials and this panel is one click away on every admin page.
- **Bookmark reset is scoped to the signed-in user** in `Model/BookmarkTool`, not in the
  controller — there is no request parameter that can reach another user's grid state.
- **Report ids are resolved against the directory listing**, never concatenated into a
  path. `Model/ReportList` walks `var/report` (bounded to depth 4 and 2000 files) and
  matches the requested id against what it found; anything not in that listing is "not
  found". A crafted id like `../../app/etc/env.php` therefore cannot address a file.
  Report bodies over 256 KB are not rendered, and legacy PHP-serialized reports are
  unserialized with `allowed_classes: false`.
- **Enabling/disabling a module is the most consequential thing here** — it rewrites
  `app/etc/config.php`, and a wrong move can take the site down including the admin.
  `Model/ModuleToggle` therefore refuses in **production mode** (there, DI is compiled
  ahead of time and recovery is CLI-only); runs Magento's own dependency and conflict
  checks with no `--force` equivalent; protects a list of modules whose loss would lock
  you out of the admin, this module included; checks `config.php` is writable before
  touching anything; and requests regeneration of `generated/` plus a full cache clean
  afterwards. `setup:upgrade` is *not* run from the web request — the response tells you
  to run it. The button needs a second click to confirm.
- **The activity log records only what the model layer sees.** Observers are bound in the
  adminhtml, REST, SOAP and GraphQL areas only (`etc/<area>/events.xml`), so storefront
  traffic never reaches them and there is no frontend cost. Writes that bypass models —
  raw `$connection->update()`, some mass actions, indexer internals — are invisible to it,
  and the panel says so rather than implying completeness. High-churn entities (quotes,
  cron_schedule, flags, indexer state…) are skipped even in those areas.
- **Config secrets are masked by path, not by column name.** `core_config_data` stores
  everything in a column called `value`, so judging sensitivity on the field name alone
  leaks `payment/gateway/api_key` in clear text; the entity label is folded into the
  check. Both the old and new values are masked.
- **Clearing the log records that it was cleared**, by whom and how many rows went. A
  trail that can be wiped without a mark is not much of a trail. Reading and clearing are
  separate permissions.
- **The log is pruned nightly** (`modracx_prune_activity_log`, 60 days) so it cannot grow
  unbounded.
- **Cron queries are time-bounded** (24h for counts, 7d for last-success) so they use the
  `scheduled_at` index rather than scanning a table that grows to millions of rows.

Both are POST-only and require a valid `form_key`.

## Notes on the merge

- Route ids `modracx_cache` and `modracx_reindex` were replaced by the single
  `modracx_devtools` route; ACL ids moved to the `Modracx_AdminDevTools::` namespace,
  so role permissions must be re-granted after upgrading from the old modules.
- The devbar shell CSS previously lived in `Modracx_AdminDarkMode`. This module now
  ships its own (`view/adminhtml/web/css/dev-tools.css`) so it works standalone, and the
  JS builder collapses duplicate bars if AdminDarkMode is also installed. The dropdown
  markup deliberately uses its own `.modracx-dropdown-*` class names rather than
  AdminDarkMode's `.modracx-reindex-*` ones, so the two stylesheets can't fight over the
  same selectors depending on module load order.
- Colours come from `--mdx-*` custom properties defined at the top of the stylesheet.
  Every surface derives from the bar colour, so the dropdown reads as part of the same
  object; the old palette had a warm bar (`#2b2622`) against a cool dropdown (`#1e1e28`).
- Fixed: the cache buttons' JS updated a `.modracx-btn-label` element that the old
  template never rendered, so the busy/ok/error state text never appeared.
- The `type` parameter is now the real cache type code (`config`, `block_html`,
  `full_page`, …) rather than the old aliases `config` / `block` / `fpc`, because the
  controller validates against `Cache\Manager::getAvailableTypes()` instead of a
  hardcoded map.
- Dropdown markup and the AJAX request/feedback code are shared between both groups
  (`window.modracxDevTools.run()` in `devbar.phtml`) instead of being duplicated.

## Uninstalling the old modules

```bash
php bin/magento module:disable Modracx_AdminCacheButtons Modracx_QuickReindex
```
