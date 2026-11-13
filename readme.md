# Multi-Level Category Menu

Version: **3.12.0**

Fast, cache-friendly category navigation for WordPress. The plugin renders a
chain of dependent dropdowns: the visitor picks a category, the next dropdown
fills with its subcategories, and the last choice opens the category page.

It is built for sites that sit behind full-page caching and a CDN. The first
dropdown is rendered by PHP, deeper levels are loaded from small static files,
and a read-only AJAX endpoint is used only as a fallback.

| Requirement | Value |
| --- | --- |
| WordPress | 5.8 or later (tested up to 7.1) |
| PHP | 7.4 or later |
| License | GPL v2 or later |

## Contents

- [Features](#features)
- [Quick start](#quick-start)
- [Usage](#usage)
- [Settings](#settings)
- [How it works](#how-it-works)
- [Caching and CDN](#caching-and-cdn)
- [Automatic regeneration](#automatic-regeneration)
- [Asset loading](#asset-loading)
- [Compression and server configuration](#compression-and-server-configuration)
- [Hooks](#hooks)
- [Security and limitations](#security-and-limitations)
- [Development and Git workflow](#development-and-git-workflow)
- [Publishing a release](#publishing-a-release)
- [Changelog](#changelog)
- [Support](#support)

## Features

**Navigation**

- Up to ten category levels, with a custom root category and excluded branches.
- Available as a shortcode, a dynamic Gutenberg block and a sidebar widget.
- Horizontal or vertical layout, custom labels, sizing, colors and an optional
  Go button.
- Selecting a category without subcategories opens its page straight away.

**Performance**

- The first dropdown is part of the HTML, so no request is needed to show it.
- Deeper levels load one small static file per selected parent.
- Frontend scripts are deferred on WordPress 6.3+ and loaded in the footer on
  older supported versions. No jQuery is used on the frontend.
- CSS and JavaScript are enqueued only on pages that contain a menu.
- Menu files are generated from a single bulk taxonomy query.

**Reliability**

- Menu files are published as immutable generations: a new generation goes
  live only after every file has been written.
- The previous generation stays available while a new one is being prepared
  and for pages that are still cached.
- Optional background regeneration after category changes, with automatic
  retries when a run fails.
- Superseded generations are removed automatically after a retention period.
- If a static file cannot be loaded, the menu falls back to AJAX.

## Quick start

1. Copy the plugin directory into `wp-content/plugins/` and activate it.
2. Open **Settings → Category Menu**, review the settings and save them.
3. Click **Generate Menu Files**.
4. Add `[mlcm_menu]`, the **Category Menu** block or the sidebar widget.
5. Purge your full-page cache.

## Usage

### Shortcode

```text
[mlcm_menu]
[mlcm_menu layout="horizontal" levels="4"]
```

| Attribute | Values | Default |
| --- | --- | --- |
| `layout` | `vertical`, `horizontal` | The **Menu Layout** setting |
| `levels` | Number of dropdowns shown, up to **Max Menu Depth** | The **Initial Visible Levels** setting |

### Block and widget

The **Category Menu** block offers the same layout and levels options in the
block sidebar. The widget uses the plugin settings.

## Settings

All settings are on **Settings → Category Menu**.

| Setting | Purpose |
| --- | --- |
| Menu Layout | Vertical or horizontal arrangement of the dropdowns. |
| Initial Visible Levels | Number of dropdowns shown on page load. |
| Max Menu Depth | Deepest category level the menu supports (1–10). |
| Custom Root Category ID | Use the children of this category as the first level. The root itself is not shown. |
| Excluded Categories | Comma-separated category IDs. Excluding a parent removes its whole branch. |
| Level N Label | Placeholder text of each dropdown. |
| Menu Width, Font Size, Gap | Size and spacing of the dropdowns. |
| Show Go Button | Adds a button that opens the selected category. |
| Button colors and font size | Appearance of the Go button. |
| Use Static JavaScript Files | Serve subcategories from generated static files (recommended). |
| Auto-Regenerate on Category Change | Rebuild the files in the background after a category is created, edited or deleted. |
| Auto-Regeneration Delay | Seconds to wait after the last change (10–60). |
| Generate Gzip Sidecars | Also write `.gz` copies. Off by default. |

Changing the root category, the excluded categories or the maximum depth
requires **Generate Menu Files** afterwards.

Category links always come from WordPress `get_category_link()`, so permalink
settings and SEO-plugin filters are respected.

## How it works

Each generation is written to its own directory in
`uploads/mlcm-menu-cache/gen-<uuid>/`:

```text
level-1.js          first level
l2-<parent-id>.js   children of one second-level parent
l3-<parent-id>.js   children of one third-level parent
...
versions.js
meta.js
```

The `mlcm_generation` option points to the published generation. Generation
runs under a filesystem lock and has a single publication point: a failed
query, link lookup, file write or option update leaves the previous generation
in place.

The lock assumes a shared local filesystem. Multi-origin deployments must
share the cache directory and the database, and should validate the locking
behaviour of their storage.

## Caching and CDN

A cached page keeps the generation it was rendered with: both the first-level
options in its HTML and the URLs of the deeper levels. This keeps every cached
page consistent, and it also means that **a cached page shows the new menu only
after that page is purged or expires**. A manifest file cannot refresh options
that are already inside cached HTML.

Because generation URLs never change their content, they can be cached for a
long time by the browser and the CDN.

To show a changed menu immediately, purge the page cache after a generation is
published. The plugin does not call any cache plugin or CDN API itself, but it
fires an action you can use:

```php
add_action('mlcm_generation_published', function ($meta) {
    // Purge your page cache and CDN here.
});
```

### Retention of old generations

Superseded generations are kept so that cached pages continue to work, and are
removed 30 days after they were replaced. Set the period to at least the
lifetime of your page cache:

```php
add_filter('mlcm_generation_ttl', function () {
    return 14 * DAY_IN_SECONDS; // 0 disables automatic removal
});
```

If a cached page refers to a generation that no longer exists, the menu falls
back to AJAX.

**Delete Cache Files** cancels pending regeneration and removes every
generation and all legacy data files at once. For maintenance: delete the
cache, regenerate, then purge the page cache and CDN.

## Automatic regeneration

With **Auto-Regenerate on Category Change** enabled, saving a category
schedules one delayed WP-Cron job. Further edits reset the delay, so a series
of changes produces a single run. The published generation stays in use until
the new one is complete.

If a scheduled run fails, it is retried after 60 seconds, up to three times.

With auto-regeneration or static files disabled, a category change detaches
the current generation for newly rendered pages, which then use AJAX until you
click **Generate Menu Files**. Files remain available for cached pages.

WP-Cron runs only when WordPress handles a request, so on a fully cached or
low-traffic site a job can start late. If your hosting supports it, run due
cron events from a system scheduler, and disable request-driven WP-Cron only
once that scheduler works.

## Asset loading

The plugin detects a shortcode or block in the requested post and enqueues its
assets in the normal way. For widgets, synced blocks and menus printed by a
template after `wp_head`, the stylesheet is printed immediately before the
first menu and the script is queued in the footer. Themes must call
`wp_head()` and `wp_footer()`.

If a template always contains a menu, request early loading so the stylesheet
is placed in the head:

```php
add_filter('mlcm_enqueue_assets', function ($needed) {
    return $needed || is_page_template('with-category-menu.php');
});
```

The block is registered with API version 3 on WordPress 6.3+ and version 2 on
older supported versions.

## Compression and server configuration

Gzip sidecars are **off by default**. Enable them only if your origin is
configured to serve precompressed files; writing `.gz` files does not make a
web server use them. CDN compression works independently of this setting.

The plugin does not create or modify `.htaccess`. Configure the JavaScript
MIME type, compression and cache headers at your origin or CDN. Make sure the
uploads directory may serve JavaScript and that your Content Security Policy
allows scripts from it.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `mlcm_generation_published` | Action | Runs after a generation is published. Receives the generation metadata. |
| `mlcm_generation_ttl` | Filter | Seconds a superseded generation is kept. Default: 30 days. `0` disables removal. |
| `mlcm_enqueue_assets` | Filter | Return `true` to enqueue frontend assets early on the current request. |

## Security and limitations

- Generating and deleting menu files requires a nonce and the `manage_options`
  capability.
- The public AJAX endpoint is read-only and returns category navigation only.
  It uses no session nonce, so it works on cached pages. It is not an access
  control boundary for private content.
- Category names and URLs are escaped before they are displayed.
- If both the static file and the AJAX fallback fail, the dependent dropdowns
  stay disabled.
- Rate-limit abusive AJAX traffic at your firewall or CDN without blocking
  normal menu use.
- One bulk `get_terms()` call is not a promise of exactly one SQL query: term
  caches, URL filters and third-party code can add work. Large category trees
  need more PHP memory during generation and produce more small files.

Test the plugin with your own taxonomy, theme, cache plugin and CDN before a
production rollout.

## Development and Git workflow

Use a feature branch, open a pull request against `main`, and merge only after
review and green **Plugin tests and release** checks. No direct deployment or
automatic merge is configured.

```bash
npm ci
npm test
```

The build, version check and packaging steps live in the workflows and in two
local actions under `.github/actions/`; the repository has no local build
script. The pinned terser rebuilds all three JS pairs and clean-css all three
CSS pairs. CI rebuilds the assets and fails on differences, so stale minified
copies cannot pass a pull request.

To rebuild the minified files of a branch, run the **Build assets** workflow
manually on it (**Actions → Build assets → Run workflow**). It commits the
rebuilt files to that branch as `github-actions[bot]`. That commit does not
start other workflows, so push another commit or reopen the pull request to
run its checks. The same workflow runs automatically when a source asset, the
build action or the npm manifests change on `main`; it runs `npm test` before
it pushes rebuilt files.

GitHub Actions runs frontend regression tests against both source and minified
JS, PHP syntax checks, and integration tests on real WordPress with MySQL
across PHP 7.4/WordPress 5.8, PHP 8.2/WordPress 6.3, and PHP 8.3/8.4 with the
latest WordPress. No production credentials or deployment secrets are used.

Run PHP tests only against a **disposable WordPress installation and database**:

```bash
MLCM_TEST_ENV=1 wp eval-file tests/integration.php --path=/tmp/wordpress
for mode in blank early late widget block; do
  MLCM_TEST_ENV=1 wp eval-file tests/assets.php "$mode" --path=/tmp/wordpress
done
MLCM_TEST_ENV=1 wp eval-file tests/uninstall.php --path=/tmp/wordpress
```

These tests create categories, change settings and delete plugin data.
Automated tests do not replace staging checks with your theme, full-page
cache, CSP and CDN.

## Publishing a release

After the release changes are merged into `main` and validated on staging,
create and push an annotated tag:

```bash
git switch main
git pull --ff-only origin main
git tag -a v3.12.0 -m "Multi-Level Category Menu 3.12.0"
git push origin v3.12.0
```

Pushing a `vX.Y.Z` tag runs version validation, asset/minification tests,
package checks and the WordPress/PHP matrix. Only after all checks pass does
the workflow publish a GitHub Release with generated notes and these assets:

```text
multi-level-category-menu-3.12.0.zip
multi-level-category-menu-3.12.0.zip.sha256
```

The tag must match the PHP plugin header, `package.json`, `package-lock.json`,
`assets/js/block.json` and the version line at the top of this file. Its commit
must belong to `main`. For a later release, update all five versions, make
sure the minified assets are up to date, merge the PR, then push the new tag.
Only stable `vX.Y.Z` releases are supported. Do not move a published tag;
existing releases are never overwritten.

The ZIP contains one `multi-level-category-menu/` directory with only the
plugin PHP files, `readme.md`, `assets/` and `includes/`. Tests, build tools,
dependency files and GitHub configuration stay in the development repository.
GitHub's automatically generated source archives are not the installable
package; use the named plugin ZIP.

The `.github/actions/package` action builds the package with `git archive`
from the checked-out commit and then inspects it: one root directory, runtime
files only, the expected version and a matching SHA256 file. To build the same
ZIP locally from a clean, committed checkout:

```bash
git archive --format=zip --prefix=multi-level-category-menu/ \
  --output=multi-level-category-menu-3.12.0.zip HEAD -- \
  multi-level-category-menu.php uninstall.php readme.md assets includes
```

Pull requests and pushes to `main` or `develop` run validation only, and a
manual workflow run does not publish a release. Only the publishing job and
the **Build assets** job have `contents: write`; both use GitHub's built-in
token. A release is published on GitHub only: it does not install updates on
WordPress sites.

## Changelog

### 3.12.0

- Open categories explicitly marked as having no subcategories without an
  additional static-file or AJAX request.
- Mark leaf options in server-rendered HTML and dynamically populated selects.
- Preserve the previous loading behaviour for older HTML and data without a
  leaf marker; retain empty leaf files for backward compatibility.
- Add frontend and server-rendering regression tests for leaf markers.

### 3.11.0

- Add the `mlcm_generation_published` action, fired after a generation is
  published.
- Remove superseded generations automatically after a retention period
  (30 days by default, `mlcm_generation_ttl` filter).
- Retry a failed scheduled regeneration up to three times.
- Guard the private WordPress hierarchy helper used by the AJAX fallback.
- Rewrite the readme and the plugin description.

### 3.10.0

- Keep the server-rendered first level; no redundant level-1 request.
- Publish complete, immutable generations; keep the previous one during
  delayed regeneration and on failure.
- Generate from one bulk taxonomy query; split child data by parent.
- Enqueue assets only where a menu is present; defer scripts where supported.
- Make gzip optional and stop generating server configuration.
- Remove the change-event debounce and JavaScript resize styling.
- Add Unicode-aware names, request-local version caching and uninstall cleanup.
- Fix asynchronous selection races, duplicate menu IDs, an invalid
  `parent__in` query and duplicate block-editor registration.
- Remove the ineffective category-base option, escape admin messages and clamp
  the depth.
- Add reproducible minification, frontend regression tests and WordPress CI.

### Earlier releases

- **3.9.5:** delayed automatic regeneration with a configurable delay.
- **3.9.4:** separate `versions.js` manifest and dynamic file versioning.
- **3.9.3:** Block API v3 preparation and PHP requirement metadata.
- **3.9.0:** read-only frontend AJAX without a session nonce.
- **3.8.0:** configurable depth and atomic individual-file writes.
- **3.6.0:** JavaScript static files, gzip sidecars and cache management.

## Support

Report issues in the
[GitHub repository](https://github.com/gemuzkm/multi-level-category-menu).
