/**
 * Multi-Level Category Menu Frontend JavaScript
 * 
 * Handles dynamic menu functionality including AJAX loading, caching,
 * mobile responsiveness, and accessibility features.
 * 
 * @package Multi_Level_Category_Menu
 * @version 3.6
 * @author Name
 */

jQuery(function($) {
    'use strict';
    
    // Main container reference
    const container = $('.mlcm-container');
    
    /**
     * DOM element cache for performance optimization
     * Caches frequently accessed elements to avoid repeated jQuery selections
     */
    const cache = {
        containers: container,
        buttons: container.find('.mlcm-go-button'),
        selects: container.find('.mlcm-select')
    };
    
    /**
     * Debounce utility function
     * 
     * Limits the rate at which a function can fire to improve performance
     * and prevent excessive API calls during rapid user interactions.
     * 
     * @param {Function} func - Function to debounce
     * @param {number} wait - Wait time in milliseconds
     * @returns {Function} Debounced function
     */
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
    
    /**
     * AJAX request cache with timestamps
     * 
     * Caches AJAX responses to prevent duplicate requests and improve
     * performance. Includes automatic expiration based on timestamps.
     */
    const ajaxCache = new Map();
    const CACHE_DURATION = 30 * 60 * 1000; // 30 minutes in milliseconds
    
    /**
     * Clean expired cache entries
     * 
     * Removes cache entries older than CACHE_DURATION to prevent
     * memory leaks and stale data usage.
     */
    function cleanExpiredCache() {
        const now = Date.now();
        for (let [key, value] of ajaxCache.entries()) {
            if (now - value.timestamp > CACHE_DURATION) {
                ajaxCache.delete(key);
            }
        }
    }
    
    /**
     * Initialize plugin and remove duplicate elements
     * 
     * Removes duplicate Go buttons that may appear due to widget/shortcode conflicts
     * and applies dynamic styling based on container properties.
     */
    
    // Remove duplicate Go buttons (fix for widget conflicts)
    cache.containers.each(function() {
        const $buttons = $(this).find('.mlcm-go-button');
        if ($buttons.length > 1) {
            $buttons.slice(1).remove(); // Keep first, remove duplicates
        }
    });
    
    // Apply dynamic CSS custom properties from container styles
    cache.containers.each(function() {
        const $cont = $(this);
        const gap = parseInt($cont.css('gap')) || 20;
        const fontSize = $cont.css('font-size');
        
        // Set CSS custom properties for consistent styling
        $cont[0].style.setProperty('--mlcm-gap', `${gap}px`);
        $cont[0].style.setProperty('--mlcm-font-size', fontSize);
    });
    
    /**
     * Main select change handler with debouncing
     * 
     * Handles category selection events, processes user choices,
     * and initiates lazy loading of subcategories. Uses debouncing
     * to prevent excessive API calls during rapid selections.
     */
    cache.containers.on('change', '.mlcm-select', debounce(function() {
        const $select = $(this);
        const level = $select.data('level');
        const selectedOption = $select.find('option:selected');
        const parentId = selectedOption.val();
        const slug = selectedOption.data('slug');
        
        // Handle "no selection" option
        if (parentId === '-1') {
            resetLevels(level);
            return;
        }
        
        // Store slug for the selected category
        $select.data('selected-slug', slug);
        
        // Trigger lazy loading of subcategories
        lazyLoadSubcategories($select, level, parentId);
    }, 150)); // 150ms debounce delay
    
    /**
     * Go button click handler
     * 
     * Handles navigation button clicks to redirect to selected category page.
     */
    cache.containers.on('click', '.mlcm-go-button', redirectToCategory);
    
    /**
     * Reset subsequent menu levels
     * 
     * Clears and disables all menu levels after the specified current level
     * when user makes a new selection or selects "no option".
     * 
     * @param {number} currentLevel - Current menu level (1-5)
     */
    function resetLevels(currentLevel) {
        cache.containers.find('.mlcm-select').each(function() {
            const $this = $(this);
            if ($this.data('level') > currentLevel) {
                $this.val('-1').prop('disabled', true).removeClass('mlcm-loading');
                $this.removeData('selected-slug');
                
                // Clear options except the first (placeholder) option
                $this.find('option:not(:first)').remove();
            }
        });
    }
    
    /**
     * Lazy load subcategories with caching
     * 
     * Dynamically loads subcategories for selected parent category using AJAX.
     * Implements intelligent caching to prevent duplicate requests and improve
     * performance. Handles loading states and error conditions gracefully.
     * 
     * @param {jQuery} $select - Current select element
     * @param {number} level - Current menu level
     * @param {number} parentId - Parent category ID
     */
    function lazyLoadSubcategories($select, level, parentId) {
        const maxLevels = cache.containers.data('levels');
        
        // If at max level, redirect immediately
        if (level >= maxLevels) {
            redirectToCategory();
            return;
        }
        
        const cacheKey = `mlcm_lazy_${parentId}`;
        
        // Clean expired cache entries
        cleanExpiredCache();
        
        // Check cache first
        if (ajaxCache.has(cacheKey)) {
            const cached = ajaxCache.get(cacheKey);
            if (Date.now() - cached.timestamp < CACHE_DURATION) {
                processSubcategories($select, level, cached.data);
                return;
            }
        }
        
        // Show loading indicator
        $select.addClass('mlcm-loading');
        
        /**
         * AJAX request for subcategories
         * 
         * Sends AJAX request to WordPress backend to fetch subcategories
         * for the selected parent category with error handling and retry logic.
         */
        $.ajax({
            url: mlcmVars.ajax_url,
            method: 'POST',
            data: {
                action: 'mlcm_get_subcategories',
                parent_id: parentId,
                security: mlcmVars.nonce
            },
            timeout: 10000, // 10 second timeout
            beforeSend: () => {
                // Disable subsequent levels during loading
                $select.nextAll('.mlcm-select').val('-1').prop('disabled', true);
            },
            success: (response) => {
                $select.removeClass('mlcm-loading');
                
                if (response.success) {
                    // Cache successful response
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
                
                // Retry logic for critical errors
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
    
    /**
     * Display error state in next select level
     * 
     * Shows user-friendly error message with retry option when
     * AJAX request fails. Provides graceful error recovery.
     * 
     * @param {jQuery} $select - Current select element that failed
     */
    function showErrorState($select) {
        const level = $select.data('level');
        const nextLevel = level + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        
        if ($nextSelect.length > 0) {
            $nextSelect.html(`
                <option value="-1">⚠ Error loading categories</option>
                <option value="retry">🔄 Click to retry</option>
            `).prop('disabled', false);
            
            // Handle retry selection
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
    
    /**
     * Process loaded subcategories
     * 
     * Handles successful subcategory loading by either updating the next
     * level select element or redirecting if no subcategories exist.
     * 
     * @param {jQuery} $select - Current select element
     * @param {number} level - Current menu level
     * @param {Object} categories - Category data from AJAX response
     */
    function processSubcategories($select, level, categories) {
        if (Object.keys(categories).length > 0) {
            updateNextLevel($select, level, categories);
        } else {
            // No subcategories found, redirect to current selection
            redirectToCategory();
        }
    }
    
    /**
     * Update next menu level with subcategories
     * 
     * Populates the next select element with subcategory options,
     * applying proper sorting and creating optimized DOM elements.
     * 
     * @param {jQuery} $select - Current select element
     * @param {number} currentLevel - Current menu level
     * @param {Object} categories - Category data to populate
     */
    function updateNextLevel($select, currentLevel, categories) {
        const nextLevel = currentLevel + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        
        if ($nextSelect.length === 0) {
            redirectToCategory();
            return;
        }
        
        if (Object.keys(categories).length > 0) {
            const label = mlcmVars.labels[nextLevel-1] || `Level ${nextLevel}`;
            
            /**
             * Sort categories with Unicode support
             * 
             * Uses Intl.Collator for proper sorting of international characters
             * and mixed case text, ensuring consistent alphabetical order.
             */
            const sortedEntries = Object.entries(categories)
                .sort(([,a], [,b]) => {
                    const collator = new Intl.Collator(undefined, { 
                        sensitivity: 'base',
                        numeric: true,
                        caseFirst: 'upper'
                    });
                    return collator.compare(a.name, b.name);
                });
            
            // Create options efficiently using DocumentFragment
            const fragment = document.createDocumentFragment();
            
            // Add placeholder option
            const placeholderOption = document.createElement('option');
            placeholderOption.value = '-1';
            placeholderOption.textContent = label;
            fragment.appendChild(placeholderOption);
            
            // Add category options
            sortedEntries.forEach(([id, data]) => {
                const option = document.createElement('option');
                option.value = id;
                option.setAttribute('data-slug', data.slug);
                option.setAttribute('data-url', data.url);
                option.textContent = data.name;
                fragment.appendChild(option);
            });
            
            // Update select element in single DOM operation
            $nextSelect[0].innerHTML = '';
            $nextSelect[0].appendChild(fragment);
            
            // Enable and focus the next select
            $nextSelect.prop('disabled', false).focus();
            
            // Add smooth appearance animation
            $nextSelect.addClass('mlcm-loaded').removeClass('mlcm-loading');
            
        } else {
            redirectToCategory();
        }
    }
    
    /**
     * Redirect to selected category page
     * 
     * Finds the last selected category and redirects the browser to its page.
     * Shows loading indicator during navigation for better user experience.
     */
    function redirectToCategory() {
        const $lastSelect = cache.containers.find('.mlcm-select').filter(function() {
            return $(this).val() !== '-1';
        }).last();
        
        if ($lastSelect.length) {
            const selectedOption = $lastSelect.find('option:selected');
            const url = selectedOption.data('url');
            if (url) {
                // Show redirect indicator
                $lastSelect.addClass('mlcm-redirecting');
                window.location.href = url;
            }
        }
    }
    
    /**
     * Handle mobile layout adaptation
     * 
     * Detects mobile viewport and applies appropriate styling and behavior
     * for touch devices. Improves usability on small screens.
     */
    function handleMobileLayout() {
        const isMobile = window.matchMedia('(max-width: 768px)').matches;
        
        cache.containers.toggleClass('mobile-layout', isMobile);
        
        if (isMobile) {
            // Mobile-specific button styling
            cache.containers.find('.mlcm-go-button').css({
                'width': '100%',
                'margin': '10px 0 0 0'
            });
            
            // Increase touch target size for selects
            cache.containers.find('.mlcm-select').css({
                'min-height': '44px', // iOS recommended touch target
                'font-size': 'clamp(14px, 4vw, 18px)' // Responsive font size
            });
        }
    }
    
    /**
     * Debounced resize handler
     * 
     * Handles window resize events with debouncing to prevent excessive
     * layout recalculations during window resizing.
     */
    const debouncedResize = debounce(handleMobileLayout, 200);
    
    // Initialize mobile layout and attach resize handler
    handleMobileLayout();
    $(window).on('resize', debouncedResize);
    
    /**
     * Cache cleanup on page unload
     * 
     * Performs cache maintenance before page unload to prevent memory
     * leaks and saves cache statistics for debugging.
     */
    $(window).on('beforeunload', function() {
        // Clean expired cache entries
        cleanExpiredCache();
        
        // Save cache statistics for debugging
        if (ajaxCache.size > 0) {
            sessionStorage.setItem('mlcm_cache_stats', JSON.stringify({
                size: ajaxCache.size,
                timestamp: Date.now()
            }));
        }
    });
    
    /**
     * Accessibility enhancements
     * 
     * Improves keyboard navigation and screen reader support by adding
     * proper focus states and ARIA labels dynamically.
     */
    cache.selects.on('focus', function() {
        $(this).parent().addClass('mlcm-focused');
        
        // Add descriptive ARIA label for screen readers
        const level = $(this).data('level');
        $(this).attr('aria-label', `Category selector level ${level}`);
    }).on('blur', function() {
        $(this).parent().removeClass('mlcm-focused');
    });
    
    /**
     * Enhanced keyboard navigation
     * 
     * Provides keyboard shortcuts and improved navigation between
     * menu levels using Enter and Tab keys.
     */
    cache.containers.on('keydown', '.mlcm-select', function(e) {
        const $this = $(this);
        
        // Enter key triggers subcategory loading
        if (e.key === 'Enter' && $this.val() !== '-1') {
            e.preventDefault();
            const level = $this.data('level');
            const parentId = $this.val();
            lazyLoadSubcategories($this, level, parentId);
        }
        
        // Tab key jumps to next available level
        if (e.key === 'Tab') {
            const currentLevel = $this.data('level');
            const nextSelect = $(`.mlcm-select[data-level="${currentLevel + 1}"]`);
            
            if (nextSelect.length && !nextSelect.prop('disabled')) {
                e.preventDefault();
                nextSelect.focus();
            }
        }
    });
    
    /**
     * Progress indicator for long operations
     * 
     * Shows a modal progress indicator for operations that take longer
     * than 1 second to provide better user feedback.
     */
    let progressIndicator = null;
    
    function showProgress(message = 'Loading...') {
        if (!progressIndicator) {
            progressIndicator = $(`
                <div class="mlcm-progress-indicator">
                    <div class="spinner"></div>
                    ${message}
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
    
    // Show progress for slow AJAX requests
    let progressTimeout;
    $(document).ajaxStart(function() {
        progressTimeout = setTimeout(() => showProgress('Loading categories...'), 1000);
    }).ajaxStop(function() {
        clearTimeout(progressTimeout);
        hideProgress();
    });
    
    /**
     * Network connection recovery
     * 
     * Handles network reconnection by clearing stale cache and
     * refreshing the first level when connection is restored.
     */
    $(window).on('online', function() {
        // Clear cache and reload data when connection restored
        ajaxCache.clear();
        cache.containers.find('.mlcm-select[data-level="1"]').trigger('change');
    });
    
    /**
     * Performance logging for debugging
     * 
     * Logs initialization timing for performance monitoring
     * and debugging purposes in development environments.
     */
    if (typeof console !== 'undefined' && console.time) {
        console.time('MLCM Frontend Init');
        $(window).on('load', function() {
            console.timeEnd('MLCM Frontend Init');
            console.log('MLCM: Frontend initialized successfully');
        });
    }
    
});