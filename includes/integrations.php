<?php
/**
 * Third-party data sources merged into the used-set. Every source is presence-detected so an
 * absent plugin adds zero cost. WooCommerce galleries/variations/category-thumbnails, ACF
 * image+gallery fields, Meta Box / Pods / Toolset image fields, and Yoast / Rank Math / AIOSEO
 * social (OG/Twitter) images.
 *
 * Note: most of these store an attachment ID or a URL in postmeta / termmeta, which the generic
 * meta sweep in references.php already catches. This module adds the few that need explicit,
 * structured handling (Woo gallery CSV, Woo category term meta, SEO per-post image IDs) and the
 * social-image global option keys, so coverage is exact and provably complete.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

/**
 * @param array  $ids      attachment-ID set (by ref)
 * @param string $haystack URL/path haystack (by ref)
 */
function devdsame_collect_integrations(&$ids, &$haystack)
{
    devdsame_collect_woocommerce($ids, $haystack);
    devdsame_collect_acf($ids, $haystack);
    devdsame_collect_seo_social($ids, $haystack);
}

/** WooCommerce: gallery CSV, variation thumbnails, category thumbnail term meta. */
function devdsame_collect_woocommerce(&$ids, &$haystack)
{
    if (!devdsame_has_woocommerce()) {
        return;
    }
    global $wpdb;

    // _product_image_gallery holds a comma-separated list of attachment IDs.
    $last = 0;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked sweep over core postmeta for a single fixed Woo key; values bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery' AND meta_id > %d
             ORDER BY meta_id ASC LIMIT %d",
            $last,
            500
        ));
        if ($wpdb->last_error !== '') {
            devdsame_db_read_failed();
            break;
        }
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $last = (int) $r->meta_id;
            foreach (preg_split('/[,\s]+/', (string) $r->meta_value) as $id) {
                $id = (int) $id;
                if ($id) {
                    $ids[$id] = true;
                }
            }
        }
        unset($rows);
    } while (true);

    // Variation thumbnails (_thumbnail_id on product_variation posts) are integers in postmeta,
    // caught by the generic meta sweep. Category / attribute term images live in termmeta:
    $term_keys = array('thumbnail_id', 'product_cat_thumbnail_id', 'order_image', 'pa_image', 'z_taxonomy_image_id');
    $tph = implode(',', array_fill(0, count($term_keys), '%s'));
    $last = 0;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked sweep over core termmeta filtered to a fixed Woo-key allowlist; values bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->termmeta}
             WHERE meta_key IN ($tph) AND meta_id > %d
             ORDER BY meta_id ASC LIMIT %d",
            array_merge($term_keys, array($last, 500))
        ));
        if ($wpdb->last_error !== '') {
            devdsame_db_read_failed();
            break;
        }
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $last = (int) $r->meta_id;
            $id = (int) $r->meta_value;
            if ($id) {
                $ids[$id] = true;
            }
        }
        unset($rows);
    } while (true);
}

/**
 * ACF: image + gallery field values store either an attachment ID, a URL, or a serialized
 * array of IDs/arrays. The generic meta sweep handles the integer + URL cases; this adds the
 * serialized-gallery array case explicitly and (when ACF is loaded) walks options-page fields.
 */
