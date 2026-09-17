<?php
/**
 * WordPress Abilities API layer (WordPress 6.9+): the whole plugin exposed as typed, discoverable
 * abilities for AI agents and MCP clients (through the official WordPress MCP Adapter).
 *
 * Coverage = every feature of the Safe Media Cleaner screens, audited against the code
 * (rest.php routes, admin.php save handlers, backups.php actions, report.php exports, cli.php,
 * 2026-09-16): media health summary, scan results with every review filter and sort, filter
 * options, job progress and control (pause, resume, cancel), Media Library and Disk scans in
 * scan or preview mode, one-click Clean Unused / Clean Orphans, moving chosen items to the
 * Recycle Bin, Recycle Bin batches and their files, restore, permanent delete, clearing the batch
 * list, protect / ignore / clear on attachments, backups (list, create, restore, delete), every
 * setting of the Settings tab, the persisted error log and its dismissal.
 *
 * Every read goes through the same queries the admin page and WP-CLI use; every write calls the
 * same job starter and setting sanitizers the buttons use, so an agent can do nothing the screen
 * cannot. Not exposed on purpose: uploading a backup zip (a file upload) and the CSV/JSON
 * download links (the same rows are available through list-scan-items, list-trash-items and
 * get-error-log). Irreversible actions (permanent delete, deleting a backup, restoring a backup
 * over current files) need confirm: true and are annotated destructive. On WordPress older than
 * 6.9 the API does not exist and this file registers nothing.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*'); values are bound through $wpdb->prepare() (IN() lists use counted %d placeholders built with array_fill(), WHERE fragments hold only placeholders).

function devdsame_ability_ids()
{
    return array(
        // reads
        'devdome-safe-media-cleaner/get-media-summary',
        'devdome-safe-media-cleaner/list-scan-items',
        'devdome-safe-media-cleaner/get-scan-filter-options',
        'devdome-safe-media-cleaner/get-job-progress',
        'devdome-safe-media-cleaner/list-trash-batches',
        'devdome-safe-media-cleaner/list-trash-items',
        'devdome-safe-media-cleaner/list-backups',
        'devdome-safe-media-cleaner/get-protected-media',
        'devdome-safe-media-cleaner/get-settings',
        'devdome-safe-media-cleaner/get-error-log',
        // writes
        'devdome-safe-media-cleaner/run-media-scan',
        'devdome-safe-media-cleaner/control-job',
        'devdome-safe-media-cleaner/trash-media-items',
        'devdome-safe-media-cleaner/clean-unused-media',
        'devdome-safe-media-cleaner/restore-trash-batch',
        'devdome-safe-media-cleaner/delete-trash-batch',
        'devdome-safe-media-cleaner/clear-trash-history',
        'devdome-safe-media-cleaner/protect-media',
        'devdome-safe-media-cleaner/update-settings',
        'devdome-safe-media-cleaner/create-backup',
        'devdome-safe-media-cleaner/restore-backup',
        'devdome-safe-media-cleaner/delete-backup',
    );
}

add_action('wp_abilities_api_categories_init', 'devdsame_register_ability_category');
function devdsame_register_ability_category()
{
    if (!function_exists('wp_register_ability_category')) {
        return;
    }
    wp_register_ability_category('devdome-safe-media-cleaner', array(
        'label'       => __('DevDome Safe Media Cleaner', 'devdome-safe-media-cleaner'),
        'description' => __('Find and safely remove unused images from the WordPress Media Library and orphan files from the uploads folder: scan, review results, move to a Recycle Bin, restore or permanently delete, protect files, back up, and manage every setting.', 'devdome-safe-media-cleaner'),
    ));
}

function devdsame_ability_can()
{
    return current_user_can(devdsame_capability());
}

/**
 * $kind: 'read' (no change), 'add' (starts new work, not idempotent), 'modify' (changes state,
 * safe to repeat, reversible), 'destroy' (irreversible). destructive follows the WordPress
 * meaning: false = additive only, null = modifies, true = destructive.
 */
function devdsame_ability_meta($kind)
{
    $map = array(
        'read'    => array('readonly' => true,  'destructive' => false, 'idempotent' => true),
        'add'     => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
        'modify'  => array('readonly' => false, 'destructive' => null,  'idempotent' => true),
        'destroy' => array('readonly' => false, 'destructive' => true,  'idempotent' => true),
        'start'   => array('readonly' => false, 'destructive' => null,  'idempotent' => false), // starts a reversible job; a retry starts another
    );
    return array(
        'public'       => true,
        'show_in_rest' => true,
        'annotations'  => isset($map[$kind]) ? $map[$kind] : $map['modify'],
        'mcp'          => array('type' => 'tool'),
    );
}

/* ------------------------------ shared schemas ------------------------------ */

function devdsame_ability_progress_schema()
{
    return array('type' => 'object', 'properties' => array(
        'active'    => array('type' => 'boolean', 'description' => 'A job is running or paused.'),
        'type'      => array('type' => 'string', 'description' => 'scan, preview, trash, restore, delete, backup or backup_restore.'),
        'scope'     => array('type' => 'string', 'description' => 'library, disk or full.'),
        'status'    => array('type' => 'string', 'description' => 'idle, running, paused, completed, cancelled or failed.'),
        'phase'     => array('type' => 'string'),
        'processed' => array('type' => array('integer', 'null')),
        'total'     => array('type' => array('integer', 'null')),
        'percent'   => array('type' => 'integer'),
        'errors'    => array('type' => 'integer'),
        'eta'       => array('type' => array('integer', 'null'), 'description' => 'Estimated seconds left, when known.'),
        'scan_id'   => array('type' => 'integer'),
        'batch_id'  => array('type' => 'integer'),
        'message'   => array('type' => 'string'),
        'last_error' => array('type' => array('object', 'null'), 'properties' => array('at' => array('type' => 'string'), 'code' => array('type' => 'string'), 'message' => array('type' => 'string')), 'description' => 'The last persisted error, until dismissed with control-job clear_error.'),
    ));
}

function devdsame_ability_item_schema()
{
    return array('type' => 'object', 'properties' => array(
        'id'            => array('type' => 'integer', 'description' => 'Scan item id, the value trash-media-items takes.'),
        'attachment_id' => array('type' => 'integer', 'description' => 'Media Library attachment id; 0 for a file that only exists on disk.'),
        'thumb'         => array('type' => 'string'),
        'filename'      => array('type' => 'string'),
        'url'           => array('type' => 'string'),
        'edit_url'      => array('type' => 'string'),
        'size'          => array('type' => 'integer', 'description' => 'Bytes.'),
        'size_h'        => array('type' => 'string'),
        'width'         => array('type' => 'integer'),
        'height'        => array('type' => 'integer'),
        'mime'          => array('type' => 'string'),
        'date'          => array('type' => 'string', 'description' => 'Upload date, site time.'),
        'status'        => array('type' => 'string', 'description' => 'used, unused, uncertain, missing, orphan or trashed (duplicate appears only on data from older versions).'),
        'status_label'  => array('type' => 'string'),
        'confidence'    => array('type' => 'integer', 'description' => '0 to 100, how sure the scanner is that the file is unused.'),
        'conf_label'    => array('type' => 'string'),
        'reasons'       => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Why it got this status, in words.'),
        'selected'      => array('type' => 'integer', 'description' => '1 when the scan auto-selected it for cleanup (unused, above the confidence threshold, not protected, not recent).'),
    ));
}

