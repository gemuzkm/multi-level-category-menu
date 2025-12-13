# Multi-Level Category Menu

**Version:** 3.5.1  
**Requires at least:** WordPress 5.8  
**Tested up to:** WordPress 6.8  
**License:** GPL v2 or later

## Description

A powerful WordPress plugin that creates customizable multi-level category menus with up to 5 levels of depth. Perfect for organizing complex category structures with AJAX-powered dynamic loading and full caching support.

## Features

- **5-Level Category Menus** - Support for up to 5 levels of nested categories
- **Gutenberg Block Support** - Add menus directly from the block editor
- **Widget Support** - Use as a sidebar widget
- **Shortcode** - `[mlcm_menu]` for easy placement anywhere
- **Customizable Labels** - Set custom labels for each menu level
- **Responsive Design** - Mobile-friendly layout that adapts to screen size
- **Advanced Caching** - Compatible with FlyingPress, WP Rocket, W3 Total Cache, WP Super Cache, and Redis Object Cache
- **AJAX Loading** - Dynamic subcategory loading without page refresh
- **Smart Nonce Handling** - Works perfectly with cached pages
- **Custom Root Category** - Select a specific category as the root for menu generation
- **Category Exclusion** - Exclude specific categories from the menu
- **Alphabetical Sorting** - Automatic sorting of categories by name
- **Performance Optimized** - Conditional script loading and efficient caching

## Installation

1. Upload the plugin files to `/wp-content/plugins/multi-level-category-menu/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure settings in **Settings → Category Menu**

## Usage

### Shortcode

Place the shortcode anywhere in your content:

```
[mlcm_menu]
```

With custom attributes:

```
[mlcm_menu layout="horizontal" levels="4"]
```

### Widget

1. Go to **Appearance → Widgets**
2. Add the "Category Menu" widget to your sidebar
3. Configure the widget settings

### Gutenberg Block

1. In the block editor, search for "Category Menu"
2. Add the block to your page
3. Configure layout and levels in the block settings

## Configuration

Navigate to **Settings → Category Menu** to configure:

- **Font Size** - Set font size for menu items (rem)
- **Container Gap** - Gap between menu items (px)
- **Button Colors** - Background and hover colors
- **Menu Layout** - Vertical or horizontal
- **Initial Levels** - Number of visible levels (1-5)
- **Menu Width** - Width of menu items (px)
- **Show Go Button** - Enable/disable the "Go" button
- **Custom Root Category ID** - Use a specific category as root
- **Excluded Categories** - Comma-separated list of category IDs to exclude
- **Level Labels** - Custom labels for each menu level

## Caching Compatibility

The plugin is fully compatible with:

- **FlyingPress** - AJAX endpoints are excluded from cache
- **WP Rocket** - Proper cache exclusion headers
- **W3 Total Cache** - Full compatibility
- **WP Super Cache** - Works seamlessly
- **Redis Object Cache** - Automatic integration via WordPress transients

### How It Works

- Nonce tokens are generated dynamically via AJAX (not embedded in HTML)
- Cookie-based nonce system works with cached pages
- AJAX endpoints are excluded from page caching
- Category data is cached using WordPress transients (compatible with Redis)

## Technical Details

### Performance Optimizations

- Scripts load only on pages with the menu
- Efficient caching system with automatic cache clearing
- Optimized database queries with proper sorting
- Debounced AJAX requests to prevent multiple calls

### Security

- WordPress nonce verification for all AJAX requests
- Cookie-based nonce system for cached pages
- Automatic nonce refresh on expiration
- Input sanitization and validation

### Sorting

- Categories are sorted alphabetically by name (case-insensitive)
- Sorting is applied consistently for all users (authenticated and non-authenticated)
- Works correctly with cached data
- JSON array format preserves sort order

## Changelog

### 3.5.1
- Fixed sorting issues for all menu levels
- Improved caching compatibility
- Enhanced nonce handling for cached pages
- Optimized performance
- Added comprehensive error handling
- Improved mobile responsiveness

### 3.4
- Initial release with basic features

## Support

For issues, feature requests, or contributions, please contact the plugin author.

## License

GPL v2 or later

