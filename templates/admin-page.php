<?php

/**
 * Admin page template
 *
 * @package WoWRaidProgress
 * @since 5.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}
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
				<h2><?php _e('Raider.io API Configuration', 'wow-raid-progress'); ?></h2>
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
								<?php
								printf(
									__('Get your API key from %s', 'wow-raid-progress'),
									'<a href="https://raider.io/api" target="_blank">Raider.io</a>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Blizzard API Tab -->
			<div class="wow-raid-settings-section">
				<h2><?php _e('Blizzard API Configuration', 'wow-raid-progress'); ?></h2>
				<p class="description">
					<?php
					printf(
						__('Required for boss icons and realm list. Get credentials from %s', 'wow-raid-progress'),
						'<a href="https://develop.battle.net" target="_blank">Battle.net Developer Portal</a>'
					);
					?>
				</p>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="wow_raid_progress_blizzard_region">
								<?php _e('Blizzard Region', 'wow-raid-progress'); ?>
							</label>
						</th>
						<td>
							<select id="wow_raid_progress_blizzard_region" name="wow_raid_progress_blizzard_region">
								<option value="us" <?php selected($blizzard_region, 'us'); ?>><?php _e('US', 'wow-raid-progress'); ?></option>
								<option value="eu" <?php selected($blizzard_region, 'eu'); ?>><?php _e('EU', 'wow-raid-progress'); ?></option>
								<option value="kr" <?php selected($blizzard_region, 'kr'); ?>><?php _e('KR', 'wow-raid-progress'); ?></option>
								<option value="tw" <?php selected($blizzard_region, 'tw'); ?>><?php _e('TW', 'wow-raid-progress'); ?></option>
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
								<option value="false" <?php selected($debug_mode, 'false'); ?>>
									<?php _e('Disabled', 'wow-raid-progress'); ?>
								</option>
								<option value="true" <?php selected($debug_mode, 'true'); ?>>
									<?php _e('Enabled', 'wow-raid-progress'); ?>
								</option>
							</select>
							<p class="description">
								<?php _e('Enable debug logging for API calls (admin only)', 'wow-raid-progress'); ?>
							</p>
						</td>
					</tr>
					<?php if ($debug_mode === 'true'): ?>
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
								<option value="us" <?php selected($region, 'us'); ?>><?php _e('US', 'wow-raid-progress'); ?></option>
								<option value="eu" <?php selected($region, 'eu'); ?>><?php _e('EU', 'wow-raid-progress'); ?></option>
								<option value="kr" <?php selected($region, 'kr'); ?>><?php _e('KR', 'wow-raid-progress'); ?></option>
								<option value="tw" <?php selected($region, 'tw'); ?>><?php _e('TW', 'wow-raid-progress'); ?></option>
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
									placeholder="<?php esc_attr_e('Type to search realms...', 'wow-raid-progress'); ?>"
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
									data-current-value="<?php echo esc_attr($raid); ?>">
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
							<label for="wow_raid_progress_show_raid_icon">
								<?php _e('Show Raid Background', 'wow-raid-progress'); ?>
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
								<?php _e('Display the raid journal background image in the header (requires Blizzard API)', 'wow-raid-progress'); ?>
							</p>
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
				<h3><?php _e('Available Parameters', 'wow-raid-progress'); ?></h3>
				<ul>
					<li><code>raid</code> - <?php _e('Raid slug (e.g., nerub-ar-palace)', 'wow-raid-progress'); ?></li>
					<li><code>difficulty</code> - <?php _e('Difficulty to display: highest, all, normal, heroic, or mythic', 'wow-raid-progress'); ?></li>
					<li><code>region</code> - <?php _e('Region: us, eu, kr, or tw', 'wow-raid-progress'); ?></li>
					<li><code>realm</code> - <?php _e('Specific realm name', 'wow-raid-progress'); ?></li>
					<li><code>guilds</code> - <?php _e('Comma-separated guild IDs (max 10)', 'wow-raid-progress'); ?></li>
					<li><code>cache</code> - <?php _e('Cache duration in minutes', 'wow-raid-progress'); ?></li>
					<li><code>show_raid_name</code> - <?php _e('Show raid name header: true or false', 'wow-raid-progress'); ?></li>
					<li><code>show_icons</code> - <?php _e('Show boss icons: true or false', 'wow-raid-progress'); ?></li>
					<li><code>limit</code> - <?php _e('Maximum number of guilds to display', 'wow-raid-progress'); ?></li>
				</ul>
			</div>

			<div class="wow-raid-doc-section">
				<h3><?php _e('Example Shortcodes', 'wow-raid-progress'); ?></h3>

				<h4><?php _e('Display specific guild progress:', 'wow-raid-progress'); ?></h4>
				<div class="shortcode-wrapper">
					<code class="shortcode-example" data-shortcode='[wow_raid_progress guilds="12345" raid="nerub-ar-palace" difficulty="mythic"]'>[wow_raid_progress guilds="12345" raid="nerub-ar-palace" difficulty="mythic"]</code>
					<button type="button" class="copy-shortcode button button-small">
						<span class="dashicons dashicons-clipboard"></span>
						<span class="copy-text"><?php _e('Copy', 'wow-raid-progress'); ?></span>
					</button>
				</div>

				<h4><?php _e('Display realm rankings:', 'wow-raid-progress'); ?></h4>
				<div class="shortcode-wrapper">
					<code class="shortcode-example" data-shortcode='[wow_raid_progress region="us" realm="Stormrage" limit="10"]'>[wow_raid_progress region="us" realm="Stormrage" limit="10"]</code>
					<button type="button" class="copy-shortcode button button-small">
						<span class="dashicons dashicons-clipboard"></span>
						<span class="copy-text"><?php _e('Copy', 'wow-raid-progress'); ?></span>
					</button>
				</div>
			</div>
		</div>
	</div>
</div>
