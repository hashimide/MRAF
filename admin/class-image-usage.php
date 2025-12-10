<?php
/**
 * Attachment usage inspector shown on the media edit screen.
 *
 * @package MRAF
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MRAF_Image_Usage
 *
 * Handles image usage tracking and display in WordPress media library.
 */
class MRAF_Image_Usage {

    /**
     * Constructor - registers all hooks and filters.
     */
    public function __construct() {
        add_action( 'edit_attachment', array( $this, 'flush_usage_cache' ) );
        add_action( 'delete_attachment', array( $this, 'flush_usage_cache' ) );
        add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_usage_field' ), 20, 2 );
        add_action( 'wp_ajax_mraf_get_usage', array( $this, 'ajax_get_usage' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_usage_assets' ) );
    }

    /**
     * Build the transient key used to cache usage lookups.
     *
     * @param int $attachment_id Attachment ID.
     * @return string
     */
    public function usage_cache_key( $attachment_id ) {
        return 'mraf_usage_' . absint( $attachment_id );
    }

    /**
     * Clear the cached usage report for a given attachment.
     *
     * @param int $attachment_id Attachment ID.
     */
    public function flush_usage_cache( $attachment_id ) {
        delete_transient( $this->usage_cache_key( $attachment_id ) );
    }

    /**
     * Render the custom "Image Usage" field on the attachment edit form.
     *
     * @param array   $form_fields Form fields.
     * @param WP_Post $post        Post object.
     * @return array
     */
    public function attachment_usage_field( $form_fields, $post ) {
        if ( 'attachment' !== $post->post_type || ! wp_attachment_is_image( $post ) ) {
            return $form_fields;
        }

        $html   = '';
        $action = '';

        if ( wp_doing_ajax() ) {
            $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
            $allowed = [ 'query-attachments', 'get-attachment', 'save-attachment', 'save-attachment-compat' ];
            if ( $action && ! in_array( $action, $allowed, true ) ) {
                return $form_fields;
            }
        }

        if ( 'query-attachments' === $action ) {
            $html = '<p class="mraf-usage-placeholder" data-mraf-attachment="' . (int) $post->ID . '">' .
                esc_html__( 'Loading image usage…', 'mraf' ) . '</p>';
        } else {
            $usage = $this->find_image_usage_locations( $post->ID );
            $html  = $this->render_usage_html( $usage );
        }

        $form_fields['mraf_usage'] = [
            'label' => __( 'Image Usage', 'mraf' ),
            'input' => 'html',
            'html'  => $html,
        ];

        return $form_fields;
    }

    /**
     * Convert usage data to HTML list markup.
     *
     * @param array $usage Usage map.
     * @return string
     */
    public function render_usage_html( array $usage ) {
        ob_start();
        if ( empty( $usage ) ) {
            echo '<p><em>' . esc_html__( 'This image is not referenced anywhere yet.', 'mraf' ) . '</em></p>';
        } else {
            foreach ( $usage as $section ) {
                if ( empty( $section['items'] ) ) {
                    continue;
                }
                echo '<strong>' . esc_html( $section['label'] ) . ':</strong>';
                echo "<ul style='margin:4px 0 10px 16px;'>";
                foreach ( $section['items'] as $item ) {
                    $label = isset( $item['title'] ) && $item['title'] !== '' ? $item['title'] : __( '(no title)', 'mraf' );
                    $type  = isset( $item['type'] ) ? $item['type'] : '';
                    $meta  = '';

                    if ( ! empty( $item['meta'] ) && is_string( $item['meta'] ) && 0 !== strpos( $item['meta'], '_wp_' ) ) {
                        $meta = ' <small>(' . esc_html( $item['meta'] ) . ')</small>';
                    } elseif ( $type && 'option' !== $type ) {
                        $meta = ' <small>(' . esc_html( $type ) . ')</small>';
                    }

                    if ( ! empty( $item['edit_link'] ) ) {
                        echo '<li><a href="' . esc_url( $item['edit_link'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>' . $meta . '</li>';
                    } else {
                        echo '<li>' . esc_html( $label ) . $meta . '</li>';
                    }
                }
                echo '</ul>';
            }
        }
        return ob_get_clean();
    }

    /**
     * Locate everywhere the attachment is referenced.
     *
     * @param int $attachment_id Attachment ID.
     * @return array
     */
    public function find_image_usage_locations( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id ) {
            return [];
        }

        static $runtime_cache = [];
        if ( isset( $runtime_cache[ $attachment_id ] ) ) {
            return $runtime_cache[ $attachment_id ];
        }

        $cache_key = $this->usage_cache_key( $attachment_id );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            $runtime_cache[ $attachment_id ] = $cached;
            return $cached;
        }

        global $wpdb;
        $urls      = $this->get_attachment_url_variants( $attachment_id );
        $patterns  = $this->build_like_patterns( $attachment_id, $urls );
        $results   = [];

        // Content (classic + blocks).
        $content_items = $this->usage_query_posts_by_content( $patterns );
        if ( ! empty( $content_items ) ) {
            $results['content'] = [
                'label' => __( 'Used Inside Content (Editor / Blocks)', 'mraf' ),
                'items' => $content_items,
            ];
        }

        // Elementor-specific meta.
        $elementor_items = $this->usage_query_elementor_meta( $patterns );
        if ( ! empty( $elementor_items ) ) {
            $results['elementor'] = [
                'label' => __( 'Used in Elementor (Sections / Widgets)', 'mraf' ),
                'items' => $elementor_items,
            ];
        }

        // Featured images.
        $featured_items = $this->usage_query_featured_images( $attachment_id );
        if ( ! empty( $featured_items ) ) {
            $results['featured'] = [
                'label' => __( 'Used as Featured Image', 'mraf' ),
                'items' => $featured_items,
            ];
        }

        // Other meta (ACF, custom builders, etc.).
        $meta_items = $this->usage_query_other_meta( $patterns, $attachment_id );
        if ( ! empty( $meta_items ) ) {
            $results['other_meta'] = [
                'label' => __( 'Used in Other Post Meta', 'mraf' ),
                'items' => $meta_items,
            ];
        }

        // Options / theme settings.
        $option_items = $this->usage_query_options( $patterns, $attachment_id );
        if ( ! empty( $option_items ) ) {
            $results['options'] = [
                'label' => __( 'Used in Options / Settings', 'mraf' ),
                'items' => $option_items,
            ];
        }

        set_transient( $cache_key, $results, 10 * MINUTE_IN_SECONDS );
        $runtime_cache[ $attachment_id ] = $results;

        return $results;
    }

    /**
     * Build LIKE %pattern% clauses that cover URLs and JSON references.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $urls          Array of URL variants.
     * @return array
     */
    public function build_like_patterns( $attachment_id, array $urls ) {
        global $wpdb;

        $patterns = [];
        foreach ( $urls as $url ) {
            $patterns[] = '%' . $wpdb->esc_like( $url ) . '%';
        }

        $attachment_id = absint( $attachment_id );
        if ( $attachment_id ) {
            $markers = [
                'wp-image-' . $attachment_id,
                '"id":' . $attachment_id,
                '"id":"' . $attachment_id . '"',
                '"image":"' . $attachment_id . '"',
                '"image_id":"' . $attachment_id . '"',
            ];
            foreach ( $markers as $marker ) {
                $patterns[] = '%' . $wpdb->esc_like( $marker ) . '%';
            }

            $plain_id = (string) $attachment_id;
            $len      = strlen( $plain_id );

            $serialized_markers = [
                's:' . $len . ':"' . $plain_id . '"',
                '"ID";s:' . $len . ':"' . $plain_id . '"',
                '"id";s:' . $len . ':"' . $plain_id . '"',
                '\"ID\";s:' . $len . ':\"' . $plain_id . '\"',
                '\"id\";s:' . $len . ':\"' . $plain_id . '\"',
            ];
            $int_markers = [
                'i:' . $plain_id . ';',
                '"ID";i:' . $plain_id,
                '"id";i:' . $plain_id,
                '\"ID\";i:' . $plain_id,
                '\"id\";i:' . $plain_id,
            ];

            foreach ( array_merge( $serialized_markers, $int_markers ) as $marker ) {
                $patterns[] = '%' . $wpdb->esc_like( $marker ) . '%';
            }

            if ( $len >= 2 ) {
                $patterns[] = '%' . $wpdb->esc_like( '"' . $plain_id . '"' ) . '%';
            }
        }

        return array_values( array_unique( array_filter( $patterns ) ) );
    }

    /**
     * Return an array of URL variations for the attachment (full, relative, sizes).
     *
     * @param int $attachment_id Attachment ID.
     * @return array
     */
    public function get_attachment_url_variants( $attachment_id ) {
        $urls    = [];
        $main    = wp_get_attachment_url( $attachment_id );
        $meta    = wp_get_attachment_metadata( $attachment_id );
        $baseurl = '';
        $dir     = '';

        if ( $main ) {
            $urls[]  = $main;
            $parsed  = wp_parse_url( $main, PHP_URL_PATH );
            $baseurl = trailingslashit( substr( $main, 0, strrpos( $main, '/' ) + 1 ) );
            if ( $parsed ) {
                $urls[] = ltrim( $parsed, '/' );
                $urls[] = $parsed;
            }
            $filename = wp_basename( $main );
            if ( $filename ) {
                $urls[] = $filename;
            }
            $dir = $baseurl;
        }

        if ( $dir && ! empty( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size ) {
                if ( empty( $size['file'] ) ) {
                    continue;
                }
                $urls[] = $dir . ltrim( $size['file'], '/' );
                $urls[] = ltrim( wp_parse_url( $dir . ltrim( $size['file'], '/' ), PHP_URL_PATH ), '/' );
            }
        }

        if ( $dir && ! empty( $meta['original_image'] ) ) {
            $urls[] = $dir . ltrim( $meta['original_image'], '/' );
        }

        return array_values( array_unique( array_filter( $urls ) ) );
    }

    /**
     * Query posts whose content references any of the patterns.
     *
     * @param array $patterns Array of LIKE patterns.
     * @return array
     */
    public function usage_query_posts_by_content( array $patterns ) {
        global $wpdb;
        if ( empty( $patterns ) ) {
            return [];
        }

        $content_where = implode( ' OR ', array_fill( 0, count( $patterns ), 'post_content LIKE %s' ) );
        $sql           = "
            SELECT ID, post_title, post_type FROM {$wpdb->posts}
            WHERE post_status IN ('publish','draft','future','pending','private')
              AND post_type != 'revision'
              AND ( $content_where )
        ";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$patterns ) );

        return array_map(
            function ( $row ) {
                return [
                    'id'        => (int) $row->ID,
                    'title'     => $row->post_title,
                    'type'      => $row->post_type,
                    'edit_link' => get_edit_post_link( $row->ID ),
                ];
            },
            $rows
        );
    }

    /**
     * Query Elementor meta rows referencing the attachment.
     *
     * @param array $patterns Array of LIKE patterns.
     * @return array
     */
    public function usage_query_elementor_meta( array $patterns ) {
        global $wpdb;
        if ( empty( $patterns ) ) {
            return [];
        }

        $meta_where = implode( ' OR ', array_fill( 0, count( $patterns ), 'pm.meta_value LIKE %s' ) );
        $sql        = "
            SELECT DISTINCT p.ID, p.post_title, p.post_type
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type != 'revision'
              AND pm.meta_key IN ('_elementor_data','_elementor_page_settings')
              AND ( $meta_where )
        ";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$patterns ) );

        return array_map(
            function ( $row ) {
                return [
                    'id'        => (int) $row->ID,
                    'title'     => $row->post_title,
                    'type'      => $row->post_type,
                    'edit_link' => get_edit_post_link( $row->ID ),
                ];
            },
            $rows
        );
    }

    /**
     * List posts that use the attachment as their featured image.
     *
     * @param int $attachment_id Attachment ID.
     * @return array
     */
    public function usage_query_featured_images( $attachment_id ) {
        global $wpdb;

        $sql  = "
            SELECT p.ID, p.post_title, p.post_type
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type != 'revision'
              AND pm.meta_key = '_thumbnail_id'
              AND pm.meta_value = %d
        ";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $attachment_id ) );

        return array_map(
            function ( $row ) {
                return [
                    'id'        => (int) $row->ID,
                    'title'     => $row->post_title,
                    'type'      => $row->post_type,
                    'edit_link' => get_edit_post_link( $row->ID ),
                ];
            },
            $rows
        );
    }

    /**
     * Query non-Elementor meta that references the attachment.
     *
     * @param array $patterns      Array of LIKE patterns.
     * @param int   $attachment_id Attachment ID.
     * @return array
     */
    public function usage_query_other_meta( array $patterns, $attachment_id ) {
        global $wpdb;

        $excluded_keys = [
            '_elementor_data',
            '_elementor_page_settings',
            '_thumbnail_id',
            '_wp_attached_file',
            '_wp_attachment_metadata',
            '_wp_attachment_image_alt',
        ];

        $clauses = [];
        $params  = [];

        if ( ! empty( $patterns ) ) {
            $clauses[] = '( ' . implode( ' OR ', array_fill( 0, count( $patterns ), 'pm.meta_value LIKE %s' ) ) . ' )';
            $params    = array_merge( $params, $patterns );
        }

        $attachment_id = absint( $attachment_id );
        if ( $attachment_id ) {
            $clauses[] = 'pm.meta_value = %s';
            $params[]  = (string) $attachment_id;
        }

        if ( empty( $clauses ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $excluded_keys ), '%s' ) );
        $value_where  = implode( ' OR ', $clauses );

        $sql = "
            SELECT pm.meta_id, pm.post_id, pm.meta_key, p.post_title, p.post_type
            FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE ( $value_where )
              AND pm.meta_key NOT IN ( $placeholders )
              AND ( p.post_type IS NULL OR ( p.post_type NOT IN ('revision','attachment') ) )
            LIMIT 50
        ";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( $params, $excluded_keys ) ) );

        $rows = array_filter(
            $rows,
            function ( $row ) {
                return ! empty( $row->post_id ) && ! in_array( $row->post_type, [ 'attachment', 'revision' ], true );
            }
        );

        return array_map(
            function ( $row ) {
                return [
                    'id'        => (int) $row->post_id,
                    'title'     => $row->post_title,
                    'type'      => $row->post_type,
                    'meta'      => $row->meta_key,
                    'edit_link' => $row->post_id ? get_edit_post_link( $row->post_id ) : '',
                ];
            },
            $rows
        );
    }

    /**
     * Query options / theme settings that reference the attachment.
     *
     * @param array $patterns      Array of LIKE patterns.
     * @param int   $attachment_id Attachment ID.
     * @return array
     */
    public function usage_query_options( array $patterns, $attachment_id ) {
        global $wpdb;

        $clauses = [];
        $params  = [];

        if ( ! empty( $patterns ) ) {
            $clauses[] = '( ' . implode( ' OR ', array_fill( 0, count( $patterns ), 'option_value LIKE %s' ) ) . ' )';
            $params    = array_merge( $params, $patterns );
        }

        $attachment_id = absint( $attachment_id );
        if ( $attachment_id ) {
            $clauses[] = 'option_value = %s';
            $params[]  = (string) $attachment_id;
        }

        if ( empty( $clauses ) ) {
            return [];
        }

        $value_where = implode( ' OR ', $clauses );

        $sql = "
            SELECT option_id, option_name
            FROM {$wpdb->options}
            WHERE autoload IN ('yes','no')
              AND ( $value_where )
            LIMIT 50
        ";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        return array_map(
            function ( $row ) {
                return [
                    'title' => $row->option_name,
                    'type'  => 'option',
                ];
            },
            $rows
        );
    }

