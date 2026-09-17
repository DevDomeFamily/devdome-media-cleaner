<?php
/**
 * Media backups — Library / Disk ZIP snapshots powering the Backup & Restore tab and the
 * dashboard Create Backup buttons.
 *
 * A backup is a ZIP in a protected /uploads/devdome-smc-backups/ folder, built in bounded
 * chunks by the shared job queue ('backup' job) so 10,000+ file libraries never time out.
 * Restore ('backup_restore' job) extracts entries back into /uploads in chunks, path-guarded.
 * Metadata (id, scope, file, files, bytes, created_at) lives in the settings store.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

/** Where backups live (no side effects); writers call devdsame_backups_dir() first. */
function devdsame_backups_path()
{
    return devdsame_uploads_basedir() . '/devdome-smc-backups';
}

/**
 * The backups folder, created and hardened. Returns '' when it cannot be made un-servable
 * (guard files could not be written) or is a link: nothing is written to such a folder.
 */
function devdsame_backups_dir()
{
    $dir = devdsame_backups_path();
    if (is_link($dir)) {
        return '';
    }
    // Same deny-all hardening as the Recycle Bin — backup ZIPs must never be publicly
    // downloadable; the Download button streams via an authenticated admin-post handler
    // instead. Verified on every writer's request (contents and links, not just presence).
    if (!devdsame_harden_dir($dir)) {
        return '';
    }
    return $dir;
}

/** All backup entries, newest first. */
function devdsame_backups()
{
    $list = devdsame_get_array('backups');
    usort($list, function ($a, $b) {
        return (int) $b['created_at'] <=> (int) $a['created_at'];
    });
    return $list;
}

/** One backup entry by id (or null). */
function devdsame_backup_get($id)
{
    foreach (devdsame_get_array('backups') as $b) {
        if ((string) $b['id'] === (string) $id) {
            return $b;
        }
    }
    return null;
}

/** Append a backup entry. */
function devdsame_backup_add($entry)
{
    $list = devdsame_get_array('backups');
    $list[] = $entry;
    return devdsame_update_setting('backups', $list) && devdsame_backup_get((string) $entry['id']) !== null;
}

/** Remove a backup entry + its ZIP file. */
function devdsame_backup_remove($id)
{
    if (is_link(devdsame_backups_path())) {
        return false; // never delete through a link that points elsewhere
    }
    $list = devdsame_get_array('backups');
    $removed = false;
    foreach ($list as $k => $b) {
        if ((string) $b['id'] === (string) $id) {
            $path = devdsame_backups_path() . '/' . wp_basename((string) $b['file']);
            if (is_file($path)) {
                wp_delete_file($path);
                clearstatcache(true, $path);
                if (is_file($path)) {
                    return false; // the zip is still there: keep the record so it can be retried
                }
            }
            unset($list[$k]);
            $removed = true;
        }
    }
    if (!$removed) {
        return false;
    }
    return devdsame_update_setting('backups', array_values($list)) && !devdsame_backup_get($id);
}

/**
 * Start a backup job for a scope. Returns the job array or WP_Error.
 *
 * @param string $scope library | disk
 * @param string $what  library: unused|all — disk: orphans|all. Default = only what
 *                      cleaning would delete (unused / orphans).
 */
function devdsame_start_backup($scope, $what = '')
{
    if (!class_exists('ZipArchive')) {
        return new WP_Error('devdsame_no_zip', __('The PHP zip extension is not available on this server.', 'devdome-safe-media-cleaner'));
    }
    $scope = $scope === 'disk' ? 'disk' : 'library';
    if (devdsame_backups_dir() === '') {
        return new WP_Error('devdsame_backup_dir', __('The backups folder could not be created or protected against public access; no backup was made.', 'devdome-safe-media-cleaner'));
    }
    if ($what !== 'all') {
        $what = $scope === 'disk' ? 'orphans' : 'unused';
    }
    // Sweep manifests/part-files from crashed or cancelled jobs (>1 day old).
    foreach ((array) glob(devdsame_backups_path() . '/.{manifest,part}-*', GLOB_BRACE) as $stale) {
        if (is_file($stale) && (time() - (int) filemtime($stale)) > DAY_IN_SECONDS) {
            wp_delete_file($stale);
        }
    }
    $file = sprintf('backup-%s-%s-%s.zip', $scope, gmdate('Ymd-His'), wp_generate_password(8, false, false));
    return devdsame_start_job('backup', array('scope' => $scope, 'what' => $what, 'file' => $file));
}

/** Open (create) a job's backup ZIP. Returns ZipArchive or null. */
function devdsame_backup_open_zip($job)
{
    $path = devdsame_backups_path() . '/' . wp_basename((string) ($job['args']['file'] ?? ''));
    if ($path === devdsame_backups_path() . '/') {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE) !== true) {
        return null;
    }
    return $zip;
}

