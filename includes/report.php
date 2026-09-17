<?php
/**
 * Reporting + exports + the cached hub summary.
 *
 * - devdsame_hub_summary(): the single CACHED snapshot the hub tiles / health / Site Monitor
 *   card read (option devdsame_hub_summary), refreshed after every scan + daily.
 * - devdsame_refresh_summary(): recompute + persist the snapshot and the headline settings.
 * - CSV / JSON streamed exports of scan results + trash batches (nonce + cap guarded).
 * - Storage trend: each scan's totals are appended to a rolling history for the bloat-over-time
 *   sparkline + "unused grew X%" alert.
 * - Scheduled-scan dispatcher (weekly / monthly) using the resumable queue.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

/** Cached hub summary option key. */
function devdsame_summary_option()
{
    return 'devdsame_hub_summary';
}

/** Read the cached summary (always returns a full-shape array). */
function devdsame_hub_summary()
{
    $defaults = array(
        'last_scan_id'           => 0, // latest LIBRARY-covering scan (full/preview/library)
        'last_scan_at'           => 0,
        'last_disk_scan_id'      => 0, // latest DISK-covering scan (full/disk)
        'last_disk_scan_at'      => 0,
        'total_files'            => 0,
        'used_count'             => 0,
        'unused_count'           => 0,
        'uncertain_count'        => 0,
        'orphan_count'           => 0,
        'missing_count'          => 0,
        'duplicate_count'        => 0,
        'library_cleanup_bytes'  => 0, // unused + duplicate bytes (Media Library side)
        'disk_images_count'      => 0, // image files walked on disk in the last disk scan
        'orphan_bytes'           => 0, // orphan file bytes (disk side)
        'disk_images_bytes'      => 0, // total bytes of all image files walked on disk
        'possible_cleanup_bytes' => 0, // library_cleanup_bytes + orphan_bytes
        'total_library_bytes'    => 0,
        'score'                  => 100,
    );
    $opt = get_option(devdsame_summary_option(), array());
    return wp_parse_args(is_array($opt) ? $opt : array(), $defaults);
}

/**
 * Recompute + persist the summary and mirror headline values to settings.
 *
 * The dashboard is TWO tools (Media Library Cleaner + Disk Cleaner) with independent scans,
 * so the summary merges two sides: a library-mode scan only refreshes the library fields and
 * a disk-mode scan only refreshes the orphan fields; full/preview scans refresh both.
 *
 * @param int  $scan_id merge this scan by its mode; 0 = re-merge the latest scan of each side.
 * @param null $score   ignored (kept for back-compat) — the score is derived from the merge.
 */
