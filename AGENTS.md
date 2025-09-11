# Repository Guidelines

## Project Structure & Module Organization
- Entry point: `wp-wallet-pass.php` — registers admin settings, shortcode, rewrite rules, and Apple/Google handlers.
- Dependencies: `composer.json` + `vendor/` (pkpass/pkpass, firebase/php-jwt). Run Composer after cloning.
- Assets: `assets/` for pass images (`icon.png` required, `logo.png` optional). Create if missing.
- Autoload: `MWP\\` → `inc/` (reserved for future classes). Keep plugin-specific code under this namespace and directory when adding files.

## Build, Test, and Development Commands
- `composer install` — install PHP dependencies into `vendor/`.
- Local run: place this folder in `wp-content/plugins/wp-wallet-pass` and activate in WP Admin.
- Flush rewrites: re-activate the plugin or visit WP Admin → Settings → Permalinks → Save.
- Smoke test:
  - Add `[member_wallet_pass]` to a page viewing a user context; click “Add to Apple/Google Wallet”.
  - Apple requires `p12` path/password and identifiers; Google requires issuer ID and Service Account JSON.

## Coding Style & Naming Conventions
- PHP 8+. Follow WordPress Coding Standards: tabs for indentation, spaces for alignment, snake_case for functions/hooks.
- Prefix: use `mwp_` for options, hooks, query vars, and shortcodes. Classes use `MWP_*`.
- Sanitization/escaping: sanitize all input (`sanitize_text_field`, `absint`), escape output (`esc_*`). Avoid new globals.

## Testing Guidelines
- No automated tests in repo. Perform manual checks:
  - Nonce validation blocks direct requests.
  - Settings errors render meaningful messages.
  - Apple: `.pkpass` downloads and opens; images load.
  - Google: redirect to `https://pay.google.com/gp/v/save/...` works.

## Commit & Pull Request Guidelines
- Commits: concise, imperative, scoped (e.g., `apple: fix WWDR cert path`, `google: improve JWT claims`). Reference issues when available.
- PRs: include summary, rationale, test steps (URLs/shortcode used), screenshots or logs, and any configuration values used (redact secrets).

## Security & Configuration Tips
- Store certificate files and service account JSON outside webroot when possible; reference absolute paths; restrict permissions (e.g., `600`).
- Never commit secrets or client assets. Disable `WP_DEBUG` in production.
