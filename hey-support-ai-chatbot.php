<?php
/**
 * Plugin Name:       Hey Support: AI Chatbot
 * Plugin URI:        https://hey.support
 * Description:       Drop-in AI chatbot widget for WordPress sites. Trains on your content, captures leads, hands off to humans. Auto-detects WooCommerce and forwards customer + cart context into the conversation.
 * Version:           1.0.0
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

define( 'HEY_SUPPORT_VERSION', '1.0.0' );
define( 'HEY_SUPPORT_FILE', __FILE__ );
define( 'HEY_SUPPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'HEY_SUPPORT_URL', plugin_dir_url( __FILE__ ) );
define( 'HEY_SUPPORT_API_BASE', 'https://hey.support' );

require_once HEY_SUPPORT_DIR . 'includes/options.php';
require_once HEY_SUPPORT_DIR . 'includes/api-client.php';
require_once HEY_SUPPORT_DIR . 'includes/embed.php';
require_once HEY_SUPPORT_DIR . 'includes/shortcode.php';
require_once HEY_SUPPORT_DIR . 'includes/gutenberg-block.php';
require_once HEY_SUPPORT_DIR . 'includes/woocommerce.php';

if ( is_admin() ) {
	require_once HEY_SUPPORT_DIR . 'admin/settings-page.php';
}

register_activation_hook( __FILE__, function () {
	hey_support_seed_default_options();
} );
