<?php
/**
 * Restore + permanent delete.
 *
 * Restore: move every file of a trash item back to its EXACT original relative path (verified
 * against the manifest with a sha1 integrity check), restore the attachment metadata flag, mark
 * restored_at. One-click undo restores a whole batch. Every restore target is realpath-validated
 * to land inside the uploads basedir.
 *
 * Permanent delete: only after explicit user confirmation OR the auto-delete cron past
 * permanent_delete_after. Removes the trashed copies (wp_delete_file) and the attachment record
 * (wp_delete_attachment), marks permanently_deleted_at. Auto-delete is OFF by default.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

/**
 * Claim a bin item for one operation: 'trashed' -> 'restoring' | 'deleting' in a single conditional
 * UPDATE, so exactly one caller wins when a restore and a delete race for the same file. True when
 * this caller holds the claim. devdsame_batch_item_count('trashed') still counts claimed items.
 */
function devdsame_claim_trash_item($trash_item_id, $to)
{
    global $wpdb;
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- conditional claim on our own table; values bound via prepare.
    $rows = $wpdb->query($wpdb->prepare("UPDATE {$ti} SET status = %s WHERE id = %d AND status = 'trashed'", $to === 'deleting' ? 'deleting' : 'restoring', (int) $trash_item_id));
    return $rows === 1;
}

/**
 * Claims left by a request that died mid-way ('restoring' / 'deleting' with nobody working on
 * them) go back to 'trashed'. Only called when no job is running, so nothing live is undone.
 */
function devdsame_reset_stale_claims($batch_id)
{
    global $wpdb;
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items reset; id bound via prepare.
    $wpdb->query($wpdb->prepare("UPDATE {$ti} SET status = 'trashed' WHERE batch_id = %d AND status IN ('restoring', 'deleting')", (int) $batch_id));
}

/** True while a queue job is running or paused (synchronous callers must not work beside it). */
function devdsame_job_active()
{
    $job = function_exists('devdsame_get_job') ? devdsame_get_job() : null;
    return $job && in_array($job['status'], array('running', 'paused'), true);
}

/** Give a claimed item back to the bin (the operation did not finish). */
function devdsame_release_trash_item($trash_item_id, $error_message = null)
{
    global $wpdb;
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items update.
    $ok = $wpdb->update($ti, array('status' => 'trashed', 'error_message' => $error_message === null ? null : substr((string) $error_message, 0, 1000)), array('id' => (int) $trash_item_id), array('%s', '%s'), array('%d'));
    if ($ok === false && function_exists('devdsame_record_error')) {
        devdsame_record_error('claim_release', __('A Recycle Bin item could not be released after a failed operation; it is cleared automatically when the next restore or delete starts.', 'devdome-safe-media-cleaner'), array('trash_item_id' => (int) $trash_item_id));
    }
}