function devdsame_refresh_summary($scan_id = 0, $score = null)
{
    global $wpdb;
    $scans = $wpdb->prefix . 'devdsame_scans';
    $items = $wpdb->prefix . 'devdsame_scan_items';

    // Start from the current snapshot so the side NOT covered by this refresh is preserved.
    $summary = devdsame_hub_summary();
    $failed = false;

    $sides = array();
    if ($scan_id) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans row read; id bound via prepare.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$scans} WHERE id = %d", (int) $scan_id), ARRAY_A);
        if ($row) {
            $mode = (string) $row['mode'];
            if ($mode !== 'disk') {
                $sides['library'] = $row;
            }
            if ($mode === 'disk' || $mode === 'full') {
                $sides['disk'] = $row;
            }
        }
    } else {
        foreach (array('library', 'disk') as $side) {
            $sid = devdsame_latest_scan_id($side);
            if ($sid) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans row read; id bound via prepare.
                $sides[$side] = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$scans} WHERE id = %d", $sid), ARRAY_A);
            }
        }
    }

    if (!empty($sides['library'])) {
        $row = $sides['library'];
        $sid = (int) $row['id'];
        $summary['last_scan_id']        = $sid;
        $summary['last_scan_at']        = $row['finished_at'] ? strtotime($row['finished_at']) : time();
        // Live count — restores/deletes between scans keep this honest without a rescan.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cheap indexed count over core posts.
        $summary['total_files']         = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s",
            $wpdb->esc_like('image/') . '%'
        ));
        $failed = $failed || $wpdb->last_error !== '';
        $summary['used_count']          = (int) $row['used_count'];
        // Live count — cleaning flips items to 'trashed', so this drops without a rescan.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items count; scan_id bound via prepare.
        $summary['unused_count']        = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$items} WHERE scan_id = %d AND status = 'unused'",
            $sid
        ));
        $failed = $failed || $wpdb->last_error !== '';
        $summary['uncertain_count']     = (int) $row['uncertain_count'];
        $summary['missing_count']       = (int) $row['missing_count'];
        $summary['duplicate_count']     = (int) $row['duplicate_count'];
        $summary['total_library_bytes'] = (int) $row['total_library_bytes'];
        // Library-side cleanup bytes (never includes orphans, even on a full scan).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items aggregate; scan_id bound via prepare.
        $summary['library_cleanup_bytes'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(file_size), 0) FROM {$items} WHERE scan_id = %d AND status IN ('unused', 'duplicate')",
            $sid
        ));
        $failed = $failed || $wpdb->last_error !== '';
    }

    if (!empty($sides['disk'])) {
        $row = $sides['disk'];
        $sid = (int) $row['id'];
        $summary['last_disk_scan_id'] = $sid;
        $summary['last_disk_scan_at'] = $row['finished_at'] ? strtotime($row['finished_at']) : time();
        // Live count — cleaning flips items to 'trashed', so this drops without a rescan.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items count; scan_id bound via prepare.
        $summary['orphan_count']      = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$items} WHERE scan_id = %d AND status = 'orphan'",
            $sid
        ));
        $failed = $failed || $wpdb->last_error !== '';
        $summary['disk_images_count'] = devdsame_get_int('disk_images_scanned', 0);
        $summary['disk_images_bytes'] = devdsame_get_int('disk_images_bytes', 0);
        $failed = $failed || !empty($GLOBALS['devdsame_db_failed']);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items aggregate; scan_id bound via prepare.
        $summary['orphan_bytes'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(file_size), 0) FROM {$items} WHERE scan_id = %d AND status = 'orphan'",
            $sid
        ));
        $failed = $failed || $wpdb->last_error !== '';
    }

    if ($failed) {
        // A failed count would persist a clean-looking site: keep the previous snapshot instead.
        devdsame_record_error('summary_db', __('The media summary could not be refreshed (database read failed); the previous numbers are kept.', 'devdome-safe-media-cleaner'), array());
        return devdsame_hub_summary();
    }
    $summary['possible_cleanup_bytes'] = (int) $summary['library_cleanup_bytes'] + (int) $summary['orphan_bytes'];

    // Cleanliness score = Media Library health only (the Disk Cleaner reports its own bytes).
    // One decimal — the ring shows the exact percentage, not a stepped approximation.
    $total = (int) $summary['total_library_bytes'];
    if ($total > 0) {
        $summary['score'] = round(100 * (1 - min(1, (int) $summary['library_cleanup_bytes'] / $total)), 1);
    } elseif ((int) $summary['total_files'] > 0) {
        $summary['score'] = round(100 * (1 - min(1, (int) $summary['unused_count'] / max(1, (int) $summary['total_files']))), 1);
    }

    update_option(devdsame_summary_option(), $summary, false);

    // Mirror headline values into the settings store (used by the dashboard without a query).
    devdsame_update_setting('last_scan_id', $summary['last_scan_id']);
    devdsame_update_setting('last_scan_at', $summary['last_scan_at']);
    devdsame_update_setting('total_files', $summary['total_files']);
    devdsame_update_setting('used_count', $summary['used_count']);
    devdsame_update_setting('unused_count', $summary['unused_count']);
    devdsame_update_setting('uncertain_count', $summary['uncertain_count']);
    devdsame_update_setting('orphan_count', $summary['orphan_count']);
    devdsame_update_setting('missing_count', $summary['missing_count']);
    devdsame_update_setting('duplicate_count', $summary['duplicate_count']);
    devdsame_update_setting('possible_cleanup_bytes', $summary['possible_cleanup_bytes']);
    devdsame_update_setting('total_library_bytes', $summary['total_library_bytes']);
    devdsame_update_setting('cleanliness_score', $summary['score']);

    // Storage-trend history (rolling, last 52 points).
    devdsame_append_trend($summary);

    // Unused-growth alert.
    devdsame_check_growth_alert($summary);

    return $summary;
}
add_action('devdsame_summary_refresh', function () {
    devdsame_refresh_summary();
});

