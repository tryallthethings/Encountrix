<?php
/**
 * Plugin Name: WoW Raid Progress Tracker
 * Plugin URI: https://example.com/wow-raid-progress
 * Description: Display World of Warcraft raid progress from Raider.io API with Blizzard achievement icons
 * Version: 5.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wow-raid-progress
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WOW_RAID_PROGRESS_VERSION', '5.0.0');
define('WOW_RAID_PROGRESS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WOW_RAID_PROGRESS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WOW_RAID_PROGRESS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Load text domain for translations
add_action('plugins_loaded', 'wow_raid_progress_load_textdomain');
function wow_raid_progress_load_textdomain() {
    load_plugin_textdomain('wow-raid-progress', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// Include required files
require_once WOW_RAID_PROGRESS_PLUGIN_DIR . 'includes/class-wow-raid-progress.php';
require_once WOW_RAID_PROGRESS_PLUGIN_DIR . 'includes/class-wow-raid-progress-admin.php';
require_once WOW_RAID_PROGRESS_PLUGIN_DIR . 'includes/class-wow-raid-progress-api.php';
require_once WOW_RAID_PROGRESS_PLUGIN_DIR . 'includes/class-wow-raid-progress-widget.php';

// Initialize the plugin
function wow_raid_progress_init() {
    $plugin = new WoWRaidProgress();
    $plugin->run();
}
add_action('plugins_loaded', 'wow_raid_progress_init');

// Activation hook
register_activation_hook(__FILE__, 'wow_raid_progress_activate');
function wow_raid_progress_activate() {
    // Set default options
    $defaults = wow_raid_progress_get_default_options();
    foreach ($defaults as $option_name => $default_value) {
        if (get_option($option_name) === false) {
            add_option($option_name, $default_value);
        }
    }
    
    // Create database table for cached raids if needed
    wow_raid_progress_create_cache_table();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'wow_raid_progress_deactivate');
function wow_raid_progress_deactivate() {
    // Clear all transients
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wow_raid_%' OR option_name LIKE '_transient_timeout_wow_raid_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wow_blizzard_%' OR option_name LIKE '_transient_timeout_wow_blizzard_%'");
    
    // Clear scheduled events if any
    wp_clear_scheduled_hook('wow_raid_progress_cron');
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'wow_raid_progress_uninstall');
function wow_raid_progress_uninstall() {
    // Remove all options
    $options = [
        'wow_raid_progress_api_key',
        'wow_raid_progress_guild_ids',
        'wow_raid_progress_expansion',
        'wow_raid_progress_raid',
        'wow_raid_progress_difficulty',
        'wow_raid_progress_region',
        'wow_raid_progress_realm',
        'wow_raid_progress_cache_time',
        'wow_raid_progress_show_icons',
        'wow_raid_progress_show_killed',
        'wow_raid_progress_use_blizzard_icons',
        'wow_raid_progress_limit',
        'wow_raid_progress_blizzard_client_id',
        'wow_raid_progress_blizzard_client_secret',
        'wow_raid_progress_blizzard_region',
        'wow_raid_progress_cached_raids'
    ];
    
    foreach ($options as $option) {
        delete_option($option);
    }
    
    // Remove all transients
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wow_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wow_%'");
}

// Helper function to get default options
function wow_raid_progress_get_default_options() {
    return [
        'wow_raid_progress_api_key' => '',
        'wow_raid_progress_guild_ids' => '',
        'wow_raid_progress_expansion' => 10,
        'wow_raid_progress_raid' => '',
        'wow_raid_progress_difficulty' => 'highest',
        'wow_raid_progress_region' => 'eu',
        'wow_raid_progress_realm' => '',
        'wow_raid_progress_cache_time' => 60,
        'wow_raid_progress_show_icons' => 'true',
        'wow_raid_progress_show_killed' => 'false',
        'wow_raid_progress_use_blizzard_icons' => 'true',
        'wow_raid_progress_limit' => 50,
        'wow_raid_progress_blizzard_client_id' => '',
        'wow_raid_progress_blizzard_client_secret' => '',
        'wow_raid_progress_blizzard_region' => 'eu',
        'wow_raid_progress_cached_raids' => []
    ];
}

// Create cache table for raids
function wow_raid_progress_create_cache_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wow_raid_cache';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        expansion_id int(11) NOT NULL,
        raid_data longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY expansion_id (expansion_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}