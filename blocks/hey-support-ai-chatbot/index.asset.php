<?php
/**
 * Editor-script dependencies + version for the Hey Support block.
 *
 * `register_block_type_from_metadata()` looks for this file next to the
 * `editorScript` it resolves from block.json. Since WP 6.5 the file is
 * optional (Trac #60460), but a missing one silently registers the script
 * with ZERO dependencies and no version — so `wp.components` and `wp.i18n`
 * (used in index.js) would not be guaranteed to be loaded, and the script
 * would have no cache-busting. Hand-written because the plugin ships plain
 * ES with no build step, so there is no bundler to generate it.
 *
 * @package HeySupport
 */

return [
	'dependencies' => [
		'wp-blocks',
		'wp-block-editor',
		'wp-components',
		'wp-element',
		'wp-i18n',
	],
	'version'      => '1.0.0',
];
