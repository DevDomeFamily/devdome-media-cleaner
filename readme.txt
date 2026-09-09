=== DevDome Media Cleaner – Remove Unused Images, Orphan Images & Duplicates ===
Contributors: devdome
Tags: media cleaner, unused images, unused media, duplicate images, orphaned images
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Image cleaner for WordPress. Find and delete unused images, orphan files, and duplicate media safely with Recycle Bin restore.

== Description ==

= WordPress Media Cleaner & Image Cleaner =

DevDome Safe Media Cleaner is a WordPress media cleaner and image cleaner for finding and safely removing unused images, orphan files, duplicate images, and missing media from your Media Library and uploads folder.

Find unused images, review orphan images and files, detect duplicate media, and clean your WordPress Media Library without permanently deleting files immediately.

Selected media moves to a protected Recycle Bin first, so you can check your site, restore cleaned files with one click, and permanently delete them only when you are ready.

= Find & Remove Unused Images =

Scan your WordPress Media Library for images that are no longer referenced by your website.

DevDome Safe Media Cleaner checks common WordPress content and settings before marking an image as unused, including posts, pages, custom post types, builders, widgets, menus, featured images, galleries, Gutenberg blocks, CSS backgrounds, responsive images, and WordPress options.

Use the review screen to inspect unused images before deleting or moving anything.

= Find Orphan Images & Files =

Find orphan files that exist inside your WordPress uploads folder but no longer have a matching Media Library record.

Orphan files can accumulate after migrations, deleted plugins, failed uploads, manual file transfers, or years of website changes.

Review orphan media individually or in bulk and move unwanted files safely to the Recycle Bin.

= Find Duplicate Images & Media =

Detect exact duplicate files in your WordPress media library.

The media cleaner groups duplicate images and helps you identify which copy to keep before cleaning the duplicates.

Duplicate files are never deleted automatically.

= Clean Your WordPress Media Library Safely =

Media cleanup should not mean permanently deleting files without a way back.

DevDome Safe Media Cleaner uses several safeguards:

* **Recycle Bin first:** cleaned files are moved instead of immediately deleted.
* **Last-second re-check:** every selected file is checked again immediately before it moves.
* **Protected by default:** recent uploads and uncertain files are not automatically selected.
* **Clear review status:** files are marked Safe to remove, Needs manual review, or Protected with the reason shown.
* **ZIP backups:** create, download, upload, and restore media backups before cleanup.

= How Media Cleanup Works =

1. **Scan:** Scan the WordPress Media Library and uploads folder in resumable background batches.
2. **Review:** Search, filter, sort, and inspect unused images, orphan files, duplicates, and missing media.
3. **Clean:** Move selected media to the protected Recycle Bin.
4. **Restore or delete:** Restore files with one click or permanently delete them after checking your website.

= Detailed Image & Media Review =

Review scan results in a visual grid or list.

You can:

* Search by filename
* Filter by cleanup status
* Sort by file size, dimensions, or age
* Select individual files or clean media in bulk
* View thumbnails, dimensions, file size, upload date, and cleanup reason
* Export scan results to CSV

Sorting unused images and orphan files by size makes it easy to find the media consuming the most disk space.

= Checks Common WordPress Image References =

Before identifying media as unused, the scanner checks common WordPress image references including:

* Posts and pages
* Custom post types
* Revisions
* Reusable and synced blocks
* Featured images
* Galleries
* Gutenberg blocks
* `srcset` and responsive images
* Lazy-loading attributes
* CSS backgrounds
* Widgets
* Navigation menus
* Site logo and site icon
* WordPress options

= Page Builder, WooCommerce & Plugin Support =

The scanner also checks data used by popular WordPress tools.

**Page builders**

Elementor, Divi, Beaver Builder, WPBakery, Bricks, Oxygen, Kadence, GenerateBlocks, Spectra, SeedProd, and Thrive.

**WooCommerce**

Product images, product galleries, variations, and category images.

**Custom fields and SEO plugins**

ACF, Meta Box, Yoast SEO, Rank Math, and AIOSEO.

**Generated WordPress images**

Thumbnail sizes, scaled images, and edited copies are matched to their original Media Library attachment.

= Built for Large Media Libraries =

Large WordPress media libraries can contain thousands of images.

Scans run in small, resumable background batches to reduce memory usage and timeout risk.

You can pause and resume a scan without starting over.

= Free Media Cleaner Features =

The following features are free and unlimited:

* Unused image scanning
* Orphan file detection
* Duplicate image detection
* Missing media detection
* Media review and reports
* Recycle Bin
* One-click restore
* ZIP media backups
* Scheduled scans
* CSV export
* WP-CLI commands

No DevDome account is required to scan, review, remove, restore, or delete unused media.

= Optional DevDome Monitoring =

You can optionally connect a free DevDome account and enable Monitoring.

After each scan, aggregate media statistics can be sent to DevDome so you can track media-library growth across connected WordPress sites and receive alerts when unused media exceeds a threshold you choose.

No media files, filenames, image URLs, or visitor data are sent.

== External services ==

**Plugin catalog (`devdome.com`).** The DevDome Dashboard inside wp-admin fetches the list of DevDome plugins (names, descriptions, logos, links, WordPress.org slugs) from `https://devdome.com/wp-plugins/catalog.json` at most once every 12 hours, so the list stays current. Only the bundled core version is sent in the request; no site or visitor data. Service provider: DevDome. Terms: https://devdome.com/terms-of-service Privacy policy: https://devdome.com/privacy-policy

All scanning, classification, Recycle Bin, backup, and restore features run on your own server. The plugin connects to DevDome only after explicit opt-in.

1. **DevDome account connection (`devdome.com` and `api.devdome.com`) - optional.**

Connecting an account is required only for optional DevDome Monitoring. When you start the connection, `devdome.com` opens in your browser. After approval, the plugin stores your public DevDome Account ID and a site token, then sends the site token to `api.devdome.com` to verify the connection. The service returns the account email displayed in the plugin settings.

The Account ID, site domain, and site token are transmitted. If you disconnect, the site domain and site token are sent once to unlink the site.

No media files, filenames, private image URLs, or visitor data are sent.

Service provider: DevDome  
Terms: https://devdome.com/terms-of-service  
Privacy policy: https://devdome.com/privacy-policy

2. **DevDome Monitoring (`api.devdome.com`) - optional and opt-in.**

When Monitoring is enabled, the plugin sends aggregate scan statistics after each scan: site domain, site token, total media count and size, unused media count and size, orphaned file count, alert threshold, and selected email frequency.

DevDome stores this history, displays it in the media-health dashboard, tracks changes across scans and connected sites, and sends alert emails when the chosen threshold is exceeded.

No media files, filenames, image URLs, or visitor data are sent.

Service provider: DevDome  
Terms: https://devdome.com/terms-of-service  
Privacy policy: https://devdome.com/privacy-policy

**Dormant endpoints in the bundled DevDome core**

The bundled shared library references these endpoints, but they are disabled and are not contacted by the WordPress.org build:

* `https://api.devdome.com/plugin-updates/` - used by the self-hosted DevDome suite installer. Updates and installs for this build come only from WordPress.org.
* `https://api.devdome.com/media-cleaner/metrics` - used by the DevDome-distributed build for aggregate product metrics. It does not run in the WordPress.org build.

No outbound request is made unless you explicitly connect a DevDome account. Monitoring statistics are sent only after you also enable DevDome Monitoring.

== Privacy ==

* **Media files stay local.** Scanning, classification, cleanup, backup, and restore run on your server.
* **Recycle Bin files stay local.** They are stored in `/wp-content/uploads/devdome-safe-trash/`, which is protected against public access during the review period.
* **No cookies** are set by the plugin.
* **No product metrics** are sent by the WordPress.org build.
* **Optional Monitoring** sends only the aggregate statistics listed in the External services section after explicit opt-in.

== Installation ==

1. Install and activate the plugin from the WordPress Plugins screen.
2. Open **DevDome > Safe Media Cleaner** and select **Scan Media Library**.
3. Review the results and move selected files to the **Recycle Bin**.
4. Check your site, then restore anything you need or confirm permanent deletion.

== Frequently Asked Questions ==

= Can a media cleaner identify every image reference? =

No scanner can guarantee detection of every custom or hard-coded reference. DevDome Safe Media Cleaner checks many common WordPress locations, page builders, WooCommerce data, custom fields, SEO settings, CSS, and responsive image references. It also re-checks each selected file immediately before moving it.

Uncertain files are marked Needs manual review and are not selected automatically. Selected files move to the Recycle Bin first so they can be restored.

= Can I undo a cleanup? =

Yes. The first cleanup action moves selected files to the Recycle Bin instead of permanently deleting them. Cleaned files can be restored to their original locations with one click.

