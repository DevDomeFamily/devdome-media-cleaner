<?php
/**
 * Background processing / chunked job runner (spec performance §).
 *
 * Persists ONE job's state in the settings store (key 'job') so it survives requests, timeouts
 * and power outages, and is fully resumable + pause/resume/cancel-able. A self-rescheduling
 * single WP-Cron event ('devdsame_run_job') drives ticks; the admin can also kick a tick via
 * REST (the poller). Each tick does at most scan_chunk_size units, batches DB writes, frees
 * memory, and records progress + ETA. Job types: scan (incl. preview), trash, restore, delete.
 */

defined('ABSPATH') || exit;

/** Current job state (array) or null when idle. */
function devdsame_get_job()
{
    $job = devdsame_get_setting('job', '');
    return is_array($job) ? $job : null;
}

/** Persist the job state. */
function devdsame_save_job($job)
{
    return devdsame_update_setting('job', is_array($job) ? $job : array());
}

/** Clear the job. */
function devdsame_clear_job()
{
    devdsame_update_setting('job', '');
}

/**
 * Persist a structured, user-visible error so it survives the page reload (the progress poller
 * only flashes it). Surfaced on the Dashboard with retry / view-details / export-log actions.
 *
 * @param string $code    machine code (e.g. 'trash_batch', 'not_writable', 'db', 'rest')
 * @param string $message human message
 * @param array  $context optional extra detail for "view details" / the exported log
 */
function devdsame_record_error($code, $message, $context = array())
{
    $log = devdsame_get_array('error_log');
    $log[] = array(
        'at'      => time(),
        'code'    => (string) $code,
        'message' => (string) $message,
        'context' => is_array($context) ? $context : array(),
    );
    if (count($log) > 50) {
        $log = array_slice($log, -50);
    }
    devdsame_update_setting('error_log', $log);
    devdsame_update_setting('last_error', array(
        'at'      => time(),
        'code'    => (string) $code,
        'message' => (string) $message,
        'context' => is_array($context) ? $context : array(),
    ));
}

/** The most recent persisted error (or null). */
function devdsame_last_error()
{
    $e = devdsame_get_setting('last_error', '');
    return is_array($e) && !empty($e['message']) ? $e : null;
}

/** Clear the surfaced error (user dismissed / retried). */
function devdsame_clear_last_error()
{
    return devdsame_update_setting('last_error', '');
}

/**
 * Start a new job. Returns the job array, or WP_Error if one is already running.
 *
 * @param string $type scan | preview | trash | restore | delete
 * @param array  $args type-specific payload (e.g. item ids for trash)
 */
