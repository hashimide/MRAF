(function ($, wp) {
    if (!wp || !wp.media || !wp.ajax) {
        return;
    }

    const render = wp.media.view.AttachmentCompat.prototype.render;
    const cache = {};

    function fetchUsage(attachmentId, $field) {
        if (!attachmentId) {
            return;
        }

        const cacheKey = String(attachmentId);
        if (cache[cacheKey]) {
            cache[cacheKey].then((response) => {
                if (response && response.html) {
                    $field.html(response.html);
                }
            });
            return;
        }

        $field.addClass('mraf-usage-loading');

        cache[cacheKey] = wp.ajax
            .post('mraf_get_usage', {
                attachment_id: attachmentId,
                nonce: mrafUsage.nonce
            })
            .done((response) => {
                if (response && response.html) {
                    $field.html(response.html);
                } else if (mrafUsage.empty) {
                    $field.html(mrafUsage.empty);
                }
            })
            .fail(() => {
                $field.html('<p class="mraf-usage-error">' + mrafUsage.error + '</p>');
            })
            .always(() => {
                $field.removeClass('mraf-usage-loading');
            });
    }

    function hydrate(view) {
        if (!view || !view.$el) {
            return;
        }
        const $field = view.$('.compat-field-mraf_usage .field');
        if (!$field.length) {
            return;
        }

        const $placeholder = $field.find('.mraf-usage-placeholder').first();
        if (!$placeholder.length) {
            return;
        }

        if ($placeholder.data('mrafLoaded')) {
            return;
        }

        const attachmentId = $placeholder.data('mrafAttachment') || (view.model && view.model.get && view.model.get('id'));
        if (!attachmentId) {
            return;
        }

        $placeholder.data('mrafLoaded', true);
        fetchUsage(attachmentId, $field);
    }

    wp.media.view.AttachmentCompat.prototype.render = function () {
        render.apply(this, arguments);
        hydrate(this);
        return this;
    };
})(jQuery, window.wp);
