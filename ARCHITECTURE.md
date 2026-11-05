# ARCHITECTURE.md — Hey Support WordPress plugin

Read this before touching anything here. Every rule below has a real failure
behind it; several are invisible in the code and one got version 1.0.0 rejected
from the plugin directory.

This repo is the **source of truth**. WordPress.org SVN is a release channel
published by tag, never edited directly. The plugin previously lived inside the
`hellothere` monorepo at `integrations/wordpress/` and drifted from the shipped
copy within one release — that is why it moved out.

---

## Architecture

Pure PHP 7.4+ / WP 6.5+. No Composer at runtime, no build step, no `vendor`.
The Gutenberg block is plain ES, not JSX, deliberately — it keeps the whole
plugin trivially reviewable for WordPress.org.

Storage is a single `wp_options` row, `hey_support_settings`, so uninstall is
one row to clean (per blog on multisite).

| File | Role |
|---|---|
| `hey-support-ai-chatbot.php` | Header, constants, requires, activation seed |
| `includes/options.php` | Option shape, defaults, sanitize, masking |
| `includes/embed.php` | The enqueue + `data-*` attributes. Read the docblock |
| `includes/shortcode.php` · `gutenberg-block.php` | Two of the three surfaces |
| `includes/woocommerce.php` | Store context, the runtime cart bridge, the WhatsApp opt-in |
| `includes/api-client.php` | Bearer GETs against the Hey Support v1 API |
| `admin/settings-page.php` · `settings.js` | The settings screen and its AJAX |

---

## Invariants an agent must not break

### The block directory name must equal the path passed to `register_block_type()`

`blocks/hey-support-ai-chatbot`. When it didn't, `register_block_type()` failed
its internal `file_exists()` check and handed the **absolute filesystem path** to
`WP_Block_Type_Registry::register()` as the block NAME — which contains
uppercase characters on most machines, so WordPress printed *"Block type names
must not contain uppercase characters"* on **every admin page** and the block
never registered at all. **That is what got 1.0.0 rejected.** Verified by A/B
against a real install.

### DECIDE and ENQUEUE are separate, on purpose

All three surfaces (footer auto-inject, shortcode, block) only *record intent*
via `hey_support_request_embed()`. A single `wp_footer` handler at **priority 5**
performs the one real `wp_enqueue_script` (footer scripts print at 20).

Non-negotiable: in a **block theme** a dynamic block's `render_callback` runs
before `wp_enqueue_scripts` and before `wp_head`, and an enqueue made that early
does not survive to the footer — enqueuing from the render callback silently
emitted nothing. The shortcode and block render an **empty string**; the launcher
is `position: fixed`, so there is nothing to place inline, and returning markup
only fed it to `wpautop`.

### Per-visitor data must never enter `data-context`

That payload is baked into page HTML, so behind a full-page cache one visitor's
cart is served to the next. `data-context` carries **store-level facts only**
(`platform`, `shop_url`, `currency`) plus logged-in identity, which every major
cache bypasses on the `wordpress_logged_in_*` cookie — belt-and-braced with
`DONOTCACHEPAGE` on `template_redirect`.

Cart figures arrive **at runtime** from WooCommerce's own Store API
(`GET /wp-json/wc/store/v1/cart`) posted via `HS_SET_CONTEXT`. This replaced
scraping guessed mini-cart CSS selectors, which failed on most themes and could
only ever recover the item count.

`totals.total_price` is a **string in minor units** with a currency-dependent
`totals.currency_minor_unit`. Never divide by a hardcoded 100 — JPY has 0 minor
units, so `1500` means 1500.

### The embed script's `$ver` is `null`, and that is deliberate

`null` means **no `?ver=` is appended at all**, which is correct for a remote
script served from hey.support behind its own `Cache-Control`. Passing
`HEY_SUPPORT_VERSION` pins the widget loader to the *plugin* version, busting CDN
cache on unrelated plugin releases and failing to bust when the widget itself
changes. This has been "fixed" wrongly once already, with the `phpcs:ignore`
explaining it left in place above the changed line.

### Three version strings must move together

The `Version:` header, the `HEY_SUPPORT_VERSION` constant, and `Stable tag:` in
`readme.txt`. The constant is the cache-buster on `admin/settings.js`; when 1.1.0
bumped the header and not the constant, upgraders would have received the old
admin script — the new button rendering and doing nothing.
(`blocks/…/index.asset.php` carries a fourth; bump it whenever `index.js`
changes.)

### The API token field is write-only

It renders empty, an empty submission preserves the stored value, and clearing
requires the explicit checkbox. Consequently **empty means "use the saved token"**
on the server, not "no token" — treating it as absent is what made Verify report
*"Invalid token"* to every user who had already saved one.

The token is never printed into a page. The embed carries only the public
chatbot ID.

### The WhatsApp opt-in writes two meta keys, and the server reads exactly those

`_hey_support_whatsapp_optin = 'yes'` and `_hey_support_whatsapp_optin_at` (ISO
8601 UTC). The Hey Support server reads them from the order over the WooCommerce
REST API as the shopper's consent, so **renaming either one silently stops every
update**. Both checkouts must end in these keys; the block checkout stores its own
value elsewhere, which is why it is copied across.

WooCommerce reuses one order across payment attempts, so an explicit untick has to
delete the keys, or a shopper who ticks, has a payment fail, then unticks and pays
keeps the old tick. A block request that omits the field withdraws nothing.

The box is off unless the merchant turned on the setting AND an agent is connected.
The setting's card only renders while connected, so without the second half a
disconnected site could never turn the box off.
`tests/whatsapp-optin-test.php` pins all of this; run it with `php`.

### `readme.txt`'s "External services" section is a listing requirement

Not documentation. The approval email flags permanent removal for *"tracking
users without consent"*, and that section is what discloses that the widget
script gives hey.support every visitor's IP, user agent and page URL even if
they never open the chat. **Never trim it for brevity, and add any newly
forwarded data to it** — including new outbound admin requests.

### `index.asset.php` is required

Since WP 6.5 the file is optional, but a missing one silently registers the
editor script with **zero dependencies and no version**, so `wp.components` and
`wp.i18n` are not guaranteed loaded. Hand-written, because there is no bundler.

### ASCII punctuation only

No em dashes, smart quotes or ellipsis characters in PHP, JS, JSON or
`readme.txt`. They reach translators and the public listing. CI fails on them —
they have leaked in three times, including inside comments and section rules.

### `.distignore` at the repo ROOT is safe; inside a plugin folder it is not

Plugin Check reports **any** hidden file inside the plugin directory as an ERROR.
Here the plugin files sit at the repo root and the deploy action strips
`.distignore` along with `.git*` before touching SVN, so it never reaches the zip
WordPress.org serves.

---

## Testing and release

CI on every PR: `php -l` across 7.4–8.3, the behaviour tests in `tests/`,
PHPCS/WPCS, the official Plugin Check action, and the ASCII gate. The approval email itself recommends PHPCS+WPCS and
Plugin Check, so this is the reviewer's own advice automated.

Release: bump the three versions, add an `== Upgrade Notice ==` entry, bump
`Tested up to:` if a new WordPress shipped, then push a `vX.Y.Z` tag.
`SVN_USERNAME` is the WordPress.org username (case-sensitive); `SVN_PASSWORD` is
the **separate** SVN password, not the account password.

Listing assets live in `.wordpress-org/` and sync to SVN `/assets`. Screenshot
files must match the order of the Screenshots section in `readme.txt`.
