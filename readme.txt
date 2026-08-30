=== Kitgenix Image Optimizer ===
Contributors: kitgenix
Donate link: https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2
Tags: image optimization, compress images, resize images, webp, avif
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Plugin URI: https://wordpress.org/plugins/kitgenix-image-optimizer/
Author Plugin URI: https://wordpress.org/plugins/kitgenix-image-optimizer/
Author: Kitgenix
Author URI: https://wordpress.org/plugins/kitgenix-image-optimizer/
Documentation URI: https://wordpress.org/plugins/kitgenix-image-optimizer/
Support URI: https://wordpress.org/support/plugin/kitgenix-image-optimizer/
Author Support URI: https://wordpress.org/support/plugin/kitgenix-image-optimizer/
Feature Request URI: https://wordpress.org/support/plugin/kitgenix-image-optimizer/

Optimize, compress, resize, and convert images to WebP/AVIF on upload, plus a resumable bulk optimizer for your Media Library. No external API.

== Description ==

**Kitgenix Image Optimizer** compresses, resizes, and converts images to WebP and AVIF automatically on upload, and includes a resumable bulk optimizer for the images already in your Media Library – all powered by WordPress's own `WP_Image_Editor` (GD or Imagick). There is no external image-optimization API, no per-image quota, no account to create, and no image data is ever sent off your server.

Every capability the plugin offers is detected live from your actual server before it's ever shown to you. If your host's GD or Imagick build can't encode WebP or AVIF, the plugin says so plainly on the Diagnostics tab instead of silently failing or claiming support it can't deliver.

This plugin is built for site owners, developers, and agencies who want faster page loads and a smaller Media Library footprint without handing image data to a third-party service or juggling per-image quotas and monthly limits.

= How Image Optimization Works =

All processing happens locally on your own server through WordPress's built-in `WP_Image_Editor` abstraction (GD or Imagick, whichever your host provides). No image, file, or byte is ever transmitted to an external API or third-party image-processing service.

Optimization runs on `wp_generate_attachment_metadata`, i.e. **after** WordPress has already generated every registered thumbnail size and applied `big_image_size_threshold` scaling – so the plugin works with core's own upload-time resizing rather than against it. Every generated sub-size is then optimized in place using your chosen compression profile.

= Key Features =

* **Zero external dependencies** – uses WordPress's built-in `WP_Image_Editor`, powered by GD or Imagick, whichever your server provides. No API keys, accounts, or subscriptions.
* **Automatic optimization on upload** – compresses the main file and every generated thumbnail size as soon as an image is uploaded (can be disabled if you prefer manual/bulk-only optimization).
* **Compression profiles** – Conservative, Balanced, Aggressive, and Maximum presets, or dial in exact JPEG, WebP, AVIF, and PNG quality values yourself.
* **WebP & AVIF conversion** – optional sidecar copies (`photo.jpg.webp`, `photo.jpg.avif`) are generated alongside the original, only when your server can actually encode that format.
* **Automatic frontend delivery of WebP/AVIF** – when sidecars exist, matching `<img>` tags in post content, featured images, and `wp_get_attachment_image()` output are automatically wrapped in a `<picture>` element (AVIF offered first, WebP second), so supporting browsers download the smaller format with no theme changes required.
* **Native lazy loading** – optionally adds `loading="lazy"` to images throughout the page (not just the ones WordPress core already tags), while keeping the first image on the page eager so it doesn't delay your Largest Contentful Paint (LCP) element.
* **Resumable bulk optimizer** – process your entire existing Media Library through a persisted queue with pause, resume, and "Retry Failed" controls; a scheduled background task keeps it moving even if you close the browser tab.
* **Backup & restore** – an optional pre-optimization backup can be restored at any time; restoring regenerates WordPress's attachment metadata and thumbnail sizes so everything stays consistent.
* **Regeneration-safe** – re-running "Regenerate Thumbnails" (or any metadata regeneration) only re-compresses sizes that actually changed, never wastefully re-processing untouched files.
* **EXIF metadata stripping** – optionally strips EXIF/GPS metadata from images on both GD and Imagick, while EXIF orientation is always correctly applied before stripping.
* **Transparency & animation preserved** – animated GIFs are left untouched by default and PNG/GIF transparency is preserved.
* **Granular exclusions** – skip optimization by MIME type, minimum/maximum file size, minimum/maximum image dimensions, filename pattern, specific attachment IDs, or upload directory.
* **Live server capability matrix** – a Diagnostics tab and a matching Site Health section show exactly what your GD/Imagick build supports: per-format encode/decode, active image editor, PHP memory limits, and upload limits.
* **Media Library integration** – a Compression column shows per-image optimization status, percentage saved, and Optimize/Restore actions, plus a summary bar with total savings across your library.
* **Safe by design** – images are validated before decoding (dimension and estimated-memory checks guard against decompression-bomb-style files), writes are atomic, and any "optimized" result that isn't actually smaller than the original is discarded automatically.
* **REST API** – a `kitgenix-image-optimizer/v1` namespace with `/status` and `/capabilities` endpoints, plus a `kitgenix_optimizer` field added to every attachment on the core `wp/v2/media` REST endpoint.
* **WP-CLI support** – `wp kitgenix-optimizer status|optimize|stats|restore|queue` for scripting, deployments, and server automation.
* **Deliberate uninstall** – your actual Media Library image files are never deleted by this plugin under any setting; optional backup-file cleanup is opt-in only.

