<?php
/**
 * WooCommerce extension.
 *
 * Two channels, split deliberately by CACHEABILITY:
 *
 *  1. `hey_support_context` filter -> `data-context` on the loader tag. Carries
 *     only STORE-level facts (platform, shop URL, currency) plus identity for a
 *     logged-in customer. This payload is baked into the page HTML.
 *
 *  2. A runtime bridge that reads WooCommerce's own Store API and posts the
 *     result via `window.postMessage({type:'HS_SET_CONTEXT'})`. Carries the CART
 *     (item count, total, currency).
 *
 * Cart figures must not travel in channel 1: behind any full-page cache
 * (WP Super Cache, W3TC, LiteSpeed, WP Rocket) an anonymous visitor's cart would
 * be baked into the cached HTML and served to the next visitor. Channel 2 is
 * per-request by construction, and reading the Store API instead of scraping
 * theme markup means the totals are authoritative on every theme.
 *
 * @package HeySupport
 */

defined( 'ABSPATH' ) || exit;

const HEY_SUPPORT_WOO_BRIDGE_HANDLE = 'hey-support-ai-chatbot-woo-bridge';

// Must be registered at load time - `before_woocommerce_init` fires earlier than
// `woocommerce_loaded`. Without it WooCommerce warns store owners that the
// plugin is incompatible with High-Performance Order Storage, even though
// wc_get_orders() below is already HPOS-safe.
add_action( 'before_woocommerce_init', 'hey_support_declare_woo_compat' );

add_action( 'woocommerce_loaded', 'hey_support_woocommerce_boot' );

function hey_support_declare_woo_compat(): void {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'custom_order_tables',
		HEY_SUPPORT_FILE,
		true
	);
}

function hey_support_woocommerce_boot(): void {
	add_filter( 'hey_support_context', 'hey_support_woocommerce_context', 10, 2 );
	add_action( 'wp_enqueue_scripts', 'hey_support_register_woo_bridge' );
	add_action( 'hey_support_embed_enqueued', 'hey_support_enqueue_woo_bridge' );
	add_action( 'template_redirect', 'hey_support_woo_cache_guard' );
}

/**
 * Belt-and-braces: tell page-cache plugins not to store a response that carries
 * a logged-in customer's identity in `data-context`. Every major cache already
 * bypasses requests carrying the `wordpress_logged_in_*` cookie; this makes the
 * intent explicit and runs early enough for output-buffering caches to see it.
 */
function hey_support_woo_cache_guard(): void {
	if ( is_admin() || ! is_user_logged_in() ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Not ours to name: this is the de-facto constant every page-cache plugin (WP Super Cache, W3TC, LiteSpeed, WP Rocket) looks for. A prefixed name would do nothing.
		define( 'DONOTCACHEPAGE', true );
	}
}

/**
 * Store-level + logged-in-customer context. No cart fields - see the file
 * docblock.
 *
 * @param array<string, mixed> $context   Existing context from earlier filters.
 * @param array<string, mixed> $overrides Per-shortcode overrides (unused here).
 * @return array<string, mixed>
 */
function hey_support_woocommerce_context( $context, $overrides = [] ): array {
	if ( ! is_array( $context ) ) {
		$context = [];
	}
	if ( ! function_exists( 'WC' ) ) {
		return $context;
	}

	// Identifies the host to the dashboard. The inbox Woo panel infers it from
	// `platform !== 'shopify'`, but the Live Visitors view reads `platform`
	// directly and showed null for every WooCommerce visitor without this.
	$context['platform'] = 'woocommerce';
	$context['shop_url'] = home_url();

	if ( function_exists( 'get_woocommerce_currency' ) ) {
		$context['currency'] = get_woocommerce_currency();
	}

	if ( ! is_user_logged_in() ) {
		return $context;
	}

	$customer_id = get_current_user_id();
	$user        = wp_get_current_user();

	$context['customer_id']    = $customer_id;
	$context['customer_email'] = $user && $user->user_email ? $user->user_email : null;

	if ( function_exists( 'wc_get_orders' ) ) {
		$orders = wc_get_orders(
			[
				'customer_id' => $customer_id,
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			]
		);
		if ( ! empty( $orders ) && is_object( $orders[0] ) ) {
			$context['last_order_id']     = (int) $orders[0]->get_id();
			$context['last_order_status'] = (string) $orders[0]->get_status();
		}
	}

	return $context;
}

