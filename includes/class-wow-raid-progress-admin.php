<?php

/**
 * WoW Raid Progress Admin Handler
 *
 * Manages admin interface, settings, and AJAX handlers
 *
 * @package WoWRaidProgress
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class WoWRaidProgressAdmin {

	private $plugin_name;
	private $version;
	private $api;

	public function __construct($plugin_name, $version, $api) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
		$this->api = $api;
	}

	/**
	 * Add admin menu page
	 */
	public function add_admin_menu() {
		add_options_page(
			__('WoW Raid Progress Settings', 'wow-raid-progress'),
			__('WoW Raid Progress', 'wow-raid-progress'),
			'manage_options',
			'wow-raid-progress',
			[$this, 'admin_page']
		);
	}

	/**
	 * Register plugin settings
	 */
	public function register_settings() {
		// API Settings
		register_setting('wow_raid_progress_settings', 'wow_raid_progress_api_key', [
			'type' => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default' => ''
		]);

		// Display Settings
		register_setting('wow_raid_progress_settings', 'wow_raid_progress_guild_ids', [
			'type' => 'string',
			'sanitize_callback' => [$this, 'sanitize_guilds'],
			'default' => ''
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_expansion', [
			'type' => 'integer',
			'sanitize_callback' => [$this, 'sanitize_expansion'],
			'default' => 10
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_raid', [
			'type' => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default' => ''
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_difficulty', [
			'type' => 'string',
			'sanitize_callback' => [$this, 'sanitize_difficulty'],
			'default' => 'highest'
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_region', [
			'type' => 'string',
			'sanitize_callback' => [$this, 'sanitize_region'],
			'default' => 'eu'
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_realm', [
			'type' => 'string',
			'sanitize_callback' => [$this, 'sanitize_realm'],
			'default' => ''
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_cache_time', [
			'type' => 'integer',
			'sanitize_callback' => [$this, 'sanitize_cache_time'],
			'default' => 60,
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_show_icons', [
			'type' => 'string',
			'sanitize_callback' => [$this, 'sanitize_boolean_string'],
			'default' => 'true'
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_show_killed', [
			'type' => 'string',
			'sanitize_callback' => [$this, 'sanitize_boolean_string'],
			'default' => 'false'
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_use_blizzard_icons', [
			'type' => 'string',
			'sanitize_callback' => [$this, 'sanitize_boolean_string'],
			'default' => 'true'
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_show_raid_name', [
			'type' => 'string',
			'sanitize_callback' => [$this, 'sanitize_boolean_string'],
			'default' => 'false'
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_show_raid_icon', [
			'type' => 'string',
			'sanitize_callback' => [$this, 'sanitize_boolean_string'],
			'default' => 'false'
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_limit', [
			'type' => 'integer',
			'sanitize_callback' => [$this, 'sanitize_limit'],
			'default' => 50
		]);

		// Blizzard API Settings
		register_setting('wow_raid_progress_settings', 'wow_raid_progress_blizzard_client_id', [
			'type' => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default' => ''
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_blizzard_client_secret', [
			'type' => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default' => ''
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_blizzard_region', [
			'type' => 'string',
			'sanitize_callback' => [$this, 'sanitize_region'],
			'default' => 'eu'
		]);

		// Cached raids
		register_setting('wow_raid_progress_settings', 'wow_raid_progress_cached_raids', [
			'type' => 'array',
			'sanitize_callback' => [$this, 'sanitize_cached_raids'],
			'default' => []
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_debug_mode', [
			'type' => 'string',
			'sanitize_callback' => [$this, 'sanitize_boolean_string'],
			'default' => 'false'
		]);

		register_setting('wow_raid_progress_settings', 'wow_raid_progress_use_cron', [
			'type' => 'string',
			'sanitize_callback' => [$this, 'sanitize_boolean_string'],
			'default' => 'false'
		]);
	}

	/**
	 * Sanitize expansion setting
	 *
	 * @param mixed $value Input value
	 * @return int Sanitized expansion ID
	 */
	public function sanitize_expansion($value) {
		$value = absint($value);
		$valid_expansions = array_keys($this->api->get_expansions());
		return in_array($value, $valid_expansions) ? $value : 10;
	}

	/**
	 * Sanitize difficulty setting
	 *
	 * @param string $value Input value
	 * @return string Sanitized difficulty
	 */
	public function sanitize_difficulty($value) {
		$valid = ['all', 'normal', 'heroic', 'mythic', 'highest'];
		return in_array($value, $valid) ? $value : 'highest';
	}

	/**
	 * Sanitize region setting
	 *
	 * @param string $value Input value
	 * @return string Sanitized region
	 */
	public function sanitize_region($value) {
		$valid = ['us', 'eu', 'kr', 'tw'];
		return in_array($value, $valid) ? $value : 'eu';
	}

	/**
	 * Sanitize cache time
	 *
	 * @param mixed $value Input value
	 * @return int Sanitized cache time in minutes
	 */
	public function sanitize_cache_time($value) {
		$value = absint($value);
		return min(1440, max(0, $value)); // Max 24 hours
	}

	/**
	 * Sanitize limit
	 *
	 * @param mixed $value Input value
	 * @return int Sanitized limit
	 */
	public function sanitize_limit($value) {
		$value = absint($value);
		return min(100, max(1, $value));
	}

	/**
	 * Sanitize boolean string
	 *
	 * @param string $value Input value
	 * @return string 'true' or 'false'
	 */
	public function sanitize_boolean_string($value) {
		return in_array($value, ['true', 'false']) ? $value : 'false';
	}

	/**
	 * Sanitize cached raids
	 *
	 * @param mixed $value Input value
	 * @return array Sanitized raids array
	 */
	public function sanitize_cached_raids($value) {
		if (!is_array($value)) {
			return [];
		}
		return $value;
	}

	/**
	 * Sanitize realm value
	 *
	 * @param string $value Raw realm value
	 * @return string Sanitized realm string
	 */
	public function sanitize_realm($value) {
		return $this->api->sanitize_realm($value);
	}

	/**
	 * Sanitize guild IDs
	 *
	 * @param string $value Raw guild IDs
	 * @return string Sanitized guild IDs
	 */
	public function sanitize_guilds($value) {
		return $this->api->sanitize_guilds($value);
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook
	 */
	public function enqueue_admin_assets($hook) {
		// Only load assets on the plugin settings page
		if ($hook !== 'settings_page_wow-raid-progress') {
			return;
		}

		wp_enqueue_style(
			'wow-raid-progress-admin',
			WOW_RAID_PROGRESS_PLUGIN_URL . 'assets/css/admin.css',
			[],
			$this->version
		);

		wp_enqueue_script(
			'wow-raid-progress-admin',
			WOW_RAID_PROGRESS_PLUGIN_URL . 'assets/js/admin.js',
			['jquery'],
			$this->version,
			true
		);

		wp_localize_script('wow-raid-progress-admin', 'wow_raid_admin', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('wow_raid_admin_nonce'),
			'current_raid' => get_option('wow_raid_progress_raid', ''),
			'strings' => [
				'loading' => __('Loading...', 'wow-raid-progress'),
				'success' => __('Success!', 'wow-raid-progress'),
				'error' => __('Error:', 'wow-raid-progress'),
				'confirm_clear' => __('Are you sure you want to clear the cache?', 'wow-raid-progress'),
				'confirm_import' => __('This will import boss icons for the selected raid. Continue?', 'wow-raid-progress'),
				'confirm_delete_icons' => __('WARNING: This will permanently delete ALL imported icons from your media library. This cannot be undone. Are you sure?', 'wow-raid-progress'),
				'settings_saved' => __('Settings saved successfully!', 'wow-raid-progress'),
				'error_loading_realms' => __('Could not load realms. You can still type a realm name manually.', 'wow-raid-progress'),
				'no_realms_found' => __('No realms found for this region.', 'wow-raid-progress'),
				'no_matching_realms' => __('No realms match your search.', 'wow-raid-progress'),
				'copied' => __('Copied!', 'wow-raid-progress'),
				'press_ctrl_c' => __('Press Ctrl+C to copy', 'wow-raid-progress'),
				'asian_region_note' => __('For KR/TW regions, you may need to enter the realm name manually.', 'wow-raid-progress'),
			]
		]);
	}

	/**
	 * AJAX handler for clearing cache
	 */
	public function ajax_clear_cache() {
		// Verify nonce
		if (!check_ajax_referer('wow_raid_admin_nonce', 'nonce', false)) {
			wp_send_json_error(__('Security check failed', 'wow-raid-progress'));
			return;
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied', 'wow-raid-progress'));
			return;
		}

		// Clear all plugin transients
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_wow_raid_%'
			 OR option_name LIKE '_transient_timeout_wow_raid_%'
			 OR option_name LIKE '_transient_wow_blizzard_%'
			 OR option_name LIKE '_transient_timeout_wow_blizzard_%'"
		);

		// Clear rate limit data
		delete_transient('wow_raid_api_rate_limit');

		$this->api->debug_log('Cache cleared by admin');

		wp_send_json_success(__('Cache cleared successfully', 'wow-raid-progress'));
	}

	/**
	 * AJAX handler for importing icons
	 */
	public function ajax_import_icons() {
		// Verify nonce
		if (!check_ajax_referer('wow_raid_admin_nonce', 'nonce', false)) {
			wp_send_json_error(__('Security check failed', 'wow-raid-progress'));
			return;
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied', 'wow-raid-progress'));
			return;
		}

		$expansion_id = get_option('wow_raid_progress_expansion', 10);
		$raid_slug = get_option('wow_raid_progress_raid', '');

		if (empty($raid_slug)) {
			wp_send_json_error(__('No raid selected in settings', 'wow-raid-progress'));
			return;
		}

		$this->api->debug_log("Starting icon import for raid: $raid_slug");

		// Get static raid data
		$static_data = $this->api->fetch_static_raid_data_by_expansion($expansion_id, 1440);
		if (is_wp_error($static_data) || !isset($static_data['raids'])) {
			wp_send_json_error(__('Could not fetch raid data', 'wow-raid-progress'));
			return;
		}

		// Find the raid
		$raid_info = null;
		foreach ($static_data['raids'] as $raid) {
			if ($raid['slug'] === $raid_slug) {
				$raid_info = $raid;
				break;
			}
		}

		if (!$raid_info || !isset($raid_info['encounters'])) {
			wp_send_json_error(sprintf(__('Could not find raid: %s', 'wow-raid-progress'), $raid_slug));
			return;
		}

		$log = [];
		$success_count = 0;
		$skip_count = 0;
		$error_count = 0;

		// Import icons for each boss
		foreach ($raid_info['encounters'] as $boss) {
			$result = $this->api->import_single_boss_icon(
				$boss['slug'],
				$boss['name'],
				$raid_slug,
				$expansion_id
			);

			if (is_numeric($result)) {
				$success_count++;
				$log[] = sprintf(__('SUCCESS: Imported icon for %s (ID: %d)', 'wow-raid-progress'), $boss['name'], $result);
			} elseif ($result === true) {
				$skip_count++;
				$log[] = sprintf(__('INFO: Icon for %s already exists', 'wow-raid-progress'), $boss['name']);
			} else {
				$error_count++;
				$log[] = sprintf(__('ERROR: Failed to import icon for %s', 'wow-raid-progress'), $boss['name']);
			}

			// Small delay to avoid overwhelming the API
			if ($success_count > 0 && $success_count % 3 === 0) {
				sleep(1);
			}
		}

		// Add summary
		$log[] = sprintf(
			__('Summary: %d imported, %d skipped, %d errors', 'wow-raid-progress'),
			$success_count,
			$skip_count,
			$error_count
		);

		$this->api->debug_log("Icon import completed: $success_count imported, $skip_count skipped, $error_count errors");

		wp_send_json_success(['log' => $log]);
	}

	/**
	 * AJAX handler for deleting all icons
	 */
	public function ajax_delete_icons() {
		// Verify nonce
		if (!check_ajax_referer('wow_raid_admin_nonce', 'nonce', false)) {
			wp_send_json_error(__('Security check failed', 'wow-raid-progress'));
			return;
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied', 'wow-raid-progress'));
			return;
		}

		$this->api->debug_log('Deleting all plugin icons');
		$deleted = $this->api->delete_all_icons();

		wp_send_json_success(sprintf(
			__('Successfully deleted %d icon(s) from the media library', 'wow-raid-progress'),
			$deleted
		));
	}

	/**
	 * AJAX handler for getting raids
	 */
	public function ajax_get_raids() {
		// Verify nonce
		if (!check_ajax_referer('wow_raid_admin_nonce', 'nonce', false)) {
			wp_send_json_error(__('Security check failed', 'wow-raid-progress'));
			return;
		}

		$expansion_id = isset($_POST['expansion_id']) ? absint($_POST['expansion_id']) : 10;

		// Validate expansion ID
		$valid_expansions = array_keys($this->api->get_expansions());
		if (!in_array($expansion_id, $valid_expansions)) {
			wp_send_json_error(__('Invalid expansion ID', 'wow-raid-progress'));
			return;
		}

		// Try to get cached raids
		$cached_raids = get_option('wow_raid_progress_cached_raids', []);
		if (isset($cached_raids[$expansion_id]) && !empty($cached_raids[$expansion_id])) {
			$this->api->debug_log("Using cached raids for expansion $expansion_id");
			wp_send_json_success($cached_raids[$expansion_id]);
			return;
		}

		// Fetch from API
		$this->api->debug_log("Fetching raids for expansion $expansion_id from API");
		$static_data = $this->api->fetch_static_raid_data_by_expansion($expansion_id, 1440);
		if (is_wp_error($static_data) || !isset($static_data['raids'])) {
			wp_send_json_error(__('Could not fetch raids', 'wow-raid-progress'));
			return;
		}

		$raids = [];
		foreach ($static_data['raids'] as $raid) {
			$raids[] = [
				'slug' => $raid['slug'],
				'name' => $raid['name']
			];
		}

		// Cache the raids
		$cached_raids[$expansion_id] = $raids;
		update_option('wow_raid_progress_cached_raids', $cached_raids);

		wp_send_json_success($raids);
	}

	/**
	 * AJAX handler for refreshing raids
	 */
	public function ajax_refresh_raids() {
		// Verify nonce
		if (!check_ajax_referer('wow_raid_admin_nonce', 'nonce', false)) {
			wp_send_json_error(__('Security check failed', 'wow-raid-progress'));
			return;
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied', 'wow-raid-progress'));
			return;
		}

		$this->api->debug_log('Refreshing raids cache');

		// Clear cached raids
		delete_option('wow_raid_progress_cached_raids');

		// Clear transients for static data
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_wow_raid_static_%'
			 OR option_name LIKE '_transient_timeout_wow_raid_static_%'"
		);

		// Now fetch fresh data
		$this->ajax_get_raids();
	}

	/**
	 * AJAX handler for getting realms
	 */
	public function ajax_get_realms() {
		if (!check_ajax_referer('wow_raid_admin_nonce', 'nonce', false)) {
			wp_send_json_error(__('Security check failed', 'wow-raid-progress'));
			return;
		}

		$region = isset($_POST['region']) ? sanitize_text_field($_POST['region']) : 'eu';

		// Validate region
		$valid_regions = ['us', 'eu', 'kr', 'tw'];
		if (!in_array($region, $valid_regions)) {
			wp_send_json_error(__('Invalid region specified', 'wow-raid-progress'));
			return;
		}

		$this->api->debug_log("Fetching realms for region: $region");
		$realms = $this->api->get_realms_list($region);

		if (empty($realms)) {
			$error_message = __('Could not fetch realms. Check Blizzard API settings.', 'wow-raid-progress');
			wp_send_json_error($error_message);
			return;
		}

		wp_send_json_success($realms);
	}

	/**
	 * AJAX handler for getting debug log
	 */
	public function ajax_get_debug_log() {
		if (!check_ajax_referer('wow_raid_admin_nonce', 'nonce', false)) {
			wp_send_json_error(__('Security check failed', 'wow-raid-progress'));
			return;
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied', 'wow-raid-progress'));
			return;
		}

		$log = get_transient('wow_raid_debug_log') ?: [];
		wp_send_json_success($log);
	}

	/**
	 * AJAX handler for clearing debug log
	 */
	public function ajax_clear_debug_log() {
		if (!check_ajax_referer('wow_raid_admin_nonce', 'nonce', false)) {
			wp_send_json_error(__('Security check failed', 'wow-raid-progress'));
			return;
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied', 'wow-raid-progress'));
			return;
		}

		delete_transient('wow_raid_debug_log');
		$this->api->debug_log('Debug log cleared');
		wp_send_json_success(__('Debug log cleared', 'wow-raid-progress'));
	}

	/**
	 * AJAX handler for testing Blizzard API
	 */
	public function ajax_test_blizzard_api() {
		if (!check_ajax_referer('wow_raid_admin_nonce', 'nonce', false)) {
			wp_send_json_error(__('Security check failed', 'wow-raid-progress'));
			return;
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied', 'wow-raid-progress'));
			return;
		}

		$results = [];
		$regions = ['us', 'eu', 'kr', 'tw'];

		foreach ($regions as $region) {
			$this->api->debug_log("Testing Blizzard API for region: $region");

			// Test token
			$token = $this->api->get_blizzard_token($region);
			if ($token) {
				$this->api->debug_log("✓ Token obtained for $region");
				$results[$region]['token'] = true;

				// Test realms
				$realms = $this->api->get_realms_list($region);
				$results[$region]['realms'] = count($realms);
				$this->api->debug_log("✓ Found " . count($realms) . " realms for $region");
			} else {
				$this->api->debug_log("✗ Failed to get token for $region");
				$results[$region]['token'] = false;
				$results[$region]['realms'] = 0;
			}
		}

		wp_send_json_success($results);
	}

	/**
	 * Render admin page (partial - just the documentation section as example)
	 * Full admin_page method would be similar to the original but with proper translations
	 */
	public function admin_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'wow-raid-progress'));
		}

		// Show custom success message
		if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
			echo '<div class="wow-raid-success-message">';
			echo '<strong>' . __('Settings saved successfully!', 'wow-raid-progress') . '</strong>';
			echo '</div>';
		}

		// Get current values (same as before)
		$api_key = get_option('wow_raid_progress_api_key', '');
		$guild_ids = get_option('wow_raid_progress_guild_ids', '');
		$expansion = get_option('wow_raid_progress_expansion', 10);
		$raid = get_option('wow_raid_progress_raid', '');
		$difficulty = get_option('wow_raid_progress_difficulty', 'highest');
		$region = get_option('wow_raid_progress_region', 'eu');
		$realm = get_option('wow_raid_progress_realm', '');
		$cache_time = get_option('wow_raid_progress_cache_time', 60);
		$show_icons = get_option('wow_raid_progress_show_icons', 'true');
		$show_killed = get_option('wow_raid_progress_show_killed', 'false');
		$use_blizzard_icons = get_option('wow_raid_progress_use_blizzard_icons', 'true');
		$show_raid_name = get_option('wow_raid_progress_show_raid_name', 'false');
		$show_raid_icon = get_option('wow_raid_progress_show_raid_icon', 'false');
		$limit = get_option('wow_raid_progress_limit', 50);
		$blizzard_client_id = get_option('wow_raid_progress_blizzard_client_id', '');
		$blizzard_client_secret = get_option('wow_raid_progress_blizzard_client_secret', '');
		$blizzard_region = get_option('wow_raid_progress_blizzard_region', 'eu');
		$debug_mode = get_option('wow_raid_progress_debug_mode', 'false');

		// Get expansions
		$expansions = $this->api->get_expansions();

		// The full HTML output would be the same as the original admin_page() method
		// but with all strings properly wrapped in __() for translation
		// I'm showing just a snippet here due to space constraints

		include WOW_RAID_PROGRESS_PLUGIN_DIR . 'templates/admin-page.php';
	}
}