/** Restore ONE trash item. Returns true or WP_Error. */
function devdsame_restore_item($trash_item_id)
{
    global $wpdb;
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items read; id bound via prepare.
    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ti} WHERE id = %d", (int) $trash_item_id));
    if ($wpdb->last_error !== '') {
        return new WP_Error('devdsame_db_read', __('The Recycle Bin item could not be read (database error); nothing was moved.', 'devdome-safe-media-cleaner'));
    }
    if (!$item) {
        return new WP_Error('devdsame_no_item', __('Trash item not found.', 'devdome-safe-media-cleaner'));
    }
    if ($item->status !== 'trashed') {
        return new WP_Error('devdsame_not_trashed', __('Item is not restorable.', 'devdome-safe-media-cleaner'));
    }
    // Claim the item: exactly one caller (queue tick, admin form, CLI or the auto-delete sweep)
    // may work on it; a concurrent delete or restore sees the claim and leaves it alone.
    if (!devdsame_claim_trash_item((int) $trash_item_id, 'restoring')) {
        return new WP_Error('devdsame_busy', __('Item is being processed by another request.', 'devdome-safe-media-cleaner'));
    }

    $meta = json_decode((string) $item->meta_json, true);
    $files = (is_array($meta) && !empty($meta['files'])) ? $meta['files'] : array();
    if (!$files && $item->original_path && $item->trash_path) {
        // Rows written before the per-file manifest existed: the single primary copy.
        $files = array(array('rel' => devdsame_path_to_relative((string) $item->original_path), 'trash' => (string) $item->trash_path, 'sha1' => ''));
    }
    $fs = devdsame_fs();
    $basedir = devdsame_uploads_basedir();
    $bin = str_replace('\\', '/', devdsame_safe_trash_dir());
    $expected = 0;
    $restored = 0;
    $problems = array();

    // Every file of the item must be back in place before the item counts as restored. A file
    // is never written over something else: a same-named file that appeared meanwhile is kept
    // and reported, so restoring an old batch cannot destroy newer media.
    foreach ($files as $f) {
        $rel = isset($f['rel']) ? ltrim((string) $f['rel'], '/') : '';
        $trash = isset($f['trash']) ? (string) $f['trash'] : '';
        if ($rel === '' || $trash === '') {
            continue;
        }
        $expected++;
        // The record is data, not trust: the relative path must be plain (no traversal, no
        // absolute or drive form) and the bin copy must sit inside this batch's bin folder
        // before anything is created, hashed, moved or deleted.
        if (strpos($rel, '..') !== false || strpos($rel, ':') !== false || strpos($rel, '\\') !== false || $rel[0] === '/') {
            $problems[] = $rel . ': invalid path in the record';
            continue;
        }
        // Canonical containment: the bin copy's real location must be inside THIS batch's bin
        // folder (a lexical prefix would let "bin/../live.jpg" through).
        $tnorm = str_replace('\\', '/', $trash);
        // A linked batch folder would make its realpath point outside the bin: refuse it outright.
        $batch_dir = $bin . '/' . (int) $item->batch_id;
        $real_bin = (!is_link($bin) && !is_link($batch_dir) && is_dir($batch_dir)) ? realpath($batch_dir) : false;
        $real_trash = file_exists($trash) ? realpath($trash) : false;
        $real_bin = $real_bin ? str_replace('\\', '/', $real_bin) : '';
        $real_trash = $real_trash ? str_replace('\\', '/', $real_trash) : '';
        if (strpos($tnorm, '..') !== false || strpos($tnorm, $bin . '/') !== 0 || ($real_trash !== '' && ($real_bin === '' || strpos($real_trash, $real_bin . '/') !== 0))) {
            $problems[] = $rel . ': the Recycle Bin copy path is outside the bin';
            continue;
        }
        $dest = $basedir . '/' . $rel;
        $dest_dir = dirname($dest);
        // Create the folder only below an existing ancestor that resolves inside uploads.
        if (!devdsame_mkdir_inside_uploads($dest_dir) || devdsame_validate_in_uploads($dest) === false) {
            $problems[] = $rel . ': outside the uploads folder';
            continue;
        }
        $sha = !empty($f['sha1']) ? (string) $f['sha1'] : '';
        if (!$fs->exists($trash)) {
            if (is_file($dest) && ($sha === '' || @sha1_file($dest) === $sha)) {
                $restored++; // already back (an earlier partial restore moved it)
            } else {
                $problems[] = $rel . ': the Recycle Bin copy is missing';
            }
            continue;
        }
        if ($sha !== '' && @sha1_file($trash) !== $sha) {
            $problems[] = $rel . ': the Recycle Bin copy is damaged';
            continue;
        }
        if (is_file($dest)) {
            if ($sha !== '' && @sha1_file($dest) === $sha) {
                wp_delete_file($trash); // identical file already in place, drop the bin copy
                $restored++;
            } else {
                $problems[] = $rel . ': a different file with this name exists now, kept';
            }
            continue;
        }
        if ($fs->move($trash, $dest, false) && is_file($dest)) {
            $restored++;
        } else {
            $problems[] = $rel . ': could not be moved back';
        }
    }

    if ($expected === 0 || $restored < $expected) {
        $message = $expected === 0
            ? __('Nothing to restore for this item.', 'devdome-safe-media-cleaner')
            : sprintf(
                /* translators: 1: files restored, 2: files expected, 3: problem list */
                __('%1$d of %2$d files restored: %3$s', 'devdome-safe-media-cleaner'),
                $restored,
                $expected,
                implode('; ', array_slice($problems, 0, 5))
            );
        devdsame_release_trash_item((int) $trash_item_id, $message); // back to 'trashed' with the reason
        return new WP_Error('devdsame_restore_failed', $message);
    }

    // Restore the attachment metadata + clear our trashed flag.
    $attach_id = (int) $item->attachment_id;
    if ($attach_id > 0) {
        delete_post_meta($attach_id, '_devdsame_trashed');
        if (is_array($meta) && !empty($meta['attachment_meta'])) {
            wp_update_attachment_metadata($attach_id, $meta['attachment_meta']);
        }
        wp_cache_delete($attach_id, 'post_meta');
        if (get_post_meta($attach_id, '_devdsame_trashed', true) !== '') {
            // Files are back, but the library would still hide the image: not restored yet.
            devdsame_release_trash_item((int) $trash_item_id, __('Files are back in place but the Media Library flag could not be cleared; try again.', 'devdome-safe-media-cleaner'));
            return new WP_Error('devdsame_restore_failed', __('Files are back in place but the Media Library flag could not be cleared; try again.', 'devdome-safe-media-cleaner'));
        }
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items update.
    $marked = $wpdb->update($ti, array('status' => 'restored', 'restored_at' => current_time('mysql'), 'error_message' => null), array('id' => (int) $trash_item_id), array('%s', '%s', '%s'), array('%d'));
    if ($marked === false) {
        // Give the claim back so a retry can pick the item up (its files are home; the retry counts them as restored).
        devdsame_release_trash_item((int) $trash_item_id, __('Files restored; the record could not be updated. Retry the restore to finish.', 'devdome-safe-media-cleaner'));
        return new WP_Error('devdsame_restore_failed', __('The files are back in place but the record could not be updated; the batch will show them as trashed until the next attempt.', 'devdome-safe-media-cleaner'));
    }

    // Flip the scan item back so dashboard counts stay honest without a rescan.
    $si = $wpdb->prefix . 'devdsame_scan_items';
    if ($attach_id > 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items update.
        $wpdb->update($si, array('status' => 'unused'), array('attachment_id' => $attach_id, 'status' => 'trashed'), array('%s'), array('%d', '%s'));
    } elseif ($item->original_path) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items update.
        $wpdb->update($si, array('status' => 'orphan'), array('file_path' => (string) $item->original_path, 'status' => 'trashed'), array('%s'), array('%s', '%s'));
    }

    return true;
}

