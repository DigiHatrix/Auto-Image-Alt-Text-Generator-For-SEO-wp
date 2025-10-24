<?php
/**
 * Auto Alt Text Generator For SEO
 *
 * @package     AAT
 * @author      Hatrix Solutions
 * @copyright   2024 Hatrix Solutions
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Auto Image Alt Text Generator For SEO
 * Plugin URI:  https://hatrixsolutions.com/auto-alt-text-generator-for-seo
 * Description: Automatically generate and apply alt tags to images using AI.
 * Version:     1.1.0
 * Author:      Hatrix Solutions
 * Text Domain: hs-auto-image-alt-text-generator-for-seo
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AAT_VERSION', '1.1.0');
define('AAT_PLUGIN_FILE', __FILE__);
define('AAT_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('AAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AAT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Initialize plugin
add_action('plugins_loaded', 'aat_init');

/**
 * Initialize the plugin
 *
 * @since 0.1.0
 * @return void
 */
function aat_init() {
    // Hook into WordPress
    add_action('admin_menu', 'aat_add_menu');
    add_action('admin_enqueue_scripts', 'aat_enqueue_admin_styles');
    
    // Weekly heartbeat to keep site registration updated
    add_action('admin_init', 'aat_maybe_ping_server');
    
    // Admin notices
    add_action('admin_notices', 'aat_admin_notices');
    add_action('admin_init', 'aat_handle_notice_dismissal');
    
    // AJAX handler for notice dismissal (via X button)
    add_action('wp_ajax_aat_dismiss_notice', 'aat_ajax_dismiss_notice');
}

register_activation_hook(__FILE__, 'aat_generate_site_id');
register_deactivation_hook(__FILE__, 'aat_track_deactivation');
// Note: Uninstall handled by uninstall.php

/**
 * Generate unique site ID on plugin activation and register with central server
 *
 * @since 0.1.0
 * @return void
 */
function aat_generate_site_id() {
    // Check if site_id exists in WordPress options
    $site_id = get_option('aat_site_id');
    $is_fresh_install = empty($site_id);
    
    // If no local site_id, check if this site was previously registered on the server
    if ($is_fresh_install) {
        $site_url = home_url();
        
        // Check server database for existing site_id by URL
        $api_url = 'https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/get-site-by-url.php';
        $response = wp_remote_post($api_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['site_url' => $site_url]),
            'timeout' => 10,
            'blocking' => true, // Must be blocking to get the response
        ]);
        
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['success']) && $body['success'] && !empty($body['site_id'])) {
                // Site was previously registered - reuse existing site_id
                $site_id = sanitize_text_field($body['site_id']);
                $is_fresh_install = false; // It's a reactivation
            }
        }
        
        // If still no site_id found, generate a new one
        if (empty($site_id)) {
            $site_id = wp_generate_uuid4();
            $is_fresh_install = true;
        }
        
        // Save site_id to WordPress options
        update_option('aat_site_id', sanitize_text_field($site_id));
    }
    
    // Register site with central server (this also tracks activation/reactivation events)
    aat_register_site_with_server($site_id);
}

/**
 * Register site with central server
 *
 * @since 1.0.0
 * @param string $site_id The unique site identifier
 * @return void
 */
function aat_register_site_with_server($site_id) {
    // Get site information
    $site_url = home_url();
    $admin_email = get_option('admin_email');
    $wp_version = get_bloginfo('version');
    
    // Get plugin version from header
    $plugin_data = get_file_data(__FILE__, [
        'Version' => 'Version'
    ]);
    $plugin_version = $plugin_data['Version'] ?? '1.0.0';
    
    // Prepare data to send
    $data = [
        'site_id' => $site_id,
        'site_url' => $site_url,
        'email' => $admin_email,
        'wp_version' => $wp_version,
        'plugin_version' => $plugin_version,
    ];
    
    // Send registration to central server
    $response = wp_remote_post('https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/register-site.php', [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode($data),
        'timeout' => 10,
        'blocking' => false, // Non-blocking to avoid slowing down activation
    ]);
    
    // Note: Using non-blocking request so activation isn't delayed
    // Registration will happen in background
}

/**
 * Track plugin deactivation
 *
 * @since 1.0.0
 * @return void
 */
function aat_track_deactivation() {
    $site_id = get_option('aat_site_id');
    if (!$site_id) {
        return;
    }
    
    // Get plugin version
    $plugin_data = get_file_data(__FILE__, ['Version' => 'Version']);
    $plugin_version = $plugin_data['Version'] ?? '1.0.0';
    
    // Send deactivation event to server
    wp_remote_post('https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/track-event.php', [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode([
            'site_id' => $site_id,
            'event_type' => 'deactivation',
            'site_url' => home_url(),
            'plugin_version' => $plugin_version,
            'timestamp' => gmdate('Y-m-d H:i:s')
        ]),
        'timeout' => 5,
        'blocking' => false, // Non-blocking so deactivation isn't delayed
    ]);
}

/**
 * Display admin notices
 *
 * @since 1.1.0
 * @return void
 */
function aat_admin_notices() {
    // Only show on admin pages
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Priority order: Show only ONE notice at a time
    // 1. Low credits (most urgent - user can't generate)
    // 2. Welcome (important for new users)
    // 3. Feedback (least urgent)
    
    if (aat_show_low_credits_notice()) {
        return; // Low credits shown, don't show others
    }
    
    if (aat_show_welcome_notice()) {
        return; // Welcome shown, don't show feedback
    }
    
    aat_show_feedback_notice();
}

/**
 * Show welcome notice after plugin activation
 *
 * @since 1.1.0
 * @return bool True if notice was shown, false otherwise
 */
function aat_show_welcome_notice() {
    // Check if already dismissed
    if (get_option('aat_welcome_dismissed')) {
        return false;
    }
    
    // Check if activation was recent (within 7 days)
    $site_id = get_option('aat_site_id');
    if (!$site_id) {
        return false;
    }
    
    // Check if we should show (only show once, within first week)
    $activation_time = get_option('aat_activation_time');
    if (!$activation_time) {
        update_option('aat_activation_time', time());
        $activation_time = time();
    }
    
    // Don't show if more than 7 days old
    if ((time() - $activation_time) > (7 * DAY_IN_SECONDS)) {
        update_option('aat_welcome_dismissed', true);
        return false;
    }
    
    $plugin_page_url = admin_url('admin.php?page=auto-alt-tagger');
    $dismiss_url = wp_nonce_url(
        add_query_arg('aat_dismiss_notice', 'welcome'),
        'aat_dismiss_notice',
        'aat_notice_nonce'
    );
    
    ?>
    <div class="notice notice-success is-dismissible" id="aat-welcome-notice">
        <h2>🎉 <?php echo esc_html__('Welcome to Auto Image Alt Text Generator!', 'hs-auto-image-alt-text-generator-for-seo'); ?></h2>
        <p><?php echo esc_html__('Thank you for installing our plugin! You\'re now ready to automatically generate SEO-optimized alt text for your images.', 'hs-auto-image-alt-text-generator-for-seo'); ?></p>
        <p>
            <strong><?php echo esc_html__('Quick Start:', 'hs-auto-image-alt-text-generator-for-seo'); ?></strong>
        </p>
        <ol style="margin-left: 20px;">
            <li><?php echo esc_html__('Visit the plugin dashboard to see all your images', 'hs-auto-image-alt-text-generator-for-seo'); ?></li>
            <li><?php echo esc_html__('Click "Generate" on any image to create AI-powered alt text', 'hs-auto-image-alt-text-generator-for-seo'); ?></li>
            <li><?php echo esc_html__('Use "Bulk Generate" to process multiple images at once (Pro Feature)', 'hs-auto-image-alt-text-generator-for-seo'); ?></li>
        </ol>
        <p>
            <a href="<?php echo esc_url($plugin_page_url); ?>" class="button button-primary">
                <?php echo esc_html__('Go to Dashboard', 'hs-auto-image-alt-text-generator-for-seo'); ?>
            </a>
            <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-secondary">
                <?php echo esc_html__('Dismiss', 'hs-auto-image-alt-text-generator-for-seo'); ?>
            </a>
        </p>
    </div>
    <style>
        #aat-welcome-notice h2 { margin: 0.5em 0; }
        #aat-welcome-notice ol { margin: 0.5em 0 1em 0; }
    </style>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#aat-welcome-notice').on('click', '.notice-dismiss', function() {
            $.post(ajaxurl, {
                action: 'aat_dismiss_notice',
                notice_type: 'welcome',
                nonce: '<?php echo esc_js(wp_create_nonce('aat_dismiss_notice')); ?>'
            });
        });
    });
    </script>
    <?php
    return true;
}

/**
 * Show feedback request after 10 generations
 *
 * @since 1.1.0
 * @return bool True if notice was shown, false otherwise
 */
