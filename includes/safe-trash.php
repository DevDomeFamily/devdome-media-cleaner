<?php
/**
 * Recycle Bin mover — the reversibility core. Nothing is ever deleted here.
 *
 * For each selected scan item: re-verify it is STILL unreferenced (last-second false-positive
 * guard against concurrent edits), then move the original + ALL size variants via WP_Filesystem
 * into /uploads/devdome-safe-trash/{batchID}/{relative-structure}, write a trash_item row (with
 * original_path, trash_path, original_url, hash, size, moved_at, status) and append to the
 * per-batch manifest.json (with a checksum so a restore can verify integrity). The attachment DB
 * record is NOT removed — it stays until an explicit permanent delete. Errors skip the file, never
 * crash the batch. Writability + disk-space are re-checked first.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

/** Lazily init WP_Filesystem (direct method) and return the global. */
function devdsame_fs()
{
    global $wp_filesystem;
    // All Recycle Bin writes are local uploads-folder files, so the direct transport is
    // required: under WP-CLI/cron the auto-detected method can be a half-configured ftpext
    // instance (null connection), which fatals on the first put_contents().
    if (!$wp_filesystem || (isset($wp_filesystem->method) && 'direct' !== $wp_filesystem->method)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $force_direct = function () {
            return 'direct';
        };
        add_filter('filesystem_method', $force_direct);
        WP_Filesystem();
        remove_filter('filesystem_method', $force_direct);
    }
    // WP_Filesystem() can be skipped when the global was pre-set, leaving the FS_CHMOD_*
    // constants undefined — mirror core's fallback.
    if (!defined('FS_CHMOD_FILE')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- this IS core's own constant, defined exactly as wp-admin/includes/file.php would.
        define('FS_CHMOD_FILE', fileperms(ABSPATH . 'index.php') & 0777 | 0644);
    }
    if (!defined('FS_CHMOD_DIR')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's own constant, same fallback as wp-admin/includes/file.php.
        define('FS_CHMOD_DIR', fileperms(ABSPATH) & 0777 | 0755);
    }
    return $wp_filesystem;
}

/** Create a trash batch row. Returns batch id (or 0). */
/**
 * Create a folder inside the Recycle Bin, one component at a time, refusing any link on the
 * way down (a linked batch or year folder would carry moved files into live uploads). Returns
 * true when $dir exists as a real folder whose every component from the bin root is real.
 */