= What is the difference between unused images and orphaned files? =

Unused images have a Media Library record but no detected reference on the site. Orphaned files exist in the uploads folder without a matching Media Library record.

= Does it find duplicate images? =

Yes. The plugin detects exact duplicate files and suggests a copy to keep. Duplicates are never deleted automatically.

= How can I free up disk space? =

Run a scan, open Review, and sort by file size. Review the largest unused images and orphaned files first. The dashboard shows the amount of storage represented by the flagged files.

= Does it support page builders and WooCommerce? =

Yes. It checks data used by Elementor, Divi, Beaver Builder, WPBakery, Bricks, Oxygen, Kadence, GenerateBlocks, Spectra, SeedProd, Thrive, WooCommerce, ACF, Meta Box, Yoast SEO, Rank Math, and AIOSEO.

= Will scanning slow down my site? =

Scanning runs in small background batches to reduce memory use and timeout risk. Large scans can be paused and resumed.

= Do I need a DevDome account? =

No. Scanning, review, reports, backups, cleanup, the Recycle Bin, and restore work without an account. A free DevDome account is required only for optional DevDome Monitoring.

= Does it support WordPress multisite? =

Yes. Each site keeps its own scans, Recycle Bin, backups, and settings.

= What is the Recycle Bin? =

It is a protected local folder used to hold cleaned files before permanent deletion. Each cleanup batch includes a manifest and checksums so files can be restored to their original locations.

= Does it delete unused images automatically? =

No. Nothing is removed until you review the results and choose what to clean. The first step is always the Recycle Bin, so every cleanup can be undone.

= Does it clean up the uploads folder? =

Yes. The disk scan walks wp-content/uploads and lists orphaned files: images with no Media Library record, including thumbnails left behind by deleted images. Other file types are left alone.

= Does the cleanup keep running if I leave the page? =

Yes. Scans, cleanups, backups, and restores run on the server in background batches and continue after you close the tab. Reopen the plugin page to see the progress.

== Screenshots ==

1. Overview: view Media Library and Disk Storage cleanup opportunities, storage totals, and scan actions.
2. Media Library review: inspect unused images with status labels, reasons, filters, and bulk selection.
3. Disk Storage review: find orphaned files in the uploads folder that have no Media Library record.
4. Recycle Bin: review cleanup batches and restore cleaned files with one click.
5. Backup and Restore: create, upload, download, and restore ZIP media backups.
6. Settings: configure scheduled scans, retention, protection rules, and optional DevDome Monitoring.

== Changelog ==

= 1.0.9 =
* First tab is now called Overview, in line with the other DevDome plugins. Old links to the Dashboard tab still open it.
* Settings: every option now shows a one line hint under the control, with the info icon holding the full explanation, the same layout as DevDome Malware Scanner.
* DevDome Dashboard: installing another DevDome plugin from the dashboard no longer activates it, you activate it yourself from its card. Output escaping tightened.

= 1.0.8 =
* DevDome Dashboard: plugin list, descriptions, logos and versions now come from devdome.com, one-click install of DevDome plugins from WordPress.org, Docs link and Fix buttons, Activate stays on the dashboard.

= 1.0.7 =
* Bundled DevDome core updated to 1.6.2: the DevDome Dashboard shows the new Affiliate Manager logo.

= 1.0.6 =
* Scans, cleanups, backups and restores now keep running on the server after you leave the page or switch tabs. Before, a job on a quiet site could stall until you came back.
* A restore or delete that cannot move any file (folder not writable) now stops with a clear message instead of running forever.
* Bundled DevDome core updated to 1.6.1. Screenshots are no longer packed into the download (1.1 MB smaller).

= 1.0.5 =
* A site that has never been scanned now shows a dash instead of a perfect score, on the plugin page and in the DevDome Dashboard.
* DevDome Monitoring settings now show a Connect button above the disabled toggle while no account is connected, instead of a dead checkbox.
* Removed the legacy connect return flow; connecting uses the suite's one-click signed-in flow shared by every DevDome plugin.
* The DevDome Dashboard (suite hub) was redesigned: cleaner cards, your account email and plan on the overview, and update buttons shown only when an update really exists.
* One button system across the suite: the same Connect button and the same Save Settings button in every DevDome plugin.

= 1.0.4 =
* Unified DevDome suite icons and updated the suite hub with one-click installs for WordPress.org plugins.

= 1.0.3 =
* Initial WordPress.org release.
