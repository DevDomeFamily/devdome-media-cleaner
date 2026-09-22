=== DevDome Media Cleaner: Remove Unused Images & Media Library Cleanup ===
Contributors: devdome
Tags: media cleaner, delete unused images, remove unused images, unused images, clean media library
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find unused images and orphan images in uploads. Review file sizes, trash to a Recycle Bin, then restore or bulk delete selected images after review.

== Description ==

DevDome Safe Media Cleaner is a WordPress media cleaner and image cleaner that finds unused images, orphan files, and missing media in your Media Library and uploads folder. Review the results, move selected files to a protected Recycle Bin, and check your site before restoring files or permanently deleting them.

= Find unused images and orphan files =

Scan your Media Library for images with no detected reference on your website. The scanner checks common WordPress content and settings before marking an image as unused.

A disk scan finds orphan images and files in the uploads folder that have no matching Media Library record. These can accumulate after migrations, deleted plugins, failed uploads, manual file transfers, or years of website changes.

Review orphan media individually or in bulk. The disk scan covers images, including thumbnails left behind by deleted images; other file types are left alone.

= Remove unused images with review and restore =

Image cleanup starts with a review, followed by a move to the Recycle Bin. You choose when to delete unused images permanently.

The plugin includes these safeguards: The media recycle bin lets you restore cleaned files to their original locations.

* **Recycle Bin first:** cleaned files are moved instead of immediately deleted.
* **Last-second re-check:** every selected file is checked again immediately before it moves.
* **Protected by default:** recent uploads and uncertain files are not automatically selected.
* **Clear review status:** files are marked Safe to remove, Needs manual review, or Protected, with the reason shown.
* **ZIP backups:** create, download, upload, and restore media backups before cleanup.

No scanner can detect every custom or hard-coded image reference. Review the results and check your website while the cleaned files can still be restored.

= Clean media library workflow =

Use the plugin for media-library maintenance when you need to clean up images and review storage use.

1. **Scan:** scan the WordPress Media Library and uploads folder in resumable background batches.
2. **Review:** search, filter, sort, and inspect unused images, orphan files, and missing media.
3. **Clean:** move selected media to the protected Recycle Bin.
4. **Restore or delete:** restore files to their original locations with one click, or permanently delete them after checking your website.

Nothing is removed until you review the results and choose what to clean. Scheduled scans do not automatically delete media.

= Review images and disk usage =

The plugin provides a media manager for cleanup review, with a visual grid or list of scan results.

You can:

* Search by filename.
* Filter by cleanup status.
* Sort by file size, dimensions, or age.
* Select individual files or clean media in bulk.
* View thumbnails, dimensions, file size, upload date, and cleanup reason.
* Export scan results to CSV.

Sort unused images and orphan files by size to find those consuming the most disk space. The dashboard shows the storage represented by flagged files.

To bulk delete images, review your selection, move it to the Recycle Bin, and confirm permanent deletion after checking your site. The same review-first process applies when you bulk delete media identified by the scanner.

= WordPress image references checked =

The scanner checks these common reference locations:

* Posts, pages, custom post types, and revisions.
* Reusable and synced blocks, Gutenberg blocks, featured images, and galleries.
* `srcset`, responsive images, and lazy-loading attributes.
* CSS backgrounds.
* Widgets and navigation menus.
* Site logo, site icon, and WordPress options.

Generated thumbnail sizes, scaled images, and edited copies are matched to their original Media Library attachment.

= Page builders, WooCommerce, and plugin support =

The scanner checks data used by these WordPress tools:

* **Page builders:** Elementor, Divi, Beaver Builder, WPBakery, Bricks, Oxygen, Kadence, GenerateBlocks, Spectra, SeedProd, and Thrive.
* **WooCommerce:** product images, product galleries, variations, and category images.
* **Custom fields and SEO plugins:** ACF, Meta Box, Yoast SEO, Rank Math, and AIOSEO.

= Background jobs and large libraries =

Media libraries can contain thousands of images. Scans run in small, resumable background batches to reduce memory usage and timeout risk. Pause and resume a scan without starting over.

Scans, cleanups, backups, and restores run on the server and continue after you close the tab. Reopen the plugin page to see progress.

On WordPress multisite, each site keeps its own scans, Recycle Bin, backups, and settings.

= Free features and settings =

These features are free and unlimited:

* Unused image scanning, orphan file detection, and missing media detection.
* Media review and reports.
* Recycle Bin and one-click restore.
* ZIP media backups.
* Scheduled scans.
* CSV export.
* WP-CLI commands.

Settings include scheduled scans, retention, protection rules, and optional DevDome Monitoring. No DevDome account is required to scan, review, report on, back up, remove, restore, or delete unused media.

= Optional DevDome Monitoring =

Connect a free DevDome account and enable Monitoring if you want to track media-library growth across connected WordPress sites.

After each scan, aggregate media statistics can be sent to DevDome. Monitoring tracks changes and sends alerts when unused media exceeds a threshold you choose.

