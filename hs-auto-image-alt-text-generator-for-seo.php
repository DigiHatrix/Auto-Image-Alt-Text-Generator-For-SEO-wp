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
 * Description: Automatically generate and apply alt text to images using AI.
 * Version:     1.2.0
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
define('AAT_VERSION', '1.2.0');
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
 * Fetch plugin configuration from central API
 * This allows changing pricing/limits without plugin updates
 * 
 * @since 1.1.1
 * @return array Configuration array with limits, pricing, URLs, etc.
 */
function aat_get_plugin_config() {
    // Allow bypassing cache for testing (add ?aat_refresh_cache=1 to any admin page)
    // phpcs:disable WordPress.Security.NonceVerification -- Cache refresh is non-destructive read-only operation
    $bypass_cache = isset($_GET['aat_refresh_cache']) || isset($_POST['aat_refresh_cache']);
    // phpcs:enable WordPress.Security.NonceVerification
    
    // For testing: Set to true to disable caching completely (instant updates)
    $disable_cache = defined('AAT_DISABLE_CONFIG_CACHE') && AAT_DISABLE_CONFIG_CACHE;
    
    // Check cache first (1 hour TTL) unless bypassing or cache disabled
    if (!$bypass_cache && !$disable_cache) {
        $cached_config = get_transient('aat_plugin_config');
        if ($cached_config !== false) {
            return $cached_config;
        }
    }
    
    // Fetch from API
    $api_url = 'https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/get-plugin-config.php';
    $response = wp_remote_get($api_url, [
        'timeout' => 5,
        'sslverify' => true,
    ]);
    
    // Minimal fallback if API completely fails - use sensible defaults so plugin still works
    $error_fallback = [
        'limits' => [
            'free_monthly' => 10,
            'pro_monthly' => 50
        ],
        'pricing' => [
            'pro_monthly_price' => 10.00,
            'generation_pack_price' => 5.00,
            'generation_pack_size' => 20,
            'currency' => 'USD'
        ],
        'stripe' => [
            'checkout_url' => '',
            'customer_portal_url' => ''
        ],
        'messages' => [
            'upgrade_prompt' => 'Service temporarily unavailable',
            'limit_reached' => 'Service temporarily unavailable',
            'bulk_action_locked' => 'Service temporarily unavailable'
        ],
        'error' => true
    ];
    
    if (is_wp_error($response)) {
        // Cache error state for 1 minute only (retry sooner), unless cache disabled
        if (!$disable_cache) {
            set_transient('aat_plugin_config', $error_fallback, 60);
        }
        return $error_fallback;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (!$data || !isset($data['success']) || !$data['success'] || !isset($data['config'])) {
        // Cache error state for 1 minute only (retry sooner), unless cache disabled
        if (!$disable_cache) {
            set_transient('aat_plugin_config', $error_fallback, 60);
        }
        return $error_fallback;
    }
    
    $config = $data['config'];
    
    // Cache for 1 hour (or use TTL from API), unless cache disabled
    if (!$disable_cache) {
        $cache_ttl = isset($config['cache_ttl']) ? intval($config['cache_ttl']) : 3600;
        set_transient('aat_plugin_config', $config, $cache_ttl);
    }
    
    return $config;
}

/**
 * Get Stripe checkout URL with site_id
 * 
 * @since 1.1.1
 * @return string Stripe checkout URL
 */
function aat_get_stripe_checkout_url() {
    $config = aat_get_plugin_config();
    $base_url = $config['stripe']['checkout_url'] ?? '';
    
    if (empty($base_url)) {
        return '#'; // Return # if no URL configured (prevents broken links)
    }
    
    $site_id = get_option('aat_site_id');
    return $base_url . '?client_reference_id=' . urlencode($site_id);
}

/**
 * Get Stripe checkout URL for generation packs with site_id
 * 
 * @since 1.2.0
 * @return string Stripe checkout URL for generation packs
 */
function aat_get_generation_pack_checkout_url() {
    $config = aat_get_plugin_config();
    $base_url = $config['stripe']['checkout_url_generation_pack'] ?? '';
    
    if (empty($base_url)) {
        return '#'; // Return # if no URL configured (prevents broken links)
    }
    
    $site_id = get_option('aat_site_id');
    
    // Append client_reference_id as URL parameter for Stripe Payment Links
    // Stripe Payment Links support client_reference_id as a query parameter
    $separator = strpos($base_url, '?') !== false ? '&' : '?';
    return $base_url . $separator . 'client_reference_id=' . urlencode($site_id);
}

/**
 * Get Stripe customer portal URL
 * 
 * @since 1.1.1
 * @return string Stripe customer portal URL
 */
function aat_get_stripe_portal_url() {
    $config = aat_get_plugin_config();
    $portal_url = $config['stripe']['customer_portal_url'] ?? '';
    return !empty($portal_url) ? $portal_url : '#';
}

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
 * Note: track-event.php automatically updates plugin_sites table too
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
    
    // Send deactivation event (also updates plugin_sites table)
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
    // 1. External service info (on first activation)
    // 2. Low credits (most urgent - user can't generate)
    // 3. Welcome (important for new users)
    // 4. Feedback (least urgent)
    
    // Show external service info notice first
    if (aat_show_external_service_notice()) {
        return; // External service notice shown, don't show others
    }
    
    if (aat_show_low_credits_notice()) {
        return; // Low credits shown, don't show others
    }
    
    if (aat_show_welcome_notice()) {
        return; // Welcome shown, don't show feedback
    }
    
    aat_show_feedback_notice();
}

