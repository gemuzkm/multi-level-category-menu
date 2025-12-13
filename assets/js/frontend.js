/**
 * Get nonce from cache or fetch fresh one
 * Priority: sessionStorage > window.mlcmNonce > fetch from server
 */
function getMlcmNonce(callback) {
    // If nonce is already in cache, return it
    const stored = sessionStorage.getItem('mlcm_nonce');
    if (stored) {
        window.mlcmNonce = stored;
        if (typeof callback === 'function') {
            callback(stored);
        }
        return stored;
    }
    
    // If exists in window variable
    if (typeof window.mlcmNonce !== 'undefined' && window.mlcmNonce) {
        if (typeof callback === 'function') {
            callback(window.mlcmNonce);
        }
        return window.mlcmNonce;
    }
    
    // Otherwise fetch from server
    fetchFreshNonce(callback);
    return null;
}

    /**
     * Fetch fresh nonce from server
     * Compatible with cached pages - always fetches fresh nonce
     */
    function fetchFreshNonce(callback) {
        if (typeof mlcmVars === 'undefined' || !mlcmVars.ajax_url) {
            console.error('MLCM: mlcmVars not defined');
            if (typeof callback === 'function') {
                callback('');
            }
            return;
        }
        
        // Add timestamp to prevent request caching
        const timestamp = new Date().getTime();
        
        jQuery.ajax({
            url: mlcmVars.ajax_url,
            method: 'POST',
            cache: false, // Explicitly disable caching
            data: {
                action: 'mlcm_get_nonce',
                _t: timestamp // Timestamp to prevent caching
            },
            success: (response) => {
                if (response.success && response.data && response.data.nonce) {
                    const nonce = response.data.nonce;
                    sessionStorage.setItem('mlcm_nonce', nonce);
                    window.mlcmNonce = nonce;
                    if (typeof callback === 'function') {
                        callback(nonce);
                    }
                } else {
                    console.error('MLCM: Failed to get nonce', response);
                    if (typeof callback === 'function') {
                        callback('');
                    }
                }
            },
            error: (xhr, status, error) => {
                console.error('MLCM: Error fetching nonce', error);
                if (typeof callback === 'function') {
                    callback('');
                }
            }
        });
    }

