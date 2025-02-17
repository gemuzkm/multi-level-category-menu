jQuery(function($) {
    $('#mlcm-clear-cache').on('click', function(e) {
        e.preventDefault();
        const $button = $(this);
        const originalText = $button.text();

        $button.prop('disabled', true).text(mlcmAdmin.i18n.clearing);

        $.ajax({
            url: mlcmAdmin.ajax_url,
            method: 'POST',
            data: {
                action: 'mlcm_clear_all_caches',
                security: mlcmAdmin.nonce
            },
            success: (response) => {
                if (response.success) {
                    addAdminNotice(response.data.message, 'success');
                } else {
                    addAdminNotice(response.data.message, 'error');
                }
            },
            error: (xhr) => {
                addAdminNotice(mlcmAdmin.i18n.error + ': ' + xhr.statusText, 'error');
            },
            complete: () => {
                $button.prop('disabled', false).text(originalText);
            }
        });
    });

    function addAdminNotice(message, type = 'success') {
        const notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').after(notice);
        setTimeout(() => notice.fadeOut(), 5000);
    }
});