<?php
/**
 * Admin: menu, scoped enqueue, settings save handlers (PRG, nonce + cap), and the tabbed
 * dashboard rendered 1:1 on the shared `.dd-app` design system. Tabs: Overview (score + status
 * cards + main buttons + storage trend), Review (visual grid powered by mc-admin.js + REST),
 * Recycle Bin (batches + restore/delete), Settings.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

require_once __DIR__ . '/devdome-tools-menu.php';

function devdsame_menu()
{
    add_submenu_page(
        defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdcorev1-tools',
        'Safe Media Cleaner',
        'Safe Media Cleaner',
        devdsame_capability(),
        DEVDSAME_PAGE,
        'devdsame_render_page',
        3
    );
}
add_action('admin_menu', 'devdsame_menu');

function devdsame_enqueue_assets($hook)
{
    if (strpos($hook, DEVDSAME_PAGE) === false) {
        return;
    }
    $css = DEVDSAME_DIR . 'assets/devdome-tools-tw.css';
    $ver = file_exists($css) ? filemtime($css) : DEVDSAME_VERSION;
    wp_enqueue_style('devdsame-ui', DEVDSAME_URL . 'assets/devdome-tools-tw.css', array(), $ver);
    wp_enqueue_style('dashicons');

    $js = DEVDSAME_DIR . 'assets/mc-admin.js';
    $jver = file_exists($js) ? filemtime($js) : DEVDSAME_VERSION;
    wp_enqueue_script('devdsame-admin', DEVDSAME_URL . 'assets/mc-admin.js', array(), $jver, true);

    wp_localize_script('devdsame-admin', 'DevdSame', array(
        'root'         => esc_url_raw(rest_url('devdsame/v1/')),
        'nonce'        => wp_create_nonce('wp_rest'),
        'maxUpload'    => (int) wp_max_upload_size(),
        'i18n'         => array(
            'scanning'      => __('Scanning...', 'devdome-safe-media-cleaner'),
            'starting'      => __('Starting...', 'devdome-safe-media-cleaner'),
            'completed'     => __('Completed', 'devdome-safe-media-cleaner'),
            'pause'         => __('Pause', 'devdome-safe-media-cleaner'),
            'resume'        => __('Resume', 'devdome-safe-media-cleaner'),
            'pausing'       => __('Pausing after the current batch...', 'devdome-safe-media-cleaner'),
            'noResults'     => __('No media matched this filter.', 'devdome-safe-media-cleaner'),
            'confirmTrash'  => __('Move the selected images to Recycle Bin? Nothing is permanently deleted, you can restore anytime.', 'devdome-safe-media-cleaner'),
            'confirmDelete' => __('Permanently delete this batch? This cannot be undone.', 'devdome-safe-media-cleaner'),
            'confirmRestore' => __('Restore every file in this batch to its original location?', 'devdome-safe-media-cleaner'),
            'confirmDeleteBackup' => __('Delete this backup file? This cannot be undone.', 'devdome-safe-media-cleaner'),
            'confirmRestoreBackup' => __('Restore every file in this backup? Existing files with the same name are overwritten.', 'devdome-safe-media-cleaner'),
            'confirmClearHistory' => __('Clear the batch list? Everything with nothing left to restore is removed. Batches that still hold restorable files are kept.', 'devdome-safe-media-cleaner'),
            'added' => __('Added', 'devdome-safe-media-cleaner'),
            'selectedLbl' => __('selected', 'devdome-safe-media-cleaner'),
            'showing' => __('Showing', 'devdome-safe-media-cleaner'),
            'of' => __('of', 'devdome-safe-media-cleaner'),
            'remove' => __('Remove', 'devdome-safe-media-cleaner'),
            'backupDeleted' => __('Backup deleted.', 'devdome-safe-media-cleaner'),
            // translators: 1: number of images, 2: total size.
            'confirmClean'  => __('Move %1$s images (%2$s) to Recycle Bin? You can restore them anytime.', 'devdome-safe-media-cleaner'),
            // translators: %d is the maximum number of images cleaned per batch on the free plan.
        ),
    ));

    wp_add_inline_style('devdsame-ui', devdsame_inline_css());
    wp_add_inline_script('devdsame-admin', devdsame_inline_js());
}
add_action('admin_enqueue_scripts', 'devdsame_enqueue_assets');

/** Page-specific CSS on top of the shared suite stylesheet (attached via wp_add_inline_style). */
function devdsame_inline_css()
{
    return <<<'CSS'
        .dd-app .mc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:14px; }
        .dd-app .mc-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; display:flex; flex-direction:column; position:relative; }
        .dd-app .mc-card.is-selected { border-color:#4f46e5; box-shadow:0 0 0 2px rgba(79,70,229,.25); }
        .dd-app .mc-thumb { aspect-ratio:1/1; background:#f3f4f6 center/cover no-repeat; display:flex; align-items:center; justify-content:center; }
        .dd-app .mc-thumb img { width:100%; height:100%; object-fit:cover; }
        .dd-app .mc-meta { padding:10px 12px; font-size:12px; }
        .dd-app .mc-fn { font-weight:600; color:#374151; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .dd-app .mc-sub { color:#6b7280; margin-top:2px; }
        .dd-app .mc-reasons { color:#9ca3af; margin-top:6px; font-size:11px; line-height:1.4; }
        .dd-app .mc-check { position:absolute; top:8px; left:8px; width:20px; height:20px; margin:0; appearance:none; -webkit-appearance:none; background:#fff; border:1px solid #d1d5db; border-radius:6px; box-shadow:0 1px 3px rgba(15,23,42,.22); cursor:pointer; display:grid; place-content:center; }
        .dd-app .mc-check::before { content:none; }
        .dd-app .mc-check:checked { background:#4f46e5; border-color:#4f46e5; }
        .dd-app .mc-check:checked::after { content:''; width:11px; height:11px; background:#fff; clip-path:polygon(14% 44%, 0 65%, 50% 100%, 100% 16%, 80% 0%, 43% 62%); }
        .dd-app .mc-badge { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 7px; border-radius:999px; }
        .dd-app .mc-badge-safe { background:#ecfdf5; color:#059669; }
        .dd-app .mc-badge-warn { background:#fffbeb; color:#b45309; }
        .dd-app .mc-badge-prot { background:#eef2ff; color:#4338ca; }
        .dd-app .mc-cardactions { padding:0 12px 12px; display:flex; gap:6px; }
        .dd-app .mc-toolbar { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:16px; }
        .dd-app .dd-btn .dashicons { margin-right:5px; }
        /* The two tool-action rows never wrap — all 4 buttons stay on one line, SAME size,
           and the row always fits its card (flex-share, never a fixed width). */
        .dd-app .mc-actions { flex-wrap:nowrap; width:100%; }
        .dd-app .mc-actions .dd-btn { white-space:nowrap; }
        .dd-app .mc-actions > .dd-btn, .dd-app .mc-actions .mc-bk-split > .dd-btn { flex:1 1 0; min-width:0; justify-content:center; font-size:12.5px; padding-left:6px; padding-right:6px; overflow:hidden; }
        .dd-app .mc-actions .mc-bk-split { flex:1 1 0; min-width:0; }
        .dd-app .mc-actions .mc-bk-split > .dd-btn { width:100%; }
        .dd-app .dd-btn:disabled { opacity:.45; cursor:not-allowed; }
        /* Header bug-report button — EXACT twin of the DevDome dashboard .stats-btn bug button. */
        .dd-app .mc-bug-btn { width:36px; height:36px; border-radius:50px; border:1px solid #dadce0; background:#fff; display:grid; place-items:center; cursor:pointer; color:#5f6368; transition:all .2s; text-decoration:none; }
        .dd-app .mc-bug-btn:hover { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
        .dd-app .mc-bug-btn svg { width:16px; height:16px; }
        .dd-app .mc-bug-btn:focus { outline:none; box-shadow:none; }
        /* Tabs: active = underline only — never a focus square lingering after a click. */
        .dd-app .dd-tab:focus, .dd-app .dd-tab:focus-visible { outline:none; box-shadow:none; }
        /* Numbers are typed, not clicked — hide the +/- spinners everywhere. */
        .dd-app input[type=number]::-webkit-outer-spin-button, .dd-app input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
        .dd-app input[type=number] { -moz-appearance:textfield; appearance:textfield; }
        /* Review Library/Disk switch — the two tools, proper segmented control. */
        .dd-app .mc-swch { display:inline-flex; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:12px; padding:4px; gap:4px; }
        .dd-app .mc-swch-btn { display:inline-flex; align-items:center; gap:7px; border:0; background:none; padding:8px 20px; border-radius:9px; font-size:13px; font-weight:600; color:#6b7280; cursor:pointer; white-space:nowrap; font-family:inherit; transition:all .15s; }
        .dd-app .mc-swch-btn .dashicons { font-size:16px; width:16px; height:16px; }
        .dd-app .mc-swch-btn:hover { color:#374151; }
        .dd-app .mc-swch-btn.is-on { background:#4f46e5; color:#fff; box-shadow:0 4px 10px rgba(79,70,229,.35); }
        .dd-app .mc-swch-btn:focus { outline:none; }
        /* Review filename search — one box per side (Library / Disk), right edge of the switch row. */
        .dd-app .mc-search { position:relative; display:inline-flex; align-items:center; }
        .dd-app .mc-search .dashicons { position:absolute; left:10px; font-size:16px; width:16px; height:16px; color:#9ca3af; pointer-events:none; }
        .dd-app .mc-search-in { width:230px; height:38px; padding:0 12px 0 34px; border:1px solid #e5e7eb; border-radius:10px; font-size:13px; background:#fff; color:#374151; font-family:inherit; }
        .dd-app .mc-search-in:focus { outline:none; border-color:#c7d2fe; box-shadow:0 0 0 2px rgba(79,70,229,.15); }
        .dd-app .mc-search-in::-webkit-search-cancel-button { -webkit-appearance:none; }
        /* Sub-filter pills with live counts. */
        .dd-app .mc-sub-pills { display:inline-flex; gap:6px; flex-wrap:wrap; }
        .dd-app .mc-pill-btn { display:inline-flex; align-items:center; gap:7px; border:1px solid #e5e7eb; background:#fff; padding:5px 12px; border-radius:999px; font-size:12.5px; font-weight:600; color:#6b7280; cursor:pointer; white-space:nowrap; font-family:inherit; }
        .dd-app .mc-pill-btn:hover { border-color:#c7d2fe; color:#4338ca; }
        .dd-app .mc-pill-btn.is-on { background:#eef2ff; border-color:#c7d2fe; color:#4338ca; }
        .dd-app .mc-pill-btn:focus { outline:none; }
        .dd-app .mc-pill-num { font-weight:700; color:#9ca3af; }
        .dd-app .mc-pill-btn.is-on .mc-pill-num { color:#4f46e5; }
        /* Review pagination — numbered, active page highlighted. */
        .dd-app .mc-page-btn { min-width:34px; justify-content:center; }
        /* Catalogue / list view switch. */
        .dd-app .mc-viewsw { display:inline-flex; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:9px; padding:3px; gap:2px; }
        .dd-app .mc-view-btn { border:0; background:none; padding:4px 10px; border-radius:6px; color:#9ca3af; cursor:pointer; display:inline-flex; align-items:center; }
        .dd-app .mc-view-btn .dashicons { font-size:17px; width:17px; height:17px; }
        .dd-app .mc-view-btn.is-on { background:#fff; color:#4338ca; box-shadow:0 1px 2px rgba(0,0,0,.08); }
        .dd-app .mc-view-btn:focus { outline:none; }
        /* Export CSV: icon-only button, exact height-twin of the view switch beside it. */
        .dd-app .mc-export-btn { display:inline-flex; align-items:center; justify-content:center; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:9px; padding:7px 10px; color:#6b7280; text-decoration:none; }
        .dd-app .mc-export-btn:hover { color:#4338ca; border-color:#c7d2fe; }
        .dd-app .mc-export-btn:focus { outline:none; box-shadow:none; }
        .dd-app .mc-export-btn .dashicons { font-size:17px; width:17px; height:17px; }
        /* List view: compact rows instead of the thumbnail catalogue. */
        .dd-app .mc-grid.is-list { display:block; }
        .dd-app .mc-grid.is-list .mc-card { flex-direction:row; align-items:center; gap:14px; padding:8px 12px; margin-bottom:6px; border-radius:10px; }
        .dd-app .mc-grid.is-list .mc-thumb { width:52px; height:52px; aspect-ratio:auto; flex-shrink:0; border-radius:8px; overflow:hidden; }
        .dd-app .mc-grid.is-list .mc-meta { flex:1; min-width:0; display:flex; align-items:center; gap:18px; padding:0; }
        .dd-app .mc-grid.is-list .mc-fn { flex:1; min-width:0; }
        .dd-app .mc-grid.is-list .mc-sub { margin:0; white-space:nowrap; }
        .dd-app .mc-grid.is-list .mc-reasons { display:none; }
        .dd-app .mc-grid.is-list .mc-meta > div:nth-of-type(4) { margin:0 !important; }
        .dd-app .mc-grid.is-list .mc-check { position:static; margin:0; flex-shrink:0; }
        .dd-app .mc-grid.is-list .mc-cardactions { padding:0; flex-shrink:0; }
        /* Add-list fields — exact Redirect Manager "Find Links/Buttons Containing" pattern:
           textarea + indigo Add button, then a "Added (N)" row list with per-row Remove. */
        .dd-app .dd-list-addbtn { padding:0 16px; height:38px; border:0; border-radius:8px; background:#4f46e5; color:#fff; font-size:13px; font-weight:600; cursor:pointer; white-space:nowrap; }
        .dd-app .dd-list-addbtn:hover { background:#4338ca; }
        .dd-app .dd-sel-group { margin-top:12px; }
        .dd-app .dd-sel-head { font-size:12px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
        .dd-app .dd-sel-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:6px 10px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; margin-bottom:4px; font-size:13px; color:#374151; word-break:break-all; }
        .dd-app .dd-sel-x { cursor:pointer; color:#ef4444; font-weight:600; font-size:12px; flex-shrink:0; padding:3px 10px; border:1px solid #fecaca; border-radius:6px; background:#fff; line-height:1.2; }
        .dd-app .dd-sel-x:hover { background:#fef2f2; }
        /* Recycle Bin source pills. */
        .dd-app .mc-src { display:inline-block; font-size:11px; font-weight:600; padding:2px 9px; border-radius:999px; }
        .dd-app .mc-src-lib { background:#eef2ff; color:#4338ca; }
        .dd-app .mc-src-disk { background:#f3f4f6; color:#4b5563; }
        /* Recycle Bin status labels: first-capital, colored, never uppercase. */
        .dd-app .mc-st { font-weight:600; font-size:12.5px; }
        .dd-app .mc-st-trashed { color:#b45309; }
        .dd-app .mc-st-restored { color:#059669; }
        .dd-app .mc-st-deleted { color:#dc2626; }
        /* Backup & Restore: compact suite-style table (dd-th's fixed 210px width breaks 5-col lists). */
        .dd-app .mc-bk-table { width:100%; border-collapse:collapse; font-size:13px; }
        .dd-app .mc-bk-table th { text-align:left; font-weight:700; color:#4b5563; font-size:12px; padding:0 14px 8px 0; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
        .dd-app .mc-bk-table td { padding:8px 14px 8px 0; border-bottom:1px solid #f3f4f6; color:#374151; vertical-align:middle; white-space:nowrap; }
        .dd-app .mc-bk-table td.mc-bk-name { max-width:320px; overflow:hidden; text-overflow:ellipsis; font-weight:600; color:#374151; }
        .dd-app .mc-bk-table td.mc-bk-actions { text-align:right; padding-right:0; }
        .dd-app .mc-bk-table td.mc-bk-actions form { display:inline-block; margin:0 0 0 6px; }
        .dd-app .mc-bk-table td.mc-bk-actions .dd-btn { margin-left:6px; }
        .dd-app .mc-bk-table td.mc-bk-actions form .dd-btn { margin-left:0; }
        .dd-app .mc-bk .dd-sec-head form { margin:0 0 0 auto; }
        /* Create Backup button = the whole button opens the menu; a backup only starts
           when one of the two menu options is clicked. */
        .dd-app .mc-bk-split { position:relative; display:inline-flex; }
        .dd-app .mc-caret { margin:0 0 0 4px !important; font-size:12px !important; width:12px !important; height:12px !important; line-height:16px; }
        .dd-app .mc-bk-split-menu { display:none; position:absolute; top:calc(100% + 4px); left:0; z-index:40; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 8px 22px rgba(0,0,0,.1); min-width:200px; padding:4px; }
        .dd-app .mc-bk-split.is-open .mc-bk-split-menu { display:block; }
        .dd-app .mc-bk-split-menu button { display:block; width:100%; text-align:left; background:none; border:0; padding:8px 10px; border-radius:6px; font-size:13px; color:#374151; cursor:pointer; white-space:nowrap; }
        .dd-app .mc-bk-split-menu button:hover { background:#eef2ff; color:#3730a3; }
        .dd-app .dd-stat-sub { font-size:11px; color:#9ca3af; margin-top:2px; }
        .dd-app .mc-progress { height:8px; background:#e5e7eb; border-radius:999px; overflow:hidden; }
        .dd-app .mc-progress > span { display:block; height:100%; background:#4f46e5; width:0; transition:width .55s ease; }
        .dd-app .mc-progress-wrap.is-done .mc-progress > span { background:#10b981; }
        .dd-app .mc-progress-wrap.is-done .mc-progress-msg { color:#059669; font-weight:600; }
        /* SVG ring (Bot Protection style) — disable the old CSS conic donut behind it. */
        .dd-app .dd-donut { background:none; }
        .dd-app .dd-donut:before { content:none; }
        .dd-app .mc-spark { display:flex; align-items:flex-end; gap:3px; height:48px; }
        .dd-app .mc-spark i { flex:1; background:#c7d2fe; border-radius:2px 2px 0 0; min-height:2px; }
        .dd-app .dd-tabpanel { display:none; }
        .dd-app .dd-tabpanel.is-active { display:block; }
CSS;
}

/** Instant client-side tab switcher (attached to the admin bundle via wp_add_inline_script). */
function devdsame_inline_js()
{
    return <<<'JS'
    (function () {
        var app = document.querySelector('.dd-app');
        if (!app) { return; }
        var tabs = app.querySelectorAll('.dd-tab[data-dd-tab]');
        var panels = app.querySelectorAll('.dd-tabpanel[data-dd-panel]');
        function show(name) {
            if (name === 'dashboard') { name = 'overview'; } // legacy hash from 1.0.8 and earlier
            var found = false;
            panels.forEach(function (p) {
                var on = p.getAttribute('data-dd-panel') === name;
                p.classList.toggle('is-active', on);
                if (on) { found = true; }
            });
            if (!found) { return; }
            tabs.forEach(function (t) {
                t.classList.toggle('is-active', t.getAttribute('data-dd-tab') === name);
            });
        }
        tabs.forEach(function (t) {
            t.addEventListener('click', function (e) {
                e.preventDefault();
                var name = t.getAttribute('data-dd-tab');
                show(name);
                if (window.history && history.replaceState) { history.replaceState(null, '', '#' + name); }
            });
        });
        // In-panel cross-links (href="#review" etc.) switch instantly too.
        window.addEventListener('hashchange', function () {
            var h = (location.hash || '').replace('#', '');
            if (h) { show(h); }
        });
        var hash = (location.hash || '').replace('#', '');
        if (hash) { show(hash); }
    })();
JS;
}

/* ----------------------------- save handlers ---------------------------- */

/** Settings tab save (self-POST, nonce + cap). */
function devdsame_handle_settings_save()
{
    if (empty($_POST['devdsame_settings_save']) || !current_user_can(devdsame_capability())) {
        return;
    }
    check_admin_referer('devdsame_settings', '_mcsn');

    $recent = isset($_POST['mc_recent_days']) ? (int) $_POST['mc_recent_days'] : 30;
    devdsame_update_setting('recent_upload_protection_days', max(1, min(999, $recent)));

    // Scheduled scan: on/off + writable "every N days".
    $sched_on = !empty($_POST['mc_scheduled_scan_on']);
    $sched_days = isset($_POST['mc_scheduled_scan_days']) ? (int) $_POST['mc_scheduled_scan_days'] : 7;
    devdsame_update_setting('scheduled_scan_days', $sched_on ? max(1, min(999, $sched_days)) : 0);
    devdsame_update_setting('scheduled_scan', $sched_on ? 'custom' : 'off');

    $auto = isset($_POST['mc_auto_delete_days']) ? (int) $_POST['mc_auto_delete_days'] : 0;
    devdsame_update_setting('auto_delete_after_days', in_array($auto, array(0, 7, 14, 30), true) ? $auto : 0);

    $threshold = isset($_POST['mc_confidence_threshold']) ? (int) $_POST['mc_confidence_threshold'] : 75;
    devdsame_update_setting('confidence_threshold', max(0, min(100, $threshold)));

    devdsame_update_setting('protect_recent', empty($_POST['mc_protect_recent']) ? 0 : 1);
    devdsame_update_setting('protect_woocommerce', empty($_POST['mc_protect_woocommerce']) ? 0 : 1);
    devdsame_update_setting('protect_theme_assets', empty($_POST['mc_protect_theme_assets']) ? 0 : 1);

    // Duplicate methods (allowlisted).
    // CDN mappings: one "from base url" per line.
    $cdn_raw = isset($_POST['mc_cdn_mappings']) ? sanitize_textarea_field(wp_unslash($_POST['mc_cdn_mappings'])) : '';
    $cdn = array();
    foreach (preg_split('/\r\n|\r|\n/', $cdn_raw) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $url = esc_url_raw($line);
            if ($url !== '') {
                $cdn[untrailingslashit($url)] = '';
            }
        }
    }
    devdsame_update_setting('cdn_mappings', $cdn);

    // Never-scan folders: one relative path per line.
    $nf_raw = isset($_POST['mc_never_scan_folders']) ? sanitize_textarea_field(wp_unslash($_POST['mc_never_scan_folders'])) : '';
    $nf = array();
    foreach (preg_split('/\r\n|\r|\n/', $nf_raw) as $line) {
        $line = trim($line, " \t/");
        if ($line !== '') {
            // Strip traversal + leading slashes; keep simple relative folder.
            $line = str_replace('..', '', $line);
            $nf[] = sanitize_text_field($line);
        }
    }
    devdsame_update_setting('never_scan_folders', array_values(array_unique($nf)));

    // Email notifications: alerts always go to the DevDome ACCOUNT email, so the toggle only
    // arms when the site is connected (the checkbox is frozen in the UI otherwise).
    $notif_on = !empty($_POST['mc_email_notifications_on']) && '' !== devdsame_connected_account_id();
    devdsame_update_setting('email_notifications', $notif_on ? 1 : 0);

    $notif_freq = isset($_POST['mc_notification_frequency_days']) ? (int) $_POST['mc_notification_frequency_days'] : 7;
    devdsame_update_setting('notification_frequency_days', in_array($notif_freq, array(1, 3, 7, 14, 30), true) ? $notif_freq : 7);

    $growth_on = !empty($_POST['mc_growth_alert_on']);
    $growth = isset($_POST['mc_unused_growth_alert_mb']) ? (float) $_POST['mc_unused_growth_alert_mb'] : 0;
    devdsame_update_setting('unused_growth_alert', ($growth_on && $growth > 0) ? (int) round($growth * 1048576) : 0);

    wp_safe_redirect(add_query_arg(array('page' => DEVDSAME_PAGE, 'mc_tab' => 'settings', 'mc_saved' => '1'), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdsame_handle_settings_save');

/** Restore a batch (non-JS fallback, nonce + cap, PRG). */
function devdsame_handle_restore()
{
    if (empty($_POST['devdsame_restore_batch']) || !current_user_can(devdsame_capability())) {
        return;
    }
    check_admin_referer('devdsame_restore', '_mcrn');
    $batch = (int) $_POST['devdsame_restore_batch'];
    if ($batch) {
        devdsame_restore_batch($batch);
        devdsame_refresh_summary();
    }
    wp_safe_redirect(add_query_arg(array('page' => DEVDSAME_PAGE, 'mc_tab' => 'trash', 'mc_restored' => '1'), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdsame_handle_restore');

/** Permanently delete a batch (non-JS fallback, nonce + cap, PRG). */
function devdsame_handle_delete()
{
    if (empty($_POST['devdsame_delete_batch']) || !current_user_can(devdsame_capability())) {
        return;
    }
    check_admin_referer('devdsame_delete', '_mcdn');
    $batch = (int) $_POST['devdsame_delete_batch'];
    if ($batch) {
        devdsame_permanent_delete_batch($batch);
        devdsame_refresh_summary();
    }
    wp_safe_redirect(add_query_arg(array('page' => DEVDSAME_PAGE, 'mc_tab' => 'trash', 'mc_deleted' => '1'), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdsame_handle_delete');

/** Dismiss the persisted last-error banner (nonce + cap, PRG). */
function devdsame_handle_clear_error()
{
    if (empty($_GET['mc_clear_error']) || !current_user_can(devdsame_capability())) {
        return;
    }
    check_admin_referer('devdsame_clear_error', '_mcerr');
    if (function_exists('devdsame_clear_last_error')) {
        devdsame_clear_last_error();
    }
    wp_safe_redirect(add_query_arg(array('page' => DEVDSAME_PAGE, 'mc_tab' => 'overview'), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdsame_handle_clear_error');

/* ----------------------------- helpers ---------------------------------- */

/**
 * Render a suite-style custom dropdown (.dd-dd) backed by a hidden form input, used INSTEAD of a
 * native <select> on the Settings form so the screen stays on the design system. mc-admin.js syncs
 * the hidden input from the chosen .dd-dd-opt on `dd:change`.
 *
 * @param string $name        the posted field name (also the hidden input + a data-name hook)
 * @param mixed  $current     currently selected option key
 * @param array  $options     [value => label] (labels already translated; printed escaped)
 * @param string $trigger_css optional inline style on the trigger wrapper
 */
function devdsame_render_dropdown($name, $current, $options, $trigger_css = '')
{
    $current = (string) $current;
    $current_label = isset($options[$current]) ? $options[$current] : reset($options);
    ?>
    <div class="dd-dd mc-settings-dd" data-name="<?php echo esc_attr($name); ?>" data-value="<?php echo esc_attr($current); ?>" style="<?php echo esc_attr($trigger_css); ?>">
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($current); ?>">
        <div class="dd-dd-trigger"><span class="dd-dd-label"><?php echo esc_html($current_label); ?></span><span class="dashicons dashicons-arrow-down-alt2 dd-dd-chev"></span></div>
        <div class="dd-dd-panel">
            <?php foreach ($options as $val => $label) : ?>
                <div class="dd-dd-opt" data-value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/** Is the DevDome Backup & Migration plugin active (for the "Create Backup First" nudge)? */
function devdsame_backup_active()
{
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    return is_plugin_active('devdome-backup-migration/devdome-backup-migration.php');
}

/* ----------------------------- page shell ------------------------------- */

function devdsame_render_page()
{
    $tab = isset($_GET['mc_tab']) ? sanitize_key(wp_unslash($_GET['mc_tab'])) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector, allowlisted below.
    if ($tab === 'dashboard') {
        $tab = 'overview'; // legacy slug from 1.0.8 and earlier
    }
    if (!in_array($tab, array('overview', 'review', 'trash', 'backup', 'settings'), true)) {
        $tab = 'overview';
    }
    $base = admin_url('admin.php?page=' . DEVDSAME_PAGE);
    $s = devdsame_hub_summary();
    ?>
    <div class="dd-app min-h-screen bg-gray-50 text-[#3c434a] font-sans text-[13px]">
        <div class="bg-white border-b border-gray-200 shadow-sm">
            <div class="max-w-5xl px-6 py-4 flex items-center gap-3">
                <div class="p-1.5 rounded text-white inline-flex items-center justify-center" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="8.5" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/><path d="M16.6 5.4l.8 1.8 1.8.8-1.8.8-.8 1.8-.8-1.8-1.8-.8 1.8-.8z" fill="currentColor" stroke="none"/></svg></div>
                <h1 class="text-xl font-bold text-gray-800 m-0"><?php esc_html_e('DevDome Safe Media Cleaner', 'devdome-safe-media-cleaner'); ?></h1>
                <div style="margin-left:auto;display:flex;align-items:center;gap:10px;">
                    <span class="dd-pill dd-pill-ok"><span class="dashicons dashicons-shield" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Recycle Bin protected', 'devdome-safe-media-cleaner'); ?></span>
                    <a class="mc-bug-btn" href="<?php echo esc_url('https://devdome.com/report-bug?plugin=devdome-safe-media-cleaner&v=' . DEVDSAME_VERSION); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e('Report a bug', 'devdome-safe-media-cleaner'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>
                    </a>
                </div>
            </div>
            <div class="dd-tabs max-w-5xl" style="margin:0;border-bottom:0;" role="tablist">
                <a class="dd-tab <?php echo $tab === 'overview' ? 'is-active' : ''; ?>" href="#overview" role="tab" data-dd-tab="overview"><span class="dashicons dashicons-dashboard"></span> <?php esc_html_e('Overview', 'devdome-safe-media-cleaner'); ?></a>
                <a class="dd-tab <?php echo $tab === 'review' ? 'is-active' : ''; ?>" href="#review" role="tab" data-dd-tab="review"><span class="dashicons dashicons-images-alt2"></span> <?php esc_html_e('Review', 'devdome-safe-media-cleaner'); ?></a>
                <a class="dd-tab <?php echo $tab === 'trash' ? 'is-active' : ''; ?>" href="#trash" role="tab" data-dd-tab="trash"><span class="dashicons dashicons-trash"></span> <?php esc_html_e('Recycle Bin', 'devdome-safe-media-cleaner'); ?></a>
                <a class="dd-tab <?php echo $tab === 'backup' ? 'is-active' : ''; ?>" href="#backup" role="tab" data-dd-tab="backup"><span class="dashicons dashicons-backup"></span> <?php esc_html_e('Backup & Restore', 'devdome-safe-media-cleaner'); ?></a>
                <a class="dd-tab <?php echo $tab === 'settings' ? 'is-active' : ''; ?>" href="#settings" role="tab" data-dd-tab="settings"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Settings', 'devdome-safe-media-cleaner'); ?></a>
            </div>
        </div>
        <main>
            <?php // All panels render once; switching is instant client-side (no page reload). The
            // $tab from ?mc_tab= still picks the initial active panel so form-save PRG redirects land right. ?>
            <div class="dd-tabpanel<?php echo $tab === 'overview' ? ' is-active' : ''; ?>" data-dd-panel="overview" role="tabpanel"><?php devdsame_render_dashboard_tab($s); ?></div>
            <div class="dd-tabpanel<?php echo $tab === 'review' ? ' is-active' : ''; ?>" data-dd-panel="review" role="tabpanel"><?php devdsame_render_review_tab($s); ?></div>
            <div class="dd-tabpanel<?php echo $tab === 'trash' ? ' is-active' : ''; ?>" data-dd-panel="trash" role="tabpanel"><?php devdsame_render_trash_tab($s); ?></div>
            <div class="dd-tabpanel<?php echo $tab === 'backup' ? ' is-active' : ''; ?>" data-dd-panel="backup" role="tabpanel"><?php devdsame_render_backup_tab(); ?></div>
            <div class="dd-tabpanel<?php echo $tab === 'settings' ? ' is-active' : ''; ?>" data-dd-panel="settings" role="tabpanel"><?php devdsame_render_settings_tab(); ?></div>
        </main>
    </div>
    <?php
}

/* ----------------------------- dashboard tab ---------------------------- */

function devdsame_render_dashboard_tab($s)
{
    $base = admin_url('admin.php?page=' . DEVDSAME_PAGE);
    // Exact percentage (one decimal, trailing .0 trimmed) — never a stepped approximation.
    $never_scanned = ((int) $s['last_scan_id'] === 0);
    // Never scanned => no score to show: empty ring + em-dash, not a made-up 100.
    $score = $never_scanned ? 0.0 : (float) $s['score'];
    $score_fmt = $never_scanned ? '—' : rtrim(rtrim(number_format($score, 1, '.', ''), '0'), '.');
    $trend = devdsame_get_array('storage_trend');
    $saved = isset($_GET['mc_saved']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from a PRG redirect.
    // After-cleanup reduction nudge (storage_reduction_pct from the trend history).
    $reduction_pct = function_exists('devdsame_storage_reduction_pct') ? (int) devdsame_storage_reduction_pct() : 0;
    ?>
    <div class="max-w-5xl px-6 py-6">
        <?php if ($saved) : ?>
            <div class="dd-banner dd-banner-ok dd-flash" style="margin-bottom:16px;"><?php esc_html_e('Settings saved.', 'devdome-safe-media-cleaner'); ?></div>
        <?php endif; ?>

        <?php
        $last_error = function_exists('devdsame_last_error') ? devdsame_last_error() : null;
        if ($last_error) :
            $err_dismiss = wp_nonce_url(add_query_arg(array('page' => DEVDSAME_PAGE, 'mc_clear_error' => '1'), admin_url('admin.php')), 'devdsame_clear_error', '_mcerr');
            $err_log_url = wp_nonce_url(admin_url('admin-post.php?action=devdsame_export&what=errors&format=csv'), 'devdsame_export', '_mcx');
        ?>
            <div class="dd-banner dd-banner-error" style="margin-bottom:16px;">
                <div style="display:flex;align-items:flex-start;gap:10px;">
                    <span class="dashicons dashicons-warning" style="margin-top:2px;"></span>
                    <div style="flex:1;">
                        <strong><?php esc_html_e('Last action reported a problem', 'devdome-safe-media-cleaner'); ?></strong>
                        <div style="margin-top:4px;"><?php echo esc_html($last_error['message']); ?></div>
                        <?php if (!empty($last_error['context'])) : ?>
                            <details style="margin-top:6px;">
                                <summary style="cursor:pointer;"><?php esc_html_e('View details', 'devdome-safe-media-cleaner'); ?></summary>
                                <pre style="white-space:pre-wrap;font-size:11px;margin:6px 0 0;"><?php echo esc_html(wp_json_encode($last_error['context'])); ?></pre>
                            </details>
                        <?php endif; ?>
                        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                            <button type="button" class="dd-btn dd-btn-sm dd-btn-primary" id="mc-retry-btn" data-mode="scan"><?php esc_html_e('Retry scan', 'devdome-safe-media-cleaner'); ?></button>
                            <a class="dd-btn dd-btn-sm" href="<?php echo esc_url($err_log_url); ?>"><?php esc_html_e('Export error log', 'devdome-safe-media-cleaner'); ?></a>
                            <a class="dd-btn dd-btn-sm" href="<?php echo esc_url($err_dismiss); ?>"><?php esc_html_e('Dismiss', 'devdome-safe-media-cleaner'); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ============ TOOL 1: Media Library Cleaner ============ -->
        <div class="dd-card" style="margin-bottom:20px;">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:24px;">
                <?php
                // Same donut as Bot Protection, in green (SVG ring, not the CSS conic fallback).
                $circ = 2 * M_PI * 70;
                $dash = max(0, min(100, $score)) / 100 * $circ;
                ?>
                <div class="dd-donut">
                    <svg width="160" height="160" viewBox="0 0 160 160">
                        <circle cx="80" cy="80" r="70" fill="none" stroke="#e5e7eb" stroke-width="12"></circle>
                        <circle cx="80" cy="80" r="70" fill="none" stroke="#10b981" stroke-width="12" stroke-linecap="round"
                            stroke-dasharray="<?php echo esc_attr(round($dash, 1) . ' ' . round($circ, 1)); ?>"
                            transform="rotate(-90 80 80)"></circle>
                    </svg>
                    <span class="dd-donut-num"><?php echo esc_html($score_fmt); ?></span>
                    <span class="dd-donut-lbl"><?php esc_html_e('Cleanliness', 'devdome-safe-media-cleaner'); ?></span>
                </div>
                <div style="flex:1;min-width:260px;">
                    <h2 class="dd-h2" style="margin:0 0 4px;"><?php esc_html_e('Media Library Cleaner', 'devdome-safe-media-cleaner'); ?></h2>
                    <p style="color:#6b7280;margin:0 0 14px;max-width:520px;"><?php esc_html_e('Find unused images in your Media Library, review them visually, and move them to Recycle Bin before permanent deletion. Nothing is deleted immediately.', 'devdome-safe-media-cleaner'); ?></p>
                    <div class="mc-toolbar mc-actions" style="margin:0;">
                        <button type="button" class="dd-btn dd-btn-primary mc-scan-main" id="mc-scan-btn" data-mode="scan" data-scope="library"><span class="dashicons dashicons-search"></span> <?php esc_html_e('Scan Media Library', 'devdome-safe-media-cleaner'); ?></button>
                        <span class="mc-bk-split">
                            <button type="button" class="dd-btn mc-bk-menu-btn" id="mc-backup-btn" data-scope="library"><span class="dashicons dashicons-backup"></span> <?php esc_html_e('Create Backup', 'devdome-safe-media-cleaner'); ?> <span class="dashicons dashicons-arrow-down-alt2 mc-caret"></span></button>
                            <span class="mc-bk-split-menu">
                                <button type="button" data-what="unused"><?php printf(/* translators: %s: size. */ esc_html__('Unused only (%s)', 'devdome-safe-media-cleaner'), esc_html(size_format((int) $s['library_cleanup_bytes']))); ?></button>
                                <button type="button" data-what="all"><?php printf(/* translators: %s: size. */ esc_html__('All images (%s)', 'devdome-safe-media-cleaner'), esc_html(size_format((int) $s['total_library_bytes']))); ?></button>
                            </span>
                        </span>
                        <a class="dd-btn" href="#review"><span class="dashicons dashicons-images-alt2"></span> <?php esc_html_e('Review Unused', 'devdome-safe-media-cleaner'); ?></a>
                        <button type="button" class="dd-btn dd-btn-danger" id="mc-clean-btn" data-scope="library" <?php disabled((int) $s['unused_count'] === 0); ?> data-count="<?php echo (int) $s['unused_count']; ?>" data-size="<?php echo esc_attr(size_format((int) $s['library_cleanup_bytes'])); ?>"><span class="dashicons dashicons-trash"></span> <?php esc_html_e('Move to Recycle Bin', 'devdome-safe-media-cleaner'); ?></button>
                    </div>
                </div>
            </div>
            <!-- live scan progress (class hooks, not ids — the Review panel renders a twin) -->
            <div class="mc-progress-wrap" data-mc-scope="library" style="display:none;margin-top:18px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;color:#6b7280;margin-bottom:6px;">
                    <span class="mc-progress-msg"><?php esc_html_e('Scanning...', 'devdome-safe-media-cleaner'); ?></span>
                    <span class="mc-progress-eta"></span>
                </div>
                <div class="mc-progress"><span class="mc-progress-bar"></span></div>
                <div style="margin-top:8px;display:flex;gap:8px;">
                    <button type="button" class="dd-btn dd-btn-sm mc-pause-btn"><?php esc_html_e('Pause', 'devdome-safe-media-cleaner'); ?></button>
                    <button type="button" class="dd-btn dd-btn-sm mc-cancel-btn"><?php esc_html_e('Cancel', 'devdome-safe-media-cleaner'); ?></button>
                </div>
            </div>

            <?php if ($never_scanned) : ?>
                <div class="dd-banner dd-banner-watch" style="margin-top:16px;">
                    <?php esc_html_e('Your Media Library has not been scanned yet. Run a scan to see what is unused.', 'devdome-safe-media-cleaner'); ?>
                </div>
            <?php else : ?>
                <?php
                $lib_total_b  = (int) $s['total_library_bytes'];
                $lib_unused_b = (int) $s['library_cleanup_bytes'];
                $lib_used_b   = max(0, $lib_total_b - $lib_unused_b);
                ?>
                <div class="dd-stats" style="margin-top:18px;">
                    <div class="dd-stat"><div class="dd-stat-num"><?php echo (int) $s['total_files']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Total images', 'devdome-safe-media-cleaner'); ?></div><div class="dd-stat-sub"><?php echo esc_html($lib_total_b > 0 ? size_format($lib_total_b) : '0 B'); ?></div></div>
                    <div class="dd-stat"><div class="dd-stat-num" style="color:#10b981;"><?php echo (int) $s['used_count']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Used images', 'devdome-safe-media-cleaner'); ?></div><div class="dd-stat-sub"><?php echo esc_html($lib_used_b > 0 ? size_format($lib_used_b) : '0 B'); ?></div></div>
                    <div class="dd-stat"><div class="dd-stat-num" style="color:#f59e0b;"><?php echo (int) $s['unused_count']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Unused images', 'devdome-safe-media-cleaner'); ?></div><div class="dd-stat-sub"><?php echo esc_html($lib_unused_b > 0 ? size_format($lib_unused_b) : '0 B'); ?></div></div>
                    <div class="dd-stat"><div class="dd-stat-num" style="color:#4f46e5;"><?php echo esc_html((int) $s['library_cleanup_bytes'] > 0 ? size_format((int) $s['library_cleanup_bytes']) : '0 B'); ?></div><div class="dd-stat-lbl"><?php esc_html_e('Possible cleanup', 'devdome-safe-media-cleaner'); ?></div></div>
                    <?php if ((int) $s['uncertain_count'] > 0) : // shown ONLY when the scanner could not verify some images ?>
                        <div class="dd-stat"><div class="dd-stat-num" style="color:#f59e0b;"><?php echo (int) $s['uncertain_count']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Needs review', 'devdome-safe-media-cleaner'); ?></div></div>
                    <?php endif; ?>
                    <?php if ((int) $s['missing_count'] > 0) : // shown ONLY when files are physically gone ?>
                        <div class="dd-stat"><div class="dd-stat-num" style="color:#ef4444;"><?php echo (int) $s['missing_count']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Missing files', 'devdome-safe-media-cleaner'); ?></div></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============ TOOL 2: Disk Cleaner ============ -->
        <?php
        // duplicate_count folds in for summaries written by older versions (bucket removed 0.2.3).
        $orphan_disk = (int) $s['orphan_count'];
        $never_disk_scanned = ((int) $s['last_disk_scan_id'] === 0);
        ?>
        <?php
        // Disk cleanliness ring — orphan bytes relative to all media bytes on disk.
        // One decimal (trailing .0 trimmed) — the exact percentage, never a stepped number.
        $disk_denom = (int) $s['orphan_bytes'] + (int) $s['total_library_bytes'];
        // Never disk-scanned => no score to show: empty ring + em-dash, not a made-up 100.
        $disk_score = $never_disk_scanned ? 0.0 : ($disk_denom > 0 ? round(100 * (1 - min(1, (int) $s['orphan_bytes'] / $disk_denom)), 1) : 100);
        $disk_score_fmt = $never_disk_scanned ? '—' : rtrim(rtrim(number_format($disk_score, 1, '.', ''), '0'), '.');
        $disk_dash = max(0, min(100, $disk_score)) / 100 * $circ;
        ?>
        <div class="dd-card" style="margin-bottom:20px;">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:24px;">
                <div class="dd-donut">
                    <svg width="160" height="160" viewBox="0 0 160 160">
                        <circle cx="80" cy="80" r="70" fill="none" stroke="#e5e7eb" stroke-width="12"></circle>
                        <circle cx="80" cy="80" r="70" fill="none" stroke="#10b981" stroke-width="12" stroke-linecap="round"
                            stroke-dasharray="<?php echo esc_attr(round($disk_dash, 1) . ' ' . round($circ, 1)); ?>"
                            transform="rotate(-90 80 80)"></circle>
                    </svg>
                    <span class="dd-donut-num"><?php echo esc_html($disk_score_fmt); ?></span>
                    <span class="dd-donut-lbl"><?php esc_html_e('Cleanliness', 'devdome-safe-media-cleaner'); ?></span>
                </div>
                <div style="flex:1;min-width:260px;">
                    <h2 class="dd-h2" style="margin:0 0 4px;"><?php esc_html_e('Disk Storage Image Cleaner', 'devdome-safe-media-cleaner'); ?></h2>
                    <p style="color:#6b7280;margin:0 0 14px;max-width:520px;"><?php esc_html_e('Scan your uploads folder for orphan files: images sitting on disk with no Media Library entry (leftovers from deleted images, old imports, or tools). WordPress cannot see these files, so review them before cleaning.', 'devdome-safe-media-cleaner'); ?></p>
                    <div class="mc-toolbar mc-actions" style="margin:0;">
                        <button type="button" class="dd-btn dd-btn-primary mc-scan-main" id="mc-disk-scan-btn" data-mode="scan" data-scope="disk"><span class="dashicons dashicons-search"></span> <?php esc_html_e('Scan Disk Storage', 'devdome-safe-media-cleaner'); ?></button>
                        <span class="mc-bk-split">
                            <button type="button" class="dd-btn mc-bk-menu-btn" id="mc-disk-backup-btn" data-scope="disk"><span class="dashicons dashicons-backup"></span> <?php esc_html_e('Create Backup', 'devdome-safe-media-cleaner'); ?> <span class="dashicons dashicons-arrow-down-alt2 mc-caret"></span></button>
                            <span class="mc-bk-split-menu">
                                <button type="button" data-what="orphans"><?php printf(/* translators: %s: size. */ esc_html__('Orphans only (%s)', 'devdome-safe-media-cleaner'), esc_html(size_format((int) $s['orphan_bytes']))); ?></button>
                                <button type="button" data-what="all"><?php printf(/* translators: %s: size. */ esc_html__('All disk images (%s)', 'devdome-safe-media-cleaner'), esc_html(size_format((int) $s['disk_images_bytes']))); ?></button>
                            </span>
                        </span>
                        <a class="dd-btn" href="<?php echo esc_url(add_query_arg(array('page' => DEVDSAME_PAGE, 'mc_tab' => 'review', 'filter' => 'orphan'), admin_url('admin.php'))); ?>"><span class="dashicons dashicons-images-alt2"></span> <?php esc_html_e('Review Orphans', 'devdome-safe-media-cleaner'); ?></a>
                        <button type="button" class="dd-btn dd-btn-danger" id="mc-disk-clean-btn" data-scope="disk" <?php disabled($orphan_disk === 0); ?> data-count="<?php echo (int) $orphan_disk; ?>" data-size="<?php echo esc_attr(size_format((int) $s['orphan_bytes'])); ?>"><span class="dashicons dashicons-trash"></span> <?php esc_html_e('Move to Recycle Bin', 'devdome-safe-media-cleaner'); ?></button>
                    </div>
                </div>
            </div>
            <div class="mc-progress-wrap" data-mc-scope="disk" style="display:none;margin-top:18px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;color:#6b7280;margin-bottom:6px;">
                    <span class="mc-progress-msg"><?php esc_html_e('Scanning...', 'devdome-safe-media-cleaner'); ?></span>
                    <span class="mc-progress-eta"></span>
                </div>
                <div class="mc-progress"><span class="mc-progress-bar"></span></div>
                <div style="margin-top:8px;display:flex;gap:8px;">
                    <button type="button" class="dd-btn dd-btn-sm mc-pause-btn"><?php esc_html_e('Pause', 'devdome-safe-media-cleaner'); ?></button>
                    <button type="button" class="dd-btn dd-btn-sm mc-cancel-btn"><?php esc_html_e('Cancel', 'devdome-safe-media-cleaner'); ?></button>
                </div>
            </div>

            <?php if ($never_disk_scanned) : ?>
                <div class="dd-banner dd-banner-watch" style="margin-top:16px;">
                    <?php esc_html_e('Your uploads folder has not been scanned yet. Run a disk scan to find orphan files.', 'devdome-safe-media-cleaner'); ?>
                </div>
            <?php else : ?>
                <div class="dd-stats" style="margin-top:18px;grid-template-columns:repeat(4,1fr);">
                    <?php
                    $disk_total_b  = (int) $s['disk_images_bytes'];
                    $disk_orphan_b = (int) $s['orphan_bytes'];
                    $disk_used_b   = max(0, $disk_total_b - $disk_orphan_b);
                    ?>
                    <div class="dd-stat"><div class="dd-stat-num"><?php echo (int) $s['disk_images_count']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Images on disk', 'devdome-safe-media-cleaner'); ?></div><div class="dd-stat-sub"><?php echo esc_html($disk_total_b > 0 ? size_format($disk_total_b) : '0 B'); ?></div></div>
                    <div class="dd-stat"><div class="dd-stat-num" style="color:#10b981;"><?php echo (int) max(0, (int) $s['disk_images_count'] - $orphan_disk); ?></div><div class="dd-stat-lbl"><?php esc_html_e('Used images', 'devdome-safe-media-cleaner'); ?></div><div class="dd-stat-sub"><?php echo esc_html($disk_used_b > 0 ? size_format($disk_used_b) : '0 B'); ?></div></div>
                    <div class="dd-stat"><div class="dd-stat-num" style="color:#f97316;"><?php echo (int) $orphan_disk; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Orphan images', 'devdome-safe-media-cleaner'); ?></div><div class="dd-stat-sub"><?php echo esc_html($disk_orphan_b > 0 ? size_format($disk_orphan_b) : '0 B'); ?></div></div>
                    <div class="dd-stat"><div class="dd-stat-num" style="color:#4f46e5;"><?php echo esc_html((int) $s['orphan_bytes'] > 0 ? size_format((int) $s['orphan_bytes']) : '0 B'); ?></div><div class="dd-stat-lbl"><?php esc_html_e('Possible cleanup', 'devdome-safe-media-cleaner'); ?></div></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Safety + backup nudge -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;">
            <div class="dd-card">
                <div class="dd-sec-head"><span class="dashicons dashicons-shield dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('How safe is this?', 'devdome-safe-media-cleaner'); ?></h2></div>
                <p style="color:#4b5563;margin:0;line-height:1.7;max-width:560px;">
                    <?php esc_html_e('Nothing is removed straight away: everything you clean goes to Review and Recycle Bin first, and only what you confirm there is deleted. You can also create a backup before cleaning, so any change can be undone.', 'devdome-safe-media-cleaner'); ?>
                </p>
            </div>

            <div class="dd-card">
                <div class="dd-sec-head"><span class="dashicons dashicons-chart-area dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Storage trend', 'devdome-safe-media-cleaner'); ?></h2></div>
                <?php
                $trend12 = array_slice($trend, -12);
                if (count($trend12) >= 2) :
                    $max = 1;
                    foreach ($trend12 as $pt) {
                        $max = max($max, (int) $pt['unused']);
                    }
                    ?>
                    <div class="mc-spark">
                        <?php foreach ($trend12 as $pt) : $h = (int) round(100 * ((int) $pt['unused']) / $max); ?>
                            <i style="height:<?php echo (int) max(2, $h); ?>%;" title="<?php echo esc_attr(size_format((int) $pt['unused'])); ?>"></i>
                        <?php endforeach; ?>
                    </div>
                    <p class="dd-hint" style="margin-top:8px;"><?php esc_html_e('Cleanable media over the last 12 scans.', 'devdome-safe-media-cleaner'); ?></p>
                    <?php if ($reduction_pct > 0) : ?>
                        <p style="margin:6px 0 0;color:#059669;font-weight:600;">
                            <?php
                            printf(
                                /* translators: %d is a percentage. */
                                esc_html__('Library size reduced by %d%% since the previous scan.', 'devdome-safe-media-cleaner'),
                                (int) $reduction_pct
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                <?php else : ?>
                    <p class="dd-hint" style="margin:0;"><?php esc_html_e('Run a couple of scans to see how your cleanable media evolves.', 'devdome-safe-media-cleaner'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

/* ----------------------------- review tab ------------------------------- */

function devdsame_render_review_tab($s)
{
    $preset = isset($_GET['filter']) ? sanitize_key(wp_unslash($_GET['filter'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter, sanitized.
    $large_threshold = devdsame_get_int('large_image_threshold_bytes', 1048576);
    // Two tools, two scans: library statuses read the library scan, 'orphan' reads the disk scan.
    $scan_id = (int) $s['last_scan_id'];
    $disk_scan_id = (int) $s['last_disk_scan_id'];
    $never_scanned = ($scan_id === 0 && $disk_scan_id === 0);
    ?>
    <div class="max-w-5xl px-6 py-6" id="mc-review" data-scan="<?php echo (int) $scan_id; ?>" data-scan-disk="<?php echo (int) $disk_scan_id; ?>" data-preset="<?php echo esc_attr($preset); ?>" data-large="<?php echo (int) $large_threshold; ?>">
        <?php if ($never_scanned) : ?>
            <div class="dd-banner dd-banner-watch"><?php esc_html_e('Run a scan from the Overview tab first to populate the review screen.', 'devdome-safe-media-cleaner'); ?></div>
        <?php else : ?>
            <?php
            // Land on the first bucket that actually has content — opening "Review" onto an
            // empty Unused list when there are 900 duplicates reads as broken.
            $default_status = 'unused';
            foreach (array('unused' => 'unused_count', 'orphan' => 'orphan_count', 'uncertain' => 'uncertain_count') as $st => $key) {
                if ((int) $s[$key] > 0) {
                    $default_status = $st;
                    break;
                }
            }
            // An explicit ?filter= always wins — dashboard links land on their bucket, and a
            // post-clean reload stays on the SIDE the user was working in (never jumps
            // from Library to Disk just because Unused hit zero).
            if (in_array($preset, array('unused', 'orphan', 'uncertain', 'missing', 'used'), true)) {
                $default_status = $preset;
            }
            ?>
            <?php
            $default_side = $default_status === 'orphan' ? 'disk' : 'library';
            $lib_pills = array(
                'unused'    => array(__('Unused', 'devdome-safe-media-cleaner'), (int) $s['unused_count']),
                'uncertain' => array(__('Needs review', 'devdome-safe-media-cleaner'), (int) $s['uncertain_count']),
                'missing'   => array(__('Missing', 'devdome-safe-media-cleaner'), (int) $s['missing_count']),
                'used'      => array(__('Used', 'devdome-safe-media-cleaner'), (int) $s['used_count']),
            );
            ?>
            <span id="mc-filter-status" data-value="<?php echo esc_attr($default_status); ?>" style="display:none;"></span>
            <div class="mc-toolbar" style="margin-bottom:12px;">
                <div class="mc-swch" role="tablist">
                    <button type="button" class="mc-swch-btn<?php echo $default_side === 'library' ? ' is-on' : ''; ?>" data-side="library"><span class="dashicons dashicons-images-alt2"></span> <?php esc_html_e('Media Library', 'devdome-safe-media-cleaner'); ?></button>
                    <button type="button" class="mc-swch-btn<?php echo $default_side === 'disk' ? ' is-on' : ''; ?>" data-side="disk"><span class="dashicons dashicons-database"></span> <?php esc_html_e('Disk Storage', 'devdome-safe-media-cleaner'); ?></button>
                </div>
                <span style="flex:1;"></span>
                <div class="mc-search" data-side-group="library" <?php echo $default_side === 'disk' ? 'style="display:none;"' : ''; ?>>
                    <span class="dashicons dashicons-search"></span>
                    <input type="search" class="mc-search-in" placeholder="<?php esc_attr_e('Search images...', 'devdome-safe-media-cleaner'); ?>" autocomplete="off">
                </div>
                <div class="mc-search" data-side-group="disk" <?php echo $default_side === 'library' ? 'style="display:none;"' : ''; ?>>
                    <span class="dashicons dashicons-search"></span>
                    <input type="search" class="mc-search-in" placeholder="<?php esc_attr_e('Search files...', 'devdome-safe-media-cleaner'); ?>" autocomplete="off">
                </div>
            </div>
            <div class="mc-toolbar">
                <label class="dd-opt" style="margin-right:2px;"><input type="checkbox" class="dd-check" id="mc-select-all"> <?php esc_html_e('Select all', 'devdome-safe-media-cleaner'); ?></label>
                <div class="mc-sub-pills" data-side-group="library" <?php echo $default_side === 'disk' ? 'style="display:none;"' : ''; ?>>
                    <?php foreach ($lib_pills as $val => $p) : ?>
                        <button type="button" class="mc-pill-btn<?php echo $default_status === $val ? ' is-on' : ''; ?>" data-value="<?php echo esc_attr($val); ?>"><?php echo esc_html($p[0]); ?> <span class="mc-pill-num"><?php echo esc_html(number_format_i18n($p[1])); ?></span></button>
                    <?php endforeach; ?>
                </div>
                <div class="mc-sub-pills" data-side-group="disk" <?php echo $default_side === 'library' ? 'style="display:none;"' : ''; ?>>
                    <button type="button" class="mc-pill-btn is-on" data-value="orphan"><?php esc_html_e('Orphans', 'devdome-safe-media-cleaner'); ?> <span class="mc-pill-num"><?php echo esc_html(number_format_i18n((int) $s['orphan_count'])); ?></span></button>
                </div>

                <span style="flex:1;"></span>
                <div class="mc-viewsw">
                    <button type="button" class="mc-view-btn is-on" data-view="grid" title="<?php esc_attr_e('Catalogue view', 'devdome-safe-media-cleaner'); ?>"><span class="dashicons dashicons-grid-view"></span></button>
                    <button type="button" class="mc-view-btn" data-view="list" title="<?php esc_attr_e('List view', 'devdome-safe-media-cleaner'); ?>"><span class="dashicons dashicons-list-view"></span></button>
                </div>
                <a class="mc-export-btn" id="mc-export-btn" title="<?php esc_attr_e('Export CSV', 'devdome-safe-media-cleaner'); ?>" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=devdsame_export&what=scan&format=csv&scan_id=' . (int) $s['last_scan_id']), 'devdsame_export', '_mcx')); ?>"><span class="dashicons dashicons-download"></span></a>
                <div class="dd-dd" id="mc-sort" data-value="size_desc">
                    <div class="dd-dd-trigger"><span class="dd-dd-label"><?php esc_html_e('File size: big to small', 'devdome-safe-media-cleaner'); ?></span><span class="dashicons dashicons-arrow-down-alt2 dd-dd-chev"></span></div>
                    <div class="dd-dd-panel">
                        <div class="dd-dd-opt" data-value="size_desc"><?php esc_html_e('File size: big to small', 'devdome-safe-media-cleaner'); ?></div>
                        <div class="dd-dd-opt" data-value="size_asc"><?php esc_html_e('File size: small to big', 'devdome-safe-media-cleaner'); ?></div>
                        <div class="dd-dd-opt" data-value="dim_desc"><?php esc_html_e('Dimensions: big to small', 'devdome-safe-media-cleaner'); ?></div>
                        <div class="dd-dd-opt" data-value="dim_asc"><?php esc_html_e('Dimensions: small to big', 'devdome-safe-media-cleaner'); ?></div>
                        <div class="dd-dd-opt" data-value="date_desc"><?php esc_html_e('File age: new to old', 'devdome-safe-media-cleaner'); ?></div>
                        <div class="dd-dd-opt" data-value="date_asc"><?php esc_html_e('File age: old to new', 'devdome-safe-media-cleaner'); ?></div>
                    </div>
                </div>
            </div>

            <div class="mc-progress-wrap" style="display:none;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;color:#6b7280;margin-bottom:6px;"><span class="mc-progress-msg"></span><span class="mc-progress-eta"></span></div>
                <div class="mc-progress"><span class="mc-progress-bar"></span></div>
            </div>

            <div id="mc-grid" class="mc-grid"></div>
            <div id="mc-empty" class="dd-empty" style="display:none;"><?php esc_html_e('No media matched this filter.', 'devdome-safe-media-cleaner'); ?></div>
            <div id="mc-pagebar" style="display:none;align-items:center;gap:10px;margin-top:18px;">
                <span class="dd-hint" id="mc-range"></span>
                <span style="flex:1;"></span>
                <span class="dd-hint"><?php esc_html_e('Per page', 'devdome-safe-media-cleaner'); ?></span>
                <div class="dd-dd" id="mc-per-page" data-value="20">
                    <div class="dd-dd-trigger"><span class="dd-dd-label">20</span><span class="dashicons dashicons-arrow-down-alt2 dd-dd-chev"></span></div>
                    <div class="dd-dd-panel">
                        <div class="dd-dd-opt" data-value="20">20</div>
                        <div class="dd-dd-opt" data-value="50">50</div>
                        <div class="dd-dd-opt" data-value="100">100</div>
                        <div class="dd-dd-opt" data-value="200">200</div>
                    </div>
                </div>
                <div id="mc-pager" style="display:flex;align-items:center;gap:6px;"></div>
            </div>

            <footer class="dd-footer">
                <div class="dd-footer-inner">
                    <div class="dd-footer-actions">
                        <button type="button" class="dd-btn-danger" id="mc-trash-btn"><span class="dashicons dashicons-trash"></span> <?php esc_html_e('Move to Recycle Bin', 'devdome-safe-media-cleaner'); ?></button>
                        <span class="dd-hint" id="mc-sel-count"></span>
                    </div>
                </div>
            </footer>
        <?php endif; ?>
    </div>
    <?php
}

/* ----------------------------- safe trash tab --------------------------- */

function devdsame_render_trash_tab($s)
{
    global $wpdb;
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches list for admin display; literal LIMIT.
    $batches = $wpdb->get_results("SELECT * FROM {$tb} ORDER BY id DESC LIMIT 100");
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // Source per batch: any attachment-backed item = Media Library wipe, else Disk wipe.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal aggregate for admin display.
    $batch_src = $wpdb->get_results("SELECT batch_id, MAX(attachment_id > 0) AS is_lib FROM {$ti} GROUP BY batch_id", OBJECT_K);
    $restored = isset($_GET['mc_restored']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from a PRG redirect.
    $deleted = isset($_GET['mc_deleted']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from a PRG redirect.
    ?>
    <div class="max-w-5xl px-6 py-6">
        <?php if ($restored) : ?>
            <div class="dd-banner dd-banner-ok dd-flash" style="margin-bottom:16px;"><?php esc_html_e('Files restored to their original location.', 'devdome-safe-media-cleaner'); ?></div>
        <?php endif; ?>
        <?php if ($deleted) : ?>
            <div class="dd-banner dd-banner-error dd-flash" style="margin-bottom:16px;"><?php esc_html_e('Batch permanently deleted.', 'devdome-safe-media-cleaner'); ?></div>
        <?php endif; ?>

        <div class="dd-card">
            <div class="dd-sec-head"><span class="dashicons dashicons-trash dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Recycle Bin batches', 'devdome-safe-media-cleaner'); ?></h2>
                <button type="button" class="dd-btn dd-btn-sm mc-clear-history-btn" style="margin-left:auto;"><?php esc_html_e('Clear batch list', 'devdome-safe-media-cleaner'); ?></button>
            </div>
            <p style="color:#6b7280;margin:0 0 12px;"><?php esc_html_e('Files moved here are not deleted. Review your site for a few days, then restore or permanently delete each batch.', 'devdome-safe-media-cleaner'); ?></p>

            <?php if (!$batches) : ?>
                <div class="dd-empty"><?php esc_html_e('No Recycle Bin batches yet.', 'devdome-safe-media-cleaner'); ?></div>
            <?php else : ?>
                <table class="mc-bk-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Batch', 'devdome-safe-media-cleaner'); ?></th>
                            <th><?php esc_html_e('Date', 'devdome-safe-media-cleaner'); ?></th>
                            <th><?php esc_html_e('Source', 'devdome-safe-media-cleaner'); ?></th>
                            <th><?php esc_html_e('Images', 'devdome-safe-media-cleaner'); ?></th>
                            <th><?php esc_html_e('Size', 'devdome-safe-media-cleaner'); ?></th>
                            <th><?php esc_html_e('Status', 'devdome-safe-media-cleaner'); ?></th>
                            <th style="text-align:right;"><?php esc_html_e('Actions', 'devdome-safe-media-cleaner'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($batches as $b) : ?>
                        <?php if ((int) $b->total_files === 0 || $b->status === 'empty') { continue; } // never list empty batches ?>
                        <tr>
                            <td class="mc-bk-name">#<?php echo (int) $b->id; ?></td>
                            <td><?php echo esc_html(mysql2date(get_option('date_format') . ' H:i', $b->created_at)); ?></td>
                            <?php $is_lib = isset($batch_src[$b->id]) ? (int) $batch_src[$b->id]->is_lib : 1; ?>
                            <td><span class="mc-src <?php echo $is_lib ? 'mc-src-lib' : 'mc-src-disk'; ?>"><?php echo $is_lib ? esc_html__('Media Library', 'devdome-safe-media-cleaner') : esc_html__('Disk', 'devdome-safe-media-cleaner'); ?></span></td>
                            <td><?php echo (int) $b->total_files; ?></td>
                            <td><?php echo esc_html(size_format((int) $b->total_bytes)); ?></td>
                            <td><span class="mc-st mc-st-<?php echo esc_attr((string) $b->status); ?>"><?php echo esc_html(ucfirst((string) $b->status)); ?></span></td>
                            <td class="mc-bk-actions">
                                <?php if ($b->status === 'trashed' && (int) $b->restore_available) : ?>
                                    <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Restore every file in this batch?', 'devdome-safe-media-cleaner')); ?>');">
                                        <?php wp_nonce_field('devdsame_restore', '_mcrn'); ?>
                                        <button type="submit" name="devdsame_restore_batch" value="<?php echo (int) $b->id; ?>" class="dd-btn dd-btn-sm"><?php esc_html_e('Restore', 'devdome-safe-media-cleaner'); ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Permanently delete this batch? This cannot be undone.', 'devdome-safe-media-cleaner')); ?>');">
                                        <?php wp_nonce_field('devdsame_delete', '_mcdn'); ?>
                                        <button type="submit" name="devdsame_delete_batch" value="<?php echo (int) $b->id; ?>" class="dd-btn dd-btn-sm dd-btn-danger"><?php esc_html_e('Delete Permanently', 'devdome-safe-media-cleaner'); ?></button>
                                    </form>
                                <?php else : ?>
                                    <span class="dd-hint">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        </div>
    </div>
    <?php
}

/* ----------------------------- backup tab ------------------------------- */

/** One backups table (Library or Disk) with restore / download / delete / upload actions. */
function devdsame_render_backup_section($scope, $title, $backups)
{
    $rows = array();
    foreach ($backups as $b) {
        if ((string) $b['scope'] === $scope) {
            $rows[] = $b;
        }
    }
    ?>
    <div class="dd-card mc-bk" style="margin-bottom:16px;">
        <div class="dd-sec-head">
            <span class="dashicons dashicons-backup dd-ico"></span><h2 class="dd-h2"><?php echo esc_html($title); ?></h2>
            <form class="mc-bk-upload-form" data-scope="<?php echo esc_attr($scope); ?>" style="margin-left:auto;display:flex;gap:8px;align-items:center;" onsubmit="return false;">
                <input type="file" accept=".zip" style="display:none;">
                <button type="button" class="dd-btn dd-btn-sm dd-btn-danger mc-bk-upload-cancel" style="display:none;"><?php esc_html_e('Cancel', 'devdome-safe-media-cleaner'); ?></button>
                <button type="button" class="dd-btn dd-btn-sm mc-bk-upload-go"><span class="dashicons dashicons-upload"></span> <?php esc_html_e('Upload Backup', 'devdome-safe-media-cleaner'); ?></button>
            </form>
        </div>
        <?php // Progress lives INSIDE the section a job belongs to (scope-tagged), not at page top. ?>
        <div class="mc-progress-wrap" data-mc-scope="<?php echo esc_attr($scope); ?>" style="display:none;margin:0 0 14px;">
            <div style="display:flex;justify-content:space-between;font-size:12px;color:#6b7280;margin-bottom:6px;"><span class="mc-progress-msg"></span><span class="mc-progress-eta"></span></div>
            <div class="mc-progress"><span class="mc-progress-bar"></span></div>
        </div>
        <?php if (!$rows) : ?>
            <div class="dd-empty" style="padding:12px 0;text-align:left;"><?php esc_html_e('No backups yet. Use Create Backup on the Overview tab.', 'devdome-safe-media-cleaner'); ?></div>
        <?php else : ?>
            <table class="mc-bk-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Backup', 'devdome-safe-media-cleaner'); ?></th>
                        <th><?php esc_html_e('Files', 'devdome-safe-media-cleaner'); ?></th>
                        <th><?php esc_html_e('Size', 'devdome-safe-media-cleaner'); ?></th>
                        <th><?php esc_html_e('Created', 'devdome-safe-media-cleaner'); ?></th>
                        <th style="text-align:right;"><?php esc_html_e('Actions', 'devdome-safe-media-cleaner'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $b) :
                    $dl = wp_nonce_url(admin_url('admin-post.php?action=devdsame_backup_download&backup_id=' . rawurlencode((string) $b['id'])), 'devdsame_backup', '_mcbk');
                ?>
                    <tr>
                        <td class="mc-bk-name" title="<?php echo esc_attr((string) $b['file']); ?>"><?php echo esc_html((string) $b['file']); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $b['files'])); ?></td>
                        <td><?php echo esc_html(size_format((int) $b['bytes'])); ?></td>
                        <td><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', (int) $b['created_at'])); ?></td>
                        <td class="mc-bk-actions">
                            <button type="button" class="dd-btn dd-btn-sm mc-bk-restore-btn" data-id="<?php echo esc_attr((string) $b['id']); ?>" data-scope="<?php echo esc_attr($scope); ?>"><?php esc_html_e('Restore', 'devdome-safe-media-cleaner'); ?></button>
                            <a class="dd-btn dd-btn-sm" href="<?php echo esc_url($dl); ?>"><?php esc_html_e('Download', 'devdome-safe-media-cleaner'); ?></a>
                            <form method="post" class="mc-bk-del-form">
                                <?php wp_nonce_field('devdsame_backup', '_mcbk'); ?>
                                <input type="hidden" name="devdsame_backup_action" value="delete">
                                <button type="submit" name="backup_id" value="<?php echo esc_attr((string) $b['id']); ?>" class="dd-btn dd-btn-sm dd-btn-danger"><?php esc_html_e('Delete', 'devdome-safe-media-cleaner'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

function devdsame_render_backup_tab()
{
    $backups = function_exists('devdsame_backups') ? devdsame_backups() : array();
    $notice = isset($_GET['mc_bk']) ? sanitize_key(wp_unslash($_GET['mc_bk'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from a PRG redirect, sanitized.
    ?>
    <div class="max-w-5xl px-6 py-6">
        <?php if ($notice === 'deleted') : ?>
            <div class="dd-banner dd-banner-error dd-flash" style="margin-bottom:16px;"><?php esc_html_e('Backup deleted.', 'devdome-safe-media-cleaner'); ?></div>
        <?php elseif ($notice === 'uploaded') : ?>
            <div class="dd-banner dd-banner-ok dd-flash" style="margin-bottom:16px;"><?php esc_html_e('Backup uploaded.', 'devdome-safe-media-cleaner'); ?></div>
        <?php elseif ($notice === 'error') : ?>
            <div class="dd-banner dd-banner-error dd-flash" style="margin-bottom:16px;"><?php esc_html_e('That did not work. Check the file and try again.', 'devdome-safe-media-cleaner'); ?></div>
        <?php endif; ?>

        <div style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:10px;padding:12px 16px;margin:0 0 16px;font-size:13px;line-height:1.6;color:#3730a3;">
            <strong><?php esc_html_e('How backups work:', 'devdome-safe-media-cleaner'); ?></strong>
            <?php esc_html_e('every backup is a full ZIP snapshot of your images, saved on this server in /uploads/devdome-smc-backups. Create one from the Overview tab before you clean anything. Restore puts every file back exactly where it was.', 'devdome-safe-media-cleaner'); ?>
        </div>

        <?php
        devdsame_render_backup_section('library', __('Media Library backups', 'devdome-safe-media-cleaner'), $backups);
        devdsame_render_backup_section('disk', __('Disk backups', 'devdome-safe-media-cleaner'), $backups);
        ?>
    </div>
    <?php
}

/* ----------------------------- settings tab ----------------------------- */

function devdsame_render_settings_tab()
{
    $recent = devdsame_get_int('recent_upload_protection_days', 30);
    $sched_days = devdsame_get_int('scheduled_scan_days', 0);
    if (!$sched_days) { // back-compat with the old weekly/monthly setting
        $old = (string) devdsame_get_setting('scheduled_scan', 'off');
        $sched_days = $old === 'weekly' ? 7 : ($old === 'monthly' ? 30 : 0);
    }
    $auto = devdsame_get_int('auto_delete_after_days', 0);
    $threshold = devdsame_get_int('confidence_threshold', 75);
    $cdn = devdsame_get_array('cdn_mappings');
    $nf = devdsame_get_array('never_scan_folders');
    $growth_b = devdsame_get_int('unused_growth_alert', 0);
    $growth_mb = $growth_b > 0 ? round($growth_b / 1048576) : 500;
    $notif_on = devdsame_get_int('email_notifications', 0);
    $notif_freq = devdsame_get_int('notification_frequency_days', 7);
    $mc_account_id = devdsame_connected_account_id();
    $mc_account_email = $mc_account_id ? devdsame_account_email() : '';
    $mc_conn = isset($_GET['mc_conn']) ? sanitize_key(wp_unslash($_GET['mc_conn'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from a PRG redirect, sanitized.
    $tip = function ($text) {
        echo '<span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">' . esc_html($text) . '</span></span>';
    };
    ?>
    <div class="max-w-5xl px-6 py-6">
        <?php if ('ok' === $mc_conn) : ?>
            <div class="dd-banner dd-banner-ok dd-flash" style="margin-bottom:16px;"><?php esc_html_e('DevDome account connected.', 'devdome-safe-media-cleaner'); ?></div>
        <?php elseif ('' !== $mc_conn) : ?>
            <div class="dd-banner dd-banner-error dd-flash" style="margin-bottom:16px;"><?php
                if ('expired' === $mc_conn) {
                    esc_html_e('That connect link expired. Please try again.', 'devdome-safe-media-cleaner');
                } elseif ('badid' === $mc_conn) {
                    esc_html_e('That Account ID does not look right. Please try again.', 'devdome-safe-media-cleaner');
                } else {
                    esc_html_e('Could not verify the connection with DevDome. Please try again.', 'devdome-safe-media-cleaner');
                }
            ?></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('devdsame_settings', '_mcsn'); ?>
            <div class="dd-card">
                <div class="dd-sec-head"><span class="dashicons dashicons-email dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('DevDome Monitoring', 'devdome-safe-media-cleaner'); ?></h2></div>
                <?php if (!$mc_account_id) : ?>
                    <style>.dd-cgo-btn:hover{background:#1d4ed8 !important;border-color:#1d4ed8 !important;}</style>
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:0 0 16px;" class="dd-gate-strip">
                        <a href="<?php echo esc_url(devdsame_connect_url()); ?>" class="dd-cgo-btn" style="display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:14px;font-weight:600;border-radius:8px;padding:10px 20px;text-decoration:none;cursor:pointer;line-height:1;white-space:nowrap;transition:.12s;color:#fff;background:#2563eb;border:1px solid #2563eb;box-shadow:0 4px 10px -3px rgba(37,99,235,.5);"><?php esc_html_e('Connect your DevDome account', 'devdome-safe-media-cleaner'); ?></a>
                        <span style="font-size:13px;color:#6b7280;"><?php esc_html_e('Monitoring runs on DevDome servers. Requires a DevDome account.', 'devdome-safe-media-cleaner'); ?></span>
                    </div>
                <?php endif; ?>
                <table class="dd-table">
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Monitoring & email alerts', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td">
                            <?php if ($mc_account_id) : ?>
                                <label class="dd-opt"><input type="checkbox" class="dd-check" name="mc_email_notifications_on" value="1" <?php checked($notif_on, 1); ?>> <?php esc_html_e('Enable DevDome Monitoring', 'devdome-safe-media-cleaner'); ?></label>
                                <span class="dd-hint" style="display:block;margin-top:8px;">
                                    <?php
                                    if ($mc_account_email !== '') {
                                        printf(
                                            /* translators: %s is the connected DevDome account email address. */
                                            esc_html__('Alerts are sent to your account email: %s', 'devdome-safe-media-cleaner'),
                                            '<strong>' . esc_html($mc_account_email) . '</strong>'
                                        );
                                    } else {
                                        esc_html_e('Alerts are sent to your DevDome account email.', 'devdome-safe-media-cleaner');
                                    }
                                    ?>
                                    <?php $tip(__('After each scan, aggregate media stats (counts and sizes only, never files) are sent to DevDome. DevDome tracks growth across your sites, shows a media-health dashboard in your account, and emails alerts to your account email.', 'devdome-safe-media-cleaner')); ?>
                                </span>
                            <?php else : ?>
                                <label class="dd-opt" style="opacity:.5;pointer-events:none;"><input type="checkbox" class="dd-check" disabled> <?php esc_html_e('Enable DevDome Monitoring', 'devdome-safe-media-cleaner'); ?></label>
                                <p class="dd-hint"><?php esc_html_e('Connect a DevDome account to turn this on.', 'devdome-safe-media-cleaner'); ?> <?php $tip(__('After each scan, aggregate media stats (counts and sizes only, never files) are sent to DevDome. DevDome tracks growth across your sites, shows a media-health dashboard in your account, and emails alerts to your account email.', 'devdome-safe-media-cleaner')); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($mc_account_id) : ?>
                    <tr data-mc-showif="mc_email_notifications_on">
                        <th class="dd-th"><?php esc_html_e('Send notifications', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td">
                            <?php devdsame_render_dropdown('mc_notification_frequency_days', $notif_freq, array(
                                1  => __('Every day', 'devdome-safe-media-cleaner'),
                                3  => __('Every 3 days', 'devdome-safe-media-cleaner'),
                                7  => __('Every 7 days', 'devdome-safe-media-cleaner'),
                                14 => __('Every 14 days', 'devdome-safe-media-cleaner'),
                                30 => __('Every 30 days', 'devdome-safe-media-cleaner'),
                            )); ?>
                            <p class="dd-hint">At most one alert email in this period. <?php $tip(__('How often an alert email can be sent at most.', 'devdome-safe-media-cleaner')); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <div class="dd-card" style="margin-top:16px;">
                <div class="dd-sec-head"><span class="dashicons dashicons-clock dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Scheduling & retention', 'devdome-safe-media-cleaner'); ?></h2></div>
                <table class="dd-table">
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Scheduled scan', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td">
                            <label class="dd-opt"><input type="checkbox" class="dd-check" name="mc_scheduled_scan_on" value="1" <?php checked($sched_days > 0); ?>> <?php esc_html_e('Scan automatically', 'devdome-safe-media-cleaner'); ?></label>
                            <p class="dd-hint">Keeps the dashboard numbers fresh without a manual scan. <?php $tip(__('Rescans your library automatically in the background so the dashboard numbers stay fresh.', 'devdome-safe-media-cleaner')); ?></p>
                        </td>
                    </tr>
                    <tr data-mc-showif="mc_scheduled_scan_on">
                        <th class="dd-th"><?php esc_html_e('Scan every', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td"><input type="number" min="1" max="999" name="mc_scheduled_scan_days" value="<?php echo (int) max(1, $sched_days ?: 7); ?>" class="dd-input" style="width:70px;margin-right:6px;"> <?php esc_html_e('days', 'devdome-safe-media-cleaner'); ?>
                            <p class="dd-hint">7 days is a good default for most sites. <?php $tip(__('How many days between automatic scans.', 'devdome-safe-media-cleaner')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Auto-delete from Recycle Bin', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td">
                            <?php devdsame_render_dropdown('mc_auto_delete_days', (int) $auto, array(
                                0  => __('Never (recommended)', 'devdome-safe-media-cleaner'),
                                7  => __('After 7 days', 'devdome-safe-media-cleaner'),
                                14 => __('After 14 days', 'devdome-safe-media-cleaner'),
                                30 => __('After 30 days', 'devdome-safe-media-cleaner'),
                            )); ?>
                            <p class="dd-hint">Never is the safe choice, files stay restorable. <?php $tip(__('Off by default. When set, trashed files are permanently removed after this many days.', 'devdome-safe-media-cleaner')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Unused-growth alert', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td">
                            <label class="dd-opt"><input type="checkbox" class="dd-check" name="mc_growth_alert_on" value="1" <?php checked($growth_b > 0); ?>> <?php esc_html_e('Alert me when unused media grows', 'devdome-safe-media-cleaner'); ?></label>
                            <p class="dd-hint">Get told when unused media passes a size you set. <?php $tip(__('When unused media grows past a size you set, an alert appears in your DevDome Suite dashboard. With DevDome Monitoring on, DevDome also emails you.', 'devdome-safe-media-cleaner')); ?></p>
                        </td>
                    </tr>
                    <tr data-mc-showif="mc_growth_alert_on">
                        <th class="dd-th"><?php esc_html_e('Alert when above', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td"><input type="number" step="1" min="1" name="mc_unused_growth_alert_mb" value="<?php echo esc_attr($growth_mb); ?>" class="dd-input" style="width:90px;margin-right:6px;"> MB
                            <p class="dd-hint">The size of unused media that triggers the alert. <?php $tip(__('The alert fires once unused media passes this size.', 'devdome-safe-media-cleaner')); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="dd-card" style="margin-top:16px;">
                <div class="dd-sec-head"><span class="dashicons dashicons-shield dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Protection rules', 'devdome-safe-media-cleaner'); ?></h2></div>
                <table class="dd-table">
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Protect new uploads', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td">
                            <label class="dd-opt"><input type="checkbox" class="dd-check" name="mc_protect_recent" value="1" <?php checked(devdsame_get_int('protect_recent', 1), 1); ?>> <?php esc_html_e('Skip recent uploads', 'devdome-safe-media-cleaner'); ?></label>
                            <p class="dd-hint">Fresh uploads are skipped so unpublished pages keep their images. <?php $tip(__('New images are often uploaded before the page that uses them is published. This keeps them out of the unused list for a while.', 'devdome-safe-media-cleaner')); ?></p>
                        </td>
                    </tr>
                    <tr data-mc-showif="mc_protect_recent">
                        <th class="dd-th"><?php esc_html_e('Skip uploads from the last', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td"><input type="number" min="1" max="999" name="mc_recent_days" value="<?php echo (int) $recent; ?>" class="dd-input" style="width:70px;margin-right:6px;"> <?php esc_html_e('days', 'devdome-safe-media-cleaner'); ?>
                            <p class="dd-hint">30 days covers most publishing workflows. <?php $tip(__('Uploads newer than this are never marked unused.', 'devdome-safe-media-cleaner')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Protected sources', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td">
                            <div style="display:flex;flex-direction:column;gap:8px;">
                                <label class="dd-opt"><input type="checkbox" class="dd-check" name="mc_protect_woocommerce" value="1" <?php checked(devdsame_get_int('protect_woocommerce', 1), 1); ?>> <?php esc_html_e('WooCommerce images', 'devdome-safe-media-cleaner'); ?></label>
                                <label class="dd-opt"><input type="checkbox" class="dd-check" name="mc_protect_theme_assets" value="1" <?php checked(devdsame_get_int('protect_theme_assets', 1), 1); ?>> <?php esc_html_e('Theme / customizer images', 'devdome-safe-media-cleaner'); ?></label>
                            </div>
                            <p class="dd-hint">Images owned by these features are never marked unused. <?php $tip(__('Images owned by these features are never marked unused, even when no page mentions them.', 'devdome-safe-media-cleaner')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Confidence threshold', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td">
                            <input type="number" min="0" max="100" name="mc_confidence_threshold" value="<?php echo (int) $threshold; ?>" class="dd-input" style="width:90px;">
                            <p class="dd-hint">Recommended 75. Lower scores go to Needs review instead. <?php $tip(__('How sure the scanner must be before calling an image unused (0-100). Below this score it goes to Needs review instead, and is never auto-selected. Recommended: 75.', 'devdome-safe-media-cleaner')); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="dd-card" style="margin-top:16px;">
                <div class="dd-sec-head"><span class="dashicons dashicons-image-filter dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Detection', 'devdome-safe-media-cleaner'); ?></h2></div>
                <table class="dd-table">
                    <tr>
                        <th class="dd-th"><?php esc_html_e('CDN domains', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td">
                            <div class="mc-chips">
                                <div style="display:flex;gap:8px;align-items:flex-start;">
                                    <textarea rows="3" class="dd-textarea mc-list-in" placeholder="https://cdn.example.com/wp-content/uploads &mdash; <?php esc_attr_e('one per line', 'devdome-safe-media-cleaner'); ?>" autocomplete="off" style="flex:1;width:100%;"></textarea>
                                    <button type="button" class="dd-list-addbtn mc-chip-add"><?php esc_html_e('Add', 'devdome-safe-media-cleaner'); ?></button>
                                </div>
                                <div class="mc-chip-list"></div>
                                <textarea name="mc_cdn_mappings" class="mc-chip-store" style="display:none;"><?php echo esc_textarea(implode("\n", array_keys($cdn))); ?></textarea>
                            </div>
                            <p class="dd-hint">Add your CDN base URL so CDN-served images count as used. <?php $tip(__('Serving images from a CDN? Add its base URL so images referenced by the CDN address still count as used. Without this they could be falsely listed as unused.', 'devdome-safe-media-cleaner')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Never scan folders', 'devdome-safe-media-cleaner'); ?></th>
                        <td class="dd-td">
                            <div class="mc-chips">
                                <div style="display:flex;gap:8px;align-items:flex-start;">
                                    <textarea rows="3" class="dd-textarea mc-list-in" placeholder="<?php esc_attr_e("woocommerce_uploads\ngallery-import\none folder per line, relative to /uploads", 'devdome-safe-media-cleaner'); ?>" autocomplete="off" style="flex:1;width:100%;"></textarea>
                                    <button type="button" class="dd-list-addbtn mc-chip-add"><?php esc_html_e('Add', 'devdome-safe-media-cleaner'); ?></button>
                                </div>
                                <div class="mc-chip-list"></div>
                                <textarea name="mc_never_scan_folders" class="mc-chip-store" style="display:none;"><?php echo esc_textarea(implode("\n", $nf)); ?></textarea>
                            </div>
                            <p class="dd-hint">Folders inside /uploads the scanner always leaves alone. <?php $tip(__('Folders inside /uploads that are always left alone, e.g. woocommerce_uploads or a folder another tool manages.', 'devdome-safe-media-cleaner')); ?></p>
                        </td>
                    </tr>
                </table>
            </div>


            <footer class="dd-footer">
                <div class="dd-footer-inner">
                    <div class="dd-footer-actions">
                        <button type="submit" name="devdsame_settings_save" value="1" class="dd-btn-primary" style="gap:8px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg><?php esc_html_e('Save Settings', 'devdome-safe-media-cleaner'); ?></button>
                        <span id="mc-saved-note" style="display:none;color:#059669;font-weight:600;font-size:13px;"><?php esc_html_e('Saved.', 'devdome-safe-media-cleaner'); ?></span>
                    </div>
                </div>
            </footer>
        </form>
    </div>
    <?php
}
