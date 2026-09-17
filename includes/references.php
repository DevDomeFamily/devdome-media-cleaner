<?php
/**
 * Core reference index builder — the SAFETY heart.
 *
 * Builds a "used set" of attachment IDs + URL/path substrings referenced anywhere on the
 * site, then exposes devdsame_is_referenced() so the scanner can ask, per attachment,
 * "is this used?". The set is built ONCE per scan (cached in a process global keyed by the
 * scan id) and covers post content/excerpt across all post types incl. revisions + reusable
 * blocks + templates, featured images, gallery shortcodes, post/term/user meta, theme mods +
 * all customizer image settings, widgets (classic + block-based), nav menu items, site logo /
 * icon / header / background, and the options table (chunked). Page-builder + 3rd-party sources
 * are merged in from builders.php / integrations.php.
 *
 * URL/ID matching supports CDN domain mappings and is bidirectional for thumbnails: if an
 * original is referenced its thumbnails are protected, and vice-versa (the scanner resolves all
 * file variants per attachment and asks about every one).
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

/**
 * Get (building if necessary) the used-set for a scan.
 *
 * @return array{ids:array<int,bool>, urls:string[], strings:string} where 'strings' is one big
 *         lowercased haystack of all scanned text/meta/option content for substring URL/path matching.
 */
/**
 * Integers stored in a serialized or JSON blob, the way plugins keep attachment ids:
 * serialized (i:12; "12";) and JSON ([12,34], ["12"], {"image_id":12}). Fills $mm[1] like
 * preg_match_all would; over-collecting is the safe direction (an id that is no attachment
 * protects nothing).
 */
function devdsame_ids_in_blob($v, &$mm)
{
    $found = array();
    if (preg_match_all('/(?:i:|")(\d{1,9})(?:";|;)/', $v, $m1)) {
        $found = $m1[1];
    }
    $first = substr(ltrim($v), 0, 1);
    if (($first === '[' || $first === '{') && preg_match_all('/[\[,:]\s*"?(\d{1,9})"?\s*(?=[,\]}])/', $v, $m2)) {
        $found = array_merge($found, $m2[1]);
    }
    $mm = array(null, array_values(array_unique($found)));
    return !empty($found);
}

function devdsame_used_set($scan_id = 0)
{
    if (!isset($GLOBALS['devdsame_usedset']) || $GLOBALS['devdsame_usedset_scan'] !== $scan_id) {
        $GLOBALS['devdsame_usedset'] = devdsame_build_used_set();
        $GLOBALS['devdsame_usedset_scan'] = $scan_id;
    }
    return $GLOBALS['devdsame_usedset'];
}

/** Drop the cached used-set (call when a scan finishes to free memory). */
function devdsame_flush_used_set()
{
    unset($GLOBALS['devdsame_usedset'], $GLOBALS['devdsame_usedset_scan']);
}

/**
 * Hard byte ceiling for the in-memory URL/path haystack. Derived from memory_limit so we never
 * let the single concatenated string exhaust PHP memory on a media-heavy 100k+ site (the spec's
 * "Must Avoid: one huge ... query / loading all into memory"). When the haystack hits this cap we
 * stop appending and the set records 'truncated' => true; the scanner then treats items it cannot
 * prove unused as Uncertain (never auto-selected) instead of Unused — a safety downgrade, not a
 * false negative. Filterable for hosts that want to raise/lower it.
 */
function devdsame_haystack_cap()
{
    $limit = function_exists('wp_convert_hr_to_bytes') ? (int) wp_convert_hr_to_bytes((string) ini_get('memory_limit')) : 0;
    if ($limit <= 0) {
        // memory_limit = -1 (unlimited) or unreadable: use a sane absolute ceiling.
        $cap = 256 * MB_IN_BYTES;
    } else {
        // Spend at most ~12.5% of the memory budget on the haystack string.
        $cap = (int) max(16 * MB_IN_BYTES, floor($limit / 8));
    }
    return (int) apply_filters('devdsame_haystack_cap', $cap);
}

/** Append to the haystack only while under the cap; flip the truncation flag once it is hit. */
function devdsame_haystack_append(&$haystack, &$truncated, $cap, $chunk)
{
    if ($truncated) {
        return;
    }
    $haystack .= $chunk;
    if (strlen($haystack) >= $cap) {
        $truncated = true;
    }
}

