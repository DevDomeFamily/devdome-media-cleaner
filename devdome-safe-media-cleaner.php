<?php
/*
Plugin Name: DevDome Safe Media Cleaner
Plugin URI: https://devdome.com/
Description: Safely find and remove unused WordPress images with visual review, confidence scoring, Recycle Bin and one-click restore. Part of the DevDome suite.
Version: 1.0.10
Author: DevDome
Author URI: https://devdome.com
Text Domain: devdome-safe-media-cleaner
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) {
    exit;
}

// The WordPress.org zip ships this marker file (defines DEVDCOREV1_WPORG_BUILD) so the
// same codebase can switch off self-hosted updates and other non-wp.org behavior.
if (file_exists(__DIR__ . '/wporg-build.php')) {
    require __DIR__ . '/wporg-build.php';
}

define('DEVDSAME_VERSION', '1.0.10');
define('DEVDSAME_DIR', plugin_dir_path(__FILE__));
define('DEVDSAME_URL', plugin_dir_url(__FILE__));
define('DEVDSAME_FILE', __FILE__);
define('DEVDSAME_PAGE', 'devdome-safe-media-cleaner');

/** Name of the protected directory (inside /uploads) that holds trashed-but-not-deleted media. */
if (!defined('DEVDSAME_TRASH_DIRNAME')) {
    define('DEVDSAME_TRASH_DIRNAME', 'devdome-safe-trash');
}

/**
 * Absolute path of the Recycle Bin root, optionally for a specific batch.
 * Always resolves INSIDE the uploads basedir so it can never escape via traversal.
 */
function devdsame_safe_trash_dir($batch_id = 0)
{
    $up = wp_get_upload_dir();
    $base = trailingslashit($up['basedir']) . DEVDSAME_TRASH_DIRNAME;
    if ($batch_id) {
        $base .= '/' . (int) $batch_id;
    }
    return $base;
}

// Shared DevDome core (vendored, version-guarded; only the highest copy across all installed
// DevDome plugins actually loads). Provides the suite hub, account seam, report
// contract and shared cron. Loaded FIRST, above every other require.
require_once DEVDSAME_DIR . 'lib/devdome-core/loader.php';

// Legacy-identifier migration ships only in fleet/self-hosted builds (.wporg-strip):
// wp.org installs are fresh and have no old-prefix data to move.
if (file_exists(DEVDSAME_DIR . 'includes/migrate.php')) {
    require_once DEVDSAME_DIR . 'includes/migrate.php';
}
require_once DEVDSAME_DIR . 'includes/settings.php';
require_once DEVDSAME_DIR . 'includes/install.php';
require_once DEVDSAME_DIR . 'includes/helpers.php';
require_once DEVDSAME_DIR . 'includes/references.php';
require_once DEVDSAME_DIR . 'includes/builders.php';
require_once DEVDSAME_DIR . 'includes/integrations.php';
require_once DEVDSAME_DIR . 'includes/scanner.php';
require_once DEVDSAME_DIR . 'includes/filesystem.php';
require_once DEVDSAME_DIR . 'includes/duplicates.php';
require_once DEVDSAME_DIR . 'includes/queue.php';
require_once DEVDSAME_DIR . 'includes/safe-trash.php';
require_once DEVDSAME_DIR . 'includes/restore.php';
require_once DEVDSAME_DIR . 'includes/backups.php';
require_once DEVDSAME_DIR . 'includes/report.php';
require_once DEVDSAME_DIR . 'includes/unlock.php';
require_once DEVDSAME_DIR . 'includes/rest.php';
require_once DEVDSAME_DIR . 'includes/cli.php';

if (is_admin()) {
    require_once DEVDSAME_DIR . 'includes/admin.php';
}

/**
 * Capability gate — agencies can scope who runs cleanups without editing code.
 * Used by every mutating handler + REST permission callback.
 */
function devdsame_capability()
{
    return apply_filters('devdsame_capability', 'manage_options');
}

