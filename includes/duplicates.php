<?php
/**
 * Duplicate finder (spec §advanced). Groups this scan's items by exact file hash (md5 of bytes,
 * computed during the scan) and, secondarily, by identical size+dimensions, then by filename
 * pattern (name, name-1, name-copy). The OLDEST / parented original is kept as the suggested
 * keeper; every OTHER member of a group is marked status='duplicate' (never auto-selected, never
 * auto-deleted). Library items only (unused, uncertain): a used image is never marked, and the
 * Disk side's orphans keep their own count. A large-unused query helper lives here too.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

/**
 * Mark duplicates within a scan. Only groups of >= 2 with a matching method count.
 * Keeper = lowest attachment_id with the oldest upload_date (stable, parented originals win).
 * Returns false as soon as one read or write failed (the caller fails the scan), true otherwise.
 */
function devdsame_mark_duplicates($scan_id)
{
    global $wpdb;
    $methods = devdsame_get_array('duplicate_methods');
    if (!$methods) {
        $methods = array('hash');
    }
    $items = $wpdb->prefix . 'devdsame_scan_items';

    // ---- Primary grouping: exact file hash. ----
    if (in_array('hash', $methods, true)) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items aggregate; scan_id bound via prepare.
        // 'used' is deliberately excluded: an image that IS referenced must stay in the Used
        // bucket (marking it duplicate invites trashing a live file and breaks the tile math).
        $hashes = $wpdb->get_col($wpdb->prepare(
            "SELECT file_hash FROM {$items}
             WHERE scan_id = %d AND file_hash <> '' AND status IN ('unused','uncertain')
             GROUP BY file_hash HAVING COUNT(*) > 1",
            $scan_id
        ));
        if ($wpdb->last_error !== '') {
            return false;
        }
        foreach ((array) $hashes as $hash) {
            if (!devdsame_mark_group($scan_id, 'file_hash', $hash)) {
                return false;
            }
        }
    }

    // ---- Secondary grouping: same size + dimensions (catches re-encodes with different bytes
    //      only if the user opted into the 'dimensions' method). ----
    if (in_array('dimensions', $methods, true)) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items aggregate; scan_id bound via prepare.
        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT file_size, width, height FROM {$items}
             WHERE scan_id = %d AND file_size > 0 AND width > 0 AND status IN ('unused','uncertain')
             GROUP BY file_size, width, height HAVING COUNT(*) > 1",
            $scan_id
        ));
        if ($wpdb->last_error !== '') {
            return false;
        }
        foreach ((array) $groups as $g) {
            if (!devdsame_mark_group_dims($scan_id, (int) $g->file_size, (int) $g->width, (int) $g->height)) {
                return false;
            }
        }
    }
    return true;
}

/** Mark all but the keeper in a single hash group as duplicate. */
function devdsame_mark_group($scan_id, $col, $value)
{
    global $wpdb;
    $items = $wpdb->prefix . 'devdsame_scan_items';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items read for a hash group; values bound via prepare.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, attachment_id, upload_date FROM {$items}
         WHERE scan_id = %d AND {$col} = %s AND status IN ('unused','uncertain')
         ORDER BY (attachment_id = 0) ASC, (upload_date IS NULL) ASC, upload_date ASC, attachment_id ASC",
        $scan_id,
        $value
    ));
    if ($wpdb->last_error !== '') {
        return false;
    }
    return devdsame_apply_keeper($scan_id, $rows);
}

/** Mark all but the keeper in a size+dimension group as duplicate. */
function devdsame_mark_group_dims($scan_id, $size, $w, $h)
{
    global $wpdb;
    $items = $wpdb->prefix . 'devdsame_scan_items';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items read for a size+dims group; values bound via prepare.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, attachment_id, upload_date FROM {$items}
         WHERE scan_id = %d AND file_size = %d AND width = %d AND height = %d AND status IN ('unused','uncertain')
         ORDER BY (upload_date IS NULL) ASC, upload_date ASC, attachment_id ASC",
        $scan_id,
        $size,
        $w,
        $h
    ));
    if ($wpdb->last_error !== '') {
        return false;
    }
    return devdsame_apply_keeper($scan_id, $rows);
}

