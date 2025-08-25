<?php

/**
 * Main plugin class
 */

if (!defined('ABSPATH')) {
	exit;
}

class WoWRaidProgress {

	private $version;
	private $plugin_name;
	private $admin;
	private $api;
	private $widget;

	public function __construct() {
		$this->version = WOW_RAID_PROGRESS_VERSION;
		$this->plugin_name = 'wow-raid-progress';
		$this->load_dependencies();
	}

	private function load_dependencies() {
		$this->api = new WoWRaidProgressAPI();
		$this->admin = new WoWRaidProgressAdmin($this->plugin_name, $this->version, $this->api);
		$this->widget = new WoWRaidProgressWidget($this->api);
	}

	private function define_hooks() {
		// Admin hooks
		add_action('admin_menu', [$this->admin, 'add_admin_menu']);
		add_action('admin_init', [$this->admin, 'register_settings']);
		add_action('admin_enqueue_scripts', [$this->admin, 'enqueue_admin_assets']);

		// AJAX hooks
		add_action('wp_ajax_wow_raid_clear_cache', [$this->admin, 'ajax_clear_cache']);
                add_action('wp_ajax_wow_raid_import_icons', [$this->admin, 'ajax_import_icons']);
                add_action('wp_ajax_wow_raid_delete_icons', [$this->admin, 'ajax_delete_icons']);
                add_action('wp_ajax_wow_raid_get_raids', [$this->admin, 'ajax_get_raids']);
		add_action('wp_ajax_wow_raid_refresh_raids', [$this->admin, 'ajax_refresh_raids']);
		add_action('wp_ajax_wow_raid_get_realms', [$this->admin, 'ajax_get_realms']);
		add_action('wp_ajax_wow_raid_get_debug_log', [$this->admin, 'ajax_get_debug_log']);
		add_action('wp_ajax_wow_raid_clear_debug_log', [$this->admin, 'ajax_clear_debug_log']);
		add_action('wp_ajax_wow_raid_test_blizzard_api', [$this->admin, 'ajax_test_blizzard_api']);

		// Frontend hooks
		add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
		add_shortcode('wow_raid_progress', [$this->widget, 'render_shortcode']);
	}

	public function run() {
		// Initialize the plugin
		$this->define_hooks();
	}

	public function enqueue_frontend_assets() {
		if ($this->should_load_assets()) {
			wp_enqueue_style(
				'wow-raid-progress',
				WOW_RAID_PROGRESS_PLUGIN_URL . 'assets/css/wow-raid-progress.css',
				[],
				$this->version
			);

			// Optionally enqueue JavaScript if needed
			wp_enqueue_script(
				'wow-raid-progress',
				WOW_RAID_PROGRESS_PLUGIN_URL . 'assets/js/wow-raid-progress.js',
				['jquery'],
				$this->version,
				true
			);

			// Localize script for AJAX if needed
			wp_localize_script('wow-raid-progress', 'wow_raid_ajax', [
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('wow_raid_frontend_nonce')
			]);
		}
	}

	private function should_load_assets() {
		global $post;

		// Check if shortcode is present in the content
		if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'wow_raid_progress')) {
			return true;
		}

		// Check if widget is active
		if (is_active_widget(false, false, 'wow_raid_progress_widget', true)) {
			return true;
		}

		return false;
	}
}
