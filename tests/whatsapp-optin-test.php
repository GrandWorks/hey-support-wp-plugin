<?php
/**
 * Behaviour tests for the WhatsApp order updates opt-in.
 *
 * Runs with plain `php tests/whatsapp-optin-test.php`, no WordPress and no
 * dependencies: the few WordPress functions the code calls are stubbed below. The
 * real test is the consent contract the Hey Support server depends on.
 *
 * @package HeySupport
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['hs_options'] = [];
$GLOBALS['hs_wp_opts'] = [];
$GLOBALS['hs_hooks']   = [];
$GLOBALS['hs_fields']  = [];
$GLOBALS['hs_billing'] = [ 'billing_phone' => [] ];
$GLOBALS['hs_failed']  = 0;
$GLOBALS['hs_passed']  = 0;

function add_action( $hook, $callback ) {
	$GLOBALS['hs_hooks'][ $hook ][] = $callback;
}
function add_filter( $hook, $callback ) {
	$GLOBALS['hs_hooks'][ $hook ][] = $callback;
}
function get_option( $key, $fallback = false ) {
	if ( 'hey_support_settings' === $key ) {
		return $GLOBALS['hs_options'];
	}
	return array_key_exists( $key, $GLOBALS['hs_wp_opts'] ) ? $GLOBALS['hs_wp_opts'][ $key ] : $fallback;
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function sanitize_textarea_field( $value ) {
	return trim( (string) $value );
}
function absint( $value ) {
	return abs( (int) $value );
}
function esc_html__( $text ) {
	return $text;
}
function __( $text ) {
	return $text;
}
function wp_unslash( $value ) {
	return $value;
}
function wp_parse_str( $input, &$out ) {
	parse_str( $input, $out );
}
function woocommerce_register_additional_checkout_field( $field ) {
	$GLOBALS['hs_fields'][] = $field;
}
// phpcs:ignore WordPress.NamingConventions
function WC() {
	return new class() {
		public function checkout() {
			return new class() {
				public function get_checkout_fields() {
					return $GLOBALS['hs_billing'];
				}
			};
		}
	};
}

/** The meta methods of a WC_Order. */
class HS_Test_Order {
	/** @var array<string, string> */
	public $meta = [];
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}
}

require __DIR__ . '/../includes/options.php';
require __DIR__ . '/../includes/woocommerce.php';

function hs_check( string $name, bool $ok ): void {
	if ( $ok ) {
		++$GLOBALS['hs_passed'];
		return;
	}
	++$GLOBALS['hs_failed'];
	echo "FAILED: {$name}\n";
}

function hs_on(): void {
	$GLOBALS['hs_options'] = [
		'chatbot_id'           => 'abc12',
		'whatsapp_order_optin' => true,
	];
}

function hs_reset(): void {
	$GLOBALS['hs_options'] = [];
	$GLOBALS['hs_wp_opts'] = [];
	$GLOBALS['hs_hooks']   = [];
	$GLOBALS['hs_fields']  = [];
	$GLOBALS['hs_billing'] = [ 'billing_phone' => [] ];
	$_POST                 = [];
}

function hs_ticked_order(): HS_Test_Order {
	$order = new HS_Test_Order();
	hey_support_record_whatsapp_optin( $order );
	return $order;
}

function hs_render(): string {
	ob_start();
	hey_support_render_whatsapp_optin();
	return (string) ob_get_clean();
}

$hs_checkout_hooks = [
	'woocommerce_review_order_before_submit',
	'woocommerce_checkout_create_order',
	'woocommerce_init',
	'woocommerce_store_api_checkout_update_order_from_request',
];

// The contract with the server.
hs_check( 'consent meta key', '_hey_support_whatsapp_optin' === HEY_SUPPORT_WHATSAPP_OPTIN_META );
hs_check( 'consent time meta key', '_hey_support_whatsapp_optin_at' === HEY_SUPPORT_WHATSAPP_OPTIN_AT_META );

