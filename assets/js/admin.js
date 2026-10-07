jQuery(function ($) {
    'use strict';
    const $generate = $('#mlcm-generate-menu');
    const $delete = $('#mlcm-delete-cache');
    const $status = $('#mlcm-generation-status');
    let timer;

    function notice(message, type) {
        clearTimeout(timer);
        // Server error messages are text, never trusted HTML.
        $status.empty().show().append(
            $('<div>').addClass('notice notice-' + type).append($('<p>').text(message))
        );
        if (type !== 'info') {
            timer = setTimeout(function () { $status.empty(); }, type === 'error' ? 8000 : 5000);
        }
    }

    function request(button, action, messages) {
        const $spinner = button.next('.spinner');
        $generate.add($delete).prop('disabled', true);
        $spinner.addClass('is-active');
        notice(messages.pending, 'info');
        $.ajax({
            url: mlcmAdmin.ajax_url,
            method: 'POST',
            data: { action, security: mlcmAdmin.nonce }
        }).done(function (response) {
            const data = response.data || {};
            notice(data.message || (response.success ? messages.success : messages.error),
                response.success ? 'success' : 'error');
        }).fail(function (xhr) {
            const data = xhr.responseJSON && xhr.responseJSON.data;
            notice((data && data.message) || messages.error, 'error');
        }).always(function () {
            $generate.add($delete).prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    }
    $generate.on('click', function (event) {
        event.preventDefault();
        request($generate, 'mlcm_generate_menu', {
            pending: mlcmAdmin.i18n.generating,
            success: mlcmAdmin.i18n.menu_generated,
            error: mlcmAdmin.i18n.error
        });
    });
    $delete.on('click', function (event) {
        event.preventDefault();
        if (!window.confirm(mlcmAdmin.i18n.confirm_delete)) return;
        request($delete, 'mlcm_delete_cache', {
            pending: mlcmAdmin.i18n.deleting,
            success: mlcmAdmin.i18n.cache_deleted,
            error: mlcmAdmin.i18n.delete_error
        });
    });
});