    /**
     * Ajax handler used by the media modal to fetch usage lazily.
     */
    public function ajax_get_usage() {
        check_ajax_referer( 'mraf_usage', 'nonce' );

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid attachment.', 'mraf' ) ] );
        }

        $usage = $this->find_image_usage_locations( $attachment_id );
        wp_send_json_success( [
            'html' => $this->render_usage_html( $usage ),
        ] );
    }

    /**
     * Enqueue admin assets that hydrate the usage placeholder in the media modal.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_usage_assets( $hook ) {
        $screens = [ 'upload.php', 'media-new.php', 'post.php', 'post-new.php' ];
        if ( ! in_array( $hook, $screens, true ) ) {
            return;
        }

        wp_enqueue_script(
            'mraf-usage',
            MRAF_PLUGIN_URL . 'admin/js/mraf-usage.js',
            [ 'jquery', 'media-views', 'wp-util' ],
            '1.0.0',
            true
        );

        wp_localize_script(
            'mraf-usage',
            'mrafUsage',
            [
                'nonce'  => wp_create_nonce( 'mraf_usage' ),
                'error'  => __( 'Unable to load image usage.', 'mraf' ),
                'empty'  => $this->render_usage_html( [] ),
            ]
        );

        wp_enqueue_script(
            'mraf-preview',
            MRAF_PLUGIN_URL . 'admin/js/mraf-preview.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );

        wp_add_inline_style(
            'media-views',
            '.mraf-preview{border:1px solid #dcdcde;padding:12px;margin:12px 0;background:#fff;} .mraf-preview ul{list-style:none;margin:8px 0 0;padding:0;} .mraf-preview li{margin:4px 0;display:flex;gap:6px;flex-wrap:wrap;} .mraf-preview__status{margin:6px 0 0;font-style:italic;}'
        );
    }
}