function aat_show_feedback_notice() {
    // Check if already dismissed
    if (get_option('aat_feedback_dismissed')) {
        return false;
    }
    
    // Check if user has at least 10 generations
    $usage = aat_get_monthly_usage();
    if (!$usage || $usage < 10) {
        return false;
    }
    
    // Check if we've already shown this
    if (get_option('aat_feedback_shown')) {
        return false;
    }
    
    // Mark as shown so it only appears once
    update_option('aat_feedback_shown', true);
    
    $feedback_url = 'https://hatrixsolutions.com/support';
    $dismiss_url = wp_nonce_url(
        add_query_arg('aat_dismiss_notice', 'feedback'),
        'aat_dismiss_notice',
        'aat_notice_nonce'
    );
    
    ?>
    <div class="notice notice-info is-dismissible" id="aat-feedback-notice">
        <h3>⭐ <?php echo esc_html__('Enjoying Auto Image Alt Text Generator?', 'hs-auto-image-alt-text-generator-for-seo'); ?></h3>
        <p>
            <?php
            echo esc_html__('You\'ve generated alt text for 10+ images! We\'d love to hear your feedback.', 'hs-auto-image-alt-text-generator-for-seo');
            ?>
        </p>
        <p>
            <?php echo esc_html__('Help us improve by sharing your experience, feature requests, or any issues you\'ve encountered.', 'hs-auto-image-alt-text-generator-for-seo'); ?>
        </p>
        <p>
            <a href="<?php echo esc_url($feedback_url); ?>" class="button button-primary" target="_blank">
                <?php echo esc_html__('Share Feedback', 'hs-auto-image-alt-text-generator-for-seo'); ?>
            </a>
            <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-secondary">
                <?php echo esc_html__('Maybe Later', 'hs-auto-image-alt-text-generator-for-seo'); ?>
            </a>
        </p>
    </div>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#aat-feedback-notice').on('click', '.notice-dismiss', function() {
            $.post(ajaxurl, {
                action: 'aat_dismiss_notice',
                notice_type: 'feedback',
                nonce: '<?php echo esc_js(wp_create_nonce('aat_dismiss_notice')); ?>'
            });
        });
    });
    </script>
    <?php
    return true;
}

/**
 * Get the upgrade URL with site_id for tracking
 *
 * @since 1.0.0
 * @return string The upgrade URL
 */
function aat_get_upgrade_url() {
    $site_id = get_option('aat_site_id');
    return 'https://buy.stripe.com/cNidR97Rj7gncfb7isejK00?client_reference_id=' . urlencode($site_id);
}

/**
 * Show low credits warning for free users
 *
 * @since 1.1.0
 * @return bool True if notice was shown, false otherwise
 */
function aat_show_low_credits_notice() {
    // Check if already dismissed this month
    $dismissed_month = get_option('aat_low_credits_dismissed_month');
    $current_month = gmdate('Y-m');
    
    // Reset dismissal if it's a new month
    if ($dismissed_month && $dismissed_month !== $current_month) {
        delete_option('aat_low_credits_dismissed');
        delete_option('aat_low_credits_dismissed_month');
        $dismissed_month = false;
    }
    
    if (get_option('aat_low_credits_dismissed')) {
        return false;
    }
    
    // Only show to free users
    $pro_status = aat_get_central_pro_status();
    if ($pro_status === 'pro') {
        return false;
    }
    
    // Get remaining generations
    $usage = aat_get_monthly_usage();
    $free_limit = 15;
    $remaining = $free_limit - $usage;
    
    // Only show if 3 or fewer generations remaining
    if ($remaining > 3 || $remaining < 0) {
        return false;
    }
    
    // If they've hit exactly 0, show different message
    $is_depleted = ($remaining === 0);
    
    $upgrade_url = aat_get_upgrade_url();
    $dismiss_url = wp_nonce_url(
        add_query_arg('aat_dismiss_notice', 'low_credits'),
        'aat_dismiss_notice',
        'aat_notice_nonce'
    );
    
    if ($is_depleted) {
        ?>
        <div class="notice notice-error" id="aat-low-credits-notice">
            <h3>⚠️ <?php echo esc_html__('No Generations Remaining', 'hs-auto-image-alt-text-generator-for-seo'); ?></h3>
            <p>
                <strong><?php echo esc_html__('You\'ve used all 15 free alt text generations this month.', 'hs-auto-image-alt-text-generator-for-seo'); ?></strong>
            </p>
            <p>
                <?php echo esc_html__('Upgrade to Pro to get 100 generations per month and unlock bulk generation features.', 'hs-auto-image-alt-text-generator-for-seo'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url($upgrade_url); ?>" class="button button-primary" target="_blank">
                    <?php echo esc_html__('Upgrade to Pro', 'hs-auto-image-alt-text-generator-for-seo'); ?>
                </a>
                <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-secondary">
                    <?php echo esc_html__('Dismiss', 'hs-auto-image-alt-text-generator-for-seo'); ?>
                </a>
            </p>
        </div>
        <?php
    } else {
        ?>
        <div class="notice notice-warning" id="aat-low-credits-notice">
            <h3>⚡ <?php echo esc_html__('Low on Generations', 'hs-auto-image-alt-text-generator-for-seo'); ?></h3>
            <p>
                <strong>
                    <?php
                    printf(
                        /* translators: 1: number of remaining generations, 2: plural suffix (s or empty) */
                        esc_html__('You have %1$d generation%2$s remaining this month.', 'hs-auto-image-alt-text-generator-for-seo'),
                        absint($remaining),
                        $remaining === 1 ? '' : 's'
                    );
                    ?>
                </strong>
            </p>
            <p>
                <?php echo esc_html__('Upgrade to Pro for 100 generations per month, bulk processing, and priority support.', 'hs-auto-image-alt-text-generator-for-seo'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url($upgrade_url); ?>" class="button button-primary" target="_blank">
                    <?php echo esc_html__('Upgrade to Pro - Only $10/month', 'hs-auto-image-alt-text-generator-for-seo'); ?>
                </a>
                <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-secondary">
                    <?php echo esc_html__('Dismiss', 'hs-auto-image-alt-text-generator-for-seo'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#aat-low-credits-notice').on('click', '.notice-dismiss', function() {
            $.post(ajaxurl, {
                action: 'aat_dismiss_notice',
                notice_type: 'low_credits',
                nonce: '<?php echo esc_js(wp_create_nonce('aat_dismiss_notice')); ?>'
            });
        });
    });
    </script>
    <?php
    
    return true;
}

/**
 * Handle notice dismissal
 *
 * @since 1.1.0
 * @return void
 */
function aat_handle_notice_dismissal() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below
    if (!isset($_GET['aat_dismiss_notice']) || !isset($_GET['aat_notice_nonce'])) {
        return;
    }
    
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below
    $nonce = sanitize_text_field(wp_unslash($_GET['aat_notice_nonce']));
    if (!wp_verify_nonce($nonce, 'aat_dismiss_notice')) {
        return;
    }
    
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above
    $notice_type = sanitize_text_field(wp_unslash($_GET['aat_dismiss_notice']));
    
    if ($notice_type === 'welcome') {
        update_option('aat_welcome_dismissed', true);
    } elseif ($notice_type === 'feedback') {
        update_option('aat_feedback_dismissed', true);
    } elseif ($notice_type === 'low_credits') {
        update_option('aat_low_credits_dismissed', true);
        update_option('aat_low_credits_dismissed_month', gmdate('Y-m'));
    }
    
    // Redirect to remove query params
    wp_safe_redirect(remove_query_arg(['aat_dismiss_notice', 'aat_notice_nonce']));
    exit;
}

/**
 * AJAX handler for dismissing notices via X button
 *
 * @since 1.1.0
 * @return void
 */
function aat_ajax_dismiss_notice() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aat_dismiss_notice')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }
    
    // Get notice type
    $notice_type = isset($_POST['notice_type']) ? sanitize_text_field(wp_unslash($_POST['notice_type'])) : '';
    
    // Dismiss the appropriate notice
    if ($notice_type === 'welcome') {
        update_option('aat_welcome_dismissed', true);
        wp_send_json_success(['message' => 'Welcome notice dismissed']);
    } elseif ($notice_type === 'feedback') {
        update_option('aat_feedback_dismissed', true);
        wp_send_json_success(['message' => 'Feedback notice dismissed']);
    } elseif ($notice_type === 'low_credits') {
        update_option('aat_low_credits_dismissed', true);
        update_option('aat_low_credits_dismissed_month', gmdate('Y-m'));
        wp_send_json_success(['message' => 'Low credits notice dismissed']);
    } else {
        wp_send_json_error(['message' => 'Invalid notice type']);
    }
}

/**
 * Periodic heartbeat to update site registration (runs weekly)
 *
 * @since 1.0.0
 * @return void
 */
function aat_maybe_ping_server() {
    $last_ping = get_option('aat_last_server_ping');
    $one_week = 7 * DAY_IN_SECONDS;
    
    // Only ping once per week
    if ($last_ping && (time() - $last_ping) < $one_week) {
        return;
    }
    
    $site_id = get_option('aat_site_id');
    if (!$site_id) {
        return;
    }
    
    // Update last ping time
    update_option('aat_last_server_ping', time());
    
    // Register/update site info
    aat_register_site_with_server($site_id);
}

/**
 * Add admin menu for the plugin
 *
 * @since 0.1.0
 * @return void
 */
function aat_add_menu() {
    add_menu_page(
        __('Auto Alt Text Generator For SEO', 'hs-auto-image-alt-text-generator-for-seo'),
        __('Auto Alt Text Generator For SEO', 'hs-auto-image-alt-text-generator-for-seo'),
        'manage_options',
        'auto-alt-tagger',
        'aat_viewer_page',
        'dashicons-format-image',
        80
    );
}


/**
 * Enqueue admin styles and scripts
 *
 * @since 0.1.0
 * @param string $hook The current admin page hook.
 * @return void
 */
function aat_enqueue_admin_styles(string $hook): void {
    // Only load on our plugin pages
    if ($hook !== 'toplevel_page_auto-alt-tagger') {
        return;
    }
    
    // Ensure dashicons is loaded
    wp_enqueue_style('dashicons');
    
    wp_enqueue_style(
        'aat-admin-style',
        AAT_PLUGIN_URL . 'assets/admin-style.css',
        array('dashicons'),
        AAT_VERSION
    );
    
    // Enqueue jQuery and localize ajaxurl
    wp_enqueue_script('jquery');
    wp_localize_script('jquery', 'aat_ajax_object', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('aat_ajax_nonce')
    ));
}

add_action('admin_init', 'aat_register_settings');

function aat_register_settings() {
    // Developer settings (aat_is_pro, aat_debug_mode) are now managed centrally via dev-dashboard
    register_setting('aat_settings_group', 'aat_user_email', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_email',
        'default' => ''
    ]);
}

