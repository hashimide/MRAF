<?php
/**
 * Shared Image Replacement Logic
 *
 * @package    MRAF
 * @subpackage MRAF/includes
 */

if (!defined('ABSPATH')) {
    exit;
}

class MRAF_Image_Replacer
{
    /**
     * Write a line to the PHP error log, but only when debugging is on.
     *
     * A replacement touches every post, meta row and option that mentions the
     * old URL, so logging each step unconditionally fills a production
     * debug.log with noise nobody asked for.
     *
     * @param string $message Line to record.
     */
    private static function log($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MRAF: ' . $message);
        }
    }

    public static function update_elementor_urls($old_url, $new_url)
    {
        global $wpdb;
        $updated_count = 0;

        // Strip query parameters from URLs for searching
        $old_url_base = $old_url ? strtok($old_url, '?') : '';
        $new_url_base = $new_url ? strtok($new_url, '?') : '';

        // Safety check - if URLs are invalid or same, return early
        if (empty($old_url_base) || empty($new_url_base)) {
            self::log("Invalid URLs provided to update_elementor_urls - Old: '{$old_url}', New: '{$new_url}'");
            return 0;
        }

        if ($old_url_base === $new_url_base) {
            self::log("URLs are identical, skipping Elementor update - URL: '{$old_url_base}'");
            return 0;
        }

        self::log("Elementor URL replacement - Old base: '{$old_url_base}', New base: '{$new_url_base}'");

        // Elementor JSON stores URLs with escaped slashes, so we need to search for both variants
        $old_url_escaped = str_replace('/', '\\/', $old_url_base);
        $new_url_escaped = str_replace('/', '\\/', $new_url_base);

        // DEBUG: Check if the old URL exists in Elementor data
        $debug_check = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_data', '_elementor_draft') AND (meta_value LIKE %s OR meta_value LIKE %s)",
            '%' . $wpdb->esc_like($old_url_base) . '%',
            '%' . $wpdb->esc_like($old_url_escaped) . '%'
        ));
        self::log("DEBUG - Found {$debug_check} Elementor rows containing old URL (escaped or not)");

        // Update BOTH published data AND draft data
        $meta_keys = ['_elementor_data', '_elementor_draft'];
        $affected_posts = [];

        foreach ($meta_keys as $meta_key) {
            // Search for BOTH regular and escaped URLs
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_id, meta_value
                FROM {$wpdb->postmeta}
                WHERE meta_key = %s
                AND (meta_value LIKE %s OR meta_value LIKE %s)",
                $meta_key,
                '%' . $wpdb->esc_like($old_url_base) . '%',
                '%' . $wpdb->esc_like($old_url_escaped) . '%'
            ));

            self::log("Searching {$meta_key} for '{$old_url_base}' - Found " . count($results) . " rows");

            foreach ($results as $row) {
                $post_id = $row->post_id;
                $meta_id = $row->meta_id;
                $old_data = $row->meta_value;

                if (empty($old_data)) {
                    continue;
                }

                $old_length = strlen($old_data);
                $decoded = json_decode($old_data, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || empty($decoded)) {
                    self::log("JSON decode failed for {$meta_key} post {$post_id}");
                    continue;
                }

                // Replace both escaped and unescaped URLs
                $new_decoded = self::recursive_url_replace(
                    [$old_url_base, $old_url_escaped],
                    [$new_url_base, $new_url_escaped],
                    $decoded
                );

                if (!is_array($new_decoded) || empty($new_decoded)) {
                    continue;
                }

                $new_data = wp_json_encode($new_decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                if ($new_data === false || $new_data === null) {
                    continue;
                }

                $new_length = strlen($new_data);
                $length_diff_percent = abs($new_length - $old_length) / max($old_length, 1) * 100;

                if ($length_diff_percent > 50) {
                    self::log("Skipping {$meta_key} post {$post_id} - length difference too large ({$length_diff_percent}%)");
                    continue;
                }

                json_decode($new_data, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                if ($new_data !== $old_data) {
                    $result = $wpdb->update(
                        $wpdb->postmeta,
                        ['meta_value' => $new_data],
                        ['meta_id' => $meta_id],
                        ['%s'],
                        ['%d']
                    );

                    if ($result !== false) {
                        $updated_count++;
                        $affected_posts[$post_id] = true;
                        self::log("Updated {$meta_key} for post {$post_id}");
                    } else {
                        self::log("FAILED to update {$meta_key} for post {$post_id}");
                    }
                }
            }
        }

        // Clear all Elementor caches for affected posts.
        //
        // The draft is not overwritten from the published data here. It is one
        // of the two meta keys the loop above already rewrote, so its URLs are
        // current, and copying the published version over it would throw away
        // whatever the editor had not saved yet.
        foreach (array_keys($affected_posts) as $post_id) {
            self::clear_elementor_post_cache($post_id);
        }

        return $updated_count;
    }

    /**
     * Clear ALL Elementor caches for a specific post
     */
    private static function clear_elementor_post_cache($post_id)
    {
        global $wpdb;

        // Delete Elementor CSS cache
        delete_post_meta($post_id, '_elementor_css');
        delete_metadata('post', $post_id, '_elementor_css', '', false);

        // Delete editor-specific caches
        delete_post_meta($post_id, '_elementor_page_assets');
        delete_post_meta($post_id, '_elementor_inline_fonts');
        delete_post_meta($post_id, '_elementor_draft_status');
        delete_post_meta($post_id, '_elementor_edit_mode_switch');

        // Clear all Elementor-related cache meta
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
            $post_id,
            '_elementor_%cache%'
        ));

        // Clear Elementor object cache entries
        wp_cache_delete($post_id . '_elementor', 'elementor');
        wp_cache_delete('elementor_' . $post_id, 'options');
        wp_cache_delete('elementor_data_' . $post_id, 'elementor');
        wp_cache_delete('elementor_draft_' . $post_id, 'elementor');
        wp_cache_delete('elementor_document_' . $post_id, 'elementor');

        // Clear transients
        delete_transient('elementor_remote_info_api_data_' . $post_id);
        delete_transient('elementor_controls_' . $post_id);
        delete_transient('elementor_document_' . $post_id);

        // Force post cache refresh
        clean_post_cache($post_id);

        self::log("Cleared Elementor caches for post {$post_id}");
    }

    public static function update_page_builder_urls($old_url, $new_url)
    {
        global $wpdb;
        $updated_count = 0;

        // Safety check
        if (empty($old_url) || empty($new_url) || $old_url === $new_url) {
            return 0;
        }

        // _et_pb_post_hide_nav used to be listed here. It holds a yes/no flag,
        // never a URL, so it could not match. Divi's own content lives in
        // post_content, which step 1 of update_all_references() covers.
        $builders = [
            '_wpb_shortcodes_custom_css' => 'text',
            '_fl_builder_data'           => 'serialized',
            '_fusion'                    => 'serialized',
        ];

        foreach ($builders as $meta_key => $type) {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_id, meta_value
                FROM {$wpdb->postmeta}
                WHERE meta_key = %s
                AND meta_value LIKE %s",
                $meta_key,
                '%' . $wpdb->esc_like($old_url) . '%'
            ));

            foreach ($results as $row) {
                $post_id = $row->post_id;
                $meta_id = $row->meta_id;
                $old_data = $row->meta_value;

                if (empty($old_data)) {
                    continue;
                }

                $old_length = strlen($old_data);

                if ($type === 'serialized') {
                    $unserialized = maybe_unserialize($old_data);

                    if ($unserialized === false || $unserialized === $old_data) {
                        continue;
                    }

                    $new_unserialized = self::recursive_url_replace([$old_url], [$new_url], $unserialized);

                    if (empty($new_unserialized)) {
                        continue;
                    }

                    $new_data = maybe_serialize($new_unserialized);

                    if (empty($new_data)) {
                        continue;
                    }

                    $check = maybe_unserialize($new_data);

                    if ($check === false || $check === $new_data) {
                        continue;
                    }
                } else {
                    $new_data = str_replace($old_url, $new_url, $old_data);
                }

                $new_length = strlen($new_data);
                $length_diff_percent = abs($new_length - $old_length) / max($old_length, 1) * 100;

                if ($length_diff_percent > 50) {
                    continue;
                }

                if ($new_data !== $old_data) {
                    $result = $wpdb->update(
                        $wpdb->postmeta,
                        ['meta_value' => $new_data],
                        ['meta_id' => $meta_id],
                        ['%s'],
                        ['%d']
                    );

                    if ($result !== false) {
                        $updated_count++;
                    }
                }
            }
        }

        return $updated_count;
    }

    public static function recursive_url_replace(array $search, array $replace, $value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::recursive_url_replace($search, $replace, $item);
            }
            return $value;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $prop => $item) {
                $value->$prop = self::recursive_url_replace($search, $replace, $item);
            }
            return $value;
        }

        if (is_string($value)) {
            return str_replace($search, $replace, $value);
        }

        return $value;
    }

    public static function replace_maybe_serialized($value, array $search, array $replace, &$changed, &$replacements = 0)
    {
        $changed = false;
        $replacements = 0;

        if (is_serialized($value)) {
            $data = maybe_unserialize($value);
            $data = self::recursive_url_replace($search, $replace, $data);
            $new = maybe_serialize($data);

            if ($new !== $value) {
                $changed = true;
                foreach ($search as $needle) {
                    $replacements += substr_count($value, $needle);
                }
            }

            return $new;
        }

        $new = str_replace($search, $replace, $value, $count);
        if ($count > 0) {
            $changed = true;
            $replacements += $count;
        }

        return $new;
    }

    /**
     * Attachments whose caches still need clearing before the request ends.
     *
     * @var array
     */
    private static $pending_flush = [];

    /**
     * Note that an attachment needs a cache flush, without doing it yet.
     *
     * Clearing the caches is the most expensive thing this class does, and a
     * single job produces one call per URL pair and one pair per image size.
     * Queueing the work means a batch of two hundred images pays for it once,
     * at the end of the request, instead of well over a thousand times.
     *
     * @param int $attachment_id Attachment ID.
     */
    public static function request_flush($attachment_id = 0)
    {
        self::$pending_flush[(int) $attachment_id] = true;

        if (!has_action('shutdown', [__CLASS__, 'flush_pending'])) {
            add_action('shutdown', [__CLASS__, 'flush_pending']);
        }
    }

    /**
     * Run every queued flush: each attachment once, the site once.
     */
    public static function flush_pending()
    {
        if (empty(self::$pending_flush)) {
            return;
        }

        $ids = array_keys(self::$pending_flush);
        self::$pending_flush = [];

        foreach ($ids as $attachment_id) {
            if ($attachment_id > 0) {
                self::flush_attachment_caches($attachment_id);
            }
        }

        self::flush_site_caches();
        do_action('mraf_after_cache_flush', reset($ids));
    }

    /**
     * Clear everything that caches one attachment.
     *
     * @param int $attachment_id Attachment ID.
     */
    public static function flush_attachment_caches($attachment_id)
    {
        $attachment_id = absint($attachment_id);

        if (!$attachment_id) {
            return;
        }

        clean_post_cache($attachment_id);

        if (function_exists('clean_attachment_cache')) {
            clean_attachment_cache($attachment_id);
        }

        wp_cache_delete($attachment_id, 'post_meta');
        wp_cache_delete($attachment_id, 'posts');
        wp_cache_delete($attachment_id . '_thumbnail_id', 'post_meta');
        wp_cache_delete('image_downsize_' . $attachment_id, 'default');
        wp_cache_delete('attachment_metadata_' . $attachment_id);
        wp_cache_delete($attachment_id, 'attachments');

        wp_cache_delete('elementor_attachment_' . $attachment_id, 'elementor');
        wp_cache_delete('attachment_' . $attachment_id, 'elementor');
        delete_transient('elementor_media_' . $attachment_id);
        delete_transient('elementor_image_' . $attachment_id);
        delete_transient('media-library-' . $attachment_id);

        foreach (get_intermediate_image_sizes() as $size) {
            wp_cache_delete('image_downsize_' . $attachment_id . '_' . $size, 'default');
            delete_transient('image_' . $attachment_id . '_' . $size);
        }

        // Bump post_modified so anything keyed on it serves the new file.
        wp_update_post([
            'ID'                => $attachment_id,
            'post_modified'     => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1),
        ]);
    }

    /**
     * Clear the site-wide caches a URL rewrite invalidates.
     *
     * This is the expensive half, so it runs once per request.
     */
    public static function flush_site_caches()
    {
        global $wpdb;

        if (did_action('elementor/loaded')) {
            try {
                if (class_exists('\Elementor\Plugin')) {
                    $elementor = \Elementor\Plugin::$instance;

                    // clear_cache() drops every generated CSS file already, so
                    // there is no reason to walk the posts and rebuild each one.
                    if (isset($elementor->files_manager) && method_exists($elementor->files_manager, 'clear_cache')) {
                        $elementor->files_manager->clear_cache();
                    }

                    delete_transient('elementor_editor_config');
                    delete_transient('elementor_editor_settings');
                }
            } catch (\Throwable $e) {
                // An Elementor that will not talk to us is not a failure here.
            }

            if (function_exists('elementor_clear_cache')) {
                elementor_clear_cache();
            }

            do_action('elementor/core/files/clear_cache');

            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_elementor_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_elementor_%'");
        }

        if (function_exists('wpb_js_remove_wpautop')) {
            delete_transient('vc_license');
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpb_vc_css%'");
        }

        if (class_exists('FLBuilderModel')) {
            try {
                \FLBuilderModel::delete_asset_cache_for_all_posts();
            } catch (\Throwable $e) {
            }
        }

        if (function_exists('et_core_clear_wp_cache')) {
            et_core_clear_wp_cache();
        }

        wp_cache_flush();

        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        if (class_exists('LiteSpeed\Purge')) {
            do_action('litespeed_purge_all');
        }

        if (class_exists('autoptimizeCache')) {
            try {
                \autoptimizeCache::clearall();
            } catch (\Throwable $e) {
            }
        }

        delete_transient('dirsize_cache');
    }

    /**
     * Clear an attachment and the site in one go, straight away.
     *
     * @param int $attachment_id Attachment ID.
     */
    public static function flush_builder_caches($attachment_id = 0)
    {
        if ($attachment_id > 0) {
            self::flush_attachment_caches($attachment_id);
        }

        self::flush_site_caches();
        do_action('mraf_after_cache_flush', $attachment_id);
    }

    /**
     * Unified reference update method for posts, postmeta, page builders, Elementor, and widgets.
     *
     * @param string   $old_url
     * @param string   $new_url
     * @param int      $attachment_id
     * @param callable $logger_cb
     * @return array
     */
    public static function update_all_references($old_url, $new_url, $attachment_id = 0, $logger_cb = null)
    {
        global $wpdb;

        $stats = [
            'posts'     => 0,
            'meta'      => 0,
            'elementor' => 0,
            'builders'  => 0,
            'widgets'   => 0,
            'options'   => 0,
        ];

        $log = function ($msg) use ($logger_cb) {
            if (is_callable($logger_cb)) {
                call_user_func($logger_cb, $msg);
            }
        };

        if (empty($old_url) || empty($new_url) || $old_url === $new_url) {
            return $stats;
        }

        // 1. Update post_content, post_excerpt
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET
             post_content = REPLACE(post_content, %s, %s),
             post_excerpt = REPLACE(post_excerpt, %s, %s)
             WHERE post_content LIKE %s OR post_excerpt LIKE %s",
            $old_url,
            $new_url,
            $old_url,
            $new_url,
            '%' . $wpdb->esc_like($old_url) . '%',
            '%' . $wpdb->esc_like($old_url) . '%'
        ));
        $stats['posts'] = $result !== false ? (int) $result : 0;
        $log("Updated {$stats['posts']} post_content/post_excerpt rows");

        // The guid is deliberately NOT written here.
        //
        // Callers rewrite one URL pair at a time and pass a pair for the full
        // size and for every thumbnail, so setting the guid on each pass left
        // the attachment claiming to be whichever size happened to be last:
        // guid ended up as ...-150x150.jpg. The guid belongs to the file the
        // attachment points at, so adopt_file() and restore_backup() own it.

        // 3. Update Elementor data
        $stats['elementor'] = self::update_elementor_urls($old_url, $new_url);
        $log("Updated {$stats['elementor']} Elementor rows");

        // 4. Update other page builders (Divi, Beaver, etc.)
        $stats['builders'] = self::update_page_builder_urls($old_url, $new_url);
        $log("Updated {$stats['builders']} page builder rows");

        // 5. Update postmeta
        $excluded_meta_keys = [
            '_elementor_data', '_elementor_draft', '_elementor_css',
            '_elementor_page_settings', '_elementor_controls_usage',
            '_elementor_version', 'elementor_data', '_wpb_shortcodes_custom_css',
            '_fl_builder_data', '_fusion', '_et_pb_'
        ];
        $exclude_conditions = [];
        foreach ($excluded_meta_keys as $key) {
            if (strpos($key, '_et_pb_') !== false) {
                $exclude_conditions[] = $wpdb->prepare("meta_key NOT LIKE %s", $key . '%');
            } else {
                $exclude_conditions[] = $wpdb->prepare("meta_key != %s", $key);
            }
        }
        $exclude_sql = implode(' AND ', $exclude_conditions);

        // Plain text postmeta
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)
            WHERE meta_value LIKE %s AND {$exclude_sql}
            AND meta_value NOT LIKE 'a:%%' AND meta_value NOT LIKE 'O:%%' AND meta_value NOT LIKE '{%%'",
            $old_url,
            $new_url,
            '%' . $wpdb->esc_like($old_url) . '%'
        ));
        $stats['meta'] += ($result !== false ? (int) $result : 0);

        // Serialized postmeta
        $search = [$old_url];
        $replace = [$new_url];
        $like = '%' . $wpdb->esc_like($old_url) . '%';

        $serialized_metas = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
            WHERE meta_value LIKE %s AND {$exclude_sql}
            AND (meta_value LIKE 'a:%%' OR meta_value LIKE 'O:%%' OR meta_value LIKE '{%%')",
            $like
        ));

        foreach ($serialized_metas as $m) {
            $raw = (string) $m->meta_value;
            $changed = false;
            $replaced_count = 0;

            $new = self::replace_maybe_serialized($raw, $search, $replace, $changed, $replaced_count);

            if ($changed) {
                $wpdb->update($wpdb->postmeta, ['meta_value' => $new], ['meta_id' => (int) $m->meta_id]);
                if (isset($m->post_id)) {
                    clean_post_cache((int) $m->post_id);
                }
                $stats['meta']++;
                $log("Updated serialized meta: {$m->meta_key} (post_id: {$m->post_id}, {$replaced_count} replaced)");
            }
        }

        // 6. Update options & widgets
        $safe_option_prefixes = ['widget_', 'sidebars_widgets', '_transient_', 'acf_', 'options_', 'theme_mods_', 'gowild_'];
        $safe_option_names = ['upload_path', 'upload_url_path'];

        $opts = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value FROM {$wpdb->options}
            WHERE option_value LIKE %s",
            $like
        ));

        foreach ($opts as $o) {
            $option_name = $o->option_name;
            $is_safe = in_array($option_name, $safe_option_names);
            if (!$is_safe) {
                foreach ($safe_option_prefixes as $prefix) {
                    if (strpos($option_name, $prefix) === 0) {
                        $is_safe = true;
                        break;
                    }
                }
            }

            $excluded_options = ['custom_css_', 'nav_menu_', 'category_children', 'rewrite_rules', 'cron'];
            $is_excluded = false;
            foreach ($excluded_options as $excluded) {
                if (strpos($option_name, $excluded) === 0 || $option_name === $excluded) {
                    $is_excluded = true;
                    break;
                }
            }

            if (!$is_safe || $is_excluded) {
                continue;
            }

            $raw = (string) $o->option_value;
            $changed = false;
            $replaced_count = 0;

            $new = self::replace_maybe_serialized($raw, $search, $replace, $changed, $replaced_count);

            if ($changed) {
                $wpdb->update($wpdb->options, ['option_value' => $new], ['option_id' => (int) $o->option_id]);
                if (!empty($o->option_name)) {
                    wp_cache_delete($o->option_name, 'options');
                }

                if (strpos($option_name, 'widget_') === 0) {
                    $stats['widgets']++;
                } else {
                    $stats['options']++;
                }
            }
        }

        // 7. Queue the cache flush. Callers rewrite one URL pair at a time,
        // so the work is collected and done once when the request ends.
        self::request_flush($attachment_id);
        $log("Queued builder and attachment cache flush for ID: {$attachment_id}");

        return $stats;
    }

    /**
     * Make a file on disk the file an attachment points at.
     *
     * Converting and replacing differ in how they produce the new file, but
     * from the moment it exists the work is the same: drop the metadata that
     * describes the old file, repoint the attachment, and build fresh sizes.
     * All three callers used to carry their own copy of that sequence, and
     * they had already drifted apart from one another.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $file_path     Absolute path to the new file.
     * @param string $stamp_key     Metadata key to stamp with the time, if any.
     * @return array ['rel' => string, 'url' => string, 'mime' => string, 'meta' => array]
     */
    public static function adopt_file($attachment_id, $file_path, $stamp_key = '')
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = absint($attachment_id);
        $uploads       = wp_get_upload_dir();
        $basedir       = trailingslashit(wp_normalize_path($uploads['basedir']));
        $rel           = ltrim(str_replace($basedir, '', wp_normalize_path($file_path)), '/');
        $url           = trailingslashit($uploads['baseurl']) . $rel;
        $filetype      = wp_check_filetype($file_path);
        $mime          = $filetype['type'] ? $filetype['type'] : (string) get_post_mime_type($attachment_id);

        // The stored metadata describes a file that is no longer there, and a
        // cached copy of it would outlive the new one.
        clean_post_cache($attachment_id);

        if (function_exists('clean_attachment_cache')) {
            clean_attachment_cache($attachment_id);
        }

        wp_cache_delete($attachment_id, 'posts');
        wp_cache_delete($attachment_id, 'post_meta');
        delete_post_meta($attachment_id, '_wp_attachment_metadata');
        delete_post_meta($attachment_id, '_wp_attachment_backup_sizes');

        update_attached_file($attachment_id, $file_path);
        update_post_meta($attachment_id, '_wp_attached_file', $rel);

        wp_update_post([
            'ID'             => $attachment_id,
            'post_mime_type' => $mime,
            'guid'           => $url,
        ]);

        $meta = wp_generate_attachment_metadata($attachment_id, $file_path);

        // A generator that gives up still leaves subsizes worth asking for.
        if ((is_wp_error($meta) || empty($meta)) && function_exists('wp_update_image_subsizes')) {
            wp_update_image_subsizes($attachment_id);
            $meta = wp_get_attachment_metadata($attachment_id);
        }

        if (is_wp_error($meta) || !is_array($meta) || empty($meta)) {
            $meta = ['file' => $rel, 'sizes' => []];
        }

        if (empty($meta['file'])) {
            $meta['file'] = $rel;
        }

        if ($stamp_key) {
            $meta[$stamp_key] = time();
        }

        wp_update_attachment_metadata($attachment_id, $meta);

        return ['rel' => $rel, 'url' => $url, 'mime' => $mime, 'meta' => $meta];
    }

    /**
     * The directory every backup lives under.
     *
     * @return string Absolute path, no trailing slash.
     */
    public static function backup_root()
    {
        return untrailingslashit(wp_normalize_path(WP_CONTENT_DIR . '/mraf-backups'));
    }

    /**
     * The backup directory for one attachment.
     *
     * @param int $attachment_id Attachment ID.
     * @return string Absolute path, no trailing slash.
     */
    public static function backup_dir($attachment_id)
    {
        return self::backup_root() . '/' . absint($attachment_id);
    }

    /**
     * Keep the backup directory off the web.
     *
     * These files are the only copy of the originals, so the guards are
     * rewritten whenever a backup is taken rather than once at activation:
     * an install that upgraded from an older version gets them too, and a
     * guard someone deleted comes back.
     *
     * The Apache rule covers 2.4 and 2.2, because the 2.2 syntax on its own
     * is a 500 error on a 2.4 server without mod_access_compat. IIS reads
     * web.config. nginx reads neither, so a server block is still needed
     * there, and index.html keeps a stray directory listing empty meanwhile.
     */
    public static function protect_backup_dir()
    {
        $root = self::backup_root();

        if (!is_dir($root)) {
            return;
        }

        $htaccess = <<<HTACCESS
# Managed by MRAF. The files below are the only copy of the originals.
Options -Indexes
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

HTACCESS;

        $webconfig = <<<WEBCONFIG
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <authorization>
            <deny users="*" />
        </authorization>
    </system.webServer>
</configuration>

WEBCONFIG;

        $guards = [
            '.htaccess'  => $htaccess,
            'web.config' => $webconfig,
            'index.php'  => "<?php // Silence is golden.\n",
            'index.html' => '',
        ];

        foreach ($guards as $name => $contents) {
            $path = $root . '/' . $name;

            if (!file_exists($path)) {
                @file_put_contents($path, $contents);
                continue;
            }

            // Earlier versions wrote an Apache 2.2 rule that is a 500 error on
            // a 2.4 server, so a guard without our marker is replaced.
            if ($contents !== '' && strpos((string) @file_get_contents($path), 'Managed by MRAF') === false
                && $name === '.htaccess') {
                @file_put_contents($path, $contents);
            }
        }
    }

    /**
     * Centralized backup creation.
     *
     * A backup is the original, so one that already exists is never replaced.
     * Converting the same image twice would otherwise overwrite the original
     * with the first conversion's output and lose it for good.
     *
     * @param int  $attachment_id Attachment ID.
     * @param bool $force         Replace an existing backup instead of keeping it.
     * @return bool
     */
    public static function create_backup($attachment_id, $force = false)
    {
        $backup_dir = self::backup_dir($attachment_id);

        if (!$force && file_exists($backup_dir . '/backup.json')) {
            return true;
        }

        if (!wp_mkdir_p($backup_dir)) {
            return false;
        }

        self::protect_backup_dir();

        // Clean existing files in attachment backup folder
        foreach (glob($backup_dir . '/*') as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $file = get_attached_file($attachment_id);
        $meta = wp_get_attachment_metadata($attachment_id);
        $backup_count = 0;

        if ($file && file_exists($file)) {
            if (@copy($file, $backup_dir . '/' . basename($file))) {
                $backup_count++;
            }
        }

        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            $base_dir = dirname($file);
            foreach ($meta['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $thumb_path = $base_dir . '/' . $size['file'];
                    if (file_exists($thumb_path)) {
                        if (@copy($thumb_path, $backup_dir . '/' . $size['file'])) {
                            $backup_count++;
                        }
                    }
                }
            }
        }

        $backup_data = [
            'attachment_id' => $attachment_id,
            'timestamp'     => current_time('timestamp'),
            'date'          => current_time('mysql'),
            'original_file' => $file,
            'original_meta' => $meta,
            'files_count'   => $backup_count
        ];

        file_put_contents($backup_dir . '/backup.json', wp_json_encode($backup_data, JSON_PRETTY_PRINT));
        return true;
    }

    /**
     * Put an attachment back the way it was before MRAF touched it.
     *
     * Copying the files back is only half the job. The attachment still
     * claims the converted MIME type and URL, and every post, widget and
     * builder still points at the file that is about to disappear, so the
     * references are rewritten back as well and the replacement is removed.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public static function restore_backup($attachment_id)
    {
        $attachment_id = absint($attachment_id);
        $backup_dir    = self::backup_dir($attachment_id);
        $json_file     = $backup_dir . '/backup.json';

        if (!file_exists($json_file)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($json_file), true);

        if (!$data || empty($data['original_file'])) {
            return false;
        }

        $orig_file   = $data['original_file'];
        $orig_meta   = isset($data['original_meta']) && is_array($data['original_meta']) ? $data['original_meta'] : [];
        $backup_orig = $backup_dir . '/' . wp_basename($orig_file);

        if (!file_exists($backup_orig)) {
            return false;
        }

        // What the attachment looks like right now, before anything moves.
        $current_file = get_attached_file($attachment_id);
        $current_url  = wp_get_attachment_url($attachment_id);
        $current_meta = wp_get_attachment_metadata($attachment_id);

        if (!@copy($backup_orig, $orig_file)) {
            return false;
        }

        if (!empty($orig_meta['sizes']) && is_array($orig_meta['sizes'])) {
            $base_dir = dirname($orig_file);

            foreach ($orig_meta['sizes'] as $size) {
                if (empty($size['file'])) {
                    continue;
                }

                $src = $backup_dir . '/' . $size['file'];

                if (file_exists($src)) {
                    @copy($src, $base_dir . '/' . $size['file']);
                }
            }
        }

        $uploads  = wp_get_upload_dir();
        $basedir  = trailingslashit(wp_normalize_path($uploads['basedir']));
        $orig_rel = ltrim(str_replace($basedir, '', wp_normalize_path($orig_file)), '/');
        $orig_url = trailingslashit($uploads['baseurl']) . $orig_rel;
        $filetype = wp_check_filetype($orig_file);

        update_attached_file($attachment_id, $orig_file);
        update_post_meta($attachment_id, '_wp_attached_file', $orig_rel);

        // The MIME type and the guid describe the converted file until they
        // are told otherwise, and the library reads both.
        wp_update_post([
            'ID'             => $attachment_id,
            'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'image/jpeg',
            'guid'           => $orig_url,
        ]);

        if (!empty($orig_meta)) {
            wp_update_attachment_metadata($attachment_id, $orig_meta);
        }

        // Point the site back at the file that exists again.
        if ($current_url && $orig_url && $current_url !== $orig_url) {
            $pairs = self::build_url_replacements($current_url, $orig_url, $current_meta, $orig_meta);

            foreach ($pairs as $pair) {
                self::update_all_references($pair['old'], $pair['new'], $attachment_id);
            }
        }

        // The replacement is now unreferenced, so it goes with its sizes.
        if ($current_file && wp_normalize_path($current_file) !== wp_normalize_path($orig_file)) {
            self::delete_stale_files($current_file, $current_meta, $orig_file, $orig_meta);
        }

        delete_post_meta($attachment_id, '_mraf_converted');
        delete_post_meta($attachment_id, '_mraf_replaced_at');

        self::request_flush($attachment_id);

        return true;
    }

    /**
     * Delete a file and its sizes, sparing anything the restored set owns.
     *
     * A converted image and its original often share a size file name, and
     * the restored copy has just been written, so a blind delete would take
     * the fresh file straight back off disk.
     *
     * @param string $file      Absolute path of the file to remove.
     * @param array  $meta      Its attachment metadata.
     * @param string $keep_file Absolute path of the file to spare.
     * @param array  $keep_meta Metadata of the file to spare.
     */
    public static function delete_stale_files($file, $meta, $keep_file, $keep_meta)
    {
        if (!$file) {
            return;
        }

        $keep = [wp_normalize_path($keep_file)];
        $dir  = dirname($keep_file);

        if (!empty($keep_meta['sizes']) && is_array($keep_meta['sizes'])) {
            foreach ($keep_meta['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $keep[] = wp_normalize_path($dir . '/' . $size['file']);
                }
            }
        }

        $targets  = [$file];
        $base_dir = dirname($file);

        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $targets[] = $base_dir . '/' . $size['file'];
                }
            }
        }

        foreach ($targets as $target) {
            if (in_array(wp_normalize_path($target), $keep, true)) {
                continue;
            }

            if (file_exists($target)) {
                @unlink($target);
            }
        }
    }

    /**
     * Delete backup folder for an attachment.
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function delete_backup($attachment_id)
    {
        $backup_dir = self::backup_dir($attachment_id);
        if (!is_dir($backup_dir)) {
            return false;
        }

        foreach (glob($backup_dir . '/*') as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($backup_dir);
        return true;
    }

    /**
     * Get list of all available backups.
     *
     * @return array
     */
    public static function get_backups_list()
    {
        $base = self::backup_root();
        if (!is_dir($base)) {
            return [];
        }

        $dirs = glob($base . '/*', GLOB_ONLYDIR);
        $backups = [];

        foreach ($dirs as $dir) {
            $json = $dir . '/backup.json';
            if (file_exists($json)) {
                $info = json_decode(file_get_contents($json), true);

                if ($info) {
                    // The browser gets the file name, never where it sits on
                    // the server.
                    $info['filename'] = !empty($info['original_file'])
                        ? wp_basename($info['original_file'])
                        : '';
                    unset($info['original_file'], $info['original_meta']);

                    $backups[] = $info;
                }
            }
        }

        usort($backups, function ($a, $b) {
            return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
        });

        return $backups;
    }

    /**
     * Build URL replacement pairs (full size + all thumbnail sizes).
     *
     * @param string $old_url
     * @param string $new_url
     * @param array  $old_meta
     * @param array  $new_meta
     * @return array
     */
    public static function build_url_replacements($old_url, $new_url, $old_meta = [], $new_meta = [])
    {
        $map = [];

        if ($old_url && $new_url && $old_url !== $new_url) {
            $map[$old_url] = $new_url;
        }

        $old_dir = self::get_url_directory($old_url);
        $new_dir = self::get_url_directory($new_url);

        if ($old_dir && !empty($old_meta['sizes'])) {
            foreach ($old_meta['sizes'] as $size_key => $info) {
                if (empty($info['file'])) {
                    continue;
                }
                $old_size_url = $old_dir . ltrim($info['file'], '/');
                $target_new = '';

                if ($new_dir && !empty($new_meta['sizes'][$size_key]['file'])) {
                    $target_new = $new_dir . ltrim($new_meta['sizes'][$size_key]['file'], '/');
                } elseif ($new_url) {
                    $target_new = $new_url;
                }

                if ($target_new && $old_size_url !== $target_new) {
                    $map[$old_size_url] = $target_new;
                }
            }
        }

        if ($old_dir && !empty($old_meta['original_image'])) {
            $old_original = $old_dir . ltrim($old_meta['original_image'], '/');
            $new_original = '';

            if ($new_dir && !empty($new_meta['original_image'])) {
                $new_original = $new_dir . ltrim($new_meta['original_image'], '/');
            } elseif ($new_url) {
                $new_original = $new_url;
            }

            if ($new_original && $old_original !== $new_original) {
                $map[$old_original] = $new_original;
            }
        }

        $pairs = [];
        foreach ($map as $old => $new) {
            $pairs[] = ['old' => $old, 'new' => $new];
        }

        return $pairs;
    }

    /**
     * Get directory path for a URL.
     *
     * @param string $url
     * @return string
     */
    public static function get_url_directory($url)
    {
        if (!$url) {
            return '';
        }
        $pos = strrpos($url, '/');
        if (false === $pos) {
            return '';
        }
        return substr($url, 0, $pos + 1);
    }
}