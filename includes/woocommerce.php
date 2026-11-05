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
 * Separately, the WhatsApp order updates opt-in: an optional checkout box that
 * records a shopper's consent on the order. It is off until the merchant turns it
 * on, and it sends nothing itself. The Hey Support server reads the two order meta
 * keys through the store connection the merchant set up.
 *
 * @package HeySupport
 */

defined( 'ABSPATH' ) || exit;

const HEY_SUPPORT_WOO_BRIDGE_HANDLE      = 'hey-support-ai-chatbot-woo-bridge';
const HEY_SUPPORT_WHATSAPP_OPTIN_META    = '_hey_support_whatsapp_optin';
const HEY_SUPPORT_WHATSAPP_OPTIN_AT_META = '_hey_support_whatsapp_optin_at';
const HEY_SUPPORT_WHATSAPP_OPTIN_FIELD   = 'hey-support/whatsapp-optin';

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

	// WhatsApp order updates opt-in, only when the merchant turned on "Offer
	// WhatsApp order updates at checkout" (off by default). Classic and block
	// checkout both end in the same two order meta keys, because that is all the
	// Hey Support server reads.
	if ( hey_support_whatsapp_optin_enabled() ) {
		add_action( 'woocommerce_review_order_before_submit', 'hey_support_render_whatsapp_optin' );
		add_action( 'woocommerce_checkout_create_order', 'hey_support_save_classic_whatsapp_optin', 10, 2 );
		add_action( 'woocommerce_init', 'hey_support_register_block_whatsapp_optin' );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'hey_support_save_block_whatsapp_optin', 10, 2 );
	}
}

/**
 * True when the store collects a billing phone number. The order's billing phone
 * is the number order updates go to, so without it the box would promise
 * something the server cannot deliver.
 */
function hey_support_store_collects_phone(): bool {
	// Block checkout: the phone field is an address setting.
	if ( 'hidden' === get_option( 'woocommerce_checkout_phone_field', 'required' ) ) {
		return false;
	}
	// Classic checkout: the field list is filterable, so ask it rather than guess.
	if ( function_exists( 'WC' ) && is_object( WC() ) && is_object( WC()->checkout() ) ) {
		$billing = WC()->checkout()->get_checkout_fields( 'billing' );
		return is_array( $billing ) && isset( $billing['billing_phone'] );
	}
	return false;
}

/**
 * Record consent on the order. Called only when the box was ticked, so an
 * order that was never ticked carries no trace. The time is refreshed on every
 * tick: WooCommerce reuses one order across payment attempts, and the latest
 * tick is the one the shopper just made.
 *
 * @param object $order WC_Order (or any object with the meta methods).
 */
function hey_support_record_whatsapp_optin( $order ): void {
	if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
		return;
	}
	$order->update_meta_data( HEY_SUPPORT_WHATSAPP_OPTIN_META, 'yes' );
	$order->update_meta_data( HEY_SUPPORT_WHATSAPP_OPTIN_AT_META, gmdate( 'Y-m-d\TH:i:s\Z' ) );
}

/**
 * Withdraw consent recorded by an earlier attempt. WooCommerce reuses the same
 * order across payment attempts (the session's order awaiting payment on the
 * classic checkout, the draft order on the block checkout), so a shopper who
 * ticks the box, has a payment fail, then unticks it and pays must not keep the
 * earlier tick.
 *
 * @param object $order WC_Order (or any object with the meta methods).
 */
function hey_support_revoke_whatsapp_optin( $order ): void {
	if ( ! is_object( $order ) || ! method_exists( $order, 'delete_meta_data' ) ) {
		return;
	}
	$order->delete_meta_data( HEY_SUPPORT_WHATSAPP_OPTIN_META );
	$order->delete_meta_data( HEY_SUPPORT_WHATSAPP_OPTIN_AT_META );
}

/**
 * Whether a submitted checkbox value means ticked.
 *
 * @param mixed $value Raw value from a form post or the Store API.
 */
function hey_support_optin_value_is_ticked( $value ): bool {
	return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'true' === $value || 'on' === $value;
}

/**
 * Classic checkout: the box, unticked by default. A checkout refresh (changing
 * the country, say) re-renders this area, so a tick already made is carried
 * through the refresh from the posted form data, the way WooCommerce does for
 * its own terms box.
 */
function hey_support_render_whatsapp_optin(): void {
	if ( ! hey_support_whatsapp_optin_enabled() ) {
		return;
	}
	if ( ! hey_support_store_collects_phone() ) {
		return;
	}

	$is_ticked = false;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only: this re-renders the box during the update_order_review AJAX refresh, which WooCommerce nonce-checks itself before the checkout fragments are built.
	if ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
		$posted = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed into an array and only tested for presence.
		wp_parse_str( wp_unslash( $_POST['post_data'] ), $posted );
		$is_ticked = ! empty( $posted['hey_support_whatsapp_optin'] );
	}
	$ticked_attr = $is_ticked ? ' checked="checked"' : '';
	?>
	<p class="form-row hey-support-whatsapp-optin">
		<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
			<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="hey_support_whatsapp_optin" id="hey_support_whatsapp_optin" value="1"<?php echo $ticked_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed literal chosen above. ?> />
			<span><?php echo esc_html__( 'Send me order updates on WhatsApp', 'hey-support-ai-chatbot' ); ?></span>
		</label>
	</p>
	<?php
}