function aat_is_pro_user() {
    // First check local WordPress option
    $local_setting = get_option('aat_is_pro');
    if ($local_setting === 'yes') {
        return true;
    }
    
    // Then check centralized pro status from hs_aat_plugin_sites
    $central_pro_status = aat_get_central_pro_status();
    if ($central_pro_status === 'pro') {
        return true;
    }
    
    return false;
}

/**
 * Get centralized pro status from API
 *
 * @since 0.1.0
 * @return string 'pro' or 'free'
 */
function aat_get_central_pro_status(): string {
    $cache_key = 'aat_pro_status_' . get_option('aat_site_id');
    
    // Allow bypassing cache for testing (add ?aat_refresh_cache=1 to any admin page)
    // phpcs:disable WordPress.Security.NonceVerification -- Cache refresh is non-destructive read-only operation
    $bypass_cache = isset($_GET['aat_refresh_cache']) || isset($_POST['aat_refresh_cache']);
    // phpcs:enable WordPress.Security.NonceVerification
    
    // For developers: shorter cache time and easier bypass
    $cache_time = aat_is_developer_environment() ? 5 : 30; // 5 seconds for dev, 30 for production
    
    $cached_pro_status = $bypass_cache ? false : get_transient($cache_key);
    
    if ($cached_pro_status === false) {
        $site_id = get_option('aat_site_id');
        if (!$site_id) {
            $cached_pro_status = 'free';
            set_transient($cache_key, $cached_pro_status, $cache_time);
            return $cached_pro_status;
        }
        
        // Make API call to get centralized pro status from hs_aat_plugin_sites
        $api_url = 'https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/dev-settings.php';
        $response = wp_remote_get($api_url . '?action=get&site_id=' . urlencode($site_id), [
            'timeout' => 5,
            'sslverify' => true
        ]);
        
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['success']) && $body['success'] && isset($body['site_info']['pro_status'])) {
                $cached_pro_status = $body['site_info']['pro_status'];
            } else {
                $cached_pro_status = 'free';
            }
        } else {
            $cached_pro_status = 'free';
        }
        
        // Cache the result
        set_transient($cache_key, $cached_pro_status, $cache_time);
    }
    
    return $cached_pro_status;
}

/**
 * Clear the pro status cache (useful for testing)
 *
 * @since 0.1.0
 * @return void
 */
function aat_clear_pro_status_cache(): void {
    $cache_key = 'aat_pro_status_' . get_option('aat_site_id');
    delete_transient($cache_key);
}

/**
 * Check if debug mode is enabled
 *
 * @since 0.1.0
 * @return bool
 */
function aat_is_debug_mode(): bool {
    // Debug mode only applies to hatrixsolutions.com
    $site_url = home_url();
    if (strpos($site_url, 'hatrixsolutions.com') === false) {
        return false;
    }
    
    // First check local WordPress option
    $local_setting = get_option('aat_debug_mode');
    if ($local_setting === 'yes') {
        return true;
    }
    
    // Then check centralized debug setting for hatrixsolutions.com
    $central_setting = aat_get_central_debug_setting();
    if ($central_setting === 'yes') {
        return true;
    }
    
    return false;
}

function aat_get_central_debug_setting() {
    // Cache debug setting for the current request
    static $cached_debug = null;
    
    if ($cached_debug === null) {
        // Make API call to get centralized debug setting (file-based)
        $api_url = 'https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/dev-settings.php';
        $response = wp_remote_get($api_url . '?action=get_debug', [
            'timeout' => 5,
            'sslverify' => true
        ]);
        
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['success']) && $body['success'] && isset($body['debug_mode'])) {
                $cached_debug = $body['debug_mode'];
            } else {
                $cached_debug = 'no';
            }
        } else {
            $cached_debug = 'no';
        }
    }
    
    return $cached_debug;
}

function aat_is_developer_environment() {
    // Secure developer authentication via API
    static $dev_status = null;
    
    if ($dev_status !== null) {
        return $dev_status;
    }
    
    // Check if developer is authenticated via secure API
    $dev_status = aat_check_developer_auth();
    return $dev_status;
}

function aat_check_developer_auth() {
    // Check for developer authentication token in cookie
    if (!isset($_COOKIE['aat_dev_token'])) {
        return false;
    }
    
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated with preg_match below
    $token = wp_unslash($_COOKIE['aat_dev_token']);
    
    // Validate token format (should be a 64-char hash)
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    
    // Token is validated by regex pattern, safe to use
    $token = sanitize_text_field($token);
    
    // Make internal request to dev auth API to validate token
    $auth_url = home_url() . '/api/hs-auto-alt-text-generator-for-seo/dev-auth.php?action=verify';
    
    $response = wp_remote_get($auth_url, [
        'timeout' => 5,
        'cookies' => $_COOKIE // Pass current session cookies including our token
    ]);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $is_authenticated = isset($body['authenticated']) && $body['authenticated'] === true;
    
    return $is_authenticated;
}

// Usage tracking functions
function aat_get_current_month_key() {
    return gmdate('Y-m'); // e.g., "2024-09"
}

function aat_get_current_billing_cycle($site_id) {
    if (!$site_id) {
        return null;
    }
    
    // Get signup date from API
    $cache_key = 'aat_billing_cycle_' . $site_id;
    $cached_data = get_transient($cache_key);
    
    if ($cached_data !== false) {
        return $cached_data;
    }
    
    // Make API call to get site info including signup date
    $api_url = 'https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/dev-settings.php';
    $response = wp_remote_get($api_url . '?action=get&site_id=' . urlencode($site_id), [
        'timeout' => 5,
        'sslverify' => true
    ]);
    
    if (is_wp_error($response)) {
        return null;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($body['success']) || !$body['success'] || !isset($body['site_info']['created_at'])) {
        return null;
    }
    
    $signup_date = $body['site_info']['created_at'];
    
    if (!$signup_date) {
        return null;
    }
    
    $signup_timestamp = strtotime($signup_date);
    $signup_day = intval(gmdate('d', $signup_timestamp));
    
    // Handle edge cases for months with fewer days
    // If signup was on 29th, 30th, or 31st, use the last day of shorter months
    $current_time = time();
    $current_year = gmdate('Y', $current_time);
    $current_month = gmdate('m', $current_time);
    $current_day = gmdate('d', $current_time);
    
    // Get the last day of current month
    $last_day_of_month = gmdate('t', mktime(0, 0, 0, $current_month, 1, $current_year));
    
    // Use signup day, but cap it at the last day of current month
    $reset_day = min($signup_day, $last_day_of_month);
    
    // Calculate current billing cycle start
    if ($current_day >= $reset_day) {
        // We're in the current month's cycle
        $cycle_start = gmdate('Y-m-d H:i:s', mktime(0, 0, 0, $current_month, $reset_day, $current_year));
        
        // Calculate next month's reset day
        $next_month = ($current_month == 12) ? 1 : $current_month + 1;
        $next_year = ($current_month == 12) ? $current_year + 1 : $current_year;
        $last_day_next_month = gmdate('t', mktime(0, 0, 0, $next_month, 1, $next_year));
        $next_reset_day = min($signup_day, $last_day_next_month);
        
        $cycle_end = gmdate('Y-m-d H:i:s', mktime(23, 59, 59, $next_month, $next_reset_day - 1, $next_year));
        $next_reset = gmdate('Y-m-d H:i:s', mktime(0, 0, 0, $next_month, $next_reset_day, $next_year));
    } else {
        // We're still in the previous month's cycle
        $prev_month = ($current_month == 1) ? 12 : $current_month - 1;
        $prev_year = ($current_month == 1) ? $current_year - 1 : $current_year;
        $last_day_prev_month = gmdate('t', mktime(0, 0, 0, $prev_month, 1, $prev_year));
        $prev_reset_day = min($signup_day, $last_day_prev_month);
        
        $cycle_start = gmdate('Y-m-d H:i:s', mktime(0, 0, 0, $prev_month, $prev_reset_day, $prev_year));
        $cycle_end = gmdate('Y-m-d H:i:s', mktime(23, 59, 59, $current_month, $reset_day - 1, $current_year));
        $next_reset = gmdate('Y-m-d H:i:s', mktime(0, 0, 0, $current_month, $reset_day, $current_year));
    }
    
    $billing_data = [
        'start' => $cycle_start,
        'end' => $cycle_end,
        'reset_day' => $reset_day,
        'signup_date' => $signup_date,
        'next_reset' => $next_reset
    ];
    
    // Cache for 1 hour
    set_transient($cache_key, $billing_data, HOUR_IN_SECONDS);
    
    return $billing_data;
}

function aat_get_monthly_usage() {
    $site_id = get_option('aat_site_id');
    if (!$site_id) {
        return 0;
    }
    
    // Check cache first (short cache since usage changes frequently)
    $cache_key = 'aat_monthly_usage_' . $site_id;
    $cached_usage = get_transient($cache_key);
    
    if ($cached_usage !== false) {
        return intval($cached_usage);
    }
    
    // Make API call to get usage count
    $api_url = 'https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/get-usage.php';
    $response = wp_remote_get($api_url . '?site_id=' . urlencode($site_id), [
        'timeout' => 5,
        'sslverify' => true
    ]);
    
    if (is_wp_error($response)) {
        return 0;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($body['success']) || !$body['success']) {
        return 0;
    }
    
    $usage_count = isset($body['usage_count']) ? intval($body['usage_count']) : 0;
    
    // Cache for 5 minutes
    set_transient($cache_key, $usage_count, 5 * MINUTE_IN_SECONDS);
    
    return $usage_count;
}

function aat_get_next_reset_date($site_id = null) {
    if (!$site_id) {
        $site_id = get_option('aat_site_id');
    }
    
    $billing_cycle = aat_get_current_billing_cycle($site_id);
    if (!$billing_cycle) {
        return null;
    }
    
    // Return the next reset date (1st of next month)
    return $billing_cycle['next_reset'];
}

