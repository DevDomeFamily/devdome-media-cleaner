<?php
/**
 * DevDome Tools: updates work when another system user owns the plugin folder (core 1.7.2, owner 2026-09-15).
 *
 * A DevDome plugin installed from a root shell (WP-CLI with --allow-root, an AI agent, a hosting script) keeps a
 * folder owned by root while PHP runs as the web server user. WordPress can read the plugin but cannot move or
 * delete it, so every update fails: "Retry update" in the DevDome dashboard, "Update failed" on the Plugins screen,
 * and an upload with "Replace current with uploaded" that keeps the old version.
 *
 * The web user can still rename entries inside a folder it owns (wp-content/plugins). So right before WordPress
 * backs up and replaces a DevDome plugin, the folder is copied into a web-owned copy, the copy is proved complete by
 * content fingerprint, the original is renamed to a hidden ".<slug>.stale-<time>" folder (WordPress skips dot
 * folders when it lists plugins, so nothing loads it) and the copy takes the original name. WordPress then keeps its
 * own backup and rollback as usual. A root-owned upgrade-temp-backup folder is renamed the same way; WordPress
 * recreates it.
 *
 * Runs only while a DevDome plugin is updated or replaced, only with the direct filesystem method, and only when
 * WordPress could not move and delete the folder itself. The hidden leftovers belong to root, so PHP cannot delete
 * them. Each one is recorded with its content fingerprint (option devdcorev1_heal_leftovers) so the DevDome Malware
 * Scanner recognises it by its files, never by its name. Every PHP file in a DevDome plugin exits without ABSPATH,
 * so a leftover reachable by URL runs nothing.
 */

defined('ABSPATH') || exit;

