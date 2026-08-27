<?php
/**
 * Gutenberg block registration.
 *
 * Server-rendered block (apiVersion 3) that re-uses the shared embed enqueuer.
 * The editor-side script (`blocks/hey-support-ai-chatbot/index.js`) ships as
 * plain ES - no JSX, no webpack - so the entire plugin stays composer-free and
 * trivially reviewable for the WordPress.org submission. Its dependencies and
 * version come from the hand-written `index.asset.php` sitting beside it.
 *
 * NOTE: the directory name MUST match the path below. When it didn't,
 * register_block_type() fell through its `file_exists()` check and handed the
 * absolute filesystem path to WP_Block_Type_Registry::register() as the block
 * NAME - which contains uppercase characters on most systems, so WordPress
 * emitted "Block type names must not contain uppercase characters" on every
 * admin page and the block never registered at all.
 *
 * @package HeySupport
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'hey_support_register_block' );

function hey_support_register_block(): void {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}
	$block_type = register_block_type(
		HEY_SUPPORT_DIR . 'blocks/hey-support-ai-chatbot',
		[
			'render_callback' => 'hey_support_render_block',
		]
	);

	// Without this the editor strings in index.js are permanently untranslated.
	if ( $block_type instanceof WP_Block_Type ) {
		foreach ( (array) $block_type->editor_script_handles as $handle ) {
			wp_set_script_translations( $handle, 'hey-support-ai-chatbot' );
		}
	}
}

/**
 * Enqueues the loader and renders nothing - the launcher is position:fixed, so
 * the block is a placement TRIGGER rather than inline markup.
 *
 * @param array<string, mixed> $attributes Block attributes from block.json.
 * @return string
 */
function hey_support_render_block( $attributes ): string {
	if ( ! is_array( $attributes ) ) {
		$attributes = [];
	}

	hey_support_request_embed(
		[
			'chatbot_id' => isset( $attributes['chatbotId'] ) ? sanitize_text_field( (string) $attributes['chatbotId'] ) : '',
			// The block's toggle is a plain boolean, so an unchecked box can't be
			// told apart from "never touched". Only ever force auto-open ON here;
			// leave OFF to the saved setting. [hey_support auto_open="false"] is
			// the surface that can force it off.
			'auto_open'  => ! empty( $attributes['autoOpen'] ) ? true : null,
		]
	);

	return '';
}
