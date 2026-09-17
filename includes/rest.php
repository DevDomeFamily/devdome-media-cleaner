<?php
/**
 * REST controller — namespace devdsame/v1. Powers the async review UI:
 * start-scan, scan-progress, scan-results (paginated + filtered), trash, restore,
 * permanent-delete, protect/ignore. Every route: permission_callback = capability check,
 * nonce via X-WP-Nonce (cookie auth), all input sanitized + allowlisted, all SQL prepared.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

add_action('rest_api_init', 'devdsame_register_routes');

function devdsame_register_routes()
{
    $ns = 'devdsame/v1';
    $perm = 'devdsame_rest_permission';

    register_rest_route($ns, '/start-scan', array(
        'methods'             => 'POST',
        'callback'            => 'devdsame_rest_start_scan',
        'permission_callback' => $perm,
        'args'                => array(
            'mode'  => array('type' => 'string', 'default' => 'scan'),
            'scope' => array('type' => 'string', 'default' => 'full'),
        ),
    ));

    register_rest_route($ns, '/start-backup', array(
        'methods'             => 'POST',
        'callback'            => 'devdsame_rest_start_backup',
        'permission_callback' => $perm,
        'args'                => array(
            'scope' => array('type' => 'string', 'default' => 'library'),
        ),
    ));

    register_rest_route($ns, '/upload-backup', array(
        'methods'             => 'POST',
        'callback'            => 'devdsame_rest_upload_backup',
        'permission_callback' => $perm,
    ));

    register_rest_route($ns, '/clear-trash-history', array(
        'methods'             => 'POST',
        'callback'            => 'devdsame_rest_clear_trash_history',
        'permission_callback' => $perm,
    ));

    register_rest_route($ns, '/restore-backup', array(
        'methods'             => 'POST',
        'callback'            => 'devdsame_rest_restore_backup',
        'permission_callback' => $perm,
    ));

    register_rest_route($ns, '/clean', array(
        'methods'             => 'POST',
        'callback'            => 'devdsame_rest_clean',
        'permission_callback' => $perm,
    ));

    register_rest_route($ns, '/scan-progress', array(
        'methods'             => 'GET',
        'callback'            => 'devdsame_rest_progress',
        'permission_callback' => $perm,
    ));

    // The loopback runner's route: advances one slice with no user session (internal key).
    register_rest_route($ns, '/tick', array(
        'methods'             => 'POST',
        'callback'            => 'devdsame_rest_tick',
        'permission_callback' => 'devdsame_rest_permission_tick',
    ));

    register_rest_route($ns, '/job-control', array(
        'methods'             => 'POST',
        'callback'            => 'devdsame_rest_job_control',
        'permission_callback' => $perm,
        'args'                => array(
            'action' => array('type' => 'string', 'required' => true),
        ),
    ));

    register_rest_route($ns, '/scan-results', array(
        'methods'             => 'GET',
        'callback'            => 'devdsame_rest_results',
        'permission_callback' => $perm,
    ));

    register_rest_route($ns, '/trash', array(
        'methods'             => 'POST',
        'callback'            => 'devdsame_rest_trash',
        'permission_callback' => $perm,
    ));

    register_rest_route($ns, '/restore', array(
        'methods'             => 'POST',
        'callback'            => 'devdsame_rest_restore',
        'permission_callback' => $perm,
    ));

    register_rest_route($ns, '/permanent-delete', array(
        'methods'             => 'POST',
        'callback'            => 'devdsame_rest_delete',
        'permission_callback' => $perm,
    ));

    register_rest_route($ns, '/protect', array(
        'methods'             => 'POST',
        'callback'            => 'devdsame_rest_protect',
        'permission_callback' => $perm,
    ));
}

/** Permission: capability. (Cookie-auth nonce is validated by core for cookie-nonce requests.) */
function devdsame_rest_permission(WP_REST_Request $request)
{
    if (!current_user_can(devdsame_capability())) {
        return new WP_Error('devdsame_forbidden', __('You do not have permission.', 'devdome-safe-media-cleaner'), array('status' => 403));
    }
    return true;
}

