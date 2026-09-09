<?php
/**
 * Filesystem scan + orphan/missing detection (spec §detection method 8).
 *
 * Memory-safe recursive walk of /uploads (excluding the Recycle Bin folder), cross-referenced
 * against the known attachment file set, to find:
 *   - orphan files: a file on disk with no Media Library record, AFTER excluding registered
 *     thumbnail/size variants + scaled/-rotated/-edited derivatives of known attachments (a legit
 *     thumbnail is NEVER flagged as orphan)
 *   - temp/backup copies: -e<digits>, .bak, ~, *-copy (surfaced as orphans with a reason)
 * Missing files are detected during the attachment scan (scanner.php) — an attachment row whose
 * original file is gone. This module adds the disk-side orphan pass, chunked + resumable via a
 * directory cursor, with disk-space + writability pre-checks.
 */

defined('ABSPATH') || exit;

/**
 * Run ONE bounded, RESUMABLE slice of the orphan filesystem pass for a scan.
 *
 * The uploads tree is walked from the top each call (the iterator order is stable within a run),
 * but we SKIP the first $offset files and process at most $max_files this slice, returning the new
 * cursor + a done flag. The queue persists the cursor on the job and keeps calling until done, so
 * the orphan pass scales to the spec's 100,000+ file target instead of silently capping. Skipped
 * (already-counted) files are cheap to walk past — no filesize/hash/getimagesize work is done for
 * them. Designed to run after the attachment scan completes so the known-file set is authoritative.
 *
 * @param int $scan_id
 * @param int $max_files files to PROCESS this slice (the per-tick budget)
 * @param int $offset    files already processed in earlier slices (the cursor)
 * @return array{orphans:int, scanned:int, offset:int, done:bool}
 */
function devdsame_scan_filesystem($scan_id, $max_files = 5000, $offset = 0)
{
    $basedir = devdsame_uploads_basedir();
    if (!is_dir($basedir) || !is_readable($basedir)) {
        return array('orphans' => 0, 'scanned' => 0, 'bytes' => 0, 'offset' => (int) $offset, 'done' => true);
    }

    $known = devdsame_known_attachment_files();
    $trash_real = realpath(devdsame_safe_trash_dir());
    $trash_real = $trash_real ? str_replace('\\', '/', $trash_real) : '';

    global $wpdb;
    $items_table = $wpdb->prefix . 'devdsame_scan_items';
    $now = current_time('mysql');

    $orphans = 0;
    $bytes = 0;              // bytes of the image files processed this slice
    $scanned = 0;            // files PROCESSED this slice
    $seen = 0;              // candidate files WALKED this slice (after the skip/guard filters)
    $offset = max(0, (int) $offset);
    $done = true;

    foreach (devdsame_iterate_files($basedir) as $abs) {
        $norm = str_replace('\\', '/', $abs);

        // Never descend into / flag the Recycle Bin folder.
        if ($trash_real !== '' && strpos($norm, $trash_real) === 0) {
            continue;
        }
        // Skip our own protective files.
        $bn = wp_basename($norm);
        if ($bn === 'index.php' || $bn === '.htaccess' || $bn === 'web.config') {
            continue;
        }
        // Only IMAGE files can be image orphans. Everything else in /uploads (plugin caches,
        // logs, generated CSS, videos, PDFs, fonts) is none of our business — flagging a
        // WooCommerce log or an Elementor CSS file as an "orphan image" is how a site with 843
        // images ends up claiming 6,387 orphans.
        $ext = strtolower((string) pathinfo($bn, PATHINFO_EXTENSION));
        if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'svg', 'heic', 'tif', 'tiff'), true)) {
            continue;
        }
        // Skip folders owned by other plugins/tools — their generated images are managed by
        // them, not by the Media Library.
        $rel_probe = devdsame_path_to_relative($abs);
        $first_seg = strtolower((string) strtok((string) $rel_probe, '/'));
        if (in_array($first_seg, devdsame_orphan_skip_folders(), true)) {
            continue;
        }

        // Resume cursor: walk (cheaply) past files counted in earlier slices.
        $seen++;
        if ($seen <= $offset) {
            continue;
        }

        // Per-slice budget reached — there is more to do, resume next tick.
        if ($scanned >= $max_files) {
            $done = false;
            break;
        }

        $scanned++;
        $bytes += (int) @filesize($abs);
        $rel = devdsame_path_to_relative($abs);
        if ($rel === '') {
            continue;
        }
        $key = strtolower($rel);

        // Known attachment file (original, size variant, scaled/rotated/edited) -> not orphan.
        if (isset($known[$key])) {
            continue;
        }

        // Is it a registered thumbnail/size variant of a known original whose stem we know?
        // Strip a -WxH or -eTIMESTAMP suffix and re-check the base name.
        if (devdsame_is_variant_of_known($rel, $known)) {
            continue;
        }

        // It's an orphan (temp/backup copies and stale thumbnails included).
        $reason = 'orphan_file';

        $size = is_file($abs) ? (int) @filesize($abs) : 0;
        $file_url = devdsame_uploads_baseurl() . '/' . $rel;
        $hash = devdsame_file_hash($abs);
        $dims = @getimagesize($abs);
        $w = (is_array($dims) && isset($dims[0])) ? (int) $dims[0] : 0;
        $h = (is_array($dims) && isset($dims[1])) ? (int) $dims[1] : 0;
        $mime = (is_array($dims) && isset($dims['mime'])) ? (string) $dims['mime'] : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items insert for an orphan file.
        $wpdb->insert($items_table, array(
            'scan_id'          => $scan_id,
            'attachment_id'    => 0,
            'file_path'        => $abs,
            'file_url'         => $file_url,
            'file_hash'        => $hash,
            'file_size'        => $size,
            'width'            => $w,
            'height'           => $h,
            'mime_type'        => $mime,
            'upload_date'      => null,
            'status'           => 'orphan',
            'confidence'       => 60,
            'reason_code'      => $reason,
            'references_found' => 0,
            'is_selected'      => 0,
            'created_at'       => $now,
        ), array('%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s'));
        $orphans++;
    }

    return array('orphans' => $orphans, 'scanned' => $scanned, 'bytes' => $bytes, 'offset' => $offset + $scanned, 'done' => $done);
}