= Bulk Optimizing Your Media Library =

The Images tab drives a persisted, resumable queue rather than one long-running request. It can be paused, resumed, or restarted at any time, and a "Retry Failed" control re-queues anything that failed. A WP-Cron heartbeat (roughly once per minute) keeps the queue moving in the background even after you close the admin page, so libraries of any size can be processed without hitting PHP execution-time limits.

= Format Conversion & Frontend Delivery =

WebP and AVIF are generated as optional sidecar files alongside your original JPEG/PNG image – never as a destructive, in-place conversion. Each format is gated behind a live `wp_image_editor_supports()` capability check, so the plugin will not let you enable a format your server's GD or Imagick build cannot actually encode. When a sidecar exists, the plugin automatically serves it to supporting browsers by wrapping the relevant `<img>` tag in a `<picture>` element (checking post content, featured images, and `wp_get_attachment_image()` output), with AVIF offered before WebP.

= Developer Features =

* Per-attachment optimization data is stored in post meta and exposed on the REST API.
* Two filters let you adjust the built-in decompression-bomb safety thresholds.
* WP-CLI commands cover status checks, single or bulk optimization, statistics, restore, and full queue control, suited to deployment scripts and cron-driven workflows.
* A shared Kitgenix admin hub page (Kitgenix → menu) lists all installed and available Kitgenix plugins in one place.

See the Developer Reference section below for exact hook names, option keys, and REST routes.

= Compatibility =

Requires PHP's GD or Imagick extension (at least one is needed for WordPress to process images at all). The Diagnostics tab and Site Health report which one is active on your server and exactly which image formats it can encode and decode. WebP and AVIF conversion options are only offered when your server's image library can actually produce them.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it directly via Plugins → Add New in your WordPress admin.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to Kitgenix → Image Optimizer → Diagnostics to confirm your server's GD or Imagick support and exactly which image formats it can encode.
4. Choose a compression profile and configure resizing/format-conversion options under Kitgenix → Image Optimizer → Settings (Balanced is a sensible default).
5. Upload a new image – it is optimized automatically once "Optimize on Upload" is enabled (the default), or use the Images tab to bulk-optimize your existing library.
6. No API key, account, or external service registration is required at any step.

== External Services ==

Kitgenix Image Optimizer does **not** send any image data to an external service. All compression, resizing, and format conversion (JPEG, PNG, GIF, WebP, and AVIF) is performed entirely on your own server, using PHP's GD or Imagick extension via WordPress's native `WP_Image_Editor` abstraction. No image, file, or byte generated by this plugin's optimization features ever leaves your site, and no third-party API key or account is required or supported.

The plugin's shared Kitgenix admin hub screen (Kitgenix → Kitgenix in the admin menu) makes read-only requests to the public WordPress.org Plugins API (`api.wordpress.org`), via WordPress core's own `plugins_api()` function, to display active-install counts, star ratings, and icon/banner artwork for other Kitgenix plugins. These requests:

* Only run when an administrator views that specific admin screen.
* Send only the plugin slugs being looked up – no site content, image data, or personal information is included.
* Are cached in a WordPress transient for up to 24 hours to minimize repeat requests.

