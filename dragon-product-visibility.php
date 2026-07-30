<?php
/**
 * Plugin Name: Dragon Product Visibility for WooCommerce
 * Plugin URI: https://plugins.dragoncore.ltd/plugins/dragon-product-visibility
 * Description: Restrict WooCommerce product visibility by specific customers or user roles. Products can be made exclusive to certain users without complex membership plugins.
 * Version: 1.0.0
 * Author: Dragon Core
 * Author URI: https://plugins.dragoncore.ltd
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dragon-product-visibility
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 10.4
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DPV_ is the plugin prefix.
define( 'DPV_VERSION', '1.0.0' );
define( 'DPV_PLUGIN_FILE', __FILE__ );
define( 'DPV_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'DPV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DPV_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
// phpcs:enable

require_once DPV_PLUGIN_PATH . 'includes/class-plugin.php';
require_once DPV_PLUGIN_PATH . 'includes/class-install.php';

// Activation/deactivation hooks (must be registered in the main plugin file body).
register_activation_hook( DPV_PLUGIN_FILE, array( Install::class, 'activate' ) );
register_deactivation_hook( DPV_PLUGIN_FILE, array( Install::class, 'deactivate' ) );

/**
 * Main instance of Plugin
 *
 * @return Plugin
 */
function dpv(): Plugin {
	return Plugin::instance();
}

// Initialize.
add_action( 'plugins_loaded', __NAMESPACE__ . '\dpv' );
