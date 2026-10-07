# Multi-Level Category Menu

Version: **3.10.0**
Requires WordPress: **5.8+**
Tested up to: **7.1**
Requires PHP: **7.4+**
License: **GPL v2 or later**

Cache-friendly category navigation for WordPress, available as a shortcode, a
dynamic Gutenberg block and a sidebar widget. The first dropdown is rendered
by PHP; subsequent dropdowns load small static files for the selected parent,
with a public, read-only AJAX fallback.

## Features

- Up to ten category levels, custom root and excluded branches.
- Horizontal/vertical layouts, labels, sizing, colors and optional Go button.
- One taxonomy selection per static generation, with in-memory tree traversal.
- Optional delayed automatic regeneration, keeping the last published snapshot
  available while WP-Cron waits or generation fails.
- Immutable generation directories, published only after all files are ready.
- Per-parent child files, including empty leaf files, instead of entire levels.
- No redundant level-1 request when PHP has populated the first dropdown.
- Shared in-flight static requests and protection against out-of-order responses.
- Conditional frontend assets, genuine minified JS/CSS, no frontend jQuery.
- Deferred scripts on WordPress 6.3+; footer loading on older supported versions.
- Unicode uppercasing with `mbstring`, locale sorting with `intl`, graceful
  fallbacks when optional extensions are absent.
- Protected admin AJAX actions; frontend read-only AJAX has no session nonce.

## Installation and usage

1. Copy the plugin directory into `wp-content/plugins/` and activate it.
2. Open **Settings → Category Menu**, save settings, then **Generate Menu Files**.
3. Add `[mlcm_menu]`, the **Category Menu** block, or the sidebar widget.
4. Purge full-page cache after deploying/upgrading or changing the menu.

```text
[mlcm_menu layout="horizontal" levels="4"]
```

Settings include initial visible levels, maximum depth, custom root ID,
comma-separated excluded IDs, labels, dimensions, button styling, static files,
automatic regeneration, a 10–60 second regeneration delay, and optional gzip
sidecars. Excluding a parent removes its branch from traversal. An explicitly
chosen custom root is the traversal starting point, not a displayed item.

Links always come from WordPress `get_category_link()`, including permalink
and SEO-plugin filters. The previously ineffective “Use Category Base” checkbox
has been removed; configure real category permalinks in WordPress instead.

## Cache lifecycle and deployment

New files are written to `uploads/mlcm-menu-cache/gen-<uuid>/`:

```text
level-1.js
l2-<parent-id>.js
l3-<parent-id>.js
...
versions.js
meta.js
```

The `mlcm_generation` option points to the published generation. Generation
uses a filesystem lock and a single publication point after successful writes.
A failed query, link resolution, write or publication does not replace that
pointer. The lock assumes a shared local filesystem; multi-origin deployments
must share the cache directory and database and validate locking semantics.

Cached HTML keeps its original generation URL and first-level options.
**A manifest does not make cached HTML fresh.** Old snapshots and legacy
`level-N.js` files are deliberately retained for those pages. Fresh PHP renders
use the new generation after publication. Purge the page cache when immediate
visibility is required; no FlyingPress, WP Rocket or Cloudflare purge API is
called automatically.

When auto-regeneration is enabled, category saves schedule a single delayed
WP-Cron job. Repeated edits reset the delay, and the old snapshot remains usable.
When auto-regeneration/static mode is disabled, a category change detaches the
snapshot for new renders and cancels pending work; old files remain for already
cached pages. Manual generation remains available.

**Delete Cache Files** cancels pending regeneration and removes all generated
snapshots and legacy data files. Cached pages may then use AJAX fallback.
For maintenance: delete cache, regenerate, then purge page/CDN cache. Monitor
disk usage on frequently changing sites: automatic snapshot pruning is
intentionally not enabled because the plugin cannot know your page-cache TTL.

WP-Cron execution can be delayed on a fully cached or low-traffic site. If your
hosting supports it, use a system scheduler to run due WordPress cron events;
only disable request-driven WP-Cron once that scheduler is working.

### Asset loading

The plugin detects a shortcode/block in the queried post early. For widgets,
synced blocks and template-generated menus detected after `wp_head`, it prints
the stylesheet immediately before the first menu and queues JS in the footer.
There is no guarantee of head placement for arbitrary late-rendered templates.
Themes must call `wp_head()` and `wp_footer()`.

For a theme that knows a menu will appear, opt into early loading:

```php
add_filter('mlcm_enqueue_assets', function ($needed) {
    return $needed || is_page_template('with-category-menu.php');
});
```

This avoids a late stylesheet in that known template without loading frontend
assets on every page. Block registration uses API v3 on WordPress 6.3+ and v2 on
older supported WordPress. The block editor script and stylesheet are registered
once with explicit dependencies.

### Compression and server configuration

Gzip sidecars are **off by default**. Enable them only when the origin is
configured to serve precompressed files. Generating `.gz` files alone does not
make a web server use them. CDN compression can be used independently.

No `.htaccess` is created or rewritten. Existing files from older versions are
left untouched. Set JavaScript MIME, compression and cache headers at your
origin/CDN; immutable generation URLs can be cached long-term. Ensure uploads
allow JavaScript and your CSP allows the relevant script origin.

## Security and limitations

Admin generation/deletion require a nonce and `manage_options`. Public AJAX
returns category navigation only; it is not an access-control boundary for
private content. Server-supplied admin messages and frontend option data are
escaped before display.

