# CLAUDE.md

Guidance for working in this repository.

## What this is

A WP-CLI package (`wp devkit …`) for local WordPress development: test-user login cookies, fixtures, and database snapshots. `devkit.php` registers the commands; each lives in `src/`. `browser.mjs` is a Playwright helper built on `wp devkit login`. Usage is in `README.md`.

## Rules that matter more than features

- **The guards are the product.** Every command calls `Config::require_local()` first, and `login` only serves test-domain users. New commands must do the same. Never add a way to switch a guard off (an empty list is an error by design), a bypass flag, or anything that works over HTTP.
- **Nothing belongs in a site's web root.** Config, fixtures and snapshots resolve to the site root above it, and paths inside the web root are refused.
- **Site-specific code never goes in this repo.** It's public. Each site's `devkit.php` and fixture file live, untracked, in that site's root. No client names, URLs, or data here.
- **Restore is destructive.** It must keep taking a pre-restore snapshot and asking for confirmation.

## Conventions

- WordPress coding standards: tabs in PHP/JS, spaces in JSON/YAML.
- Namespace `WP_Local_Devkit`. PHP 7.4+.
- Test against a throwaway local install (its own database) for anything that writes or restores, not a site someone is working on.