function devdsame_ability_settings_schema($for_input)
{
    $props = array(
        'recent_upload_protection_days' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 999, 'description' => 'Uploads newer than this many days are never auto-selected for cleanup.'),
        'protect_recent'         => array('type' => 'boolean', 'description' => 'Apply the recent-upload protection.'),
        'protect_woocommerce'    => array('type' => 'boolean', 'description' => 'Treat images referenced by WooCommerce products, variations and categories as used.'),
        'protect_theme_assets'   => array('type' => 'boolean', 'description' => 'Treat images referenced by the theme, customizer and site icon/logo as used.'),
        'confidence_threshold'   => array('type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'Only items whose unused confidence is at or above this are auto-selected.'),
        'scheduled_scan_days'    => array('type' => 'integer', 'minimum' => 0, 'maximum' => 999, 'description' => 'Run a full scan automatically every N days; 0 = off.'),
        'auto_delete_after_days' => array('type' => 'integer', 'enum' => array(0, 7, 14, 30), 'description' => 'Permanently delete Recycle Bin batches automatically after this many days; 0 = keep until deleted by hand.'),
        'cdn_mappings'           => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'CDN base URLs that serve the uploads folder (for example https://cdn.example.com/wp-content/uploads), so references through the CDN count as used.'),
        'never_scan_folders'     => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Folders inside wp-content/uploads to skip entirely, relative, for example woocommerce_uploads or 2019.'),
        'email_notifications'    => array('type' => 'boolean', 'description' => 'Email alerts to the connected DevDome account address. Can only be turned on while the site is connected to a DevDome account.'),
        'notification_frequency_days' => array('type' => 'integer', 'enum' => array(1, 3, 7, 14, 30), 'description' => 'Minimum days between alert emails.'),
        'unused_growth_alert_mb' => array('type' => 'number', 'minimum' => 0, 'description' => 'Alert when unused media grows beyond this many MB; 0 = off.'),
    );
    if ($for_input) {
        $props['confirm'] = array('type' => 'boolean', 'default' => false, 'description' => 'Required true when a change lowers protection or adds risk: turning a protection off, lowering the confidence threshold or the recent-upload window, removing a never-scan folder, turning automatic permanent delete on or shortening it, or turning email notifications on. Ask the user first.');
        return array('type' => 'object', 'properties' => $props, 'additionalProperties' => false);
    }
    $props['account_connected'] = array('type' => 'boolean', 'description' => 'The site is connected to a DevDome account (needed for email notifications).');
    $props['metrics_optin'] = array('type' => 'boolean', 'description' => 'Anonymous product metrics opt-in (read only here).');
    return array('type' => 'object', 'properties' => $props);
}

/* ------------------------------ registration ------------------------------ */

