<?php

/**
 * Plugin Name: Encountrix
 * Plugin URI: https://github.com/tryallthethings/encountrix
 * Description: Shows your guilds World of Warcraft raid progress with data the from Raider.io API. If enabled, it will also fetch boss icons and raid background images from the Blizzard API.
 * Version: 5.1.0
 * Author: Tryallthethings
 * License: GPL v2 or later
 * Text Domain: encountrix
 * Domain Path: /languages
 * Requires PHP: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('ENCOUNTRIX_VERSION', '5.0.1');
define('ENCOUNTRIX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ENCOUNTRIX_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ENCOUNTRIX_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Load text domain for translations
add_action('plugins_loaded', 'encountrix_load_textdomain');

function encountrix_load_textdomain(): void {
	load_plugin_textdomain('encountrix', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

require_once ENCOUNTRIX_PLUGIN_DIR . 'includes/class-encountrix.php';
require_once ENCOUNTRIX_PLUGIN_DIR . 'includes/class-encountrix-admin.php';
require_once ENCOUNTRIX_PLUGIN_DIR . 'includes/class-encountrix-api.php';
require_once ENCOUNTRIX_PLUGIN_DIR . 'includes/class-encountrix-widget.php';

function encountrix_init(): void {
	$plugin = new Encountrix();
	$plugin->run();
}
add_action('plugins_loaded', 'encountrix_init');

register_activation_hook(__FILE__, 'encountrix_activate');

function encountrix_activate(): void {
	$defaults = encountrix_get_default_options();
	foreach ($defaults as $option_name => $default_value) {
		if (get_option($option_name) === false) {
			add_option($option_name, $default_value);
		}
	}
	encountrix_create_cache_table();
	flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, 'encountrix_deactivate');

function encountrix_deactivate(): void {
	encountrix_clear_transients([
		'_transient_encountrix_',
		'_transient_timeout_encountrix_',
		'_transient_encountrix_blizzard_',
		'_transient_timeout_encountrix_blizzard_',
	]);
	wp_clear_scheduled_hook('encountrix_cron');
	wp_clear_scheduled_hook('encountrix_update_data');
}

register_uninstall_hook(__FILE__, 'encountrix_uninstall');

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

function encountrix_clear_transients(array $prefixes): void {
	global $wpdb;
	foreach ($prefixes as $prefix) {
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like($prefix) . '%'
		));
	}
}

function encountrix_should_use_cron(): bool {
	return get_option('encountrix_use_cron', 'false') === 'true';
}

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

function encountrix_background_update(): void {
	if (!encountrix_should_use_cron()) {
		return;
	}
	$guild_ids = get_option('encountrix_guild_ids', '');
	if (empty($guild_ids)) {
		return;
	}
	$api = new EncountrixAPI();
	$raid = get_option('encountrix_raid', '');
	$region = get_option('encountrix_region', 'eu');
	$realm = get_option('encountrix_realm', '');
	if (empty($raid)) {
		return;
	}
	foreach (['normal', 'heroic', 'mythic'] as $difficulty) {
		$api->fetch_raid_data($raid, $difficulty, $region, $realm, $guild_ids, 1440, 50, 0);
		sleep(2);
	}
}
add_action('encountrix_update_data', 'encountrix_background_update');
