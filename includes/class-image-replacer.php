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
    public static function update_elementor_urls($old_url, $new_url, $attachment_id = 0)
    {
        global $wpdb;
        $updated_count = 0;

        // Strip query parameters from URLs for searching
        $old_url_base = $old_url ? strtok($old_url, '?') : '';
        $new_url_base = $new_url ? strtok($new_url, '?') : '';

        // Safety check - if URLs are invalid or same, return early
        if (empty($old_url_base) || empty($new_url_base)) {
            error_log("MRAF: Invalid URLs provided to update_elementor_urls - Old: '{$old_url}', New: '{$new_url}'");
            return 0;
        }

        if ($old_url_base === $new_url_base) {
            error_log("MRAF: URLs are identical, skipping Elementor update - URL: '{$old_url_base}'");
            return 0;
        }

        error_log("MRAF: Elementor URL replacement - Old base: '{$old_url_base}', New base: '{$new_url_base}'");

        // Elementor JSON stores URLs with escaped slashes, so we need to search for both variants
        $old_url_escaped = str_replace('/', '\\/', $old_url_base);
        $new_url_escaped = str_replace('/', '\\/', $new_url_base);

        // DEBUG: Check if the old URL exists in Elementor data
        $debug_check = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_data', '_elementor_draft') AND (meta_value LIKE %s OR meta_value LIKE %s)",
            '%' . $wpdb->esc_like($old_url_base) . '%',
            '%' . $wpdb->esc_like($old_url_escaped) . '%'
        ));
        error_log("MRAF: DEBUG - Found {$debug_check} Elementor rows containing old URL (escaped or not)");

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

            error_log("MRAF: Searching {$meta_key} for '{$old_url_base}' - Found " . count($results) . " rows");

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
                    error_log("MRAF: JSON decode failed for {$meta_key} post {$post_id}");
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
                    error_log("MRAF: Skipping {$meta_key} post {$post_id} - length difference too large ({$length_diff_percent}%)");
                    continue;
                }

                $verify = json_decode($new_data, true);
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
                        error_log("MRAF: Updated {$meta_key} for post {$post_id}");
                    } else {
                        error_log("MRAF: FAILED to update {$meta_key} for post {$post_id}");
                    }
                }
            }
        }

        // Clear all Elementor caches for affected posts
        foreach (array_keys($affected_posts) as $post_id) {
            self::clear_elementor_post_cache($post_id);

            // CRITICAL: Force sync _elementor_draft with _elementor_data
            // Get the freshly updated _elementor_data directly from DB
            $fresh_data = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
                $post_id
            ));

            if (!empty($fresh_data)) {
                // Delete existing draft and recreate with fresh data
                delete_post_meta($post_id, '_elementor_draft');
                update_post_meta($post_id, '_elementor_draft', $fresh_data);
                error_log("MRAF: Force synced _elementor_draft for post {$post_id}");
            }
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

        error_log("MRAF: Cleared Elementor caches for post {$post_id}");
    }

    public static function update_page_builder_urls($old_url, $new_url, $attachment_id = 0)
    {
        global $wpdb;
        $updated_count = 0;

        // Safety check
        if (empty($old_url) || empty($new_url) || $old_url === $new_url) {
            return 0;
        }

        $builders = [
            '_wpb_shortcodes_custom_css' => 'text',
            '_fl_builder_data' => 'serialized',
            '_et_pb_post_hide_nav' => 'text',
            '_fusion' => 'serialized',
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

                    $verify = maybe_unserialize($new_data);
                    if ($verify === false || $verify === $new_data) {
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

    public static function flush_builder_caches($attachment_id = 0)
    {
        global $wpdb;

        // Clear all attachment-specific caches first
        if ($attachment_id > 0) {
            clean_post_cache($attachment_id);

            if (function_exists('clean_attachment_cache')) {
                clean_attachment_cache($attachment_id);
            }

            // Clear attachment caches
            wp_cache_delete($attachment_id, 'post_meta');
            wp_cache_delete($attachment_id, 'posts');
            wp_cache_delete($attachment_id . '_thumbnail_id', 'post_meta');
            wp_cache_delete('image_downsize_' . $attachment_id, 'default');
            wp_cache_delete('attachment_metadata_' . $attachment_id);

            // Clear Elementor-specific attachment caches
            wp_cache_delete('elementor_attachment_' . $attachment_id, 'elementor');
            wp_cache_delete('attachment_' . $attachment_id, 'elementor');
            delete_transient('elementor_media_' . $attachment_id);
            delete_transient('elementor_image_' . $attachment_id);

            // Clear WordPress image caches
            delete_transient('media-library-' . $attachment_id);
            wp_cache_delete($attachment_id, 'attachments');

            // Clear all image size caches
            $sizes = get_intermediate_image_sizes();
            foreach ($sizes as $size) {
                wp_cache_delete('image_downsize_' . $attachment_id . '_' . $size, 'default');
                delete_transient('image_' . $attachment_id . '_' . $size);
            }

            // Force browser cache bust
            wp_update_post([
                'ID' => $attachment_id,
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', 1)
            ]);

            error_log("MRAF: Cleared attachment caches for ID: {$attachment_id}");
        }

        // Elementor cache clearing
        if (did_action('elementor/loaded')) {
            try {
                if (class_exists('\Elementor\Plugin')) {
                    $elementor = \Elementor\Plugin::$instance;

                    if (isset($elementor->files_manager) && method_exists($elementor->files_manager, 'clear_cache')) {
                        $elementor->files_manager->clear_cache();
                    }

                    // Regenerate CSS for all Elementor posts
                    if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                        $elementor_posts = get_posts([
                            'post_type' => 'any',
                            'posts_per_page' => -1,
                            'meta_key' => '_elementor_edit_mode',
                            'meta_value' => 'builder',
                            'fields' => 'ids',
                        ]);

                        foreach ($elementor_posts as $post_id) {
                            try {
                                delete_post_meta($post_id, '_elementor_css');
                                $css_file = new \Elementor\Core\Files\CSS\Post($post_id);
                                $css_file->delete();
                                $css_file->update();
                                clean_post_cache($post_id);
                            } catch (\Throwable $e) {
                            }
                        }
                    }

                    // Clear editor caches
                    delete_transient('elementor_editor_config');
                    delete_transient('elementor_editor_settings');
                }
            } catch (\Throwable $e) {
            }

            if (function_exists('elementor_clear_cache')) {
                elementor_clear_cache();
            }

            do_action('elementor/core/files/clear_cache');

            // Clear ALL Elementor transients
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_elementor_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_elementor_%'");
        }

        // Other page builders
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

        // Clear WordPress caches
        wp_cache_flush();

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // Popular cache plugins
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
            'guid'      => 0,
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

        // 2. Update GUID for attachment if specified
        if ($attachment_id > 0) {
            $guid_result = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET guid = %s WHERE ID = %d",
                $new_url,
                $attachment_id
            ));
            if ($guid_result) {
                $stats['guid'] = 1;
            }
        }

        // 3. Update Elementor data
        $stats['elementor'] = self::update_elementor_urls($old_url, $new_url, $attachment_id);
        $log("Updated {$stats['elementor']} Elementor rows");

        // 4. Update other page builders (Divi, Beaver, etc.)
        $stats['builders'] = self::update_page_builder_urls($old_url, $new_url, $attachment_id);
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
            AND meta_value NOT LIKE 'a:%' AND meta_value NOT LIKE 'O:%' AND meta_value NOT LIKE '{%'",
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
            AND (meta_value LIKE 'a:%' OR meta_value LIKE 'O:%' OR meta_value LIKE '{%')",
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

        // 7. Clear all caches
        self::flush_builder_caches($attachment_id);
        $log("Flushed builder and attachment caches for ID: {$attachment_id}");

        return $stats;
    }

    /**
     * Centralized backup creation.
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function create_backup($attachment_id)
    {
        $backup_dir = WP_CONTENT_DIR . '/mraf-backups/' . $attachment_id;

        if (!wp_mkdir_p($backup_dir)) {
            return false;
        }

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
     * Restore attachment from backup.
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function restore_backup($attachment_id)
    {
        $backup_dir = WP_CONTENT_DIR . '/mraf-backups/' . $attachment_id;
        $json_file = $backup_dir . '/backup.json';

        if (!file_exists($json_file)) {
            return false;
        }

        $data = json_decode(file_get_contents($json_file), true);
        if (!$data || empty($data['original_file'])) {
            return false;
        }

        $orig_file = $data['original_file'];
        $orig_basename = basename($orig_file);
        $backup_orig = $backup_dir . '/' . $orig_basename;

        if (file_exists($backup_orig)) {
            @copy($backup_orig, $orig_file);
            update_attached_file($attachment_id, $orig_file);
        }

        if (!empty($data['original_meta']['sizes']) && is_array($data['original_meta']['sizes'])) {
            $base_dir = dirname($orig_file);
            foreach ($data['original_meta']['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $src = $backup_dir . '/' . $size['file'];
                    if (file_exists($src)) {
                        @copy($src, $base_dir . '/' . $size['file']);
                    }
                }
            }
        }

        if (!empty($data['original_meta'])) {
            wp_update_attachment_metadata($attachment_id, $data['original_meta']);
        }

        self::flush_builder_caches($attachment_id);
        return true;
    }

    /**
     * Delete backup folder for an attachment.
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function delete_backup($attachment_id)
    {
        $backup_dir = WP_CONTENT_DIR . '/mraf-backups/' . $attachment_id;
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
        $base = WP_CONTENT_DIR . '/mraf-backups';
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