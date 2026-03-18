<?php

/**
 * Admin page template
 *
 * @package Encountrix
 * @since 5.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap encountrix-admin">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="encountrix-tabs">
		<h2 class="nav-tab-wrapper">
			<a href="#general" class="nav-tab nav-tab-active"><?php _e( 'General Settings', 'encountrix' ); ?></a>
			<?php if ( $has_raiderio_key ) : ?>
				<a href="#display" class="nav-tab"><?php _e( 'Display Settings', 'encountrix' ); ?></a>
				<a href="#tools" class="nav-tab"><?php _e( 'Tools', 'encountrix' ); ?></a>
			<?php endif; ?>
			<a href="#documentation" class="nav-tab"><?php _e( 'Documentation', 'encountrix' ); ?></a>
		</h2>
	</div>

	<form method="post" action="options.php">
		<?php settings_fields( 'encountrix_settings' ); ?>

		<!-- General Settings Tab -->
		<div id="general" class="tab-content active">
			<div class="encountrix-settings-section">
				<h2><?php _e( 'Raider.io API Configuration', 'encountrix' ); ?></h2>
				<p class="description">
					<?php _e( 'The Raider.io API key is required for the plugin to fetch raid rankings and guild progress data. Without it, the plugin cannot display any content.', 'encountrix' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="encountrix_api_key">
								<?php _e( 'Raider.io API Key', 'encountrix' ); ?>
							</label>
						</th>
						<td>
							<input type="text"
								id="encountrix_api_key"
								name="encountrix_api_key"
								value="<?php echo esc_attr( $api_key ); ?>"
								class="regular-text" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to Raider.io API page */
									__( 'Get your API key from %s. After entering your key, save the settings to unlock the remaining options.', 'encountrix' ),
									'<a href="https://raider.io/api" target="_blank">Raider.io</a>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Blizzard API -->
			<div class="encountrix-settings-section">
				<h2><?php _e( 'Blizzard API Configuration', 'encountrix' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to Battle.net Developer Portal */
						__( 'Optional. Enables boss icons, raid background images, and realm autocompletion. Get credentials from %s', 'encountrix' ),
						'<a href="https://develop.battle.net" target="_blank">Battle.net Developer Portal</a>'
					);
					?>
				</p>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="encountrix_blizzard_region">
								<?php _e( 'Blizzard Region', 'encountrix' ); ?>
							</label>
						</th>
						<td>
							<select id="encountrix_blizzard_region" name="encountrix_blizzard_region">
								<option value="us" <?php selected( $blizzard_region, 'us' ); ?>><?php _e( 'US', 'encountrix' ); ?></option>
								<option value="eu" <?php selected( $blizzard_region, 'eu' ); ?>><?php _e( 'EU', 'encountrix' ); ?></option>
								<option value="kr" <?php selected( $blizzard_region, 'kr' ); ?>><?php _e( 'KR', 'encountrix' ); ?></option>
								<option value="tw" <?php selected( $blizzard_region, 'tw' ); ?>><?php _e( 'TW', 'encountrix' ); ?></option>
							</select>
							<p class="description">
								<?php _e( 'Must match the region of your Blizzard API application.', 'encountrix' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="encountrix_blizzard_client_id">
								<?php _e( 'Client ID', 'encountrix' ); ?>
							</label>
						</th>
						<td>
							<input type="text"
								id="encountrix_blizzard_client_id"
								name="encountrix_blizzard_client_id"
								value="<?php echo esc_attr( $blizzard_client_id ); ?>"
								class="regular-text" />
							<p class="description">
								<?php _e( 'Found in your Battle.net application details under "Client ID".', 'encountrix' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="encountrix_blizzard_client_secret">
								<?php _e( 'Client Secret', 'encountrix' ); ?>
							</label>
						</th>
						<td>
							<input type="password"
								id="encountrix_blizzard_client_secret"
								name="encountrix_blizzard_client_secret"
								value="<?php echo esc_attr( $blizzard_client_secret ); ?>"
								class="regular-text" />
							<p class="description">
								<?php _e( 'Found in your Battle.net application details under "Client Secret". Saved in the WordPress options table.', 'encountrix' ); ?>
							</p>
						</td>
					</tr>
					<?php if ( $has_blizzard_api ) : ?>
						<tr>
							<th scope="row">
								<label for="encountrix_debug_mode">
									<?php _e( 'Debug Mode', 'encountrix' ); ?>
								</label>
							</th>
							<td>
								<select id="encountrix_debug_mode" name="encountrix_debug_mode">
									<option value="false" <?php selected( $debug_mode, 'false' ); ?>>
										<?php _e( 'Disabled', 'encountrix' ); ?>
									</option>
									<option value="true" <?php selected( $debug_mode, 'true' ); ?>>
										<?php _e( 'Enabled', 'encountrix' ); ?>
									</option>
								</select>
								<p class="description">
									<?php _e( 'Logs all Blizzard API requests and responses. Useful for troubleshooting connectivity or authentication issues. Only visible to admins.', 'encountrix' ); ?>
								</p>
							</td>
						</tr>
						<?php if ( 'true' === $debug_mode ) : ?>
							<tr>
								<th scope="row"><?php _e( 'Debug Log', 'encountrix' ); ?></th>
								<td>
									<div id="debug-log" style="background: #f0f0f0; padding: 10px; border-radius: 4px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;">
										<div class="debug-log-content"></div>
									</div>
									<button type="button" class="button" id="clear-debug-log"><?php _e( 'Clear Log', 'encountrix' ); ?></button>
									<button type="button" class="button" id="test-blizzard-api"><?php _e( 'Test Blizzard API', 'encountrix' ); ?></button>
								</td>
							</tr>
						<?php endif; ?>
					<?php endif; ?>
				</table>
			</div>

			<?php if ( $has_raiderio_key ) : ?>
				<div class="encountrix-settings-section">
					<h2><?php _e( 'Default Guild Settings', 'encountrix' ); ?></h2>
					<p class="description">
						<?php _e( 'Configure which guilds to display by default. These defaults are used when no parameters are specified in the shortcode.', 'encountrix' ); ?>
					</p>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="encountrix_guild_ids">
									<?php _e( 'Guild IDs', 'encountrix' ); ?>
								</label>
							</th>
							<td>
								<input type="text"
									id="encountrix_guild_ids"
									name="encountrix_guild_ids"
									value="<?php echo esc_attr( $guild_ids ); ?>"
									class="regular-text" />
								<p class="description">
									<?php _e( 'Comma-separated numeric Raider.io guild IDs (max 10). Find your guild ID in the URL on your Raider.io guild page. Leave empty to show realm or region rankings instead.', 'encountrix' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="encountrix_region">
									<?php _e( 'Default Region', 'encountrix' ); ?>
								</label>
							</th>
							<td>
								<select id="encountrix_region" name="encountrix_region">
									<option value="us" <?php selected( $region, 'us' ); ?>><?php _e( 'US', 'encountrix' ); ?></option>
									<option value="eu" <?php selected( $region, 'eu' ); ?>><?php _e( 'EU', 'encountrix' ); ?></option>
									<option value="kr" <?php selected( $region, 'kr' ); ?>><?php _e( 'KR', 'encountrix' ); ?></option>
									<option value="tw" <?php selected( $region, 'tw' ); ?>><?php _e( 'TW', 'encountrix' ); ?></option>
								</select>
								<p class="description">
									<?php _e( 'The WoW region used for rankings and realm lists.', 'encountrix' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="encountrix_realm">
									<?php _e( 'Default Realm', 'encountrix' ); ?>
								</label>
							</th>
							<td>
								<div class="encountrix-realm-selector">
									<input type="text"
										id="encountrix_realm"
										name="encountrix_realm"
										value="<?php echo esc_attr( $realm ); ?>"
										class="regular-text"
										placeholder="<?php echo $has_blizzard_api ? esc_attr__( 'Type to search realms...', 'encountrix' ) : esc_attr__( 'Enter realm slug (e.g., eredar)', 'encountrix' ); ?>"
										autocomplete="off" />
									<?php if ( $has_blizzard_api ) : ?>
										<div class="encountrix-realm-dropdown" id="realm-dropdown" style="display: none;">
											<div class="encountrix-realm-list" id="realm-list">
												<!-- Populated via JavaScript -->
											</div>
										</div>
									<?php endif; ?>
								</div>
								<p class="description">
									<?php if ( $has_blizzard_api ) : ?>
										<?php _e( 'Start typing to search available realms. Leave empty for region-wide rankings.', 'encountrix' ); ?>
									<?php else : ?>
										<?php _e( 'Enter the realm slug manually (e.g., eredar). Realm autocompletion requires Blizzard API credentials. Leave empty for region-wide rankings.', 'encountrix' ); ?>
									<?php endif; ?>
								</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="encountrix-settings-section">
					<h2><?php _e( 'Default Raid Settings', 'encountrix' ); ?></h2>
					<p class="description">
						<?php _e( 'Choose which raid and difficulty to display by default. Can be overridden per shortcode.', 'encountrix' ); ?>
					</p>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="encountrix_expansion">
									<?php _e( 'Expansion', 'encountrix' ); ?>
								</label>
							</th>
							<td>
								<select id="encountrix_expansion" name="encountrix_expansion">
									<?php foreach ( $expansions as $id => $exp ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $expansion, $id ); ?>>
											<?php echo esc_html( $exp['name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php _e( 'Determines which raids are available in the dropdown below.', 'encountrix' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="encountrix_raid">
									<?php _e( 'Default Raid', 'encountrix' ); ?>
								</label>
							</th>
							<td>
								<div class="inline-form-elements">
									<select id="encountrix_raid"
										name="encountrix_raid"
										data-current-value="<?php echo esc_attr( $raid ); ?>">
										<option value=""><?php _e( 'Loading raids...', 'encountrix' ); ?></option>
									</select>
									<button type="button" class="button" id="refresh-raids-btn">
										<?php _e( 'Refresh Raids', 'encountrix' ); ?>
									</button>
								</div>
								<p class="description">
									<?php _e( 'The raid to display when none is specified in the shortcode. Use "Refresh Raids" to update the list from the API.', 'encountrix' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="encountrix_difficulty">
									<?php _e( 'Default Difficulty', 'encountrix' ); ?>
								</label>
							</th>
							<td>
								<select id="encountrix_difficulty" name="encountrix_difficulty">
									<option value="highest" <?php selected( $difficulty, 'highest' ); ?>>
										<?php _e( 'Highest Progress', 'encountrix' ); ?>
									</option>
									<option value="all" <?php selected( $difficulty, 'all' ); ?>>
										<?php _e( 'All Difficulties', 'encountrix' ); ?>
									</option>
									<option value="normal" <?php selected( $difficulty, 'normal' ); ?>>
										<?php _e( 'Normal', 'encountrix' ); ?>
									</option>
									<option value="heroic" <?php selected( $difficulty, 'heroic' ); ?>>
										<?php _e( 'Heroic', 'encountrix' ); ?>
									</option>
									<option value="mythic" <?php selected( $difficulty, 'mythic' ); ?>>
										<?php _e( 'Mythic', 'encountrix' ); ?>
									</option>
								</select>
								<p class="description">
									<?php _e( '"Highest Progress" shows only the highest difficulty the guild has progressed in. "All Difficulties" shows a section for each difficulty.', 'encountrix' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
			<?php else : ?>
				<div class="encountrix-settings-section">
					<div class="notice notice-info inline">
						<p><?php _e( 'Enter and save a valid Raider.io API key above to configure guild, raid, display, and tool settings.', 'encountrix' ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php _e( 'Save Changes', 'encountrix' ); ?>
				</button>
			</p>
		</div>

		<?php if ( $has_raiderio_key ) : ?>
			<!-- Display Settings Tab -->
			<div id="display" class="tab-content">
				<div class="encountrix-settings-section">
					<h2><?php _e( 'Display Options', 'encountrix' ); ?></h2>
					<p class="description">
						<?php _e( 'Control how raid progress is displayed on the frontend. These are the defaults used by the [encountrix] shortcode.', 'encountrix' ); ?>
					</p>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="encountrix_show_raid_name">
									<?php _e( 'Show Raid Name', 'encountrix' ); ?>
								</label>
							</th>
							<td>
								<select id="encountrix_show_raid_name" name="encountrix_show_raid_name">
									<option value="true" <?php selected( $show_raid_name, 'true' ); ?>>
										<?php _e( 'Yes', 'encountrix' ); ?>
									</option>
									<option value="false" <?php selected( $show_raid_name, 'false' ); ?>>
										<?php _e( 'No', 'encountrix' ); ?>
									</option>
								</select>
								<p class="description">
									<?php _e( 'Displays the raid name (e.g., "Nerub-ar Palace") as a header above the boss list.', 'encountrix' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="encountrix_show_icons">
									<?php _e( 'Show Boss Icons', 'encountrix' ); ?>
								</label>
							</th>
							<td>
								<select id="encountrix_show_icons" name="encountrix_show_icons">
									<option value="true" <?php selected( $show_icons, 'true' ); ?>>
										<?php _e( 'Yes', 'encountrix' ); ?>
									</option>
									<option value="false" <?php selected( $show_icons, 'false' ); ?>>
										<?php _e( 'No', 'encountrix' ); ?>
									</option>
								</select>
								<p class="description">
									<?php _e( 'Shows an icon next to each boss name. Icons must be imported via the Tools tab first.', 'encountrix' ); ?>
								</p>
							</td>
						</tr>
						<?php if ( $has_blizzard_api ) : ?>
							<tr>
								<th scope="row">
									<label for="encountrix_show_raid_icon">
										<?php _e( 'Show Raid Background', 'encountrix' ); ?>
									</label>
								</th>
								<td>
									<select id="encountrix_show_raid_icon" name="encountrix_show_raid_icon">
										<option value="true" <?php selected( $show_raid_icon, 'true' ); ?>>
											<?php _e( 'Yes', 'encountrix' ); ?>
										</option>
										<option value="false" <?php selected( $show_raid_icon, 'false' ); ?>>
											<?php _e( 'No', 'encountrix' ); ?>
										</option>
									</select>
									<p class="description">
										<?php _e( 'Fetches and displays the official raid journal background image from Blizzard in the widget header.', 'encountrix' ); ?>
									</p>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row">
								<label for="encountrix_show_killed">
									<?php _e( 'Show Defeated Bosses', 'encountrix' ); ?>
								</label>
							</th>
							<td>
								<select id="encountrix_show_killed" name="encountrix_show_killed">
									<option value="true" <?php selected( $show_killed, 'true' ); ?>>
										<?php _e( 'Yes', 'encountrix' ); ?>
									</option>
									<option value="false" <?php selected( $show_killed, 'false' ); ?>>
										<?php _e( 'No', 'encountrix' ); ?>
									</option>
								</select>
								<p class="description">
									<?php _e( 'When set to No, only bosses that have not yet been defeated are shown. Useful to highlight remaining progress.', 'encountrix' ); ?>
								</p>
							</td>
						</tr>
						<?php if ( $has_blizzard_api ) : ?>
							<tr>
								<th scope="row">
									<label for="encountrix_use_blizzard_icons">
										<?php _e( 'Use Blizzard Icons', 'encountrix' ); ?>
									</label>
								</th>
								<td>
									<select id="encountrix_use_blizzard_icons" name="encountrix_use_blizzard_icons">
										<option value="true" <?php selected( $use_blizzard_icons, 'true' ); ?>>
											<?php _e( 'Yes', 'encountrix' ); ?>
										</option>
										<option value="false" <?php selected( $use_blizzard_icons, 'false' ); ?>>
											<?php _e( 'No', 'encountrix' ); ?>
										</option>
									</select>
									<p class="description">
										<?php _e( 'Uses official Blizzard achievement icons for bosses. When disabled, generic icons are shown. Icons must be imported first via the Tools tab.', 'encountrix' ); ?>
									</p>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row">
								<label for="encountrix_cache_time">
									<?php _e( 'Cache Duration', 'encountrix' ); ?>
								</label>
							</th>
							<td>
								<input type="number"
									id="encountrix_cache_time"
									name="encountrix_cache_time"
									value="<?php echo esc_attr( $cache_time ); ?>"
									min="0"
									max="1440"
									class="small-text" />
								<?php _e( 'minutes', 'encountrix' ); ?>
								<p class="description">
									<?php _e( 'How long API responses are cached locally. Higher values reduce API calls but delay updates. Set to 0 to always fetch fresh data (not recommended for production). Maximum: 1440 minutes (24 hours).', 'encountrix' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="encountrix_limit">
									<?php _e( 'Results Limit', 'encountrix' ); ?>
								</label>
							</th>
							<td>
								<input type="number"
									id="encountrix_limit"
									name="encountrix_limit"
									value="<?php echo esc_attr( $limit ); ?>"
									min="1"
									max="100"
									class="small-text" />
								<p class="description">
									<?php _e( 'Maximum number of guilds shown in realm/region rankings (1-100). Only applies when no specific guild IDs are set.', 'encountrix' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php _e( 'Save Changes', 'encountrix' ); ?>
					</button>
				</p>
			</div>
		<?php endif; ?>
	</form>

	<?php if ( $has_raiderio_key ) : ?>
		<!-- Tools Tab -->
		<div id="tools" class="tab-content">
			<?php if ( $has_blizzard_api ) : ?>
				<div class="encountrix-settings-section">
					<h2><?php _e( 'Icon Management', 'encountrix' ); ?></h2>
					<p class="description">
						<?php _e( 'Import and manage boss achievement icons from the Blizzard API. Icons are stored in your WordPress media library.', 'encountrix' ); ?>
					</p>
					<div class="encountrix-tool-card">
						<h3><?php _e( 'Import Boss Icons', 'encountrix' ); ?></h3>
						<p><?php _e( 'Downloads boss achievement icons for the currently selected raid from the Blizzard API and saves them to your media library. Already imported icons are skipped.', 'encountrix' ); ?></p>
						<button type="button" class="button button-primary" id="import-icons-btn">
							<?php _e( 'Import Icons', 'encountrix' ); ?>
						</button>
						<div id="import-status" class="notice" style="display: none;"></div>
					</div>

					<div class="encountrix-tool-card warning">
						<h3><?php _e( 'Delete All Icons', 'encountrix' ); ?></h3>
						<p><strong><?php _e( 'Warning:', 'encountrix' ); ?></strong> <?php _e( 'This will permanently delete ALL imported boss and raid icons from your media library.', 'encountrix' ); ?></p>
						<button type="button" class="button button-danger" id="delete-icons-btn">
							<?php _e( 'Delete All Icons', 'encountrix' ); ?>
						</button>
						<span id="delete-icons-message" class="notice notice-success" style="display: none;"></span>
					</div>
				</div>
			<?php else : ?>
				<div class="encountrix-settings-section">
					<h2><?php _e( 'Icon Management', 'encountrix' ); ?></h2>
					<div class="notice notice-info inline">
						<p><?php _e( 'Enter and save valid Blizzard API credentials in the General Settings tab to import boss icons.', 'encountrix' ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<div class="encountrix-settings-section">
				<h2><?php _e( 'Cache Management', 'encountrix' ); ?></h2>
				<div class="encountrix-tool-card">
					<h3><?php _e( 'Clear All Cache', 'encountrix' ); ?></h3>
					<p><?php _e( 'Removes all cached API responses. The next page load will fetch fresh data from the APIs. Useful after changing API keys or when data appears outdated.', 'encountrix' ); ?></p>
					<button type="button" class="button" id="clear-cache-btn">
						<?php _e( 'Clear Cache', 'encountrix' ); ?>
					</button>
					<span id="cache-clear-message" class="notice notice-success" style="display: none;">
						<?php _e( 'Cache cleared successfully!', 'encountrix' ); ?>
					</span>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<!-- Documentation Tab -->
	<div id="documentation" class="tab-content">
		<div class="encountrix-settings-section">
			<h2><?php _e( 'Shortcode Documentation', 'encountrix' ); ?></h2>

			<div class="encountrix-doc-section">
				<h3><?php _e( 'Basic Usage', 'encountrix' ); ?></h3>
				<p><?php _e( 'Use the following shortcode to display raid progress:', 'encountrix' ); ?></p>
				<div class="shortcode-wrapper">
					<code class="shortcode-example" data-shortcode="[encountrix]">[encountrix]</code>
					<button type="button" class="copy-shortcode button button-small" title="<?php esc_attr_e( 'Copy to clipboard', 'encountrix' ); ?>">
						<span class="dashicons dashicons-clipboard"></span>
						<span class="copy-text"><?php _e( 'Copy', 'encountrix' ); ?></span>
					</button>
				</div>
				<p><?php _e( 'This will use all default settings configured above.', 'encountrix' ); ?></p>
			</div>

			<div class="encountrix-doc-section">
				<h3><?php _e( 'Available Parameters', 'encountrix' ); ?></h3>
				<ul>
					<li><code>raid</code> - <?php _e( 'Raid slug (e.g., nerub-ar-palace)', 'encountrix' ); ?></li>
					<li><code>difficulty</code> - <?php _e( 'Difficulty to display: highest, all, normal, heroic, or mythic', 'encountrix' ); ?></li>
					<li><code>region</code> - <?php _e( 'Region: us, eu, kr, or tw', 'encountrix' ); ?></li>
					<li><code>realm</code> - <?php _e( 'Specific realm name', 'encountrix' ); ?></li>
					<li><code>guilds</code> - <?php _e( 'Comma-separated guild IDs (max 10)', 'encountrix' ); ?></li>
					<li><code>cache</code> - <?php _e( 'Cache duration in minutes', 'encountrix' ); ?></li>
					<li><code>show_raid_name</code> - <?php _e( 'Show raid name header: true or false', 'encountrix' ); ?></li>
					<li><code>show_icons</code> - <?php _e( 'Show boss icons: true or false', 'encountrix' ); ?></li>
					<li><code>limit</code> - <?php _e( 'Maximum number of guilds to display', 'encountrix' ); ?></li>
				</ul>
			</div>

			<div class="encountrix-doc-section">
				<h3><?php _e( 'Example Shortcodes', 'encountrix' ); ?></h3>

				<h4><?php _e( 'Display specific guild progress:', 'encountrix' ); ?></h4>
				<div class="shortcode-wrapper">
					<code class="shortcode-example" data-shortcode='[encountrix guilds="12345" raid="nerub-ar-palace" difficulty="mythic"]'>[encountrix guilds="12345" raid="nerub-ar-palace" difficulty="mythic"]</code>
					<button type="button" class="copy-shortcode button button-small">
						<span class="dashicons dashicons-clipboard"></span>
						<span class="copy-text"><?php _e( 'Copy', 'encountrix' ); ?></span>
					</button>
				</div>

				<h4><?php _e( 'Display realm rankings:', 'encountrix' ); ?></h4>
				<div class="shortcode-wrapper">
					<code class="shortcode-example" data-shortcode='[encountrix region="us" realm="Stormrage" limit="10"]'>[encountrix region="us" realm="Stormrage" limit="10"]</code>
					<button type="button" class="copy-shortcode button button-small">
						<span class="dashicons dashicons-clipboard"></span>
						<span class="copy-text"><?php _e( 'Copy', 'encountrix' ); ?></span>
					</button>
				</div>
			</div>
		</div>
	</div>
</div>
