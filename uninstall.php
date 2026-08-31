<?php
/**
 * Uninstall handler - runs when an admin deletes the plugin from the Plugins screen.
 * Cleans up the single wp_options row we own. No DB tables are created.
 *
 * The row holds an API token, so on multisite every site's copy has to go -
 * deleting only the current site's would orphan a credential per site.
 *
 * @package HeySupport
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Wrapped in a function so the loop variable stays out of the global scope.
 */
function hey_support_uninstall_cleanup(): void {
	$option = 'hey_support_settings';

	if ( ! is_multisite() ) {
		delete_option( $option );
		return;
	}

	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $site_id ) {
		switch_to_blog( (int) $site_id );
		delete_option( $option );
		restore_current_blog();
	}
}

hey_support_uninstall_cleanup();