add_action('wp_abilities_api_init', 'devdsame_register_abilities');
function devdsame_register_abilities()
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    // An empty properties list must be a PHP array, not stdClass: WordPress validates input by array
    // access on it and an unexpected argument from a client would otherwise raise a type error
    // instead of a clean "invalid input" refusal.
    $empty_input = array('type' => 'object', 'properties' => array(), 'additionalProperties' => false);
    $progress = devdsame_ability_progress_schema();
    $item = devdsame_ability_item_schema();
    $confirm = array('type' => 'boolean', 'description' => 'Must be true. This action cannot be undone; ask the user before passing it.');
    $batch_id = array('type' => 'integer', 'minimum' => 1, 'description' => 'The batch id from list-trash-batches.');
    $backup_id = array('type' => 'string', 'description' => 'The backup id from list-backups.');
    $scan_id = array('type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'A scan id; 0 = the latest completed scan.');

    $reg = function ($id, $label, $desc, $in, $out, $cb, $kind) {
        wp_register_ability($id, array(
            'label'               => $label,
            'description'         => $desc,
            'category'            => 'devdome-safe-media-cleaner',
            'input_schema'        => $in,
            'output_schema'       => $out,
            'execute_callback'    => $cb,
            'permission_callback' => 'devdsame_ability_can',
            'meta'                => devdsame_ability_meta($kind),
        ));
    };

    /* ---- reads ---- */

    $reg('devdome-safe-media-cleaner/get-media-summary', __('Get media health summary', 'devdome-safe-media-cleaner'),
        __('Get the media health summary of this WordPress site from the latest scans: total Media Library files, how many are used, unused, uncertain, missing from disk, orphan files on disk that no attachment owns, the bytes that could be cleaned up on each side, the cleanliness score (0 to 100), when the last Media Library and disk scans ran, whether a job is running now, the last error, whether the uploads folder is writable and the free disk space, how many attachments are protected or ignored, and how many Recycle Bin batches and backups exist. Read only. Run run-media-scan first if there was never a scan.', 'devdome-safe-media-cleaner'),
        $empty_input,
        array('type' => 'object', 'properties' => array(
            'last_scan_id' => array('type' => 'integer'), 'last_scan_at' => array('type' => 'string', 'description' => 'ISO 8601 UTC or empty.'),
            'last_disk_scan_id' => array('type' => 'integer'), 'last_disk_scan_at' => array('type' => 'string'),
            'total_files' => array('type' => 'integer'), 'used_count' => array('type' => 'integer'), 'unused_count' => array('type' => 'integer'),
            'uncertain_count' => array('type' => 'integer'), 'duplicate_count' => array('type' => 'integer'), 'missing_count' => array('type' => 'integer'),
            'orphan_count' => array('type' => 'integer'), 'disk_images_count' => array('type' => 'integer'),
            'library_cleanup_bytes' => array('type' => 'integer'), 'orphan_bytes' => array('type' => 'integer'), 'possible_cleanup_bytes' => array('type' => 'integer'),
            'total_library_bytes' => array('type' => 'integer'), 'disk_images_bytes' => array('type' => 'integer'),
            'score' => array('type' => 'integer'),
            'job' => $progress,
            'uploads_writable' => array('type' => 'boolean'), 'disk_free_bytes' => array('type' => array('integer', 'null')),
            'protected_count' => array('type' => 'integer'), 'ignored_count' => array('type' => 'integer'),
            'trash_batches' => array('type' => 'integer', 'description' => 'Batches that still hold restorable files.'), 'backups' => array('type' => 'integer'),
        )),
        'devdsame_ability_summary', 'read');

    $reg('devdome-safe-media-cleaner/list-scan-items', __('List scan results', 'devdome-safe-media-cleaner'),
        __('List the files found by a media scan on this WordPress site, one page at a time, with the same filters as the review screen: status (unused, used, uncertain, missing, orphan, or all; duplicate only matches data from older versions), minimum size in bytes, file name search, upload month (YYYY-MM), mime type, uploads folder (for example 2026/06), only the auto-selected items, sorted by file size, confidence, upload date, id or pixel dimensions. Each row has the scan item id (needed by trash-media-items), the attachment id, URL, size, dimensions, status, confidence and the reasons. Read only. Use get-scan-filter-options for the months, mime types and folders that exist.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array(
            'scan_id'  => $scan_id,
            'status'   => array('type' => 'string', 'enum' => array('unused', 'used', 'uncertain', 'missing', 'orphan', 'duplicate', 'all'), 'default' => 'unused'),
            'min_bytes' => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
            'search'   => array('type' => 'string', 'default' => '', 'description' => 'Part of the file name.'),
            'month'    => array('type' => 'string', 'default' => '', 'description' => 'YYYY-MM upload month.'),
            'mime'     => array('type' => 'string', 'default' => '', 'description' => 'Exact mime type, for example image/jpeg.'),
            'folder'   => array('type' => 'string', 'default' => '', 'description' => 'Uploads sub folder, for example 2026/06.'),
            'selected_only' => array('type' => 'boolean', 'default' => false, 'description' => 'Only items the scan auto-selected for cleanup.'),
            'orderby'  => array('type' => 'string', 'enum' => array('file_size', 'confidence', 'upload_date', 'id', 'dimensions'), 'default' => 'file_size'),
            'order'    => array('type' => 'string', 'enum' => array('desc', 'asc'), 'default' => 'desc'),
            'page'     => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
            'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 20),
        ), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array(
            'scan_id' => array('type' => 'integer'), 'page' => array('type' => 'integer'), 'per_page' => array('type' => 'integer'),
            'total' => array('type' => 'integer'), 'pages' => array('type' => 'integer'),
            'items' => array('type' => 'array', 'items' => $item),
        )),
        'devdsame_ability_list_items', 'read');

    $reg('devdome-safe-media-cleaner/get-scan-filter-options', __('Get scan filter options', 'devdome-safe-media-cleaner'),
        __('Get the values available for filtering a scan\'s results on this WordPress site: the upload months (YYYY-MM), the mime types and the uploads folders present in the scan. Read only.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array('scan_id' => $scan_id), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('scan_id' => array('type' => 'integer'), 'months' => array('type' => 'array', 'items' => array('type' => 'string')), 'mimes' => array('type' => 'array', 'items' => array('type' => 'string')), 'folders' => array('type' => 'array', 'items' => array('type' => 'string')))),
        'devdsame_ability_filter_options', 'read');

    $reg('devdome-safe-media-cleaner/get-job-progress', __('Get job progress', 'devdome-safe-media-cleaner'),
        __('Get the progress of the media job running on this WordPress site (scan, preview, cleanup to the Recycle Bin, restore, permanent delete, backup or backup restore): status, phase, processed and total counts, percent, estimated time left, error count, the scan or batch it works on, and the last persisted error. Read only and changes nothing; the job runs on the server by itself, so poll this every few seconds until active is false.', 'devdome-safe-media-cleaner'),
        $empty_input, $progress, 'devdsame_ability_progress', 'read');

    $reg('devdome-safe-media-cleaner/list-trash-batches', __('List Recycle Bin batches', 'devdome-safe-media-cleaner'),
        __('List the Recycle Bin batches on this WordPress site, newest first, one page at a time: each cleanup run that moved files out of the Media Library or the uploads folder, with its date, source (Media Library or disk), file count, bytes, status (trashed = files waiting, restored, deleted, empty), whether it can still be restored, how many files are still in the bin, the note and the automatic delete date. Read only. Batches with nothing left are hidden unless include_empty is true.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array(
            'include_empty' => array('type' => 'boolean', 'default' => false),
            'page'     => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
            'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50),
        ), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('total' => array('type' => 'integer', 'description' => 'Batches matching, across all pages.'), 'page' => array('type' => 'integer'), 'per_page' => array('type' => 'integer'), 'pages' => array('type' => 'integer'), 'items' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'created_at' => array('type' => 'string'), 'source' => array('type' => 'string', 'enum' => array('library', 'disk')),
            'total_files' => array('type' => 'integer'), 'total_bytes' => array('type' => 'integer'), 'status' => array('type' => 'string'),
            'restore_available' => array('type' => 'boolean'), 'files_in_bin' => array('type' => 'integer'), 'note' => array('type' => 'string'),
            'permanent_delete_after' => array('type' => 'string', 'description' => 'Site time, empty when automatic delete is off.'),
        ))))),
        'devdsame_ability_list_batches', 'read');

    $reg('devdome-safe-media-cleaner/list-trash-items', __('List files of a Recycle Bin batch', 'devdome-safe-media-cleaner'),
        __('List the files inside one Recycle Bin batch on this WordPress site, one page at a time: original path and URL, attachment id, size, status (trashed, restored or deleted), when it was moved, restored or deleted, and any error. Read only.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array(
            'batch_id' => $batch_id,
            'status'   => array('type' => 'string', 'enum' => array('all', 'trashed', 'restored', 'deleted'), 'default' => 'all'),
            'page'     => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
            'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50),
        ), 'required' => array('batch_id'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('batch_id' => array('type' => 'integer'), 'page' => array('type' => 'integer'), 'per_page' => array('type' => 'integer'), 'total' => array('type' => 'integer'), 'pages' => array('type' => 'integer'), 'items' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'attachment_id' => array('type' => 'integer'), 'original_path' => array('type' => 'string', 'description' => 'Relative to the uploads folder.'), 'original_url' => array('type' => 'string'),
            'file_size' => array('type' => 'integer'), 'status' => array('type' => 'string'), 'moved_at' => array('type' => 'string'), 'restored_at' => array('type' => 'string'), 'permanently_deleted_at' => array('type' => 'string'), 'error' => array('type' => 'string'),
        ))))),
        'devdsame_ability_list_trash_items', 'read');

    $reg('devdome-safe-media-cleaner/list-backups', __('List media backups', 'devdome-safe-media-cleaner'),
        __('List the media backup archives kept by this WordPress site, newest first: id, scope (library = Media Library images, disk = orphan files), file name, number of files, size in bytes, when it was created, and whether the zip file is still present. Read only. Backups are downloaded from the Backup tab in wp-admin.', 'devdome-safe-media-cleaner'),
        $empty_input,
        array('type' => 'object', 'properties' => array('total' => array('type' => 'integer'), 'items' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'string'), 'scope' => array('type' => 'string'), 'file' => array('type' => 'string'), 'files' => array('type' => 'integer'), 'bytes' => array('type' => 'integer'), 'created_at' => array('type' => 'string'), 'present' => array('type' => 'boolean'),
        ))))),
        'devdsame_ability_list_backups', 'read');

    $reg('devdome-safe-media-cleaner/get-protected-media', __('Get protected and ignored media', 'devdome-safe-media-cleaner'),
        __('Get the attachments a user marked on this WordPress site: protected (never selected for cleanup, always kept) and ignored (hidden from the review list for good), each with its id, file name and URL. Read only.', 'devdome-safe-media-cleaner'),
        $empty_input,
        array('type' => 'object', 'properties' => array('protected' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('attachment_id' => array('type' => 'integer'), 'filename' => array('type' => 'string'), 'url' => array('type' => 'string')))), 'ignored' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('attachment_id' => array('type' => 'integer'), 'filename' => array('type' => 'string'), 'url' => array('type' => 'string')))))),
        'devdsame_ability_protected', 'read');

    $reg('devdome-safe-media-cleaner/get-settings', __('Get Safe Media Cleaner settings', 'devdome-safe-media-cleaner'),
        __('Get every setting of Safe Media Cleaner on this WordPress site: recent upload protection (days and on/off), WooCommerce and theme asset protection, the confidence threshold for auto-selection, the scheduled scan cadence in days, automatic permanent delete after N days, CDN base URLs, folders never scanned, email notifications and their frequency, the unused-growth alert threshold, and whether the site is connected to a DevDome account. Read only.', 'devdome-safe-media-cleaner'),
        $empty_input, devdsame_ability_settings_schema(false), 'devdsame_ability_get_settings', 'read');

    $reg('devdome-safe-media-cleaner/get-error-log', __('Get the error log', 'devdome-safe-media-cleaner'),
        __('Get the last 50 errors recorded by media jobs on this WordPress site (files that could not be moved, restored or deleted, scan failures), newest last, each with time, code, message and context. Read only.', 'devdome-safe-media-cleaner'),
        $empty_input,
        array('type' => 'object', 'properties' => array('total' => array('type' => 'integer'), 'items' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('at' => array('type' => 'string'), 'code' => array('type' => 'string'), 'message' => array('type' => 'string'), 'context' => array('type' => 'object')))))),
        'devdsame_ability_error_log', 'read');

    /* ---- writes ---- */

    $reg('devdome-safe-media-cleaner/run-media-scan', __('Run a media scan', 'devdome-safe-media-cleaner'),
        __('Start a media scan on this WordPress site in the background and return its progress. scope library = classify every Media Library image as used, unused, uncertain or missing by checking posts, pages, products, page builders, meta, options, widgets, menus, the theme and the customizer; scope disk = find orphan image files in the uploads folder that no attachment owns; scope full = both. mode scan auto-selects the unused items above the confidence threshold for cleanup, mode preview only classifies and selects nothing. Nothing is moved or deleted by a scan. Refused while another job is running. Poll get-job-progress until active is false, then read list-scan-items. Same as the Scan buttons in wp-admin.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array(
            'scope' => array('type' => 'string', 'enum' => array('library', 'disk', 'full'), 'default' => 'full'),
            'mode'  => array('type' => 'string', 'enum' => array('scan', 'preview'), 'default' => 'scan'),
        ), 'additionalProperties' => false),
        $progress, 'devdsame_ability_run_scan', 'add');

    $reg('devdome-safe-media-cleaner/control-job', __('Pause, resume or cancel the job', 'devdome-safe-media-cleaner'),
        __('Control the media job running on this WordPress site: pause it, resume it, cancel it (a cancelled cleanup rolls its moved files back), or clear_error to dismiss the last persisted error banner. Returns the progress afterwards. Same as the Pause, Resume, Cancel and dismiss buttons in wp-admin.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array('action' => array('type' => 'string', 'enum' => array('pause', 'resume', 'cancel', 'clear_error'))), 'required' => array('action'), 'additionalProperties' => false),
        $progress, 'devdsame_ability_control_job', 'modify');

    $reg('devdome-safe-media-cleaner/trash-media-items', __('Move chosen files to the Recycle Bin', 'devdome-safe-media-cleaner'),
        __('Move chosen scan items on this WordPress site to the plugin\'s Recycle Bin as one batch: the files leave the uploads folder and the Media Library grid but nothing is deleted, and the batch can be restored with restore-trash-batch. Pass the scan item ids from list-scan-items (not attachment ids). A file that is referenced again since the scan is skipped. Runs in the background; poll get-job-progress. Refused while another job is running. Same as selecting rows and clicking Move to Recycle Bin in wp-admin.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array(
            'item_ids' => array('type' => 'array', 'items' => array('type' => 'integer', 'minimum' => 1), 'minItems' => 1, 'maxItems' => 5000, 'description' => 'Scan item ids from list-scan-items.'),
            'note'     => array('type' => 'string', 'default' => '', 'description' => 'Optional note stored with the batch.'),
        ), 'required' => array('item_ids'), 'additionalProperties' => false),
        $progress, 'devdsame_ability_trash_items', 'start'); // starts a job: a retry makes a second batch, so not idempotent

    $reg('devdome-safe-media-cleaner/clean-unused-media', __('Clean all unused media or all orphans', 'devdome-safe-media-cleaner'),
        __('One-click cleanup on this WordPress site: move EVERY item the latest scan classified as unused (scope library) or every orphan file the latest disk scan found (scope disk) to the Recycle Bin as one batch. Uncertain, used, protected and recent items are never touched. Reversible with restore-trash-batch until the batch is permanently deleted. Runs in the background; poll get-job-progress. Refused when there is nothing to clean or another job is running. Same as the Clean Unused and Clean Orphans buttons in wp-admin.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array('scope' => array('type' => 'string', 'enum' => array('library', 'disk'), 'default' => 'library')), 'additionalProperties' => false),
        $progress, 'devdsame_ability_clean', 'start'); // starts a job: not idempotent

    $reg('devdome-safe-media-cleaner/restore-trash-batch', __('Restore a Recycle Bin batch', 'devdome-safe-media-cleaner'),
        __('Restore every file of one Recycle Bin batch on this WordPress site to its original location and back into the Media Library. Only batches whose status is trashed and that still allow a restore can be restored. Runs in the background; poll get-job-progress. Same as the Restore button in wp-admin.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array('batch_id' => $batch_id), 'required' => array('batch_id'), 'additionalProperties' => false),
        $progress, 'devdsame_ability_restore_batch', 'modify');

    $reg('devdome-safe-media-cleaner/delete-trash-batch', __('Permanently delete a Recycle Bin batch', 'devdome-safe-media-cleaner'),
        __('Permanently delete every file of one Recycle Bin batch on this WordPress site and remove their attachment records. This cannot be undone; pass confirm: true only after the user agreed, and consider create-backup first. Runs in the background; poll get-job-progress. Same as the Delete Permanently button in wp-admin.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array('batch_id' => $batch_id, 'confirm' => $confirm), 'required' => array('batch_id', 'confirm'), 'additionalProperties' => false),
        $progress, 'devdsame_ability_delete_batch', 'destroy');

    $reg('devdome-safe-media-cleaner/clear-trash-history', __('Clear the Recycle Bin batch list', 'devdome-safe-media-cleaner'),
        __('Remove the finished batches from the Recycle Bin list on this WordPress site: batches that were fully restored, permanently deleted or ended empty. Batches that still hold restorable files are always kept, so no file is lost. Same as the Clear batch list button in wp-admin.', 'devdome-safe-media-cleaner'),
        $empty_input,
        array('type' => 'object', 'properties' => array('cleared' => array('type' => 'integer'), 'ids' => array('type' => 'array', 'items' => array('type' => 'integer')))),
        'devdsame_ability_clear_history', 'destroy');

    $reg('devdome-safe-media-cleaner/protect-media', __('Protect, ignore or release attachments', 'devdome-safe-media-cleaner'),
        __('Mark Media Library attachments on this WordPress site: protect = never select them for cleanup and always keep them, ignore = hide them from the review list for good, clear = remove either mark so the image can be selected for cleanup again (lowers protection, needs confirm: true after the user agreed). Pass attachment ids (from list-scan-items attachment_id, not scan item ids). Same as the Protect, Ignore and Clear actions in wp-admin.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array(
            'attachment_ids' => array('type' => 'array', 'items' => array('type' => 'integer', 'minimum' => 1), 'minItems' => 1, 'maxItems' => 5000),
            'mode' => array('type' => 'string', 'enum' => array('protect', 'ignore', 'clear'), 'default' => 'protect'),
            'confirm' => array('type' => 'boolean', 'default' => false, 'description' => 'Required true for mode clear: releasing a mark makes the image eligible for cleanup again.'),
        ), 'required' => array('attachment_ids'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('updated' => array('type' => 'integer'), 'mode' => array('type' => 'string'), 'not_attachments' => array('type' => 'array', 'items' => array('type' => 'integer'), 'description' => 'Ids that are not attachments and were skipped.'))),
        'devdsame_ability_protect', 'modify');

    $reg('devdome-safe-media-cleaner/update-settings', __('Update Safe Media Cleaner settings', 'devdome-safe-media-cleaner'),
        __('Change any settings of Safe Media Cleaner on this WordPress site; pass only the keys to change: recent upload protection days (1 to 999) and on/off, WooCommerce and theme asset protection, the confidence threshold (0 to 100) for auto-selection, scheduled scans every N days (0 = off), automatic permanent delete of Recycle Bin batches after 0, 7, 14 or 30 days, CDN base URLs, folders never scanned, email notifications (only while connected to a DevDome account) and their frequency (1, 3, 7, 14 or 30 days), and the unused-growth alert in MB (0 = off). Values are validated exactly like the Settings form and the saved settings are read back and returned. Same as Save Settings in wp-admin.', 'devdome-safe-media-cleaner'),
        devdsame_ability_settings_schema(true), devdsame_ability_settings_schema(false), 'devdsame_ability_update_settings', 'modify');

    $reg('devdome-safe-media-cleaner/create-backup', __('Create a media backup', 'devdome-safe-media-cleaner'),
        __('Create a zip backup on this WordPress site in the background: scope library = Media Library images (what unused = only the images the latest scan classified as unused, what all = every image), scope disk = orphan files on disk (what unused = only the orphans, what all = every image file in the uploads folder). The zip is kept in the uploads folder and listed by list-backups. Needs the PHP zip extension. Runs in the background; poll get-job-progress. Refused while another job is running. Same as the Create Backup buttons in wp-admin.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array(
            'scope' => array('type' => 'string', 'enum' => array('library', 'disk'), 'default' => 'library'),
            'what'  => array('type' => 'string', 'enum' => array('unused', 'all'), 'default' => 'unused'),
        ), 'additionalProperties' => false),
        $progress, 'devdsame_ability_create_backup', 'add');

    $reg('devdome-safe-media-cleaner/restore-backup', __('Restore a media backup', 'devdome-safe-media-cleaner'),
        __('Restore one backup zip on this WordPress site in the background: files missing from the uploads folder are written back, files that exist now are kept untouched (a restore never overwrites current media), and restored Media Library images are re-registered. Runs in the background; poll get-job-progress, whose final message says how many files were written and how many were kept. Refused while another job is running. Same as the Restore button on the Backup tab in wp-admin.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array('backup_id' => $backup_id), 'required' => array('backup_id'), 'additionalProperties' => false),
        $progress, 'devdsame_ability_restore_backup', 'modify');

    $reg('devdome-safe-media-cleaner/delete-backup', __('Delete a media backup', 'devdome-safe-media-cleaner'),
        __('Delete one backup zip on this WordPress site and its record. This cannot be undone; pass confirm: true only after the user agreed. Same as the Delete button on the Backup tab in wp-admin.', 'devdome-safe-media-cleaner'),
        array('type' => 'object', 'properties' => array('backup_id' => $backup_id, 'confirm' => $confirm), 'required' => array('backup_id', 'confirm'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('deleted' => array('type' => 'boolean'), 'backup_id' => array('type' => 'string'))),
        'devdsame_ability_delete_backup', 'destroy');
}

/* ------------------------------- helpers ------------------------------- */

function devdsame_ability_iso($ts)
{
    $ts = (int) $ts;
    return $ts > 0 ? gmdate('c', $ts) : '';
}

/** Progress plus the last persisted error, the shape every job ability returns. */
function devdsame_ability_progress_out()
{
    $p = devdsame_job_progress();
    $e = devdsame_last_error();
    $p['last_error'] = $e ? array('at' => devdsame_ability_iso($e['at'] ?? 0), 'code' => (string) ($e['code'] ?? ''), 'message' => (string) ($e['message'] ?? '')) : null;
    if (!isset($p['type'])) { // idle: give the full shape
        $p += array('type' => '', 'scope' => '', 'phase' => '', 'processed' => null, 'total' => null, 'percent' => 0, 'errors' => 0, 'eta' => null, 'scan_id' => 0, 'batch_id' => 0, 'message' => '');
    }
    return $p;
}

function devdsame_ability_job_error($job)
{
    return new WP_Error($job->get_error_code(), $job->get_error_message());
}

function devdsame_ability_batch($batch_id)
{
    global $wpdb;
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches read; id bound via prepare.
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tb} WHERE id = %d", (int) $batch_id));
    if ($wpdb->last_error !== '') {
        return new WP_Error('devdsame_db_read', __('The Recycle Bin could not be read (database error).', 'devdome-safe-media-cleaner'));
    }
    return $row;
}

/* ------------------------------- reads ------------------------------- */

function devdsame_ability_summary($input = array())
{
    global $wpdb;
    $s = devdsame_hub_summary();
    $health = devdsame_filesystem_health();
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal count.
    $restorable = $wpdb->get_var("SELECT COUNT(*) FROM {$tb} WHERE status = 'trashed' AND restore_available = 1 AND total_files > 0");
    $protected = devdsame_protected_ids();
    $ignored = devdsame_ignored_ids();
    if ($restorable === null || $wpdb->last_error !== '' || $protected === null || $ignored === null) {
        return new WP_Error('devdsame_db_read', __('The media summary could not be read from the database right now.', 'devdome-safe-media-cleaner'));
    }
    $out = array();
    foreach (array('last_scan_id', 'last_disk_scan_id', 'total_files', 'used_count', 'unused_count', 'uncertain_count', 'duplicate_count', 'missing_count', 'orphan_count', 'disk_images_count', 'library_cleanup_bytes', 'orphan_bytes', 'possible_cleanup_bytes', 'total_library_bytes', 'disk_images_bytes', 'score') as $k) {
        $out[$k] = (int) ($s[$k] ?? 0);
    }
    $out['last_scan_at'] = devdsame_ability_iso($s['last_scan_at'] ?? 0);
    $out['last_disk_scan_at'] = devdsame_ability_iso($s['last_disk_scan_at'] ?? 0);
    $out['job'] = devdsame_ability_progress_out();
    $out['uploads_writable'] = !empty($health['writable']);
    $out['disk_free_bytes'] = isset($health['free']) && $health['free'] !== null ? (int) $health['free'] : null;
    $out['protected_count'] = count($protected);
    $out['ignored_count'] = count($ignored);
    $out['trash_batches'] = (int) $restorable;
    $out['backups'] = count(devdsame_backups());
    return $out;
}

function devdsame_ability_list_items($input = array())
{
    $input = is_array($input) ? $input : array();
    $args = $input;
    $args['selected_only'] = !empty($input['selected_only']) ? 1 : 0;
    $args['order'] = isset($input['order']) ? strtoupper((string) $input['order']) : 'DESC';
    $args['scan_id'] = (int) ($input['scan_id'] ?? 0);
    if (!$args['scan_id'] && !devdsame_latest_scan_id()) {
        return new WP_Error('devdsame_no_scan', __('No completed scan yet. Run run-media-scan first.', 'devdome-safe-media-cleaner'));
    }
    return devdsame_scan_items_page($args);
}

function devdsame_ability_filter_options($input = array())
{
    $input = is_array($input) ? $input : array();
    $sid = (int) ($input['scan_id'] ?? 0);
    $sid = $sid ?: (int) devdsame_latest_scan_id();
    if (!$sid) {
        return new WP_Error('devdsame_no_scan', __('No completed scan yet. Run run-media-scan first.', 'devdome-safe-media-cleaner'));
    }
    return array('scan_id' => $sid, 'months' => devdsame_scan_months($sid), 'mimes' => devdsame_scan_mimes($sid), 'folders' => devdsame_scan_folders($sid));
}

function devdsame_ability_progress($input = array())
{
    // Observational only: the cron event and the loopback tick chain drive the job.
    return devdsame_ability_progress_out();
}

function devdsame_ability_list_batches($input = array())
{
    global $wpdb;
    $input = is_array($input) ? $input : array();
    $include_empty = !empty($input['include_empty']);
    $page = max(1, (int) ($input['page'] ?? 1));
    $per = max(1, min(200, (int) ($input['per_page'] ?? 50)));
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    $where = $include_empty ? '1 = 1' : "total_files > 0 AND status <> 'empty'";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches count; WHERE holds only literals.
    $total = $wpdb->get_var("SELECT COUNT(*) FROM {$tb} WHERE {$where}");
    if ($total === null || $wpdb->last_error !== '') {
        return new WP_Error('devdsame_db_read', __('The Recycle Bin list could not be read from the database right now.', 'devdome-safe-media-cleaner'));
    }
    $total = (int) $total;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches page; bounds bound via prepare.
    $batches = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tb} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per, ($page - 1) * $per));
    if ($wpdb->last_error !== '') {
        return new WP_Error('devdsame_db_read', __('The Recycle Bin list could not be read from the database right now.', 'devdome-safe-media-cleaner'));
    }
    $src = array();
    if ($batches) {
        $ids = array_map('intval', wp_list_pluck($batches, 'id'));
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal aggregate for this page; ids bound via prepare.
        $src = $wpdb->get_results($wpdb->prepare("SELECT batch_id, MAX(attachment_id > 0) AS is_lib, SUM(status IN ('trashed', 'restoring', 'deleting')) AS in_bin FROM {$ti} WHERE batch_id IN ({$ph}) GROUP BY batch_id", $ids), OBJECT_K);
        if ($wpdb->last_error !== '') {
            return new WP_Error('devdsame_db_read', __('The Recycle Bin list could not be read from the database right now.', 'devdome-safe-media-cleaner'));
        }
    }
    $items = array();
    foreach ($batches as $b) {
        $agg = isset($src[$b->id]) ? $src[$b->id] : null;
        $items[] = array(
            'id'                => (int) $b->id,
            'created_at'        => (string) $b->created_at,
            'source'            => ($agg === null || (int) $agg->is_lib) ? 'library' : 'disk',
            'total_files'       => (int) $b->total_files,
            'total_bytes'       => (int) $b->total_bytes,
            'status'            => (string) $b->status,
            'restore_available' => (bool) $b->restore_available,
            'files_in_bin'      => $agg ? (int) $agg->in_bin : 0,
            'note'              => (string) $b->note,
            'permanent_delete_after' => (string) ($b->permanent_delete_after ?? ''),
        );
    }
    return array('total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => (int) ceil($total / $per), 'items' => $items);
}