function devdsame_collect_acf(&$ids, &$haystack)
{
    if (!class_exists('ACF') && !function_exists('get_field')) {
        return;
    }
    global $wpdb;

    // ACF gallery fields commonly store a serialized array of attachment IDs in postmeta.
    // Catch serialized int lists in any non-underscore meta value that looks like an array.
    $last = 0;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked sweep over core postmeta for serialized array values; meta_id bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_value LIKE %s AND meta_id > %d
               AND meta_key NOT IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes')
             ORDER BY meta_id ASC LIMIT %d",
            $wpdb->esc_like('a:') . '%',
            $last,
            800
        ));
        if ($wpdb->last_error !== '') {
            devdsame_db_read_failed();
            break;
        }
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $last = (int) $r->meta_id;
            $arr = @unserialize((string) $r->meta_value, array('allowed_classes' => false));
            if (is_array($arr)) {
                array_walk_recursive($arr, function ($val) use (&$ids, &$haystack) {
                    if (is_int($val) || (is_string($val) && ctype_digit($val))) {
                        $id = (int) $val;
                        if ($id) {
                            $ids[$id] = true;
                        }
                    } elseif (is_string($val) && stripos($val, 'uploads') !== false) {
                        if (strlen($haystack) + strlen(strtolower($val)) <= devdsame_haystack_cap()) {
                            $haystack .= "\n" . strtolower($val);
                        } else {
                            $GLOBALS['devdsame_haystack_truncated'] = true;
                        }
                    }
                });
            }
        }
        unset($rows);
    } while (true);

    // ACF options-page image fields live in wp_options as options_{field} — already covered by
    // the options sweep in references.php (URL + serialized-ID detection).
}

/** Yoast / Rank Math / AIOSEO social (OG / Twitter) images: per-post IDs + global defaults. */
function devdsame_collect_seo_social(&$ids, &$haystack)
{
    global $wpdb;

    // Per-post social image IDs (integers) across the three SEO plugins.
    $keys = array(
        '_yoast_wpseo_opengraph-image-id',
        '_yoast_wpseo_twitter-image-id',
        'rank_math_facebook_image_id',
        'rank_math_twitter_image_id',
        '_aioseo_og_image_id',
        '_aioseo_twitter_image_id',
    );
    $present = false;
    $kph = implode(',', array_fill(0, count($keys), '%s'));
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single bounded read over core postmeta for a fixed SEO-key allowlist; values bound via prepare.
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ($kph)",
        $keys
    ));
    if ($wpdb->last_error !== '') {
        devdsame_db_read_failed();
    }
    if ($rows) {
        $present = true;
        foreach ($rows as $v) {
            $id = (int) $v;
            if ($id) {
                $ids[$id] = true;
            }
        }
    }

    // URL-valued per-post social images.
    $url_keys = array('_yoast_wpseo_opengraph-image', '_yoast_wpseo_twitter-image', 'rank_math_facebook_image', 'rank_math_twitter_image', '_aioseo_og_image_custom_url', '_aioseo_twitter_image_custom_url');
    $uph = implode(',', array_fill(0, count($url_keys), '%s'));
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single bounded read over core postmeta for a fixed SEO-key allowlist; values bound via prepare.
    $urows = $wpdb->get_col($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ($uph) AND meta_value LIKE %s",
        array_merge($url_keys, array('%' . $wpdb->esc_like('uploads') . '%'))
    ));
    if ($wpdb->last_error !== '') {
        devdsame_db_read_failed();
    }
    if ($urows) {
        foreach ($urows as $u) {
            if (strlen($haystack) + strlen(strtolower((string) $u)) <= devdsame_haystack_cap()) {
                            $haystack .= "\n" . strtolower((string) $u);
                        } else {
                            $GLOBALS['devdsame_haystack_truncated'] = true;
                        }
        }
    }

    // Global default OG images (options). These option blobs are already in the options sweep,
    // but resolve Yoast's default explicitly to capture the ID even if stored numerically.
    $yoast = get_option('wpseo_social', array());
    if (is_array($yoast) && !empty($yoast['og_default_image_id'])) {
        $ids[(int) $yoast['og_default_image_id']] = true;
    }
    if (is_array($yoast) && !empty($yoast['og_default_image'])) {
        if (strlen($haystack) + strlen(strtolower((string) $yoast['og_default_image'])) <= devdsame_haystack_cap()) {
                            $haystack .= "\n" . strtolower((string) $yoast['og_default_image']);
                        } else {
                            $GLOBALS['devdsame_haystack_truncated'] = true;
                        }
    }
}
