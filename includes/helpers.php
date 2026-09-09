<?php
/**
 * Shared utilities: uploads paths, URL<->path normalization (full / relative /
 * protocol-relative / http+https / encoded / CDN-mapped), size-variant URL/path
 * resolution (all registered intermediate sizes + scaled/-rotated/-edited variants),
 * filesize formatting, reason-code + confidence labels, and a path-traversal-safe
 * realpath validator (every target must resolve inside the uploads basedir).
 */

defined('ABSPATH') || exit;

/** Cached uploads base dir (absolute path, no trailing slash variance). */
function devdsame_uploads_basedir()
{
    static $base = null;
    if ($base === null) {
        $up = wp_get_upload_dir();
        $base = untrailingslashit($up['basedir']);
    }
    return $base;
}

/** Cached uploads base URL. */
function devdsame_uploads_baseurl()
{
    static $url = null;
    if ($url === null) {
        $up = wp_get_upload_dir();
        $url = untrailingslashit($up['baseurl']);
    }
    return $url;
}

/**
 * Resolve an attachment to every physical file it owns: the original (or -scaled),
 * the unscaled original (original_image), and every registered intermediate size, plus
 * -rotated / edited (-eTIMESTAMP) derivatives discoverable from the metadata.
 *
 * @return array{paths:string[], urls:string[], rel:string[]}
 */
function devdsame_attachment_files($attachment_id)
{
    $paths = array();
    $urls  = array();
    $rel   = array();

    $main = get_attached_file($attachment_id);
    if ($main) {
        $paths[] = $main;
    }

    $meta = wp_get_attachment_metadata($attachment_id);
    $basedir = devdsame_uploads_basedir();

    // The directory the file lives in (relative to uploads), e.g. "2026/06".
    $subdir = '';
    if (!empty($meta['file'])) {
        $subdir = trailingslashit(dirname($meta['file']));
        if ($subdir === './') {
            $subdir = '';
        }
        $paths[] = $basedir . '/' . ltrim($meta['file'], '/');
    }

    // Unscaled original (WP 5.3+ -scaled handling).
    if (!empty($meta['original_image']) && $subdir !== '') {
        $paths[] = $basedir . '/' . $subdir . $meta['original_image'];
    }

    // Every registered intermediate size.
    if (!empty($meta['sizes']) && is_array($meta['sizes']) && $subdir !== '') {
        foreach ($meta['sizes'] as $size) {
            if (!empty($size['file'])) {
                $paths[] = $basedir . '/' . $subdir . $size['file'];
            }
        }
    }

    // Edited-image derivatives left behind by the WP image editor: same dir, name with
    // an -eTIMESTAMP token. Discover them on disk so they tie back to this attachment and
    // are never mis-flagged as orphans.
    if ($subdir !== '' && !empty($meta['file'])) {
        $abs_dir = $basedir . '/' . untrailingslashit($subdir);
        $name = wp_basename($meta['file']);
        $stem = preg_replace('/\.[^.]+$/', '', $name);
        // strip a -scaled suffix from the stem so -e variants of the base match too
        $stem_base = preg_replace('/-scaled$/', '', $stem);
        if (is_dir($abs_dir)) {
            $glob = @glob($abs_dir . '/' . $stem_base . '-e[0-9]*');
            if (is_array($glob)) {
                foreach ($glob as $g) {
                    $paths[] = $g;
                }
            }
        }
    }

    $paths = array_values(array_unique(array_filter($paths)));

    // Build the matching URL + relative-path lists.
    foreach ($paths as $abs) {
        $r = devdsame_path_to_relative($abs);
        if ($r !== '') {
            $rel[] = $r;
            $urls[] = devdsame_uploads_baseurl() . '/' . $r;
        }
    }

    return array(
        'paths' => $paths,
        'urls'  => array_values(array_unique($urls)),
        'rel'   => array_values(array_unique($rel)),
    );
}

/** Absolute path -> path relative to uploads basedir (forward slashes), or '' if outside. */
function devdsame_path_to_relative($abs)
{
    $abs = str_replace('\\', '/', (string) $abs);
    $base = str_replace('\\', '/', devdsame_uploads_basedir());
    if (strpos($abs, $base . '/') === 0) {
        return ltrim(substr($abs, strlen($base) + 1), '/');
    }
    return '';
}

/**
 * Build the full family of haystack tokens for a URL/path so a reference can be matched
 * however it was stored: absolute http/https, protocol-relative, scheme-less host path,
 * site-root-relative, bare relative-to-uploads, and URL-encoded forms.
 *
 * @param string $rel relative-to-uploads path, e.g. "2026/06/pic.jpg"
 * @return string[] candidate tokens (lowercased where appropriate is left to the matcher)
 */
function devdsame_url_tokens($rel)
{
    $rel = ltrim((string) $rel, '/');
    if ($rel === '') {
        return array();
    }
    $baseurl = devdsame_uploads_baseurl();
    $abs = $baseurl . '/' . $rel;

    $tokens = array();
    $tokens[] = $abs;                                   // https://site.com/wp-content/uploads/2026/06/pic.jpg
    $tokens[] = set_url_scheme($abs, 'http');
    $tokens[] = set_url_scheme($abs, 'https');
    $tokens[] = preg_replace('#^https?:#i', '', $abs);  // protocol-relative //site.com/...

    // Site-root-relative (/wp-content/uploads/...).
    $parsed = wp_parse_url($abs);
    if (!empty($parsed['path'])) {
        $tokens[] = $parsed['path'];
    }

    // CDN-mapped variants: replace the uploads base URL with each mapped CDN base.
    $maps = function_exists('devdsame_get_array') ? devdsame_get_array('cdn_mappings') : array();
    foreach ($maps as $cdn_base => $unused) {
        $cdn_base = untrailingslashit((string) $cdn_base);
        if ($cdn_base !== '') {
            $tokens[] = $cdn_base . '/' . $rel;
            $tokens[] = preg_replace('#^https?:#i', '', $cdn_base . '/' . $rel);
        }
    }

    // Bare relative + the dated relative path itself (themes/builders store these).
    $tokens[] = $rel;

    // Encoded forms (spaces -> %20 etc.).
    $enc = array();
    foreach ($tokens as $t) {
        $e = str_replace('%2F', '/', rawurlencode($t));
        if ($e !== $t) {
            $enc[] = $e;
        }
    }
    $tokens = array_merge($tokens, $enc);

    return array_values(array_unique(array_filter($tokens)));
}