function devdsame_start_job($type, $args = array())
{
    // Fresh read — this can run inside a long-lived tick request whose per-request settings
    // cache predates a concurrent cancel/clear. A stale "running" job here would wrongly
    // reject the start (this blocked cancel rollbacks from ever starting).
    unset($GLOBALS['devdsame_cache']);
    $existing = devdsame_get_job();
    if ($existing && in_array($existing['status'], array('running', 'paused'), true)) {
        // Self-heal: a job whose last heartbeat is older than 10 minutes is dead (crashed tick,
        // closed tab, power loss). Never let it block new work forever.
        $age = time() - (int) $existing['updated_at'];
        if ($existing['status'] === 'running' && $age > 600) {
            // A dead clean is not "done": its batch goes back like a cancel (all-or-nothing), and the
            // event is recorded. If the marker cannot be written the dead job stays and blocks.
            if ($existing['type'] === 'trash' && !empty($existing['batch_id'])) {
                update_option('devdsame_rollback_batch', (string) (int) $existing['batch_id'], false);
                if (devdsame_pending_rollback() !== (int) $existing['batch_id']) {
                    return new WP_Error('devdsame_job_running', __('A previous cleanup stopped unexpectedly and could not be rolled back yet. Try again in a moment.', 'devdome-safe-media-cleaner'));
                }
            }
            devdsame_record_error('job_stale', sprintf(/* translators: %s: job type */ __('A %s job stopped responding for 10 minutes and was closed. A stopped cleanup is put back automatically; check the Recycle Bin.', 'devdome-safe-media-cleaner'), (string) $existing['type']), array('batch_id' => (int) ($existing['batch_id'] ?? 0)));
            devdsame_release_tick_lock();
            devdsame_clear_job();
            if (devdsame_pending_rollback() > 0) {
                devdsame_schedule_tick(1);
                return new WP_Error('devdsame_rollback_pending', __('A stopped cleanup is being put back first. Try again in a moment.', 'devdome-safe-media-cleaner'));
            }
        } else {
            return new WP_Error('devdsame_job_running', __('Another job is already in progress.', 'devdome-safe-media-cleaner'));
        }
    }

    // A cancelled clean is still being rolled back by the ticks: a new job would run ahead of it
    // (and a delete on that batch would destroy files the rollback still owes the site).
    if (devdsame_pending_rollback()) {
        return new WP_Error('devdsame_rollback_pending', __('A cancelled clean is still being put back. Try again in a moment.', 'devdome-safe-media-cleaner'));
    }

    $type = in_array($type, array('scan', 'preview', 'trash', 'restore', 'delete', 'backup', 'backup_restore'), true) ? $type : 'scan';

    // Scan scope: 'library' (Media Library attachments only), 'disk' (uploads-folder orphan
    // pass only) or 'full' (both — cron, CLI, retry). The dashboard's two tools pass it.
    $scope = isset($args['scope']) && in_array($args['scope'], array('library', 'disk', 'full'), true) ? $args['scope'] : 'full';

    $job = array(
        'id'         => wp_generate_password(12, false, false), // what a cancel names, never a second-resolution timestamp
        'type'       => $type,
        'status'     => 'running',
        'created_at' => time(),
        'updated_at' => time(),
        'cursor'     => 0,
        'processed'  => 0,
        'total'      => 0,
        'errors'     => 0,
        'scan_id'    => 0,
        'batch_id'   => 0,
        'scope'      => $scope,
        'phase'      => 'attachments', // for scan: attachments -> filesystem -> finalize
        'args'       => is_array($args) ? $args : array(),
        'message'    => '',
    );

    if ($type === 'scan' || $type === 'preview') {
        $mode = $type === 'preview' ? 'preview' : $scope;
        $job['scan_id'] = devdsame_create_scan($mode, get_current_user_id());
        if ($scope === 'disk') {
            // Disk-only scan: skip the attachments phase entirely.
            $job['phase'] = 'filesystem';
            $job['total'] = 0;
            $job['message'] = __('Scanning files on disk...', 'devdome-safe-media-cleaner');
        } else {
            $job['total'] = devdsame_total_attachments();
        }
        if (!$job['scan_id']) {
            return new WP_Error('devdsame_scan_failed', __('Could not create a scan session.', 'devdome-safe-media-cleaner'));
        }
    } elseif ($type === 'trash') {
        $job['total'] = count((array) ($args['item_ids'] ?? array()));
        $job['message'] = __('Cleaning...', 'devdome-safe-media-cleaner');
    } elseif ($type === 'restore') {
        $job['batch_id'] = (int) ($args['batch_id'] ?? 0);
        devdsame_reset_stale_claims($job['batch_id']); // no job runs at this point: a dead request's claims go back to the bin
        $job['total'] = (int) devdsame_batch_item_count($job['batch_id'], 'trashed');
    } elseif ($type === 'delete') {
        $job['batch_id'] = (int) ($args['batch_id'] ?? 0);
        devdsame_reset_stale_claims($job['batch_id']);
        $job['total'] = (int) devdsame_batch_item_count($job['batch_id'], 'trashed');
    } elseif ($type === 'backup') {
        // Library total = attachments; disk total = last scan's orphan count (the disk backup
        // saves ONLY orphan images — library files belong to the library backup).
        $job['total'] = $scope === 'disk' ? devdsame_get_int('orphan_count', 0) : devdsame_total_attachments();
        $job['message'] = __('Backing up...', 'devdome-safe-media-cleaner');
    } elseif ($type === 'backup_restore') {
        $entry = function_exists('devdsame_backup_get') ? devdsame_backup_get((string) ($args['backup_id'] ?? '')) : null;
        $job['total'] = $entry ? (int) $entry['files'] : 0;
        $job['message'] = __('Restoring backup...', 'devdome-safe-media-cleaner');
    }

    if (!devdsame_save_job($job) || !devdsame_get_job()) {
        return new WP_Error('devdsame_job_save_failed', __('The job could not be saved (database write failed); nothing was started.', 'devdome-safe-media-cleaner'));
    }
    devdsame_schedule_tick(1);
    return $job;
}

/** Schedule the next tick (single cron event). */
function devdsame_schedule_tick($delay = 1)
{
    if (!wp_next_scheduled('devdsame_run_job')) {
        wp_schedule_single_event(time() + max(1, (int) $delay), 'devdsame_run_job');
    }
    // WP-Cron only fires on page loads and the browser poller stops the moment the admin
    // leaves the tab: on a quiet site a clean sat half-done until someone came back. A
    // fire-and-forget loopback request to our own tick route keeps the job moving with no
    // browser open; the single-holder tick lock makes any overlap with the poller harmless.
    devdsame_spawn_tick();
}

/** Internal key that lets the loopback request call the tick route without a user session. */
function devdsame_tick_key()
{
    $key = get_option('devdsame_tick_key', '');
    if (!is_string($key) || strlen($key) < 32) {
        $key = wp_generate_password(48, false);
        update_option('devdsame_tick_key', $key, false);
    }
    return $key;
}

/**
 * Arm one loopback POST to our own tick route, sent at shutdown. At shutdown the tick lock
 * this request may hold is already released; a spawn from inside the tick would make the
 * child lose the lock and the chain die.
 */
function devdsame_spawn_tick()
{
    static $armed = false;
    if ($armed || !function_exists('rest_url')) {
        return;
    }
    $armed = true;
    register_shutdown_function('devdsame_spawn_tick_now');
}

/** Is a fresh (non-stale) tick lock held right now? Uncached: the holder is another request. */
function devdsame_tick_lock_held()
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberate cache bypass: the lock is written by a concurrent request.
    $held = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        devdsame_lock_key()
    ));
    return $held > 0 && (time() - $held) < 600;
}

/** Work still pending: a running job, or a cancel-rollback marker left with no job. */
function devdsame_work_pending()
{
    unset($GLOBALS['devdsame_cache']);
    $job = devdsame_get_job();
    return ($job && $job['status'] === 'running') || devdsame_pending_rollback() !== 0;
}

