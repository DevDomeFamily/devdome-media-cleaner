<?php
/**
 * DevDome Tools — suite-wide account connection state + disconnect handler.
 *
 * The account exists for one user-facing purpose: the optional DevDome Monitoring service
 * (email alerts + the account dashboard). This file holds the server-verified
 * connection-state helper and the hub Disconnect handler. Nothing here gates any feature.
 */

defined('ABSPATH') || exit;

/**
 * Suite-wide, server-VERIFIED account connection state: {ok, account_id, email}.
 * "Connected" means the suite's site token (option devdcorev1_site_token, provisioned by the
 * one-click connect in any DevDome plugin) is linked to a DevDome account RIGHT NOW — the
 * alert service resolves token -> account -> email. Local option presence alone (stale or
 * test leftovers) never renders a connected UI. Cached: a transient marker skips the remote
 * call on normal page loads and a persistent last-known state survives transient outages.
 * function_exists-guarded so plugins can ship helpers of the same name safely.
 */
if (!function_exists('devdcorev1_connection_state')) {
    function devdcorev1_connection_state($force = false)
    {
        $state = get_option('devdcorev1_conn_state', array('ok' => 0, 'account_id' => '', 'email' => '', 'plan' => ''));
        if (!is_array($state)) {
            $state = array('ok' => 0, 'account_id' => '', 'email' => '', 'plan' => '');
        }
        $state += array('plan' => ''); // states stored before the plan field existed
        if (!$force && get_transient('devdcorev1_conn_checked')) {
            return $state;
        }
        $token = (string) get_option('devdcorev1_site_token', '');
        if ('' === $token) {
            // No token = never connected anywhere in the suite. Definitive, no remote call.
            $state = array('ok' => 0, 'account_id' => '', 'email' => '');
            update_option('devdcorev1_conn_state', $state, false);
            set_transient('devdcorev1_conn_checked', 1, 12 * HOUR_IN_SECONDS);
            return $state;
        }
        // No calling home before the user opts in (wp.org Guideline 7): the token is
        // auto-provisioned locally, so its mere presence is NOT consent. Until some explicit
        // connect action has been recorded on this site — a completed connect, a saved Account
        // ID, or the user pressing a Connect button (devdcorev1_connect_started, stamped by
        // devdcorev1_hub_handle_connect_go) — the state is definitively "not connected" and no
        // remote request is made. Deliberately NOT transient-cached: the first connect action
        // must be able to verify immediately.
        if (empty($state['ok'])
            && '' === (string) get_option('devdcorev1_connected_at', '')
            && '' === (string) get_option('devdcorev1_account_id', '')
            && !get_option('devdcorev1_connect_started', 0)) {
            return array('ok' => 0, 'account_id' => '', 'email' => '');
        }
        $site = (string) get_option('devdcorev1_site_id', '');
        if ('' === $site) {
            $site = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        }
        // The site token is a long-lived secret: it travels in the Authorization header of a
        // POST, never in a URL (query strings end up in proxy, CDN and server logs). Core 1.6.0.
        $resp = wp_remote_post('https://api.devdome.com/plugin/account', array(
            'timeout' => 8,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ),
            'body'    => wp_json_encode(array('site' => $site)),
        ));
        if (is_wp_error($resp)) {
            set_transient('devdcorev1_conn_checked', 1, 10 * MINUTE_IN_SECONDS); // outage: keep last known
            return $state;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (200 === $code && is_array($data) && !empty($data['email']) && is_email($data['email'])) {
            $acct = isset($data['account_id']) ? strtoupper(sanitize_text_field($data['account_id'])) : '';
            $state = array(
                'ok'         => 1,
                'account_id' => preg_match('/^DD\d{8}$/', $acct) ? $acct : '',
                'email'      => sanitize_email($data['email']),
                'plan'       => isset($data['plan']) ? sanitize_key((string) $data['plan']) : '',
            );
            update_option('devdcorev1_conn_state', $state, false);
            // 15 min, not 12 h: the hub shows the account PLAN from this state, and a plan
            // change (upgrade/downgrade) must reach the dashboard within minutes.
            set_transient('devdcorev1_conn_checked', 1, 15 * MINUTE_IN_SECONDS);
        } elseif (in_array($code, array(400, 401, 403, 404), true)) {
            // Definitive: the token is not linked to an account (or was unlinked server-side).
            $state = array('ok' => 0, 'account_id' => '', 'email' => '');
            update_option('devdcorev1_conn_state', $state, false);
            set_transient('devdcorev1_conn_checked', 1, HOUR_IN_SECONDS);
        } else {
            set_transient('devdcorev1_conn_checked', 1, 10 * MINUTE_IN_SECONDS); // 5xx: keep last known
        }
        return $state;
    }
}

