(function ($) {
    'use strict';

    let rowCount = 0;
    let uploadedFilesCache = {};

    // Debug logging helper
    function debugLog(message, data) {
        if (mrafBulkData.debugEnabled) {
            console.log('[MRAF Bulk] ' + message, data !== undefined ? data : '');
        }
    }

    $(document).ready(function () {
        debugLog('Script initialized');
        debugLog('mrafBulkData:', mrafBulkData);
        debugLog('AJAX URL:', mrafBulkData.ajaxUrl);
        debugLog('Nonce:', mrafBulkData.nonce ? 'Present' : 'Missing');

        initTabs();
        initManualTab();
        initSearchTab();
        initDebugTab();
    });

    // Tab switching
    function initTabs() {
        $('.mraf-tab-btn').on('click', function () {
            const tab = $(this).data('tab');
            debugLog('Tab switched to:', tab);

            $('.mraf-tab-btn').removeClass('active');
            $(this).addClass('active');

            $('.mraf-tab-content').removeClass('active');
            $('.mraf-tab-content[data-tab="' + tab + '"]').addClass('active');
        });

        // Update manual tab count badge
        updateManualTabCount();
    }

    function updateManualTabCount() {
        const count = $('#mraf-replace-list .mraf-replace-row').length;
        const manualTab = $('.mraf-tab-btn[data-tab="manual"]');

        // Remove existing badge if any
        manualTab.find('.mraf-tab-badge').remove();

        // Add badge if count > 0
        if (count > 0) {
            manualTab.append(`<span class="mraf-tab-badge">${count}</span>`);
        }
    }

    // Debug Tab
    function initDebugTab() {
        $('#mraf-refresh-log').on('click', function () {
            debugLog('Refreshing debug log');
            refreshDebugLog();
        });

        $('#mraf-clear-log').on('click', function () {
            debugLog('Clearing debug log');
            clearDebugLog();
        });
    }

    function refreshDebugLog() {
        $.post(mrafBulkData.ajaxUrl, {
            action: 'mraf_get_bulk_debug_log',
            nonce: mrafBulkData.nonce
        }, function (response) {
            if (response.success) {
                $('#mraf-debug-log').text(response.data.log);
            }
        });
    }

    function clearDebugLog() {
        $.post(mrafBulkData.ajaxUrl, {
            action: 'mraf_get_bulk_debug_log',
            nonce: mrafBulkData.nonce,
            clear: 1
        }, function (response) {
            if (response.success) {
                $('#mraf-debug-log').text(response.data.log);
                debugLog('Debug log cleared');
            }
        });
    }

    // Manual Selection Tab
    function initManualTab() {
        $('#mraf-add-row').on('click', function () {
            debugLog('Adding new row');
            addReplaceRow();
        });

        $('#mraf-clear-all-rows').on('click', function () {
            if (!confirm('Are you sure you want to remove all rows? This action cannot be undone.')) {
                return;
            }
            debugLog('Clearing all rows');
            clearAllRows();
        });

        $('#mraf-replace-all').on('click', function () {
            if (!confirm(mrafBulkData.confirmReplaceAll)) {
                return;
            }
            replaceAllMatched();
        });

        // Don't add any rows by default - let user add them manually
    }

    function clearAllRows() {
        $('#mraf-replace-list .mraf-replace-row').fadeOut(300, function () {
            $(this).remove();
            updateReplaceAllButton();
            updateManualTabCount();
            updateClearAllButton();
            showToast('All rows cleared', 'info');
        });
    }

    function updateClearAllButton() {
        const rowCount = $('#mraf-replace-list .mraf-replace-row').length;
        if (rowCount > 0) {
            $('#mraf-clear-all-rows').show();
        } else {
            $('#mraf-clear-all-rows').hide();
        }
    }

    function addReplaceRow(attachmentData = null, newFileData = null) {
        rowCount++;
        const rowId = 'mraf-row-' + rowCount;
        debugLog('Creating row:', rowId);

        const row = $(`
            <div class="mraf-replace-row" id="${rowId}" data-row-id="${rowCount}" data-attachment-id="${attachmentData ? attachmentData.id : ''}">
                <div class="mraf-row-badge">#${rowCount}</div>

                <div class="mraf-row-content">
                    <div class="mraf-column mraf-column-current">
                        <div class="mraf-column-header">
                            <h4>Current Image</h4>
                        </div>
                        <div class="mraf-preview-container" data-section="current">
                            <div class="mraf-empty-state">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                    <circle cx="8.5" cy="8.5" r="1.5"/>
                                    <polyline points="21 15 16 10 5 21"/>
                                </svg>
                                <p>Select an image from library</p>
                                <button type="button" class="mraf-btn mraf-btn-secondary mraf-select-current">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                    </svg>
                                    Browse Library
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mraf-arrow-divider">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                            <polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </div>

                    <div class="mraf-column mraf-column-new">
                        <div class="mraf-column-header">
                            <h4>New Image</h4>
                        </div>
                        <div class="mraf-preview-container" data-section="new">
                            <div class="mraf-empty-state">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                    <polyline points="17 8 12 3 7 8"/>
                                    <line x1="12" y1="3" x2="12" y2="15"/>
                                </svg>
                                <p>Upload replacement file</p>
                                <input type="file" class="mraf-file-input" accept="image/*" style="display:none;">
                                <button type="button" class="mraf-btn mraf-btn-secondary mraf-upload-new">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                        <polyline points="17 8 12 3 7 8"/>
                                        <line x1="12" y1="3" x2="12" y2="15"/>
                                    </svg>
                                    Upload File
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mraf-row-actions">
                    <button type="button" class="mraf-btn mraf-btn-primary mraf-replace-btn" disabled>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 4 23 10 17 10"/>
                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                        </svg>
                        Replace
                    </button>
                    <button type="button" class="mraf-btn mraf-btn-ghost mraf-remove-row">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                    </button>
                </div>

                <div class="mraf-row-status"></div>
            </div>
        `);

        $('#mraf-replace-list').append(row);

        // Initialize row events
        initRowEvents(row, attachmentData, newFileData);

        updateReplaceAllButton();
        updateManualTabCount();
        updateClearAllButton();

        return row;
    }

    function initRowEvents(row, attachmentData, newFileData) {
        const rowId = row.attr('id');
        debugLog('Initializing events for:', rowId);

        // Select current image from library
        row.find('.mraf-select-current').on('click', function () {
            debugLog('Opening media library for:', rowId);
            openMediaLibrary(row, 'current');
        });

        // Upload new image - using event delegation to handle dynamically added buttons
        row.on('click', '.mraf-upload-new', function () {
            debugLog('Triggering file input for:', rowId);
            row.find('.mraf-file-input').click();
        });

        row.on('click', '.mraf-change-new', function () {
            debugLog('Change file button clicked for:', rowId);
            row.find('.mraf-file-input').click();
        });

        row.on('change', '.mraf-file-input', function (e) {
            const file = this.files[0];
            if (file) {
                debugLog('File selected:', file.name, 'Size:', file.size);
                uploadSingleFile(row, file);
                // Reset input so the same file can be selected again if needed
                $(this).val('');
            }
        });

        // Replace button
        row.find('.mraf-replace-btn').on('click', function () {
            if (!confirm(mrafBulkData.confirmReplace)) {
                return;
            }
            debugLog('Replace button clicked for:', rowId);
            replaceSingleRow(row);
        });

        // Remove row
        row.find('.mraf-remove-row').on('click', function () {
            debugLog('Removing row:', rowId);
            row.fadeOut(300, function () {
                $(this).remove();
                updateReplaceAllButton();
                updateManualTabCount();
                updateClearAllButton();
            });
        });

        // Set initial data if provided
        if (attachmentData) {
            debugLog('Setting initial attachment data for:', rowId);
            setCurrentImage(row, attachmentData);
        }
        if (newFileData) {
            debugLog('Setting initial file data for:', rowId);
            setNewFilePreview(row, newFileData);
        }
    }

    function openMediaLibrary(row, section) {
        const frame = wp.media({
            title: section === 'current' ? 'Select Current Image' : 'Select Replacement Image',
            button: {
                text: 'Select'
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });

        frame.on('select', function () {
            const attachment = frame.state().get('selection').first().toJSON();
            debugLog('Media library selection:', attachment);

            if (section === 'current') {
                setCurrentImage(row, attachment);
            } else {
                // Not used in current design, but kept for flexibility
                setNewFilePreview(row, attachment);
            }

            checkRowComplete(row);
        });

        frame.open();
    }

    function setCurrentImage(row, attachment) {
        row.data('attachment-id', attachment.id);
        debugLog('Set attachment ID:', attachment.id, 'for row:', row.attr('id'));

        // Determine preview URL with multiple fallbacks
        let previewUrl = attachment.url; // Default to full URL

        if (attachment.sizes) {
            if (attachment.sizes.medium && attachment.sizes.medium.url) {
                previewUrl = attachment.sizes.medium.url;
            } else if (attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
                previewUrl = attachment.sizes.thumbnail.url;
            } else if (attachment.sizes.large && attachment.sizes.large.url) {
                previewUrl = attachment.sizes.large.url;
            }
        }

        const preview = row.find('[data-section="current"]');
        preview.html(`
            <div class="mraf-image-card">
                <div class="mraf-image-wrapper">
                    <img src="${previewUrl}" alt="${escapeHtml(attachment.title || attachment.filename)}" loading="lazy">
                </div>
                <div class="mraf-image-details">
                    <div class="mraf-detail-row">
                        <span class="mraf-detail-label">File:</span>
                        <span class="mraf-detail-value">${escapeHtml(attachment.filename || attachment.title || 'Unknown')}</span>
                    </div>
                    <div class="mraf-detail-row">
                        <span class="mraf-detail-label">Size:</span>
                        <span class="mraf-detail-value">${attachment.width || '?'} × ${attachment.height || '?'}</span>
                    </div>
                    <div class="mraf-detail-row">
                        <span class="mraf-detail-label">Type:</span>
                        <span class="mraf-detail-value">${(attachment.subtype || attachment.mime || 'image').toUpperCase()}</span>
                    </div>
                </div>
                <button type="button" class="mraf-btn mraf-btn-secondary mraf-change-current" style="margin-top: 8px; width: 100%;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="23 4 23 10 17 10"/>
                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                    </svg>
                    Change Image
                </button>
            </div>
        `);

        // Re-attach event for "Change Image" button
        preview.find('.mraf-change-current').on('click', function() {
            debugLog('Changing current image for row:', row.attr('id'));
            openMediaLibrary(row, 'current');
        });

        checkRowComplete(row);
    }

    function uploadSingleFile(row, file) {
        const formData = new FormData();
        formData.append('action', 'mraf_bulk_upload_files');
        formData.append('nonce', mrafBulkData.nonce);
        formData.append('files', file);

        debugLog('Starting upload for:', file.name);
        debugLog('Upload data:', {
            action: 'mraf_bulk_upload_files',
            nonce: mrafBulkData.nonce ? 'Present' : 'Missing',
            fileSize: file.size
        });

        // Show uploading state
        row.find('.mraf-upload-new').prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Uploading...');

        $.ajax({
            url: mrafBulkData.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                debugLog('Upload response:', response);

                if (response.success && response.data.files.length > 0) {
                    const fileData = response.data.files[0];
                    debugLog('File uploaded successfully:', fileData.file);

                    row.data('new-file-path', fileData.file);

                    setNewFilePreview(row, fileData);
                    checkRowComplete(row);

                    row.find('.mraf-upload-new').prop('disabled', false).html(`
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        Upload File
                    `);

                    // Auto-refresh debug log
                    refreshDebugLog();
                } else {
                    debugLog('Upload failed:', response);
                    alert(mrafBulkData.uploadError + '\n\nCheck the Debug Log tab for details.');
                    row.find('.mraf-upload-new').prop('disabled', false).html(`
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        Upload File
                    `);
                    refreshDebugLog();
                }
            },
            error: function (xhr, status, error) {
                debugLog('Upload AJAX error:', {xhr: xhr, status: status, error: error});
                alert(mrafBulkData.uploadError + '\n\nError: ' + error + '\n\nCheck the Debug Log tab for details.');
                row.find('.mraf-upload-new').prop('disabled', false).html(`
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    Upload File
                `);
                refreshDebugLog();
            }
        });
    }

    function setNewFilePreview(row, fileData) {
        const preview = row.find('[data-section="new"]');
        const fileType = (fileData.type || '').split('/')[1]?.toUpperCase() || 'IMAGE';

        debugLog('Setting new file preview:', fileData.name);

        // Keep the file input in the DOM (just hidden)
        const existingFileInput = row.find('.mraf-file-input');
        let fileInputHtml = '<input type="file" class="mraf-file-input" accept="image/*" style="display:none;">';
        if (existingFileInput.length > 0) {
            // Preserve the existing file input
            fileInputHtml = existingFileInput.prop('outerHTML');
        }

        preview.html(`
            <div class="mraf-image-card">
                <div class="mraf-image-wrapper">
                    <img src="${fileData.url}" alt="${escapeHtml(fileData.name)}" loading="lazy">
                </div>
                <div class="mraf-image-details">
                    <div class="mraf-detail-row">
                        <span class="mraf-detail-label">File:</span>
                        <span class="mraf-detail-value">${escapeHtml(fileData.name)}</span>
                    </div>
                    <div class="mraf-detail-row">
                        <span class="mraf-detail-label">Size:</span>
                        <span class="mraf-detail-value">${fileData.dimensions || 'Loading...'}</span>
                    </div>
                    <div class="mraf-detail-row">
                        <span class="mraf-detail-label">Type:</span>
                        <span class="mraf-detail-value">${fileType}</span>
                    </div>
                </div>
                ${fileInputHtml}
                <button type="button" class="mraf-btn mraf-btn-secondary mraf-change-new" style="margin-top: 8px; width: 100%;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    Change File
                </button>
            </div>
        `);

        // Event handler is already attached via delegation in initRowEvents, so no need to re-attach
        debugLog('File preview updated, file input preserved');
    }

    function checkRowComplete(row) {
        const hasAttachment = row.data('attachment-id');
        const hasNewFile = row.data('new-file-path');

        debugLog('Checking row completion:', {
            rowId: row.attr('id'),
            hasAttachment: !!hasAttachment,
            hasNewFile: !!hasNewFile
        });

        if (hasAttachment && hasNewFile) {
            row.find('.mraf-replace-btn').prop('disabled', false);
            row.addClass('mraf-row-ready');
            debugLog('Row is ready:', row.attr('id'));
        } else {
            row.find('.mraf-replace-btn').prop('disabled', true);
            row.removeClass('mraf-row-ready');
        }

        updateReplaceAllButton();
    }

    function replaceSingleRow(row) {
        const attachmentId = row.data('attachment-id');
        const newFilePath = row.data('new-file-path');

        debugLog('Starting replacement:', {
            attachmentId: attachmentId,
            newFilePath: newFilePath
        });

        if (!attachmentId || !newFilePath) {
            debugLog('ERROR: Missing data', {attachmentId: attachmentId, newFilePath: newFilePath});
            alert('Missing attachment ID or file path. Please refresh and try again.');
            return;
        }

        const data = {
            action: 'mraf_bulk_replace_single',
            nonce: mrafBulkData.nonce,
            attachment_id: attachmentId,
            new_file_path: newFilePath,
            keep_basename: $('#mraf-global-keep-basename').is(':checked') ? 1 : 0,
            update_refs: $('#mraf-global-update-refs').is(':checked') ? 1 : 0,
            create_backup: $('#mraf-global-create-backup').is(':checked') ? 1 : 0
        };

        debugLog('Replace request data:', data);

        // Show processing state
        row.addClass('mraf-row-processing');
        row.find('.mraf-replace-btn').prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Processing...');
        row.find('.mraf-row-status').html('<span class="mraf-status-processing">Processing...</span>');

        $.post(mrafBulkData.ajaxUrl, data, function (response) {
            debugLog('Replace response:', response);

            row.removeClass('mraf-row-processing');

            if (response.success) {
                row.addClass('mraf-row-success');
                row.find('.mraf-row-status').html('<span class="mraf-status-success"><span class="dashicons dashicons-yes"></span> Replaced successfully!</span>');
                row.find('.mraf-replace-btn').remove();
                row.find('.mraf-upload-new').remove();

                debugLog('Replacement successful for attachment:', attachmentId);
                debugLog('New image URL:', response.data.new_url);

                if ($('.mraf-tab-content[data-tab="search"]').hasClass('active')) {
                    debugLog('Refreshing search results to show updated images');
                    const currentPage = parseInt($('#mraf-search-results').data('current-page') || 1);
                    setTimeout(function() {
                        }, 1000);
                }

                // Set the "New Image" column to show the REPLACED image (with cache-busting timestamp)
                const preview = row.find('[data-section="new"]');
                preview.html(`
                    <div class="mraf-image-card">
                        <div class="mraf-image-wrapper">
                            <img src="${response.data.new_url}?t=${Date.now()}" alt="${escapeHtml(response.data.new_title)}" loading="lazy">
                        </div>
                        <div class="mraf-image-details">
                            <div class="mraf-detail-row">
                                <span class="mraf-detail-label">File:</span>
                                <span class="mraf-detail-value">${escapeHtml(response.data.new_title)}</span>
                            </div>
                            <div class="mraf-detail-row">
                                <span class="mraf-detail-label">Status:</span>
                                <span class="mraf-detail-value" style="color: #00a32a;">✓ REPLACED</span>
                            </div>
                            <div class="mraf-detail-row">
                                <span class="mraf-detail-label">URL:</span>
                                <span class="mraf-detail-value" style="font-size: 10px; word-break: break-all;">${escapeHtml(response.data.new_url)}</span>
                            </div>
                        </div>
                    </div>
                `);

                // Auto-refresh debug log
                refreshDebugLog();
            } else {
                row.addClass('mraf-row-error');
                const errorMsg = response.data && response.data.message ? response.data.message : mrafBulkData.replaceError;
                row.find('.mraf-row-status').html('<span class="mraf-status-error"><span class="dashicons dashicons-no"></span> ' + escapeHtml(errorMsg) + '</span>');
                row.find('.mraf-replace-btn').prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Retry');

                debugLog('Replacement failed:', errorMsg);
                alert('Replacement failed: ' + errorMsg + '\n\nCheck the Debug Log tab for details.');

                // Auto-refresh debug log
                refreshDebugLog();
            }
        }).fail(function (xhr, status, error) {
            debugLog('Replace AJAX error:', {xhr: xhr, status: status, error: error});

            row.removeClass('mraf-row-processing');
            row.addClass('mraf-row-error');
            row.find('.mraf-row-status').html('<span class="mraf-status-error"><span class="dashicons dashicons-no"></span> ' + mrafBulkData.replaceError + '</span>');
            row.find('.mraf-replace-btn').prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Retry');

            alert('AJAX Error: ' + error + '\n\nCheck the Debug Log tab for details.');

            // Auto-refresh debug log
            refreshDebugLog();
        });
    }

    function replaceAllMatched() {
        const readyRows = $('.mraf-replace-row.mraf-row-ready');

        if (readyRows.length === 0) {
            alert('No rows ready to replace.');
            return;
        }

        debugLog('Starting bulk replacement for', readyRows.length, 'rows');

        let completed = 0;
        const total = readyRows.length;

        // Show progress modal
        showProgressModal(total);

        readyRows.each(function (index) {
            const row = $(this);

            setTimeout(function () {
                debugLog('Processing row', (index + 1), 'of', total);
                replaceSingleRow(row);

                completed++;
                updateProgressModal(completed, total);

                if (completed === total) {
                    setTimeout(function () {
                        hideProgressModal();
                        refreshDebugLog();
                    }, 2000);
                }
            }, index * 500); // Stagger requests by 500ms
        });
    }

    function updateReplaceAllButton() {
        const readyCount = $('.mraf-replace-row.mraf-row-ready').length;

        if (readyCount > 0) {
            $('#mraf-replace-all').show().text(`Replace All Matched (${readyCount})`);
        } else {
            $('#mraf-replace-all').hide();
        }
    }

    // Search Tab
    function initSearchTab() {
        $('#mraf-search-btn').on('click', function () {
            performSearch(1, false);
        });

        $('#mraf-search-query').on('keypress', function (e) {
            if (e.which === 13) {
                performSearch(1, false);
            }
        });
    }

    function performSearch(page = 1, append = false) {
        const query = $('#mraf-search-query').val();
        const type = $('#mraf-search-type').val();
        const date = $('#mraf-search-date').val();

        debugLog('Performing search:', {query: query, type: type, date: date, page: page});

        const data = {
            action: 'mraf_bulk_search_images',
            nonce: mrafBulkData.nonce,
            query: query,
            type: type,
            date: date,
            page: page
        };

        const resultsContainer = $('#mraf-search-results');

        if (!append) {
            resultsContainer.html('<p>Searching...</p>');
        } else {
            $('#mraf-load-more-btn').prop('disabled', true).text('Loading...');
        }

        $.post(mrafBulkData.ajaxUrl, data, function (response) {
            debugLog('Search response:', response);

            if (response.success) {
                displaySearchResults(response.data.images, append, response.data);
            } else {
                resultsContainer.html('<p>Search failed. Please try again.</p>');
            }
        }).fail(function () {
            resultsContainer.html('<p>Search failed. Please try again.</p>');
        });
    }

    function displaySearchResults(images, append, data) {
        const resultsContainer = $('#mraf-search-results');

        if (images.length === 0 && !append) {
            resultsContainer.html('<p>No images found.</p>');
            return;
        }

        debugLog('Displaying', images.length, 'search results');

        if (!append) {
            resultsContainer.empty();
        } else {
            $('#mraf-load-more-btn').remove();
        }

        let grid = resultsContainer.find('.mraf-search-grid');
        if (!grid.length) {
            grid = $('<div class="mraf-search-grid"></div>');
            resultsContainer.append(grid);
        }

        images.forEach(function (image) {
            const thumbUrl = image.thumb + (image.thumb.indexOf('?') > -1 ? '&' : '?') + 't=' + Date.now();
            const isReplaced = !!image.replaced_at;
            const isInList = $('.mraf-replace-row[data-attachment-id="' + image.id + '"]').length > 0;

            const item = $(`
                <div class="mraf-search-item ${isReplaced ? 'mraf-item-replaced' : ''} ${isInList ? 'mraf-item-in-list' : ''}" data-id="${image.id}">
                    <div class="mraf-search-thumb">
                        <img src="${thumbUrl}" alt="${escapeHtml(image.title)}" loading="lazy">
                        ${isReplaced ? '<span class="mraf-badge mraf-badge-replaced">✓ Replaced</span>' : ''}
                        ${isInList && !isReplaced ? '<span class="mraf-badge mraf-badge-in-list">In List</span>' : ''}
                    </div>
                    <div class="mraf-search-info">
                            <strong style="display:block; white-space: normal;" title="${escapeHtml(image.filename)}">${escapeHtml(image.filename)}</strong>
                            <span style="font-style: italic; color: #666; display:block;">Title: ${escapeHtml(image.title || '(none)')}</span>
                            <span style="font-style: italic; color: #666; display:block;">Alt: ${escapeHtml(image.alt_text || '(none)')}</span>
                            <span>${image.dimensions} x ${image.mime_type.split('/') [1].toUpperCase()}</span>
                        </div>
                    <button type="button" class="mraf-btn mraf-btn-primary mraf-add-to-list" ${isInList && !isReplaced ? 'disabled' : ''}>
                        ${isReplaced ? 'Replace Again' : isInList ? 'In List' : 'Add to List'}
                    </button>
                </div>
            `);

            item.find('.mraf-add-to-list').on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const button = $(this);
                debugLog('Adding image to list:', image.id);
                button.text('Added').prop('disabled', true);
                const newRow = addReplaceRow(image);
                debugLog('Row added, image will remain on search tab');

                showToast('Image added to Manual Selection tab!', 'success');

                item.addClass('mraf-item-in-list');
                item.find('.mraf-search-thumb .mraf-badge-in-list').remove();
                item.find('.mraf-search-thumb').append('<span class="mraf-badge mraf-badge-in-list">In List</span>');
                button.text('In List').prop('disabled', true);

                const manualTabBadge = $('.mraf-tab-btn[data-tab="manual"] .mraf-tab-badge');
                if (manualTabBadge.length) {
                    manualTabBadge.css({'background': '#00a32a', 'transform': 'scale(1.3)', 'transition': 'all 0.3s ease'});
                    setTimeout(function() {
                        manualTabBadge.css({'background': '', 'transform': '', 'transition': 'all 0.3s ease'});
                    }, 1000);
                }
            });

            grid.append(item);
        });

        if (data && data.has_more) {
            const loadMoreBtn = $(`
                <div style="text-align: center; padding: 20px;">
                    <button type="button" class="mraf-btn mraf-btn-primary" id="mraf-load-more-btn" style="padding: 0 24px;">
                        Load More (${data.total - (data.page * data.per_page)} remaining)
                    </button>
                </div>
            `);
            loadMoreBtn.find('#mraf-load-more-btn').on('click', function() {
                performSearch(data.page + 1, true);
            });
            resultsContainer.append(loadMoreBtn);
        }
    }

    // Progress Modal
    function showProgressModal(total) {
        const modal = $('#mraf-progress-modal');
        modal.find('#mraf-progress-text').text(`0 / ${total}`);
        modal.find('#mraf-progress-fill').css('width', '0%');
        modal.find('#mraf-progress-log').empty();
        modal.fadeIn();
    }

    function updateProgressModal(completed, total) {
        const percent = (completed / total) * 100;
        $('#mraf-progress-fill').css('width', percent + '%');
        $('#mraf-progress-text').text(`${completed} / ${total}`);
    }

    function hideProgressModal() {
        $('#mraf-progress-modal').fadeOut();
    }

    // Utilities
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function (m) {
            return map[m];
        });
    }

    // Toast notification
    function showToast(message, type = 'info') {
        // Remove existing toasts
        $('.mraf-toast').remove();

        const colors = {
            success: '#00a32a',
            error: '#dc3232',
            info: '#2271b1',
            warning: '#f0b849'
        };

        const icons = {
            success: '✓',
            error: '✕',
            info: 'ℹ',
            warning: '⚠'
        };

        const toast = $(`
            <div class="mraf-toast" style="
                position: fixed;
                bottom: 30px;
                right: 30px;
                background: ${colors[type] || colors.info};
                color: white;
                padding: 16px 24px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 100001;
                display: flex;
                align-items: center;
                gap: 12px;
                font-size: 14px;
                font-weight: 500;
                animation: mraf-slide-in 0.3s ease;
            ">
                <span style="font-size: 18px; font-weight: bold;">${icons[type] || icons.info}</span>
                <span>${escapeHtml(message)}</span>
            </div>
        `);

        // Add CSS animation
        if (!$('#mraf-toast-styles').length) {
            $('head').append(`
                <style id="mraf-toast-styles">
                    @keyframes mraf-slide-in {
                        from {
                            transform: translateX(400px);
                            opacity: 0;
                        }
                        to {
                            transform: translateX(0);
                            opacity: 1;
                        }
                    }
                    @keyframes mraf-slide-out {
                        from {
                            transform: translateX(0);
                            opacity: 1;
                        }
                        to {
                            transform: translateX(400px);
                            opacity: 0;
                        }
                    }
                </style>
            `);
        }

        $('body').append(toast);

        // Auto-remove after 3 seconds
        setTimeout(function() {
            toast.css('animation', 'mraf-slide-out 0.3s ease');
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, 3000);
    }

})(jQuery);

