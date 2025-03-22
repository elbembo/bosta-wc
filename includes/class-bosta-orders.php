<?php
if (!defined('ABSPATH')) {
    exit;
}

class Bosta_Orders {
    private $api;

    public function __construct() {
        $this->api = new Bosta_API();
        // add_filter('bulk_actions-edit-shop_order', array($this, 'register_bulk_action'));
        // add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_action'), 10, 3);
        // add_filter('woocommerce_admin_order_actions', array($this, 'add_send_order_button'), 10, 2);

        add_filter('woocommerce_admin_order_preview_get_order_details', array($this,'admin_order_preview_add_custom_meta_data'), 10, 2);
        add_filter('woocommerce_states', array($this,'custom_woocommerce_states'));

        add_filter('manage_edit-shop_order_columns', array($this,'wco_add_columns'));
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this,'wco_add_columns'));

        add_action('manage_shop_order_posts_custom_column', array($this,'wco_column_cb_data'), 10, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this,'wco_column_cb_data'), 10, 2);

        add_action('woocommerce_order_list_table_extra_tablenav', array($this, 'add_extra_tablenav_components_hpos'), 20, 2);

        add_action('manage_posts_extra_tablenav', array($this,'add_extra_tablenav_components'), 20);

        add_filter('woocommerce_order_table_search_query_meta_keys', array($this,'woocommerce_shop_order_search_order_total'));
        add_filter('woocommerce_shop_order_search_fields', array($this,'woocommerce_shop_order_search_order_total'));

        add_action('woocommerce_order_list_table_restrict_manage_orders', array($this,'add_custom_bosta_statues_filter_for_hpos'));
        add_filter('woocommerce_order_list_table_prepare_items_query_args', array($this,'apply_custom_bosta_statues_filter_for_hpos'));

    }
    public function admin_order_preview_add_custom_meta_data($data, $order)
    {
        $APIKey = $this->api->get_api_key();
        if (empty($APIKey)) {
            $message = 'API Key is required to be able to sync with Bosta';
            Bosta_Helper::bosta_set_transient('bosta_errors', "<p>{$message}</p>");
            // bosta_redirect_to_settings_page();
            return;
        }

        $trackingNumber = $order->get_meta('bosta_tracking_number', true);
        if (empty($trackingNumber)) {
            $message = 'Order is not synced at Bosta';
            Bosta_Helper::bosta_set_transient('bosta_errors', "<p>{$message}</p>");
            return;
        }

        $url = BOSTA_ENV_URL_V2 . '/deliveries/business/' . $trackingNumber;
        $response = $this->api->send_api_request('GET', $url, $APIKey);
        if (!$response['success']) {
            Bosta_Helper::format_failed_order_message($response['error']);
            return;
        }
        $orderDetails = $response['body']['data'];
        $data = array_merge($data, $this->preview_extract_order_timeline_details($orderDetails));
        $data = array_merge($data, $this->preview_extract_order_details($orderDetails));
        $data = array_merge($data, $this->preview_extract_customer_info($orderDetails));
        $data = array_merge($data, $this->preview_extract_pickup_info($orderDetails));
        $data = array_merge($data, $this->preview_extract_bosta_performance_info($orderDetails));

        return $data;
    }

    private function preview_extract_order_timeline_details($orderDetails)
    {
        $timelineData = [];
    
        if (!empty($orderDetails['timeline'])) {
            foreach ($orderDetails['timeline'] as $x => $timeline) {
                $timelineData["timeline_value_$x"] = $timeline['value'] ?? 'N/A';
                $timelineData["timeline_date_$x"] = isset($timeline['date']) ? Bosta_Helper::format_date($timeline['date']) : 'N/A';
                $isDone = $timeline['done'] ?? false;
                $timelineData["timeline_done_$x"] = $isDone ? 'status_done' : 'status_not_done';
                if ($isDone) {
                    $timelineData["timeline_next_action"] = $timeline['nextAction'] ?? 'N/A';
                    $timelineData["timeline_shipment_age"] = $timeline['nextAction'] ?? 'N/A';
                }
            }
            $timelineLength = count($orderDetails['timeline']);
            set_transient('bosta_timelineLength', $timelineLength, HOUR_IN_SECONDS);
        }
    
        return $timelineData;
    }
    
    private function preview_extract_order_details($orderDetails)
    {
        return [
            'trackingNumber' => $orderDetails['trackingNumber'] ?? 'N/A',
            'status' => $orderDetails['state']['value'] ?? 'N/A',
            'type' => $orderDetails['type']['value'] ?? 'N/A',
            'cod' => $orderDetails['cod'] ?? '0',
            'createdAt' => Bosta_Helper::format_date($orderDetails['createdAt']),
            'updatedAt' => Bosta_Helper::format_date($orderDetails['updatedAt']),
            'itemsCount' => $orderDetails['specs']['packageDetails']['itemsCount'] ?? 'N/A',
            'notes' => $orderDetails['notes'] ?? 'N/A'
        ];
    }
    
    private function preview_extract_customer_info($orderDetails)
    {
        return [
            'fullName' => $orderDetails['receiver']['fullName'] ?? 'N/A',
            'phone' => $orderDetails['receiver']['phone'] ?? 'N/A',
            'dropOffAddressCity' => $orderDetails['dropOffAddress']['city']['name'] ?? 'N/A',
            'dropOffAddressZone' => $orderDetails['dropOffAddress']['zone']['name'] ?? 'N/A',
            'dropOffAddressDistrict' => $orderDetails['dropOffAddress']['district']['name'] ?? 'N/A',
            'dropOffAddressFistLine' => $orderDetails['dropOffAddress']['firstLine'] ?? 'N/A',
            'dropOffAddressBuilding' => $orderDetails['dropOffAddress']['buildingNumber'] ?? 'N/A',
            'dropOffAddressFloor' => $orderDetails['dropOffAddress']['floor'] ?? 'N/A',
            'dropOffAddressApartment' => $orderDetails['dropOffAddress']['apartment'] ?? 'N/A'
        ];
    }
    
    private function preview_extract_pickup_info($orderDetails)
    {
        return [
            'pickupAddressCity' => $orderDetails['pickupAddress']['city']['name'] ?? 'N/A',
            'pickupAddressZone' => $orderDetails['pickupAddress']['zone']['name'] ?? 'N/A',
            'pickupAddressDistrict' => $orderDetails['pickupAddress']['district']['name'] ?? 'N/A',
            'pickupAddressFistLine' => $orderDetails['pickupAddress']['firstLine'] ?? 'N/A',
            'pickupAddressBuilding' => $orderDetails['pickupAddress']['buildingNumber'] ?? 'N/A',
            'pickupAddressFloor' => $orderDetails['pickupAddress']['floor'] ?? 'N/A',
            'pickupAddressApartment' => $orderDetails['pickupAddress']['apartment'] ?? 'N/A',
            'pickupRequestId' => $orderDetails['pickupRequestId'] ?? 'N/A'
        ];
    }
    
    private function preview_extract_bosta_performance_info($orderDetails)
    {
        $promise = 'Not started yet';
        if (!empty($orderDetails['sla'])) {
            $isExceeded = $orderDetails['sla']['e2eSla']['isExceededE2ESla'] ?? false;
            $data['promise'] = $isExceeded ? 'Not met' : 'Met';
        }
    
        return [
            'outboundActionsCount' => $orderDetails['outboundActionsCount'] ?? '0',
            'deliveryAttemptsLength' => $orderDetails['deliveryAttemptsLength'] ?? '0',
            'promise' => $promise
        ];
    }

    public function custom_woocommerce_states($states)
    {
        $bosta_cities = $this->api->get_cities();
        $states['EG'] = $bosta_cities;
        return $states;
    }

    public function wco_add_columns($columns)
    {
        $order_total = $columns['order_total'];
        $order_date = $columns['order_date'];
        $order_status = $columns['order_status'];
        $wc_actions = $columns['wc_actions'];

        unset($columns['order_date']);
        unset($columns['order_status']);
        unset($columns['order_total']);
        unset($columns['wc_actions']);

        $columns["bosta_tracking_number"] = __("Bosta Tracking Number", "bosta-wc");
        $columns['order_date'] = $order_date;
        $columns['order_status'] = $order_status;
        $columns["bosta_status"] = __("Bosta Status", "bosta-wc");
        $columns["bosta_delivery_date"] = __("Delivered at", "bosta-wc");
        $columns["bosta_customer_phone"] = __("Customer phone", "bosta-wc");
        $columns['order_total'] = $order_total;
        $columns['wc_actions'] = $wc_actions ;

        return $columns;
    }

    public function wco_column_cb_data($colName, $orderId)
    {
        $order = wc_get_order($orderId);
    
        $status = $order->get_meta('bosta_status', true);
        $trackingNumber = $order->get_meta('bosta_tracking_number', true);
        $deliveryDate = $order->get_meta('bosta_delivery_date', true);
        $customerPhone = $order->get_meta('bosta_customer_phone', true);
    
        if ($colName == 'bosta_status') {
            echo !empty($status) ? esc_html($status) : "---";
        }
    
        if ($colName == 'bosta_tracking_number') {
            echo !empty($trackingNumber) ? esc_html($trackingNumber) : "---";
        }
    
        if ($colName == 'bosta_delivery_date') {
            echo !empty($deliveryDate) ? $deliveryDate : "---";
        }
    
        if ($colName == 'bosta_customer_phone') {
            echo !empty($customerPhone) ? esc_html($customerPhone) : "---";
        }
    }

    public function add_extra_tablenav_components_hpos($post_type, $which)
    {
        if ('shop_order' !== $post_type || 'top' !== $which) {
            return;
        }
    
        $nonces = [
            'send_all' => wp_create_nonce('bosta_send_all_nonce'),
            'fetch_status' => wp_create_nonce('bosta_fetch_status_nonce'),
        ];
    
        $action_handlers = [
            'create_pickup' => function () {
                $redirect_url = add_query_arg('page', 'bosta-woocommerce-create-edit-pickup', admin_url('admin.php'));
                wp_safe_redirect($redirect_url);
                exit;
            },
            'send_all_orders' => function () {
                $this->handle_custom_bulk_action(
                    'bosta_send_all_nonce',
                    'bosta_send_all_nonce_field',
                    'sync_to_bosta'
                );
            },
            'fetch_status' => function () {
                $this->handle_custom_bulk_action(
                    'bosta_fetch_status_nonce',
                    'bosta_fetch_status_nonce_field',
                    'fetch_latest_status'
                );
            },
        ];
    
        foreach ($action_handlers as $action => $handler) {
            $value = isset($_GET[$action]) ? sanitize_text_field($_GET[$action]) : null;
            if ($value === 'yes') {
                $handler();
                break;
            }
        }
    
        $this->render_custom_buttons($nonces['send_all'], $nonces['fetch_status']);
    }

    public function add_extra_tablenav_components($which)
    {
        $screen = get_current_screen();
        $this->add_extra_tablenav_components_hpos($screen->post_type, $which);
    }

    private function handle_custom_bulk_action($nonce_action, $nonce_field, $action_type)
    {
        $nonce_value = isset($_GET[$nonce_field]) ? sanitize_text_field($_GET[$nonce_field]) : null;
        if ($nonce_value && check_admin_referer($nonce_action, $nonce_field)) {
            $current_user_id = get_current_user_id();
            $current_page = isset($_GET['page_num']) ? $_GET['page_num'] : 1;
            $orders_per_page = get_user_option('edit_shop_order_per_page', $current_user_id);
    
            $orderIds = wc_get_orders([
                'limit' => $orders_per_page,
                'paged' => $current_page,
                'return' => 'ids',
            ]);
    
            $redirect_url = add_query_arg('paged', $current_page, admin_url('edit.php?post_type=shop_order'));
            bosta_handle_bulk_action($redirect_url, $action_type, $orderIds);
        } else {
            wp_die(__('Invalid nonce! Something went wrong.', 'bosta'));
        }
    }

    private function render_custom_buttons($send_all_nonce, $fetch_status)
    {
        ?>
            <div class="alignleft bosta_custom_buttons_div">
                <div class="rightDiv">
                    <button type="submit" name="create_pickup" class="orders-button bosta_custom_button" value="yes">Create Pickup</button>
                    <button type="submit" name="send_all_orders" class="orders-button bosta_custom_button" value="yes">Send all Orders to Bosta</button>
                    <input type="hidden" name="bosta_send_all_nonce_field" value="<?php echo esc_attr($send_all_nonce); ?>">
                </div>
                <div class="leftDiv">
                    <button type="submit" name="fetch_status" class="danger-button bosta_custom_button" value="yes">
                        <img class="refreshIcon" src="<?php echo esc_url(plugins_url("assets/images/refreshIcon.png", BOSTA_BASE_FILE)); ?>" alt="Bosta"> Refresh Bosta Status
                    </button>
                    <input type="hidden" name="bosta_fetch_status_nonce_field" value="<?php echo esc_attr($fetch_status); ?>">
                </div>
                <input type="hidden" name="page_num" value="<?php echo esc_attr($_GET['paged'] ?? '1'); ?>">
            </div>
        <?php
    }
    
    public function woocommerce_shop_order_search_order_total($search_fields)
    {
        $search_fields[] = 'bosta_tracking_number';
        $search_fields[] = 'bosta_customer_phone';
        $search_fields[] = 'bosta_status';
    
        return $search_fields;
    }

    public function add_custom_bosta_statues_filter_for_hpos() {
        $current_status = isset($_GET['bosta_status']) ? sanitize_text_field($_GET['bosta_status']) : '';

        $options = array(
            ''          => __('All Bosta Statuses', 'woocommerce'),
            'created'   => __('Created', 'woocommerce'),
            'delivered' => __('Delivered', 'woocommerce'),
            'terminated' => __('Terminated', 'woocommerce'),
            'returned' => __('Returned', 'woocommerce'),
        );

        echo '<select name="bosta_status" id="bosta_status">';
        foreach ($options as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($current_status, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function apply_custom_bosta_statues_filter_for_hpos($query_args) {
        if (!empty($_GET['bosta_status'])) {
            $bosta_status = sanitize_text_field($_GET['bosta_status']);
            
            $query_args['meta_query'][] = array(
                'key'     => 'bosta_status',
                'value'   => $bosta_status,
                'compare' => '=',
            );
        }
    
        return $query_args;
    }

    



    public function get_order_by_metadata($meta_key, $meta_value) 
    {	
        $page_num = isset($_GET['page_num']) ? $_GET['page_num'] : 1;
        $query = new \WC_Order_Query([
            'limit' => 1, 
            'meta_key' => $meta_key, 
            'meta_value' => $meta_value, 
            'paged' => $page_num
        ]);
        $orders = $query->get_orders();
        return !empty($orders) ? $orders[0] : null;
    }
    public function update_order_metadata($order, $bosta_data)
    {
        $is_order_delivered = $bosta_data['state']['code'] == 45;
        $deliveried_at = $is_order_delivered ? Bosta_Helper::format_date($bosta_data['state']['delivering']['time']) : null;
        $meta_mapping = [
            'bosta_delivery_id'     => $bosta_data['_id'] ?? null,
            'bosta_status'          => $bosta_data['state']['value'] ?? null,
            'bosta_tracking_number' => $bosta_data['trackingNumber'] ?? null,
            'bosta_customer_phone'  => $bosta_data['receiver']['phone'] ?? null,
            'bosta_delivery_date' => $deliveried_at
        ];

        foreach ($meta_mapping as $meta_key => $meta_value) {
            if (!empty($meta_value)) {
                $order->update_meta_data($meta_key, $meta_value);
            }
        }
        $order->save();
    }

    public function delete_order_metadata($order)
    {
        $meta_keys = [
            'bosta_delivery_id',
            'bosta_status',
            'bosta_tracking_number',
            'bosta_customer_phone',
            'bosta_delivery_date'
        ];

        foreach ($meta_keys as $meta_key) {
            $order->delete_meta_data($meta_key);
        }

        $order->save();
    }

    

    // AI
    public function register_bulk_action($bulk_actions) {
        $bulk_actions['send_to_bosta'] = __('Send to Bosta', 'bosta-wc');
        return $bulk_actions;
    }

    public function handle_bulk_action($redirect_to, $action, $order_ids) {
        if ($action !== 'send_to_bosta') {
            return $redirect_to;
        }

        foreach ($order_ids as $order_id) {
            $this->send_order_to_bosta($order_id);
        }

        $redirect_to = add_query_arg('bosta_orders_sent', count($order_ids), $redirect_to);
        return $redirect_to;
    }

    public function add_send_order_button($actions, $order) {
        $actions['send_to_bosta'] = [
            'url' => wp_nonce_url(admin_url('admin-post.php?action=send_to_bosta&order_id=' . $order->get_id()), 'send_to_bosta'),
            'name' => __('Send to Bosta', 'bosta-wc'),
            'action' => 'send-to-bosta'
        ];
        return $actions;
    }
    

    public function send_order_to_bosta($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $order_data = [
            'orderId' => $order_id,
            'customerName' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customerPhone' => $order->get_billing_phone(),
            'customerAddress' => $order->get_billing_address_1(),
            'city' => $order->get_billing_city(),
            'postalCode' => $order->get_billing_postcode(),
            'totalAmount' => $order->get_total()
        ];

        $response = $this->api->create_order($order_data);
        if (!empty($response['error'])) {
            error_log('Bosta API Error: ' . $response['error']);
        } else {
            update_post_meta($order_id, '_bosta_tracking_number', $response['trackingNumber']);
        }
    }
}
new Bosta_Orders();