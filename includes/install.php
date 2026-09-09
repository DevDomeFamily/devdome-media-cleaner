<?php
/**
 * Activation / deactivation lifecycle: create the custom tables (settings + protected
 * list + scans / scan_items / trash_batches / trash_items per spec §"Custom Tables"),
 * seed defaults, create + harden the Recycle Bin directory, schedule cron.
 * Deactivation is non-destructive (schedules only); full removal is in uninstall.php.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

function devdsame_activate()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $p = $wpdb->prefix . 'devdsame_';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // name/value settings store
    dbDelta("CREATE TABLE {$p}settings (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        setting_name varchar(191) NOT NULL,
        setting_value longtext NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY setting_name (setting_name)
    ) $charset_collate;");

    // user-protected / ignored attachment IDs (survives re-scans)
    dbDelta("CREATE TABLE {$p}protected (
        attachment_id bigint(20) unsigned NOT NULL,
        mode varchar(16) NOT NULL DEFAULT 'protect',
        created_at datetime NOT NULL,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (attachment_id),
        KEY idx_mode (mode)
    ) $charset_collate;");

    // scan sessions (spec §wp_devdsame_scans)
    dbDelta("CREATE TABLE {$p}scans (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        status varchar(32) NOT NULL DEFAULT 'queued',
        mode varchar(16) NOT NULL DEFAULT 'full',
        started_at datetime NULL,
        finished_at datetime NULL,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        total_attachments bigint(20) unsigned NOT NULL DEFAULT 0,
        total_files bigint(20) unsigned NOT NULL DEFAULT 0,
        used_count bigint(20) unsigned NOT NULL DEFAULT 0,
        unused_count bigint(20) unsigned NOT NULL DEFAULT 0,
        uncertain_count bigint(20) unsigned NOT NULL DEFAULT 0,
        duplicate_count bigint(20) unsigned NOT NULL DEFAULT 0,
        orphan_count bigint(20) unsigned NOT NULL DEFAULT 0,
        missing_count bigint(20) unsigned NOT NULL DEFAULT 0,
        possible_cleanup_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
        total_library_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
        error_message text NULL,
        PRIMARY KEY  (id),
        KEY idx_status (status),
        KEY idx_started_at (started_at)
    ) $charset_collate;");

    // per-attachment / per-file scan result (spec §wp_devdsame_scan_items)
    dbDelta("CREATE TABLE {$p}scan_items (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        scan_id bigint(20) unsigned NOT NULL,
        attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
        file_path text NOT NULL,
        file_url text NULL,
        file_hash char(32) NULL,
        file_size bigint(20) unsigned NOT NULL DEFAULT 0,
        width int unsigned NOT NULL DEFAULT 0,
        height int unsigned NOT NULL DEFAULT 0,
        mime_type varchar(100) NULL,
        upload_date datetime NULL,
        status varchar(20) NOT NULL DEFAULT 'unused',
        confidence tinyint unsigned NOT NULL DEFAULT 0,
        reason_code text NULL,
        references_found int unsigned NOT NULL DEFAULT 0,
        is_selected tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_scan_status (scan_id, status),
        KEY idx_scan_attachment (scan_id, attachment_id),
        KEY idx_file_hash (file_hash),
        KEY idx_file_size (file_size)
    ) $charset_collate;");

    // cleanup batches (spec §wp_devdsame_trash_batches)
    dbDelta("CREATE TABLE {$p}trash_batches (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        status varchar(32) NOT NULL DEFAULT 'trashed',
        total_files bigint(20) unsigned NOT NULL DEFAULT 0,
        total_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
        restore_available tinyint(1) NOT NULL DEFAULT 1,
        permanent_delete_after datetime NULL,
        manifest_path text NULL,
        note text NULL,
        PRIMARY KEY  (id),
        KEY idx_status (status),
        KEY idx_created_at (created_at)
    ) $charset_collate;");

    // moved files / restore records (spec §wp_devdsame_trash_items)
    dbDelta("CREATE TABLE {$p}trash_items (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        batch_id bigint(20) unsigned NOT NULL,
        attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
        original_path text NOT NULL,
        trash_path text NOT NULL,
        original_url text NULL,
        rel_path text NULL,
        file_hash char(32) NULL,
        file_size bigint(20) unsigned NOT NULL DEFAULT 0,
        meta_json longtext NULL,
        moved_at datetime NULL,
        restored_at datetime NULL,
        permanently_deleted_at datetime NULL,
        status varchar(32) NOT NULL DEFAULT 'trashed',
        error_message text NULL,
        PRIMARY KEY  (id),
        KEY idx_batch (batch_id),
        KEY idx_attachment (attachment_id),
        KEY idx_status (status)
    ) $charset_collate;");

    // Seed defaults only when missing (reactivation/upgrade never wipes).
    $defaults = array(
        // protection / detection tuning
        'recent_upload_protection_days' => 30,         // never auto-select uploads newer than this
        'protect_recent'                => 1,
        'protect_woocommerce'           => 1,
        'protect_theme_assets'          => 1,
        'large_image_threshold_bytes'   => 1048576,    // 1 MB
        'confidence_threshold'          => 75,         // below = never auto-select
        'scan_chunk_size'               => 200,        // attachments per tick (clamped 25..1000)
        'duplicate_methods'             => array('hash'),
        'never_scan_folders'            => array(),    // relative-to-uploads folders to skip
        'cdn_mappings'                  => array(),    // ['https://cdn.example.com/uploads/' => '']
        // scheduling / retention
        'scheduled_scan'                => 'off',       // off | weekly | monthly
        'auto_delete_after_days'        => 0,           // 0 = off; 7 | 14 | 30
        'unused_growth_alert'           => 0,           // alert if unused grows beyond X bytes (0 = off)
        'notification_frequency_days'   => 7,           // min days between alert emails (1|3|7|14|30)
        'metrics_optin'                 => 0,           // opt-in privacy-safe product metrics
        // headline counters / cached summary fields
        'last_scan_id'                  => 0,
        'last_scan_at'                  => 0,
        'total_files'                   => 0,
        'used_count'                    => 0,
        'unused_count'                  => 0,
        'uncertain_count'               => 0,
        'orphan_count'                  => 0,
        'missing_count'                 => 0,
        'duplicate_count'               => 0,
        'possible_cleanup_bytes'        => 0,
        'total_library_bytes'           => 0,
        'cleanliness_score'             => 0,
    );
    $table = $wpdb->prefix . 'devdsame_settings';
    foreach ($defaults as $name => $value) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; values bound via prepare; one-time seed.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (setting_name, setting_value) VALUES (%s, %s)",
            $name,
            is_array($value) ? serialize($value) : $value
        ));
    }

    unset($GLOBALS['devdsame_cache']);

    // Create + harden the Recycle Bin directory.
    devdsame_init_safe_trash();

    // Schedule the scheduled-scan dispatcher + the auto-delete sweep (both honour the
    // settings; if disabled they no-op). The queue runner is self-scheduling per job.
    if (!wp_next_scheduled('devdsame_scheduled_scan')) {
        wp_schedule_event(time() + 3600, 'daily', 'devdsame_scheduled_scan');
    }
    if (!wp_next_scheduled('devdsame_autodelete_sweep')) {
        wp_schedule_event(time() + 3600, 'daily', 'devdsame_autodelete_sweep');
    }
    if (!wp_next_scheduled('devdsame_summary_refresh')) {
        wp_schedule_event(time() + 7200, 'daily', 'devdsame_summary_refresh');
    }
    // Opt-in aggregate-metrics ping (no-op unless the user opts in AND this is a DevDome build).
    if (!wp_next_scheduled('devdsame_metrics_send')) {
        wp_schedule_event(time() + 10800, 'weekly', 'devdsame_metrics_send');
    }
}

function devdsame_deactivate()
{
    // Non-destructive: settings, scans, AND the Recycle Bin survive a toggle.
    // Only clear schedules so nothing runs while the plugin is off.
    wp_clear_scheduled_hook('devdsame_scheduled_scan');
    wp_clear_scheduled_hook('devdsame_autodelete_sweep');
    wp_clear_scheduled_hook('devdsame_summary_refresh');
    wp_clear_scheduled_hook('devdsame_run_job');
    wp_clear_scheduled_hook('devdsame_metrics_send');
    delete_option('devdsame_tick_lock');
    wp_cache_flush();
}

/**
 * Create the Recycle Bin directory and make it un-servable: index.php silence, a
 * deny-all .htaccess (Apache), and a web.config (IIS) so trashed-but-not-deleted media is
 * never publicly served or indexed during the review window.
 */
function devdsame_init_safe_trash()
{
    return devdsame_harden_dir(devdsame_safe_trash_dir());
}

/**
 * Make a plugin-owned uploads subfolder un-servable: index.php silence, a deny-all
 * .htaccess (Apache), and a web.config (IIS). Used by the Recycle Bin and backups folders.
 */
function devdsame_harden_dir($dir)
{
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    if (!is_dir($dir)) {
        return false;
    }

    $index = $dir . '/index.php';
    if (!file_exists($index)) {
        @file_put_contents($index, "<?php\n// Silence is golden.\n");
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        $rules = "Order allow,deny\nDeny from all\n"
            . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule mod_headers.c>\nHeader set X-Robots-Tag \"noindex, nofollow\"\n</IfModule>\n";
        @file_put_contents($htaccess, $rules);
    }

    $webconfig = $dir . '/web.config';
    if (!file_exists($webconfig)) {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n"
            . "    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n"
            . "  </system.webServer>\n</configuration>\n";
        @file_put_contents($webconfig, $xml);
    }

    return true;
}
