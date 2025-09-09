=== Multi-Level Category Menu ===
Contributors: Name
Tags: categories, menu, dropdown, widget, modal
Requires at least: 5.8
Tested up to: 6.8
Stable tag: 3.5

Create multi-level category menus with 5-level depth and modal window support.

== Description ==
Features:
- 5-level category menus
- Gutenberg block support with modal option
- Widget support with modal option
- Shortcode [mlcm_menu] with modal parameter
- New shortcode [mlcm_modal_button] for modal trigger
- GeneratePress-style modal window
- Customizable labels
- Responsive design
- Cache system
- AJAX loading
- Deletion in slug 'category'
- Selecting the root category (id) for menu generation
- Modal window support with accessibility features

New in version 3.5:
- Added modal window functionality
- New modal toggle button shortcode
- Updated widget with modal option
- Enhanced Gutenberg block with modal toggle
- Improved mobile responsiveness
- ARIA accessibility support for modal

== Installation ==
1. Upload the plugin files to /wp-content/plugins/
2. Activate through 'Plugins' menu
3. Configure in Settings → Category Menu

== Usage ==
Shortcodes:
- [mlcm_menu] - Display category menu directly
- [mlcm_menu modal="true"] - Display modal trigger button
- [mlcm_modal_button] - Display modal trigger button with default text
- [mlcm_modal_button button_text="Custom Text"] - Custom button text

Gutenberg Block:
- Use the "Category Menu" block in the editor
- Toggle modal display option in block settings

Widget:
- Add "Category Menu" widget to any widget area
- Toggle modal display option in widget settings

== Frequently Asked Questions ==
= How do I enable the modal window? =
Go to Settings → Category Menu and check "Use Modal Window" or use the modal parameter in shortcodes.

= Can I customize the modal button text? =
Yes, use the button_text parameter in the [mlcm_modal_button] shortcode.

= Is the modal accessible? =
Yes, the modal includes ARIA attributes and can be closed with ESC key or by clicking outside.

== Changelog ==
= 3.5 =
* Added modal window functionality
* New modal toggle button shortcode
* Updated widget with modal option
* Enhanced Gutenberg block with modal toggle
* Improved mobile responsiveness
* ARIA accessibility support for modal

= 3.4 =
* Initial release with basic functionality
* 5-level category menus
* Gutenberg block support
* Widget support
* Shortcode implementation