if (!function_exists('devdcorev1_heal_note_run')) {
    /** Why the last heal failed, as a plain sentence fragment for the error shown to the user. */
    function devdcorev1_heal_last_error($set = null)
    {
        static $error = '';
        if ($set !== null) {
            $error = (string) $set;
        }
        return $error;
    }

    /**
     * upgrader_package_options: a run that clears the destination (updates always, uploads only after "Replace current
     * with uploaded") is marked in its own hook_extra, which WordPress hands to every hook of that run. Nothing else
     * in the options changes.
     */
    function devdcorev1_heal_note_run($options)
    {
        if (is_array($options) && !empty($options['clear_destination'])) {
            if (!isset($options['hook_extra']) || !is_array($options['hook_extra'])) {
                $options['hook_extra'] = array();
            }
            $options['hook_extra']['devdcorev1_replacing'] = true;
        }
        return $options;
    }
    add_filter('upgrader_package_options', 'devdcorev1_heal_note_run', PHP_INT_MAX);

    /**
     * upgrader_source_selection runs after the package is unpacked and checked, and before WordPress moves the old
     * folder to upgrade-temp-backup and deletes it: the last moment the folders can be made writable.
     */
    function devdcorev1_heal_before_replace($source, $remote_source, $upgrader, $hook_extra)
    {
        $hook_extra = is_array($hook_extra) ? $hook_extra : array();
        if (is_wp_error($source) || empty($hook_extra['devdcorev1_replacing']) || !($upgrader instanceof Plugin_Upgrader) || !devdcorev1_heal_fs_ready()) {
            return $source;
        }
        $slugs = array(basename(untrailingslashit((string) $source)));
        if (!empty($hook_extra['plugin'])) {
            $slugs[] = dirname((string) $hook_extra['plugin']);
        }
        foreach (array_unique($slugs) as $slug) {
            if (!preg_match('/^devdome-[a-z0-9-]+$/', $slug)) {
                continue; // only DevDome plugin folders, never another plugin
            }
            devdcorev1_heal_last_error('');
            if (!empty($hook_extra['temp_backup']) && !devdcorev1_heal_temp_backup(WP_CONTENT_DIR, $slug)) {
                return new WP_Error('devdcorev1_heal_backup', 'WordPress cannot use its update backup folder (wp-content/upgrade-temp-backup): ' . devdcorev1_heal_last_error());
            }
            if (!devdcorev1_heal_dir(trailingslashit(WP_PLUGIN_DIR) . $slug)) {
                return new WP_Error('devdcorev1_heal_plugin', 'WordPress cannot replace this plugin: ' . devdcorev1_heal_last_error());
            }
        }
        return $source;
    }
    add_filter('upgrader_source_selection', 'devdcorev1_heal_before_replace', PHP_INT_MAX, 4);

    /** The heal uses the WordPress filesystem API with the direct method only (FTP or SSH methods act as another user). */
    function devdcorev1_heal_fs_ready()
    {
        global $wp_filesystem;
        return is_object($wp_filesystem) && isset($wp_filesystem->method) && $wp_filesystem->method === 'direct' && function_exists('copy_dir');
    }

    /**
     * True when WordPress can move and delete $path itself: every folder and file in the tree is writable, or belongs
     * to the web user so WordPress can chmod it (its clear_destination() does exactly that before deleting). A
     * symbolic link inside the tree is renamed and unlinked with its parent and is never followed.
     */
    function devdcorev1_heal_tree_writable($path)
    {
        global $wp_filesystem;
        if (!devdcorev1_heal_entry_ok($path)) {
            return false;
        }
        $list = $wp_filesystem->dirlist($path, true, false);
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $name => $entry) {
            $child = trailingslashit($path) . $name;
            if (is_link($child)) {
                continue;
            }
            if (isset($entry['type']) && $entry['type'] === 'd') {
                if (!devdcorev1_heal_tree_writable($child)) {
                    return false;
                }
            } elseif (!devdcorev1_heal_entry_ok($child)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Writable, or made writable the way WordPress does it before it deletes a plugin (clear_destination(): chmod to
     * the default mode, then check again). Only the owner can change a mode, so another user's entry stays unwritable.
     */
    function devdcorev1_heal_entry_ok($path)
    {
        global $wp_filesystem;
        if ($wp_filesystem->is_writable($path)) {
            return true;
        }
        $wp_filesystem->chmod($path, $wp_filesystem->is_dir($path) ? FS_CHMOD_DIR : FS_CHMOD_FILE);
        return $wp_filesystem->is_writable($path);
    }

    /**
     * Make $dir web-owned: copy it next to itself, prove the copy by content fingerprint, rename the original to a
     * hidden stale name and give the copy the original name. True when nothing needed healing or the heal worked.
     * False leaves $dir as it was and sets devdcorev1_heal_last_error().
     */
    /**
     * Best effort: deny web access to a displaced tree (Apache 2.2 and 2.4 syntax) and add a listing guard. True when
     * the deny rule is in place. The displaced tree is the root-owned original, so in the very case the heal exists
     * for the write usually FAILS: the outcome is recorded with the leftover and the scanner's leftover finding tells
     * the owner to remove the folder from a shell (Codex full round 5). Containment by code is not possible without
     * permission to write into that folder.
     */
    function devdcorev1_heal_deny_web($dir)
    {
        global $wp_filesystem;
        if (!$wp_filesystem->is_dir($dir) || is_link(untrailingslashit($dir)) || is_link($dir . '/.htaccess') || is_link($dir . '/index.php')) {
            devdcorev1_heal_leftover_protected(false);
            return false; // a linked folder or a linked guard file is never written through (Codex full round 8)
        }
        $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
        $ok    = false;
        if (!$wp_filesystem->exists($dir . '/.htaccess') || $wp_filesystem->is_writable($dir . '/.htaccess')) {
            $ok = (bool) $wp_filesystem->put_contents($dir . '/.htaccess', $rules, FS_CHMOD_FILE);
        }
        if (!$wp_filesystem->exists($dir . '/index.php')) {
            $wp_filesystem->put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE);
        }
        $ok = $ok && $wp_filesystem->exists($dir . '/.htaccess');
        devdcorev1_heal_leftover_protected($ok);
        return $ok;
    }

    /**
     * Rename a root-owned tree out of the way: the leftover is recorded and read back FIRST (no record, no rename), then
     * the tree is moved, denied web access (best effort) and its record refreshed with the outcome. False (with the
     * error set) when the record or the rename failed; a rename that failed after the record forgets it again.
     */
    function devdcorev1_heal_displace($from, $stale)
    {
        global $wp_filesystem;
        devdcorev1_heal_leftover_protected(false);
        if (!devdcorev1_heal_record_leftover($stale, devdcorev1_heal_tree_hash($from))) {
            devdcorev1_heal_last_error('the database did not accept the record of ' . basename($from) . ', so it was left as it is.');
            return false;
        }
        if (!$wp_filesystem->move($from, $stale)) {
            devdcorev1_heal_record_forget($stale);
            devdcorev1_heal_last_error('another system user owns ' . basename($from) . ' and it could not be renamed.');
            return false;
        }
        devdcorev1_heal_deny_web($stale);
        devdcorev1_heal_record_leftover($stale, devdcorev1_heal_tree_hash($stale)); // refresh: final fingerprint + protection outcome
        return true;
    }

    /** Drop the record of a leftover that was never created (the rename failed after the record was written). */
    function devdcorev1_heal_record_forget($stale)
    {
        $record = get_option('devdcorev1_heal_leftovers', array());
        if (is_array($record) && isset($record[basename($stale)])) {
            unset($record[basename($stale)]);
            update_option('devdcorev1_heal_leftovers', $record, false);
        }
    }

    /** Whether the LAST displaced tree got its deny rule; read by devdcorev1_heal_record_leftover(). */
    function devdcorev1_heal_leftover_protected($set = null)
    {
        static $protected = true;
        if ($set !== null) {
            $protected = (bool) $set;
        }
        return $protected;
    }

    function devdcorev1_heal_dir($dir)
    {
        global $wp_filesystem;
        // The link check comes FIRST: the writability probe chmods through the path, and through a symbolic link
        // that would change permissions outside the plugins tree (Codex full round 4).
        if (is_link(untrailingslashit($dir)) || is_link(dirname(untrailingslashit($dir))) || is_link(dirname(dirname(untrailingslashit($dir))))) {
            devdcorev1_heal_last_error('another system user owns the plugin folder and the folder or its parent is a symbolic link, which is never probed or replaced.'); // parents too (Codex full round 7)
            return false;
        }
        if (!$wp_filesystem->is_dir($dir) || devdcorev1_heal_tree_writable($dir)) {
            return true;
        }
        $parent = dirname($dir);
        if (!$wp_filesystem->is_writable($parent)) {
            devdcorev1_heal_last_error('another system user owns the plugin folder and the web server user cannot write to ' . basename($parent) . '.');
            return false;
        }
        $hash = devdcorev1_heal_tree_hash($dir);
        if ($hash === '') {
            devdcorev1_heal_last_error('another system user owns the plugin folder and it holds a symbolic link, an unreadable file or too many files to copy safely.');
            return false;
        }
        $stamp = gmdate('YmdHis') . '-' . wp_rand(1000, 9999);
        $copy  = $parent . '/.' . basename($dir) . '.heal-' . $stamp;
        $stale = $parent . '/.' . basename($dir) . '.stale-' . $stamp;
        if (!$wp_filesystem->mkdir($copy, FS_CHMOD_DIR)) {
            devdcorev1_heal_last_error('another system user owns the plugin folder and a copy could not be created next to it.');
            return false;
        }
        if (true !== copy_dir($dir, $copy) || devdcorev1_heal_tree_hash($copy) !== $hash) {
            $wp_filesystem->delete($copy, true);
            devdcorev1_heal_last_error('another system user owns the plugin folder and a complete copy could not be made (disk space or file permissions).');
            return false;
        }
        if (devdcorev1_heal_tree_writable($dir)) { // another update replaced the folder while the copy was made
            $wp_filesystem->delete($copy, true);
            return true;
        }
        // The leftover is recorded (and the write read back) BEFORE the rename (Codex full round 10): a tree the
        // record cannot hold is never displaced, so nothing reachable is ever untracked.
        devdcorev1_heal_leftover_protected(false);
        if (!devdcorev1_heal_record_leftover($stale, $hash)) {
            $wp_filesystem->delete($copy, true);
            devdcorev1_heal_last_error('the database did not accept the record of the old copy, so the folder was left as it is.');
            return false;
        }
        if (!$wp_filesystem->move($dir, $stale)) {
            $wp_filesystem->delete($copy, true);
            devdcorev1_heal_record_forget($stale);
            devdcorev1_heal_last_error('another system user owns the plugin folder and it could not be renamed.');
            return false;
        }
        if (!$wp_filesystem->move($copy, $dir)) {
            if ($wp_filesystem->move($stale, $dir)) { // the original is back under its name
                devdcorev1_heal_record_forget($stale);
                $wp_filesystem->delete($copy, true);
                devdcorev1_heal_last_error('another system user owns the plugin folder and the repaired copy could not be renamed.');
                return false;
            }
            devdcorev1_heal_last_error('the plugin folder is now ' . basename($stale) . ' and a complete web-owned copy is ' . basename($copy) . ', both in wp-content/plugins. Rename either one back to ' . basename($dir) . '.');
            return false;
        }
        if (function_exists('wp_opcache_invalidate_directory')) {
            wp_opcache_invalidate_directory($dir);
        }
        // The displaced tree is root-owned (that is why it could not be replaced in place), so it cannot be deleted;
        // it is at least made unreachable over the web where the server honours .htaccess, and a guard index.php
        // stops directory listings elsewhere (Codex full round 4). The scanner lists it as a leftover by fingerprint.
        devdcorev1_heal_deny_web($stale);
        // The fingerprint is taken AFTER the protection attempt, whatever it achieved (Codex full rounds 5 and 6): the
        // recorded leftover must match the tree as it now is, or the scanner would report its own leftover as foreign.
        // The record itself was written and read back before the rename; this refresh adds the outcome.
        $final = devdcorev1_heal_tree_hash($stale);
        if ($final !== '') {
            $hash = $final;
        }
        devdcorev1_heal_record_leftover($stale, $hash);
        return true;
    }

    /**
     * Content fingerprint of a folder: sha256 over "<relative path>\0<sha256 of the file>\n" for every file, sorted
     * by path in byte order. '' when the folder is or holds a symbolic link, holds an unreadable file, more than 5000
     * entries or more than 256 MB. Only a Windows separator is normalised, a backslash in a Unix file name stays what
     * it is. The DevDome Malware Scanner computes the same value (scanner/inventory.php): keep both in step.
     */
    function devdcorev1_heal_tree_hash($dir)
    {
        $dir   = untrailingslashit((string) $dir);
        $lines = array();
        $count = 0;
        $bytes = 0;
        if (is_link($dir)) {
            return '';
        }
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $entry) {
                if (++$count > 5000 || $entry->isLink()) {
                    return '';
                }
                if (!$entry->isFile()) {
                    continue;
                }
                $bytes += (int) $entry->getSize();
                $sum    = ($bytes <= 268435456 && $entry->isReadable()) ? hash_file('sha256', $entry->getPathname()) : false;
                if (!is_string($sum)) {
                    return '';
                }
                $rel = substr($entry->getPathname(), strlen($dir) + 1);
                if (DIRECTORY_SEPARATOR === '\\') {
                    $rel = str_replace('\\', '/', $rel);
                }
                $lines[] = $rel . "\0" . $sum . "\n";
            }
        } catch (Exception $e) {
            return '';
        }
        sort($lines, SORT_STRING);
        return hash('sha256', implode('', $lines));
    }

    /** Remember a leftover folder by name with its fingerprint; entries whose folder is gone are dropped. */
    function devdcorev1_heal_record_leftover($stale, $hash)
    {
        // A tree that could not be fingerprinted (a link inside, an unreadable file, too big) is recorded with an
        // empty hash: it is still a displaced, possibly reachable copy the scanner must list (Codex full round 9).
        $hash   = is_string($hash) && strlen($hash) === 64 ? $hash : '';
        $parent = dirname($stale);
        $record = get_option('devdcorev1_heal_leftovers', array());
        $record = is_array($record) ? $record : array();
        foreach ($record as $name => $entry) {
            // Each entry is pruned against ITS OWN folder (Codex full round 8): a backup-tree leftover under wp-content
            // used to drop every plugin-folder record. Entries without a folder (1.3.3) are assumed to be plugin-folder ones.
            $dir = is_array($entry) && !empty($entry['dir']) ? (string) $entry['dir'] : $parent;
            if (!is_dir($dir . '/' . $name)) {
                unset($record[$name]);
            }
        }
        $record[basename($stale)] = array('hash' => $hash, 'at' => time(), 'protected' => devdcorev1_heal_leftover_protected(), 'dir' => $parent);
        // Nothing that still exists on disk is ever dropped (the prune above removes only vanished folders, there is no
        // cap: Codex full round 10), and the write is read back; a leftover the record does not hold goes to a second,
        // plain list so the scanner still reports it (Codex full round 9).
        update_option('devdcorev1_heal_leftovers', $record, false);
        wp_cache_delete('devdcorev1_heal_leftovers', 'options');
        $back = get_option('devdcorev1_heal_leftovers', array());
        if (!is_array($back) || !isset($back[basename($stale)])) {
            $untracked   = get_option('devdcorev1_heal_untracked', array());
            $untracked   = is_array($untracked) ? $untracked : array();
            $untracked[] = (string) $stale;
            update_option('devdcorev1_heal_untracked', array_values(array_unique($untracked)), false);
            wp_cache_delete('devdcorev1_heal_untracked', 'options');
            $back2 = get_option('devdcorev1_heal_untracked', array());
            return is_array($back2) && in_array((string) $stale, $back2, true); // true only when SOME durable record exists
        }
        return true;
    }

    /**
     * WordPress moves the old plugin into wp-content/upgrade-temp-backup/plugins/<slug> before it installs the new one.
     * A root-owned backup folder (or a root-owned leftover backup of this plugin) is renamed to a hidden stale name so
     * WordPress can create a fresh one. True when the backup folder is usable; false sets devdcorev1_heal_last_error().
     */
    function devdcorev1_heal_temp_backup($content_dir, $slug)
    {
        global $wp_filesystem;
        $stamp = gmdate('YmdHis') . '-' . wp_rand(1000, 9999);
        $base  = untrailingslashit($content_dir) . '/upgrade-temp-backup';
        $sub   = $base . '/plugins';
        // Links are refused before ANY probe or rename (Codex full rounds 5 and 6): a linked backup folder would
        // redirect the rename or the chmod probe outside the tree.
        if (is_link(untrailingslashit($content_dir)) || is_link($base) || is_link($sub) || is_link($sub . '/' . $slug)) {
            devdcorev1_heal_last_error('the upgrade backup folder or the old backup inside it is a symbolic link, which is never probed or replaced.');
            return false;
        }
        if (!($wp_filesystem->is_dir($sub) && $wp_filesystem->is_writable($sub))) {
            if (!$wp_filesystem->is_dir($base)) {
                if (!$wp_filesystem->is_writable($content_dir)) {
                    devdcorev1_heal_last_error('the web server user cannot write to wp-content, so the folder cannot be created.');
                    return false;
                }
            } elseif (!$wp_filesystem->is_writable($base)) {
                $stale_base = untrailingslashit($content_dir) . '/.upgrade-temp-backup.stale-' . $stamp;
                if (!devdcorev1_heal_displace($base, $stale_base)) { // the whole backup tree, other plugins' backups included (Codex full rounds 7 and 10)
                    return false;
                }
            } elseif ($wp_filesystem->is_dir($sub)) {
                if (!devdcorev1_heal_displace($sub, $base . '/.plugins.stale-' . $stamp)) {
                    return false;
                }
            }
            return true; // WordPress creates upgrade-temp-backup/plugins itself
        }
        $old = $sub . '/' . $slug;
        if ($wp_filesystem->is_dir($old) && !devdcorev1_heal_tree_writable($old)) {
            if (!devdcorev1_heal_displace($old, $sub . '/.' . $slug . '.stale-' . $stamp)) { // recorded first, then moved (Codex full rounds 8 and 10)
                return false;
            }
        }
        return true;
    }
}