Data submitted to the WordPress.org Plugins API is governed by the WordPress.org Privacy Policy: https://wordpress.org/about/privacy/

No other external service, API, or third party is contacted by this plugin.

== Developer Reference ==

**Settings option:** `kitgenix_image_optimizer_settings` (single array option holding all plugin settings; sanitized via `register_setting()`).

**Other stored options:** `kitgenix_image_optimizer_event_log`, `kitgenix_image_optimizer_stats`, `kitgenix_image_optimizer_queue`, `kitgenix_image_optimizer_db_version`.

**Post meta:** `_kitgenix_image_optimizer` – per-attachment optimization data (status, original/optimized size, savings, timestamp), stored on the attachment post and exposed read-only via REST as the `kitgenix_optimizer` field.

**REST API routes** (namespace `kitgenix-image-optimizer/v1`, require the `upload_files` capability):

* `GET /wp-json/kitgenix-image-optimizer/v1/status` – total/optimized/unoptimized attachment counts, total bytes saved, and current bulk-queue progress.
* `GET /wp-json/kitgenix-image-optimizer/v1/capabilities` – the live GD/Imagick capability report shown on the Diagnostics tab.
* `wp/v2/media` – gains a read-only `kitgenix_optimizer` field on every attachment.

**Filters:**

* `kitgenix_image_optimizer_max_pixels` – adjusts the maximum decoded pixel count allowed before an image is rejected as a possible decompression-bomb file. Default: `60000000`.
* `kitgenix_image_optimizer_max_dimension` – adjusts the maximum single-axis pixel dimension allowed before an image is rejected. Default: `40000`.

**WP-CLI:** `wp kitgenix-optimizer <status|optimize|stats|restore|queue>` – see `wp help kitgenix-optimizer <subcommand>` for arguments.

**Scheduled events:** `kitgenix_image_optimizer_queue_tick` (custom one-minute cron schedule, drives the resumable bulk-optimization queue) and `kitgenix_image_optimizer_backfill_stats`.

== Frequently Asked Questions ==

= Does this plugin require an external API or account? =
No. It uses WordPress's native `WP_Image_Editor`, which delegates to your server's GD or Imagick PHP extension. No image is ever uploaded to a third-party service, and there is no API key or account to set up.

= Is my image data sent anywhere? =
No. All optimization happens locally on your own server. The only network request the plugin makes at all is a read-only, cached lookup of install counts/ratings for other Kitgenix plugins on the shared admin hub screen – no image or site content is ever included in that request. See the External Services section above for full details.

= Will this reduce my image quality? =
It depends on the compression profile you choose. Conservative is visually lossless; Balanced (the default) targets a strong quality-to-size ratio; Aggressive and Maximum trade more visible quality for smaller files. You can also set exact JPEG/WebP/AVIF/PNG quality values manually. An optimized result that isn't actually smaller than the original is automatically discarded, so optimization never makes a file larger.

= How much will my images shrink? =
It depends entirely on the image and the compression profile chosen – there is no fixed or guaranteed percentage. Photographic JPEGs straight from a camera typically see the largest gains; already-compressed or very small images may see little to no change, and the plugin simply leaves those alone rather than making them larger.

= Does it support WebP and AVIF? =
Yes, where your server supports them. Both are generated as optional sidecar copies alongside the original file, not destructive conversions, and both are gated behind a live capability check – the plugin will not let you enable a format your server can't actually encode. When sidecars exist, matching images are automatically served to supporting browsers via a `<picture>` element. Check the Diagnostics tab to see exactly what your server supports.

= Are images optimized automatically when I upload them? =
Yes, when "Optimize on Upload" is enabled (the default). Optimization runs after WordPress finishes generating all registered thumbnail sizes, so it works with core's own upload-time resizing instead of against it.

= Can I bulk-optimize the images already in my Media Library? =
Yes. The Images tab processes your existing library through a resumable, pausable queue rather than one long request, and a scheduled background task keeps it moving even if you close the admin page – so large libraries won't hit PHP timeouts.

= What happens if thumbnails are regenerated later (e.g. by a "Regenerate Thumbnails" plugin)? =
Only sub-sizes that actually changed are re-compressed; untouched sizes are left as they are, so regeneration never destructively re-processes the same file twice.

