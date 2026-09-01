=== Hey Support: AI Sales & Shopping Agent ===
Contributors: grandworksco, siddr, wazidshah
Tags: ai, chatbot, ecommerce, woocommerce, live-chat
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI agent that answers from your content, shows live products from your store, captures leads, and hands off to a human. Detects WooCommerce.

== Description ==

Hey Support is a hosted AI chatbot built for small and mid-size businesses. Train it on your website, docs, and FAQs in minutes. It answers customer questions, qualifies leads, and escalates to a human when it should.

This plugin embeds the widget on any WordPress site in two clicks:

1. Press **Connect to Hey Support** under **Settings > Hey Support**.
2. Pick your agent. The widget appears on every page.

**This plugin requires a Hey Support account.** Hey Support is a paid third-party service with a free tier; the chatbot cannot work without it, because replies are generated on Hey Support's servers. Sign up at https://hey.support and see the "External services" section below for exactly what is sent and when.

= WooCommerce-aware =

If WooCommerce is active, the plugin automatically forwards store and customer context into each conversation:

* Store URL and currency
* Customer ID and email (when the shopper is logged in)
* Last order ID and status (when the shopper is logged in)
* Current cart total and item count

The chatbot can use these fields to answer order questions, recover abandoned carts, or recommend products without you wiring anything up.

Cart figures are read at runtime from WooCommerce's own Store API rather than being written into the page, so they stay correct on cached sites and on any theme.

= Other features =

* Shortcode `[hey_support]` for per-page placement and overrides.
* Gutenberg block. Drop the chatbot onto a specific page from the block editor.
* Show on / hide on rules: all pages, posts only, pages only, or shortcode-only.
* Per-page exclusions by post ID.
* Live cart updates. Cart totals in the chat follow along without a page reload.

= What you'll need =

A Hey Support workspace at https://hey.support, and the public chatbot ID of the chatbot you want to embed. That ID is the only thing the widget needs.

A workspace API token is optional. It powers the **Verify token** and **Refresh chatbot list** buttons on the settings screen, which use the Hey Support public API. That API is available on the Scale plan and up; on every other plan, paste the chatbot ID by hand.

== External services ==

