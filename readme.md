# Multi-Level Category Menu

**Version:** 3.9.4  
**Requires at least:** WordPress 5.8  
**Tested up to:** WordPress 7.0  
**Requires PHP:** 7.4  
**License:** GPL v2 or later

## Description

A powerful WordPress plugin that creates customizable multi-level category menus with configurable depth. Fully compatible with page caching and CDN solutions — no frontend nonce is used, and static menu files now include a dedicated `versions.js` manifest to ensure correct cache-busting for all menu levels even on fully cached pages. Tested and compatible with WordPress 7.0.

## Features

- **Multi-Level Category Menus** — Support for up to 10 levels of nested categories (configurable)
- **WordPress 7.0 Compatible** — Gutenberg block uses Block API v3, fully compatible with the iframed editor
- **Page Cache Friendly** — No frontend nonce; works out-of-the-box with any page caching plugin
- **Cache-Safe Static File Versioning** — Dedicated `versions.js` manifest ensures correct `?v=` parameters for dynamically loaded level files on cached pages
- **Static JavaScript File Caching** — Generate static JS files for faster loading and CDN compatibility
- **Gzip Compression** — Automatic gzip compression for cache files to reduce bandwidth
- **Gutenberg Block Support** — Add menus directly from the block editor (Block API v3)
- **Widget Support** — Use as a sidebar widget
- **Shortcode** — `[mlcm_menu]` for easy placement anywhere
- **Customizable Labels** — Set custom labels for each menu level
- **Responsive Design** — Mobile-friendly layout that adapts to screen size
- **Caching Compatibility** — Works with FlyingPress, WP Rocket, W3 Total Cache, WP Super Cache, Redis Object Cache, and Cloudflare
- **AJAX Loading** — Dynamic subcategory loading without page refresh (fallback mode)
- **Custom Root Category** — Select a specific category as the root for menu generation
- **Category Exclusion** — Exclude specific categories from the menu
- **Alphabetical Sorting** — Automatic sorting of categories by name (stable across all MySQL collations)
- **Performance Optimized** — Static file generation, efficient caching, minimal PHP on frontend
- **Auto-Redirect** — Automatically redirects to category page when selected category has no subcategories
- **Cache Management** — Easy cache file generation and deletion from admin panel

## Requirements

- **WordPress:** 5.8 or later
- **PHP:** 7.4 or later
- **Tested up to:** WordPress 7.0

## Installation

