<?php
/**
 * Embed enqueuing + wp_footer auto-injection.
 *
 * The widget loader is a REMOTE script (hey.support/api/chat/embed.js) carrying
 * per-page `data-*` attributes. It goes through `wp_enqueue_script()` plus a
 * narrowly-scoped `script_loader_tag` filter rather than a hand-written <script>
 * tag, so the plugin plays by the normal WordPress asset rules.
 *
 * All three surfaces (wp_footer auto-inject, [hey_support] shortcode, Gutenberg
 * block) funnel through `hey_support_request_embed()`, so they emit identical
 * markup. The shortcode and block RENDER NOTHING - the launcher is
 * position:fixed, so inline placement never mattered, and returning markup from
 * a shortcode only exposed it to wpautop mangling.
 *
 * DECIDING and ENQUEUING are deliberately separate. In a BLOCK theme a dynamic
 * block's render_callback runs before `wp_enqueue_scripts` and even before
 * `wp_head`, and an enqueue made that early does not survive to the footer - the
 * block silently emitted nothing. So the three surfaces only RECORD intent, and
 * a single `wp_footer` handler performs the one real enqueue, which is late
 * enough to be safe and early enough to print (footer scripts go out at
 * `wp_footer` priority 20).
 *
 * @package HeySupport
 */

defined( 'ABSPATH' ) || exit;

const HEY_SUPPORT_EMBED_HANDLE = 'hey-support-ai-chatbot-embed';

add_action( 'wp_footer', 'hey_support_footer_embed', 5 );
add_filter( 'script_loader_tag', 'hey_support_embed_script_tag', 10, 2 );

/**
 * Resolved data-* payload for the enqueued script, or null until something
 * enqueues. Doubles as the "already handled this request" flag so the footer
 * auto-injection doesn't double up on a page that used the shortcode or block.
 *
 * @return array<string, string>|null
 */
function &hey_support_embed_state() {
	static $state = null;
	return $state;
}

function hey_support_already_rendered(): bool {
	$state = &hey_support_embed_state();
	return null !== $state;
}

/**
 * Back-compat no-op. The shortcode and block used to call this after printing;
 * enqueuing now records the flag itself. Kept so a third-party snippet calling
 * it doesn't fatal.
 */
function hey_support_mark_rendered(): void {
	// Kept so the body is unmistakably an intentional no-op, not a stub.
	return; // phpcs:ignore Squiz.PHP.NonExecutableCode.ReturnNotRequired
}

/**
 * Apply enable-on rules + exclude-list to decide whether to auto-inject.
 */
function hey_support_should_auto_inject(): bool {
	$opts = hey_support_get_options();
	if ( empty( $opts['chatbot_id'] ) ) {
		return false;
	}
	if ( 'shortcode-only' === $opts['enable_on'] ) {
		return false;
	}
	if ( is_admin() ) {
		return false;
	}

	$post_id = get_queried_object_id();
	if ( $post_id && in_array( (int) $post_id, hey_support_excluded_post_ids(), true ) ) {
		return false;
	}

	if ( 'posts' === $opts['enable_on'] && ! is_singular( 'post' ) ) {
		return false;
	}
	if ( 'pages' === $opts['enable_on'] && ! is_page() ) {
		return false;
	}

	return true;
}

/**
 * The single enqueue point. Runs late enough that a block-theme render_callback
 * has already recorded its intent, and early enough that footer scripts (printed
 * at `wp_footer` priority 20) still pick it up.
 */
function hey_support_footer_embed(): void {
	if ( ! hey_support_already_rendered() && hey_support_should_auto_inject() ) {
		hey_support_request_embed();
	}

	$state = &hey_support_embed_state();
	if ( ! is_array( $state ) || empty( $state ) ) {
		return;
	}

	wp_enqueue_script(
		HEY_SUPPORT_EMBED_HANDLE,
		HEY_SUPPORT_API_BASE . '/api/chat/embed.js',
		[],
		// A remote endpoint on hey.support, which ships widget updates on its own
		// cadence behind its own Cache-Control headers. `null` means no ?ver= is
		// appended at all; pinning it to the PLUGIN version would bust the CDN
		// cache on unrelated plugin releases AND fail to bust it when the widget
		// itself changes. The ignore must sit on THIS line: phpcs reports the
		// violation at the argument, and an annotation above the call covers only
		// the next line, so the old placement silently suppressed nothing -- which
		// is how this argument came to be 'fixed' the wrong way once already.
		null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		[
			'in_footer' => true,
			'strategy'  => 'defer',
		]
	);

	/**
	 * Fires once, immediately after the widget loader is enqueued for this
	 * request. Companion assets (e.g. the WooCommerce cart bridge) hook here so
	 * they load exactly when the widget does - never on a page where no widget
	 * renders.
	 *
	 * @param array<string, string> $attrs Resolved data-* attributes.
	 */
	do_action( 'hey_support_embed_enqueued', $state );
}