/** Add one file to the ZIP, stored (images are already compressed — deflate wastes CPU). */
function devdsame_backup_zip_add($zip, $abs, $rel)
{
    if (!is_file($abs) || !$zip->addFile($abs, $rel)) {
        return false;
    }
    if (method_exists($zip, 'setCompressionName')) {
        $zip->setCompressionName($rel, ZipArchive::CM_STORE);
    }
    return true;
}

/** Path of a job's build manifest (the complete file list to zip, one "rel<TAB>abs" line each). */
function devdsame_backup_manifest_path($job)
{
    return devdsame_backups_path() . '/.manifest-' . md5((string) ($job['args']['file'] ?? '')) . '.txt';
}

/**
 * Build the manifest ONCE at job start. Library scope = every attachment image + its variants;
 * disk scope = ORPHAN images only (same filters + known-set test as the disk scan — library
 * files are the library backup's job). One walk total, instead of one walk per tick.
 *
 * Saves a live "Preparing backup... (N files found)" message to the job every few hundred
 * files so the ?peek=1 poller shows movement while this walk is still running.
 *
 * @return int|false number of files listed, or false on failure.
 */
function devdsame_backup_build_manifest($job)
{
    $scope = (string) ($job['args']['scope'] ?? 'library');
    $fh = @fopen(devdsame_backup_manifest_path($job), 'wb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming manifest write in our own guarded folder.
    if (!$fh) {
        return false;
    }
    $count = 0;
    $seen = 0;
    $pulse = function ($found, $walked) use (&$job) {
        $job['prep'] = (int) $walked;
        $job['message'] = sprintf(
            /* translators: %s: number of files found so far. */
            __('Preparing backup... (%s files found)', 'devdome-safe-media-cleaner'),
            number_format_i18n((int) $found)
        );
        $job['updated_at'] = time();
        devdsame_save_job($job);
    };
    $pulse(0, 0);

    $what = (string) ($job['args']['what'] ?? '');

    if ($scope === 'library') {
        global $wpdb;
        // 'unused' (the default) = only the attachments the last library scan marked unused —
        // exactly what Recycle Bin would delete. 'all' = every image attachment.
        $unused_ids = null;
        if ($what !== 'all') {
            $sid = function_exists('devdsame_latest_scan_id') ? (int) devdsame_latest_scan_id('library') : 0;
            $items = $wpdb->prefix . 'devdsame_scan_items';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items read.
            $unused_ids = $sid ? array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT attachment_id FROM {$items} WHERE scan_id = %d AND status = 'unused' AND attachment_id > 0",
                $sid
            ))) : array();
            if ($wpdb->last_error !== '') {
                fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen above.
                return false; // a failed read would back up nothing and call it complete
            }
        }
        $cursor = 0;
        while (true) {
            if (is_array($unused_ids)) {
                $ids = $cursor === 0 ? $unused_ids : array(); // one pass — the list is already complete
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked walk over core posts; bounds bound via prepare.
                $ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
                    $wpdb->esc_like('image/') . '%',
                    $cursor,
                    1000
                ));
            }
            if ($wpdb->last_error !== '') {
                fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen above.
                return false;
            }
            if (!$ids) {
                break;
            }
            foreach ($ids as $id) {
                $cursor = (int) $id;
                // ONE file per attachment — the original image the user sees in the Media
                // Library. Generated size variants are excluded (WP can regenerate them),
                // so the backup count matches the library count exactly.
                $abs = function_exists('wp_get_original_image_path') ? wp_get_original_image_path((int) $id) : false;
                if (!$abs) {
                    $abs = get_attached_file((int) $id);
                }
                if ($abs) {
                    $rel = devdsame_path_to_relative($abs);
                    if ($rel !== '' && is_file($abs)) {
                        $line = $rel . "\t" . $abs . "\n";

                        if (fwrite($fh, $line) !== strlen($line)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- manifest stream.

                            fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the manifest fopen.

                            return false; // a truncated list would back up fewer files than it claims

                        }
                        $count++;
                    }
                }
                clean_post_cache((int) $id);
                $seen++;
                if ($seen % 250 === 0) {
                    $pulse($count, $seen);
                }
            }
        }
    } else {
        $basedir = devdsame_uploads_basedir();
        $trash_real = realpath(devdsame_safe_trash_dir());
        $trash_real = $trash_real ? str_replace('\\', '/', $trash_real) : '';
        $backups_real = realpath(devdsame_backups_path());
        $backups_real = $backups_real ? str_replace('\\', '/', $backups_real) : '';
        $never_folders = array();
        foreach (devdsame_get_array('never_scan_folders') as $nf) {
            $never_folders[] = strtolower(trim((string) $nf, '/'));
        }
        $known = $what === 'all' ? array() : devdsame_known_attachment_files();
        if ($known === null) {
            fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the manifest fopen.
            return false; // without the library list every image would be listed as an orphan
        }

        foreach (devdsame_iterate_files($basedir) as $abs) {
            $seen++;
            if ($seen % 1000 === 0) {
                $pulse($count, $seen);
            }
            $norm = str_replace('\\', '/', $abs);
            if ($trash_real !== '' && ($norm === $trash_real || strpos($norm, $trash_real . '/') === 0)) {
                continue;
            }
            if ($backups_real !== '' && ($norm === $backups_real || strpos($norm, $backups_real . '/') === 0)) {
                continue;
            }
            $bn = wp_basename($norm);
            $ext = strtolower((string) pathinfo($bn, PATHINFO_EXTENSION));
            if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'svg', 'heic', 'tif', 'tiff'), true)) {
                continue;
            }
            $rel = devdsame_path_to_relative($abs);
            $first_seg = strtolower((string) strtok((string) $rel, '/'));
            if ($rel === '' || in_array($first_seg, devdsame_orphan_skip_folders(), true)) {
                continue;
            }
            // The user's never-scan folders are outside cleaning, so outside this backup too (same rule as the disk scan).
            $skip_never = false;
            foreach ($never_folders as $nf) {
                if ($nf !== '' && ($first_seg === $nf || strpos(strtolower((string) $rel), $nf . '/') === 0)) {
                    $skip_never = true;
                    break;
                }
            }
            if ($skip_never) {
                continue;
            }
            // 'orphans' (the default) backs up only what disk cleaning would delete;
            // 'all' includes library-owned files too.
            $rel_key = strtolower((string) $rel);
            if ($what !== 'all' && (isset($known[$rel_key]) || devdsame_is_variant_of_known($rel, $known))) {
                continue;
            }
            $line = $rel . "\t" . $abs . "\n";

            if (fwrite($fh, $line) !== strlen($line)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- manifest stream.

                fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the manifest fopen.

                return false; // a truncated list would back up fewer files than it claims

            }
            $count++;
        }
    }
    fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the manifest fopen.
    return $count;
}

