<?php
/**
 * Plugin Name: Bosta WooCommerce Integration
 * Plugin URI: https://emadissa.com/bosta-wc
 * Description: Integrates WooCommerce with Bosta shipping services.
 * Version: 5.0.0
 * Author: Emad Issa
 * Author URI: https://emadissa.com
 * License: GPL2
 * Text Domain: bosta-wc
 */

// Exit if accessed directly
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define Plugin Constants
define('BOSTA_WC_VERSION', '1.0.0');
define('BOSTA_WC_PATH', plugin_dir_path(__FILE__));
define('BOSTA_WC_URL', plugin_dir_url(__FILE__));
define('BOSTA_WC_API_URL', 'https://app.bosta.co/api/v2'); // Replace with actual API URL
const BOSTA_ENV_URL_V0 = 'https://app.bosta.co/api/v0';
const BOSTA_ENV_URL_V2 = 'https://app.bosta.co/api/v2';
const PLUGIN_VERSION = '4.0.0';
const bosta_cache_duration = 86400;
const bosta_country_id_duration = 604800;
const BOSTA_EGYPT_COUNTRY_ID = "60e4482c7cb7d4bc4849c4d5";

// Always include the API class (may be used in both frontend and admin)
require_once BOSTA_WC_PATH . 'includes/bosta-helper.php';
require_once BOSTA_WC_PATH . 'includes/class-bosta-api.php';
require_once BOSTA_WC_PATH . 'includes/class-bosta-checkout.php';
// Load admin-related files only if in the admin dashboard
if (is_admin()) {
    require_once BOSTA_WC_PATH . 'includes/class-bosta-admin-menu.php';
    require_once BOSTA_WC_PATH . 'includes/class-bosta-orders.php';
    // require_once BOSTA_WC_PATH . 'includes/class-bosta-pickups.php';
    require_once BOSTA_WC_PATH . 'includes/class-bosta-settings.php';
    require_once BOSTA_WC_PATH . 'includes/class-bosta-order-actions.php';
    require_once BOSTA_WC_PATH . 'includes/class-bosta-bulk-actions.php';

    // Initialize Admin Classes
    function bosta_wc_admin_init() {
        new Bosta_Admin_Menu();
        new Bosta_Orders();
        // new Bosta_Pickups();
        new Bosta_Settings();
        new Bosta_Order_Actions();
        new Bosta_Bulk_Actions();
    }
    add_action('admin_init', 'bosta_wc_admin_init');
}

// Activation Hook
function bosta_wc_activate() {
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'bosta_wc_activate');

// Deactivation Hook
function bosta_wc_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'bosta_wc_deactivate');