function devdsame_ability_list_trash_items($input = array())
{
    global $wpdb;
    $input = is_array($input) ? $input : array();
    $batch_id = (int) ($input['batch_id'] ?? 0);
    $batch_row = devdsame_ability_batch($batch_id);
    if (is_wp_error($batch_row)) {
        return $batch_row;
    }
    if (!$batch_row) {
        return new WP_Error('devdsame_no_batch', __('Recycle Bin batch not found.', 'devdome-safe-media-cleaner'));
    }
    $status = isset($input['status']) ? sanitize_key((string) $input['status']) : 'all';
    $status = in_array($status, array('all', 'trashed', 'restored', 'deleted'), true) ? $status : 'all';
    $page = max(1, (int) ($input['page'] ?? 1));
    $per = max(1, min(200, (int) ($input['per_page'] ?? 50)));
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    $where = 'batch_id = %d';
    $params = array($batch_id);
    if ($status !== 'all') {
        $where .= ' AND status = %s';
        $params[] = $status;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items count; values bound via prepare.
    $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ti} WHERE {$where}", $params));
    if ($total === null || $wpdb->last_error !== '') {
        return new WP_Error('devdsame_db_read', __('The Recycle Bin batch could not be read from the database right now.', 'devdome-safe-media-cleaner'));
    }
    $total = (int) $total;
    $params[] = $per;
    $params[] = ($page - 1) * $per;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items page; values bound via prepare.
    $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$ti} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", $params));
    if ($wpdb->last_error !== '') {
        return new WP_Error('devdsame_db_read', __('The Recycle Bin batch could not be read from the database right now.', 'devdome-safe-media-cleaner'));
    }
    $items = array();
    foreach ($rows as $r) {
        $items[] = array(
            'id'            => (int) $r->id,
            'attachment_id' => (int) $r->attachment_id,
            'original_path' => (string) ($r->rel_path !== null && $r->rel_path !== '' ? $r->rel_path : devdsame_path_to_relative((string) $r->original_path)),
            'original_url'  => (string) $r->original_url,
            'file_size'     => (int) $r->file_size,
            'status'        => (string) $r->status,
            'moved_at'      => (string) $r->moved_at,
            'restored_at'   => (string) $r->restored_at,
            'permanently_deleted_at' => (string) $r->permanently_deleted_at,
            'error'         => (string) $r->error_message,
        );
    }
    return array('batch_id' => $batch_id, 'page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => (int) ceil($total / $per), 'items' => $items);
}