function devdsame_bin_mkdir($dir)
{
    $bin = rtrim(str_replace('\\', '/', devdsame_safe_trash_dir()), '/');
    $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
    if (strpos($dir, '..') !== false || ($dir !== $bin && strpos($dir, $bin . '/') !== 0)) {
        return false;
    }
    clearstatcache();
    if (is_link($bin) || !is_dir($bin) || devdsame_validate_in_uploads($bin) === false) {
        return false;
    }
    $probe = $bin;
    $rest = $dir === $bin ? array() : explode('/', substr($dir, strlen($bin) + 1));
    foreach ($rest as $seg) {
        if ($seg === '' || $seg === '.') {
            return false;
        }
        $probe .= '/' . $seg;
        if (!file_exists($probe)) {
            @mkdir($probe, defined('FS_CHMOD_DIR') ? FS_CHMOD_DIR : (fileperms(ABSPATH) & 0777 | 0755)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- one component below a verified real folder inside uploads.
            clearstatcache(true, $probe);
        }
        if (is_link($probe) || !is_dir($probe)) {
            return false;
        }
    }
    return devdsame_validate_in_uploads($dir) !== false;
}

function devdsame_create_trash_batch($user_id = 0, $note = '')
{
    if (!devdsame_init_safe_trash()) {
        return 0; // the bin folder could not be created or guarded against public access
    }
    $health = devdsame_filesystem_health();
    if (empty($health['writable'])) {
        return 0;
    }

    global $wpdb;
    $t = $wpdb->prefix . 'devdsame_trash_batches';
    $now = current_time('mysql');
    $auto_days = devdsame_get_int('auto_delete_after_days', 0);
    $delete_after = $auto_days > 0 ? gmdate('Y-m-d H:i:s', time() + $auto_days * DAY_IN_SECONDS) : null;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches insert.
    $ok = $wpdb->insert($t, array(
        'user_id'                => (int) $user_id,
        'created_at'             => $now,
        'status'                 => 'trashed',
        'restore_available'      => 1,
        'permanent_delete_after' => $delete_after,
        'note'                   => (string) $note,
    ), array('%d', '%s', '%s', '%d', '%s', '%s'));
    if (!$ok) {
        return 0;
    }
    $batch_id = (int) $wpdb->insert_id;

    // Per-batch trash dir + manifest.
    $dir = devdsame_safe_trash_dir($batch_id);
    // A brand-new batch id must have no folder yet: a pre-planted folder (with a linked
    // manifest.json or year folder inside) is never adopted.
    clearstatcache();
    if (is_link($dir) || file_exists($dir) || !devdsame_bin_mkdir($dir)) {
        // No real batch folder = nothing may be moved; drop the row so no empty batch is listed.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches cleanup.
        $wpdb->delete($t, array('id' => $batch_id), array('%d'));
        return 0;
    }
    $manifest = $dir . '/manifest.json';
    devdsame_fs()->put_contents($manifest, wp_json_encode(array(
        'batch_id'   => $batch_id,
        'created_at' => $now,
        'site_url'   => home_url(),
        'items'      => array(),
    )), FS_CHMOD_FILE);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches update.
    $wpdb->update($t, array('manifest_path' => $manifest), array('id' => $batch_id), array('%s'), array('%d'));

    return $batch_id;
}

/**
 * Move ONE scan item's files to Recycle Bin. Returns true or WP_Error (logged on the row).
 *
 * @param int $batch_id
 * @param int $item_id  scan_items.id
 */
function devdsame_trash_item($batch_id, $item_id)
{
    global $wpdb;
    $items = $wpdb->prefix . 'devdsame_scan_items';

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items read; id bound via prepare.
    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$items} WHERE id = %d", (int) $item_id));
    if ($wpdb->last_error !== '') {
        return devdsame_fail_item($item_id, __('Skipped: the scan item could not be read (database error).', 'devdome-safe-media-cleaner'));
    }
    if (!$item) {
        return new WP_Error('devdsame_no_item', __('Scan item not found.', 'devdome-safe-media-cleaner'));
    }

    $attach_id = (int) $item->attachment_id;

    // ---- Eligibility: only what the review screen can select. A used, missing or already
    // trashed item, a protected or ignored attachment, or a recent upload is never moved, no
    // matter which caller (screen, ability, CLI, a stale selection) named its id. ----
    if (!in_array((string) $item->status, array('unused', 'uncertain', 'duplicate', 'orphan'), true)) {
        return devdsame_fail_item($item_id, sprintf(
            /* translators: %s: item status */
            __('Skipped: item is %s, not a cleanup candidate.', 'devdome-safe-media-cleaner'),
            (string) $item->status
        ));
    }
    if ($attach_id > 0) {
        $marks = devdsame_protected_ids();
        $ignored = devdsame_ignored_ids();
        if ($marks === null || $ignored === null) {
            return devdsame_fail_item($item_id, __('Skipped: the protection list could not be read.', 'devdome-safe-media-cleaner'));
        }
        if (isset($marks[$attach_id]) || isset($ignored[$attach_id])) {
            return devdsame_fail_item($item_id, __('Skipped: image is protected or ignored.', 'devdome-safe-media-cleaner'));
        }
        $recent_days = devdsame_get_int('recent_upload_protection_days', 30);
        if (devdsame_get_int('protect_recent', 1) && $recent_days > 0 && !get_post_meta($attach_id, '_devdsame_reregistered', true)) {
            $uploaded = strtotime((string) get_post_field('post_date', $attach_id));
            if ($uploaded && $uploaded >= time() - $recent_days * DAY_IN_SECONDS) {
                return devdsame_fail_item($item_id, __('Skipped: recent upload, protected by the settings.', 'devdome-safe-media-cleaner'));
            }
        }
        // The CURRENT never-scan folders apply to attachments too (a folder excluded after the scan).
        $arel = strtolower(devdsame_path_to_relative((string) get_attached_file($attach_id)));
        foreach (devdsame_get_array('never_scan_folders') as $nf) {
            $nf = strtolower(trim((string) $nf, '/'));
            if ($nf !== '' && $arel !== '' && ($arel === $nf || strpos($arel, $nf . '/') === 0)) {
                return devdsame_fail_item($item_id, __('Skipped: the file is inside a folder the settings say to never scan.', 'devdome-safe-media-cleaner'));
            }
        }
    }

    if (!empty($GLOBALS['devdsame_db_failed'])) {
        // A settings read failed above: the protections could not be applied, so nothing moves.
        return devdsame_fail_item($item_id, __('Skipped: the plugin settings could not be read, so the protections could not be checked.', 'devdome-safe-media-cleaner'));
    }

    // ---- Last-second re-verification guard (the false-positive killer). An incomplete set
    // (memory cap hit or a failed database read) cannot prove a file is unreferenced: skip. ----
    $vset = devdsame_used_set(devdsame_latest_scan_id());
    if (!empty($vset['truncated'])) {
        return devdsame_fail_item($item_id, __('Skipped: the reference check could not read every source right now; nothing was moved.', 'devdome-safe-media-cleaner'));
    }
    if ($attach_id > 0) {
        // Rebuild a fresh, single-attachment used-set scan and bail if it's now referenced.
        if (devdsame_attachment_referenced_now($attach_id)) {
            return devdsame_fail_item($item_id, __('Skipped: image became referenced since the scan.', 'devdome-safe-media-cleaner'));
        }
    } else {
        // Orphan file (no attachment record): its path can still be hardcoded in a template,
        // option or builder blob — run the same haystack check on the file path before moving.
        $orel = devdsame_path_to_relative((string) $item->file_path);
        // Ownership can change after the scan: a file that became an attachment's original, scaled
        // copy or thumbnail since then belongs to that attachment now, never an orphan to move
        // around its protections. The known-file set is built once per process and cached.
        $known = devdsame_known_attachment_files();
        if ($known === null) {
            return devdsame_fail_item($item_id, __('Skipped: the Media Library could not be checked for this file.', 'devdome-safe-media-cleaner'));
        }
        if ($orel !== '' && (isset($known[strtolower($orel)]) || devdsame_is_variant_of_known($orel, $known))) {
            return devdsame_fail_item($item_id, __('Skipped: the file now belongs to a Media Library item; scan again to review it there.', 'devdome-safe-media-cleaner'));
        }
        $ofirst = strtolower((string) strtok($orel, '/'));
        foreach (devdsame_get_array('never_scan_folders') as $nf) {
            $nf = strtolower(trim((string) $nf, '/'));
            if ($nf !== '' && ($ofirst === $nf || strpos(strtolower($orel), $nf . '/') === 0)) {
                return devdsame_fail_item($item_id, __('Skipped: the file is inside a folder the settings say to never scan.', 'devdome-safe-media-cleaner'));
            }
        }
        if ($orel !== '') {
            $oset = $vset;
            $omatches = 0;
            if (devdsame_is_referenced($oset, 0, array('paths' => array(), 'urls' => array(), 'rel' => array($orel)), $omatches)) {
                return devdsame_fail_item($item_id, __('Skipped: file path is referenced in site content.', 'devdome-safe-media-cleaner'));
            }
        }
    }

    $files = $attach_id > 0 ? devdsame_attachment_files($attach_id) : array('paths' => array($item->file_path), 'urls' => array($item->file_url), 'rel' => array(devdsame_path_to_relative($item->file_path)));
    if (empty($files['paths'])) {
        return devdsame_fail_item($item_id, __('No files found to move.', 'devdome-safe-media-cleaner'));
    }

    $fs = devdsame_fs();
    $batch_dir = devdsame_safe_trash_dir($batch_id);
    $moved = array();
    $moved_total_bytes = 0;
    $primary_trash_path = '';
    $primary_rel = '';
    $primary_hash = (string) $item->file_hash;
    $expected = 0;
    $failed = false;

    // All or nothing: every present file of the item moves, or none does. A half-moved image
    // (original in the bin, thumbnails still live, or the reverse) can neither be restored nor
    // deleted cleanly, so a single failed move puts the moved files back and skips the item.
    foreach ($files['paths'] as $abs) {
        // Validate source is inside uploads (never move anything outside).
        $valid = devdsame_validate_in_uploads($abs);
        if ($valid === false || !is_file($abs)) {
            continue;
        }
        $rel = devdsame_path_to_relative($abs);
        if ($rel === '') {
            continue;
        }
        $expected++;
        $dest = $batch_dir . '/' . $rel;
        $dest_dir = dirname($dest);
        // The destination folder is built component by component inside THIS batch's real folder
        // (a linked folder in the bin would carry the move into live uploads), and the move never
        // writes over a file that is already there.
        if (!devdsame_bin_mkdir($dest_dir) || devdsame_validate_in_uploads($dest) === false || file_exists($dest)) {
            $failed = true;
            break;
        }
        $size = (int) @filesize($abs);
        if (!$fs->move($abs, $dest, false) || !is_file($dest)) {
            $failed = true;
            break;
        }
        $moved[] = array('rel' => $rel, 'trash' => $dest, 'size' => $size, 'orig' => $abs);
        $moved_total_bytes += $size;
        if ($primary_trash_path === '') {
            $primary_trash_path = $dest;
            $primary_rel = $rel;
        }
    }

    if ($expected === 0) {
        return devdsame_fail_item($item_id, __('Files could not be moved (permissions or already gone).', 'devdome-safe-media-cleaner'));
    }
    if ($failed || count($moved) < $expected) {
        $stranded = devdsame_undo_moves($moved);
        if ($stranded) {
            devdsame_record_stranded($batch_id, $item, $stranded, 'not every file could be moved and the undo failed');
            return devdsame_fail_item($item_id, __('Skipped: not every file of this image could be moved, and some moved files could not be put back; they are recorded in this batch and can be restored.', 'devdome-safe-media-cleaner'));
        }
        return devdsame_fail_item($item_id, __('Skipped: not every file of this image could be moved; the moved ones were put back.', 'devdome-safe-media-cleaner'));
    }

    // Record the trash item (one row per scan item; all variants captured in meta_json).
    $now = current_time('mysql');
    $meta_json = wp_json_encode(array(
        'attachment_meta' => $attach_id > 0 ? wp_get_attachment_metadata($attach_id) : null,
        'mime_type'       => (string) $item->mime_type,
        'files'           => array_map(function ($m) {
            return array('rel' => $m['rel'], 'trash' => $m['trash'], 'size' => $m['size'], 'orig' => $m['orig'], 'sha1' => is_file($m['trash']) ? @sha1_file($m['trash']) : '');
        }, $moved),
    ));

    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items insert.
    $inserted = $wpdb->insert($ti, array(
        'batch_id'      => (int) $batch_id,
        'attachment_id' => $attach_id,
        'original_path' => (string) ($moved[0]['orig']),
        'trash_path'    => $primary_trash_path,
        'original_url'  => (string) $item->file_url,
        'rel_path'      => $primary_rel,
        'file_hash'     => $primary_hash,
        'file_size'     => $moved_total_bytes,
        'meta_json'     => $meta_json,
        'moved_at'      => $now,
        'status'        => 'trashed',
    ), array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'));
    if (!$inserted) {
        // No restore record = no way back. Put the files where they were and skip the item.
        $stranded = devdsame_undo_moves($moved);
        if ($stranded) {
            devdsame_record_stranded($batch_id, $item, $stranded, 'the restore record could not be written and the undo failed');
            return devdsame_fail_item($item_id, __('Skipped: the restore record could not be written, and some moved files could not be put back; they stay in the Recycle Bin (see the error log).', 'devdome-safe-media-cleaner'));
        }
        return devdsame_fail_item($item_id, __('Skipped: the restore record could not be written; the files were put back.', 'devdome-safe-media-cleaner'));
    }
    $trash_item_id = (int) $wpdb->insert_id;

    // Flip the scan item to 'trashed' so dashboard counts and the Review grid stay honest
    // without needing a rescan (restore flips it back).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items update.
    $wpdb->update($items, array('status' => 'trashed'), array('id' => (int) $item_id), array('%s'), array('%d'));

    // Detach the attachment record so WP stops listing the file as present, but DO NOT delete it.
    $flag_failed = false;
    if ($attach_id > 0) {
        // Mark our own meta flag; keep the post + metadata for a clean restore.
        update_post_meta($attach_id, '_devdsame_trashed', $batch_id);
        // Without the flag the library keeps listing the image as present: say so instead of "done".
        if ((int) get_post_meta($attach_id, '_devdsame_trashed', true) !== (int) $batch_id) {
            $flag_failed = true;
        }
    }

    // Append to the manifest.
    devdsame_manifest_append($batch_id, array(
        'trash_item_id' => $trash_item_id,
        'attachment_id' => $attach_id,
        'original_url'  => (string) $item->file_url,
        'bytes'         => $moved_total_bytes,
        'files'         => array_map(function ($m) {
            return array('rel' => $m['rel'], 'sha1' => is_file($m['trash']) ? @sha1_file($m['trash']) : '');
        }, $moved),
        'moved_at'      => $now,
    ));

    // Bump the batch totals.
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches atomic increment; values bound via prepare.
    $wpdb->query($wpdb->prepare(
        "UPDATE {$tb} SET total_files = total_files + 1, total_bytes = total_bytes + %d WHERE id = %d",
        $moved_total_bytes,
        (int) $batch_id
    ));

    if ($flag_failed) {
        $msg = __('Moved to the Recycle Bin, but the Media Library flag could not be written (database error): the image may show as missing until the batch is restored.', 'devdome-safe-media-cleaner');
        devdsame_record_error('trash_flag', $msg, array('attachment_id' => $attach_id, 'batch_id' => (int) $batch_id));
        return new WP_Error('devdsame_flag_failed', $msg, array('item_id' => (int) $item_id));
    }
    return true;
}

/**
 * Put files a half-finished move already placed in the bin back where they came from.
 * Returns the entries that could NOT be put back (still in the bin), empty when all went home.
 */
function devdsame_undo_moves($moved)
{
    $fs = devdsame_fs();
    $stranded = array();
    foreach ((array) $moved as $m) {
        if (is_file($m['orig'])) {
            // Home already, unless a different file took the name meanwhile: then the bin copy
            // is the only copy of the original bytes and must stay recorded.
            if (is_file($m['trash'])) {
                if (@sha1_file($m['orig']) !== @sha1_file($m['trash'])) {
                    $stranded[] = $m;
                } else {
                    wp_delete_file($m['trash']); // same bytes are home: the bin copy is a stray, not a record
                }
            }
            continue;
        }
        if (!is_file($m['trash']) || !$fs->move($m['trash'], $m['orig'], false) || !is_file($m['orig'])) {
            $stranded[] = $m;
        }
    }
    return $stranded;
}

/**
 * A partial move whose undo also failed: the files sit in the bin with no record, which is the one
 * state that can lose data. Write the recovery row for exactly those files so the batch can
 * restore them, and say so. The scan item is left as it was (its live files are still live).
 */
function devdsame_record_stranded($batch_id, $item, $stranded, $why)
{
    global $wpdb;
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    $bytes = 0;
    foreach ($stranded as $m) {
        $bytes += (int) $m['size'];
    }
    $meta_json = wp_json_encode(array(
        'attachment_meta' => (int) $item->attachment_id > 0 ? wp_get_attachment_metadata((int) $item->attachment_id) : null,
        'mime_type'       => (string) $item->mime_type,
        'partial'         => true,
        'files'           => array_map(function ($m) {
            return array('rel' => $m['rel'], 'trash' => $m['trash'], 'size' => $m['size'], 'orig' => $m['orig'], 'sha1' => is_file($m['trash']) ? @sha1_file($m['trash']) : '');
        }, $stranded),
    ));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items insert (recovery record).
    $ok = $wpdb->insert($ti, array(
        'batch_id'      => (int) $batch_id,
        'attachment_id' => (int) $item->attachment_id,
        'original_path' => (string) $stranded[0]['orig'],
        'trash_path'    => (string) $stranded[0]['trash'],
        'original_url'  => (string) $item->file_url,
        'rel_path'      => (string) $stranded[0]['rel'],
        'file_hash'     => (string) $item->file_hash,
        'file_size'     => $bytes,
        'meta_json'     => $meta_json,
        'moved_at'      => current_time('mysql'),
        'status'        => 'trashed',
        'error_message' => substr('Partial move: ' . $why, 0, 1000),
    ), array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'));
    $names = implode(', ', array_map(function ($m) { return $m['rel']; }, $stranded));
    if ($ok) {
        /* translators: 1: file list, 2: batch id */
        $text = __('Some files could not be put back after a failed move and stay in the Recycle Bin (batch #%2$d, restorable from the Recycle Bin tab): %1$s', 'devdome-safe-media-cleaner');
    } else {
        /* translators: 1: file list, 2: batch id */
        $text = __('Some files could not be put back after a failed move and stay in the Recycle Bin of batch #%2$d WITHOUT a record. Move them back by hand from wp-content/uploads/devdome-safe-trash/%2$d/: %1$s', 'devdome-safe-media-cleaner');
    }
    if (function_exists('devdsame_record_error')) {
        devdsame_record_error('trash_partial', sprintf($text, $names, (int) $batch_id), array('batch_id' => (int) $batch_id, 'files' => $names, 'recorded' => (bool) $ok));
    }
    if ($ok) {
        $tb = $wpdb->prefix . 'devdsame_trash_batches';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches increment; values bound via prepare.
        $wpdb->query($wpdb->prepare("UPDATE {$tb} SET total_files = total_files + 1, total_bytes = total_bytes + %d WHERE id = %d", $bytes, (int) $batch_id));
    }
}

/** Mark a scan item's move as failed (skip-on-error) and log the message on the trash item table? Returns WP_Error. */
function devdsame_fail_item($item_id, $message)
{
    // We don't have a trash_item yet; record the skip in the queue error counter via the caller.
    return new WP_Error('devdsame_skip', $message, array('item_id' => (int) $item_id));
}

/** Append an entry to a batch manifest (read-modify-write, integrity-safe). */
function devdsame_manifest_append($batch_id, $entry)
{
    $fs = devdsame_fs();
    $manifest = devdsame_safe_trash_dir($batch_id) . '/manifest.json';
    if (is_link($manifest)) {
        return; // never write through a link (the rows are the record; the manifest is a copy)
    }
    $data = array('batch_id' => (int) $batch_id, 'items' => array());
    if ($fs->exists($manifest)) {
        $decoded = json_decode((string) $fs->get_contents($manifest), true);
        if (is_array($decoded)) {
            $data = $decoded;
            if (!isset($data['items']) || !is_array($data['items'])) {
                $data['items'] = array();
            }
        }
    }
    $data['items'][] = $entry;
    $data['checksum'] = sha1(wp_json_encode($data['items']));
    $fs->put_contents($manifest, wp_json_encode($data), FS_CHMOD_FILE);
}

/** Finalize a trash batch (no-op accounting hook; manifest + totals already written). */
function devdsame_finalize_trash_batch($batch_id)
{
    global $wpdb;
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches read; id bound via prepare.
    // Emptiness comes from the item rows, not the counter (an unchecked counter increment could lie).
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items count; id bound via prepare.
    $n = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ti} WHERE batch_id = %d", (int) $batch_id));
    if ($n === null || $wpdb->last_error !== '') {
        return; // unknown: never mark a batch empty on a failed read
    }
    if ((int) $n > 0) {
        // Keep the batch counters honest with the rows that exist.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches reconcile; id bound via prepare.
        $wpdb->query($wpdb->prepare("UPDATE {$tb} b SET total_files = (SELECT COUNT(*) FROM {$ti} i WHERE i.batch_id = b.id), total_bytes = (SELECT COALESCE(SUM(file_size), 0) FROM {$ti} i WHERE i.batch_id = b.id) WHERE b.id = %d", (int) $batch_id));
    }
    if ((int) $n === 0) {
        // Empty batch (everything skipped) -> mark it so the UI can explain.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches update.
        $wpdb->update($tb, array('status' => 'empty'), array('id' => (int) $batch_id), array('%s'), array('%d'));
    }
}

/**
 * Fresh, single-attachment reference check used immediately before moving. Rebuilds a tiny
 * targeted view: featured-image relations, gallery/content references, and the global used-set
 * (cached). Returns true if the image now appears referenced.
 */
function devdsame_attachment_referenced_now($attachment_id)
{
    // Reuse the cached used-set if present (it was just built for the scan); otherwise the
    // is_referenced check is still authoritative since references.php scans live data.
    $set = devdsame_used_set(devdsame_latest_scan_id());
    $files = devdsame_attachment_files((int) $attachment_id);
    $matches = 0;
    return devdsame_is_referenced($set, (int) $attachment_id, $files, $matches);
}

/** Count items in a batch with a given status. */
function devdsame_batch_item_count($batch_id, $status)
{
    global $wpdb;
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items count; values bound via prepare.
    // 'trashed' means "still in the bin": items claimed by a running restore or delete count too,
    // so a batch is never finalised while another process is halfway through its files.
    $statuses = $status === 'trashed' ? array('trashed', 'restoring', 'deleting') : array($status);
    $ph = implode(',', array_fill(0, count($statuses), '%s'));
    $n = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ti} WHERE batch_id = %d AND status IN ({$ph})", array_merge(array((int) $batch_id), $statuses)));
    if ($n === null || $wpdb->last_error !== '') {
        return null; // unknown: a failed count must never read as "nothing left" (that authorises deletes)
    }
    return (int) $n;
}

