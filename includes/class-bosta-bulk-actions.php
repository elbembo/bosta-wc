<?php
if (!defined('ABSPATH')) {
    exit;
}
require_once 'class-bosta-api.php';
class Bosta_Bulk_Actions {
    private $api;

    public function __construct() {
        $this->api = new Bosta_API();
        add_filter('bulk_actions-edit-shop_order', array($this, 'bosta_sync_cash_collection_orders'), 20);
        add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'bosta_sync_cash_collection_orders'), 20);

        add_filter('bulk_actions-edit-shop_order', array($this,'bosta_sync'), 20);
        add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'bosta_sync'), 20);

        add_filter('bulk_actions-edit-shop_order', array($this,'bosta_print_awb', 20));
        add_filter('bulk_actions-woocommerce_page_wc-orders', array($this,'bosta_print_awb'), 20);

        add_filter('handle_bulk_actions-edit-shop_order', array($this,'bosta_handle_bulk_action'), 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this,'bosta_handle_bulk_action'), 10, 3);

        // add_filter('bulk_actions-edit-shop_order', array($this, 'register_bulk_action'));
        // add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_action'), 10, 3);
    }

    public function bosta_sync_cash_collection_orders($actions)
    {
        $actions['sync_cash_collection_orders'] = __('Send Cash Collection Orders', 'woocommerce');
        return $actions;
    }

    public function bosta_sync($actions)
    {
        $actions['sync_to_bosta'] = __('Send To Bosta', 'woocommerce');
        return $actions;
    }

    public function bosta_print_awb($actions)
    {
        $actions['print_bosta_awb'] = __('Print Bosta AirWaybill', 'woocommerce');
        return $actions;
    }

    public function bosta_handle_bulk_action($redirect_to, $action, $order_ids)
    {
        $order_action = $this->handle_order_action($action);
        if (!$order_action) {
            return;
        }
    
        $APIKey = $this->api->get_api_key();
        if (empty($APIKey)) {
            $message = 'API Key is required to be able to sync with Bosta';
            Bosta_Helper::bosta_set_transient('bosta_errors', "<p>{$message}</p>");
            Bosta_Helper::redirect_to_settings_page();
            return;
        }
    
        $orders = wc_get_orders([
            'limit'    => -1,
            'post__in' => $order_ids,
        ]);
    
        if (!empty($orders)) {
            switch ($order_action['actionType']) {
                case 'sync_orders':
                    $this->handle_send_orders_bulk_action([
                        'APIKey'       => $APIKey,
                        'redirect_to'  => $redirect_to,
                        'orders'       => $orders,
                        'order_action' => $order_action,
                    ]);
                    break;
    
                case 'print_awbs':
                    $this->handle_print_awbs_bulk_action([
                        'APIKey' => $APIKey,
                        'orders' => $orders,
                    ]);
                    break;
    
                case 'fetch_status':
                    $this->handle_fetch_status_bulk_action([
                        'APIKey' => $APIKey,
                        'redirect_to'  => $redirect_to,
                        'orders' => $orders,
                    ]);
                    break;
                default:
                    throw new Exception('Unknown action type: ' . $order_action['actionType']);
            }
        }
        return $redirect_to;
    }

    public function handle_order_action($action)
    {
        switch ($action) {
            case 'sync_cash_collection_orders':
                return [
                    'actionType' => 'sync_orders',
                    'orderType' => 15,
                    'addressType' => 'pickupAddress',
                ];
            case 'sync_to_bosta':
                return [
                    'actionType' => 'sync_orders',
                    'orderType' => 10,
                    'addressType' => 'dropOffAddress',
                ];
            case 'print_bosta_awb':
                return [
                    'actionType' => 'print_awbs',
                ];
            case 'fetch_latest_status':
                return [
                    'actionType' => 'fetch_status',
                ];
            default:
                return null;
        }
    }

    public function handle_send_orders_bulk_action($params)
    {
        $APIKey = $params['APIKey'];
        $orders = $params['orders'];
        $order_action = $params['order_action'];
    
        $formatted_orders = [];
        foreach ($orders as $order) {
            $isOrderSyncedWithBosta = !empty($order->get_meta('bosta_tracking_number'));
            if (!$isOrderSyncedWithBosta) {
                $formatted_orders[] = Bosta_Helper::format_order_payload($order, $order_action);
            }
        }
    
        if (empty($formatted_orders)) {
            wp_safe_redirect(add_query_arg(['post_type' => 'shop_order'], admin_url('edit.php')));
            exit;
        }
    
        $chunkSize = 100;
        $chunks = array_chunk($formatted_orders, $chunkSize);
        $successfulDeliveriesCount = 0;
        $allFailedDeliveries = [];
        foreach ($chunks as $chunk) {
            $url = BOSTA_ENV_URL_V2 . '/deliveries/bulk';
            $body = (object)[
                'deliveries' => $chunk,
                'deleteFailedDeliveries' => false
            ];
    
            $response = $this->api->send_api_request('POST', $url, $APIKey, $body);
    
            if (!$response['success']) {
                Bosta_Helper::render_error_notice($response['error']);
                return;
            }
    
            $data = $response['body']['data'];
            $failedDeliveries = $data['failedDeliveries'] ?? [];
            $createdDeliveriesIds = $data['createdDeliveriesIds'] ?? $data;
    
            $this->api->get_woocommerce_deliveries_data($createdDeliveriesIds, $APIKey);
    
            if (!empty($failedDeliveries)) {
                $allFailedDeliveries = array_merge($allFailedDeliveries, $failedDeliveries);
            }
    
            $successfulDeliveriesCount += count($createdDeliveriesIds);
        }
    
        if (!empty($allFailedDeliveries)) {
            array_walk($allFailedDeliveries, function ($failedDelivery) {
                Bosta_Helper::format_failed_order_message($failedDelivery['errorMessage'], $failedDelivery['businessReference']);
            });
        }
    
        if ($successfulDeliveriesCount > 0) {
            set_transient('bosta_success_count', $successfulDeliveriesCount, HOUR_IN_SECONDS);
        }
    
        wp_safe_redirect(add_query_arg(['post_type' => 'shop_order'], admin_url('edit.php')));
        exit;
    }

    public function handle_print_awbs_bulk_action($params)
    {
        $APIKey = $params['APIKey'];
        $orders = $params['orders'];
    
        $delivery_ids = array_filter(array_map(function ($order) {
            return $order->get_meta('bosta_delivery_id');
        }, $orders));
    
        if (empty($delivery_ids)) {
            $error_message = '<p>No orders have been synced with Bosta for AWB printing</p>';
            Bosta_Helper::bosta_set_transient('bosta_errors', $error_message);
            return;
        }
    
        $url = BOSTA_ENV_URL_V2 . '/deliveries/mass-awb?ids=' . implode(',', $delivery_ids) . '&lang=ar';
        $response = $this->api->send_api_request('GET', $url, $APIKey);
    
        if (!$response['success']) {
            Bosta_Helper::format_failed_order_message($response['error']);
            return;
        }
    
        $pdf_data = base64_decode($response['body']['data'], true);
    
        if ($pdf_data === false) {
            $error_message = '<p>Failed to decode PDF data</p>';
            Bosta_Helper::bosta_set_transient('bosta_errors', $error_message);
            return;
        }
    
        Bosta_Helper::render_pdf($pdf_data);
    }

    public function handle_fetch_status_bulk_action($params)
    {
        $APIKey = $params['APIKey'];
        $redirect_to = $params['redirect_to'];
        $orders = $params['orders'];
    
        $deliveriesIds = [];
        foreach ($orders as $order) {
            $deliveryId = $order->get_meta('bosta_delivery_id', true);
            if (!empty($deliveryId)) {
                $deliveriesIds[] = $deliveryId;
            }
        }
    
        $chunkSize = 50;
        $chunks = array_chunk($deliveriesIds, $chunkSize);
        foreach ($chunks as $chunk) {
            $this->api->get_woocommerce_deliveries_data($chunk, $APIKey);
        }
    
        if (!empty($redirect_to)) {
            wp_safe_redirect($redirect_to);
        } else {
            wp_safe_redirect(add_query_arg(['post_type' => 'shop_order'], admin_url('edit.php')));
        }
        exit;
    }

    


    public function register_bulk_action($bulk_actions) {
        $bulk_actions['send_to_bosta'] = __('Send to Bosta', 'bosta-wc');
        return $bulk_actions;
    }

    public function handle_bulk_action($redirect_to, $action, $order_ids) {
        if ($action !== 'send_to_bosta') {
            return $redirect_to;
        }

        $sent_count = 0;
        $error_count = 0;

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                $error_count++;
                continue;
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
                $error_count++;
                continue;
            }
            
            update_post_meta($order_id, '_bosta_tracking_number', $response['tracking_number']);
            $order->update_status('processing', __('Sent to Bosta', 'bosta-wc'));
            $sent_count++;
        }

        $redirect_to = add_query_arg([
            'bosta_bulk_sent' => $sent_count,
            'bosta_bulk_failed' => $error_count
        ], $redirect_to);

        return $redirect_to;
    }
}

new Bosta_Bulk_Actions();