/**
 * Backup tick. Tick 1 builds the manifest; each following tick appends up to 500 manifest
 * files in ONE zip open/close. addFile defers the byte copy to close(), so the 500 cap
 * bounds how much data a single tick writes. 500 (not thousands) keeps each tick ~2-3s so
 * the polled progress bar visibly advances instead of jumping in one giant step.
 */
function devdsame_tick_backup($job, $chunk)
{
    $scope = (string) ($job['args']['scope'] ?? 'library');

    if (empty($job['manifest_ready'])) {
        $count = devdsame_backup_build_manifest($job);
        if ($count === false) {
            $job['status'] = 'error';
            $job['message'] = __('Could not create the backup file. Check that /uploads is writable.', 'devdome-safe-media-cleaner');
            devdsame_record_error('backup_open', $job['message'], array('scope' => $scope));
            return $job;
        }
        $job['manifest_ready'] = 1;
        $job['total'] = (int) $count;
        $job['cursor'] = 0;
        $job['message'] = sprintf(
            /* translators: %s: number of files that will be backed up. */
            __('Backing up %s files...', 'devdome-safe-media-cleaner'),
            number_format_i18n((int) $count)
        );
        return $job;
    }

    $zip = devdsame_backup_open_zip($job);
    if (!$zip) {
        $job['status'] = 'error';
        $job['message'] = __('Could not create the backup file. Check that /uploads is writable.', 'devdome-safe-media-cleaner');
        devdsame_record_error('backup_open', $job['message'], array('scope' => $scope));
        return $job;
    }

    $lines = file(devdsame_backup_manifest_path($job), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- manifest read.
    if (!is_array($lines)) {
        $zip->close();
        $job['status'] = 'error';
        $job['message'] = __('The backup file list could not be read; no backup was created.', 'devdome-safe-media-cleaner');
        devdsame_record_error('backup_manifest', $job['message'], array('scope' => $scope));
        return $job;
    }
    $total = count($lines);
    $job['total'] = $total;
    $added = (int) ($job['files_added'] ?? 0);
    $i = (int) $job['cursor'];
    $end = min($total, $i + 500);

    for (; $i < $end; $i++) {
        $parts = explode("\t", $lines[$i], 2);
        if (count($parts) === 2 && devdsame_backup_zip_add($zip, $parts[1], $parts[0])) {
            $added++;
        }
        $job['processed']++;
    }
    $job['cursor'] = $i;
    $job['files_added'] = $added;
    $job['message'] = sprintf(
        /* translators: 1: files done, 2: total files. */
        __('Backing up... (%1$s of %2$s files)', 'devdome-safe-media-cleaner'),
        number_format_i18n($i),
        number_format_i18n($total)
    );
    $path = devdsame_backups_path() . '/' . wp_basename((string) $job['args']['file']);
    if (!$zip->close()) { // the actual byte copy for this tick's slice happens here
        $job['status'] = 'error';
        $job['message'] = __('The backup file could not be written (disk full or folder not writable). No backup was created.', 'devdome-safe-media-cleaner');
        devdsame_record_error('backup_write', $job['message'], array('scope' => $scope, 'file' => wp_basename($path)));
        wp_delete_file(devdsame_backup_manifest_path($job));
        if (is_file($path)) {
            wp_delete_file($path);
        }
        return $job;
    }

    if ($i >= $total) {
        wp_delete_file(devdsame_backup_manifest_path($job));
        if (!is_file($path) || ($added > 0 && (int) filesize($path) === 0)) {
            $job['status'] = 'error';
            $job['message'] = __('The backup file is missing or empty after writing. No backup was created.', 'devdome-safe-media-cleaner');
            devdsame_record_error('backup_write', $job['message'], array('scope' => $scope, 'file' => wp_basename($path)));
            return $job;
        }
        if ($added < $total) {
            // Files listed but not added (vanished or unreadable): the archive is not the safety copy it claims to be.
            $job['status'] = 'error';
            $job['message'] = sprintf(
                /* translators: 1: files added, 2: files expected */
                __('Backup incomplete: %1$s of %2$s files could be added, so it was not registered. Fix the unreadable files and try again.', 'devdome-safe-media-cleaner'),
                number_format_i18n($added),
                number_format_i18n($total)
            );
            devdsame_record_error('backup_incomplete', $job['message'], array('scope' => $scope, 'added' => $added, 'expected' => $total));
            wp_delete_file($path);
            return $job;
        }
        $registered = devdsame_backup_add(array(
            'id'         => (string) $job['created_at'] . '-' . substr(md5((string) $job['args']['file']), 0, 6),
            'scope'      => $scope,
            'file'       => wp_basename((string) $job['args']['file']),
            'files'      => $added,
            'bytes'      => is_file($path) ? (int) filesize($path) : 0,
            'created_at' => time(),
        ));
        if (!$registered) {
            $job['status'] = 'error';
            $job['message'] = __('The backup was written but could not be registered (database write failed). It is on disk in the backups folder; run the backup again.', 'devdome-safe-media-cleaner');
            devdsame_record_error('backup_register', $job['message'], array('scope' => $scope, 'file' => wp_basename($path)));
            return $job;
        }
        $job['status'] = 'completed';
        $job['message'] = __('Backup created.', 'devdome-safe-media-cleaner');
    }
    return $job;
}

/** Restore tick — extract one bounded chunk of ZIP entries back into /uploads. */
function devdsame_tick_backup_restore($job, $chunk)
{
    $entry = devdsame_backup_get((string) ($job['args']['backup_id'] ?? ''));
    $path = $entry ? devdsame_backups_path() . '/' . wp_basename((string) $entry['file']) : '';
    if (!$entry || !is_file($path) || is_link(devdsame_backups_path()) || is_link($path) || !class_exists('ZipArchive')) {
        $job['status'] = 'error';
        $job['message'] = __('Backup file not found.', 'devdome-safe-media-cleaner');
        return $job;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        $job['status'] = 'error';
        $job['message'] = __('Could not open the backup file.', 'devdome-safe-media-cleaner');
        return $job;
    }

    $basedir = devdsame_uploads_basedir();
    $total = (int) $zip->numFiles;
    $job['total'] = $total;
    $restored = array();
    $end = min($total, (int) $job['cursor'] + max(50, (int) $chunk));
    // Largest entry we will buffer in memory: a quarter of memory_limit (unlimited = 256 MB), filterable down.
    $mem = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
    $entry_cap = (int) apply_filters('devdsame_max_backup_entry_bytes', min(256 * MB_IN_BYTES, $mem > 0 ? (int) floor($mem / 4) : 256 * MB_IN_BYTES));

    // Restore extension allowlist: image files only (what our own backups contain).
    // SVG is only restored when the site itself allows SVG uploads (it can carry scripts).
    $allowed = array('jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'tif', 'tiff', 'heic');
    foreach (array_keys(get_allowed_mime_types()) as $mime_ext) {
        if (preg_match('/(^|\|)svgz?($|\|)/', $mime_ext)) {
            $allowed[] = 'svg';
            break;
        }
    }

    for ($i = (int) $job['cursor']; $i < $end; $i++) {
        $job['cursor'] = $i + 1;
        $job['processed']++;
        $name = (string) $zip->getNameIndex($i);
        // Path guard: relative, inside uploads, no traversal (backslash rejected for Windows hosts).
        if ($name === '' || strpos($name, '..') !== false || strpos($name, ':') !== false || strpos($name, '\\') !== false || $name[0] === '/') {
            $job['errors']++;
            continue;
        }
        if (substr($name, -1) === '/') {
            $job['processed']--; // a folder entry is not a file: keep the "written back" count honest
            continue;
        }
        // Entry guards for uploaded archives: allowlisted image extensions, no
        // hidden/dot files, and a per-entry uncompressed size cap so a crafted ZIP
        // cannot exhaust memory via getFromIndex().
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $stat = $zip->statIndex($i);
        if (
            !in_array($ext, $allowed, true)
            || 0 === strpos(wp_basename($name), '.')
            || !$stat
            || (int) $stat['size'] > (int) apply_filters('devdsame_max_backup_entry_bytes', 256 * MB_IN_BYTES)
        ) {
            $job['errors']++;
            continue;
        }
        $target = $basedir . '/' . $name;
        $dir = dirname($target);
        // The real path must stay inside uploads: folders are created only below an existing
        // ancestor that resolves inside uploads (never through a link), and a link at the target
        // is refused. An existing file is never written over (a restore puts back what is
        // missing; it must not replace newer media that took the same name).
        if (!devdsame_mkdir_inside_uploads($dir) || is_link($target) || devdsame_validate_in_uploads($target) === false) {
            $job['errors']++;
            continue;
        }
        if (file_exists($target)) {
            $job['kept'] = (int) ($job['kept'] ?? 0) + 1;
            continue;
        }
        // getFromIndex() buffers the whole entry: keep it well under the memory limit.
        if ((int) $stat['size'] > $entry_cap) {
            $job['errors']++;
            continue;
        }
        $data = $zip->getFromIndex($i);
        if ($data === false) {
            $job['errors']++;
            continue;
        }
        $written = @file_put_contents($target, $data, LOCK_EX); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- streaming restore of media files into uploads; WP_Filesystem direct method wraps the same call.
        if ($written === false || $written !== strlen($data)) {
            // A short write (disk full) leaves a truncated image: remove it, count the failure.
            if (is_file($target)) {
                wp_delete_file($target);
            }
            $job['errors']++;
        } else {
            $restored[] = array($name, $target);
        }
        unset($data);
    }
    $zip->close();

    // Library restore must bring images BACK INTO the Media Library, not just onto disk:
    // any restored image with no attachment (e.g. its batch was permanently deleted) is
    // re-registered so it shows up in the library again.
    if ((string) $entry['scope'] === 'library' && $restored) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $known = devdsame_known_attachment_files();
        if ($known === null) {
            // The files are written back, but without the library file set a restored image could be
            // registered twice: stop here and say so instead of guessing.
            $job['status'] = 'error';
            $job['message'] = __('Files were written back, but the Media Library could not be checked (database read failed), so none were added to it. Run a scan to review them.', 'devdome-safe-media-cleaner');
            devdsame_record_error('backup_restore', $job['message'], array('backup_id' => (string) ($job['args']['backup_id'] ?? '')));
            return $job;
        }
        foreach ($restored as $rp) {
            list($rel, $abs) = $rp;
            $key = strtolower((string) $rel);
            if (isset($known[$key]) || devdsame_is_variant_of_known($rel, $known)) {
                continue; // already in the library
            }
            $ft = wp_check_filetype(wp_basename($abs));
            if (strpos((string) $ft['type'], 'image/') !== 0) {
                continue;
            }
            $aid = wp_insert_attachment(array(
                'post_mime_type' => (string) $ft['type'],
                'post_title'     => sanitize_file_name((string) pathinfo($abs, PATHINFO_FILENAME)),
                'post_status'    => 'inherit',
            ), $abs);
            if (!$aid || is_wp_error($aid)) {
                $job['errors']++; // on disk but not in the library: reported, never hidden
                continue;
            }
            if ($aid && !is_wp_error($aid)) {
                // Exempts it from recent-upload protection: this is a restore, not a fresh upload.
                update_post_meta($aid, '_devdsame_reregistered', 1);
                wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $abs));
                if (!devdsame_backup_register_scan_item((int) $aid, (string) $rel, (string) $abs)) {
                    // The image IS back in the library; only its scan row is missing until the next scan.
                    devdsame_record_error('backup_restore', __('A restored image could not be added to the scan results (database write failed); it is in the Media Library and the next scan will list it.', 'devdome-safe-media-cleaner'), array('attachment_id' => (int) $aid));
                }
                $job['registered'] = (int) ($job['registered'] ?? 0) + 1;
                if ((int) $job['registered'] % 3 === 0) {
                    // Live message while thumbnails generate — this is the slow part.
                    $job['message'] = sprintf(
                        /* translators: %s: number of images added back so far. */
                        __('Adding images back to the Media Library... (%s)', 'devdome-safe-media-cleaner'),
                        number_format_i18n((int) $job['registered'])
                    );
                    $job['updated_at'] = time();
                    devdsame_save_job($job);
                }
            }
        }
    }

    $job['message'] = sprintf(
        /* translators: 1: files restored so far, 2: total files in the backup. */
        __('Restoring... (%1$s of %2$s files)', 'devdome-safe-media-cleaner'),
        number_format_i18n((int) $job['processed']),
        number_format_i18n($total)
    );
    if ($job['cursor'] >= $total) {
        $written = (int) ($job['processed'] ?? 0) - (int) $job['errors'] - (int) ($job['kept'] ?? 0);
        if ((int) $job['errors'] > 0) {
            $job['status'] = 'error';
            $job['message'] = sprintf(
                /* translators: 1: files written, 2: files skipped as invalid or not writable, 3: files kept because they already existed */
                __('Restore finished with problems: %1$s files written back, %2$s could not be restored (invalid entry or not writable), %3$s existing files kept.', 'devdome-safe-media-cleaner'),
                number_format_i18n(max(0, $written)),
                number_format_i18n((int) $job['errors']),
                number_format_i18n((int) ($job['kept'] ?? 0))
            );
            devdsame_record_error('backup_restore', $job['message'], array('backup_id' => (string) ($job['args']['backup_id'] ?? ''), 'errors' => (int) $job['errors']));
        } else {
            $job['status'] = 'completed';
            $job['message'] = sprintf(
                /* translators: 1: files written back, 2: existing files kept */
                __('Backup restored: %1$s files written back, %2$s already present and kept.', 'devdome-safe-media-cleaner'),
                number_format_i18n(max(0, $written)),
                number_format_i18n((int) ($job['kept'] ?? 0))
            );
        }
        if (!empty($job['registered']) && function_exists('devdsame_refresh_summary')) {
            devdsame_refresh_summary();
        }
    }
    return $job;
}

