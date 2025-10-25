<?php
/**
 * Uninstall script for Auto Image Alt Text Generator For SEO
 * 
 * Handles plugin deletion cleanup and event tracking
 *
 * @package     AAT
 * @since       1.1.0
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Track uninstall event (also updates plugin_sites table automatically)
$site_id = get_option('aat_site_id');
if ($site_id) {
    // Get plugin version from main file
    $plugin_file = __DIR__ . '/hs-auto-image-alt-text-generator-for-seo.php';
    $plugin_data = get_file_data($plugin_file, ['Version' => 'Version']);
    $plugin_version = $plugin_data['Version'] ?? '1.1.0';
    
    // Send uninstall event (track-event.php also updates plugin_sites table)
    wp_remote_post('https://hatrixsolutions.com/api/hs-auto-alt-text-generator-for-seo/track-event.php', [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode([
            'site_id' => $site_id,
            'event_type' => 'uninstall',
            'site_url' => home_url(),
            'plugin_version' => $plugin_version,
            'timestamp' => gmdate('Y-m-d H:i:s')
        ]),
        'timeout' => 5,
        'blocking' => true, // Blocking to ensure it's tracked before cleanup
    ]);
}

// Clean up plugin options
delete_option('aat_site_id');
delete_option('aat_user_email');
delete_option('aat_last_server_ping');
delete_option('aat_activation_time');
delete_option('aat_external_service_notice_dismissed');
delete_option('aat_welcome_dismissed');
delete_option('aat_feedback_dismissed');
delete_option('aat_feedback_shown');
delete_option('aat_low_credits_dismissed');
delete_option('aat_low_credits_dismissed_month');

// Clean up transients (rate limiting and cached data)
global $wpdb;

// Delete all transients starting with 'aat_'
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// Direct query necessary: WordPress has no function to delete transients by prefix
// Caching not applicable: This runs during plugin uninstall
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_aat_') . '%',
        $wpdb->esc_like('_transient_timeout_aat_') . '%'
    )
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Optional: Remove user meta (notice dismissals per user)
// Uncomment if you add per-user notice tracking in the future
// $wpdb->query("DELETE FROM $wpdb->usermeta WHERE meta_key LIKE 'aat_%'");

// Note: We intentionally DO NOT delete:
// - WordPress attachment post meta (alt text) - users may want to keep their generated alt text
// - Media library images - these belong to the user


