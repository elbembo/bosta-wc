<?php
if (!defined('ABSPATH')) {
    exit;
}
class Bosta_Checkout
{
    private $api;
    public function __construct()
    {
        $this->api = new Bosta_API();
        // إضافة خيار شحن Bosta في صفحة الدفع
        // add_filter('woocommerce_checkout_fields', array($this, 'add_checkout_fields'));

        // حفظ بيانات إضافية عند الدفع
        // add_action('woocommerce_checkout_update_order_meta', array($this, 'save_checkout_fields'));

        // إرسال الطلب تلقائيًا إلى Bosta بعد الدفع
        // add_action('woocommerce_thankyou', array($this, 'send_order_to_bosta'), 10, 1);
        add_filter('woocommerce_checkout_fields', array($this, 'bosta_add_dynamic_area_dropdown_to_checkout'), 20);
        
        add_action('woocommerce_admin_order_data_after_billing_address', array($this,'bosta_add_area_dropdown_admin_order'), 10, 1 );

        add_action('wp_footer', array($this,'bosta_enqueue_dynamic_area_dropdown_script'));
        add_action('admin_footer', array($this,'bosta_enqueue_dynamic_area_dropdown_script'));

        add_action('woocommerce_checkout_update_order_meta', array($this,'bosta_save_billing_area_to_order_metadata'), 10, 2);
        add_action('woocommerce_process_shop_order_meta', array($this,'bosta_save_billing_area_to_order_metadata'), 10, 2);
    }

