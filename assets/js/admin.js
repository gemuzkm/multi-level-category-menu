jQuery(function($) {
    const $genBtn = $('#mlcm-generate-menu');
    const $clearBtn = $('#mlcm-clear-cache');
    const $spinner = $genBtn.next('.spinner');
    const $status = $('#mlcm-generation-status');
    
    if (!$genBtn.length) return;

    /**
     * Отображение сообщения и автоудаление через 5 секунд
     */
    function showMessage(message, isSuccess = true) {
        const noticeClass = isSuccess ? 'notice-success' : 'notice-error';
        const icon = isSuccess ? '✓' : '✕';
        const html = `<div class="notice ${noticeClass} is-dismissible notice-mlcm" data-dismissible="mlcm">
            <p>${icon} ${message}</p>
            <button type="button" class="notice-dismiss" style="position: absolute; top: 5px; right: 5px; background: none; border: none; padding: 0; cursor: pointer; font-size: 20px;">×</button>
        </div>`;
        
        $status.html(html);
        
        // Обработка кнопки закрытия
        $status.find('.notice-dismiss').on('click', function() {
            removeNotice();
        });
        
        // Автоудаление через 5 секунд
        const timeout = setTimeout(function() {
            removeNotice();
        }, 5000);
        
        // Сохранить timeout для возможной очистки
        $status.data('timeout', timeout);
    }

    /**
     * Удаление уведомления с анимацией
     */
    function removeNotice() {
        const timeout = $status.data('timeout');
        if (timeout) {
            clearTimeout(timeout);
        }
        $status.fadeOut(200, function() {
            $status.html('');
            $status.show();
        });
    }

    /**
     * Обновление состояния кнопки Delete Cache
     */
    function updateClearButtonState() {
        $.ajax({
            url: mlcmAdmin.ajax_url,
            method: 'POST',
            data: {
                action: 'mlcm_check_cache',
                security: mlcmAdmin.nonce
            },
            success: function(response) {
                if (response.success && response.data.has_cache) {
                    $clearBtn.prop('disabled', false);
                } else {
                    $clearBtn.prop('disabled', true);
                }
            }
        });
    }
    
    // Генерирование меню
    $genBtn.on('click', function(e) {
        e.preventDefault();
        
        // Очистить старое сообщение
        clearTimeout($status.data('timeout'));
        
        $spinner.addClass('is-active');
        $genBtn.prop('disabled', true);
        $status.html('<div class="notice notice-info notice-mlcm"><p>' + mlcmAdmin.i18n.generating + '</p></div>');
        
        $.ajax({
            url: mlcmAdmin.ajax_url,
            method: 'POST',
            data: {
                action: 'mlcm_generate_menu',
                security: mlcmAdmin.nonce
            },
            success: function(response) {
                $spinner.removeClass('is-active');
                $genBtn.prop('disabled', false);
                
                if (response.success || response.data.success) {
                    const message = response.data.message || mlcmAdmin.i18n.menu_generated;
                    showMessage(message, true);
                    updateClearButtonState();
                } else {
                    const error = response.data.message || mlcmAdmin.i18n.error;
                    showMessage(error, false);
                }
            },
            error: function(xhr, status, error) {
                $spinner.removeClass('is-active');
                $genBtn.prop('disabled', false);
                showMessage(mlcmAdmin.i18n.error + ': ' + error, false);
            }
        });
    });
    
    // Удаление кэша
    $clearBtn.on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(mlcmAdmin.i18n.confirm_clear)) {
            return;
        }
        
        // Очистить старое сообщение
        clearTimeout($status.data('timeout'));
        
        $spinner.addClass('is-active');
        $clearBtn.prop('disabled', true);
        $status.html('<div class="notice notice-info notice-mlcm"><p>' + mlcmAdmin.i18n.clearing + '</p></div>');
        
        $.ajax({
            url: mlcmAdmin.ajax_url,
            method: 'POST',
            data: {
                action: 'mlcm_clear_cache',
                security: mlcmAdmin.nonce
            },
            success: function(response) {
                $spinner.removeClass('is-active');
                
                if (response.success) {
                    const message = response.data.message || mlcmAdmin.i18n.cache_cleared;
                    showMessage(message, true);
                    $clearBtn.prop('disabled', true);
                } else {
                    const error = response.data.message || mlcmAdmin.i18n.error;
                    showMessage(error, false);
                    $clearBtn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $spinner.removeClass('is-active');
                $clearBtn.prop('disabled', false);
                showMessage(mlcmAdmin.i18n.error + ': ' + error, false);
            }
        });
    });
    
    // Инициализация состояния кнопки при загрузке
    updateClearButtonState();
});
