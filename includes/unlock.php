<?php
/**
 * DevDome account connect seam + the privacy-safe product metrics transport.
 *
 * Every cleanup feature is free and unlimited. The only account-gated extra is the DevDome
 * Monitoring service: the plugin pushes aggregate scan statistics (counts + sizes only) to
 * DevDome, whose servers keep the cross-site history for the account dashboard, decide when
 * the alert threshold is passed, compose the alert email and deliver it to the ACCOUNT email.
 * The "account" is the suite-wide one the shared hub manages, so there is one connect flow
 * for the whole suite and this plugin only READS its state — it never phones home itself
 * except for the monitoring push and the opt-in aggregate metrics below.
 *
 * Both transports send ONLY privacy-safe aggregate numbers, only after opt-in, and
 * never any media file or private URL (see readme "External services").
 */

defined('ABSPATH') || exit;

/** Is this site connected to a DevDome account? Server-verified state ONLY — no local option
 *  shortcut: a stale flag must never paint "connected" while the server says "not linked". */
function devdsame_account_connected()
{
    $state = devdsame_connection_state();
    return !empty($state['ok']);
}

/* ---------------------------------------------------------------------------
 * One-click DevDome account connect (dd_id bounce + site token) + email alerts.
 * Same flow and shared wp options as DevDome Analytics, so one connect covers
 * the whole suite: devdome.com/connect bounces back with the public Account ID
 * while the site token never leaves WordPress.
 * ------------------------------------------------------------------------- */

if (!defined('DEVDSAME_CONNECT_BASE')) {
    define('DEVDSAME_CONNECT_BASE', 'https://devdome.com');
}
if (!defined('DEVDSAME_STATUS_ENDPOINT')) {
    define('DEVDSAME_STATUS_ENDPOINT', 'https://api.devdome.com/plugin/status');
}
if (!defined('DEVDSAME_MONITOR_ENDPOINT')) {
    define('DEVDSAME_MONITOR_ENDPOINT', 'https://api.devdome.com/plugin/monitor');
}
if (!defined('DEVDSAME_ACCOUNT_ENDPOINT')) {
    define('DEVDSAME_ACCOUNT_ENDPOINT', 'https://api.devdome.com/plugin/account');
}

/** Suite-shared site identity (same options DevDome Analytics provisions): domain + secret token. */
function devdsame_site_identity()
{
    $site = (string) get_option('devdcorev1_site_id', '');
    if ($site === '') {
        $site = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        update_option('devdcorev1_site_id', $site);
    }
    $token = (string) get_option('devdcorev1_site_token', '');
    if ($token === '') {
        $token = wp_generate_password(40, false);
        update_option('devdcorev1_site_token', $token);
    }
    return array('site' => $site, 'token' => $token);
}

/**
 * Server-VERIFIED connection state: {ok, account_id, email}. Delegates to the suite-shared
 * devdcorev1_connection_state() in the vendored devdome-core (hub-account.php), which
 * verifies the site token against the DevDome account service with transient caching — local
 * option presence alone never renders a connected UI. The fallback (only if an older shared core
 * without the helper wins the version guard) reads the shared last-known state, never remote.
 */
function devdsame_connection_state($force = false)
{
    if (function_exists('devdcorev1_connection_state')) {
        return devdcorev1_connection_state($force);
    }
    $state = get_option('devdcorev1_conn_state', array('ok' => 0, 'account_id' => '', 'email' => ''));
    return is_array($state) ? $state : array('ok' => 0, 'account_id' => '', 'email' => '');
}

/** The linked Account ID (DD + 8 digits) when the server verifies the site token, else ''. */
function devdsame_connected_account_id()
{
    $state = devdsame_connection_state();
    return !empty($state['ok']) ? (string) $state['account_id'] : '';
}

/**
 * One-click connect URL: the suite-shared hub connect (two-sided rt flow in devdome-core
 * 1.5.0 — connect request registered server-to-server, browser carries only an opaque
 * handle, the hub page completes the claim). SMC's old browser-carried ?dd_connect=<ID>
 * flow and its return handler were REMOVED 2026-08-11: one connect flow for the suite.
 */
function devdsame_connect_url()
{
    // NOT wp_nonce_url(): it entity-encodes the ampersand (&amp;) for HTML context, and this
    // URL lands in a plain href the browser sends literally ("amp;_wpnonce" = expired link).
    return add_query_arg(
        array(
            'action'   => 'devdcorev1_connect_go',
            '_wpnonce' => wp_create_nonce('devdcorev1_connect_go'),
        ),
        admin_url('admin-post.php')
    );
}

/** The DevDome ACCOUNT email alerts go to (shown read-only in Settings), '' when not connected. */
function devdsame_account_email($refresh = false)
{
    $state = devdsame_connection_state($refresh);
    return !empty($state['ok']) ? (string) $state['email'] : '';
}

/** Is DevDome Monitoring live (toggle on + account connected)? */
function devdsame_email_notifications_on()
{
    return devdsame_get_int('email_notifications', 0) && '' !== devdsame_connected_account_id();
}

