<?php
/**
 * Scan engine + classifier.
 *
 * Creates a scan session, iterates image attachments in ID-bounded chunks (never load-all),
 * resolves each attachment's file path + size variants + hash + size + dims + mime, asks the
 * used-set whether it is referenced, then assigns:
 *   - status: used | unused | uncertain | missing | duplicate (+ orphan/missing added by filesystem.php)
 *   - confidence: 0-100 from the spec factors
 *   - reason_code(s): structured, human-readable
 * and applies the default-protection rules (recent upload, published parent, in options,
 * WooCommerce, reusable block/template, theme/customizer, never-scan folder, user-protected,
 * below-threshold) to force-protect borderline items. Results are batched into scan_items and
 * the scan row + cached summary are rolled up. Duplicate marking happens in duplicates.php after.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

/**
 * Start a new scan session. Returns scan id (or 0 on failure).
 *
 * @param string $mode full | preview | library | disk — 'library' covers Media Library
 *                     attachments only, 'disk' covers the uploads-folder orphan pass only,
 *                     'full'/'preview' cover both (cron, CLI, retry).
 */
function devdsame_create_scan($mode = 'full', $user_id = 0)
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdsame_scans';
    $now = current_time('mysql');
    $mode = in_array($mode, array('full', 'preview', 'library', 'disk'), true) ? $mode : 'full';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table insert.
    $ok = $wpdb->insert($table, array(
        'status'     => 'running',
        'mode'       => $mode,
        'started_at' => $now,
        'user_id'    => (int) $user_id,
    ), array('%s', '%s', '%s', '%d'));
    return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * Scan one chunk of attachments for a scan.
 *
 * @param int $scan_id
 * @param int $after_id last attachment id processed (cursor)
 * @param int $limit    attachments this tick
 * @return array{processed:int, last_id:int, done:bool}
 */
