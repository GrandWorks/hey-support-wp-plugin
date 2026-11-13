# Hey Support: AI Sales & Shopping Agent — WordPress plugin

The official WordPress plugin for [Hey Support](https://hey.support). Drops an AI
chat widget onto any WordPress site, and forwards store and customer context
automatically when WooCommerce is active.

**Listing:** <https://wordpress.org/plugins/hey-support-ai-chatbot/>
**Requires:** WordPress 6.5+ · PHP 7.4+
**License:** GPL-2.0-or-later

This repository is the source of truth. WordPress.org SVN is a *release*
channel, published from here by tag — never edited directly.

---

## Install (for users)

Install from the WordPress plugin directory, activate, then open
**Settings → Hey Support** and connect the site to your agent.

You need a Hey Support account. The chatbot cannot work without one: replies are
generated on Hey Support's servers. New workspaces start with a 15-day free trial, no card needed.

## What it does

- Injects the widget site-wide, or per page via the `[hey_support]` shortcode or
  the Gutenberg block.
- Display rules: all pages, posts only, pages only, or shortcode/block only,
  with per-post exclusions.
- **WooCommerce-aware.** Store URL, currency and — for logged-in shoppers —
  customer identity and last order are forwarded as page context. Cart totals
  are read at runtime from WooCommerce's own Store API, so they stay correct on
  cached sites and on any theme.

---

## Development

No build step. No Composer requirement at runtime — the plugin ships no `vendor`
directory and the Gutenberg block is plain ES, not JSX.

```bash
composer install     # dev tooling only (PHPCS + WPCS)
composer run lint    # PHPCS against the WordPress standard
composer run lint:fix
```

CI runs on every pull request: `php -l` across 7.4–8.3, PHPCS/WPCS, the official
[Plugin Check](https://wordpress.org/plugins/plugin-check/) action, and an
ASCII-punctuation gate.

### Releasing

1. Bump the version in **three** places — the `Version:` header, the
   `HEY_SUPPORT_VERSION` constant, and `Stable tag:` in `readme.txt`. They have
   drifted before, and the constant is the cache-buster on the admin script.
2. Add an `== Upgrade Notice ==` entry, and bump `Tested up to:` if a new
   WordPress has shipped.
3. Tag `vX.Y.Z` and push the tag. `.github/workflows/deploy.yml` publishes to
   WordPress.org SVN.

Deployment needs two repository secrets. `SVN_USERNAME` is the WordPress.org
username (case-sensitive). `SVN_PASSWORD` is **not** the WordPress.org login
password — it is a separate SVN password, set at
`profiles.wordpress.org/me/profile/edit/group/3/`.

### Listing assets

`.wordpress-org/` syncs to SVN `/assets` on deploy: `icon-128x128.png`,
`icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`, and
`screenshot-N.png` matching the order of the Screenshots section in
`readme.txt`.

---

## Reporting issues

Bugs and feature requests: [GitHub issues](https://github.com/GrandWorks/hey-support-wp-plugin/issues).
Support questions about the service itself: <https://hey.support>.