/** The loopback POST itself: once per request, and only while work is still pending. */
function devdsame_spawn_tick_now()
{
    static $sent = false;
    if ($sent) {
        return;
    }
    $sent = true;
    if (!devdsame_work_pending()) {
        return;
    }
    // 1s, not 0.01: behind a TLS proxy a 10ms timeout aborts during the handshake and the
    // request never reaches PHP. The tick route replies at once, so the spawning request is
    // held for a few ms, never the whole second.
    wp_remote_post(rest_url('devdsame/v1/tick'), array(
        'timeout'   => 1,
        'blocking'  => false,
        'sslverify' => false, // our own site; a self-signed or proxy certificate must not stop the runner.
        'headers'   => array('X-DevdSame-Tick' => devdsame_tick_key()),
        'body'      => '',
    ));
}

/**
 * One loopback tick: wait (at most a minute) for whoever holds the tick lock to let go, run
 * one tick, and arm the next loopback. Nobody is waiting on this request, so waiting here
 * costs nothing; giving up after a minute still re-arms the chain.
 */
function devdsame_internal_tick()
{
    $deadline = time() + 60;
    while (time() < $deadline) {
        if (!devdsame_work_pending()) {
            return;
        }
        if (devdsame_tick_lock_held()) {
            sleep(1);
            continue;
        }
        devdsame_run_tick();
        break;
    }
    devdsame_spawn_tick();
}

/** Pause / resume / cancel controls. */
function devdsame_pause_job()
{
    $job = devdsame_get_job();
    if ($job && $job['status'] === 'running') {
        $job['status'] = 'paused';
        $job['updated_at'] = time();
        return devdsame_save_job($job);
    }
    return false;
}
function devdsame_resume_job()
{
    $job = devdsame_get_job();
    if ($job && $job['status'] === 'paused') {
        $job['status'] = 'running';
        $job['updated_at'] = time();
        if (!devdsame_save_job($job)) {
            return false;
        }
        devdsame_schedule_tick(1);
        return true;
    }
    return false;
}
function devdsame_cancel_job()
{
    global $wpdb;
    $job = devdsame_get_job();
    // Cleaning is all-or-nothing: cancelling a trash job rolls back whatever it already
    // moved, so a cancel means "nothing happened" instead of a half-trashed library. The
    // rollback marker is persisted and read back BEFORE anything is cleared: a cancel that
    // could not record its rollback obligation is refused, and the job simply carries on.
    $rollback_batch = ($job && $job['type'] === 'trash' && !empty($job['batch_id'])) ? (int) $job['batch_id'] : 0;
    if ($rollback_batch) {
        update_option('devdsame_rollback_batch', (string) $rollback_batch, false);
        if (devdsame_pending_rollback() !== $rollback_batch) {
            return false;
        }
    }

    // Raise the DB-level cancel flag FIRST. An in-flight tick checks it after its chunk;
    // if the flag went up after we cleared the job, that tick would re-save the old job,
    // resurrect the cancelled clean AND block the rollback from starting (real race).
    update_option('devdsame_cancelled_at', (string) time(), false);

    // Name the job being cancelled: an in-flight tick compares its own id, so a cancel in the
    // job's first second is honoured and a cancel can never hit a job started a moment later.
    update_option('devdsame_cancelled_job', $job ? (string) ($job['id'] ?? '') : '', false);
    // Both flags must be on disk before the job store is cleared, or an in-flight tick keeps going.
    if ($job && (devdsame_job_cancelled($job) !== true || devdsame_cancelled_since() <= 0)) {
        return false;
    }
    if ($job) {
        // Mark an in-flight scan session cancelled so it never surfaces as "latest scan".
        if (!empty($job['scan_id']) && in_array($job['type'], array('scan', 'preview'), true)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table status update on cancel.
            $wpdb->update(
                $wpdb->prefix . 'devdsame_scans',
                array('status' => 'cancelled', 'finished_at' => current_time('mysql')),
                array('id' => (int) $job['scan_id']),
                array('%s', '%s'),
                array('%d')
            );
        }
        // Clear entirely — a cancelled job must never block or confuse the next start.
        devdsame_clear_job();
    }
    wp_clear_scheduled_hook('devdsame_run_job');
    // The lock is NOT released here: a tick may still be inside its chunk, and it releases the
    // lock itself when done (a dead holder is taken over after 10 minutes anyway). Releasing it
    // early let the next tick start beside the old one.

    if ($rollback_batch) {
        // All-or-nothing rollback as a PERSISTENT MARKER (written above), not a job: every
        // subsequent tick services it until the batch is verifiably empty. Immune to every
        // job-store race: even a straggler chunk landing later just gets swept by the next tick.
        devdsame_schedule_tick(1);
    }
    return true;
}

/** Uncached read of the pending cancel-rollback batch id (written by a concurrent request). */
function devdsame_pending_rollback()
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberate cache bypass: the marker is written by a concurrent request.
    $v = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        'devdsame_rollback_batch'
    ));
    if ($wpdb->last_error !== '') {
        return -1; // unknown: callers treat it as "something is pending" and never as "nothing to do"
    }
    return (int) $v;
}

