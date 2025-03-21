<?php
if (!defined('ABSPATH')) {
    exit;
}

class Bosta_Settings {
    public function __construct() {
        add_action('admin_menu', array($this, 'register_settings_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function register_settings_menu() {
        add_submenu_page(
            'bosta-dashboard', 
            __('Settings', 'bosta-wc'), 
            __('Settings', 'bosta-wc'), 
            'manage_options', 
            'bosta-settings', 
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting('woocommerce_bosta_settings_group', 'woocommerce_bosta_settings');
        add_settings_section('bosta_api_settings', __('API Settings', 'bosta-wc'), null, 'bosta-settings');

        add_settings_field('APIKey', __('API Key', 'bosta-wc'), array($this, 'api_key_field'), 'bosta-settings', 'bosta_api_settings');
        add_settings_field('DisableBostaZoning', __('Disable Bosta Zoning', 'bosta-wc'), array($this, 'checkbox_field'), 'bosta-settings', 'bosta_api_settings', ['name' => 'DisableBostaZoning']);
        add_settings_field('ProductDescription', __('Product Description', 'bosta-wc'), array($this, 'checkbox_field'), 'bosta-settings', 'bosta_api_settings', ['name' => 'ProductDescription']);
        add_settings_field('AllowToOpenPackage', __('Allow to Open Package', 'bosta-wc'), array($this, 'checkbox_field'), 'bosta-settings', 'bosta_api_settings', ['name' => 'AllowToOpenPackage']);
        add_settings_field('OrderRef', __('Order Reference', 'bosta-wc'), array($this, 'checkbox_field'), 'bosta-settings', 'bosta_api_settings', ['name' => 'OrderRef']);
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Bosta Settings', 'bosta-wc'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('woocommerce_bosta_settings_group');
                do_settings_sections('bosta-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function api_key_field() {
        $options = get_option('woocommerce_bosta_settings');
        ?>
        <input type="text" name="woocommerce_bosta_settings[APIKey]" value="<?php echo esc_attr($options['APIKey'] ?? ''); ?>" class="regular-text">
        <?php
    }

    public function checkbox_field($args) {
        $options = get_option('woocommerce_bosta_settings');
        $checked = isset($options[$args['name']]) && $options[$args['name']] === 'yes' ? 'checked' : '';
        ?>
        <input type="checkbox" name="woocommerce_bosta_settings[<?php echo esc_attr($args['name']); ?>]" value="yes" <?php echo $checked; ?>>
        <?php
    }
}
new Bosta_Settings();
