jQuery(function($) {
    'use strict';
    
    const container = $('.mlcm-container');
    
    // Кеширование DOM элементов для оптимизации
    const cache = {
        containers: container,
        buttons: container.find('.mlcm-go-button'),
        selects: container.find('.mlcm-select')
    };
    
    // Дебаунс для оптимизации событий
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    // ДОБАВЛЕНО: Кеш для AJAX запросов с временными метками
    const ajaxCache = new Map();
    const CACHE_DURATION = 30 * 60 * 1000; // 30 минут в миллисекундах
    
    // Очистка устаревшего кеша
    function cleanExpiredCache() {
        const now = Date.now();
        for (let [key, value] of ajaxCache.entries()) {
            if (now - value.timestamp > CACHE_DURATION) {
                ajaxCache.delete(key);
            }
        }
    }
    
    // Удаление дублированных кнопок
    cache.containers.each(function() {
        const $buttons = $(this).find('.mlcm-go-button');
        if ($buttons.length > 1) {
            $buttons.slice(1).remove();
        }
    });
    
    // Применение динамических стилей
    cache.containers.each(function() {
        const $cont = $(this);
        const gap = parseInt($cont.css('gap')) || 20;
        const fontSize = $cont.css('font-size');
        
        $cont[0].style.setProperty('--mlcm-gap', `${gap}px`);
        $cont[0].style.setProperty('--mlcm-font-size', fontSize);
    });
    
    // ОПТИМИЗИРОВАННЫЙ: Обработчик изменений в селектах с ленивой загрузкой
    cache.containers.on('change', '.mlcm-select', debounce(function() {
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
        
        // УЛУЧШЕНО: Ленивая загрузка подкategорий
        lazyLoadSubcategories($select, level, parentId);
    }, 150));
    
    // Обработчик нажатий на кнопку
    cache.containers.on('click', '.mlcm-go-button', redirectToCategory);
    
    // Сброс следующих уровней
    function resetLevels(currentLevel) {
        cache.containers.find('.mlcm-select').each(function() {
            const $this = $(this);
            if ($this.data('level') > currentLevel) {
                $this.val('-1').prop('disabled', true).removeClass('mlcm-loading');
                $this.removeData('selected-slug');
                
                // Очищаем опции кроме первой
                $this.find('option:not(:first)').remove();
            }
        });
    }
    
    /**
     * НОВОЕ: Ленивая загрузка подкategорий с улучшенным кешированием
     */
    function lazyLoadSubcategories($select, level, parentId) {
        const maxLevels = cache.containers.data('levels');
        
        if (level >= maxLevels) {
            redirectToCategory();
            return;
        }
        
        const cacheKey = `mlcm_lazy_${parentId}`;
        
        // Очистка устаревшего кеша
        cleanExpiredCache();
        
        // Проверяем кеш
        if (ajaxCache.has(cacheKey)) {
            const cached = ajaxCache.get(cacheKey);
            if (Date.now() - cached.timestamp < CACHE_DURATION) {
                processSubcategories($select, level, cached.data);
                return;
            }
        }
        
        // Показываем индикатор загрузки
        $select.addClass('mlcm-loading');
        
        // ОПТИМИЗИРОВАНО: AJAX запрос для ленивой загрузки
        $.ajax({
            url: mlcmVars.ajax_url,
            method: 'POST',
            data: {
                action: 'mlcm_get_subcategories',
                parent_id: parentId,
                security: mlcmVars.nonce
            },
            timeout: 10000, // 10 секунд таймаут
            beforeSend: () => {
                $select.nextAll('.mlcm-select').val('-1').prop('disabled', true);
            },
            success: (response) => {
                $select.removeClass('mlcm-loading');
                
                if (response.success) {
                    // Кешируем ответ
                    ajaxCache.set(cacheKey, {
                        data: response.data,
                        timestamp: Date.now()
                    });
                    
                    processSubcategories($select, level, response.data);
                } else {
                    console.error('MLCM Ajax Error:', response.data);
                    showErrorState($select);
                }
            },
            error: (xhr, status, error) => {
                $select.removeClass('mlcm-loading');
                console.error('MLCM Ajax Error:', {xhr, status, error});
                showErrorState($select);
                
                // Повторная попытка для критических ошибок
                if (xhr.status === 0 || xhr.status >= 500) {
                    setTimeout(() => {
                        if (confirm('Connection error. Retry loading subcategories?')) {
                            lazyLoadSubcategories($select, level, parentId);
                        }
                    }, 1000);
                }
            }
        });
    }
    
    // ДОБАВЛЕНО: Обработка состояния ошибки
    function showErrorState($select) {
        const level = $select.data('level');
        const nextLevel = level + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        
        if ($nextSelect.length > 0) {
            $nextSelect.html(`
                <option value="-1">⚠ Error loading categories</option>
                <option value="retry">🔄 Click to retry</option>
            `).prop('disabled', false);
            
            // Обработчик повторной попытки
            $nextSelect.off('change.retry').on('change.retry', function() {
                if ($(this).val() === 'retry') {
                    const parentId = $select.val();
                    if (parentId !== '-1') {
                        $(this).val('-1').prop('disabled', true);
                        lazyLoadSubcategories($select, level, parentId);
                    }
                }
            });
        }
    }
    
    // Обработка подкategорий
    function processSubcategories($select, level, categories) {
        if (Object.keys(categories).length > 0) {
            updateNextLevel($select, level, categories);
        } else {
            // Если нет подкategорий, переходим к выбранной категории
            redirectToCategory();
        }
    }
    
    // УЛУЧШЕНО: Обновление следующего уровня с оптимизацией
    function updateNextLevel($select, currentLevel, categories) {
        const nextLevel = currentLevel + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        
        if ($nextSelect.length === 0) {
            redirectToCategory();
            return;
        }
        
        if (Object.keys(categories).length > 0) {
            const label = mlcmVars.labels[nextLevel-1] || `Level ${nextLevel}`;
            
            // ОПТИМИЗИРОВАНО: Сортировка с поддержкой Unicode и кеширование
            const sortedEntries = Object.entries(categories)
                .sort(([,a], [,b]) => {
                    // Используем Intl.Collator для правильной сортировки Unicode
                    const collator = new Intl.Collator(undefined, { 
                        sensitivity: 'base',
                        numeric: true,
                        caseFirst: 'upper'
                    });
                    return collator.compare(a.name, b.name);
                });
            
            // Создаем options более эффективно через DocumentFragment
            const fragment = document.createDocumentFragment();
            
            // Добавляем placeholder option
            const placeholderOption = document.createElement('option');
            placeholderOption.value = '-1';
            placeholderOption.textContent = label;
            fragment.appendChild(placeholderOption);
            
            // Добавляем категории
            sortedEntries.forEach(([id, data]) => {
                const option = document.createElement('option');
                option.value = id;
                option.setAttribute('data-slug', data.slug);
                option.setAttribute('data-url', data.url);
                option.textContent = data.name;
                fragment.appendChild(option);
            });
            
            // Обновляем select одним действием
            $nextSelect[0].innerHTML = '';
            $nextSelect[0].appendChild(fragment);
            
            $nextSelect.prop('disabled', false).focus();
            
            // ДОБАВЛЕНО: Плавная анимация появления
            $nextSelect.addClass('mlcm-loaded').removeClass('mlcm-loading');
                
        } else {
            redirectToCategory();
        }
    }
    
    // Переадресация на страницу категории
    function redirectToCategory() {
        const $lastSelect = cache.containers.find('.mlcm-select').filter(function() {
            return $(this).val() !== '-1';
        }).last();
        
        if ($lastSelect.length) {
            const selectedOption = $lastSelect.find('option:selected');
            const url = selectedOption.data('url');
            if (url) {
                // ДОБАВЛЕНО: Показываем индикатор загрузки перед переходом
                $lastSelect.addClass('mlcm-redirecting');
                window.location.href = url;
            }
        }
    }
    
    // УЛУЧШЕНО: Адаптация для мобильных устройств
    function handleMobileLayout() {
        const isMobile = window.matchMedia('(max-width: 768px)').matches;
        
        cache.containers.toggleClass('mobile-layout', isMobile);
        
        if (isMobile) {
            // Адаптивные стили для мобильных
            cache.containers.find('.mlcm-go-button').css({
                'width': '100%',
                'margin': '10px 0 0 0'
            });
            
            // Увеличиваем область касания на мобильных
            cache.containers.find('.mlcm-select').css({
                'min-height': '44px',
                'font-size': 'clamp(14px, 4vw, 18px)'
            });
        }
    }
    
    // Оптимизированный обработчик resize
    const debouncedResize = debounce(handleMobileLayout, 200);
    
    // Инициализация
    handleMobileLayout();
    $(window).on('resize', debouncedResize);
    
    // ДОБАВЛЕНО: Очистка кеша при выходе/перезагрузке
    $(window).on('beforeunload', function() {
        // Очищаем только старые данные
        cleanExpiredCache();
        
        // Сохраняем статистику использования кеша
        if (ajaxCache.size > 0) {
            sessionStorage.setItem('mlcm_cache_stats', JSON.stringify({
                size: ajaxCache.size,
                timestamp: Date.now()
            }));
        }
    });
    
    // УЛУЧШЕНО: Accessibility улучшения
    cache.selects.on('focus', function() {
        $(this).parent().addClass('mlcm-focused');
        
        // Показываем подсказку для screen readers
        const level = $(this).data('level');
        $(this).attr('aria-label', `Category selector level ${level}`);
    }).on('blur', function() {
        $(this).parent().removeClass('mlcm-focused');
    });
    
    // ДОБАВЛЕНО: Keyboard navigation improvements
    cache.containers.on('keydown', '.mlcm-select', function(e) {
        const $this = $(this);
        
        if (e.key === 'Enter' && $this.val() !== '-1') {
            e.preventDefault();
            const level = $this.data('level');
            const parentId = $this.val();
            lazyLoadSubcategories($this, level, parentId);
        }
        
        // Быстрое перемещение между уровнями с помощью Tab
        if (e.key === 'Tab') {
            const currentLevel = $this.data('level');
            const nextSelect = $(`.mlcm-select[data-level="${currentLevel + 1}"]`);
            
            if (nextSelect.length && !nextSelect.prop('disabled')) {
                e.preventDefault();
                nextSelect.focus();
            }
        }
    });
    
    // ДОБАВЛЕНО: Индикатор прогресса для длительных операций
    let progressIndicator = null;
    
    function showProgress(message = 'Loading...') {
        if (!progressIndicator) {
            progressIndicator = $(`
                <div class="mlcm-progress-indicator" style="
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: rgba(0,0,0,0.8);
                    color: white;
                    padding: 15px 20px;
                    border-radius: 5px;
                    z-index: 10000;
                    font-size: 14px;
                ">
                    <div class="spinner" style="
                        width: 20px;
                        height: 20px;
                        border: 2px solid #fff;
                        border-top: 2px solid transparent;
                        border-radius: 50%;
                        animation: spin 1s linear infinite;
                        display: inline-block;
                        margin-right: 10px;
                    "></div>
                    <span>${message}</span>
                </div>
            `);
            $('body').append(progressIndicator);
        }
    }
    
    function hideProgress() {
        if (progressIndicator) {
            progressIndicator.remove();
            progressIndicator = null;
        }
    }
    
    // Показываем прогресс для медленных AJAX запросов
    let progressTimeout;
    $(document).ajaxStart(function() {
        progressTimeout = setTimeout(() => showProgress('Loading categories...'), 1000);
    }).ajaxStop(function() {
        clearTimeout(progressTimeout);
        hideProgress();
    });
    
    // ДОБАВЛЕНО: Восстановление состояния после ошибок сети
    $(window).on('online', function() {
        // Очищаем кеш и перезагружаем данные при восстановлении соединения
        ajaxCache.clear();
        cache.containers.find('.mlcm-select[data-level="1"]').trigger('change');
    });
    
    // Логирование производительности для отладки
    if (typeof console !== 'undefined' && console.time) {
        console.time('MLCM Frontend Init');
        $(window).on('load', function() {
            console.timeEnd('MLCM Frontend Init');
            console.log('MLCM: Frontend initialized successfully');
        });
    }
});