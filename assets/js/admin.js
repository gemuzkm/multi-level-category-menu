jQuery(function($) {
    const $genBtn = $('#mlcm-generate-menu');
    const $deleteBtn = $('#mlcm-delete-cache');
    const $spinner = $genBtn.next('.spinner');
    const $status = $('#mlcm-generation-status');
    
    // Auto-hide messages function
    function autoHideMessage($element, delay) {
        delay = delay || 5000;
        setTimeout(() => {
            $element.fadeOut('fast', function() {
                $(this).html('');
                $(this).show();
            });
        }, delay);
    }
    
    // Generate menu handler
    if ($genBtn.length) {
        $genBtn.on('click', function(e) {
            e.preventDefault();
            
            $spinner.addClass('is-active');
            $genBtn.prop('disabled', true);
            $status.html('<div class="notice notice-info"><p>' + mlcmAdmin.i18n.generating + '</p></div>');
            
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
                    
                    // Check response structure - WordPress AJAX can return data in different formats
                    const responseData = response.data || response;
                    const isSuccess = response.success === true || (responseData && responseData.success === true);
                    
                    if (isSuccess) {
                        const message = (responseData && responseData.message) ? responseData.message : mlcmAdmin.i18n.menu_generated;
                        $status.html('<div class="notice notice-success is-dismissible"><p>' + message + '</p></div>');
                        autoHideMessage($status, 5000);
                    } else {
                        const error = (responseData && responseData.message) ? responseData.message : mlcmAdmin.i18n.error;
                        $status.html('<div class="notice notice-error is-dismissible"><p>' + error + '</p></div>');
                        autoHideMessage($status, 8000);
                    }
                },
                error: function(xhr, status, error) {
                    $spinner.removeClass('is-active');
                    $genBtn.prop('disabled', false);
                    const errorMsg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                        ? xhr.responseJSON.data.message 
                        : (mlcmAdmin.i18n.error + ': ' + error);
                    $status.html('<div class="notice notice-error is-dismissible"><p>' + errorMsg + '</p></div>');
                    autoHideMessage($status, 8000);
                }
            });
        });
    }
    
    // Delete cache handler
    if ($deleteBtn.length) {
        $deleteBtn.on('click', function(e) {
            e.preventDefault();
            
            if (!confirm(mlcmAdmin.i18n.confirm_delete)) {
                return;
            }
            
            const $btnSpinner = $deleteBtn.next('.spinner');
            $btnSpinner.addClass('is-active');
            $deleteBtn.prop('disabled', true);
            $status.html('<div class="notice notice-info"><p>' + mlcmAdmin.i18n.deleting + '</p></div>');
            
            $.ajax({
                url: mlcmAdmin.ajax_url,
                method: 'POST',
                data: {
                    action: 'mlcm_delete_cache',
                    security: mlcmAdmin.nonce
                },
                success: function(response) {
                    $btnSpinner.removeClass('is-active');
                    $deleteBtn.prop('disabled', false);
                    
                    const responseData = response.data || response;
                    const isSuccess = response.success === true || (responseData && responseData.success === true);
                    
                    if (isSuccess) {
                        const message = (responseData && responseData.message) ? responseData.message : mlcmAdmin.i18n.cache_deleted;
                        $status.html('<div class="notice notice-success is-dismissible"><p>' + message + '</p></div>');
                        autoHideMessage($status, 5000);
                    } else {
                        const error = (responseData && responseData.message) ? responseData.message : mlcmAdmin.i18n.delete_error;
                        $status.html('<div class="notice notice-error is-dismissible"><p>' + error + '</p></div>');
                        autoHideMessage($status, 8000);
                    }
                },
                error: function(xhr, status, error) {
                    $btnSpinner.removeClass('is-active');
                    $deleteBtn.prop('disabled', false);
                    const errorMsg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                        ? xhr.responseJSON.data.message 
                        : (mlcmAdmin.i18n.delete_error + ': ' + error);
                    $status.html('<div class="notice notice-error is-dismissible"><p>' + errorMsg + '</p></div>');
                    autoHideMessage($status, 8000);
                }
            });
        });
    }
    
    // Handle notice dismissal
    $(document).on('click', '.notice-dismiss', function() {
        $(this).closest('.notice').fadeOut('fast');
    });
});