function aat_increment_monthly_usage($image_url = null) {
    $site_id = get_option('aat_site_id');
    if (!$site_id || !$image_url) {
        return 0;
    }
    
    // Usage is tracked automatically by the generate-alt-tag.php API
    // Just clear the cache so next call gets fresh data
    $cache_key = 'aat_monthly_usage_' . $site_id;
    delete_transient($cache_key);
    
    // Get updated count from API
    $new_count = aat_get_monthly_usage();
    
    return $new_count;
}

function aat_can_generate_free() {
    $monthly_usage = aat_get_monthly_usage();
    $limits = aat_get_user_limits();
    
    return $monthly_usage < $limits['current_limit'];
}

function aat_get_remaining_free_generations() {
    $monthly_usage = aat_get_monthly_usage();
    $limits = aat_get_user_limits();
    
    return max(0, $limits['current_limit'] - $monthly_usage);
}

/**
 * Get user limits from server-side API (secure)
 *
 * @since 0.1.0
 * @return array
 */
function aat_get_user_limits() {
    // Cache limits for the current request to avoid multiple API calls
    static $cached_limits = null;
    
    if ($cached_limits === null) {
        $site_id = get_option('aat_site_id');
        if (!$site_id) {
            // Fallback to free limits if no site ID
            $cached_limits = [
                'current_limit' => 15,
                'plan' => 'free',
                'is_pro' => false
            ];
            return $cached_limits;
        }
        
        // Make API call to get limits from server
        $api_url = 'https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/dev-settings.php';
        $response = wp_remote_get($api_url . '?action=get_limits&site_id=' . urlencode($site_id), [
            'timeout' => 5,
            'sslverify' => true
        ]);
        
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['success']) && $body['success'] && isset($body['limits'])) {
                $cached_limits = $body['limits'];
            } else {
                // Fallback to free limits if API fails
                $cached_limits = [
                    'current_limit' => 15,
                    'plan' => 'free',
                    'is_pro' => false
                ];
            }
        } else {
            // Fallback to free limits if API fails
            $cached_limits = [
                'current_limit' => 15,
                'plan' => 'free',
                'is_pro' => false
            ];
        }
    }
    
    return $cached_limits;
}

// Future: Get user's plan limits
function aat_get_plan_limits() {
    $plan = get_option('aat_plan', 'free');
    
    $limits = [
        'free' => 15,
        'basic' => 50,    // $10/month
        'pro' => 100,      // $20/month  
        'agency' => -1     // $30-49/month (unlimited)
    ];
    
    return $limits[$plan] ?? 15;
}

// Future: Check if user can generate based on their plan
function aat_can_generate_by_plan() {
    $plan_limit = aat_get_plan_limits();
    
    if ($plan_limit === -1) {
        return true; // Unlimited
    }
    
    $monthly_usage = aat_get_monthly_usage();
    return $monthly_usage < $plan_limit;
}

// Future: Get remaining generations based on plan
function aat_get_remaining_by_plan() {
    $plan_limit = aat_get_plan_limits();
    
    if ($plan_limit === -1) {
        return -1; // Unlimited
    }
    
    $monthly_usage = aat_get_monthly_usage();
    return max(0, $plan_limit - $monthly_usage);
}



// Old admin page function removed - now using aat_viewer_page as main page

add_action('wp_ajax_aat_scan_and_tag', 'aat_scan_and_tag');

/**
 * Check rate limiting for AJAX requests
 * Allows 60 requests per minute (suitable for bulk operations)
 *
 * @since 1.1.0
 * @param string $action Action identifier
 * @return bool True if allowed, false if rate limited
 */
function aat_check_rate_limit(string $action): bool {
    $user_id = get_current_user_id();
    if (!$user_id) {
        return false;
    }
    
    $transient_key = 'aat_rate_limit_' . $action . '_' . $user_id;
    $attempts = get_transient($transient_key);
    
    // Allow 60 requests per minute (1 per second average, suitable for bulk)
    if ($attempts && $attempts > 60) {
        return false;
    }
    
    set_transient($transient_key, ($attempts ? $attempts + 1 : 1), MINUTE_IN_SECONDS);
    return true;
}

/**
 * AJAX handler for scanning and tagging images
 *
 * @since 0.1.0
 * @return void
 */
function aat_scan_and_tag(): void {
    // Verify nonce for security
    $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'aat_ajax_nonce')) {
        wp_send_json_error(__('Security check failed', 'hs-auto-image-alt-text-generator-for-seo'));
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'hs-auto-image-alt-text-generator-for-seo'));
        return;
    }
    
    // Check rate limiting
    if (!aat_check_rate_limit('scan_and_tag')) {
        wp_send_json_error(__('Too many requests. Please wait a moment and try again.', 'hs-auto-image-alt-text-generator-for-seo'));
        return;
    }
    
    // Check if user can generate
    if (!aat_can_generate_free()) {
        $next_reset = aat_get_next_reset_date();
        $limits = aat_get_user_limits();
        $is_pro = $limits['is_pro'];
        
        if ($is_pro) {
            /* translators: %d: number of generations per month */
            $message = sprintf(__('Monthly Pro generation limit reached (%d/month). Your limit resets next billing cycle.', 'hs-auto-image-alt-text-generator-for-seo'), $limits['current_limit']);
            wp_send_json([
                'success' => false,
                'message' => $message,
                'limit_reached' => true,
                'next_reset' => $next_reset,
                'remaining' => 0,
                'is_pro_limit' => true
            ]);
        } else {
            wp_send_json([
                'success' => false,
                'message' => __('Monthly generation limit reached. Upgrade to Pro for 100 generations per month.', 'hs-auto-image-alt-text-generator-for-seo'),
                'limit_reached' => true,
                'next_reset' => $next_reset,
                'remaining' => 0
            ]);
        }
        return;
    }

	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Necessary to find images missing alt text
	$images = get_posts([
		'post_type' => 'attachment',
		'post_mime_type' => 'image',
		'posts_per_page' => -1, // -1 = process all images, 1 = 1 at a time
		'post_status' => 'inherit',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Necessary to find images missing alt text
		'meta_query' => [
		    'relation' => 'OR',
		    [
		        'key' => '_wp_attachment_image_alt',
		        'compare' => 'NOT EXISTS',
		    ],
		    [
		        'key' => '_wp_attachment_image_alt',
		        'value' => '',
		        'compare' => '='
		    ]
		]
	]);

	$countTagged = 0;
	$updated_images = [];
	
	foreach ($images as $image) {
		// Check limit before each generation (for non-pro users)
		if (!aat_can_generate_free()) {
			break; // Stop processing if limit reached
		}
		$url = wp_get_attachment_url($image->ID);
		$filename = basename($url);

		$response = wp_remote_post('https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/generate-alt-tag.php', [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode([
				'image_url' => $url,
				'filename' => $filename,
				'site_id' => get_option('aat_site_id'),
			]),
			'timeout' => 30,
		]);

		if (is_wp_error($response)) { 
			continue;
		}
			
		$body_raw = wp_remote_retrieve_body($response); 
		// API response received 

		
		// $body = json_decode(wp_remote_retrieve_body($response), true);
		$body = json_decode($body_raw, true);
		$alt_text = trim($body['alt_text'] ?? '');
		
		if ( $alt_text !== '') {
			update_post_meta($image->ID, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
			
			// Track usage for non-pro users
			aat_increment_monthly_usage($url);
			
			$countTagged++;
			$updated_images[] = [
				'id' => $image->ID,
				'url' => $url,
				'alt' => $alt_text,
				'title' => get_the_title($image->ID),
				'edit_url' => get_edit_post_link($image->ID)
			];
		} else {	
			// GPT response processed
		}
		// Processing image ID: {$image->ID}
	}

		wp_send_json([
			'success' => true,
			'message' => "Tagged $countTagged image(s).",
			'images' => $updated_images,
			'remaining' => aat_get_remaining_free_generations()
		]);

}


add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'aat_plugin_action_links');

function aat_plugin_action_links($links) {
	$settings_link = '<a href="admin.php?page=auto-alt-tagger">Settings</a>';
	array_unshift($links, $settings_link);
	return $links;
}



