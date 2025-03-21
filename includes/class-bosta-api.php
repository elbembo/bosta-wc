<?php
if (!defined('ABSPATH')) {
    exit;
}

class Bosta_API {
    private $api_url;
    private $api_key;

    public function __construct() {
        $this->api_url = BOSTA_WC_API_URL;
        $settings = get_option('woocommerce_bosta_settings');
        $this->api_key = isset($settings['APIKey']) ? sanitize_text_field($settings['APIKey']) : '';
    }
    public function send_api_request($method, $url, $body = null)
    {
        $args = [
            'timeout' => 30,
            'method'  => strtoupper($method),
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-Requested-By'   => 'WooCommerce',
                'X-Plugin-Version' => PLUGIN_VERSION,
            ],
        ];
    
        if (!empty($this->api_key)) {
            $args['headers']['authorization'] = $this->api_key;
        }
    
        if ($body) {
            $args['body'] = json_encode($body);
        }
    
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }
        $response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
    
        if ($response_code < 200 || $response_code >= 300 || empty($response_body)) {
            $decoded_body = json_decode($response_body, true);
    
            if (isset($decoded_body['message'])) {
                $error_message = $decoded_body['message'];
            } elseif (isset($decoded_body[0]['message'])) {
                $error_message = $decoded_body[0]['message'];
            } else {
                $error_message = 'Unknown error';
            }
            
            $error_messages = [
                'success' => false,
                'error'   => $error_message,
            ];
    
            if (isset($decoded_body['data'])) {
                $error_messages['data'] = $decoded_body['data'];
            }
    
            return $error_messages;
        }
    
        return [
            'success' => true,
            'code'    => $response_code,
            'body'    => json_decode($response_body, true),
        ];
    }
    public function validate_api_key($bostaApiKey)
    {
        if ($bostaApiKey == null) {
            return false;
        }
    
        $url = BOSTA_ENV_URL_V0 . '/businesses/' . esc_html($bostaApiKey) . '/info';
        $response = $this->send_api_request('GET', $url);
    
        if (!$response['success']) {
            return false;
        }
    
        return true;
    }
    public function get_api_key()
    {
        $apikey = get_option('woocommerce_bosta_settings')['APIKey'];
        if (isset($apikey)) {
            return sanitize_text_field($apikey);
        }
    }
    public function get_country_id()
    {
        $APIKey = $this->get_api_key();
        if (empty($APIKey)) {
            return;
        }
    
        $url = BOSTA_ENV_URL_V0 . '/businesses/' . esc_html(bosta_get_api_key()) . '/info';
        $response = $this->send_api_request('GET', $url);
    
        if (!$response['success']) {
            return BOSTA_EGYPT_COUNTRY_ID;
        } else {
            $business = $response['body'];
            $country_id = $business['country']['_id'];
            set_transient('bosta_country_id_Transient', $country_id, bosta_country_id_duration);
            return $country_id;
        }
    }
    public function check_area_coverage($area)
    {
        return isset($area['dropOffAvailability']) && $area['dropOffAvailability'] == true;
    }
    public function get_zoning()
    {
        $country_id = get_transient('bosta_country_id_Transient');
        if (!$country_id) {
            $country_id = $this->get_country_id();
            if ($country_id == null) {
                return array();
            }
        }
    
        $bosta_zoning_key_cache = 'bosta_zoning';
        $bosta_zoning = get_transient($bosta_zoning_key_cache);
    
        if (!$bosta_zoning) {
            $url = BOSTA_ENV_URL_V2 .  '/cities/getAllDistricts?countryId=' . esc_html($country_id);
            $response = $this->send_api_request('GET', $url);
            if (!$response['success']) {
                return array();
            }
            $bosta_zoning = $response['body']['data'];
            set_transient($bosta_zoning_key_cache, $bosta_zoning, bosta_cache_duration);
        }
    
        return $bosta_zoning ? $bosta_zoning : [];
    }
    public function get_cities()
    {
        $bosta_zoning = $this->get_zoning();
        $bosta_cities = [];
    
        if (defined('ICL_SITEPRESS_VERSION')) {
            $current_language = apply_filters('wpml_current_language', 'ar');
            $current_language = ($current_language === 'en') ? 'en' : 'ar';
        } else {
            $current_language = 'ar';
        }
        $is_arabic = $current_language === 'ar';
    
        foreach ($bosta_zoning as $city) {
            if (!isset($city['cityOtherName']) || !$this->check_area_coverage($city)) {
                continue;
            }
            $city_code = $city['cityCode'];
            $city_name = $is_arabic ? $city['cityOtherName'] : $city['cityName'];
            $bosta_cities[$city_code] = $city_name;
        }
        return $bosta_cities;
    }
    public function get_city_areas()
    {
        $bosta_zoning = $this->get_zoning();
        
        if (defined('ICL_SITEPRESS_VERSION')) {
            $current_language = apply_filters('wpml_current_language', 'ar');
            $current_language = ($current_language === 'en') ? 'en' : 'ar';
        } else {
            $current_language = 'ar';
        }
        $is_arabic = $current_language === 'ar';
    
        $bosta_city_areas_cache_key = 'bosta_city_areas' . '_' . $current_language;
        $bosta_city_areas = get_transient($bosta_city_areas_cache_key);
        if (!$bosta_city_areas) {
            $bosta_city_areas = [];
            foreach ($bosta_zoning as $city) {
                $city_code = $city['cityCode'];
                $city_areas = '';
                foreach ($city['districts'] as $district) {
                    $zone_name = $is_arabic ? $district['zoneOtherName'] : $district['zoneName'];
                    $district_name = $is_arabic ? $district['districtOtherName'] : $district['districtName'];
    
                    if ($zone_name === $district_name) {
                        $area = $district_name;
                    } else {
                        $area = $zone_name . ' - ' . $district_name;
                    }
    
                    $city_areas .= sprintf(
                        '<option value="%s">%s</option>',
                        esc_attr($district['districtId']),
                        esc_html($area)
                    );
                }
                $bosta_city_areas[$city_code] = $city_areas;
            }
            set_transient($bosta_city_areas_cache_key, $bosta_city_areas, bosta_cache_duration);
        }
        return $bosta_city_areas ? $bosta_city_areas : [];
    }

    public function get_woocommerce_deliveries_data($deliveriesIds, $APIKey)
    {
        if (!empty($deliveriesIds)) {
            $url = BOSTA_ENV_URL_V2 . '/deliveries/woocommerce-data';
            $body = (object)[
                'deliveriesIds' => $deliveriesIds,
            ];
    
            $response = $this->send_api_request('POST', $url, $APIKey, $body);
            $deliveriesData = $response['body']['data'] ?? [];
            $returnedDeliveryIds = [];
            foreach ($deliveriesData as $deliveryData) {
                $order_id = substr($deliveryData['uniqueBusinessReference'], 3);
                $order = wc_get_order($order_id);
                if ($order) {
                    bosta_update_order_metadata($order, $deliveryData);
                }
                $returnedDeliveryIds[$deliveryData['_id']] = $deliveryData['_id'];
            }
    
            if (count($deliveriesIds) !== count($returnedDeliveryIds)) {
                foreach ($deliveriesIds as $deliveryId) {
                    $order = bosta_get_order_by_metadata('bosta_delivery_id', $deliveryId);
                    if (!isset($returnedDeliveryIds[$deliveryId])) {
    
                        if ($order) {
                            bosta_delete_order_metadata($order);
                        }
                    }
                }
            }
            
        }
    }


    public function get_order_status($tracking_number) {
        return $this->request("/shipments/$tracking_number/status");
    }

    public function create_order($order_data) {
        return $this->send_api_request("/shipments", 'POST', $order_data);
    }

    public function create_pickup_request($pickup_data) {
        return $this->send_api_request("/pickups", 'POST', $pickup_data);
    }
    public function send_order_to_bosta($order_data) {
        if (empty($this->api_key)) {
            return ['error' => __('API Key is missing.', 'bosta-wc')];
        }

        $endpoint = $this->base_url . 'shipments';
        $response = wp_remote_post($endpoint, array(
            'method'    => 'POST',
            'timeout'   => 45,
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body'      => json_encode($order_data),
        ));

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['trackingNumber'])) {
            return ['error' => __('Failed to get tracking number.', 'bosta-wc')];
        }

        return ['tracking_number' => $body['trackingNumber']];
    }
    public function request_pickup($pickup_data) {
        if (empty($this->api_key)) {
            return ['error' => __('API Key is missing.', 'bosta-wc')];
        }

        $endpoint = $this->base_url . 'pickups';
        $response = wp_remote_post($endpoint, array(
            'method'    => 'POST',
            'timeout'   => 45,
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body'      => json_encode($pickup_data),
        ));

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['pickupId'])) {
            return ['error' => __('Failed to create pickup request.', 'bosta-wc')];
        }

        return ['pickup_id' => $body['pickupId']];
    }
}