function devdsame_scan_chunk($scan_id, $after_id, $limit)
{
    global $wpdb;
    $limit = max(25, min(1000, (int) $limit));

    // Image + media attachments only (skip non-image mime types — they have no thumbnails and
    // the spec is media/images). We include all common media image types.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked scan over core posts; bounds bound via prepare.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_parent, post_date, post_mime_type FROM {$wpdb->posts}
         WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d
         ORDER BY ID ASC LIMIT %d",
        $wpdb->esc_like('image/') . '%',
        (int) $after_id,
        $limit
    ));

    if (!$rows) {
        return array('processed' => 0, 'last_id' => (int) $after_id, 'done' => true);
    }

    $set = devdsame_used_set($scan_id);
    $threshold = devdsame_get_int('confidence_threshold', 75);
    $recent_days = devdsame_get_int('recent_upload_protection_days', 30);
    $protect_recent = (int) devdsame_get_int('protect_recent', 1);
    $protect_woo = (int) devdsame_get_int('protect_woocommerce', 1);
    $protect_theme = (int) devdsame_get_int('protect_theme_assets', 1);
    $never_folders = devdsame_get_array('never_scan_folders');
    $protected_ids = devdsame_protected_ids();
    $ignored_ids = devdsame_ignored_ids();
    $builder_unreadable = function_exists('devdsame_has_unreadable_builder_data') ? devdsame_has_unreadable_builder_data() : false;
    // If the URL/path haystack was capped for memory, we cannot prove a URL-only reference is
    // absent, so non-ID-referenced items are downgraded to Uncertain (never auto-selected).
    $haystack_truncated = !empty($set['truncated']);
    $recent_cut = $recent_days > 0 ? (time() - $recent_days * DAY_IN_SECONDS) : 0;

    $items_table = $wpdb->prefix . 'devdsame_scan_items';
    $now = current_time('mysql');
    $last_id = (int) $after_id;
    $processed = 0;

    // Roll-up accumulators for this chunk.
    $acc = array('used' => 0, 'unused' => 0, 'uncertain' => 0, 'missing' => 0, 'bytes_unused' => 0, 'bytes_total' => 0);

    foreach ($rows as $r) {
        $last_id = (int) $r->ID;
        $processed++;
        $attach_id = (int) $r->ID;

        $files = devdsame_attachment_files($attach_id);
        $main_path = !empty($files['paths']) ? $files['paths'][0] : get_attached_file($attach_id);
        $rel = $main_path ? devdsame_path_to_relative($main_path) : '';
        $file_url = $rel !== '' ? devdsame_uploads_baseurl() . '/' . $rel : (string) wp_get_attachment_url($attach_id);

        $exists = $main_path && is_file($main_path);
        $size = $exists ? (int) @filesize($main_path) : 0;
        // Add variant bytes (thumbnails count toward possible cleanup).
        $variant_bytes = 0;
        foreach ($files['paths'] as $vp) {
            if ($vp !== $main_path && is_file($vp)) {
                $variant_bytes += (int) @filesize($vp);
            }
        }
        $total_attachment_bytes = $size + $variant_bytes;

        $meta = wp_get_attachment_metadata($attach_id);
        $width = !empty($meta['width']) ? (int) $meta['width'] : 0;
        $height = !empty($meta['height']) ? (int) $meta['height'] : 0;
        $mime = (string) $r->post_mime_type;
        $upload_ts = strtotime((string) $r->post_date);

        $reasons = array();
        $matches = 0;
        $status = 'unused';
        $confidence = 100;
        $hash = '';

        if (get_post_meta($attach_id, '_devdsame_trashed', true)) {
            // Lives in Recycle Bin right now — never re-list it as unused or missing.
            // Restoring the batch flips it back; cleaning again would only skip it.
            $status = 'trashed';
            $confidence = 0;
            $reasons[] = 'in_safe_trash';
        } elseif (!$exists) {
            // Missing file: attachment row exists, physical original gone.
            $status = 'missing';
            $confidence = 100;
            $reasons[] = 'missing_file';
            $acc['missing']++;
        } else {
            $hash = devdsame_file_hash($main_path);
            $acc['bytes_total'] += $total_attachment_bytes;

            $referenced = devdsame_is_referenced($set, $attach_id, $files, $matches);

            if ($referenced) {
                $status = 'used';
                $confidence = 0;
                if (isset($set['ids'][$attach_id])) {
                    $reasons[] = 'in_post';
                }
                $acc['used']++;
            } else {
                // Build the "why unused" reason list (transparency differentiator).
                $reasons[] = 'no_post_reference';
                $reasons[] = 'no_meta';
                $reasons[] = 'no_option';
                if (!isset($set['ids'][$attach_id])) {
                    $reasons[] = 'no_featured';
                    $reasons[] = 'no_builder';
                }
                $confidence = 100;

                // ---- Default-protection rules: downgrade to used/protected or uncertain. ----
                $protected_reason = '';

                // User protected / ignored forever (survives re-scans).
                if (isset($protected_ids[$attach_id])) {
                    $protected_reason = 'user_protected';
                } elseif (isset($ignored_ids[$attach_id])) {
                    $protected_reason = 'user_protected';
                }

                // Recent upload. Images re-registered by a backup restore are exempt — their
                // post_date is the restore moment, not a real fresh upload.
                if ($protected_reason === '' && $protect_recent && $recent_cut && $upload_ts && $upload_ts >= $recent_cut
                    && !get_post_meta($attach_id, '_devdsame_reregistered', true)) {
                    $protected_reason = 'recent_upload';
                }

                // Published parent.
                if ($protected_reason === '' && (int) $r->post_parent > 0) {
                    $pstatus = get_post_status((int) $r->post_parent);
                    if ($pstatus === 'publish' || $pstatus === 'private') {
                        $protected_reason = 'has_published_parent';
                    }
                }

                // WooCommerce image (parent is a product / variation).
                if ($protected_reason === '' && $protect_woo && devdsame_has_woocommerce() && (int) $r->post_parent > 0) {
                    $ptype = get_post_type((int) $r->post_parent);
                    if ($ptype === 'product' || $ptype === 'product_variation') {
                        $protected_reason = 'in_woocommerce';
                    }
                }

                // Never-scan folder.
                if ($protected_reason === '' && $rel !== '' && $never_folders) {
                    foreach ($never_folders as $folder) {
                        $folder = trim((string) $folder, '/');
                        if ($folder !== '' && strpos($rel, $folder . '/') === 0) {
                            $protected_reason = 'never_scan_folder';
                            break;
                        }
                    }
                }

                if ($protected_reason !== '') {
                    $status = 'used';
                    $confidence = 0;
                    $reasons[] = $protected_reason;
                    $acc['used']++;
                } else {
                    // Uncertain if a builder blob is unreadable OR the URL haystack was capped for
                    // memory (either way we can't prove the image is unused).
                    if ($builder_unreadable || $haystack_truncated) {
                        $status = 'uncertain';
                        $confidence = 50;
                        $reasons[] = $builder_unreadable ? 'builder_unreadable' : 'haystack_truncated';
                        $acc['uncertain']++;
                    } else {
                        // Confidence factors: stronger when older + no parent + no matches.
                        $confidence = devdsame_compute_confidence($r, $upload_ts, $recent_cut);
                        if ($confidence < $threshold) {
                            $status = 'uncertain';
                            $reasons[] = 'below_threshold';
                            $acc['uncertain']++;
                        } else {
                            $status = 'unused';
                            $acc['unused']++;
                            $acc['bytes_unused'] += $total_attachment_bytes;
                        }
                    }
                }
            }
        }

        // Auto-select ONLY clean "unused" with confidence >= threshold. Never uncertain/missing.
        $is_selected = ($status === 'unused' && $confidence >= $threshold) ? 1 : 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items table insert.
        $wpdb->insert($items_table, array(
            'scan_id'          => $scan_id,
            'attachment_id'    => $attach_id,
            'file_path'        => (string) $main_path,
            'file_url'         => $file_url,
            'file_hash'        => $hash,
            'file_size'        => $total_attachment_bytes,
            'width'            => $width,
            'height'           => $height,
            'mime_type'        => $mime,
            'upload_date'      => $upload_ts ? gmdate('Y-m-d H:i:s', $upload_ts) : null,
            'status'           => $status,
            'confidence'       => $confidence,
            'reason_code'      => implode(',', array_unique($reasons)),
            'references_found' => $matches,
            'is_selected'      => $is_selected,
            'created_at'       => $now,
        ), array('%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s'));

        // Memory guard: drop the cached attachment metadata for this ID.
        clean_post_cache($attach_id);
    }

    // Roll the chunk's accumulators into the scan row.
    devdsame_accumulate_scan($scan_id, count($rows), $acc);

    unset($rows);
    if (function_exists('gc_collect_cycles') && ($processed % 1000) === 0) {
        gc_collect_cycles();
    }

    return array('processed' => $processed, 'last_id' => $last_id, 'done' => false);
}

