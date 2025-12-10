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

        // Strip query parameters from URLs for searching (Elementor stores base URLs without ?mraf= params)
        $old_url_base = $old_url ? strtok($old_url, '?') : '';
        $new_url_base = $new_url ? strtok($new_url, '?') : '';

        // Safety check - if URLs are invalid, return early
        if (empty($old_url_base) || empty($new_url_base)) {
            error_log("MRAF: Invalid URLs provided to update_elementor_urls - Old: '{$old_url}', New: '{$new_url}'");
            return 0;
        }

        error_log("MRAF: Elementor URL replacement - Old base: '{$old_url_base}', New base: '{$new_url_base}'");

        // Update both published data AND draft data
        $meta_keys = ['_elementor_data', '_elementor_draft'];

        foreach ($meta_keys as $meta_key) {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_id, meta_value
                FROM {$wpdb->postmeta}
                WHERE meta_key = %s
                AND meta_value LIKE %s",
                $meta_key,
                '%' . $wpdb->esc_like($old_url_base) . '%'
            ));

            error_log("MRAF: Searching {$meta_key} for '{$old_url_base}' - Found " . count($results) . " rows");

            // Additional debug: Show what we're actually searching for
            if (count($results) > 0) {
                error_log("MRAF: Sample of first result - Post ID: {$results[0]->post_id}, Data length: " . strlen($results[0]->meta_value));
            }

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
                    continue;
                }

                $new_decoded = self::recursive_url_replace([$old_url_base], [$new_url_base], $decoded);

                if (!is_array($new_decoded) || empty($new_decoded)) {
                    continue;
                }

                $new_data = wp_json_encode($new_decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                if ($new_data === false || $new_data === null) {
                    continue;
                }

                $new_length = strlen($new_data);
                $length_diff_percent = abs($new_length - $old_length) / $old_length * 100;

                if ($length_diff_percent > 50) {
                    continue;
                }

                $verify = json_decode($new_data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                $old_count = count($decoded);
                $new_count = count($verify);
                if ($old_count !== $new_count) {
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
                        error_log("MRAF: Updated {$meta_key} for post {$post_id} - Replaced '{$old_url_base}' with '{$new_url_base}'");

                        // Clear ALL Elementor caches for this post
                        delete_post_meta($post_id, '_elementor_css');
                        delete_metadata('post', $post_id, '_elementor_css', '', false);

                        // Clear Elementor draft and autosave data
                        delete_post_meta($post_id, '_elementor_draft_status');

                        // Force Elementor to reload data from DB
                        wp_cache_delete($post_id . '_elementor', 'elementor');
                        wp_cache_delete('elementor_' . $post_id, 'options');
                    } else {
                        error_log("MRAF: FAILED to update {$meta_key} for post {$post_id}");
                    }
                } else {
                    error_log("MRAF: No changes needed for {$meta_key} post {$post_id} (URLs already match)");
                }
            }
        }

        // Force sync draft with published data for all affected posts
        if ($updated_count > 0) {
            self::sync_elementor_draft_with_data();
        }

        return $updated_count;
    }

    /**
     * Sync Elementor draft with published data
     * This ensures the editor shows the latest changes
     */
    private static function sync_elementor_draft_with_data()
    {
        global $wpdb;

        // Find all posts with _elementor_data but no _elementor_draft
        $posts_needing_draft = $wpdb->get_results("
            SELECT p.post_id, p.meta_value as elementor_data
            FROM {$wpdb->postmeta} p
            WHERE p.meta_key = '_elementor_data'
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} d
                WHERE d.post_id = p.post_id
                AND d.meta_key = '_elementor_draft'
            )
            LIMIT 50
        ");

        foreach ($posts_needing_draft as $post) {
            // Copy _elementor_data to _elementor_draft
            add_post_meta($post->post_id, '_elementor_draft', $post->elementor_data, true);
            error_log("MRAF: Created _elementor_draft for post {$post->post_id}");
        }
    }

    public static function update_page_builder_urls($old_url, $new_url, $attachment_id = 0)
    {
        global $wpdb;
        $updated_count = 0;

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
                $length_diff_percent = abs($new_length - $old_length) / $old_length * 100;

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
        // Clear all attachment-specific caches first
        if ($attachment_id > 0) {
            clean_post_cache($attachment_id);

            if (function_exists('clean_attachment_cache')) {
                clean_attachment_cache($attachment_id);
            }

            // Clear attachment file path cache
            wp_cache_delete($attachment_id, 'post_meta');
            wp_cache_delete($attachment_id, 'posts');

            // Clear thumbnail cache
            wp_cache_delete($attachment_id . '_thumbnail_id', 'post_meta');

            // Clear image downsize cache (for responsive images)
            wp_cache_delete('image_downsize_' . $attachment_id, 'default');

            // Force regenerate attachment metadata from fresh DB query
            wp_cache_delete('attachment_metadata_' . $attachment_id);

            // Clear Elementor-specific attachment caches (for image picker)
            wp_cache_delete('elementor_attachment_' . $attachment_id, 'elementor');
            wp_cache_delete('attachment_' . $attachment_id, 'elementor');
            delete_transient('elementor_media_' . $attachment_id);

            // Force browser cache bust by updating attachment modified time
            wp_update_post([
                'ID' => $attachment_id,
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', 1)
            ]);
        }

        // Elementor cache clearing
        if (did_action('elementor/loaded')) {
            try {
                if (class_exists('\Elementor\Plugin')) {
                    $elementor = \Elementor\Plugin::$instance;

                    if (isset($elementor->files_manager) && method_exists($elementor->files_manager, 'clear_cache')) {
                        $elementor->files_manager->clear_cache();
                    }

                    if (isset($elementor->post_css_manager) && method_exists($elementor->post_css_manager, 'clear_cache')) {
                        $elementor->post_css_manager->clear_cache();
                    }

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
                                // Delete Elementor CSS cache
                                delete_post_meta($post_id, '_elementor_css');
                                delete_metadata('post', $post_id, '_elementor_css', '', false);

                                // Regenerate CSS files
                                $css_file = new \Elementor\Core\Files\CSS\Post($post_id);
                                $css_file->delete();
                                $css_file->update();

                                // Clear post cache to force reload
                                clean_post_cache($post_id);
                            } catch (\Throwable $e) {
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
            }

            if (function_exists('elementor_clear_cache')) {
                elementor_clear_cache();
            }

            do_action('elementor/core/files/clear_cache');
        }

        // WPBakery Page Builder
        if (function_exists('wpb_js_remove_wpautop')) {
            delete_transient('vc_license');

            // Clear WPBakery cache
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpb_vc_css%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wpb_vc_css%'");
        }

        // Beaver Builder
        if (class_exists('FLBuilderModel')) {
            try {
                \FLBuilderModel::delete_asset_cache_for_all_posts();
            } catch (\Throwable $e) {
            }
        }

        // Divi Builder
        if (function_exists('et_core_clear_wp_cache')) {
            et_core_clear_wp_cache();
        }

        // Clear Divi static CSS files
        if (function_exists('et_pb_clear_builder_cache')) {
            et_pb_clear_builder_cache();
        }

        // Clear WordPress object cache
        wp_cache_flush();

        // Clear opcode cache if available
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // Clear APCu cache if available
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }

        // WP Rocket cache
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        // LiteSpeed Cache
        if (class_exists('LiteSpeed\Purge')) {
            do_action('litespeed_purge_all');
        }

        // Autoptimize
        if (class_exists('autoptimizeCache')) {
            try {
                \autoptimizeCache::clearall();
            } catch (\Throwable $e) {
            }
        }

        // Clear all transients related to images
        delete_transient('dirsize_cache');

        // Trigger action for third-party cache plugins
        do_action('mraf_after_cache_flush', $attachment_id);
    }

}
