# Changelog

## 2.0.0 – 22 August 2026

- **New:** Added per-file change fingerprinting so thumbnail regeneration only re-compresses image files that have actually changed instead of repeatedly processing every existing file.
- **New:** Added AVIF conversion alongside the existing WebP conversion feature.
- **New:** WebP and AVIF conversion options are now gated by live `wp_image_editor_supports()` capability checks, preventing unsupported conversion settings from appearing on servers that cannot encode the selected format.
- **New:** Added a Diagnostics tab showing GD and Imagick availability and versions, active image editor information, JPEG/PNG/GIF/WebP/AVIF encode and decode support, PHP memory and upload limits, and recent optimisation failure counts.
- **New:** Added matching WordPress Site Health diagnostics for the image editor environment and supported image formats.
- **New:** Rebuilt the bulk optimiser as a persisted, resumable processing queue with pause, resume, and "Retry Failed" controls.
- **New:** Added scheduled background processing for the bulk optimisation queue so processing can continue after the administrator closes the browser tab.
- **New:** Expanded optimisation exclusion rules to support MIME type, minimum and maximum file size, minimum and maximum image dimensions, specific attachment IDs, and upload directories in addition to the existing minimum-size threshold and filename-pattern exclusions.
- **New:** Added a dedicated Uninstall settings section with explicit "Keep Settings & Data" and "Delete Backup Files on Uninstall" options.
- **New:** Backup-file deletion on uninstall is disabled by default, and Media Library images are never deleted by the plugin regardless of the selected uninstall settings.
- **New:** Added the `wp kitgenix-optimizer status` WP-CLI command for displaying the current Image Optimizer status.
- **New:** Added `wp kitgenix-optimizer queue start`, `pause`, `resume`, `stop`, `retry`, and `status` WP-CLI commands for managing the bulk optimisation queue.
- **New:** Added a manual "Recalculate stats from scratch" action for administrators who want to perform a complete optimisation statistics recount on demand.
- **New:** Redesigned the entire admin interface around the shared Kitgenix design system with a sticky topbar, cross-plugin navigation, in-page settings search, and consistent card-based layouts.
- **New:** Added "/" and Cmd/Ctrl+K keyboard shortcuts for the in-page settings search.
- **New:** Added a light/dark theme toggle for the admin interface, with the selected preference remembered between visits.
- **Improved:** All image writes are now atomic – output is written to a temporary file, verified, and then renamed into place – preventing an interrupted PHP process from leaving behind a corrupted or partially written image.
- **Improved:** Image optimisation now runs on `wp_generate_attachment_metadata` after WordPress has generated registered image sub-sizes and applied `big_image_size_threshold`, instead of processing the raw upload before WordPress core.
- **Improved:** The configured maximum image width and height are now integrated with WordPress's `big_image_size_threshold` filter so oversized images are resized once instead of being independently resized by both WordPress and the plugin.
- **Improved:** Restoring an image backup now regenerates WordPress attachment metadata and sub-sizes from the restored master image, keeping stored dimensions and generated image files consistent with the restored source.
- **Improved:** Savings and optimised-image statistics are now stored in a lightweight incrementally updated option instead of being calculated through a live database scan on every Media Library or Settings page load.
- **Improved:** Compression presets are now labelled Conservative, Balanced, Aggressive, and Maximum, with the applicable per-format quality values displayed directly on each preset card, including AVIF values where supported.
- **Improved:** The Log tab now supports live search and pagination with 25 entries per page.
- **Improved:** Added a reference table to the Log tab explaining what each recorded category means and whether administrator action is required.
- **Improved:** The Support tab is now three focused cards – a donate card with a collapsible monthly-amount picker, a "what your support funds" summary, and a "get involved" panel for reviews, plugin links, and community.
- **Fix:** Optimised output that is not actually smaller than the original image is now discarded automatically and the original file is retained, preventing optimisation from increasing file size.
- **Fix:** The "Strip Image Metadata" setting is now correctly applied on Imagick-based servers using `strip_meta()`. Previously the option had no effect when Imagick was the active image editor, leaving EXIF and GPS metadata intact despite the setting being enabled.
- **Fix:** The "PNG Compression" setting is now correctly passed to the active image editor. Previously the value was stored in Settings but never applied during PNG optimisation.
- **Security:** AJAX handlers for single-image optimisation and restore now verify the `edit_post` capability for the specific attachment in addition to the general `upload_files` capability, preventing contributor-level users from optimising or restoring attachments they do not have permission to edit.
- **Security:** Images are now validated before decoding using file-size limits, pixel-dimension limits, and an estimated-memory check against PHP's `memory_limit`, allowing malformed or decompression-bomb-style files to be rejected before GD or Imagick attempts to decode them.
- **Removed:** Removed the non-functional "Convert PNG to JPEG" setting, which was present in previous versions but had never been implemented.
- **Dev:** Added a shared `kitgenix-admin-components.js` component controller providing reusable toast notifications, modals, collapsible cards, copy-to-clipboard controls, and live table filtering for consistent behaviour across Kitgenix plugins.

## 1.0.0

- **New:** Initial release.