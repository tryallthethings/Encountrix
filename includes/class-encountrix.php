<?php
/**
 * Main Encountrix plugin class.
 *
 * Bootstraps plugin dependencies, registers WordPress hooks,
 * and manages front-end asset enqueueing.
 *
 * @package Encountrix
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Encountrix
 *
 * Orchestrates the plugin lifecycle: loads dependencies, wires up
 * admin / AJAX / front-end hooks, and conditionally enqueues assets.
 *
 * @since 1.0.0
 */
class Encountrix {

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Unique plugin identifier used for handles and option prefixes.
	 *
	 * @var string
	 */
	private string $plugin_name;

	/**
	 * Admin-facing functionality.
	 *
	 * @var EncountrixAdmin
	 */
	private EncountrixAdmin $admin;

	/**
	 * Blizzard API integration layer.
	 *
	 * @var EncountrixApi
	 */
	private EncountrixApi $api;

	/**
	 * Front-end widget and shortcode renderer.
	 *
	 * @var EncountrixWidget
	 */
	private EncountrixWidget $widget;

	/**
	 * Initialise properties and load dependent classes.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->version     = ENCOUNTRIX_VERSION;
		$this->plugin_name = 'encountrix';
		$this->load_dependencies();
	}

	/**
	 * Instantiate the API, admin, and widget components.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_dependencies(): void {
		$this->api    = new EncountrixApi();
		$this->admin  = new EncountrixAdmin( $this->plugin_name, $this->version, $this->api );
		$this->widget = new EncountrixWidget( $this->api );
	}

	/**
	 * Register all WordPress action and shortcode hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function define_hooks(): void {
		// Admin hooks.
		add_action( 'admin_menu', [ $this->admin, 'add_admin_menu' ] );
		add_action( 'admin_init', [ $this->admin, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this->admin, 'enqueue_admin_assets' ] );

		// AJAX hooks.
		add_action( 'wp_ajax_encountrix_clear_cache', [ $this->admin, 'ajax_clear_cache' ] );
		add_action( 'wp_ajax_encountrix_import_icons', [ $this->admin, 'ajax_import_icons' ] );
		add_action( 'wp_ajax_encountrix_delete_icons', [ $this->admin, 'ajax_delete_icons' ] );
		add_action( 'wp_ajax_encountrix_get_raids', [ $this->admin, 'ajax_get_raids' ] );
		add_action( 'wp_ajax_encountrix_refresh_raids', [ $this->admin, 'ajax_refresh_raids' ] );
		add_action( 'wp_ajax_encountrix_get_realms', [ $this->admin, 'ajax_get_realms' ] );
		add_action( 'wp_ajax_encountrix_get_debug_log', [ $this->admin, 'ajax_get_debug_log' ] );
		add_action( 'wp_ajax_encountrix_clear_debug_log', [ $this->admin, 'ajax_clear_debug_log' ] );
		add_action( 'wp_ajax_encountrix_test_blizzard_api', [ $this->admin, 'ajax_test_blizzard_api' ] );

		// Front-end hooks.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		add_shortcode( 'encountrix', [ $this->widget, 'render_shortcode' ] );
	}

	/**
	 * Boot the plugin by registering all hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function run(): void {
		$this->define_hooks();
	}

	/**
	 * Enqueue front-end CSS and JavaScript when needed.
	 *
	 * Assets are only loaded on pages that contain the [encountrix]
	 * shortcode or an active Encountrix widget.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		if ( $this->should_load_assets() ) {
			wp_enqueue_style(
				'encountrix',
				ENCOUNTRIX_PLUGIN_URL . 'assets/css/encountrix.css',
				[],
				$this->version
			);

			wp_enqueue_script(
				'encountrix',
				ENCOUNTRIX_PLUGIN_URL . 'assets/js/encountrix.js',
				[ 'jquery' ],
				$this->version,
				true
			);

			wp_localize_script( 'encountrix', 'encountrix_ajax', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'encountrix_frontend_nonce' ),
			] );
		}
	}

	/**
	 * Determine whether front-end assets should be enqueued.
	 *
	 * Returns true when the current page contains the [encountrix]
	 * shortcode or an active encountrix_widget instance.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if assets are required, false otherwise.
	 */
	private function should_load_assets(): bool {
		global $post;

		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'encountrix' ) ) {
			return true;
		}

		if ( is_active_widget( false, false, 'encountrix_widget', true ) ) {
			return true;
		}

		return false;
	}
}
