# Kitgenix Image Optimizer

## Overview

**Plugin Name:** Kitgenix Image Optimizer
**Version:** 2.1.0 (per plugin header, `KITGENIX_IMAGE_OPTIMIZER_VERSION`, and `readme.txt`'s `Stable tag`, all in sync). Note: `languages/kitgenix-image-optimizer.pot`'s `Project-Id-Version` header still reads `2.0.0` and hasn't been regenerated for 2.1.0.
**Plugin URI:** https://wordpress.org/plugins/kitgenix-image-optimizer/
**Author:** Kitgenix (https://kitgenix.com/)
**License:** GPLv3 or later (https://www.gnu.org/licenses/gpl-3.0.html)
**Text Domain:** `kitgenix-image-optimizer`
**Domain Path:** `/languages`
**Requires at least:** WordPress 6.0
**Tested up to:** WordPress 7.1
**Requires PHP:** 8.1
**Main file:** `kitgenix-image-optimizer.php`

From the plugin header docblock (`kitgenix-image-optimizer.php`, lines 1–22):

> Optimize, compress, and resize images in your WordPress media library with automatic on-upload processing and bulk optimization tools.

The plugin performs **local** image optimization only — it does not call an external image-compression API or SaaS service for image processing. All compression, resizing, format conversion, and metadata stripping happens on the server via WordPress's own `WP_Image_Editor` abstraction (GD or Imagick, whichever WordPress selects). This is explicitly stated in `readme.txt`: *"without sending image files to a remote compression API."*

### Server requirements

- **GD or Imagick PHP extension** — at least one must be available for any optimization to occur. The plugin does not bundle its own image-processing library; it exclusively uses `wp_get_image_editor()` / `WP_Image_Editor`.
- **WebP encode support** (optional) — required only if "Convert to WebP" is enabled; gated live via `wp_image_editor_supports()` (WP 6.5+) in `includes/Core/Capabilities.php`.
- **AVIF encode support** (optional) — required only if "Convert to AVIF" is enabled; same live-capability gating.
- **No external API keys** are required or supported anywhere in the codebase — there is no HTTP client call to any third-party image-optimization endpoint.
- **WP-Cron** must be running (default WordPress behavior) for the persistent bulk-optimization queue's background continuation and for daily event-log pruning.
- The shared "Kitgenix Hub" admin page (`kitgenix-image-optimizer.php`) does make outbound HTTP requests via WordPress core's `plugins_api()` to `wordpress.org` to fetch install counts, ratings, and icons/banners for a family of sibling Kitgenix plugins — cached in transients for 24 hours. This is unrelated to image processing.
- The admin CSS (`assets/css/kitgenix-admin-ui.css`) `@import`s Google Fonts (Inter, Manrope) from `fonts.googleapis.com`, disclosed in `readme.txt` under "External Services".

## Architecture

### Folder/file layout

```
kitgenix-image-optimizer/
├── kitgenix-image-optimizer.php      Plugin bootstrap file: headers, constants, Kitgenix Hub admin page, autoloader, plugins_loaded hook
├── uninstall.php                     Standalone WP uninstall handler (options/postmeta/cron cleanup, optional backup deletion)
├── readme.txt                        WordPress.org plugin readme (description, FAQ, changelog, external services disclosure)
├── CHANGELOG.md                      Human-maintained changelog (duplicate of readme.txt's changelog, plus 2.1.0 entry)
├── languages/
│   └── kitgenix-image-optimizer.pot  Translation template only — no .po/.mo files are shipped
├── assets/
│   ├── css/
│   │   ├── kitgenix-admin-ui.css     Shared cross-plugin Kitgenix design system (tokens, cards, toggles, tables, topbar)
│   │   ├── kitgenix-hub.css          Styles for the shared "Kitgenix" hub/plugin-discovery admin page
│   │   └── admin.css                 This plugin's own admin styles layered on the shared design system
│   ├── js/
│   │   ├── kitgenix-admin-tabs.js       Shared topbar/tab/search/theme-toggle controller (identical across Kitgenix plugins)
│   │   ├── kitgenix-admin-components.js Shared toast/modal/collapsible/copy/table-search/pagination component controller
│   │   └── admin.js                     This plugin's own admin logic: bulk queue polling, KPI updates, single-image AJAX actions
│   └── images/                       Kitgenix branding: logos, social icons, WP.org banner/icon assets
└── includes/
    ├── Plugin.php                    Main bootstrap class (`KitgenixImageOptimizer\Plugin::init()`)
    ├── Core/
    │   ├── Settings.php               Option schema, defaults, sanitization, typed getters, compression-level presets
    │   ├── Optimizer.php               Core optimization engine: attachment orchestration, single-file optimization, AJAX/REST, backup/restore
    │   ├── Queue.php                   Persisted, resumable bulk-optimization queue + WP-Cron heartbeat + AJAX controls
    │   ├── Capabilities.php            Live GD/Imagick/format capability detection
    │   ├── Image_Guard.php             Pre-decode safety checks (file size, pixel count, memory estimate) against decompression bombs
    │   ├── Stats.php                   Incrementally-maintained aggregate optimization counters (avoids full-table scans)
    │   ├── Event_Log.php                Capped activity/error log with configurable retention + daily cron pruning
    │   ├── Migrations.php               One-time versioned data migrations (DB_VERSION-gated)
    │   ├── Media_Column.php            Media Library "Compression" column, filter dropdown, stats bar, inline AJAX
    │   ├── Lazy_Load.php                Frontend output-buffer based `loading="lazy"`/`loading="eager"` injection
    │   ├── Webp_Delivery.php           Frontend `<picture>` wrapping to serve WebP/AVIF sidecars
    │   ├── Site_Health.php              WordPress Site Health integration (debug info + status test)
    │   └── CLI.php                      WP-CLI commands (`wp kitgenix-optimizer ...`)
    └── Admin/
        ├── Settings_UI.php             Admin settings page: tabs (Settings/Images/Diagnostics/Support/Log), all form rendering
        └── Attachment_Panel.php        Meta box on the attachment edit screen (post.php) with Optimize/Restore controls
```

### Bootstrap sequence

1. **`kitgenix-image-optimizer.php`** loads first (WordPress includes all active plugin main files). It:
   - Declares `strict_types=1`.
   - Registers `kitgenix_image_optimizer_register_admin_ui_style()` on `admin_enqueue_scripts` (priority 5) to register (not enqueue) the shared admin UI stylesheet early.
   - Defines the constants `KITGENIX_IMAGE_OPTIMIZER_VERSION` (`2.1.0`), `KITGENIX_IMAGE_OPTIMIZER_FILE`, `KITGENIX_IMAGE_OPTIMIZER_DIR`, `KITGENIX_IMAGE_OPTIMIZER_URL`.
   - Registers the shared top-level `Kitgenix` admin menu (slug `kitgenix`) via `kitgenix_image_optimizer_ensure_admin_menu()` on `admin_menu` (priority 5) — designed to be idempotent across multiple co-installed Kitgenix plugins (checks `$admin_page_hooks['kitgenix']` before adding).
   - Defines `kitgenix_render_admin_page()` — renders the "Kitgenix Hub" grid of sibling plugins (Image Optimizer, Turnstile CAPTCHA, Custom Tabs for WooCommerce, Document Manager, Order Tracking, PDF Invoicing, Stock Sync, MultiStore Sync, Affiliate Link Manager), showing install/activate/open actions per plugin using `get_plugins()`/`is_plugin_active()` and WP.org metadata (install counts, ratings, icons/banners) fetched via `plugins_api()` and cached in transients.
   - Registers activation/deactivation hooks: on activation, sets a 30-second transient `kitgenix_image_optimizer_do_activation_redirect`; on `admin_init`, if that transient is present (and not a bulk/network activation), redirects to the plugin's own settings page; deactivation clears the transient.
   - Adds a "Settings" link to the plugin's row in Plugins → Installed Plugins via `plugin_action_links_{basename}`.
   - Registers a simple **PSR-4-style autoloader** via `spl_autoload_register()`: any class under the `KitgenixImageOptimizer\` namespace maps to `includes/<relative-path>.php` (e.g. `KitgenixImageOptimizer\Core\Optimizer` → `includes/Core/Optimizer.php`).
   - Hooks `Plugin::init()` onto `plugins_loaded`.
2. **`includes/Plugin.php`** (`KitgenixImageOptimizer\Plugin::init()`) is the real bootstrap. In order:
   1. `Settings::ensure_defaults()` — seeds the settings option if missing, merges in any newly-added default keys otherwise.
   2. `add_action('admin_init', [Settings::class, 'register_settings'])` — registers the Settings API group.
   3. `Migrations::init()` — hooks the one-time DB-version-gated migration check.
   4. `Event_Log::init()` — hooks daily log-retention pruning cron.
   5. If `is_admin()`: `Settings_UI::init()`, `Media_Column::init()`, `Attachment_Panel::init()`.
   6. `Optimizer::init()` — registers the core optimization hooks and also boots `Queue::init()`.
   7. `Lazy_Load::init()` — frontend lazy-loading (only if the setting is enabled).
   8. `Webp_Delivery::init()` — frontend `<picture>` wrapping (only if WebP or AVIF conversion is enabled).
   9. `Site_Health::init()` — Site Health integration.
   10. If running under WP-CLI (`defined('WP_CLI') && WP_CLI`): `CLI::register()`.

### Namespace / autoloading

All PHP classes live under the `KitgenixImageOptimizer\` namespace (`KitgenixImageOptimizer\Core\*`, `KitgenixImageOptimizer\Admin\*`, and the root `KitgenixImageOptimizer\Plugin`). There is **no Composer autoloader** — the plugin ships its own minimal `spl_autoload_register()` callback in the main plugin file that converts the namespace to a file path under `includes/`. Every class file also begins with `declare(strict_types=1);` and `defined('ABSPATH') || exit;`.

### Key classes and responsibilities

| Class | File | Responsibility |
|---|---|---|
| `Plugin` | `includes/Plugin.php` | Bootstraps all subsystems on `plugins_loaded`. |
| `Settings` | `includes/Core/Settings.php` | Single source of truth for the option schema, defaults, sanitization callback, and typed static getters (`is_enabled()`, `max_width()`, `effective_quality_jpeg()`, etc.). |
| `Optimizer` | `includes/Core/Optimizer.php` | The optimization engine: hooks into `wp_generate_attachment_metadata`, orchestrates per-attachment optimization (main file + sub-sizes), single-file optimization via `WP_Image_Editor`, sidecar (WebP/AVIF) generation, backup/restore, AJAX handlers, REST routes, and MIME/exclusion logic. |
| `Queue` | `includes/Core/Queue.php` | A persisted, resumable state machine (idle/running/paused/completed) driving bulk optimization one attachment at a time, advanced by both AJAX polling and a WP-Cron heartbeat. |
| `Capabilities` | `includes/Core/Capabilities.php` | Live-probes GD/Imagick availability, per-format encode/decode support, preferred editor, memory limits — never assumes support. |
| `Image_Guard` | `includes/Core/Image_Guard.php` | Pre-decode safety gate: file-size ceiling, pixel-count ceiling, per-dimension ceiling, and an estimated-memory-vs-`memory_limit` check, to reject decompression-bomb-style files before GD/Imagick ever touches them. |
| `Stats` | `includes/Core/Stats.php` | Incrementally maintained aggregate counters (`optimized`, `failed`, `original_bytes`, `optimized_bytes`) stored in one option, avoiding a live DB scan on every page load; `recalculate()` does the expensive full scan, only on demand. |
| `Event_Log` | `includes/Core/Event_Log.php` | Capped (100-entry), retention-pruned (7/14/30 days) activity log stored in an option; maps internal error/skip codes to human-readable categories for the admin Log tab. |
| `Migrations` | `includes/Core/Migrations.php` | Runs once per DB-version bump; currently schedules a background `Stats::recalculate()` backfill when upgrading from a version with no Stats aggregate. |
| `Media_Column` | `includes/Core/Media_Column.php` | Adds the "Compression" column and filter dropdown to the Media Library grid/list, plus a stats summary bar above the library. |
| `Lazy_Load` | `includes/Core/Lazy_Load.php` | Output-buffers the entire frontend page and injects `loading="lazy"`/`loading="eager"` onto `<img>` tags that lack one. |
| `Webp_Delivery` | `includes/Core/Webp_Delivery.php` | Wraps `<img>` tags (in post content, featured images, `wp_get_attachment_image()`) in `<picture>` with AVIF/WebP `<source>` elements when sidecar files exist. |
| `Site_Health` | `includes/Core/Site_Health.php` | Surfaces the capability report in Tools → Site Health (`debug_information`) and adds a status test that warns on capability/setting mismatches. |
| `CLI` | `includes/Core/CLI.php` | `wp kitgenix-optimizer` command family: `status`, `optimize`, `queue`, `stats`, `restore`. |
| `Settings_UI` | `includes/Admin/Settings_UI.php` | Renders the entire admin settings page (five tabs), registers the submenu page, enqueues admin assets, handles two `admin-post` actions (clear log, save log retention). |
| `Attachment_Panel` | `includes/Admin/Attachment_Panel.php` | Adds an "Image Optimizer" meta box to the attachment edit screen with per-image status/stats/actions. |

## Features

- **Automatic optimization on upload** — via the `wp_generate_attachment_metadata` filter, after WordPress has finished generating all registered sub-sizes (toggle: "Optimize on Upload").
- **Manual/bulk optimization** — a persisted, resumable queue (`Queue` class) processes the whole library or specific failed items, continuing in the background via WP-Cron even if the browser tab is closed.
- **Force re-optimization mode** — "Bulk Re-Optimize" reprocesses every eligible image regardless of whether it was already optimized, ignoring the fingerprint short-circuit.
- **Format conversion (WebP/AVIF sidecars)** — for JPEG/PNG source images only, optional additional `.webp`/`.avif` files are generated alongside the original (e.g. `photo.jpg.webp`), never replacing the original file, gated on live server capability.
- **Compression presets and custom quality** — five compression levels (`lossless`/Conservative, `normal`/Balanced, `aggressive`, `ultra`/Maximum, `custom`), each mapping to fixed JPEG/WebP/AVIF quality numbers except `custom`, which uses the manually-entered `quality_jpeg`/`quality_webp`/`quality_avif` fields. PNG always uses its own dedicated 0–9 "PNG Compression" setting (translated to a 0–100 quality value), independent of the compression-level preset.
- **Image resizing / max dimensions** — configurable `max_width`/`max_height` (default 2560×2560, 0 = disabled), which is applied two ways: (1) it is fed into WordPress's own `big_image_size_threshold` filter so core performs the resize during normal upload processing (avoiding a duplicate resize pass), and (2) `Optimizer::optimize_file()` still contains its own resize-fallback logic for files that reach it outside the normal upload flow (CLI runs, sideloads, legacy attachments).
- **EXIF/metadata stripping** — optional; calls `WP_Image_Editor::strip_meta()` when supported (works with both GD and Imagick editors; the changelog notes a prior version had this broken specifically for Imagick).
- **Thumbnail/sub-size optimization** — optional; when enabled, every registered sub-size in `_wp_attachment_metadata['sizes']` is optimized in addition to the main file.
- **Original backup & restore** — optional "Backup Originals" setting copies the pre-optimization file to `<filename>-kitgenix-original.<ext>` before the first optimization pass; a per-image "Restore Original" action (AJAX + WP-CLI) copies it back over the current file and regenerates attachment metadata/sub-sizes.
- **Per-image statistics** — original size, optimized size, bytes saved, percent saved, per-file breakdown (main file + each sub-size), timestamp — stored in postmeta.
- **Animated GIF protection** — animated GIFs are detected via a raw byte scan for multiple Graphic Control Extension blocks and skipped by default (toggle: "Skip Animated GIFs").
- **"Never make it bigger" guarantee** — if the optimized output is not strictly smaller than the original, the original is kept unchanged (`kept_original` flag in the result).
- **Atomic file writes** — every write goes to a temp file first (`<file>.kitgenix-tmp-<uniqid>.<ext>`), then is renamed/moved into place via `WP_Filesystem::move()`, so an interrupted process never leaves a corrupt/partial file.
- **Pre-decode safety checks** — file-size ceiling (200 MB), pixel-count ceiling (60 megapixels, filterable), per-dimension ceiling (40,000px, filterable), and an estimated-memory-vs-`memory_limit` check, all evaluated *before* any decode is attempted.
- **Exclusion rules** — by MIME type, minimum/maximum file size (KB), filename substrings/glob patterns, minimum/maximum pixel dimensions, specific attachment IDs, and uploads-relative directory path fragments.
- **Media Library UI additions** — a "Compression" column with per-image Optimize/Re-optimize/Restore links and inline AJAX, a filter dropdown (All/Optimized/Not optimized/Errors), and a stats summary bar above the library grid.
- **Attachment edit-screen meta box** — status badge, size/savings table, and Optimize/Re-optimize/Restore buttons.
- **Diagnostics tab** — live GD/Imagick availability & versions, active editor, per-format encode/decode/WP-support matrix, PHP memory/upload limits.
- **WordPress Site Health integration** — same capability data surfaced under Tools → Site Health, plus an automated status test.
- **Activity/error log** — capped, searchable, paginated admin log with a reference table explaining each event category and whether action is needed; configurable auto-delete (7/14/30 days).
- **REST API status/capability endpoints** and a **`kitgenix_optimizer` field** on the core `wp/v2/media` REST endpoint.
- **WP-CLI command suite** for status, optimize, queue control, stats, and restore.
- **Optional frontend lazy loading** — adds native `loading="lazy"`/`loading="eager"` attributes to `<img>` tags site-wide (not just core-rendered images) via output buffering.
- **Frontend WebP/AVIF delivery** — wraps eligible `<img>` tags in `<picture>` elements with AVIF/WebP `<source>`s when sidecar files exist.
- **Configurable uninstall behavior** — "Keep Settings & Data" and "Delete Backup Files on Uninstall" toggles.

## Optimization Engine

### Mechanism

The plugin does **not** bundle a compression library (no cwebp/mozjpeg/pngquant binaries, no `exec()` calls) and does **not** call any external HTTP API for image processing. All compression is delegated to WordPress core's `WP_Image_Editor` abstraction via `wp_get_image_editor( $file )`, which internally selects either the **Imagick** or **GD** PHP extension (WordPress's own `wp_image_editors` filter order, normally Imagick first). `includes/Core/Optimizer.php` (method `optimize_file()`) is the single-file optimization routine; the flow is:

1. **Readability check** — `is_readable()`/`is_writable()` on the file.
2. **Animated GIF check** — if MIME is `image/gif`, `skip_animated_gif` is on, and `is_animated_gif()` (raw GIF89a/87a header + multi-Graphic-Control-Extension-block scan) detects animation, the file is skipped (`animated_gif_skipped`).
3. **Dimension probe** — `Image_Guard::probe()` reads dimensions via `getimagesize()` (header-only, no decode).
4. **Exclusion check** — `Optimizer::is_excluded_file()` against all configured exclusion rules (MIME, size range, filename pattern, min/max dimensions, directory).
5. **Safety validation** — `Image_Guard::validate()`: file-size ceiling, dimension ceiling, pixel-count ceiling, estimated-decode-memory-vs-available-`memory_limit` check (bytes ≈ `width * height * 4 * 2.5`, requiring the estimate to fit within 60% of available headroom). Any failure returns a `WP_Error` before decoding.
6. **Backup** — if `backup_originals` is on, and no backup file already exists, the pre-optimization bytes are copied (`@copy()`) to `<file>-kitgenix-original.<ext>`.
7. **Editor init** — `wp_get_image_editor( $file )`.
8. **Resize fallback** — if `max_width`/`max_height` > 0 and the image exceeds them, `$editor->resize()` is called (aspect ratio preserved, `crop = false`). In the normal upload flow this rarely fires because `align_big_image_size_threshold()` already made WordPress core do the resize during `wp_generate_attachment_metadata`; this path exists for files that reach the optimizer outside that flow (CLI, sideloads, legacy attachments).
9. **Metadata strip** — if `strip_metadata` is on and the editor supports `strip_meta()`, it is called (non-fatally wrapped in try/catch).
10. **Quality** — `$editor->set_quality( <value> )`, where the value is resolved by `Optimizer::quality_for_mime()`:
    - `image/webp` → `Settings::effective_quality_webp()`
    - `image/avif` → `Settings::effective_quality_avif()`
    - `image/png` → `png_quality_from_compression_level()` = `min(100, max(0, level*10 + 5))` (ImageMagick's documented PNG quality convention, filter type fixed at 5/adaptive)
    - everything else (JPEG etc.) → `Settings::effective_quality_jpeg()`
11. **Atomic save** — `$editor->save( $tmp_file, $mime_type )` to a temp file in the same directory; on success, if the result is not smaller than the original, the temp file is discarded and the original is kept (`kept_original = true`); otherwise `WP_Filesystem::move()` (rename, falling back to copy+delete) atomically replaces the original.
12. **Sidecar generation** — `maybe_generate_sidecars()`: for JPEG/PNG sources only, if `convert_to_webp`/`convert_to_avif` is on **and** `Capabilities::supports_webp()`/`supports_avif()` returns true, a fresh editor instance encodes a `<file>.webp`/`<file>.avif` sidecar (same atomic-temp-then-replace pattern). Failure here is logged but non-fatal to the main optimization result.

### Attachment-level orchestration (`optimize_attachment()`)

Given an attachment ID:
- Resolves the attached file and MIME type; bails with `WP_Error` if missing/unsupported/excluded.
- Optimizes the main file via `optimize_one_file()`, which short-circuits (returns the previous result unchanged) if a cheap **fingerprint** (`filesize:mtime` string) matches the last recorded run **and** `$force` is false — this is what prevents thumbnail-regeneration events from repeatedly recompressing unchanged files.
- If `optimize_thumbnails` is on, iterates every entry in `wp_get_attachment_metadata()['sizes']` and optimizes each sub-size file the same way (a single sub-size failure does not fail the whole attachment).
- Carries forward any previously-optimized files not touched this run (e.g. thumbnails toggled off after an earlier run) provided the file still exists on disk, so aggregate totals don't inflate forever for deleted sub-sizes.
- Aggregates `original_size`/`optimized_size`/`savings_bytes`/`savings_percent` across all processed files and writes them to postmeta (`_kitgenix_image_optimizer`), and updates the `Stats` aggregate.
- Logs the outcome to `Event_Log`.

### Error handling / fallback

- Every failure path returns a typed `WP_Error` with a stable code (`not_found`, `not_optimizable`, `excluded_by_rule`, `animated_gif_skipped`, `not_writable`, `filesize_read_failed`, `editor_init_failed`, `resize_failed`, `save_failed`, `invalid_dimensions`, `dimension_too_large`, `decompression_bomb_suspected`, `insufficient_memory`, `unreadable_header`, `empty_file`, `file_too_large`).
- Genuine failures (anything except `animated_gif_skipped`/`excluded_by_rule`) are recorded via `record_failure()`, which writes an `error` status to postmeta and decrements/increments the `Stats` aggregate.
- Purely-by-design skips (animated GIF, exclusion rule) are logged as `skipped`, not `error`, and do not count against the failure stats.
- A failed sub-size does not abort the whole attachment; only if *nothing* (main file and all sub-sizes) could be processed does the attachment as a whole fail.
- `Image_Guard` failures happen *before* any GD/Imagick decode call, specifically to avoid PHP hard-fatals from decompression-bomb-style malformed images (GD in particular has no catchable failure mode for an oversized `imagecreatefromjpeg()`).

## Admin Interface

### Menu structure

- **Kitgenix** (top-level menu, slug `kitgenix`, shared across all co-installed Kitgenix plugins, capability `manage_woocommerce` if WooCommerce is active else `manage_options`) — renders the plugin-discovery "Hub" grid.
  - **Image Optimizer** (submenu, slug `kitgenix-image-optimizer`, capability `manage_options`) — registered by `Settings_UI::register_menu()`.

The Image Optimizer page (`Settings_UI::render_page()`) uses a single-page, JS-driven tab layout (`data-kitgenix-tabs`) with five panels, selected via `?tab=` query arg or URL hash: **Settings**, **Images**, **Diagnostics**, **Log**, **Support**.

### Settings tab — cards and every option

All settings are stored in a **single option**, `kitgenix_image_optimizer_settings` (`Settings::OPTION_NAME`), registered via `register_setting()` under group `kitgenix_image_optimizer_settings_group`, sanitized by `Settings::sanitize()`.

| Card | Field label | Option key | Type | Default | Notes |
|---|---|---|---|---|---|
| General | Enable Image Optimizer | `enabled` | bool | `1` | Master switch; if off, `Optimizer::init()` registers nothing. |
| General | Optimize on Upload | `auto_optimize_on_upload` | bool | `1` | Controls whether `wp_generate_attachment_metadata` hook is registered. |
| Quality | Compression Level | `compression_level` | enum | `normal` | One of `lossless`, `normal`, `aggressive`, `ultra`, `custom`. Overrides manual quality fields unless `custom`. |
| Quality | JPEG Quality | `quality_jpeg` | int 1–100 | `82` | Used only when `compression_level = custom`. |
| Quality | WebP Quality | `quality_webp` | int 1–100 | `82` | Used only when `compression_level = custom`. |
| Quality | AVIF Quality | `quality_avif` | int 1–100 | `60` | Used only when `compression_level = custom`; field only rendered if AVIF is supported. |
| Quality | PNG Compression | `quality_png_compression` | int 0–9 | `6` | Always used for PNG regardless of compression-level preset. |
| Resizing | Max Width (px) | `max_width` | int ≥0 | `2560` | `0` disables. Also drives `big_image_size_threshold`. |
| Resizing | Max Height (px) | `max_height` | int ≥0 | `2560` | `0` disables. |
| Advanced | Convert to WebP | `convert_to_webp` | bool | `0` | Disabled in UI (and force-cleared to 0 on save) if server can't encode WebP. |
| Advanced | Convert to AVIF | `convert_to_avif` | bool | `0` | Disabled in UI (and force-cleared to 0 on save) if server can't encode AVIF. |
| Advanced | Strip Image Metadata | `strip_metadata` | bool | `1` | Calls `WP_Image_Editor::strip_meta()`. |
| Advanced | Optimize Thumbnails | `optimize_thumbnails` | bool | `1` | Whether registered sub-sizes are also compressed. |
| Advanced | Backup Originals | `backup_originals` | bool | `0` | Enables the `-kitgenix-original` backup-before-optimize behavior. |
| Advanced | Skip Animated GIFs | `skip_animated_gif` | bool | `1` | |
| Advanced | Lazy Load Images | `lazy_load` | bool | `0` | Controls `Lazy_Load::init()`. |
| Exclusions (collapsible) | Skip files smaller than (KB) | `exclude_below_kb` | int ≥0 | `0` | `0` disables. |
| Exclusions | Skip files larger than (KB) | `exclude_above_kb` | int ≥0 | `0` | `0` disables. |
| Exclusions | Skip by File Type | `excluded_mime_types` | array of strings | `[]` | Subset of `Settings::OPTIMIZABLE_MIMES`. |
| Exclusions | Skip images smaller than (px) | `excluded_min_width` / `excluded_min_height` | int ≥0 | `0` / `0` | |
| Exclusions | Skip images larger than (px) | `excluded_max_width` / `excluded_max_height` | int ≥0 | `0` / `0` | |
| Exclusions | Skip by Filename Pattern | `excluded_filename_patterns` | string (comma list) | `''` | Substring or glob (`fnmatch()`) match against basename. |
| Exclusions | Skip by Directory | `excluded_directories` | string (comma list) | `''` | Substring match against the uploads-relative path. |
| Exclusions | Skip Specific Attachments | `excluded_attachment_ids` | string (comma list of ints) | `''` | Normalized to a clean comma list of positive integers. |
| Uninstall | Keep Settings & Data | `keep_data_on_uninstall` | bool | `0` | If on, `uninstall.php` returns immediately without deleting anything. |
| Uninstall | Delete Backup Files on Uninstall | `delete_backups_on_uninstall` | bool | `0` | Only relevant if the above is off; deletes `-kitgenix-original` and `.webp`/`.avif` sidecar files. |

`Settings::OPTIMIZABLE_MIMES` = `['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif']`.

Compression-level → effective quality mapping (`Settings::effective_quality_*()`):

| Level | JPEG | WebP | AVIF |
|---|---|---|---|
| `lossless` (Conservative) | 96 | 96 | 90 |
| `normal` (Balanced, default) | 82 | 80 | 60 |
| `aggressive` | 78 | 76 | 55 |
| `ultra` (Maximum) | 65 | 63 | 42 |
| `custom` | `quality_jpeg` | `quality_webp` | `quality_avif` |

### Images tab

- Four KPI stat tiles: Total Images, Optimized (with % complete), Pending, Space Saved.
- "Recalculate stats from scratch" link → AJAX call to `kitgenix_image_optimizer_recalculate_stats`.
- Bulk Optimization card: progress bar, "Start Bulk Optimization" / "Bulk Re-Optimize" / "Retry N Failed" buttons, live status text.
- "Recent Images" table (last 30 image attachments by date) with per-row thumbnail, filename/MIME, original size, savings chip, status badge, and Optimize/Re-optimize/Restore action buttons (AJAX, via `admin.js`).

### Diagnostics tab

- Stat tiles: GD Extension (available + version), Imagick Extension (available + version), Active Editor, PHP `memory_limit` (+ max pixels decoded).
- Table: per-format (JPEG/PNG/GIF/WebP/AVIF) Decode/Encode/"WordPress Will Use It" (`wp_supported`) columns.
- Upload Limits table: `upload_max_filesize`, `post_max_size`, `max_execution_time`.

### Log tab

- Searchable, paginated (25/page) activity log table: Time, Event, Outcome (badge), Details.
- "What each event means" reference table mapping internal codes to plain-English descriptions and "action needed?" guidance. This table is a separately hand-maintained array in `Settings_UI::render_log_reference_table()`, not generated from `Event_Log::get_category_and_note()` — the two diverge: the reference table lists `avif-not-generated` and `image-safety-rejected` categories that `get_category_and_note()`'s map never produces, while codes that are actually logged (every `Image_Guard` rejection code, and `avif_sidecar_failed`) are absent from `get_category_and_note()`'s map and fall through to a generic "No further detail available" note instead of the specific row the reference table promises.
- "Auto-delete logs after" select (7/14/30 days) submitted via `admin-post.php?action=kitgenix_image_optimizer_save_log_retention`.
- "Clear Log" link (with confirm dialog) via `admin-post.php?action=kitgenix_image_optimizer_clear_event_log`.

### Support tab

Static content: PayPal donate link, review link, documentation link, "copy plugin link" button. No settings.

### Sidebar (all tabs)

"Need Help?" (docs/support links), "Visit Our Official Facebook Group", "Follow Us" (social icon grid).

### Media Library UI additions

- **"Compression" column** (`manage_media_columns` / `manage_media_custom_column`) showing either an "Optimize" link (unoptimized), savings percent + size (optimized), or an error badge + "Re-optimize" link, plus a "Restore original" link when a backup file exists.
- **Filter dropdown** (`restrict_manage_posts` on `upload.php`) — All / Optimized / Not optimized / Errors — implemented via `pre_get_posts` meta-query manipulation (and a PHP-level `the_posts` filter for the "Errors" case, since status lives inside serialized meta).
- **Media Library stats bar** (`admin_notices` on the `upload` screen) — total/optimized/pending counts, space saved, and a "Bulk Optimize →" / "Re-Optimize All →" CTA linking to the Images tab.

### Attachment edit-screen meta box

Registered on the `attachment` post type, side context, titled "Image Optimizer" (`Attachment_Panel::render_meta_box()`). Shows a status badge (Not Optimized / Optimized / Error), a stats table (Original/Optimized/Saved/Date) when optimized, and Optimize/Re-optimize/Try Again + Restore Original buttons, wired to the same AJAX actions via inline JS.

## Hooks Reference

### WordPress hooks the plugin registers INTO (consumes)

| Hook | Type | Class::method | Purpose |
|---|---|---|---|
| `admin_enqueue_scripts` (priority 5) | action | `kitgenix_image_optimizer_register_admin_ui_style()` | Register shared admin UI stylesheet early. |
| `admin_menu` (priority 5) | action | `kitgenix_image_optimizer_ensure_admin_menu()` | Register the shared top-level "Kitgenix" menu. |
| `admin_enqueue_scripts` (priority 20) | action | `kitgenix_image_optimizer_enqueue_hub_styles()` | Enqueue Hub-page styles + thickbox. |
| `admin_init` | action | anonymous (activation redirect) | One-time redirect to settings page after activation. |
| `plugin_action_links_{basename}` | filter | anonymous | Add "Settings" row-action link. |
| `plugins_loaded` | action | `Plugin::init()` | Bootstrap. |
| `admin_init` | action | `Settings::register_settings` | Register Settings API option. |
| `admin_init` (priority 1) | action | `Migrations::maybe_run` | Run pending migrations. |
| `kitgenix_image_optimizer_backfill_stats` (custom cron hook) | action | `Stats::recalculate` | Background stats backfill after upgrade. |
| `kitgenix_image_optimizer_prune_event_log` (custom cron hook) | action | `Event_Log::prune` | Daily log pruning. |
| `admin_init` | action | `Event_Log::maybe_schedule_prune_cron` | Ensure the daily prune cron is scheduled. |
| `big_image_size_threshold` (priority 20) | filter | `Optimizer::align_big_image_size_threshold` | Align core's own upload-time scaling with configured max dimensions. |
| `wp_generate_attachment_metadata` (priority 999) | filter | `Optimizer::handle_generated_metadata` | Trigger automatic optimization after sub-sizes exist (only if `auto_optimize_on_upload`). |
| `wp_ajax_kitgenix_image_optimizer_optimize_single` | action | `Optimizer::ajax_optimize_single` | AJAX: optimize one attachment. |
| `wp_ajax_kitgenix_image_optimizer_restore_backup` | action | `Optimizer::ajax_restore_backup` | AJAX: restore from backup. |
| `wp_ajax_kitgenix_image_optimizer_recalculate_stats` | action | `Optimizer::ajax_recalculate_stats` | AJAX: force a Stats full recount. |
| `rest_api_init` | action | `Optimizer::register_rest_routes` | Register REST routes + `kitgenix_optimizer` field on `wp/v2/media`. |
| `cron_schedules` | filter | `Queue::register_cron_schedule` | Register the `kitgenix_one_minute` interval. |
| `kitgenix_image_optimizer_queue_tick` (custom cron hook) | action | `Queue::run_cron_batch` | Background queue advancement, ≤20s per tick. |
| `wp_ajax_kitgenix_image_optimizer_queue_start/next/pause/resume/stop/retry/status` | action | `Queue::ajax_*` | Bulk queue AJAX controls. |
| `manage_media_columns` | filter | `Media_Column::add_column` | Add "Compression" column. |
| `manage_media_custom_column` | action | `Media_Column::render_column` | Render column content. |
| `restrict_manage_posts` | action | `Media_Column::add_filter_dropdown` | Add optimization-status filter dropdown. |
| `pre_get_posts` | action | `Media_Column::apply_filter` | Apply the filter to the Media Library query. |
| `the_posts` | filter | `Media_Column::php_filter_errors` | Secondary PHP-level filter for the "Errors" view. |
| `admin_enqueue_scripts` | action | `Media_Column::enqueue_scripts` | Inline CSS/JS for the column on `upload.php`. |
| `admin_notices` | action | `Media_Column::render_media_library_bar` | Stats bar above the library. |
| `template_redirect` | action | `Lazy_Load::start_buffer` | Start output buffering (frontend only, when `lazy_load` is enabled). |
| `shutdown` (priority 0) | action | `Lazy_Load::close_buffer` | Flush the output buffer. |
| `the_content` / `post_thumbnail_html` (priority 99) | filter | `Webp_Delivery::wrap_content_images` | Wrap `<img>` tags in `<picture>`. |
| `wp_get_attachment_image` (priority 99) | filter | `Webp_Delivery::wrap_single_image` | Wrap single attachment-image output. |
| `debug_information` | filter | `Site_Health::add_debug_information` | Site Health debug tab data. |
| `site_status_tests` | filter | `Site_Health::add_status_tests` | Site Health automated status test. |
| `admin_menu` (priority 50) | action | `Settings_UI::register_menu` | Register the "Image Optimizer" submenu page. |
| `admin_enqueue_scripts` (priority 20) | action | `Settings_UI::enqueue_assets` | Enqueue plugin admin CSS/JS on the settings page only. |
| `admin_notices` | action | `Settings_UI::render_admin_notices` | Render `settings_errors()` on the settings screen. |
| `admin_post_kitgenix_image_optimizer_clear_event_log` | action | `Settings_UI::handle_clear_event_log` | Clear-log form handler. |
| `admin_post_kitgenix_image_optimizer_save_log_retention` | action | `Settings_UI::handle_save_log_retention` | Retention-select form handler. |
| `update_option_kitgenix_image_optimizer_settings` | action | `Settings_UI::on_settings_saved` | Log a "settings-saved" event. |
| `add_meta_boxes` | action | `Attachment_Panel::register_meta_box` | Register the attachment edit-screen meta box. |
| `admin_enqueue_scripts` | action | `Attachment_Panel::enqueue_scripts` | Inline JS/CSS for the meta box (post.php/post-new.php, attachment post type only). |
| `wp_image_editors` (read, not modified) | filter | `Capabilities::preferred_editor_label` | Reads core's own editor-preference filter for display purposes only. |

### Custom hooks the plugin fires (for other code to use)

| Hook | Type | Default | Purpose |
|---|---|---|---|
| `kitgenix_image_optimizer_max_pixels` | filter | `60_000_000` | Override the maximum total-pixel decode ceiling used by `Image_Guard`. |
| `kitgenix_image_optimizer_max_dimension` | filter | `40_000` | Override the maximum single-dimension (width or height) ceiling used by `Image_Guard`. |

No other custom action/filter hooks are fired for external consumption — the plugin does not expose a broad "before/after optimize" action hook pair beyond these two Image_Guard filters.

## Data Storage

### Custom database tables

**None.** The plugin creates no custom `$wpdb` tables; all persistent state lives in the WordPress Options API and post meta.

### Options (all `autoload = false` unless noted)

| Option name | Owner | Shape | Purpose |
|---|---|---|---|
| `kitgenix_image_optimizer_settings` | `Settings::OPTION_NAME` | assoc array | All plugin settings (see Admin Interface table above). |
| `kitgenix_image_optimizer_db_version` | `Migrations::OPTION_KEY` | int | Current schema/data version (`Settings::DB_VERSION = 2`), gates one-time migrations. |
| `kitgenix_image_optimizer_stats` | `Stats::OPTION_KEY` | `{optimized:int, failed:int, original_bytes:int, optimized_bytes:int, recalculated_at:int}` | Incrementally-maintained aggregate optimization counters. |
| `kitgenix_image_optimizer_event_log` | `Event_Log::OPTION_KEY` | array of `{time, context, outcome, note, code}`, capped at 100 entries | Activity/error log shown on the Log tab. |
| `kitgenix_image_optimizer_log_retention_days` | `Event_Log::RETENTION_OPTION_KEY` | int (7\|14\|30) | Log auto-delete window. |
| `kitgenix_image_optimizer_queue` | `Queue::OPTION_KEY` | `{status, mode, cursor, total_snapshot, processed, failed[], retry_ids[], started_at, updated_at, completed_at}` | Persisted bulk-queue state machine. |
| `kitgenix_hub_wporg_active_installs_v1` | transient (Hub) | map slug→int | 24h cache of WP.org active-install counts for the Kitgenix plugin family. |
| `kitgenix_hub_wporg_ratings_v1` | transient (Hub) | map slug→int | 24h cache of WP.org rating percentages. |
| `kitgenix_hub_wporg_media_v1` | transient (Hub) | map slug→`{url,type}` | 24h cache of WP.org banner/icon URLs. |
| `kitgenix_image_optimizer_do_activation_redirect` | transient | `1`, 30s TTL | One-shot post-activation redirect flag. |
| `kitgenix_image_optimizer_queue_lock` | transient (`Queue::LOCK_KEY`) | timestamp, 30s TTL | Best-effort lock preventing AJAX + cron from processing the same queue item simultaneously. |

### Postmeta

| Meta key | Constant | Owner | Shape | Purpose |
|---|---|---|---|---|
| `_kitgenix_image_optimizer` | `Optimizer::META_KEY` | `Optimizer` | On success: `{status:'optimized', optimized_at:int, original_size:int, optimized_size:int, savings_bytes:int, savings_percent:float, files:{<filename>:{fingerprint, original_size, optimized_size, kept_original, optimized, optimized_at}}}`. On failure: `{status:'error', error_message:string, error_at:int, files:[]}`. | Per-attachment optimization status/stats, keyed per sub-file. Read by the Media Library column, attachment meta box, REST `kitgenix_optimizer` field, and Media Library filter. Deleted on restore. |

### Usermeta

None used by this plugin.

## AJAX / REST Endpoints

### AJAX (all via `admin-ajax.php`, nonce action `kitgenix_image_optimizer_nonce`, `POST`)

| Action | Handler | Capability check | Parameters | Response (`wp_send_json_success`/`error` data) |
|---|---|---|---|---|
| `kitgenix_image_optimizer_optimize_single` | `Optimizer::ajax_optimize_single` | `upload_files` + `edit_post` on the target attachment | `nonce`, `attachment_id` | success: `{message, savings_bytes, savings_percent, savings_formatted, original_size_formatted, optimized_size_formatted}`; error: `{message}` |
| `kitgenix_image_optimizer_restore_backup` | `Optimizer::ajax_restore_backup` | `upload_files` + `edit_post` on the target attachment | `nonce`, `attachment_id` | success: `{message}`; error: `{message, code}` |
| `kitgenix_image_optimizer_recalculate_stats` | `Optimizer::ajax_recalculate_stats` | `manage_options` | `nonce` | success: `{optimized, failed, savings_bytes, savings_formatted}` |
| `kitgenix_image_optimizer_queue_start` | `Queue::ajax_start` | `upload_files` | `nonce`, `mode` (`bulk`\|`reoptimize`) | queue state object |
| `kitgenix_image_optimizer_queue_next` | `Queue::ajax_next` | `upload_files` | `nonce` | result of one `advance()` call merged with `progress()` |
| `kitgenix_image_optimizer_queue_pause` | `Queue::ajax_pause` | `upload_files` | `nonce` | queue state object |
| `kitgenix_image_optimizer_queue_resume` | `Queue::ajax_resume` | `upload_files` | `nonce` | queue state object |
| `kitgenix_image_optimizer_queue_stop` | `Queue::ajax_stop` | `upload_files` | `nonce` | queue state object |
| `kitgenix_image_optimizer_queue_retry` | `Queue::ajax_retry` | `upload_files` | `nonce` | queue state object |
| `kitgenix_image_optimizer_queue_status` | `Queue::ajax_status` | `upload_files` | `nonce` | `progress()` object |

`progress()` shape: `{status, mode, total, processed, done_estimate, percent, failed_count, failed[] (last 20), retry_pending, savings_bytes, started_at, updated_at}`.

All AJAX handlers begin with `check_ajax_referer( 'kitgenix_image_optimizer_nonce', 'nonce' )` (dies on failure), and `attachment_id`-specific handlers additionally check `current_user_can( 'edit_post', $attachment_id )` (see `Optimizer::current_user_can_manage_attachment()`), preventing an Author-level user with generic `upload_files` from acting on other users' attachments by guessing IDs.

### REST API

Namespace: `kitgenix-image-optimizer/v1`

| Route | Method | Permission callback | Response |
|---|---|---|---|
| `/status` | `GET` (`WP_REST_Server::READABLE`) | `current_user_can('upload_files')` | `{total, optimized, unoptimized, savings_bytes, queue: <progress()>}` |
| `/capabilities` | `GET` | `current_user_can('upload_files')` | `Capabilities::get_report()` — `{gd_available, gd_version, imagick_available, imagick_version, preferred_editor, formats:{jpeg,png,gif,webp,avif:{decode,encode,wp_supported}}, memory_limit, memory_limit_text, upload_max_filesize, post_max_size, max_execution_time, max_pixels}` |

Additionally, a **REST field** `kitgenix_optimizer` is registered on the core `attachment` post type (`wp/v2/media`), read-only (`update_callback: null`), returning the same `_kitgenix_image_optimizer` postmeta value (or `{status:'unoptimized'}` if absent).

Both routes and the field are read-only/diagnostic — there is no REST route for triggering optimization, uploading images, or writing settings.

## Cron / Scheduled Events

| Hook | Schedule | Registered by | Purpose |
|---|---|---|---|
| `kitgenix_image_optimizer_queue_tick` | Custom `kitgenix_one_minute` interval (60s), single event scheduled ~60s in the future, re-scheduled by `Queue::schedule_cron()` whenever the queue starts/resumes/retries, unscheduled on pause/stop/completion | `Queue::init()` → `Queue::schedule_cron()`/`unschedule_cron()` | Advances the bulk-optimization queue in the background (up to ~20 seconds of work per tick via `run_cron_batch()`), so a bulk run continues even if the admin's browser tab is closed. |
| `kitgenix_image_optimizer_prune_event_log` | WordPress built-in `daily`, first run ~1 hour after being scheduled | `Event_Log::maybe_schedule_prune_cron()`, hooked to `admin_init` | Removes log entries older than the configured retention window (7/14/30 days) and re-applies the 100-entry cap. |
| `kitgenix_image_optimizer_backfill_stats` | One-off (`wp_schedule_single_event`, 30s in the future) | `Migrations::maybe_run()`, only when upgrading from DB version < 2 | Runs `Stats::recalculate()` once in the background so the incremental Stats aggregate is correctly seeded after upgrading from a version that had no such aggregate, without a blocking scan on the triggering page load. |

All three hooks are explicitly unscheduled in `uninstall.php` (`wp_unschedule_event`/`wp_clear_scheduled_hook`).

## Frontend Behavior

- **Lazy loading** (`Lazy_Load`, opt-in via `lazy_load` setting): on `template_redirect` (frontend, non-admin only), starts an output buffer (`ob_start`) that regex-scans the entire rendered HTML for `<img ...>` tags. Any `<img>` without an existing `loading` attribute gets `loading="eager"` if it's the very first image on the page (to avoid delaying the likely LCP candidate) or `loading="lazy"` otherwise. Tags with a `data:` URI `src` (tracking pixels/inline images) are left alone. The buffer is explicitly closed on `shutdown` (priority 0).
- **WebP/AVIF `<picture>` delivery** (`Webp_Delivery`, active whenever `convert_to_webp` or `convert_to_avif` is on): filters `the_content`, `post_thumbnail_html` (both priority 99, regex-scanning for all `<img>` tags), and `wp_get_attachment_image` (single-image filter). For each `<img>` whose `src` is a local JPEG/PNG file inside the uploads directory, it checks for sibling `<file>.avif`/`<file>.webp` sidecar files on disk and, if found, wraps the tag in `<picture><source type="image/avif" ...><source type="image/webp" ...><img .../></picture>` — AVIF source listed first so capable browsers prefer it. It also rewrites any `srcset` attribute entry-by-entry to the sidecar equivalents, falling back to the single-`src` sidecar if any srcset entry lacks one. Non-local (off-uploads-dir) image URLs are left untouched.
- No other frontend markup, shortcodes, blocks, or widgets are registered.

## Assets

### JavaScript (`assets/js/`)

| File | Enqueued on | Purpose |
|---|---|---|
| `kitgenix-admin-tabs.js` | Settings page only (`Settings_UI::enqueue_assets`) | Shared cross-plugin controller: tab switching (URL hash/query sync), in-page settings search (`/` and Cmd/Ctrl+K shortcuts), mobile hamburger menu, dropdown menus, light/dark theme toggle (persisted to `localStorage`), sticky-topbar-offset measurement, and moving WP admin notices above the app shell. Explicitly designed to be byte-identical across all Kitgenix plugins. |
| `kitgenix-admin-components.js` | Settings page only | Shared component controller: toast notifications, accessible modal open/close (focus trap + return-focus + Escape-to-close), collapsible/accordion cards, copy-to-clipboard, live client-side table search/filter, and lightweight client-side table pagination (`data-kitgenix-table-paginate`). Delegated event binding so dynamically-injected markup works without re-binding. |
| `admin.js` | Settings page only, depends on `jquery` (registered but not actually used — code is vanilla JS using `fetch`) | This plugin's own logic: reads `window.kitgenix_image_optimizer_admin` (localized data), drives the bulk-queue lifecycle (`kitgenix_image_optimizer_queue_start/next/pause/resume/retry` polling loop with a 150ms interval between `queue_next` calls while running), updates KPI stat tiles and the progress bar live, updates the "Retry N Failed" button, handles the "Recalculate stats" button, and handles single-image Optimize/Re-optimize/Restore buttons in the Images-tab table. |

`Media_Column::inline_js()` and `Attachment_Panel::enqueue_scripts()` also each emit a small inline `wp_add_inline_script` payload (attached to the `jquery` handle) providing the Media Library column's and the attachment meta box's own Optimize/Restore AJAX click handlers respectively — these are separate, self-contained snippets, not part of the three files above.

### CSS (`assets/css/`)

| File | Enqueued on | Purpose |
|---|---|---|
| `kitgenix-admin-ui.css` | All admin pages where the plugin registers it (early, priority-5 registration in the main plugin file; actually enqueued by `Settings_UI`, `Media_Column`, `Attachment_Panel`, and the Hub) | The shared cross-plugin Kitgenix design system: CSS custom-property tokens (brand colors, radius scale, spacing scale, `@import`ed Manrope/Inter from Google Fonts), and every shared component style (topbar, cards, toggles, stat tiles, tables, badges, buttons, notices, modals, forms). Explicitly documented as "keep this file identical between plugins." |
| `kitgenix-hub.css` | The shared `kitgenix` Hub admin page only | Plugin-discovery grid layout, consuming the same design tokens. |
| `admin.css` | Settings page, Media Library (`upload.php`), and the attachment edit screen | This plugin's own presentation layer: app-wrapper max-width, bulk-status text colors, KPI stat-tile color accents, and the Images-tab table's column widths/typography — everything else is inherited from `kitgenix-admin-ui.css`. |

## Internationalization

- **Text Domain:** `kitgenix-image-optimizer` (declared in the plugin header, `Domain Path: /languages`).
- **No explicit `load_plugin_textdomain()` call exists anywhere in the codebase.** Since the plugin is distributed via wordpress.org (per its `Plugin URI`), it relies on WordPress's automatic translation loading for wordpress.org-hosted plugins (available since WP 4.6), which does not require a manual `load_plugin_textdomain()` call as long as the plugin is installed from/updated through wordpress.org and a matching `.mo` translation exists in the language pack.
- **`languages/kitgenix-image-optimizer.pot`** is present (a template only) — generated via WP-CLI (`X-Generator: WP-CLI 2.12.0`), `POT-Creation-Date: 2026-08-30`. **No `.po` or `.mo` files are shipped in the repository** — translated strings would come from wordpress.org's own translation infrastructure once the plugin is live there, not from local files.
- All user-facing strings throughout the codebase consistently use `__()`, `_e()`, `esc_html__()`, `esc_attr__()`, and `sprintf()`/`printf()` with `/* translators: */` comments for every placeholder-bearing string (verified across `Settings_UI.php`, `Event_Log.php`, `Optimizer.php`, `Queue.php`, `CLI.php`, `Site_Health.php`, `Media_Column.php`, `Attachment_Panel.php`).

## Security

- **Nonces:** All AJAX handlers use `check_ajax_referer( 'kitgenix_image_optimizer_nonce', 'nonce' )`. The two `admin-post.php` form handlers (`handle_clear_event_log`, `handle_save_log_retention`) use `check_admin_referer()` with distinct nonce actions (`kitgenix_image_optimizer_clear_event_log`, `kitgenix_image_optimizer_save_log_retention`). The Settings form uses `wp_nonce_field( 'kitgenix_image_optimizer_settings_save', ... )` plus the standard `settings_fields()` Settings-API nonce.
- **Capability checks:**
  - Settings page and its form handlers: `manage_options`.
  - AJAX single-image optimize/restore: `upload_files` **and** `edit_post` on the specific attachment ID (`Optimizer::current_user_can_manage_attachment()`) — explicitly prevents a Contributor/Author with generic `upload_files` from optimizing/restoring another user's attachment by guessing its ID (called out as a 2.0.0 security fix in the changelog).
  - AJAX stats recalculation: `manage_options`.
  - Bulk-queue AJAX actions: `upload_files`.
  - REST routes: `upload_files` via permission callbacks (never `__return_true`).
  - Hub page rendering: `manage_options` or (`WooCommerce` active and) `manage_woocommerce`.
- **Sanitization:** `Settings::sanitize()` is the single choke point for all settings input — every boolean is coerced to `0`/`1`, every numeric field is `absint()`-clamped to its valid range, `compression_level` is whitelist-checked against 5 valid values, MIME exclusions are checked against `sanitize_mime_type()` + the `OPTIMIZABLE_MIMES` whitelist, free-text fields use `sanitize_textarea_field( wp_unslash( ... ) )`, and the attachment-ID list is parsed/re-serialized through `absint()` per entry (`sanitize_id_list()`).
- **Escaping:** Every admin-rendered string uses `esc_html()`/`esc_html__()`/`esc_attr()`/`esc_attr__()`/`esc_url()`/`esc_textarea()`/`esc_js()` at output time. Where raw HTML must be echoed (small trusted inline SVG icon markup from `Settings_UI::icon()`, or `$yes`/`$no` badge strings pre-built with `esc_html__()`), the code carries an explicit `// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` comment justifying why (static, non-user-controlled markup). Dynamic HTML fragments assembled from mixed sources (e.g. the Hub page's action-button HTML, image-table action cells) are passed through `wp_kses()` with an explicit allowed-tags/attributes whitelist before output.
- **File handling security:**
  - `Image_Guard` rejects oversized files (>200MB), oversized pixel counts (>60 megapixels, filterable), oversized single dimensions (>40,000px, filterable), and estimates decode memory against `memory_limit` — all *before* any GD/Imagick decode call, specifically to prevent decompression-bomb crashes.
  - All optimized-file writes are atomic: written to a uniquely-named temp file (`uniqid()`-suffixed) in the same directory, verified to exist and be non-empty/smaller, then moved into place via `WP_Filesystem::move()` (or the temp file is deleted on any failure) — no partially-written file is ever left at the live path.
  - The "never make it bigger" rule discards any optimization result that isn't strictly smaller than the input.
  - Backup/restore/sidecar file paths are all derived deterministically from the attachment's own already-validated attached-file path (`preg_replace()`-based suffixing), never from user-supplied path input.
  - `uninstall.php` only ever deletes plugin-generated derivative files (`-kitgenix-original.*` backups, `.webp`/`.avif` sidecars) and only when the site owner has explicitly opted in via `delete_backups_on_uninstall` — the actual Media Library-attached files are never touched by uninstall regardless of settings.

## Uninstall / Cleanup

**`uninstall.php`** (standalone convention file, invoked automatically by WordPress when the plugin is deleted from Plugins → Installed Plugins — no explicit `register_uninstall_hook()` call is used or needed for this convention). Guarded by `defined('WP_UNINSTALL_PLUGIN') || exit;`.

Behavior:
1. Reads the `kitgenix_image_optimizer_settings` option directly (does not bootstrap plugin classes — uninstall runs in isolation).
2. If `keep_data_on_uninstall` is truthy, the script **returns immediately** — nothing is deleted (settings, logs, per-image postmeta, and backup files are all left in place).
3. Otherwise, it deletes:
   - Options: `kitgenix_image_optimizer_settings`, `kitgenix_image_optimizer_event_log`, `kitgenix_image_optimizer_log_retention_days`, `kitgenix_image_optimizer_stats`, `kitgenix_image_optimizer_queue`, `kitgenix_image_optimizer_db_version`.
   - Transient: `kitgenix_image_optimizer_do_activation_redirect`.
   - Scheduled cron events: `kitgenix_image_optimizer_queue_tick` (via `wp_unschedule_event`), `kitgenix_image_optimizer_backfill_stats` and `kitgenix_image_optimizer_prune_event_log` (via `wp_clear_scheduled_hook`).
   - All `_kitgenix_image_optimizer` postmeta rows across every attachment, via a direct `$wpdb->query()` (bulk delete, not per-post `delete_post_meta()`, for performance at scale).
4. If, in addition, `delete_backups_on_uninstall` is truthy, it paginates through every image attachment (500 at a time) and deletes each attachment's `-kitgenix-original.<ext>` backup file and any `.webp`/`.avif` sidecar files, if present, via `wp_delete_file()`.
5. **The plugin never deletes a user's actual Media Library-attached image files**, under any combination of these settings — this is explicitly documented in both the code comments and the Uninstall settings-card copy in the admin UI.

**Deactivation** (`register_deactivation_hook` in the main plugin file) only clears the `kitgenix_image_optimizer_do_activation_redirect` transient — it does not stop the bulk queue, unschedule cron events, or touch any data. This means a paused/running bulk queue and its cron hook remain scheduled across a deactivate/reactivate cycle (only `uninstall.php` — i.e. actual deletion — tears those down).

## Known Limitations / TODOs

- **`CHANGELOG.md` and `readme.txt` disagree on the 2.1.0 release date** (14 September 2026 vs. 10 October 2026 respectively), even though `DOCUMENTATION.md` describes `CHANGELOG.md` as a duplicate of `readme.txt`'s changelog plus the 2.1.0 entry.
- **No true content-hash change detection:** The per-file "fingerprint" used to short-circuit re-optimization is `filesize:mtime`, explicitly documented in code as a deliberate cheap-but-imperfect tradeoff ("a real content hash would be safer but far more expensive on large libraries") — a file replaced with different content but coincidentally identical size and mtime would not be re-optimized.
- **Best-effort locking only:** `Queue`'s AJAX/cron overlap lock is a 30-second transient, explicitly documented as "not a distributed lock" — acceptable per the code comment because `optimize_attachment()` is idempotent, but not safe against true concurrent multi-process race conditions beyond the common single-site case.
- **No bulk/global "before/after optimize" hook:** unlike some hooks exposed for `Image_Guard` limits, there is no `do_action('kitgenix_image_optimizer_after_optimize', ...)`-style hook fired per attachment for third-party integrations to react to; the only two filters exposed publicly are `kitgenix_image_optimizer_max_pixels` and `kitgenix_image_optimizer_max_dimension`.
- **WebP/AVIF sidecars are JPEG/PNG-source only:** `maybe_generate_sidecars()` explicitly skips any other MIME type (including GIF and WebP-source images), by design.
- **Backup files are not integrity-checked:** `optimize_file()`'s backup copy uses `@copy()` with the error suppressed ("best-effort backup; failure is non-fatal") — a failed backup copy does not block optimization and is not surfaced to the admin as a warning at that moment (though a later restore attempt would correctly report `no_backup_file`).
- **`Settings::flush_cache()` is a documented no-op:** kept only "for API stability" — the per-request static settings cache cannot actually be cleared mid-request, which is fine for normal request lifecycles but is explicitly noted as relevant "for tests."
- **Media Library column/meta-box/table support list is narrower than the optimizable MIME list:** `Media_Column::render_column()`, `Attachment_Panel::render_meta_box()`, and the Images-tab "Recent Images" table's `WP_Query` (`Settings_UI`) all check against `['image/jpeg','image/png','image/webp','image/gif']` (four types), which omits `image/avif` even though AVIF is part of `Settings::OPTIMIZABLE_MIMES` and is optimized by the engine — an AVIF attachment would show no Compression-column UI, meta box, or "Recent Images" row despite being processed.
- **A rendered Settings-form nonce field appears to be vestigial.** `wp_nonce_field( 'kitgenix_image_optimizer_settings_save', 'kitgenix_image_optimizer_settings_nonce' )` is output on the Settings form, but no corresponding `check_admin_referer()`/`wp_verify_nonce()` call exists anywhere in the codebase — actual save-time protection comes entirely from the Settings API's own `settings_fields()`-generated nonce.
- **`jquery` dependency in `admin.js`:** the script is registered with a `jquery` dependency but its own code uses only vanilla `fetch()`/DOM APIs — the dependency appears to exist only to guarantee `jquery` loads before it (since `Media_Column`'s and `Attachment_Panel`'s own inline scripts, which *do* use jQuery, are also attached to the `jquery` handle on the same/related pages), not because `admin.js` itself needs jQuery.
- **Lazy-loading is a regex-based, full-page output buffer:** `Lazy_Load::process_buffer()` uses a single `<img\b([^>]*?)>` regex over the entire response body rather than an HTML parser; this is fast and dependency-free but — as with any regex-on-HTML approach — could theoretically mis-handle unusual edge cases (e.g. `<img>`-like strings inside inline `<script>`/`<textarea>` blocks or malformed markup), though no specific bug of this kind is flagged in the code.
- **No explicit `load_plugin_textdomain()` call**, relying entirely on wordpress.org's automatic translation-loading mechanism; if this plugin is ever distributed outside wordpress.org (e.g. manually zipped and installed), translations would not load without such a call being added.
- **No `.po`/`.mo` files are shipped**, only the `.pot` template — translations are not available out-of-the-box in a non-wordpress.org distribution.