/**
 * Build the used-set. Scans in chunks; everything funnels into:
 *   - $ids[(int)id] = true        (explicit attachment-ID references)
 *   - $haystack .= lowercased content   (for URL / relative-path substring matching)
 */
function devdsame_build_used_set()
{
    global $wpdb;

    $ids = array();
    $haystack = '';
    $cap = devdsame_haystack_cap();
    $truncated = false;
    $GLOBALS['devdsame_db_failed'] = '';
    $GLOBALS['devdsame_haystack_truncated'] = false;

    // ---- 1. Post content + excerpt across ALL post types incl. revisions, reusable blocks,
    //         templates and template parts. Chunked by ID range.
    $last = 0;
    $chunk = 400;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded chunked scan over core posts; ID bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
             WHERE post_status NOT IN ('trash','auto-draft') AND ID > %d
             ORDER BY ID ASC LIMIT %d",
            $last,
            $chunk
        ));
        if ($wpdb->last_error !== '') {
            devdsame_db_read_failed();
            break;
        }
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $last = (int) $r->ID;
            $content = (string) $r->post_content . ' ' . (string) $r->post_excerpt;
            if ($content === ' ') {
                continue;
            }
            // IDs are always collected (cheap, bounded); the haystack append respects the cap.
            devdsame_collect_ids_from_content($content, $ids);
            devdsame_haystack_append($haystack, $truncated, $cap, "\n" . strtolower($content));
        }
        unset($rows);
    } while (true);

    // ---- 2. Featured images (_thumbnail_id) + any meta value that is an attachment ID or a URL.
    devdsame_collect_meta($wpdb->postmeta, 'post_id', $ids, $haystack, $truncated, $cap);
    devdsame_collect_meta($wpdb->termmeta, 'term_id', $ids, $haystack, $truncated, $cap);
    devdsame_collect_meta($wpdb->usermeta, 'user_id', $ids, $haystack, $truncated, $cap);

    // ---- 3. Options table (chunked). Theme mods, widgets, site logo/icon, header/background,
    //         and arbitrary plugin/theme option blobs frequently hold image IDs/URLs.
    devdsame_collect_options($ids, $haystack, $truncated, $cap);

    // ---- 4. Site logo / icon / header / background / theme mods (explicit IDs).
    $logo = (int) get_theme_mod('custom_logo', 0);
    if ($logo) {
        $ids[$logo] = true;
    }
    $site_icon = (int) get_option('site_icon', 0);
    if ($site_icon) {
        $ids[$site_icon] = true;
    }
    $woo_placeholder = (int) get_option('woocommerce_placeholder_image', 0); // bare id, used on every product without an image
    if ($woo_placeholder) {
        $ids[$woo_placeholder] = true;
    }
    $header = get_custom_header();
    if ($header && !empty($header->attachment_id)) {
        $ids[(int) $header->attachment_id] = true;
    }

    // ---- 5. Nav menu items can reference attachment-backed images via menu-item meta; their
    //         object IDs are posts already covered, but custom menu images live in postmeta (#2).

    // ---- 6. Page-builder + third-party sources (Elementor/Divi/WooCommerce/ACF/SEO/...).
    if (function_exists('devdsame_collect_builders')) {
        devdsame_collect_builders($ids, $haystack);
    }
    if (function_exists('devdsame_collect_integrations')) {
        devdsame_collect_integrations($ids, $haystack);
    }

    // ---- 7. Pluggable: any plugin/theme can register its own used IDs or extra image URLs.
    $extra_ids = apply_filters('devdsame_used_attachment_ids', array());
    if (is_array($extra_ids)) {
        foreach ($extra_ids as $eid) {
            $eid = (int) $eid;
            if ($eid) {
                $ids[$eid] = true;
            }
        }
    }
    $extra_urls = apply_filters('devdsame_extra_image_urls', array());
    if (is_array($extra_urls) && $extra_urls) {
        devdsame_haystack_append($haystack, $truncated, $cap, "\n" . strtolower(implode("\n", array_map('strval', $extra_urls))));
    }

    return array(
        'ids'       => $ids,
        'haystack'  => $haystack,
        'truncated' => $truncated || !empty($GLOBALS['devdsame_db_failed']) || !empty($GLOBALS['devdsame_haystack_truncated']), // a failed read = an incomplete set: downgrade, never "unused"
    );
}