function devdsame_ability_list_backups($input = array())
{
    $items = array();
    foreach (devdsame_backups() as $b) {
        $file = wp_basename((string) ($b['file'] ?? ''));
        $items[] = array(
            'id'         => (string) ($b['id'] ?? ''),
            'scope'      => (string) ($b['scope'] ?? 'library'),
            'file'       => $file,
            'files'      => (int) ($b['files'] ?? 0),
            'bytes'      => (int) ($b['bytes'] ?? 0),
            'created_at' => devdsame_ability_iso($b['created_at'] ?? 0),
            'present'    => $file !== '' && is_file(devdsame_backups_path() . '/' . $file),
        );
    }
    return array('total' => count($items), 'items' => $items);
}

function devdsame_ability_protected($input = array())
{
    $protected = devdsame_protected_ids();
    $ignored = devdsame_ignored_ids();
    if ($protected === null || $ignored === null) {
        return new WP_Error('devdsame_db_read', __('The protected media list could not be read from the database right now.', 'devdome-safe-media-cleaner'));
    }
    $fmt = function ($ids) {
        $out = array();
        foreach (array_keys($ids) as $id) {
            $url = (string) wp_get_attachment_url((int) $id);
            $out[] = array('attachment_id' => (int) $id, 'filename' => $url !== '' ? wp_basename($url) : '', 'url' => $url);
        }
        return $out;
    };
    return array('protected' => $fmt($protected), 'ignored' => $fmt($ignored));
}