= What image file types are supported? =
JPEG, PNG, WebP, GIF, and AVIF images already in a format WordPress itself recognizes. Files that fail basic image validation, or whose dimensions/estimated memory use exceed a safety threshold, are skipped rather than processed, protecting against malformed files and decompression-bomb-style uploads.

= Can I restore an image to its original, pre-optimization state? =
Yes, but only if "Backup Originals" was enabled in Settings at the time that image was optimized. Restoring copies the backup back over the optimized file, removes any WebP/AVIF sidecars, and regenerates WordPress's attachment metadata and thumbnail sizes so everything stays consistent.

= Can I set a maximum image size for uploads? =
Yes, in the Resizing section of Settings. The configured maximum width/height is aligned with WordPress core's own `big_image_size_threshold`, so an oversized upload is resized once by core rather than resized twice.

= Does it add lazy loading to images? =
Optionally, yes. When enabled, the plugin adds `loading="lazy"` to images throughout the page that WordPress core doesn't already tag, while keeping the first image on the page eager so it doesn't delay your page's Largest Contentful Paint.

= What happens to my data if I uninstall the plugin? =
By default, uninstalling removes the plugin's settings, activity log, and per-image optimization records, but **never** your actual Media Library image files. A "Keep Settings & Data" option in Settings → Uninstall lets you preserve all of that instead. Backup files (the `-kitgenix-original` copies) are only deleted on uninstall if you explicitly enable "Delete Backup Files on Uninstall"; otherwise they are left in place.

= Is there a WP-CLI command for automating image optimization? =
Yes. Run `wp kitgenix-optimizer status|optimize|stats|restore|queue` to check status, run optimization, view savings statistics, restore backups, or manage the bulk optimization queue from the command line – useful for deployment scripts and server automation.

= Will optimizing images slow down my Media Library screen? =
No. The Media Library list view shows a Compression column with per-image status, but it doesn't run a live database scan on every page load – savings and status data are tracked in a small, incrementally-updated aggregate instead.

= Does this plugin work with page builders and other themes? =
Yes. Optimization runs at the WordPress core attachment-metadata level, so it is independent of your active theme or page builder. The optional `<picture>`-based WebP/AVIF delivery and lazy-loading features work on any HTML output containing standard `<img>` tags, including post content, featured images, and `wp_get_attachment_image()` calls.

== Screenshots ==

1. The Settings tab, showing the Conservative/Balanced/Aggressive/Maximum compression profiles with their per-format JPEG, WebP, AVIF, and PNG quality values.
2. The Images tab's resumable bulk optimizer, with live progress, pause/resume controls, and a "Retry Failed" action for the current queue run.
3. The Diagnostics tab's live server capability matrix, listing GD and Imagick availability and per-format encode/decode support for JPEG, PNG, GIF, WebP, and AVIF.
4. The Media Library list view with the added Compression column, showing per-image optimization status, percentage saved, and Optimize/Restore actions.
5. The summary bar at the top of the Media Library, showing total images, how many are optimized, how many are pending, and total space saved.
6. The WordPress Site Health status page showing the plugin's own "Your server can process images" test result and detailed debug information section.
7. The Kitgenix admin hub screen, listing every installed and available Kitgenix plugin with its activation status and quick actions.

== Credits ==
Built with ❤︎ by @kitgenix - https://kitgenix.com

== Upgrade Notice ==

= 2.0.0 =
Major update: adds AVIF conversion, a resumable/pausable bulk-optimization queue with background processing, a Diagnostics tab and Site Health checks, expanded exclusion rules, WP-CLI queue commands, and several security and reliability fixes (capability checks on AJAX handlers, decompression-bomb protection, atomic file writes). Review your Settings after updating, as compression preset labels and defaults have changed.

== Changelog ==

= 2.0.0 (25 August 2026) =

