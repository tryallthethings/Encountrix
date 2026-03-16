<?php

/**
 * Admin page template
 *
 * @package Encountrix
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
	exit;
}
?>

<div class="wrap encountrix-admin">
	<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

	<div class="encountrix-tabs">
		<h2 class="nav-tab-wrapper">
			<a href="#general" class="nav-tab nav-tab-active"><?php esc_html_e('General Settings', 'encountrix'); ?></a>
			<a href="#display" class="nav-tab"><?php esc_html_e('Display Settings', 'encountrix'); ?></a>
			<a href="#tools" class="nav-tab"><?php esc_html_e('Tools', 'encountrix'); ?></a>
			<a href="#documentation" class="nav-tab"><?php esc_html_e('Documentation', 'encountrix'); ?></a>
		</h2>
	</div>

	<form method="post" action="options.php">
		<?php settings_fields('encountrix_settings'); ?>

		<!-- General Settings Tab -->
		<div id="general" class="tab-content active">
			<div class="encountrix-settings-section">
				<h2><?php esc_html_e('Raider.io API Configuration', 'encountrix'); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="encountrix_api_key"><?php esc_html_e('Raider.io API Key', 'encountrix'); ?></label>
						</th>
						<td>
							<input type="text" id="encountrix_api_key" name="encountrix_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to Raider.io */
									esc_html__('Get your API key from %s', 'encountrix'),
									'<a href="https://raider.io/api" target="_blank">Raider.io</a>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="encountrix-settings-section">
				<h2><?php esc_html_e('Blizzard API Configuration', 'encountrix'); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to Battle.net Developer Portal */
						esc_html__('Required for boss icons and realm list. Get credentials from %s', 'encountrix'),
						'<a href="https://develop.battle.net" target="_blank">Battle.net Developer Portal</a>'
					);
					?>
				</p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="encountrix_blizzard_region"><?php esc_html_e('Blizzard Region', 'encountrix'); ?></label></th>
						<td>
							<select id="encountrix_blizzard_region" name="encountrix_blizzard_region">
								<option value="us" <?php selected($blizzard_region, 'us'); ?>><?php esc_html_e('US', 'encountrix'); ?></option>
								<option value="eu" <?php selected($blizzard_region, 'eu'); ?>><?php esc_html_e('EU', 'encountrix'); ?></option>
								<option value="kr" <?php selected($blizzard_region, 'kr'); ?>><?php esc_html_e('KR', 'encountrix'); ?></option>
								<option value="tw" <?php selected($blizzard_region, 'tw'); ?>><?php esc_html_e('TW', 'encountrix'); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_blizzard_client_id"><?php esc_html_e('Client ID', 'encountrix'); ?></label></th>
						<td><input type="text" id="encountrix_blizzard_client_id" name="encountrix_blizzard_client_id" value="<?php echo esc_attr($blizzard_client_id); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_blizzard_client_secret"><?php esc_html_e('Client Secret', 'encountrix'); ?></label></th>
						<td><input type="password" id="encountrix_blizzard_client_secret" name="encountrix_blizzard_client_secret" value="<?php echo esc_attr($blizzard_client_secret); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_debug_mode"><?php esc_html_e('Debug Mode', 'encountrix'); ?></label></th>
						<td>
							<select id="encountrix_debug_mode" name="encountrix_debug_mode">
								<option value="false" <?php selected($debug_mode, 'false'); ?>><?php esc_html_e('Disabled', 'encountrix'); ?></option>
								<option value="true" <?php selected($debug_mode, 'true'); ?>><?php esc_html_e('Enabled', 'encountrix'); ?></option>
							</select>
							<p class="description"><?php esc_html_e('Enable debug logging for API calls (admin only)', 'encountrix'); ?></p>
						</td>
					</tr>
					<?php if ($debug_mode === 'true'): ?>
						<tr>
							<th scope="row"><?php esc_html_e('Debug Log', 'encountrix'); ?></th>
							<td>
								<div id="debug-log" style="background: #f0f0f0; padding: 10px; border-radius: 4px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;">
									<div class="debug-log-content"></div>
								</div>
								<button type="button" class="button" id="clear-debug-log"><?php esc_html_e('Clear Log', 'encountrix'); ?></button>
								<button type="button" class="button" id="test-blizzard-api"><?php esc_html_e('Test Blizzard API', 'encountrix'); ?></button>
							</td>
						</tr>
					<?php endif; ?>
				</table>
			</div>

			<div class="encountrix-settings-section">
				<h2><?php esc_html_e('Default Guild Settings', 'encountrix'); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="encountrix_guild_ids"><?php esc_html_e('Guild IDs', 'encountrix'); ?></label></th>
						<td>
							<input type="text" id="encountrix_guild_ids" name="encountrix_guild_ids" value="<?php echo esc_attr($guild_ids); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e('Comma-separated Raider.io guild IDs (max 10). Leave empty to show realm rankings.', 'encountrix'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_region"><?php esc_html_e('Default Region', 'encountrix'); ?></label></th>
						<td>
							<select id="encountrix_region" name="encountrix_region">
								<option value="us" <?php selected($region, 'us'); ?>><?php esc_html_e('US', 'encountrix'); ?></option>
								<option value="eu" <?php selected($region, 'eu'); ?>><?php esc_html_e('EU', 'encountrix'); ?></option>
								<option value="kr" <?php selected($region, 'kr'); ?>><?php esc_html_e('KR', 'encountrix'); ?></option>
								<option value="tw" <?php selected($region, 'tw'); ?>><?php esc_html_e('TW', 'encountrix'); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_realm"><?php esc_html_e('Default Realm', 'encountrix'); ?></label></th>
						<td>
							<div class="encountrix-realm-selector">
								<input type="text" id="encountrix_realm" name="encountrix_realm" value="<?php echo esc_attr($realm); ?>" class="regular-text" placeholder="<?php esc_attr_e('Type to search realms...', 'encountrix'); ?>" autocomplete="off" />
								<div class="encountrix-realm-dropdown" id="realm-dropdown" style="display: none;">
									<div class="encountrix-realm-list" id="realm-list"></div>
								</div>
							</div>
							<p class="description"><?php esc_html_e('Select a realm or leave empty for region-wide rankings.', 'encountrix'); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="encountrix-settings-section">
				<h2><?php esc_html_e('Default Raid Settings', 'encountrix'); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="encountrix_expansion"><?php esc_html_e('Expansion', 'encountrix'); ?></label></th>
						<td>
							<select id="encountrix_expansion" name="encountrix_expansion">
								<?php foreach ($expansions as $id => $exp): ?>
									<option value="<?php echo esc_attr($id); ?>" <?php selected($expansion, $id); ?>><?php echo esc_html($exp['name']); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_raid"><?php esc_html_e('Default Raid', 'encountrix'); ?></label></th>
						<td>
							<div class="inline-form-elements">
								<select id="encountrix_raid" name="encountrix_raid" data-current-value="<?php echo esc_attr($raid); ?>">
									<option value=""><?php esc_html_e('Loading raids...', 'encountrix'); ?></option>
								</select>
								<button type="button" class="button" id="refresh-raids-btn"><?php esc_html_e('Refresh Raids', 'encountrix'); ?></button>
							</div>
							<p class="description"><?php esc_html_e('Select a raid from the chosen expansion', 'encountrix'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_difficulty"><?php esc_html_e('Default Difficulty', 'encountrix'); ?></label></th>
						<td>
							<select id="encountrix_difficulty" name="encountrix_difficulty">
								<option value="highest" <?php selected($difficulty, 'highest'); ?>><?php esc_html_e('Highest Progress', 'encountrix'); ?></option>
								<option value="all" <?php selected($difficulty, 'all'); ?>><?php esc_html_e('All Difficulties', 'encountrix'); ?></option>
								<option value="normal" <?php selected($difficulty, 'normal'); ?>><?php esc_html_e('Normal', 'encountrix'); ?></option>
								<option value="heroic" <?php selected($difficulty, 'heroic'); ?>><?php esc_html_e('Heroic', 'encountrix'); ?></option>
								<option value="mythic" <?php selected($difficulty, 'mythic'); ?>><?php esc_html_e('Mythic', 'encountrix'); ?></option>
							</select>
						</td>
					</tr>
				</table>
			</div>

			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Save Changes', 'encountrix'); ?></button></p>
		</div>

		<!-- Display Settings Tab -->
		<div id="display" class="tab-content">
			<div class="encountrix-settings-section">
				<h2><?php esc_html_e('Display Options', 'encountrix'); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="encountrix_show_raid_name"><?php esc_html_e('Show Raid Name', 'encountrix'); ?></label></th>
						<td>
							<select id="encountrix_show_raid_name" name="encountrix_show_raid_name">
								<option value="true" <?php selected($show_raid_name, 'true'); ?>><?php esc_html_e('Yes', 'encountrix'); ?></option>
								<option value="false" <?php selected($show_raid_name, 'false'); ?>><?php esc_html_e('No', 'encountrix'); ?></option>
							</select>
							<p class="description"><?php esc_html_e('Display the raid name as a header in the widget', 'encountrix'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_show_icons"><?php esc_html_e('Show Boss Icons', 'encountrix'); ?></label></th>
						<td>
							<select id="encountrix_show_icons" name="encountrix_show_icons">
								<option value="true" <?php selected($show_icons, 'true'); ?>><?php esc_html_e('Yes', 'encountrix'); ?></option>
								<option value="false" <?php selected($show_icons, 'false'); ?>><?php esc_html_e('No', 'encountrix'); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_show_raid_icon"><?php esc_html_e('Show Raid Background', 'encountrix'); ?></label></th>
						<td>
							<select id="encountrix_show_raid_icon" name="encountrix_show_raid_icon">
								<option value="true" <?php selected($show_raid_icon, 'true'); ?>><?php esc_html_e('Yes', 'encountrix'); ?></option>
								<option value="false" <?php selected($show_raid_icon, 'false'); ?>><?php esc_html_e('No', 'encountrix'); ?></option>
							</select>
							<p class="description"><?php esc_html_e('Display the raid journal background image in the header (requires Blizzard API)', 'encountrix'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_show_killed"><?php esc_html_e('Show Defeated Bosses', 'encountrix'); ?></label></th>
						<td>
							<select id="encountrix_show_killed" name="encountrix_show_killed">
								<option value="true" <?php selected($show_killed, 'true'); ?>><?php esc_html_e('Yes', 'encountrix'); ?></option>
								<option value="false" <?php selected($show_killed, 'false'); ?>><?php esc_html_e('No', 'encountrix'); ?></option>
							</select>
							<p class="description"><?php esc_html_e('When set to No, defeated bosses will be hidden from the list', 'encountrix'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_use_blizzard_icons"><?php esc_html_e('Use Blizzard Icons', 'encountrix'); ?></label></th>
						<td>
							<select id="encountrix_use_blizzard_icons" name="encountrix_use_blizzard_icons">
								<option value="true" <?php selected($use_blizzard_icons, 'true'); ?>><?php esc_html_e('Yes', 'encountrix'); ?></option>
								<option value="false" <?php selected($use_blizzard_icons, 'false'); ?>><?php esc_html_e('No', 'encountrix'); ?></option>
							</select>
							<p class="description"><?php esc_html_e('Download and display official Blizzard achievement icons', 'encountrix'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_cache_time"><?php esc_html_e('Cache Duration', 'encountrix'); ?></label></th>
						<td>
							<input type="number" id="encountrix_cache_time" name="encountrix_cache_time" value="<?php echo esc_attr($cache_time); ?>" min="0" max="1440" class="small-text" />
							<?php esc_html_e('minutes', 'encountrix'); ?>
							<p class="description"><?php esc_html_e('How long to cache API data (0 to disable caching, max 1440)', 'encountrix'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="encountrix_limit"><?php esc_html_e('Results Limit', 'encountrix'); ?></label></th>
						<td>
							<input type="number" id="encountrix_limit" name="encountrix_limit" value="<?php echo esc_attr($limit); ?>" min="1" max="100" class="small-text" />
							<p class="description"><?php esc_html_e('Maximum number of guilds to display (1-100)', 'encountrix'); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Save Changes', 'encountrix'); ?></button></p>
		</div>
	</form>

	<!-- Tools Tab -->
	<div id="tools" class="tab-content">
		<div class="encountrix-settings-section">
			<h2><?php esc_html_e('Icon Management', 'encountrix'); ?></h2>
			<div class="encountrix-tool-card">
				<h3><?php esc_html_e('Import Boss Icons', 'encountrix'); ?></h3>
				<p><?php esc_html_e('Download and import boss achievement icons for the selected raid from Blizzard API.', 'encountrix'); ?></p>
				<button type="button" class="button button-primary" id="import-icons-btn"><?php esc_html_e('Import Icons', 'encountrix'); ?></button>
				<div id="import-status" class="notice" style="display: none;"></div>
			</div>

			<div class="encountrix-tool-card warning">
				<h3><?php esc_html_e('Delete All Icons', 'encountrix'); ?></h3>
				<p><strong><?php esc_html_e('Warning:', 'encountrix'); ?></strong> <?php esc_html_e('This will permanently delete ALL imported boss and raid icons from your media library.', 'encountrix'); ?></p>
				<button type="button" class="button button-danger" id="delete-icons-btn"><?php esc_html_e('Delete All Icons', 'encountrix'); ?></button>
				<span id="delete-icons-message" class="notice notice-success" style="display: none;"></span>
			</div>
		</div>

		<div class="encountrix-settings-section">
			<h2><?php esc_html_e('Cache Management', 'encountrix'); ?></h2>
			<div class="encountrix-tool-card">
				<h3><?php esc_html_e('Clear All Cache', 'encountrix'); ?></h3>
				<p><?php esc_html_e('Remove all cached API data and force fresh data retrieval.', 'encountrix'); ?></p>
				<button type="button" class="button" id="clear-cache-btn"><?php esc_html_e('Clear Cache', 'encountrix'); ?></button>
				<span id="cache-clear-message" class="notice notice-success" style="display: none;"><?php esc_html_e('Cache cleared successfully!', 'encountrix'); ?></span>
			</div>
		</div>
	</div>

	<!-- Documentation Tab -->
	<div id="documentation" class="tab-content">
		<div class="encountrix-settings-section">
			<h2><?php esc_html_e('Shortcode Documentation', 'encountrix'); ?></h2>

			<div class="encountrix-doc-section">
				<h3><?php esc_html_e('Basic Usage', 'encountrix'); ?></h3>
				<p><?php esc_html_e('Use the following shortcode to display raid progress:', 'encountrix'); ?></p>
				<div class="shortcode-wrapper">
					<code class="shortcode-example" data-shortcode="[encountrix]">[encountrix]</code>
					<button type="button" class="copy-shortcode button button-small" title="<?php esc_attr_e('Copy to clipboard', 'encountrix'); ?>">
						<span class="dashicons dashicons-clipboard"></span>
						<span class="copy-text"><?php esc_html_e('Copy', 'encountrix'); ?></span>
					</button>
				</div>
				<p><?php esc_html_e('This will use all default settings configured above.', 'encountrix'); ?></p>
			</div>

			<div class="encountrix-doc-section">
				<h3><?php esc_html_e('Available Parameters', 'encountrix'); ?></h3>
				<ul>
					<li><code>raid</code> - <?php esc_html_e('Raid slug (e.g., nerub-ar-palace)', 'encountrix'); ?></li>
					<li><code>difficulty</code> - <?php esc_html_e('Difficulty to display: highest, all, normal, heroic, or mythic', 'encountrix'); ?></li>
					<li><code>region</code> - <?php esc_html_e('Region: us, eu, kr, or tw', 'encountrix'); ?></li>
					<li><code>realm</code> - <?php esc_html_e('Specific realm name', 'encountrix'); ?></li>
					<li><code>guilds</code> - <?php esc_html_e('Comma-separated guild IDs (max 10)', 'encountrix'); ?></li>
					<li><code>cache</code> - <?php esc_html_e('Cache duration in minutes', 'encountrix'); ?></li>
					<li><code>show_raid_name</code> - <?php esc_html_e('Show raid name header: true or false', 'encountrix'); ?></li>
					<li><code>show_icons</code> - <?php esc_html_e('Show boss icons: true or false', 'encountrix'); ?></li>
					<li><code>limit</code> - <?php esc_html_e('Maximum number of guilds to display', 'encountrix'); ?></li>
				</ul>
			</div>

			<div class="encountrix-doc-section">
				<h3><?php esc_html_e('Example Shortcodes', 'encountrix'); ?></h3>

				<h4><?php esc_html_e('Display specific guild progress:', 'encountrix'); ?></h4>
				<div class="shortcode-wrapper">
					<code class="shortcode-example" data-shortcode='[encountrix guilds="12345" raid="nerub-ar-palace" difficulty="mythic"]'>[encountrix guilds="12345" raid="nerub-ar-palace" difficulty="mythic"]</code>
					<button type="button" class="copy-shortcode button button-small">
						<span class="dashicons dashicons-clipboard"></span>
						<span class="copy-text"><?php esc_html_e('Copy', 'encountrix'); ?></span>
					</button>
				</div>

				<h4><?php esc_html_e('Display realm rankings:', 'encountrix'); ?></h4>
				<div class="shortcode-wrapper">
					<code class="shortcode-example" data-shortcode='[encountrix region="us" realm="Stormrage" limit="10"]'>[encountrix region="us" realm="Stormrage" limit="10"]</code>
					<button type="button" class="copy-shortcode button button-small">
						<span class="dashicons dashicons-clipboard"></span>
						<span class="copy-text"><?php esc_html_e('Copy', 'encountrix'); ?></span>
					</button>
				</div>
			</div>
		</div>
	</div>
</div>