/**
 * Classic checkout: save on order creation. This runs on every submit, so an
 * absent or empty box is an explicit untick and withdraws any earlier tick on a
 * reused order. WooCommerce has already verified the checkout nonce by the time
 * this runs.
 *
 * @param object              $order WC_Order being created.
 * @param array<string,mixed> $data  Posted checkout data (unused).
 */
// Action signature: registered with add_action( ..., 10, 2 ).
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
function hey_support_save_classic_whatsapp_optin( $order, $data = [] ): void {
	if ( ! hey_support_whatsapp_optin_enabled() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checkout nonce is verified by WooCommerce before this action fires.
	if ( empty( $_POST['hey_support_whatsapp_optin'] ) ) {
		hey_support_revoke_whatsapp_optin( $order );
		return;
	}
	if ( ! hey_support_store_collects_phone() ) {
		return;
	}
	hey_support_record_whatsapp_optin( $order );
}

/**
 * Block checkout (WooCommerce 8.9+): an optional checkbox in the order section.
 * The block checkout stores its own value under its own meta key, which the
 * Hey Support server does not read; hey_support_save_block_whatsapp_optin()
 * copies it into the canonical keys.
 */
function hey_support_register_block_whatsapp_optin(): void {
	if ( ! hey_support_whatsapp_optin_enabled() ) {
		return;
	}
	if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
		return;
	}
	if ( 'hidden' === get_option( 'woocommerce_checkout_phone_field', 'required' ) ) {
		return;
	}
	woocommerce_register_additional_checkout_field(
		[
			'id'       => HEY_SUPPORT_WHATSAPP_OPTIN_FIELD,
			// Plain translated text: the blocks checkout renders the label as React text, so escaped entities would show literally.
			'label'    => __( 'Send me order updates on WhatsApp', 'hey-support-ai-chatbot' ),
			'location' => 'order',
			'type'     => 'checkbox',
			'required' => false,
		]
	);
}

/**
 * Block checkout: read the field's value and write the canonical keys. A
 * request that carries the field unticked withdraws any earlier tick on the
 * reused draft order; a request that omits the field leaves the order's meta as
 * it is.
 *
 * @param object $order   WC_Order being updated from the request.
 * @param mixed  $request The Store API request.
 */
function hey_support_save_block_whatsapp_optin( $order, $request = null ): void {
	if ( ! hey_support_whatsapp_optin_enabled() ) {
		return;
	}
	// The submitted value, when the request carries the field.
	if ( is_object( $request ) && ( $request instanceof \ArrayAccess ) ) {
		$fields = $request['additional_fields'] ?? null;
		if ( is_array( $fields ) && array_key_exists( HEY_SUPPORT_WHATSAPP_OPTIN_FIELD, $fields ) ) {
			if ( hey_support_optin_value_is_ticked( $fields[ HEY_SUPPORT_WHATSAPP_OPTIN_FIELD ] ) ) {
				hey_support_record_whatsapp_optin( $order );
			} else {
				hey_support_revoke_whatsapp_optin( $order );
			}
			return;
		}
	}

	// The request did not carry it: fall back to the value WooCommerce stored on
	// the order. A stored tick is recorded; nothing here ever withdraws one.
	if ( is_object( $order ) && hey_support_optin_value_is_ticked( hey_support_stored_block_optin_value( $order ) ) ) {
		hey_support_record_whatsapp_optin( $order );
	}
}

/**
 * The block checkout's own stored value for the field, or null when it cannot
 * be read (older WooCommerce, or the blocks package is not loaded).
 *
 * @param object $order WC_Order.
 * @return mixed
 */
function hey_support_stored_block_optin_value( $order ) {
	$package = '\\Automattic\\WooCommerce\\Blocks\\Package';
	$fields  = '\\Automattic\\WooCommerce\\Blocks\\Domain\\Services\\CheckoutFields';
	if ( ! class_exists( $package ) || ! class_exists( $fields ) ) {
		return null;
	}
	try {
		$service = $package::container()->get( $fields );
		if ( ! is_object( $service ) || ! method_exists( $service, 'get_field_from_object' ) ) {
			return null;
		}
		return $service->get_field_from_object( HEY_SUPPORT_WHATSAPP_OPTIN_FIELD, $order, 'other' );
	} catch ( \Throwable $e ) {
		return null;
	}
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
// Filter signature: registered with add_filter( ..., 10, 2 ), so the second
// parameter has to exist whether or not this callback reads it.
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
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