1. Upload the plugin files to `/wp-content/plugins/multi-level-category-menu/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure settings in **Settings → Category Menu**
4. Click **Generate Menu Files** to create static JavaScript cache files
5. If you use full-page caching or CDN caching, purge page cache once after generating files so the latest `versions.js` is referenced immediately

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

- **Font Size** — Set font size for menu items (rem)
- **Container Gap** — Gap between menu items (px)
- **Button Colors** — Background and hover colors
- **Menu Layout** — Vertical or horizontal
- **Initial Levels** — Number of visible levels on page load
- **Max Menu Depth** — Maximum number of levels supported (1–10)
- **Menu Width** — Width of menu items (px)
- **Show Go Button** — Enable/disable the “Go” button
- **Use Static JavaScript Files** — Enable static file generation for better performance and CDN caching
- **Custom Root Category ID** — Use a specific category as root
- **Excluded Categories** — Comma-separated list of category IDs to exclude
- **Level Labels** — Custom labels for each menu level
- **Generate Menu Files** — Generate static JavaScript cache files and refresh `versions.js`
- **Delete Cache Files** — Remove all generated cache files, including `versions.js`

## Caching Compatibility

The plugin is fully compatible with:

- **Cloudflare** — Static JavaScript files with file modification time versioning for proper caching
- **FlyingPress** — Compatible with cached guest pages; `versions.js` keeps dynamic level files versioned correctly
- **WP Rocket** — No additional configuration needed
- **W3 Total Cache** — Full compatibility
- **WP Super Cache** — Works seamlessly
- **Redis Object Cache** — Automatic integration via WordPress transients

### Why No Frontend Nonce?

Frontend nonces are tied to user sessions and change on every page load, which breaks full-page caching. Since the AJAX endpoint (`mlcm_get_subcategories`) only reads data and makes no state changes, there is no CSRF risk — nonce verification is unnecessary. Admin AJAX actions (generate menu, delete cache) still use nonce protection.

### Static File Caching

When "Use Static JavaScript Files" is enabled:

- Category data is stored in static JavaScript files (`/wp-content/uploads/mlcm-menu-cache/`)
- A separate `versions.js` manifest stores file modification times for all generated menu levels
- `versions.js` is enqueued by WordPress before frontend initialization, ensuring fresh version data even on cached pages
- Files are automatically gzipped for bandwidth savings
- File modification time is used for cache versioning (CDN-friendly)
- Files are automatically regenerated when categories are created, edited, or deleted
- Browser caching is optimized with proper cache headers (7 days)
- Fallback to AJAX if static files are unavailable

### How It Works

- **Static Mode (Recommended)**: Category data is pre-generated in JavaScript files, loaded via dynamic script tags. A small `versions.js` manifest ensures the correct `?v=` parameter is applied to every level file, even when the page HTML comes from cache.
- **AJAX Mode (Fallback)**: Subcategory data is fetched via AJAX without nonce — safe for cached pages

## Technical Details

### Performance Optimizations

- Static JavaScript file generation for instant category loading with zero runtime DB queries
- Dedicated `versions.js` manifest for cache-safe dynamic file versioning on full-page-cached sites
- Gzip compression for cache files (up to 85% size reduction)
- Efficient caching system with automatic cache clearing on category changes
- File modification time-based versioning for optimal CDN caching
- Atomic file writes (write to `.tmp` → rename) prevent serving partial cache files
- Options loaded once per request and held in memory (no repeated `get_option()` calls)
- N+1 query prevention: child existence checked in one batch query per level
- Frontend JS loads in footer (`wp_enqueue_script` with `$in_footer = true`)
- `versions.js` is tiny and adds negligible overhead while eliminating stale cached HTML issues for dynamic level files

### WordPress 7.0 Compatibility

- Gutenberg block registered with **Block API v3** — required for WordPress 7.1+, compatible with the always-on iframed editor introduced in WordPress 7.0
- `wp-editor` dependency removed from block editor script (deprecated in WordPress 6.x)
- All standard WordPress hooks and APIs used; no deprecated functions
- Requires PHP 7.4+ in line with WordPress 7.0 minimum requirements

### Security

- Admin AJAX actions protected by WordPress nonce
- Frontend AJAX endpoint is read-only — no state changes, no CSRF risk
- All inputs sanitized and validated (`sanitize_text_field`, `sanitize_hex_color`, `absint`, `esc_attr`, `esc_url`, `esc_html`)

### Sorting

- Categories are sorted alphabetically by name (case-insensitive) using PHP `uasort()`
- PHP-side sorting guarantees consistent order regardless of MySQL collation (important for sites with Cyrillic, Ukrainian, or other non-ASCII category names)
- Sorting is applied after `strtoupper()` and `htmlspecialchars_decode()` transformations for accurate results
- JavaScript array format preserves sort order from server

### Auto-Redirect Behavior

- When a category is selected and it has no subcategories, the menu automatically redirects to that category page
- If subcategories exist and more levels are available, subcategories are loaded into the next level
- This provides a smooth user experience without requiring selection of all levels

## Changelog

### 3.9.4
- Fixed cached guest-page issue where dynamically loaded `level-2.js` and higher could be requested without a `?v=` parameter on fully cached pages
- Added dedicated `versions.js` manifest with file modification times for generated menu levels
- `versions.js` is now enqueued by WordPress before frontend initialization, making dynamic level file versioning independent of stale cached HTML
- Updated frontend loader to prefer `window.mlcmVersions` over localized page data, with fallback for installations that have not yet regenerated static files
- Cache deletion now removes `versions.js` and its gzipped variant alongside other generated menu files
- Plugin version bumped to 3.9.4

### 3.9.3
- **WordPress 7.0 compatibility**: upgraded Gutenberg block from Block API v2 to v3 (v2 deprecated since WP 6.9, required for WP 7.1+)
- Removed deprecated `wp-editor` dependency from block editor script; using `wp-components` only
- Added `Requires PHP: 7.4` and `Tested up to: 7.0` headers to plugin file
- Updated all fallback version strings to current plugin version
- Updated plugin description to mention WordPress 7.0 compatibility

### 3.9.2
- Block API v3 preparation: `block.json` attributes updated with `minimum`/`maximum` validation
- Minor stability improvements

### 3.9.0
- **Removed frontend nonce** — plugin is now fully compatible with all page caching solutions without any configuration
- Removed `Cache-Control: no-cache` header from AJAX handler (safe for caching proxies)
- Updated plugin description to reflect caching-first approach
- Version bumped to reflect breaking change in frontend JS (remove `nonce` from `mlcmVars`)

### 3.8.0
- Configurable max levels (up to 10)
- Atomic file writes for cache files
- Various stability improvements

### 3.6.0
- **Major**: Changed cache format from JSON to JavaScript files for better performance
- **Major**: Added gzip compression support for cache files
- **Major**: Implemented file modification time-based versioning for Cloudflare compatibility
- Added cache deletion button in admin panel
- Fixed auto-redirect when selected category has no subcategories
- Fixed loading spinner appearing on hover when no data is loading
- Improved error message handling with auto-dismiss functionality
- Enhanced cache management with better user feedback
- All admin messages now in English
- Improved loading indicator logic to only show during actual data loading

### 3.5.1
- Fixed sorting issues for all menu levels
- Improved caching compatibility
- Optimized performance
- Added comprehensive error handling
- Improved mobile responsiveness

### 3.4
- Initial release with basic features

## Support

For issues, feature requests, or contributions, please visit the [GitHub repository](https://github.com/gemuzkm/multi-level-category-menu).

## License

GPL v2 or later