/**
 * Show external service notice (informational, non-blocking)
 *
 * @since 1.1.1
 * @return bool True if notice was shown, false otherwise
 */
function aat_show_external_service_notice() {
    // Don't show if already dismissed
    if (get_option('aat_external_service_notice_dismissed')) {
        return false;
    }
    
    // Only show once after first activation
    $activation_time = get_option('aat_activation_time');
    if (!$activation_time) {
        update_option('aat_activation_time', time());
    }
    
    $dismiss_url = wp_nonce_url(
        add_query_arg('aat_dismiss_notice', 'external_service'),
        'aat_dismiss_notice',
        'aat_notice_nonce'
    );
    
    $plugin_page_url = admin_url('admin.php?page=hs-auto-image-alt-text-generator-for-seo');
    $readme_url = 'https://wordpress.org/plugins/auto-image-alt-text-generator-for-seo/#description';
    
    ?>
    <div class="notice notice-info is-dismissible" id="aat-external-service-notice">
        <h3 style="margin: 10px 0;">
            <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
            <?php echo esc_html__('Auto Image Alt Text Generator Uses External Services', 'hs-auto-image-alt-text-generator-for-seo'); ?>
        </h3>
        <p>
            <?php echo esc_html__('This plugin connects to external APIs to provide AI-powered alt text generation. Your site URL, WordPress version, and image URLs are sent to our servers for processing.', 'hs-auto-image-alt-text-generator-for-seo'); ?>
        </p>
        <p>
            <strong><?php echo esc_html__('What\'s sent:', 'hs-auto-image-alt-text-generator-for-seo'); ?></strong>
            <?php echo esc_html__('Site URL, WordPress/plugin versions, image URLs (when generating), usage statistics', 'hs-auto-image-alt-text-generator-for-seo'); ?>
        </p>
        <p>
            <a href="<?php echo esc_url($plugin_page_url); ?>" class="button button-primary">
                <?php echo esc_html__('Go to Plugin Dashboard', 'hs-auto-image-alt-text-generator-for-seo'); ?>
            </a>
            <a href="<?php echo esc_url($readme_url); ?>" target="_blank" class="button button-secondary">
                <?php echo esc_html__('Learn More About Data Collection', 'hs-auto-image-alt-text-generator-for-seo'); ?>
            </a>
            <a href="https://hatrixsolutions.com/privacy" target="_blank" class="button button-secondary">
                <?php echo esc_html__('Privacy Policy', 'hs-auto-image-alt-text-generator-for-seo'); ?>
            </a>
            <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-link">
                <?php echo esc_html__('Dismiss', 'hs-auto-image-alt-text-generator-for-seo'); ?>
            </a>
        </p>
    </div>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#aat-external-service-notice').on('click', '.notice-dismiss', function() {
            $.post(ajaxurl, {
                action: 'aat_dismiss_notice',
                notice_type: 'external_service',
                nonce: '<?php echo esc_js(wp_create_nonce('aat_dismiss_notice')); ?>'
            });
        });
    });
    </script>
    <?php
    return true;
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
    
    $plugin_page_url = admin_url('admin.php?page=hs-auto-image-alt-text-generator-for-seo');
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
    if (!$usage || $usage < 5) {
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
    return aat_get_stripe_checkout_url();
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
    $config = aat_get_plugin_config();
    $free_limit = $config['limits']['free_monthly'] ?? 5;
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
                <strong><?php echo esc_html__('You\'ve used all 5 free alt text generations this month.', 'hs-auto-image-alt-text-generator-for-seo'); ?></strong>
            </p>
            <p>
                <?php 
                $config = aat_get_plugin_config();
                $pro_limit = $config['limits']['pro_monthly'] ?? 50;
                /* translators: %d: Number of generations per month */
                echo esc_html(sprintf(__('Upgrade to Pro to get %d generations per month and unlock bulk generation features.', 'hs-auto-image-alt-text-generator-for-seo'), $pro_limit)); 
                ?>
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
                <?php 
                $config = aat_get_plugin_config();
                $pro_limit = $config['limits']['pro_monthly'] ?? 50;
                /* translators: %d: Number of generations per month */
                echo esc_html(sprintf(__('Upgrade to Pro for %d generations per month, bulk processing, and priority support.', 'hs-auto-image-alt-text-generator-for-seo'), $pro_limit)); 
                ?>
            </p>
            <p>
                <?php
                $config = aat_get_plugin_config();
                $pro_price = floatval($config['pricing']['pro_monthly_price'] ?? 10.00);
                $price_display = '$' . number_format($pro_price, 0);
                ?>
                <a href="<?php echo esc_url($upgrade_url); ?>" class="button button-primary" target="_blank">
                    <?php 
                    /* translators: %s: Price (e.g., $10) */
                    echo esc_html(sprintf(__('Upgrade to Pro - Only %s/month', 'hs-auto-image-alt-text-generator-for-seo'), $price_display)); 
                    ?>
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
    
    if ($notice_type === 'external_service') {
        update_option('aat_external_service_notice_dismissed', true);
    } elseif ($notice_type === 'welcome') {
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
    if ($notice_type === 'external_service') {
        update_option('aat_external_service_notice_dismissed', true);
        wp_send_json_success(['message' => 'External service notice dismissed']);
    } elseif ($notice_type === 'welcome') {
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
 * Purpose: Keeps plugin version and WordPress version updated for compatibility support
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
        'hs-auto-image-alt-text-generator-for-seo',
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
    if ($hook !== 'toplevel_page_hs-auto-image-alt-text-generator-for-seo') {
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
    
    // Handle email update via API when settings are saved
    // Use both hooks to catch Settings API saves and direct option updates
    add_action('update_option_aat_user_email', 'aat_update_email_on_server', 10, 2);
    add_action('admin_init', 'aat_maybe_update_email_on_save', 20); // Run after Settings API processes
}

/**
 * Check if email was updated via Settings API and sync to server
 * 
 * @since 1.1.1
 * @return void
 */
function aat_maybe_update_email_on_save() {
    // Only run on our settings page
    $current_screen = get_current_screen();
    if (!$current_screen || strpos($current_screen->id, 'hs-auto-image-alt-text-generator-for-seo') === false) {
        return;
    }
    
    // Verify nonce for security (Settings API nonce verification)
    if (!check_admin_referer('aat_settings_group-options')) {
        return;
    }
    
    // Check if settings form was submitted
    if (!isset($_POST['option_page']) || $_POST['option_page'] !== 'aat_settings_group') {
        return;
    }
    
    // Check if email field was submitted
    if (!isset($_POST['aat_user_email'])) {
        return;
    }
    
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below
    $new_email = sanitize_email(wp_unslash($_POST['aat_user_email']));
    if (empty($new_email)) {
        return;
    }
    
    // Get current saved value
    $old_email = get_option('aat_user_email', '');
    
    // Only update if different
    if ($new_email !== $old_email) {
        aat_update_email_on_server($old_email, $new_email);
    }
}

/**
 * Update email on server when changed in settings
 *
 * @since 1.1.1
 * @param string $old_value Old email value
 * @param string $new_value New email value
 * @return void
 */
function aat_update_email_on_server($old_value, $new_value) {
    // Only update if email actually changed and is not empty
    if ($new_value === $old_value || empty($new_value)) {
        return;
    }
    
    $site_id = get_option('aat_site_id');
    if (!$site_id) {
        return;
    }
    
    // Update email on server via dev-settings.php
    // Use blocking request to ensure it completes (since user just clicked save)
    $api_url = 'https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/dev-settings.php';
    $response = wp_remote_post($api_url, [
        'body' => [
            'action' => 'update_email',
            'site_id' => $site_id,
            'email' => sanitize_email($new_value)
        ],
        'timeout' => 5,
        'blocking' => true // Blocking to ensure it saves
    ]);
    
    // Email update response is handled silently - errors are non-critical
}

function aat_is_pro_user() {
    // First check local WordPress option
    $local_setting = get_option('aat_is_pro');
    if ($local_setting === 'yes') {
        return true;
    }
    
    // Then check centralized pro status from hs_aat_plugin_sites
    $central_pro_status = aat_get_central_pro_status();
    // Only 'pro' tier is considered pro user (removed Basic - now using generation packs)
    if ($central_pro_status === 'pro') {
        return true;
    }
    
    return false;
}

/**
 * Check if user has Pro tier
 * Used for features that are Pro-only
 *
 * @since 1.1.1
 * @return bool True if user has Pro tier, false otherwise
 */
function aat_is_pro_tier(): bool {
    // First check local WordPress option
    $local_setting = get_option('aat_is_pro');
    if ($local_setting === 'yes') {
        return true;
    }
    
    // Then check centralized pro status - must be exactly 'pro'
    $central_pro_status = aat_get_central_pro_status();
    return $central_pro_status === 'pro';
}

/**
 * Get centralized tier status from API
 *
 * @since 0.1.0
 * @return string 'free' or 'pro' (removed 'basic' - now using generation packs)
 */
function aat_get_central_pro_status(): string {
    $cache_key = 'aat_pro_status_' . get_option('aat_site_id');
    
    // Allow bypassing cache for testing (add ?aat_refresh_cache=1 to any admin page)
    // phpcs:disable WordPress.Security.NonceVerification -- Cache refresh is non-destructive read-only operation
    $bypass_cache = isset($_GET['aat_refresh_cache']) || isset($_POST['aat_refresh_cache']);
    // phpcs:enable WordPress.Security.NonceVerification
    
    // Short cache time for better UX after upgrades (customers expect instant results after payment)
    $cache_time = 5; // 5 seconds - fast enough for good UX, still reduces API calls
    
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
    
    // For Pro users, use pro_subscription_start_date if available; otherwise use created_at
    $pro_status = $body['site_info']['pro_status'] ?? 'free';
    $is_pro = ($pro_status === 'pro');
    
    if ($is_pro && !empty($body['site_info']['pro_subscription_start_date'])) {
        $billing_date = $body['site_info']['pro_subscription_start_date'];
    } else {
        $billing_date = $body['site_info']['created_at'];
    }
    
    if (!$billing_date) {
        return null;
    }
    
    $billing_timestamp = strtotime($billing_date);
    $billing_day = intval(gmdate('d', $billing_timestamp));
    
    // Handle edge cases for months with fewer days
    // If signup was on 29th, 30th, or 31st, use the last day of shorter months
    $current_time = time();
    $current_year = gmdate('Y', $current_time);
    $current_month = gmdate('m', $current_time);
    $current_day = gmdate('d', $current_time);
    
    // Get the last day of current month
    $last_day_of_month = gmdate('t', mktime(0, 0, 0, $current_month, 1, $current_year));
    
    // Use billing day (subscription start for Pro, signup for Free), but cap it at the last day of current month
    $reset_day = min($billing_day, $last_day_of_month);
    
    // Calculate current billing cycle start
    if ($current_day >= $reset_day) {
        // We're in the current month's cycle
        $cycle_start = gmdate('Y-m-d H:i:s', mktime(0, 0, 0, $current_month, $reset_day, $current_year));
        
        // Calculate next month's reset day
        $next_month = ($current_month == 12) ? 1 : $current_month + 1;
        $next_year = ($current_month == 12) ? $current_year + 1 : $current_year;
            $last_day_next_month = gmdate('t', mktime(0, 0, 0, $next_month, 1, $next_year));
            $next_reset_day = min($billing_day, $last_day_next_month);
        
        $cycle_end = gmdate('Y-m-d H:i:s', mktime(23, 59, 59, $next_month, $next_reset_day - 1, $next_year));
        $next_reset = gmdate('Y-m-d H:i:s', mktime(0, 0, 0, $next_month, $next_reset_day, $next_year));
    } else {
        // We're still in the previous month's cycle
        $prev_month = ($current_month == 1) ? 12 : $current_month - 1;
        $prev_year = ($current_month == 1) ? $current_year - 1 : $current_year;
            $last_day_prev_month = gmdate('t', mktime(0, 0, 0, $prev_month, 1, $prev_year));
            $prev_reset_day = min($billing_day, $last_day_prev_month);
        
        $cycle_start = gmdate('Y-m-d H:i:s', mktime(0, 0, 0, $prev_month, $prev_reset_day, $prev_year));
        $cycle_end = gmdate('Y-m-d H:i:s', mktime(23, 59, 59, $current_month, $reset_day - 1, $current_year));
        $next_reset = gmdate('Y-m-d H:i:s', mktime(0, 0, 0, $current_month, $reset_day, $current_year));
    }
    
    $billing_data = [
        'start' => $cycle_start,
        'end' => $cycle_end,
        'reset_day' => $reset_day,
        'billing_date' => $billing_date,
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
    $paid_generations = isset($body['paid_generations']) ? intval($body['paid_generations']) : 0;
    
    // Cache both values
    set_transient($cache_key, $usage_count, 5 * MINUTE_IN_SECONDS);
    set_transient($cache_key . '_paid', $paid_generations, 5 * MINUTE_IN_SECONDS);
    
    return $usage_count;
}

/**
 * Get paid generations available for the site
 *
 * @since 1.2.0
 * @return int Number of paid generations available
 */
function aat_get_paid_generations() {
    $site_id = get_option('aat_site_id');
    if (!$site_id) {
        return 0;
    }
    
    // Allow bypassing cache for testing (add ?aat_refresh_cache=1 to any admin page)
    // phpcs:disable WordPress.Security.NonceVerification -- Cache refresh is non-destructive read-only operation
    $bypass_cache = isset($_GET['aat_refresh_cache']) || isset($_POST['aat_refresh_cache']);
    // phpcs:enable WordPress.Security.NonceVerification
    
    // Check cache first (unless bypassing)
    $cache_key = 'aat_monthly_usage_' . $site_id . '_paid';
    $cached_paid = $bypass_cache ? false : get_transient($cache_key);
    
    if ($cached_paid !== false) {
        return intval($cached_paid);
    }
    
    // Make API call to get usage (includes paid_generations)
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
    
    $paid_generations = isset($body['paid_generations']) ? intval($body['paid_generations']) : 0;
    
    // Cache for 5 minutes
    set_transient($cache_key, $paid_generations, 5 * MINUTE_IN_SECONDS);
    
    return $paid_generations;
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
    
    // Check monthly quota first
    if ($monthly_usage < $limits['current_limit']) {
        return true; // Has monthly quota available
    }
    
    // If monthly quota exhausted, check paid generations
    $paid_generations = aat_get_paid_generations();
    return $paid_generations > 0;
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
        $config = aat_get_plugin_config();
        $free_limit = $config['limits']['free_monthly'] ?? 10;
        $pro_limit = $config['limits']['pro_monthly'] ?? 50;
        
        $site_id = get_option('aat_site_id');
        if (!$site_id) {
            // Fallback to free limits if no site ID
            $cached_limits = [
                'current_limit' => $free_limit,
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
            $response_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if ($response_code === 200 && isset($body['success']) && $body['success'] && isset($body['limits'])) {
                $cached_limits = $body['limits'];
            } else {
                // API call succeeded but returned error - fallback to free limits
                $cached_limits = [
                    'current_limit' => $free_limit,
                    'plan' => 'free',
                    'is_pro' => false
                ];
            }
        } else {
            // API call failed - fallback to free limits
            $cached_limits = [
                'current_limit' => $free_limit,
                'plan' => 'free',
                'is_pro' => false
            ];
        }
    }
    
    return $cached_limits;
}

// Future: Get user's plan limits
function aat_get_plan_limits() {
    $config = aat_get_plugin_config();
    $plan = get_option('aat_plan', 'free');
    
    $limits = [
        'free' => $config['limits']['free_monthly'] ?? 10,
        'pro' => $config['limits']['pro_monthly'] ?? 50,
        'agency' => -1     // Future feature (unlimited)
    ];
    
    return $limits[$plan] ?? ($config['limits']['free_monthly'] ?? 5);
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
    
    // Check if user has available generations (quota-based, not tier-based)
    // This allows free users to use bulk generation within their quota limits
    
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
                /* translators: %d: Number of generations per month */
                'message' => sprintf(__('Monthly generation limit reached. Upgrade to Pro for %d generations per month.', 'hs-auto-image-alt-text-generator-for-seo'), aat_get_plugin_config()['limits']['pro_monthly'] ?? 50),
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
	$settings_link = '<a href="admin.php?page=hs-auto-image-alt-text-generator-for-seo">Settings</a>';
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
	$count_args = [
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'posts_per_page' => -1,
        'post_status' => 'inherit',
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Necessary for filtering images by alt text status
        'meta_query' => $meta_query,
		'fields' => 'ids',
	];
	
	if ($search) {
		$count_args['s'] = $search;
	}
	
	$all_image_ids = get_posts($count_args);
	$total_images = count($all_image_ids);
	
	// Count missing alt text from all images
	$missing_alt_count = 0;
	foreach ($all_image_ids as $image_id) {
		$alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
		if (empty($alt)) {
			$missing_alt_count++;
		}
	}

	// Now get paginated results
	$query_args = [
		'post_type' => 'attachment',
		'post_mime_type' => 'image',
		'posts_per_page' => $per_page,
		'paged' => $paged,
		'post_status' => 'inherit',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Necessary for filtering images by alt text status
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
			Alt Text Viewer
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
	$paid_generations = aat_get_paid_generations();
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
			<div class="aat-stat-label">With Alt Text</div>
		</div>
		<div class="aat-stat-card aat-stat-warning">
			<div class="aat-stat-number"><?php echo absint($data['missing_alt_count']) ?></div>
			<div class="aat-stat-label">Missing Alt Text</div>
		</div>
		
		<!-- Free/Pro Monthly Generations Card -->
		<div class="aat-stat-card aat-stat-info">
			<?php 
			$limits = aat_get_user_limits();
			?>
			<div class="aat-stat-number"><?php echo absint($remaining_generations) ?></div>
			<div class="aat-stat-label">
				<?php echo $is_pro ? 'Pro' : 'Free' ?> Generations Left
				<button type="button" id="aat-refresh-status" class="button button-small" style="margin-left: 8px; padding: 0 6px; font-size: 11px; height: 20px; line-height: 18px; vertical-align: middle; display: inline-flex; align-items: center; justify-content: center;" title="Refresh status (useful after upgrading)">
					<span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; line-height: 1; vertical-align: middle;"></span>
				</button>
			</div>
			<div class="aat-stat-note"><?php echo absint($limits['current_limit']) ?> per month</div>
			<?php if (!$is_pro): ?>
			<?php
			$config = aat_get_plugin_config();
			$pro_limit = $config['limits']['pro_monthly'] ?? 50;
			$pro_price = floatval($config['pricing']['pro_monthly_price'] ?? 10.00);
			$price_display = '$' . number_format($pro_price, 0);
			?>
			<div class="aat-upgrade-text" style="margin-top: 8px; padding: 6px 8px; background: rgba(34, 113, 177, 0.1); border-radius: 4px; border-left: 3px solid #2271b1; transition: all 0.2s ease;">
				<span style="color: #2271b1; font-size: 11px; font-weight: 500; display: block; line-height: 1.4;">
					🚀 <span style="text-decoration: underline; cursor: pointer;" onclick="window.open('<?php echo esc_js(aat_get_stripe_checkout_url()) ?>', '_blank')"><?php 
					/* translators: %1$s: Price (e.g., $10), %2$d: Number of generations per month */
					echo esc_html(sprintf(__('Upgrade to Pro (%1$s/month) for %2$d Generations per Month', 'hs-auto-image-alt-text-generator-for-seo'), $price_display, $pro_limit)); 
					?></span>
				</span>
			</div>
			<?php endif; ?>
		</div>
		
		<!-- Paid Generations Card -->
		<div class="aat-stat-card aat-stat-info">
			<?php
			$config = aat_get_plugin_config();
			$gen_pack_price = floatval($config['pricing']['generation_pack_price'] ?? 5.00);
			$gen_pack_price_display = '$' . number_format($gen_pack_price, 0);
			$gen_pack_url = aat_get_generation_pack_checkout_url();
			?>
			<div class="aat-stat-number"><?php echo absint($paid_generations) ?></div>
			<div class="aat-stat-label">
				Paid Generations Remaining
				<button type="button" id="aat-refresh-paid-generations" class="button button-small" style="margin-left: 8px; padding: 0 6px; font-size: 11px; height: 20px; line-height: 18px; vertical-align: middle; display: inline-flex; align-items: center; justify-content: center;" title="Refresh paid generations (useful after purchasing a pack)">
					<span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; line-height: 1; vertical-align: middle;"></span>
				</button>
			</div>
			<div class="aat-stat-note">From Generation Packs</div>
			<?php if ($gen_pack_url): ?>
			<div class="aat-upgrade-text" style="margin-top: 8px; padding: 6px 8px; background: rgba(34, 113, 177, 0.1); border-radius: 4px; border-left: 3px solid #2271b1; transition: all 0.2s ease;">
				<span style="color: #2271b1; font-size: 11px; font-weight: 500; display: block; line-height: 1.4;">
					💎 <span style="text-decoration: underline; cursor: pointer;" onclick="window.open('<?php echo esc_js($gen_pack_url) ?>', '_blank')"><?php 
					/* translators: %s: Price (e.g., $5) */
					echo esc_html(sprintf(__('Buy Generation Pack (%s for 20 generations)', 'hs-auto-image-alt-text-generator-for-seo'), $gen_pack_price_display)); 
					?></span>
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
	<div class="aat-bulk-section">
		<h3>Bulk Actions</h3>
		<div class="aat-bulk-controls">
			<button type="button" id="aat-bulk-scan-button" class="button button-primary" style="display: flex; align-items: center; gap: 5px;">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink: 0; width: 16px; height: 16px;">
					<path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z" fill="currentColor"/>
				</svg>
				Bulk Generate Missing Alt Text
			</button>
			<span class="aat-bulk-info">Generate alt text for all images missing them</span>
		</div>
		<p class="aat-bulk-note"><strong>Note:</strong> This will use your available AI generations. Free users can generate up to 10 images per month. Upgrade to Pro for 50 generations per month.</p>
		<div id="aat-bulk-results" class="aat-bulk-results"></div>
	</div>
	<?php
}

// Helper function to render filters and search form
function aat_render_filters_form($data) {
	?>
	<!-- Filters and Search -->
	<form method="get" class="aat-filter-form">
		<input type="hidden" name="page" value="hs-auto-image-alt-text-generator-for-seo" />
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
					<option value="missing" <?php echo $data['filter'] === 'missing' ? 'selected' : '' ?>>Missing Alt Text</option>
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
					<a href="<?php echo esc_url(admin_url('admin.php?page=hs-auto-image-alt-text-generator-for-seo')) ?>" class="button">Clear Filters</a>
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
							with missing alt text
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
									<label for="aat_user_email">Your Email:</label>
									<?php 
									// Get email from database via API
									$site_id = get_option('aat_site_id');
									$db_email = '';
									$admin_email = get_option('admin_email');
									
									if ($site_id) {
										$api_url = 'https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/dev-settings.php';
										$response = wp_remote_get($api_url . '?action=get&site_id=' . urlencode($site_id), [
											'timeout' => 5,
											'sslverify' => true
										]);
										
										if (!is_wp_error($response)) {
											$body = json_decode(wp_remote_retrieve_body($response), true);
											if (isset($body['success']) && $body['success'] && isset($body['site_info']['email'])) {
												$db_email = $body['site_info']['email'];
											}
										}
									}
									
									// Use database email if available, otherwise WordPress admin email
									$display_email = !empty($db_email) ? $db_email : $admin_email;
									?>
									<input type="email" id="aat_user_email" name="aat_user_email" 
										value="<?php echo esc_attr($display_email) ?>" 
										class="aat-setting-input" />
									<small class="aat-setting-help">
										Email address stored in database for plugin updates and support. 
										Defaults to WordPress admin email (<?php echo esc_html($admin_email) ?>) if not set.
									</small>
								</div>
								<div class="aat-setting-group">
									<label for="aat_site_id">Site ID:</label>
									<input type="text" id="aat_site_id" name="aat_site_id" 
										value="<?php echo esc_attr(get_option('aat_site_id')) ?>" 
										class="aat-setting-input" readonly />
									<small class="aat-setting-help">Unique identifier for your site (read-only).</small>
								</div>
								<?php if (aat_is_pro_user()): ?>
								<div class="aat-setting-group">
									<label>Subscription Management:</label>
									<a href="<?php echo esc_url(aat_get_stripe_portal_url()) ?>" 
									   target="_blank" 
									   class="button button-secondary" 
									   style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none;">
										<span class="dashicons dashicons-admin-generic" style="font-size: 16px;"></span>
										Manage Subscription
									</a>
									<small class="aat-setting-help">Update payment method, view invoices, or cancel your subscription.</small>
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
								<strong>Tier:</strong> <?php 
								$tier = aat_get_central_pro_status();
								echo esc_html(ucfirst($tier)); // Shows: Free or Pro
								?>
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

	<style>
		@keyframes spin {
			from { transform: rotate(0deg); }
			to { transform: rotate(360deg); }
		}
		.aat-bulk-info .aat-underline,
		.aat-bulk-info u,
		span.aat-underline {
			text-decoration: underline !important;
			border-bottom: 1px solid currentColor !important;
			display: inline-block;
		}
		.aat-upgrade-popup {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0, 0, 0, 0.7);
			display: flex;
			align-items: center;
			justify-content: center;
			z-index: 100000;
		}
		.aat-upgrade-popup-content {
			background: #fff;
			border-radius: 8px;
			padding: 24px;
			max-width: 500px;
			width: 90%;
			box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
			position: relative;
		}
		.aat-upgrade-popup-content h2 {
			margin-top: 0;
			margin-bottom: 16px;
		}
		.aat-upgrade-buttons {
			display: flex;
			gap: 10px;
			margin-top: 20px;
			flex-wrap: wrap;
			justify-content: center;
		}
		.aat-upgrade-buttons-top {
			display: flex;
			gap: 10px;
			justify-content: center;
			width: 100%;
			margin-bottom: 10px;
		}
		.aat-upgrade-buttons-bottom {
			display: flex;
			justify-content: center;
			width: 100%;
		}
		.aat-upgrade-btn {
			flex: 1;
			max-width: 200px;
			padding: 10px 20px;
			border: none;
			border-radius: 4px;
			cursor: pointer;
			font-size: 14px;
			font-weight: 600;
			text-decoration: none;
			display: inline-block;
			text-align: center;
		}
		.aat-upgrade-btn.primary {
			background: #2271b1;
			color: #fff;
		}
		.aat-upgrade-btn.primary:hover {
			background: #135e96;
		}
		.aat-upgrade-btn.secondary {
			background: #f0f0f1;
			color: #2c3338;
		}
		.aat-upgrade-btn.secondary:hover {
			background: #dcdcde;
		}
	</style>
	
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
			
		<?php
		$config = aat_get_plugin_config();
		
		// Get limits from config (live values from database)
		// Only use fallback if config has error flag (API completely failed)
		$is_error_state = isset($config['error']) && $config['error'] === true;
		
		// Handle limits - if value is 0 or missing, use fallback (unless error state)
		$free_limit_raw = $config['limits']['free_monthly'] ?? null;
		$free_limit = $is_error_state ? 10 : ((!is_null($free_limit_raw) && $free_limit_raw > 0) ? intval($free_limit_raw) : 10);
		
		$pro_limit_raw = $config['limits']['pro_monthly'] ?? null;
		$pro_limit = $is_error_state ? 50 : ((!is_null($pro_limit_raw) && $pro_limit_raw > 0) ? intval($pro_limit_raw) : 50);
		
		// Get prices (always numeric, format with $ when displaying)
		$pro_price = floatval($config['pricing']['pro_monthly_price'] ?? 10.00);
		$price_display = '$' . number_format($pro_price, 0);
		
		$gen_pack_price = floatval($config['pricing']['generation_pack_price'] ?? 5.00);
		$gen_pack_price_display = '$' . number_format($gen_pack_price, 0);
		
		$checkout_url = aat_get_stripe_checkout_url();
		$gen_pack_url = aat_get_generation_pack_checkout_url();
		?>
		const popup = document.createElement('div');
		popup.className = 'aat-upgrade-popup';
		popup.innerHTML = `
			<div class="aat-upgrade-popup-content">
				<h2>🚀 Generation Limit Reached</h2>
				<p>You've used all <?php echo absint($free_limit); ?> of your free alt text generations for this month!</p>
				<div class="aat-countdown">
					⏰ Free generations reset in: <strong>${countdownText}</strong>
				</div>
				<p>Want <?php echo absint($pro_limit); ?> generations per month? Upgrade to Pro for just <strong><?php echo esc_html($price_display); ?>/month</strong>!</p>
				<?php if ($gen_pack_url): ?>
				<p style="margin-top: 16px; padding: 12px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 4px; color: #1d2327;">
					💎 <strong>Or buy a Generation Pack:</strong> Get 20 generations for just <strong><?php echo esc_html($gen_pack_price_display); ?></strong> - works alongside your monthly quota and never expires!
				</p>
				<?php endif; ?>
				<div class="aat-upgrade-buttons">
					<div class="aat-upgrade-buttons-top">
						<a href="#" class="aat-upgrade-btn primary" onclick="window.open('<?php echo esc_js($checkout_url); ?>', '_blank')">
							🔥 Upgrade to Pro
						</a>
						<?php if ($gen_pack_url): ?>
						<a href="#" class="aat-upgrade-btn primary" style="background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);" onclick="window.open('<?php echo esc_js($gen_pack_url); ?>', '_blank')">
							💎 Buy Generation Pack
						</a>
						<?php endif; ?>
					</div>
					<div class="aat-upgrade-buttons-bottom">
						<button class="aat-upgrade-btn secondary" onclick="closeUpgradePopup()">
							Maybe Later
						</button>
					</div>
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
				bulkButton.addEventListener('click', function(e) {
					e.preventDefault();
					e.stopPropagation();
					
					<?php
					// Get current generation counts for confirmation dialog
					$remaining_generations = aat_get_remaining_free_generations();
					$paid_generations = aat_get_paid_generations();
					$total_available = $remaining_generations + $paid_generations;
					$is_pro = aat_is_pro_user();
					$config = aat_get_plugin_config();
					$monthly_limit = $is_pro 
						? ($config['limits']['pro_monthly'] ?? 50)
						: ($config['limits']['free_monthly'] ?? 10);
					?>
					
					// Show confirmation dialog
					const remainingFree = <?php echo absint($remaining_generations); ?>;
					const remainingPaid = <?php echo absint($paid_generations); ?>;
					const totalAvailable = <?php echo absint($total_available); ?>;
					const isPro = <?php echo $is_pro ? 'true' : 'false'; ?>;
					const monthlyLimit = <?php echo absint($monthly_limit); ?>;
					
					// Create confirmation popup
					const confirmPopup = document.createElement('div');
					confirmPopup.className = 'aat-upgrade-popup';
					confirmPopup.style.zIndex = '100001';
					confirmPopup.innerHTML = `
						<div class="aat-upgrade-popup-content" style="max-width: 500px;">
							<h2>⚠️ Confirm Bulk Generation</h2>
							<p style="margin-bottom: 16px;"><strong>This will generate alt text for all images missing them.</strong></p>
							
							<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px; margin: 16px 0; border-radius: 4px;">
								<p style="margin: 0 0 8px 0; font-weight: 600; color: #1d2327;">📊 Your Available Generations:</p>
								<ul style="margin: 0; padding-left: 20px; color: #50575e;">
									<li><strong>${remainingFree}</strong> ${isPro ? 'Pro' : 'Free'} generations remaining this month</li>
									${remainingPaid > 0 ? `<li><strong>${remainingPaid}</strong> paid generations from packs</li>` : '<li>No paid generations available</li>'}
									<li style="margin-top: 8px; font-weight: 600; color: #1d2327;">Total available: <strong>${totalAvailable}</strong></li>
								</ul>
							</div>
							
							<p style="color: #d63638; font-weight: 600; margin-bottom: 16px;">
								⚠️ Each image will use 1 generation. Make sure you have enough generations available, or manually generate the alt text for the images you want to add alt text to.
							</p>
							
							<div class="aat-upgrade-buttons">
								<button type="button" class="aat-upgrade-btn primary" id="aat-confirm-bulk">
									✅ Yes, Generate Missing Image Alt Texts
								</button>
								<button type="button" class="aat-upgrade-btn secondary" id="aat-cancel-bulk">
									Cancel
								</button>
							</div>
						</div>
					`;
					
					document.body.appendChild(confirmPopup);
					
					// Handle confirmation - use setTimeout to ensure DOM is ready
					setTimeout(function() {
						const confirmBtn = document.getElementById('aat-confirm-bulk');
						const cancelBtn = document.getElementById('aat-cancel-bulk');
						
						if (confirmBtn) {
							confirmBtn.addEventListener('click', function() {
								confirmPopup.remove();
								
								// Proceed with bulk generation
								const originalHtml = bulkButton.innerHTML;
								const originalSvg = bulkButton.querySelector('svg') ? bulkButton.querySelector('svg').outerHTML : '';
								bulkButton.innerHTML = originalSvg + ' Processing...';
								bulkButton.disabled = true;
								
								bulkResults.style.display = 'block';
								bulkResults.innerHTML = '<p>Starting bulk generation of alt text...</p>';
								
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
										
										if (data.success) {
											bulkButton.innerHTML = originalSvg + ' Completed!';
											bulkButton.style.background = '#00a32a';
											
											let html = `<p><strong>${data.message}</strong></p>`;
											
											if (data.images && data.images.length) {
												html += `<div style="margin-top: 15px;">
													<h4>Generated Alt Text:</h4>
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
											
											html += `<p style="margin-top: 15px;"><a href="${window.location.href.split('?')[0]}?aat_refresh_cache=1" class="button button-primary">Refresh Page</a></p>`;
											
											bulkResults.innerHTML = html;
											
											// Reset button after delay
											setTimeout(() => {
												bulkButton.innerHTML = originalHtml;
												bulkButton.disabled = false;
												bulkButton.style.background = '';
											}, 3000);
										} else {
											bulkButton.innerHTML = originalHtml;
											bulkButton.disabled = false;
											bulkResults.innerHTML = '<p style="color: #d63638;">Error: ' + (data.message || 'Unknown error') + '</p>';
										}
									})
									.catch(error => {
										bulkButton.innerHTML = originalHtml;
										bulkButton.disabled = false;
										bulkResults.innerHTML = '<p style="color: #d63638;">Network error during bulk processing: ' + error.message + '</p>';
									});
							});
						}
						
						if (cancelBtn) {
							cancelBtn.addEventListener('click', function() {
								confirmPopup.remove();
							});
						}
						
						// Close on outside click
						confirmPopup.addEventListener('click', function(e) {
							if (e.target === confirmPopup) {
								confirmPopup.remove();
							}
						});
					}, 10);
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
		
		// Refresh status button
		const refreshStatusBtn = document.getElementById('aat-refresh-status');
		if (refreshStatusBtn) {
			refreshStatusBtn.addEventListener('click', function() {
				const icon = this.querySelector('.dashicons');
				const originalText = this.title;
				
				// Show loading state
				icon.style.animation = 'spin 1s linear infinite';
				this.title = 'Refreshing...';
				this.disabled = true;
				
				// Reload page with cache bypass parameter
				const url = new URL(window.location.href);
				url.searchParams.set('aat_refresh_cache', '1');
				window.location.href = url.toString();
			});
		}
		
		// Refresh paid generations button
		const refreshPaidBtn = document.getElementById('aat-refresh-paid-generations');
		if (refreshPaidBtn) {
			refreshPaidBtn.addEventListener('click', function() {
				const icon = this.querySelector('.dashicons');
				const originalText = this.title;
				
				// Show loading state
				icon.style.animation = 'spin 1s linear infinite';
				this.title = 'Refreshing...';
				this.disabled = true;
				
				// Reload page with cache bypass parameter
				const url = new URL(window.location.href);
				url.searchParams.set('aat_refresh_cache', '1');
				window.location.href = url.toString();
			});
		}

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
			if (closeBtn) {
				closeBtn.addEventListener('click', function() {
					modal.style.display = 'none';
					document.body.style.overflow = 'auto';
				});
			}
			
			// Close on background click
			if (modal) {
				modal.addEventListener('click', function(e) {
					if (e.target === modal) {
						modal.style.display = 'none';
						document.body.style.overflow = 'auto';
					}
				});
			}
			
			// Close on Escape key
			document.addEventListener('keydown', function(e) {
				if (e.key === 'Escape' && modal && modal.style.display === 'block') {
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
                /* translators: %d: Number of generations per month */
                'message' => sprintf(__('Monthly generation limit reached. Upgrade to Pro for %d generations per month.', 'hs-auto-image-alt-text-generator-for-seo'), aat_get_plugin_config()['limits']['pro_monthly'] ?? 50),
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
        
        // Special handling for "Site not registered" errors
        if ($response_code === 403 && (strpos($error_message, 'Site not registered') !== false || strpos($error_message, 'Invalid site') !== false)) {
            // Try to re-register the site automatically
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