/** Human-readable label for a single reason code. */
function devdsame_reason_label($code)
{
    $map = array(
        'no_post_reference'   => 'Not referenced in any post or page',
        'no_featured'         => 'Not used as a featured image',
        'no_builder'          => 'No page-builder reference',
        'no_meta'             => 'Not referenced in post/term/user meta',
        'no_option'           => 'Not referenced in any option or theme setting',
        'no_widget'           => 'Not referenced in any widget',
        'no_woocommerce'      => 'Not a WooCommerce product image',
        'recent_upload'       => 'Uploaded recently (protected)',
        'has_published_parent' => 'Attached to a published post (protected)',
        'in_option'           => 'Referenced in an option / theme setting',
        'in_post'             => 'Referenced in post content',
        'is_featured'         => 'Used as a featured image',
        'in_builder'          => 'Referenced by a page builder',
        'in_woocommerce'      => 'WooCommerce product image',
        'in_template'         => 'Used in a reusable block or template',
        'in_theme'            => 'Theme / customizer image',
        'user_protected'      => 'Protected by you',
        'never_scan_folder'   => 'In a folder you excluded from scanning',
        'below_threshold'     => 'Confidence below your threshold',
        'missing_file'        => 'Attachment record exists but the file is missing',
        'orphan_file'         => 'File on disk with no Media Library record',
        'duplicate_of'        => 'Duplicate of another image',
        'builder_unreadable'  => 'Inside an unreadable page-builder blob (kept as Uncertain)',
        'haystack_truncated'  => 'Site content too large to fully scan in memory (kept as Uncertain)',
    );
    return isset($map[$code]) ? $map[$code] : ucfirst(str_replace('_', ' ', (string) $code));
}

/** Turn a stored reason_code string (CSV) into a list of human labels. */
function devdsame_reason_labels($reason_code)
{
    $out = array();
    foreach (array_filter(array_map('trim', explode(',', (string) $reason_code))) as $c) {
        $out[] = devdsame_reason_label($c);
    }
    return $out;
}

/** Confidence score (0-100) -> simple, non-scary label. */
function devdsame_confidence_label($confidence, $status = '')
{
    if ($status === 'used' || $status === 'protected') {
        return __('Protected', 'devdome-safe-media-cleaner');
    }
    $c = (int) $confidence;
    if ($c >= (int) devdsame_get_int('confidence_threshold', 75)) {
        return __('Safe to remove', 'devdome-safe-media-cleaner');
    }
    return __('Needs manual review', 'devdome-safe-media-cleaner');
}

/** Human label for a status code. */
function devdsame_status_label($status)
{
    $map = array(
        'used'      => __('Used', 'devdome-safe-media-cleaner'),
        'unused'    => __('Unused', 'devdome-safe-media-cleaner'),
        'uncertain' => __('Uncertain', 'devdome-safe-media-cleaner'),
        'missing'   => __('Missing', 'devdome-safe-media-cleaner'),
        'orphan'    => __('Orphan', 'devdome-safe-media-cleaner'),
        'duplicate' => __('Duplicate', 'devdome-safe-media-cleaner'),
        'protected' => __('Protected', 'devdome-safe-media-cleaner'),
    );
    return isset($map[$status]) ? $map[$status] : ucfirst((string) $status);
}

/**
 * Path-traversal-safe validation: a target path is allowed ONLY if its real (or, for a
 * not-yet-existing file, its resolved parent) path is inside the uploads basedir.
 * Returns the normalized absolute path, or false if it escapes the allowed root.
 */
function devdsame_validate_in_uploads($path)
{
    $base = devdsame_uploads_basedir();
    $real_base = realpath($base);
    if ($real_base === false) {
        return false;
    }
    $real_base = str_replace('\\', '/', $real_base);

    $real = realpath($path);
    if ($real === false) {
        // File may not exist yet (restore target): validate the parent directory instead.
        $parent = realpath(dirname($path));
        if ($parent === false) {
            return false;
        }
        $parent = str_replace('\\', '/', $parent);
        if ($parent !== $real_base && strpos($parent, $real_base . '/') !== 0) {
            return false;
        }
        return str_replace('\\', '/', rtrim($parent, '/')) . '/' . wp_basename($path);
    }

    $real = str_replace('\\', '/', $real);
    if ($real !== $real_base && strpos($real, $real_base . '/') !== 0) {
        return false;
    }
    return $real;
}

/** Compute an md5 hash of a file's bytes (cheap, collision-grouped with size+dims later). */
function devdsame_file_hash($path)
{
    if (!is_string($path) || !is_file($path) || !is_readable($path)) {
        return '';
    }
    $size = @filesize($path);
    // Skip hashing absurdly large files to protect memory; group those by size+name only.
    if ($size !== false && $size > 209715200) { // 200 MB
        return '';
    }
    $hash = @md5_file($path);
    return $hash ? $hash : '';
}

/** Is WooCommerce active in this request? */
function devdsame_has_woocommerce()
{
    return class_exists('WooCommerce') || function_exists('wc_get_product');
}