/** Restore an entire batch (synchronous; the queue runner uses devdsame_restore_item per slice). */
function devdsame_restore_batch($batch_id)
{
    // Synchronous callers (admin form, WP-CLI) hold the same lock the queue ticks use, so two of
    // them, or one of them and a tick, never work on the same bin at once.
    if (devdsame_job_active() || !devdsame_acquire_tick_lock()) {
        return array('restored' => 0, 'errors' => 1); // another operation owns the bin right now
    }
    try {
        devdsame_reset_stale_claims($batch_id); // safe under the lock: nobody else is mid-way
        $rows = devdsame_batch_items_slice($batch_id, 'trashed', 100000);
        if ($rows === null) {
            return array('restored' => 0, 'errors' => 1); // failed read: nothing done, reported as a problem
        }
        $ok = 0;
        $err = 0;
        foreach ((array) $rows as $r) {
            $res = devdsame_restore_item((int) $r->id);
            is_wp_error($res) ? $err++ : $ok++;
        }
        devdsame_finalize_restore_batch($batch_id);
        return array('restored' => $ok, 'errors' => $err);
    } finally {
        devdsame_release_tick_lock();
    }
}

/** Mark a batch restored when nothing trashed remains. */
function devdsame_finalize_restore_batch($batch_id)
{
    global $wpdb;
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    if (devdsame_batch_item_count($batch_id, 'trashed') === 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches update.
        $wpdb->update($tb, array('status' => 'restored', 'restore_available' => 0), array('id' => (int) $batch_id), array('%s', '%d'), array('%d'));
    }
}

