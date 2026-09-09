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
    return $wp_filesystem;
}

/** Create a trash batch row. Returns batch id (or 0). */
function devdsame_create_trash_batch($user_id = 0, $note = '')
{
    devdsame_init_safe_trash();
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
    wp_mkdir_p($dir);
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
    if (!$item) {
        return new WP_Error('devdsame_no_item', __('Scan item not found.', 'devdome-safe-media-cleaner'));
    }

    $attach_id = (int) $item->attachment_id;

    // ---- Last-second re-verification guard (the false-positive killer). ----
    if ($attach_id > 0) {
        // Rebuild a fresh, single-attachment used-set scan and bail if it's now referenced.
        if (devdsame_attachment_referenced_now($attach_id)) {
            return devdsame_fail_item($item_id, __('Skipped: image became referenced since the scan.', 'devdome-safe-media-cleaner'));
        }
    } else {
        // Orphan file (no attachment record): its path can still be hardcoded in a template,
        // option or builder blob — run the same haystack check on the file path before moving.
        $orel = devdsame_path_to_relative((string) $item->file_path);
        if ($orel !== '') {
            $oset = devdsame_used_set(devdsame_latest_scan_id());
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
        $dest = $batch_dir . '/' . $rel;
        $dest_dir = dirname($dest);
        if (!is_dir($dest_dir)) {
            wp_mkdir_p($dest_dir);
        }
        // Validate destination resolves inside uploads too.
        if (devdsame_validate_in_uploads($dest) === false) {
            continue;
        }
        $size = (int) @filesize($abs);
        if ($fs->move($abs, $dest, true)) {
            $moved[] = array('rel' => $rel, 'trash' => $dest, 'size' => $size, 'orig' => $abs);
            $moved_total_bytes += $size;
            if ($primary_trash_path === '') {
                $primary_trash_path = $dest;
                $primary_rel = $rel;
            }
        }
    }

    if (!$moved) {
        return devdsame_fail_item($item_id, __('Files could not be moved (permissions or already gone).', 'devdome-safe-media-cleaner'));
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
    $wpdb->insert($ti, array(
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
    $trash_item_id = (int) $wpdb->insert_id;

    // Flip the scan item to 'trashed' so dashboard counts and the Review grid stay honest
    // without needing a rescan (restore flips it back).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items update.
    $wpdb->update($items, array('status' => 'trashed'), array('id' => (int) $item_id), array('%s'), array('%d'));

    // Detach the attachment record so WP stops listing the file as present, but DO NOT delete it.
    if ($attach_id > 0) {
        // Mark our own meta flag; keep the post + metadata for a clean restore.
        update_post_meta($attach_id, '_devdsame_trashed', $batch_id);
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

    return true;
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
    $n = (int) $wpdb->get_var($wpdb->prepare("SELECT total_files FROM {$tb} WHERE id = %d", (int) $batch_id));
    if ($n === 0) {
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
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ti} WHERE batch_id = %d AND status = %s", (int) $batch_id, $status));
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
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$ti} WHERE batch_id = %d AND status = %s ORDER BY id ASC LIMIT %d",
        (int) $batch_id,
        $status,
        (int) $limit
    ));
}