function devdsame_ability_get_settings($input = array())
{
    return array(
        'recent_upload_protection_days' => max(1, devdsame_get_int('recent_upload_protection_days', 30)),
        'protect_recent'         => (bool) devdsame_get_int('protect_recent', 1),
        'protect_woocommerce'    => (bool) devdsame_get_int('protect_woocommerce', 1),
        'protect_theme_assets'   => (bool) devdsame_get_int('protect_theme_assets', 1),
        'confidence_threshold'   => max(0, min(100, devdsame_get_int('confidence_threshold', 75))),
        'scheduled_scan_days'    => devdsame_ability_scheduled_days(),
        'auto_delete_after_days' => devdsame_get_int('auto_delete_after_days', 0),
        'cdn_mappings'           => array_values(array_map('strval', array_keys(devdsame_get_array('cdn_mappings')))),
        'never_scan_folders'     => array_values(array_map('strval', devdsame_get_array('never_scan_folders'))),
        'email_notifications'    => (bool) devdsame_get_int('email_notifications', 0),
        'notification_frequency_days' => devdsame_get_int('notification_frequency_days', 7),
        'unused_growth_alert_mb' => round(devdsame_get_int('unused_growth_alert', 0) / 1048576, 2),
        'account_connected'      => '' !== devdsame_connected_account_id(),
        'metrics_optin'          => (bool) devdsame_get_int('metrics_optin', 0),
    );
}

/** The scheduled scan cadence in days the dispatcher honours (0 = off), including the old weekly/monthly values. */
function devdsame_ability_scheduled_days()
{
    $days = devdsame_get_int('scheduled_scan_days', 0);
    if (!$days) {
        $cadence = (string) devdsame_get_setting('scheduled_scan', 'off');
        $days = $cadence === 'weekly' ? 7 : ($cadence === 'monthly' ? 30 : 0);
    }
    return max(0, $days);
}

function devdsame_ability_error_log($input = array())
{
    $items = array();
    foreach (devdsame_get_array('error_log') as $e) {
        $items[] = array(
            'at'      => devdsame_ability_iso($e['at'] ?? 0),
            'code'    => (string) ($e['code'] ?? ''),
            'message' => (string) ($e['message'] ?? ''),
            'context' => is_array($e['context'] ?? null) ? $e['context'] : array(),
        );
    }
    return array('total' => count($items), 'items' => $items);
}

/* ------------------------------- writes ------------------------------- */

function devdsame_ability_run_scan($input = array())
{
    $input = is_array($input) ? $input : array();
    $scope = isset($input['scope']) && in_array($input['scope'], array('library', 'disk', 'full'), true) ? $input['scope'] : 'full';
    $mode = isset($input['mode']) && $input['mode'] === 'preview' ? 'preview' : 'scan';
    $job = devdsame_start_job($mode, array('scope' => $scope));
    if (is_wp_error($job)) {
        return devdsame_ability_job_error($job);
    }
    return devdsame_ability_progress_out();
}

function devdsame_ability_control_job($input = array())
{
    $input = is_array($input) ? $input : array();
    $action = isset($input['action']) ? sanitize_key((string) $input['action']) : '';
    if ($action === 'clear_error') {
        if (!devdsame_clear_last_error()) {
            return new WP_Error('devdsame_control_failed', __('The error could not be cleared (database write failed).', 'devdome-safe-media-cleaner'));
        }
        return devdsame_ability_progress_out();
    }
    $job = devdsame_get_job();
    if (!$job || !in_array($job['status'], array('running', 'paused'), true)) {
        return new WP_Error('devdsame_no_job', __('No job is running.', 'devdome-safe-media-cleaner'));
    }
    if ($action === 'pause') {
        if (!devdsame_pause_job()) {
            return new WP_Error('devdsame_control_failed', __('The job could not be paused (state write failed).', 'devdome-safe-media-cleaner'));
        }
    } elseif ($action === 'resume') {
        if (!devdsame_resume_job()) {
            return new WP_Error('devdsame_control_failed', __('The job could not be resumed (state write failed).', 'devdome-safe-media-cleaner'));
        }
    } elseif ($action === 'cancel') {
        if (!devdsame_cancel_job()) {
            return new WP_Error('devdsame_control_failed', __('The job could not be cancelled: its rollback marker could not be saved, so it keeps running.', 'devdome-safe-media-cleaner'));
        }
    } else {
        return new WP_Error('devdsame_bad_action', __('Unknown action.', 'devdome-safe-media-cleaner'));
    }
    return devdsame_ability_progress_out();
}