/* ---------------------------------------------------------------------------
 * Media Library consistency: keep trashed-but-not-deleted attachments OUT of the library grid
 * so users never see broken thumbnails during the Recycle Bin review window (a gap vs. tools that
 * keep the library consistent). The attachment record + metadata are intentionally preserved for a
 * clean restore; we only HIDE them from the admin browse/grid via the `_devdsame_trashed` flag.
 * ------------------------------------------------------------------------- */

/** Exclude trashed attachments from admin attachment queries (list table + grid). */
function devdsame_hide_trashed_from_library($query)
{
    if (!is_admin() || !($query instanceof WP_Query)) {
        return;
    }
    if ($query->get('post_type') !== 'attachment') {
        return;
    }
    $mq = (array) $query->get('meta_query');
    $mq[] = array(
        'key'     => '_devdsame_trashed',
        'compare' => 'NOT EXISTS',
    );
    $query->set('meta_query', $mq);
}
add_action('pre_get_posts', 'devdsame_hide_trashed_from_library');

/** Exclude trashed attachments from the AJAX media grid (wp_ajax query-attachments). */
function devdsame_hide_trashed_from_ajax($args)
{
    if (!isset($args['meta_query']) || !is_array($args['meta_query'])) {
        $args['meta_query'] = array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- initializes the array for the single NOT EXISTS key below.
    }
    $args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single NOT EXISTS key appended to the admin media-grid query only.
        'key'     => '_devdsame_trashed',
        'compare' => 'NOT EXISTS',
    );
    return $args;
}
add_filter('ajax_query_attachments_args', 'devdsame_hide_trashed_from_ajax');

/** A slice of a batch's items with a given status (for the chunked runner). */
function devdsame_batch_items_slice($batch_id, $status, $limit)
{
    global $wpdb;
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items read; values bound via prepare.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$ti} WHERE batch_id = %d AND status = %s ORDER BY id ASC LIMIT %d",
        (int) $batch_id,
        $status,
        (int) $limit
    ));
    if ($wpdb->last_error !== '') {
        return null; // a failed read is not "nothing left"; callers stop instead of finalising
    }
    return $rows;
}