/**
 * Collect explicit attachment-ID references from a content string: [gallery ids="..."],
 * wp:image {"id":N}, data-id="N", and the classic wp-image-N class.
 */
function devdsame_collect_ids_from_content($content, &$ids)
{
    // [gallery ids="1,2,3"] and ids='1,2,3'
    if (stripos($content, '[gallery') !== false && preg_match_all('/\[gallery[^\]]*ids=["\']([0-9,\s]+)["\']/i', $content, $m)) {
        foreach ($m[1] as $list) {
            foreach (preg_split('/[,\s]+/', $list) as $id) {
                $id = (int) $id;
                if ($id) {
                    $ids[$id] = true;
                }
            }
        }
    }
    // Gutenberg image/gallery/media blocks: "id":N and "ids":[N,...]
    if (strpos($content, 'wp:') !== false || strpos($content, '"id"') !== false) {
        if (preg_match_all('/"id"\s*:\s*(\d+)/', $content, $m)) {
            foreach ($m[1] as $id) {
                $id = (int) $id;
                if ($id) {
                    $ids[$id] = true;
                }
            }
        }
        if (preg_match_all('/"ids"\s*:\s*\[([0-9,\s]+)\]/', $content, $m)) {
            foreach ($m[1] as $list) {
                foreach (preg_split('/[,\s]+/', $list) as $id) {
                    $id = (int) $id;
                    if ($id) {
                        $ids[$id] = true;
                    }
                }
            }
        }
    }
    // wp-image-123 class (classic editor / TinyMCE).
    if (preg_match_all('/wp-image-(\d+)/', $content, $m)) {
        foreach ($m[1] as $id) {
            $id = (int) $id;
            if ($id) {
                $ids[$id] = true;
            }
        }
    }
}

/**
 * Generic meta sweep: any meta value that is a plain integer is treated as a possible
 * attachment ID; any value containing the uploads URL/path is appended to the haystack.
 * Featured images (_thumbnail_id) are caught by the integer branch. Chunked by meta_id.
 */
function devdsame_collect_meta($table, $owner_col, &$ids, &$haystack, &$truncated = false, $cap = 0)
{
    global $wpdb;
    if ($cap <= 0) {
        $cap = devdsame_haystack_cap();
    }
    $base_url = devdsame_uploads_baseurl();
    $needle = strtolower(wp_basename($base_url)); // 'uploads'
    // usermeta's PK is umeta_id, every other core meta table uses meta_id.
    $pk = ($table === $wpdb->usermeta) ? 'umeta_id' : 'meta_id';
    // Attachments' own bookkeeping meta stores their own file path — feeding it into the
    // haystack would make EVERY image "reference itself" and nothing could ever be unused.
    $skip = ($table === $wpdb->postmeta)
        ? " AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes')"
        : '';
    $last = 0;
    $chunk = 1000;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked sweep over a core meta table named via internal constant; PK bound via allowlisted variable, bounds via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$pk} AS meta_pk, meta_value FROM {$table} WHERE {$pk} > %d{$skip} ORDER BY {$pk} ASC LIMIT %d",
            $last,
            $chunk
        ));
        if ($wpdb->last_error !== '') {
            devdsame_db_read_failed();
            break;
        }
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $last = (int) $r->meta_pk;
            $v = (string) $r->meta_value;
            if ($v === '') {
                continue;
            }
            // Plain attachment ID.
            if (ctype_digit($v)) {
                $id = (int) $v;
                if ($id) {
                    $ids[$id] = true;
                }
                continue;
            }
            $lv = strtolower($v);
            // Serialized arrays of IDs (ACF gallery, Woo gallery): pull out integers when the
            // value is a serialized list of ints.
            // Ids are collected even when the blob also carries a URL: {"image_id":12,"url":"..."} must keep 12.
            if (strpos($lv, 'a:') === 0 || strpos($lv, '[') === 0 || strpos($lv, '{') === 0) {
                if (devdsame_ids_in_blob($v, $mm)) {
                    foreach ($mm[1] as $maybe) {
                        $maybe = (int) $maybe;
                        if ($maybe) {
                            $ids[$maybe] = true;
                        }
                    }
                }
            }
            // URL / path references.
            if (strpos($lv, $needle) !== false || strpos($lv, '.jpg') !== false || strpos($lv, '.png') !== false
                || strpos($lv, '.jpeg') !== false || strpos($lv, '.gif') !== false || strpos($lv, '.webp') !== false
                || strpos($lv, '.svg') !== false || strpos($lv, '.avif') !== false) {
                devdsame_collect_ids_from_content($v, $ids);
                devdsame_haystack_append($haystack, $truncated, $cap, "\n" . $lv);
            }
        }
        unset($rows);
    } while (true);
}

