<?php
/**
 * Request-wide database guard (DESIGN.md 24 + 24.5, Safe Media Cleaner 1.1.5).
 *
 * $wpdb reports a failed query only through last_error, and the NEXT query clears it. A failed read that a
 * call site took for an empty answer ("no rows" = nothing references this image, "no row" = already gone) was
 * invisible by the time the action reported success. The guard records every failed query as it happens, and
 * inside an action window (REST handler, ability, admin form handler, the admin page, a job tick, the cron
 * sweep) the plugin's own write helpers refuse once a query failed ("no write after a failed read": the
 * settings and job store, the state options, a move to the Recycle Bin, a permanent delete), while the
 * response boundary answers a database error instead of "done". Outside a window (other plugins calling in,
 * the test suite) nothing changes.
 *
 * Reference: Malware Scanner 1.2.3 includes/findings.php, copied with this plugin's prefix.
 */

defined('ABSPATH') || exit;

$GLOBALS['devdsame_db_guard'] = array('errors' => array(), 'count' => 0, 'last' => '', 'mark' => 0, 'open' => 0, 'pending' => false);

/** Record the pending $wpdb->last_error once (internal). */
function devdsame_db_guard_sync()
{
    global $wpdb;
    $g = &$GLOBALS['devdsame_db_guard'];
    if (isset($wpdb->last_error) && (string) $wpdb->last_error !== '' && empty($g['pending'])) {
        $g['count'] = (int) $g['count'] + 1; // the counter never saturates; the list below is diagnostic
        $g['last']  = (string) $wpdb->last_error;
        if (count($g['errors']) < 50) {
            $g['errors'][] = (string) $wpdb->last_error;
        }
        $g['pending'] = true;
    }
}

/** 'query' filter (priority 1): the previous query's error is recorded before wpdb::query() flushes it. */
function devdsame_db_guard_record($query)
{
    devdsame_db_guard_sync();
    $GLOBALS['devdsame_db_guard']['pending'] = false; // a new query starts; its own error is fresh
    return $query;
}

/** Clear $wpdb->last_error the honest way: record it first. No code in this plugin clears it by hand. */
function devdsame_db_reset_error()
{
    global $wpdb;
    devdsame_db_guard_sync();
    $GLOBALS['devdsame_db_guard']['pending'] = false;
    if (isset($wpdb->last_error)) {
        $wpdb->last_error = '';
    }
}

/** True when the query run since devdsame_db_reset_error() failed. */
function devdsame_db_failed()
{
    global $wpdb;
    return isset($wpdb->last_error) && (string) $wpdb->last_error !== '';
}

/** Open a guard window. Nested windows share the outer list; only the outermost begin() clears it. */
function devdsame_db_guard_begin()
{
    global $wpdb;
    devdsame_db_guard_sync(); // a nested window inherits what is pending
    $g = &$GLOBALS['devdsame_db_guard'];
    $g['open'] = (int) $g['open'] + 1;
    if ($g['open'] === 1) {
        $g['errors']  = array();
        $g['count']   = 0;
        $g['last']    = '';
        $g['mark']    = 0;
        $g['pending'] = false;
        if (isset($wpdb->last_error)) {
            $wpdb->last_error = ''; // an error from before this action is not this action's
        }
    }
}

function devdsame_db_guard_end()
{
    $g = &$GLOBALS['devdsame_db_guard'];
    $g['open'] = max(0, (int) $g['open'] - 1);
}

/** True while an action window is open. */
function devdsame_db_guard_open()
{
    return (int) $GLOBALS['devdsame_db_guard']['open'] > 0;
}

/** Move the mark to now: errors before it are handled (reported by their own step). */
function devdsame_db_guard_rebase()
{
    devdsame_db_guard_sync();
    $GLOBALS['devdsame_db_guard']['mark'] = (int) $GLOBALS['devdsame_db_guard']['count'];
}

/** The failure counter (synced): a caller keeps its own mark with it, like the job tick does for one stage. */
function devdsame_db_guard_count()
{
    devdsame_db_guard_sync();
    return (int) $GLOBALS['devdsame_db_guard']['count'];
}

/** True when a query failed since the mark. */
function devdsame_db_guard_failed()
{
    devdsame_db_guard_sync();
    $g = $GLOBALS['devdsame_db_guard'];
    return (int) $g['count'] > (int) $g['mark'];
}

/** True when a window is open AND a query failed since its mark: the write helpers and the boundaries refuse on this. */
function devdsame_db_guard_active()
{
    return devdsame_db_guard_open() && devdsame_db_guard_failed();
}

/** The last recorded error text ('' when none). */
function devdsame_db_guard_error()
{
    devdsame_db_guard_sync();
    return (string) $GLOBALS['devdsame_db_guard']['last'];
}