// DevDome Tools hub: register this plugin in the suite dashboard (decoupled — a new plugin is
// ~10 lines, zero hub edits). Tiles + health read ONLY the cached summary option (no query on render).
add_filter('devdcorev1_suite_register', function ($r) {
    $r['devdome-safe-media-cleaner'] = array(
        'slug'     => 'devdome-safe-media-cleaner',
        'name'     => 'Safe Media Cleaner',
        'desc'     => 'Find &amp; safely remove unused media with rollback protection.',
        'icon'     => 'dashicons-images-alt2',
        'version'  => DEVDSAME_VERSION,
        'page'     => 'devdome-safe-media-cleaner',
        'position' => 80,
        'schema'   => 1,
        'tiles'    => function () {
            if (!function_exists('devdsame_hub_summary')) {
                return array();
            }
            $s    = devdsame_hub_summary();
            $href = 'admin.php?page=devdome-safe-media-cleaner';
            $unused = (int) $s['unused_count'];
            $bytes  = (int) $s['possible_cleanup_bytes'];
            $score  = (int) $s['score'];
            // The shared hub formatter has no 'bytes' case, so pre-format the size to a string
            // and pass it as plain text (fmt 'text' => printed verbatim).
            $scanned = (int) $s['last_scan_id'] > 0;
            return array(
                array('label' => 'Unused media',     'value' => $unused, 'fmt' => 'int', 'state' => $unused ? 'warn' : 'good', 'href' => $href),
                array('label' => 'Possible cleanup', 'value' => $bytes ? size_format($bytes) : 0, 'fmt' => 'text', 'state' => $bytes ? 'warn' : 'idle', 'href' => $href),
                // Never scanned => no score to claim: grey em-dash, not a made-up 100%.
                array('label' => 'Cleanliness',      'value' => $scanned ? $score : null,  'fmt' => 'pct', 'state' => $scanned ? ($score >= 75 ? 'good' : ($score >= 50 ? 'warn' : 'urgent')) : 'idle', 'href' => $href),
            );
        },
        'health'   => function () {
            if (!function_exists('devdsame_hub_summary')) {
                return null;
            }
            $s     = devdsame_hub_summary();
            $href  = admin_url('admin.php?page=devdome-safe-media-cleaner');
            $score = (int) $s['score'];
            $issues = array();
            if ((int) $s['last_scan_id'] === 0) {
                $issues[] = array(
                    'problem'        => 'Your Media Library has not been scanned yet.',
                    'why_it_matters' => 'Unused images may be bloating your backups, migrations and storage.',
                    'fix'            => 'Run a Media Library scan.',
                    'actions'        => array(array('label' => 'Scan now', 'href' => $href)),
                );
            } else {
                if ((int) $s['unused_count'] > 0) {
                    $issues[] = array(
                        'problem'        => 'Unused media is bloating your library.',
                        'why_it_matters' => size_format((int) $s['possible_cleanup_bytes']) . ' of media appears unused and safe to review.',
                        'fix'            => 'Review the flagged images and move them to Recycle Bin.',
                        'actions'        => array(array('label' => 'Review media', 'href' => $href)),
                    );
                }
                if ((int) $s['missing_count'] > 0) {
                    $issues[] = array(
                        'problem'        => 'Missing files detected.',
                        'why_it_matters' => (int) $s['missing_count'] . ' attachment records have no physical file.',
                        'fix'            => 'Review the missing files and clean up the broken records.',
                        'actions'        => array(array('label' => 'Review media', 'href' => $href)),
                    );
                }
            }
            // Never scanned => contribute NO score (the hub skips null scorers but still
            // shows the "run a scan" issue) — a 100 next to "not scanned yet" reads as a lie.
            $scanned = (int) $s['last_scan_id'] > 0;
            return array(
                'score'       => $scanned ? $score : null,
                'status'      => $scanned ? ($score >= 75 ? 'good' : ($score >= 50 ? 'warn' : 'urgent')) : 'idle',
                'scope_label' => 'Media',
                'summary'     => '',
                'issues'      => $issues,
            );
        },
    );
    return $r;
});

// Recent-activity digest section (read-only, from the cached summary).
add_filter('devdcorev1_suite_report_sections', function ($s) {
    if (!function_exists('devdsame_hub_summary')) {
        return $s;
    }
    $sum = devdsame_hub_summary();
    $s[] = array('title' => 'Safe Media Cleaner', 'lines' => array(
        (int) $sum['total_files'] . ' media files',
        (int) $sum['unused_count'] . ' unused (' . size_format((int) $sum['possible_cleanup_bytes']) . ')',
        (int) $sum['orphan_count'] . ' orphan, ' . (int) $sum['missing_count'] . ' missing',
    ));
    return $s;
});

/**
 * Decoupled "Media Health" signal for Site Monitor (mirrors devdcorev1_money_signals from the
 * affiliate suite). Any consumer can apply_filters('devdcorev1_media_signals', array()) to get a
 * one-card snapshot of this site's media health from the cached summary — zero hub edits.
 */
add_filter('devdcorev1_media_signals', function ($signals) {
    if (!function_exists('devdsame_hub_summary')) {
        return $signals;
    }
    $s = devdsame_hub_summary();
    $signals['devdome-safe-media-cleaner'] = array(
        'slug'                   => 'devdome-safe-media-cleaner',
        'label'                  => 'Media Health',
        'total_files'            => (int) $s['total_files'],
        'unused_count'           => (int) $s['unused_count'],
        'possible_cleanup_bytes' => (int) $s['possible_cleanup_bytes'],
        'orphan_count'           => (int) $s['orphan_count'],
        'missing_count'          => (int) $s['missing_count'],
        'duplicate_count'        => (int) $s['duplicate_count'],
        'last_scan_at'           => (int) $s['last_scan_at'],
        'score'                  => (int) $s['score'],
        'href'                   => admin_url('admin.php?page=devdome-safe-media-cleaner'),
    );
    return $signals;
});

register_activation_hook(__FILE__, 'devdsame_activate');
register_deactivation_hook(__FILE__, 'devdsame_deactivate');

/* DevDome suite self-hosted updates (admin/cron only - never on the front end). The wp.org
 * build drops the updater file from the zip and defines DEVDCOREV1_WPORG_BUILD (wp.org itself
 * delivers updates there), so the whole block is skipped. */
if ( ! defined( 'DEVDCOREV1_WPORG_BUILD' ) && file_exists( __DIR__ . '/includes/class-devdome-suite-updater.php' ) ) {
	require_once __DIR__ . '/includes/class-devdome-suite-updater.php';
	if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		new DevDome_Suite_Updater( __FILE__, 'devdome-safe-media-cleaner', 'https://api.devdome.com/plugin-updates/devdome-safe-media-cleaner.json' );
	}
}