/** Uncached: was THIS job (by id) cancelled by another request? Jobs without an id fall back to the time flag. */
function devdsame_job_cancelled($job)
{
    global $wpdb;
    if (!is_array($job)) {
        return false;
    }
    if (empty($job['id'])) {
        return devdsame_cancelled_since() > (int) ($job['created_at'] ?? 0);
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberate cache bypass: the marker is written by a concurrent request.
    $named = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'devdsame_cancelled_job'));
    if ($wpdb->last_error !== '') {
        return null; // unknown: chunk loops stop, the post-chunk check keeps the job and its progress for a retry
    }
    return $named !== '' && $named === (string) $job['id'];
}

/** Uncached read of the cancel flag (the cancel comes from a DIFFERENT request). */
function devdsame_cancelled_since()
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberate cache bypass: the flag is written by a concurrent request.
    $v = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        'devdsame_cancelled_at'
    ));
    return (int) $v;
}

/** Lock transient key + max lifetime (a tick can never legitimately run longer than this). */
function devdsame_lock_key()
{
    return 'devdsame_tick_lock';
}

/**
 * Acquire the single in-flight-tick lock. Atomic via add_option (false if the row already
 * exists), with a stale-lock takeover after the timeout so a crashed/timed-out tick can't
 * wedge the job forever.
 *
 * @return bool true if THIS caller now holds the lock.
 */
function devdsame_acquire_tick_lock()
{
    global $wpdb;
    $key = devdsame_lock_key();
    $now = time();
    $ttl = 600; // a tick is bounded; 10 min is a generous crash window.

    // The lock value is "<time>:<token>": the release only removes the lock this holder took, so a
    // tick that overran the stale window can no longer delete the lock a successor holds.
    $token = (string) $now . ':' . wp_generate_password(8, false, false);

    // Atomic insert: succeeds for exactly one caller when the option does not yet exist.
    if (add_option('devdsame_tick_lock', $token, '', 'no')) {
        $GLOBALS['devdsame_tick_token'] = $token;
        return true;
    }

    // Lock row exists — take it over only if it is stale.
    $raw = (string) get_option('devdsame_tick_lock', '');
    $held = (int) strtok($raw, ':');
    if ($held && ($now - $held) < $ttl) {
        return false; // a fresh tick is in flight.
    }
    // Stale: claim it atomically (only the UPDATE that matches the stale value wins the race).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic stale-lock takeover on the options table; values bound via prepare.
    $rows = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
        $token,
        $key,
        $raw
    ));
    if ($rows === 1) {
        wp_cache_delete($key, 'options');
        $GLOBALS['devdsame_tick_token'] = $token;
        return true;
    }
    return false;
}

