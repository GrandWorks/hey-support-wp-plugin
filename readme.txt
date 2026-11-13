=== Hey Support: AI Sales & Shopping Agent ===
Contributors: grandworksco, siddr, wazidshah
Tags: ai, chatbot, ecommerce, woocommerce, live-chat
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI agent for your site. Answers from your own pages, shows live WooCommerce products, takes leads and hands over to you.

== Description ==

Your customers ask the same questions day and night. Hey Support answers them from your own pages, and brings you the conversations that need a person.

It is an AI agent you train on your own website. Paste your address, check what it learned, and it starts answering. This plugin puts it on your WordPress site with no code:

1. Press **Connect to Hey Support** under **Settings > Hey Support**.
2. Pick your agent. The chat appears on your site.

There is no ID to find and nothing to paste.

= What your agent does =

* **Answers from your own pages and files**, and shows the page each answer came from. When they don't cover a question, it says so.
* **Takes contact details in the conversation**, when a visitor shows interest. You choose how hard it asks.
* **Books meetings** into Google Calendar or Cal.com, at times that are really free.
* **Hands over to you** when someone needs a person. You get the whole conversation, so nobody repeats themselves.
* **Replies in the visitor's language.**

You decide what it knows, how it talks and the rules it cannot break, in your Hey Support dashboard.

= On a WooCommerce store =

If WooCommerce is active, your agent can help people shop as well as answer questions.

* **Product answers.** Real products from your store, with live prices and stock, shown in the chat with add to cart. On every plan.
* **Order lookups.** It answers "where is my order?" from the order number and the email on it, and can change a delivery address before the order ships. On Growth and up. This reads customer orders, so you approve it once in your own WooCommerce admin.
* **Store and cart context.** The plugin tells your agent which store it is on and what is in the cart:
    * Store URL and currency
    * Current cart total and item count
    * Customer ID and email, when the shopper is logged in
    * Last order ID and status, when the shopper is logged in

Cart figures are read from WooCommerce's own Store API while the page is open, not written into the page. They stay correct on cached sites and on any theme, and they follow the cart without a reload.

= Where the chat appears =

* Everywhere, on blog posts only, on pages only, or only where you place it.
* Hide it on pages you choose, such as checkout. You pick them by name.
* Place it yourself with the Hey Support block or the `[hey_support]` shortcode.

= This plugin needs a Hey Support account =

Hey Support is a paid third-party service. New workspaces start with a 15-day free trial, and no card is needed. The chat cannot work without an account, because replies are generated on Hey Support's servers. Sign up at https://hey.support, and see "External services" below for exactly what is sent and when.

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
* WhatsApp order updates (only when you turn on "Offer WhatsApp order updates at
  checkout"): a shopper who ticks the checkout box has that choice and the time
  stored on their order. The plugin itself sends nothing more. Hey Support reads
  the order, including the billing phone number and name, through the WooCommerce
  connection you set up under "Order lookups", and sends the updates to that number
  from your own WhatsApp Business number through Meta's WhatsApp Business Platform.
* Connecting your agent: when you press "Connect to Hey Support", your site
  address, site name and whether WooCommerce is active are sent to Hey Support
  so it can show you your agents and confirm which site is being connected. Hey
  Support then makes one request back to your site to confirm the connection
  started here, and your server makes one request to Hey Support to complete it.
  No account credentials are involved, and the only thing stored on your site as
  a result is your public chatbot ID.
* Checking your store: when you click "Test this site first", your own
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
4. Sign in, or create an account, and pick the agent for this site. You land back here with it live.

If Hey Support cannot reach your site from the internet, which is normally only true of a local install, choose **Enter a chatbot ID instead**.

== Frequently Asked Questions ==

= What does it cost? =

One flat price per plan, never per message or per resolution. New workspaces start with a 15-day free trial, and no card is needed. Plans are at https://hey.support/pricing.

= Will it make things up? =

It answers only from your own pages and files, and shows the source under every answer. When they don't cover a question, it says so and offers to bring in your team.

= Do I need to be technical? =

No. Connecting is one button, and there is nothing to copy or paste. Your agent learns from your website address, which you give it in your Hey Support dashboard.

= Do I need WooCommerce? =

No. The plugin works on any WordPress site. The store features appear only when WooCommerce is active.

= Which plan do I need for my store? =

Product answers, with live prices, stock and add to cart, are on every plan. Order lookups and address changes need Growth or above.

= Where is my data sent? =

Conversations, and the WooCommerce context listed under "External services", are sent to Hey Support (https://hey.support) and stored in the workspace tied to your account. The chat itself is loaded from https://hey.support/api/chat/embed.js over HTTPS. "External services" has the full detail.

= Is anything secret printed on my pages? =

No. The page carries only your agent's public chatbot ID, which is safe to show.

= How do I show the chat on only some pages? =

In **Settings > Hey Support**, under **Where it appears**, choose **Only where I place it**. Then add the Hey Support block, or the `[hey_support]` shortcode, to each page where you want it.

To do the opposite and hide it on a few pages, add them under **Except on these pages**.

= Can I use a different agent on one page? =

Yes. The shortcode and the block both take a chatbot ID:

`[hey_support chatbot_id="abc123"]`

The shortcode can also turn auto-open on or off for one page, whatever the site-wide setting is:

`[hey_support auto_open="false"]`

= Does the cart total update live? =

Yes. The plugin reads the cart from WooCommerce's Store API and refreshes on WooCommerce's standard cart events (`added_to_cart`, `removed_from_cart`, `wc_fragments_refreshed`, `updated_cart_totals`), so totals stay right without a page reload.

== Screenshots ==

1. Settings > Hey Support after connecting: the agent, where the chat appears, and your store.
2. The connect screen, before an agent is chosen.
3. Choosing an agent on Hey Support.
4. Hiding the chat on pages you choose, by name.
5. Live products from your store, shown in the chat with add to cart.
6. The Hey Support block in the editor.

== Changelog ==

= 2.1.1 =
* The plugin listing is rewritten to say what your agent does and which plan each store feature needs. It no longer says the agent recovers abandoned carts, which it does not.
* The description on the Plugins screen says the same.

= 2.1.0 =
* New optional checkbox at WooCommerce checkout, "Send me order updates on WhatsApp", for stores that collect a billing phone number. It is off by default: the store owner turns it on with the new "Offer WhatsApp order updates at checkout" setting under Your store, and until then checkout is unchanged. Once on, it works on both the classic and the block checkout, starts unticked, and is recorded on the order as the customer's consent. Orders are unchanged when it is left unticked.
* The plugin listing and settings screen are brought up to date: a 15-day free trial instead of a free plan, no mention of the API token removed in 2.0.0, and Order lookups no longer promise coupons.

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

= 2.1.1 =
A clearer listing and description. Nothing changes on your site.

= 2.1.0 =
Adds an optional WhatsApp order updates checkbox at WooCommerce checkout, off until you turn it on in Settings > Hey Support.

= 2.0.0 =
Connecting is now one click: press Connect and pick your agent. Your existing settings, including which pages the widget is hidden on, are kept. The plugin is also renamed to "Hey Support: AI Sales & Shopping Agent".

= 1.1.0 =
Live products in chat for WooCommerce, a one-approval AI Actions connection, and a store reachability check. Fixes an admin script that could stay cached from 1.0.0.

= 1.0.0 =
First release.
