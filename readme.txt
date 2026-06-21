=== Kitgenix Image Optimizer ===
Contributors: kitgenix
Donate link: https://buymeacoffee.com/kitgenix
Tags: image optimization, compress images, resize images, webp, media library
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
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

Optimize, compress, and resize images in your WordPress media library with automatic on-upload processing and bulk optimization tools.

== Description ==

**Kitgenix Image Optimizer** compresses and resizes images automatically on upload and provides bulk optimization tools for your existing media library — all powered by WordPress's native WP_Image_Editor (GD or Imagick). No external API required.

Designed to be lightweight and modular:
- Automatic optimization on upload
- Configurable JPEG, WebP, and PNG quality settings
- Max-dimension resizing (preserves aspect ratio)
- Optional WebP conversion
- Optional EXIF metadata stripping
- Optional thumbnail sub-size optimization
- Per-image status tracking with a dedicated Images tab
- Activity log for all optimization events
- Bulk optimization with start/stop controls

= Features =

- **Zero external dependencies** — uses WordPress's built-in WP_Image_Editor powered by GD or Imagick.
- **Auto-optimize on upload** — every new image is compressed and resized the moment it enters the media library.
- **Configurable quality** — set JPEG quality (1–100), WebP quality (1–100), and PNG compression level (0–9) independently.
- **Max dimension capping** — set maximum width and height; images exceeding those dimensions are scaled down while preserving aspect ratio.
- **WebP conversion** — optionally convert JPEG and PNG images to WebP on upload.
- **Metadata stripping** — remove EXIF data to shave extra bytes off every image.
- **Thumbnail optimization** — optionally optimize every registered sub-size alongside the full image.
- **Bulk optimization** — the Images tab shows your 30 most recent uploads with per-image status and a bulk start/stop control that processes one image at a time without timeouts.
- **Activity log** — the Log tab records every optimization and settings change with timestamps.

== Quick Start ==

1. Install and activate the plugin.
2. Go to Kitgenix → Image Optimizer to configure settings.
3. Upload an image — it will be optimized automatically if "Optimize on Upload" is enabled.
4. Use the Images tab to bulk-optimize existing media.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` (or install via Plugins → Add New).
2. Activate the plugin.
3. Configure: Kitgenix → Image Optimizer.

== Frequently Asked Questions ==

= Does this plugin require an external API? =
No. It uses WordPress's native WP_Image_Editor which delegates to GD or Imagick — whichever your server provides.

= Will it affect my original images? =
Yes, the plugin overwrites the original file with the optimized version. Enable "Backup Originals" in settings if you want to keep a copy of the unoptimized file.

= Can I bulk-optimize existing images? =
Yes. Go to Kitgenix → Image Optimizer → Images and click "Start Bulk Optimization".

= What file types are supported? =
JPEG, PNG, WebP, and GIF images in the media library.

= Can I set a maximum image size? =
Yes. Set Max Width and Max Height in the Resizing section of the settings page. Images larger than these dimensions will be scaled down (aspect ratio preserved) on upload.

== Changelog ==

= 1.0.0 =
* Initial release.
