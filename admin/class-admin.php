<?php
if (!defined('ABSPATH'))
    exit;

class MRAF_Admin
{
    const NONCE_ACTION = 'mraf_replace_file';
    const NONCE_NAME = 'mraf_nonce';
    const NOTICES_KEY = 'mraf_notices';
    const DEBUG_KEY = 'mraf_debug';

    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        add_filter('upload_mimes', [$this, 'allow_webp_mime']);
        add_action('post_edit_form_tag', [$this, 'force_multipart_form']);
        add_action('add_meta_boxes_attachment', [$this, 'add_metaboxes']);
        add_action('load-post.php', [$this, 'maybe_handle_replace_on_edit_screen']);
        add_action('admin_notices', [$this, 'admin_notices']);
    }

    public function allow_webp_mime($mimes)
    {
        $mimes['webp'] = 'image/webp';
        $mimes['avif'] = 'image/avif';
        return $mimes;
    }

    public function force_multipart_form()
    {
        echo ' enctype="multipart/form-data"';
    }

    public function add_metaboxes()
    {
        add_meta_box('mraf_replace_box', __('Replace File (any format)', 'mraf'), [$this, 'render_replace_metabox'], null, 'side', 'high');
        add_meta_box('mraf_debug_box', __('Replace Debug', 'mraf'), [$this, 'render_debug_box'], null, 'normal', 'low');
    }

    public function render_replace_metabox(WP_Post $post)
    {
        if (!current_user_can('upload_files')) {
            echo '<p>' . esc_html__('You do not have permission to replace files.', 'mraf') . '</p>';
            return;
        }

        $url = wp_get_attachment_url($post->ID);
        $file = get_attached_file($post->ID);
        $ext = $file ? pathinfo((string) $file, PATHINFO_EXTENSION) : '';
        $mime = get_post_mime_type($post);
        $uploads = wp_get_upload_dir();
        $old_rel = get_post_meta($post->ID, '_wp_attached_file', true);
        $old_dir_rel = $old_rel ? dirname($old_rel) : '';
        $dir_url = trailingslashit($uploads['baseurl']) . ($old_dir_rel && '.' !== $old_dir_rel ? trailingslashit($old_dir_rel) : '');
        $predicted_base = $old_rel ? pathinfo($old_rel, PATHINFO_FILENAME) : ($file ? pathinfo((string) $file, PATHINFO_FILENAME) : 'file');

        echo '<p><strong>' . esc_html__('Current file', 'mraf') . ":</strong><br>";
        if ($url) echo '<code style="word-break:break-all;display:inline-block;max-width:100%;">' . esc_html($url) . '</code><br>';
        echo '<small>' . esc_html(sprintf('MIME: %s | Ext: .%s', $mime ?: '—', $ext ?: '—')) . '</small></p>';

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        echo '<input type="hidden" name="mraf_do" value="1">';
        echo '<input type="hidden" name="attachment_id" value="' . (int) $post->ID . '">';
        echo '<p><label for="mraf_file"><strong>' . esc_html__('Upload new file (any image format)', 'mraf') . '</strong></label><br>';
        echo '<input type="file" id="mraf_file" name="mraf_file" accept="image/*"></p>';
        echo '<p><label><input type="checkbox" name="mraf_update_refs" value="1" checked> ';
        echo esc_html__('Update references from old URL to new URL (content, meta, options)', 'mraf') . '</label></p>';
        echo '<p><label><input type="checkbox" name="mraf_keep_basename" value="1" checked> ';
        echo esc_html__('Keep the old filename base (only extension changes)', 'mraf') . '</label><br>';
        echo '<small>' . esc_html__('Example: photo.jpg → photo.webp', 'mraf') . '</small></p>';
        echo '<div id="mraf-preview" class="mraf-preview" data-dir-url="' . esc_attr($dir_url) . '" data-old-base="' . esc_attr($predicted_base) . '">';
        echo '<strong>' . esc_html__('New file preview', 'mraf') . '</strong>';
        echo '<p class="mraf-preview__status">' . esc_html__('Choose an image to see its details before replacing.', 'mraf') . '</p>';
        echo '<ul class="mraf-preview__details">';
        echo '<li><span>' . esc_html__('File name:', 'mraf') . '</span> <strong data-preview="name">—</strong></li>';
        echo '<li><span>' . esc_html__('Predicted URL:', 'mraf') . '</span> <a data-preview="url" href="#" target="_blank" rel="noopener noreferrer">—</a></li>';
        echo '<li><span>' . esc_html__('File size:', 'mraf') . '</span> <strong data-preview="size">—</strong></li>';
        echo '<li><span>' . esc_html__('Dimensions:', 'mraf') . '</span> <strong data-preview="dimensions">—</strong></li>';
        echo '</ul></div>';
        echo '<p><button type="submit" class="button button-primary" name="mraf_replace_submit" value="1" form="post">' . esc_html__('Replace File', 'mraf') . '</button></p>';
    }

    public function render_debug_box(WP_Post $post)
    {
        echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px;margin-bottom:12px;">';
        echo '<strong>📊 Current Attachment Status</strong><br>';
        $file = get_attached_file($post->ID);
        $url = wp_get_attachment_url($post->ID);
        $meta = wp_get_attachment_metadata($post->ID);
        $last_replaced = get_post_meta($post->ID, '_mraf_last_replaced', true);
        echo '<table style="width:100%;margin-top:8px;" cellpadding="4">';
        echo '<tr><td style="width:200px;"><strong>File Path:</strong></td><td><code style="font-size:11px;">' . esc_html($file ?: 'NOT SET') . '</code></td></tr>';
        echo '<tr><td><strong>Current URL:</strong></td><td><a href="' . esc_url($url) . '" target="_blank" style="font-size:11px;">' . esc_html($url ?: 'NOT SET') . '</a></td></tr>';
        echo '<tr><td><strong>File Exists:</strong></td><td>' . ($file && file_exists($file) ? '✅ Yes' : '❌ NO - FILE MISSING!') . '</td></tr>';
        echo '<tr><td><strong>Metadata Status:</strong></td><td>' . (!empty($meta) ? '✅ Present (' . count($meta['sizes'] ?? []) . ' sizes)' : '❌ MISSING!') . '</td></tr>';
        echo '<tr><td><strong>Last Replaced:</strong></td><td>' . ($last_replaced ? date('Y-m-d H:i:s', $last_replaced) : 'Never') . '</td></tr>';
        echo '</table></div>';

        echo '<div style="background:#fff;border:1px solid #ddd;padding:12px;margin-top:12px;">';
        echo '<strong>🔍 Replacement Debug Log</strong><br>';
        $dbg = get_transient(self::DEBUG_KEY . '_' . (int) $post->ID);
        echo '<div style="font-family:monospace;white-space:pre-wrap;font-size:11px;max-height:400px;overflow-y:auto;margin-top:8px;background:#f9f9f9;padding:8px;">';
        if ($dbg) {
            foreach ((array) $dbg as $line) {
                if (strpos($line, 'ERROR') !== false || strpos($line, 'CRITICAL') !== false) {
                    echo '<span style="color:#d63638;font-weight:bold;">' . esc_html($line) . '</span>' . "\n";
                } elseif (strpos($line, 'WARNING') !== false) {
                    echo '<span style="color:#dba617;">' . esc_html($line) . '</span>' . "\n";
                } elseif (strpos($line, 'Fresh metadata') !== false || strpos($line, 'successfully') !== false) {
                    echo '<span style="color:#00a32a;font-weight:bold;">' . esc_html($line) . '</span>' . "\n";
                } else {
                    echo esc_html($line) . "\n";
                }
            }
        } else {
            echo esc_html__('No recent replace attempt. After you run Replace, details will appear here.', 'mraf');
        }
        echo '</div></div>';
    }

    private function dbg($attachment_id, $msg)
    {
        $key = self::DEBUG_KEY . '_' . (int) $attachment_id;
        $dbg = get_transient($key);
        $dbg = is_array($dbg) ? $dbg : [];
        $dbg[] = '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $msg;
        set_transient($key, $dbg, 10 * MINUTE_IN_SECONDS);
    }

    public function admin_notices()
    {
        $list = get_transient(self::NOTICES_KEY);
        if (!$list) return;
        delete_transient(self::NOTICES_KEY);
        foreach ($list as $n) {
            $class = ($n['type'] === 'updated') ? 'notice notice-success' : 'notice notice-error';
            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($n['message']) . '</p></div>';
        }
    }

    private function add_notice($type, $message)
    {
        $list = get_transient(self::NOTICES_KEY);
        $list = is_array($list) ? $list : [];
        $list[] = ['type' => $type, 'message' => $message];
        set_transient(self::NOTICES_KEY, $list, 60);
    }

    public function maybe_handle_replace_on_edit_screen()
    {
        if (empty($_POST['mraf_replace_submit']) || empty($_POST['mraf_do'])) return;

        if (!current_user_can('upload_files')) wp_die(__('You do not have permission to replace files.'));
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
            wp_die(__('Security check failed.'));
        }

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$attachment_id || 'attachment' !== get_post_type($attachment_id)) {
            $this->add_notice('error', 'Invalid attachment.');
            return;
        }

        delete_transient(self::DEBUG_KEY . '_' . $attachment_id);
        $this->dbg($attachment_id, 'php post_max_size=' . ini_get('post_max_size') . ' | upload_max_filesize=' . ini_get('upload_max_filesize') . ' | max_file_uploads=' . ini_get('max_file_uploads'));
        if (isset($_SERVER['CONTENT_LENGTH'])) $this->dbg($attachment_id, 'request CONTENT_LENGTH=' . (int) $_SERVER['CONTENT_LENGTH']);

        $update_refs = !empty($_POST['mraf_update_refs']);
        $keep_basename = !empty($_POST['mraf_keep_basename']);

        if (empty($_FILES['mraf_file']['name'])) {
            $this->add_notice('error', 'No file received. Tip: ensure the file size is under post_max_size and upload_max_filesize.');
            $this->dbg($attachment_id, 'No file name present in $_FILES[mraf_file].');
            return;
        }
        if (!is_uploaded_file($_FILES['mraf_file']['tmp_name'])) {
            $this->add_notice('error', 'Upload failed early: temp file missing. Check server permissions and PHP settings.');
            $this->dbg($attachment_id, 'is_uploaded_file() returned false for tmp_name=' . ($_FILES['mraf_file']['tmp_name'] ?? '(none)'));
            return;
        }

        $old_url = wp_get_attachment_url($attachment_id);
        $old_file = get_attached_file($attachment_id);
        $old_meta = wp_get_attachment_metadata($attachment_id);
        $this->dbg($attachment_id, 'Old URL: ' . ($old_url ?: '(none)'));
        $this->dbg($attachment_id, 'Old file: ' . ($old_file ?: '(none)'));

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $overrides = ['test_form' => false, 'mimes' => get_allowed_mime_types()];
        $upload = wp_handle_upload($_FILES['mraf_file'], $overrides);
        if (isset($upload['error'])) {
            $msg = 'Upload failed: ' . sanitize_text_field($upload['error']);
            $this->add_notice('error', $msg);
            $this->dbg($attachment_id, $msg);
            return;
        }

        $new_tmp_file = $upload['file'];
        $new_mime = $upload['type'];
        $this->dbg($attachment_id, 'Uploaded tmp: ' . $new_tmp_file . ' | mime: ' . $new_mime);

        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error'])) {
            @unlink($new_tmp_file);
            $msg = 'Upload dir error: ' . sanitize_text_field($uploads['error']);
            $this->add_notice('error', $msg);
            $this->dbg($attachment_id, $msg);
            return;
        }

        $original_filename = sanitize_file_name($_FILES['mraf_file']['name']);
        $ext_new = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        $uploaded_base = pathinfo($original_filename, PATHINFO_FILENAME);
        $old_rel = get_post_meta($attachment_id, '_wp_attached_file', true);
        $old_dir_rel = $old_rel ? dirname($old_rel) : '';
        $old_base = $old_rel ? pathinfo($old_rel, PATHINFO_FILENAME) : 'file';
        $target_dir = trailingslashit($uploads['basedir']) . ($old_dir_rel && '.' !== $old_dir_rel ? trailingslashit($old_dir_rel) : '');
        if (!wp_mkdir_p($target_dir)) {
            @unlink($new_tmp_file);
            $this->add_notice('error', 'Failed to create target directory: ' . $target_dir);
            $this->dbg($attachment_id, 'mkdir failed: ' . $target_dir);
            return;
        }

        $deleted_files = $this->delete_old_files($old_file, $old_meta);
        if (!empty($deleted_files)) {
            $names = array_map('wp_basename', $deleted_files);
            $this->dbg($attachment_id, sprintf('Removed %d legacy file(s): %s', count($deleted_files), implode(', ', $names)));
        } else {
            $this->dbg($attachment_id, 'No legacy files detected to delete.');
        }

        $base = $keep_basename ? $old_base : $uploaded_base;
        $proposed = $ext_new ? $base . '.' . $ext_new : $base;
        $proposed_path = trailingslashit($target_dir) . $proposed;
        $this->dbg($attachment_id, 'Proposed filename: ' . $proposed);
        $this->dbg($attachment_id, 'Uploaded temp file: ' . $new_tmp_file);
        $this->dbg($attachment_id, 'Proposed path: ' . $proposed_path);

        $tmp_normalized = wp_normalize_path($new_tmp_file);
        $proposed_normalized = wp_normalize_path($proposed_path);
        $this->dbg($attachment_id, 'Normalized temp: ' . $tmp_normalized);
        $this->dbg($attachment_id, 'Normalized proposed: ' . $proposed_normalized);

        if ($tmp_normalized === $proposed_normalized) {
            $final_name = $proposed;
            $final_path = $proposed_path;
            $this->dbg($attachment_id, 'Temp file already has the desired name.');
        } elseif (file_exists($proposed_path)) {
            $existing_normalized = wp_normalize_path(realpath($proposed_path));
            if ($existing_normalized === $tmp_normalized) {
                $final_name = $proposed;
                $final_path = $proposed_path;
                $this->dbg($attachment_id, 'Temp file is the same as existing file with desired name.');
            } else {
                $temp_holder = $new_tmp_file . '.tmp';
                if (@rename($new_tmp_file, $temp_holder)) {
                    $final_name = wp_unique_filename($target_dir, $proposed);
                    $final_path = trailingslashit($target_dir) . $final_name;
                    @rename($temp_holder, $new_tmp_file);
                    $this->dbg($attachment_id, 'Filename conflict detected, using: ' . $final_name);
                } else {
                    $final_name = wp_unique_filename($target_dir, $proposed);
                    $final_path = trailingslashit($target_dir) . $final_name;
                    $this->dbg($attachment_id, 'Using unique filename: ' . $final_name);
                }
            }
        } else {
            $final_name = $proposed;
            $final_path = $proposed_path;
            $this->dbg($attachment_id, 'Using proposed filename: ' . $final_name);
        }

        $this->dbg($attachment_id, 'Target path: ' . $final_path);

        if ($tmp_normalized !== wp_normalize_path($final_path)) {
            if (!@rename($new_tmp_file, $final_path)) {
                $this->dbg($attachment_id, 'rename() failed; trying copy+unlink');
                if (!@copy($new_tmp_file, $final_path)) {
                    @unlink($new_tmp_file);
                    $this->add_notice('error', 'Could not move/copy uploaded file to destination.');
                    $this->dbg($attachment_id, 'copy() failed to: ' . $final_path);
                    return;
                }
                @unlink($new_tmp_file);
            }
        } else {
            $this->dbg($attachment_id, 'No file move needed - already at target path.');
        }

        $this->dbg($attachment_id, 'CRITICAL: Clearing all caches BEFORE metadata update');
        clean_post_cache($attachment_id);
        if (function_exists('clean_attachment_cache')) {
            clean_attachment_cache($attachment_id);
        }
        wp_cache_delete($attachment_id, 'posts');
        wp_cache_delete($attachment_id, 'post_meta');
        delete_post_meta($attachment_id, '_wp_attachment_metadata');
        delete_post_meta($attachment_id, '_wp_attachment_backup_sizes');
        delete_post_meta($attachment_id, '_wp_attached_file');
        wp_cache_flush();
        $this->dbg($attachment_id, 'All old metadata and caches purged');

        $new_rel = ($old_dir_rel && '.' !== $old_dir_rel ? trailingslashit($old_dir_rel) : '') . $final_name;
        update_post_meta($attachment_id, '_wp_attached_file', $new_rel);
        $new_guid = trailingslashit($uploads['baseurl']) . $new_rel;
        wp_update_post(['ID' => $attachment_id, 'post_mime_type' => $new_mime, 'guid' => $new_guid]);
        $this->dbg($attachment_id, 'Core fields updated: ' . $new_rel);

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata($attachment_id, $final_path);
        if (is_wp_error($meta) || empty($meta)) {
            $this->dbg($attachment_id, 'wp_generate_attachment_metadata failed; trying wp_update_image_subsizes');
            if (function_exists('wp_update_image_subsizes')) {
                $ok = wp_update_image_subsizes($attachment_id);
                $this->dbg($attachment_id, 'wp_update_image_subsizes: ' . ($ok ? 'true' : 'false'));
                $meta = wp_get_attachment_metadata($attachment_id);
            }
        }

        if (!empty($meta) && !is_wp_error($meta)) {
            if (empty($meta['file'])) {
                $meta['file'] = $new_rel;
            }
            wp_update_attachment_metadata($attachment_id, $meta);
            $this->dbg($attachment_id, 'Fresh metadata saved with ' . count($meta['sizes'] ?? []) . ' sizes');
        } else {
            $this->dbg($attachment_id, 'No metadata generated; saving minimal meta.');
            wp_update_attachment_metadata($attachment_id, ['file' => $new_rel, 'sizes' => []]);
        }

        clean_post_cache($attachment_id);
        if (function_exists('clean_attachment_cache')) {
            clean_attachment_cache($attachment_id);
        }
        wp_cache_delete($attachment_id, 'posts');
        wp_cache_delete($attachment_id, 'post_meta');
        delete_post_meta($attachment_id, '_wp_attachment_backup_sizes');

        // Force fresh data from database
        $new_meta = wp_get_attachment_metadata($attachment_id, true);
        $new_url = wp_get_attachment_url($attachment_id);

        update_post_meta($attachment_id, '_mraf_last_replaced', time());
        do_action('mraf_after_image_replaced', $attachment_id, $old_url, $new_url);
        wp_cache_flush();
        $this->dbg($attachment_id, 'All caches cleared - metadata and URLs refreshed');

        $new_title = pathinfo($final_name, PATHINFO_FILENAME);
        $new_title = str_replace(['-', '_'], ' ', $new_title);
        wp_update_post(['ID' => $attachment_id, 'post_title' => $new_title, 'post_name' => sanitize_title($new_title)]);
        $this->dbg($attachment_id, 'Attachment title updated to: ' . $new_title);
        $this->dbg($attachment_id, 'New URL: ' . ($new_url ?: '(none)'));

        $replacements = $this->build_url_replacements($old_url, $new_url, $old_meta, $new_meta);
        if ($update_refs && !empty($replacements)) {
            $stats = $this->replace_urls_everywhere($replacements, $attachment_id);
            $this->log_replacement_summary($attachment_id, $stats);
        }

        $cache_instructions = '<strong>✅ Image replaced successfully!</strong><br>';
        if ($update_refs) {
            $cache_instructions .= '• All references updated in database<br>';
        }
        $cache_instructions .= '• WordPress & Elementor caches automatically cleared<br>';
        $cache_instructions .= '• Elementor CSS files automatically regenerated<br>';
        $cache_instructions .= '<br><strong>⚠️ If you still see the old image:</strong><br>';
        $cache_instructions .= '1. Hard refresh browser: <code>Ctrl+F5</code> (Windows) or <code>Cmd+Shift+R</code> (Mac)<br>';
        $cache_instructions .= '2. Clear browser cache completely<br>';
        $cache_instructions .= '3. If using CDN (Cloudflare/WP Rocket), purge CDN cache<br>';
        $cache_instructions .= '4. Wait 2-5 minutes for caches to expire';
        $this->add_notice('updated', $cache_instructions);

        if (function_exists('mraf_flush_usage_cache')) {
            mraf_flush_usage_cache($attachment_id);
        }
    }

    private function delete_old_files($old_file_abs, $old_meta)
    {
        $deleted = [];

        if ($old_file_abs && file_exists($old_file_abs) && @unlink($old_file_abs)) {
            $deleted[] = $old_file_abs;
        }

        if (!empty($old_meta['sizes']) && is_array($old_meta['sizes'])) {
            $dir = pathinfo((string) $old_file_abs, PATHINFO_DIRNAME);
            if ($dir && is_dir($dir)) {
                foreach ($old_meta['sizes'] as $size) {
                    if (!empty($size['file'])) {
                        $p = trailingslashit($dir) . $size['file'];
                        if (file_exists($p) && @unlink($p)) {
                            $deleted[] = $p;
                        }
                    }
                }
            }
        }

        return $deleted;
    }

    private function replace_urls_everywhere(array $replacements, $attachment_id = 0)
    {
        global $wpdb;
        $replacements = array_values(array_filter($replacements, function ($pair) {
            return !empty($pair['old']) && !empty($pair['new']) && $pair['old'] !== $pair['new'];
        }));

        $stats = [
            'pairs' => $replacements,
            'posts' => [],
            'postmeta' => [],
            'options' => [],
            'elementor' => [],
            'builders' => [],
            'counts' => ['posts' => 0, 'postmeta' => 0, 'options' => 0, 'elementor' => 0, 'builders' => 0, 'instances' => 0],
        ];

        if (empty($replacements)) return $stats;

        $search = wp_list_pluck($replacements, 'old');
        $replace = wp_list_pluck($replacements, 'new');
        $likes = array_map(function ($old) use ($wpdb) {
            return '%' . $wpdb->esc_like($old) . '%';
        }, $search);

        $anything_changed = false;

        foreach ($replacements as $pair) {
            $elementor_count = MRAF_Image_Replacer::update_elementor_urls($pair['old'], $pair['new'], $attachment_id);
            $stats['counts']['elementor'] += $elementor_count;
            if ($elementor_count > 0) {
                $stats['elementor'][] = ['old_url' => $pair['old'], 'new_url' => $pair['new'], 'updates' => $elementor_count];
                $anything_changed = true;
            }

            $builder_count = MRAF_Image_Replacer::update_page_builder_urls($pair['old'], $pair['new'], $attachment_id);
            $stats['counts']['builders'] += $builder_count;
            if ($builder_count > 0) {
                $stats['builders'][] = ['old_url' => $pair['old'], 'new_url' => $pair['new'], 'updates' => $builder_count];
                $anything_changed = true;
            }
        }

        $content_where = implode(' OR ', array_fill(0, count($likes), 'post_content LIKE %s'));
        if ($content_where) {
            $rows = $wpdb->get_results($wpdb->prepare("
                SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
                WHERE post_status IN ('publish','draft','private','future','pending','inherit')
                  AND post_type != 'revision' AND ( $content_where )
            ", ...$likes));
            foreach ($rows as $r) {
                $content = str_replace($search, $replace, $r->post_content, $count);
                if ($count > 0) {
                    wp_update_post(['ID' => (int) $r->ID, 'post_content' => $content]);
                    $anything_changed = true;
                    $stats['posts'][] = ['ID' => (int) $r->ID, 'title' => $r->post_title, 'type' => $r->post_type, 'matches' => $count];
                    $stats['counts']['posts']++;
                    $stats['counts']['instances'] += $count;
                }
            }
        }

        $meta_where = implode(' OR ', array_fill(0, count($likes), 'pm.meta_value LIKE %s'));
        $excluded_meta_keys = ['_elementor_data', '_elementor_draft', '_elementor_css', '_elementor_page_settings', '_elementor_controls_usage', '_elementor_version', 'elementor_data', '_wpb_shortcodes_custom_css', '_fl_builder_data', '_fusion'];
        $exclude_conditions = [];
        foreach ($excluded_meta_keys as $key) {
            $exclude_conditions[] = $wpdb->prepare("pm.meta_key != %s", $key);
        }
        $exclude_conditions[] = "pm.meta_key NOT LIKE '_et_pb_%'";
        $exclude_sql = implode(' AND ', $exclude_conditions);

        if ($meta_where) {
            $metas = $wpdb->get_results($wpdb->prepare("
                SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type
                FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE ( $meta_where ) AND {$exclude_sql}
            ", ...$likes));
            foreach ($metas as $m) {
                $raw = (string) $m->meta_value;
                $changed = false;
                $replaced_count = 0;
                $new = MRAF_Image_Replacer::replace_maybe_serialized($raw, $search, $replace, $changed, $replaced_count);
                if ($changed) {
                    $wpdb->update($wpdb->postmeta, ['meta_value' => $new], ['meta_id' => (int) $m->meta_id]);
                    if (isset($m->post_id)) clean_post_cache((int) $m->post_id);
                    $anything_changed = true;
                    $matches = max(1, (int) $replaced_count);
                    $stats['postmeta'][] = ['meta_id' => (int) $m->meta_id, 'post_id' => isset($m->post_id) ? (int) $m->post_id : 0, 'meta_key' => $m->meta_key, 'post_title' => $m->post_title ?? '', 'post_type' => $m->post_type ?? '', 'matches' => $matches];
                    $stats['counts']['postmeta']++;
                    $stats['counts']['instances'] += $matches;
                }
            }
        }

        $safe_option_prefixes = ['widget_', 'sidebars_widgets', '_transient_', 'acf_', 'options_'];
        $safe_option_names = ['upload_path', 'upload_url_path'];

        $option_where = implode(' OR ', array_fill(0, count($likes), 'option_value LIKE %s'));
        if ($option_where) {
            $opts = $wpdb->get_results($wpdb->prepare("
                SELECT option_id, option_name, option_value FROM {$wpdb->options}
                WHERE ( $option_where )
            ", ...$likes));
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

                $excluded_options = ['theme_mods_', 'custom_css_', 'nav_menu_', 'category_children', 'rewrite_rules'];
                $is_excluded = false;
                foreach ($excluded_options as $excluded) {
                    if (strpos($option_name, $excluded) === 0) {
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
                $new = MRAF_Image_Replacer::replace_maybe_serialized($raw, $search, $replace, $changed, $replaced_count);
                if ($changed) {
                    $wpdb->update($wpdb->options, ['option_value' => $new], ['option_id' => (int) $o->option_id]);
                    if (!empty($o->option_name)) wp_cache_delete($o->option_name, 'options');
                    $anything_changed = true;
                    $matches = max(1, (int) $replaced_count);
                    $stats['options'][] = ['option_name' => $o->option_name, 'matches' => $matches];
                    $stats['counts']['options']++;
                    $stats['counts']['instances'] += $matches;
                }
            }
        }

        if ($anything_changed) MRAF_Image_Replacer::flush_builder_caches($attachment_id);

        return $stats;
    }

    private function log_replacement_summary($attachment_id, array $stats)
    {
        $pairs = $stats['pairs'] ?? [];
        $pair_total = count($pairs);
        if ($pair_total) {
            $this->dbg($attachment_id, sprintf('Replacement pairs (%d):', $pair_total));
            foreach (array_slice($pairs, 0, 8) as $pair) {
                $this->dbg($attachment_id, sprintf('  - %s -> %s', $pair['old'], $pair['new']));
            }
            if ($pair_total > 8) {
                $this->dbg($attachment_id, sprintf('  - ...and %d more pair(s)', $pair_total - 8));
            }
        }

        $counts = $stats['counts'] ?? ['posts' => 0, 'postmeta' => 0, 'options' => 0, 'elementor' => 0, 'builders' => 0, 'instances' => 0];
        $this->dbg($attachment_id, sprintf('Content updated: %d post(s), %d meta row(s), %d option(s), %d Elementor page(s), %d page builder(s) | Total replacements: %d',
            $counts['posts'] ?? 0, $counts['postmeta'] ?? 0, $counts['options'] ?? 0, $counts['elementor'] ?? 0, $counts['builders'] ?? 0, $counts['instances'] ?? 0));

        if (!empty($stats['posts'])) {
            $labels = array_map(function ($post) {
                return sprintf('#%d %s (%s, %d matches)', $post['ID'], $post['title'] !== '' ? $post['title'] : '(no title)', $post['type'] ?: 'post', $post['matches'] ?? 1);
            }, $stats['posts']);
            $this->dbg($attachment_id, 'Posts touched: ' . implode('; ', $labels));
        } else {
            $this->dbg($attachment_id, 'Posts touched: none.');
        }

        if (!empty($stats['postmeta'])) {
            $labels = array_map(function ($meta) {
                $post_ref = $meta['post_id'] ? sprintf('post #%d', $meta['post_id']) : 'global';
                return sprintf('meta_id %d (%s, %s, %d matches)', $meta['meta_id'], $meta['meta_key'] ?: '(key missing)', $post_ref, $meta['matches'] ?? 1);
            }, $stats['postmeta']);
            $this->dbg($attachment_id, 'Meta rows touched: ' . implode('; ', $labels));
        } else {
            $this->dbg($attachment_id, 'Meta rows touched: none.');
        }

        if (!empty($stats['options'])) {
            $labels = array_map(function ($option) {
                return sprintf('%s (%d matches)', $option['option_name'] ?: '(unnamed option)', $option['matches'] ?? 1);
            }, $stats['options']);
            $this->dbg($attachment_id, 'Options touched: ' . implode('; ', $labels));
        } else {
            $this->dbg($attachment_id, 'Options touched: none.');
        }
    }

    private function build_url_replacements($old_url, $new_url, $old_meta, $new_meta)
    {
        $map = [];

        if ($old_url && $new_url && $old_url !== $new_url) {
            $map[$old_url] = $new_url;
        }

        $old_dir = $this->get_url_directory($old_url);
        $new_dir = $this->get_url_directory($new_url);

        if ($old_dir && !empty($old_meta['sizes'])) {
            foreach ($old_meta['sizes'] as $size_key => $info) {
                if (empty($info['file'])) continue;
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

    private function get_url_directory($url)
    {
        if (!$url) return '';
        $pos = strrpos($url, '/');
        if (false === $pos) return '';
        return substr($url, 0, $pos + 1);
    }
}