/** The loopback runner presents the internal tick key instead of a user session. */
function devdsame_rest_is_internal_tick()
{
    $hdr = isset($_SERVER['HTTP_X_DEVDSAME_TICK']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_DEVDSAME_TICK'])) : '';
    return $hdr !== '' && hash_equals(devdsame_tick_key(), $hdr);
}
function devdsame_rest_permission_tick(WP_REST_Request $request)
{
    return devdsame_rest_is_internal_tick() ? true : devdsame_rest_permission($request);
}

/**
 * POST /tick — the loopback runner. Nobody is waiting on this response, so answer at once,
 * keep working after the caller hangs up, run one tick and let that tick spawn the next one.
 * That chain is what finishes a clean, scan or backup with no browser open and no visitors.
 */
function devdsame_rest_tick(WP_REST_Request $request)
{
    if (!devdsame_rest_is_internal_tick()) {
        devdsame_run_tick();
        return rest_ensure_response(devdsame_job_progress());
    }
    ignore_user_abort(true);
    $detached = false;
    if (function_exists('fastcgi_finish_request') && !headers_sent()) {
        status_header(202);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(array('accepted' => true));
        fastcgi_finish_request();
        $detached = true;
    }
    devdsame_internal_tick();
    if ($detached) {
        exit;
    }
    return rest_ensure_response(devdsame_job_progress());
}

/** POST /start-scan */
function devdsame_rest_start_scan(WP_REST_Request $request)
{
    $mode = sanitize_key((string) $request->get_param('mode'));
    $mode = $mode === 'preview' ? 'preview' : 'scan';
    $scope = sanitize_key((string) $request->get_param('scope'));
    $scope = in_array($scope, array('library', 'disk', 'full'), true) ? $scope : 'full';
    $job = devdsame_start_job($mode, array('scope' => $scope));
    if (is_wp_error($job)) {
        return new WP_Error($job->get_error_code(), $job->get_error_message(), array('status' => 409));
    }
    // Respond IMMEDIATELY — the poller (which also ticks) starts the work within a second.
    // Running the first tick here would block this response for the whole first chunk.
    return rest_ensure_response(devdsame_job_progress());
}

/** POST /start-backup { scope: library|disk } — powers the dashboard Create Backup buttons. */
function devdsame_rest_start_backup(WP_REST_Request $request)
{
    $scope = sanitize_key((string) $request->get_param('scope'));
    $what = sanitize_key((string) $request->get_param('what'));
    $job = devdsame_start_backup($scope === 'disk' ? 'disk' : 'library', $what);
    if (is_wp_error($job)) {
        return new WP_Error($job->get_error_code(), $job->get_error_message(), array('status' => 409));
    }
    return rest_ensure_response(devdsame_job_progress());
}

/**
 * GET /scan-progress — also advances a tick (acts as the WP-Cron-less driver while polling).
 * ?peek=1 skips the tick and returns the live snapshot only, so the UI can keep updating
 * while a long tick (manifest build, zip chunk) is still in flight on the driver request.
 */
function devdsame_rest_progress(WP_REST_Request $request)
{
    if ((int) $request->get_param('peek') !== 1) {
        $job = devdsame_get_job();
        if ($job && $job['status'] === 'running') {
            devdsame_run_tick();
        }
    }
    return rest_ensure_response(devdsame_job_progress());
}

/** POST /job-control { action: pause|resume|cancel } */
function devdsame_rest_job_control(WP_REST_Request $request)
{
    $action = sanitize_key((string) $request->get_param('action'));
    if ($action === 'pause') {
        if (!devdsame_pause_job()) {
            return new WP_Error('devdsame_job_control_failed', __('The job could not be paused (state write failed); it keeps running.', 'devdome-safe-media-cleaner'), array('status' => 500));
        }
    } elseif ($action === 'resume') {
        if (!devdsame_resume_job()) {
            return new WP_Error('devdsame_job_control_failed', __('The job could not be resumed (state write failed).', 'devdome-safe-media-cleaner'), array('status' => 500));
        }
    } elseif (!in_array($action, array('pause', 'resume', 'cancel'), true)) {
        return new WP_Error('devdsame_bad_action', __('Unknown action.', 'devdome-safe-media-cleaner'), array('status' => 400));
    } elseif ($action === 'cancel') {
        if (!devdsame_cancel_job()) {
            return new WP_Error('devdsame_cancel_failed', __('The job could not be cancelled: its rollback marker could not be saved, so it keeps running.', 'devdome-safe-media-cleaner'), array('status' => 500));
        }
    }
    return rest_ensure_response(devdsame_job_progress());
}

/** GET /scan-results — paginated + filtered review data with thumbnails. */
function devdsame_rest_results(WP_REST_Request $request)
{
    return rest_ensure_response(devdsame_scan_items_page($request->get_params()));
}

/**
 * One page of scan items with the review filters. The REST route and the Abilities API share it.
 * $args keys (all optional): scan_id, page, per_page, status, min_bytes, search, month, mime, folder,
 * selected_only, orderby, order. Every value is allowlisted or typed here.
 */
function devdsame_scan_items_page($args)
{
    global $wpdb;
    $items = $wpdb->prefix . 'devdsame_scan_items';
    $args = is_array($args) ? $args : array();
    $get = function ($key, $default = '') use ($args) {
        return isset($args[$key]) ? $args[$key] : $default;
    };

    $scan_id = (int) ($get('scan_id') ?: devdsame_latest_scan_id());
    $page = max(1, (int) $get('page'));
    $per = max(1, min(200, (int) ($get('per_page') ?: 20)));
    $offset = ($page - 1) * $per;

    // Filters (all allowlisted / typed).
    $valid_status = array('all', 'used', 'unused', 'uncertain', 'missing', 'orphan', 'duplicate');
    $status = sanitize_key((string) $get('status'));
    $status = in_array($status, $valid_status, true) ? $status : 'unused';

    $min_bytes = (int) $get('min_bytes');
    $search = substr(sanitize_text_field((string) $get('search')), 0, 100);
    $month = sanitize_text_field((string) $get('month')); // YYYY-MM
    $mime = sanitize_text_field((string) $get('mime'));
    $folder = sanitize_text_field((string) $get('folder')); // e.g. 2026/06
    $selected_only = (int) $get('selected_only');

    $valid_orderby = array('file_size', 'confidence', 'upload_date', 'id', 'dimensions');
    $orderby = sanitize_key((string) $get('orderby'));
    $orderby = in_array($orderby, $valid_orderby, true) ? $orderby : 'file_size';
    if ($orderby === 'dimensions') {
        $orderby = '(width * height)'; // pixel size (e.g. 1500x1500), not bytes
    }
    $order = strtoupper((string) $get('order')) === 'ASC' ? 'ASC' : 'DESC';

    $where = array('scan_id = %d');
    $params = array($scan_id);
    if ($status !== 'all') {
        $where[] = 'status = %s';
        $params[] = $status;
    }
    if ($min_bytes > 0) {
        $where[] = 'file_size >= %d';
        $params[] = $min_bytes;
    }
    if ($search !== '') {
        // Filename search: the stored file_url always ends in the filename.
        $where[] = 'file_url LIKE %s';
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $where[] = "DATE_FORMAT(upload_date, '%%Y-%%m') = %s";
        $params[] = $month;
    }
    if ($mime !== '') {
        $where[] = 'mime_type = %s';
        $params[] = $mime;
    }
    // Folder filter: match the uploads-relative folder inside the stored file_url. Allowlisted to
    // a safe "segment[/segment]" shape, then matched as a LIKE on the uploads base URL + folder.
    if ($folder !== '' && preg_match('#^[A-Za-z0-9_./-]{1,80}$#', $folder) && strpos($folder, '..') === false) {
        $folder_prefix = devdsame_uploads_baseurl() . '/' . trim($folder, '/') . '/';
        $where[] = 'file_url LIKE %s';
        $params[] = $wpdb->esc_like($folder_prefix) . '%';
    }
    if ($selected_only) {
        $where[] = 'is_selected = 1';
    }
    $where_sql = implode(' AND ', $where);

    // Total for pagination.
    $count_params = $params;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items count; WHERE values bound via prepare.
    $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$items} WHERE {$where_sql}", $count_params));
    if ($total === null || $wpdb->last_error !== '') {
        // A failed read must not render as an empty, clean site.
        return new WP_Error('devdsame_db_read', __('The scan results could not be read from the database right now.', 'devdome-safe-media-cleaner'), array('status' => 500));
    }
    $total = (int) $total;

    $params[] = $per;
    $params[] = $offset;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items page read; ORDER BY column is allowlisted, all values bound via prepare.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$items} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
        $params
    ), ARRAY_A);
    if ($wpdb->last_error !== '') {
        return new WP_Error('devdsame_db_read', __('The scan results could not be read from the database right now.', 'devdome-safe-media-cleaner'), array('status' => 500));
    }

    $out = array();
    foreach ((array) $rows as $r) {
        $attach_id = (int) $r['attachment_id'];
        $thumb = '';
        if ($attach_id > 0) {
            $t = wp_get_attachment_image_src($attach_id, 'thumbnail');
            $thumb = ($t && !empty($t[0])) ? $t[0] : '';
        }
        if ($thumb === '') {
            $thumb = (string) $r['file_url'];
        }
        $out[] = array(
            'id'            => (int) $r['id'],
            'attachment_id' => $attach_id,
            'thumb'         => $thumb,
            'filename'      => wp_basename((string) $r['file_path']),
            'url'           => (string) $r['file_url'],
            'edit_url'      => $attach_id ? get_edit_post_link($attach_id, 'raw') : '',
            'size'          => (int) $r['file_size'],
            'size_h'        => size_format((int) $r['file_size']),
            'width'         => (int) $r['width'],
            'height'        => (int) $r['height'],
            'mime'          => (string) $r['mime_type'],
            'date'          => (string) $r['upload_date'],
            'status'        => (string) $r['status'],
            'status_label'  => devdsame_status_label((string) $r['status']),
            'confidence'    => (int) $r['confidence'],
            'conf_label'    => devdsame_confidence_label((int) $r['confidence'], (string) $r['status']),
            'reasons'       => devdsame_reason_labels((string) $r['reason_code']),
            'selected'      => (int) $r['is_selected'],
        );
    }

    return array(
        'scan_id'  => $scan_id,
        'page'     => $page,
        'per_page' => $per,
        'total'    => $total,
        'pages'    => (int) ceil($total / $per),
        'items'    => $out,
    );
}