function aat_get_viewer_data() {
    $meta_query = [];
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of data, no destructive operations
	$search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
	$filter = isset($_GET['filter']) ? sanitize_text_field(wp_unslash($_GET['filter'])) : '';
	$view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : 'grid'; // grid or table
	$paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
	$per_page = isset($_GET['per_page']) ? absint($_GET['per_page']) : 20;
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
	
	if ($filter === 'missing') {
	    $meta_query = [
	        'relation' => 'OR',
	        ['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
	        ['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=']
	    ];
	}

	// First, get total count for pagination
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Necessary for filtering images by alt text status
	$count_args = [
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'posts_per_page' => -1,
        'post_status' => 'inherit',
        'meta_query' => $meta_query,
		'fields' => 'ids',
	];
	
	if ($search) {
		$count_args['s'] = $search;
	}
	
	$all_image_ids = get_posts($count_args);
	$total_images = count($all_image_ids);
	
	// Count missing alt tags from all images
	$missing_alt_count = 0;
	foreach ($all_image_ids as $image_id) {
		$alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
		if (empty($alt)) {
			$missing_alt_count++;
		}
	}

	// Now get paginated results
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Necessary for filtering images by alt text status
	$query_args = [
		'post_type' => 'attachment',
		'post_mime_type' => 'image',
		'posts_per_page' => $per_page,
		'paged' => $paged,
		'post_status' => 'inherit',
		'meta_query' => $meta_query,
	];

	if ($search) {
		$query_args['s'] = $search;
	}

	$images = get_posts($query_args);
	$total_pages = ceil($total_images / $per_page);
	$showing_from = (($paged - 1) * $per_page) + 1;
	$showing_to = min($paged * $per_page, $total_images);

	return [
		'search' => $search,
		'filter' => $filter,
		'view' => $view,
		'paged' => $paged,
		'per_page' => $per_page,
		'images' => $images,
		'total_images' => $total_images,
		'missing_alt_count' => $missing_alt_count,
		'total_pages' => $total_pages,
		'showing_from' => $showing_from,
		'showing_to' => $showing_to
	];
}

// Helper function to render page header and navigation
function aat_render_page_header($active_tab) {
	?>
	<div class="wrap aat-viewer-wrap">
		<h1 class="aat-page-title">
			<span class="dashicons dashicons-format-image"></span>
			Auto Alt Text Generator For SEO
		</h1>
		
	<!-- Tab Navigation -->
	<div class="aat-tab-nav">
		<a href="<?php echo esc_url(add_query_arg('tab', 'viewer', remove_query_arg(['paged', 'search', 'filter', 'view']))) ?>" 
		   class="aat-tab <?php echo $active_tab === 'viewer' ? 'active' : '' ?>">
			<span class="dashicons dashicons-images-alt2"></span>
			Alt Tag Viewer
		</a>
		<a href="<?php echo esc_url(add_query_arg('tab', 'settings', remove_query_arg(['paged', 'search', 'filter', 'view']))) ?>" 
		   class="aat-tab <?php echo $active_tab === 'settings' ? 'active' : '' ?>">
			<span class="dashicons dashicons-admin-settings"></span>
			Settings
		</a>
	</div>
	<?php
}

// Helper function to render stats cards
function aat_render_stats_cards($data) {
	$monthly_usage = aat_get_monthly_usage();
	$limits = aat_get_user_limits();
	$remaining_generations = aat_get_remaining_free_generations();
	$is_pro = aat_is_pro_user();
	$is_developer = aat_is_developer_environment();
	
	// Developer status check
	?>
	<!-- Stats Cards -->
	<div class="aat-stats-row">
		<div class="aat-stat-card">
			<div class="aat-stat-number"><?php echo absint($data['total_images']) ?></div>
			<div class="aat-stat-label">Total Images</div>
		</div>
		
		<div class="aat-stat-card aat-stat-success">
			<div class="aat-stat-number"><?php echo absint($data['total_images'] - $data['missing_alt_count']) ?></div>
			<div class="aat-stat-label">With Alt Tags</div>
		</div>
		<div class="aat-stat-card aat-stat-warning">
			<div class="aat-stat-number"><?php echo absint($data['missing_alt_count']) ?></div>
			<div class="aat-stat-label">Missing Alt Tags</div>
		</div>
		<div class="aat-stat-card aat-stat-info">
			<?php 
			$limits = aat_get_user_limits();
			?>
			<div class="aat-stat-number"><?php echo absint($remaining_generations) ?></div>
			<div class="aat-stat-label"><?php echo $is_pro ? 'Pro' : 'Free' ?> Generations Left</div>
			<div class="aat-stat-note"><?php echo absint($limits['current_limit']) ?> per month</div>
			<?php if (!$is_pro): ?>
			<div class="aat-upgrade-text" style="margin-top: 8px; padding: 6px 8px; background: rgba(34, 113, 177, 0.1); border-radius: 4px; border-left: 3px solid #2271b1; transition: all 0.2s ease; cursor: pointer;" onmouseover="this.style.background='rgba(34, 113, 177, 0.15)'" onmouseout="this.style.background='rgba(34, 113, 177, 0.1)'" onclick="window.open('https://buy.stripe.com/cNidR97Rj7gncfb7isejK00?client_reference_id=<?php echo esc_attr(urlencode(get_option('aat_site_id'))) ?>', '_blank')">
				<span style="color: #2271b1; text-decoration: none; font-size: 11px; font-weight: 500; display: block; line-height: 1.4;">
					🚀 Upgrade to Pro ($10/month) for 100 Generations per Month
				</span>
			</div>
			<?php endif; ?>
		</div>
		<?php 
		// Developer cards removed - debug info available in admin dashboard
		?>
	</div>
	<?php
}

// Helper function to render bulk actions section
function aat_render_bulk_actions() {
	?>
	<!-- Bulk Actions -->
	<?php if (aat_is_pro_user()): ?>
		<div class="aat-bulk-section">
			<h3>Bulk Actions</h3>
			<div class="aat-bulk-controls">
				<button id="aat-bulk-scan-button" class="button button-primary" style="display: flex; align-items: center; gap: 5px;">
					<span class="dashicons dashicons-superhero"></span>
					Bulk Generate Missing Alt Tags
				</button>
				<span class="aat-bulk-info">Generate alt tags for all images missing them</span>
			</div>
			<p class="aat-bulk-note"><strong>Note:</strong> This will use AI to generate alt tags for images without them.</p>
			<div id="aat-bulk-results" class="aat-bulk-results"></div>
		</div>
	<?php else: ?>
		<div class="aat-bulk-section aat-bulk-disabled">
			<h3>Bulk Actions</h3>
			<div class="aat-bulk-controls">
				<button class="button button-secondary" disabled>
					<span class="dashicons dashicons-lock"></span>
					Bulk Generate Missing Alt Tags
				</button>
					<span class="aat-bulk-info">
						<a href="<?php echo esc_url('https://buy.stripe.com/cNidR97Rj7gncfb7isejK00?client_reference_id=' . urlencode(get_option('aat_site_id'))) ?>" target="_blank">Upgrade to Pro ($10/month)</a> to use bulk actions
					</span>
			</div>
			<p class="aat-bulk-note"><strong>Note:</strong> This will use AI to generate alt tags for images without them.</p>
		</div>
	<?php endif; ?>
	<?php
}

// Helper function to render filters and search form
function aat_render_filters_form($data) {
	?>
	<!-- Filters and Search -->
	<form method="get" class="aat-filter-form">
		<input type="hidden" name="page" value="auto-alt-tagger" />
		<input type="hidden" name="view" value="<?php echo esc_attr($data['view']) ?>" />
		
		<div class="aat-control-row">
			<div class="aat-control-group">
				<label for="aat-search">Search Images:</label>
				<input type="text" id="aat-search" name="search" value="<?php echo esc_attr($data['search']) ?>" 
					   placeholder="Search by filename..." class="aat-search-input" />
			</div>
			
			<div class="aat-control-group">
				<label for="aat-filter">Filter:</label>
				<select id="aat-filter" name="filter" class="aat-filter-select">
        	<option value="">All Images</option>
					<option value="missing" <?php echo $data['filter'] === 'missing' ? 'selected' : '' ?>>Missing Alt Tags</option>
				</select>
			</div>
			
			<div class="aat-control-group">
				<label for="aat-per-page">Per Page:</label>
				<select id="aat-per-page" name="per_page" class="aat-filter-select">
					<option value="10" <?php echo $data['per_page'] == 10 ? 'selected' : '' ?>>10</option>
					<option value="20" <?php echo $data['per_page'] == 20 ? 'selected' : '' ?>>20</option>
					<option value="50" <?php echo $data['per_page'] == 50 ? 'selected' : '' ?>>50</option>
					<option value="100" <?php echo $data['per_page'] == 100 ? 'selected' : '' ?>>100</option>
	    </select>
			</div>
			
			<div class="aat-control-group">
				<button type="submit" class="button button-primary">Apply Filters</button>
				<?php if ($data['search'] || $data['filter']): ?>
					<a href="<?php echo esc_url(admin_url('admin.php?page=auto-alt-tagger')) ?>" class="button">Clear Filters</a>
				<?php endif; ?>
			</div>
		</div>
	</form>
	<?php
}

// Helper function to render view toggle
function aat_render_view_toggle($data) {
	?>
	<!-- View Toggle -->
	<div class="aat-view-controls">
		<span class="aat-view-label">View:</span>
		<div class="aat-view-toggle">
			<a href="<?php echo esc_url(add_query_arg(['view' => 'grid', 'paged' => 1])) ?>" 
			   class="aat-view-btn <?php echo $data['view'] === 'grid' ? 'active' : '' ?>">
				<span class="dashicons dashicons-grid-view"></span>
				Grid
			</a>
			<a href="<?php echo esc_url(add_query_arg(['view' => 'table', 'paged' => 1])) ?>" 
			   class="aat-view-btn <?php echo $data['view'] === 'table' ? 'active' : '' ?>">
				<span class="dashicons dashicons-list-view"></span>
				Table
			</a>
		</div>
	</div>
	<?php
}

function aat_viewer_page() {
	$data = aat_get_viewer_data();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab selection is non-destructive
	$active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'viewer';
	
	// Render page header and navigation
	aat_render_page_header($active_tab);
	
	if ($active_tab === 'viewer'): ?>
		<!-- Viewer Tab Content -->
		<div class="aat-tab-content">
			<?php 
			// Render stats cards
			aat_render_stats_cards($data);
			?>

			<!-- Controls Section -->
			<div class="aat-controls">
				<?php 
				// Render bulk actions
				aat_render_bulk_actions();
				
				// Render filters and search form
				aat_render_filters_form($data);
				
				// Render view toggle
				aat_render_view_toggle($data);
				?>
			</div>
		</div>

			<?php if (empty($data['images'])): ?>
				<div class="aat-no-results">
					<div class="aat-no-results-icon">📷</div>
					<h3>No images found</h3>
					<p>Try adjusting your search or filter criteria.</p>
			</div>
		<?php else: ?>
			<!-- Results Info and Pagination -->
				<div class="aat-results-header">
					<div class="aat-results-info">
						Showing <?php echo absint($data['showing_from']) ?>-<?php echo absint($data['showing_to']) ?> of <?php echo absint($data['total_images']) ?> image<?php echo $data['total_images'] !== 1 ? 's' : '' ?>
						<?php if ($data['search']): ?>
							matching "<?php echo esc_html($data['search']) ?>"
						<?php endif; ?>
						<?php if ($data['filter'] === 'missing'): ?>
							with missing alt tags
						<?php endif; ?>
					</div>
					
					<?php if ($data['total_pages'] > 1): ?>
						<div class="aat-pagination">
						<?php
							$base_url = remove_query_arg('paged');
							
							// Previous button
							if ($data['paged'] > 1): ?>
								<a href="<?php echo esc_url(add_query_arg('paged', $data['paged'] - 1, $base_url)) ?>" class="aat-page-btn">
									<span class="dashicons dashicons-arrow-left-alt2"></span>
									Previous
								</a>
							<?php endif; ?>
							
							<?php
							// Page numbers
							$start_page = max(1, $data['paged'] - 2);
							$end_page = min($data['total_pages'], $data['paged'] + 2);
							
							if ($start_page > 1): ?>
								<a href="<?php echo esc_url(add_query_arg('paged', 1, $base_url)) ?>" class="aat-page-num">1</a>
								<?php if ($start_page > 2): ?>
									<span class="aat-page-dots">...</span>
								<?php endif; ?>
							<?php endif; ?>
							
							
							<?php for ($i = $start_page; $i <= $end_page; $i++): ?>
								<a href="<?php echo esc_url(add_query_arg('paged', $i, $base_url)) ?>" 
								class="aat-page-num <?php echo $i === $data['paged'] ? 'current' : '' ?>"><?php echo absint($i) ?></a>
							<?php endfor; ?>
							
							
							<?php if ($end_page < $data['total_pages']): ?>
								<?php if ($end_page < $data['total_pages'] - 1): ?>
									<span class="aat-page-dots">...</span>
								<?php endif; ?>
								<a href="<?php echo esc_url(add_query_arg('paged', $data['total_pages'], $base_url)) ?>" class="aat-page-num"><?php echo absint($data['total_pages']) ?></a>
							<?php endif; ?>
							
							<?php
							// Next button
							if ($data['paged'] < $data['total_pages']): ?>
								<a href="<?php echo esc_url(add_query_arg('paged', $data['paged'] + 1, $base_url)) ?>" class="aat-page-btn">
									Next
									<span class="dashicons dashicons-arrow-right-alt2"></span>
								</a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>

				<?php if ($data['view'] === 'grid'): ?>
					<!-- Grid View -->
					<div class="aat-images-grid">
						<?php foreach ($data['images'] as $image): 
							$alt = get_post_meta($image->ID, '_wp_attachment_image_alt', true);
							$thumb_url = wp_get_attachment_image_url($image->ID, 'medium');
							if (!$thumb_url) {
								$thumb_url = wp_get_attachment_url($image->ID);
							}
							$filename = basename(get_attached_file($image->ID));
							$has_alt = !empty($alt);
						?>
							<div class="aat-image-card <?php echo $has_alt ? 'has-alt' : 'missing-alt' ?>">
								<div class="aat-image-preview">
									<img src="<?php echo esc_url($thumb_url) ?>" alt="<?php echo esc_attr($alt ?: 'Image preview') ?>" 
										loading="lazy" onclick="aat_openImageModal(<?php echo absint($image->ID) ?>, '<?php echo esc_js($thumb_url) ?>', '<?php echo esc_js($alt) ?>', '<?php echo esc_js($filename) ?>')" />
									<div class="aat-image-overlay">
										<span class="aat-status-badge <?php echo $has_alt ? 'status-good' : 'status-missing' ?>">
											<?php echo $has_alt ? '✓ Has Alt' : '⚠ Missing Alt' ?>
										</span>
									</div>
								</div>
								
								<div class="aat-image-info">
									<div class="aat-filename" title="<?php echo esc_attr($filename) ?>">
										<?php echo esc_html(strlen($filename) > 25 ? substr($filename, 0, 25) . '...' : $filename) ?>
									</div>
									
									<div class="aat-alt-text">
										<?php if ($has_alt): ?>
											<span class="aat-alt-preview" title="<?php echo esc_attr($alt) ?>">
												<?php echo esc_html($alt) ?>
											</span>
										<?php else: ?>
											<span class="aat-no-alt">No alt text</span>
										<?php endif; ?>
									</div>
									
								<div class="aat-image-actions">
									<button class="aat-btn aat-btn-primary generate-alt" data-id="<?php echo absint($image->ID) ?>">
										<svg class="aat-icon" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
											<path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/>
										</svg>
										<?php echo $has_alt ? 'Regenerate' : 'Generate' ?>
									</button>
										<a href="<?php echo esc_url(get_edit_post_link($image->ID)) ?>" target="_blank" class="aat-btn aat-btn-link">
											<span class="dashicons dashicons-edit"></span>
											Edit
										</a>
										<?php if ($has_alt): ?>
											<button class="aat-btn aat-btn-secondary clear-alt" data-id="<?php echo absint($image->ID) ?>">
												<span class="dashicons dashicons-editor-removeformatting"></span>
												Clear
											</button>
										<?php endif; ?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
				</div>
				
			<?php else: ?>
				<!-- Table View -->
					<div class="aat-table-container">
						<table class="aat-images-table widefat striped">
							<thead>
								<tr>
									<th style="width: 80px;">Preview</th>
									<th>Filename</th>
									<th>Alt Text</th>
									<th style="width: 100px;">Status</th>
									<th style="width: 200px;">Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($data['images'] as $image): 
        $alt = get_post_meta($image->ID, '_wp_attachment_image_alt', true);
        $thumb_url = wp_get_attachment_image_url($image->ID, 'thumbnail');
if (!$thumb_url) {
										$thumb_url = wp_get_attachment_url($image->ID);
									}	
									$filename = basename(get_attached_file($image->ID));
									$has_alt = !empty($alt);
								?>
									<tr class="<?php echo $has_alt ? 'has-alt-row' : 'missing-alt-row' ?>">
										<td>
											<img src="<?php echo esc_url($thumb_url) ?>" alt="<?php echo esc_attr($alt ?: 'Image preview') ?>" 
												class="aat-table-thumb" loading="lazy"
												onclick="aat_openImageModal(<?php echo absint($image->ID) ?>, '<?php echo esc_js($thumb_url) ?>', '<?php echo esc_js($alt) ?>', '<?php echo esc_js($filename) ?>')" />
										</td>
										<td>
											<strong><?php echo esc_html($filename) ?></strong>
											<div class="aat-table-meta">ID: <?php echo absint($image->ID) ?></div>
										</td>
										<td>
											<?php if ($has_alt): ?>
												<span class="aat-table-alt"><?php echo esc_html($alt) ?></span>
											<?php else: ?>
												<span class="aat-table-no-alt">No alt text</span>
											<?php endif; ?>
										</td>
										<td>
											<span class="aat-table-status <?php echo $has_alt ? 'status-good' : 'status-missing' ?>">
												<?php echo $has_alt ? '✓ Has Alt' : '⚠ Missing' ?>
											</span>
										</td>
										<td>
										<div class="aat-table-actions">
											<button class="aat-btn aat-btn-primary generate-alt" data-id="<?php echo absint($image->ID) ?>">
												<svg class="aat-icon" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
													<path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/>
												</svg>
												<?php echo $has_alt ? 'Regenerate' : 'Generate' ?>
											</button>
												<a href="<?php echo esc_url(get_edit_post_link($image->ID)) ?>" target="_blank" class="aat-btn aat-btn-link">
													<span class="dashicons dashicons-edit"></span>
													Edit
												</a>
												<?php if ($has_alt): ?>
													<button class="aat-btn aat-btn-secondary clear-alt" data-id="<?php echo absint($image->ID) ?>">
														<span class="dashicons dashicons-editor-removeformatting"></span>
														Clear
													</button>
												<?php endif; ?>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				
				<!-- Bottom Pagination -->
				<?php if ($data['total_pages'] > 1): ?>
					<div class="aat-pagination aat-pagination-bottom">
						<?php
						// Previous button
						if ($data['paged'] > 1): ?>
							<a href="<?php echo esc_url(add_query_arg('paged', $data['paged'] - 1, $base_url)) ?>" class="aat-page-btn">
								<span class="dashicons dashicons-arrow-left-alt2"></span>
								Previous
							</a>
						<?php endif; ?>
						
						<?php 
						// Page numbers (simplified for bottom)
						for ($i = max(1, $data['paged'] - 2); $i <= min($data['total_pages'], $data['paged'] + 2); $i++): ?>
							<a href="<?php echo esc_url(add_query_arg('paged', $i, $base_url)) ?>" 
							class="aat-page-num <?php echo $i === $data['paged'] ? 'current' : '' ?>"><?php echo absint($i) ?></a>
						<?php endfor; ?>
						
						<?php
						// Next button
						if ($data['paged'] < $data['total_pages']): ?>
							<a href="<?php echo esc_url(add_query_arg('paged', $data['paged'] + 1, $base_url)) ?>" class="aat-page-btn">
								Next
								<span class="dashicons dashicons-arrow-right-alt2"></span>
							</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php endif; ?>
		
		<?php if ($active_tab === 'settings'): ?>
			<!-- Settings Tab Content -->
			<div class="aat-tab-content">
				<div class="aat-controls">
					<div class="aat-settings-section">
						<h3>Plugin Settings</h3>
						<form method="post" action="options.php" class="aat-settings-form">
							<?php settings_fields('aat_settings_group'); ?>
							<div class="aat-settings-row">
								<div class="aat-setting-group">
									<label for="aat_user_email">Your Email (for updates):</label>
									<input type="email" id="aat_user_email" name="aat_user_email" 
										value="<?php echo esc_attr(get_option('aat_user_email')) ?>" 
										class="aat-setting-input" />
									<small class="aat-setting-help">We'll send you important updates about the plugin.</small>
								</div>
								<div class="aat-setting-group">
									<label for="aat_site_id">Site ID:</label>
									<input type="text" id="aat_site_id" name="aat_site_id" 
										value="<?php echo esc_attr(get_option('aat_site_id')) ?>" 
										class="aat-setting-input" readonly />
									<small class="aat-setting-help">Unique identifier for your site (read-only).</small>
								</div>
								<?php 
								// Developer settings are now managed via the Developer Dashboard
								$is_developer = aat_is_developer_environment();
								if ($is_developer): ?>
								<div class="aat-setting-group aat-developer-only">
									<p><strong>Developer Settings</strong></p>
									<p>Pro Mode and Debug Mode are managed separately via the admin dashboard.</p>
									<small class="aat-setting-help">Current Pro Mode: <strong><?php echo aat_is_pro_user() ? 'Enabled' : 'Disabled' ?></strong> | Debug Mode: <strong><?php echo aat_is_debug_mode() ? 'Enabled' : 'Disabled' ?></strong></small>
								</div>
								<?php endif; ?>
							</div>
							<div class="aat-settings-actions">
								<?php submit_button('Save Settings', 'primary', 'submit', false); ?>
							</div>
						</form>
					</div>
					
					<div class="aat-info-section">
						<h3>Plugin Information</h3>
						<div class="aat-info-grid">
							<div class="aat-info-item">
								<strong>Version:</strong> <?php echo esc_html(get_file_data(__FILE__, ['Version' => 'Version'], 'plugin')['Version']) ?>
							</div>
							<div class="aat-info-item">
								<strong>WordPress Version:</strong> <?php echo esc_html(get_bloginfo('version')) ?>
							</div>
							<div class="aat-info-item">
								<strong>Pro Status:</strong> <?php echo aat_is_pro_user() ? 'Active' : 'Free Version' ?>
							</div>
							<div class="aat-info-item">
								<strong>Site URL:</strong> <?php echo esc_url(home_url()) ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<!-- Image Modal -->
	<div id="aat-image-modal" class="aat-modal" style="display: none;">
		<div class="aat-modal-content">
			<span class="aat-modal-close">&times;</span>
			<div class="aat-modal-body">
				<img id="aat-modal-image" src="" alt="" />
				<div class="aat-modal-info">
					<h3 id="aat-modal-filename"></h3>
					<div class="aat-modal-alt">
						<label for="aat-modal-alt-text">Alt Text:</label>
						<div id="aat-modal-alt-text" role="textbox" aria-readonly="true"></div>
					</div>
				</div>
			</div>
		</div>
	</div>

	
	<script>
		// Upgrade popup functionality
		function showUpgradePopup(nextResetDate) {
			// Calculate countdown
			const resetDate = new Date(nextResetDate);
			const now = new Date();
			const timeDiff = resetDate.getTime() - now.getTime();
			
			let countdownText = 'Calculating...';
			if (timeDiff > 0) {
				const days = Math.floor(timeDiff / (1000 * 60 * 60 * 24));
				const hours = Math.floor((timeDiff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
				const minutes = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
				
				if (days > 0) {
					countdownText = `${days} day${days !== 1 ? 's' : ''}, ${hours} hour${hours !== 1 ? 's' : ''}`;
				} else if (hours > 0) {
					countdownText = `${hours} hour${hours !== 1 ? 's' : ''}, ${minutes} minute${minutes !== 1 ? 's' : ''}`;
				} else {
					countdownText = `${minutes} minute${minutes !== 1 ? 's' : ''}`;
				}
			}
			
			const popup = document.createElement('div');
			popup.className = 'aat-upgrade-popup';
			popup.innerHTML = `
				<div class="aat-upgrade-popup-content">
					<h2>🚀 Generation Limit Reached</h2>
					<p>You've used all 15 of your free alt tag generations for this month!</p>
					<div class="aat-countdown">
						⏰ Free generations reset in: <strong>${countdownText}</strong>
					</div>
					<p>Want 100 generations per month? Upgrade to Pro for just <strong>$10/month</strong>!</p>
					<div class="aat-upgrade-buttons">
						<a href="#" class="aat-upgrade-btn primary" onclick="window.open('https://buy.stripe.com/cNidR97Rj7gncfb7isejK00?client_reference_id=<?php echo esc_js(get_option('aat_site_id')) ?>', '_blank')">
							🔥 Upgrade to Pro
						</a>
						<button class="aat-upgrade-btn secondary" onclick="closeUpgradePopup()">
							Maybe Later
						</button>
					</div>
				</div>
			`;
			
			document.body.appendChild(popup);
		}
		
		function closeUpgradePopup() {
			const popup = document.querySelector('.aat-upgrade-popup');
			if (popup) {
				popup.remove();
			}
		}
		
		// Bulk scan functionality
		document.addEventListener('DOMContentLoaded', function() {
			const bulkButton = document.getElementById('aat-bulk-scan-button');
			const bulkResults = document.getElementById('aat-bulk-results');
			
			if (bulkButton) {
				bulkButton.addEventListener('click', function() {
					const originalHtml = bulkButton.innerHTML;
					bulkButton.innerHTML = '<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite;"></span> Processing...';
					bulkButton.disabled = true;
					
					bulkResults.style.display = 'block';
					bulkResults.innerHTML = '<p>Starting bulk generation of alt tags...</p>';
					
					fetch(aat_ajax_object.ajax_url + '?action=aat_scan_and_tag&nonce=' + aat_ajax_object.nonce)
						.then(res => res.json())
						.then(data => {
							// Check if limit reached
							if (data.limit_reached && data.next_reset) {
								bulkButton.innerHTML = originalHtml;
								bulkButton.disabled = false;
								bulkResults.innerHTML = '<p style="color: #d63638;">Generation limit reached for this month.</p>';
								showUpgradePopup(data.next_reset);
								return;
							}
							
							bulkButton.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> Completed!';
							bulkButton.style.background = '#00a32a';
							
							let html = `<p><strong>${data.message}</strong></p>`;
							
							if (data.images && data.images.length) {
								html += `<div style="margin-top: 15px;">
									<h4>Generated Alt Tags:</h4>
									<div style="max-height: 300px; overflow-y: auto;">`;
								
								data.images.forEach(img => {
									html += `<div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding: 8px; background: #fff; border-radius: 4px; border: 1px solid #e0e0e0;">
										<img src="${img.url}" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">
										<div style="flex: 1;">
											<div style="font-weight: 600; font-size: 14px; margin-bottom: 4px;">${img.title || 'Untitled'}</div>
											<div style="font-size: 12px; color: #646970; margin-bottom: 4px;">ID: ${img.id}</div>
											<div style="color: #1d2327; font-size: 13px; line-height: 1.4;">${img.alt}</div>
										</div>
										<div style="margin-left: auto;">
											<a href="${img.edit_url}" target="_blank" style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 3px; font-size: 12px;">
												<span class="dashicons dashicons-edit" style="font-size: 12px; width: 12px; height: 12px;"></span>
												Edit
											</a>
										</div>
									</div>`;
								});
								
								html += `</div></div>`;
							}
							
							html += `<p style="margin-top: 15px;"><a href="${window.location.href}" class="button button-primary">Refresh Page</a></p>`;
							
							bulkResults.innerHTML = html;
							
							// Reset button after delay
							setTimeout(() => {
								bulkButton.innerHTML = originalHtml;
								bulkButton.disabled = false;
								bulkButton.style.background = '';
							}, 3000);
						})
						.catch(error => {
							bulkButton.innerHTML = originalHtml;
							bulkButton.disabled = false;
							bulkResults.innerHTML = '<p style="color: #d63638;">Network error during bulk processing: ' + error.message + '</p>';
						});
				});
			}
		});

		// Enhanced button functionality with better UX
		let activeGenerations = 0;
        document.querySelectorAll('.generate-alt').forEach(button => {
            button.addEventListener('click', () => {
                const id = button.dataset.id;
				const originalHtml = button.innerHTML;
				button.innerHTML = '<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite;"></span> Working...';
				button.disabled = true;
				activeGenerations++;
				
                fetch(aat_ajax_object.ajax_url + '?action=aat_generate_single&image_id=' + id + '&nonce=' + aat_ajax_object.nonce)
                    .then(res => res.json())
					.then(data => {
						activeGenerations--;
						if (data.success) {
							// Show success feedback before reload
							button.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> Generated!';
							button.style.background = '#00a32a';
							// Wait for all active generations to complete before reloading
							if (activeGenerations === 0) {
								setTimeout(() => location.reload(), 1000);
							} else {
								setTimeout(() => location.reload(), 3000); // Longer delay if multiple requests
							}
						} else {
							button.innerHTML = originalHtml;
							button.disabled = false;
							
							// Check if limit reached
							if (data.limit_reached && data.next_reset) {
								showUpgradePopup(data.next_reset);
							} else {
								const errorMsg = data.message || data.error || 'Failed to generate alt text. Please try again.';
								alert('Error: ' + errorMsg);
							}
						}
					})
					.catch(error => {
						activeGenerations--;
						button.innerHTML = originalHtml;
						button.disabled = false;
						// Original error: error.message (usually "Failed to fetch")
						alert('⚠️ Too many requests at once. Please wait a few seconds between generations, or upgrade to Pro for faster processing.');
			});
            });
        });
		
        document.querySelectorAll('.clear-alt').forEach(button => {
		    button.addEventListener('click', () => {
				if (!confirm('Are you sure you want to clear this alt text?')) return;
				
		        const id = button.dataset.id;
				const originalHtml = button.innerHTML;
				button.innerHTML = '<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite;"></span> Clearing...';
				button.disabled = true;
				
		        fetch(aat_ajax_object.ajax_url + '?action=aat_clear_alt&image_id=' + id + '&nonce=' + aat_ajax_object.nonce)
		            .then(res => res.json())
					.then(data => {
						if (data.success) {
							button.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> Cleared!';
							setTimeout(() => location.reload(), 1000);
						} else {
							button.innerHTML = originalHtml;
							button.disabled = false;
							alert('Failed to clear alt text. Please try again.');
						}
					})
					.catch(error => {
						button.innerHTML = originalHtml;
						button.disabled = false;
						alert('Error clearing alt text. Please try again.');
					});
			});
		});

		// Modal functionality
		function aat_openImageModal(id, imageUrl, altText, filename) {
			const modal = document.getElementById('aat-image-modal');
			const modalImage = document.getElementById('aat-modal-image');
			const modalFilename = document.getElementById('aat-modal-filename');
			const modalAltText = document.getElementById('aat-modal-alt-text');
			
			modalImage.src = imageUrl;
			modalFilename.textContent = filename;
			modalAltText.textContent = altText || 'No alt text available';
			modalAltText.style.color = altText ? '#646970' : '#d63638';
			
			modal.style.display = 'block';
			document.body.style.overflow = 'hidden'; // Prevent background scrolling
		}
		
		// Close modal functionality
		document.addEventListener('DOMContentLoaded', function() {
			const modal = document.getElementById('aat-image-modal');
			const closeBtn = document.querySelector('.aat-modal-close');
			
			// Close on X button
			closeBtn.addEventListener('click', function() {
				modal.style.display = 'none';
				document.body.style.overflow = 'auto';
			});
			
			// Close on background click
			modal.addEventListener('click', function(e) {
				if (e.target === modal) {
					modal.style.display = 'none';
					document.body.style.overflow = 'auto';
				}
			});
			
			// Close on Escape key
			document.addEventListener('keydown', function(e) {
				if (e.key === 'Escape' && modal.style.display === 'block') {
					modal.style.display = 'none';
					document.body.style.overflow = 'auto';
				}
		    });
		});

		// Developer authentication and analytics
		document.addEventListener('DOMContentLoaded', function() {
			const devAnalytics = document.getElementById('aat-dev-analytics');
			const devAuthCard = document.getElementById('aat-dev-auth-card');
			
			// Always show auth card if not authenticated (no domain restrictions in plugin)
			if (devAuthCard && !devAnalytics) {
				devAuthCard.style.display = 'block';
				devAuthCard.addEventListener('click', showDeveloperAuth);
			}
			
			// Developer analytics now handled via separate secure dashboard
		});
		
		function showDeveloperAuth() {
			const siteId = '<?php echo esc_js(get_option('aat_site_id')) ?>';
			const authKey = prompt('Enter developer authentication key:');
			
			if (!authKey) return;
			
			fetch('<?php echo esc_js(home_url()) ?>/api/hs-auto-alt-text-generator-for-seo/dev-auth.php', {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: `action=authenticate&site_id=${siteId}&auth_key=${authKey}`
			})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					alert('Developer authenticated! Click OK to continue.');
					location.reload();
				} else {
					alert('Authentication failed: ' + data.error);
				}
			})
			.catch(error => {
				alert('Authentication error: ' + error.message);
			});
		}
		
		// Developer dashboard is now handled via separate secure page

		// Spinning animation is now in CSS file
    </script>
    <?php
} // End of aat_viewer_page function