/** Confidence (0-100): older + parentless + zero matches = higher. */
function devdsame_compute_confidence($post_row, $upload_ts, $recent_cut)
{
    $score = 60; // base for a non-referenced image past the recent window
    if ((int) $post_row->post_parent === 0) {
        $score += 20; // never attached to a post
    }
    if ($upload_ts && $recent_cut && $upload_ts < ($recent_cut - 180 * DAY_IN_SECONDS)) {
        $score += 20; // older than ~6 months past the protection window
    } elseif ($upload_ts && $recent_cut && $upload_ts < $recent_cut) {
        $score += 10;
    }
    return max(0, min(100, $score));
}

/** Add a chunk's counts to the scan row (atomic increments). */
function devdsame_accumulate_scan($scan_id, $attachments, $acc)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdsame_scans';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table atomic increment; all values bound via prepare.
    $wpdb->query($wpdb->prepare(
        "UPDATE {$t} SET
            total_attachments = total_attachments + %d,
            total_files = total_files + %d,
            used_count = used_count + %d,
            unused_count = unused_count + %d,
            uncertain_count = uncertain_count + %d,
            missing_count = missing_count + %d,
            possible_cleanup_bytes = possible_cleanup_bytes + %d,
            total_library_bytes = total_library_bytes + %d
         WHERE id = %d",
        (int) $attachments,
        (int) $attachments,
        (int) $acc['used'],
        (int) $acc['unused'],
        (int) $acc['uncertain'],
        (int) $acc['missing'],
        (int) $acc['bytes_unused'],
        (int) $acc['bytes_total'],
        (int) $scan_id
    ));
}

