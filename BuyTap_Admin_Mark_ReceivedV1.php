<?php
/*
Plugin Name: BuyTap Admin - Mark Received Feature
Description: Adds admin functionality to mark orders as received on behalf of users
Version: 1.0
Author: Philip Osir BuyTap-Team  
*/

// Security check to prevent direct access
// Security check to prevent direct access
if (!defined('ABSPATH')) exit;

class BuyTapMarkReceived {

    public function __construct() {
        // Run only in WP Admin and AFTER CPTs are registered
        add_action('admin_init', array($this, 'init'), 20);
    }

    public function init() {
        // (Optional) bail out if CPT truly missing
        if ( ! post_type_exists('buytap_order') ) return;

        /* ✅ Use the post-type–specific hook so WP calls this
           ONLY when editing a buytap_order screen */
        add_action('add_meta_boxes_buytap_order', array($this, 'add_meta_boxes'));

        // Save only for this CPT
        add_action('save_post_buytap_order', array($this, 'handle_save_post'));

        add_filter('bulk_actions-edit-buytap_order', array($this, 'add_bulk_actions'));
        add_filter('handle_bulk_actions-edit-buytap_order', array($this, 'handle_bulk_actions'), 10, 3);

        add_action('admin_notices', array($this, 'show_admin_notices'));
        add_filter('post_row_actions', array($this, 'add_quick_actions'), 10, 2);
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    
    /**
     * Add meta boxes to order edit screen
     */
    public function add_meta_boxes() {
        add_meta_box(
            'buytap_admin_actions', 
            'Admin Actions', 
            array($this, 'render_admin_actions_meta_box'), 
            'buytap_order', 
            'side', 
            'high'
        );
        
        add_meta_box(
            'buytap_admin_notes', 
            'Admin Notes', 
            array($this, 'render_admin_notes_meta_box'), 
            'buytap_order', 
            'normal', 
            'low'
        );
    }
    
    /**
     * Render admin actions meta box
     */
    public function render_admin_actions_meta_box($post) {
        $status = get_post_meta($post->ID, 'status', true);
        $sub_status = get_post_meta($post->ID, 'sub_status', true);
        
        // Only show for matured orders waiting to be paired
        if (in_array($status, ['Matured']) && $sub_status === 'Waiting to be Paired') {
            $chunks = $this->get_seller_chunks($post->ID);
            
            if (!empty($chunks)) {
                echo '<div style="padding:10px; background:#f9f9f9; border:1px solid #ddd; border-radius:4px;">';
                echo '<h3>Mark Payments as Received</h3>';
                echo '<p>Mark individual buyer payments as received on behalf of seller.</p>';
                
                echo '<form method="post">';
                wp_nonce_field('buytap_admin_mark_received', 'buytap_admin_nonce');
                echo '<input type="hidden" name="order_id" value="' . $post->ID . '">';
                
                foreach ($chunks as $chunk) {
                    if ($chunk->status !== 'Received') {
                        $buyer_order = get_post($chunk->buyer_order_id);
                        $buyer_user = get_userdata($buyer_order->post_author);
                        $buyer_name = $buyer_user ? $buyer_user->display_name : 'Unknown Buyer';
                        $buyer_email = $buyer_user ? $buyer_user->user_email : '';
                        
                        echo '<div style="margin:10px 0; padding:8px; border:1px solid #ccc; border-radius:4px;">';
                        echo '<label style="display:block; margin-bottom:5px;">';
                        echo '<input type="checkbox" name="mark_received_chunks[]" value="' . $chunk->id . '"> ';
                        echo '<strong>Buyer:</strong> ' . esc_html($buyer_name) . ' (' . esc_html($buyer_email) . ')';
                        echo '<br><strong>Amount:</strong> Ksh ' . number_format($chunk->amount, 2);
                        echo '<br><strong>Status:</strong> ' . esc_html($chunk->status);
                        echo '</label>';
                        echo '</div>';
                    }
                }
                
                echo '<button type="submit" name="buytap_admin_mark_received" class="button button-primary" style="width:100%;">';
                echo 'Mark Selected as Received';
                echo '</button>';
                echo '</form>';
                echo '</div>';
            }
        } else {
            echo '<p>No admin actions available for this order status.</p>';
        }
    }
    
    /**
     * Render admin notes meta box
     */
    public function render_admin_notes_meta_box($post) {
        $admin_notes = get_post_meta($post->ID, 'admin_notes', true);
        
        if (!empty($admin_notes) && is_array($admin_notes)) {
            echo '<div style="max-height:200px; overflow-y:auto;">';
            foreach ($admin_notes as $note) {
                echo '<div style="padding:8px; margin-bottom:8px; border-left:3px solid #0073aa; background:#f9f9f9;">';
                echo '<strong>' . date('Y-m-d H:i', strtotime($note['date'])) . '</strong> - ';
                echo 'Admin #' . $note['admin'] . ': ' . esc_html($note['action']);
                if (!empty($note['details'])) {
                    echo '<br><em>' . esc_html($note['details']) . '</em>';
                }
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<p>No admin notes recorded.</p>';
        }
    }
    
    /**
     * Handle form submissions
     */
    public function handle_save_post($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (get_post_type($post_id) !== 'buytap_order') return;
        if (!current_user_can('manage_options')) return;
        
        if (isset($_POST['buytap_admin_mark_received']) && 
            isset($_POST['buytap_admin_nonce']) && 
            wp_verify_nonce($_POST['buytap_admin_nonce'], 'buytap_admin_mark_received')) {
            
            $chunk_ids = isset($_POST['mark_received_chunks']) ? array_map('intval', $_POST['mark_received_chunks']) : [];
            
            if (!empty($chunk_ids)) {
                $this->mark_chunks_as_received($post_id, $chunk_ids);
            }
        }
    }
    
    /**
     * Mark chunks as received
     */
    public function mark_chunks_as_received($seller_order_id, $chunk_ids) {
        global $wpdb;
        
        $marked_count = 0;
        $chunk_details = [];
        $table_name = $wpdb->prefix . 'buytap_chunks';
        
        foreach ($chunk_ids as $chunk_id) {
            $chunk = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d AND seller_order_id = %d",
                $chunk_id, $seller_order_id
            ));
            
            if ($chunk && $chunk->status !== 'Received') {
                // Mark chunk as received
                $wpdb->update(
                    $table_name,
                    ['status' => 'Received'],
                    ['id' => $chunk_id]
                );
                
                $buyer_order = get_post($chunk->buyer_order_id);
                $buyer_user = get_userdata($buyer_order->post_author);
                $buyer_name = $buyer_user ? $buyer_user->display_name : 'Unknown Buyer';
                
                $chunk_details[] = [
                    'buyer' => $buyer_name,
                    'amount' => $chunk->amount,
                    'chunk_id' => $chunk_id
                ];
                
                $marked_count++;
                
                $this->log_action('Admin marked chunk as received', [
                    'chunk_id' => $chunk_id,
                    'seller_order_id' => $seller_order_id,
                    'buyer_order_id' => $chunk->buyer_order_id,
                    'admin_id' => get_current_user_id()
                ]);
            }
        }
        
        if ($marked_count > 0) {
            // Update seller's remaining balance
            $seller_remaining = $this->get_seller_remaining($seller_order_id);
            update_post_meta($seller_order_id, 'remaining_to_receive', $seller_remaining);
            
            // Check if seller is now fully funded
            if ($seller_remaining <= 0) {
                update_post_meta($seller_order_id, 'status', 'Closed');
                update_post_meta($seller_order_id, 'sub_status', 'Completed by Admin');
            }
            
            // Add admin note
            $admin_note = get_post_meta($seller_order_id, 'admin_notes', true) ?: [];
            $admin_note[] = [
                'date' => current_time('mysql'),
                'admin' => get_current_user_id(),
                'action' => 'Marked ' . $marked_count . ' payment(s) as received',
                'details' => 'Marked for buyers: ' . implode(', ', array_map(function($detail) {
                    return $detail['buyer'] . ' (Ksh ' . number_format($detail['amount'], 2) . ')';
                }, $chunk_details))
            ];
            update_post_meta($seller_order_id, 'admin_notes', $admin_note);
            
            // Store success message in transient
            set_transient('buytap_admin_message_' . get_current_user_id(), [
                'type' => 'success',
                'message' => 'Successfully marked ' . $marked_count . ' payment(s) as received for Order #' . $seller_order_id . '.'
            ], 30);
            
            // Try to activate buyers if their payments are now complete
            foreach ($chunk_ids as $chunk_id) {
                $chunk = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table_name WHERE id = %d",
                    $chunk_id
                ));
                
                if ($chunk) {
                    $this->activate_buyer_if_complete($chunk->buyer_order_id);
                }
            }
        }
    }
    
    /**
     * Add bulk actions to orders list
     */
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['mark_received_admin'] = 'Mark as Received (Admin)';
        return $bulk_actions;
    }
    
    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions($redirect_url, $action, $post_ids) {
        if ($action === 'mark_received_admin') {
            // Store the selected order IDs in a transient for the next page
            set_transient('buytap_bulk_mark_orders', $post_ids, 300); // 5 minutes
            $redirect_url = add_query_arg('bulk_mark_received', '1', admin_url('admin.php?page=buytap_bulk_mark_received'));
        }
        return $redirect_url;
    }
    
    /**
     * Show admin notices
     */
    public function show_admin_notices() {
        $message = get_transient('buytap_admin_message_' . get_current_user_id());
        
        if ($message) {
            echo '<div class="notice notice-' . $message['type'] . ' is-dismissible">';
            echo '<p>' . $message['message'] . '</p>';
            echo '</div>';
            delete_transient('buytap_admin_message_' . get_current_user_id());
        }
        
        if (!empty($_REQUEST['bulk_marked_received'])) {
            $count = intval($_REQUEST['bulk_marked_received']);
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . sprintf(_n('%s order marked as received by admin.', '%s orders marked as received by admin.', $count), $count) . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Add quick actions to orders list
     */
    public function add_quick_actions($actions, $post) {
        if ($post->post_type === 'buytap_order') {
            $status = get_post_meta($post->ID, 'status', true);
            $sub_status = get_post_meta($post->ID, 'sub_status', true);
            
            if (in_array($status, ['Matured']) && $sub_status === 'Waiting to be Paired' && current_user_can('manage_options')) {
                $actions['mark_received'] = sprintf(
                    '<a href="%s">Mark Received</a>',
                    admin_url(sprintf('post.php?post=%d&action=edit#buytap_admin_actions', $post->ID))
                );
            }
        }
        return $actions;
    }
    
    /**
     * Add admin menu for bulk operations
     */
    public function add_admin_menu() {
        add_submenu_page(
            null, // No parent menu
            'Bulk Mark Received',
            'Bulk Mark Received',
            'manage_options',
            'buytap_bulk_mark_received',
            array($this, 'render_bulk_mark_received_page')
        );
    }
    
    /**
     * Render bulk mark received page
     */
    public function render_bulk_mark_received_page() {
        if (!current_user_can('manage_options')) wp_die('Not allowed');
        
        $order_ids = get_transient('buytap_bulk_mark_orders');
        if (!$order_ids) {
            echo '<div class="wrap"><h1>Bulk Mark Received</h1>';
            echo '<p>No orders selected or session expired.</p>';
            echo '<p><a href="' . admin_url('edit.php?post_type=buytap_order') . '">Return to Orders</a></p>';
            echo '</div>';
            return;
        }
        
        echo '<div class="wrap"><h1>Bulk Mark Payments as Received</h1>';
        
        if (isset($_POST['buytap_bulk_mark_received'])) {
            // Process the bulk marking
            $selected_chunks = [];
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'chunk_') === 0) {
                    $parts = explode('_', $key);
                    if (count($parts) === 3) {
                        $order_id = intval($parts[1]);
                        $chunk_id = intval($parts[2]);
                        $selected_chunks[$order_id][] = $chunk_id;
                    }
                }
            }
            
            $total_marked = 0;
            foreach ($selected_chunks as $order_id => $chunk_ids) {
                $this->mark_chunks_as_received($order_id, $chunk_ids);
                $total_marked += count($chunk_ids);
            }
            
            echo '<div class="notice notice-success"><p>Marked ' . $total_marked . ' payments as received.</p></div>';
            echo '<p><a href="' . admin_url('edit.php?post_type=buytap_order') . '">Return to Orders</a></p>';
            
        } else {
            // Show the selection form
            echo '<form method="post">';
            wp_nonce_field('buytap_bulk_mark_received', 'buytap_bulk_nonce');
            
            foreach ($order_ids as $order_id) {
                $order = get_post($order_id);
                if ($order && get_post_meta($order_id, 'status', true) === 'Matured') {
                    $chunks = $this->get_seller_chunks($order_id);
                    
                    if (!empty($chunks)) {
                        echo '<h3>Order #' . $order_id . '</h3>';
                        
                        foreach ($chunks as $chunk) {
                            if ($chunk->status !== 'Received') {
                                $buyer_order = get_post($chunk->buyer_order_id);
                                $buyer_user = get_userdata($buyer_order->post_author);
                                $buyer_name = $buyer_user ? $buyer_user->display_name : 'Unknown Buyer';
                                
                                echo '<label style="display:block; margin:5px 0; padding:5px; border:1px solid #ddd;">';
                                echo '<input type="checkbox" name="chunk_' . $order_id . '_' . $chunk->id . '" value="1"> ';
                                echo '<strong>Buyer:</strong> ' . esc_html($buyer_name);
                                echo ' | <strong>Amount:</strong> Ksh ' . number_format($chunk->amount, 2);
                                echo ' | <strong>Status:</strong> ' . esc_html($chunk->status);
                                echo '</label>';
                            }
                        }
                    }
                }
            }
            
            echo '<p><button type="submit" name="buytap_bulk_mark_received" class="button button-primary">Mark Selected Payments as Received</button></p>';
            echo '</form>';
        }
        
        echo '</div>';
    }
    
    /**
     * Helper function to get seller chunks
     */
    private function get_seller_chunks($seller_order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'buytap_chunks';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE seller_order_id = %d ORDER BY id ASC",
            $seller_order_id
        ));
    }
    
    /**
     * Helper function to get seller remaining balance
     */
    private function get_seller_remaining($seller_order_id) {
        // Prefer the atomic counter if present
        $meta = get_post_meta($seller_order_id, 'seller_remaining', true);
        if ($meta !== '' && $meta !== null) {
            return max(0, (float) $meta);
        }

        // Legacy fallback (for old data): target - allocated
        $target = (float) get_post_meta($seller_order_id, 'expected_amount', true);
        if ($target <= 0) {
            $target = (float) get_post_meta($seller_order_id, 'amount_to_make', true);
        }
        
        $allocated = 0;
        $chunks = $this->get_seller_chunks($seller_order_id);
        foreach ($chunks as $chunk) {
            if (in_array($chunk->status, ['Awaiting Payment', 'Payment Made', 'Received'])) {
                $allocated += (float) $chunk->amount;
            }
        }
        
        return max(0, $target - $allocated);
    }
    
    /**
     * Helper function to activate buyer if complete
     */
    private function activate_buyer_if_complete($buyer_order_id) {
        // This would need to be implemented based on your main plugin's logic
        // For now, we'll just leave it as a placeholder
    }
    
    /**
     * Helper function to log actions
     */
    private function log_action($message, $context = []) {
        if (!function_exists('buytap_log')) {
            error_log('[BuyTap] ' . $message . ' ' . json_encode($context));
            return;
        }
        
        buytap_log($message, $context);
    }
}

// Initialize the plugin
new BuyTapMarkReceived();