/** Permanently delete ONE trash item: remove the trashed copies + the attachment record. */
function devdsame_permanent_delete_item($trash_item_id)
{
    global $wpdb;
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items read; id bound via prepare.
    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ti} WHERE id = %d", (int) $trash_item_id));
    if ($wpdb->last_error !== '') {
        return new WP_Error('devdsame_db_read', __('The Recycle Bin item could not be read (database error); nothing was deleted.', 'devdome-safe-media-cleaner'));
    }
    if (!$item || $item->status !== 'trashed') {
        return new WP_Error('devdsame_not_trashed', __('Item is not pending deletion.', 'devdome-safe-media-cleaner'));
    }
    if (!devdsame_claim_trash_item((int) $trash_item_id, 'deleting')) {
        return new WP_Error('devdsame_busy', __('Item is being processed by another request.', 'devdome-safe-media-cleaner'));
    }

    $meta = json_decode((string) $item->meta_json, true);
    $files = (is_array($meta) && !empty($meta['files'])) ? $meta['files'] : array();
    $bin = str_replace('\\', '/', devdsame_safe_trash_dir());
    $left = array();

    $paths = array();
    foreach ($files as $f) {
        $paths[] = isset($f['trash']) ? (string) $f['trash'] : '';
    }
    $paths[] = (string) $item->trash_path; // primary copy fallback for rows without a file list
    // Only ever delete inside THIS batch's real bin folder: the record is data, not trust, so a
    // path with traversal, a linked folder or a link to a live file is refused and reported.
    $batch_dir = $bin . '/' . (int) $item->batch_id;
    $real_batch = (!is_link($bin) && !is_link($batch_dir) && is_dir($batch_dir)) ? realpath($batch_dir) : false;
    $real_batch = $real_batch ? str_replace('\\', '/', $real_batch) : '';
    foreach (array_unique(array_filter($paths)) as $trash) {
        $tnorm = str_replace('\\', '/', $trash);
        if (strpos($tnorm, '..') !== false || strpos($tnorm, $batch_dir . '/') !== 0) {
            $left[] = wp_basename($trash);
            continue;
        }
        if (is_file($trash)) {
            $real = realpath($trash);
            $real = $real ? str_replace('\\', '/', $real) : '';
            if ($real_batch === '' || $real === '' || strpos($real, $real_batch . '/') !== 0) {
                $left[] = wp_basename($trash);
                continue;
            }
            wp_delete_file($trash);
            clearstatcache(true, $trash);
            if (is_file($trash)) {
                $left[] = wp_basename($trash);
            }
        }
    }
    if ($left) {
        $message = sprintf(
            /* translators: %s: file names */
            __('Could not delete: %s (check folder permissions on /wp-content/uploads).', 'devdome-safe-media-cleaner'),
            implode(', ', array_slice($left, 0, 5))
        );
        devdsame_release_trash_item((int) $trash_item_id, $message); // back to 'trashed', still restorable
        return new WP_Error('devdsame_delete_failed', $message);
    }

    // The bin copies are gone: from here the item IS deleted, whatever the record cleanup does.
    // The attachment record goes without touching the disk (its paths may hold a file that a
    // partial restore put back or a newer upload with the same name; those are not ours to delete).
    $attach_id = (int) $item->attachment_id;
    $record_note = null;
    // A partial-recovery row holds only the files a failed move left behind; the attachment's
    // other files are still live, so its record stays.
    $partial = is_array($meta) && !empty($meta['partial']);
    if ($attach_id > 0 && !$partial && get_post($attach_id)) {
        $keep_disk = function () {
            return '';
        };
        add_filter('wp_delete_file', $keep_disk, PHP_INT_MAX);
        $gone = wp_delete_attachment($attach_id, true);
        remove_filter('wp_delete_file', $keep_disk, PHP_INT_MAX);
        if (!$gone || get_post($attach_id)) {
            $record_note = __('Files deleted; the Media Library record could not be removed and will show as missing on the next scan.', 'devdome-safe-media-cleaner');
        }
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items update.
    $ok = $wpdb->update($ti, array('status' => 'deleted', 'permanently_deleted_at' => current_time('mysql'), 'error_message' => $record_note), array('id' => (int) $trash_item_id), array('%s', '%s', '%s'), array('%d'));
    if ($ok === false) {
        // Claim released so a retry finishes the record (the bin copies are gone, so it lands on 'deleted').
        devdsame_release_trash_item((int) $trash_item_id, __('Files deleted; the record could not be updated. Retry the delete to finish.', 'devdome-safe-media-cleaner'));
        return new WP_Error('devdsame_delete_failed', __('The files are deleted but the record could not be updated.', 'devdome-safe-media-cleaner'));
    }
    if ($record_note !== null) {
        return new WP_Error('devdsame_record_kept', $record_note);
    }

    return true;
}

