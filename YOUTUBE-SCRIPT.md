# YouTube Script: Kitgenix Image Optimizer

**Suggested video title:** Make WordPress Images Smaller, Automatically: Kitgenix Image Optimizer (WebP, AVIF, Bulk Optimize, No API)

**Alternative titles:**
- Stop Slow WordPress Images: Free Local Image Optimizer with WebP & AVIF
- Kitgenix Image Optimizer: Full Setup & Walkthrough

**Estimated runtime:** 10-11 minutes (approx. 1,780 spoken words at ~160-170 words per minute; screen demos may add pauses)

**Plugin version covered:** 2.1.0 (requires WordPress 6.0+, PHP 8.1+)

**Recording notes:** Record on a test site with a few dozen images already in the Media Library, plus a fresh, large phone photo (a 4-8 MB JPEG) ready to upload. Before recording, open **Kitgenix → Image Optimizer → Diagnostics** to confirm whether the host can encode WebP and AVIF, so the screen matches what you say in Section 5.

---

## 1. Hook

[ON SCREEN: A blog post loading slowly on a phone, with a throttled "Slow 4G" connection. Large hero photo appearing line by line.]

You've written a great blog post. You've added beautiful photos. And then you open it on your phone... and wait. And wait.

[ON SCREEN: Browser dev tools Network tab showing image requests: "IMG_4821.jpg — 6.2 MB", "IMG_4822.jpg — 5.8 MB".]

Here's the reason. Every photo came straight off a phone or camera. That's several megabytes each, uncompressed, on every post and every product page. That hurts your Core Web Vitals. It can drag down your search rankings. And visitors on slow mobile connections simply leave before the page finishes loading.

[ON SCREEN: Text overlay: "The fix doesn't need a subscription."]

The good news is that you can fix it without a monthly subscription, and without sending a single image off your server.

## 2. Intro

