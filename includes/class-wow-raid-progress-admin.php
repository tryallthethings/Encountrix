<?php

/**
 * Admin settings class
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
	 * Add admin menu
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
	 * Register settings
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
	}

	/**
	 * Sanitize expansion setting
	 */
	public function sanitize_expansion($value) {
		$value = absint($value);
		$valid_expansions = array_keys($this->api->get_expansions());
		return in_array($value, $valid_expansions) ? $value : 10;
	}

	/**
	 * Sanitize difficulty setting
	 */
	public function sanitize_difficulty($value) {
		$valid = ['all', 'normal', 'heroic', 'mythic', 'highest'];
		return in_array($value, $valid) ? $value : 'highest';
	}

	/**
	 * Sanitize region setting
	 */
	public function sanitize_region($value) {
		$valid = ['us', 'eu', 'kr', 'tw'];
		return in_array($value, $valid) ? $value : 'eu';
	}

	/**
	 * Sanitize cache time
	 */
	public function sanitize_cache_time($value) {
		$value = absint($value);
		return min(1440, max(0, $value)); // Max 24 hours
	}

	/**
	 * Sanitize limit
	 */
	public function sanitize_limit($value) {
		$value = absint($value);
		return min(100, max(1, $value));
	}

	/**
	 * Sanitize boolean string
	 */
	public function sanitize_boolean_string($value) {
		return in_array($value, ['true', 'false']) ? $value : 'false';
	}

	/**
	 * Sanitize cached raids
	 */
	public function sanitize_cached_raids($value) {
		if (!is_array($value)) {
			return [];
		}
		return $value;
	}

	/**
	 * Enqueue admin assets
	 */
	public function enqueue_admin_assets($hook) {
		// This condition might be too restrictive
		if ($hook !== 'settings_page_wow-raid-progress') {
			return;
		}

		// Make sure the path is correct
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
	 * Admin page HTML
	 */
	public function admin_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'wow-raid-progress'));
		}

		// Show custom success message instead of WordPress default
		if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
			echo '<div class="wow-raid-success-message">';
			echo '<strong>' . __('Settings saved successfully!', 'wow-raid-progress') . '</strong>';
			echo '</div>';
		}

		// Get current values
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

		// Get expansions
		$expansions = $this->api->get_expansions();