/** Append a trend point and trim history. */
function devdsame_append_trend($summary)
{
    $hist = devdsame_get_array('storage_trend');
    $hist[] = array(
        't'      => time(),
        'unused' => (int) $summary['possible_cleanup_bytes'],
        'total'  => (int) $summary['total_library_bytes'],
        'files'  => (int) $summary['total_files'],
    );
    if (count($hist) > 52) {
        $hist = array_slice($hist, -52);
    }
    devdsame_update_setting('storage_trend', $hist);
}

/** Push stats to DevDome Monitoring, then raise the local suite alert past the threshold. */
function devdsame_check_growth_alert($summary)
{
    // DevDome Monitoring (opt-in): hand the raw scan stats to the service after every scan.
    // The server keeps the cross-site history, decides threshold crossings and sends the
    // account email — the plugin never composes or delivers alert emails itself.
    if (function_exists('devdsame_push_monitor_stats')) {
        devdsame_push_monitor_stats($summary);
    }
    $threshold = devdsame_get_int('unused_growth_alert', 0);
    if ($threshold <= 0) {
        return;
    }
    if ((int) $summary['possible_cleanup_bytes'] >= $threshold) {
        $size = size_format((int) $summary['possible_cleanup_bytes']);
        if (function_exists('devdcorev1_suite_alert')) {
            devdcorev1_suite_alert(array(
                'slug'     => 'devdome-safe-media-cleaner',
                'severity' => 'warn',
                'title'    => __('Unused media is growing', 'devdome-safe-media-cleaner'),
                'body'     => sprintf(
                    /* translators: %s is a human-readable file size. */
                    __('Your Media Library now has %s of media that appears unused.', 'devdome-safe-media-cleaner'),
                    $size
                ),
                'href'     => admin_url('admin.php?page=devdome-safe-media-cleaner'),
            ));
        }
    }
}

/** Percent storage reduction since the previous trend point (for the "after cleanup" nudge). */
function devdsame_storage_reduction_pct()
{
    $hist = devdsame_get_array('storage_trend');
    $n = count($hist);
    if ($n < 2) {
        return 0;
    }
    $prev = (int) $hist[$n - 2]['total'];
    $now = (int) $hist[$n - 1]['total'];
    if ($prev <= 0) {
        return 0;
    }
    return (int) round(100 * max(0, $prev - $now) / $prev);
}

/* ---------------------------------------------------------------------------
 * Review-screen filter facets (read-only; power the month / file-type / folder controls).
 * ------------------------------------------------------------------------- */

/** Distinct upload months (YYYY-MM) present in a scan, newest first. */
function devdsame_scan_months($scan_id)
{
    global $wpdb;
    $items = $wpdb->prefix . 'devdsame_scan_items';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items facet read; scan_id bound via prepare.
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT DATE_FORMAT(upload_date, '%%Y-%%m') AS ym FROM {$items}
         WHERE scan_id = %d AND upload_date IS NOT NULL ORDER BY ym DESC",
        (int) $scan_id
    ));
    return array_values(array_filter((array) $rows));
}

/** Distinct mime types present in a scan, alphabetical. */
function devdsame_scan_mimes($scan_id)
{
    global $wpdb;
    $items = $wpdb->prefix . 'devdsame_scan_items';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items facet read; scan_id bound via prepare.
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT mime_type FROM {$items} WHERE scan_id = %d AND mime_type <> '' ORDER BY mime_type ASC",
        (int) $scan_id
    ));
    return array_values(array_filter((array) $rows));
}