This plugin connects to Hey Support (https://hey.support), the hosted service
that powers the AI chatbot. This connection is required - the chatbot cannot
function without it, since replies are generated server-side by Hey Support.

The plugin communicates with Hey Support in the following ways:

* Chatbot widget: the widget script is loaded from
  https://hey.support/api/chat/embed.js on every page where the widget is shown.
  Because this is a third-party script running in each visitor's browser, Hey
  Support receives standard request metadata such as the visitor's IP address,
  user agent, and the URL of the page the widget loaded on, even if the visitor
  never opens the chat. Once a visitor does chat, their messages and the
  conversation context are sent to Hey Support so the chatbot can generate
  replies.
* WooCommerce context (only when WooCommerce is active): the plugin forwards the
  store URL and currency, the current cart total and item count, and - only when
  the shopper is logged in - the customer ID, customer email, and last order ID
  and status, so the chatbot can answer order- and cart-related questions.
* Connecting your agent: when you press "Connect to Hey Support", your site
  address, site name and whether WooCommerce is active are sent to Hey Support
  so it can show you your agents and confirm which site is being connected. Hey
  Support then makes one request back to your site to confirm the connection
  started here, and your server makes one request to Hey Support to complete it.
  No account credentials are involved, and the only thing stored on your site as
  a result is your public chatbot ID.
* Checking your store: when you click "Check my store is reachable", your own
  site is asked whether its WooCommerce Store API answers. That request does not
  leave your server.

Data is sent to Hey Support when the widget loads, when a visitor interacts with
the chatbot, or when you perform the admin actions above.

This service is provided by Hey Support. By using this plugin you agree to
Hey Support's policies:

* Terms of Service: https://hey.support/terms
* Privacy Policy: https://hey.support/privacy

== Installation ==

1. Install through the WordPress Plugins screen, or upload the `hey-support-ai-chatbot` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to **Settings > Hey Support** and press **Connect to Hey Support**.
4. Pick the agent you want on this site. You land back here connected.

There is no ID to find and nothing to paste. If Hey Support cannot reach your site, which is normally only true of local development installs, you can enter a chatbot ID by hand instead.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. The plugin works on any WordPress site. WooCommerce features activate only when WooCommerce is installed.

= Where is my data sent? =

Conversations and the WooCommerce context described in the "External services" section are sent to and processed by Hey Support (https://hey.support), stored in the workspace tied to your account. The chatbot widget itself is loaded from https://hey.support/api/chat/embed.js over HTTPS. See the "External services" section for full details.

= Is my API token exposed on the page? =

No. The embed carries only your public chatbot ID. The token is used server-side by the Verify and Refresh buttons, is never printed into your pages, and is never shown back to you after saving.

= Why don't the Verify token and Refresh chatbot list buttons work? =

Those two buttons call the Hey Support public API, which is available on the Scale plan and up. On other plans they will report that API access is not on your plan. Nothing is broken: paste your chatbot ID into the field directly and the widget works exactly the same.

= How do I show the widget on only specific pages? =

In **Settings > Hey Support**, set "Show widget on" to "Only where [hey_support] shortcode or block is used", then drop the `[hey_support]` shortcode (or the Hey Support block) on the pages where you want it.

= Can I override the chatbot per page? =

Yes. The shortcode and Gutenberg block both accept a chatbot ID override:

`[hey_support chatbot_id="abc123"]`

The shortcode can also force auto-open on or off for a single page, overriding the site-wide setting in both directions:

`[hey_support auto_open="false"]`

= Does the cart total update live? =

Yes. The plugin reads the cart from WooCommerce's Store API and refreshes on WooCommerce's standard cart events (`added_to_cart`, `removed_from_cart`, `wc_fragments_refreshed`, `updated_cart_totals`), so totals stay accurate without a page reload.

= Is there a free tier? =

Yes. Sign up at https://hey.support and start on the Free plan. Paid plans are one flat monthly price, never per message.

== Screenshots ==

1. Settings > Hey Support after connecting, showing the agent, where the widget appears, and your store.
2. The connect screen before an agent is chosen.
3. Choosing an agent on Hey Support.
4. Hiding the widget on chosen pages, by name.
5. Live products from your store, shown in chat with add to cart.
6. The Hey Support block in the editor.

== Changelog ==

= 2.0.0 =
* Renamed to "Hey Support: AI Sales & Shopping Agent". Same plugin and the same settings -- the name now says what it does on a store. Nothing to reinstall or reconnect.
* One-click connect. Press Connect, pick your agent on Hey Support, and land back here connected. No chatbot ID to find and nothing to paste, on every plan.
* Rebuilt the settings screen. Before you connect it asks one thing; afterwards it shows where the widget appears, your store, and an Advanced section for everything else.
* "Hide on these post IDs" is now a search-by-name picker. WordPress never showed you post IDs, so finding them meant hunting through URLs. Your existing choices are kept exactly as they were.
* Removed the workspace API token field. Connecting replaced both things it did, and it only ever worked on the Scale plan.
* Added a Settings link on the Plugins screen, documentation and support links, and a reminder if the plugin is installed but not connected.
* The screen now names the agent it is connected to, rather than just saying "connected", and links straight to your site so you can go and look at the widget.
* Each placement option now explains what it does, so you can tell "Blog posts only" from "Pages only" without trying it.
* Added Disconnect. Unlinking a site previously meant clearing a field by hand.
* Fixed: the page exclusion list is no longer offered when the widget is set to appear only where you place it yourself, where it could never have had any effect. Your list is kept either way.
* Fixed: an expired connect link no longer reports that the plugin is missing.

= 1.1.0 =
* New "Live products in chat" setting: connect this store's public catalogue so the chatbot can show real products, prices, and stock, and add them to the shopper's cart.
* Added a "Check my store is reachable" button that tests this site's own WooCommerce Store API and names the fix when something blocks it (permalinks, security plugins, caching).
* AI Actions now connect with a single approval in your WooCommerce admin. The REST API key is created and delivered automatically, so there is nothing to copy and paste.

= 1.0.0 =
* Initial release.
* Settings screen: chatbot ID, optional workspace API token, enable-on rules, per-post exclusions, auto-open.
* Site-wide auto-injection of the Hey Support widget loader in `wp_footer`.
* `[hey_support]` shortcode for per-page placement, chatbot overrides, and forcing auto-open on or off.
* Server-rendered Gutenberg block with chatbot ID and auto-open controls.
* Conditional WooCommerce extension forwarding store, customer, and cart context, with live cart updates read from the WooCommerce Store API.
* Verify-token and refresh-chatbot-list helpers for workspaces on plans that include public API access.

== Upgrade Notice ==

= 2.0.0 =
Connecting is now one click: press Connect and pick your agent. Your existing settings, including which pages the widget is hidden on, are kept. The plugin is also renamed to "Hey Support: AI Sales & Shopping Agent".

= 1.1.0 =
Live products in chat for WooCommerce, a one-approval AI Actions connection, and a store reachability check. Fixes an admin script that could stay cached from 1.0.0.

= 1.0.0 =
First release.
