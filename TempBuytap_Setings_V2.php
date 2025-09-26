<?php
/**
 * Plugin Name: BuyTap Settings
 * Description: Adds a settings page to configure BuyTap purchase limits (min/max).
 * Version: 1.0
 * Author: Philip Osir
 */

if (!defined('ABSPATH')) exit;

// 1. Add submenu under "Settings"
add_action('admin_menu', function () {
    add_options_page(
        'BuyTap Settings',   // Page title
        'BuyTap Settings',   // Menu title
        'manage_options',    // Capability
        'buytap-settings',   // Slug
        'buytap_settings_page' // Callback
    );
});

// 2. Register settings
add_action('admin_init', function () {
    // Existing
    register_setting('buytap_settings', 'buytap_min_purchase', [
        'default' => 500,
        'type' => 'integer',
        'sanitize_callback' => 'absint'
    ]);
    register_setting('buytap_settings', 'buytap_max_purchase', [
        'default' => 5000,
        'type' => 'integer',
        'sanitize_callback' => 'absint'
    ]);

    // 🔔 New: Countdown settings
    register_setting('buytap_settings', 'buytap_mode', [
        'default' => 'test',
        'type' => 'string',
        'sanitize_callback' => function($val){
            return in_array($val, ['test','schedule'], true) ? $val : 'test';
        }
    ]);

    register_setting('buytap_settings', 'buytap_test_wait_seconds', [
        'default' => 60,
        'type' => 'integer',
        'sanitize_callback' => 'absint'
    ]);

    register_setting('buytap_settings', 'buytap_show_for_seconds', [
        'default' => 3600,
        'type' => 'integer',
        'sanitize_callback' => 'absint'
    ]);

    register_setting('buytap_settings', 'buytap_open_times', [
        'default' => '09:00,19:00',
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field'
    ]);

    register_setting('buytap_settings', 'buytap_timezone', [
        'default' => 'Africa/Nairobi',
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field'
    ]);

    // 🗓️ Optional: Specific open dates (one per line: "YYYY-MM-DD HH:MM" or "YYYY-MM-DD")
    register_setting('buytap_settings', 'buytap_open_dates', [
        'default' => '',
        'type' => 'string',
        'sanitize_callback' => 'sanitize_textarea_field'
    ]);
	    // 📌 New: Active Plans (one per line: "days:profit")
    register_setting('buytap_settings', 'buytap_active_plans', [
        'default' => "4:30\n8:65\n12:95",
        'type' => 'string',
        'sanitize_callback' => 'sanitize_textarea_field'
    ]);
	
	    // 🆕 Force Close toggle
    register_setting('buytap_settings', 'buytap_force_close', [
        'default' => 0,
        'type' => 'boolean',
        'sanitize_callback' => function($val){ return $val ? 1 : 0; }
    ]);

});
// == CLOSE-TIME SYNC & FORCE-CLOSE endpoints ==
add_action('rest_api_init', function () {

  // Normal "now" endpoint
  register_rest_route('buytap/v1', '/now', [
    'methods'  => 'GET',
    'callback' => function () {
      nocache_headers();
      return new WP_REST_Response([
        'utc'     => (int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp(),
        'nairobi' => (int) (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->getTimestamp(),
      ], 200);
    },
    'permission_callback' => '__return_true',
  ]);

  // 🆕 Force Close endpoint
  register_rest_route('buytap/v1', '/status', [
    'methods'  => 'GET',
    'callback' => function () {
      $force = (bool) get_option('buytap_force_close', 0);

      // Auto-reset: if it was set, clear it immediately
      if ($force) {
        update_option('buytap_force_close', 0);
      }

      return new WP_REST_Response([
        'force_close' => $force
      ], 200);
    },
    'permission_callback' => '__return_true',
  ]);

});




// 3. Render the settings page
function buytap_settings_page() {
    ?>
    <div class="wrap">
        <h1>BuyTap Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('buytap_settings');
            do_settings_sections('buytap_settings');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="buytap_min_purchase">Minimum Purchase</label></th>
                    <td>
                        <input type="number" min="1" name="buytap_min_purchase" id="buytap_min_purchase"
                               value="<?php echo esc_attr(get_option('buytap_min_purchase', 500)); ?>">
                        <p class="description">Set the minimum purchase limit (default: 500).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="buytap_max_purchase">Maximum Purchase</label></th>
                    <td>
                        <input type="number" min="1" name="buytap_max_purchase" id="buytap_max_purchase"
                               value="<?php echo esc_attr(get_option('buytap_max_purchase', 5000)); ?>">
                        <p class="description">Set the maximum purchase limit (default: 5000).</p>
                    </td>
                </tr>
				<!-- 👉 ADD NEW FIELDS HERE 👇 -->
                <tr>
                    <th scope="row"><label for="buytap_mode">Countdown Mode</label></th>
                    <td>
                        <select name="buytap_mode" id="buytap_mode">
                            <option value="test" <?php selected(get_option('buytap_mode', 'test'), 'test'); ?>>Test</option>
							<option value="schedule" <?php selected(get_option('buytap_mode', 'test'), 'schedule'); ?>>Schedule</option>

                        </select>
                        <p class="description">Test = opens after X seconds. Schedule = open at specified times/dates.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="buytap_test_wait_seconds">Test Wait (seconds)</label></th>
                    <td>
                        <input type="number" min="1" name="buytap_test_wait_seconds" id="buytap_test_wait_seconds"
                               value="<?php echo esc_attr(get_option('buytap_test_wait_seconds', 60)); ?>">
                        <p class="description">Only used in Test mode. Default: 60.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="buytap_show_for_seconds">Show Form For (seconds)</label></th>
                    <td>
                        <input type="number" min="1" name="buytap_show_for_seconds" id="buytap_show_for_seconds"
                               value="<?php echo esc_attr(get_option('buytap_show_for_seconds', 3600)); ?>">
                        <p class="description">How long the form stays visible after opening (default: 3600 = 1 hour).</p>
                    </td>
                </tr>
				<tr>
					<th scope="row"><label for="buytap_force_close">Force Close Current Window</label></th>
					<td>
						<input type="checkbox" name="buytap_force_close" id="buytap_force_close" value="1"
							   <?php checked(1, get_option('buytap_force_close', 0)); ?>>
						<p class="description">
							Tick this box to immediately close the active form window.<br>
							⚠️ It will reset automatically once the window closes.
						</p>
					</td>
				</tr>
                <tr>
                    <th scope="row"><label for="buytap_open_times">Open Times (daily, HH:MM)</label></th>
                    <td>
                        <input type="text" name="buytap_open_times" id="buytap_open_times" size="40"
                               value="<?php echo esc_attr(get_option('buytap_open_times', '09:00,19:00')); ?>">
                        <p class="description">Comma-separated daily times (24h). Example: <code>09:00,19:00</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="buytap_open_dates">Open Dates (optional)</label></th>
                    <td>
                        <textarea name="buytap_open_dates" id="buytap_open_dates" rows="5" cols="60"><?php
                            echo esc_textarea(get_option('buytap_open_dates',''));
                        ?></textarea>
                        <p class="description">
                            One per line. Use <code>YYYY-MM-DD HH:MM</code> or just <code>YYYY-MM-DD</code>.
                            If time is omitted, the first daily time above is used.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="buytap_timezone">Timezone</label></th>
                    <td>
                        <input type="text" name="buytap_timezone" id="buytap_timezone"
                               value="<?php echo esc_attr(get_option('buytap_timezone','Africa/Nairobi')); ?>">
                        <p class="description">IANA timezone (e.g., <code>Africa/Nairobi</code>).</p>
                    </td>
                </tr>
                <!-- 👉 END NEW FIELDS -->
				<tr>
					<th scope="row"><label for="buytap_active_plans">Active Plans</label></th>
					<td>
						<textarea name="buytap_active_plans" id="buytap_active_plans" rows="5" cols="40"><?php
							echo esc_textarea(get_option('buytap_active_plans', "4:30\n8:65\n12:95"));
						?></textarea>
						<p class="description">
							Define one plan per line in the format <code>days:profit</code>.<br>
							Example:<br>
							<code>4:30</code> → 30% in 4 days<br>
							<code>8:65</code> → 65% in 8 days<br>
							<code>12:95</code> → 95% in 12 days
						</p>
					</td>
				</tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
// ✅ Show & edit UM fields in WP User Profile
add_action('show_user_profile', 'buytap_show_um_fields');
add_action('edit_user_profile', 'buytap_show_um_fields');

function buytap_show_um_fields($user) {
    ?>
    <h3>Ultimate Member – Custom Fields</h3>
    <table class="form-table">
        <!-- Phone Number -->
        <tr>
            <th><label for="mobile_number">Phone Number</label></th>
            <td>
                <input type="text" name="mobile_number" id="mobile_number"
                       value="<?php echo esc_attr(get_user_meta($user->ID, 'mobile_number', true)); ?>"
                       class="regular-text" /><br>
                <span class="description">This updates the Ultimate Member phone number (meta key: mobile_number).</span>
            </td>
        </tr>

        <!-- Username (sync with user_login) -->
        <tr>
            <th><label for="um_username">Username</label></th>
            <td>
                <input type="text" name="um_username" id="um_username"
                       value="<?php echo esc_attr(get_user_meta($user->ID, 'username', true)); ?>"
                       class="regular-text" /><br>
                <span class="description">
                    This updates the UM "username" field AND WordPress user_login (used for login).
                </span>
            </td>
        </tr>
    </table>
    <?php
}

// ✅ Save fields when admin updates profile
add_action('personal_options_update', 'buytap_save_um_fields');
add_action('edit_user_profile_update', 'buytap_save_um_fields');

function buytap_save_um_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) return false;

    // Save phone number
    if (isset($_POST['mobile_number'])) {
        update_user_meta($user_id, 'mobile_number', sanitize_text_field($_POST['mobile_number']));
    }

    // Save username (update both UM meta and WP core user_login)
    if (isset($_POST['um_username'])) {
        $new_username = sanitize_user($_POST['um_username'], true);

        // Update UM custom field (meta key 'username')
        update_user_meta($user_id, 'username', $new_username);

        // Update WordPress core login field (user_login) directly in wp_users
        global $wpdb;
        $wpdb->update(
            $wpdb->users,
            array('user_login' => $new_username),
            array('ID' => $user_id)
        );

        // Clear cached data so login works with new username
        clean_user_cache($user_id);
    }
}