?>

		<div class="wrap wow-raid-progress-admin">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

			<div class="wow-raid-tabs">
				<h2 class="nav-tab-wrapper">
					<a href="#general" class="nav-tab nav-tab-active"><?php _e('General Settings', 'wow-raid-progress'); ?></a>
					<a href="#display" class="nav-tab"><?php _e('Display Settings', 'wow-raid-progress'); ?></a>
					<a href="#tools" class="nav-tab"><?php _e('Tools', 'wow-raid-progress'); ?></a>
					<a href="#documentation" class="nav-tab"><?php _e('Documentation', 'wow-raid-progress'); ?></a>
				</h2>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields('wow_raid_progress_settings'); ?>

				<!-- General Settings Tab -->
				<div id="general" class="tab-content active">
					<div class="wow-raid-settings-section">
						<h2><?php _e('API Configuration', 'wow-raid-progress'); ?></h2>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_api_key">
										<?php _e('Raider.io API Key', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<input type="text"
										id="wow_raid_progress_api_key"
										name="wow_raid_progress_api_key"
										value="<?php echo esc_attr($api_key); ?>"
										class="regular-text" />
									<p class="description">
										<?php _e('Get your API key from', 'wow-raid-progress'); ?>
										<a href="https://raider.io/api" target="_blank">Raider.io</a>
									</p>
								</td>
							</tr>
							<!-- Add Blizzard API settings here -->
							<tr>
								<th scope="row" colspan="2">
									<h3 style="margin: 20px 0 10px 0; padding-top: 20px; border-top: 1px solid #ddd;">
										<?php _e('Blizzard API (Optional)', 'wow-raid-progress'); ?>
									</h3>
									<p class="description" style="font-weight: normal;">
										<?php _e('Required for boss icons and realm list. Get credentials from', 'wow-raid-progress'); ?>
										<a href="https://develop.battle.net" target="_blank">Battle.net Developer Portal</a>
									</p>
								</th>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_blizzard_region">
										<?php _e('Blizzard Region', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<select id="wow_raid_progress_blizzard_region" name="wow_raid_progress_blizzard_region">
										<option value="us" <?php selected($blizzard_region, 'us'); ?>>US</option>
										<option value="eu" <?php selected($blizzard_region, 'eu'); ?>>EU</option>
										<option value="kr" <?php selected($blizzard_region, 'kr'); ?>>KR</option>
										<option value="tw" <?php selected($blizzard_region, 'tw'); ?>>TW</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_blizzard_client_id">
										<?php _e('Client ID', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<input type="text"
										id="wow_raid_progress_blizzard_client_id"
										name="wow_raid_progress_blizzard_client_id"
										value="<?php echo esc_attr($blizzard_client_id); ?>"
										class="regular-text" />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_blizzard_client_secret">
										<?php _e('Client Secret', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<input type="password"
										id="wow_raid_progress_blizzard_client_secret"
										name="wow_raid_progress_blizzard_client_secret"
										value="<?php echo esc_attr($blizzard_client_secret); ?>"
										class="regular-text" />
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="wow_raid_progress_debug_mode">
										<?php _e('Debug Mode', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<select id="wow_raid_progress_debug_mode" name="wow_raid_progress_debug_mode">
										<option value="false" <?php selected(get_option('wow_raid_progress_debug_mode', 'false'), 'false'); ?>>
											<?php _e('Disabled', 'wow-raid-progress'); ?>
										</option>
										<option value="true" <?php selected(get_option('wow_raid_progress_debug_mode', 'false'), 'true'); ?>>
											<?php _e('Enabled', 'wow-raid-progress'); ?>
										</option>
									</select>
									<p class="description">
										<?php _e('Enable debug logging for API calls (admin only)', 'wow-raid-progress'); ?>
									</p>
								</td>
							</tr>
							<?php if (get_option('wow_raid_progress_debug_mode', 'false') === 'true'): ?>
								<tr>
									<th scope="row"><?php _e('Debug Log', 'wow-raid-progress'); ?></th>
									<td>
										<div id="debug-log" style="background: #f0f0f0; padding: 10px; border-radius: 4px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;">
											<div class="debug-log-content"></div>
										</div>
										<button type="button" class="button" id="clear-debug-log"><?php _e('Clear Log', 'wow-raid-progress'); ?></button>
										<button type="button" class="button" id="test-blizzard-api"><?php _e('Test Blizzard API', 'wow-raid-progress'); ?></button>
									</td>
								</tr>
							<?php endif; ?>
						</table>
					</div>
					<div class="wow-raid-settings-section">
						<h2><?php _e('Default Guild Settings', 'wow-raid-progress'); ?></h2>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_guild_ids">
										<?php _e('Guild IDs', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<input type="text"
										id="wow_raid_progress_guild_ids"
										name="wow_raid_progress_guild_ids"
										value="<?php echo esc_attr($guild_ids); ?>"
										class="regular-text" />
									<p class="description">
										<?php _e('Comma-separated Raider.io guild IDs (max 10). Leave empty to show realm rankings.', 'wow-raid-progress'); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_region">
										<?php _e('Default Region', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<select id="wow_raid_progress_region" name="wow_raid_progress_region">
										<option value="us" <?php selected($region, 'us'); ?>>US</option>
										<option value="eu" <?php selected($region, 'eu'); ?>>EU</option>
										<option value="kr" <?php selected($region, 'kr'); ?>>KR</option>
										<option value="tw" <?php selected($region, 'tw'); ?>>TW</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_realm">
										<?php _e('Default Realm', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<div class="wow-realm-selector">
										<input type="text"
											id="wow_raid_progress_realm"
											name="wow_raid_progress_realm"
											value="<?php echo esc_attr($realm); ?>"
											class="regular-text"
											placeholder="<?php _e('Type to search realms...', 'wow-raid-progress'); ?>"
											autocomplete="off" />
										<div class="wow-realm-dropdown" id="realm-dropdown" style="display: none;">
											<div class="wow-realm-list" id="realm-list">
												<!-- Populated via JavaScript -->
											</div>
										</div>
									</div>
									<p class="description">
										<?php _e('Select a realm or leave empty for region-wide rankings.', 'wow-raid-progress'); ?>
									</p>
								</td>
							</tr>
						</table>
					</div>

					<div class="wow-raid-settings-section">
						<h2><?php _e('Default Raid Settings', 'wow-raid-progress'); ?></h2>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_expansion">
										<?php _e('Expansion', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<select id="wow_raid_progress_expansion" name="wow_raid_progress_expansion">
										<?php foreach ($expansions as $id => $exp): ?>
											<option value="<?php echo esc_attr($id); ?>" <?php selected($expansion, $id); ?>>
												<?php echo esc_html($exp['name']); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_raid">
										<?php _e('Default Raid', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<div class="inline-form-elements">
										<select id="wow_raid_progress_raid"
											name="wow_raid_progress_raid"
											data-current-value="<?php echo esc_attr(get_option('wow_raid_progress_raid', '')); ?>">
											<option value=""><?php _e('Loading raids...', 'wow-raid-progress'); ?></option>
										</select>
										<button type="button" class="button" id="refresh-raids-btn">
											<?php _e('Refresh Raids', 'wow-raid-progress'); ?>
										</button>
									</div>
									<p class="description">
										<?php _e('Select a raid from the chosen expansion', 'wow-raid-progress'); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_difficulty">
										<?php _e('Default Difficulty', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<select id="wow_raid_progress_difficulty" name="wow_raid_progress_difficulty">
										<option value="highest" <?php selected($difficulty, 'highest'); ?>>
											<?php _e('Highest Progress', 'wow-raid-progress'); ?>
										</option>
										<option value="all" <?php selected($difficulty, 'all'); ?>>
											<?php _e('All Difficulties', 'wow-raid-progress'); ?>
										</option>
										<option value="normal" <?php selected($difficulty, 'normal'); ?>>
											<?php _e('Normal', 'wow-raid-progress'); ?>
										</option>
										<option value="heroic" <?php selected($difficulty, 'heroic'); ?>>
											<?php _e('Heroic', 'wow-raid-progress'); ?>
										</option>
										<option value="mythic" <?php selected($difficulty, 'mythic'); ?>>
											<?php _e('Mythic', 'wow-raid-progress'); ?>
										</option>
									</select>
								</td>
							</tr>
						</table>
					</div>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php _e('Save Changes', 'wow-raid-progress'); ?>
						</button>
					</p>
				</div>

				<!-- Display Settings Tab -->
				<div id="display" class="tab-content">
					<div class="wow-raid-settings-section">
						<h2><?php _e('Display Options', 'wow-raid-progress'); ?></h2>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_show_raid_name">
										<?php _e('Show Raid Name', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<select id="wow_raid_progress_show_raid_name" name="wow_raid_progress_show_raid_name">
										<option value="true" <?php selected($show_raid_name, 'true'); ?>>
											<?php _e('Yes', 'wow-raid-progress'); ?>
										</option>
										<option value="false" <?php selected($show_raid_name, 'false'); ?>>
											<?php _e('No', 'wow-raid-progress'); ?>
										</option>
									</select>
									<p class="description">
										<?php _e('Display the raid name as a header in the widget', 'wow-raid-progress'); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_show_raid_icon">
										<?php _e('Show Raid Icon', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<select id="wow_raid_progress_show_raid_icon" name="wow_raid_progress_show_raid_icon">
										<option value="true" <?php selected($show_raid_icon, 'true'); ?>>
											<?php _e('Yes', 'wow-raid-progress'); ?>
										</option>
										<option value="false" <?php selected($show_raid_icon, 'false'); ?>>
											<?php _e('No', 'wow-raid-progress'); ?>
										</option>
									</select>
									<p class="description">
										<?php _e('Display the raid achievement icon', 'wow-raid-progress'); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_show_icons">
										<?php _e('Show Boss Icons', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<select id="wow_raid_progress_show_icons" name="wow_raid_progress_show_icons">
										<option value="true" <?php selected($show_icons, 'true'); ?>>
											<?php _e('Yes', 'wow-raid-progress'); ?>
										</option>
										<option value="false" <?php selected($show_icons, 'false'); ?>>
											<?php _e('No', 'wow-raid-progress'); ?>
										</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_show_killed">
										<?php _e('Show Defeated Bosses', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<select id="wow_raid_progress_show_killed" name="wow_raid_progress_show_killed">
										<option value="true" <?php selected($show_killed, 'true'); ?>>
											<?php _e('Yes', 'wow-raid-progress'); ?>
										</option>
										<option value="false" <?php selected($show_killed, 'false'); ?>>
											<?php _e('No', 'wow-raid-progress'); ?>
										</option>
									</select>
									<p class="description">
										<?php _e('When set to No, defeated bosses will be hidden from the list', 'wow-raid-progress'); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_use_blizzard_icons">
										<?php _e('Use Blizzard Icons', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<select id="wow_raid_progress_use_blizzard_icons" name="wow_raid_progress_use_blizzard_icons">
										<option value="true" <?php selected($use_blizzard_icons, 'true'); ?>>
											<?php _e('Yes', 'wow-raid-progress'); ?>
										</option>
										<option value="false" <?php selected($use_blizzard_icons, 'false'); ?>>
											<?php _e('No', 'wow-raid-progress'); ?>
										</option>
									</select>
									<p class="description">
										<?php _e('Download and display official Blizzard achievement icons', 'wow-raid-progress'); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_limit">
										<?php _e('Results Limit', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<input type="number"
										id="wow_raid_progress_limit"
										name="wow_raid_progress_limit"
										value="<?php echo esc_attr($limit); ?>"
										min="1"
										max="100"
										class="small-text" />
									<p class="description">
										<?php _e('Maximum number of guilds to display (1-100)', 'wow-raid-progress'); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wow_raid_progress_cache_time">
										<?php _e('Cache Duration', 'wow-raid-progress'); ?>
									</label>
								</th>
								<td>
									<input type="number"
										id="wow_raid_progress_cache_time"
										name="wow_raid_progress_cache_time"
										value="<?php echo esc_attr($cache_time); ?>"
										min="0"
										max="1440"
										class="small-text" />
									<?php _e('minutes', 'wow-raid-progress'); ?>
									<p class="description">
										<?php _e('How long to cache API data (0 to disable caching, max 1440)', 'wow-raid-progress'); ?>
									</p>
								</td>
							</tr>
						</table>
					</div>
					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php _e('Save Changes', 'wow-raid-progress'); ?>
						</button>
					</p>
				</div>
			</form>

			<!-- Tools Tab -->
			<div id="tools" class="tab-content">
				<div class="wow-raid-settings-section">
					<h2><?php _e('Icon Management', 'wow-raid-progress'); ?></h2>
					<div class="wow-raid-tool-card">
						<h3><?php _e('Import Boss Icons', 'wow-raid-progress'); ?></h3>
						<p><?php _e('Download and import boss achievement icons for the selected raid from Blizzard API.', 'wow-raid-progress'); ?></p>
						<button type="button" class="button button-primary" id="import-icons-btn">
							<?php _e('Import Icons', 'wow-raid-progress'); ?>
						</button>
						<div id="import-status" class="notice" style="display: none;"></div>
					</div>

					<div class="wow-raid-tool-card warning">
						<h3><?php _e('Delete All Icons', 'wow-raid-progress'); ?></h3>
						<p><strong><?php _e('Warning:', 'wow-raid-progress'); ?></strong> <?php _e('This will permanently delete ALL imported boss and raid icons from your media library.', 'wow-raid-progress'); ?></p>
						<button type="button" class="button button-danger" id="delete-icons-btn">
							<?php _e('Delete All Icons', 'wow-raid-progress'); ?>
						</button>
						<span id="delete-icons-message" class="notice notice-success" style="display: none;"></span>
					</div>
				</div>

				<div class="wow-raid-settings-section">
					<h2><?php _e('Cache Management', 'wow-raid-progress'); ?></h2>
					<div class="wow-raid-tool-card">
						<h3><?php _e('Clear All Cache', 'wow-raid-progress'); ?></h3>
						<p><?php _e('Remove all cached API data and force fresh data retrieval.', 'wow-raid-progress'); ?></p>
						<button type="button" class="button" id="clear-cache-btn">
							<?php _e('Clear Cache', 'wow-raid-progress'); ?>
						</button>
						<span id="cache-clear-message" class="notice notice-success" style="display: none;">
							<?php _e('Cache cleared successfully!', 'wow-raid-progress'); ?>
						</span>
					</div>
				</div>
			</div>

			<!-- Documentation Tab -->
			<div id="documentation" class="tab-content">
				<div class="wow-raid-settings-section">
					<h2><?php _e('Shortcode Documentation', 'wow-raid-progress'); ?></h2>

					<div class="wow-raid-doc-section">
						<h3><?php _e('Basic Usage', 'wow-raid-progress'); ?></h3>
						<p><?php _e('Use the following shortcode to display raid progress:', 'wow-raid-progress'); ?></p>
						<div class="shortcode-wrapper">
							<code class="shortcode-example" data-shortcode="[wow_raid_progress]">[wow_raid_progress]</code>
							<button type="button" class="copy-shortcode button button-small" title="<?php esc_attr_e('Copy to clipboard', 'wow-raid-progress'); ?>">
								<span class="dashicons dashicons-clipboard"></span>
								<span class="copy-text"><?php _e('Copy', 'wow-raid-progress'); ?></span>
							</button>
						</div>
						<p><?php _e('This will use all default settings configured above.', 'wow-raid-progress'); ?></p>
					</div>

					<div class="wow-raid-doc-section">
						<h3><?php _e('Shortcode Parameters', 'wow-raid-progress'); ?></h3>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php _e('Parameter', 'wow-raid-progress'); ?></th>
									<th><?php _e('Description', 'wow-raid-progress'); ?></th>
									<th><?php _e('Default', 'wow-raid-progress'); ?></th>
									<th><?php _e('Example', 'wow-raid-progress'); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><code>raid</code></td>
									<td><?php _e('Raid slug (e.g., nerub-ar-palace)', 'wow-raid-progress'); ?></td>
									<td><?php _e('From settings', 'wow-raid-progress'); ?></td>
									<td><code>raid="nerub-ar-palace"</code></td>
								</tr>
								<tr>
									<td><code>difficulty</code></td>
									<td><?php _e('Difficulty to display: highest, all, normal, heroic, or mythic', 'wow-raid-progress'); ?></td>
									<td><?php _e('From settings', 'wow-raid-progress'); ?></td>
									<td><code>difficulty="mythic"</code></td>
								</tr>
								<tr>
									<td><code>region</code></td>
									<td><?php _e('Region: us, eu, kr, or tw', 'wow-raid-progress'); ?></td>
									<td><?php _e('From settings', 'wow-raid-progress'); ?></td>
									<td><code>region="us"</code></td>
								</tr>
								<tr>
									<td><code>realm</code></td>
									<td><?php _e('Specific realm name', 'wow-raid-progress'); ?></td>
									<td><?php _e('From settings', 'wow-raid-progress'); ?></td>
									<td><code>realm="Stormrage"</code></td>
								</tr>
								<tr>
									<td><code>guilds</code></td>
									<td><?php _e('Comma-separated guild IDs (max 10)', 'wow-raid-progress'); ?></td>
									<td><?php _e('From settings', 'wow-raid-progress'); ?></td>
									<td><code>guilds="12345,67890"</code></td>
								</tr>
								<tr>
									<td><code>cache</code></td>
									<td><?php _e('Cache duration in minutes', 'wow-raid-progress'); ?></td>
									<td><?php _e('From settings', 'wow-raid-progress'); ?></td>
									<td><code>cache="30"</code></td>
								</tr>
								<tr>
									<td><code>show_raid_name</code></td>
									<td><?php _e('Show raid name header: true or false', 'wow-raid-progress'); ?></td>
									<td><?php _e('From settings', 'wow-raid-progress'); ?></td>
									<td><code>show_raid_name="true"</code></td>
								</tr>
								<tr>
									<td><code>show_raid_icon</code></td>
									<td><?php _e('Show raid icon: true or false', 'wow-raid-progress'); ?></td>
									<td><?php _e('From settings', 'wow-raid-progress'); ?></td>
									<td><code>show_raid_icon="true"</code></td>
								</tr>
								<tr>
									<td><code>show_icons</code></td>
									<td><?php _e('Show boss icons: true or false', 'wow-raid-progress'); ?></td>
									<td><?php _e('From settings', 'wow-raid-progress'); ?></td>
									<td><code>show_icons="true"</code></td>
								</tr>
								<tr>
									<td><code>show_killed</code></td>
									<td><?php _e('Show defeated bosses: true or false', 'wow-raid-progress'); ?></td>
									<td><?php _e('From settings', 'wow-raid-progress'); ?></td>
									<td><code>show_killed="false"</code></td>
								</tr>
								<tr>
									<td><code>use_blizzard_icons</code></td>
									<td><?php _e('Use Blizzard icons: true or false', 'wow-raid-progress'); ?></td>
									<td><?php _e('From settings', 'wow-raid-progress'); ?></td>
									<td><code>use_blizzard_icons="true"</code></td>
								</tr>
								<tr>
									<td><code>limit</code></td>
									<td><?php _e('Maximum number of guilds to display', 'wow-raid-progress'); ?></td>
									<td><?php _e('From settings', 'wow-raid-progress'); ?></td>
									<td><code>limit="10"</code></td>
								</tr>
								<tr>
									<td><code>page</code></td>
									<td><?php _e('Page number for pagination', 'wow-raid-progress'); ?></td>
									<td>0</td>
									<td><code>page="1"</code></td>
								</tr>
							</tbody>
						</table>
					</div>

					<div class="wow-raid-doc-section">
						<h3><?php _e('Example Shortcodes', 'wow-raid-progress'); ?></h3>

						<h4><?php _e('Display specific guild progress:', 'wow-raid-progress'); ?></h4>
						<div class="shortcode-wrapper">
							<code class="shortcode-example" data-shortcode='[wow_raid_progress guilds="12345" raid="nerub-ar-palace" difficulty="mythic"]'>[wow_raid_progress guilds="12345" raid="nerub-ar-palace" difficulty="mythic"]</code>
							<button type="button" class="copy-shortcode button button-small" title="<?php esc_attr_e('Copy to clipboard', 'wow-raid-progress'); ?>">
								<span class="dashicons dashicons-clipboard"></span>
								<span class="copy-text"><?php _e('Copy', 'wow-raid-progress'); ?></span>
							</button>
						</div>

						<h4><?php _e('Display multiple guilds:', 'wow-raid-progress'); ?></h4>
						<div class="shortcode-wrapper">
							<code class="shortcode-example" data-shortcode='[wow_raid_progress guilds="12345,67890,11111" difficulty="all"]'>[wow_raid_progress guilds="12345,67890,11111" difficulty="all"]</code>
							<button type="button" class="copy-shortcode button button-small" title="<?php esc_attr_e('Copy to clipboard', 'wow-raid-progress'); ?>">
								<span class="dashicons dashicons-clipboard"></span>
								<span class="copy-text"><?php _e('Copy', 'wow-raid-progress'); ?></span>
							</button>
						</div>

						<h4><?php _e('Display realm rankings:', 'wow-raid-progress'); ?></h4>
						<div class="shortcode-wrapper">
							<code class="shortcode-example" data-shortcode='[wow_raid_progress region="us" realm="Stormrage" limit="10"]'>[wow_raid_progress region="us" realm="Stormrage" limit="10"]</code>
							<button type="button" class="copy-shortcode button button-small" title="<?php esc_attr_e('Copy to clipboard', 'wow-raid-progress'); ?>">
								<span class="dashicons dashicons-clipboard"></span>
								<span class="copy-text"><?php _e('Copy', 'wow-raid-progress'); ?></span>
							</button>
						</div>

						<h4><?php _e('Display with raid header:', 'wow-raid-progress'); ?></h4>
						<div class="shortcode-wrapper">
							<code class="shortcode-example" data-shortcode='[wow_raid_progress show_raid_name="true" show_raid_icon="true"]'>[wow_raid_progress show_raid_name="true" show_raid_icon="true"]</code>
							<button type="button" class="copy-shortcode button button-small" title="<?php esc_attr_e('Copy to clipboard', 'wow-raid-progress'); ?>">
								<span class="dashicons dashicons-clipboard"></span>
								<span class="copy-text"><?php _e('Copy', 'wow-raid-progress'); ?></span>
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
<?php
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

		$static_data = $this->api->fetch_static_raid_data_by_expansion($expansion_id, 0);
		if (is_wp_error($static_data) || !isset($static_data['raids'])) {
			wp_send_json_error(__('Could not fetch raid data', 'wow-raid-progress'));
			return;
		}

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
		foreach ($raid_info['encounters'] as $boss) {
			$result = $this->api->import_single_boss_icon($boss['slug'], $boss['name'], $raid_slug, $expansion_id);
			if (is_numeric($result)) {
				$log[] = sprintf(__('SUCCESS: Imported icon for %s (ID: %d)', 'wow-raid-progress'), $boss['name'], $result);
			} elseif ($result === true) {
				$log[] = sprintf(__('INFO: Icon for %s already exists', 'wow-raid-progress'), $boss['name']);
			} else {
				$log[] = sprintf(__('ERROR: Failed to import icon for %s: %s', 'wow-raid-progress'), $boss['name'], $result);
			}
		}

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
			wp_send_json_success($cached_raids[$expansion_id]);
			return;
		}

		// Fetch from API
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

	public function sanitize_realm($value) {
		return $this->api->sanitize_realm($value);
	}

	public function sanitize_guilds($value) {
		return $this->api->sanitize_guilds($value);
	}

	/**
	 * Get available realms from Blizzard API
	 */
	public function get_realms_list($region = 'eu') {
		$debug_mode = get_option('wow_raid_progress_debug_mode', 'false') === 'true';

		try {
			if ($debug_mode) {
				$this->debug_log("Getting realms for region: " . $region);
			}

			$cache_key = $this->blizzard_cache_prefix . 'realms_' . $region;
			$cached_realms = get_transient($cache_key);
			if ($cached_realms !== false) {
				if ($debug_mode) {
					$this->debug_log("Using cached realms for " . $region . " (" . count($cached_realms) . " realms)");
				}
				return $cached_realms;
			}

			$token = $this->get_blizzard_token($region);
			if (!$token) {
				if ($debug_mode) {
					$this->debug_log("ERROR: Could not get token for region: " . $region);
				}
				return [];
			}

			if ($debug_mode) {
				$this->debug_log("Got token for " . $region . ": " . substr($token, 0, 10) . "...");
			}

			$api_base = $this->regional_endpoints[$region]['api'] ?? $this->regional_endpoints['eu']['api'];
			$namespace = 'dynamic-' . $region;

			$realms_url = add_query_arg([
				'namespace' => $namespace,
				'locale' => 'en_US'
			], "{$api_base}/data/wow/realm/index");

			if ($debug_mode) {
				$this->debug_log("Requesting: " . $realms_url);
			}

			$response = wp_remote_get($realms_url, [
				'timeout' => $this->http_timeout,
				'headers' => array_merge($this->base_headers(), [
					'Authorization' => 'Bearer ' . $token
				]),
			]);

			if (is_wp_error($response)) {
				if ($debug_mode) {
					$this->debug_log("ERROR: " . $response->get_error_message());
				}
				return [];
			}

			$response_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($debug_mode) {
				$this->debug_log("Response code: " . $response_code);
				$this->debug_log("Response body (first 500 chars): " . substr($body, 0, 500));
			}

			if ($response_code !== 200) {
				return [];
			}

			$data = json_decode($body, true);
			if (!isset($data['realms']) || !is_array($data['realms'])) {
				if ($debug_mode) {
					$this->debug_log("ERROR: No realms in response. Keys found: " . implode(', ', array_keys($data ?: [])));
				}
				return [];
			}

			$realms = [];
			foreach ($data['realms'] as $realm) {
				if (isset($realm['slug']) && isset($realm['name'])) {
					$realms[] = [
						'slug' => $realm['slug'],
						'name' => $realm['name'],
						'id' => $realm['id'] ?? 0
					];
				}
			}

			if ($debug_mode) {
				$this->debug_log("Found " . count($realms) . " realms for " . $region);
			}

			usort($realms, function ($a, $b) {
				return strcasecmp($a['name'], $b['name']);
			});

			set_transient($cache_key, $realms, 30 * DAY_IN_SECONDS);
			return $realms;
		} catch (Exception $e) {
			if ($debug_mode) {
				$this->debug_log("EXCEPTION: " . $e->getMessage());
			}
			return [];
		}
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

		// Enable debug mode temporarily
		$original_debug = get_option('wow_raid_progress_debug_mode', 'false');
		update_option('wow_raid_progress_debug_mode', 'true');

		$results = [];
		$regions = ['us', 'eu', 'kr', 'tw'];

		foreach ($regions as $region) {
			$this->api->debug_log("=== Testing region: $region ===");

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

		// Restore original debug setting
		update_option('wow_raid_progress_debug_mode', $original_debug);

		wp_send_json_success($results);
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

		$realms = $this->api->get_realms_list($region);

		if (empty($realms)) {
			// Provide more specific error message
			$error_message = __('Could not fetch realms.', 'wow-raid-progress');

			wp_send_json_error($error_message);
			return;
		}

		wp_send_json_success($realms);
	}
}
