/**
 * Load level data from static JSON or AJAX fallback
 */
function loadLevelData(level, parentId = null, callback) {
    const useStatic = typeof mlcmVars !== 'undefined' && mlcmVars.use_static === '1';
    
    if (!useStatic) {
        // Fallback to AJAX
        loadLevelDataAjax(level, parentId, callback);
        return;
    }
    
    // Try to load static JSON first
    let jsonUrl;
    if (level === 1) {
        jsonUrl = mlcmVars.static_url + '/level-1.json?v=' + Math.random();
    } else if (level > 1 && level <= 5) {
        jsonUrl = mlcmVars.static_url + '/level-' + level + '.json?v=' + Math.random();
    } else {
        callback(null);
        return;
    }
    
    // Fetch static JSON with gzip support
    fetch(jsonUrl, {
        method: 'GET',
        headers: {
            'Accept-Encoding': 'gzip, deflate, br',
            'Accept': 'application/json'
        },
        cache: 'default'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Failed to load JSON: ' + response.statusText);
        }
        return response.json();
    })
    .then(data => {
        callback(data);
    })
    .catch(error => {
        console.warn('MLCM: Failed to load static JSON, falling back to AJAX:', error);
        loadLevelDataAjax(level, parentId, callback);
    });
}

/**
 * Fallback AJAX method for getting level data
 */
function loadLevelDataAjax(level, parentId, callback) {
    if (typeof mlcmVars === 'undefined' || !mlcmVars.ajax_url) {
        console.error('MLCM: mlcmVars not defined');
        callback(null);
        return;
    }
    
    jQuery.ajax({
        url: mlcmVars.ajax_url,
        method: 'POST',
        cache: false,
        data: {
            action: 'mlcm_get_subcategories',
            parent_id: parentId || 0,
            _t: new Date().getTime()
        },
        success: (response) => {
            if (response.success && response.data) {
                callback(response.data);
            } else {
                console.error('MLCM: AJAX response error', response);
                callback(null);
            }
        },
        error: (xhr, status, error) => {
            console.error('MLCM: AJAX error', error);
            callback(null);
        }
    });
}

jQuery(function($) {
    const $container = $('.mlcm-container');
    if (!$container.length) return;

    const useStatic = $container.data('use-static') === 1 || $container.data('use-static') === '1';
    const maxLevels = parseInt($container.data('levels')) || 3;
    
    // Load level 1 data immediately on page load
    if (useStatic) {
        loadLevelData(1, 0, function(data) {
            if (data && Array.isArray(data)) {
                populateLevel(1, data);
            }
        });
    }

    // Remove duplicate buttons
    const $buttons = $container.find('.mlcm-go-button');
    if ($buttons.length > 1) {
        $buttons.not(':first').remove();
    }

    // Applying dynamic styles
    const gap = parseInt($container.css('gap'));
    const fontSize = $container.css('font-size');
    if (gap) $container[0].style.setProperty('--mlcm-gap', `${gap}px`);
    if (fontSize) $container[0].style.setProperty('--mlcm-font-size', fontSize);

    // Debounce for select change handling
    let changeTimeout;
    $container.on('change', '.mlcm-select', function() {
        const $select = $(this);
        const level = parseInt($select.data('level'));
        const parentId = parseInt($select.val());
        
        clearTimeout(changeTimeout);
        
        if (parentId === -1) {
            resetLevels(level);
            return;
        }
        
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
                $sel.val('-1').prop('disabled', true).html(`<option value="-1">${mlcmVars.labels[$sel.data('level') - 1]}</option>`);
            }
        });
    }

    // Populate level with data
    function populateLevel(level, categories) {
        const $select = $(`.mlcm-select[data-level="${level}"]`);
        if (!$select.length) return;
        
        const label = mlcmVars.labels[level - 1];
        let options = `<option value="-1">${label}</option>`;
        
        if (Array.isArray(categories)) {
            options += categories.map(cat => {
                const id = cat.id || cat.term_id || '';
                const name = cat.name || '';
                const slug = cat.slug || '';
                const url = cat.url || '';
                return `<option value="${id}" data-slug="${slug}" data-url="${url}">${name}</option>`;
            }).join('');
        } else if (typeof categories === 'object') {
            // Handle object format (indexed by parent_id for level 2+)
            const firstLevel = Object.values(categories)[0];
            if (Array.isArray(firstLevel)) {
                options += firstLevel.map(cat => {
                    const id = cat.id || cat.term_id || '';
                    const name = cat.name || '';
                    const slug = cat.slug || '';
                    const url = cat.url || '';
                    return `<option value="${id}" data-slug="${slug}" data-url="${url}">${name}</option>`;
                }).join('');
            }
        }
        
        $select.prop('disabled', false).html(options);
    }

    // Extract subcategories for specific parent from level data
    function getSubcategoriesForParent(levelData, parentId) {
        if (Array.isArray(levelData)) {
            // Level 1 - flat array
            return levelData;
        } else if (typeof levelData === 'object' && levelData[parentId]) {
            // Level 2+ - indexed by parent_id
            return levelData[parentId];
        }
        return null;
    }

    // Loading subcategories
    function loadSubcategories($select, level, parentId) {
        if (level >= maxLevels) {
            redirectToCategory();
            return;
        }

        // Show loading indicator
        const nextLevel = level + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        if ($nextSelect.length) {
            $nextSelect.prop('disabled', true).html(`<option value="-1">Loading...</option>`);
        }

        // Load next level data
        loadLevelData(nextLevel, parentId, function(data) {
            if (!data) {
                console.error('MLCM: Failed to load level data');
                redirectToCategory();
                return;
            }
            
            const subcats = getSubcategoriesForParent(data, parentId);
            if (subcats && (Array.isArray(subcats) && subcats.length > 0 || typeof subcats === 'object')) {
                updateNextLevel($select, level, subcats);
                // Reset levels after next level
                resetLevels(nextLevel);
            } else {
                redirectToCategory();
            }
        });
    }

    // Next level update
    function updateNextLevel($select, currentLevel, categories) {
        const nextLevel = currentLevel + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        
        if (!$nextSelect.length) return;
        
        const label = mlcmVars.labels[nextLevel - 1];
        const selectId = $nextSelect.attr('id');
        const labelId = `mlcm-label-level-${nextLevel}`;
        
        // Ensure label exists
        let $label = $(`#${labelId}`);
        if (!$label.length) {
            $label = $(`<label for="${selectId}" id="${labelId}" class="mlcm-screen-reader-text">${label}</label>`);
            $nextSelect.before($label);
        } else {
            $label.text(label);
        }
        
        $nextSelect.attr('aria-labelledby', labelId);
        
        let options = '';
        
        if (Array.isArray(categories)) {
            options = categories.map(item => {
                const id = item.id || item.term_id || '';
                const name = item.name || '';
                const slug = item.slug || '';
                const url = item.url || '';
                return `<option value="${id}" data-slug="${slug}" data-url="${url}">${name}</option>`;
            }).join('');
        } else if (typeof categories === 'object' && categories !== null) {
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
            if (url && (url.startsWith('http://') || url.startsWith('https://'))) {
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