/**
 * Top-level /uploads folders whose images belong to OTHER plugins (caches, generated assets,
 * logs, backups) and must never be flagged as Media Library orphans. Filterable.
 */
function devdsame_orphan_skip_folders()
{
    $folders = array(
        'elementor', 'et-cache', 'wc-logs', 'woocommerce_uploads', 'wp-clone', 'backup',
        'backups', 'backups-dup-lite', 'backwpup', 'updraft', 'ai1wm-backups', 'aiowps_backups',
        'cache', 'lscache', 'litespeed', 'wpforms', 'gravity_forms', 'formidable', 'pum',
        'oxygen', 'bb-plugin', 'brizy', 'uag-plugin', 'astra-addon', 'kadence_forms',
        'sucuri', 'wflogs', 'wp-defender', 'snapshots', 'devdome-safe-trash', 'devdome-backups',
        'sitepress', 'wpml', 'smush-webp', 'wpo', 'wp-optimize',
    );
    return (array) apply_filters('devdsame_orphan_skip_folders', $folders);
}

/**
 * Memory-safe generator over every regular file under $dir, depth-first, skipping symlinks.
 * Uses RecursiveDirectoryIterator + yield so we never build a giant array.
 */
function devdsame_iterate_files($dir)
{
    try {
        $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS;
        $dirIter = new RecursiveDirectoryIterator($dir, $flags);
        $iter = new RecursiveIteratorIterator($dirIter, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
    } catch (Exception $e) {
        return;
    }
    foreach ($iter as $fileinfo) {
        if (!$fileinfo->isFile() || $fileinfo->isLink()) {
            continue;
        }
        yield $fileinfo->getPathname();
    }
}

/**
 * Build a lowercase set [relative-path => true] of every file owned by every image
 * attachment (original + all size variants + scaled/rotated/edited derivatives). Chunked
 * so it never loads all metadata at once. Cached per request.
 */
function devdsame_known_attachment_files()
{
    if (isset($GLOBALS['devdsame_known_files']) && is_array($GLOBALS['devdsame_known_files'])) {
        return $GLOBALS['devdsame_known_files'];
    }
    global $wpdb;
    $known = array();
    $last = 0;
    do {
        // ALL attachments, not just images: a PDF/video/audio attachment's files are known
        // library files too — without them every uploaded PDF gets flagged as an orphan.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked scan over core posts for attachment IDs; bounds bound via prepare.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
            $last,
            500
        ));
        if (!$ids) {
            break;
        }
        foreach ($ids as $id) {
            $last = (int) $id;
            $files = devdsame_attachment_files((int) $id);
            foreach ($files['rel'] as $rel) {
                $known[strtolower($rel)] = true;
            }
            clean_post_cache((int) $id);
        }
    } while (true);

    $GLOBALS['devdsame_known_files'] = $known;
    return $known;
}

