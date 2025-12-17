/**
 * MRAF Shared Utilities
 * Common functions used across Converter and Bulk Replacer
 *
 * @package MRAF
 * @version 1.0.0
 */

(function($) {
    'use strict';

    // Create global MRAF namespace
    window.MRAF = window.MRAF || {};

    /**
     * Shared Gallery/Search Rendering Module
     */
    window.MRAF.Gallery = {
        /**
         * Render search results grid
         *
         * @param {Array} images - Array of image objects
         * @param {jQuery} $container - Container element
         * @param {Object} options - Configuration options
         * @returns {jQuery} The grid element
         */
        renderSearchGrid: function(images, $container, options) {
            const defaults = {
                itemClass: 'mraf-search-item',
                gridClass: 'mraf-search-grid',
                badgeType: 'replaced', // 'replaced' or 'converted'
                buttonClass: 'mraf-add-to-list',
                buttonText: {
                    add: 'Add to List',
                    added: 'Added',
                    processed: 'Replace Again' // or 'Convert Again'
                },
                onButtonClick: function(image, $item, $btn) {},
                checkIfInList: function(imageId) { return false; },
                checkIfProcessed: function(image) { return false; }
            };

            const opts = $.extend({}, defaults, options);

            let grid = $container.find('.' + opts.gridClass);
            if (!grid.length) {
                grid = $('<div class="' + opts.gridClass + '"></div>');
                $container.append(grid);
            }

            images.forEach(function(image) {
                if (image.mime_type === 'image/svg+xml') {
                    return; // Skip SVG images
                }

                const thumbUrl = image.thumb + (image.thumb.indexOf('?') > -1 ? '&' : '?') + 't=' + Date.now();
                const isInList = opts.checkIfInList(image.id);
                const isProcessed = opts.checkIfProcessed(image);

                // Determine button state
                let buttonText = opts.buttonText.add;
                let buttonDisabled = false;

                if (isInList && !isProcessed) {
                    buttonText = opts.buttonText.added;
                    buttonDisabled = true;
                } else if (isProcessed) {
                    buttonText = opts.buttonText.processed;
                }

                // Create badge HTML
                const badgeTypeCapitalized = opts.badgeType.charAt(0).toUpperCase() + opts.badgeType.slice(1);
                const badgeProcessed = isProcessed ?
                    '<span class="mraf-badge mraf-badge-' + opts.badgeType + '">✓ ' + badgeTypeCapitalized + '</span>' : '';
                const badgeInList = (isInList && !isProcessed) ?
                    '<span class="mraf-badge mraf-badge-in-list">In List</span>' : '';

                const item = $('<div class="' + opts.itemClass + ' ' +
                    (isProcessed ? 'mraf-item-' + opts.badgeType : '') + ' ' +
                    (isInList ? 'mraf-item-in-list' : '') + '" data-id="' + image.id + '">' +
                    '<div class="mraf-search-thumb">' +
                        '<img src="' + thumbUrl + '" alt="' + MRAF.Utils.escapeHtml(image.title) + '" loading="lazy">' +
                        badgeProcessed +
                        badgeInList +
                    '</div>' +
                    '<div class="mraf-search-info">' +
                        '<strong style="display:block; white-space: normal;" title="' + MRAF.Utils.escapeHtml(image.filename) + '">' + MRAF.Utils.escapeHtml(image.filename) + '</strong>' +
                        '<span style="font-style: italic; color: #666; display:block;">Title: ' + MRAF.Utils.escapeHtml(image.title || '(none)') + '</span>' +
                        '<span style="font-style: italic; color: #666; display:block;">Alt: ' + MRAF.Utils.escapeHtml(image.alt_text || '(none)') + '</span>' +
                        '<span>' + image.dimensions + ' × ' + image.mime_type.split('/')[1].toUpperCase() + '</span>' +
                    '</div>' +
                    '<button type="button" class="mraf-btn mraf-btn-primary ' + opts.buttonClass + '" ' + (buttonDisabled ? 'disabled' : '') + '>' +
                        buttonText +
                    '</button>' +
                '</div>');

                // Store image data on element
                item.data('image-data', image);

                // Bind button click event
                item.find('.' + opts.buttonClass).on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    opts.onButtonClick(image, item, $(this));
                });

                grid.append(item);
            });

            return grid;
        },

        /**
         * Render "Load More" button
         *
         * @param {Object} data - Response data with pagination info
         * @param {jQuery} $container - Container element
         * @param {Function} onLoadMore - Callback when clicked
         */
        renderLoadMore: function(data, $container, onLoadMore) {
            // Remove existing load more button
            $container.find('#mraf-load-more-btn').parent().remove();

            if (data && data.has_more) {
                const remaining = data.total - (data.page * data.per_page);
                const loadMoreBtn = $('<div style="text-align: center; padding: 20px;">' +
                    '<button type="button" class="mraf-btn mraf-btn-primary" id="mraf-load-more-btn" style="padding: 0 24px;">' +
                        'Load More (' + remaining + ' remaining)' +
                    '</button>' +
                '</div>');

                loadMoreBtn.find('#mraf-load-more-btn').on('click', function() {
                    onLoadMore(data.page + 1);
                });

                $container.append(loadMoreBtn);
            }
        },

        /**
         * Update search item state after adding to list
         *
         * @param {jQuery} $item - The item element
         * @param {jQuery} $button - The button element
         * @param {Object} options - State options
         */
        updateItemState: function($item, $button, options) {
            const defaults = {
                buttonText: 'Added',
                removeClass: null, // e.g., 'mraf-item-replaced' or 'mraf-item-converted'
                removeBadge: null, // e.g., '.mraf-badge-replaced' or '.mraf-badge-converted'
                addBadgeHtml: '<span class="mraf-badge mraf-badge-in-list">Added</span>'
            };

            const opts = $.extend({}, defaults, options);

            // Update button state
            $button.text(opts.buttonText).prop('disabled', true);

            // Add "in list" class
            $item.addClass('mraf-item-in-list');

            // Remove processed class if specified
            if (opts.removeClass) {
                $item.removeClass(opts.removeClass);
            }

            // Remove processed badge if specified
            if (opts.removeBadge) {
                $item.find(opts.removeBadge).remove();
            }

            // Add "Added" badge if not already there
            if (opts.addBadgeHtml && !$item.find('.mraf-badge-in-list').length) {
                $item.find('.mraf-search-thumb').append(opts.addBadgeHtml);
            }
        },

        /**
         * Highlight tab badge with animation
         *
         * @param {jQuery} $badge - Badge element
         * @param {String} color - Highlight color (default: #00a32a)
         */
        highlightTabBadge: function($badge, color) {
            color = color || '#00a32a';

            if ($badge && $badge.length) {
                $badge.css({
                    'background': color,
                    'transform': 'scale(1.3)',
                    'transition': 'all 0.3s ease'
                });

                setTimeout(function() {
                    $badge.css({
                        'background': '',
                        'transform': '',
                        'transition': 'all 0.3s ease'
                    });
                }, 1000);
            }
        }
    };

    /**
     * Shared Utility Functions
     */
    window.MRAF.Utils = {
        /**
         * Escape HTML special characters
         *
         * @param {String} text - Text to escape
         * @returns {String} Escaped text
         */
        escapeHtml: function(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) {
                return map[m];
            });
        },

        /**
         * Show toast notification
         *
         * @param {String} message - Message to display
         * @param {String} type - Type: success, error, info, warning
         * @param {Number} duration - Duration in ms (default: 3000)
         */
        showToast: function(message, type, duration) {
            type = type || 'info';
            duration = duration || 3000;

            // Remove existing toasts
            $('.mraf-toast').remove();

            const colors = {
                success: '#00a32a',
                error: '#dc3232',
                info: '#2271b1',
                warning: '#f0b849'
            };

            const toast = $('<div class="mraf-toast" style="' +
                'position: fixed;' +
                'bottom: 30px;' +
                'right: 30px;' +
                'background: ' + (colors[type] || colors.info) + ';' +
                'color: white;' +
                'padding: 16px 24px;' +
                'border-radius: 8px;' +
                'box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);' +
                'z-index: 999999;' +
                'font-size: 14px;' +
                'font-weight: 500;' +
                'animation: mraf-slide-in 0.3s ease;' +
            '">' + MRAF.Utils.escapeHtml(message) + '</div>');

            $('body').append(toast);

            setTimeout(function() {
                toast.fadeOut(300, function() {
                    $(this).remove();
                });
            }, duration);
        },

        /**
         * Generate default title from filename
         *
         * @param {String} filename - Original filename
         * @returns {String} Sanitized title
         */
        generateDefaultTitle: function(filename) {
            return filename
                .replace(/\.[^/.]+$/, '') // Remove extension
                .replace(/[^a-z0-9]/gi, '-') // Replace non-alphanumeric with hyphen
                .replace(/-+/g, '-') // Replace multiple hyphens
                .replace(/^-|-$/g, '') // Remove leading/trailing hyphens
                .toLowerCase();
        }
    };

    // Add CSS for toast animation if not already present
    if (!$('#mraf-toast-styles').length) {
        $('<style id="mraf-toast-styles">' +
            '@keyframes mraf-slide-in {' +
                'from { transform: translateX(400px); opacity: 0; }' +
                'to { transform: translateX(0); opacity: 1; }' +
            '}' +
        '</style>').appendTo('head');
    }

})(jQuery);