/** Read a list of scan_item ids from a request, sanitized to ints. */
function devdsame_request_item_ids(WP_REST_Request $request)
{
    $ids = $request->get_param('item_ids');
    if (!is_array($ids)) {
        $ids = array();
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return $ids;
}

/**
 * POST /clear-trash-history — clear the batch list: removes every batch with NOTHING left
 * to restore (restored, deleted, empty, or glitched leftovers). Batches still holding
 * restorable files are always kept.
 */
function devdsame_rest_clear_trash_history(WP_REST_Request $request)
{
    $ids = devdsame_clear_trash_history();
    if ($ids === null) {
        return new WP_Error('devdsame_db_write', __('The batch list could not be cleared (database error). Nothing was changed.', 'devdome-safe-media-cleaner'), array('status' => 500));
    }
    return rest_ensure_response(array('cleared' => count($ids), 'ids' => $ids));
}

/** Remove every batch record with nothing left to restore. Returns the removed batch ids. Shared with the Abilities API. */
function devdsame_clear_trash_history()
{
    global $wpdb;
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // A batch a running cleanup just created has no items yet: it is not history. Neither is
    // any batch younger than 15 minutes with nothing in it, nor one with claimed (restoring or
    // deleting) items.
    $job = devdsame_get_job();
    $active = ($job && in_array($job['status'], array('running', 'paused'), true)) ? (int) $job['batch_id'] : 0;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal history cleanup on our own tables; values bound via prepare.
    $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT b.id FROM {$tb} b
         LEFT JOIN {$ti} i ON i.batch_id = b.id AND i.status IN ('trashed', 'restoring', 'deleting')
         WHERE b.id <> %d AND (b.total_files > 0 OR b.status = 'empty' OR b.created_at < %s)
         GROUP BY b.id HAVING COUNT(i.id) = 0",
        $active,
        gmdate('Y-m-d H:i:s', current_time('timestamp') - 15 * MINUTE_IN_SECONDS) // created_at is site-local time
    )));
    if ($wpdb->last_error !== '') {
        return null;
    }
    // A batch whose folder still holds files (a stranded move that could not be recorded) keeps
    // its row: the row is the only way back to those files from the screen.
    $ids = array_values(array_filter($ids, function ($id) {
        return !devdsame_batch_folder_holds_files($id);
    }));
    if (!$ids) {
        return array();
    }
    $in = implode(',', $ids);
    // Both deletes or neither: a batch row must never outlive its items or the reverse.
    $wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- two-table delete kept atomic.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ids are intval'd above.
    $ok = $wpdb->query("DELETE FROM {$ti} WHERE batch_id IN ({$in})") !== false;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ids are intval'd above.
    $ok = $ok && $wpdb->query("DELETE FROM {$tb} WHERE id IN ({$in})") !== false;
    if (!$ok) {
        $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- undo the half-done delete.
        return null;
    }
    $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- both deletes landed.
    // Report only what is really gone.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ids are intval'd above.
    $left = array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$tb} WHERE id IN ({$in})"));
    return array_values(array_diff($ids, $left));
}

