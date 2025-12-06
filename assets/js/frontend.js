/**
 * Get nonce from data attribute, sessionStorage, or fallback
 * Priority: data-nonce > sessionStorage > window.mlcmNonce > mlcmVars.nonce
 */
function getMlcmNonce() {
    // ПРИОРИТЕТ 1: data-nonce в контейнере (самый надёжный)
    const $container = document.querySelector('.mlcm-container');
    if ($container && $container.dataset.nonce) {
        sessionStorage.setItem('mlcm_nonce', $container.dataset.nonce);
        window.mlcmNonce = $container.dataset.nonce;
        return $container.dataset.nonce;
    }
    
    // ПРИОРИТЕТ 2: sessionStorage (сохранено из data-nonce)
    const stored = sessionStorage.getItem('mlcm_nonce');
    if (stored) {
        window.mlcmNonce = stored;
        return stored;
    }
    
    // ПРИОРИТЕТ 3: window переменная (установлено ранее)
    if (typeof window.mlcmNonce !== 'undefined' && window.mlcmNonce) {
        return window.mlcmNonce;
    }
    
    // ПРИОРИТЕТ 4: mlcmVars.nonce (fallback)
    return (typeof mlcmVars !== 'undefined' ? mlcmVars.nonce : '');
}

jQuery(function($) {
    const $container = $('.mlcm-container');
    if (!$container.length) return; // Early exit

    // Сохраняем nonce в sessionStorage при загрузке
    const dataNonce = $container.attr('data-nonce');
    if (dataNonce) {
        sessionStorage.setItem('mlcm_nonce', dataNonce);
        window.mlcmNonce = dataNonce;
    }

    // Remove duplicate buttons (оптимизировано)
    const $buttons = $container.find('.mlcm-go-button');
    if ($buttons.length > 1) {
        $buttons.not(':first').remove();
    }

    // Applying dynamic styles (только если нужны)
    const gap = parseInt($container.css('gap'));
    const fontSize = $container.css('font-size');
    if (gap) $container[0].style.setProperty('--mlcm-gap', `${gap}px`);
    if (fontSize) $container[0].style.setProperty('--mlcm-font-size', fontSize);

    // Handler of changes in selections
    $container.on('change', '.mlcm-select', function() {
        const $select = $(this);
        const level = $select.data('level');
        const $selectedOption = $select.find('option:selected');
        const parentId = $selectedOption.val();
        
        if (parentId === '-1') {
            resetLevels(level);
            return;
        }
        
        loadSubcategories($select, level, parentId);
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

    // Loading subcategories (оптимизировано)
    function loadSubcategories($select, level, parentId) {
        const maxLevels = $container.data('levels');
        if (level >= maxLevels) {
            redirectToCategory();
            return;
        }

        const currentNonce = getMlcmNonce();

        $.ajax({
            url: mlcmVars.ajax_url,
            method: 'POST',
            data: {
                action: 'mlcm_get_subcategories',
                parent_id: parentId,
                security: currentNonce
            },
            beforeSend: () => {
                $select.nextAll('.mlcm-select').val('-1').prop('disabled', true);
            },
            success: (response) => {
                if (response.success && Object.keys(response.data).length > 0) {
                    updateNextLevel($select, level, response.data);
                } else {
                    redirectToCategory();
                }
            },
            error: () => {
                console.error('MLCM: Failed to load subcategories');
            }
        });
    }

    // Next level update (оптимизировано)
    function updateNextLevel($select, currentLevel, categories) {
        const nextLevel = currentLevel + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        
        if (!$nextSelect.length) return;
        
        const label = mlcmVars.labels[nextLevel - 1];
        const sortedEntries = Object.entries(categories)
            .sort((a, b) => a[1].name.localeCompare(b[1].name, undefined, { sensitivity: 'base' }));
        
        const options = sortedEntries.map(([id, data]) => 
            `<option value="${id}" data-slug="${data.slug}" data-url="${data.url}">${data.name}</option>`
        ).join('');
        
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
            if (url) window.location.href = url;
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