    public function bosta_add_dynamic_area_dropdown_to_checkout($fields)
    {
        if (!Bosta_Helper::check_disable_bosta_zoning_checkbox()) {
            $field_priority = 50;
            if (isset($fields['billing']['billing_state']['priority'])) {
                $field_priority = $fields['billing']['billing_state']['priority'] + 1;
            }

            $fields['billing']['billing_area'] = array(
                'type'     => 'select',
                'label'    => __('Area', 'woocommerce'),
                'required' => false,
                'options'  => array(
                    '' => __('Select an option...', 'woocommerce'),
                ),
                'input_class' => array(
                    'wc-enhanced-select',
                ),
                'priority' => $field_priority,
            );

            wc_enqueue_js("
        jQuery(document).ready(function($) {
            $(':input.wc-enhanced-select').filter(':not(.enhanced)').each(function() {
                var select2_args = { minimumResultsForSearch: 5 };
                $(this).select2(select2_args).addClass('enhanced');
            });
    
            $('select.wc-enhanced-select').val('').trigger('change');
            $('#billing_state').val('').trigger('change');
        });	
        ");
        }

        return $fields;
    }

    public function bosta_add_area_dropdown_admin_order($order)
    {
        if (!Bosta_Helper::check_disable_bosta_zoning_checkbox()) {
    
    
            $current_state = get_post_meta($order->get_id(), '_billing_state', true);
            $current_area = get_post_meta($order->get_id(), '_billing_area', true);
    
            $bosta_city_areas = $this->api->get_city_areas();
            $areas_options = '<option value="">' . __('Select an area...', 'woocommerce') . '</option>';
    
            if (!empty($bosta_city_areas[$current_state])) {
                $areas = explode('</option>', $bosta_city_areas[$current_state]);
                foreach ($areas as $area_option) {
                    if (strpos($area_option, 'value="' . esc_attr($current_area) . '"') !== false) {
                        $area_option = str_replace('<option', '<option selected="selected"', $area_option);
                    }
                    $areas_options .= $area_option . '</option>';
                }
            }
    
            echo '<p class="form-field" style="width:100%">' .
                '<label for="billing_area">' . __('Area', 'woocommerce') . '</label>' .
                '<select name="billing_area" id="billing_area" class="wc-enhanced-select">' .
                $areas_options .
                '</select>' .
                '</p>';
        }
    }

    public function bosta_enqueue_dynamic_area_dropdown_script()
    {
        if (!Bosta_Helper::check_disable_bosta_zoning_checkbox()) {
            $bosta_city_areas = $this->api->get_city_areas();
            $city_areas_js = json_encode($bosta_city_areas);
    
            $is_valid_screen = false;
            $is_checkout = is_checkout();
            $is_admin = is_admin();
            if($is_checkout) {
                $is_valid_screen = true;
            }
            if($is_admin) {
                $current_screen = get_current_screen();
                if ($current_screen && isset($current_screen->post_type) && $current_screen->post_type === 'shop_order') {
                    $is_valid_screen = true;
                }
            }
            if (!$is_valid_screen) {
                return;
            }
    
        ?>
            <script type="text/javascript">
                jQuery(function($) {
                    function updateAreaDropdown(stateSelector, areaSelector, cityAreas) {
                        $(document).on('change', stateSelector, function() {
                            var selectedState = $(this).val();
                            var areaDropdown = $(areaSelector);
    
                            areaDropdown.empty();
                            areaDropdown.append($('<option></option>').attr('value', '').text('Select an option...'));
    
                            if (selectedState && cityAreas[selectedState]) {
                                var areas = cityAreas[selectedState];
                                areaDropdown.append(areas);
                            } else {
                                areaDropdown.append($('<option></option>').attr('value', '').text('No areas available'));
                            }
    
                            areaDropdown.trigger('change');
                        });
                    }
    
                    var cityAreasJs = <?php echo $city_areas_js; ?>;
    
                    <?php if ($is_checkout): ?>
                        updateAreaDropdown('#billing_state', '#billing_area', cityAreasJs);
                    <?php endif; ?>
    
                    <?php if ($is_admin): ?>
                        updateAreaDropdown('#_billing_state', '#billing_area', cityAreasJs);
                    <?php endif; ?>
                });
            </script>
        <?php
        }
    }

    public function bosta_save_billing_area_to_order_metadata($order_id, $posted_data)
    {
        if (Bosta_Helper::check_disable_bosta_zoning_checkbox()) {
            return;
        }
    
        if (isset($_POST['billing_area'])) {
            $billing_area = sanitize_text_field($_POST['billing_area']);
            $order = wc_get_order($order_id);
            $order->update_meta_data('_billing_area', $billing_area);
            $order->save();
        }
    }
    /**
     * إضافة حقول مخصصة في صفحة الدفع
     */
    // public function add_checkout_fields($fields) {
    //     $fields['shipping']['bosta_allow_open_package'] = array(
    //         'type'     => 'checkbox',
    //         'label'    => __('Allow to open package', 'bosta-wc'),
    //         'required' => false,
    //         'class'    => array('form-row-wide'),
    //         'priority' => 100,
    //     );
    //     return $fields;
    // }

    /**
     * حفظ البيانات المدخلة عند الدفع
     */
    // public function save_checkout_fields($order_id) {
    //     if (isset($_POST['bosta_allow_open_package'])) {
    //         update_post_meta($order_id, '_bosta_allow_open_package', 'yes');
    //     } else {
    //         update_post_meta($order_id, '_bosta_allow_open_package', 'no');
    //     }
    // }

    /**
     * إرسال الطلب إلى Bosta بعد إتمام الدفع
     */
    // public function send_order_to_bosta($order_id) {
    //     $order = wc_get_order($order_id);
    //     if (!$order) {
    //         return;
    //     }

    //     $order_data = Bosta_Helper::sanitize_order_data($order);

    //     // إضافة خاصية السماح بفتح الطرد
    //     $order_data['allow_open_package'] = get_post_meta($order_id, '_bosta_allow_open_package', true);

    //     $api = new Bosta_API();
    //     $response = $api->send_order_to_bosta($order_data);

    //     if (!empty($response['tracking_number'])) {
    //         update_post_meta($order_id, '_bosta_tracking_number', $response['tracking_number']);
    //         $order->update_status('processing', __('Sent to Bosta', 'bosta-wc'));
    //     } else {
    //         Bosta_Helper::log('Error sending order: ' . json_encode($response));
    //     }
    // }
}

new Bosta_Checkout();