/** True when a batch's bin folder holds anything besides manifest.json (unreadable = true: never assume empty). */
function devdsame_batch_folder_holds_files($batch_id)
{
    $dir = devdsame_safe_trash_dir((int) $batch_id);
    if (!is_dir($dir) || is_link($dir)) {
        return false;
    }
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getFilename() !== 'manifest.json') {
                return true;
            }
        }
    } catch (Exception $e) {
        return true;
    }
    return false;
}

/** POST /restore-backup { backup_id } — start a backup-restore job (async, no page reload). */
function devdsame_rest_restore_backup(WP_REST_Request $request)
{
    $id = sanitize_text_field((string) $request->get_param('backup_id'));
    $entry = $id !== '' ? devdsame_backup_get($id) : null;
    if (!$entry) {
        return new WP_Error('devdsame_no_backup', __('Backup not found.', 'devdome-safe-media-cleaner'), array('status' => 404));
    }
    $job = devdsame_start_job('backup_restore', array('backup_id' => $id, 'scope' => (string) $entry['scope']));
    if (is_wp_error($job)) {
        return new WP_Error($job->get_error_code(), $job->get_error_message(), array('status' => 409));
    }
    return rest_ensure_response(devdsame_job_progress());
}

/**
 * POST /clean { scope } — one-click Clean Unused / Clean Orphans: move EVERY unused
 * (library) or orphan (disk) item from the latest scan to Recycle Bin. Reversible.
 */
