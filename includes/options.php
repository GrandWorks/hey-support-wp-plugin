<?php
/**
 * Options storage.
 *
 * Single serialized array under the `hey_support_settings` wp_options key, so
 * uninstall is one row to clean.
 *
 * `api_token` no longer has any UI: one-click connect replaced both things it
 * did, and it was gated to the Scale plan so it advertised a capability most
 * people could never use. The key stays in the shape and the sanitizer still
 * preserves it, because silently destroying a stored credential on upgrade is
 * not ours to do -- it is simply never rendered or read.
 *
 * @package HeySupport
 */

defined( 'ABSPATH' ) || exit;

const HEY_SUPPORT_OPTION_KEY = 'hey_support_settings';

/**
 * Default settings shape.
 *
 * @return array<string, mixed>
 */
function hey_support_default_options(): array {
	return [
		'api_token'   => '',
		'chatbot_id'  => '',
		'enable_on'   => 'all',     // all | posts | pages | shortcode-only
		'exclude_ids' => '',        // comma-separated post IDs
		'auto_open'   => false,
	];
}

function hey_support_seed_default_options(): void {
	if ( false === get_option( HEY_SUPPORT_OPTION_KEY ) ) {
		add_option( HEY_SUPPORT_OPTION_KEY, hey_support_default_options() );
	}
}

/**
 * Read merged settings (defaults + saved overrides).
 *
 * @return array<string, mixed>
 */
function hey_support_get_options(): array {
	$saved = get_option( HEY_SUPPORT_OPTION_KEY, [] );
	if ( ! is_array( $saved ) ) {
		$saved = [];
	}
	return array_merge( hey_support_default_options(), $saved );
}

/**
 * Sanitize submitted settings before persistence.
 *
 * @param array<string, mixed> $input Raw $_POST['hey_support_settings'] payload.
 * @return array<string, mixed>
 */
function hey_support_sanitize_options( $input ): array {
	$out = hey_support_default_options();
	if ( ! is_array( $input ) ) {
		return $out;
	}

	// The form renders an EMPTY token field, so an empty submission means "leave
	// it alone" rather than "delete it". Clearing is explicit.
	$stored       = get_option( HEY_SUPPORT_OPTION_KEY, [] );
	$stored_token = is_array( $stored ) && isset( $stored['api_token'] ) ? (string) $stored['api_token'] : '';
	$submitted    = isset( $input['api_token'] ) ? sanitize_text_field( $input['api_token'] ) : '';

	if ( ! empty( $input['api_token_clear'] ) ) {
		$out['api_token'] = '';
	} elseif ( '' !== $submitted ) {
		$out['api_token'] = $submitted;
	} else {
		$out['api_token'] = $stored_token;
	}

	$out['chatbot_id'] = isset( $input['chatbot_id'] ) ? sanitize_text_field( $input['chatbot_id'] ) : '';

	$enable_on        = isset( $input['enable_on'] ) ? sanitize_text_field( $input['enable_on'] ) : 'all';
	$out['enable_on'] = in_array( $enable_on, [ 'all', 'posts', 'pages', 'shortcode-only' ], true ) ? $enable_on : 'all';

	$out['auto_open'] = ! empty( $input['auto_open'] );

	$raw_excludes       = isset( $input['exclude_ids'] ) ? sanitize_textarea_field( $input['exclude_ids'] ) : '';
	$ids                = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $raw_excludes ) ) );
	$out['exclude_ids'] = implode( ',', array_unique( $ids ) );

	return $out;
}

/**
 * Parse the comma-separated exclude_ids string into an int array.
 *
 * @return int[]
 */
function hey_support_excluded_post_ids(): array {
	$opts = hey_support_get_options();
	if ( empty( $opts['exclude_ids'] ) ) {
		return [];
	}
	return array_filter( array_map( 'absint', explode( ',', $opts['exclude_ids'] ) ) );
}