/** Distinct top-level uploads folders (e.g. "2026/06") present in a scan, newest first. */
function devdsame_scan_folders($scan_id)
{
    global $wpdb;
    $items = $wpdb->prefix . 'devdsame_scan_items';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items facet read; scan_id bound via prepare.
    // Every distinct URL, read in keyed pages (a flat LIMIT hid the folders of a big scan).
    $rows = array();
    $after = '';
    do {
        $page = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT file_url FROM {$items} WHERE scan_id = %d AND file_url <> '' AND file_url > %s ORDER BY file_url ASC LIMIT 5000",
            (int) $scan_id,
            $after
        ));
        if ($wpdb->last_error !== '' || !$page) {
            break;
        }
        $rows = array_merge($rows, $page);
        $after = (string) end($page);
    } while (count($page) === 5000);
    $base = devdsame_uploads_baseurl();
    $folders = array();
    foreach ((array) $rows as $url) {
        $rel = '';
        if (strpos($url, $base . '/') === 0) {
            $rel = substr($url, strlen($base) + 1);
        }
        if ($rel === '') {
            continue;
        }
        // Keep "YYYY/MM" style two-segment folders; fall back to the first segment otherwise.
        $parts = explode('/', $rel);
        if (count($parts) >= 3 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
            $folders[$parts[0] . '/' . $parts[1]] = true;
        } elseif (count($parts) >= 2) {
            $folders[$parts[0]] = true;
        }
    }
    $folders = array_keys($folders);
    rsort($folders);
    return array_slice($folders, 0, 60);
}

/* ---------------------------------------------------------------------------
 * Streamed exports (nonce + cap guarded download).
 * ------------------------------------------------------------------------- */

/** admin-post handler: export scan results as CSV or JSON. */
function devdsame_handle_export()
{
    if (!current_user_can(devdsame_capability())) {
        wp_die(esc_html__('You do not have permission to export.', 'devdome-safe-media-cleaner'));
    }
    check_admin_referer('devdsame_export', '_mcx');

    $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : 'csv';
    $what = isset($_GET['what']) ? sanitize_key(wp_unslash($_GET['what'])) : 'scan';
    $scan_id = isset($_GET['scan_id']) ? (int) $_GET['scan_id'] : devdsame_latest_scan_id();
    $format = in_array($format, array('csv', 'json'), true) ? $format : 'csv';

    if ($what === 'trash') {
        devdsame_export_trash($format);
    } elseif ($what === 'errors') {
        devdsame_export_errors($format);
    } else {
        devdsame_export_scan($scan_id, $format);
    }
    exit;
}
add_action('admin_post_devdsame_export', 'devdsame_handle_export');

/**
 * CSV-safe cell: defuse spreadsheet formula injection by prefixing a leading =,+,-,@ (or a
 * leading tab/CR that some apps treat as a formula trigger) with a single quote, then quote-escape.
 */
function devdsame_csv_cell($value)
{
    $v = (string) $value;
    if ($v !== '' && in_array($v[0], array('=', '+', '-', '@', "\t", "\r"), true)) {
        $v = "'" . $v;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}

/** Stream the persisted error log as CSV/JSON (for support / view-details). */
function devdsame_export_errors($format)
{
    $log = devdsame_get_array('error_log');
    $cols = array('at', 'code', 'message', 'context');

    nocache_headers();
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="devdome-media-errors.json"');
        echo wp_json_encode(array_values($log));
        return;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="devdome-media-errors.csv"');
    echo esc_html(implode(',', $cols)) . "\n";
    foreach ($log as $e) {
        $row = array(
            isset($e['at']) ? gmdate('Y-m-d H:i:s', (int) $e['at']) : '',
            isset($e['code']) ? $e['code'] : '',
            isset($e['message']) ? $e['message'] : '',
            isset($e['context']) ? wp_json_encode($e['context']) : '',
        );
        $cells = array();
        foreach ($row as $v) {
            $cells[] = devdsame_csv_cell($v);
        }
        echo implode(',', $cells) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV row, cells formula-guarded + quote-escaped for a file download.
    }
}

/** Stream a scan's scan_items as CSV/JSON. */
function devdsame_export_scan($scan_id, $format)
{
    global $wpdb;
    $items = $wpdb->prefix . 'devdsame_scan_items';
    $cols = array('id', 'attachment_id', 'file_url', 'file_size', 'width', 'height', 'mime_type', 'upload_date', 'status', 'confidence', 'reason_code', 'references_found');

    $filename = 'devdome-media-scan-' . (int) $scan_id . '.' . $format;
    nocache_headers();
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
    } else {
        header('Content-Type: text/csv; charset=utf-8');
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $last = 0;
    $first = true;
    if ($format === 'json') {
        echo '[';
    } else {
        echo esc_html(implode(',', $cols)) . "\n";
    }
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked export read over internal scan_items; bounds bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$items} WHERE scan_id = %d AND id > %d ORDER BY id ASC LIMIT %d",
            (int) $scan_id,
            $last,
            500
        ), ARRAY_A);
        if ($wpdb->last_error !== '') {
            echo "\nEXPORT INCOMPLETE: a database read failed; the rows above are not the whole result.\n";
            exit;
        }
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $last = (int) $r['id'];
            if ($format === 'json') {
                $line = array();
                foreach ($cols as $c) {
                    $line[$c] = isset($r[$c]) ? $r[$c] : '';
                }
                echo ($first ? '' : ',') . wp_json_encode($line);
                $first = false;
            } else {
                $vals = array();
                foreach ($cols as $c) {
                    $vals[] = devdsame_csv_cell(isset($r[$c]) ? $r[$c] : '');
                }
                echo implode(',', $vals) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV row, cells formula-guarded + quote-escaped for a file download.
            }
        }
    } while (true);
    if ($format === 'json') {
        echo ']';
    }
}