/**
 * A re-registered (restored) image goes straight into the latest library scan's results as
 * 'unused', and the scan totals are bumped — the dashboard numbers update with NO rescan.
 */
function devdsame_backup_register_scan_item($aid, $rel, $abs)
{
    global $wpdb;
    $sid = function_exists('devdsame_latest_scan_id') ? (int) devdsame_latest_scan_id('library') : 0;
    if (!$sid) {
        return true; // never scanned — the first scan will pick it up.
    }
    $size = is_file($abs) ? (int) @filesize($abs) : 0;
    $dims = @getimagesize($abs);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items insert.
    $inserted = $wpdb->insert($wpdb->prefix . 'devdsame_scan_items', array(
        'scan_id'          => $sid,
        'attachment_id'    => (int) $aid,
        'file_path'        => (string) $abs,
        'file_url'         => devdsame_uploads_baseurl() . '/' . ltrim((string) $rel, '/'),
        'file_hash'        => function_exists('devdsame_file_hash') ? devdsame_file_hash($abs) : '',
        'file_size'        => $size,
        'width'            => is_array($dims) && isset($dims[0]) ? (int) $dims[0] : 0,
        'height'           => is_array($dims) && isset($dims[1]) ? (int) $dims[1] : 0,
        'mime_type'        => is_array($dims) && isset($dims['mime']) ? (string) $dims['mime'] : '',
        'upload_date'      => null,
        'status'           => 'unused',
        'confidence'       => 100,
        'reason_code'      => 'restored_from_backup',
        'references_found' => 0,
        'is_selected'      => 0,
        'created_at'       => current_time('mysql'),
    ), array('%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s'));
    if (!$inserted || $wpdb->last_error !== '') {
        return false;
    }
    // Bump the library-bytes roll-up (total image count is computed live by the summary).
    $scans = $wpdb->prefix . 'devdsame_scans';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans roll-up bump; values bound via prepare.
    $ok = $wpdb->query($wpdb->prepare(
        "UPDATE {$scans} SET total_library_bytes = total_library_bytes + %d WHERE id = %d",
        $size,
        $sid
    ));
    return $ok !== false && $wpdb->last_error === '';
}