jQuery(function($) {
    const $container = $('.mlcm-container');
    if (!$container.length) return; // Early exit

    // Check if page is cached
    const isCached = $container.data('cached') === 1 || $container.data('cached') === '1';
    
    // For cached pages, get nonce in advance
    // For non-cached pages, get it on first use
    if (isCached) {
        getMlcmNonce(); // Preload nonce for cached pages
    }

    // Remove duplicate buttons (optimized)
    const $buttons = $container.find('.mlcm-go-button');
    if ($buttons.length > 1) {
        $buttons.not(':first').remove();
    }

    // Applying dynamic styles (only if needed)
    const gap = parseInt($container.css('gap'));
    const fontSize = $container.css('font-size');
    if (gap) $container[0].style.setProperty('--mlcm-gap', `${gap}px`);
    if (fontSize) $container[0].style.setProperty('--mlcm-font-size', fontSize);

    // Debounce for select change handling
    let changeTimeout;
    $container.on('change', '.mlcm-select', function() {
        const $select = $(this);
        const level = $select.data('level');
        const $selectedOption = $select.find('option:selected');
        const parentId = $selectedOption.val();
        
        clearTimeout(changeTimeout);
        
        if (parentId === '-1') {
            resetLevels(level);
            return;
        }
        
        // Small delay to prevent multiple requests
        changeTimeout = setTimeout(() => {
            loadSubcategories($select, level, parentId);
        }, 150);
    });

    // Button click handler
    $container.on('click', '.mlcm-go-button', redirectToCategory);

    // Reset the next levels
    function resetLevels(currentLevel) {
        $container.find('.mlcm-select').each(function() {
            const $sel = $(this);
            if ($sel.data('level') > currentLevel) {
                $sel.val('-1').prop('disabled', true);
            }
        });
    }

    // Show loading indicator
    function showLoading($select) {
        const $nextSelect = $select.next('.mlcm-level').find('.mlcm-select');
        if ($nextSelect.length) {
            $nextSelect.prop('disabled', true)
                .html('<option value="-1">Loading...</option>');
        }
    }

    // Hide loading indicator
    function hideLoading() {
        // Already handled in updateNextLevel
    }

    // Loading subcategories (optimized)
    function loadSubcategories($select, level, parentId, retryCount = 0) {
        const maxLevels = $container.data('levels');
        if (level >= maxLevels) {
            redirectToCategory();
            return;
        }

        // Show loading indicator
        showLoading($select);

        // Get nonce asynchronously
        getMlcmNonce(function(currentNonce) {
            if (!currentNonce) {
                console.error('MLCM: Could not get nonce');
                hideLoading();
                return;
            }

            $.ajax({
                url: mlcmVars.ajax_url,
                method: 'POST',
                cache: false, // Explicitly disable caching AJAX запросов
                data: {
                    action: 'mlcm_get_subcategories',
                    parent_id: parentId,
                    security: currentNonce,
                    _t: new Date().getTime() // Timestamp to prevent caching
                },
                beforeSend: () => {
                    $select.nextAll('.mlcm-select').val('-1').prop('disabled', true);
                },
                success: (response) => {
                    // Check nonce error and retry request
                    if (!response.success && response.data && response.data.code === 'invalid_nonce' && response.data.retry) {
                        if (retryCount < 2) {
                            // Clear nonce cache and get new one
                            sessionStorage.removeItem('mlcm_nonce');
                            delete window.mlcmNonce;
                            
                            // Retry request with new nonce
                            setTimeout(function() {
                                loadSubcategories($select, level, parentId, retryCount + 1);
                            }, 100);
                            return;
                        } else {
                            console.error('MLCM: Failed to get valid nonce after retries');
                            hideLoading();
                            return;
                        }
                    }
                    
                    // Handle successful response
                    if (response.success && response.data && Object.keys(response.data).length > 0) {
                        updateNextLevel($select, level, response.data);
                    } else {
                        // If no subcategories, redirect
                        hideLoading();
                        redirectToCategory();
                    }
                },
                error: (xhr, status, error) => {
                    console.error('MLCM: Failed to load subcategories', error);
                    hideLoading();
                }
            });
        });
    }

    // Next level update (optimized)
    // Data comes as array of objects, already sorted on server
    function updateNextLevel($select, currentLevel, categories) {
        const nextLevel = currentLevel + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        
        if (!$nextSelect.length) return;
        
        const label = mlcmVars.labels[nextLevel - 1];
        
        // Data now comes as array of objects [{id, name, slug, url}, ...]
        // This ensures order preservation from server
        let options = '';
        
        if (Array.isArray(categories)) {
            // If it's an array - use directly (new format)
            options = categories.map(item => {
                const id = item.id || item.term_id || '';
                const name = item.name || '';
                const slug = item.slug || '';
                const url = item.url || '';
                return `<option value="${id}" data-slug="${slug}" data-url="${url}">${name}</option>`;
            }).join('');
        } else if (typeof categories === 'object' && categories !== null) {
            // Backward compatibility: if object received (old format)
            // Sort by name before displaying
            const entries = Object.entries(categories);
            entries.sort((a, b) => {
                const nameA = (a[1].name || '').toUpperCase();
                const nameB = (b[1].name || '').toUpperCase();
                return nameA.localeCompare(nameB);
            });
            
            options = entries.map(([id, data]) => 
                `<option value="${id}" data-slug="${data.slug || ''}" data-url="${data.url || ''}">${data.name || ''}</option>`
            ).join('');
        }
        
        $nextSelect.prop('disabled', false)
            .html(`<option value="-1">${label}</option>${options}`);
    }

    // Redirect to category page
    function redirectToCategory() {
        const $lastSelect = $container.find('.mlcm-select')
            .filter(function() { return $(this).val() !== '-1'; })
            .last();
        
        if ($lastSelect.length) {
            const url = $lastSelect.find('option:selected').data('url');
            // Validate URL before redirect
            if (url && url.startsWith('http')) {
                window.location.href = url;
            }
        }
    }

    // Mobile layout (debounced)
    const handleMobileLayout = (() => {
        let timeout;
        return () => {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                if (window.matchMedia('(max-width: 768px)').matches) {
                    $container.find('.mlcm-go-button').css({
                        width: '100%',
                        margin: '10px 0 0 0'
                    });
                }
            }, 100);
        };
    })();

    handleMobileLayout();
    $(window).on('resize', handleMobileLayout);
});
