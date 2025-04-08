jQuery(function($) {
    // Cache DOM elements and configure settings
    const $containers = $('.mlcm-container');
    const settings = {
        mobileBreakpoint: 768,
        defaultGap: 20
    };

    /**
     * Initialize menu containers
     * Sets up initial state and removes duplicate buttons
     */
    function initializeContainers() {
        $containers.each(function() {
            const $container = $(this);
            
            // Remove duplicate buttons (keep only first one)
            const $buttons = $container.find('.mlcm-go-button');
            if ($buttons.length > 1) {
                $buttons.slice(1).remove();
            }

            // Set container custom properties
            setContainerProperties($container);
        });
    }

    /**
     * Set container CSS custom properties
     * @param {jQuery} $container - Container element
     */
    function setContainerProperties($container) {
        const gap = parseInt($container.css('gap')) || settings.defaultGap;
        const fontSize = $container.css('font-size');
        
        $container[0].style.setProperty('--mlcm-gap', `${gap}px`);
        $container[0].style.setProperty('--mlcm-font-size', fontSize);
    }

    /**
     * Handle select element changes
     * Loads subcategories or triggers redirect
     * @param {Event} event - Change event
     */
    function handleSelectChange(event) {
        const $select = $(event.target);
        const level = $select.data('level');
        const selectedOption = $select.find('option:selected');
        const parentId = selectedOption.val();
        const slug = selectedOption.data('slug');
        
        if (parentId === '-1') {
            resetLevels(level);
            return;
        }
        
        $select.data('selected-slug', slug);
        loadSubcategories($select, level, parentId);
    }

    /**
     * Reset all levels after the current one
     * @param {number} currentLevel - Current selection level
     */
    function resetLevels(currentLevel) {
        $containers.find('.mlcm-select').each(function() {
            if ($(this).data('level') > currentLevel) {
                $(this).val('-1').prop('disabled', true);
            }
        });
    }

    /**
     * Load subcategories via AJAX
     * @param {jQuery} $select - Select element
     * @param {number} level - Current level
     * @param {number} parentId - Parent category ID
     */
    function loadSubcategories($select, level, parentId) {
        const maxLevels = $containers.data('levels');
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
                if (response.success && Object.keys(response.data).length > 0) {
                    updateNextLevel($select, level, response.data);
                } else {
                    redirectToCategory();
                }
            }
        });
    }

    /**
     * Update next level select with new options
     * @param {jQuery} $select - Current select element
     * @param {number} currentLevel - Current level
     * @param {Object} categories - Categories data
     */
    function updateNextLevel($select, currentLevel, categories) {
        const nextLevel = currentLevel + 1;
        const $nextSelect = $(`.mlcm-select[data-level="${nextLevel}"]`);
        
        if (Object.keys(categories).length > 0) {
            const label = mlcmVars.labels[nextLevel-1];
            const sortedEntries = Object.entries(categories)
                .sort((a, b) => a[1].name.localeCompare(b[1].name, undefined, { sensitivity: 'base' }));
            
            const options = sortedEntries.map(([id, data]) => 
                `<option value="${id}" data-slug="${data.slug}" data-url="${data.url}">${data.name}</option>`
            );

            $nextSelect
                .prop('disabled', false)
                .html(`<option value="-1">${label}</option>${options.join('')}`);
        }
    }

    /**
     * Redirect to the selected category URL
     */
    function redirectToCategory() {
        const $lastSelect = $containers.find('.mlcm-select').filter(function() {
            return $(this).val() !== '-1';
        }).last();
        
        if ($lastSelect.length) {
            const url = $lastSelect.find('option:selected').data('url');
            if (url) {
                window.location = url;
            }
        }
    }

    /**
     * Handle mobile layout adjustments
     */
    function handleMobileLayout() {
        const isMobile = window.matchMedia(`(max-width: ${settings.mobileBreakpoint}px)`).matches;
        
        $containers.find('.mlcm-go-button').css({
            'width': isMobile ? '100%' : '',
            'margin': isMobile ? '10px 0 0 0' : ''
        });
    }

    // Event Listeners
    $containers.on('change', '.mlcm-select', handleSelectChange);
    $containers.on('click', '.mlcm-go-button', redirectToCategory);
    $(window).on('resize', handleMobileLayout);

    // Initialize
    initializeContainers();
    handleMobileLayout();
});