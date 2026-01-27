jQuery(function($) {
    const $genBtn = $('#mlcm-generate-menu');
    const $clearBtn = $('#mlcm-clear-cache');
    const $spinner = $genBtn.next('.spinner');
    const $status = $('#mlcm-generation-status');
    
    if (!$genBtn.length) return;

    /**
     * Display message and auto-hide after 5 seconds
     */
    function showMessage(message, isSuccess = true) {
        const noticeClass = isSuccess ? 'notice-success' : 'notice-error';
        const icon = isSuccess ? '✓' : '✕';
        const html = `<div class="notice ${noticeClass} is-dismissible notice-mlcm" data-dismissible="mlcm" style="animation: slideIn 0.3s ease-in;">
            <p><strong>${icon}</strong> ${message}</p>
            <button type="button" class="notice-dismiss" style="position: absolute; top: 5px; right: 5px; background: none; border: none; padding: 0; cursor: pointer; font-size: 20px; color: inherit;">×</button>
        </div>
        <style>
            @keyframes slideIn {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @keyframes slideOut {
                from { opacity: 1; transform: translateY(0); }
                to { opacity: 0; transform: translateY(-10px); }
            }
            .notice-mlcm { animation: slideIn 0.3s ease-in; }
            .notice-mlcm.removing { animation: slideOut 0.3s ease-out forwards; }
        </style>`;
        
        $status.html(html);
        
        // Dismiss button handler
        $status.find('.notice-dismiss').on('click', function() {
            removeNotice();
        });
        
        // Auto-hide after 5 seconds
        const timeout = setTimeout(function() {
            removeNotice();
        }, 5000);
        
        $status.data('timeout', timeout);
    }

    /**
     * Remove notification with animation
     */
    function removeNotice() {
        const timeout = $status.data('timeout');
        if (timeout) {
            clearTimeout(timeout);
        }
        
        $status.find('.notice-mlcm').addClass('removing');
        
        setTimeout(function() {
            $status.html('');
        }, 300);
    }

    /**
     * Update Delete Cache button state
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
    
    /**
     * Generate Menu Button Handler
     */
    $genBtn.on('click', function(e) {
        e.preventDefault();
        
        // Clear previous message timeout
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
                
                if (response.success || (response.data && response.data.success)) {
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
    
    /**
     * Delete Cache Button Handler
     */
    $clearBtn.on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(mlcmAdmin.i18n.confirm_clear)) {
            return;
        }
        
        // Clear previous message timeout
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
    
    // Initialize button states on page load
    updateClearButtonState();
});
