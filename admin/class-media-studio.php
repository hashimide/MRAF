<?php
/**
 * Media Studio - Unified Media Replacement & Optimization Hub
 *
 * @package MRAF
 * @subpackage MRAF/admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class MRAF_Media_Studio
{
    const NONCE_ACTION = 'mraf_studio_action';
    const DEBUG_KEY    = 'mraf_studio_debug';
    const OPTION_KEY   = 'mraf_studio_options';

    private $image_library = null;

    /**
     * Job options live in the Settings tab, not in the run modal, so every
     * conversion and replacement uses one known configuration.
     *
     * @return array
     */
    public static function get_options()
    {
        $defaults = [
            'quality'         => 85,
            'enable_resize'   => false,
            'max_width'       => 1920,
            'update_refs'     => true,
            'create_backup'   => true,
            'delete_original' => false,
            'keep_basename'   => false,
            'r_keep_basename' => true,
        ];

        $saved = get_option(self::OPTION_KEY, []);

        if (!is_array($saved)) {
            $saved = [];
        }

        $options = array_merge($defaults, $saved);

        $options['quality']   = max(1, min(100, absint($options['quality'])));
        $options['max_width'] = max(1, absint($options['max_width']));

        foreach (['enable_resize', 'update_refs', 'create_backup', 'delete_original', 'keep_basename', 'r_keep_basename'] as $flag) {
            $options[$flag] = (bool) $options[$flag];
        }

        return $options;
    }

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_legacy_redirects']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        // AJAX endpoints
        add_action('wp_ajax_mraf_studio_convert_single', [$this, 'ajax_convert_single']);
        add_action('wp_ajax_mraf_studio_upload_files', [$this, 'ajax_upload_files']);
        add_action('wp_ajax_mraf_studio_replace_single', [$this, 'ajax_replace_single']);
        add_action('wp_ajax_mraf_studio_get_backups', [$this, 'ajax_get_backups']);
        add_action('wp_ajax_mraf_studio_restore_backup', [$this, 'ajax_restore_backup']);
        add_action('wp_ajax_mraf_studio_delete_backup', [$this, 'ajax_delete_backup']);
        add_action('wp_ajax_mraf_studio_debug_log', [$this, 'ajax_debug_log']);
        add_action('wp_ajax_mraf_studio_save_setting', [$this, 'ajax_save_setting']);
        add_action('wp_ajax_mraf_studio_save_options', [$this, 'ajax_save_options']);
        add_action('wp_ajax_mraf_studio_save_details', [$this, 'ajax_save_details']);
        add_action('wp_ajax_mraf_studio_get_items', [$this, 'ajax_get_items']);
        add_action('wp_ajax_mraf_studio_search', [$this, 'ajax_search_images']);

        $this->detect_image_library();
    }

    /**
     * Redirect legacy menu URLs to unified Media Studio.
     */
    public function handle_legacy_redirects()
    {
        if (!is_admin()) {
            return;
        }

        $page = sanitize_key($_GET['page'] ?? '');
        if ($page === 'mraf-bulk-replace' || $page === 'mraf-image-converter') {
            wp_safe_redirect(admin_url('upload.php?page=mraf-media-studio'));
            exit;
        }
    }

    /**
     * Register single Media Studio menu page.
     */
    public function add_admin_menu()
    {
        add_media_page(
            __('Media Studio', 'mraf'),
            __('Media Studio', 'mraf'),
            'upload_files',
            'mraf-media-studio',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Detect GD or Imagick library availability.
     */
    private function detect_image_library()
    {
        $imagick_available = extension_loaded('imagick') && class_exists('Imagick');
        $gd_available      = extension_loaded('gd') && function_exists('gd_info');

        $imagick_webp = false;
        if ($imagick_available) {
            try {
                $imagick = new Imagick();
                $imagick_webp = in_array('WEBP', $imagick->queryFormats());
            } catch (\Throwable $e) {
                $imagick_webp = false;
            }
        }

        $gd_webp = false;
        if ($gd_available) {
            $gd_info = gd_info();
            $gd_webp = !empty($gd_info['WebP Support']);
        }

        if ($imagick_available && $imagick_webp) {
            $this->image_library = 'imagick';
        } elseif ($gd_available && $gd_webp) {
            $this->image_library = 'gd';
        } elseif ($imagick_available) {
            $this->image_library = 'imagick';
        } elseif ($gd_available) {
            $this->image_library = 'gd';
        } else {
            $this->image_library = null;
        }
    }

    public function get_image_library()
    {
        return $this->image_library;
    }

    public function is_webp_supported()
    {
        if ($this->image_library === 'imagick') {
            try {
                $imagick = new Imagick();
                return in_array('WEBP', $imagick->queryFormats());
            } catch (\Throwable $e) {
                return false;
            }
        } elseif ($this->image_library === 'gd') {
            $gd_info = gd_info();
            return !empty($gd_info['WebP Support']);
        }
        return false;
    }

    /**
     * Enqueue assets for Media Studio.
     */
    public function enqueue_scripts($hook)
    {
        if ($hook !== 'media_page_mraf-media-studio') {
            return;
        }

        wp_enqueue_media();

        $shared_js_ver  = (@filemtime(MRAF_PLUGIN_DIR . 'admin/js/mraf-shared.js') ?: MRAF_VERSION) . '.' . time();
        $studio_js_ver  = (@filemtime(MRAF_PLUGIN_DIR . 'admin/js/mraf-studio.js') ?: MRAF_VERSION) . '.' . time();
        $shared_css_ver = (@filemtime(MRAF_PLUGIN_DIR . 'admin/css/mraf-shared.css') ?: MRAF_VERSION) . '.' . time();
        $studio_css_ver = (@filemtime(MRAF_PLUGIN_DIR . 'admin/css/mraf-studio.css') ?: MRAF_VERSION) . '.' . time();

        wp_enqueue_script('mraf-shared', MRAF_PLUGIN_URL . 'admin/js/mraf-shared.js', ['jquery'], $shared_js_ver, true);
        wp_enqueue_script('mraf-studio', MRAF_PLUGIN_URL . 'admin/js/mraf-studio.js', ['jquery', 'mraf-shared'], $studio_js_ver, true);

        wp_enqueue_style('mraf-shared', MRAF_PLUGIN_URL . 'admin/css/mraf-shared.css', [], $shared_css_ver);
        wp_enqueue_style('mraf-studio', MRAF_PLUGIN_URL . 'admin/css/mraf-studio.css', ['mraf-shared'], $studio_css_ver);

        wp_localize_script('mraf-studio', 'mrafStudioData', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce(self::NONCE_ACTION),
            'usageNonce'   => wp_create_nonce('mraf_usage'),
            'editUrlBase'  => admin_url('post.php?action=edit&post='),
            'imageLibrary' => $this->image_library,
            'webpSupport'  => $this->is_webp_supported(),
            'debugEnabled' => (bool) get_option('mraf_show_debug_tab', false),
            'options'      => self::get_options(),
            'settingsUrl'  => admin_url('upload.php?page=mraf-media-studio#settings'),
            'strings'      => [
                'selectImages'        => __('Please select at least one image.', 'mraf'),
                'confirmConvert'      => __('Convert selected images to WebP? This will optimize files and update references.', 'mraf'),
                'confirmReplace'      => __('Replace selected images with the mapped files?', 'mraf'),
                'confirmRestore'      => __('Restore this backup? Current media file will be replaced with original.', 'mraf'),
                'confirmDeleteBackup' => __('Permanently delete this backup?', 'mraf'),
                'processing'          => __('Processing...', 'mraf'),
                'completed'           => __('Completed!', 'mraf'),
                'failed'              => __('Failed', 'mraf'),
            ]
        ]);
    }

    /**
     * AJAX: Convert single image to WebP/AVIF.
     */
    public function ajax_convert_single()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        $attachment_id = absint($_POST['attachment_id'] ?? 0);
        $new_name      = sanitize_file_name(wp_unslash($_POST['new_name'] ?? ''));
        $options       = self::get_options();

        $quality         = $options['quality'];
        $max_width       = $options['max_width'];
        $enable_resize   = $options['enable_resize'];
        $delete_original = $options['delete_original'];
        $keep_basename   = $options['keep_basename'];
        $update_refs     = $options['update_refs'];
        $create_backup   = $options['create_backup'];

        if (!$attachment_id) {
            wp_send_json_error(['message' => __('Invalid attachment ID.', 'mraf')]);
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_error(['message' => __('Original file not found on disk.', 'mraf')]);
        }

        $old_url  = wp_get_attachment_url($attachment_id);
        $old_meta = wp_get_attachment_metadata($attachment_id);
        $old_base  = pathinfo($file_path, PATHINFO_FILENAME);
        $old_bytes = (int) (@filesize($file_path) ?: 0);

        try {
            if ($create_backup) {
                MRAF_Image_Replacer::create_backup($attachment_id);
            }

            $temp_file = $file_path;

            // Optional max width resizing
            if ($enable_resize && $max_width > 0) {
                $resized = $this->resize_to_max_width($temp_file, $max_width);
                if ($resized['success']) {
                    $temp_file = $resized['output_path'];
                }
            }

            // Convert to WebP
            $conv_result = $this->convert_to_webp($temp_file, $quality);
            if (!$conv_result['success']) {
                throw new Exception($conv_result['message']);
            }

            $webp_path = $conv_result['output_path'];

            // Rename the WebP when the modal asked for a different file name.
            $wanted = $keep_basename ? '' : pathinfo($new_name, PATHINFO_FILENAME);

            if ($wanted !== '' && $wanted !== pathinfo($webp_path, PATHINFO_FILENAME)) {
                $dir    = dirname($webp_path);
                $unique = wp_unique_filename($dir, $wanted . '.webp');
                $target = $dir . '/' . $unique;

                if (@rename($webp_path, $target)) {
                    $webp_path = $target;
                }
            }

            // Delete original file & sizes if requested and target is distinct
            if ($delete_original && $webp_path !== $file_path) {
                $this->delete_attachment_files($file_path, $old_meta);
            }

            // Update attachment metadata to WebP
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $uploads = wp_get_upload_dir();
            $new_rel = ltrim(str_replace($uploads['basedir'], '', $webp_path), '/\\');

            update_attached_file($attachment_id, $webp_path);
            update_post_meta($attachment_id, '_wp_attached_file', $new_rel);

            wp_update_post([
                'ID'             => $attachment_id,
                'post_mime_type' => 'image/webp',
                'guid'           => trailingslashit($uploads['baseurl']) . $new_rel,
            ]);

            $new_meta = wp_generate_attachment_metadata($attachment_id, $webp_path);
            if (!empty($new_meta)) {
                $new_meta['mraf_converted'] = time();
                wp_update_attachment_metadata($attachment_id, $new_meta);
            }

            $new_url = wp_get_attachment_url($attachment_id);

            // Update content references across database
            $ref_stats = [];
            if ($update_refs && $old_url && $new_url && $old_url !== $new_url) {
                $pairs = MRAF_Image_Replacer::build_url_replacements($old_url, $new_url, $old_meta, $new_meta);
                foreach ($pairs as $pair) {
                    $s = MRAF_Image_Replacer::update_all_references($pair['old'], $pair['new'], $attachment_id);
                    foreach ($s as $k => $v) {
                        $ref_stats[$k] = ($ref_stats[$k] ?? 0) + $v;
                    }
                }
            } else {
                MRAF_Image_Replacer::flush_builder_caches($attachment_id);
            }

            update_post_meta($attachment_id, '_mraf_converted', current_time('timestamp'));

            if ($temp_file !== $file_path && file_exists($temp_file)) {
                @unlink($temp_file);
            }

            $new_bytes  = (int) (@filesize($webp_path) ?: 0);
            $saved_here = max(0, $old_bytes - $new_bytes);
            $saved_all  = (float) get_option('mraf_bytes_saved', 0) + $saved_here;
            update_option('mraf_bytes_saved', $saved_all, false);

            wp_send_json_success([
                'message'           => __('Image converted to WebP successfully!', 'mraf'),
                'attachment_id'     => $attachment_id,
                'new_url'           => $new_url,
                'compression_ratio' => $conv_result['compression_ratio'] ?? 0,
                'ref_stats'         => $ref_stats,
                'bytes_saved'       => $saved_here,
                'bytes_saved_total' => $saved_all,
                'saved_total_human' => size_format($saved_all, 1),
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Handle file upload for replacement matching.
     */
    public function ajax_upload_files()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => __('No file uploaded.', 'mraf')]);
        }

        $file = $_FILES['file'];
        $uploads = wp_get_upload_dir();
        $temp_dir = trailingslashit($uploads['basedir']) . 'mraf-temp';

        if (!wp_mkdir_p($temp_dir)) {
            wp_send_json_error(['message' => __('Could not create temporary upload directory.', 'mraf')]);
        }

        $clean_name = sanitize_file_name($file['name']);
        $unique_name = wp_unique_filename($temp_dir, $clean_name);
        $target_path = $temp_dir . '/' . $unique_name;

        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            wp_send_json_error(['message' => __('Failed to move uploaded file.', 'mraf')]);
        }

        $filetype = wp_check_filetype($target_path);
        $uploads_baseurl = trailingslashit($uploads['baseurl']);
        $preview_url = $uploads_baseurl . 'mraf-temp/' . $unique_name;

        wp_send_json_success([
            'temp_path'   => $target_path,
            'filename'    => $clean_name,
            'preview_url' => $preview_url,
            'size'        => size_format(filesize($target_path)),
            'mime_type'   => $filetype['type'],
        ]);
    }

    /**
     * AJAX: Replace single attachment file.
     */
    public function ajax_replace_single()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        $attachment_id = absint($_POST['attachment_id'] ?? 0);
        $new_file_path = wp_normalize_path((string) ($_POST['new_file_path'] ?? ''));
        $options       = self::get_options();

        $keep_basename = $options['r_keep_basename'];
        $update_refs   = $options['update_refs'];
        $create_backup = $options['create_backup'];

        if (!$attachment_id || !$new_file_path || !file_exists($new_file_path)) {
            wp_send_json_error(['message' => __('Invalid attachment or replacement file.', 'mraf')]);
        }

        $uploads_dir = wp_get_upload_dir();
        $uploads_base = wp_normalize_path(trailingslashit($uploads_dir['basedir']));

        if (strpos($new_file_path, $uploads_base) !== 0) {
            wp_send_json_error(['message' => __('Invalid file path.', 'mraf')]);
        }

        try {
            global $wpdb;

            $original_rel = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attached_file' LIMIT 1",
                $attachment_id
            ));

            $original_meta_raw = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attachment_metadata' LIMIT 1",
                $attachment_id
            ));
            $original_meta = $original_meta_raw ? maybe_unserialize($original_meta_raw) : [];

            $uploads_basedir = trailingslashit($uploads_dir['basedir']);
            $uploads_baseurl = trailingslashit($uploads_dir['baseurl']);
            $original_file   = $original_rel ? $uploads_basedir . $original_rel : '';
            $old_url         = $original_rel ? $uploads_baseurl . $original_rel : '';

            if ($create_backup) {
                MRAF_Image_Replacer::create_backup($attachment_id);
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';

            $old_dir_rel = $original_rel ? dirname($original_rel) : '';
            $old_base    = $original_rel ? pathinfo($original_rel, PATHINFO_FILENAME) : 'file';
            $target_dir  = trailingslashit($uploads_dir['basedir']) . ($old_dir_rel && '.' !== $old_dir_rel ? trailingslashit($old_dir_rel) : '');

            $ext_new    = strtolower(pathinfo($new_file_path, PATHINFO_EXTENSION));
            $base       = $keep_basename ? $old_base : pathinfo($new_file_path, PATHINFO_FILENAME);
            $final_name = $base . '.' . $ext_new;
            $final_path = trailingslashit($target_dir) . $final_name;

            // Copy to final location
            if (!@copy($new_file_path, $final_path)) {
                throw new Exception(__('Could not copy new file to final destination.', 'mraf'));
            }

            // Purge old meta
            clean_post_cache($attachment_id);
            if (function_exists('clean_attachment_cache')) {
                clean_attachment_cache($attachment_id);
            }

            delete_post_meta($attachment_id, '_wp_attachment_metadata');
            delete_post_meta($attachment_id, '_wp_attachment_backup_sizes');

            $new_rel = ($old_dir_rel && '.' !== $old_dir_rel ? trailingslashit($old_dir_rel) : '') . $final_name;
            update_post_meta($attachment_id, '_wp_attached_file', $new_rel);

            $filetype = wp_check_filetype($final_path);
            $new_url  = trailingslashit($uploads_dir['baseurl']) . $new_rel;

            wp_update_post([
                'ID'             => $attachment_id,
                'post_mime_type' => $filetype['type'],
                'guid'           => $new_url
            ]);

            $new_meta = wp_generate_attachment_metadata($attachment_id, $final_path);
            if (!empty($new_meta)) {
                $new_meta['mraf_replaced'] = time();
                wp_update_attachment_metadata($attachment_id, $new_meta);
            }

            // Reference replacement
            $ref_stats = [];
            if ($update_refs && $old_url && $new_url && $old_url !== $new_url) {
                $pairs = MRAF_Image_Replacer::build_url_replacements($old_url, $new_url, $original_meta, $new_meta);
                foreach ($pairs as $pair) {
                    $s = MRAF_Image_Replacer::update_all_references($pair['old'], $pair['new'], $attachment_id);
                    foreach ($s as $k => $v) {
                        $ref_stats[$k] = ($ref_stats[$k] ?? 0) + $v;
                    }
                }
            } else {
                MRAF_Image_Replacer::flush_builder_caches($attachment_id);
            }

            // Cleanup old file if extension changed
            if ($original_file && file_exists($original_file) && wp_normalize_path($original_file) !== wp_normalize_path($final_path)) {
                $this->delete_attachment_files($original_file, $original_meta);
            }

            // Cleanup temp file
            if (file_exists($new_file_path) && wp_normalize_path($new_file_path) !== wp_normalize_path($final_path)) {
                @unlink($new_file_path);
            }

            update_post_meta($attachment_id, '_mraf_replaced_at', current_time('timestamp'));

            wp_send_json_success([
                'message'       => __('Image replaced successfully!', 'mraf'),
                'attachment_id' => $attachment_id,
                'new_url'       => $new_url,
                'ref_stats'     => $ref_stats,
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Get Backups list.
     */
    public function ajax_get_backups()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        $backups = MRAF_Image_Replacer::get_backups_list();
        wp_send_json_success(['backups' => $backups]);
    }

    /**
     * AJAX: Restore backup.
     */
    public function ajax_restore_backup()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        $attachment_id = absint($_POST['attachment_id'] ?? 0);
        if (!$attachment_id) {
            wp_send_json_error(['message' => __('Invalid attachment ID.', 'mraf')]);
        }

        $restored = MRAF_Image_Replacer::restore_backup($attachment_id);
        if ($restored) {
            delete_post_meta($attachment_id, '_mraf_converted');
            wp_send_json_success(['message' => __('Backup restored successfully!', 'mraf')]);
        } else {
            wp_send_json_error(['message' => __('Could not restore backup.', 'mraf')]);
        }
    }

    /**
     * AJAX: Delete backup.
     */
    public function ajax_delete_backup()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        $attachment_id = absint($_POST['attachment_id'] ?? 0);
        if (!$attachment_id) {
            wp_send_json_error(['message' => __('Invalid attachment ID.', 'mraf')]);
        }

        $deleted = MRAF_Image_Replacer::delete_backup($attachment_id);
        if ($deleted) {
            wp_send_json_success(['message' => __('Backup deleted.', 'mraf')]);
        } else {
            wp_send_json_error(['message' => __('Could not delete backup.', 'mraf')]);
        }
    }

    /**
     * AJAX: Live debug log retrieval and clearing.
     */
    public function ajax_debug_log()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        if (!empty($_POST['clear'])) {
            delete_transient(self::DEBUG_KEY);
            delete_transient('mraf_bulk_debug');
            delete_transient('mraf_converter_debug');
            wp_send_json_success(['log' => '']);
        }

        $log = get_transient(self::DEBUG_KEY) ?: '';
        wp_send_json_success(['log' => $log]);
    }

    /**
     * AJAX: Save settings.
     */
    public function ajax_save_setting()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        $key   = sanitize_key($_POST['key'] ?? '');
        $value = sanitize_text_field($_POST['value'] ?? '');

        if ($key === 'debug_enabled') {
            update_option('mraf_show_debug_tab', (bool) $value);
            wp_send_json_success(['message' => __('Setting saved.', 'mraf')]);
        }

        wp_send_json_error(['message' => __('Unknown setting.', 'mraf')]);
    }

    /**
     * AJAX: Save the job defaults from the Settings tab.
     */
    public function ajax_save_options()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        $flags   = ['enable_resize', 'update_refs', 'create_backup', 'delete_original', 'keep_basename', 'r_keep_basename'];
        $options = [
            'quality'   => max(1, min(100, absint($_POST['quality'] ?? 85))),
            'max_width' => max(1, absint($_POST['max_width'] ?? 1920)),
        ];

        foreach ($flags as $flag) {
            $options[$flag] = !empty($_POST[$flag]) && $_POST[$flag] !== 'false';
        }

        update_option(self::OPTION_KEY, $options);

        wp_send_json_success([
            'message' => __('Settings saved.', 'mraf'),
            'options' => self::get_options(),
        ]);
    }

    /**
     * Build the item shape the studio UI works with.
     *
     * @param int $attachment_id Attachment ID.
     * @return array|null
     */
    public function format_item($attachment_id)
    {
        $post = get_post($attachment_id);

        if (!$post || $post->post_type !== 'attachment') {
            return null;
        }

        $mime = get_post_mime_type($attachment_id);

        if (strpos((string) $mime, 'image/') !== 0 || $mime === 'image/svg+xml') {
            return null;
        }

        $file  = get_attached_file($attachment_id);
        $thumb = wp_get_attachment_image_src($attachment_id, 'thumbnail');
        $full  = wp_get_attachment_image_src($attachment_id, 'full');
        $meta  = wp_get_attachment_metadata($attachment_id);

        $width  = $meta['width'] ?? ($full ? $full[1] : 0);
        $height = $meta['height'] ?? ($full ? $full[2] : 0);

        return [
            'id'          => (int) $attachment_id,
            'title'       => $post->post_title,
            'filename'    => $file ? basename($file) : '',
            'alt_text'    => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'caption'     => $post->post_excerpt,
            'description' => $post->post_content,
            'uploaded'    => get_the_date(get_option('date_format'), $post),
            'dimensions'  => ($width && $height) ? "{$width} × {$height}" : '-',
            'mime_type'   => $mime,
            'format'      => strtoupper(str_replace('image/', '', (string) $mime)),
            'url'         => wp_get_attachment_url($attachment_id),
            'thumb'       => $thumb ? $thumb[0] : wp_get_attachment_url($attachment_id),
            'filesize'    => ($file && file_exists($file)) ? size_format(filesize($file)) : '-',
            'converted'   => $mime === 'image/webp' || (bool) get_post_meta($attachment_id, '_mraf_converted', true),
            'has_backup'  => file_exists(WP_CONTENT_DIR . '/mraf-backups/' . $attachment_id . '/backup.json'),
        ];
    }

    /**
     * AJAX: the images the workspace gallery shows, page by page.
     */
    public function ajax_search_images()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        $query  = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $type   = isset($_POST['type']) ? sanitize_mime_type(wp_unslash($_POST['type'])) : '';
        $date   = isset($_POST['date']) ? sanitize_key($_POST['date']) : '';
        $filter = isset($_POST['filter']) ? sanitize_key($_POST['filter']) : 'all';
        $page   = max(1, absint($_POST['page'] ?? 1));
        $per    = 40;

        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => $type ? $type : 'image',
            'posts_per_page' => $per,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ($query) {
            $args['s'] = $query;

            // Core only searches post fields, so let the filename count too.
            add_filter('wp_allow_query_attachment_by_filename', '__return_true');
        }

        if ($date) {
            $args['date_query'] = $this->get_date_query($date);
        }

        if ($filter === 'unconverted') {
            $args['meta_query'] = [['key' => '_mraf_converted', 'compare' => 'NOT EXISTS']];
        } elseif ($filter === 'unreplaced') {
            $args['meta_query'] = [['key' => '_mraf_replaced_at', 'compare' => 'NOT EXISTS']];
        }

        $found = new WP_Query($args);
        $items = [];

        foreach ($found->posts as $post) {
            $item = $this->format_item($post->ID);

            if ($item) {
                $items[] = $item;
            }
        }

        wp_send_json_success([
            'images'   => $items,
            'total'    => (int) $found->found_posts,
            'pages'    => (int) $found->max_num_pages,
            'has_more' => $page < (int) $found->max_num_pages,
        ]);
    }

    /**
     * Turn the date dropdown into a date_query.
     *
     * @param string $date_filter today, week, month or year.
     * @return array
     */
    private function get_date_query($date_filter)
    {
        switch ($date_filter) {
            case 'today':
                return [['after' => 'today']];
            case 'week':
                return [['after' => '1 week ago']];
            case 'month':
                return [['after' => '1 month ago']];
            case 'year':
                return [['after' => '1 year ago']];
            default:
                return [];
        }
    }

    /**
     * AJAX: Hydrate a set of attachment IDs for the studio modal.
     */
    public function ajax_get_items()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        $ids   = array_filter(array_map('absint', (array) ($_POST['ids'] ?? [])));
        $items = [];

        foreach (array_slice($ids, 0, 500) as $id) {
            $item = $this->format_item($id);

            if ($item) {
                $items[] = $item;
            }
        }

        wp_send_json_success(['items' => $items]);
    }

    /**
     * AJAX: Save the attachment fields edited in the Details tab.
     */
    public function ajax_save_details()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $attachment_id = absint($_POST['attachment_id'] ?? 0);

        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            wp_send_json_error(['message' => __('Invalid attachment.', 'mraf')]);
        }

        if (!current_user_can('edit_post', $attachment_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        $alt = sanitize_text_field(wp_unslash($_POST['alt'] ?? ''));
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);

        wp_update_post([
            'ID'           => $attachment_id,
            'post_title'   => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'post_excerpt' => wp_kses_post(wp_unslash($_POST['caption'] ?? '')),
            'post_content' => wp_kses_post(wp_unslash($_POST['description'] ?? '')),
        ]);

        wp_send_json_success(['message' => __('Details saved.', 'mraf')]);
    }

    /**
     * Render the Media Studio page: workspace, backups and settings.
     */
    public function render_admin_page()
    {
        $lib        = $this->image_library;
        $webp       = $this->is_webp_supported();
        $show_debug = (bool) get_option('mraf_show_debug_tab', false);
        $opt        = self::get_options();
        ?>
        <div class="wrap mraf-studio">

            <header class="mraf-head">
                <div>
                    <h1>
                        <?php esc_html_e('Media Studio', 'mraf'); ?>
                        <span class="mraf-ver">v<?php echo esc_html(MRAF_VERSION); ?></span>
                    </h1>
                    <p class="description">
                        <?php esc_html_e('Optimize, convert to WebP and replace files. Every reference is updated automatically.', 'mraf'); ?>
                    </p>
                </div>
                <span class="mraf-engine <?php echo $webp ? 'is-ok' : 'is-warn'; ?>">
                    <span class="dashicons <?php echo $webp ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                    <?php
                    if ($webp) {
                        printf(esc_html__('Engine: %s — WebP ready', 'mraf'), esc_html(strtoupper($lib)));
                    } else {
                        esc_html_e('WebP not supported on this server', 'mraf');
                    }
                    ?>
                </span>
            </header>

            <nav class="mraf-tabs">
                <button type="button" class="is-active" data-tab="workspace"><?php esc_html_e('Studio Workspace', 'mraf'); ?></button>
                <button type="button" data-tab="backups"><?php esc_html_e('Backups', 'mraf'); ?></button>
                <button type="button" data-tab="settings"><?php esc_html_e('Settings', 'mraf'); ?></button>
                <?php if ($show_debug): ?>
                    <button type="button" data-tab="debug"><?php esc_html_e('Debug Log', 'mraf'); ?></button>
                <?php endif; ?>
            </nav>

            <!-- ============================ WORKSPACE ============================ -->
            <section class="mraf-panel is-active" data-panel="workspace">

                <div class="mraf-browser" id="mraf-browser">

                    <div class="mraf-bar" id="mraf-bar-normal">
                        <label class="screen-reader-text" for="mraf-q"><?php esc_html_e('Search media', 'mraf'); ?></label>
                        <input type="search" id="mraf-q" placeholder="<?php esc_attr_e('Search title, alt text, filename…', 'mraf'); ?>">

                        <label class="screen-reader-text" for="mraf-f-type"><?php esc_html_e('Format', 'mraf'); ?></label>
                        <select id="mraf-f-type">
                            <option value=""><?php esc_html_e('All formats', 'mraf'); ?></option>
                            <option value="image/jpeg">JPG</option>
                            <option value="image/png">PNG</option>
                            <option value="image/webp">WebP</option>
                            <option value="image/gif">GIF</option>
                            <option value="image/avif">AVIF</option>
                        </select>

                        <label class="screen-reader-text" for="mraf-f-date"><?php esc_html_e('Date', 'mraf'); ?></label>
                        <select id="mraf-f-date">
                            <option value=""><?php esc_html_e('All dates', 'mraf'); ?></option>
                            <option value="today"><?php esc_html_e('Today', 'mraf'); ?></option>
                            <option value="week"><?php esc_html_e('This week', 'mraf'); ?></option>
                            <option value="month"><?php esc_html_e('This month', 'mraf'); ?></option>
                            <option value="year"><?php esc_html_e('This year', 'mraf'); ?></option>
                        </select>

                        <label class="screen-reader-text" for="mraf-f-status"><?php esc_html_e('Status', 'mraf'); ?></label>
                        <select id="mraf-f-status">
                            <option value="all"><?php esc_html_e('All statuses', 'mraf'); ?></option>
                            <option value="unconverted"><?php esc_html_e('Not converted', 'mraf'); ?></option>
                            <option value="unreplaced"><?php esc_html_e('Never replaced', 'mraf'); ?></option>
                        </select>

                        <span class="mraf-bar__spacer"></span>
                        <button type="button" class="button" id="mraf-btn-bulk"><?php esc_html_e('Bulk Select', 'mraf'); ?></button>
                    </div>

                    <div class="mraf-bar mraf-bar--sel" id="mraf-bar-bulk" hidden>
                        <strong id="mraf-sel-count">0 <?php esc_html_e('selected', 'mraf'); ?></strong>
                        <button type="button" class="mraf-linkbtn" id="mraf-btn-sel-all"><?php esc_html_e('select all shown', 'mraf'); ?></button>
                        <button type="button" class="mraf-linkbtn" id="mraf-btn-sel-none"><?php esc_html_e('clear', 'mraf'); ?></button>
                        <span class="mraf-bar__spacer"></span>
                        <button type="button" class="button" id="mraf-btn-bulk-replace" disabled><?php esc_html_e('Replace files', 'mraf'); ?></button>
                        <button type="button" class="button button-primary" id="mraf-btn-bulk-convert" disabled><?php esc_html_e('Convert to WebP', 'mraf'); ?></button>
                        <button type="button" class="button" id="mraf-btn-bulk-cancel"><?php esc_html_e('Cancel', 'mraf'); ?></button>
                    </div>

                    <div class="mraf-grid" id="mraf-grid"></div>

                    <div class="mraf-browser__foot">
                        <span class="spinner" id="mraf-spinner"></span>
                        <span id="mraf-shown"></span>
                        <span class="mraf-bar__spacer"></span>
                        <button type="button" class="button" id="mraf-btn-more" hidden><?php esc_html_e('Load more', 'mraf'); ?></button>
                        <span class="mraf-hint" id="mraf-hint">
                            <?php
                            echo wp_kses(
                                __('Click an image to work on it. Press <strong>Bulk Select</strong> to pick several.', 'mraf'),
                                ['strong' => []]
                            );
                            ?>
                        </span>
                    </div>
                </div>
            </section>

            <!-- ============================ BACKUPS ============================ -->
            <section class="mraf-panel" data-panel="backups" hidden>
                <p class="description">
                    <?php esc_html_e('Every conversion and replacement is backed up before it writes. Restore any image to its original state.', 'mraf'); ?>
                </p>
                <table class="widefat striped mraf-table" id="mraf-backups">
                    <thead>
                        <tr>
                            <th style="width:70px;"><?php esc_html_e('ID', 'mraf'); ?></th>
                            <th><?php esc_html_e('Original file', 'mraf'); ?></th>
                            <th style="width:170px;"><?php esc_html_e('Backed up', 'mraf'); ?></th>
                            <th style="width:120px;"><?php esc_html_e('Files', 'mraf'); ?></th>
                            <th style="width:190px;"><?php esc_html_e('Actions', 'mraf'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="5"><?php esc_html_e('Loading backups…', 'mraf'); ?></td></tr>
                    </tbody>
                </table>
            </section>

            <!-- ============================ SETTINGS ============================ -->
            <section class="mraf-panel" data-panel="settings" hidden>
                <div class="mraf-card">
                    <h2><?php esc_html_e('Engine and server', 'mraf'); ?></h2>
                    <table class="mraf-info">
                        <tr>
                            <td><?php esc_html_e('Active image library', 'mraf'); ?></td>
                            <td><code><?php echo esc_html(strtoupper($lib ?: 'none')); ?></code></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('WebP support', 'mraf'); ?></td>
                            <td>
                                <?php if ($webp): ?>
                                    <span class="mraf-pill is-ok"><?php esc_html_e('Available', 'mraf'); ?></span>
                                <?php else: ?>
                                    <span class="mraf-pill is-bad"><?php esc_html_e('Not supported — install GD WebP or Imagick WebP', 'mraf'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('PHP memory limit', 'mraf'); ?></td>
                            <td><code><?php echo esc_html(ini_get('memory_limit')); ?></code></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Max upload size', 'mraf'); ?></td>
                            <td><code><?php echo esc_html(size_format(wp_max_upload_size())); ?></code></td>
                        </tr>
                    </table>

                    <hr>

                    <h2><?php esc_html_e('Media Library', 'mraf'); ?></h2>
                    <p class="description">
                        <?php
                        printf(
                            wp_kses(
                                __('The same actions are also available from the <a href="%s">Media Library</a> as row and bulk actions.', 'mraf'),
                                ['a' => ['href' => []]]
                            ),
                            esc_url(admin_url('upload.php?mode=list'))
                        );
                        ?>
                    </p>

                </div>

                <div class="mraf-card">
                    <h2><?php esc_html_e('Job settings', 'mraf'); ?></h2>
                    <p class="description"><?php esc_html_e('Every conversion and replacement, from this screen or the Media Library, uses these.', 'mraf'); ?></p>

                    <label class="mraf-check">
                        <input type="checkbox" id="mraf-s-refs" <?php checked($opt['update_refs'], true); ?>>
                        <span><?php esc_html_e('Update every reference in posts, pages, Elementor and ACF', 'mraf'); ?></span>
                    </label>
                    <label class="mraf-check">
                        <input type="checkbox" id="mraf-s-backup" <?php checked($opt['create_backup'], true); ?>>
                        <span><?php esc_html_e('Create a backup before writing', 'mraf'); ?></span>
                    </label>

                    <h3><?php esc_html_e('When converting to WebP', 'mraf'); ?></h3>

                    <div class="mraf-field">
                        <label for="mraf-s-quality"><?php esc_html_e('WebP quality', 'mraf'); ?></label>
                        <span class="mraf-range">
                            <input type="range" id="mraf-s-quality" min="1" max="100" value="<?php echo esc_attr($opt['quality']); ?>">
                            <output id="mraf-s-quality-val"><?php echo esc_html($opt['quality']); ?></output>
                        </span>
                        <p class="description"><?php esc_html_e('80 to 88 is safe for photos. Lower means smaller files.', 'mraf'); ?></p>
                    </div>

                    <label class="mraf-check">
                        <input type="checkbox" id="mraf-s-resize" <?php checked($opt['enable_resize'], true); ?>>
                        <span>
                            <?php esc_html_e('Limit max width to', 'mraf'); ?>
                            <input type="number" id="mraf-s-maxw" value="<?php echo esc_attr($opt['max_width']); ?>" min="1" step="10">
                            <?php esc_html_e('px', 'mraf'); ?>
                        </span>
                    </label>
                    <label class="mraf-check">
                        <input type="checkbox" id="mraf-s-delete" <?php checked($opt['delete_original'], true); ?>>
                        <span><?php esc_html_e('Delete the original file after a successful conversion', 'mraf'); ?></span>
                    </label>
                    <label class="mraf-check">
                        <input type="checkbox" id="mraf-s-keepname" <?php checked($opt['keep_basename'], true); ?>>
                        <span><?php esc_html_e('Keep the original file name where possible', 'mraf'); ?></span>
                    </label>

                    <h3><?php esc_html_e('When replacing a file', 'mraf'); ?></h3>

                    <label class="mraf-check">
                        <input type="checkbox" id="mraf-s-r-keepname" <?php checked($opt['r_keep_basename'], true); ?>>
                        <span><?php esc_html_e('Keep the same file name and URL so existing links never break', 'mraf'); ?></span>
                    </label>
                </div>

                <div class="mraf-card">
                    <h2><?php esc_html_e('Developer', 'mraf'); ?></h2>
                    <label class="mraf-check">
                        <input type="checkbox" id="mraf-setting-debug" <?php checked($show_debug, true); ?>>
                        <span><?php esc_html_e('Show the Debug Log tab', 'mraf'); ?></span>
                    </label>
                    <p class="description"><?php esc_html_e('Logs batch runs, URL rewrites and cache flushes.', 'mraf'); ?></p>
                    <p><button type="button" class="button button-primary" id="mraf-btn-save-settings"><?php esc_html_e('Save settings', 'mraf'); ?></button></p>
                </div>
            </section>

            <?php if ($show_debug): ?>
            <section class="mraf-panel" data-panel="debug" hidden>
                <div class="mraf-card">
                    <div class="mraf-card__head">
                        <h2><?php esc_html_e('Debug log', 'mraf'); ?></h2>
                        <span>
                            <button type="button" class="button" id="mraf-btn-refresh-log"><?php esc_html_e('Refresh', 'mraf'); ?></button>
                            <button type="button" class="button" id="mraf-btn-clear-log"><?php esc_html_e('Clear', 'mraf'); ?></button>
                        </span>
                    </div>
                    <pre class="mraf-console" id="mraf-console"><?php echo esc_html(get_transient(self::DEBUG_KEY) ?: __('Log is empty.', 'mraf')); ?></pre>
                </div>
            </section>
            <?php endif; ?>

            <?php MRAF_Library_Studio::action_modal(); ?>
        </div>
        <?php
    }

    /**
     * Helper: Convert image to WebP using active engine.
     */
    private function convert_to_webp($source_file, $quality = 85)
    {
        $dir = dirname($source_file);
        $base = pathinfo($source_file, PATHINFO_FILENAME);
        $output_file = $dir . '/' . $base . '.webp';

        if ($this->image_library === 'imagick') {
            $imagick = new Imagick($source_file);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality($quality);
            $imagick->writeImage($output_file);
            $imagick->clear();
            $imagick->destroy();
        } elseif ($this->image_library === 'gd') {
            $info = getimagesize($source_file);
            $mime = $info['mime'] ?? '';

            switch ($mime) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($source_file);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($source_file);
                    imagepalettetotruecolor($image);
                    imagealphablending($image, true);
                    imagesavealpha($image, true);
                    break;
                case 'image/gif':
                    $image = imagecreatefromgif($source_file);
                    break;
                default:
                    return ['success' => false, 'message' => __('Unsupported image format for GD conversion.', 'mraf')];
            }

            if (!$image) {
                return ['success' => false, 'message' => __('Failed to load image in GD.', 'mraf')];
            }

            imagewebp($image, $output_file, $quality);
            imagedestroy($image);
        } else {
            return ['success' => false, 'message' => __('No supported image engine available on server.', 'mraf')];
        }

        $old_size = filesize($source_file);
        $new_size = file_exists($output_file) ? filesize($output_file) : $old_size;
        $ratio = $old_size > 0 ? round((($old_size - $new_size) / $old_size) * 100) : 0;

        return [
            'success'           => true,
            'output_path'       => $output_file,
            'compression_ratio' => $ratio,
            'message'           => sprintf(__('Converted with %d%% compression savings.', 'mraf'), $ratio)
        ];
    }

    /**
     * Helper: Resize image to max width.
     */
    private function resize_to_max_width($source_file, $max_width)
    {
        $editor = wp_get_image_editor($source_file);
        if (is_wp_error($editor)) {
            return ['success' => false, 'message' => $editor->get_error_message()];
        }

        $size = $editor->get_size();
        if ($size['width'] <= $max_width) {
            return ['success' => true, 'output_path' => $source_file, 'message' => __('No resize needed.', 'mraf')];
        }

        $editor->resize($max_width, null, false);
        $temp_out = $source_file . '.resized.tmp';
        $saved = $editor->save($temp_out);

        if (is_wp_error($saved)) {
            return ['success' => false, 'message' => $saved->get_error_message()];
        }

        return ['success' => true, 'output_path' => $saved['path'], 'message' => __('Resized successfully.', 'mraf')];
    }

    /**
     * Helper: Delete attachment physical file and intermediate thumbnails.
     */
    private function delete_attachment_files($file_path, $meta)
    {
        $count = 0;
        if (file_exists($file_path)) {
            @unlink($file_path);
            $count++;
        }

        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            $base_dir = dirname($file_path);
            foreach ($meta['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $thumb = $base_dir . '/' . $size['file'];
                    if (file_exists($thumb)) {
                        @unlink($thumb);
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

}
