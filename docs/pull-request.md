# v3.10.0: eight optimizations, regression tests and reproducible assets

Closes #1.

## Changes

- Preserve the existing SSR level-1 optimization.
- Publish immutable, per-parent menu snapshots after complete generation.
- Keep the previous snapshot during delayed regeneration and on failure.
- Build the tree with one `get_terms()` invocation; retain AJAX fallback.
- Add defer with backwards compatibility and conditional frontend assets.
- Make gzip sidecars opt-in; stop generating `.htaccess`.
- Fix stale-response races, duplicate IDs, Unicode handling, option-cache
  invalidation, editor dependencies and uninstall cleanup.
- Rebuild all six JS/CSS minified pairs using locked Terser/clean-css tooling.
- Update plugin description, version metadata, cache documentation and rollback.
- Add GitHub Actions for branch/PR validation, not automatic deployment.

## Verification before opening the PR

- 16 Node/jsdom regression tests pass against source and minified frontend,
  admin actions and block-editor registration.
- PHP syntax checks pass for all PHP files.
- Real WordPress 7.1.3 / PHP 8.5.4 local integration suite passes, including
  query failure, partial-generation failure, publication failure and locking.
- Separate-process asset tests pass for blank pages, early shortcode, late
  shortcode, widget and dynamic block.
- Uninstall removes options, scheduled events and cache files.

The GitHub Actions matrix is the authoritative result for PHP 7.4 / WP 5.8,
PHP 8.2 / WP 6.3, and PHP 8.3/8.4 / latest WordPress.

## Review and rollout

No changes have been deployed to the production site or merged into `main`.
After review and green CI: install on staging, regenerate files, purge page
cache, verify frontend/editor/mobile with the actual theme and CDN.

Old generations are retained intentionally for cached HTML, so monitor disk
usage. The manifest does not refresh HTML already cached by a page-cache plugin.
There is no automatic CDN/page-cache purge or snapshot pruning.

To roll back, reinstall the previous plugin, regenerate its legacy cache format,
and purge page/CDN cache. Detailed scope: `docs/optimization-ticket.md`.