/* ----------------------------- chunked upload ---------------------------- */

/**
 * REST: POST /upload-backup — one chunk of a client-side sliced ZIP upload.
 *
 * Hosts commonly cap request bodies at 1-64 MB (nginx client_max_body_size, PHP
 * upload_max_filesize/post_max_size), so a whole-file upload 413s for any real backup.
 * The JS slices the file into small chunks (halving on 413) and this endpoint appends
 * them to a temp part-file; the final chunk validates the ZIP and registers the backup.
 */
function devdsame_rest_upload_backup(WP_REST_Request $request)
{
    if (devdsame_backups_dir() === '') {
        return new WP_Error('devdsame_backup_dir', __('The backups folder could not be created or protected against public access.', 'devdome-safe-media-cleaner'), array('status' => 500));
    }
    $scope = $request->get_param('scope') === 'disk' ? 'disk' : 'library';
    $name = sanitize_file_name((string) $request->get_param('name'));
    $done = (int) $request->get_param('done');
    $upload_id = strtolower((string) $request->get_param('upload_id'));

    // Cancel: discard the assembled part-file and end the session.
    if ((int) $request->get_param('cancel') === 1) {
        if (preg_match('/^[a-z0-9]{8,32}$/', $upload_id)) {
            $p = devdsame_backups_path() . '/.part-' . $upload_id;
            if (is_file($p)) {
                wp_delete_file($p);
                clearstatcache(true, $p);
                if (is_file($p)) {
                    return new WP_Error('devdsame_cancel_failed', __('The partial upload could not be removed (check folder permissions on /wp-content/uploads).', 'devdome-safe-media-cleaner'), array('status' => 500));
                }
            }
        }
        return rest_ensure_response(array('cancelled' => true));
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- REST route: cookie nonce + capability enforced by the route's permission_callback; tmp_name used via is_uploaded_file gate only.
    $chunk = isset($_FILES['chunk']) ? $_FILES['chunk'] : null;
    if (!$chunk || !isset($chunk['tmp_name']) || !is_uploaded_file($chunk['tmp_name']) || (int) $chunk['error'] !== UPLOAD_ERR_OK) {
        return new WP_Error('devdsame_no_chunk', __('No data received.', 'devdome-safe-media-cleaner'), array('status' => 400));
    }

    $dir = devdsame_backups_path();

    if ($upload_id === '') {
        $upload_id = strtolower(wp_generate_password(16, false, false));
        // Sweep abandoned part-files (>1 day old) so failed uploads never pile up.
        foreach ((array) glob($dir . '/.part-*') as $stale) {
            if (is_file($stale) && (time() - (int) filemtime($stale)) > DAY_IN_SECONDS) {
                wp_delete_file($stale);
            }
        }
    }
    if (!preg_match('/^[a-z0-9]{8,32}$/', $upload_id)) {
        return new WP_Error('devdsame_bad_upload', __('Invalid upload session.', 'devdome-safe-media-cleaner'), array('status' => 400));
    }
    $temp = $dir . '/.part-' . $upload_id;
    if (is_link($temp)) {
        return new WP_Error('devdsame_write_failed', __('Could not write to the backups folder.', 'devdome-safe-media-cleaner'), array('status' => 500));
    }

    $bytes = file_get_contents($chunk['tmp_name']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local uploaded chunk, not a remote URL.
    if ($bytes === false || @file_put_contents($temp, $bytes, FILE_APPEND) !== strlen($bytes)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- append-assembly of a chunked upload.
        return new WP_Error('devdsame_write_failed', __('Could not write to the backups folder.', 'devdome-safe-media-cleaner'), array('status' => 500));
    }

    // Same total cap as the form upload — enforced on the growing part-file so an
    // oversized archive is rejected mid-stream, not after the last chunk.
    $max_bytes = (int) apply_filters('devdsame_max_backup_upload_bytes', 2 * GB_IN_BYTES);
    if ((int) filesize($temp) > $max_bytes) {
        wp_delete_file($temp);
        return new WP_Error('devdsame_too_large', __('The uploaded archive is larger than the allowed maximum.', 'devdome-safe-media-cleaner'), array('status' => 413));
    }

    if (!$done) {
        return rest_ensure_response(array('upload_id' => $upload_id, 'received' => (int) filesize($temp)));
    }

    // Final chunk: the assembled file must be a readable ZIP.
    if (!class_exists('ZipArchive')) {
        wp_delete_file($temp);
        return new WP_Error('devdsame_no_zip', __('The PHP zip extension is not available on this server.', 'devdome-safe-media-cleaner'), array('status' => 500));
    }
    $zip = new ZipArchive();
    if ($zip->open($temp) !== true) {
        wp_delete_file($temp);
        return new WP_Error('devdsame_bad_zip', __('The uploaded file is not a valid ZIP archive.', 'devdome-safe-media-cleaner'), array('status' => 400));
    }
    $files = (int) $zip->numFiles;
    $zip->close();
    if ($files > (int) apply_filters('devdsame_max_backup_entries', 200000)) {
        wp_delete_file($temp);
        return new WP_Error('devdsame_too_many_files', __('The uploaded archive contains too many files.', 'devdome-safe-media-cleaner'), array('status' => 400));
    }

    $final = $dir . '/upload-' . $scope . '-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false) . '-' . ($name !== '' ? $name : 'backup.zip');
    if (file_exists($final) || is_link($final) || !@rename($temp, $final)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic move inside our own guarded folder.
        wp_delete_file($temp);
        return new WP_Error('devdsame_move_failed', __('Could not finalize the uploaded backup.', 'devdome-safe-media-cleaner'), array('status' => 500));
    }
    $registered = devdsame_backup_add(array(
        'id'         => time() . '-' . substr(md5($final), 0, 6),
        'scope'      => $scope,
        'file'       => wp_basename($final),
        'files'      => $files,
        'bytes'      => (int) filesize($final),
        'created_at' => time(),
    ));
    if (!$registered) {
        wp_delete_file($final);
        return new WP_Error('devdsame_register_failed', __('The uploaded backup could not be registered (database write failed). Upload it again.', 'devdome-safe-media-cleaner'), array('status' => 500));
    }
    return rest_ensure_response(array('upload_id' => $upload_id, 'complete' => true, 'files' => $files));
}

/* --------------------------- admin-post handlers ------------------------- */

/** Stream a backup ZIP as a download (nonce + cap). */
function devdsame_handle_backup_download()
{
    if (!current_user_can(devdsame_capability())) {
        wp_die(esc_html__('You do not have permission.', 'devdome-safe-media-cleaner'));
    }
    check_admin_referer('devdsame_backup', '_mcbk');
    $id = isset($_GET['backup_id']) ? sanitize_text_field(wp_unslash($_GET['backup_id'])) : '';
    $entry = devdsame_backup_get($id);
    $path = $entry ? devdsame_backups_path() . '/' . wp_basename((string) $entry['file']) : '';
    if (!$entry || !is_file($path)) {
        wp_die(esc_html__('Backup file not found.', 'devdome-safe-media-cleaner'));
    }
    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . wp_basename($path) . '"');
    header('Content-Length: ' . (string) filesize($path));
    $fh = fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- chunked streaming download; readfile would buffer the whole archive.
    if ($fh) {
        while (!feof($fh)) {
            echo fread($fh, 1048576); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- binary ZIP stream.
            flush();
        }
        fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the streaming fopen above.
    }
    exit;
}
add_action('admin_post_devdsame_backup_download', 'devdsame_handle_backup_download');

/** Backup tab actions: create / restore / delete / upload (self-POST, nonce + cap, PRG). */
function devdsame_handle_backup_actions()
{
    if (empty($_POST['devdsame_backup_action']) || !current_user_can(devdsame_capability())) {
        return;
    }
    check_admin_referer('devdsame_backup', '_mcbk');
    $action = sanitize_key(wp_unslash($_POST['devdsame_backup_action']));
    $id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';
    $notice = '';

    if ($action === 'delete' && $id !== '') {
        $notice = devdsame_backup_remove($id) ? 'deleted' : 'error';
    } elseif ($action === 'upload') {
        $notice = devdsame_handle_backup_upload_file();
    }

    wp_safe_redirect(add_query_arg(array('page' => DEVDSAME_PAGE, 'mc_tab' => 'backup', 'mc_bk' => $notice), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdsame_handle_backup_actions');

/** Move an uploaded .zip into the backups folder and register it. Returns a notice key. */
function devdsame_handle_backup_upload_file()
{
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- called only from devdsame_handle_backup_actions() after check_admin_referer(); $_FILES paths are used via the is_uploaded_file gate only.
    $f = isset($_FILES['mc_backup_zip']) ? $_FILES['mc_backup_zip'] : null;
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verified the nonce via check_admin_referer(); value is compared against a fixed allowlist.
    $scope = isset($_POST['backup_scope']) && $_POST['backup_scope'] === 'disk' ? 'disk' : 'library';
    if (!$f || !isset($f['tmp_name']) || !is_uploaded_file($f['tmp_name']) || (int) $f['error'] !== UPLOAD_ERR_OK) {
        return 'error';
    }
    $name = sanitize_file_name((string) $f['name']);
    if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
        return 'error';
    }
    // Size cap: bounded but filterable upward for very large libraries. Most hosts'
    // upload_max_filesize gates far lower anyway.
    $max_bytes = (int) apply_filters('devdsame_max_backup_upload_bytes', 2 * GB_IN_BYTES);
    if ((int) $f['size'] > $max_bytes) {
        return 'error';
    }
    if (devdsame_backups_dir() === '') {
        return 'error';
    }
    if (!class_exists('ZipArchive')) {
        return 'error'; // an archive this host cannot open could never be restored: refuse it up front
    }
    $dest = devdsame_backups_path() . '/upload-' . $scope . '-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false) . '-' . $name;
    // Move the validated upload with the direct WP_Filesystem method: the PHP temp folder is
    // local, and an auto-selected FTP method could not reach it. Never replace an existing archive.
    $fs = devdsame_fs();
    if (!$fs || file_exists($dest) || is_link($dest) || !$fs->move($f['tmp_name'], $dest, false) || !is_file($dest)) {
        return 'error';
    }
    $files = 0;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($dest) === true) {
            $files = (int) $zip->numFiles;
            // Entry-count cap (zip-bomb guard); per-entry checks run again at restore time.
            if ($files > (int) apply_filters('devdsame_max_backup_entries', 200000)) {
                $zip->close();
                wp_delete_file($dest);
                return 'error';
            }
            $zip->close();
        } else {
            wp_delete_file($dest);
            return 'error';
        }
    }
    $registered = devdsame_backup_add(array(
        'id'         => time() . '-' . substr(md5($dest), 0, 6),
        'scope'      => $scope,
        'file'       => wp_basename($dest),
        'files'      => $files,
        'bytes'      => (int) filesize($dest),
        'created_at' => time(),
    ));
    if (!$registered) {
        wp_delete_file($dest);
        return 'error';
    }
    return 'uploaded';
}