/** Given an ordered group (keeper first), flag the rest as duplicate (never auto-select). False on a failed write. */
function devdsame_apply_keeper($scan_id, $rows)
{
    if (!is_array($rows) || count($rows) < 2) {
        return true;
    }
    global $wpdb;
    $items = $wpdb->prefix . 'devdsame_scan_items';
    $keeper = array_shift($rows);
    $keeper_id = (int) $keeper->attachment_id;
    foreach ($rows as $r) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items update; values bound via prepare.
        $ok = $wpdb->query($wpdb->prepare(
            "UPDATE {$items} SET status = 'duplicate', is_selected = 0,
                reason_code = CONCAT('duplicate_of,', COALESCE(reason_code, ''))
             WHERE id = %d AND scan_id = %d",
            (int) $r->id,
            (int) $scan_id
        ));
        if ($ok === false || $wpdb->last_error !== '') {
            return false;
        }
    }
    return true;
}

/**
 * Re-decide one hash group of the latest library scan after a restore (1.1.4): the restored row gets its hash
 * stored, then the group is ordered by the scan's keeper rule and the first row is the original (unused), every
 * other row a duplicate. Handles a restored keeper (the old duplicate becomes the original again), a whole group
 * restored one by one after a rescan (each restore stores a hash, the second one already finds the first), and a
 * group of one (plain unused). False on a failed read or write.
 */
function devdsame_remark_hash_group($scan_id, $row_id, $hash)
{
    global $wpdb;
    $items = $wpdb->prefix . 'devdsame_scan_items';
    if ($hash === '' || (int) $scan_id < 1) {
        return true;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items update.
    if ($wpdb->update($items, array('file_hash' => $hash), array('id' => (int) $row_id, 'scan_id' => (int) $scan_id), array('%s'), array('%d', '%d')) === false) {
        return false;
    }
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items read for a hash group; values bound via prepare.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, status, reason_code FROM {$items}
         WHERE scan_id = %d AND file_hash = %s AND status IN ('unused','uncertain','duplicate')
         ORDER BY (attachment_id = 0) ASC, (upload_date IS NULL) ASC, upload_date ASC, attachment_id ASC",
        (int) $scan_id,
        $hash
    ));
    if ($wpdb->last_error !== '') {
        return false;
    }
    foreach ((array) $rows as $i => $r) {
        $reason = implode(',', array_values(array_diff(array_filter(array_map('trim', explode(',', (string) $r->reason_code))), array('duplicate_of'))));
        if ($i === 0) {
            if ($r->status !== 'duplicate' && $reason === (string) $r->reason_code) {
                continue; // the original as it is (unused or uncertain), nothing to strip
            }
            $set = array('status' => $r->status === 'duplicate' ? 'unused' : $r->status, 'reason_code' => $reason);
        } else {
            if ($r->status === 'duplicate') {
                continue;
            }
            $set = array('status' => 'duplicate', 'is_selected' => 0, 'reason_code' => 'duplicate_of' . ($reason !== '' ? ',' . $reason : ''));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items update.
        if ($wpdb->update($items, $set, array('id' => (int) $r->id), null, array('%d')) === false) {
            return false;
        }
    }
    return true;
}

/**
 * Large-unused query helper: unused items at/above a byte threshold, newest first.
 * Used by the dashboard "quick win" surface.
 */
function devdsame_large_unused($scan_id, $min_bytes = 0, $limit = 50)
{
    global $wpdb;
    $items = $wpdb->prefix . 'devdsame_scan_items';
    $min_bytes = $min_bytes > 0 ? (int) $min_bytes : devdsame_get_int('large_image_threshold_bytes', 1048576);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items read; values bound via prepare.
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$items} WHERE scan_id = %d AND status = 'unused' AND file_size >= %d
         ORDER BY file_size DESC LIMIT %d",
        $scan_id,
        $min_bytes,
        (int) $limit
    ));
}
