# Multi-Level Category Menu

![Version](https://img.shields.io/badge/version-3.4-blue.svg)
![PHP Version](https://img.shields.io/badge/PHP-7.4+-purple.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.0+-green.svg)
![License](https://img.shields.io/badge/license-GPL%20v2-yellow.svg)

Create powerful and flexible multi-level category navigation menus with up to 5 levels of depth for WordPress.

## Description

Multi-Level Category Menu is a powerful WordPress plugin that allows you to create dynamic, user-friendly category navigation menus with up to 5 levels of depth.

### Key Features

- **Flexible Navigation**: Up to 5 levels of category depth
- **Multiple Integration Options**:
  - Gutenberg block support
  - Widget integration
  - Shortcode support `[mlcm_menu]`
- **Performance Optimized**:
  - AJAX-powered category loading
  - Built-in caching system
  - Minimal database queries
- **Responsive Design**:
  - Fully responsive layout
  - Mobile-friendly interface
  - RTL support
- **Customization Options**:
  - Custom category base support
  - Root category selection
  - Customizable labels and styling

## Installation

1. Upload `multi-level-category-menu` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure settings in 'Settings → Category Menu'
4. Add menu using widget, shortcode, or Gutenberg block

## Usage

### Widget
Add the "Category Menu" widget to any widget area through Appearance → Widgets

### Shortcode
Insert the menu anywhere using shortcode:
```php
[mlcm_menu]
```

### Gutenberg Block
Add the "Category Menu" block in the Gutenberg editor

## Customization

The plugin offers various customization options:

- Custom category labels
- Adjustable menu width
- Configurable colors
- Mobile-friendly layouts
- RTL support

## FAQ

**Q: How many levels of categories can I display?**  
A: You can display up to 5 levels of nested categories.

**Q: Does it support Gutenberg?**  
A: Yes, the plugin includes a dedicated Gutenberg block.

**Q: Is it mobile-friendly?**  
A: Yes, the menu is fully responsive and adapts to all screen sizes.

**Q: Can I customize the styling?**  
A: Yes, you can customize colors, sizes, and layouts through the settings panel.

## Changelog

### 3.4
- Added Gutenberg block support
- Improved caching system
- Enhanced mobile responsiveness
- Performance optimizations
- Bug fixes

### 3.3
- Added custom category base support
- Improved AJAX loading
- Fixed widget display issues

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- JavaScript enabled browser

## Development

- [GitHub Repository](https://github.com/gemuzkm/multi-level-category-menu)

## Credits

- Built with WordPress best practices
- Uses jQuery for AJAX functionality
- GPL v2.0 or later license

## License

This project is licensed under the GPL v2.0 or later - see the [LICENSE](LICENSE) file for details.