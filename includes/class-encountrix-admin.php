<?php

/**
 * Encountrix Admin Handler
 *
 * Manages admin interface, settings, and AJAX handlers
 *
 * @package Encountrix
 * @since 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EncountrixAdmin {

	private string $plugin_name;
	private string $version;
	private EncountrixApi $api;

	public function __construct( string $plugin_name, string $version, EncountrixApi $api ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->api         = $api;

		add_action( 'update_option_encountrix_api_key', array( $this, 'validate_raiderio_key_on_save' ), 10, 2 );
		add_action( 'update_option_encountrix_blizzard_client_id', array( $this, 'validate_blizzard_credentials_on_save' ), 10, 0 );
		add_action( 'update_option_encountrix_blizzard_client_secret', array( $this, 'validate_blizzard_credentials_on_save' ), 10, 0 );
	}

	/**
	 * Validate the Raider.io API key when it is saved and persist the result.
	 *
	 * Makes a lightweight static-data request to confirm the key is accepted.
	 *
	 * @since 5.1.0
	 *
	 * @param string $old_value Previous key value.
	 * @param string $new_value Newly saved key value.
	 * @return void
	 */
	public function validate_raiderio_key_on_save( string $old_value, string $new_value ): void {
		if ( empty( $new_value ) ) {
			update_option( 'encountrix_raiderio_key_valid', false );
			return;
		}

		$url = add_query_arg(
			array(
				'access_key'   => $new_value,
				'expansion_id' => 10,
			),
			'https://raider.io/api/v1/raiding/static-data'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		$valid = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
		update_option( 'encountrix_raiderio_key_valid', $valid );
	}

	/**
	 * Validate Blizzard API credentials when either is saved and persist the result.
	 *
	 * Attempts an OAuth token fetch to confirm the client ID / secret pair works.
	 *
	 * @since 5.1.0
	 *
	 * @return void
	 */
	public function validate_blizzard_credentials_on_save(): void {
		$client_id     = get_option( 'encountrix_blizzard_client_id', '' );
		$client_secret = get_option( 'encountrix_blizzard_client_secret', '' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			update_option( 'encountrix_blizzard_credentials_valid', false );
			return;
		}

		// Clear any cached token so we test the new credentials.
		$region    = get_option( 'encountrix_blizzard_region', 'eu' );
		$cache_key = 'encountrix_blizzard_oauth_token_' . $region;
		delete_transient( $cache_key );

		$valid = false !== $this->api->get_blizzard_token( $region );
		update_option( 'encountrix_blizzard_credentials_valid', $valid );
	}

	/**
	 * Register the plugin settings page under the Settings menu.
	 *
	 * @since 5.0.0
	 */
	public function add_admin_menu(): void {
		add_options_page(
			__( 'Encountrix Settings', 'encountrix' ),
			__( 'Encountrix', 'encountrix' ),
			'manage_options',
			'encountrix',
			array( $this, 'admin_page' )
		);
	}

	/**
	 * Register all plugin settings with the WordPress Settings API.
	 *
	 * @since 5.0.0
	 */
	public function register_settings(): void {
		// API Settings
		register_setting(
			'encountrix_settings',
			'encountrix_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		// Display Settings
		register_setting(
			'encountrix_settings',
			'encountrix_guild_ids',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_guilds' ),
				'default'           => '',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_expansion',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_expansion' ),
				'default'           => 10,
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_raid',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_difficulty',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_difficulty' ),
				'default'           => 'highest',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_region',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_region' ),
				'default'           => 'eu',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_realm',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_realm' ),
				'default'           => '',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_cache_time',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_cache_time' ),
				'default'           => 60,
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_show_icons',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_boolean_string' ),
				'default'           => 'true',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_show_killed',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_boolean_string' ),
				'default'           => 'false',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_use_blizzard_icons',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_boolean_string' ),
				'default'           => 'true',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_show_raid_name',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_boolean_string' ),
				'default'           => 'false',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_show_raid_icon',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_boolean_string' ),
				'default'           => 'false',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_limit',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_limit' ),
				'default'           => 50,
			)
		);

		// Blizzard API Settings
		register_setting(
			'encountrix_settings',
			'encountrix_blizzard_client_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_blizzard_client_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_blizzard_region',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_region' ),
				'default'           => 'eu',
			)
		);

		// Cached raids
		register_setting(
			'encountrix_settings',
			'encountrix_cached_raids',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_cached_raids' ),
				'default'           => array(),
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_debug_mode',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_boolean_string' ),
				'default'           => 'false',
			)
		);

		register_setting(
			'encountrix_settings',
			'encountrix_use_cron',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_boolean_string' ),
				'default'           => 'false',
			)
		);
	}

	/**
	 * Sanitize the expansion setting value.
	 *
	 * @since 5.0.0
	 *
	 * @param mixed $value Raw input value.
	 * @return int Validated expansion ID, or 10 as default.
	 */
	public function sanitize_expansion( mixed $value ): int {
		$value            = absint( $value );
		$valid_expansions = array_keys( $this->api->get_expansions() );
		return in_array( $value, $valid_expansions, true ) ? $value : 10;
	}

	/**
	 * Sanitize the difficulty setting value.
	 *
	 * @since 5.0.0
	 *
	 * @param mixed $value Raw input value.
	 * @return string Validated difficulty string, or 'highest' as default.
	 */
	public function sanitize_difficulty( mixed $value ): string {
		$value = (string) $value;
		$valid = array( 'all', 'normal', 'heroic', 'mythic', 'highest' );
		return in_array( $value, $valid, true ) ? $value : 'highest';
	}

	/**
	 * Sanitize the region setting value.
	 *
	 * @since 5.0.0
	 *
	 * @param mixed $value Raw input value.
	 * @return string Validated region code, or 'eu' as default.
	 */
	public function sanitize_region( mixed $value ): string {
		$value = (string) $value;
		$valid = array( 'us', 'eu', 'kr', 'tw' );
		return in_array( $value, $valid, true ) ? $value : 'eu';
	}

	/**
	 * Sanitize the cache time setting value.
	 *
	 * @since 5.0.0
	 *
	 * @param mixed $value Raw input value.
	 * @return int Cache time in minutes, clamped between 0 and 1440.
	 */
	public function sanitize_cache_time( mixed $value ): int {
		$value = absint( $value );
		return min( 1440, max( 0, $value ) );
	}

	/**
	 * Sanitize the results limit setting value.
	 *
	 * @since 5.0.0
	 *
	 * @param mixed $value Raw input value.
	 * @return int Results limit, clamped between 1 and 100.
	 */
	public function sanitize_limit( mixed $value ): int {
		$value = absint( $value );
		return min( 100, max( 1, $value ) );
	}

	/**
	 * Sanitize a boolean-as-string setting value.
	 *
	 * @since 5.0.0
	 *
	 * @param mixed $value Raw input value.
	 * @return string 'true' or 'false'.
	 */
	public function sanitize_boolean_string( mixed $value ): string {
		$value = (string) $value;
		return in_array( $value, array( 'true', 'false' ), true ) ? $value : 'false';
	}

	/**
	 * Sanitize the cached raids option value.
	 *
	 * @since 5.0.0
	 *
	 * @param mixed $value Raw input value.
	 * @return array Sanitized array of cached raid data.
	 */
	public function sanitize_cached_raids( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return $this->sanitize_array_recursive( $value );
	}

	/**
	 * Recursively sanitize an array's values.
	 *
	 * @param array $arr Input array.
	 * @return array Sanitized array with keys preserved.
	 */
	private function sanitize_array_recursive( array $arr ): array {
		$sanitized = array();
		foreach ( $arr as $key => $val ) {
			if ( is_array( $val ) ) {
				$sanitized[ $key ] = $this->sanitize_array_recursive( $val );
			} elseif ( is_int( $val ) ) {
				$sanitized[ $key ] = intval( $val );
			} elseif ( is_float( $val ) ) {
				$sanitized[ $key ] = floatval( $val );
			} elseif ( is_bool( $val ) ) {
				$sanitized[ $key ] = $val;
			} elseif ( is_string( $val ) ) {
				$sanitized[ $key ] = sanitize_text_field( $val );
			}
		}
		return $sanitized;
	}

	/**
	 * Sanitize the realm setting value.
	 *
	 * Delegates to the API class for realm-specific validation.
	 *
	 * @since 5.0.0
	 *
	 * @param mixed $value Raw input value.
	 * @return string Sanitized realm slug.
	 */
	public function sanitize_realm( mixed $value ): string {
		return $this->api->sanitize_realm( (string) $value );
	}

	/**
	 * Sanitize the guild IDs setting value.
	 *
	 * Delegates to the API class for guild ID validation.
	 *
	 * @since 5.0.0
	 *
	 * @param mixed $value Raw input value.
	 * @return string Sanitized comma-separated guild IDs.
	 */
	public function sanitize_guilds( mixed $value ): string {
		return $this->api->sanitize_guilds( $value ?? '' );
	}

	/**
	 * Enqueue admin CSS and JavaScript on the plugin settings page.
	 *
	 * @since 5.0.0
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		// Only load assets on the plugin settings page
		if ( $hook !== 'settings_page_encountrix' ) {
			return;
		}

		wp_enqueue_style(
			'encountrix-admin',
			ENCOUNTRIX_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			$this->version
		);

		wp_enqueue_script(
			'encountrix-admin',
			ENCOUNTRIX_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			$this->version,
			true
		);

		wp_localize_script(
			'encountrix-admin',
			'encountrix_admin',
			array(
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'encountrix_admin_nonce' ),
				'current_raid' => get_option( 'encountrix_raid', '' ),
				'strings'      => array(
					'loading'                      => __( 'Loading...', 'encountrix' ),
					'success'                      => __( 'Success!', 'encountrix' ),
					'error'                        => __( 'Error:', 'encountrix' ),
					'unknown_error'                => __( 'Unknown error', 'encountrix' ),
					'confirm_clear'                => __( 'Are you sure you want to clear the cache?', 'encountrix' ),
					'confirm_import'               => __( 'This will import boss icons for the selected raid. Continue?', 'encountrix' ),
					'confirm_delete_icons'         => __( 'WARNING: This will permanently delete ALL imported icons from your media library. This cannot be undone. Are you sure?', 'encountrix' ),
					'no_api_key_continue'          => __( 'No API key is set. The plugin will not be able to fetch raid data. Continue anyway?', 'encountrix' ),
					'unsaved_changes'              => __( 'You have unsaved changes. Are you sure you want to leave?', 'encountrix' ),
					'settings_saved'               => __( 'Settings saved successfully!', 'encountrix' ),
					'select_raid_placeholder'      => __( '-- Select a Raid --', 'encountrix' ),
					'error_loading_raids'          => __( 'Error loading raids', 'encountrix' ),
					'failed_load_raids'            => __( 'Failed to load raids', 'encountrix' ),
					'raids_refreshed_success'      => __( 'Raids refreshed successfully!', 'encountrix' ),
					'failed_refresh_raids'         => __( 'Failed to refresh raids', 'encountrix' ),
					'configure_blizzard_api_first' => __( 'Please configure Blizzard API credentials first', 'encountrix' ),
					'select_raid_first'            => __( 'Please select a raid first', 'encountrix' ),
					'importing'                    => __( 'Importing...', 'encountrix' ),
					'deleting'                     => __( 'Deleting...', 'encountrix' ),
					'starting_import'              => __( 'Starting import process...', 'encountrix' ),
					'import_complete'              => __( 'Import Complete!', 'encountrix' ),
					'failed_delete_icons'          => __( 'Failed to delete icons', 'encountrix' ),
					'failed_clear_cache'           => __( 'Failed to clear cache', 'encountrix' ),
					'invalid_guild_ids'            => __( 'Invalid guild IDs:', 'encountrix' ),
					'guild_ids_numeric'            => __( 'Guild IDs must be numeric.', 'encountrix' ),
					'max_guild_ids'                => __( 'Maximum 10 guild IDs allowed.', 'encountrix' ),
					'realm_too_long'               => __( 'Realm name is too long (max 50 characters).', 'encountrix' ),
					'cache_time_invalid'           => __( 'Cache time must be between 0 and 1440 minutes.', 'encountrix' ),
					'limit_invalid'                => __( 'Results limit must be between 1 and 100.', 'encountrix' ),
					'please_fix_errors'            => __( 'Please fix the following errors:', 'encountrix' ),
					'dismiss_notice'               => __( 'Dismiss this notice.', 'encountrix' ),
					'log_cleared'                  => __( 'Log cleared', 'encountrix' ),
					'no_log_entries'               => __( 'No log entries', 'encountrix' ),
					'error_loading_realms'         => __( 'Could not load realms. You can still type a realm name manually.', 'encountrix' ),
					'no_realms_found'              => __( 'No realms found for this region.', 'encountrix' ),
					'no_matching_realms'           => __( 'No realms match your search.', 'encountrix' ),
					'copied'                       => __( 'Copied!', 'encountrix' ),
					'press_ctrl_c'                 => __( 'Press Ctrl+C to copy', 'encountrix' ),
					'asian_region_note'            => __( 'For KR/TW regions, you may need to enter the realm name manually.', 'encountrix' ),
				),
			)
		);
	}

	/**
	 * Delete transients matching the given option name prefixes.
	 *
	 * Uses prepared statements for safe wildcard deletion against the
	 * options table.
	 *
	 * @since 5.0.0
	 *
	 * @param array<string> $prefixes Option name prefixes (without trailing %).
	 */
	private function delete_transients_by_prefix( array $prefixes ): void {
		global $wpdb;
		foreach ( $prefixes as $prefix ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}
	}

	/**
	 * Handle AJAX request to clear the plugin transient cache.
	 *
	 * @since 5.0.0
	 */
	public function ajax_clear_cache(): void {
		if ( ! check_ajax_referer( 'encountrix_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed', 'encountrix' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'encountrix' ) );
			return;
		}

		$this->delete_transients_by_prefix(
			array(
				'_transient_encountrix_',
				'_transient_timeout_encountrix_',
				'_transient_encountrix_blizzard_',
				'_transient_timeout_encountrix_blizzard_',
			)
		);

		// Clear rate limit data
		delete_transient( 'encountrix_api_rate_limit' );

		$this->api->debug_log( 'Cache cleared by admin' );

		wp_send_json_success( __( 'Cache cleared successfully', 'encountrix' ) );
	}

	/**
	 * Handle AJAX request to import boss icons for the selected raid.
	 *
	 * Iterates over all encounters in the configured raid and imports
	 * each boss icon into the WordPress media library. Returns a log
	 * distinguishing newly imported, already existing, and failed icons.
	 *
	 * @since 5.0.0
	 */
	public function ajax_import_icons(): void {
		if ( ! check_ajax_referer( 'encountrix_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed', 'encountrix' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'encountrix' ) );
			return;
		}

		$expansion_id = get_option( 'encountrix_expansion', 10 );
		$raid_slug    = get_option( 'encountrix_raid', '' );

		if ( empty( $raid_slug ) ) {
			wp_send_json_error( __( 'No raid selected in settings', 'encountrix' ) );
			return;
		}

		$this->api->debug_log( "Starting icon import for raid: $raid_slug" );

		// Get static raid data
		$static_data = $this->api->fetch_static_raid_data_by_expansion( $expansion_id, 1440 );
		if ( is_wp_error( $static_data ) || ! isset( $static_data['raids'] ) ) {
			wp_send_json_error( __( 'Could not fetch raid data', 'encountrix' ) );
			return;
		}

		// Find the raid
		$raid_info = null;
		foreach ( $static_data['raids'] as $raid ) {
			if ( $raid['slug'] === $raid_slug ) {
				$raid_info = $raid;
				break;
			}
		}

		if ( ! $raid_info || ! isset( $raid_info['encounters'] ) ) {
			wp_send_json_error( sprintf( __( 'Could not find raid: %s', 'encountrix' ), $raid_slug ) );
			return;
		}

		$log           = array();
		$success_count = 0;
		$skip_count    = 0;
		$error_count   = 0;

		// Import icons for each boss
		foreach ( $raid_info['encounters'] as $boss ) {
			$result = $this->api->import_single_boss_icon(
				$boss['slug'],
				$boss['name'],
				$raid_slug,
				$expansion_id
			);

			if ( is_int( $result ) ) {
				++$success_count;
				$log[] = sprintf( __( 'SUCCESS: Imported icon for %1$s (ID: %2$d)', 'encountrix' ), $boss['name'], $result );
			} elseif ( $result === true ) {
				++$skip_count;
				$log[] = sprintf( __( 'INFO: Icon for %s already exists', 'encountrix' ), $boss['name'] );
			} else {
				++$error_count;
				$log[] = sprintf( __( 'ERROR: Failed to import icon for %s', 'encountrix' ), $boss['name'] );
			}

			// Throttle every 3 successful imports to avoid overloading the server.
			if ( $success_count > 0 && $success_count % 3 === 0 ) {
				usleep( 300000 ); // 300ms
			}
		}

		// Add summary
		$log[] = sprintf(
			__( 'Summary: %1$d imported, %2$d skipped, %3$d errors', 'encountrix' ),
			$success_count,
			$skip_count,
			$error_count
		);

		$this->api->debug_log( "Icon import completed: $success_count imported, $skip_count skipped, $error_count errors" );

		wp_send_json_success( array( 'log' => $log ) );
	}

	/**
	 * Handle AJAX request to delete all imported boss icons from the media library.
	 *
	 * @since 5.0.0
	 */
	public function ajax_delete_icons(): void {
		if ( ! check_ajax_referer( 'encountrix_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed', 'encountrix' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'encountrix' ) );
			return;
		}

		$this->api->debug_log( 'Deleting all plugin icons' );
		$deleted = $this->api->delete_all_icons();

		wp_send_json_success(
			sprintf(
				__( 'Successfully deleted %d icon(s) from the media library', 'encountrix' ),
				$deleted
			)
		);
	}

	/**
	 * Handle AJAX request to retrieve available raids for an expansion.
	 *
	 * Returns cached raid data when available, otherwise fetches from
	 * the Raider.IO API and stores the result for future requests.
	 *
	 * @since 5.0.0
	 */
	public function ajax_get_raids(): void {
		if ( ! check_ajax_referer( 'encountrix_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed', 'encountrix' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'encountrix' ) );
			return;
		}

		$expansion_id = isset( $_POST['expansion_id'] ) ? absint( $_POST['expansion_id'] ) : 10;

		// Validate expansion ID
		$valid_expansions = array_keys( $this->api->get_expansions() );
		if ( ! in_array( $expansion_id, $valid_expansions, true ) ) {
			wp_send_json_error( __( 'Invalid expansion ID', 'encountrix' ) );
			return;
		}

		// Try to get cached raids
		$cached_raids = get_option( 'encountrix_cached_raids', array() );
		if ( isset( $cached_raids[ $expansion_id ] ) && ! empty( $cached_raids[ $expansion_id ] ) ) {
			$this->api->debug_log( "Using cached raids for expansion $expansion_id" );
			wp_send_json_success( $cached_raids[ $expansion_id ] );
			return;
		}

		// Fetch from API
		$this->api->debug_log( "Fetching raids for expansion $expansion_id from API" );
		$static_data = $this->api->fetch_static_raid_data_by_expansion( $expansion_id, 1440 );
		if ( is_wp_error( $static_data ) || ! isset( $static_data['raids'] ) ) {
			wp_send_json_error( __( 'Could not fetch raids', 'encountrix' ) );
			return;
		}

		$raids = array();
		foreach ( $static_data['raids'] as $raid ) {
			$raids[] = array(
				'slug' => $raid['slug'],
				'name' => $raid['name'],
			);
		}

		// Cache the raids
		$cached_raids[ $expansion_id ] = $raids;
		update_option( 'encountrix_cached_raids', $cached_raids );

		wp_send_json_success( $raids );
	}

	/**
	 * Handle AJAX request to refresh the raids cache.
	 *
	 * Clears stored raid data and static-data transients, then
	 * delegates to {@see ajax_get_raids()} to fetch fresh results.
	 *
	 * @since 5.0.0
	 */
	public function ajax_refresh_raids(): void {
		if ( ! check_ajax_referer( 'encountrix_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed', 'encountrix' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'encountrix' ) );
			return;
		}

		$this->api->debug_log( 'Refreshing raids cache' );

		// Clear cached raids
		delete_option( 'encountrix_cached_raids' );

		// Clear transients for static data
		$this->delete_transients_by_prefix(
			array(
				'_transient_encountrix_static_',
				'_transient_timeout_encountrix_static_',
			)
		);

		// Now fetch fresh data
		$this->ajax_get_raids();
	}

	/**
	 * Handle AJAX request to retrieve available realms for a region.
	 *
	 * @since 5.0.0
	 */
	public function ajax_get_realms(): void {
		if ( ! check_ajax_referer( 'encountrix_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed', 'encountrix' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'encountrix' ) );
			return;
		}

		$region = isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : 'eu';

		// Validate region
		$valid_regions = array( 'us', 'eu', 'kr', 'tw' );
		if ( ! in_array( $region, $valid_regions, true ) ) {
			wp_send_json_error( __( 'Invalid region specified', 'encountrix' ) );
			return;
		}

		$this->api->debug_log( "Fetching realms for region: $region" );
		$realms = $this->api->get_realms_list( $region );

		if ( empty( $realms ) ) {
			$error_message = __( 'Could not fetch realms. Check Blizzard API settings.', 'encountrix' );
			wp_send_json_error( $error_message );
			return;
		}

		wp_send_json_success( $realms );
	}

	/**
	 * Handle AJAX request to retrieve the debug log entries.
	 *
	 * @since 5.0.0
	 */
	public function ajax_get_debug_log(): void {
		if ( ! check_ajax_referer( 'encountrix_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed', 'encountrix' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'encountrix' ) );
			return;
		}

		$log = get_transient( 'encountrix_debug_log' ) ?: array();
		wp_send_json_success( $log );
	}

	/**
	 * Handle AJAX request to clear the debug log.
	 *
	 * @since 5.0.0
	 */
	public function ajax_clear_debug_log(): void {
		if ( ! check_ajax_referer( 'encountrix_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed', 'encountrix' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'encountrix' ) );
			return;
		}

		delete_transient( 'encountrix_debug_log' );
		$this->api->debug_log( 'Debug log cleared' );
		wp_send_json_success( __( 'Debug log cleared', 'encountrix' ) );
	}

	/**
	 * Handle AJAX request to test Blizzard API connectivity across all regions.
	 *
	 * @since 5.0.0
	 */
	public function ajax_test_blizzard_api(): void {
		if ( ! check_ajax_referer( 'encountrix_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed', 'encountrix' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'encountrix' ) );
			return;
		}

		$results = array();
		$regions = array( 'us', 'eu', 'kr', 'tw' );

		foreach ( $regions as $region ) {
			$this->api->debug_log( "Testing Blizzard API for region: $region" );

			// Test token
			$token = $this->api->get_blizzard_token( $region );
			if ( $token ) {
				$this->api->debug_log( "✓ Token obtained for $region" );
				$results[ $region ]['token'] = true;

				// Test realms
				$realms                       = $this->api->get_realms_list( $region );
				$results[ $region ]['realms'] = count( $realms );
				$this->api->debug_log( '✓ Found ' . count( $realms ) . " realms for $region" );
			} else {
				$this->api->debug_log( "✗ Failed to get token for $region" );
				$results[ $region ]['token']  = false;
				$results[ $region ]['realms'] = 0;
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * Render the plugin settings page.
	 *
	 * @since 5.0.0
	 */
	public function admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'encountrix' ) );
		}

		// Show custom success message
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check on settings-updated flag set by WP core.
		$settings_updated = isset( $_GET['settings-updated'] ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : '';
		if ( 'true' === $settings_updated ) {
			echo '<div class="encountrix-success-message">';
			echo '<strong>' . esc_html__( 'Settings saved successfully!', 'encountrix' ) . '</strong>';
			echo '</div>';
		}

		// Get current values
		$api_key                = get_option( 'encountrix_api_key', '' );
		$guild_ids              = get_option( 'encountrix_guild_ids', '' );
		$expansion              = get_option( 'encountrix_expansion', 10 );
		$raid                   = get_option( 'encountrix_raid', '' );
		$difficulty             = get_option( 'encountrix_difficulty', 'highest' );
		$region                 = get_option( 'encountrix_region', 'eu' );
		$realm                  = get_option( 'encountrix_realm', '' );
		$cache_time             = get_option( 'encountrix_cache_time', 60 );
		$show_icons             = get_option( 'encountrix_show_icons', 'true' );
		$show_killed            = get_option( 'encountrix_show_killed', 'false' );
		$use_blizzard_icons     = get_option( 'encountrix_use_blizzard_icons', 'true' );
		$show_raid_name         = get_option( 'encountrix_show_raid_name', 'false' );
		$show_raid_icon         = get_option( 'encountrix_show_raid_icon', 'false' );
		$limit                  = get_option( 'encountrix_limit', 50 );
		$blizzard_client_id     = get_option( 'encountrix_blizzard_client_id', '' );
		$blizzard_client_secret = get_option( 'encountrix_blizzard_client_secret', '' );
		$blizzard_region        = get_option( 'encountrix_blizzard_region', 'eu' );
		$debug_mode             = get_option( 'encountrix_debug_mode', 'false' );

		// Get expansions
		$expansions = $this->api->get_expansions();

		// Persisted validation flags — set when API keys are saved.
		$has_raiderio_key = (bool) get_option( 'encountrix_raiderio_key_valid', false );
		$has_blizzard_api = (bool) get_option( 'encountrix_blizzard_credentials_valid', false );

		include ENCOUNTRIX_PLUGIN_DIR . 'templates/admin-page.php';
	}
}
