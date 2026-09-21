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
        if ($url)
            echo '<code style="word-break:break-all;display:inline-block;max-width:100%;">' . esc_html($url) . '</code><br>';
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
        if (!$list)
            return;
        delete_transient(self::NOTICES_KEY);
        foreach ($list as $n) {
            $class = ($n['type'] === 'updated') ? 'notice notice-success' : 'notice notice-error';
            // The messages are written here, not by a user, and they carry
            // line breaks and emphasis that esc_html() would print as tags.
            echo '<div class="' . esc_attr($class) . '"><p>' . wp_kses_post($n['message']) . '</p></div>';
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
        if (empty($_POST['mraf_replace_submit']) || empty($_POST['mraf_do']))
            return;

        if (!current_user_can('upload_files'))
            wp_die(__('You do not have permission to replace files.'));
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
        if (isset($_SERVER['CONTENT_LENGTH']))
            $this->dbg($attachment_id, 'request CONTENT_LENGTH=' . (int) $_SERVER['CONTENT_LENGTH']);

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

        // CRITICAL: Get original values DIRECTLY from database to avoid caching issues
        global $wpdb;
        $uploads = wp_get_upload_dir();
        $uploads_basedir = trailingslashit($uploads['basedir']);
        $uploads_baseurl = trailingslashit($uploads['baseurl']);

        // Get fresh values using raw SQL to completely bypass WordPress caching
        $old_rel = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attached_file' LIMIT 1",
            $attachment_id
        ));

        // Get metadata directly from DB
        $old_meta_raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attachment_metadata' LIMIT 1",
            $attachment_id
        ));
        $old_meta = $old_meta_raw ? maybe_unserialize($old_meta_raw) : [];

        // Build file path and URL from the rel path we just fetched
        $old_file = $old_rel ? $uploads_basedir . $old_rel : '';
        $old_url = $old_rel ? $uploads_baseurl . $old_rel : '';

        $this->dbg($attachment_id, 'CAPTURED Original rel path (from DB): ' . $old_rel);
        $this->dbg($attachment_id, 'CAPTURED Original file (built): ' . $old_file);
        $this->dbg($attachment_id, 'CAPTURED Original URL (built): ' . $old_url);

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
        $old_dir_rel = $old_rel ? dirname($old_rel) : '';
        $old_base = $old_rel ? pathinfo($old_rel, PATHINFO_FILENAME) : 'file';
        $target_dir = trailingslashit($uploads['basedir']) . ($old_dir_rel && '.' !== $old_dir_rel ? trailingslashit($old_dir_rel) : '');
        if (!wp_mkdir_p($target_dir)) {
            @unlink($new_tmp_file);
            $this->add_notice('error', 'Failed to create target directory: ' . $target_dir);
            $this->dbg($attachment_id, 'mkdir failed: ' . $target_dir);
            return;
        }

        // A backup before the first byte changes, so a failure halfway through
        // is recoverable.
        $backed_up = false;

        if (class_exists('MRAF_Media_Studio')) {
            $options = MRAF_Media_Studio::get_options();

            if (!empty($options['create_backup'])) {
                $backed_up = MRAF_Image_Replacer::create_backup($attachment_id);
                $this->dbg($attachment_id, 'Backup before replacing: ' . ($backed_up ? 'written' : 'failed'));
            } else {
                $this->dbg($attachment_id, 'Backups are switched off in Settings.');
            }
        }

        // The old files are only listed here. Nothing is removed until the
        // replacement is safely on disk.
        $old_files = array_map('wp_normalize_path', $this->collect_old_files($old_file, $old_meta));

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

        // A name held by one of the files this replacement retires is free:
        // those files go before the new one takes their place. Any other
        // occupant belongs to a different attachment and is left alone.
        if ($tmp_normalized === $proposed_normalized) {
            $final_name = $proposed;
            $final_path = $proposed_path;
            $this->dbg($attachment_id, 'Temp file already has the desired name.');
        } elseif (file_exists($proposed_path) && !in_array($proposed_normalized, $old_files, true)) {
            $final_name = wp_unique_filename($target_dir, $proposed);
            $final_path = trailingslashit($target_dir) . $final_name;
            $this->dbg($attachment_id, 'Name taken by another file, using: ' . $final_name);
        } else {
            $final_name = $proposed;
            $final_path = $proposed_path;
            $this->dbg($attachment_id, 'Using proposed filename: ' . $final_name);
        }

        $this->dbg($attachment_id, 'Target path: ' . $final_path);

        // Stage the upload beside its destination first. The old files are
        // removed only once the new one exists on disk, so an upload that
        // cannot be written never leaves the attachment with no file at all.
        $staged_path = $final_path . '.mraf-new';

        if ($tmp_normalized === wp_normalize_path($final_path)) {
            $staged_path = $final_path;
            $this->dbg($attachment_id, 'No file move needed - already at target path.');
        } elseif (!@rename($new_tmp_file, $staged_path)) {
            $this->dbg($attachment_id, 'rename() failed; trying copy+unlink');

            if (!@copy($new_tmp_file, $staged_path)) {
                @unlink($new_tmp_file);
                $this->add_notice('error', 'Could not move the uploaded file into place. Nothing was changed.');
                $this->dbg($attachment_id, 'copy() failed to: ' . $staged_path);
                return;
            }

            @unlink($new_tmp_file);
        }

        $deleted_files = $this->delete_old_files($old_file, $old_meta, $staged_path);

        if (!empty($deleted_files)) {
            $names = array_map('wp_basename', $deleted_files);
            $this->dbg($attachment_id, sprintf('Removed %d legacy file(s): %s', count($deleted_files), implode(', ', $names)));
        } else {
            $this->dbg($attachment_id, 'No legacy files detected to delete.');
        }

        if ($staged_path !== $final_path && !@rename($staged_path, $final_path)) {
            @unlink($staged_path);
            $this->dbg($attachment_id, 'CRITICAL: could not move staged file to ' . $final_path);

            if ($backed_up && MRAF_Image_Replacer::restore_backup($attachment_id)) {
                $this->add_notice('error', 'Could not put the new file in place. The original was restored from its backup.');
                $this->dbg($attachment_id, 'Original restored from backup.');
            } else {
                $this->add_notice('error', 'Could not put the new file in place, and there was no backup to restore.');
            }

            return;
        }

        $adopted  = MRAF_Image_Replacer::adopt_file($attachment_id, $final_path, 'mraf_replaced');
        $new_meta = $adopted['meta'];
        $new_url  = $adopted['url'];

        $this->dbg($attachment_id, 'Attachment now points at ' . $adopted['rel']);
        $this->dbg($attachment_id, 'Fresh metadata saved with ' . count($new_meta['sizes'] ?? []) . ' sizes');

        update_post_meta($attachment_id, '_mraf_last_replaced', time());
        do_action('mraf_after_image_replaced', $attachment_id, $old_url, $new_url);

        $new_title = pathinfo($final_name, PATHINFO_FILENAME);
        $new_title = str_replace(['-', '_'], ' ', $new_title);
        wp_update_post(['ID' => $attachment_id, 'post_title' => $new_title, 'post_name' => sanitize_title($new_title)]);
        $this->dbg($attachment_id, 'Attachment title updated to: ' . $new_title);
        $this->dbg($attachment_id, 'REPLACEMENT: Old URL: ' . $old_url);
        $this->dbg($attachment_id, 'REPLACEMENT: New URL: ' . $new_url);

        $replacements = MRAF_Image_Replacer::build_url_replacements($old_url, $new_url, $old_meta, $new_meta);
        if ($update_refs && !empty($replacements)) {
            $this->dbg($attachment_id, 'Total URL pairs to replace: ' . count($replacements));
            $stats = $this->replace_urls_everywhere($replacements, $attachment_id);
            $this->log_replacement_summary($attachment_id, $stats);
        } else {
            $this->dbg($attachment_id, 'Skipping URL reference updates - Old URL: ' . ($old_url ?: '(empty)') . ', New URL: ' . ($new_url ?: '(empty)') . ', Same: ' . ($old_url === $new_url ? 'yes' : 'no'));
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

        MRAF_Image_Usage::flush_usage_cache($attachment_id);
    }

    /**
     * Every file the attachment currently owns: the original and its sizes.
     *
     * @param string $old_file_abs Absolute path to the attached file.
     * @param array  $old_meta     Attachment metadata.
     * @return array Absolute paths that exist on disk.
     */
    private function collect_old_files($old_file_abs, $old_meta)
    {
        $files = [];

        if ($old_file_abs && file_exists($old_file_abs)) {
            $files[] = $old_file_abs;
        }

        if (!empty($old_meta['sizes']) && is_array($old_meta['sizes'])) {
            $dir = pathinfo((string) $old_file_abs, PATHINFO_DIRNAME);

            if ($dir && is_dir($dir)) {
                foreach ($old_meta['sizes'] as $size) {
                    if (empty($size['file'])) {
                        continue;
                    }

                    $path = trailingslashit($dir) . $size['file'];

                    if (file_exists($path)) {
                        $files[] = $path;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * Remove the files the attachment is leaving behind.
     *
     * @param string $old_file_abs Absolute path to the attached file.
     * @param array  $old_meta     Attachment metadata.
     * @param string $keep_path    A path to spare, normally the new file.
     * @return array Absolute paths that were deleted.
     */
    private function delete_old_files($old_file_abs, $old_meta, $keep_path = '')
    {
        $deleted = [];
        $keep    = $keep_path ? wp_normalize_path($keep_path) : '';

        foreach ($this->collect_old_files($old_file_abs, $old_meta) as $path) {
            if ($keep && wp_normalize_path($path) === $keep) {
                continue;
            }

            if (@unlink($path)) {
                $deleted[] = $path;
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
            'pairs'  => $replacements,
            'counts' => ['posts' => 0, 'postmeta' => 0, 'options' => 0, 'elementor' => 0, 'builders' => 0, 'instances' => 0],
        ];

        if (empty($replacements))
            return $stats;

        foreach ($replacements as $pair) {
            $this->dbg($attachment_id, 'Processing pair: ' . $pair['old'] . ' -> ' . $pair['new']);

            $res = MRAF_Image_Replacer::update_all_references(
                $pair['old'],
                $pair['new'],
                $attachment_id,
                function ($msg) use ($attachment_id) {
                    $this->dbg($attachment_id, $msg);
                }
            );

            $stats['counts']['posts']     += $res['posts'];
            $stats['counts']['postmeta']  += $res['meta'];
            $stats['counts']['elementor'] += $res['elementor'];
            $stats['counts']['builders']  += $res['builders'];
            $stats['counts']['options']   += $res['options'];
            $stats['counts']['instances'] += array_sum($res);
        }

        return $stats;
    }

    private function log_replacement_summary($attachment_id, array $stats)
    {
        $pairs      = $stats['pairs'] ?? [];
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

        // The replacer reports how many rows each area changed, not which
        // ones, so the log says exactly that and no more.
        $counts = $stats['counts'] ?? [];

        $this->dbg($attachment_id, sprintf(
            'Content updated: %d post(s), %d meta row(s), %d option(s), %d Elementor page(s), %d page builder(s) | Total replacements: %d',
            (int) ($counts['posts'] ?? 0),
            (int) ($counts['postmeta'] ?? 0),
            (int) ($counts['options'] ?? 0),
            (int) ($counts['elementor'] ?? 0),
            (int) ($counts['builders'] ?? 0),
            (int) ($counts['instances'] ?? 0)
        ));
    }
}