function devdsame_ability_trash_items($input = array())
{
    global $wpdb;
    $input = is_array($input) ? $input : array();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($input['item_ids'] ?? array())))));
    if (!$ids) {
        return new WP_Error('devdsame_empty', __('No items given.', 'devdome-safe-media-cleaner'));
    }
    // Only items of a real scan: an unknown id would just count as an error inside the job.
    $items = $wpdb->prefix . 'devdsame_scan_items';
    $ph = implode(',', array_fill(0, count($ids), '%d'));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items check; ids bound via prepare.
    $known = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$items} WHERE id IN ({$ph})", $ids)));
    if ($wpdb->last_error !== '') {
        return new WP_Error('devdsame_db_read', __('The scan items could not be read (database error); nothing was moved.', 'devdome-safe-media-cleaner'));
    }
    $missing = array_values(array_diff($ids, $known));
    if ($missing) {
        return new WP_Error('devdsame_unknown_items', sprintf(
            /* translators: %s: list of ids */
            __('These are not scan item ids: %s. Use the id field from list-scan-items.', 'devdome-safe-media-cleaner'),
            implode(', ', array_slice($missing, 0, 20))
        ));
    }
    $note = isset($input['note']) ? sanitize_text_field((string) $input['note']) : '';
    $job = devdsame_start_job('trash', array('item_ids' => $ids, 'note' => $note));
    if (is_wp_error($job)) {
        return devdsame_ability_job_error($job);
    }
    devdsame_run_tick();
    return devdsame_ability_progress_out();
}

function devdsame_ability_clean($input = array())
{
    $input = is_array($input) ? $input : array();
    $job = devdsame_start_clean(isset($input['scope']) && $input['scope'] === 'disk' ? 'disk' : 'library');
    if (is_wp_error($job)) {
        return devdsame_ability_job_error($job);
    }
    return devdsame_ability_progress_out();
}

function devdsame_ability_restore_batch($input = array())
{
    $input = is_array($input) ? $input : array();
    $b = devdsame_ability_batch((int) ($input['batch_id'] ?? 0));
    if (is_wp_error($b)) {
        return $b;
    }
    if (!$b) {
        return new WP_Error('devdsame_no_batch', __('Recycle Bin batch not found.', 'devdome-safe-media-cleaner'));
    }
    if ($b->status !== 'trashed' || !(int) $b->restore_available) {
        return new WP_Error('devdsame_not_restorable', __('This batch has nothing left to restore.', 'devdome-safe-media-cleaner'));
    }
    $job = devdsame_start_job('restore', array('batch_id' => (int) $b->id));
    if (is_wp_error($job)) {
        return devdsame_ability_job_error($job);
    }
    devdsame_run_tick();
    return devdsame_ability_progress_out();
}

function devdsame_ability_delete_batch($input = array())
{
    $input = is_array($input) ? $input : array();
    if (!isset($input['confirm']) || $input['confirm'] !== true) {
        return new WP_Error('devdsame_confirm_required', __('This cannot be undone. Pass confirm: true to proceed.', 'devdome-safe-media-cleaner'));
    }
    $b = devdsame_ability_batch((int) ($input['batch_id'] ?? 0));
    if (is_wp_error($b)) {
        return $b;
    }
    if (!$b) {
        return new WP_Error('devdsame_no_batch', __('Recycle Bin batch not found.', 'devdome-safe-media-cleaner'));
    }
    if ($b->status !== 'trashed' || !(int) $b->restore_available) {
        return new WP_Error('devdsame_not_deletable', __('This batch holds no files to delete.', 'devdome-safe-media-cleaner'));
    }
    $job = devdsame_start_job('delete', array('batch_id' => (int) $b->id));
    if (is_wp_error($job)) {
        return devdsame_ability_job_error($job);
    }
    devdsame_run_tick();
    return devdsame_ability_progress_out();
}

function devdsame_ability_clear_history($input = array())
{
    $ids = devdsame_clear_trash_history();
    if ($ids === null) {
        return new WP_Error('devdsame_db_write', __('The batch list could not be cleared (database error). Nothing was changed.', 'devdome-safe-media-cleaner'));
    }
    return array('cleared' => count($ids), 'ids' => array_map('intval', $ids));
}

function devdsame_ability_protect($input = array())
{
    $input = is_array($input) ? $input : array();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($input['attachment_ids'] ?? array())))));
    if (!$ids) {
        return new WP_Error('devdsame_empty', __('No attachment ids given.', 'devdome-safe-media-cleaner'));
    }
    $mode = isset($input['mode']) ? sanitize_key((string) $input['mode']) : 'protect';
    $mode = in_array($mode, array('protect', 'ignore', 'clear'), true) ? $mode : 'protect';
    if ($mode === 'clear' && (!isset($input['confirm']) || $input['confirm'] !== true)) {
        return new WP_Error('devdsame_confirm_required', __('Clearing a mark makes the image eligible for cleanup again. Pass confirm: true to proceed.', 'devdome-safe-media-cleaner'));
    }
    $updated = 0;
    $skipped = array();
    $failed = array();
    foreach ($ids as $id) {
        if (get_post_type($id) !== 'attachment') {
            $skipped[] = $id;
            continue;
        }
        $ok = $mode === 'clear' ? devdsame_unset_protected($id) : devdsame_set_protected($id, $mode);
        if (!$ok) {
            $failed[] = $id;
            continue;
        }
        $updated++;
    }
    if ($failed) {
        return new WP_Error('devdsame_save_failed', sprintf(
            /* translators: 1: number marked, 2: ids that failed */
            __('%1$d marked, but these could not be written (database error): %2$s.', 'devdome-safe-media-cleaner'),
            $updated,
            implode(', ', array_slice($failed, 0, 20))
        ));
    }
    return array('updated' => $updated, 'mode' => $mode, 'not_attachments' => $skipped);
}