No media files, filenames, image URLs, or visitor data are sent.

= AI and Agent Support =

On WordPress 6.9 and newer, DevDome Safe Media Cleaner registers WordPress Abilities covering the whole plugin:

* Media health summary, scan results with every filter, and filter options.
* Job progress and control, and scans of the Media Library, disk, and preview.
* One-click cleanup and moving chosen files to the Recycle Bin.
* Batches and their files, restore, permanent delete, protect, and ignore.
* Backups: list, create, restore, and delete.
* Every setting and the error log.

Compatible AI agents and MCP clients, for example through the official WordPress MCP Adapter, run the same code as the plugin screens under the same capability checks. Irreversible actions need an explicit confirmation.

== External services ==

**Plugin catalog (`devdome.com`).** The DevDome Dashboard inside wp-admin fetches the list of DevDome plugins (names, descriptions, logos, links, WordPress.org slugs) from `https://devdome.com/wp-plugins/catalog.json` at most once every 12 hours, so the list stays current. Only the bundled core version is sent in the request; no site or visitor data. Service provider: DevDome. Terms: https://devdome.com/terms-of-service Privacy policy: https://devdome.com/privacy-policy

All scanning, classification, Recycle Bin, backup, and restore features run on your own server. Account connection and Monitoring require explicit opt-in. The plugin catalog fetch and manually submitted error reports are described separately here.

1. **DevDome account connection (`devdome.com`, `api.devdome.com` and `analytics.devdome.com`) - optional.**

Connecting an account is required only for optional DevDome Monitoring. When you start the connection, `devdome.com` opens in your browser. After approval, the plugin stores your public DevDome Account ID and a site token, then sends the site token to `api.devdome.com` to verify the connection; the connection handshake itself (start and claim) talks to `analytics.devdome.com`. The service returns the account email displayed in the plugin settings.

The Account ID, site domain, and site token are transmitted. If you disconnect, the site domain and site token are sent once to unlink the site. When you connect from the DevDome Tools dashboard, whose Connect card states this before you press the button, those account checks also carry the slug and version of each active DevDome plugin on the site plus the bundled DevDome library, WordPress and PHP versions, so your DevDome account can show your sites and their DevDome plugins for support and update notices. Nothing about other plugins, users, email addresses, content or visitors is included. Sites connected before this was introduced, and sites connected from a button that does not show that text, do not send the list. Disconnecting stops the plugin list.

No media files, filenames, private image URLs, or visitor data are sent.

Service provider: DevDome  
Terms: https://devdome.com/terms-of-service  
Privacy policy: https://devdome.com/privacy-policy

2. **DevDome Monitoring (`api.devdome.com`) - optional and opt-in.**

When Monitoring is enabled, the plugin sends aggregate scan statistics after each scan: site domain, site token, total media count and size, unused media count and size, orphaned file count, alert threshold, selected email frequency, and the link to this plugin's wp-admin screen.

DevDome stores this history, displays it in the media-health dashboard, tracks changes across scans and connected sites, and sends alert emails when the chosen threshold is exceeded.

No media files, filenames, image URLs, or visitor data are sent.

Service provider: DevDome  
Terms: https://devdome.com/terms-of-service  
Privacy policy: https://devdome.com/privacy-policy

**Dormant endpoints in the bundled DevDome core**

The bundled shared library references these endpoints, but they are disabled and are not contacted by the WordPress.org build:

* `https://api.devdome.com/plugin-updates/` - used by the self-hosted DevDome suite installer. Updates and installs for this build come only from WordPress.org.
* `https://api.devdome.com/media-cleaner/metrics` - used by the DevDome-distributed build for aggregate product metrics. It does not run in the WordPress.org build.

Beyond the plugin catalog fetch and the error reports described below, no outbound request is made unless you explicitly connect a DevDome account. Monitoring statistics are sent only after you also enable DevDome Monitoring.

3. **Error reports (`devdome.com`) - only when you press Report this error.** The button on an error message sends the error text, the plugin, WordPress and PHP versions, the screen you were on, your site address and your admin email (so support can reply) to `https://devdome.com/api/plugin/error-report`. Nothing is sent unless you press it. Terms: https://devdome.com/terms-of-service Privacy: https://devdome.com/privacy-policy

== Privacy ==

* **Media files stay local.** Scanning, classification, cleanup, backup, and restore run on your server.
* **Recycle Bin files stay local.** They are stored in `/wp-content/uploads/devdome-safe-trash/`, protected against public access by the bundled .htaccess (Apache) and web.config (IIS) rules; on nginx add a deny rule for that folder yourself.
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

= What is the Recycle Bin, and can I undo a cleanup? =

The Recycle Bin is a protected local folder that holds cleaned files before permanent deletion. The first cleanup action moves selected files there instead of permanently deleting them.

Each cleanup batch includes a manifest and checksums. You can restore cleaned files to their original locations with one click while they remain in the Recycle Bin.

