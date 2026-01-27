jQuery(function($) {
    const $genBtn = $('#mlcm-generate-menu');
    const $spinner = $genBtn.next('.spinner');
    const $status = $('#mlcm-generation-status');
    
    if (!$genBtn.length) return;
    
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
                
                if (response.success || response.data.success) {
                    const message = response.data.message || mlcmAdmin.i18n.menu_generated;
                    $status.html('<div class="notice notice-success is-dismissible"><p>' + message + '</p></div>');
                    
                    // Auto-dismiss after 5 seconds
                    setTimeout(() => {
                        $status.fadeOut('fast', function() {
                            $(this).html('');
                            $(this).show();
                        });
                    }, 5000);
                } else {
                    const error = response.data.message || mlcmAdmin.i18n.error;
                    $status.html('<div class="notice notice-error is-dismissible"><p>' + error + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $spinner.removeClass('is-active');
                $genBtn.prop('disabled', false);
                $status.html('<div class="notice notice-error is-dismissible"><p>' + mlcmAdmin.i18n.error + ': ' + error + '</p></div>');
            }
        });
    });
    
    // Handle notice dismissal
    $(document).on('click', '.notice-dismiss', function() {
        $(this).closest('.notice').fadeOut('fast');
    });
});