function devdsame_ability_update_settings($input = array())
{
    $input = is_array($input) ? $input : array();
    if (!$input) {
        return new WP_Error('devdsame_empty', __('Pass at least one setting to change.', 'devdome-safe-media-cleaner'));
    }
    $writes = array();
    if (array_key_exists('recent_upload_protection_days', $input)) {
        $writes['recent_upload_protection_days'] = max(1, min(999, (int) $input['recent_upload_protection_days']));
    }
    foreach (array('protect_recent', 'protect_woocommerce', 'protect_theme_assets') as $k) {
        if (array_key_exists($k, $input)) {
            $writes[$k] = !empty($input[$k]) ? 1 : 0;
        }
    }
    if (array_key_exists('confidence_threshold', $input)) {
        $writes['confidence_threshold'] = max(0, min(100, (int) $input['confidence_threshold']));
    }
    if (array_key_exists('scheduled_scan_days', $input)) {
        $days = max(0, min(999, (int) $input['scheduled_scan_days']));
        $writes['scheduled_scan_days'] = $days;
        $writes['scheduled_scan'] = $days > 0 ? 'custom' : 'off';
    }
    if (array_key_exists('auto_delete_after_days', $input)) {
        $auto = (int) $input['auto_delete_after_days'];
        if (!in_array($auto, array(0, 7, 14, 30), true)) {
            return new WP_Error('devdsame_invalid', __('auto_delete_after_days must be 0, 7, 14 or 30.', 'devdome-safe-media-cleaner'));
        }
        $writes['auto_delete_after_days'] = $auto;
    }
    if (array_key_exists('cdn_mappings', $input)) {
        $writes['cdn_mappings'] = devdsame_sanitize_cdn_list((array) $input['cdn_mappings']);
    }
    if (array_key_exists('never_scan_folders', $input)) {
        $writes['never_scan_folders'] = devdsame_sanitize_folder_list((array) $input['never_scan_folders']);
    }
    if (array_key_exists('email_notifications', $input)) {
        $on = !empty($input['email_notifications']);
        if ($on && '' === devdsame_connected_account_id()) {
            return new WP_Error('devdsame_not_connected', __('Email notifications need a connected DevDome account; connect the site in the DevDome hub first.', 'devdome-safe-media-cleaner'));
        }
        $writes['email_notifications'] = $on ? 1 : 0;
    }
    if (array_key_exists('notification_frequency_days', $input)) {
        $freq = (int) $input['notification_frequency_days'];
        if (!in_array($freq, array(1, 3, 7, 14, 30), true)) {
            return new WP_Error('devdsame_invalid', __('notification_frequency_days must be 1, 3, 7, 14 or 30.', 'devdome-safe-media-cleaner'));
        }
        $writes['notification_frequency_days'] = $freq;
    }
    if (array_key_exists('unused_growth_alert_mb', $input)) {
        $mb = (float) $input['unused_growth_alert_mb'];
        if ($mb < 0) {
            return new WP_Error('devdsame_invalid', __('unused_growth_alert_mb cannot be negative.', 'devdome-safe-media-cleaner'));
        }
        $writes['unused_growth_alert'] = $mb > 0 ? (int) round($mb * 1048576) : 0;
    }
    if (!$writes) {
        return new WP_Error('devdsame_empty', __('Pass at least one setting to change.', 'devdome-safe-media-cleaner'));
    }
    // Changes that lower protection or add risk need the user's explicit yes. The comparison
    // needs the REAL current values: a failed read would make every change look safe.
    unset($GLOBALS['devdsame_cache'], $GLOBALS['devdsame_db_failed']);
    devdsame_get_setting('confidence_threshold');
    if (!empty($GLOBALS['devdsame_db_failed'])) {
        return new WP_Error('devdsame_db_read', __('The current settings could not be read (database error); nothing was changed.', 'devdome-safe-media-cleaner'));
    }
    $risky = devdsame_ability_risky_changes($writes);
    if ($risky && (!isset($input['confirm']) || $input['confirm'] !== true)) {
        return new WP_Error('devdsame_confirm_required', sprintf(
            /* translators: %s: list of setting changes */
            __('These changes lower protection or add risk and need confirm: true after the user agreed: %s.', 'devdome-safe-media-cleaner'),
            implode(', ', $risky)
        ));
    }
    // Written and read back (a lost write must not look like a success).
    $failed = devdsame_write_settings($writes);
    if ($failed) {
        return new WP_Error('devdsame_save_failed', sprintf(
            /* translators: %s: setting names */
            __('These settings could not be saved: %s.', 'devdome-safe-media-cleaner'),
            implode(', ', $failed)
        ));
    }
    return devdsame_ability_get_settings();
}

/** The requested setting changes that lower protection or add risk, in words (empty = none). */
function devdsame_ability_risky_changes($writes)
{
    $risky = array();
    $now = devdsame_ability_get_settings();
    foreach (array('protect_recent', 'protect_woocommerce', 'protect_theme_assets') as $k) {
        if (isset($writes[$k]) && !$writes[$k] && !empty($now[$k])) {
            $risky[] = $k . ' off';
        }
    }
    if (isset($writes['confidence_threshold']) && $writes['confidence_threshold'] < $now['confidence_threshold']) {
        $risky[] = 'confidence_threshold lowered to ' . $writes['confidence_threshold'];
    }
    if (isset($writes['recent_upload_protection_days']) && $writes['recent_upload_protection_days'] < $now['recent_upload_protection_days']) {
        $risky[] = 'recent_upload_protection_days lowered to ' . $writes['recent_upload_protection_days'];
    }
    if (isset($writes['never_scan_folders']) && array_diff($now['never_scan_folders'], $writes['never_scan_folders'])) {
        $risky[] = 'never_scan_folders loses ' . implode(', ', array_diff($now['never_scan_folders'], $writes['never_scan_folders']));
    }
    if (isset($writes['cdn_mappings']) && array_diff($now['cdn_mappings'], array_keys((array) $writes['cdn_mappings']))) {
        // Files referenced only through a dropped CDN base stop counting as used.
        $risky[] = 'cdn_mappings loses ' . implode(', ', array_diff($now['cdn_mappings'], array_keys((array) $writes['cdn_mappings'])));
    }
    if (isset($writes['auto_delete_after_days']) && $writes['auto_delete_after_days'] > 0 && ($now['auto_delete_after_days'] === 0 || $writes['auto_delete_after_days'] < $now['auto_delete_after_days'])) {
        $risky[] = 'auto_delete_after_days ' . $writes['auto_delete_after_days'] . ' (automatic permanent delete)';
    }
    if (!empty($writes['email_notifications']) && empty($now['email_notifications'])) {
        $risky[] = 'email_notifications on (sends media statistics to the DevDome account)';
    }
    return $risky;
}

function devdsame_ability_create_backup($input = array())
{
    $input = is_array($input) ? $input : array();
    $scope = isset($input['scope']) && $input['scope'] === 'disk' ? 'disk' : 'library';
    $what = isset($input['what']) && $input['what'] === 'all' ? 'all' : '';
    $job = devdsame_start_backup($scope, $what);
    if (is_wp_error($job)) {
        return devdsame_ability_job_error($job);
    }
    return devdsame_ability_progress_out();
}

function devdsame_ability_restore_backup($input = array())
{
    $input = is_array($input) ? $input : array();
    $id = isset($input['backup_id']) ? sanitize_text_field((string) $input['backup_id']) : '';
    $entry = $id !== '' ? devdsame_backup_get($id) : null;
    if (!$entry) {
        return new WP_Error('devdsame_no_backup', __('Backup not found.', 'devdome-safe-media-cleaner'));
    }
    if (!is_file(devdsame_backups_path() . '/' . wp_basename((string) $entry['file']))) {
        return new WP_Error('devdsame_no_backup_file', __('The backup zip file is missing on disk.', 'devdome-safe-media-cleaner'));
    }
    $job = devdsame_start_job('backup_restore', array('backup_id' => $id, 'scope' => (string) $entry['scope']));
    if (is_wp_error($job)) {
        return devdsame_ability_job_error($job);
    }
    return devdsame_ability_progress_out();
}

function devdsame_ability_delete_backup($input = array())
{
    $input = is_array($input) ? $input : array();
    if (!isset($input['confirm']) || $input['confirm'] !== true) {
        return new WP_Error('devdsame_confirm_required', __('This cannot be undone. Pass confirm: true to proceed.', 'devdome-safe-media-cleaner'));
    }
    $id = isset($input['backup_id']) ? sanitize_text_field((string) $input['backup_id']) : '';
    if ($id === '' || !devdsame_backup_get($id)) {
        return new WP_Error('devdsame_no_backup', __('Backup not found.', 'devdome-safe-media-cleaner'));
    }
    $job = devdsame_get_job();
    if ($job && in_array($job['status'], array('running', 'paused'), true) && $job['type'] === 'backup_restore' && (string) ($job['args']['backup_id'] ?? '') === $id) {
        return new WP_Error('devdsame_backup_in_use', __('This backup is being restored right now.', 'devdome-safe-media-cleaner'));
    }
    if (!devdsame_backup_remove($id) || devdsame_backup_get($id)) {
        return new WP_Error('devdsame_delete_failed', __('The backup zip could not be deleted (check folder permissions on /wp-content/uploads); its record was kept.', 'devdome-safe-media-cleaner'));
    }
    return array('deleted' => true, 'backup_id' => $id);
}

/* ---------------------------- MCP adapter ---------------------------- */

/** List our abilities as direct tools on the MCP Adapter's default server. */
function devdsame_mcp_default_server_tools($config)
{
    if (!is_array($config)) {
        return $config;
    }
    $tools = isset($config['tools']) && is_array($config['tools']) ? $config['tools'] : array();
    $config['tools'] = array_values(array_unique(array_merge($tools, devdsame_ability_ids())));
    return $config;
}
add_filter('mcp_adapter_default_server_config', 'devdsame_mcp_default_server_tools');
