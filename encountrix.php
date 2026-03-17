<?php

/**
 * Plugin Name: Encountrix - WoW Raid Progress
 * Plugin URI: https://github.com/tryallthethings/Encountrix
 * Description: Display a guilds World of Warcraft raid progression via widget or shortcode with data from Raider.io and boss / raid background images from Blizzard's API (if enabled). Supports caching, multiple guilds, and all raid difficulties.
 * Version: 5.1.0
 * Author: Tryallthethings
 * License: GPL v2 or later
 * Text Domain: wow-raid-progress
 * Domain Path: /languages
 * Requires PHP: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('ENCOUNTRIX_VERSION', '5.1.0');
define('ENCOUNTRIX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ENCOUNTRIX_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ENCOUNTRIX_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Load text domain for translations
add_action('plugins_loaded', 'encountrix_load_textdomain');

/**
 * Load plugin text domain for internationalization.
 */
function encountrix_load_textdomain(): void {
	load_plugin_textdomain('encountrix', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// Include required files
require_once ENCOUNTRIX_PLUGIN_DIR . 'includes/class-encountrix.php';
require_once ENCOUNTRIX_PLUGIN_DIR . 'includes/class-encountrix-admin.php';
require_once ENCOUNTRIX_PLUGIN_DIR . 'includes/class-encountrix-api.php';
require_once ENCOUNTRIX_PLUGIN_DIR . 'includes/class-encountrix-widget.php';

/**
 * Bootstraps the core plugin functionality.
 */
function encountrix_init(): void {
	$plugin = new Encountrix();
	$plugin->run();
}
add_action('plugins_loaded', 'encountrix_init');

// Activation hook
register_activation_hook(__FILE__, 'encountrix_activate');

/**
 * Runs during plugin activation.
 * Sets up default options and required database structures.
 */
function encountrix_activate(): void {
	// Set default options
	$defaults = encountrix_get_default_options();
	foreach ($defaults as $option_name => $default_value) {
		if (get_option($option_name) === false) {
			add_option($option_name, $default_value);
		}
	}

	// Create database table for cached raids if needed
	encountrix_create_cache_table();

	// Flush rewrite rules
	flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'encountrix_deactivate');

/**
 * Runs on plugin deactivation.
 * Cleans up cached data and scheduled events.
 */
function encountrix_deactivate(): void {
	encountrix_clear_transients([
		'_transient_encountrix_',
		'_transient_timeout_encountrix_',
		'_transient_encountrix_blizzard_',
		'_transient_timeout_encountrix_blizzard_',
	]);

	// Clear all scheduled events
	wp_clear_scheduled_hook('encountrix_cron');
	wp_clear_scheduled_hook('encountrix_update_data');
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'encountrix_uninstall');

/**
 * Runs on plugin uninstall.
 * Removes stored options and cached transients.
 */
function encountrix_uninstall(): void {
	$defaults = encountrix_get_default_options();
	foreach (array_keys($defaults) as $option) {
		delete_option($option);
	}

	encountrix_clear_transients([
		'_transient_encountrix_',
		'_transient_timeout_encountrix_',
	]);
}

/**
 * Retrieve plugin default option values.
 *
 * @return array<string, mixed>
 */
function encountrix_get_default_options(): array {
	return [
		'encountrix_api_key' => '',
		'encountrix_guild_ids' => '',
		'encountrix_expansion' => 10,
		'encountrix_raid' => '',
		'encountrix_difficulty' => 'highest',
		'encountrix_region' => 'eu',
		'encountrix_realm' => '',
		'encountrix_cache_time' => 60,
		'encountrix_show_icons' => 'true',
		'encountrix_show_killed' => 'false',
		'encountrix_use_blizzard_icons' => 'true',
		'encountrix_show_raid_name' => 'false',
		'encountrix_show_raid_icon' => 'false',
		'encountrix_limit' => 50,
		'encountrix_blizzard_client_id' => '',
		'encountrix_blizzard_client_secret' => '',
		'encountrix_blizzard_region' => 'eu',
		'encountrix_cached_raids' => [],
		'encountrix_debug_mode' => 'false',
		'encountrix_use_cron' => 'false',
	];
}

/**
 * Create database table used for caching raid information.
 */
function encountrix_create_cache_table(): void {
	global $wpdb;
	$table_name = $wpdb->prefix . 'encountrix_cache';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        expansion_id int(11) NOT NULL,
        raid_data longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY expansion_id (expansion_id)
    ) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
}

/**
 * Remove plugin transients matching the provided prefixes.
 *
 * @param array<string> $prefixes Array of option_name prefixes (without trailing %).
 */
function encountrix_clear_transients(array $prefixes): void {
	global $wpdb;
	foreach ($prefixes as $prefix) {
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like($prefix) . '%'
		));
	}
}

/**
 * Determine whether background cron updates are enabled.
 *
 * @return bool True when cron-based data refresh is active.
 */
function encountrix_should_use_cron(): bool {
	return get_option('encountrix_use_cron', 'false') === 'true';
}

/**
 * Register or clear the scheduled event for background data updates.
 */
function encountrix_schedule_updates(): void {
	if (encountrix_should_use_cron()) {
		if (!wp_next_scheduled('encountrix_update_data')) {
			wp_schedule_event(time(), 'hourly', 'encountrix_update_data');
		}
	} else {
		wp_clear_scheduled_hook('encountrix_update_data');
	}
}
add_action('wp', 'encountrix_schedule_updates');

/**
 * Execute a background refresh of raid data for all difficulties.
 *
 * Invoked by the WP-Cron event 'encountrix_update_data'.
 */
function encountrix_background_update(): void {
	if (!encountrix_should_use_cron()) {
		return;
	}

	$guild_ids = get_option('encountrix_guild_ids', '');
	if (empty($guild_ids)) {
		return;
	}

	$api = new EncountrixApi();
	$raid = get_option('encountrix_raid', '');
	$region = get_option('encountrix_region', 'eu');
	$realm = get_option('encountrix_realm', '');

	if (empty($raid)) {
		return;
	}

	// Update each difficulty with longer cache time
	foreach (['normal', 'heroic', 'mythic'] as $difficulty) {
		$api->fetch_raid_data(
			$raid,
			$difficulty,
			$region,
			$realm,
			$guild_ids,
			1440, // Cache for 24 hours
			50,
			0
		);

		// Wait between requests to avoid rate limiting
		sleep(2);
	}
}
add_action('encountrix_update_data', 'encountrix_background_update');
