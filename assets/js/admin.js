jQuery(function($) {
    'use strict';
    
    // Обработчик очистки всего кеша
    $('#mlcm-clear-cache').on('click', function(e) {
        e.preventDefault();
        const $button = $(this);
        const originalText = $button.text();
        const $spinner = $button.next('.spinner');
        
        $button.prop('disabled', true).text(mlcmAdmin.i18n.clearing);
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
                $button.prop('disabled', false).text(originalText);
                $spinner.css('visibility', 'hidden');
            }
        });
    });
    
    // ДОБАВЛЕНО: Обработчик очистки устаревших транзиентов
    $('#mlcm-cleanup-transients').on('click', function(e) {
        e.preventDefault();
        const $button = $(this);
        const originalText = $button.text();
        const $spinner = $button.next('.spinner');
        
        $button.prop('disabled', true).text('Cleaning up...');
        $spinner.css('visibility', 'visible');
        
        $.ajax({
            url: mlcmAdmin.ajax_url,
            method: 'POST',
            data: {
                action: 'mlcm_cleanup_transients',
                security: mlcmAdmin.nonce
            },
            success: (response) => {
                const messageType = response.success ? 'success' : 'error';
                const message = response.success ? mlcmAdmin.i18n.cleanup_done : response.data.message;
                addAdminNotice(message, messageType);
            },
            error: (xhr) => {
                addAdminNotice('Error cleaning up transients: ' + xhr.statusText, 'error');
            },
            complete: () => {
                $button.prop('disabled', false).text(originalText);
                $spinner.css('visibility', 'hidden');
            }
        });
    });
    
    function addAdminNotice(message, type = 'success') {
        const notice = $(`
            <div class="notice notice-${type} is-dismissible">
                <p>${message}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            </div>
        `);
        
        $('.wrap h1').after(notice);
        
        // Автоматическое скрытие через 5 секунд
        setTimeout(() => notice.fadeOut(), 5000);
        
        // Обработчик кнопки закрытия
        notice.on('click', '.notice-dismiss', function() {
            notice.fadeOut();
        });
    }
});