/** Convenience: is the site connected to a DevDome account (server-verified, cached)? */
if (!function_exists('devdcorev1_account_is_connected')) {
    function devdcorev1_account_is_connected()
    {
        $state = devdcorev1_connection_state();
        return !empty($state['ok']);
    }
}

/**
 * The hub's "Connect your DevDome account" button posts here (admin-post). This is the explicit
 * opt-in: it stamps devdcorev1_connect_started — which is what first allows
 * devdcorev1_connection_state() to verify remotely — then registers a short-lived connect
 * request with DevDome (site-authenticated, the same two-sided flow Analytics 1.0.4 uses) and
 * sends the browser to devdome.com carrying ONLY the opaque request token. Before this click,
 * the suite makes no account request at all.
 */
function devdcorev1_hub_handle_connect_go()
{
    if (!current_user_can('manage_options')) {
        wp_die('You are not allowed to do that.');
    }
    check_admin_referer('devdcorev1_connect_go');
    update_option('devdcorev1_connect_started', time(), false);
    $tools = admin_url('admin.php?page=' . (defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdome-tools'));
    // Optional `return`: the plugin screen the user came from, shown again once the connect
    // completes (1.5.9). Same-site URLs only (wp_validate_redirect); anything else = the hub.
    $return = isset($_GET['return']) ? wp_validate_redirect(esc_url_raw(wp_unslash($_GET['return'])), '') : '';
    // Auto-provision the suite identity so connecting is one click (mirrors the plugins'
    // activation provisioning; covers a suite where no plugin has provisioned it yet).
    $site = (string) get_option('devdcorev1_site_id', '');
    if ('' === $site) {
        $site = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        update_option('devdcorev1_site_id', $site);
    }
    $token = (string) get_option('devdcorev1_site_token', '');
    if ('' === $token) {
        $token = wp_generate_password(40, false);
        update_option('devdcorev1_site_token', $token);
    }
    // Two-sided connect, step 1: register the request server-to-server. The single-use nonce
    // stays in a transient here — it is never placed in a browser URL.
    $resp = wp_remote_post('https://analytics.devdome.com/api/plugin/connect/start', array(
        'timeout' => 15,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array(
            'site_id'     => $site,
            'site_domain' => $site,
            'site_token'  => $token,
            'return_url'  => $tools,
        )),
    ));
    $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
    $data = is_wp_error($resp) ? null : json_decode(wp_remote_retrieve_body($resp), true);
    if (200 !== $code || !is_array($data) || empty($data['request_token']) || empty($data['nonce'])) {
        // Carry the reason (review 2026-09-11 round 2): a callback-URL refusal (HTTP admin URL,
        // custom port, other admin host) used to read like "cannot reach DevDome".
        $why = is_wp_error($resp)
            ? 'network: ' . $resp->get_error_message()
            : 'server ' . $code . (is_array($data) && !empty($data['error']) ? ': ' . (string) $data['error'] : '');
        wp_safe_redirect(add_query_arg(array('dd_error' => 'start', 'dd_why' => rawurlencode(substr(sanitize_text_field($why), 0, 160))), $tools));
        exit;
    }
    $rt = sanitize_key((string) $data['request_token']);
    // 20 min, not 10: the server gives the request 10 min to be authorized and then a fresh
    // 10 min for the claim, so a slow sign-in must not outlive the local correlation.
    set_transient('devdcorev1_connrt_' . $rt, (string) $data['nonce'], 20 * MINUTE_IN_SECONDS);
    if ('' !== $return) {
        set_transient('devdcorev1_connret_' . $rt, $return, 20 * MINUTE_IN_SECONDS);
    }
    $account_url = rtrim((string) apply_filters('devdcorev1_account_url', 'https://devdome.com'), '/');
    wp_redirect($account_url . '/connect/?' . http_build_query(array('rt' => $rt))); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed first-party host, never user input.
    exit;
}
add_action('admin_post_devdcorev1_connect_go', 'devdcorev1_hub_handle_connect_go');

/**
 * Two-sided connect, step 3: devdome.com sends the browser back to the hub page with
 * ?dd_connect=1&rt=. The rt must match a request THIS site started (its single-use nonce is
 * in our transient) — that correlation is the CSRF guard. The Account ID itself is claimed
 * server-to-server with the site token; it never rides the browser.
 */
function devdcorev1_hub_maybe_complete_connect()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $slug = defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdome-tools';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this IS the connect return; the CSRF guard is the single-use transient correlation on `rt`, not a form nonce.
    if (!isset($_GET['page'], $_GET['dd_connect'], $_GET['rt']) || $slug !== sanitize_key(wp_unslash($_GET['page']))) {
        return;
    }
    $tools = admin_url('admin.php?page=' . (defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdome-tools'));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
    $rt    = sanitize_key(wp_unslash($_GET['rt']));
    $nonce = '' !== $rt ? get_transient('devdcorev1_connrt_' . $rt) : false;
    if (false === $nonce) {
        wp_safe_redirect(add_query_arg('dd_error', 'expired', $tools));
        exit;
    }
    // The handshake transients stay until the claim SUCCEEDS (review 2026-09-11): a timeout, an
    // outbound block or a server refusal used to burn the nonce first, so a refresh of this same
    // callback said "expired" while the account server still had the request as authorized.
    // Now a refresh retries the claim; the server side stays single-use on its own.
    $back  = get_transient('devdcorev1_connret_' . $rt);
    $site  = (string) get_option('devdcorev1_site_id', '');
    $token = (string) get_option('devdcorev1_site_token', '');
    $resp  = wp_remote_post('https://analytics.devdome.com/api/plugin/connect/claim', array(
        'timeout' => 30, // several sequential DB round trips server-side; 15 s abandoned live claims
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array(
            'site_id'       => $site,
            'site_domain'   => $site,
            'site_token'    => $token,
            'request_token' => $rt,
            'nonce'         => (string) $nonce,
        )),
    ));
    $data = is_wp_error($resp) ? null : json_decode(wp_remote_retrieve_body($resp), true);
    $acct = ( is_array($data) && !empty($data['ok']) && !empty($data['account_id']) )
        ? strtoupper(sanitize_text_field((string) $data['account_id'])) : '';
    if (!preg_match('/^DD\d{8}$/', $acct)) {
        // Say WHY (the hub renders dd_error + dd_why): transport error text, or the server's
        // HTTP code and error field. The user can press Connect again; nothing is lost locally.
        if (is_wp_error($resp)) {
            $why = 'network: ' . $resp->get_error_message();
        } else {
            $why = 'server ' . (int) wp_remote_retrieve_response_code($resp)
                . (is_array($data) && !empty($data['error']) ? ': ' . (string) $data['error'] : '');
        }
        // dd_retry: the hub renders a "Try again" link back to this callback (same rt, the kept
        // nonce), so the retained request is actually retried instead of a fresh Connect.
        wp_safe_redirect(add_query_arg(array('dd_error' => 'verify', 'dd_why' => rawurlencode(substr(sanitize_text_field($why), 0, 160)), 'dd_retry' => $rt), $tools));
        exit;
    }
    delete_transient('devdcorev1_connrt_' . $rt);
    delete_transient('devdcorev1_connret_' . $rt);
    update_option('devdcorev1_account_id', $acct);
    update_option('devdcorev1_connected_at', gmdate('c'));
    // The claim itself is the proof of connection: record it locally FIRST, so the UI lands
    // connected even when the follow-up account lookup below fails (it then keeps this state).
    update_option('devdcorev1_conn_state', array('ok' => 1, 'account_id' => $acct, 'email' => '', 'plan' => ''), false);
    delete_transient('devdcorev1_conn_checked');
    devdcorev1_connection_state(true); // fetch email + plan now so the UI shows them
    // Back to the screen the user connected from (a plugin's own page), else the hub.
    wp_safe_redirect((is_string($back) && '' !== $back) ? $back : $tools);
    exit;
}
add_action('admin_init', 'devdcorev1_hub_maybe_complete_connect');

/**
 * Serve /.well-known/devdome-connect-proof.txt — the sha256 of THIS site's token. It lets the
 * DevDome server adopt a fresh install's auto-provisioned token at connect start (only someone
 * who controls the site can serve the file); the hash is one-way, reading it reveals nothing
 * usable. Analytics 1.0.4+ ships the same handler — identical output, first one to run exits.
 */
if (!function_exists('devdcorev1_serve_connect_proof')) {
    function devdcorev1_serve_connect_proof()
    {
        $path = isset($_SERVER['REQUEST_URI'])
            ? (string) wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH)
            : '';
        // Query form (core 1.6.6): hosts that answer /.well-known/* from the web server never let
        // this handler run, so the same proof is also served on the home URL with a query flag.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public one-way hash, no state change.
        $query_form = isset($_GET['devdome-connect-proof']) && '1' === (string) wp_unslash($_GET['devdome-connect-proof']);
        if ('/.well-known/devdome-connect-proof.txt' !== $path && !$query_form) {
            return;
        }
        $token = (string) get_option('devdcorev1_site_token', '');
        if ('' === $token) {
            return;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo esc_html(hash('sha256', $token));
        exit;
    }
    add_action('init', 'devdcorev1_serve_connect_proof');
}

/**
 * Handle the hub Disconnect: unlink the site from the account server-side (authenticated by
 * sending the site id + site token to the DevDome API), then clear every local connect
 * artifact so the verified state flips to disconnected everywhere.
 */
function devdcorev1_hub_handle_account()
{
    if (empty($_POST['devdcorev1_disconnect']) || !current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('devdcorev1_account', '_ddacct');
    delete_option('devdcorev1_account_connected'); // legacy flag: nothing reads it since 1.5.0 — delete, don't zero
    $site  = (string) get_option('devdcorev1_site_id', '');
    $token = (string) get_option('devdcorev1_site_token', '');
    $remote_ok = true; // nothing to unlink when the site was never registered
    if ($site !== '' && $token !== '') {
        $res = wp_remote_post('https://api.devdome.com/plugin/disconnect', array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array('site' => $site, 'token' => $token)),
        ));
        // The local state is cleared either way (the owner asked to disconnect), but a timeout or a refusal means
        // the account server still lists this site: say so instead of "Disconnected" (review 2026-09-10).
        $code      = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        $remote_ok = $code >= 200 && $code < 300;
    }
    delete_option('devdcorev1_conn_state');
    delete_transient('devdcorev1_conn_checked');
    delete_option('devdcorev1_connected_at');
    delete_option('devdcorev1_account_id'); // disconnect wipes the WHOLE identity — a surviving id re-paints "connected" UIs
    delete_option('devdcorev1_account_email');
    delete_option('devdcorev1_connect_started');
    delete_option('devdcorev1_hub_connect_dismissed'); // the connect card should reappear
    devdcorev1_connection_state(true); // re-verify now so the UI flips immediately
    wp_safe_redirect(add_query_arg(
        array('page' => DEVDCOREV1_TOOLS_MENU_SLUG, 'ddacct' => $remote_ok ? 'disconnected' : 'disconnected-local'),
        admin_url('admin.php')
    ));
    exit;
}
add_action('admin_init', 'devdcorev1_hub_handle_account');
