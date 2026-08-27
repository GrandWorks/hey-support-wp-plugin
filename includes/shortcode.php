<?php
/**
 * `[hey_support]` shortcode - per-page placement / override.
 *
 * Examples:
 *   [hey_support]
 *   [hey_support chatbot_id="ncimu" auto_open="true"]
 *   [hey_support auto_open="false"]        // switch off a site-wide auto-open
 *
 * Enqueues the loader (which lands in the footer) and returns an EMPTY string.
 * The launcher is position:fixed, so there is nothing to place inline, and
 * returning markup only exposed the tag to wpautop.
 *
 * @package HeySupport
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'hey_support', 'hey_support_shortcode_handler' );

/**
 * Parse a shortcode boolean into a tri-state.
 *
 * @param string $raw Raw attribute value.
 * @return bool|null True/false when explicitly given, null when absent or unparseable.
 */
function hey_support_parse_tristate( string $raw ): ?bool {
	$v = strtolower( trim( $raw ) );
	if ( '' === $v ) {
		return null;
	}
	if ( in_array( $v, [ 'true', '1', 'yes', 'on' ], true ) ) {
		return true;
	}
	if ( in_array( $v, [ 'false', '0', 'no', 'off' ], true ) ) {
		return false;
	}
	return null;
}

/**
 * @param array<string, string>|string $atts
 * @return string
 */
function hey_support_shortcode_handler( $atts ): string {
	$atts = shortcode_atts(
		[
			'chatbot_id' => '',
			'auto_open'  => '',
		],
		is_array( $atts ) ? $atts : [],
		'hey_support'
	);

	hey_support_request_embed(
		[
			'chatbot_id' => sanitize_text_field( $atts['chatbot_id'] ),
			'auto_open'  => hey_support_parse_tristate( (string) $atts['auto_open'] ),
		]
	);

	return '';
}