add_action('wp_ajax_aat_generate_single', 'aat_generate_single');

/**
 * AJAX handler for generating alt text for a single image
 *
 * @since 0.1.0
 * @return void
 */
function aat_generate_single(): void {
    // Verify nonce for security
    $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'aat_ajax_nonce')) {
        wp_send_json_error(__('Security check failed', 'hs-auto-image-alt-text-generator-for-seo'));
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'hs-auto-image-alt-text-generator-for-seo'));
        return;
    }
    
    // Check rate limiting
    if (!aat_check_rate_limit('generate_single')) {
        wp_send_json_error(__('Too many requests. Please wait a moment and try again.', 'hs-auto-image-alt-text-generator-for-seo'));
        return;
    }
    
    // Check if user can generate
    if (!aat_can_generate_free()) {
        $next_reset = aat_get_next_reset_date();
        $limits = aat_get_user_limits();
        $is_pro = $limits['is_pro'];
        
        if ($is_pro) {
            /* translators: %d: number of generations per month */
            $message = sprintf(__('Monthly Pro generation limit reached (%d/month). Your limit resets next billing cycle.', 'hs-auto-image-alt-text-generator-for-seo'), $limits['current_limit']);
            wp_send_json([
                'success' => false,
                'message' => $message,
                'limit_reached' => true,
                'next_reset' => $next_reset,
                'remaining' => 0,
                'is_pro_limit' => true
            ]);
        } else {
            wp_send_json([
                'success' => false,
                'message' => __('Monthly generation limit reached. Upgrade to Pro for 100 generations per month.', 'hs-auto-image-alt-text-generator-for-seo'),
                'limit_reached' => true,
                'next_reset' => $next_reset,
                'remaining' => 0
            ]);
        }
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above
    $image_id = isset($_GET['image_id']) ? absint($_GET['image_id']) : 0;
    
    // Validate image ID
    if (!$image_id || !wp_attachment_is_image($image_id)) {
        wp_send_json_error(__('Invalid image ID', 'hs-auto-image-alt-text-generator-for-seo'));
        return;
    }
    
    $url = wp_get_attachment_url($image_id);
    if (!$url) {
        wp_send_json_error(__('Could not get image URL', 'hs-auto-image-alt-text-generator-for-seo'));
        return;
    }
    
    $filename = basename($url);
    
    // Ensure site is registered before generating (fixes "Database validation failed" errors)
    $site_id = get_option('aat_site_id');
    if (!$site_id) {
        wp_send_json([
            'success' => false,
            'error' => 'Plugin not properly configured. Please try deactivating and reactivating the plugin.',
            'remaining' => 0
        ]);
        return;
    }

    $response = wp_remote_post('https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/generate-alt-tag.php', [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'image_url' => $url,
            'filename' => $filename,
            'site_id' => get_option('aat_site_id'),
        ]),
        'timeout' => 30,
    ]);

    // Check for WordPress HTTP errors (connection issues, timeouts, etc.)
    if (is_wp_error($response)) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for troubleshooting API issues
        error_log('AAT API Error: ' . $response->get_error_message());
        wp_send_json([
            'success' => false, 
            'error' => 'Connection error: ' . $response->get_error_message(),
            'remaining' => aat_get_remaining_free_generations()
        ]);
        return;
    }

    // Get response code and body
    $response_code = wp_remote_retrieve_response_code($response);
    $body_raw = wp_remote_retrieve_body($response);
    $body = json_decode($body_raw, true);

    // Check for API errors
    if ($response_code !== 200) {
        $error_message = $body['error'] ?? 'Unknown API error';
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for troubleshooting API issues
        error_log('AAT API returned error: ' . $error_message);
        
        // Special handling for "Site not registered" errors
        if ($response_code === 403 && (strpos($error_message, 'Site not registered') !== false || strpos($error_message, 'Invalid site') !== false)) {
            // Try to re-register the site
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for troubleshooting re-registration
            error_log('AAT: Attempting automatic re-registration');
            $site_id_to_register = get_option('aat_site_id');
            if ($site_id_to_register) {
                aat_register_site_with_server($site_id_to_register);
            }
            
            $error_message = 'Site registration issue detected. We attempted to fix it. Please try again in a few seconds, or deactivate and reactivate the plugin if the issue persists.';
        }
        
        wp_send_json([
            'success' => false, 
            'error' => $error_message,
            'remaining' => aat_get_remaining_free_generations()
        ]);
        return;
    }

    $alt_text = trim($body['alt_text'] ?? '');

    if ($alt_text !== '') {
        update_post_meta($image_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        
        // Track usage and clear cache
        aat_increment_monthly_usage($url);
        
        wp_send_json([
            'success' => true, 
            'alt' => $alt_text,
            'remaining' => aat_get_remaining_free_generations()
        ]);
    } else {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for troubleshooting empty responses
        error_log('AAT: No alt text in response');
        wp_send_json([
            'success' => false, 
            'error' => 'No alt text generated',
            'remaining' => aat_get_remaining_free_generations()
        ]);
    }
}