/**
 * Mask what must never travel in an error text: credentials in URLs (user:pass@host), secret-looking query
 * values (key, token, secret, pass, auth, sig, ...) and email addresses.
 */
function devdsame_redact_text($text)
{
    $text = (string) $text;
    $text = preg_replace('~(https?://)[^\s/@:]+:[^\s/@]+@~i', '$1[redacted]@', $text);
    $text = preg_replace('~([?&;][^=&;\s]*(?:key|token|secret|pass|pwd|auth|sig|signature|credential|session)[^=&;\s]*=)[^&;\s]*~i', '$1[redacted]', $text);
    $text = preg_replace('~[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}~i', '[email redacted]', $text);
    return $text;
}

/** The one text every boundary uses. */
function devdsame_db_guard_message()
{
    $err = devdsame_redact_text(devdsame_db_guard_error()); // a query text inside the error can carry a credential: redact BEFORE the cut
    if (function_exists('mb_substr')) {
        $err = mb_substr($err, 0, 160);
    } else {
        $err = substr($err, 0, 160);
    }
    return 'A database query failed during this action' . ($err !== '' ? ' (' . $err . ')' : '') . '. The result is not trusted and nothing more was changed: reload the page and check the current state before trying again.';
}

/* ------------------------------ boundaries ------------------------------ */

/**
 * REST boundary: every route callback runs inside a guard window. A query that failed and that the handler
 * did not turn into its own WP_Error becomes WP_Error devdsame_db_error (500) instead of a success answer.
 */
function devdsame_rest_guarded($cb)
{
    return function (WP_REST_Request $request) use ($cb) {
        devdsame_db_guard_begin();
        try {
            $r = call_user_func($cb, $request);
        } finally {
            $failed = devdsame_db_guard_failed();
            devdsame_db_guard_end();
        }
        if (!is_wp_error($r) && $failed) {
            $r = new WP_Error('devdsame_db_error', devdsame_db_guard_message(), array('status' => 500));
        }
        return $r;
    };
}

/** Abilities boundary: same window, a failed window becomes WP_Error devdsame_db_error. */
function devdsame_ability_guarded($cb, $input)
{
    devdsame_db_guard_begin();
    try {
        $r = call_user_func($cb, $input);
    } finally {
        $failed = devdsame_db_guard_failed();
        devdsame_db_guard_end();
    }
    if (!is_wp_error($r) && $failed) {
        $r = new WP_Error('devdsame_db_error', devdsame_db_guard_message());
    }
    return $r;
}

/**
 * Form-handler boundary (PRG redirects): the success flag a handler wants to carry becomes mc_err=db when a
 * query failed inside its window. The page prints the guard message for that flag.
 */
function devdsame_db_guard_flag($flag)
{
    return devdsame_db_guard_active() ? array('mc_err' => 'db') : (array) $flag;
}

/* ------------------------- proved option writes (24.5) ------------------------- */

/** The stored option row, past the option cache: array(exists, value), or false when the read failed. */
function devdsame_option_row($key)
{
    global $wpdb;
    devdsame_db_reset_error();
    // get_row(), not get_var(): get_var() answers null for an EMPTY value as well as for a missing row.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the proof of a write must read the row, not the cache.
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", (string) $key), ARRAY_A);
    if (devdsame_db_failed()) {
        return false;
    }
    if (!is_array($row) || !array_key_exists('option_value', $row)) {
        return array(false, null);
    }
    return array(true, maybe_unserialize($row['option_value']));
}

function devdsame_option_norm($v)
{
    if (is_array($v)) {
        $out = array();
        foreach ($v as $k => $item) {
            $out[(string) $k] = devdsame_option_norm($item);
        }
        return $out;
    }
    if (is_object($v)) {
        return devdsame_option_norm(get_object_vars($v));
    }
    if (null === $v) {
        return null;
    }
    if (is_bool($v)) {
        return $v ? '1' : '';
    }
    return (string) $v;
}

function devdsame_option_same($stored, $value)
{
    return devdsame_option_norm($stored) === devdsame_option_norm($value);
}

/** Write a state-carrying option and prove it: true only when the row holds the value afterwards. Refuses inside a failed window. */
function devdsame_option_write($key, $value)
{
    if (devdsame_db_guard_active()) {
        return false;
    }
    update_option($key, $value, false);
    $row = devdsame_option_row($key);
    return is_array($row) && $row[0] && devdsame_option_same($row[1], $value);
}

/** Delete a state-carrying option and prove it: true only when no row exists afterwards. A failed read is not "gone". */
function devdsame_option_delete($key)
{
    if (devdsame_db_guard_active()) {
        return false;
    }
    delete_option($key);
    $row = devdsame_option_row($key);
    return is_array($row) && !$row[0];
}
