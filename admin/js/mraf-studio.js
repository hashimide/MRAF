/**
 * MRAF Media Studio
 *
 * Runs on two screens:
 *   upload.php            the library, which is the studio
 *   the Media Studio page backups, settings and the debug log
 */
(function ($) {
    'use strict';

    var D = window.mrafStudioData || {};
    var S = {
        task: 'convert',
        items: [],
        pairs: [],
        pool: [],
        slot: null,
        queue: [],
        index: 0,
        stopped: false,
        running: false
    };

    /* Helpers */

    function post(action, data) {
        return $.post(D.ajaxUrl, $.extend({ action: action, nonce: D.nonce }, data || {}));
    }

    function str(key, fallback) {
        return (D.strings && D.strings[key]) || fallback || '';
    }

    function esc(value) {
        return $('<div>').text(value == null ? '' : value).html();
    }

    function baseName(name) {
        return String(name).replace(/\.[^.]+$/, '').toLowerCase();
    }

    function byId(id) {
        for (var i = 0; i < S.items.length; i++) {
            if (S.items[i].id === id) {
                return S.items[i];
            }
        }
        return null;
    }

    /**
     * Freeze the page behind the modal without moving it.
     *
     * Hiding the body scrollbar hands its width back to the viewport, and the
     * gallery is an auto-fill grid, so it answers by fitting one more column.
     * Every tile shifts, which reads as the pictures changing when the modal
     * opens and changing back when it closes. Padding by exactly the width
     * the scrollbar gave up keeps the layout still.
     *
     * @param {boolean} on Whether the modal is open.
     */
    function lockScroll(on) {
        var $body = $('body');

        if (!on) {
            $body.removeClass('mraf-modal-open').css('padding-right', $body.data('mraf-pad') || '');
            return;
        }

        if ($body.hasClass('mraf-modal-open')) {
            return;
        }

        var gap = window.innerWidth - document.documentElement.clientWidth;

        $body.data('mraf-pad', $body[0].style.paddingRight || '');

        if (gap > 0) {
            $body.css('padding-right', (parseFloat($body.css('padding-right')) || 0) + gap + 'px');
        }

        $body.addClass('mraf-modal-open');
    }

    function toast(message) {
        var $t = $('#mraf-toast');
        if (!$t.length) {
            return;
        }
        $t.text(message).prop('hidden', false);
        clearTimeout($t.data('timer'));
        $t.data('timer', setTimeout(function () {
            $t.prop('hidden', true);
        }, 2200));
    }

    /* ==================================================================
       Modal: open, tabs, footer
       ================================================================== */

    // The gallery already holds the same item shape the modal needs, so a tile
    // click can open straight from that cache instead of waiting on AJAX.
    function cachedItems(ids) {
        var out = [];

        for (var i = 0; i < ids.length; i++) {
            var hit = null;

            for (var j = 0; j < W.images.length; j++) {
                if (W.images[j].id === ids[i]) {
                    hit = W.images[j];
                    break;
                }
            }

            if (!hit) {
                return null;
            }

            out.push(hit);
        }

        return out;
    }

    function showModal(items, task) {
        // 'convert' is no longer a tab: it opens the modal and runs the job.
        var auto = task === 'convert';

        if (auto) {
            task = 'details';
        }

        S.items = items;
        S.pairs = items.map(function (item) {
            return { id: item.id, file: null };
        });
        S.pool = [];
        S.slot = null;

        renderHead();
        setTab(task || 'convert');

        $('#mraf-mp-run').prop('hidden', true);
        $('#mraf-modal').removeClass('is-running is-converting');
        $('#mraf-m-done').prop('hidden', true);
        $('#mraf-m-stop').prop('hidden', true);
        $('#mraf-m-go').prop('hidden', false);
        $('#mraf-m-convert').prop('hidden', false);
        $('#mraf-modal').prop('hidden', false);
        lockScroll(true);

        if (auto && !$('#mraf-m-convert').prop('disabled')) {
            $('#mraf-m-convert').trigger('click');
        }
    }

    function openModal(ids, task) {
        ids = (ids || []).map(Number).filter(Boolean);

        if (!ids.length) {
            window.alert(str('selectFirst', 'Select at least one image first.'));
            return;
        }

        $('#mraf-match-note').empty();
        S.stopped = false;
        S.running = false;

        var cached = cachedItems(ids);

        if (cached) {
            showModal(cached, task);
            return;
        }

        post('mraf_studio_get_items', { ids: ids }).done(function (res) {
            if (!res || !res.success) {
                window.alert((res && res.data && res.data.message) || 'Could not load those images.');
                return;
            }

            var items = res.data.items || [];

            if (!items.length) {
                window.alert('No images in that selection.');
                return;
            }

            showModal(items, task);
        });
    }

    function closeModal() {
        S.stopped = true;
        S.running = false;
        $('#mraf-modal').prop('hidden', true);
        lockScroll(false);
    }

    function renderHead() {
        var one = S.items.length === 1;
        var html = S.items.slice(0, 3).map(function (item) {
            return '<img src="' + esc(item.thumb) + '" alt="">';
        }).join('');

        $('#mraf-m-thumbs').html(html);

        if (one) {
            $('#mraf-m-title').text(S.items[0].filename || S.items[0].title);
            $('#mraf-m-sub').text(
                S.items[0].format + ' · ' + S.items[0].filesize + ' · ' + S.items[0].dimensions +
                ' · uploaded ' + S.items[0].uploaded
            );
        } else {
            $('#mraf-m-title').text(S.items.length + ' images selected');
            $('#mraf-m-sub').text('Bulk action');
        }
    }

    function opts() {
        return D.options || {};
    }

    function setTab(tab) {
        // Details needs a single image; a bulk selection always lands on Replace.
        if (tab !== 'replace' && S.items.length !== 1) {
            tab = 'replace';
        }

        if (tab !== 'details' && tab !== 'replace') {
            tab = 'details';
        }

        S.task = tab;

        closeConvertPanel();

        $('.mraf-modal__tabs button').each(function () {
            $(this).toggleClass('is-active', $(this).data('mtab') === tab);
        });

        $('.mraf-modal__tabs button[data-mtab="details"]').prop('hidden', S.items.length !== 1);

        $('#mraf-mp-replace').prop('hidden', tab !== 'replace');
        $('#mraf-mp-details').prop('hidden', tab !== 'details');
        $('#mraf-mp-run').prop('hidden', true);

        if (tab === 'replace') {
            renderPairs();
        }

        if (tab === 'details') {
            renderDetails();
        }

        syncConvertButton();
        syncFooter();
    }

    function baseOf(name) {
        return String(name || '').replace(/\.[^.]+$/, '');
    }

    // Convert asks for the file names first, then runs.
    function renderConvertPanel() {
        var rows = S.items.map(function (item, index) {
            var isWebp = item.mime_type === 'image/webp';

            return '<div class="mraf-crow' + (isWebp ? ' is-skipped' : '') + '">' +
                '<img class="mraf-crow__img" src="' + esc(item.thumb) + '" alt="">' +
                '<span class="mraf-crow__from" title="' + esc(item.filename) + '">' + esc(item.filename) + '</span>' +
                (isWebp
                    ? '<span class="mraf-crow__note">already WebP, skipped</span>'
                    : '<span class="mraf-crow__to">' +
                        '<input type="text" class="mraf-crow__name" data-index="' + index + '" value="' + esc(baseOf(item.filename)) + '">' +
                        '<span class="mraf-crow__ext">.webp</span>' +
                      '</span>') +
            '</div>';
        }).join('');

        var n = S.items.filter(function (item) {
            return item.mime_type !== 'image/webp';
        }).length;

        $('#mraf-c-intro').text(n === 1
            ? 'Check the file name, then convert.'
            : 'Check the file names, then convert ' + n + ' images.');

        $('#mraf-c-rows').html(rows);
    }

    function openConvertPanel() {
        S.task = 'convert';

        $('#mraf-mp-details, #mraf-mp-replace, #mraf-mp-run').prop('hidden', true);
        $('#mraf-mp-convert').prop('hidden', false);
        $('#mraf-modal').addClass('is-converting');
        $('#mraf-m-convert').prop('hidden', true);

        renderConvertPanel();
        syncFooter();
    }

    function closeConvertPanel() {
        $('#mraf-mp-convert').prop('hidden', true);
        $('#mraf-modal').removeClass('is-converting');
        $('#mraf-m-convert').prop('hidden', false);
    }

    // The head action converts whatever the modal holds, on either tab.
    function syncConvertButton() {
        var $btn = $('#mraf-m-convert');
        var n = S.items.length;
        var pending = S.items.filter(function (item) {
            return item.mime_type !== 'image/webp';
        }).length;

        $btn.text(n === 1 ? 'Convert to WebP' : 'Convert ' + n + ' to WebP');

        if (!D.webpSupport) {
            $btn.prop('disabled', true).attr('title', 'This server cannot write WebP.');
            return;
        }

        if (!pending) {
            $btn.prop('disabled', true).attr('title', n === 1 ? 'This image is already WebP.' : 'Every selected image is already WebP.');
            return;
        }

        $btn.prop('disabled', false).attr('title', 'Quality ' + (opts().quality || 85) + '. Change it in Settings.');
    }

    function syncFooter() {
        var $go = $('#mraf-m-go');
        var $foot = $('#mraf-m-foot');

        if (S.task === 'convert') {
            var pending = S.items.filter(function (item) {
                return item.mime_type !== 'image/webp';
            }).length;

            $go.prop('hidden', false).prop('disabled', pending === 0)
                .text(pending === 1 ? 'Convert this image' : 'Convert ' + pending + ' images');
            $foot.text(opts().create_backup
                ? 'A backup is written before anything changes.'
                : 'Backups are off in Settings, so originals are not kept.');
        } else if (S.task === 'replace') {
            var ready = S.pairs.filter(function (p) { return p.file; }).length;
            $go.prop('hidden', false).prop('disabled', ready === 0)
                .text(ready ? 'Replace ' + ready + (ready === 1 ? ' file' : ' files') : 'Replace');
            $foot.text(ready ? '' : str('needFile', 'Give at least one image a new file.'));
        } else {
            if (S.items.length === 1) {
                $go.prop('hidden', false).prop('disabled', false).text('Save changes');
                $foot.text('Alt text, title, caption and description are saved to the attachment.');
            } else {
                $go.prop('hidden', true);
                $foot.text('');
            }
        }
    }

    /* ==================================================================
       Replace: pairing rows
       ================================================================== */

    function renderPairs() {
        var html = S.pairs.map(function (pair, index) {
            var item = byId(pair.id);
            if (!item) {
                return '';
            }

            var right;

            if (pair.file) {
                right = '<div class="mraf-filled">' +
                    '<img src="' + esc(pair.file.preview_url) + '" alt="">' +
                    '<span><span class="mraf-pair__name">' + esc(pair.file.filename) + '</span>' +
                    '<span class="mraf-pair__meta">' + esc(pair.file.size) + '</span></span>' +
                    '<button type="button" class="mraf-linkbtn" style="margin-left:auto" data-clear="' + index + '">change</button>' +
                '</div>';
            } else if (S.slot === index && S.pool.length) {
                right = '<div class="mraf-pool">' +
                    '<span class="mraf-pair__meta">Pick one of the files you added:</span>' +
                    S.pool.map(function (file, i) {
                        return '<button type="button" class="mraf-pool__opt" data-pool="' + i + '">' +
                            esc(file.filename) + ' <span class="mraf-pair__meta">' + esc(file.size) + '</span></button>';
                    }).join('') +
                    '<button type="button" class="mraf-linkbtn" id="mraf-pool-browse">or browse for another file</button>' +
                '</div>';
            } else {
                right = '<button type="button" class="mraf-slot" data-slot="' + index + '">choose new file</button>';
            }

            return '<div class="mraf-pair">' +
                '<span class="mraf-pair__side">' +
                    '<img src="' + esc(item.thumb) + '" alt="">' +
                    '<span style="min-width:0">' +
                        '<span class="mraf-pair__name">' + esc(item.filename) + '</span>' +
                        '<span class="mraf-pair__meta">' + esc(item.format + ' · ' + item.filesize + ' · ' + item.dimensions) + '</span>' +
                    '</span>' +
                '</span>' +
                '<span class="mraf-pair__arrow">&rarr;</span>' +
                '<span>' + right + '</span>' +
            '</div>';
        }).join('');

        $('#mraf-pairs').html(html);
        syncFooter();
    }

    function uploadFile(file, index) {
        var form = new FormData();
        form.append('action', 'mraf_studio_upload_files');
        form.append('nonce', D.nonce);
        form.append('file', file);

        var $slot = $('.mraf-slot[data-slot="' + index + '"]');
        $slot.addClass('is-busy').text(str('uploading', 'Uploading…'));

        $.ajax({
            url: D.ajaxUrl,
            method: 'POST',
            data: form,
            processData: false,
            contentType: false
        }).done(function (res) {
            if (res && res.success) {
                S.pairs[index].file = {
                    filename: res.data.filename,
                    temp_name: res.data.temp_name,
                    preview_url: res.data.preview_url,
                    size: res.data.size
                };
            } else {
                window.alert((res && res.data && res.data.message) || 'Upload failed.');
            }
            renderPairs();
        }).fail(function () {
            window.alert('Upload failed.');
            renderPairs();
        });
    }

    /**
     * Upload several files at once, match by filename where the names happen
     * to line up, and park the rest in the pool for manual pairing.
     *
     * @param {Array} files Files chosen by the user.
     */
    function uploadPool(files) {
        var pending = files.length;
        var uploaded = [];

        $('#mraf-match-note').html('<div class="mraf-note">' + str('uploading', 'Uploading…') + '</div>');

        files.forEach(function (file) {
            var form = new FormData();
            form.append('action', 'mraf_studio_upload_files');
            form.append('nonce', D.nonce);
            form.append('file', file);

            $.ajax({
                url: D.ajaxUrl,
                method: 'POST',
                data: form,
                processData: false,
                contentType: false
            }).done(function (res) {
                if (res && res.success) {
                    uploaded.push({
                        filename: res.data.filename,
                        temp_name: res.data.temp_name,
                        preview_url: res.data.preview_url,
                        size: res.data.size
                    });
                }
            }).always(function () {
                pending--;

                if (pending === 0) {
                    applyPool(uploaded);
                }
            });
        });
    }

    /**
     * Assign uploaded files to rows whose filename matches, pool the rest.
     *
     * @param {Array} uploaded Files that reached the server.
     */
    function applyPool(uploaded) {
        var hit = 0;

        uploaded.forEach(function (file) {
            var target = baseName(file.filename);
            var row = null;

            for (var i = 0; i < S.pairs.length; i++) {
                var item = byId(S.pairs[i].id);

                if (!S.pairs[i].file && item && baseName(item.filename) === target) {
                    row = S.pairs[i];
                    break;
                }
            }

            if (row) {
                row.file = file;
                hit++;
            } else {
                S.pool.push(file);
            }
        });

        S.slot = null;
        renderPairs();

        var miss = S.pairs.filter(function (p) { return !p.file; }).length;

        $('#mraf-match-note').html(
            '<div class="mraf-note"><strong>' + hit + ' matched by filename, ' + miss + ' still unpaired.</strong> ' +
            'Filenames in a real library are mostly random, so matching only ever fills in the easy ones. ' +
            (S.pool.length
                ? S.pool.length + ' uploaded file' + (S.pool.length === 1 ? ' is' : 's are') + ' waiting — click an empty row to place ' + (S.pool.length === 1 ? 'it' : 'them') + '.'
                : 'Pair the rest yourself.') +
            '</div>'
        );
    }

    /* ==================================================================
       Details tab
       ================================================================== */

    function renderDetails() {
        var one = S.items.length === 1;

        $('#mraf-d-multi').prop('hidden', one);
        $('#mraf-d-single').prop('hidden', !one);

        if (!one) {
            return;
        }

        var item = S.items[0];

        $('#mraf-d-alt').val(item.alt_text);
        $('#mraf-d-title').val(item.title);
        $('#mraf-d-caption').val(item.caption);
        $('#mraf-d-desc').val(item.description);
        $('#mraf-d-url').val(item.url);

        $('#mraf-d-view').attr('href', item.url);
        $('#mraf-d-download').attr('href', item.url);
        $('#mraf-d-edit').attr('href', (D.editUrlBase || '') + item.id);

        $('#mraf-d-usage').text('Loading image usage…');

        $.post(D.ajaxUrl, {
            action: 'mraf_get_usage',
            nonce: D.usageNonce,
            attachment_id: item.id
        }).done(function (res) {
            if (res && res.success && res.data && res.data.html) {
                $('#mraf-d-usage').html(res.data.html);
            } else {
                $('#mraf-d-usage').text('Unable to load image usage.');
            }
        }).fail(function () {
            $('#mraf-d-usage').text('Unable to load image usage.');
        });
    }

    function saveDetails() {
        var item = S.items[0];

        post('mraf_studio_save_details', {
            attachment_id: item.id,
            alt: $('#mraf-d-alt').val(),
            title: $('#mraf-d-title').val(),
            caption: $('#mraf-d-caption').val(),
            description: $('#mraf-d-desc').val()
        }).done(function (res) {
            if (res && res.success) {
                item.alt_text = $('#mraf-d-alt').val();
                item.title = $('#mraf-d-title').val();
                item.caption = $('#mraf-d-caption').val();
                item.description = $('#mraf-d-desc').val();
                toast(str('saved', 'Details saved.'));
            } else {
                window.alert((res && res.data && res.data.message) || 'Could not save.');
            }
        });
    }

    /* ==================================================================
       Running a job, one image per request
       ================================================================== */

    function bytes(n) {
        n = Number(n) || 0;

        if (n >= 1048576) {
            return (n / 1048576).toFixed(1) + ' MB';
        }

        if (n >= 1024) {
            return Math.round(n / 1024) + ' KB';
        }

        return n + ' B';
    }

    // Only trouble is listed. A clean run says everything in the summary line.
    function log(name, note, cls) {
        var $log = $('#mraf-run-log');
        var mark = cls === 'bad' ? '&#10007;' : (cls === 'warn' ? '!' : '&#10003;');

        if (cls !== 'bad' && cls !== 'warn') {
            return;
        }

        $log.prop('hidden', false).append(
            '<div class="' + (cls || '') + '">' +
                '<span class="mraf-log__mark">' + mark + '</span>' +
                '<span class="mraf-log__name">' + esc(name) + '</span>' +
                (note ? '<span class="mraf-log__note">' + note + '</span>' : '') +
            '</div>'
        );

        $log.scrollTop($log[0].scrollHeight);
    }

    function startRun() {
        if (S.task === 'convert') {
            S.queue = S.items.filter(function (item) {
                return item.mime_type !== 'image/webp';
            }).map(function (item) {
                return {
                    id: item.id,
                    name: item.filename,
                    rename_to: item.rename_to == null ? baseOf(item.filename) : item.rename_to
                };
            });
        } else {
            S.queue = S.pairs.filter(function (p) { return p.file; }).map(function (p) {
                var item = byId(p.id);
                return { id: p.id, name: item ? item.filename : String(p.id), file: p.file };
            });
        }

        if (!S.queue.length) {
            return;
        }

        S.index = 0;
        S.stopped = false;
        S.running = true;
        S.done = 0;
        S.failed = 0;
        S.saved = 0;

        $('#mraf-mp-replace, #mraf-mp-details, #mraf-mp-convert').prop('hidden', true);
        $('#mraf-mp-run').prop('hidden', false);
        $('#mraf-modal').removeClass('is-converting').addClass('is-running');
        $('#mraf-m-convert').prop('hidden', true);
        $('#mraf-m-go').prop('hidden', true);
        $('#mraf-m-done').prop('hidden', true);
        $('#mraf-m-stop').prop('hidden', false);
        $('#mraf-m-foot').text('');
        $('#mraf-run-log').empty().prop('hidden', true);
        $('#mraf-run-summary').prop('hidden', true).empty();
        $('#mraf-run-bar').css('width', '0%');
        $('#mraf-run-pct').text('0%');
        $('#mraf-run-state').text(S.queue.length === 1
            ? 'Working on 1 image…'
            : 'Working on ' + S.queue.length + ' images…');

        step();
    }

    function step() {
        if (S.stopped) {
            log(str('stopped', 'Stopped. Nothing else was touched.'), '', 'warn');
            finishRun();
            return;
        }

        if (S.index >= S.queue.length) {
            finishRun();
            return;
        }

        var job = S.queue[S.index];
        var request;

        if (S.task === 'convert') {
            request = post('mraf_studio_convert_single', {
                attachment_id: job.id,
                new_name: job.rename_to || ''
            });
        } else {
            request = post('mraf_studio_replace_single', {
                attachment_id: job.id,
                new_file: job.file.temp_name
            });
        }

        request.done(function (res) {
            if (res && res.success) {
                var data = res.data || {};
                var note = '';

                S.done++;

                if (S.task === 'convert') {
                    S.saved += Number(data.bytes_saved) || 0;

                    if (data.compression_ratio) {
                        note = '&minus;' + esc(data.compression_ratio) + '%';
                    }

                    if (data.bytes_saved) {
                        note += (note ? ' · ' : '') + bytes(data.bytes_saved) + ' saved';
                    }
                } else {
                    note = 'replaced';
                }

                log(job.name, note, 'ok');
            } else {
                S.failed++;
                log(job.name, esc((res && res.data && res.data.message) || 'failed'), 'bad');
            }
        }).fail(function () {
            S.failed++;
            log(job.name, 'request failed', 'bad');
        }).always(function () {
            S.index++;

            var pct = Math.round((S.index / S.queue.length) * 100);
            $('#mraf-run-bar').css('width', pct + '%');
            $('#mraf-run-pct').text(pct + '%');
            $('#mraf-run-state').text('Image ' + S.index + ' of ' + S.queue.length);

            step();
        });
    }

    function finishRun() {
        var verb = S.task === 'convert' ? 'Converted' : 'Replaced';
        var bits = [];

        S.running = false;

        $('#mraf-run-state').text(S.stopped ? 'Stopped' : 'Finished');

        bits.push(verb + ' ' + S.done + (S.done === 1 ? ' image' : ' images'));

        if (S.task === 'convert' && S.saved > 0) {
            bits.push(bytes(S.saved) + ' saved');
        }

        if (S.failed) {
            bits.push(S.failed + (S.failed === 1 ? ' failure' : ' failures'));
        }

        $('#mraf-run-summary')
            .toggleClass('is-bad', S.failed > 0 && S.done === 0)
            .html(
                '<span class="mraf-runsum__mark">' + (S.failed && !S.done ? '&#10007;' : '&#10003;') + '</span>' +
                '<span class="mraf-runsum__text">' + bits.join(' · ') + '</span>'
            )
            .prop('hidden', false);

        $('#mraf-m-stop').prop('hidden', true);
        $('#mraf-m-done').prop('hidden', false);
    }

    /* ==================================================================
       Studio Workspace: the gallery
       ================================================================== */

    var W = {
        page: 1,
        pages: 1,
        total: 0,
        images: [],
        selected: [],
        bulk: false,
        loading: false,
        timer: null
    };

    function initWorkspace() {
        if (!$('#mraf-grid').length) {
            return;
        }

        loadImages(true);

        $('#mraf-q').on('input', function () {
            clearTimeout(W.timer);
            W.timer = setTimeout(function () { loadImages(true); }, 350);
        });

        $('#mraf-f-type, #mraf-f-date, #mraf-f-status').on('change', function () {
            loadImages(true);
        });

        $('#mraf-btn-more').on('click', function () {
            loadImages(false);
        });

        $('#mraf-btn-bulk').on('click', function () { setBulk(true); });
        $('#mraf-btn-bulk-cancel').on('click', function () { setBulk(false); });

        $('#mraf-btn-sel-all').on('click', function () {
            W.selected = W.images.map(function (img) { return img.id; });
            renderGrid();
        });

        $('#mraf-btn-sel-none').on('click', function () {
            W.selected = [];
            renderGrid();
        });

        $('#mraf-btn-bulk-convert').on('click', function () {
            openModal(W.selected.slice(), 'convert');
        });

        $('#mraf-btn-bulk-replace').on('click', function () {
            openModal(W.selected.slice(), 'replace');
        });

        $(document).on('click', '.mraf-tile', function () {
            var id = $(this).data('id');

            if (W.bulk) {
                var at = W.selected.indexOf(id);

                if (at === -1) {
                    W.selected.push(id);
                } else {
                    W.selected.splice(at, 1);
                }

                renderGrid();
                return;
            }

            openModal([id], 'details');
        });
    }

    function setBulk(on) {
        W.bulk = on;

        if (!on) {
            W.selected = [];
        }

        $('#mraf-browser').toggleClass('is-bulk', on);
        $('#mraf-bar-normal').prop('hidden', on);
        $('#mraf-bar-bulk').prop('hidden', !on);
        $('#mraf-hint').html(
            on
                ? 'Tick the images you want, then choose an action above.'
                : 'Click an image to work on it. Press <strong>Bulk Select</strong> to pick several.'
        );

        renderGrid();
    }

    function loadImages(reset) {
        if (W.loading) {
            return;
        }

        W.loading = true;
        W.page = reset ? 1 : W.page + 1;

        $('#mraf-spinner').addClass('is-active');

        post('mraf_studio_search', {
            query: $('#mraf-q').val(),
            type: $('#mraf-f-type').val(),
            date: $('#mraf-f-date').val(),
            filter: $('#mraf-f-status').val(),
            page: W.page
        }).done(function (res) {
            if (res && res.success) {
                W.images = reset ? res.data.images : W.images.concat(res.data.images);
                W.total = res.data.total;
                W.pages = res.data.pages;

                if (reset) {
                    W.selected = [];
                }

                renderGrid();
                $('#mraf-btn-more').prop('hidden', !res.data.has_more);
            }
        }).always(function () {
            W.loading = false;
            $('#mraf-spinner').removeClass('is-active');
        });
    }

    function renderGrid() {
        if (!W.images.length) {
            $('#mraf-grid').html('<p class="mraf-empty">No images match those filters.</p>');
            $('#mraf-shown').text('');
            return;
        }

        var html = W.images.map(function (img) {
            var on = W.selected.indexOf(img.id) !== -1;
            var format = String(img.mime_type || '').replace('image/', '').toUpperCase();
            var name = img.filename || img.title;
            var flags = '';

            if (img.converted) {
                flags += '<span class="mraf-flag is-ok" title="Already WebP">WebP</span>';
            }

            if (img.has_backup) {
                flags += '<span class="mraf-flag" title="A backup of the original exists">Backup</span>';
            }

            if (!img.alt_text) {
                flags += '<span class="mraf-flag is-warn" title="No alt text set">No alt</span>';
            }

            return '<button type="button" class="mraf-tile' + (on ? ' is-selected' : '') + '" data-id="' + img.id + '" title="' + esc(name) + '">' +
                '<span class="mraf-tile__shot">' +
                    '<img class="mraf-tile__img" src="' + esc(img.thumb) + '" alt="">' +
                    (img.dimensions && img.dimensions !== '-'
                        ? '<span class="mraf-tile__dim">' + esc(img.dimensions) + '</span>'
                        : '') +
                '</span>' +
                '<span class="mraf-tile__cap">' +
                    '<span class="mraf-tile__name">' + esc(name) + '</span>' +
                    '<span class="mraf-tile__meta">' +
                        '<span class="mraf-badge' + (img.converted ? ' is-ok' : '') + '">' + esc(format) + '</span>' +
                        '<span class="mraf-tile__size">' + esc(img.filesize) + '</span>' +
                        '<span class="mraf-tile__date">' + esc(img.uploaded) + '</span>' +
                    '</span>' +
                    (flags ? '<span class="mraf-tile__flags">' + flags + '</span>' : '') +
                '</span>' +
                '<span class="mraf-tile__tick">' + (on ? '&#10003;' : '') + '</span>' +
            '</button>';
        }).join('');

        $('#mraf-grid').html(html);
        $('#mraf-shown').text(W.images.length + ' of ' + W.total + ' shown');
        $('#mraf-sel-count').text(W.selected.length + ' selected');
        $('#mraf-btn-bulk-convert, #mraf-btn-bulk-replace').prop('disabled', W.selected.length === 0);
    }

    /* ==================================================================
       Library screen wiring
       ================================================================== */

    function initLibrary() {
        if (!$('#mraf-modal').length) {
            return;
        }

        // Row actions, and the buttons inside the attachment details sidebar.
        $(document).on('click', '.mraf-row-action', function (e) {
            e.preventDefault();
            openModal([$(this).data('id')], $(this).data('mraf-action'));
        });

        // A bulk action handed us a selection through the URL.
        if (D.pendingJob && D.pendingJob.ids && D.pendingJob.ids.length) {
            openModal(D.pendingJob.ids, D.pendingJob.task);

            if (window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.delete('mraf_job');
                url.searchParams.delete('mraf_ids');
                window.history.replaceState({}, '', url.toString());
            }
        }

        // Modal chrome.
        $(document).on('click', '.mraf-modal__tabs button', function () {
            setTab($(this).data('mtab'));
        });

        $(document).on('click', '#mraf-m-close, #mraf-m-done', closeModal);

        $(document).on('click', '#mraf-modal', function (e) {
            if (e.target.id === 'mraf-modal' && !S.running) {
                closeModal();
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && !$('#mraf-modal').prop('hidden') && !S.running) {
                closeModal();
            }
        });

        $(document).on('input', '#mraf-s-quality', function () {
            $('#mraf-s-quality-val').text($(this).val());
        });

        // Pairing rows. With files waiting in the pool, offer those first.
        $(document).on('click', '.mraf-slot', function () {
            S.slot = $(this).data('slot');

            if (S.pool.length) {
                renderPairs();
                return;
            }

            $('#mraf-file-input').val('').trigger('click');
        });

        $(document).on('change', '#mraf-file-input', function () {
            if (this.files && this.files[0] && S.slot !== null) {
                uploadFile(this.files[0], S.slot);
            }
        });

        $(document).on('click', '[data-clear]', function () {
            S.pairs[$(this).data('clear')].file = null;
            renderPairs();
        });

        $(document).on('dragover', '.mraf-slot', function (e) {
            e.preventDefault();
            $(this).addClass('is-over');
        });

        $(document).on('dragleave drop', '.mraf-slot', function () {
            $(this).removeClass('is-over');
        });

        $(document).on('drop', '.mraf-slot', function (e) {
            e.preventDefault();
            var files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;

            if (files && files[0]) {
                uploadFile(files[0], $(this).data('slot'));
            }
        });

        // Drop a batch of new files, match what can be matched by name,
        // and keep the rest in a pool the empty rows can pick from.
        $(document).on('click', '#mraf-btn-automatch', function () {
            $('#mraf-files-input').val('').trigger('click');
        });

        $(document).on('change', '#mraf-files-input', function () {
            var files = Array.prototype.slice.call(this.files || []);

            if (files.length) {
                uploadPool(files);
            }
        });

        $(document).on('click', '[data-pool]', function () {
            var pick = S.pool.splice($(this).data('pool'), 1)[0];

            if (pick && S.slot !== null) {
                S.pairs[S.slot].file = pick;
            }

            S.slot = null;
            renderPairs();
        });

        $(document).on('click', '#mraf-pool-browse', function () {
            $('#mraf-file-input').val('').trigger('click');
        });

        // Copy the file URL.
        $(document).on('click', '#mraf-btn-copy-url', function () {
            var field = document.getElementById('mraf-d-url');

            if (!field) {
                return;
            }

            field.select();

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(field.value);
            } else {
                document.execCommand('copy');
            }

            toast(str('copied', 'URL copied to clipboard.'));
        });

        $(document).on('click', '#mraf-m-convert', function () {
            if (S.running) {
                return;
            }

            openConvertPanel();
        });

        $(document).on('input', '.mraf-crow__name', function () {
            var index = Number($(this).data('index'));

            if (S.items[index]) {
                S.items[index].rename_to = $(this).val();
            }
        });

        $(document).on('click', '#mraf-c-reset', function () {
            S.items.forEach(function (item) {
                delete item.rename_to;
            });

            renderConvertPanel();
        });

        // The one primary button.
        $(document).on('click', '#mraf-m-go', function () {
            if (S.task === 'details') {
                saveDetails();
                return;
            }

            startRun();
        });

        $(document).on('click', '#mraf-m-stop', function () {
            S.stopped = true;
        });
    }

    /* ==================================================================
       Backups, settings and debug screen
       ================================================================== */

    function initSettingsScreen() {
        if (!$('.mraf-tabs').length) {
            return;
        }

        $('.mraf-tabs button').on('click', function () {
            var tab = $(this).data('tab');

            $('.mraf-tabs button').removeClass('is-active');
            $(this).addClass('is-active');

            $('.mraf-panel').each(function () {
                $(this).prop('hidden', $(this).data('panel') !== tab);
            });

            if (tab === 'backups') {
                loadBackups();
            }
        });

        // The modal links here with #settings, so honour the hash on load.
        if (window.location.hash === '#settings') {
            $('.mraf-tabs button[data-tab="settings"]').trigger('click');
        }

        $(document).on('click', '.mraf-restore', function () {
            if (!window.confirm('Restore this image from its backup? The current file is overwritten.')) {
                return;
            }

            post('mraf_studio_restore_backup', { attachment_id: $(this).data('id') }).done(function (res) {
                if (res && res.success) {
                    loadBackups();
                } else {
                    window.alert((res && res.data && res.data.message) || 'Could not restore that backup.');
                }
            });
        });

        $(document).on('click', '.mraf-delete-backup', function () {
            if (!window.confirm('Delete this backup? This cannot be undone.')) {
                return;
            }

            post('mraf_studio_delete_backup', { attachment_id: $(this).data('id') }).done(function () {
                loadBackups();
            });
        });

        $('#mraf-btn-save-settings').on('click', function () {
            var $btn = $(this).prop('disabled', true);
            var debugWas = D.debugEnabled;
            var debugNow = $('#mraf-setting-debug').is(':checked');

            post('mraf_studio_save_options', {
                quality: $('#mraf-s-quality').val(),
                enable_resize: $('#mraf-s-resize').is(':checked') ? 1 : 0,
                max_width: $('#mraf-s-maxw').val(),
                update_refs: $('#mraf-s-refs').is(':checked') ? 1 : 0,
                create_backup: $('#mraf-s-backup').is(':checked') ? 1 : 0,
                delete_original: $('#mraf-s-delete').is(':checked') ? 1 : 0,
                keep_basename: $('#mraf-s-keepname').is(':checked') ? 1 : 0,
                r_keep_basename: $('#mraf-s-r-keepname').is(':checked') ? 1 : 0
            }).done(function (res) {
                if (!res || !res.success) {
                    $btn.prop('disabled', false);
                    window.alert((res && res.data && res.data.message) || 'Could not save the settings.');
                    return;
                }

                D.options = res.data.options;

                post('mraf_studio_save_setting', {
                    key: 'debug_enabled',
                    value: debugNow ? 1 : ''
                }).always(function () {
                    // The Debug tab is printed server side, so its state needs a reload.
                    if (debugNow !== debugWas) {
                        window.location.reload();
                        return;
                    }

                    $btn.prop('disabled', false);
                    toast(str('saved', 'Settings saved.'));
                });
            }).fail(function () {
                $btn.prop('disabled', false);
                window.alert('Could not save the settings.');
            });
        });

        $('#mraf-btn-refresh-log').on('click', function () {
            post('mraf_studio_debug_log').done(function (res) {
                $('#mraf-console').text((res && res.data && res.data.log) || 'Log is empty.');
            });
        });

        $('#mraf-btn-clear-log').on('click', function () {
            post('mraf_studio_debug_log', { clear: 1 }).done(function () {
                $('#mraf-console').text('Log is empty.');
            });
        });
    }

    function loadBackups() {
        var $body = $('#mraf-backups tbody');

        if (!$body.length) {
            return;
        }

        $body.html('<tr><td colspan="5">Loading backups&hellip;</td></tr>');

        post('mraf_studio_get_backups').done(function (res) {
            if (!res || !res.success || !res.data.backups.length) {
                $body.html('<tr><td colspan="5">No backups yet. One is written before every conversion and replacement.</td></tr>');
                return;
            }

            var rows = res.data.backups.map(function (backup) {
                return '<tr>' +
                    '<td>' + esc(backup.attachment_id || backup.id || '') + '</td>' +
                    '<td>' + esc(backup.filename || backup.original_file || '') + '</td>' +
                    '<td>' + esc(backup.date || backup.backed_up || '') + '</td>' +
                    '<td>' + esc(backup.files_count || (backup.files ? backup.files.length : '')) + '</td>' +
                    '<td>' +
                        '<button type="button" class="button mraf-restore" data-id="' + esc(backup.attachment_id || backup.id) + '">Restore</button> ' +
                        '<button type="button" class="button mraf-delete-backup" data-id="' + esc(backup.attachment_id || backup.id) + '">Delete</button>' +
                    '</td>' +
                '</tr>';
            });

            $body.html(rows.join(''));
        });
    }

    $(function () {
        initLibrary();
        initWorkspace();
        initSettingsScreen();
    });
}(jQuery));
