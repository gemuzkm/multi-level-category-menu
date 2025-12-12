/**
 * Get nonce from cache or fetch fresh one
 * Priority: sessionStorage > window.mlcmNonce > fetch from server
 */
function getMlcmNonce(callback) {
    // Если nonce уже есть в кэше, возвращаем его
    const stored = sessionStorage.getItem('mlcm_nonce');
    if (stored) {
        window.mlcmNonce = stored;
        if (typeof callback === 'function') {
            callback(stored);
        }
        return stored;
    }
    
    // Если есть в window переменной
    if (typeof window.mlcmNonce !== 'undefined' && window.mlcmNonce) {
        if (typeof callback === 'function') {
            callback(window.mlcmNonce);
        }
        return window.mlcmNonce;
    }
    
    // Иначе получаем с сервера
    fetchFreshNonce(callback);
    return null;
}

/**
 * Fetch fresh nonce from server
 */
function fetchFreshNonce(callback) {
    if (typeof mlcmVars === 'undefined' || !mlcmVars.ajax_url) {
        console.error('MLCM: mlcmVars not defined');
        if (typeof callback === 'function') {
            callback('');
        }
        return;
    }
    
    jQuery.ajax({
        url: mlcmVars.ajax_url,
        method: 'POST',
        data: {
            action: 'mlcm_get_nonce'
        },
        success: (response) => {
            if (response.success && response.data.nonce) {
                const nonce = response.data.nonce;
                sessionStorage.setItem('mlcm_nonce', nonce);
                window.mlcmNonce = nonce;
                if (typeof callback === 'function') {
                    callback(nonce);
                }
            } else {
                console.error('MLCM: Failed to get nonce');
                if (typeof callback === 'function') {
                    callback('');
                }
            }
        },
        error: () => {
            console.error('MLCM: Error fetching nonce');
            if (typeof callback === 'function') {
                callback('');
            }
        }
    });
}

jQuery(function($) {
    const $container = $('.mlcm-container');
    if (!$container.length) return; // Early exit

    // Получаем nonce при загрузке страницы (ленивая загрузка)
    // Nonce будет получен при первом использовании меню

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

    // Debounce для обработки изменений select
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
        
        // Небольшая задержка для предотвращения множественных запросов
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

    // Показать индикатор загрузки
    function showLoading($select) {
        const $nextSelect = $select.next('.mlcm-level').find('.mlcm-select');
        if ($nextSelect.length) {
            $nextSelect.prop('disabled', true)
                .html('<option value="-1">Loading...</option>');
        }
    }

    // Скрыть индикатор загрузки
    function hideLoading() {
        // Уже обрабатывается в updateNextLevel
    }

    // Loading subcategories (оптимизировано)
    function loadSubcategories($select, level, parentId, retryCount = 0) {
        const maxLevels = $container.data('levels');
        if (level >= maxLevels) {
            redirectToCategory();
            return;
        }

        // Показываем индикатор загрузки
        showLoading($select);

        // Получаем nonce асинхронно
        getMlcmNonce(function(currentNonce) {
            if (!currentNonce) {
                console.error('MLCM: Could not get nonce');
                hideLoading();
                return;
            }

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
                    // Проверяем ошибку nonce и повторяем запрос
                    if (!response.success && response.data && response.data.code === 'invalid_nonce' && response.data.retry) {
                        if (retryCount < 2) {
                            // Очищаем кэш nonce и получаем новый
                            sessionStorage.removeItem('mlcm_nonce');
                            delete window.mlcmNonce;
                            
                            // Повторяем запрос с новым nonce
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
                    
                    // Обрабатываем успешный ответ
                    if (response.success && response.data && Object.keys(response.data).length > 0) {
                        updateNextLevel($select, level, response.data);
                    } else {
                        // Если нет подкатегорий, делаем редирект
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

    // Next level update (оптимизировано)
    // Убрана двойная сортировка - данные уже отсортированы на сервере
    function updateNextLevel($select, currentLevel, categories) {
        const nextLevel = currentLevel + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        
        if (!$nextSelect.length) return;
        
        const label = mlcmVars.labels[nextLevel - 1];
        
        // Данные уже отсортированы на сервере, просто создаем опции
        const options = Object.entries(categories).map(([id, data]) => 
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
            // Валидация URL перед редиректом
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
