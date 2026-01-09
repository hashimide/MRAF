(function($) {
    'use strict';

    const MRAFConverter = {
        selectedFiles: [],
        isProcessing: false,
        currentView: 'grid', // 'grid' or 'list'

        init: function() {
            this.cacheElements();
            this.bindEvents();
            this.initQualitySlider();
            this.checkServerRequirements();
            this.loadViewPreference();
            this.initTabs();
            this.initDebugTab();
        },

        cacheElements: function() {
            this.$dropZone = $('#mraf-drop-zone');
            this.$fileInput = $('#mraf-file-input');
            this.$selectFilesBtn = $('#mraf-select-files');
            this.$imagesList = $('#mraf-images-list');
            this.$imagesSection = $('#mraf-images-section');
            this.$imageCount = $('#mraf-image-count');
            this.$convertAllBtn = $('#mraf-convert-all');
            this.$clearAllBtn = $('#mraf-clear-all');
            this.$qualityInput = $('#mraf-quality');
            this.$qualitySlider = $('#mraf-quality-slider');
            this.$qualityLabel = $('.mraf-quality-label');
            this.$maxWidthEnable = $('#mraf-max-width-enable');
            this.$maxWidth = $('#mraf-max-width');
            this.$deleteOriginal = $('#mraf-delete-original');
            this.$progressSection = $('#mraf-progress-section');
            this.$progressFill = $('#mraf-progress-fill');
            this.$progressText = $('#mraf-progress-text');
            this.$notices = $('#mraf-converter-notices');
            this.$viewGridBtn = $('#mraf-view-grid');
            this.$viewListBtn = $('#mraf-view-list');
            this.$keepBasename = $('#mraf-keep-basename');
            this.$updateRefs = $('#mraf-update-refs');
            this.$createBackup = $('#mraf-create-backup');

            // Search & Filter tab elements
            this.$searchQuery = $('#mraf-search-query');
            this.$searchType = $('#mraf-search-type');
            this.$searchDate = $('#mraf-search-date');
            this.$searchBtn = $('#mraf-search-btn');
            this.$searchResults = $('#mraf-search-results');

            // Advanced filter elements
            this.$filterUnconverted = $('#mraf-filter-unconverted');
            this.$selectAllFormatBtn = $('#mraf-select-all-format');
        },

        bindEvents: function() {
            const self = this;

            // File selection via WordPress Media Library
            this.$selectFilesBtn.on('click', function() {
                self.openMediaLibrary();
            });

            // Quality slider
            this.$qualityInput.on('input change', function() {
                self.setQuality($(this).val());
            });
            this.$qualitySlider.on('input change', function() {
                self.setQuality($(this).val());
            });

            // Max width toggle
            this.$maxWidthEnable.on('change', function() {
                self.$maxWidth.prop('disabled', !$(this).is(':checked'));
            });

            // Convert all
            this.$convertAllBtn.on('click', function() {
                if (confirm(mrafConverterData.strings.confirmConvertAll)) {
                    self.convertAll();
                }
            });

            // Clear all
            this.$clearAllBtn.on('click', function() {
                self.clearAll();
            });

            // System info toggle
            $('.mraf-toggle-section').on('click', function() {
                const $content = $(this).closest('h3').next('.mraf-system-info-content');
                const $icon = $(this).find('.dashicons');

                $content.slideToggle();
                $icon.toggleClass('dashicons-arrow-right dashicons-arrow-down');
            });

            // View toggle
            this.$viewGridBtn.on('click', function() {
                self.switchView('grid');
            });

            this.$viewListBtn.on('click', function() {
                self.switchView('list');
            });

            // Search button
            this.$searchBtn.on('click', function() {
                self.performSearch(1, false);
            });

            // Enter key in search
            this.$searchQuery.on('keypress', function(e) {
                if (e.which === 13) {
                    self.performSearch(1, false);
                }
            });

            // Advanced filters
            this.$filterUnconverted.on('change', function() {
                self.performSearch(1, false);
            });

            this.$searchType.on('change', function() {
                self.performSearch(1, false);
            });

            this.$searchDate.on('change', function() {
                self.performSearch(1, false);
            });

            // Select all visible images
            this.$selectAllFormatBtn.on('click', function() {
                self.selectAllVisibleImages();
            });
        },

        loadViewPreference: function() {
            const savedView = localStorage.getItem('mraf_converter_view');
            if (savedView) {
                this.switchView(savedView);
            }
        },

        switchView: function(view) {
            this.currentView = view;
            localStorage.setItem('mraf_converter_view', view);

            if (view === 'grid') {
                this.$imagesList.removeClass('mraf-images-list').addClass('mraf-images-grid');
                this.$viewGridBtn.addClass('active');
                this.$viewListBtn.removeClass('active');
            } else {
                this.$imagesList.removeClass('mraf-images-grid').addClass('mraf-images-list');
                this.$viewListBtn.addClass('active');
                this.$viewGridBtn.removeClass('active');
            }

            // Re-render existing items
            this.renderAllItems();
        },

        renderAllItems: function() {
            this.$imagesList.empty();
            this.selectedFiles.forEach(file => {
                if (this.currentView === 'grid') {
                    this.renderGridItem(file);
                } else {
                    this.renderListItem(file);
                }
            });
        },

        setQuality: function(value) {
            let val = parseInt(value, 10);
            if (isNaN(val)) val = 85;
            val = Math.min(100, Math.max(1, val));

            this.$qualityInput.val(val);
            this.$qualitySlider.val(val);
            this.$qualityLabel.text(val + '%');

            const percent = ((val - 1) / 99) * 100;
            const fill = `linear-gradient(to right, var(--mraf-primary) 0%, var(--mraf-primary) ${percent}%, #e5e7eb ${percent}%, #e5e7eb 100%)`;
            this.$qualitySlider.css('background', fill);
        },

        initQualitySlider: function() {
            const initial = this.$qualityInput.val() || 85;
            this.setQuality(initial);

            // Initialize disabled state for max width
            this.$maxWidth.prop('disabled', !this.$maxWidthEnable.is(':checked'));
        },

        checkServerRequirements: function() {
            if (!mrafConverterData.webpSupported) {
                this.showNotice(mrafConverterData.strings.noWebpSupport, 'error');
                this.$convertAllBtn.prop('disabled', true);
            }

            if (!mrafConverterData.imageLibrary) {
                this.showNotice(mrafConverterData.strings.noLibrary, 'error');
                this.$convertAllBtn.prop('disabled', true);
            }
        },

        openMediaLibrary: function() {
            const self = this;

            if (!wp || !wp.media) {
                this.showNotice('WordPress Media Library not available', 'error');
                return;
            }

            const frame = wp.media({
                title: 'Select Images to Convert',
                button: {
                    text: 'Select Images'
                },
                multiple: true,
                library: {
                    type: 'image'
                }
            });

            frame.on('select', function() {
                const attachments = frame.state().get('selection').toJSON();
                self.addMediaLibraryImages(attachments);
            });

            frame.open();
        },

        addMediaLibraryImages: function(attachments) {
            attachments.forEach(attachment => {
                const fileId = 'file-' + attachment.id + '-' + Date.now();

                const fileData = {
                    id: fileId,
                    attachment_id: attachment.id,
                    file_path: attachment.url,
                    filename: attachment.filename,
                    title: this.generateDefaultTitle(attachment.filename),
                    width: attachment.width,
                    height: attachment.height,
                    filesize: attachment.filesizeHumanReadable || '',
                    preview_url: this.getPreviewUrl(attachment),
                    status: 'pending'
                };

                this.selectedFiles.push(fileData);

                if (this.currentView === 'grid') {
                    this.renderGridItem(fileData);
                } else {
                    this.renderListItem(fileData);
                }
            });

            this.updateUI();
        },

        getPreviewUrl: function(attachment) {
            if (attachment.sizes) {
                if (attachment.sizes.thumbnail) {
                    return attachment.sizes.thumbnail.url;
                } else if (attachment.sizes.medium) {
                    return attachment.sizes.medium.url;
                }
            }
            return attachment.url;
        },

        generateDefaultTitle: function(filename) {
            return MRAF.Utils.generateDefaultTitle(filename);
        },

        renderGridItem: function(fileData) {
            const itemHtml = `
                <div class="mraf-image-card" data-file-id="${fileData.id}">
                    <div class="mraf-image-wrapper">
                        <img src="${fileData.preview_url}" alt="${fileData.filename}">
                    </div>
                    <div class="mraf-card-content">
                        <strong>${fileData.filename}</strong>
                        <span class="mraf-image-meta">${fileData.width} × ${fileData.height}${fileData.filesize ? ' • ' + fileData.filesize : ''}</span>
                        <div class="mraf-image-title-field">
                            <label>New Title:</label>
                            <input type="text" class="mraf-title-input" value="${fileData.title}" placeholder="image-title" />
                            <span class="mraf-preview-filename">${fileData.title}.webp</span>
                        </div>
                        <div class="mraf-image-status">
                            <span class="mraf-status-badge mraf-status-pending">Pending</span>
                        </div>
                    </div>
                    <div class="mraf-image-actions">
                        <button type="button" class="button mraf-convert-single" title="Convert">
                            <span class="dashicons dashicons-image-rotate"></span>
                        </button>
                        <button type="button" class="button mraf-remove-image" title="Remove">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    </div>
                </div>
            `;

            this.$imagesList.append(itemHtml);
            this.bindFileItemEvents(fileData.id);
        },

        renderListItem: function(fileData) {
            const itemHtml = `
                <div class="mraf-image-item" data-file-id="${fileData.id}">
                    <div class="mraf-image-preview">
                        <img src="${fileData.preview_url}" alt="${fileData.filename}">
                    </div>
                    <div class="mraf-image-info">
                        <div class="mraf-image-details">
                            <strong>${fileData.filename}</strong>
                            <span class="mraf-image-meta">${fileData.width} × ${fileData.height}${fileData.filesize ? ' • ' + fileData.filesize : ''}</span>
                        </div>
                        <div class="mraf-image-title-field">
                            <label>New Title:</label>
                            <input type="text" class="mraf-title-input" value="${fileData.title}" placeholder="image-title" />
                            <span class="mraf-preview-filename">${fileData.title}.webp</span>
                        </div>
                        <div class="mraf-image-status">
                            <span class="mraf-status-badge mraf-status-pending">Pending</span>
                        </div>
                    </div>
                    <div class="mraf-image-actions">
                        <button type="button" class="button mraf-convert-single" title="Convert this image">
                            <span class="dashicons dashicons-image-rotate"></span>
                        </button>
                        <button type="button" class="button mraf-remove-image" title="Remove">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    </div>
                </div>
            `;

            this.$imagesList.append(itemHtml);
            this.bindFileItemEvents(fileData.id);
        },

        bindFileItemEvents: function(fileId) {
            const self = this;
            const $item = $('[data-file-id="' + fileId + '"]');

            // Title input change - use a debounce approach and only sanitize on blur
            const $titleInput = $item.find('.mraf-title-input');

            $titleInput.on('input', function() {
                const title = $(this).val();
                // Just update the preview, don't modify the input value while typing
                const sanitized = self.sanitizeTitle(title);
                $item.find('.mraf-preview-filename').text(sanitized + '.webp');

                // Update in selectedFiles with sanitized version
                const file = self.selectedFiles.find(f => f.id === fileId);
                if (file) {
                    file.title = sanitized;
                }
            });

            // Sanitize on blur (when user leaves the field)
            $titleInput.on('blur', function() {
                const title = $(this).val();
                const sanitized = self.sanitizeTitle(title);
                $(this).val(sanitized);
                $item.find('.mraf-preview-filename').text(sanitized + '.webp');

                // Update in selectedFiles
                const file = self.selectedFiles.find(f => f.id === fileId);
                if (file) {
                    file.title = sanitized;
                }
            });

            // Remove button
            $item.find('.mraf-remove-image').on('click', function() {
                self.removeFile(fileId);
            });

            // Convert single button
            $item.find('.mraf-convert-single').on('click', function() {
                self.convertSingle(fileId);
            });
        },

        sanitizeTitle: function(title) {
            return title
                .toLowerCase()
                .replace(/[^a-z0-9.\s\-]/g, '')  // Keep only alphanumeric, dots, spaces, and hyphens
                .replace(/\s+/g, '-')             // Convert spaces to hyphens
                .replace(/-+/g, '-')              // Remove consecutive hyphens
                .replace(/\.+/g, '.')             // Remove consecutive dots
                .replace(/^[\-\.]+|[\-\.]+$/g, ''); // Remove leading/trailing hyphens and dots
        },

        formatFileSize: function(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        },

        removeFile: function(fileId) {
            const file = this.selectedFiles.find(f => f.id === fileId);
            this.selectedFiles = this.selectedFiles.filter(f => f.id !== fileId);
            $('[data-file-id="' + fileId + '"]').fadeOut(300, function() {
                $(this).remove();
            });
            this.updateUI();
        },

        clearAll: function() {
            this.selectedFiles = [];
            this.$imagesList.empty();
            this.updateUI();
        },

        updateUI: function() {
            const count = this.selectedFiles.length;
            this.$imageCount.text(count);

            if (count > 0) {
                this.$imagesSection.show();
            } else {
                this.$imagesSection.hide();
            }

            this.$convertAllBtn.prop('disabled', count === 0 || this.isProcessing);
            this.$clearAllBtn.prop('disabled', count === 0 || this.isProcessing);
            this.updateManualTabCount();
        },

        updateFileStatus: function(fileId, status, message) {
            const $item = $('[data-file-id="' + fileId + '"]');
            const $statusBadge = $item.find('.mraf-status-badge');

            $statusBadge.removeClass('mraf-status-pending mraf-status-processing mraf-status-success mraf-status-error');
            $statusBadge.addClass('mraf-status-' + status);

            const statusTexts = {
                pending: 'Pending',
                processing: 'Processing...',
                success: 'Converted ✓',
                error: 'Failed ✗'
            };

            $statusBadge.text(statusTexts[status] || status);

            if (message) {
                $statusBadge.attr('title', message);
            }

            // Update in selectedFiles
            const file = this.selectedFiles.find(f => f.id === fileId);
            if (file) {
                file.status = status;
                if (file.attachment_id && status === 'success') {
                    this.markSearchItemConverted(file.attachment_id);
                }
            }
        },

        convertSingle: function(fileId) {
            const fileData = this.selectedFiles.find(f => f.id === fileId);
            if (!fileData) {
                console.error('MRAF Converter: File not found', fileId);
                return;
            }

            console.log('MRAF Converter: Starting conversion', fileData);

            this.updateFileStatus(fileId, 'processing');

            const data = {
                action: 'mraf_convert_single_image',
                nonce: mrafConverterData.nonce,
                attachment_id: fileData.attachment_id,
                title: fileData.title,
                quality: this.$qualityInput.val(),
                max_width: this.$maxWidth.val(),
                enable_resize: this.$maxWidthEnable.is(':checked') ? 'true' : 'false',
                delete_original: this.$deleteOriginal.is(':checked') ? 'true' : 'false',
                keep_basename: this.$keepBasename.is(':checked') ? 'true' : 'false',
                update_refs: this.$updateRefs.is(':checked') ? 'true' : 'false',
                create_backup: this.$createBackup.is(':checked') ? 'true' : 'false'
            };

            console.log('MRAF Converter: AJAX Request Data', data);

            const self = this;

            $.ajax({
                url: mrafConverterData.ajaxUrl,
                type: 'POST',
                data: data,
                success: function(response) {
                    console.log('MRAF Converter: AJAX Response', response);
                    if (response.success) {
                        self.updateFileStatus(fileId, 'success',
                            'Converted successfully! Compression: ' + response.data.compression_ratio + '%');
                        self.showNotice(response.data.message, 'success');
                        if (fileData.attachment_id) {
                            self.markSearchItemConverted(fileData.attachment_id);
                        }
                    } else {
                        console.error('MRAF Converter: Conversion failed', response.data);
                        self.updateFileStatus(fileId, 'error', response.data.message);
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('MRAF Converter: AJAX Error', {xhr, status, error});
                    self.updateFileStatus(fileId, 'error', mrafConverterData.strings.convertError);
                    self.showNotice(mrafConverterData.strings.convertError, 'error');
                }
            });
        },

        markSearchItemConverted: function(attachmentId) {
            const $item = $('.mraf-search-item[data-id="' + attachmentId + '"]');
            if (!$item.length) return;

            $item.addClass('mraf-item-replaced').removeClass('mraf-item-in-list');
            $item.find('.mraf-badge-replaced').remove();
            $item.find('.mraf-badge-in-list').remove();
            $item.find('.mraf-search-thumb').append('<span class="mraf-badge mraf-badge-replaced">Replaced</span>');

            const $btn = $item.find('.mraf-add-to-convert');
            $btn.text('Replace Again').prop('disabled', false);
        },

        convertAll: function() {
            if (this.isProcessing) return;

            const queue = this.selectedFiles.filter(f => f.status === 'pending');
            if (!queue.length) return;

            this.isProcessing = true;
            this.$convertAllBtn.prop('disabled', true);
            this.$clearAllBtn.prop('disabled', true);
            this.$progressSection.show();
            const total = queue.length;
            this.updateProgress(0, total);

            const self = this;
            let completed = 0;

            function runNext() {
                if (!queue.length) {
                    self.isProcessing = false;
                    self.updateUI();
                    self.showNotice('All images processed!', 'success');
                    setTimeout(function() {
                        self.$progressSection.fadeOut();
                    }, 1500);
                    return;
                }

                const nextFile = queue.shift();

                const originalSuccess = self.updateFileStatus.bind(self);
                // Wrap updateFileStatus to detect success/error completion for this file
                self.updateFileStatus = function(fileId, status, message) {
                    originalSuccess(fileId, status, message);
                    if (fileId === nextFile.id && (status === 'success' || status === 'error')) {
                        completed++;
                        self.updateProgress(completed, total);
                        self.updateFileStatus = originalSuccess;
                        runNext();
                    }
                };

                self.convertSingle(nextFile.id);
            }

            runNext();
        },

        updateProgress: function(current, total) {
            const percentage = total > 0 ? Math.round((current / total) * 100) : 0;
            this.$progressFill.css('width', percentage + '%');
            this.$progressText.text('Processing: ' + current + ' / ' + total + ' (' + percentage + '%)');
        },

        showNotice: function(message, type) {
            MRAF.Utils.showToast(message, type);
        },

        initTabs: function() {
            const self = this;

            $('.mraf-tab-btn').on('click', function() {
                const tab = $(this).data('tab');

                $('.mraf-tab-btn').removeClass('active');
                $(this).addClass('active');

                $('.mraf-tab-content').removeClass('active');
                $('.mraf-tab-content[data-tab="' + tab + '"]').addClass('active');
            });

            this.updateManualTabCount();
        },

        updateManualTabCount: function() {
            const count = this.selectedFiles.length;
            const $badge = $('#mraf-manual-count');

            if (count > 0) {
                $badge.text(count).show();
            } else {
                $badge.hide();
            }
        },

        performSearch: function(page, append) {
            const self = this;
            const query = this.$searchQuery.val();
            const type = this.$searchType.val();
            const date = this.$searchDate.val();
            const unconvertedOnly = this.$filterUnconverted.is(':checked');

            const data = {
                action: 'mraf_search_converter_images',
                nonce: mrafConverterData.nonce,
                query: query,
                type: type,
                date: date,
                unconverted_only: unconvertedOnly ? 1 : 0,
                page: page
            };

            if (!append) {
                this.$searchResults.html('<p style="padding: 20px; text-align: center;">Searching...</p>');
            } else {
                $('#mraf-load-more-btn').prop('disabled', true).text('Loading...');
            }

            $.post(mrafConverterData.ajaxUrl, data, function(response) {
                if (response.success) {
                    self.displaySearchResults(response.data.images, append, response.data);
                } else {
                    self.$searchResults.html('<p style="padding: 20px; text-align: center;">Search failed. Please try again.</p>');
                }
            }).fail(function() {
                self.$searchResults.html('<p style="padding: 20px; text-align: center;">Search failed. Please try again.</p>');
            });
        },

        displaySearchResults: function(images, append, data) {
            const self = this;

            if (images.length === 0 && !append) {
                this.$searchResults.html('<p style="padding: 20px; text-align: center;">No images found matching your criteria.</p>');
                return;
            }

            if (!append) {
                this.$searchResults.empty();
            } else {
                $('#mraf-load-more-btn').parent().remove();
            }

            // Use shared gallery rendering
            MRAF.Gallery.renderSearchGrid(images, this.$searchResults, {
                badgeType: 'converted',
                buttonClass: 'mraf-add-to-convert',
                buttonText: {
                    add: 'Add to Convert',
                    added: 'In List',
                    processed: 'Convert Again'
                },
                checkIfInList: function(imageId) {
                    return self.selectedFiles.some(f => f.attachment_id === imageId);
                },
                checkIfProcessed: function(image) {
                    return !!image.converted;
                },
                onButtonClick: function(image, $item, $btn) {
                    const wasProcessed = !!image.converted;

                    // Remove processed badge if applicable
                    if (wasProcessed) {
                        $item.removeClass('mraf-item-converted');
                        $item.find('.mraf-badge-converted').remove();
                    }

                    // Create file data object
                    const fileId = 'file-' + image.id + '-' + Date.now();
                    const fileData = {
                        id: fileId,
                        attachment_id: image.id,
                        file_path: image.url,
                        filename: image.filename,
                        title: MRAF.Utils.generateDefaultTitle(image.filename),
                        width: image.width,
                        height: image.height,
                        filesize: image.filesize,
                        preview_url: image.thumb,
                        status: 'pending'
                    };

                    self.selectedFiles.push(fileData);

                    if (self.currentView === 'grid') {
                        self.renderGridItem(fileData);
                    } else {
                        self.renderListItem(fileData);
                    }

                    self.updateUI();
                    self.updateManualTabCount();

                    // Update item state using shared function
                    MRAF.Gallery.updateItemState($item, $btn, {
                        buttonText: '✓ Added',
                        removeClass: 'mraf-item-converted',
                        removeBadge: '.mraf-badge-converted'
                    });

                    MRAF.Utils.showToast('Image added to conversion list!', 'success');

                    // Highlight the manual tab badge
                    MRAF.Gallery.highlightTabBadge($('#mraf-manual-count'));
                }
            });

            // Use shared load more rendering
            MRAF.Gallery.renderLoadMore(data, this.$searchResults, function(page) {
                self.performSearch(page, true);
            });
        },

        selectAllVisibleImages: function() {
            const self = this;
            let addedCount = 0;

            // Find all "Add to Convert" buttons that are not disabled
            this.$searchResults.find('.mraf-add-to-convert:not(:disabled)').each(function() {
                const $btn = $(this);
                const $item = $btn.closest('.mraf-search-item');
                const image = $item.data('image-data');

                if (!image) return;

                const imageId = image.id;
                const isInList = self.selectedFiles.some(f => f.attachment_id === imageId);
                const wasReplaced = !!image.converted;

                if (isInList && !wasReplaced) {
                    return; // Skip if already in list
                }

                // Remove replaced badge if applicable
                if (wasReplaced) {
                    $item.removeClass('mraf-item-replaced');
                    $item.find('.mraf-badge-replaced').remove();
                }

                // Create file data object from search result
                const fileId = 'file-' + image.id + '-' + Date.now() + '-' + addedCount;
                const fileData = {
                    id: fileId,
                    attachment_id: image.id,
                    file_path: image.url,
                    filename: image.filename,
                    title: self.generateDefaultTitle(image.filename),
                    width: image.width,
                    height: image.height,
                    filesize: image.filesize,
                    preview_url: image.thumb,
                    status: 'pending'
                };

                self.selectedFiles.push(fileData);

                if (self.currentView === 'grid') {
                    self.renderGridItem(fileData);
                } else {
                    self.renderListItem(fileData);
                }

                // Update button and item state using shared function
                MRAF.Gallery.updateItemState($item, $btn, {
                    buttonText: '✓ Added',
                    removeClass: 'mraf-item-converted',
                    removeBadge: '.mraf-badge-converted'
                });

                addedCount++;
            });

            // Update UI once at the end
            if (addedCount > 0) {
                self.updateUI();
                self.updateManualTabCount();

                // Highlight the manual tab badge using shared function
                MRAF.Gallery.highlightTabBadge($('#mraf-manual-count'));

                // Show single notification
                this.showNotice(`Added ${addedCount} image(s) to conversion list!`, 'success');
            } else {
                this.showNotice('No new images to add. They may already be in the list.', 'info');
            }
        },

        initDebugTab: function() {
            const self = this;

            $('#mraf-converter-refresh-log').on('click', function() {
                self.refreshDebugLog();
            });

            $('#mraf-converter-clear-log').on('click', function() {
                self.clearDebugLog();
            });
        },

        refreshDebugLog: function() {
            $.post(mrafConverterData.ajaxUrl, {
                action: 'mraf_get_converter_debug',
                nonce: mrafConverterData.nonce
            }, function(response) {
                if (response.success) {
                    $('#mraf-converter-debug-log').text(response.data.log);
                }
            });
        },

        clearDebugLog: function() {
            $.post(mrafConverterData.ajaxUrl, {
                action: 'mraf_get_converter_debug',
                nonce: mrafConverterData.nonce,
                clear: 1
            }, function(response) {
                if (response.success) {
                    $('#mraf-converter-debug-log').text(response.data.log);
                }
            });
        },

        escapeHtml: function(text) {
            return MRAF.Utils.escapeHtml(text);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        MRAFConverter.init();
    });

})(jQuery);