/** Count of image attachments to scan (for progress/ETA). */
function devdsame_total_attachments()
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single count over core posts.
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'");
}

/** Finalize a scan: duplicate pass, score, cached summary, cleanup. */
function devdsame_finalize_scan($scan_id)
{
    global $wpdb;

    // No duplicate reclassification: an identical copy is simply unused (or orphan) like
    // anything else — a separate "duplicate" bucket only confused the tile math.

    $scans = $wpdb->prefix . 'devdsame_scans';
    $items = $wpdb->prefix . 'devdsame_scan_items';

    // Recompute EVERY headline count + the possible-cleanup byte total straight from the items
    // table now that duplicate/orphan marking is done. The per-chunk accumulators wrote the
    // pre-duplicate numbers; mark_duplicates() then moved items out of unused/uncertain into
    // 'duplicate', and the filesystem pass inserted 'orphan' rows. A single authoritative
    // GROUP BY keeps unused_count / uncertain_count / used_count / duplicate_count / orphan_count /
    // missing_count and possible_cleanup_bytes consistent (no over-reporting on sites with dups).
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items aggregate; scan_id bound via prepare.
    $by_status = $wpdb->get_results($wpdb->prepare(
        "SELECT status, COUNT(*) AS n FROM {$items} WHERE scan_id = %d GROUP BY status",
        $scan_id
    ));
    $counts = array('used' => 0, 'unused' => 0, 'uncertain' => 0, 'missing' => 0, 'duplicate' => 0, 'orphan' => 0);
    foreach ((array) $by_status as $row) {
        $st = (string) $row->status;
        if (isset($counts[$st])) {
            $counts[$st] = (int) $row->n;
        }
    }

    // Possible cleanup = bytes of everything the user COULD clean from the review screen:
    // unused + duplicates + orphan files. (Uncertain/missing are excluded — uncertain is never
    // auto-selected and missing has no bytes on disk.) This is what makes the headline number
    // match the tiles: 987 duplicates + orphans can never show "0 B possible cleanup" again.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items aggregate; scan_id bound via prepare.
    $cleanup_bytes = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(file_size), 0) FROM {$items} WHERE scan_id = %d AND status IN ('unused', 'duplicate', 'orphan')",
        $scan_id
    ));

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table update.
    $wpdb->update($scans, array(
        'status'                 => 'completed',
        'finished_at'            => current_time('mysql'),
        'used_count'             => $counts['used'],
        'unused_count'           => $counts['unused'],
        'uncertain_count'        => $counts['uncertain'],
        'missing_count'          => $counts['missing'],
        'duplicate_count'        => $counts['duplicate'],
        'orphan_count'           => $counts['orphan'],
        'possible_cleanup_bytes' => $cleanup_bytes,
    ), array('id' => $scan_id), array('%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d'), array('%d'));

    devdsame_flush_used_set();

    // Privacy-safe aggregate metrics (incremented locally; only sent on opt-in).
    if (function_exists('devdsame_metric_bump')) {
        devdsame_metric_bump('scans', 1);
        devdsame_metric_bump('images_scanned', (int) $counts['used'] + (int) $counts['unused'] + (int) $counts['uncertain'] + (int) $counts['missing']);
        devdsame_metric_bump('cleanup_bytes_found', (int) $cleanup_bytes);
    }

    // Score + cached summary.
    $score = devdsame_compute_score($scan_id);
    if (function_exists('devdsame_refresh_summary')) {
        devdsame_refresh_summary($scan_id, $score);
    }
    return $score;
}