function devdsame_rest_clean(WP_REST_Request $request)
{
    $job = devdsame_start_clean($request->get_param('scope') === 'disk' ? 'disk' : 'library');
    if (is_wp_error($job)) {
        return new WP_Error($job->get_error_code(), $job->get_error_message(), array('status' => $job->get_error_code() === 'devdsame_empty' ? 400 : 409));
    }
    // Respond immediately — the poller drives the ticks (same pattern as start-scan).
    return rest_ensure_response(devdsame_job_progress());
}

/**
 * Start the one-click clean: every unused (library) or orphan (disk) item of the latest scan of that
 * scope goes to the Recycle Bin as one batch. Returns the job or a WP_Error. Shared with the Abilities API.
 */
function devdsame_start_clean($scope)
{
    global $wpdb;
    $scope = $scope === 'disk' ? 'disk' : 'library';
    $status = $scope === 'disk' ? 'orphan' : 'unused';
    $sid = (int) devdsame_latest_scan_id($scope);
    $items = $wpdb->prefix . 'devdsame_scan_items';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items read.
    $ids = $sid ? array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$items} WHERE scan_id = %d AND status = %s",
        $sid,
        $status
    ))) : array();
    if ($sid && $wpdb->last_error !== '') {
        return new WP_Error('devdsame_db_read', __('The scan results could not be read from the database; nothing was cleaned.', 'devdome-safe-media-cleaner'));
    }
    if (!$ids) {
        return new WP_Error('devdsame_empty', __('Nothing to clean. Run a scan first.', 'devdome-safe-media-cleaner'));
    }
    return devdsame_start_job('trash', array('item_ids' => $ids, 'scope' => $scope, 'note' => 'clean-' . $status));
}