Static files may be unavailable after explicit deletion, deployments or origin
errors. The loader falls back to AJAX; if that fails, dependent selects stay
disabled rather than navigating using stale options. Rate-limit abusive AJAX
traffic separately without blocking normal menu usage.

One bulk `get_terms()` invocation is not a promise of exactly one SQL query:
term caches, hierarchy lookups, URL filters and third-party code can add work.
Large trees trade lower query overhead for PHP memory and more small files.
Test against your taxonomy, plugins and CDN before production rollout.

## Development and Git workflow

Use a feature branch, open a pull request against `main`, and merge only after
review and green **Plugin tests and release** checks. No direct deployment or automatic
merge is configured. This is a lightweight branch/PR workflow, not a mandatory
Git Flow model with permanent `develop`/release branches.

```bash
npm ci
npm run build
npm test
```

Terser rebuilds all three JS pairs; clean-css rebuilds all three CSS pairs.
Commit sources and generated `.min.*` files together. CI rebuilds assets and
fails on differences, so stale minified copies cannot pass.

GitHub Actions runs frontend regression tests against both source and minified
JS, PHP syntax checks, and real WordPress/MariaDB-compatible MySQL integration
tests across PHP 7.4/WordPress 5.8, PHP 8.2/WordPress 6.3, and PHP 8.3/8.4 with
the latest WordPress. No production credentials or deployment secrets are used.

Run PHP tests only against a **disposable WordPress installation and database**:

```bash
MLCM_TEST_ENV=1 wp eval-file tests/integration.php --path=/tmp/wordpress
for mode in blank early late widget block; do
  MLCM_TEST_ENV=1 wp eval-file tests/assets.php "$mode" --path=/tmp/wordpress
done
MLCM_TEST_ENV=1 wp eval-file tests/uninstall.php --path=/tmp/wordpress
```

These tests create categories, change settings and delete plugin data.
See [the implementation ticket](https://github.com/gemuzkm/multi-level-category-menu/issues/1) for the eight-point
scope and acceptance criteria. Automated tests do not replace staging checks
with your theme, full-page cache, CSP and CDN.

## Publishing a release

After the release changes are merged into `main` and validated on staging,
create and push an annotated tag:

```bash
git switch main
git pull --ff-only origin main
git tag -a v3.10.0 -m "Multi-Level Category Menu 3.10.0"
git push origin v3.10.0
```

Pushing a `vX.Y.Z` tag runs version validation, asset/minification tests, package
tests and the WordPress/PHP matrix. Only after all checks pass does the workflow
publish a GitHub Release with generated notes and these assets:

```text
multi-level-category-menu-3.10.0.zip
multi-level-category-menu-3.10.0.zip.sha256
```

The tag must match the PHP plugin header, `package.json`, `assets/js/block.json`
and the README version. Its commit must belong to `main`. For a later release,
update all four versions, rebuild/commit assets, merge the PR, then push the
new tag. Only stable `vX.Y.Z` releases are supported by this workflow.
Do not move a published tag; existing releases are never overwritten.

The ZIP contains one `multi-level-category-menu/` directory with only the plugin
PHP files, `readme.md`, `assets/` and `includes/`. Tests, build tools, dependency
files and GitHub configuration stay in the development repository, not the ZIP.
GitHub's automatically generated source archives are not the installable asset
maintained by this workflow; use the named plugin ZIP.

To build the same package locally from a clean, committed checkout:

```bash
npm ci
npm run build
npm test
npm run package
```

Packaging uses `git archive` and writes ZIP/SHA256 files to ignored `dist/`.
The packaging tests also require Python 3's standard library to inspect ZIP
contents. No separate ZIP library is needed at runtime.

PRs and pushes to `main`/`develop` run validation only. A manual workflow run
does not publish a release. The publishing job alone has `contents: write`;
it uses GitHub's built-in token, with no personal access token required.
This publishes on GitHub only: it does not install updates on WordPress.

## Changelog

### 3.10.0

- Retain the prior SSR optimization; test it against source and minified JS.
- Preserve old snapshots during delayed regeneration; publish a complete new
  generation only after successful writes.
- Generate from one bulk taxonomy selection; split child data by parent.
- Add conditional assets and compatible deferred loading.
- Make gzip optional and stop generating server configuration.
- Remove change-event debounce and JS resize styling; use existing mobile CSS.
- Add Unicode-aware names, request-local version caching and uninstall cleanup.
- Fix asynchronous selection races, duplicate menu IDs, invalid `parent__in`
  query assumptions and duplicate block-editor registration.
- Remove ineffective category-base UI, escape admin messages and clamp depth.
- Add reproducible minification, frontend regression tests and WordPress CI.
- Clarify cache freshness, retained snapshots, migration and deployment limits.

### Earlier releases

- **3.9.5:** delayed automatic regeneration and configurable delay.
- **3.9.4:** separate `versions.js` manifest and dynamic file versioning.
- **3.9.3:** Block API v3 preparation and PHP requirement metadata.
- **3.9.0:** read-only frontend AJAX without a session nonce.
- **3.8.0:** configurable depth and atomic individual-file writes.
- **3.6.0:** JavaScript static files, gzip sidecars and cache management.

## Support

Report issues in the [GitHub repository](https://github.com/gemuzkm/multi-level-category-menu).