/**
 * Cleanliness score (0-100): the share of the library that is NOT unused/orphan bloat.
 * 100 = nothing to clean; lower = more bloat relative to total bytes.
 */
function devdsame_compute_score($scan_id)
{
    global $wpdb;
    $scans = $wpdb->prefix . 'devdsame_scans';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans row read; scan_id bound via prepare.
    $row = $wpdb->get_row($wpdb->prepare("SELECT possible_cleanup_bytes, total_library_bytes, unused_count, total_attachments FROM {$scans} WHERE id=%d", $scan_id));
    if (!$row) {
        return 100;
    }
    $total = (int) $row->total_library_bytes;
    $bloat = (int) $row->possible_cleanup_bytes;
    if ($total <= 0) {
        // Fall back to count-based when byte totals are unavailable.
        $ta = max(1, (int) $row->total_attachments);
        return (int) round(100 * (1 - min(1, (int) $row->unused_count / $ta)));
    }
    $ratio = min(1, $bloat / $total);
    return (int) round(100 * (1 - $ratio));
}

/** Map of user-protected attachment IDs => true. */
function devdsame_protected_ids()
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdsame_protected';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal protected table read.
    $ids = $wpdb->get_col($wpdb->prepare("SELECT attachment_id FROM {$t} WHERE mode = %s", 'protect'));
    $out = array();
    foreach ((array) $ids as $id) {
        $out[(int) $id] = true;
    }
    return $out;
}

/** Map of ignored-forever attachment IDs => true. */
function devdsame_ignored_ids()
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdsame_protected';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal protected table read.
    $ids = $wpdb->get_col($wpdb->prepare("SELECT attachment_id FROM {$t} WHERE mode = %s", 'ignore'));
    $out = array();
    foreach ((array) $ids as $id) {
        $out[(int) $id] = true;
    }
    return $out;
}

/** Add an attachment to the persistent protect/ignore list. */
function devdsame_set_protected($attachment_id, $mode = 'protect')
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdsame_protected';
    $mode = $mode === 'ignore' ? 'ignore' : 'protect';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal protected table upsert; values bound via prepare.
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$t} (attachment_id, mode, created_at, user_id) VALUES (%d, %s, %s, %d)
         ON DUPLICATE KEY UPDATE mode = VALUES(mode)",
        (int) $attachment_id,
        $mode,
        current_time('mysql'),
        get_current_user_id()
    ));
}

/** Remove an attachment from the protect/ignore list. */
function devdsame_unset_protected($attachment_id)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdsame_protected';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal protected table delete.
    $wpdb->delete($t, array('attachment_id' => (int) $attachment_id), array('%d'));
}

/**
 * The most recent completed scan id (0 if none).
 *
 * @param string $scope '' = any mode; 'library' = scans that covered the Media Library
 *                      (full/preview/library); 'disk' = scans that covered the uploads
 *                      folder (full/disk).
 */
function devdsame_latest_scan_id($scope = '')
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdsame_scans';
    if ($scope === 'library') {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table read for latest completed scan.
        return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE status = %s AND mode IN ('full','preview','library') ORDER BY id DESC LIMIT 1", 'completed'));
    }
    if ($scope === 'disk') {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table read for latest completed scan.
        return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE status = %s AND mode IN ('full','disk') ORDER BY id DESC LIMIT 1", 'completed'));
    }
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table read for latest completed scan.
    return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE status = %s ORDER BY id DESC LIMIT 1", 'completed'));
}
