jQuery(function($) {
    const container = $('.mlcm-container');
    const maxLevels = container.data('levels');
    
    container.on('change', '.mlcm-select', function() {
        const $select = $(this);
        const level = $select.data('level');
        const parentId = $select.val();
        
        if (parentId === '-1') {
            resetLevels(level);
            return;
        }
        
        loadSubcategories($select, level, parentId);
    });

    function resetLevels(currentLevel) {
        container.find('.mlcm-select').each(function() {
            if ($(this).data('level') > currentLevel) {
                $(this).val('-1').prop('disabled', true);
            }
        });
    }

    function loadSubcategories($select, level, parentId) {
        if (level >= maxLevels) {
            window.location = `/?cat=${parentId}`;
            return;
        }

        $.ajax({
            url: mlcmVars.ajax_url,
            method: 'POST',
            data: {
                action: 'mlcm_get_subcategories',
                parent_id: parentId,
                security: mlcmVars.nonce
            },
            beforeSend: () => {
                $select.nextAll('.mlcm-select').val('-1').prop('disabled', true);
            },
            success: (response) => {
                if (response.success) {
                    updateNextLevel($select, level, response.data);
                }
            }
        });
    }

    function updateNextLevel($select, currentLevel, categories) {
        const nextLevel = currentLevel + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        
        if (Object.keys(categories).length > 0) {
            const label = mlcmVars.labels[nextLevel-1];
            $nextSelect.prop('disabled', false)
                       .html(`<option value="-1">${label}</option>` + 
                             Object.entries(categories).map(([id, name]) => 
                               `<option value="${id}">${name}</option>`).join(''));
        } else {
            window.location = `/?cat=${$select.val()}`;
        }
    }
});