/**
 * Push this site's scan statistics to the DevDome Monitoring service. The plugin sends raw
 * aggregate numbers only (counts + sizes, never files, filenames or URLs). DevDome's servers
 * keep the cross-site history for the account dashboard, track growth over time, decide when
 * the user's alert threshold is passed, compose the alert email and deliver it to the ACCOUNT
 * email at most once per the user's chosen frequency — none of that runs in the plugin.
 *
 * @return bool True when the monitoring service accepted the stats.
 */
function devdsame_push_monitor_stats($summary)
{
    if (!devdsame_email_notifications_on()) {
        return false;
    }
    $id = devdsame_site_identity();
    $body = array(
        'site'   => $id['site'],
        'token'  => $id['token'],
        'plugin' => 'devdome-safe-media-cleaner',
        'stats'  => array(
            'total_files'         => (int) $summary['total_files'],
            'total_library_bytes' => (int) $summary['total_library_bytes'],
            'unused_count'        => (int) $summary['unused_count'],
            'unused_bytes'        => (int) $summary['possible_cleanup_bytes'],
            'orphan_count'        => (int) $summary['orphan_count'],
        ),
        'settings' => array(
            'growth_alert_bytes' => devdsame_get_int('unused_growth_alert', 0),
            'frequency_days'     => max(1, devdsame_get_int('notification_frequency_days', 7)),
        ),
        'dashboard_url' => admin_url('admin.php?page=' . DEVDSAME_PAGE),
    );
    $resp = wp_remote_post(DEVDSAME_MONITOR_ENDPOINT, array(
        'timeout' => 10,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode($body),
    ));
    return !is_wp_error($resp) && 200 === (int) wp_remote_retrieve_response_code($resp);
}

/* ---------------------------------------------------------------------------
 * Privacy-safe product metrics (spec §"Analytics / Lead Data") — opt-in only.
 * ------------------------------------------------------------------------- */

/** Endpoint for the opt-in aggregate metrics ping (DevDome distribution only; off on WP.org). */
if (!defined('DEVDSAME_METRICS_ENDPOINT')) {
    define('DEVDSAME_METRICS_ENDPOINT', 'https://api.devdome.com/media-cleaner/metrics');
}

/** Record a lightweight usage counter (incremented locally; only ever SENT in aggregate, opt-in). */
function devdsame_metric_bump($key, $by = 1)
{
    $valid = array('scans', 'images_scanned', 'cleanup_bytes_found', 'cleanups_used', 'restores_used');
    if (!in_array($key, $valid, true)) {
        return;
    }
    $m = devdsame_get_array('metrics_counters');
    $m[$key] = (int) (isset($m[$key]) ? $m[$key] : 0) + (int) $by;
    devdsame_update_setting('metrics_counters', $m);
}

/**
 * Build the privacy-safe aggregate metrics payload. NO media files, NO private URLs, NO IDs —
 * only counts + sizes. Mirrors the readme "External services" description exactly.
 */
function devdsame_metrics_payload()
{
    $m = devdsame_get_array('metrics_counters');
    $s = function_exists('devdsame_hub_summary') ? devdsame_hub_summary() : array();
    return array(
        'plugin'              => 'devdome-safe-media-cleaner',
        'version'             => defined('DEVDSAME_VERSION') ? DEVDSAME_VERSION : '',
        'scans'               => (int) (isset($m['scans']) ? $m['scans'] : 0),
        'images_scanned'      => (int) (isset($m['images_scanned']) ? $m['images_scanned'] : 0),
        'cleanup_bytes_found' => (int) (isset($m['cleanup_bytes_found']) ? $m['cleanup_bytes_found'] : 0),
        'cleanups_used'       => (int) (isset($m['cleanups_used']) ? $m['cleanups_used'] : 0),
        'restores_used'       => (int) (isset($m['restores_used']) ? $m['restores_used'] : 0),
        'avg_library_bytes'   => isset($s['total_library_bytes']) ? (int) $s['total_library_bytes'] : 0,
        'account_connected'   => devdsame_account_connected() ? 1 : 0,
    );
}

/** Is the metrics transport allowed to fire? Opt-in checkbox AND a DevDome (non-WP.org) build. */
function devdsame_metrics_enabled()
{
    if (!devdsame_get_int('metrics_optin', 0)) {
        return false;
    }
    // Never phone home from the free WP.org build (Guideline 8 / privacy); the DevDome-owned
    // distribution opts in by defining DEVDCOREV1_DISTRIBUTION = 'devdome'.
    return defined('DEVDCOREV1_DISTRIBUTION') && DEVDCOREV1_DISTRIBUTION === 'devdome';
}

/** Weekly cron: send the opt-in aggregate metrics (no-op unless enabled). */
function devdsame_metrics_send()
{
    if (!devdsame_metrics_enabled()) {
        return;
    }
    wp_remote_post(DEVDSAME_METRICS_ENDPOINT, array(
        'timeout'  => 10,
        'blocking' => false,
        'headers'  => array('Content-Type' => 'application/json'),
        'body'     => wp_json_encode(devdsame_metrics_payload()),
    ));
}
add_action('devdsame_metrics_send', 'devdsame_metrics_send');
