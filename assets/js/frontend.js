/**
 * Multi-Level Category Menu - Frontend Script
 * Handles dynamic menu loading and user interactions
 */

(function($) {
    'use strict';

    var MLCMFrontend = {
        config: window.mlcm_config || {},
        cacheLoaded: false,
        menuData: {},

        /**
         * Initialize the menu
         */
        init: function() {
            if (!this.config.cache_url) {
                console.error('MLCM: Cache URL not configured');
                return;
            }

            this.bindEvents();
            this.loadMenuCache();
        },

        /**
         * Load menu cache from JavaScript file
         */
        loadMenuCache: function() {
            var self = this;
            var cacheUrl = this.config.cache_url + 'level-1.js?v=' + this.config.version;

            $.ajax({
                url: cacheUrl,
                dataType: 'script',
                cache: true,
                timeout: 5000,
                success: function() {
                    self.cacheLoaded = true;
                    if (window.mlcmData && window.mlcmData[1]) {
                        self.menuData = window.mlcmData[1];
                        self.onCacheLoaded();
                    }
                },
                error: function() {
                    console.warn('MLCM: Failed to load menu cache');
                }
            });
        },

        /**
         * Handle cache loaded event
         */
        onCacheLoaded: function() {
            $(document).trigger('mlcm:cache-loaded', [this.menuData]);
            this.setupMenuInteractions();
        },

        /**
         * Setup menu interactions
         */
        setupMenuInteractions: function() {
            var self = this;

            // Handle category menu expand/collapse
            $('.mlcm-menu').on('click', '.mlcm-link', function(e) {
                var $link = $(this);
                var $item = $link.closest('.mlcm-item');
                var $children = $item.find('> .mlcm-children');

                if ($children.length) {
                    e.preventDefault();
                    $item.toggleClass('expanded');
                    $children.slideToggle(200);
                }
            });

            // Load subcategories on demand
            $('.mlcm-children-loader').on('click', function(e) {
                e.preventDefault();
                var level = $(this).data('level');
                var parentId = $(this).data('parent-id');

                self.loadMenuLevel(level, parentId);
            });
        },

        /**
         * Load specific menu level
         */
        loadMenuLevel: function(level, parentId) {
            var self = this;
            var cacheFile = 'level-' + level + '.js';
            var cacheUrl = this.config.cache_url + cacheFile + '?v=' + this.config.version;

            $.ajax({
                url: cacheUrl,
                dataType: 'script',
                cache: true,
                timeout: 5000,
                success: function() {
                    if (window.mlcmData && window.mlcmData[level]) {
                        $(document).trigger('mlcm:level-loaded', [level, window.mlcmData[level]]);
                    }
                },
                error: function() {
                    console.warn('MLCM: Failed to load level ' + level);
                }
            });
        },

        /**
         * Bind global events
         */
        bindEvents: function() {
            var self = this;

            // Trigger custom events
            $(document).on('mlcm:cache-loaded', function(e, data) {
                // Cache loaded successfully
                console.log('MLCM: Menu cache loaded', data);
            });

            $(document).on('mlcm:level-loaded', function(e, level, data) {
                // Specific level loaded
                console.log('MLCM: Level ' + level + ' loaded', data);
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        MLCMFrontend.init();
    });

    // Expose to global scope
    window.MLCMFrontend = MLCMFrontend;

})(jQuery);