/**
 * Build the `data-context` payload - extensible via the `hey_support_context`
 * filter (WooCommerce hooks here to supply platform/customer fields).
 *
 * Per-visitor CART figures deliberately do NOT live here: this payload is baked
 * into the page HTML, and behind a full-page cache one visitor's cart would be
 * served to the next. The Woo bridge pushes those at runtime instead - see
 * includes/woocommerce.php.
 *
 * @param array<string, mixed> $overrides Per-shortcode overrides.
 * @return array<string, mixed>
 */
function hey_support_build_context( array $overrides = [] ): array {
	$context = apply_filters( 'hey_support_context', [], $overrides );
	if ( ! is_array( $context ) ) {
		return [];
	}
	// Drop null / empty-string entries so the JSON payload stays tight.
	return array_filter(
		$context,
		static function ( $v ) {
			return null !== $v && '' !== $v;
		}
	);
}

/**
 * Resolve the loader's data-* attributes and record them for this request.
 *
 * Does NOT enqueue - see hey_support_footer_embed() for why. First caller wins,
 * so an explicit shortcode or block beats the site-wide auto-injection.
 *
 * @param array<string, mixed> $overrides {
 *     @type string    $chatbot_id Override the saved chatbot ID.
 *     @type bool|null $auto_open  Tri-state: true forces on, false forces off,
 *                                 null defers to the saved setting.
 *     @type array     $context    Extra context merged into data-context.
 * }
 * @return bool Whether the widget will be emitted this request.
 */
function hey_support_request_embed( array $overrides = [] ): bool {
	if ( hey_support_already_rendered() ) {
		return false;
	}

	$opts       = hey_support_get_options();
	$chatbot_id = isset( $overrides['chatbot_id'] ) && '' !== $overrides['chatbot_id']
		? (string) $overrides['chatbot_id']
		: (string) $opts['chatbot_id'];

	if ( '' === $chatbot_id ) {
		return false;
	}

	// Tri-state: an explicit shortcode/block value wins in BOTH directions, so
	// [hey_support auto_open="false"] can switch off a site-wide auto-open.
	$auto_open = array_key_exists( 'auto_open', $overrides ) && null !== $overrides['auto_open']
		? (bool) $overrides['auto_open']
		: ! empty( $opts['auto_open'] );

	$context = hey_support_build_context( $overrides );
	if ( ! empty( $overrides['context'] ) && is_array( $overrides['context'] ) ) {
		$context = array_merge( $context, $overrides['context'] );
	}

	$attrs = [ 'data-chatbot-id' => $chatbot_id ];

	if ( ! empty( $context ) ) {
		$json = wp_json_encode( $context );
		if ( false !== $json ) {
			// embed.js does JSON.parse(decodeURIComponent(raw)) - percent-encoding,
			// NOT form-encoding, so rawurlencode() is the required encoder.
			$attrs['data-context'] = rawurlencode( $json );
		}
	}

	if ( $auto_open ) {
		// embed.js compares against the literal string "true".
		$attrs['data-auto-open'] = 'true';
	}

	$state = &hey_support_embed_state();
	$state = $attrs;

	return true;
}

/**
 * Attach the data-* attributes to our handle only.
 *
 * @param string $tag    The full <script> tag WordPress built.
 * @param string $handle Script handle being filtered.
 * @return string
 */
function hey_support_embed_script_tag( $tag, $handle ): string {
	if ( HEY_SUPPORT_EMBED_HANDLE !== $handle ) {
		return (string) $tag;
	}
	$state = &hey_support_embed_state();
	if ( ! is_array( $state ) || empty( $state ) ) {
		return (string) $tag;
	}

	$attrs = '';
	foreach ( $state as $name => $value ) {
		$attrs .= ' ' . $name . '="' . esc_attr( $value ) . '"';
	}

	return (string) preg_replace( '/<script(\s|>)/', '<script' . $attrs . '$1', (string) $tag, 1 );
}
