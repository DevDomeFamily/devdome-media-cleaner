<?php
/**
 * Page-builder reference extraction (the coverage that beats the competitors).
 *
 * Each builder stores its layout in post meta — usually JSON (Elementor, Bricks, Oxygen) or
 * a serialized blob (Beaver Builder) or shortcodes inside post_content (Divi, WPBakery). We
 * sweep the relevant meta keys, pull every image ID + URL out, and merge them into the
 * used-set. Builder presence is detected by the existence of its meta key so absent builders
 * add zero cost. Any blob we cannot decode is recorded so the scanner can mark those images
 * Uncertain (never Unused) rather than risk a false positive.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

/**
 * Merge page-builder references into the used-set.
 *
 * @param array  $ids      attachment-ID set (by ref)
 * @param string $haystack URL/path haystack (by ref)
 */
function devdsame_collect_builders(&$ids, &$haystack)
{
    global $wpdb;

    // Builder meta keys whose values are JSON / serialized layout blobs that hold image
    // IDs and URLs. Detected + swept generically: pull "id":N, "ids":[...], wp-image-N,
    // and any uploads URL/path. Divi & WPBakery layouts live in post_content (already
    // swept in references.php), but their template-builder layouts also use these keys.
    $meta_keys = array(
        '_elementor_data',          // Elementor (JSON)
        '_elementor_page_settings', // Elementor page bg etc.
        '_fl_builder_data',         // Beaver Builder (serialized)
        '_fl_builder_draft',
        '_bricks_page_content_2',   // Bricks (JSON)
        '_bricks_page_header_2',
        '_bricks_page_footer_2',
        'ct_builder_json',          // Oxygen (JSON)
        'ct_builder_shortcodes',    // Oxygen (shortcodes)
        '_themify_builder_settings_json',
        '_kad_blocks_meta',         // Kadence
        '_generateblocks_dynamic_css', // GenerateBlocks
        'panels_data',              // SiteOrigin Page Builder
        'sp_wpb_css',               // various
        '_et_pb_built_for_post_type', // Divi (presence marker)
        '_et_builder_settings',     // Divi global settings (background images)
    );

    $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

    $last = 0;
    $chunk = 500;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked sweep over core postmeta filtered to a fixed builder-key allowlist; all values bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key IN ($placeholders) AND meta_id > %d
             ORDER BY meta_id ASC LIMIT %d",
            array_merge($meta_keys, array($last, $chunk))
        ));
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $last = (int) $r->meta_id;
            $v = (string) $r->meta_value;
            if ($v === '') {
                continue;
            }
            devdsame_parse_builder_blob($v, $ids, $haystack);
        }
        unset($rows);
    } while (true);

    // Divi / WPBakery / Thrive / Spectra / SeedProd image references in post_content are
    // already part of the content sweep in references.php (URLs + shortcode ids), and their
    // background/gallery attrs there too. Thrive Architect stores in tve_updated_post_* meta:
    $tve = array('tve_updated_post', 'tve_updated_post_desktop', 'tve_updated_post_tablet', 'tve_updated_post_mobile');
    $tph = implode(',', array_fill(0, count($tve), '%s'));
    $last = 0;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked sweep over core postmeta filtered to a fixed Thrive-key allowlist; values bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key IN ($tph) AND meta_id > %d
             ORDER BY meta_id ASC LIMIT %d",
            array_merge($tve, array($last, 500))
        ));
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $last = (int) $r->meta_id;
            devdsame_parse_builder_blob((string) $r->meta_value, $ids, $haystack);
        }
        unset($rows);
    } while (true);
}

/**
 * Parse one builder blob (JSON, serialized, or raw shortcodes) for image IDs + uploads URLs.
 * Slashes are stripped first because Elementor stores escaped JSON in meta.
 */
function devdsame_parse_builder_blob($v, &$ids, &$haystack)
{
    // Elementor stores _elementor_data slash-escaped.
    $decoded = $v;
    if (strpos($v, '\\/') !== false || strpos($v, '\\"') !== false) {
        $decoded = wp_unslash($v);
    }

    $lv = strtolower($decoded);

    // Any uploads URL/path -> haystack.
    if (strpos($lv, 'uploads') !== false || strpos($lv, 'http') !== false) {
        $haystack .= "\n" . $lv;
    }

    // "id":N, "ids":[...], wp-image-N (covers Elementor image widgets, background_image.id,
    // gallery arrays, responsive image fields, Bricks/Oxygen settings).
    devdsame_collect_ids_from_content($decoded, $ids);

    // Builder-specific id keys: Elementor uses {"image":{"id":N,"url":"..."}} and
    // {"background_image":{"id":N}}; pull any "url":"...uploads..." too.
    if (preg_match_all('/"url"\s*:\s*"([^"]*uploads[^"]*)"/i', $decoded, $m)) {
        foreach ($m[1] as $u) {
            $haystack .= "\n" . strtolower(stripslashes($u));
        }
    }

    // Serialized Beaver Builder blobs hold ID lists too.
    if (strpos($lv, 'a:') === 0 || strpos($lv, 'o:') === 0) {
        if (preg_match_all('/(?:i:|s:\d+:")(\d{1,9})(?:"|;)/', $decoded, $mm)) {
            foreach ($mm[1] as $maybe) {
                $maybe = (int) $maybe;
                if ($maybe) {
                    $ids[$maybe] = true;
                }
            }
        }
    }
}

/**
 * Does this post use a page builder whose layout we may not fully decode? Used by the scanner
 * to mark attachments attached to such posts as Uncertain instead of Unused (the spec's "external
 * builder data is unreadable" case). We only flag genuinely opaque blobs (decode failure).
 *
 * @return bool true if at least one builder blob on the site failed to decode
 */
function devdsame_has_unreadable_builder_data()
{
    if (isset($GLOBALS['devdsame_builder_unreadable'])) {
        return (bool) $GLOBALS['devdsame_builder_unreadable'];
    }
    global $wpdb;
    $unreadable = false;
    // Cheap heuristic: an _elementor_data value that is non-empty but not valid JSON after
    // unslash signals an unreadable blob (corrupt/partial). We sample a handful.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded sample of a single fixed builder key; literal LIMIT.
    $rows = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value <> '' LIMIT 50");
    if ($rows) {
        foreach ($rows as $v) {
            $j = json_decode(wp_unslash((string) $v), true);
            if ($j === null && json_last_error() !== JSON_ERROR_NONE) {
                $unreadable = true;
                break;
            }
        }
    }
    $GLOBALS['devdsame_builder_unreadable'] = $unreadable;
    return $unreadable;
}
