<?php
/**
 * Library Studio - turns the core Media Library screen into the Media Studio.
 *
 * The plugin does not rebuild a gallery. It adds a status column, a status
 * filter, row actions and bulk actions to upload.php, and prints one action
 * modal that carries the options and the progress log.
 *
 * @package MRAF
 * @subpackage MRAF/admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class MRAF_Library_Studio
{
    const NONCE_ACTION = 'mraf_studio_action';

    /**
     * Register every hook this integration needs.
     */
    public function __construct()
    {
        // List mode: column, filter, row actions, bulk actions.
        add_filter('manage_media_columns', [$this, 'add_status_column']);
        add_action('manage_media_custom_column', [$this, 'render_status_column'], 10, 2);
        add_filter('media_row_actions', [$this, 'add_row_actions'], 10, 2);
        add_filter('bulk_actions-upload', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_actions'], 10, 3);
        add_action('restrict_manage_posts', [$this, 'render_status_filter']);
        add_action('pre_get_posts', [$this, 'apply_status_filter']);

        // Grid mode and the media modal: our tools next to the usage field.
        add_filter('attachment_fields_to_edit', [$this, 'add_studio_field'], 15, 2);

        // Screen furniture.
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_footer-upload.php', ['MRAF_Library_Studio', 'action_modal']);
    }

    /**
     * True on the media library screen.
     */
    private function is_library_screen()
    {
        global $pagenow;

        if (isset($pagenow) && $pagenow === 'upload.php') {
            return true;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        return $screen && $screen->id === 'upload';
    }

    /**
     * True on the library screen, and on the AJAX calls grid mode makes from it.
     *
     * Grid mode loads the attachment details over admin-ajax.php, where
     * $pagenow is admin-ajax.php and there is no screen, so the referer decides.
     */
    private function is_library_context()
    {
        if ($this->is_library_screen()) {
            return true;
        }

        if (!wp_doing_ajax()) {
            return false;
        }

        $referer = wp_get_referer();

        return $referer && strpos((string) wp_parse_url($referer, PHP_URL_PATH), 'upload.php') !== false;
    }

    /* ======================================================================
       List mode
       ====================================================================== */

    /**
     * Add the MRAF status column after the file column.
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_status_column($columns)
    {
        $out = [];

        foreach ($columns as $key => $label) {
            $out[$key] = $label;

            if ($key === 'title') {
                $out['mraf_status'] = __('Format', 'mraf');
            }
        }

        if (!isset($out['mraf_status'])) {
            $out['mraf_status'] = __('Format', 'mraf');
        }

        return $out;
    }

    /**
     * Print one cell of the status column.
     *
     * @param string $column        Column key.
     * @param int    $attachment_id Attachment ID.
     */
    public function render_status_column($column, $attachment_id)
    {
        if ($column !== 'mraf_status') {
            return;
        }

        $mime = get_post_mime_type($attachment_id);

        if (strpos((string) $mime, 'image/') !== 0) {
            echo '<span class="mraf-cell-muted">&mdash;</span>';
            return;
        }

        $file  = get_attached_file($attachment_id);
        $size  = ($file && file_exists($file)) ? size_format(filesize($file)) : '';
        $label = strtoupper(str_replace('image/', '', $mime));
        $done  = MRAF_Media_Studio::is_converted($attachment_id, $mime);

        printf(
            '<span class="mraf-badge %1$s">%2$s</span> <span class="mraf-cell-muted">%3$s</span><br><span class="mraf-cell-muted">%4$s</span>',
            $done ? 'is-ok' : '',
            esc_html($label),
            esc_html($size),
            $done ? esc_html__('converted', 'mraf') : esc_html__('not converted', 'mraf')
        );
    }

    /**
     * Add Convert and Replace to the row actions.
     *
     * @param array   $actions Existing actions.
     * @param WP_Post $post    Attachment.
     * @return array
     */
    public function add_row_actions($actions, $post)
    {
        if (!current_user_can('upload_files') || strpos((string) get_post_mime_type($post), 'image/') !== 0) {
            return $actions;
        }

        $actions['mraf_convert'] = sprintf(
            '<a href="#" class="mraf-row-action" data-mraf-action="convert" data-id="%d">%s</a>',
            $post->ID,
            esc_html__('Convert to WebP', 'mraf')
        );

        $actions['mraf_replace'] = sprintf(
            '<a href="#" class="mraf-row-action" data-mraf-action="replace" data-id="%d">%s</a>',
            $post->ID,
            esc_html__('Replace file', 'mraf')
        );

        return $actions;
    }

    /**
     * Add both jobs to the bulk actions dropdown.
     *
     * @param array $actions Existing bulk actions.
     * @return array
     */
    public function add_bulk_actions($actions)
    {
        if (!current_user_can('upload_files')) {
            return $actions;
        }

        $actions['mraf_convert'] = __('Convert to WebP', 'mraf');
        $actions['mraf_replace'] = __('Replace files', 'mraf');

        return $actions;
    }

    /**
     * Carry the chosen IDs back to the screen so the modal can open on them.
     *
     * Nothing is written here. The modal collects the options first.
     *
     * @param string $redirect Redirect URL.
     * @param string $action   Bulk action name.
     * @param array  $ids      Selected attachment IDs.
     * @return string
     */
    public function handle_bulk_actions($redirect, $action, $ids)
    {
        if ($action !== 'mraf_convert' && $action !== 'mraf_replace') {
            return $redirect;
        }

        $ids = array_filter(array_map('absint', (array) $ids));

        if (empty($ids)) {
            return $redirect;
        }

        return add_query_arg(
            [
                'mraf_job' => $action === 'mraf_convert' ? 'convert' : 'replace',
                'mraf_ids' => implode(',', array_slice($ids, 0, 500)),
            ],
            $redirect
        );
    }

    /**
     * Print the status dropdown next to the core filters.
     */
    public function render_status_filter()
    {
        if (!$this->is_library_screen()) {
            return;
        }

        $current = isset($_GET['mraf_status']) ? sanitize_key($_GET['mraf_status']) : '';
        $format  = isset($_GET['mraf_format']) ? sanitize_mime_type(wp_unslash($_GET['mraf_format'])) : '';
        ?>
        <label class="screen-reader-text" for="mraf_status"><?php esc_html_e('MRAF status', 'mraf'); ?></label>
        <select name="mraf_status" id="mraf_status">
            <option value=""><?php esc_html_e('All statuses', 'mraf'); ?></option>
            <?php foreach ($this->status_choices() as $value => $label): ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label class="screen-reader-text" for="mraf_format"><?php esc_html_e('Image format', 'mraf'); ?></label>
        <select name="mraf_format" id="mraf_format">
            <option value=""><?php esc_html_e('All formats', 'mraf'); ?></option>
            <?php foreach ($this->format_choices() as $value => $label): ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($format, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * The image formats the format dropdown offers.
     *
     * @return array MIME type => label.
     */
    private function format_choices()
    {
        return [
            'image/jpeg' => __('JPG', 'mraf'),
            'image/png'  => __('PNG', 'mraf'),
            'image/webp' => __('WebP', 'mraf'),
            'image/gif'  => __('GIF', 'mraf'),
            'image/avif' => __('AVIF', 'mraf'),
        ];
    }

    /**
     * Apply the status dropdown to the main query.
     *
     * @param WP_Query $query Current query.
     */
    public function apply_status_filter($query)
    {
        if (!is_admin() || !$query->is_main_query() || !$this->is_library_screen()) {
            return;
        }

        $format = isset($_GET['mraf_format']) ? sanitize_mime_type(wp_unslash($_GET['mraf_format'])) : '';

        if ($format && array_key_exists($format, $this->format_choices())) {
            $query->set('post_mime_type', $format);
        }

        $status = isset($_GET['mraf_status']) ? sanitize_key($_GET['mraf_status']) : '';

        if (!$status) {
            return;
        }

        $map = [
            'unconverted' => ['key' => '_mraf_converted', 'compare' => 'NOT EXISTS'],
            'converted'   => ['key' => '_mraf_converted', 'compare' => 'EXISTS'],
            'unreplaced'  => ['key' => '_mraf_replaced_at', 'compare' => 'NOT EXISTS'],
        ];

        if (!isset($map[$status])) {
            return;
        }

        $meta_query   = (array) $query->get('meta_query');
        $meta_query[] = $map[$status];

        $query->set('meta_query', $meta_query);
    }

    /* ======================================================================
       Grid mode and the media modal
       ====================================================================== */

    /**
     * Add the studio buttons to the attachment details sidebar.
     *
     * @param array   $fields Existing fields.
     * @param WP_Post $post   Attachment.
     * @return array
     */
    public function add_studio_field($fields, $post)
    {
        if (!$this->is_library_context() || !current_user_can('upload_files')) {
            return $fields;
        }

        if (strpos((string) get_post_mime_type($post), 'image/') !== 0) {
            return $fields;
        }

        $done = MRAF_Media_Studio::is_converted($post->ID);

        $html = '<div class="mraf-modal-tools" data-id="' . esc_attr($post->ID) . '">';

        if ($done) {
            $html .= '<p class="mraf-cell-muted">' . esc_html__('Already converted to WebP.', 'mraf') . '</p>';
        }

        $html .= '<button type="button" class="button button-primary mraf-row-action" data-mraf-action="convert" data-id="' . esc_attr($post->ID) . '">'
            . esc_html__('Convert to WebP', 'mraf') . '</button> ';
        $html .= '<button type="button" class="button mraf-row-action" data-mraf-action="replace" data-id="' . esc_attr($post->ID) . '">'
            . esc_html__('Replace file', 'mraf') . '</button>';
        $html .= '</div>';

        $fields['mraf_studio'] = [
            'label' => __('Media Studio', 'mraf'),
            'input' => 'html',
            'html'  => $html,
        ];

        return $fields;
    }

    /**
     * The status choices both modes offer.
     *
     * @return array Key => label.
     */
    private function status_choices()
    {
        return [
            'unconverted' => __('Not converted', 'mraf'),
            'converted'   => __('Already converted', 'mraf'),
            'unreplaced'  => __('Never replaced', 'mraf'),
        ];
    }

    /* ======================================================================
       Screen furniture
       ====================================================================== */

    /**
     * Load the studio assets on the library screen.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue($hook)
    {
        if ($hook !== 'upload.php') {
            return;
        }

        wp_enqueue_media();

        $js_ver  = @filemtime(MRAF_PLUGIN_DIR . 'admin/js/mraf-studio.js') ?: MRAF_VERSION;
        $css_ver = @filemtime(MRAF_PLUGIN_DIR . 'admin/css/mraf-studio.css') ?: MRAF_VERSION;

        wp_enqueue_script('mraf-studio', MRAF_PLUGIN_URL . 'admin/js/mraf-studio.js', ['jquery'], $js_ver, true);
        wp_enqueue_style('mraf-studio', MRAF_PLUGIN_URL . 'admin/css/mraf-studio.css', [], $css_ver);

        $job = isset($_GET['mraf_job']) ? sanitize_key($_GET['mraf_job']) : '';
        $ids = isset($_GET['mraf_ids']) ? array_filter(array_map('absint', explode(',', (string) $_GET['mraf_ids']))) : [];

        wp_localize_script('mraf-studio', 'mrafStudioData', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce(self::NONCE_ACTION),
            'usageNonce'  => wp_create_nonce('mraf_usage'),
            'editUrlBase' => admin_url('post.php?action=edit&post='),
            'webpSupport' => $this->webp_supported(),
            // Without these the modal on this screen reports the defaults
            // rather than the settings the jobs actually run under.
            'options'     => MRAF_Media_Studio::get_options(),
            'settingsUrl' => admin_url('upload.php?page=mraf-media-studio#settings'),
            'pendingJob'  => $job && $ids ? ['task' => $job, 'ids' => array_values($ids)] : null,
            'strings'     => [
                'convert'     => __('Convert to WebP', 'mraf'),
                'replace'     => __('Replace file', 'mraf'),
                'selectFirst' => __('Select at least one image first.', 'mraf'),
                'needFile'    => __('Give at least one image a new file.', 'mraf'),
                'saved'       => __('Details saved.', 'mraf'),
                'copied'      => __('URL copied to clipboard.', 'mraf'),
                'finished'    => __('Finished.', 'mraf'),
                'stopped'     => __('Stopped. Nothing else was touched.', 'mraf'),
                'uploading'   => __('Uploading…', 'mraf'),
            ],
        ]);
    }

    /**
     * Detect WebP support on the active image engine.
     *
     * @return bool
     */
    private function webp_supported()
    {
        return MRAF_Media_Studio::webp_available();
    }

    /**
     * Print the one modal that carries options, pairing rows and progress.
     */
    public static function action_modal()
    {
        if (!current_user_can('upload_files')) {
            return;
        }
        ?>
        <div class="mraf-modal" id="mraf-modal" hidden>
            <div class="mraf-modal__box" role="dialog" aria-modal="true" aria-labelledby="mraf-m-title">

                <div class="mraf-modal__head">
                    <span class="mraf-modal__thumbs" id="mraf-m-thumbs"></span>
                    <span class="mraf-modal__id">
                        <strong id="mraf-m-title"><?php esc_html_e('Media Studio', 'mraf'); ?></strong>
                        <span id="mraf-m-sub"></span>
                    </span>
                    <button type="button" class="button button-primary" id="mraf-m-convert"><?php esc_html_e('Convert to WebP', 'mraf'); ?></button>
                    <button type="button" class="button" id="mraf-m-close"><?php esc_html_e('Close', 'mraf'); ?></button>
                </div>

                <div class="mraf-modal__tabs">
                    <button type="button" class="is-active" data-mtab="details"><?php esc_html_e('Details', 'mraf'); ?></button>
                    <button type="button" data-mtab="replace"><?php esc_html_e('Replace file', 'mraf'); ?></button>
                </div>

                <div class="mraf-modal__body">

                    <div class="mraf-mp" id="mraf-mp-replace" hidden>
                        <div class="mraf-rowline">
                            <span class="description"><?php esc_html_e('Give each image its new file.', 'mraf'); ?></span>
                            <span class="mraf-bar__spacer"></span>
                            <button type="button" class="mraf-linkbtn" id="mraf-btn-automatch"><?php esc_html_e('add new files and match by name', 'mraf'); ?></button>
                        </div>

                        <div id="mraf-pairs"></div>
                        <div id="mraf-match-note"></div>

                        <p class="description mraf-runnote">
                            <?php
                            printf(
                                wp_kses(
                                    __('Replacements follow the <a href="%s">saved settings</a>.', 'mraf'),
                                    ['a' => ['href' => []]]
                                ),
                                esc_url(admin_url('upload.php?page=mraf-media-studio') . '#settings')
                            );
                            ?>
                        </p>
                    </div>

                    <div class="mraf-mp" id="mraf-mp-details" hidden>
                        <p class="mraf-multi-note" id="mraf-d-multi" hidden>
                            <?php esc_html_e('Select a single image to edit its details.', 'mraf'); ?>
                        </p>

                        <div id="mraf-d-single">
                            <div class="mraf-form">
                                <label for="mraf-d-alt"><?php esc_html_e('Alternative Text', 'mraf'); ?></label>
                                <div>
                                    <textarea id="mraf-d-alt" rows="3"></textarea>
                                    <p class="description">
                                        <a href="https://www.w3.org/WAI/tutorials/images/decision-tree/" target="_blank" rel="noopener">
                                            <?php esc_html_e('Learn how to describe the purpose of the image', 'mraf'); ?>
                                        </a>.
                                        <?php esc_html_e('Leave empty if the image is purely decorative.', 'mraf'); ?>
                                    </p>
                                </div>

                                <label for="mraf-d-title"><?php esc_html_e('Title', 'mraf'); ?></label>
                                <div><input type="text" id="mraf-d-title"></div>

                                <label for="mraf-d-caption"><?php esc_html_e('Image Caption', 'mraf'); ?></label>
                                <div><textarea id="mraf-d-caption" rows="3"></textarea></div>

                                <label for="mraf-d-desc"><?php esc_html_e('Description', 'mraf'); ?></label>
                                <div><textarea id="mraf-d-desc" rows="3"></textarea></div>

                                <label for="mraf-d-url"><?php esc_html_e('File URL', 'mraf'); ?></label>
                                <div>
                                    <input type="text" id="mraf-d-url" readonly>
                                    <p><button type="button" class="button" id="mraf-btn-copy-url"><?php esc_html_e('Copy URL to clipboard', 'mraf'); ?></button></p>
                                </div>

                                <label><?php esc_html_e('Image Usage', 'mraf'); ?></label>
                                <div id="mraf-d-usage"></div>
                            </div>

                            <p class="mraf-dlinks">
                                <a id="mraf-d-view" target="_blank" rel="noopener"><?php esc_html_e('View media file', 'mraf'); ?></a> |
                                <a id="mraf-d-edit"><?php esc_html_e('Edit more details', 'mraf'); ?></a> |
                                <a id="mraf-d-download" download><?php esc_html_e('Download file', 'mraf'); ?></a>
                            </p>
                        </div>
                    </div>

                    <div class="mraf-mp" id="mraf-mp-convert" hidden>
                        <div class="mraf-rowline">
                            <span class="description" id="mraf-c-intro"></span>
                            <span class="mraf-bar__spacer"></span>
                            <button type="button" class="mraf-linkbtn" id="mraf-c-reset"><?php esc_html_e('reset names', 'mraf'); ?></button>
                        </div>

                        <div id="mraf-c-rows"></div>
                    </div>

                    <div class="mraf-mp" id="mraf-mp-run" hidden>
                        <div class="mraf-rowline">
                            <strong class="mraf-run__state" id="mraf-run-state"><?php esc_html_e('Starting…', 'mraf'); ?></strong>
                            <span class="mraf-bar__spacer"></span>
                            <span class="description mraf-run__pct" id="mraf-run-pct">0%</span>
                        </div>
                        <div class="mraf-prog"><i id="mraf-run-bar"></i></div>

                        <div class="mraf-runsum" id="mraf-run-summary" hidden></div>

                        <div class="mraf-log" id="mraf-run-log" hidden></div>
                    </div>
                </div>

                <div class="mraf-modal__foot">
                    <span class="description" id="mraf-m-foot"></span>
                    <span class="mraf-bar__spacer"></span>
                    <button type="button" class="button" id="mraf-m-stop" hidden><?php esc_html_e('Stop', 'mraf'); ?></button>
                    <button type="button" class="button button-primary" id="mraf-m-go"><?php esc_html_e('Convert', 'mraf'); ?></button>
                    <button type="button" class="button button-primary" id="mraf-m-done" hidden><?php esc_html_e('Done', 'mraf'); ?></button>
                </div>
            </div>
        </div>

        <input type="file" id="mraf-file-input" accept="image/*" hidden>
        <input type="file" id="mraf-files-input" accept="image/*" multiple hidden>
        <div class="mraf-toast" id="mraf-toast" hidden></div>
        <?php
    }
}
