<?php
/**
 * Settings storage — a single name/value table (the suite's Bot Protection /
 * Redirect Manager convention) with a process-level read cache. Arrays are
 * serialized on write and unserialized with allowed_classes => false on read.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

function devdsame_settings_table()
{
    global $wpdb;
    return $wpdb->prefix . 'devdsame_settings';
}

/** Read a setting, falling back to $default when unset. Arrays are unserialized. */
function devdsame_get_setting($name, $default = '')
{
    global $wpdb;

    if (!isset($GLOBALS['devdsame_cache']) || !is_array($GLOBALS['devdsame_cache'])) {
        $GLOBALS['devdsame_cache'] = array();
        $table = devdsame_settings_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; whole-table read cached for the request in $GLOBALS.
        $rows = $wpdb->get_results("SELECT setting_name, setting_value FROM $table", ARRAY_A);
        if ($wpdb->last_error !== '') {
            // A failed read is not an empty table: hand back the default, cache nothing, and flag
            // it so a running scan can stop instead of treating protections as unset.
            unset($GLOBALS['devdsame_cache']);
            if (function_exists('devdsame_db_read_failed')) {
                devdsame_db_read_failed();
            }
            return $default;
        }
        if ($rows) {
            foreach ($rows as $r) {
                $GLOBALS['devdsame_cache'][$r['setting_name']] = $r['setting_value'];
            }
        }
    }

    if (!array_key_exists($name, $GLOBALS['devdsame_cache'])) {
        return $default;
    }

    $value = $GLOBALS['devdsame_cache'][$name];
    $unser = @unserialize($value, array('allowed_classes' => false));
    if ($unser !== false || $value === 'b:0;') {
        return $unser;
    }
    return $value;
}

/** Write a setting (insert or update). Arrays are serialized. Invalidates the cache. */
function devdsame_update_setting($name, $value)
{
    global $wpdb;
    $table = devdsame_settings_table();
    $stored = is_array($value) ? serialize($value) : $value;

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; values bound via prepare; settings table is not cacheable.
    $result = $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (setting_name, setting_value) VALUES (%s, %s)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        $name,
        $stored
    ));

    unset($GLOBALS['devdsame_cache']);
    // false = the query failed. 0 rows is fine: MySQL reports 0 when the value did not change.
    return $result !== false;
}

/**
 * CDN base URLs as the Settings form and the Abilities API store them: one absolute URL per entry,
 * trailing slash dropped, invalid or empty lines skipped. Returns the stored map (url => '').
 */
function devdsame_sanitize_cdn_list($lines)
{
    $cdn = array();
    foreach ((array) $lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $url = esc_url_raw($line);
        if ($url !== '' && preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
            $cdn[untrailingslashit($url)] = '';
        }
    }
    return $cdn;
}

/** Never-scan folders as stored: uploads-relative, no traversal, no leading or trailing slashes, unique. */
function devdsame_sanitize_folder_list($lines)
{
    $nf = array();
    foreach ((array) $lines as $line) {
        $line = trim(str_replace('..', '', (string) $line), " \t/\\");
        if ($line !== '') {
            $nf[] = sanitize_text_field($line);
        }
    }
    return array_values(array_unique(array_filter($nf)));
}

/** Write settings and read them back. Returns the names that are NOT stored as requested (empty = all saved). */
function devdsame_write_settings($writes)
{
    foreach ($writes as $name => $value) {
        devdsame_update_setting($name, $value);
    }
    unset($GLOBALS['devdsame_cache']);
    $failed = array();
    foreach ($writes as $name => $value) {
        $stored = devdsame_get_setting($name, null);
        if (is_array($value) ? ($stored != $value) : ((string) $stored !== (string) $value)) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- arrays compare by content
            $failed[] = $name;
        }
    }
    return $failed;
}

/** Convenience: read an array setting, always returning an array. */
function devdsame_get_array($name, $default = array())
{
    $v = devdsame_get_setting($name, $default);
    return is_array($v) ? $v : (array) $default;
}

/** Read an integer setting. */
function devdsame_get_int($name, $default = 0)
{
    return (int) devdsame_get_setting($name, $default);
}
