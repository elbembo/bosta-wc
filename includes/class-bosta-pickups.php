<?php
if (!defined('ABSPATH')) {
    exit;
}
require_once 'class-bosta-api.php';
class Bosta_Pickups {
    private $api;

    public function __construct() {
        $this->api = new Bosta_API();
        add_action('admin_menu', array($this, 'add_pickup_page'));
        add_action('admin_post_bosta_create_pickup', array($this, 'handle_pickup_request'));
        add_action('admin_menu', array($this, 'add_pickup_requests_page'));
    }

    public function add_pickup_page() {
        add_submenu_page(
            'bosta-dashboard', 
            __('Create Pickup', 'bosta-wc'), 
            __('Create Pickup', 'bosta-wc'), 
            'manage_options', 
            'bosta-create-pickup', 
            array($this, 'pickup_page_content')
        );
    }

    public function add_pickup_requests_page() {
        add_submenu_page(
            'bosta-dashboard', 
            __('Pickup Requests', 'bosta-wc'), 
            __('Pickup Requests', 'bosta-wc'), 
            'manage_options', 
            'bosta-pickup-requests', 
            array($this, 'pickup_requests_page_content')
        );
    }

    public function pickup_page_content() {
        ?>
        <div class="wrap">
            <h1><?php _e('Create Pickup Request', 'bosta-wc'); ?></h1>
            <?php if (isset($_GET['success'])): ?>
                <div class="updated notice is-dismissible">
                    <p><?php _e('Pickup request created successfully!', 'bosta-wc'); ?></p>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="bosta_create_pickup">
                <?php wp_nonce_field('bosta_create_pickup_action', 'bosta_create_pickup_nonce'); ?>
                
                <label><?php _e('Scheduled Date', 'bosta-wc'); ?></label>
                <input type="date" name="pickup_date" required>
                
                <label><?php _e('Pickup Address', 'bosta-wc'); ?></label>
                <input type="text" name="pickup_address" required>
                
                <input type="submit" value="<?php _e('Request Pickup', 'bosta-wc'); ?>" class="button-primary">
            </form>
        </div>
        <?php
    }

    public function pickup_requests_page_content() {
        ?>
        <div class="wrap">
            <h1><?php _e('Pickup Requests', 'bosta-wc'); ?></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Pickup ID', 'bosta-wc'); ?></th>
                        <th><?php _e('Scheduled Date', 'bosta-wc'); ?></th>
                        <th><?php _e('Status', 'bosta-wc'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $requests = $this->api->get_pickup_requests();
                    if (!empty($requests)) {
                        foreach ($requests as $request) {
                            echo '<tr>';
                            echo '<td>' . esc_html($request['id']) . '</td>';
                            echo '<td>' . esc_html($request['scheduledDate']) . '</td>';
                            echo '<td>' . esc_html($request['status']) . '</td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="3">' . __('No pickup requests found.', 'bosta-wc') . '</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handle_pickup_request() {
        if (!isset($_POST['bosta_create_pickup_nonce']) || !wp_verify_nonce($_POST['bosta_create_pickup_nonce'], 'bosta_create_pickup_action')) {
            wp_die(__('Security check failed.', 'bosta-wc'));
        }

        $pickup_data = array(
            'scheduledDate' => sanitize_text_field($_POST['pickup_date']),
            'address' => sanitize_text_field($_POST['pickup_address'])
        );

        $response = $this->api->request_pickup($pickup_data);

        if (!empty($response['error'])) {
            wp_die(__('Pickup request failed: ', 'bosta-wc') . $response['error']);
        }

        wp_redirect(admin_url('admin.php?page=bosta-create-pickup&success=1'));
        exit;
    }
}

new Bosta_Pickups();