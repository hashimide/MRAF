<?php
if (!defined('ABSPATH'))
    exit;

class MRAF_Bulk_Replace
{
    const BACKUP_DIR = 'mraf-backups';
    const DEBUG_KEY = 'mraf_bulk_debug';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_mraf_bulk_upload_files', [$this, 'ajax_upload_files']);
        add_action('wp_ajax_mraf_bulk_replace_single', [$this, 'ajax_replace_single']);
        add_action('wp_ajax_mraf_bulk_search_images', [$this, 'ajax_search_images']);
        add_action('wp_ajax_mraf_get_bulk_debug_log', [$this, 'ajax_get_debug_log']);
    }

    public function add_admin_menu()
    {
        add_media_page(
            __('Bulk Replace Images', 'mraf'),
            __('Bulk Replace', 'mraf'),
            'upload_files',
            'mraf-bulk-replace',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_scripts($hook)
    {
        if ($hook !== 'media_page_mraf-bulk-replace') {
            return;
        }

        wp_enqueue_media();

        $bulk_js_version = @filemtime(MRAF_PLUGIN_DIR . 'admin/js/mraf-bulk-replace.js') ?: MRAF_VERSION;
        $bulk_css_version = @filemtime(MRAF_PLUGIN_DIR . 'admin/css/mraf-bulk-replace.css') ?: MRAF_VERSION;

        wp_enqueue_script('mraf-bulk-replace', MRAF_PLUGIN_URL . 'admin/js/mraf-bulk-replace.js', ['jquery', 'jquery-ui-sortable'], $bulk_js_version, true);
        wp_enqueue_style('mraf-bulk-replace', MRAF_PLUGIN_URL . 'admin/css/mraf-bulk-replace.css', [], $bulk_css_version);

        wp_localize_script('mraf-bulk-replace', 'mrafBulkData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mraf_bulk_replace'),
            'confirmReplace' => __('Are you sure you want to replace this image?', 'mraf'),
            'confirmReplaceAll' => __('Are you sure you want to replace all matched images?', 'mraf'),
            'uploadError' => __('Upload failed. Please try again.', 'mraf'),
            'replaceSuccess' => __('Image replaced successfully!', 'mraf'),
            'replaceError' => __('Failed to replace image.', 'mraf'),
            'debugEnabled' => true,
        ]);
    }

    public function render_admin_page()
    {
        ?>
        <div class="wrap mraf-bulk-page">
            <h1><?php esc_html_e('Bulk Replace Images', 'mraf'); ?></h1>
            <div class="mraf-bulk-tabs">
                <button class="mraf-tab-btn active" data-tab="manual"><?php esc_html_e('Manual Selection', 'mraf'); ?></button>
                <button class="mraf-tab-btn" data-tab="search"><?php esc_html_e('Search & Filter', 'mraf'); ?></button>
                <button class="mraf-tab-btn" data-tab="debug"><?php esc_html_e('Debug Log', 'mraf'); ?></button>
            </div>
            <div class="mraf-tab-content active" data-tab="manual">
                <div class="mraf-actions">
                    <button type="button" class="mraf-btn mraf-btn-secondary" id="mraf-add-row">
                        <span class="dashicons dashicons-plus"></span>
                        <?php esc_html_e('Add Image Row', 'mraf'); ?>
                    </button>
                    <button type="button" class="mraf-btn mraf-btn-secondary" id="mraf-clear-all-rows" style="display:none;">
                        <span class="dashicons dashicons-trash"></span>
                        <?php esc_html_e('Clear All Rows', 'mraf'); ?>
                    </button>
                    <button type="button" class="mraf-btn mraf-btn-primary" id="mraf-replace-all" style="display:none;">
                        <?php esc_html_e('Replace All Matched', 'mraf'); ?>
                    </button>
                </div>
                <div class="mraf-options-bar">
                    <label><input type="checkbox" id="mraf-global-keep-basename"><?php esc_html_e('Keep original filenames (only change extension)', 'mraf'); ?></label>
                    <label><input type="checkbox" id="mraf-global-update-refs" checked><?php esc_html_e('Update references in content', 'mraf'); ?></label>
                    <label><input type="checkbox" id="mraf-global-create-backup" checked><?php esc_html_e('Create backups before replacing', 'mraf'); ?></label>
                </div>
                <div id="mraf-replace-list" class="mraf-replace-list"></div>
            </div>
            <div class="mraf-tab-content" data-tab="search">
                <div class="mraf-search-box">
                    <h3><?php esc_html_e('Find Images to Replace', 'mraf'); ?></h3>
                    <div class="mraf-search-filters">
                        <input type="text" id="mraf-search-query" placeholder="<?php esc_attr_e('Search by image title or alt text...', 'mraf'); ?>" class="mraf-input">
                        <select id="mraf-search-type" class="mraf-select">
                            <option value=""><?php esc_html_e('All Types', 'mraf'); ?></option>
                            <option value="image/jpeg">jpg / jpeg</option>
                            <option value="image/png">png</option>
                            <option value="image/webp">webp</option>
                            <option value="image/gif">gif</option>
                            <option value="image/svg+xml">svg</option>
                            <option value="image/avif">avif</option>
                        </select>
                        <select id="mraf-search-date" class="mraf-select">
                            <option value=""><?php esc_html_e('All Dates', 'mraf'); ?></option>
                            <option value="today"><?php esc_html_e('Today', 'mraf'); ?></option>
                            <option value="week"><?php esc_html_e('This Week', 'mraf'); ?></option>
                            <option value="month"><?php esc_html_e('This Month', 'mraf'); ?></option>
                            <option value="year"><?php esc_html_e('This Year', 'mraf'); ?></option>
                        </select>
                        <button type="button" class="mraf-btn mraf-btn-primary" id="mraf-search-btn">
                            <span class="dashicons dashicons-search"></span>
                            <?php esc_html_e('Search', 'mraf'); ?>
                        </button>
                    </div>
                    <div id="mraf-search-results" class="mraf-search-results"></div>
                </div>
            </div>
            <div class="mraf-tab-content" data-tab="debug">
                <div class="mraf-debug-box">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h3 style="margin: 0;"><?php esc_html_e('Debug Log', 'mraf'); ?></h3>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="mraf-btn mraf-btn-secondary" id="mraf-refresh-log">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e('Refresh', 'mraf'); ?>
                            </button>
                            <button type="button" class="mraf-btn mraf-btn-secondary" id="mraf-clear-log">
                                <span class="dashicons dashicons-trash"></span>
                                <?php esc_html_e('Clear Log', 'mraf'); ?>
                            </button>
                        </div>
                    </div>
                    <div id="mraf-debug-log" style="font-family:monospace;white-space:pre-wrap;background:#f8f9fa;border:1px solid #ddd;padding:16px;border-radius:4px;max-height:600px;overflow-y:auto;">
                        <?php
                        $dbg = get_transient(self::DEBUG_KEY);
                        if ($dbg && is_array($dbg)) {
                            foreach ($dbg as $line) {
                                echo esc_html($line) . "\n";
                            }
                        } else {
                            echo esc_html__('No debug logs yet. Logs will appear here after upload/replace operations.', 'mraf');
                        }
                        ?>
                    </div>
                </div>
            </div>
            <div id="mraf-progress-modal" class="mraf-modal" style="display:none;">
                <div class="mraf-modal-content">
                    <h2><?php esc_html_e('Replacing Images...', 'mraf'); ?></h2>
                    <div class="mraf-progress-bar"><div class="mraf-progress-fill" id="mraf-progress-fill"></div></div>
                    <p id="mraf-progress-text">0 / 0</p>
                    <div id="mraf-progress-log"></div>
                </div>
            </div>
        </div>
        <?php
    }

    private function dbg($msg)
    {
        $key = self::DEBUG_KEY;
        $dbg = get_transient($key);
        $dbg = is_array($dbg) ? $dbg : [];
        $dbg[] = '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $msg;
        if (count($dbg) > 100) {
            $dbg = array_slice($dbg, -100);
        }
        set_transient($key, $dbg, HOUR_IN_SECONDS);
    }

    public function ajax_get_debug_log()
    {
        check_ajax_referer('mraf_bulk_replace', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        if (isset($_POST['clear']) && $_POST['clear']) {
            delete_transient(self::DEBUG_KEY);
            wp_send_json_success(['log' => __('Log cleared.', 'mraf')]);
        }

        $dbg = get_transient(self::DEBUG_KEY);
        $log = ($dbg && is_array($dbg)) ? implode("\n", $dbg) : __('No debug logs yet.', 'mraf');
        wp_send_json_success(['log' => $log]);
    }

    public function ajax_upload_files()
    {
        $this->dbg('=== UPLOAD START ===');
        check_ajax_referer('mraf_bulk_replace', 'nonce');

        if (!current_user_can('upload_files')) {
            $this->dbg('ERROR: Permission denied');
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        if (empty($_FILES['files'])) {
            $this->dbg('ERROR: No files in $_FILES');
            wp_send_json_error(['message' => __('No files uploaded.', 'mraf')]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $uploaded_files = [];
        $files = $_FILES['files'];
        $file_count = is_array($files['name']) ? count($files['name']) : 1;
        $this->dbg('Processing ' . $file_count . ' file(s)');

        for ($i = 0; $i < $file_count; $i++) {
            $file = [
                'name' => is_array($files['name']) ? $files['name'][$i] : $files['name'],
                'type' => is_array($files['type']) ? $files['type'][$i] : $files['type'],
                'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                'error' => is_array($files['error']) ? $files['error'][$i] : $files['error'],
                'size' => is_array($files['size']) ? $files['size'][$i] : $files['size'],
            ];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $this->dbg('WARNING: File ' . $file['name'] . ' upload error code: ' . $file['error']);
                continue;
            }

            $upload = wp_handle_upload($file, ['test_form' => false, 'mimes' => get_allowed_mime_types()]);

            if (isset($upload['error'])) {
                $this->dbg('WARNING: wp_handle_upload error for ' . $file['name'] . ': ' . $upload['error']);
                continue;
            }

            $image_info = @getimagesize($upload['file']);
            $dimensions = $image_info ? $image_info[0] . ' × ' . $image_info[1] : 'Unknown';

            $uploaded_files[] = [
                'file' => $upload['file'],
                'url' => $upload['url'],
                'type' => $upload['type'],
                'name' => $file['name'],
                'size' => size_format($file['size']),
                'dimensions' => $dimensions
            ];
            $this->dbg('Uploaded: ' . $file['name'] . ' -> ' . $upload['file']);
        }

        if (empty($uploaded_files)) {
            $this->dbg('ERROR: No files were successfully uploaded');
            wp_send_json_error(['message' => __('No files were successfully uploaded.', 'mraf')]);
        }

        $upload_id = uniqid('mraf_upload_');
        set_transient('mraf_uploaded_' . $upload_id, $uploaded_files, HOUR_IN_SECONDS);
        $this->dbg('Upload complete: ' . count($uploaded_files) . ' file(s) with ID: ' . $upload_id);

        wp_send_json_success(['upload_id' => $upload_id, 'files' => $uploaded_files]);
    }

    public function ajax_replace_single()
    {
        $attachment_id = absint($_POST['attachment_id'] ?? 0);
        $this->dbg('=== REPLACE START === ID: ' . $attachment_id);

        check_ajax_referer('mraf_bulk_replace', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        $new_file_path = wp_normalize_path((string) ($_POST['new_file_path'] ?? ''));
        $keep_basename = !empty($_POST['keep_basename']);
        $update_refs = !empty($_POST['update_refs']);
        $create_backup = !empty($_POST['create_backup']);
        $upload_id = sanitize_key($_POST['upload_id'] ?? '');

        if (!$attachment_id) {
            wp_send_json_error(['message' => __('Invalid attachment ID.', 'mraf')]);
        }

        if (!$new_file_path || !file_exists($new_file_path)) {
            wp_send_json_error(['message' => __('File not found. It may have been cleaned up by the server.', 'mraf')]);
        }

        // Ensure the new file is inside uploads (prevents arbitrary path abuse)
        $uploads_dir = wp_get_upload_dir();
        $uploads_base = wp_normalize_path(trailingslashit($uploads_dir['basedir']));
        if (strpos($new_file_path, $uploads_base) !== 0) {
            wp_send_json_error(['message' => __('Invalid file location.', 'mraf')]);
        }

        try {
        // Capture the original attachment state BEFORE any file or meta operations happen.
        $uploads_dir = wp_get_upload_dir();
        $original_rel = get_post_meta($attachment_id, '_wp_attached_file', true);
        $original_file = get_attached_file($attachment_id);
        $original_meta = wp_get_attachment_metadata($attachment_id);
        $original_url = $original_rel ? trailingslashit($uploads_dir['baseurl']) . $original_rel : wp_get_attachment_url($attachment_id);

        if ($create_backup) {
            $this->create_backup($attachment_id);
        }

        $result = $this->replace_image_file(
            $attachment_id,
            $new_file_path,
            $keep_basename,
            $update_refs,
            [
                'file' => $original_file,
                'meta' => $original_meta,
                'rel'  => $original_rel,
                'url'  => $original_url,
            ]
        );
            $result['debug_log'] = get_transient(self::DEBUG_KEY);

            // Clean up uploaded temp file if it differs from the new attachment path
            $final_path = get_attached_file($attachment_id);
            if (!empty($new_file_path) && file_exists($new_file_path) && wp_normalize_path($new_file_path) !== wp_normalize_path($final_path)) {
                @unlink($new_file_path);
            }
            // Clear upload transient if provided
            if (!empty($upload_id)) {
                delete_transient('mraf_uploaded_' . $upload_id);
            }

            wp_send_json_success($result);
        } catch (Exception $e) {
            if (!empty($new_file_path) && file_exists($new_file_path)) {
                @unlink($new_file_path);
            }
            if (!empty($upload_id)) {
                delete_transient('mraf_uploaded_' . $upload_id);
            }
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function replace_image_file($attachment_id, $new_file_path, $keep_basename, $update_refs, $original_snapshot = [])
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $snapshot = is_array($original_snapshot) ? $original_snapshot : [];
        $old_file = $snapshot['file'] ?? get_attached_file($attachment_id);
        $old_meta = $snapshot['meta'] ?? wp_get_attachment_metadata($attachment_id);

        // Build old URL manually from file path to avoid cache issues, but prefer the captured value first
        $uploads = wp_get_upload_dir();
        $old_rel_path = array_key_exists('rel', $snapshot) ? $snapshot['rel'] : get_post_meta($attachment_id, '_wp_attached_file', true);
        $old_url = isset($snapshot['url']) ? $snapshot['url'] : ($old_rel_path ? trailingslashit($uploads['baseurl']) . $old_rel_path : wp_get_attachment_url($attachment_id));

        $this->dbg('Old URL: ' . ($old_url ?: '(none)'));
        $this->dbg('Old file: ' . ($old_file ?: '(none)'));

        if (!$old_file) {
            $this->dbg('ERROR: Original file not found');
            throw new Exception(__('Original file not found.', 'mraf'));
        }

        $uploads = wp_get_upload_dir();
        $old_dir_rel = $old_rel_path ? dirname($old_rel_path) : '';
        $old_base = $old_rel_path ? pathinfo($old_rel_path, PATHINFO_FILENAME) : 'file';
        $target_dir = trailingslashit($uploads['basedir']) . ($old_dir_rel && '.' !== $old_dir_rel ? trailingslashit($old_dir_rel) : '');

        $ext_new = strtolower(pathinfo($new_file_path, PATHINFO_EXTENSION));
        $base = $keep_basename ? $old_base : pathinfo($new_file_path, PATHINFO_FILENAME);
        $final_name = $base . '.' . $ext_new;
        $final_path = trailingslashit($target_dir) . $final_name;

        $this->dbg('Proposed filename: ' . $final_name);
        $this->dbg('Source temp file: ' . $new_file_path);
        $this->dbg('Target path: ' . $final_path);

        $normalized_source = wp_normalize_path($new_file_path);
        $normalized_dest = wp_normalize_path($final_path);

        $this->dbg('Normalized source: ' . $normalized_source);
        $this->dbg('Normalized dest: ' . $normalized_dest);

        $temp_target = wp_normalize_path($final_path . '.mraf-tmp');
        $backup_path = wp_normalize_path($final_path . '.mraf-backup');
        $old_file_exists = ($old_file && file_exists($old_file));

        try {
            // Copy to temp in target directory
            if ($normalized_source !== $temp_target) {
                if (!@copy($new_file_path, $temp_target)) {
                    $this->dbg('ERROR: copy() failed to temp: ' . $temp_target);
                    throw new Exception(__('Could not copy file to destination.', 'mraf'));
                }
                $this->dbg('File copied to temp successfully');
            } else {
                $this->dbg('Source already at temp path.');
            }

            // Backup original before overwriting
            if ($old_file_exists) {
                if (!@rename($old_file, $backup_path)) {
                    @unlink($temp_target);
                    $this->dbg('ERROR: could not backup original file to: ' . $backup_path);
                    throw new Exception(__('Could not backup original file.', 'mraf'));
                }
                $this->dbg('Original file backed up to: ' . $backup_path);
            }

            // Move temp into final place
            if (!@rename($temp_target, $final_path)) {
                @unlink($temp_target);
                if (file_exists($backup_path)) {
                    @rename($backup_path, $old_file);
                }
                $this->dbg('ERROR: could not move temp to final path: ' . $final_path);
                throw new Exception(__('Could not finalize new file.', 'mraf'));
            }
            $this->dbg('New file placed at final path');

        } catch (Exception $ex) {
            // Rollback on any failure
            if (file_exists($temp_target)) {
                @unlink($temp_target);
            }
            if (file_exists($backup_path) && !file_exists($old_file)) {
                @rename($backup_path, $old_file);
            }
            throw $ex;
        }

        $filetype = wp_check_filetype($final_path);
        $new_mime = $filetype['type'];

        $this->dbg('CRITICAL: Clearing all caches BEFORE metadata update');
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
        $this->dbg('All old metadata and caches purged');

        $new_rel = ($old_dir_rel && '.' !== $old_dir_rel ? trailingslashit($old_dir_rel) : '') . $final_name;
        update_post_meta($attachment_id, '_wp_attached_file', $new_rel);

        $new_guid = trailingslashit($uploads['baseurl']) . $new_rel;
        wp_update_post(['ID' => $attachment_id, 'post_mime_type' => $new_mime, 'guid' => $new_guid]);
        $this->dbg('Core fields updated: ' . $new_rel);

        // Generate metadata (thumbnails, dimensions, etc.)
        $meta = wp_generate_attachment_metadata($attachment_id, $final_path);

        if (!empty($meta)) {
            if (empty($meta['file'])) {
                $meta['file'] = $new_rel;
            }
            // Add timestamp to force cache invalidation in browsers/CDNs
            $meta['mraf_replaced'] = time();
            wp_update_attachment_metadata($attachment_id, $meta);
            $this->dbg('Fresh metadata saved with ' . count($meta['sizes'] ?? []) . ' sizes');
        } else {
            wp_update_attachment_metadata($attachment_id, ['file' => $new_rel, 'sizes' => [], 'mraf_replaced' => time()]);
            $this->dbg('No metadata generated; saving minimal meta.');
        }

        $new_title = pathinfo($final_name, PATHINFO_FILENAME);
        $new_title = str_replace(['-', '_'], ' ', $new_title);
        wp_update_post(['ID' => $attachment_id, 'post_title' => $new_title, 'post_name' => sanitize_title($new_title)]);
        $this->dbg('Attachment title updated to: ' . $new_title);

        clean_post_cache($attachment_id);
        if (function_exists('clean_attachment_cache')) {
            clean_attachment_cache($attachment_id);
        }

        wp_cache_delete($attachment_id, 'posts');
        wp_cache_delete($attachment_id, 'post_meta');
        delete_post_meta($attachment_id, '_wp_attachment_backup_sizes');
        wp_cache_flush();
        $this->dbg('All caches cleared - metadata and URLs refreshed');

        // Force fresh URL fetch from database BEFORE triggering actions
        clean_post_cache($attachment_id);
        if (function_exists('clean_attachment_cache')) {
            clean_attachment_cache($attachment_id);
        }
        wp_cache_delete($attachment_id, 'posts');
        wp_cache_delete($attachment_id, 'post_meta');

        $new_url = wp_get_attachment_url($attachment_id);
        $this->dbg('New URL: ' . ($new_url ?: '(none)'));

        // Trigger action with correct new URL
        do_action('mraf_after_image_replaced', $attachment_id, $old_url, $new_url);

        if ($update_refs && $old_url !== $new_url) {
            $this->dbg('Updating URL references from old to new (including sizes)');
            $this->dbg('Old URL: ' . $old_url);
            $this->dbg('New URL: ' . $new_url);
            $replacements = $this->build_url_replacements($old_url, $new_url, $old_meta, $meta);
            $this->dbg('Total URL pairs to replace: ' . count($replacements));
            foreach ($replacements as $pair) {
                $stats = $this->update_url_references($pair['old'], $pair['new']);
                $this->dbg(sprintf('References updated for %s -> %s: %d post(s), %d meta row(s), %d Elementor page(s), %d builder(s), %d option(s), %d widget(s)',
                    $pair['old'], $pair['new'],
                    $stats['posts'] ?? 0, $stats['meta'] ?? 0, $stats['elementor'] ?? 0, $stats['builders'] ?? 0, $stats['options'] ?? 0, $stats['widgets'] ?? 0));
            }
        } else {
            $this->dbg('Skipping URL reference updates - Old URL: ' . $old_url . ', New URL: ' . $new_url . ', Update refs: ' . ($update_refs ? 'yes' : 'no'));
        }

        // Delete legacy files only after success (use backup path if present)
        $old_file_for_delete = file_exists($backup_path) ? $backup_path : $old_file;
        $deleted_count = $this->delete_attachment_files($old_file_for_delete, $old_meta);
        if ($deleted_count > 0) {
            $this->dbg(sprintf('Removed %d legacy file(s)', $deleted_count));
        } else {
            $this->dbg('No legacy files detected to delete.');
        }

        // Clean up any backup left
        if (file_exists($backup_path)) {
            @unlink($backup_path);
        }

        // Flush all caches AFTER URL replacement is complete
        MRAF_Image_Replacer::flush_builder_caches($attachment_id);
        $this->dbg('All caches flushed (attachment, builders, page cache, opcode cache)');
        $this->dbg('=== REPLACE COMPLETE === ID: ' . $attachment_id);

        update_post_meta($attachment_id, '_mraf_replaced_at', current_time('timestamp'));

        return ['new_url' => $new_url, 'new_title' => $new_title, 'message' => __('Image replaced successfully!', 'mraf')];
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

    public function ajax_search_images()
    {
        check_ajax_referer('mraf_bulk_replace', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        global $wpdb;

        $query = sanitize_text_field($_POST['query'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? '');
        $date = sanitize_text_field($_POST['date'] ?? '');
        $page = absint($_POST['page'] ?? 1);
        $per_page = 50;
        $where_cb = null;

        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'post_mime_type' => 'image',
        ];

        // Search by title and alt text
        if ($query) {
            // Search across title, content, excerpt, guid (URL), and file/alt metas.
            $where_cb = function($where) use ($query, $wpdb) {
                $like = '%' . $wpdb->esc_like($query) . '%';
                $where .= $wpdb->prepare(
                    " AND (
                        {$wpdb->posts}.post_title LIKE %s
                        OR {$wpdb->posts}.post_content LIKE %s
                        OR {$wpdb->posts}.post_excerpt LIKE %s
                        OR {$wpdb->posts}.guid LIKE %s
                        OR EXISTS (
                            SELECT 1 FROM {$wpdb->postmeta} pm
                            WHERE pm.post_id = {$wpdb->posts}.ID
                            AND pm.meta_key IN ('_wp_attachment_image_alt', '_wp_attached_file')
                            AND pm.meta_value LIKE %s
                        )
                    )",
                    $like, $like, $like, $like, $like
                );
                return $where;
            };

            add_filter('posts_where', $where_cb, 10, 1);
        }

        // Filter by MIME type
        if ($type) {
            $args['post_mime_type'] = $type;
        }

        // Filter by date
        if ($date) {
            $args['date_query'] = $this->get_date_query($date);
        }

        $query_obj = new WP_Query($args);

        // Remove the filter if it was added
        if ($where_cb) {
            remove_filter('posts_where', $where_cb, 10);
        }

        $attachments = $query_obj->posts;
        $total = $query_obj->found_posts;
        $results = [];

        foreach ($attachments as $attachment) {
            $url = wp_get_attachment_url($attachment->ID);
            $thumb = wp_get_attachment_image_src($attachment->ID, 'thumbnail');
            $meta = wp_get_attachment_metadata($attachment->ID);
            $file_path = get_post_meta($attachment->ID, '_wp_attached_file', true);
            $alt_text = get_post_meta($attachment->ID, '_wp_attachment_image_alt', true);
            $replaced_at = (int) get_post_meta($attachment->ID, '_mraf_replaced_at', true);

            $results[] = [
                'id' => $attachment->ID,
                'title' => $attachment->post_title,
                'alt_text' => $alt_text,
                'url' => $url,
                'thumb' => $thumb ? $thumb[0] : $url,
                'filename' => basename($url),
                'filepath' => $file_path,
                'mime_type' => $attachment->post_mime_type,
                'dimensions' => !empty($meta['width']) ? $meta['width'] . ' × ' . $meta['height'] : 'Unknown',
                'filesize' => !empty($meta['filesize']) ? size_format($meta['filesize']) : 'Unknown',
                'replaced_at' => $replaced_at
            ];
        }

        wp_send_json_success([
            'images' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'has_more' => ($page * $per_page) < $total
        ]);
    }

    private function get_date_query($date)
    {
        switch ($date) {
            case 'today': return ['after' => '1 day ago'];
            case 'week': return ['after' => '1 week ago'];
            case 'month': return ['after' => '1 month ago'];
            case 'year': return ['after' => '1 year ago'];
            default: return [];
        }
    }

    private function create_backup($attachment_id)
    {
        $this->dbg('Creating backup for attachment ID: ' . $attachment_id);
        $backup_dir = WP_CONTENT_DIR . '/' . self::BACKUP_DIR . '/' . $attachment_id;

        if (!wp_mkdir_p($backup_dir)) {
            $this->dbg('ERROR: Could not create backup directory: ' . $backup_dir);
            throw new Exception(__('Could not create backup directory.', 'mraf'));
        }

        $old_file = get_attached_file($attachment_id);
        $old_meta = wp_get_attachment_metadata($attachment_id);
        $post = get_post($attachment_id);
        $backup_count = 0;

        if ($old_file && file_exists($old_file)) {
            if (@copy($old_file, $backup_dir . '/' . basename($old_file))) {
                $backup_count++;
            }
        }

        if (!empty($old_meta['sizes'])) {
            $dir = dirname($old_file);
            foreach ($old_meta['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $thumb_path = trailingslashit($dir) . $size['file'];
                    if (file_exists($thumb_path)) {
                        if (@copy($thumb_path, $backup_dir . '/' . $size['file'])) {
                            $backup_count++;
                        }
                    }
                }
            }
        }

        $backup_data = [
            'post' => $post,
            'metadata' => $old_meta,
            'attached_file' => get_post_meta($attachment_id, '_wp_attached_file', true),
            'timestamp' => time(),
            'user_id' => get_current_user_id()
        ];

        file_put_contents($backup_dir . '/backup.json', json_encode($backup_data, JSON_PRETTY_PRINT));
        $this->dbg('Backup created: ' . $backup_count . ' file(s) backed up to ' . $backup_dir);
    }

    private function delete_attachment_files($file, $meta)
    {
        $count = 0;

        if ($file && file_exists($file)) {
            @unlink($file);
            $count++;
        }

        if (!empty($meta['sizes'])) {
            $dir = dirname($file);
            foreach ($meta['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $thumb = trailingslashit($dir) . $size['file'];
                    if (file_exists($thumb)) {
                        @unlink($thumb);
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    private function update_url_references($old_url, $new_url)
    {
        global $wpdb;

        $stats = ['posts' => 0, 'meta' => 0, 'elementor' => 0, 'builders' => 0, 'options' => 0, 'widgets' => 0];

        // Update post content
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
            $old_url, $new_url, '%' . $wpdb->esc_like($old_url) . '%'
        ));
        $stats['posts'] = $result !== false ? $result : 0;

        // Update Elementor data
        $stats['elementor'] = MRAF_Image_Replacer::update_elementor_urls($old_url, $new_url);

        // Update other page builders
        $stats['builders'] = MRAF_Image_Replacer::update_page_builder_urls($old_url, $new_url);

        // Update postmeta - handle both plain text and serialized data
        $excluded_meta_keys = ['_elementor_data', '_elementor_draft', '_elementor_css', '_elementor_page_settings', '_elementor_controls_usage', '_elementor_version', 'elementor_data', '_wpb_shortcodes_custom_css', '_fl_builder_data', '_fusion', '_et_pb_'];
        $exclude_conditions = [];
        foreach ($excluded_meta_keys as $key) {
            if (strpos($key, '%') !== false || strpos($key, '_et_pb_') !== false) {
                $exclude_conditions[] = $wpdb->prepare("meta_key NOT LIKE %s", $key . '%');
            } else {
                $exclude_conditions[] = $wpdb->prepare("meta_key != %s", $key);
            }
        }
        $exclude_sql = implode(' AND ', $exclude_conditions);

        // First, update simple (non-serialized) postmeta
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)
            WHERE meta_value LIKE %s AND {$exclude_sql}
            AND meta_value NOT LIKE 'a:%' AND meta_value NOT LIKE 'O:%' AND meta_value NOT LIKE '{%'",
            $old_url, $new_url, '%' . $wpdb->esc_like($old_url) . '%'
        ));
        $stats['meta'] = $result !== false ? $result : 0;

        // Second, handle SERIALIZED postmeta (meta boxes, ACF galleries, repeaters, etc.)
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

            // Handle serialized arrays/objects in meta boxes
            $new = MRAF_Image_Replacer::replace_maybe_serialized($raw, $search, $replace, $changed, $replaced_count);

            if ($changed) {
                $wpdb->update($wpdb->postmeta, ['meta_value' => $new], ['meta_id' => (int) $m->meta_id]);
                if (isset($m->post_id)) {
                    clean_post_cache((int) $m->post_id);
                }
                $stats['meta']++;
                $this->dbg('Updated serialized meta: ' . $m->meta_key . ' (post_id: ' . $m->post_id . ', ' . $replaced_count . ' replacement(s))');
            }
        }

        // Update theme options and widgets (for themes like GoWild)
        $search = [$old_url];
        $replace = [$new_url];
        $like = '%' . $wpdb->esc_like($old_url) . '%';

        // Safe option prefixes for theme data, widgets, ACF, etc.
        $safe_option_prefixes = ['widget_', 'sidebars_widgets', '_transient_', 'acf_', 'options_', 'theme_mods_', 'gowild_'];
        $safe_option_names = ['upload_path', 'upload_url_path'];

        $opts = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value FROM {$wpdb->options}
            WHERE option_value LIKE %s",
            $like
        ));

        foreach ($opts as $o) {
            $option_name = $o->option_name;

            // Check if this is a safe option to update
            $is_safe = in_array($option_name, $safe_option_names);
            if (!$is_safe) {
                foreach ($safe_option_prefixes as $prefix) {
                    if (strpos($option_name, $prefix) === 0) {
                        $is_safe = true;
                        break;
                    }
                }
            }

            // Skip dangerous options that could break the site
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

            // Handle serialized data properly (widgets, theme settings, etc.)
            $new = MRAF_Image_Replacer::replace_maybe_serialized($raw, $search, $replace, $changed, $replaced_count);

            if ($changed) {
                $wpdb->update($wpdb->options, ['option_value' => $new], ['option_id' => (int) $o->option_id]);
                if (!empty($o->option_name)) {
                    wp_cache_delete($o->option_name, 'options');
                }

                // Track widgets separately for better logging
                if (strpos($option_name, 'widget_') === 0) {
                    $stats['widgets']++;
                } else {
                    $stats['options']++;
                }

                $this->dbg('Updated option: ' . $option_name . ' (' . $replaced_count . ' replacement(s))');
            }
        }

        return $stats;
    }
}