= What is the difference between unused images and orphaned files, and does it clean up the uploads folder? =

Unused images have a Media Library record but no detected reference on the site. Orphaned files exist in the uploads folder without a matching Media Library record.

Yes, the disk scan walks wp-content/uploads and lists orphaned files: images with no Media Library record, including thumbnails left behind by deleted images. Other file types are left alone.

= Does it find duplicate images? =

Not in this version. An identical copy is listed like any other unused or orphan file. A duplicate view that groups copies and suggests which one to keep is planned.

= How can I free up disk space? =

Run a scan, open Review, and sort by file size. Review the largest unused images and orphaned files first. The dashboard shows the amount of storage represented by the flagged files.

Moving files to the local Recycle Bin keeps them available for restoration. Check your site before confirming permanent deletion to reclaim that space.

= Does it support page builders and WooCommerce? =

Yes. It checks data used by Elementor, Divi, Beaver Builder, WPBakery, Bricks, Oxygen, Kadence, GenerateBlocks, Spectra, SeedProd, Thrive, WooCommerce, ACF, Meta Box, Yoast SEO, Rank Math, and AIOSEO.

= Will scanning slow down my site, and does cleanup keep running if I leave the page? =

Scanning runs in small background batches to reduce memory use and timeout risk. Large scans can be paused and resumed.

Yes, scans, cleanups, backups, and restores run on the server in background batches and continue after you close the tab. Reopen the plugin page to see the progress.

= Do I need a DevDome account? =

No. Scanning, review, reports, backups, cleanup, the Recycle Bin, and restore work without an account. A free DevDome account is required only for optional DevDome Monitoring.

= Does it support WordPress multisite? =

Yes. Each site keeps its own scans, Recycle Bin, backups, and settings.

= Does it delete unused images automatically? =

No. Nothing is removed until you review the results and choose what to clean. The first step is always the Recycle Bin, so cleanup can be undone until you confirm permanent deletion.

= What happens to the Recycle Bin when I uninstall? =

Restore or permanently delete every batch first. Uninstalling removes the plugin tables, so files still in the Recycle Bin folder can no longer be restored from the screen; the folder itself is left in place and the hidden attachments become visible again.

= Is unattached media safe to delete? =

Not necessarily. An image can be used on your site without being attached to a particular post. The scanner checks image references before classifying files. Review the status and reason, keep uncertain files for manual review, and check your site before permanently deleting anything from the Recycle Bin.

== Screenshots ==

1. Overview: view Media Library and Disk Storage cleanup opportunities, storage totals, and scan actions.
2. Media Library review: inspect unused images with status labels, reasons, filters, and bulk selection.
3. Disk Storage review: find orphaned files in the uploads folder that have no Media Library record.
4. Recycle Bin: review cleanup batches and restore cleaned files with one click.
5. Backup and Restore: create, upload, download, and restore ZIP media backups.
6. Settings: configure scheduled scans, retention, protection rules, and optional DevDome Monitoring.

== Changelog ==

= 1.1.1 =
* Bundled DevDome library 1.7.6: if you connect a DevDome account from the DevDome Tools dashboard, the Connect card now says exactly what is shared, including the list of active DevDome plugins and their versions. Sites that were already connected, and sites that never connect, send nothing new. See External services.
* Listing text rewritten: new title, short description, tags and a restructured description. No change to how the plugin works.

= 1.1.0 =
* WordPress Abilities API: 22 abilities covering every feature (summary, scan results and filters, scans, cleanup, Recycle Bin batches, restore, permanent delete, protect, backups, settings, error log) for AI agents and MCP clients on WordPress 6.9 and newer.
* Updates now work when the plugin folder belongs to another system user (shared DevDome core 1.7.4): folders installed from a root shell or by an AI agent no longer fail to update through the hub, the Plugins screen, bulk updates, uploads or automatic updates.

= 1.0.10 =
* Connect fix (shared DevDome core 1.6.6): the connect claim now waits up to 30 seconds and keeps the handshake for 20 minutes so a refresh retries it, the DevDome hub shows why a connect failed with a Try again link, and the verify file is served through a query form for hosts that answer /.well-known/ before WordPress.

= 1.0.9 =
* First tab is now called Overview, in line with the other DevDome plugins. Old links to the Dashboard tab still open it.
* Settings: every option now shows a one line hint under the control, with the info icon holding the full explanation, the same layout as DevDome Malware Scanner.
* DevDome Dashboard: installing another DevDome plugin from the dashboard no longer activates it, you activate it yourself from its card. Output escaping tightened.

= 1.0.8 =
* DevDome Dashboard: plugin list, descriptions, logos and versions now come from devdome.com, one-click install of DevDome plugins from WordPress.org, Docs link and Fix buttons, Activate stays on the dashboard.

= 1.0.7 =
* Bundled DevDome core updated to 1.6.2: the DevDome Dashboard shows the new Affiliate Manager logo.
