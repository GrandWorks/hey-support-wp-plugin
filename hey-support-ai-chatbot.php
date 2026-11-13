<?php
/**
 * Plugin Name:       Hey Support: AI Sales & Shopping Agent
 * Plugin URI:        https://hey.support
 * Description:       Adds your Hey Support AI agent to WordPress. It answers from your own pages, takes leads and hands over to you. On WooCommerce it shows live products and looks up orders.
 * Version:           2.1.2
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Hey Support
 * Author URI:        https://grandworks.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hey-support-ai-chatbot
 *
 * @package HeySupport
 */

defined( 'ABSPATH' ) || exit;

define( 'HEY_SUPPORT_VERSION', '2.1.2' );
define( 'HEY_SUPPORT_FILE', __FILE__ );
define( 'HEY_SUPPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'HEY_SUPPORT_URL', plugin_dir_url( __FILE__ ) );
define( 'HEY_SUPPORT_BASENAME', plugin_basename( __FILE__ ) );
// Overridable from wp-config.php so a development site can point at a local
// server. The connect handshake requires hey.support to reach THIS site, so a
// local install cannot use it and falls back to pasting an ID.
defined( 'HEY_SUPPORT_API_BASE' ) || define( 'HEY_SUPPORT_API_BASE', 'https://hey.support' );

require_once HEY_SUPPORT_DIR . 'includes/options.php';
require_once HEY_SUPPORT_DIR . 'includes/embed.php';
require_once HEY_SUPPORT_DIR . 'includes/shortcode.php';
require_once HEY_SUPPORT_DIR . 'includes/gutenberg-block.php';
require_once HEY_SUPPORT_DIR . 'includes/connect.php';
require_once HEY_SUPPORT_DIR . 'includes/woocommerce.php';

if ( is_admin() ) {
	require_once HEY_SUPPORT_DIR . 'admin/settings-page.php';
}

register_activation_hook(
	__FILE__,
	function () {
		hey_support_seed_default_options();
	}
);
