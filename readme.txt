=== Kitgenix Image Optimizer ===
Contributors: kitgenix
Donate link: https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2
Tags: image optimization, image compression, webp, avif, media library
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
PluginScore Verification: pluginscore-verify-f5d18f8e683a8cc18cb05f6edbe91503

Compress, resize and bulk-optimize WordPress images locally, with WebP/AVIF sidecars, backups, diagnostics and WP-CLI.

== Description ==

**Kitgenix Image Optimizer** is a local WordPress image optimization plugin for compressing Media Library images, resizing oversized uploads and creating modern WebP or AVIF sidecar files without sending image files to a remote compression API.

Image processing runs through WordPress's `WP_Image_Editor` layer using the image library available on the server, normally GD or Imagick. New uploads can be optimized automatically, while existing media can be processed through a persistent background queue or WP-CLI.

The plugin is designed for WordPress sites and WooCommerce stores that want image compression, size reduction and modern-format delivery without per-image credits or an external image-processing account.

Learn more about Kitgenix at [Kitgenix](https://kitgenix.com/).

= WordPress Image Optimization Features =

* Local JPEG, PNG, WebP, GIF and AVIF optimization when supported by the active WordPress image editor.
* Automatic optimization after WordPress generates attachment metadata and registered image sizes.
* Persistent bulk queue for existing Media Library images.
* Bulk optimize or force re-optimize modes.
* Pause, resume, stop and retry-failed queue controls.
* WP-Cron background continuation so the browser does not need to remain open.
* Compression presets plus custom JPEG, PNG, WebP and AVIF quality controls.
* Maximum width/height integration with WordPress's `big_image_size_threshold` behaviour.
* Optional WebP and AVIF sidecar generation for JPEG/PNG source images.
* Frontend `<picture>` delivery when corresponding sidecars exist.
* Optional metadata stripping.
* Optional optimization of generated thumbnails/sub-sizes.
* Optional original-image backups and restore workflow.
* Optional native `loading` attribute management for frontend images.
* Animated GIF protection.
* Exclusions by MIME type, file size, dimensions, filename pattern, attachment ID and uploads-relative directory.
* File/pixel/memory safety checks before expensive image decoding.
* Atomic file replacement to reduce corruption risk if processing is interrupted.
* "Do not replace with a larger file" protection.
* Media Library status/savings information and per-image actions.
* Aggregate optimization statistics with manual recalculation.
* Diagnostics and WordPress Site Health integration.
* Capped local activity/error log.
* REST capability/status endpoints.
* WP-CLI commands for optimization, queue control, status, statistics and restore.

= Local Processing: No Image Optimization API Required =

The source image and generated image files remain on the WordPress server. The optimizer asks WordPress for an appropriate image editor, processes the file locally and writes the result back to the uploads directory.

There is no Kitgenix compression service and no requirement to upload images to a third-party optimization API. That avoids per-image quotas and keeps the actual image-processing data flow within the site/hosting environment.

= Automatic Upload Optimization =

When automatic optimization is enabled, the plugin hooks into WordPress attachment-metadata generation after WordPress has created registered image sizes. This lets the plugin work with the final master/sub-size set rather than racing the core thumbnail-generation process.

Per-file fingerprints are used so regenerating attachment metadata does not automatically mean every unchanged file must be recompressed again.

= Compression Presets and Custom Quality =

Administrators can choose a preset compression profile or use custom format settings. The code supports configurable JPEG quality, PNG compression level, WebP quality and AVIF quality, subject to what the server's active image editor can actually encode.

Modern-format options are capability-gated. If WordPress reports that the active server/editor cannot encode WebP or AVIF, the relevant functionality is not treated as available simply because a checkbox exists.

= WebP and AVIF Sidecars =

For JPEG/PNG source images, optional `.webp` and `.avif` sidecars can be generated alongside the original file. The original source remains the fallback.

When sidecar delivery is enabled and the corresponding sidecar files exist, frontend image markup can be wrapped in a `<picture>` element containing AVIF and/or WebP `<source>` elements. AVIF is placed before WebP where both are available so a capable browser can choose the first supported modern format.

The delivery logic only rewrites images that resolve within the site's WordPress uploads directory. Remote images are not rewritten as if local sidecar files existed.

= Resizing Oversized Images =

Configured maximum dimensions integrate with WordPress's big-image threshold so the site does not unnecessarily perform one resize in WordPress core and a second independent resize in the plugin.

The optimizer preserves aspect ratio and relies on the active WordPress image editor for the actual image operation.

= Metadata Stripping =

Optional metadata stripping can remove image metadata during processing where supported by the active editor. This can reduce file size and may remove embedded EXIF/GPS information from images that are rewritten.

Because metadata requirements differ between sites, administrators should keep this disabled if they need embedded camera/copyright/location metadata to remain in optimized derivatives.

= Backups and Restore =

If **Backup Originals** is enabled before optimization, the plugin can retain an original backup file. A per-image restore action and WP-CLI restore command can put that backup back in place.

Restoring a master image regenerates WordPress attachment metadata and sub-sizes so the database dimensions and generated files stay aligned with the restored source.

Backup deletion on uninstall is separately configurable. Media Library originals are never treated as disposable plugin settings data.

= Bulk Optimization Queue =

Large libraries are handled through persisted queue state rather than one giant admin request. The queue can be started in normal bulk mode or re-optimization mode, paused, resumed, stopped and asked to retry failed items.

A scheduled background runner continues processing queue batches after the administrator leaves the page. The admin interface can poll queue status and display processed/failed counts without requiring one PHP request to remain open for the entire library.

= Exclusion Rules =

The code includes exclusions for:

* Files below a minimum size.
* Files above a maximum size.
* Filename patterns.
* MIME types.
* Minimum width/height thresholds.
* Maximum width/height thresholds.
* Specific attachment IDs.
* Upload-directory path fragments.

Animated GIFs can also be skipped to avoid destroying animation during normal still-image processing.

= Image Safety and Atomic Writes =

Before decoding an image, the guard can consider file size, pixel dimensions and estimated memory requirements. This is intended to reject obviously unsafe or resource-exhausting image inputs before GD/Imagick attempts a costly decode.

Optimized output is written to a temporary file and verified before it replaces the existing file. If the newly generated file is not actually smaller than the original, the optimized candidate can be discarded so "optimization" does not make the asset larger.

= Lazy Loading =

An optional frontend lazy-loading layer can add native `loading` attributes to `<img>` elements that do not already declare one. The first image is left eager to avoid automatically delaying the likely Largest Contentful Paint candidate, while later images can receive `loading="lazy"`.

The lazy-loading processor skips admin and other unsuitable contexts and does not overwrite an existing loading attribute.

= Media Library and Attachment Tools =

The Media Library receives optimization status/savings information and per-image controls. An attachment panel provides additional context for a selected image, and administrators can trigger single-image optimization or restore where permitted.

AJAX actions require both the plugin-level media capability and permission to edit the individual attachment.

= Diagnostics and Site Health =

Diagnostics report GD/Imagick availability and versions, preferred WordPress image editor, PHP memory information and encode/decode support for relevant formats. Site Health exposes the same capability picture and can warn when a feature such as WebP/AVIF conversion is enabled but the server cannot perform that conversion.

Optimization failure counts and queue status are also available for troubleshooting.

= REST API =

The plugin registers authenticated/read-only diagnostic routes under:

`kitgenix-image-optimizer/v1`

Routes provide status and capability information. They are intended for administration/diagnostics rather than remote image upload or third-party compression.

= WP-CLI =

The command base is:

`wp kitgenix-optimizer`

The code includes commands for server/queue status, optimizing all eligible images or a single attachment, forced re-optimization, statistics, backup restore and queue actions (`start`, `pause`, `resume`, `stop`, `retry`, `status`).

= Privacy and Storage =

Images are processed locally. Plugin settings, queue state, statistics, logs and per-image optimization metadata are stored in WordPress. Optional backup and sidecar files are stored in the site's uploads area.

The shared Kitgenix admin Hub can request public plugin-directory data from WordPress.org, and the admin stylesheet imports disclosed Google Fonts. Neither service receives image files for optimization.

= Typical Uses =

* Reduce image file sizes on a content-heavy WordPress site.
* Optimize WooCommerce product imagery without a per-image SaaS plan.
* Convert supported JPEG/PNG uploads to WebP/AVIF sidecars.
* Process an existing Media Library in resumable batches.
* Preserve recoverability by creating local original backups before optimization.
* Diagnose whether a host can actually encode WebP or AVIF.
* Automate image maintenance with WP-CLI.

== Installation ==

1. Install and activate **Kitgenix Image Optimizer**.
2. Open **Kitgenix → Image Optimizer → Settings** and configure compression, resizing, WebP/AVIF, backups, lazy loading, and exclusions.
3. Use **Images** for existing files and **Diagnostics** to check GD/Imagick and format support.

== Frequently Asked Questions ==

= Does it require an image optimization API? =
No. Processing is local through WordPress `WP_Image_Editor`. See **External Services** for non-image admin requests.

= Can I bulk-optimize and restore images? =
Yes. The queue supports optimization, re-optimization, pause/resume, and failed-item retry. Restore is available when **Backup Originals** was enabled before optimization.

== Screenshots ==

1. Settings screen for compression presets, custom JPEG/PNG/WebP/AVIF quality, resizing, and metadata stripping.
2. Images tab showing stats.
3. Diagnostics tab reporting GD/Imagick availability, encode/decode support, and PHP memory limits.
4. Media Library optimization status, savings information, and per-image restore actions.
5. Log tab with searchable, paginated activity and error history.

== External Services ==

**WordPress.org Plugins API:** When an authorized administrator opens the shared Kitgenix Hub, WordPress core `plugins_api()` retrieves public Kitgenix plugin metadata. Requests use plugin slugs, are cached for up to 24 hours, and do not send images. Service: https://wordpress.org/plugins/ – Privacy: https://wordpress.org/about/privacy/

**Google Fonts:** Kitgenix admin CSS imports Inter and Manrope on screens where it is loaded, so the administrator's browser may send connection data such as an IP address to Google. Service: https://fonts.google.com/ – Privacy: https://policies.google.com/privacy – Terms: https://policies.google.com/terms

No image is sent to either service for optimization.

== Upgrade Notice ==

= 2.1.0 =
Version 2.1.0 is recommended for all sites.

== Changelog ==

= 2.1.0 (13 October 2026) =

* New: Added a configurable log auto-delete setting (7/14/30 days) to automatically remove old event log entries.
* New: Added a "Request a Feature" button to the Need Help? sidebar, linking directly to this plugin's page on the Kitgenix roadmap (https://roadmap.kitgenix.com).
* New: Added a "Request a Feature" banner below the plugin grid on the Kitgenix Hub page, linking to the shared Kitgenix roadmap.

= 2.0.0 (31 August 2026) =

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