/** POST /trash { item_ids:[], note } — start a background trash job. */
function devdsame_rest_trash(WP_REST_Request $request)
{
    $ids = devdsame_request_item_ids($request);
    if (!$ids) {
        return new WP_Error('devdsame_empty', __('No items selected.', 'devdome-safe-media-cleaner'), array('status' => 400));
    }

    $note = sanitize_text_field((string) $request->get_param('note'));
    $job = devdsame_start_job('trash', array('item_ids' => $ids, 'note' => $note));
    if (is_wp_error($job)) {
        return new WP_Error($job->get_error_code(), $job->get_error_message(), array('status' => 409));
    }
    devdsame_run_tick();
    return rest_ensure_response(devdsame_job_progress());
}

/** POST /restore { batch_id } */
function devdsame_rest_restore(WP_REST_Request $request)
{
    $batch_id = (int) $request->get_param('batch_id');
    if (!$batch_id) {
        return new WP_Error('devdsame_no_batch', __('No batch specified.', 'devdome-safe-media-cleaner'), array('status' => 400));
    }
    $job = devdsame_start_job('restore', array('batch_id' => $batch_id));
    if (is_wp_error($job)) {
        return new WP_Error($job->get_error_code(), $job->get_error_message(), array('status' => 409));
    }
    devdsame_run_tick();
    return rest_ensure_response(devdsame_job_progress());
}

/** POST /permanent-delete { batch_id } */
function devdsame_rest_delete(WP_REST_Request $request)
{
    $batch_id = (int) $request->get_param('batch_id');
    if (!$batch_id) {
        return new WP_Error('devdsame_no_batch', __('No batch specified.', 'devdome-safe-media-cleaner'), array('status' => 400));
    }
    $job = devdsame_start_job('delete', array('batch_id' => $batch_id));
    if (is_wp_error($job)) {
        return new WP_Error($job->get_error_code(), $job->get_error_message(), array('status' => 409));
    }
    devdsame_run_tick();
    return rest_ensure_response(devdsame_job_progress());
}

/** POST /protect { attachment_ids:[], mode: protect|ignore|clear } */
function devdsame_rest_protect(WP_REST_Request $request)
{
    $ids = $request->get_param('attachment_ids');
    if (!is_array($ids)) {
        $ids = array();
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $mode = sanitize_key((string) $request->get_param('mode'));
    $mode = in_array($mode, array('protect', 'ignore', 'clear'), true) ? $mode : 'protect';

    $updated = 0;
    $failed = array();
    foreach ($ids as $id) {
        if (get_post_type($id) !== 'attachment') {
            $failed[] = $id;
            continue;
        }
        $ok = $mode === 'clear' ? devdsame_unset_protected($id) : devdsame_set_protected($id, $mode);
        if ($ok) {
            $updated++;
        } else {
            $failed[] = $id;
        }
    }
    if ($failed) {
        return new WP_Error('devdsame_protect_failed', sprintf(
            /* translators: 1: number marked, 2: ids that failed */
            __('%1$d marked; these could not be written: %2$s.', 'devdome-safe-media-cleaner'),
            $updated,
            implode(', ', array_slice($failed, 0, 20))
        ), array('status' => 500, 'updated' => $updated, 'failed' => $failed));
    }
    return rest_ensure_response(array('updated' => $updated, 'mode' => $mode));
}
