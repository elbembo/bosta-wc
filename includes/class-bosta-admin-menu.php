<?php
if (!defined('ABSPATH')) {
    exit;
}

class Bosta_Admin_Menu {
    public function __construct() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_notices', array($this, 'bosta_woocommerce_notice'));
        add_action('admin_print_styles', array($this,'register_stylesheet'));
    }

    public function register_admin_menu() {
        add_menu_page(
            __('Bosta', 'bosta-wc'), 
            __('Bosta', 'bosta-wc'), 
            'manage_options', 
            'bosta-dashboard', 
            array($this, 'dashboard_page'), 
            plugin_dir_url(BOSTA_BASE_FILE) . '/assets/images/bosta.svg', 
            56
        );
        add_submenu_page('bosta-dashboard', __('Pickup Requests', 'bosta-wc'), __('Pickup Requests', 'bosta-wc'), 'manage_options', 'bosta-pickup-requests', array($this, 'pickup_requests_page'));
        
         
    }
    function register_stylesheet()
    {
        $main_css_file = BOSTA_WC_PATH . 'assets/css/admin-style.css';

        $main_css_version = filemtime($main_css_file);


        wp_enqueue_style( 'bosta-stylesheet', plugins_url('../assets/css/admin-style.css', __FILE__), 
            array(), 
            $main_css_version
        );
    }

    public function dashboard_page() {
        echo '<div class="wrap"><h1>' . __('Bosta Dashboard', 'bosta-wc') . '</h1></div>';
    } 

    public function pickup_requests_page() {
        echo '<div class="wrap"><h1>' . __('Bosta Pickup Requests', 'bosta-wc') . '</h1></div>';
    }
    
    public function bosta_woocommerce_notice()
    {
        //check if woocommerce installed and activated
        if (!class_exists('WooCommerce')) {
            echo
            '<div class="error notice-warning text-bold">
                  <p>
                    <img src="' . esc_url(plugins_url('assets/images/bosta.svg', BOSTA_BASE_FILE)) . '" alt="Bosta" style="height:13px; width:25px;">
                    <strong>' . sprintf(esc_html__('Bosta requires WooCommerce to be installed and active. You can download %s here.'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong>
                  </p>
                </div>';
        }
    
        $success_count = get_transient('bosta_success_count');
        if ($success_count) {
            Bosta_Helper::render_success_notice($success_count);
            delete_transient('bosta_success_count');
        }
    
        $bosta_errors = get_transient('bosta_errors');
        if ($bosta_errors) {
            Bosta_Helper::render_error_notice($bosta_errors);
            delete_transient('bosta_errors');
        }
    
        $failed_orders = get_transient('bosta_failed_orders');
        if ($failed_orders) {
            Bosta_Helper::render_failed_orders_notice($failed_orders);
            delete_transient('bosta_failed_orders');
        }
    }
    
}
new Bosta_Admin_Menu();

