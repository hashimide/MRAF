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

    function replaceSingleRow(row, callback) {
        const attachmentId = row.data('attachment-id');
        const newFilePath = row.data('new-file-path');

        debugLog('Starting replacement:', {
            attachmentId: attachmentId,
            newFilePath: newFilePath
        });

        if (!attachmentId || !newFilePath) {
            debugLog('ERROR: Missing data', {attachmentId: attachmentId, newFilePath: newFilePath});
            alert('Missing attachment ID or file path. Please refresh and try again.');
            if (callback) callback(false);
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
        row.find('.mraf-row-status').empty();

        $.post(mrafBulkData.ajaxUrl, data, function (response) {
            debugLog('Replace response:', response);

            row.removeClass('mraf-row-processing');

            if (response.success) {
                row.addClass('mraf-row-success');

                // Replace button with success message
                row.find('.mraf-replace-btn').html(`
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Replaced successfully!
                `).prop('disabled', true).css({
                    'background': 'hsl(142.1 76.2% 36.3%)',
                    'color': 'white',
                    'cursor': 'not-allowed'
                });

                row.find('.mraf-upload-new').remove();

                debugLog('Replacement successful for attachment:', attachmentId);
                debugLog('New image URL:', response.data.new_url);

                if ($('.mraf-tab-content[data-tab="search"]').hasClass('active')) {
                    debugLog('Refreshing search results to show updated images');
                    const currentPage = parseInt($('#mraf-search-results').data('current-page') || 1);
                    setTimeout(function() {
                        }, 1000);
                }

                // Set the "New Image" column to show the REPLACED image with compact layout
                const preview = row.find('[data-section="new"]');
                preview.html(`
                    <div class="mraf-image-card mraf-image-card-compact">
                        <div class="mraf-image-wrapper-compact">
                            <img src="${response.data.new_url}?t=${Date.now()}" alt="${escapeHtml(response.data.new_title)}" loading="lazy">
                        </div>
                        <div class="mraf-image-details">
                            <div class="mraf-detail-row">
                                <span class="mraf-detail-label">Status:</span>
                                <span class="mraf-detail-value" style="color: #00a32a; font-weight: 600;">✓ REPLACED</span>
                            </div>
                            <div class="mraf-detail-row">
                                <span class="mraf-detail-label">File:</span>
                                <span class="mraf-detail-value">${escapeHtml(response.data.new_title)}</span>
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

                // Call callback with success
                if (callback) callback(true);
            } else {
                row.addClass('mraf-row-error');
                const errorMsg = response.data && response.data.message ? response.data.message : mrafBulkData.replaceError;
                row.find('.mraf-row-status').html('<span class="mraf-status-error"><span class="dashicons dashicons-no"></span> ' + escapeHtml(errorMsg) + '</span>');
                row.find('.mraf-replace-btn').prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Retry');

                debugLog('Replacement failed:', errorMsg);
                alert('Replacement failed: ' + errorMsg + '\n\nCheck the Debug Log tab for details.');

                // Auto-refresh debug log
                refreshDebugLog();

                // Call callback with failure
                if (callback) callback(false);
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

            // Call callback with failure
            if (callback) callback(false);
        });
    }

    function replaceAllMatched() {
        const readyRows = $('.mraf-replace-row.mraf-row-ready').toArray();

        if (readyRows.length === 0) {
            alert('No rows ready to replace.');
            return;
        }

        debugLog('Starting bulk replacement for', readyRows.length, 'rows');

        let completed = 0;
        const total = readyRows.length;
        const queue = readyRows.slice(); // Create a copy of the array

        // Show progress section
        showProgressModal(total);

        // Process rows sequentially
        function processNext() {
            if (queue.length === 0) {
                // All done
                debugLog('Bulk replacement completed:', completed, 'of', total);
                hideProgressModal();
                refreshDebugLog();
                return;
            }

            const row = $(queue.shift());
            debugLog('Processing row', (completed + 1), 'of', total);

            // Process this row and wait for completion
            replaceSingleRow(row, function(success) {
                completed++;
                updateProgressModal(completed, total);

                // Process next row immediately after this one completes
                processNext();
            });
        }

        // Start processing the first row
        processNext();
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

        // Advanced filters
        $('#mraf-filter-unreplaced').on('change', function() {
            performSearch(1, false);
        });

        $('#mraf-search-type').on('change', function() {
            performSearch(1, false);
        });

        $('#mraf-search-date').on('change', function() {
            performSearch(1, false);
        });

        // Select all visible images
        $('#mraf-select-all-visible').on('click', function() {
            selectAllVisibleImages();
        });
    }

    function performSearch(page = 1, append = false) {
        const query = $('#mraf-search-query').val();
        const type = $('#mraf-search-type').val();
        const date = $('#mraf-search-date').val();
        const unreplacedOnly = $('#mraf-filter-unreplaced').is(':checked');

        debugLog('Performing search:', {query: query, type: type, date: date, unreplacedOnly: unreplacedOnly, page: page});

        const data = {
            action: 'mraf_bulk_search_images',
            nonce: mrafBulkData.nonce,
            query: query,
            type: type,
            date: date,
            unreplaced_only: unreplacedOnly ? 1 : 0,
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
            $('#mraf-load-more-btn').parent().remove();
        }

        // Use shared gallery rendering
        MRAF.Gallery.renderSearchGrid(images, resultsContainer, {
            badgeType: 'replaced',
            buttonClass: 'mraf-add-to-list',
            buttonText: {
                add: 'Add to List',
                added: 'In List',
                processed: 'Replace Again'
            },
            checkIfInList: function(imageId) {
                return $('.mraf-replace-row[data-attachment-id="' + imageId + '"]').length > 0;
            },
            checkIfProcessed: function(image) {
                return !!image.replaced_at;
            },
            onButtonClick: function(image, $item, $btn) {
                debugLog('Adding image to list:', image.id);

                // Add the row
                const newRow = addReplaceRow(image);
                debugLog('Row added, image will remain on search tab');

                // Update item state using shared function
                MRAF.Gallery.updateItemState($item, $btn, {
                    buttonText: 'In List',
                    removeClass: 'mraf-item-replaced',
                    removeBadge: '.mraf-badge-replaced'
                });

                MRAF.Utils.showToast('Image added to Manual Selection tab!', 'success');

                // Highlight the manual tab badge
                const manualTabBadge = $('.mraf-tab-btn[data-tab="manual"] .mraf-tab-badge');
                MRAF.Gallery.highlightTabBadge(manualTabBadge);
            }
        });

        // Use shared load more rendering
        MRAF.Gallery.renderLoadMore(data, resultsContainer, function(page) {
            performSearch(page, true);
        });
    }

    // Progress Section (inline like convert & replace)
    function showProgressModal(total) {
        const $progressSection = $('#mraf-progress-section');
        $progressSection.show();
        updateProgressModal(0, total);
    }

    function updateProgressModal(completed, total) {
        const percentage = total > 0 ? Math.round((completed / total) * 100) : 0;
        $('#mraf-progress-fill').css('width', percentage + '%').text(percentage + '%');
        $('#mraf-progress-text').text('Processing: ' + completed + ' / ' + total + ' (' + percentage + '%)');
    }

    function hideProgressModal() {
        setTimeout(function() {
            $('#mraf-progress-section').fadeOut();
        }, 1500);
    }

    // Select all visible images
    function selectAllVisibleImages() {
        let addedCount = 0;
        const resultsContainer = $('#mraf-search-results');

        // Find all "Add to List" buttons that are not disabled
        resultsContainer.find('.mraf-add-to-list:not(:disabled)').each(function() {
            const $btn = $(this);
            const $item = $btn.closest('.mraf-search-item');
            const image = $item.data('image-data');

            if (!image) return;

            const imageId = image.id;
            const isInList = $('.mraf-replace-row[data-attachment-id="' + imageId + '"]').length > 0;
            const wasReplaced = !!image.replaced_at;

            if (isInList && !wasReplaced) {
                return; // Skip if already in list
            }

            // Remove replaced badge if applicable
            if (wasReplaced) {
                $item.removeClass('mraf-item-replaced');
                $item.find('.mraf-badge-replaced').remove();
            }

            // Add the row
            debugLog('Adding image to list (bulk):', imageId);
            const newRow = addReplaceRow(image);

            // Update button and item state using shared function
            MRAF.Gallery.updateItemState($item, $btn, {
                buttonText: 'In List',
                removeClass: 'mraf-item-replaced',
                removeBadge: '.mraf-badge-replaced'
            });

            addedCount++;
        });

        // Update UI once at the end
        if (addedCount > 0) {
            updateManualTabCount();

            // Highlight the manual tab badge using shared function
            const manualTabBadge = $('.mraf-tab-btn[data-tab="manual"] .mraf-tab-badge');
            MRAF.Gallery.highlightTabBadge(manualTabBadge);

            // Show single notification
            showToast('Added ' + addedCount + ' image(s) to Manual Selection tab!', 'success');
        } else {
            showToast('No new images to add. They may already be in the list.', 'info');
        }
    }

    // Utilities - Use shared functions
    function escapeHtml(text) {
        return MRAF.Utils.escapeHtml(text);
    }

    // Toast notification - Use shared function
    function showToast(message, type) {
        MRAF.Utils.showToast(message, type);
    }

})(jQuery);

