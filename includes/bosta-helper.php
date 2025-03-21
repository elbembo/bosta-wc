<?php
if (!defined('ABSPATH')) {
    exit;
}

class Bosta_Helper {
    public static function bosta_set_transient($key, $value, $expiration = HOUR_IN_SECONDS)
    {
        $existing_value = get_transient($key) ?: '';
        $updated_value = $existing_value . $value;
        set_transient($key, $updated_value, $expiration);
    }
    public static function format_failed_order_message($error_message, $order_id = null)
    {
        $formatted_error_message = '<p>' . ($order_id ? '<strong>Order ID:</strong> ' . esc_html($order_id) . '<br>' : '') .
            '<strong>Reason:</strong> ' . esc_html(print_r($error_message, true)) . '</p>';
            self::bosta_set_transient('bosta_failed_orders', $formatted_error_message);
    }
    public static function render_pdf($pdf_data)
    {
        header('Content-Type: application/pdf');
        header('Cache-Control: public, must-revalidate, max-age=0');
        header('Pragma: public');
        ob_clean();
        flush();
        echo $pdf_data;
        exit;
    }
    public static function format_date($date)
    {
        try {
            $pos = strrpos($date, '(');
            $clean_date = $pos !== false ? substr($date, 0, $pos) : $date;
            $datetime = new DateTime($clean_date, new DateTimeZone('UTC'));
            $datetime->setTimezone(new DateTimeZone('Africa/Cairo'));
            return $datetime->format('l, d/m/Y h:ia');
        } catch (Exception $e) {
            error_log('Error parsing date: ' . $e->getMessage());
            return null;
        }
    }

    public static function check_disable_bosta_zoning_checkbox()
    {
        $disable_bosta_zoning = get_option('woocommerce_bosta_settings')['DisableBostaZoning'];
        return $disable_bosta_zoning === 'yes';
    }

    public static function format_order_payload($order, $order_action)
    {
        $productDescription = get_option('woocommerce_bosta_settings')['ProductDescription'];
        $allowToOpenPackage = get_option('woocommerce_bosta_settings')['AllowToOpenPackage'];
        $orderRef = get_option('woocommerce_bosta_settings')['OrderRef'];
    
        $newOrder = new stdClass();
        $newOrder->id = $order->get_id();
        $newOrder->type = $order_action['orderType'];
        $newOrder->notes = $order->get_customer_note();
        $newOrder->uniqueBusinessReference = "WC_" . $order->get_id();
        $newOrder->specs = new stdClass();
        $newOrder->specs->packageDetails = self::format_package_details($order, $productDescription);
    
        if ($allowToOpenPackage === 'yes') {
            $newOrder->allowToOpenPackage = true;
        }
    
        if ($orderRef === 'yes') {
            $newOrder->businessReference = 'Woocommerce_' . $order->get_id();
        }
    
        $newOrder->receiver = self::format_receiver_details($order);
        $newOrder->{$order_action['addressType']} = self::format_address_details($order);
        if ($order->get_payment_method() === 'cod') {
            $newOrder->cod = (float) $order->get_total();
        }
        
        return $newOrder;
    }

    public static function format_package_details($order, $productDescription)
    {
        $items = $order->get_items();
        $itemsCount = 0;
        $descArray = [];
        $index = 1;
    
        foreach ($items as $item) {
            $product = $item->get_product();
            $itemsCount += $item->get_quantity();
            $descArray[] = self::format_order_description($productDescription, $index, $product->get_sku(), $product->get_name(), $item->get_quantity());
            $index++;
        }
    
        $packageDetails = new stdClass();
        $packageDetails->itemsCount = $itemsCount;
        $packageDetails->description = implode(", ", $descArray);
    
        return $packageDetails;
    }

    public static function format_order_description($productDescription, $index, $sku, $name, $quantity)
    {
        $desc = "Product_$index: ";
        if ($productDescription === 'yes') $desc .= $name;
        if (!empty($sku)) $desc .= " [$sku]";
        $desc .= " (" . $quantity . ")";
    
        return $desc;
    }
    
    public static function format_receiver_details($order)
    {
        $firstname = $order->get_billing_first_name() ?: $order->get_shipping_first_name();
        $lastname = $order->get_billing_last_name() ?: $order->get_shipping_last_name();
        $receiver = new stdClass();
        $receiver->firstName = mb_substr($firstname, 0, 50);
        $receiver->lastName = $lastname;
        $receiver->phone = $order->get_billing_phone() ?: $order->get_shipping_phone();
        return $receiver;
    }
    
    function format_address_details($order)
    {
        $states = WC()->countries->get_states('EG');
        $address = new stdClass();
    
        $address->firstLine = $order->get_billing_address_1() ?: $order->get_shipping_address_1();
        $address->secondLine = $order->get_billing_address_2() ?: $order->get_shipping_address_2();
    
        $city_code = $order->get_billing_state() ?: $order->get_shipping_state();
        if (isset($city_code) && isset($states[$city_code])) {
            $address->city = $states[$city_code];
        }
    
        $district_id = $order->get_meta('_billing_area');
        if (!empty($district_id)) {
            $address->districtId = $district_id;
        }
        return $address;
    }

    public static function redirect_to_settings_page()
    {
        $redirect_url = admin_url('admin.php?') . 'page=wc-settings&tab=shipping&section=bosta';
        wp_redirect($redirect_url);
        exit;
    }
    public static function render_error_notice($error_message)
    {
        echo '<div class="notice notice-error is-dismissible">';
        echo $error_message;
        echo '</div>';
    }

    public static function render_failed_orders_notice($failed_orders)
    {
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p>Some orders failed to be synced at Bosta. <span class="toggle-details" style="cursor: pointer; color: red;">&#9660;</span></p>';
        echo '<div class="details hidden" style="max-height: 150px; overflow-y: auto; margin: 10px;">';
        echo $failed_orders;
        echo '</div>';
        echo '</div>';
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                document.querySelector('.toggle-details').addEventListener('click', function() {
                    const detailsSection = document.querySelector('.details');
                    detailsSection.classList.toggle('hidden');
                    this.innerHTML = detailsSection.classList.contains('hidden') ? '&#9660;' : '&#9650;';
                });
            });
        </script>
    <?php
    }

    public static function render_success_notice($success_count)
    {
        if ($success_count) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . sprintf(esc_html__('%d orders successfully synced at Bosta.'), $success_count) . '</p>';
            echo '</div>';
        }
    }
    //from out ai
    public static function get_bosta_settings() {
        return get_option('woocommerce_bosta_settings', []);
    }

    public static function get_api_key() {
        $settings = self::get_bosta_settings();
        return isset($settings['APIKey']) ? sanitize_text_field($settings['APIKey']) : '';
    }

    public static function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Bosta] ' . $message);
        }
    }

    public static function format_phone_number($phone) {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    public static function sanitize_order_data($order) {
        return [
            'order_id' => $order->get_id(),
            'customer_name' => sanitize_text_field($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'customer_phone' => self::format_phone_number($order->get_billing_phone()),
            'customer_address' => sanitize_text_field($order->get_billing_address_1()),
            'total_price' => wc_format_decimal($order->get_total(), 2),
        ];
    }
}