$order = hs_ticked_order();
hs_check( 'a tick writes yes', 'yes' === $order->meta['_hey_support_whatsapp_optin'] );
hs_check( 'the time is ISO 8601 UTC', 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $order->meta['_hey_support_whatsapp_optin_at'] ) );

// Off by default, and off without a connected agent.
hs_reset();
hs_check( 'the setting defaults to off', false === hey_support_default_options()['whatsapp_order_optin'] );
hs_check( 'not enabled by default', ! hey_support_whatsapp_optin_enabled() );
$GLOBALS['hs_options'] = [ 'whatsapp_order_optin' => true ];
hs_check( 'not enabled without a connected agent', ! hey_support_whatsapp_optin_enabled() );
$GLOBALS['hs_options'] = [ 'chatbot_id' => 'abc12' ];
hs_check( 'not enabled without the setting', ! hey_support_whatsapp_optin_enabled() );
hs_on();
hs_check( 'enabled with both', hey_support_whatsapp_optin_enabled() );

// The boot hooks nothing at checkout until it is on.
hs_reset();
hey_support_woocommerce_boot();
foreach ( $hs_checkout_hooks as $hs_hook ) {
	hs_check( "off: {$hs_hook} is not hooked", ! isset( $GLOBALS['hs_hooks'][ $hs_hook ] ) );
}
hs_reset();
hs_on();
hey_support_woocommerce_boot();
foreach ( $hs_checkout_hooks as $hs_hook ) {
	hs_check( "on: {$hs_hook} is hooked", isset( $GLOBALS['hs_hooks'][ $hs_hook ] ) );
}

// The setting survives saving, and a save that omits it turns it off.
hs_reset();
$hs_saved = hey_support_sanitize_options( [ 'whatsapp_order_optin' => '1' ] );
hs_check( 'saving keeps it as a boolean', true === $hs_saved['whatsapp_order_optin'] );
$hs_saved = hey_support_sanitize_options( [ 'chatbot_id' => 'abc12' ] );
hs_check( 'an unticked save turns it off', false === $hs_saved['whatsapp_order_optin'] );
$hs_saved = hey_support_sanitize_options( hey_support_sanitize_options( [ 'whatsapp_order_optin' => '1' ] ) );
hs_check( 'the connect read-modify-write keeps it', true === $hs_saved['whatsapp_order_optin'] );

// Classic checkout: the box.
hs_reset();
hs_on();
$hs_html = hs_render();
hs_check( 'the box renders', false !== strpos( $hs_html, 'Send me order updates on WhatsApp' ) );
hs_check( 'the box starts unticked', false === strpos( $hs_html, 'checked=' ) );
$_POST['post_data'] = 'hey_support_whatsapp_optin=1&billing_country=IN';
hs_check( 'a checkout refresh keeps a tick', false !== strpos( hs_render(), 'checked="checked"' ) );
hs_reset();
hs_on();
$GLOBALS['hs_billing'] = [];
hs_check( 'no box when the classic checkout has no phone field', '' === hs_render() );
hs_reset();
hs_on();
$GLOBALS['hs_wp_opts']['woocommerce_checkout_phone_field'] = 'hidden';
hs_check( 'no box when the phone field is hidden', '' === hs_render() );
hs_reset();
hs_check( 'no box when off', '' === hs_render() );

// Classic checkout: the save.
hs_reset();
hs_on();
$_POST['hey_support_whatsapp_optin'] = '1';
$order                               = new HS_Test_Order();
hey_support_save_classic_whatsapp_optin( $order );
hs_check( 'classic: a tick is recorded', 'yes' === ( $order->meta['_hey_support_whatsapp_optin'] ?? '' ) );

hs_reset();
hs_on();
$order = hs_ticked_order();
hey_support_save_classic_whatsapp_optin( $order );
hs_check( 'classic: an untick withdraws an earlier tick', [] === $order->meta );

hs_reset();
hs_on();
$GLOBALS['hs_wp_opts']['woocommerce_checkout_phone_field'] = 'hidden';
$_POST['hey_support_whatsapp_optin']                       = '1';
$order = new HS_Test_Order();
hey_support_save_classic_whatsapp_optin( $order );
hs_check( 'classic: nothing recorded without a phone field', [] === $order->meta );

