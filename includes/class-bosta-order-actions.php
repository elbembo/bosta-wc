<?php
if (!defined('ABSPATH')) {
    exit;
}
require_once 'class-bosta-api.php';
class Bosta_Order_Actions {
    private $api;

    public function __construct() {
        $this->api = new Bosta_API();
        add_filter('woocommerce_admin_order_actions', array($this, 'add_send_to_bosta_button'), 10, 2);
        add_action('admin_post_bosta_send_order', array($this, 'handle_send_order'));
        
        add_filter('woocommerce_admin_order_actions', array($this, 'add_bosta_print_AWB_button'), 10, 2);
        add_action('admin_post_bosta_print_awb', array($this, 'handle_bosta_print_awb'));
    }

    public function add_send_to_bosta_button($actions, $order) {
        if ($order->get_status() !== 'completed') {
            $actions['send_to_bosta'] = array(
                'url'  => wp_nonce_url(admin_url('admin-post.php?action=bosta_send_order&order_id=' . $order->get_id()), 'bosta_send_order_nonce'),
                'name' => __('Send to Bosta', 'bosta-wc'),
                'action' => 'send_to_bosta',
            );
        }
        return $actions;
    }
    public function add_bosta_print_AWB_button($actions, $order) {
        if ($order->get_status() !== 'completed') {
            $actions['print_awb'] = array(
                'url'  => wp_nonce_url(admin_url('admin-post.php?action=print_awb&order_id=' . $order->get_id()), 'print_awb_nonce'),
                'name' => __('Send to Bosta', 'bosta-wc'),
                'action' => 'print_awb',
            );
        }
        return $actions;
    }

    public function handle_send_order() {
        if (!isset($_GET['order_id']) || !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'bosta_send_order_nonce')) {
            wp_die(__('Security check failed', 'bosta-wc'));
        }

        $order_id = intval($_GET['order_id']);
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(__('Invalid order', 'bosta-wc'));
        }

        $order_data = [
            'order_id' => $order_id,
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_phone' => $order->get_billing_phone(),
            'customer_address' => $order->get_billing_address_1(),
            'total_price' => $order->get_total(),
        ];

        $response = $this->api->send_order_to_bosta($order_data);
        
        if (!empty($response['error'])) {
            wp_redirect(admin_url('edit.php?post_type=shop_order&bosta_error=1'));
            exit;
        }
        
        update_post_meta($order_id, '_bosta_tracking_number', $response['tracking_number']);
        $order->update_status('processing', __('Sent to Bosta', 'bosta-wc'));
        
        wp_redirect(admin_url('edit.php?post_type=shop_order&bosta_success=1'));
        exit;
    }
    public function handle_bosta_print_awb() {
        if (!isset($_GET['order_id']) || !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'bosta_send_order_nonce')) {
            wp_die(__('Security check failed', 'bosta-wc'));
        }

        $order_id = intval($_GET['order_id']);
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(__('Invalid order', 'bosta-wc'));
        }

        $order_data = [
            'order_id' => $order_id,
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_phone' => $order->get_billing_phone(),
            'customer_address' => $order->get_billing_address_1(),
            'total_price' => $order->get_total(),
        ];

        $response = $this->api->send_order_to_bosta($order_data);
        
        if (!empty($response['error'])) {
            wp_redirect(admin_url('edit.php?post_type=shop_order&bosta_error=1'));
            exit;
        }
        
        update_post_meta($order_id, '_bosta_tracking_number', $response['tracking_number']);
        $order->update_status('processing', __('Sent to Bosta', 'bosta-wc'));
        
        wp_redirect(admin_url('edit.php?post_type=shop_order&bosta_success=1'));
        exit;
    }
}

new Bosta_Order_Actions();