<?php
/*
Plugin Name: BuyTap Wallet Creditor
Description: Automatically credits matured unpaid BuyTap orders into each seller’s wallet balance.
Version: 1.0.0
Author: BuyTap Team
*/

if (!defined('ABSPATH')) exit;


function buytap_log_credit_action($message) {
    $dir = WP_CONTENT_DIR . '/uploads/buytap-logs';
    if (!file_exists($dir)) wp_mkdir_p($dir);
    $file = $dir . '/creditor-' . date('Y-m-d') . '.log';
    $timestamp = '[' . current_time('mysql') . '] ';
    file_put_contents($file, $timestamp . $message . "\n", FILE_APPEND);
}

/**
 * Helper: get total amount already received for a seller order
 */
if (!function_exists('buytap_seller_received_sum')) {
    function buytap_seller_received_sum($seller_order_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'buytap_chunks';
        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0)
             FROM $t 
             WHERE seller_order_id = %d 
             AND status = 'Received'",
             $seller_order_id
        ));
    }
}


/**
 * Main function:
 * Finds all matured unpaid orders and credits their expected amount to seller wallet.
 */
function buytap_credit_unpaid_matured_orders() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized.');
    }
    if ( get_option('buytap_creditor_lock') ) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning"><p>Another credit process is already running. Please wait.</p></div>';
        });
        return;
    }
    update_option('buytap_creditor_lock', true);

    $args = [
        'post_type'      => 'buytap_order',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'   => 'status',
                'value' => 'Matured',
            ],
        ],
    ];

    $orders = get_posts($args);
    $credited_count = 0;

    foreach ($orders as $order_id) {
        $status = get_post_meta($order_id, 'status', true);

        // Skip closed or already credited
        if (in_array($status, ['Closed', 'Credited'], true)) continue;

        $seller_id = (int) get_post_field('post_author', $order_id);
        if ($seller_id <= 0) continue;

        $expected = (float) get_post_meta($order_id, 'expected_amount', true);
		$received = (float) buytap_seller_received_sum($order_id);
		$amount = max(0, $expected - $received);

		// Skip if nothing left unpaid
		if ($amount <= 0) continue;


        // Credit wallet balance
        $current_balance = (float) get_user_meta($seller_id, 'buytap_wallet_balance', true);
        $new_balance = $current_balance + $amount;
        update_user_meta($seller_id, 'buytap_wallet_balance', $new_balance);

        // Update order
        update_post_meta($order_id, 'status', 'Credited');
        update_post_meta($order_id, 'credited_on', current_time('mysql'));
        update_post_meta($order_id, 'credit_amount', $amount);

        buytap_log_credit_action("Credited remaining KSh {$amount} for order #{$order_id} (Expected {$expected}, Received {$received}) → User {$seller_id}");

        $credited_count++;
    }

    add_action('admin_notices', function() use ($credited_count) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>BuyTap Wallet Creditor:</strong> ' 
             . esc_html($credited_count) . ' matured orders credited to seller wallets.</p></div>';
    });
    delete_option('buytap_creditor_lock');
}

/**
 * Add admin menu button under Tools → Credit Matured Orders
 */
add_action('admin_menu', function () {
    add_management_page(
        'Credit Matured Orders',
        'Credit BuyTap Orders',
        'manage_options',
        'buytap-creditor',
        'buytap_wallet_creditor_admin_page'
    );
});

/**
 * Credit matured orders for a specific user
 */
function buytap_credit_user_matured_orders( $user_id ) {
    if ( ! $user_id ) return 0;

    $args = [
        'post_type'      => 'buytap_order',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'author'         => $user_id,
        'meta_query'     => [
            [
                'key'   => 'status',
                'value' => 'Matured',
            ],
        ],
    ];

    $orders = get_posts($args);
    $credited = 0;

    foreach ($orders as $order_id) {
        $status = get_post_meta($order_id, 'status', true);
        if (in_array($status, ['Closed', 'Credited'], true)) continue;

        $expected = (float) get_post_meta($order_id, 'expected_amount', true);
		$received = (float) buytap_seller_received_sum($order_id);
		$amount = max(0, $expected - $received);

		// Skip if nothing left unpaid
		if ($amount <= 0) continue;


        $balance = (float) get_user_meta($user_id, 'buytap_wallet_balance', true);
        update_user_meta($user_id, 'buytap_wallet_balance', $balance + $amount);

        update_post_meta($order_id, 'status', 'Credited');
        update_post_meta($order_id, 'credited_on', current_time('mysql'));
        update_post_meta($order_id, 'credit_amount', $amount);
        
        buytap_log_credit_action("Credited order #{$order_id} to user {$user_id} amount {$amount}");
        
        $credited++;
    }

    return $credited;
}

/**
 * Admin page UI
 */
function buytap_wallet_creditor_admin_page() {
    echo '<div class="wrap"><h1>BuyTap Wallet Creditor</h1>';
    echo '<p>Enter a user ID to credit matured orders or run for all users.</p>';

    // Handle run for selected user
    if ( isset($_POST['run_single']) && check_admin_referer('buytap_wallet_creditor') ) {
        $uid = intval($_POST['user_id']);

        if ( $uid <= 0 ) {
            echo '<div class="notice notice-error"><p><strong>Please enter a valid numeric User ID.</strong></p></div>';
        } elseif ( ! get_userdata($uid) ) {
            echo '<div class="notice notice-error"><p><strong>User ID ' . esc_html($uid) . ' not found.</strong></p></div>';
        } else {
            $count = buytap_credit_user_matured_orders($uid);
            if ( $count > 0 ) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>'
                     . esc_html($count) . ' matured orders credited for user ID ' . esc_html($uid) . '.</strong></p></div>';
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p><strong>'
                     . 'No matured orders found for user ID ' . esc_html($uid) . '.</strong></p></div>';
            }
        }
    }

    // Handle run for all users
    if ( isset($_POST['run_all']) && check_admin_referer('buytap_wallet_creditor') ) {
        buytap_credit_unpaid_matured_orders();
    }

    // ========== FORM UI ==========
    echo '<form method="post" style="margin-top:20px;">';
    wp_nonce_field('buytap_wallet_creditor');

    echo '<label for="user_id"><strong>Enter User ID:</strong></label><br>';
    echo '<input type="number" name="user_id" id="user_id" min="1" step="1" placeholder="e.g. 42" style="width:220px; margin-top:6px; margin-bottom:8px;"> ';
    submit_button('Run for This User', 'primary', 'run_single', false);
    echo ' ';
    submit_button('Run for All Users', 'secondary', 'run_all', false);

    echo '<p style="color:#666;font-size:13px;margin-top:8px;">Tip: You can find User IDs in <strong>Users → All Users</strong> (hover over the username).</p>';

    echo '</form></div>';
}

/**
 * Shortcode: [buytap_wallet]
 * Displays the logged-in user's wallet balance in KSh
 */
add_shortcode('buytap_wallet', function () {
    if (!is_user_logged_in()) {
        return '<p>Please log in to view your wallet balance.</p>';
    }

    $user_id = get_current_user_id();
    $balance = (float) get_user_meta($user_id, 'buytap_wallet_balance', true);

    ob_start(); ?>
    <div class="buytap-wallet-box" style="
        background: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 16px;
        text-align: center;
        max-width: 320px;
        margin: 10px auto;
        box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    ">
        <h3 style="margin:0 0 8px 0; color:#4b0082;">💰 Tap Vault </h3>
        <p style="font-size:1.4em; color:#222; font-weight:600;">
            KSh <?= number_format($balance, 2) ?>
        </p>
    </div>
    <?php
    return ob_get_clean();
});