hs_reset();
$_POST['hey_support_whatsapp_optin'] = '1';
$order                               = new HS_Test_Order();
hey_support_save_classic_whatsapp_optin( $order );
hs_check( 'classic: nothing recorded when off', [] === $order->meta );

// Block checkout: the field.
hs_reset();
hs_on();
hey_support_register_block_whatsapp_optin();
hs_check( 'block: one field registered', 1 === count( $GLOBALS['hs_fields'] ) );
hs_check( 'block: optional', false === $GLOBALS['hs_fields'][0]['required'] );
hs_check( 'block: a checkbox', 'checkbox' === $GLOBALS['hs_fields'][0]['type'] );
hs_check( 'block: plain label text', 'Send me order updates on WhatsApp' === $GLOBALS['hs_fields'][0]['label'] );
hs_reset();
hs_on();
$GLOBALS['hs_wp_opts']['woocommerce_checkout_phone_field'] = 'hidden';
hey_support_register_block_whatsapp_optin();
hs_check( 'block: no field when the phone is hidden', [] === $GLOBALS['hs_fields'] );
hs_reset();
hey_support_register_block_whatsapp_optin();
hs_check( 'block: no field when off', [] === $GLOBALS['hs_fields'] );

// Block checkout: the save.
hs_reset();
hs_on();
$hs_field = HEY_SUPPORT_WHATSAPP_OPTIN_FIELD;
foreach ( [ true, 1, '1', 'yes', 'true', 'on' ] as $hs_value ) {
	$order = new HS_Test_Order();
	hey_support_save_block_whatsapp_optin( $order, new ArrayObject( [ 'additional_fields' => [ $hs_field => $hs_value ] ] ) );
	hs_check( 'block: a tick is recorded for ' . var_export( $hs_value, true ), 'yes' === ( $order->meta['_hey_support_whatsapp_optin'] ?? '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
}
foreach ( [ false, 0, '0', '', 'no', null ] as $hs_value ) {
	$order = hs_ticked_order();
	hey_support_save_block_whatsapp_optin( $order, new ArrayObject( [ 'additional_fields' => [ $hs_field => $hs_value ] ] ) );
	hs_check( 'block: an untick withdraws for ' . var_export( $hs_value, true ), [] === $order->meta ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
}
$order = hs_ticked_order();
hey_support_save_block_whatsapp_optin( $order, new ArrayObject( [ 'additional_fields' => [] ] ) );
hs_check( 'block: a request that omits the field withdraws nothing', 'yes' === $order->meta['_hey_support_whatsapp_optin'] );
hs_reset();
$order = new HS_Test_Order();
hey_support_save_block_whatsapp_optin( $order, new ArrayObject( [ 'additional_fields' => [ $hs_field => true ] ] ) );
hs_check( 'block: nothing recorded when off', [] === $order->meta );

// One version everywhere.
$hs_main   = (string) file_get_contents( __DIR__ . '/../hey-support-ai-chatbot.php' );
$hs_readme = (string) file_get_contents( __DIR__ . '/../readme.txt' );
preg_match( '/^ \* Version:\s+(\S+)/m', $hs_main, $hs_header );
preg_match( "/define\( 'HEY_SUPPORT_VERSION', '([^']+)' \)/", $hs_main, $hs_constant );
preg_match( '/^Stable tag: (\S+)/m', $hs_readme, $hs_stable );
hs_check( 'header and constant agree', $hs_header[1] === $hs_constant[1] );
hs_check( 'header and stable tag agree', $hs_header[1] === $hs_stable[1] );
hs_check( 'the changelog has this version', false !== strpos( $hs_readme, "= {$hs_header[1]} =" ) );

echo "{$GLOBALS['hs_passed']} passed, {$GLOBALS['hs_failed']} failed\n";
exit( $GLOBALS['hs_failed'] > 0 ? 1 : 0 );
