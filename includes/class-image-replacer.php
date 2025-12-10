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
}