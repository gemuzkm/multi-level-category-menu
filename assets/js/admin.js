jQuery(function($) {
    $('#mlcm-clear-cache').on('click', function(e) {
        e.preventDefault();
        const $button = $(this);
        const originalText = $button.text();
        const $spinner = $button.next('.spinner');

        $button.prop('disabled', true);
        $spinner.css('visibility', 'visible');

        $.ajax({
            url: mlcmAdmin.ajax_url,
            method: 'POST',
            data: {
                action: 'mlcm_clear_all_caches',
                security: mlcmAdmin.nonce
            },
            success: (response) => {
                const messageType = response.success ? 'success' : 'error';
                addAdminNotice(response.data.message, messageType);
            },
            error: (xhr) => {
                addAdminNotice(mlcmAdmin.i18n.error + ': ' + xhr.statusText, 'error');
            },
            complete: () => {
                $button.prop('disabled', false);
                $spinner.css('visibility', 'hidden');
            }
        });
    });

    function addAdminNotice(message, type = 'success') {
        const notice = $(`
            <div class="notice notice-${type} is-dismissible">
                <p>${message}</p>
            </div>
        `);
        $('.wrap h1').after(notice);
        setTimeout(() => notice.fadeOut(), 5000);
    }
});