* New: Added per-file change fingerprinting so thumbnail regeneration only re-compresses image files that have actually changed instead of repeatedly processing every existing file.
* New: Added AVIF conversion alongside the existing WebP conversion feature.
* New: WebP and AVIF conversion options are now gated by live `wp_image_editor_supports()` capability checks, preventing unsupported conversion settings from appearing on servers that cannot encode the selected format.
* New: Added a Diagnostics tab showing GD and Imagick availability and versions, active image editor information, JPEG/PNG/GIF/WebP/AVIF encode and decode support, PHP memory and upload limits, and recent optimisation failure counts.
* New: Added matching WordPress Site Health diagnostics for the image editor environment and supported image formats.
* New: Rebuilt the bulk optimiser as a persisted, resumable processing queue with pause, resume, and "Retry Failed" controls.
* New: Added scheduled background processing for the bulk optimisation queue so processing can continue after the administrator closes the browser tab.
* New: Expanded optimisation exclusion rules to support MIME type, minimum and maximum file size, minimum and maximum image dimensions, specific attachment IDs, and upload directories in addition to the existing minimum-size threshold and filename-pattern exclusions.
* New: Added a dedicated Uninstall settings section with explicit "Keep Settings & Data" and "Delete Backup Files on Uninstall" options.
* New: Backup-file deletion on uninstall is disabled by default, and Media Library images are never deleted by the plugin regardless of the selected uninstall settings.
* New: Added the `wp kitgenix-optimizer status` WP-CLI command for displaying the current Image Optimizer status.
* New: Added `wp kitgenix-optimizer queue start`, `pause`, `resume`, `stop`, `retry`, and `status` WP-CLI commands for managing the bulk optimisation queue.
* New: Added a manual "Recalculate stats from scratch" action for administrators who want to perform a complete optimisation statistics recount on demand.
* New: Redesigned the entire admin interface around the shared Kitgenix design system with a sticky topbar, cross-plugin navigation, in-page settings search, and consistent card-based layouts.
* New: Added "/" and Cmd/Ctrl+K keyboard shortcuts for the in-page settings search.
* New: Added a light/dark theme toggle for the admin interface, with the selected preference remembered between visits.
* Improved: All image writes are now atomic – output is written to a temporary file, verified, and then renamed into place – preventing an interrupted PHP process from leaving behind a corrupted or partially written image.
* Improved: Image optimisation now runs on `wp_generate_attachment_metadata` after WordPress has generated registered image sub-sizes and applied `big_image_size_threshold`, instead of processing the raw upload before WordPress core.
* Improved: The configured maximum image width and height are now integrated with WordPress's `big_image_size_threshold` filter so oversized images are resized once instead of being independently resized by both WordPress and the plugin.
* Improved: Restoring an image backup now regenerates WordPress attachment metadata and sub-sizes from the restored master image, keeping stored dimensions and generated image files consistent with the restored source.
* Improved: Savings and optimised-image statistics are now stored in a lightweight incrementally updated option instead of being calculated through a live database scan on every Media Library or Settings page load.
* Improved: Compression presets are now labelled Conservative, Balanced, Aggressive, and Maximum, with the applicable per-format quality values displayed directly on each preset card, including AVIF values where supported.
* Improved: The Log tab now supports live search and pagination with 25 entries per page.
* Improved: Added a reference table to the Log tab explaining what each recorded category means and whether administrator action is required.
* Improved: The Support tab is now three focused cards – a PayPal donate card with a collapsible monthly-amount picker, a "what your support funds" summary, and a "get involved" panel for reviews and plugin links.
* Fix: Optimised output that is not actually smaller than the original image is now discarded automatically and the original file is retained, preventing optimisation from increasing file size.
* Fix: The "Strip Image Metadata" setting is now correctly applied on Imagick-based servers using `strip_meta()`. Previously the option had no effect when Imagick was the active image editor, leaving EXIF and GPS metadata intact despite the setting being enabled.
* Fix: The "PNG Compression" setting is now correctly passed to the active image editor. Previously the value was stored in Settings but never applied during PNG optimisation.
* Security: AJAX handlers for single-image optimisation and restore now verify the `edit_post` capability for the specific attachment in addition to the general `upload_files` capability, preventing contributor-level users from optimising or restoring attachments they do not have permission to edit.
* Security: Images are now validated before decoding using file-size limits, pixel-dimension limits, and an estimated-memory check against PHP's `memory_limit`, allowing malformed or decompression-bomb-style files to be rejected before GD or Imagick attempts to decode them.
* Removed: Removed the non-functional "Convert PNG to JPEG" setting, which was present in previous versions but had never been implemented.
* Dev: Added a shared `kitgenix-admin-components.js` component controller providing reusable toast notifications, modals, collapsible cards, copy-to-clipboard controls, and live table filtering for consistent behaviour across Kitgenix plugins.