/** Permanently delete a whole batch (synchronous helper; queue runner does it per slice). */
function devdsame_permanent_delete_batch($batch_id)
{
    if (devdsame_job_active() || !devdsame_acquire_tick_lock()) {
        return array('deleted' => 0, 'errors' => 1); // another operation owns the bin right now
    }
    try {
        devdsame_reset_stale_claims($batch_id);
        $rows = devdsame_batch_items_slice($batch_id, 'trashed', 100000);
        if ($rows === null) {
            return array('deleted' => 0, 'errors' => 1); // failed read: nothing done, reported as a problem
        }
        $ok = 0;
        $err = 0;
        foreach ((array) $rows as $r) {
            $res = devdsame_permanent_delete_item((int) $r->id);
            is_wp_error($res) ? $err++ : $ok++;
        }
        devdsame_finalize_delete_batch($batch_id);
        return array('deleted' => $ok, 'errors' => $err);
    } finally {
        devdsame_release_tick_lock();
    }
}

/** Mark a batch deleted + remove its (now-empty) trash dir. */
function devdsame_finalize_delete_batch($batch_id)
{
    global $wpdb;
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    if (devdsame_batch_item_count($batch_id, 'trashed') === 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches update.
        $wpdb->update($tb, array('status' => 'deleted', 'restore_available' => 0), array('id' => (int) $batch_id), array('%s', '%d'), array('%d'));
        // Remove the per-batch folder only when it really holds nothing but its manifest: a file
        // without a record (a stranded move whose record could not be written) must survive.
        $dir = devdsame_safe_trash_dir($batch_id);
        if (is_dir($dir) && !is_link($dir)) {
            $leftover = false;
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($it as $entry) {
                    if ($entry->isFile() && $entry->getFilename() !== 'manifest.json') {
                        $leftover = true;
                        break;
                    }
                }
            } catch (Exception $e) {
                $leftover = true;
            }
            if ($leftover) {
                devdsame_record_error('batch_folder_kept', sprintf(
                    /* translators: %d: batch id */
                    __('Batch #%d is marked deleted but its Recycle Bin folder still holds files without a record; the folder was kept. Check wp-content/uploads/devdome-safe-trash/ by hand.', 'devdome-safe-media-cleaner'),
                    (int) $batch_id
                ), array('batch_id' => (int) $batch_id));
            } else {
                devdsame_fs()->delete($dir, true);
            }
        }
    }
}

/**
 * Auto-delete sweep cron (disabled by default). Permanently deletes batches whose
 * permanent_delete_after has elapsed, in bounded slices, only when auto-delete is enabled.
 */
function devdsame_autodelete_sweep()
{
    $days = devdsame_get_int('auto_delete_after_days', 0);
    if ($days <= 0) {
        return; // OFF by default — never silently destroys user files.
    }
    global $wpdb;
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    // permanent_delete_after is written with gmdate() (UTC): compare on the same clock, or a site
    // east of UTC would sweep batches hours before their retention ended.
    $now = gmdate('Y-m-d H:i:s');
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches read for due batches; values bound via prepare.
    $batches = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$tb} WHERE status = 'trashed' AND permanent_delete_after IS NOT NULL AND permanent_delete_after <= %s ORDER BY id ASC LIMIT 5",
        $now
    ));
    if ($wpdb->last_error !== '') {
        return; // unknown state: delete nothing this run
    }
    // The cron sweep never works beside a user's job or a synchronous restore: same lock.
    if (!$batches || devdsame_job_active() || !devdsame_acquire_tick_lock()) {
        return;
    }
    try {
        foreach ((array) $batches as $bid) {
            devdsame_reset_stale_claims((int) $bid);
            $rows = devdsame_batch_items_slice((int) $bid, 'trashed', 200);
            if ($rows === null) {
                return; // failed read: never finalise a batch on a guess
            }
            foreach ((array) $rows as $r) {
                devdsame_permanent_delete_item((int) $r->id);
            }
            devdsame_finalize_delete_batch((int) $bid);
        }
    } finally {
        devdsame_release_tick_lock();
    }
    if (function_exists('devdsame_refresh_summary')) {
        devdsame_refresh_summary(devdsame_latest_scan_id(), (int) devdsame_get_int('cleanliness_score', 0));
    }
}
add_action('devdsame_autodelete_sweep', 'devdsame_autodelete_sweep');