/**
 * Register (but do not enqueue) the cart bridge. It is enqueued only from
 * `hey_support_embed_enqueued`, so it never ships on a page with no widget -
 * including pages excluded by ID or by the enable-on rules.
 */
function hey_support_register_woo_bridge(): void {
	if ( is_admin() ) {
		return;
	}

	wp_register_script( HEY_SUPPORT_WOO_BRIDGE_HANDLE, '', [ 'jquery' ], HEY_SUPPORT_VERSION, true );
	wp_add_inline_script(
		HEY_SUPPORT_WOO_BRIDGE_HANDLE,
		'window.heySupportWooCartUrl=' . wp_json_encode( rest_url( 'wc/store/v1/cart' ) ) . ';',
		'before'
	);
	wp_add_inline_script( HEY_SUPPORT_WOO_BRIDGE_HANDLE, hey_support_woo_bridge_js() );
}

function hey_support_enqueue_woo_bridge(): void {
	if ( is_admin() ) {
		return;
	}
	if ( ! wp_script_is( HEY_SUPPORT_WOO_BRIDGE_HANDLE, 'registered' ) ) {
		return;
	}
	wp_enqueue_script( HEY_SUPPORT_WOO_BRIDGE_HANDLE );
}

/**
 * Bridge source.
 *
 * Reads `GET /wp-json/wc/store/v1/cart` (public, session-scoped, no nonce needed
 * for GET) rather than scraping mini-cart CSS selectors, which silently failed on
 * any theme outside a hardcoded guess-list and could only ever recover the item
 * count - leaving a fresh count beside a stale total in the inbox.
 *
 * One fetch per page load; the retry ticks only re-POST the cached payload,
 * because embed.js registers its `message` listener after an awaited config
 * fetch and drops anything that arrives first. Merging on the widget side is
 * cumulative and per-key, so repeat posts are idempotent.
 */
function hey_support_woo_bridge_js(): string {
	return <<<'JS'
(function () {
	var url = window.heySupportWooCartUrl;
	if (!url || !window.fetch) { return; }
	var origin = window.location.origin;
	var latest = null;

	function post() {
		if (!latest) { return; }
		try {
			window.postMessage({ type: 'HS_SET_CONTEXT', payload: latest }, origin);
		} catch (e) { /* no-op */ }
	}

	function refresh() {
		fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				if (!data) { return; }
				var payload = {};
				if (typeof data.items_count === 'number') {
					payload.current_cart_item_count = data.items_count;
				}
				var t = data.totals;
				if (t) {
					// total_price is a STRING in MINOR units, with a
					// currency-dependent exponent. Never divide by a hardcoded 100
					// (JPY has 0 minor units, so 1500 means 1500, not 15).
					var minor = parseInt(t.currency_minor_unit, 10);
					if (isNaN(minor) || minor < 0) { minor = 2; }
					var raw = parseFloat(t.total_price);
					if (!isNaN(raw)) {
						payload.current_cart_total = raw / Math.pow(10, minor);
					}
					if (t.currency_code) { payload.currency = t.currency_code; }
				}
				if (Object.keys(payload).length === 0) { return; }
				latest = payload;
				post();
			})
			.catch(function () { /* no-op */ });
	}

	refresh();
	setTimeout(post, 1500);
	setTimeout(post, 4000);
	window.addEventListener('load', post);

	if (window.jQuery) {
		window.jQuery(document.body).on(
			'wc_fragments_refreshed wc_fragments_loaded updated_cart_totals added_to_cart removed_from_cart',
			refresh
		);
	}
}());
JS;
}
