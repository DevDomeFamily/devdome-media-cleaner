<?php
/**
 * WP-CLI commands: `wp devdome media <subcommand>`.
 *
 *   wp devdome media scan [--dry-run]
 *   wp devdome media report [--format=table|json]
 *   wp devdome media list [--status=unused] [--limit=50]
 *   wp devdome media status
 *   wp devdome media trash [--selected] [--dry-run]   (moves the latest scan's auto-selected unused items into a NEW batch)
 *   wp devdome media restore --batch=<id>
 *   wp devdome media delete --batch=<id> [--yes]
 *
 * Reuses the scanner / safe-trash / restore engine directly so the full flow is scriptable
 * (CI / agency automation), with a dry-run flag on the destructive ops.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

if (defined('WP_CLI') && WP_CLI) {

    class DEVDSAME_CLI
    {
        /**
         * Scan the Media Library (runs synchronously, chunked).
         *
         * [--dry-run]
         * : Preview only — classify without selecting anything for deletion.
         */
        public function scan($args, $assoc)
        {
            $dry = isset($assoc['dry-run']);
            $mode = $dry ? 'preview' : 'full';
            $scan_id = devdsame_create_scan($mode, 0);
            if (!$scan_id) {
                WP_CLI::error('Could not create a scan session.');
            }
            $total = devdsame_total_attachments();
            WP_CLI::log(sprintf('Scanning %d image attachments...', $total));

            $progress = function_exists('WP_CLI\\Utils\\make_progress_bar')
                ? \WP_CLI\Utils\make_progress_bar('Scanning', max(1, $total))
                : null;

            $cursor = 0;
            $done = 0;
            do {
                $res = devdsame_scan_chunk($scan_id, $cursor, 200);
                if (!empty($res['error'])) {
                    devdsame_fail_scan($scan_id, (string) $res['error']);
                    WP_CLI::error('Scan failed: ' . $res['error']);
                }
                $cursor = $res['last_id'];
                $done += $res['processed'];
                if ($progress) {
                    $progress->tick($res['processed']);
                }
            } while (!$res['done']);
            if ($progress) {
                $progress->finish();
            }

            WP_CLI::log('Scanning files on disk for orphans...');
            $fs_offset = 0;
            do {
                $fs_res = devdsame_scan_filesystem($scan_id, 100000, $fs_offset);
                if (!empty($fs_res['error'])) {
                    devdsame_fail_scan($scan_id, (string) $fs_res['error']);
                    WP_CLI::error('Scan failed: ' . $fs_res['error']);
                }
                $fs_offset = (int) $fs_res['offset'];
            } while (empty($fs_res['done']));
            $score = devdsame_finalize_scan($scan_id);
            if ($score === false) {
                WP_CLI::error('Scan failed: the results could not be counted (database read failed).');
            }
            if (!devdsame_update_setting('last_scan_id', (int) $scan_id) || !devdsame_update_setting('last_scan_at', time())) {
                WP_CLI::error('The scan finished but its pointer could not be saved (database write failed); the dashboard still shows the previous scan.');
            }

            $s = devdsame_hub_summary();
            WP_CLI::success(sprintf(
                'Scan #%d complete. Unused: %d (%s). Uncertain: %d. Orphan: %d. Missing: %d. Duplicate: %d. Cleanliness: %d%%.',
                $scan_id,
                $s['unused_count'],
                size_format($s['possible_cleanup_bytes']),
                $s['uncertain_count'],
                $s['orphan_count'],
                $s['missing_count'],
                $s['duplicate_count'],
                $score
            ));
        }

        /**
         * Print a media-health report.
         *
         * [--format=<format>]
         * : table or json. Default: table.
         */
        public function report($args, $assoc)
        {
            $s = devdsame_hub_summary();
            $format = isset($assoc['format']) ? $assoc['format'] : 'table';
            $rows = array(
                array('metric' => 'Total media files', 'value' => $s['total_files']),
                array('metric' => 'Used', 'value' => $s['used_count']),
                array('metric' => 'Unused', 'value' => $s['unused_count']),
                array('metric' => 'Uncertain', 'value' => $s['uncertain_count']),
                array('metric' => 'Orphan files', 'value' => $s['orphan_count']),
                array('metric' => 'Missing files', 'value' => $s['missing_count']),
                array('metric' => 'Duplicates', 'value' => $s['duplicate_count']),
                array('metric' => 'Possible cleanup', 'value' => size_format($s['possible_cleanup_bytes'])),
                array('metric' => 'Cleanliness score', 'value' => $s['score'] . '%'),
            );
            WP_CLI\Utils\format_items($format, $rows, array('metric', 'value'));
        }

        /** Print the current job / last-scan status. */
        public function status($args, $assoc)
        {
            $p = devdsame_job_progress();
            if (!empty($p['active'])) {
                WP_CLI::log(sprintf('Job: %s — %s — %d/%d (%d%%)', $p['type'], $p['status'], $p['processed'], $p['total'], $p['percent']));
            } else {
                WP_CLI::log('No job running.');
            }
            WP_CLI::log('Last scan id: ' . devdsame_get_int('last_scan_id', 0));
        }

        /**
         * List scan items.
         *
         * [--status=<status>]
         * : used|unused|uncertain|missing|orphan|duplicate. Default: unused.
         *
         * [--limit=<n>]
         * : Max rows. Default: 50.
         *
         * [--format=<format>]
         * : table or json. Default: table.
         */
        public function list($args, $assoc)
        {
            global $wpdb;
            $items = $wpdb->prefix . 'devdsame_scan_items';
            $scan_id = devdsame_latest_scan_id();
            $status = isset($assoc['status']) ? sanitize_key($assoc['status']) : 'unused';
            $valid = array('used', 'unused', 'uncertain', 'missing', 'orphan', 'duplicate');
            $status = in_array($status, $valid, true) ? $status : 'unused';
            $limit = isset($assoc['limit']) ? max(1, (int) $assoc['limit']) : 50;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CLI read over internal scan_items; values bound via prepare.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, attachment_id, file_url, file_size, status, confidence FROM {$items} WHERE scan_id=%d AND status=%s ORDER BY file_size DESC LIMIT %d",
                $scan_id,
                $status,
                $limit
            ), ARRAY_A);
            if ($wpdb->last_error !== '') {
                WP_CLI::error('The scan results could not be read (database error): ' . $wpdb->last_error);
            }
            $out = array();
            foreach ((array) $rows as $r) {
                $out[] = array(
                    'id'         => $r['id'],
                    'attach'     => $r['attachment_id'],
                    'size'       => size_format((int) $r['file_size']),
                    'confidence' => $r['confidence'],
                    'url'        => $r['file_url'],
                );
            }
            $format = isset($assoc['format']) ? $assoc['format'] : 'table';
            WP_CLI\Utils\format_items($format, $out, array('id', 'attach', 'size', 'confidence', 'url'));
        }

        /**
         * Move selected unused items (or a specific selection) to Recycle Bin.
         *
         * [--selected]
         * : Trash everything the last scan auto-selected (status=unused, is_selected=1).
         *
         * [--dry-run]
         * : Show what would move without moving anything.
         */
        public function trash($args, $assoc)
        {
            global $wpdb;
            $items = $wpdb->prefix . 'devdsame_scan_items';
            $scan_id = devdsame_latest_scan_id();
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CLI selection read over internal scan_items; values bound via prepare.
            $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$items} WHERE scan_id=%d AND status='unused' AND is_selected=1", $scan_id));
            if ($wpdb->last_error !== '') {
                WP_CLI::error('The selection could not be read (database error): ' . $wpdb->last_error);
            }
            $ids = array_map('intval', (array) $ids);
            if (!$ids) {
                WP_CLI::warning('Nothing selected to trash.');
                return;
            }
            if (isset($assoc['dry-run'])) {
                WP_CLI::log(sprintf('[dry-run] Would move %d items to Recycle Bin.', count($ids)));
                return;
            }
            // Same rule as the queue and the restore/delete helpers: one writer owns the bin.
            if (devdsame_job_active() || !devdsame_acquire_tick_lock()) {
                WP_CLI::error('Another operation owns the Recycle Bin right now (a running job or a request in flight). Try again in a minute.');
            }
            try {
                $batch_id = devdsame_create_trash_batch(0, 'wp-cli');
                if (!$batch_id) {
                    WP_CLI::error('Could not create a Recycle Bin batch (folder not writable?).', false);
                    return;
                }
                $ok = 0;
                $err = 0;
                foreach ($ids as $id) {
                    $r = devdsame_trash_item($batch_id, $id);
                    is_wp_error($r) ? $err++ : $ok++;
                }
                devdsame_finalize_trash_batch($batch_id);
            } finally {
                devdsame_release_tick_lock();
            }
            devdsame_refresh_summary($scan_id);
            if ($err > 0) {
                WP_CLI::error(sprintf('Batch #%d: moved %d, skipped %d. See the error log for the skipped files.', $batch_id, $ok, $err));
            }
            WP_CLI::success(sprintf('Batch #%d: moved %d, skipped %d.', $batch_id, $ok, $err));
        }

        /**
         * Restore a Recycle Bin batch.
         *
         * --batch=<id>
         * : Batch id to restore.
         */
        public function restore($args, $assoc)
        {
            $batch = isset($assoc['batch']) ? (int) $assoc['batch'] : 0;
            if (!$batch) {
                WP_CLI::error('Pass --batch=<id>.');
            }
            $res = devdsame_restore_batch($batch);
            if ($res['errors'] > 0) {
                WP_CLI::error(sprintf('Restored %d, errors %d. The failed files stay in the Recycle Bin; see the error log.', $res['restored'], $res['errors']));
            }
            WP_CLI::success(sprintf('Restored %d, errors %d.', $res['restored'], $res['errors']));
        }

        /**
         * Permanently delete a Recycle Bin batch (irreversible).
         *
         * --batch=<id>
         * : Batch id to delete.
         *
         * [--yes]
         * : Skip the confirmation prompt.
         */
        public function delete($args, $assoc)
        {
            $batch = isset($assoc['batch']) ? (int) $assoc['batch'] : 0;
            if (!$batch) {
                WP_CLI::error('Pass --batch=<id>.');
            }
            WP_CLI::confirm(sprintf('Permanently delete every file in batch #%d? This cannot be undone.', $batch), $assoc);
            $res = devdsame_permanent_delete_batch($batch);
            devdsame_refresh_summary(devdsame_latest_scan_id());
            if ($res['errors'] > 0) {
                WP_CLI::error(sprintf('Deleted %d, errors %d. The failed files stay in the Recycle Bin; see the error log.', $res['deleted'], $res['errors']));
            }
            WP_CLI::success(sprintf('Deleted %d, errors %d.', $res['deleted'], $res['errors']));
        }
    }

    WP_CLI::add_command('devdome media', 'DEVDSAME_CLI');
}