[ON SCREEN: Kitgenix logo, then the plugin's banner on the WordPress.org plugin page.]

This is **Kitgenix Image Optimizer**, a free WordPress plugin from us at Kitgenix.

It compresses and resizes your images, can create modern WebP and AVIF versions of them, and can bulk-optimize everything already in your Media Library. All of it happens locally, on your own server.

[ON SCREEN: Quick montage: a blog, a WooCommerce store, a photography portfolio.]

If your WordPress site has images, and honestly, every site does, this is for you. Bloggers, WooCommerce stores, portfolios, agencies managing client sites.

Here's the promise in one sentence: **upload images the way you always have, and the plugin quietly makes them smaller, safely, with no API keys and no image quotas.**

## 3. Why We Built This

[ON SCREEN: Simple two-column graphic. Left: "Cloud optimizers: monthly quotas, per-image credits, images uploaded to a third party." Right: "Basic compressors: JPEG/PNG only, no WebP or AVIF."]

Most image optimizers fall into one of two camps. Cloud services upload your images to someone else's servers, usually with a monthly quota or per-image credits, which a store with thousands of product photos uses up fast. Simple compress-only tools are free and local, but often stop at JPEG and PNG, with no WebP or AVIF.

[ON SCREEN: Text overlay: "Local processing. Modern formats. No quotas."]

We wanted the best of both. Kitgenix Image Optimizer does all of its processing through WordPress's own image editor, using GD or Imagick, whichever your server has. Your images never leave your hosting, and there are no per-image limits.

## 4. Installing & Activating

[ON SCREEN: WordPress admin → Plugins → Add New. Type "Kitgenix Image Optimizer" in the search box.]

Installing it takes about thirty seconds. In your dashboard, go to **Plugins, Add New**, and search for "Kitgenix Image Optimizer".

[ON SCREEN: Click "Install Now", then "Activate".]

Click **Install Now**, then **Activate**.

[ON SCREEN: The automatic redirect to the plugin's Settings page. Highlight the new "Kitgenix" menu in the sidebar with "Image Optimizer" beneath it.]

As soon as you activate, you're taken straight to the settings page. You can always find it again under the **Kitgenix** menu, then **Image Optimizer**.

[ON SCREEN: Click the "Diagnostics" tab. Show the GD / Imagick tiles and the format table with Decode / Encode / "WordPress Will Use It" columns.]

Before we touch any settings, here's a quick tip: open the **Diagnostics** tab. It shows whether your server has GD or Imagick, which editor WordPress is using, and, importantly, whether your host can actually encode WebP and AVIF. That tells you which modern-format options you'll be able to switch on.

## 5. Settings Walkthrough

[ON SCREEN: Back to the "Settings" tab. Scroll slowly through the General card.]

Now the settings. The defaults are sensible, so you could stop right here, but let's walk through what each option means for your site.

[ON SCREEN: General card: "Enable Image Optimizer" and "Optimize on Upload" toggles, both on.]

At the top, **Enable Image Optimizer** is the master switch, and **Optimize on Upload** controls whether new uploads get processed automatically. Both are on by default.

[ON SCREEN: Quality card. Hover over each preset card: Conservative, Balanced, Aggressive, Maximum, then Custom.]

Next is **Compression Level**, the biggest tradeoff you'll make. Each preset card shows the actual quality numbers it uses. **Conservative** keeps quality very high. **Balanced** is the recommended default, with a JPEG quality of 82, which most people can't tell apart from the original. **Aggressive** and **Maximum** give you smaller files with more visible compression. And **Custom** lets you set your own JPEG, WebP and AVIF quality values. PNGs have their own **PNG Compression** setting from zero to nine.

[ON SCREEN: Resizing card: Max Width 2560, Max Height 2560.]

Under **Resizing**, the max width and height default to 2560 pixels, so a huge camera photo is scaled down to a sensible size with its aspect ratio kept. This works with WordPress's own big-image handling, so each image is resized once, not twice.

[ON SCREEN: Advanced card. Point to "Convert to WebP" and "Convert to AVIF". If either is greyed out on this host, show that.]

In **Advanced** are **Convert to WebP** and **Convert to AVIF**. Both are off by default. Switch them on and the plugin creates an extra WebP or AVIF copy next to each image, keeping your original as the fallback. On the front end, modern browsers get the smaller format and everything else still gets the original. If your server can't encode a format, that option is greyed out, so you're never misled.

[ON SCREEN: Text overlay: "WebP/AVIF copies are made from JPEG & PNG sources only."]

Note that these copies are only made from JPEG and PNG uploads. GIFs and existing WebP files are still compressed, but don't get an extra copy.

[ON SCREEN: Point to "Strip Image Metadata", "Optimize Thumbnails", "Backup Originals", "Skip Animated GIFs".]

A few more toggles. **Strip Image Metadata** is on by default and removes EXIF data, such as camera details and GPS location. **Optimize Thumbnails** compresses every size WordPress generates. **Skip Animated GIFs** keeps your animations intact. And **Backup Originals** is off by default, but I'd strongly suggest turning it on. It keeps each original so you can restore it later, but only for images optimized *after* you enable it.

[ON SCREEN: Expand the collapsible "Exclusions" card.]

Finally, **Exclusions**. You can skip images by file size, file type, dimensions, filename pattern, attachment ID, or folder. For example, you could exclude anything with "logo" in its name.

[ON SCREEN: Click "Save Changes".]

Save, and you're set.

## 6. Automatic Optimization on Upload

[ON SCREEN: Media → Add New. Drag in the large phone photo. Show the file size in Finder first: ~6 MB.]

Let's see it in action. I'll upload a photo straight off my phone. It's about six megabytes.

[ON SCREEN: Upload completes. Go to Media → Library in list view. Highlight the "Compression" column showing the savings percentage and new size.]

WordPress uploads it and creates all its thumbnail sizes. Then the plugin steps in and optimizes the main image and every one of those sizes. In list view in the Media Library there's now a **Compression** column showing how much was saved.

[ON SCREEN: Point out the stats bar above the library and the filter dropdown: All / Optimized / Not optimized / Errors.]

Above the library there's a stats bar with your totals and space saved, and a filter dropdown, so you can show only optimized images, only unoptimized ones, or anything that hit an error.

[ON SCREEN: Click into the attachment. Show the "Image Optimizer" meta box in the sidebar: status badge, Original / Optimized / Saved / Date, Re-optimize and Restore Original buttons.]

Open any image and you'll find an **Image Optimizer** panel in the sidebar. It shows the status, the original size, the optimized size, how much was saved, and when. There are buttons to **Re-optimize**, for example after you change your compression level, and to **Restore Original** if you kept a backup.

[ON SCREEN: Text overlay: "Never bigger than the original."]

One promise we've built in: if compression doesn't make a file smaller, the plugin keeps the original. Optimizing will never make an image bigger.

## 7. Bulk-Optimizing Your Existing Library

[ON SCREEN: Image Optimizer → Images tab. Show the four KPI tiles: Total Images, Optimized, Pending, Space Saved.]

New uploads are covered. But what about the years of photos already on your site?

That's what the **Images** tab is for. You'll see your total images, how many are optimized, how many are pending, and the total space saved.

[ON SCREEN: Click "Start Bulk Optimization". Progress bar starts moving; KPI tiles update live.]

Click **Start Bulk Optimization**. The plugin works through your library one image at a time, and you can watch the progress bar and stats update live.

[ON SCREEN: Click "Pause", then "Resume". Then close the browser tab.]

You can pause and resume at any time. And here's the really useful part: you can close the tab. The queue is saved, and it keeps going in the background through WordPress's scheduled tasks, so you don't have to babysit a browser window for an hour.

[ON SCREEN: Reopen the Images tab later; show the "Retry N Failed" button and the "Bulk Re-Optimize" button.]

If any images fail, a **Retry Failed** button appears so you can try just those again. And if you change your compression level later, **Bulk Re-Optimize** reprocesses everything with the new settings.

## 8. Safety Guardrails

[ON SCREEN: Simple graphic: a file icon passing through a shield labelled "Image Guard", with checks: "File size", "Dimensions", "Total pixels", "Memory".]

Now, some engineering most people never think about until it bites them. Some image files *look* small but expand into something enormous in memory once they're opened. These are known as decompression bombs, and even an honest but giant panorama can do the same thing. If an optimizer opens one blindly, PHP can run out of memory and crash.

[ON SCREEN: Text overlay listing the limits: "Over 200 MB file", "Over 60 megapixels", "Over 40,000 px on a side", "Not enough PHP memory".]

So before any image is opened, our safety layer, Image Guard, checks the file size, the dimensions and the total pixel count, and estimates how much memory it will need compared with what your server has. If anything looks unsafe, the image is skipped and logged, and nothing crashes.

[ON SCREEN: Text overlay: "Write to temp file → verify → swap in."]

Every optimized file is also written to a temporary file first and only swapped in once it's complete. In plain terms, a weird file won't crash your server, and an interrupted job won't corrupt your images.

## 9. Lazy Loading

[ON SCREEN: Settings → Advanced → toggle "Lazy Load Images" on. Save.]

Back to that **Lazy Load Images** toggle. It's off by default, so switch it on if you want it.

[ON SCREEN: Front end of a long blog post. Dev tools Network tab: images further down only load as you scroll.]

Lazy loading means images further down the page aren't downloaded until the visitor scrolls near them. The first screen appears sooner, and images nobody scrolls to are never downloaded at all.

[ON SCREEN: View source, highlight `loading="eager"` on the first image and `loading="lazy"` on later ones.]

The first image on the page is deliberately loaded straight away, because it's usually your hero image and delaying it would make the page feel slower. And if your theme has already set loading behaviour on an image, we leave it alone.

## 10. The Log Tab & Troubleshooting

[ON SCREEN: Image Optimizer → Log tab. Show the searchable table: Time, Event, Outcome, Details.]

To see what the plugin has been doing, open the **Log** tab. It's a searchable list of optimizations, skipped files and errors.

[ON SCREEN: Scroll down to the "What each event means" reference table, then the "Auto-delete logs after" dropdown: 7 / 14 / 30 days.]

Below it, a reference table explains each type of event and whether you need to act. A skipped animated GIF, for example, needs no action. Old entries can be auto-deleted after 7, 14 or 30 days.

[ON SCREEN: Tools → Site Health → Info tab, showing the Kitgenix Image Optimizer section.]

For troubleshooting, use the Diagnostics tab. The same information appears in WordPress's **Site Health** screen, which also warns you if WebP or AVIF is switched on but your server can't produce them.

## 11. Real-World Example

[ON SCREEN: A photography portfolio site homepage with a large gallery grid.]

Let's put it all together. Meet Sarah, a wedding photographer whose portfolio site has around 500 full-resolution JPEGs built up over the years.

[ON SCREEN: Settings tab: Balanced selected, Convert to WebP on, Backup Originals on, Strip Image Metadata off, a client-proofs folder in "Skip by Directory".]

She checks Diagnostics to confirm her host supports WebP. Then she keeps **Balanced** compression, turns on **Convert to WebP** and **Backup Originals**, and turns **Strip Image Metadata** off to keep her copyright details in her images. She also excludes her client-proofs folder.

[ON SCREEN: Images tab, click "Start Bulk Optimization". Speed-ramp the progress bar. She closes the laptop lid.]

She starts the bulk optimization, closes her laptop and gets on with her day while the queue keeps running in the background.

[ON SCREEN: Images tab later: Optimized count at 100%, "Space Saved" tile showing the total.]

When she comes back, her library is optimized, and the **Space Saved** tile shows exactly how much storage she's recovered. That's less bandwidth for every visitor, and WebP-capable browsers now get the smaller versions automatically.

[ON SCREEN: She uploads a new wedding gallery; the Compression column fills in on each new image.]

From then on, every new gallery she uploads is optimized automatically.

## 12. Tips & Best Practices

[ON SCREEN: Text list building up one tip at a time.]

A few tips before you go:

1. **Turn on Backup Originals before you bulk-optimize**, because restore only works for images that were backed up.
2. **Start with Balanced**, and check a few images before trying Aggressive or Maximum.
3. **Check Diagnostics** before relying on WebP or AVIF.
4. **Changed your compression level?** Use **Bulk Re-Optimize**.
5. **Exclude anything that must stay pixel-perfect**, like logos or print files.
6. **Make sure WP-Cron is working**, because it keeps the bulk queue running in the background.

[ON SCREEN: Small footnote text: "AVIF uploads are optimized but don't yet appear in the Compression column, attachment panel or Recent Images table."]

One honest note: images uploaded as AVIF files are optimized, but they don't yet appear in the Compression column, the attachment panel or the Recent Images list.

[ON SCREEN: The Uninstall card in Settings.]

And if you ever uninstall, your Media Library images are never deleted.

## 13. Recap & Call to Action

[ON SCREEN: Fast recap montage: upload → Compression column → bulk progress bar → picture tag in source → Log tab.]

So, to recap. Kitgenix Image Optimizer:

- automatically compresses and resizes new uploads,
- bulk-optimizes your existing library in the background, with pause, resume and retry,
- creates WebP and AVIF versions with automatic fallback,
- lazy-loads images below the fold,
- and does it all locally, with safety checks, backups and a clear log, with no API keys and no quotas.

[ON SCREEN: WordPress.org plugin page with the "Download" button highlighted. Link in description.]

You can download it free from WordPress.org. The link is in the description.

[ON SCREEN: Subscribe button animation, then the Kitgenix Facebook group and kitgenix.com.]

If this video helped, give it a like and subscribe for more WordPress tutorials from Kitgenix. If the plugin saves you time, a review on WordPress.org really helps us out. And if you have questions, drop them in the comments or join our Facebook group.

Thanks for watching, and enjoy your faster site.

[ON SCREEN: End card with Kitgenix logo and links to related videos.]