/** Stream all trash items as CSV/JSON. */
function devdsame_export_trash($format)
{
    global $wpdb;
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    $cols = array('id', 'batch_id', 'attachment_id', 'original_url', 'rel_path', 'file_size', 'moved_at', 'restored_at', 'permanently_deleted_at', 'status');

    nocache_headers();
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
    } else {
        header('Content-Type: text/csv; charset=utf-8');
    }
    header('Content-Disposition: attachment; filename="devdome-media-trash.' . $format . '"');

    if ($format === 'json') {
        echo '[';
    } else {
        echo esc_html(implode(',', $cols)) . "\n";
    }
    $last = 0;
    $first = true;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked export read over internal trash_items; bounds bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$ti} WHERE id > %d ORDER BY id ASC LIMIT %d", $last, 500), ARRAY_A);
        if ($wpdb->last_error !== '') {
            echo "\nEXPORT INCOMPLETE: a database read failed; the rows above are not the whole result.\n";
            exit;
        }
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $last = (int) $r['id'];
            if ($format === 'json') {
                $line = array();
                foreach ($cols as $c) {
                    $line[$c] = isset($r[$c]) ? $r[$c] : '';
                }
                echo ($first ? '' : ',') . wp_json_encode($line);
                $first = false;
            } else {
                $vals = array();
                foreach ($cols as $c) {
                    $vals[] = devdsame_csv_cell(isset($r[$c]) ? $r[$c] : '');
                }
                echo implode(',', $vals) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV row, cells formula-guarded + quote-escaped for a file download.
            }
        }
    } while (true);
    if ($format === 'json') {
        echo ']';
    }
}

/* ---------------------------------------------------------------------------
 * Scheduled-scan dispatcher.
 * ------------------------------------------------------------------------- */

/** Daily cron: kick a background scan on the chosen weekly/monthly cadence. */
function devdsame_scheduled_scan_dispatch()
{
    // "Every N days" (0 = off). Back-compat: old weekly/monthly string maps to 7/30.
    $days = devdsame_get_int('scheduled_scan_days', 0);
    if (!$days) {
        $cadence = (string) devdsame_get_setting('scheduled_scan', 'off');
        $days = $cadence === 'weekly' ? 7 : ($cadence === 'monthly' ? 30 : 0);
    }
    if ($days < 1) {
        return;
    }
    $last = devdsame_get_int('last_scheduled_scan_at', 0);
    $interval = $days * DAY_IN_SECONDS;
    if (time() - $last < $interval) {
        return;
    }
    // Don't stomp a running job.
    $job = function_exists('devdsame_get_job') ? devdsame_get_job() : null;
    if ($job && in_array($job['status'], array('running', 'paused'), true)) {
        return;
    }
    // Stamp the run only when a scan really started: a refused start must not skip a whole interval.
    if (!is_wp_error(devdsame_start_job('scan'))) {
        devdsame_update_setting('last_scheduled_scan_at', time());
    }
}
add_action('devdsame_scheduled_scan', 'devdsame_scheduled_scan_dispatch');
