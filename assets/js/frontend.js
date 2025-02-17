jQuery(function($) {
    const $containers = $('.mlcm-container');
    
    // Удаляем дубликаты кнопок
    $containers.each(function() {
        const $buttons = $(this).find('.mlcm-go-button');
        if ($buttons.length > 1) {
            $buttons.slice(1).remove();
        }
    });
    
    // Применение динамических стилей
    container.each(function() {
        const $cont = $(this);
        const gap = parseInt($cont.css('gap')) || 20;
        const fontSize = $cont.css('font-size');
        
        // Установка CSS-переменных
        $cont[0].style.setProperty('--mlcm-gap', `${gap}px`);
        $cont[0].style.setProperty('--mlcm-font-size', fontSize);
    });

    // Обработчик изменений в селектах
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

    // Обработчик клика по кнопке
    container.on('click', '.mlcm-go-button', function() {
        const selected = container.find('.mlcm-select:enabled').filter(function() {
            return $(this).val() !== '-1';
        });
        
        if (selected.length === 0) return;
        
        const lastSelected = selected.last();
        const catId = lastSelected.val();
        window.location = `/?cat=${catId}`;
    });

    // Сброс последующих уровней
    function resetLevels(currentLevel) {
        container.find('.mlcm-select').each(function() {
            if ($(this).data('level') > currentLevel) {
                $(this).val('-1').prop('disabled', true);
            }
        });
    }

    // Загрузка подкатегорий
    function loadSubcategories($select, level, parentId) {
        const maxLevels = container.data('levels');
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

    // Обновление следующего уровня
    function updateNextLevel($select, currentLevel, categories) {
        const nextLevel = currentLevel + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        
        if (Object.keys(categories).length > 0) {
            const label = mlcmVars.labels[nextLevel-1];
            const sortedEntries = Object.entries(categories)
                .sort((a, b) => a[1].localeCompare(b[1], undefined, { sensitivity: 'base' }));
            
            $nextSelect.prop('disabled', false)
                .html(`<option value="-1">${label}</option>` + 
                    sortedEntries.map(([id, name]) => 
                        `<option value="${id}">${name}</option>`).join(''));
        } else {
            window.location = `/?cat=${$select.val()}`;
        }
    }

    // Адаптация для мобильных устройств
    function handleMobileLayout() {
        if (window.matchMedia('(max-width: 768px)').matches) {
            container.find('.mlcm-go-button').css({
                'width': '100%',
                'margin': '10px 0 0 0'
            });
        }
    }

    // Инициализация адаптива
    handleMobileLayout();
    $(window).on('resize', handleMobileLayout);
});