/** Release the in-flight-tick lock, but only the one this request holds. */
function devdsame_release_tick_lock()
{
    global $wpdb;
    $token = isset($GLOBALS['devdsame_tick_token']) ? (string) $GLOBALS['devdsame_tick_token'] : '';
    unset($GLOBALS['devdsame_tick_token']);
    if ($token === '') {
        return; // this request never acquired it (a cancel, an activation): leave the holder's lock alone
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- conditional delete of our own lock row; values bound via prepare.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", devdsame_lock_key(), $token));
    wp_cache_delete(devdsame_lock_key(), 'options');
}

/**
 * Run ONE tick. Bounded by scan_chunk_size. Reschedules itself until the job completes.
 * Safe to call from cron or the REST poller — a single-holder lock guarantees that two
 * overlapping callers (the self-rescheduled cron event AND the 1.5s REST poller) never
 * process the same job slice concurrently. The second caller simply returns the current
 * progress snapshot and lets the in-flight tick finish.
 */
function devdsame_run_tick()
{
    $job = devdsame_get_job();
    // No running job normally means nothing to do — UNLESS a cancel-rollback is pending
    // (post-cancel state has an empty job store; the marker branch below must still run).
    if ((!$job || $job['status'] !== 'running') && !devdsame_pending_rollback()) {
        return $job;
    }

    // Mutex: only one tick may be in flight at a time. A losing caller no-ops.
    if (!devdsame_acquire_tick_lock()) {
        return $job;
    }

    try {
        // Re-read under the lock so we never act on a stale snapshot the other caller just
        // wrote. MUST bypass the per-request settings cache — this request may have cached
        // the whole table seconds ago, and acting on that stale job resurrects cancelled
        // cleans (the exact bug behind stranded half-trashed batches).
        unset($GLOBALS['devdsame_cache']);
        $job = devdsame_get_job();

        // A pending cancel-rollback outranks everything: while its batch still holds trashed
        // files, every tick restores a slice. The marker only clears once the batch is EMPTY,
        // so late stragglers from a racing chunk are swept too. All-or-nothing, guaranteed.
        $rb = devdsame_pending_rollback();
        if ($rb < 0) {
            devdsame_schedule_tick(5); // the marker could not be read: do nothing, try again shortly
            return $job;
        }
        if ($rb && (!$job || $job['status'] !== 'running')) {
            $rows = devdsame_batch_items_slice($rb, 'trashed', 200);
            if ($rows === null) {
                devdsame_schedule_tick(5); // failed read: keep the marker, try again shortly
                return $job;
            }
            if ($rows) {
                $ok = 0;
                foreach ($rows as $r) {
                    if (!is_wp_error(devdsame_restore_item((int) $r->id))) {
                        $ok++;
                    }
                }
                // Runaway guard: a slice where NOTHING restores (folder not writable, files
                // gone) would otherwise re-run forever now that the loopback chain drives
                // ticks with no browser to close. Three strikes, then stop and say so.
                $stalls = $ok > 0 ? 0 : (int) get_option('devdsame_rollback_stalls', 0) + 1;
                if ($stalls >= 3) {
                    delete_option('devdsame_rollback_batch');
                    delete_option('devdsame_rollback_stalls');
                    devdsame_record_error(
                        'rollback_stalled',
                        __('The cancelled cleanup could not be rolled back: the files could not be moved back (check folder permissions on /wp-content/uploads). They are still in the Recycle Bin and can be restored from there.', 'devdome-safe-media-cleaner'),
                        array('batch_id' => $rb, 'left' => count($rows))
                    );
                    return $job;
                }
                update_option('devdsame_rollback_stalls', $stalls, false);
                devdsame_schedule_tick(1);
            } else {
                delete_option('devdsame_rollback_stalls');
                delete_option('devdsame_rollback_batch');
                devdsame_finalize_restore_batch($rb);
                if (function_exists('devdsame_refresh_summary')) {
                    devdsame_refresh_summary();
                }
            }
            return $job;
        }

        if (!$job || $job['status'] !== 'running') {
            return $job;
        }

        @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running chunked job tick; each request processes one bounded slice.
        $chunk = max(25, min(1000, devdsame_get_int('scan_chunk_size', 200)));

        if ($job['type'] === 'scan' || $job['type'] === 'preview') {
            $job = devdsame_tick_scan($job, $chunk);
        } elseif ($job['type'] === 'trash') {
            $job = devdsame_tick_trash($job, $chunk);
        } elseif ($job['type'] === 'restore') {
            $job = devdsame_tick_restore($job, $chunk);
        } elseif ($job['type'] === 'delete') {
            $job = devdsame_tick_delete($job, $chunk);
        } elseif ($job['type'] === 'backup') {
            $job = devdsame_tick_backup($job, $chunk);
        } elseif ($job['type'] === 'backup_restore') {
            $job = devdsame_tick_backup_restore($job, $chunk);
        }

        // A cancel may have arrived from another request while this chunk was running — honour
        // it instead of resurrecting the job from our stale copy. The rollback marker (if any)
        // is serviced by the NEXT tick; schedule one so it starts without waiting for a poll.
        if (devdsame_job_cancelled($job) === true) { // null (read failed) keeps the job: the next tick re-checks before any item
            $stored = devdsame_get_job();
            if ($stored && (string) ($stored['id'] ?? '') === (string) ($job['id'] ?? '') && $stored['type'] === $job['type']) {
                devdsame_clear_job();
            }
            if (devdsame_pending_rollback()) {
                devdsame_schedule_tick(1);
            }
            return null;
        }

        $job['updated_at'] = time();
        devdsame_save_job($job);

        // A clean completion proves any earlier surfaced error is stale — stop showing it.
        if ($job['status'] === 'completed' && (int) $job['errors'] === 0 && function_exists('devdsame_clear_last_error')) {
            devdsame_clear_last_error();
        }

        // Surface a persistent "some files were skipped" notice once a destructive job finishes
        // with skip-on-error items, so the user can view details / export the log (spec error UX).
        if ($job['status'] === 'completed' && (int) $job['errors'] > 0
            && in_array($job['type'], array('trash', 'restore', 'delete'), true)) {
            devdsame_record_error(
                $job['type'] . '_skipped',
                sprintf(
                    /* translators: 1: count of skipped files, 2: job type. */
                    __('%1$d file(s) were skipped during the %2$s operation (already gone, locked, or became referenced). The rest completed.', 'devdome-safe-media-cleaner'),
                    (int) $job['errors'],
                    $job['type']
                ),
                array('processed' => (int) $job['processed'], 'errors' => (int) $job['errors'], 'batch_id' => (int) $job['batch_id'])
            );
        }

        if ($job['status'] === 'running') {
            devdsame_schedule_tick(1);
        }
    } finally {
        devdsame_release_tick_lock();
    }
    return $job;
}
add_action('devdsame_run_job', 'devdsame_run_tick');

/** Scan tick: attachments phase -> filesystem phase -> finalize. */
function devdsame_tick_scan($job, $chunk)
{
    $scope = isset($job['scope']) ? $job['scope'] : 'full';

    if ($job['phase'] === 'attachments') {
        $res = devdsame_scan_chunk($job['scan_id'], $job['cursor'], $chunk);
        if (!empty($res['error'])) {
            devdsame_fail_scan($job['scan_id'], (string) $res['error']);
            $job['status'] = 'error';
            $job['message'] = (string) $res['error'];
            return $job;
        }
        $job['cursor'] = $res['last_id'];
        $job['processed'] += $res['processed'];
        if ($res['done']) {
            if ($scope === 'library') {
                // Library-only scan: no disk pass.
                $job['phase'] = 'finalize';
                $job['message'] = __('Finishing up...', 'devdome-safe-media-cleaner');
            } else {
                $job['phase'] = 'filesystem';
                $job['cursor'] = 0;
                $job['message'] = __('Scanning files on disk...', 'devdome-safe-media-cleaner');
            }
        }
        return $job;
    }

    if ($job['phase'] === 'filesystem') {
        // Bounded, RESUMABLE filesystem pass: process up to a per-tick budget, persist the
        // directory cursor on the job, and stay in this phase until the whole uploads tree is
        // walked. This is what makes the orphan pass complete on 100,000+ file sites.
        // NOTE: file counts are tracked separately (fs_scanned) — they must NEVER inflate the
        // attachment progress counter (processed/total is the 843/843 the user sees).
        $budget = max(2000, $chunk * 20);
        $res = devdsame_scan_filesystem($job['scan_id'], $budget, (int) $job['cursor']);
        if (!empty($res['error'])) {
            devdsame_fail_scan($job['scan_id'], (string) $res['error']);
            $job['status'] = 'error';
            $job['message'] = (string) $res['error'];
            return $job;
        }
        $job['cursor'] = (int) $res['offset'];
        $job['fs_scanned'] = (int) (($job['fs_scanned'] ?? 0) + (int) $res['scanned']);
        $job['fs_bytes'] = (int) (($job['fs_bytes'] ?? 0) + (int) ($res['bytes'] ?? 0));
        if (!empty($res['done'])) {
            $job['phase'] = 'finalize';
            $job['message'] = __('Finishing up...', 'devdome-safe-media-cleaner');
        }
        return $job;
    }

    // finalize
    if ($scope !== 'library') {
        // Total image files walked on disk this scan — the Disk Cleaner's "Images on disk" tile.
        devdsame_update_setting('disk_images_scanned', (int) ($job['fs_scanned'] ?? 0));
        devdsame_update_setting('disk_images_bytes', (int) ($job['fs_bytes'] ?? 0));
    }
    if (devdsame_finalize_scan($job['scan_id']) === false) {
        $job['status'] = 'error';
        $job['message'] = __('The scan could not be finished: a database read failed. Nothing was changed; run the scan again.', 'devdome-safe-media-cleaner');
        return $job;
    }
    $job['status'] = 'completed';
    $job['message'] = __('Scan complete.', 'devdome-safe-media-cleaner');
    devdsame_update_setting('last_scan_id', (int) $job['scan_id']);
    devdsame_update_setting('last_scan_at', time());
    return $job;
}

/** Trash tick: move a slice of selected items to Recycle Bin. */
function devdsame_tick_trash($job, $chunk)
{
    $ids = (array) ($job['args']['item_ids'] ?? array());
    if (!$job['batch_id']) {
        // Force the last-second re-verification to rebuild the used-set FRESH for this cleanup
        // (never reuse a set the scan cached earlier in this same process — it could be stale).
        if (function_exists('devdsame_flush_used_set')) {
            devdsame_flush_used_set();
        }
        $job['batch_id'] = devdsame_create_trash_batch(get_current_user_id(), (string) ($job['args']['note'] ?? ''));
        if (!$job['batch_id']) {
            $health = function_exists('devdsame_filesystem_health') ? devdsame_filesystem_health() : array();
            $job['status'] = 'error';
            $job['message'] = empty($health['writable'])
                ? __('Recycle Bin is not writable. Check folder permissions on /wp-content/uploads, then retry.', 'devdome-safe-media-cleaner')
                : __('Could not create a Recycle Bin batch.', 'devdome-safe-media-cleaner');
            devdsame_record_error('trash_batch', $job['message'], array('writable' => !empty($health['writable']), 'free_bytes' => isset($health['free']) ? $health['free'] : null));
            return $job;
        }
        // Persist the batch id BEFORE the first move: a cancel arriving during this tick reads
        // the stored job, and without the id it could not schedule the all-or-nothing rollback.
        $job['updated_at'] = time();
        if (!devdsame_save_job($job)) {
            // Nothing has moved yet: stop here rather than clean with a batch id a cancel cannot see.
            $job['status'] = 'error';
            $job['message'] = __('The job state could not be saved (database write failed); nothing was moved.', 'devdome-safe-media-cleaner');
            devdsame_record_error('trash_batch', $job['message'], array('batch_id' => (int) $job['batch_id']));
            return $job;
        }
    }
    $slice = array_slice($ids, $job['cursor'], $chunk);
    foreach ($slice as $item_id) {
        // Uncached flag check per item: a cancel arriving MID-CHUNK stops the moves right
        // here, instead of this chunk quietly finishing 200 files behind the rollback.
        if (devdsame_job_cancelled($job) !== false) { // cancelled, or unknown: stop moving
            return $job;
        }
        $r = devdsame_trash_item($job['batch_id'], (int) $item_id);
        if (is_wp_error($r)) {
            $job['errors']++;
        }
        $job['processed']++;
        $job['cursor']++;
    }
    $job['message'] = sprintf(
        /* translators: 1: images cleaned so far, 2: total images. */
        __('Cleaning... (%1$s of %2$s)', 'devdome-safe-media-cleaner'),
        number_format_i18n((int) $job['processed']),
        number_format_i18n(count($ids))
    );
    if ($job['cursor'] >= count($ids)) {
        devdsame_finalize_trash_batch($job['batch_id']);
        if ((int) $job['errors'] >= count($ids)) {
            // Nothing moved at all: that is a failed cleanup, not a completed one.
            $job['status'] = 'error';
            $job['message'] = __('Nothing was moved: every selected file was skipped (protected, referenced again, already gone, or not movable). See the error log for details.', 'devdome-safe-media-cleaner');
            devdsame_record_error('trash_all_skipped', $job['message'], array('batch_id' => (int) $job['batch_id'], 'selected' => count($ids)));
            return $job;
        }
        $job['status'] = 'completed';
        $job['message'] = __('Moved to Recycle Bin.', 'devdome-safe-media-cleaner');
        if (function_exists('devdsame_metric_bump')) {
            devdsame_metric_bump('cleanups_used', 1);
        }
        if (function_exists('devdsame_refresh_summary')) {
            devdsame_refresh_summary();
        }
    }
    return $job;
}

/** Restore tick: move a slice of a batch's files back. */
function devdsame_tick_restore($job, $chunk)
{
    $rows = devdsame_batch_items_slice($job['batch_id'], 'trashed', $chunk);
    if ($rows === null) {
        $job['status'] = 'error';
        $job['message'] = __('The Recycle Bin could not be read (database error); nothing was changed. Retry the restore.', 'devdome-safe-media-cleaner');
        devdsame_record_error('restore_db', $job['message'], array('batch_id' => (int) $job['batch_id']));
        return $job;
    }
    if (!$rows) {
        // Safety net: a chunk that raced the cancel can land items a beat late, and a
        // transient failure can leave a straggler. Sweep up to 5 extra rounds before
        // declaring the batch restored — never leave a half-trashed tail behind.
        $sweeps = (int) ($job['sweeps'] ?? 0);
        // Ticks run one at a time under the lock, so any claim still present here belongs to a
        // tick that died: hand it back so the next slice can finish it.
        devdsame_reset_stale_claims((int) $job['batch_id']);
        $remaining = devdsame_batch_item_count((int) $job['batch_id'], 'trashed');
        if ($remaining === null || $remaining > 0) {
            if ($sweeps < 5) {
                $job['sweeps'] = $sweeps + 1;
                return $job; // stay running — next tick picks the stragglers up.
            }
            // Files are still in the bin (claimed by another request, or the count cannot be read):
            // that is not "restored".
            $job['status'] = 'error';
            $job['message'] = $remaining === null
                ? __('The Recycle Bin count could not be read; the batch may hold files still. Retry the restore.', 'devdome-safe-media-cleaner')
                : sprintf(
                    /* translators: %d: files still in the bin */
                    __('%d file(s) are still in the Recycle Bin (another request is working on them or they could not be moved). Retry the restore.', 'devdome-safe-media-cleaner'),
                    (int) $remaining
                );
            devdsame_record_error('restore_incomplete', $job['message'], array('batch_id' => (int) $job['batch_id'], 'left' => $remaining));
            return $job;
        }
        devdsame_finalize_restore_batch($job['batch_id']);
        $job['status'] = 'completed';
        $job['message'] = __('Restored from Recycle Bin.', 'devdome-safe-media-cleaner');
        if (function_exists('devdsame_metric_bump')) {
            devdsame_metric_bump('restores_used', 1);
        }
        return $job;
    }
    $job['sweeps'] = 0; // progress made — reset the straggler counter.
    $ok = 0;
    foreach ($rows as $r) {
        $res = devdsame_restore_item((int) $r->id);
        if (is_wp_error($res)) {
            $job['errors']++;
        } else {
            $ok++;
        }
        $job['processed']++;
    }
    // Runaway guard: failed rows stay 'trashed' and come straight back in the next slice, so a
    // slice with zero successes would re-run forever. Three strikes, then stop and say so.
    $job['stalls'] = $ok > 0 ? 0 : (int) ($job['stalls'] ?? 0) + 1;
    if ($job['stalls'] >= 3) {
        $job['status'] = 'error';
        $job['message'] = __('Could not move the files back (check folder permissions on /wp-content/uploads). They are still in the Recycle Bin; fix the permissions and retry.', 'devdome-safe-media-cleaner');
        devdsame_record_error('restore_stalled', $job['message'], array('batch_id' => (int) $job['batch_id'], 'left' => count($rows)));
        return $job;
    }
    $job['message'] = sprintf(
        /* translators: %s: files restored so far. */
        __('Undoing... (%s restored)', 'devdome-safe-media-cleaner'),
        number_format_i18n((int) $job['processed'])
    );
    return $job;
}

/** Delete tick: permanently delete a slice of a batch's trashed files. */
function devdsame_tick_delete($job, $chunk)
{
    $rows = devdsame_batch_items_slice($job['batch_id'], 'trashed', $chunk);
    if ($rows === null) {
        $job['status'] = 'error';
        $job['message'] = __('The Recycle Bin could not be read (database error); nothing was deleted. Retry the delete.', 'devdome-safe-media-cleaner');
        devdsame_record_error('delete_db', $job['message'], array('batch_id' => (int) $job['batch_id']));
        return $job;
    }
    if (!$rows) {
        devdsame_reset_stale_claims((int) $job['batch_id']); // a dead tick's claims come back under the lock
        $remaining = devdsame_batch_item_count((int) $job['batch_id'], 'trashed');
        if ($remaining === null || $remaining > 0) {
            $sweeps = (int) ($job['sweeps'] ?? 0);
            if ($sweeps < 5) {
                $job['sweeps'] = $sweeps + 1;
                return $job; // items claimed elsewhere or a failed count: look again next tick
            }
            $job['status'] = 'error';
            $job['message'] = $remaining === null
                ? __('The Recycle Bin count could not be read; the batch may hold files still. Retry the delete.', 'devdome-safe-media-cleaner')
                : sprintf(
                    /* translators: %d: files still in the bin */
                    __('%d file(s) are still in the Recycle Bin (another request is working on them). Retry the delete.', 'devdome-safe-media-cleaner'),
                    (int) $remaining
                );
            devdsame_record_error('delete_incomplete', $job['message'], array('batch_id' => (int) $job['batch_id'], 'left' => $remaining));
            return $job;
        }
        devdsame_finalize_delete_batch($job['batch_id']);
        $job['status'] = 'completed';
        $job['message'] = __('Permanently deleted.', 'devdome-safe-media-cleaner');
        if (function_exists('devdsame_refresh_summary')) {
            devdsame_refresh_summary();
        }
        return $job;
    }
    $ok = 0;
    foreach ($rows as $r) {
        // Permanent deletes are irreversible: a cancel arriving mid-chunk stops right here.
        if (devdsame_job_cancelled($job) !== false) { // cancelled, or unknown: never delete on a read that failed
            return $job;
        }
        $res = devdsame_permanent_delete_item((int) $r->id);
        if (is_wp_error($res)) {
            $job['errors']++;
        } else {
            $ok++;
        }
        $job['processed']++;
    }
    // Same runaway guard as the restore tick: a slice with zero successes must not loop forever.
    $job['stalls'] = $ok > 0 ? 0 : (int) ($job['stalls'] ?? 0) + 1;
    if ($job['stalls'] >= 3) {
        $job['status'] = 'error';
        $job['message'] = __('Could not delete the files (check folder permissions on /wp-content/uploads). They are still in the Recycle Bin; fix the permissions and retry.', 'devdome-safe-media-cleaner');
        devdsame_record_error('delete_stalled', $job['message'], array('batch_id' => (int) $job['batch_id'], 'left' => count($rows)));
        return $job;
    }
    return $job;
}

/** Progress snapshot for the poller. Percent is phase-aware and monotonic for scans. */
function devdsame_job_progress()
{
    $job = devdsame_get_job();
    if (!$job) {
        return array('active' => false, 'status' => 'idle');
    }
    $total = max(0, (int) $job['total']);
    $done = min((int) $job['processed'], $total > 0 ? $total : (int) $job['processed']);
    $is_scan = in_array($job['type'], array('scan', 'preview'), true);
    $message = (string) $job['message'];
    $show_counts = true;

    if ($is_scan) {
        $scope = isset($job['scope']) ? $job['scope'] : 'full';
        // Full scan: attachments fill 0-85%, the disk pass creeps 85-97%, finalize 98%,
        // completed 100%. Library-only: attachments fill 0-97%. Disk-only: creep 0-97%.
        if ($job['status'] === 'completed') {
            $pct = 100;
        } elseif ($job['phase'] === 'attachments') {
            $span = $scope === 'library' ? 97 : 85;
            $pct = $total > 0 ? (int) round($span * $done / $total) : 0;
            if ($message === '') {
                $message = __('Scanning images...', 'devdome-safe-media-cleaner');
            }
        } elseif ($job['phase'] === 'filesystem') {
            $fs = (int) ($job['fs_scanned'] ?? 0);
            $pct = $scope === 'disk'
                ? min(97, 5 + (int) floor($fs / 250))
                : min(97, 85 + (int) floor($fs / 500));
            $message = sprintf(
                /* translators: %s: number of files checked on disk. */
                __('Scanning files on disk... (%s checked)', 'devdome-safe-media-cleaner'),
                number_format_i18n($fs)
            );
            $show_counts = false; // disk files are not the 843-image count — never mix them.
        } else { // finalize
            $pct = 98;
            $show_counts = false;
        }
    } elseif ($job['type'] === 'trash') {
        // Message carries its own "(x of y)" — never let the JS double the counts.
        $show_counts = false;
        $pct = $total > 0 ? min(100, (int) round(100 * $done / $total)) : ($job['status'] === 'completed' ? 100 : 0);
    } elseif ($job['type'] === 'backup') {
        // Manifest walk creeps 1-10% (prep = files walked, saved live by the builder),
        // zipping fills 10-99%. Counts stay in the message — never doubled by the JS.
        $show_counts = false;
        if ($job['status'] === 'completed') {
            $pct = 100;
        } elseif (empty($job['manifest_ready'])) {
            $pct = min(10, 1 + (int) floor((int) ($job['prep'] ?? 0) / 400));
        } else {
            $pct = $total > 0 ? min(99, 10 + (int) round(89 * $done / $total)) : 10;
        }
    } else {
        $pct = $total > 0 ? min(100, (int) round(100 * $done / $total)) : ($job['status'] === 'completed' ? 100 : 0);
    }

    $eta = null;
    $elapsed = max(1, time() - (int) $job['created_at']);
    if ($done > 0 && $total > $done && $job['status'] === 'running' && (!$is_scan || $job['phase'] === 'attachments')) {
        $rate = $done / $elapsed;
        if ($rate > 0) {
            $eta = (int) round(($total - $done) / $rate);
        }
    }

    return array(
        'active'    => in_array($job['status'], array('running', 'paused'), true),
        'type'      => $job['type'],
        'scope'     => isset($job['scope']) ? $job['scope'] : 'full',
        'status'    => $job['status'],
        'phase'     => $job['phase'],
        'processed' => $show_counts ? $done : null,
        'total'     => $show_counts ? $total : null,
        'percent'   => $pct,
        'errors'    => (int) $job['errors'],
        'eta'       => $eta,
        'scan_id'   => (int) $job['scan_id'],
        'batch_id'  => (int) $job['batch_id'],
        'message'   => $message,
    );
}
