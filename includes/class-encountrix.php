<?php

/**
 * Main plugin class
 */

if (!defined('ABSPATH')) {
	exit;
}

class Encountrix {

	private $version;
	private $plugin_name;
	private $admin;
	private $api;
	private $widget;

	public function __construct() {
		$this->version = ENCOUNTRIX_VERSION;
		$this->plugin_name = 'encountrix';
		$this->load_dependencies();
	}

	private function load_dependencies() {
		$this->api = new EncountrixAPI();
		$this->admin = new EncountrixAdmin($this->plugin_name, $this->version, $this->api);
		$this->widget = new EncountrixWidget($this->api);
	}

	private function define_hooks() {
		// Admin hooks
		add_action('admin_menu', [$this->admin, 'add_admin_menu']);
		add_action('admin_init', [$this->admin, 'register_settings']);
		add_action('admin_enqueue_scripts', [$this->admin, 'enqueue_admin_assets']);

		// AJAX hooks
		add_action('wp_ajax_encountrix_clear_cache', [$this->admin, 'ajax_clear_cache']);
                add_action('wp_ajax_encountrix_import_icons', [$this->admin, 'ajax_import_icons']);
                add_action('wp_ajax_encountrix_delete_icons', [$this->admin, 'ajax_delete_icons']);
                add_action('wp_ajax_encountrix_get_raids', [$this->admin, 'ajax_get_raids']);
		add_action('wp_ajax_encountrix_refresh_raids', [$this->admin, 'ajax_refresh_raids']);
		add_action('wp_ajax_encountrix_get_realms', [$this->admin, 'ajax_get_realms']);
		add_action('wp_ajax_encountrix_get_debug_log', [$this->admin, 'ajax_get_debug_log']);
		add_action('wp_ajax_encountrix_clear_debug_log', [$this->admin, 'ajax_clear_debug_log']);
		add_action('wp_ajax_encountrix_test_blizzard_api', [$this->admin, 'ajax_test_blizzard_api']);

		// Frontend hooks
		add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
		add_shortcode('encountrix', [$this->widget, 'render_shortcode']);
	}

	public function run() {
		$this->define_hooks();
	}

	public function enqueue_frontend_assets() {
		if ($this->should_load_assets()) {
			wp_enqueue_style(
				'encountrix',
				ENCOUNTRIX_PLUGIN_URL . 'assets/css/encountrix.css',
				[],
				$this->version
			);

			wp_enqueue_script(
				'encountrix',
				ENCOUNTRIX_PLUGIN_URL . 'assets/js/encountrix.js',
				['jquery'],
				$this->version,
				true
			);

			wp_localize_script('encountrix', 'encountrix_ajax', [
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('encountrix_frontend_nonce')
			]);
		}
	}

	private function should_load_assets() {
		global $post;

		if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'encountrix')) {
			return true;
		}

		if (is_active_widget(false, false, 'encountrix_widget', true)) {
			return true;
		}

		return false;
	}
}