/**
 * Is $rel a size/edited/next-gen variant of a known original? Strips a trailing -WxH or
 * -eTIMESTAMP token and optimizer .webp/.avif suffixes from the basename and checks whether
 * a matching base file is known.
 */
function devdsame_is_variant_of_known($rel, $known)
{
    $rel = strtolower($rel);
    $dir = dirname($rel);
    $dir = ($dir === '.' || $dir === '') ? '' : $dir . '/';
    $name = wp_basename($rel);

    // Optimizer sibling, appended style: image.jpg.webp / image.png.avif -> image.jpg.
    if (preg_match('/^(.+\.(?:jpe?g|png|gif|bmp|tiff?))\.(?:webp|avif)$/', $name, $m)) {
        if (isset($known[$dir . $m[1]]) || devdsame_is_variant_of_known($dir . $m[1], $known)) {
            return true;
        }
    }

    // -WxH variant -> base name.
    $stripped = preg_replace('/-\d+x\d+(?=\.\w+$)/', '', $name);
    // -eTIMESTAMP edited derivative -> base name.
    $stripped = preg_replace('/-e\d{8,}(?=(\-\d+x\d+)?\.\w+$)/', '', $stripped);

    if ($stripped !== $name) {
        $candidate = $dir . $stripped;
        if (isset($known[$candidate])) {
            return true;
        }
        // Also try with a -scaled base (originals stored as name-scaled.ext).
        $ext_pos = strrpos($stripped, '.');
        if ($ext_pos !== false) {
            $scaled = substr($stripped, 0, $ext_pos) . '-scaled' . substr($stripped, $ext_pos);
            if (isset($known[$dir . $scaled])) {
                return true;
            }
        }
    }

    // Optimizer sibling, replaced-extension style: image.webp / image-300x200.webp where
    // image.jpg|jpeg|png|gif is a known original (Imagify/EWWW/ShortPixel conversions).
    $probe = $stripped !== $name ? $stripped : $name;
    if (preg_match('/^(.+)\.(?:webp|avif)$/', $probe, $m)) {
        foreach (array('jpg', 'jpeg', 'png', 'gif') as $alt) {
            if (isset($known[$dir . $m[1] . '.' . $alt])) {
                return true;
            }
            if (isset($known[$dir . $m[1] . '-scaled.' . $alt])) {
                return true;
            }
        }
    }
    return false;
}

/** Pre-check: is the uploads dir writable and is there headroom on disk? */
function devdsame_filesystem_health()
{
    $basedir = devdsame_uploads_basedir();
    $trash = devdsame_safe_trash_dir();
    $writable = wp_is_writable($basedir) && (is_dir($trash) ? wp_is_writable($trash) : wp_is_writable($basedir));
    $free = function_exists('disk_free_space') ? @disk_free_space($basedir) : false;
    return array(
        'writable' => (bool) $writable,
        'free'     => $free === false ? null : (int) $free,
    );
}
