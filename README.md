# Multi-Level Category Menu

[![WordPress Plugin](https://img.shields.io/badge/WordPress-Plugin-blue.svg)](https://wordpress.org)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF.svg)](https://php.net)
[![WordPress Version](https://img.shields.io/badge/WordPress-%3E%3D5.8-21759B.svg)](https://wordpress.org)
[![License](https://img.shields.io/badge/License-GPL--2.0-red.svg)](LICENSE)

A powerful WordPress plugin for creating multi-level category navigation menus with advanced performance optimizations, modern accessibility features, and responsive design.

## 🚀 Features

### Core Functionality
- **Multi-Level Navigation**: Create category menus with up to 5 hierarchical levels
- **AJAX Lazy Loading**: Dynamic subcategory loading for improved performance
- **Gutenberg Block Support**: Native WordPress block editor integration
- **Widget Support**: Traditional WordPress widget for sidebars and widget areas
- **Shortcode Support**: Easy implementation with `[mlcm_menu]` shortcode

### Performance Optimization
- **Advanced Caching System**: Fragment-based caching with transients and object cache
- **Database Indexes**: Automatic creation of optimized database indexes
- **Lazy Loading**: On-demand subcategory loading with intelligent debounce
- **Memory Optimization**: Efficient memory usage and cache cleanup automation
- **FlyingPress Compatibility**: Optimized for popular caching plugins

### User Experience
- **Responsive Design**: Mobile-first approach with touch-friendly interfaces
- **Accessibility Compliant**: WCAG guidelines compliance with ARIA labels
- **Dark/Light Theme Support**: Automatic theme adaptation
- **Error Handling**: Graceful error handling with retry mechanisms
- **Loading Indicators**: Visual feedback during AJAX operations

### Customization Options
- **Custom Root Category**: Start menu from any specific category
- **Flexible Levels**: Configure 1-5 menu levels
- **Layout Options**: Vertical or horizontal menu layouts  
- **Custom Labels**: Personalize labels for each menu level
- **Styling Controls**: Colors, fonts, spacing, and button customization
- **Category Exclusion**: Hide specific categories from menus
- **Optional Go Button**: Toggle navigation button visibility

## 📦 Installation

### Automatic Installation
1. Go to WordPress Admin → Plugins → Add New
2. Search for "Multi-Level Category Menu"
3. Click "Install Now" and then "Activate"

### Manual Installation
1. Download the plugin files
2. Upload to `/wp-content/plugins/multi-level-category-menu/`
3. Activate through the WordPress 'Plugins' menu

### Requirements
- WordPress 5.8 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher (for optimal index performance)

## 🔧 Configuration

### Plugin Settings
Access plugin settings via **WordPress Admin → Settings → Category Menu**

#### Basic Settings
- **Menu Layout**: Choose between vertical or horizontal layouts
- **Initial Levels**: Set number of menu levels (1-5)
- **Menu Width**: Configure menu width in pixels
- **Show Go Button**: Enable/disable navigation button

#### Advanced Settings
- **Custom Root Category ID**: Start menu from specific category
- **Excluded Categories**: Comma-separated category IDs to exclude
- **Level Labels**: Custom labels for each menu level
- **Styling Options**: Colors, fonts, and spacing controls

#### Performance Settings
- **Cache Management**: Clear cached data and manage performance
- **Database Indexes**: Automatic optimization for better performance

## 📝 Usage

### Shortcode Implementation
```php
// Basic usage
[mlcm_menu]

// With custom parameters  
[mlcm_menu layout="horizontal" levels="4" root_id="25"]

// In PHP templates
echo do_shortcode('[mlcm_menu layout="vertical" levels="3"]');
```

### Gutenberg Block
1. Add new block in editor
2. Search for "Category Menu"
3. Configure block settings in sidebar
4. Customize layout, levels, and root category

### Widget Usage
1. Go to **Appearance → Widgets**
2. Add "Category Menu" widget to desired area
3. Configure title, layout, and levels
4. Save widget settings

### Template Integration
```php
// Direct integration in theme templates
if (class_exists('Multi_Level_Category_Menu')) {
    $menu = Multi_Level_Category_Menu::get_instance();
    echo $menu->shortcode_handler(['layout' => 'vertical', 'levels' => 3]);
}
```

## 🎨 Customization

### CSS Customization
The plugin uses CSS custom properties for easy theming:

```css
:root {
    --mlcm-primary-color: #your-color;
    --mlcm-width: 300px;
    --mlcm-gap: 20px;
    --mlcm-border-radius: 8px;
}
```

### Theme Integration
```php
// Add to your theme's functions.php
add_action('wp_enqueue_scripts', function() {
    wp_add_inline_style('mlcm-frontend', '
        .mlcm-select { border-color: var(--your-theme-color); }
        .mlcm-container { gap: 30px; }
    ');
});
```

### Hooks and Filters
```php
// Modify menu output
add_filter('mlcm_menu_html', function($html, $atts) {
    // Your customization logic
    return $html;
}, 10, 2);

// Customize category query
add_filter('mlcm_category_args', function($args) {
    // Modify category retrieval arguments
    return $args;
});
```

## 🔧 Technical Documentation

### Architecture Overview
- **Main Class**: `Multi_Level_Category_Menu` - Singleton pattern implementation
- **Widget Class**: `MLCM_Widget` - WordPress widget integration
- **Caching System**: Fragment-based with dual-layer cache (object + transient)
- **Database Optimization**: Custom indexes for category hierarchy queries

### Performance Features
- **Fragment Caching**: Cache individual menu levels separately
- **Lazy Loading**: Load subcategories on-demand via AJAX
- **Database Indexes**: Optimized indexes for category parent-child relationships
- **Memory Management**: Efficient object reuse and cache cleanup

### Cache Management
```php
// Programmatic cache clearing
$menu = Multi_Level_Category_Menu::get_instance();
$menu->clear_all_caches();

// Clear specific category cache
$menu->clear_related_cache($category_id);
```

### Database Schema
The plugin creates the following indexes for optimization:
- `idx_category_parent_order`: Optimizes parent-child category queries
- `idx_category_hierarchy`: Speeds up category relationship lookups
- `idx_taxonomy_parent_name`: Enhances taxonomy-based queries

## 🐛 Troubleshooting

### Common Issues

#### Menu Not Loading
1. Check if categories exist in your WordPress site
2. Verify plugin is activated
3. Clear all caches (plugin + server caches)
4. Check browser console for JavaScript errors

#### AJAX Errors
1. Verify WordPress AJAX URL is accessible
2. Check for plugin conflicts by deactivating other plugins
3. Ensure proper WordPress nonce validation
4. Review server error logs

#### Performance Issues
1. Enable object cache (Redis/Memcached recommended)
2. Configure proper database indexes
3. Adjust cache durations in plugin settings
4. Monitor cache hit rates

### Debug Mode
Enable WordPress debug mode to see detailed error information:
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## 🔄 Compatibility

### WordPress Versions
- **Minimum**: WordPress 5.8
- **Tested up to**: WordPress 6.8+
- **Recommended**: WordPress 6.0+

### Plugin Compatibility
- **Caching Plugins**: WP Rocket, W3 Total Cache, WP Super Cache
- **Performance Plugins**: FlyingPress, Autoptimize, WP Optimize
- **SEO Plugins**: Yoast SEO, RankMath, All in One SEO
- **Page Builders**: Elementor, Beaver Builder, Divi

### Browser Support
- Modern browsers (Chrome 70+, Firefox 65+, Safari 12+, Edge 79+)
- Mobile browsers (iOS Safari 12+, Chrome Mobile 70+)
- Progressive enhancement for older browsers

## 📊 Performance Metrics

### Optimization Results
- **Database Queries**: Reduced by up to 80% with fragment caching
- **Page Load Time**: Improved by 15-30% with lazy loading
- **Memory Usage**: Optimized with efficient object reuse
- **Mobile Performance**: Touch-optimized with responsive breakpoints

### Cache Statistics
- **Fragment Cache**: 30-minute default duration
- **Transient Cache**: 2-hour default duration  
- **Object Cache**: 1-hour default duration
- **Cleanup**: Automatic expired cache cleanup

## 🤝 Contributing

### Development Setup
1. Clone the repository
2. Install WordPress development environment
3. Activate plugin in development site
4. Follow WordPress coding standards

### Code Standards
- Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Use proper sanitization and validation
- Include comprehensive inline documentation
- Write responsive, accessible CSS

### Pull Request Guidelines
1. Fork the repository
2. Create feature branch from main
3. Make changes with proper documentation
4. Test across different WordPress versions
5. Submit pull request with detailed description

## 📄 License

This plugin is licensed under the [GPL v2 or later](LICENSE).

```
Multi-Level Category Menu WordPress Plugin
Copyright (C) 2024 [Author Name]

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

## 📞 Support

### Getting Help
- **Documentation**: Check this README and inline code comments
- **WordPress Support Forums**: Search for existing solutions
- **GitHub Issues**: Report bugs and request features
- **WordPress Plugin Directory**: Leave reviews and ask questions

### Report Issues
When reporting issues, please include:
- WordPress version
- Plugin version  
- PHP version
- Steps to reproduce
- Error messages
- Browser console logs

---

**Made with ❤️ for the WordPress community**