/**
 * Options sweep. Autoloaded options are read in one cheap query (they are already loaded by WP),
 * then non-autoloaded options are chunked. Any value containing the uploads path is added to the
 * haystack; serialized image-ID lists contribute IDs.
 */
function devdsame_collect_options(&$ids, &$haystack, &$truncated = false, $cap = 0)
{
    global $wpdb;
    if ($cap <= 0) {
        $cap = devdsame_haystack_cap();
    }
    $needle = strtolower(wp_basename(devdsame_uploads_baseurl())); // the real uploads folder name (a custom UPLOADS dir is not "uploads")
    $last = 0;
    $chunk = 500;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked sweep over core options; option_id bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d ORDER BY option_id ASC LIMIT %d",
            $last,
            $chunk
        ));
        if ($wpdb->last_error !== '') {
            devdsame_db_read_failed();
            break;
        }
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $last = (int) $r->option_id;
            $v = (string) $r->option_value;
            if ($v === '') {
                continue;
            }
            $lv = strtolower($v);
            $has_url = strpos($lv, $needle) !== false;
            if ($has_url) {
                devdsame_collect_ids_from_content($v, $ids);
                devdsame_haystack_append($haystack, $truncated, $cap, "\n" . $lv);
            }
            // Serialized or JSON blobs (arrays included) are mined for ids on top of the URL pass
            // when the option looks image-related by its VALUE, its NAME, or the URL it carries.
            if (strpos($lv, 'a:') === 0 || strpos($lv, '[') === 0 || strpos($lv, '{') === 0) {
                // Possible theme-mod / customizer / widget array holding image IDs.
                // Serialized option blobs that look image-related (theme options, widgets, sliders)
                // often store bare attachment ids: collect every integer in them. Over-protective by
                // design (an id that is not an attachment protects nothing).
                $kw = '/logo|image|icon|thumb|media|attachment|photo|banner|background|avatar|gallery|slide|theme_mods|widget/';
                if (($has_url || preg_match($kw, $lv) || preg_match($kw, strtolower((string) $r->option_name))) && devdsame_ids_in_blob($v, $mm)) {
                    foreach ($mm[1] as $maybe) {
                        $maybe = (int) $maybe;
                        if ($maybe) {
                            $ids[$maybe] = true;
                        }
                    }
                }
            }
        }
        unset($rows);
    } while (true);
}

/**
 * The matcher used by the scanner. Returns true if ANY of the attachment's identifiers is
 * referenced: the attachment ID itself, or any of its file URLs / relative paths appearing
 * in the haystack (covers <img src>, srcset, picture, CSS url(), inline-style, data-src,
 * lazyload, builder JSON, options, meta — anything that stored a URL/path).
 *
 * @param array $set       result of devdsame_used_set()
 * @param int   $attach_id attachment ID
 * @param array $files     result of devdsame_attachment_files() {paths,urls,rel}
 * @param int   $matches   (out) number of distinct identifiers matched
 * @return bool
 */
function devdsame_is_referenced($set, $attach_id, $files, &$matches = 0)
{
    $matches = 0;
    $attach_id = (int) $attach_id;

    if ($attach_id && isset($set['ids'][$attach_id])) {
        $matches++;
    }

    $hay = $set['haystack'];
    if ($hay !== '') {
        // Test relative paths first (cheapest, dated path is unique). Bidirectional thumbnail
        // protection: $files['rel'] contains the original AND every size variant.
        foreach ($files['rel'] as $rel) {
            $rel = strtolower($rel);
            if ($rel !== '' && strpos($hay, $rel) !== false) {
                $matches++;
            }
        }
        // CDN-mapped + encoded variants (only if no plain-path hit, to keep it cheap).
        if ($matches === 0) {
            foreach ($files['rel'] as $rel) {
                foreach (devdsame_url_tokens($rel) as $tok) {
                    $tok = strtolower($tok);
                    if ($tok !== '' && strpos($hay, $tok) !== false) {
                        $matches++;
                        break 2;
                    }
                }
            }
        }
    }

    return $matches > 0;
}
