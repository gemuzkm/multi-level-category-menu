/**
 * Get fresh nonce from cookie
 */
function getMlcmNonce() {
    const match = document.cookie.match(/mlcm_nonce=([^;]+)/);
    return match ? match[1] : (typeof mlcmVars !== 'undefined' ? mlcmVars.nonce : '');
}

jQuery(function($) {
    const container = $('.mlcm-container');

    // Remove duplicate buttons
    container.each(function() {
        const $buttons = $(this).find('.mlcm-go-button');
        if ($buttons.length > 1) {
            $buttons.slice(1).remove();
        }
    });

    // Applying dynamic styles
    container.each(function() {
        const $cont = $(this);
        const gap = parseInt($cont.css('gap')) || 20;
        const fontSize = $cont.css('font-size');
        $cont[0].style.setProperty('--mlcm-gap', `${gap}px`);
        $cont[0].style.setProperty('--mlcm-font-size', fontSize);
    });

    // Handler of changes in selections
    container.on('change', '.mlcm-select', function() {
        const $select = $(this);
        const level = $select.data('level');
        const selectedOption = $select.find('option:selected');
        const parentId = selectedOption.val();
        const slug = selectedOption.data('slug');
        
        if (parentId === '-1') {
            resetLevels(level);
            return;
        }
        
        // Save the slug for the selected category
        $select.data('selected-slug', slug);
        
        loadSubcategories($select, level, parentId);
    });

    // Button click handler
    container.on('click', '.mlcm-go-button', redirectToCategory);

    // Reset the next levels
    function resetLevels(currentLevel) {
        container.find('.mlcm-select').each(function() {
            if ($(this).data('level') > currentLevel) {
                $(this).val('-1').prop('disabled', true);
            }
        });
    }

    // Loading subcategories
    function loadSubcategories($select, level, parentId) {
        const maxLevels = container.data('levels');
        if (level >= maxLevels) {
            redirectToCategory();
            return;
        }

        $.ajax({
            url: mlcmVars.ajax_url,
            method: 'POST',
            data: {
                action: 'mlcm_get_subcategories',
                parent_id: parentId,
                security: getMlcmNonce() // Use nonce from cookie
            },
            beforeSend: () => {
                $select.nextAll('.mlcm-select').val('-1').prop('disabled', true);
            },
            success: (response) => {
                if (response.success) {
                    if (Object.keys(response.data).length > 0) {
                        updateNextLevel($select, level, response.data);
                    } else {
                        redirectToCategory();
                    }
                }
            }
        });
    }

    // Next level update
    function updateNextLevel($select, currentLevel, categories) {
        const nextLevel = currentLevel + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        
        if (Object.keys(categories).length > 0) {
            const label = mlcmVars.labels[nextLevel-1];
            const sortedEntries = Object.entries(categories)
                .sort((a, b) => a[1].name.localeCompare(b[1].name, undefined, { sensitivity: 'base' }));
            
            $nextSelect.prop('disabled', false)
                .html(`<option value="-1">${label}</option>` + 
                    sortedEntries.map(([id, data]) => 
                        `<option value="${id}" data-slug="${data.slug}" data-url="${data.url}">${data.name}</option>`).join(''));
        } else {
            redirectToCategory();
        }
    }

    // Redirect to category page
    function redirectToCategory() {
        const $lastSelect = container.find('.mlcm-select').filter(function() {
            return $(this).val() !== '-1';
        }).last();
        
        if ($lastSelect.length) {
            const url = $lastSelect.find('option:selected').data('url');
            if (url) {
                window.location = url;
            }
        }
    }

    // Adaptation for mobile devices
    function handleMobileLayout() {
        if (window.matchMedia('(max-width: 768px)').matches) {
            container.find('.mlcm-go-button').css({
                'width': '100%',
                'margin': '10px 0 0 0'
            });
        }
    }

    handleMobileLayout();
    $(window).on('resize', handleMobileLayout);
});