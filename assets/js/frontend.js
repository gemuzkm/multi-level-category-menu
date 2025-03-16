jQuery(function($) {
    const container = $('.mlcm-container');

    // Удаляем дубликаты кнопок
    container.each(function() {
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
        $cont[0].style.setProperty('--mlcm-gap', `${gap}px`);
        $cont[0].style.setProperty('--mlcm-font-size', fontSize);
    });

    // Обработчик изменений в селектах
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
        
        // Сохраняем slug для выбранной категории
        $select.data('selected-slug', slug);
        
        loadSubcategories($select, level, parentId);
    });

    // Обработчик клика по кнопке
    container.on('click', '.mlcm-go-button', redirectToCategory);

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
            redirectToCategory();
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
                    if (Object.keys(response.data).length > 0) {
                        updateNextLevel($select, level, response.data);
                    } else {
                        redirectToCategory();
                    }
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
                .sort((a, b) => a[1].name.localeCompare(b[1].name, undefined, { sensitivity: 'base' }));
            
            $nextSelect.prop('disabled', false)
                .html(`<option value="-1">${label}</option>` + 
                    sortedEntries.map(([id, data]) => 
                        `<option value="${id}" data-slug="${data.slug}">${data.name}</option>`).join(''));
        } else {
            redirectToCategory();
        }
    }

    // Получение полного пути выбранных категорий
    function getSelectedPath() {
        const selectedSlugs = [];
        container.find('.mlcm-select').each(function() {
            const val = $(this).val();
            if (val !== '-1') {
                const slug = $(this).find('option:selected').data('slug');
                if (slug) {
                    selectedSlugs.push(slug);
                }
            } else {
                return false; // Прерываем цикл на первом невыбранном уровне
            }
        });
        return selectedSlugs.join('/');
    }

    // Перенаправление на страницу категории
    function redirectToCategory() {
        const path = getSelectedPath();
        if (path) {
            const base = mlcmVars.use_category_base ? '/category/' : '/';
            window.location = `${base}${path}/`;
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

    handleMobileLayout();
    $(window).on('resize', handleMobileLayout);
});