add_action('wp_ajax_aat_clear_alt', 'aat_clear_alt');

/**
 * AJAX handler for clearing alt text from an image
 *
 * @since 0.1.0
 * @return void
 */
function aat_clear_alt(): void {
    // Verify nonce for security
    $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'aat_ajax_nonce')) {
        wp_send_json_error(__('Security check failed', 'hs-auto-image-alt-text-generator-for-seo'));
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'hs-auto-image-alt-text-generator-for-seo'));
        return;
    }
    
    // Check rate limiting
    if (!aat_check_rate_limit('clear_alt')) {
        wp_send_json_error(__('Too many requests. Please wait a moment and try again.', 'hs-auto-image-alt-text-generator-for-seo'));
        return;
    }
    
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above
    $image_id = isset($_GET['image_id']) ? absint($_GET['image_id']) : 0;
    
    // Validate image ID
    if (!$image_id || !wp_attachment_is_image($image_id)) {
        wp_send_json_error(__('Invalid image ID', 'hs-auto-image-alt-text-generator-for-seo'));
        return;
    }
    
    $image_url = wp_get_attachment_url($image_id);
    
    // Clear the alt text from WordPress
    delete_post_meta($image_id, '_wp_attachment_image_alt');
    
    // Also clear the usage record from the server
    if ($image_url) {
        $response = wp_remote_post('https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/clear-alt-usage.php', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'image_url' => $image_url,
                'site_id' => get_option('aat_site_id'),
            ]),
            'timeout' => 15,
        ]);
        
        // Clear usage tracking via API (non-blocking)
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
        }
    }
    
    wp_send_json(['success' => true]);
}
