<?php

/**
 * Widget and shortcode handler class
 *
 * FIX #8: Uses batch icon loading via get_all_boss_icon_ids() instead of
 *         per-boss get_boss_icon_id() calls (eliminates N+1 queries).
 */

if (!defined('ABSPATH')) {
	exit;
}

class WoWRaidProgressWidget {

	private WoWRaidProgressAPI $api;

	public function __construct(WoWRaidProgressAPI $api) {
		$this->api = $api;
	}

	/**
	 * Render shortcode
	 */
	public function render_shortcode(array $atts): string {

		if (defined('WOW_RAID_PROGRESS_PLUGIN_URL')) {
			wp_enqueue_style(
				'wow-raid-progress',
				WOW_RAID_PROGRESS_PLUGIN_URL . 'assets/css/wow-raid-progress.css',
				[],
				defined('WOW_RAID_PROGRESS_VERSION') ? WOW_RAID_PROGRESS_VERSION : null
			);
			wp_enqueue_script(
				'wow-raid-progress',
				WOW_RAID_PROGRESS_PLUGIN_URL . 'assets/js/wow-raid-progress.js',
				['jquery'],
				defined('WOW_RAID_PROGRESS_VERSION') ? WOW_RAID_PROGRESS_VERSION : null,
				true
			);
		}

		// Get default values from settings
		$defaults = [
			'raid' => get_option('wow_raid_progress_raid', ''),
			'difficulty' => get_option('wow_raid_progress_difficulty', 'highest'),
			'region' => get_option('wow_raid_progress_region', 'eu'),
			'realm' => get_option('wow_raid_progress_realm', ''),
			'guilds' => get_option('wow_raid_progress_guild_ids', ''),
			'cache' => get_option('wow_raid_progress_cache_time', 60),
			'show_icons' => get_option('wow_raid_progress_show_icons', 'true'),
			'show_killed' => get_option('wow_raid_progress_show_killed', 'false'),
			'use_blizzard_icons' => get_option('wow_raid_progress_use_blizzard_icons', 'true'),
			'show_raid_name' => get_option('wow_raid_progress_show_raid_name', 'false'),
			'show_raid_icon' => get_option('wow_raid_progress_show_raid_icon', 'false'),
			'limit' => get_option('wow_raid_progress_limit', 50),
			'page' => 0
		];

		$atts = shortcode_atts($defaults, $atts, 'wow_raid_progress');

		if (empty($atts['raid'])) {
			return $this->render_error(__('No raid specified. Please configure a default raid in the plugin settings or specify one in the shortcode.', 'wow-raid-progress'));
		}

		if (empty($atts['region'])) {
			return $this->render_error(__('No region specified. Please configure a default region in the plugin settings or specify one in the shortcode.', 'wow-raid-progress'));
		}

		// Sanitize inputs
		$raid_slug = sanitize_text_field($atts['raid']);
		$difficulty = $this->validate_difficulty($atts['difficulty']);
		$region = sanitize_text_field($atts['region']);
		$realm = $this->api->sanitize_realm($atts['realm']);
		$guilds = $this->api->sanitize_guilds($atts['guilds']);
		$cache_minutes = absint($atts['cache']);
		$show_icons = filter_var($atts['show_icons'], FILTER_VALIDATE_BOOLEAN);
		$show_killed = filter_var($atts['show_killed'], FILTER_VALIDATE_BOOLEAN);
		$use_blizzard_icons = filter_var($atts['use_blizzard_icons'], FILTER_VALIDATE_BOOLEAN);
		$show_raid_name = filter_var($atts['show_raid_name'], FILTER_VALIDATE_BOOLEAN);
		$show_raid_icon = filter_var($atts['show_raid_icon'], FILTER_VALIDATE_BOOLEAN);
		$limit = max(1, min(100, absint($atts['limit'])));
		$page = max(0, absint($atts['page']));

		$expansion_id = get_option('wow_raid_progress_expansion', 10);

		$static_data = $this->api->fetch_static_raid_data_by_expansion($expansion_id, $cache_minutes);
		if (is_wp_error($static_data)) {
			return $this->render_error(
				sprintf(
					__('Error fetching raid information: %s', 'wow-raid-progress'),
					$static_data->get_error_message()
				)
			);
		}

		$raid_info = $this->find_raid_info($static_data, $raid_slug);
		if (!$raid_info) {
			return $this->render_error(
				sprintf(
					__('Raid "%s" not found. Please check the raid name.', 'wow-raid-progress'),
					esc_html($raid_slug)
				)
			);
		}

		// FIX #8: Batch-load all boss icons for this raid in one query
		$icon_map = [];
		if ($show_icons && $use_blizzard_icons) {
			$icon_map = $this->api->get_all_boss_icon_ids($raid_slug);
		}

		// Handle multiple guilds
		$guild_ids = !empty($guilds) ? explode(',', $guilds) : [];
		$guild_ids = array_map('trim', $guild_ids);
		$guild_ids = array_filter($guild_ids);

		if (empty($guild_ids)) {
			return $this->render_rankings(
				$raid_info, $raid_slug, $difficulty, $region, $realm, '',
				$cache_minutes, $limit, $page,
				$show_icons, $show_killed, $use_blizzard_icons,
				$show_raid_name, $show_raid_icon, $expansion_id, $icon_map
			);
		}

		$output = '<div class="wow-raid-progress-container">';

		if ($show_raid_name || $show_raid_icon) {
			$output .= $this->render_raid_header($raid_info, $show_raid_name, $show_raid_icon, $use_blizzard_icons, $expansion_id);
		}

		foreach ($guild_ids as $guild_id) {
			$output .= $this->render_guild_progress(
				$raid_info, $raid_slug, $difficulty, $region, $realm, $guild_id,
				$cache_minutes, $show_icons, $show_killed, $use_blizzard_icons,
				$expansion_id, $icon_map
			);
		}

		if ($use_blizzard_icons) {
			$output .= '<div class="wow-raid-attribution">';
			$output .= '<small>' . __('Boss artwork © Blizzard Entertainment', 'wow-raid-progress') . '</small>';
			$output .= '</div>';
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Render raid header
	 */
	private function render_raid_header(
		array $raid_info,
		bool $show_raid_name,
		bool $show_raid_icon,
		bool $use_blizzard_icons,
		int $expansion_id
	): string {
		if (!$show_raid_name && !$show_raid_icon) {
			return '';
		}

		$raid_name = !empty($raid_info['name']) ? $raid_info['name'] : '';
		$raid_slug = !empty($raid_info['slug']) ? $raid_info['slug'] : '';

		$bg_image_url = '';
		if ($show_raid_icon && $use_blizzard_icons) {
			$bg_image_id = $this->api->get_journal_instance_image_id($raid_slug, $expansion_id);
			if ($bg_image_id) {
				$bg_image_url = wp_get_attachment_image_url($bg_image_id, 'full');
			}
		}

		$output = '<div class="wow-raid-header-container">';

		if ($bg_image_url) {
			$output .= '<div class="wow-raid-header-hero" style="background-image: url(' . esc_url($bg_image_url) . ');">';
			$output .= '<div class="wow-raid-header-overlay">';
			$output .= '<h2 class="wow-raid-header-title">' . esc_html($raid_name) . '</h2>';
			$output .= '</div>';
			$output .= '</div>';
		} else {
			$output .= '<div class="wow-raid-header-simple">';
			$output .= '<h2 class="wow-raid-header-title">' . esc_html($raid_name) . '</h2>';
			$output .= '</div>';
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Render general rankings (no specific guild)
	 *
	 * @param array<string, int> $icon_map Pre-loaded boss icon map
	 */
	private function render_rankings(
		array $raid_info,
		string $raid_slug,
		string $difficulty,
		string $region,
		string $realm,
		string $guilds,
		int $cache_minutes,
		int $limit,
		int $page,
		bool $show_icons,
		bool $show_killed,
		bool $use_blizzard_icons,
		bool $show_raid_name,
		bool $show_raid_icon,
		int $expansion_id,
		array $icon_map
	): string {
		$bg_image_url = '';
		if ($show_raid_icon && $use_blizzard_icons) {
			$bg_image_id = $this->api->get_journal_instance_image_id($raid_slug, $expansion_id);
			if ($bg_image_id) {
				$bg_image_url = wp_get_attachment_image_url($bg_image_id, 'full');
			}
		}

		$output = '<div class="wow-raid-progress">';

		if ($bg_image_url && $show_raid_name) {
			$output .= '<div class="wow-raid-header-hero" style="background-image: url(' . esc_url($bg_image_url) . ');">';
			$output .= '<div class="wow-raid-header-overlay">';
			$output .= '<h2 class="wow-raid-header-title" data-text="' . esc_attr($raid_info['name']) . '">';
			$output .= '<span>' . esc_html($raid_info['name']) . '</span>';
			$output .= '</h2>';
			$output .= '</div>';
			$output .= '</div>';
		} elseif ($show_raid_name) {
			$output .= '<div class="wow-raid-header-simple">';
			$output .= '<h2 class="wow-raid-header-title">' . esc_html($raid_info['name']) . '</h2>';
			$output .= '</div>';
		}

		if ($difficulty === 'highest') {
			$all = [
				'normal' => $this->api->fetch_raid_data($raid_slug, 'normal', $region, $realm, $guilds, $cache_minutes, $limit, $page),
				'heroic' => $this->api->fetch_raid_data($raid_slug, 'heroic', $region, $realm, $guilds, $cache_minutes, $limit, $page),
				'mythic' => $this->api->fetch_raid_data($raid_slug, 'mythic', $region, $realm, $guilds, $cache_minutes, $limit, $page),
			];

			$target = $this->find_highest_progress_difficulty($all, $raid_info);
			$data = $all[$target] ?? ['raidRankings' => []];

			if (is_wp_error($data)) {
				if (!$this->is_rate_limit_error($data)) {
					$output .= $this->render_error_inline(
						sprintf(__('Error loading %s data: %s', 'wow-raid-progress'), ucfirst($target), $data->get_error_message())
					);
				}
			} else {
				$output .= $this->render_difficulty_section(
					is_array($data) ? $data : ['raidRankings' => []],
					$target, $raid_info, $all,
					$show_icons, $show_killed, $use_blizzard_icons,
					$expansion_id, $icon_map
				);
			}
		} else {
			$difficulties_to_render = is_array($difficulty) ? $difficulty : [$difficulty];

			foreach ($difficulties_to_render as $diff) {
				[$data, $used_diff, $all_attempts] = $this->fetch_with_fallback(
					$raid_slug, $diff, $region, $realm, $guilds, $cache_minutes, $limit, $page
				);

				if (is_wp_error($data)) {
					if (!$this->is_rate_limit_error($data)) {
						$output .= $this->render_error_inline(
							sprintf(__('Error loading %s data: %s', 'wow-raid-progress'), ucfirst($diff), $data->get_error_message())
						);
					}
					continue;
				}

				$current_tier_data = (isset($all_attempts[$diff]) && is_array($all_attempts[$diff]))
					? $all_attempts[$diff]
					: ['raidRankings' => []];

				$output .= $this->render_difficulty_section(
					$current_tier_data, $diff, $raid_info, $all_attempts,
					$show_icons, $show_killed, $use_blizzard_icons,
					$expansion_id, $icon_map
				);
			}
		}

		if ($use_blizzard_icons) {
			$output .= '<div class="wow-raid-attribution"><small>' . __('Data and media © Blizzard Entertainment', 'wow-raid-progress') . '</small></div>';
		}

		$output .= '</div>';
		return $output;
	}

	/**
	 * Render progress for a specific guild
	 *
	 * @param array<string, int> $icon_map Pre-loaded boss icon map
	 */
	private function render_guild_progress(
		array $raid_info,
		string $raid_slug,
		string $difficulty,
		string $region,
		string $realm,
		string $guild_id,
		int $cache_minutes,
		bool $show_icons,
		bool $show_killed,
		bool $use_blizzard_icons,
		int $expansion_id,
		array $icon_map
	): string {

		if (!preg_match('/^\d+$/', $guild_id)) {
			return $this->render_error_inline(
				sprintf(__('Invalid guild ID: %s', 'wow-raid-progress'), esc_html($guild_id))
			);
		}

		$all_difficulties_data = [];
		foreach (['normal', 'heroic', 'mythic'] as $diff_key) {
			$all_difficulties_data[$diff_key] = $this->api->fetch_raid_data(
				$raid_slug, $diff_key, $region, $realm, $guild_id, $cache_minutes, 50, 0
			);
		}

		$difficulties_to_render = [];

		if ($difficulty === 'all') {
			$difficulties_to_render = ['normal', 'heroic', 'mythic'];
		} elseif ($difficulty === 'highest') {
			$target_diff = $this->find_highest_progress_difficulty($all_difficulties_data, $raid_info);
			$difficulties_to_render[] = $target_diff;
		} else {
			$difficulties_to_render[] = $difficulty;
		}

		$output = '';

		foreach ($difficulties_to_render as $diff) {
			$data = $all_difficulties_data[$diff] ?? null;

			if (is_wp_error($data)) {
				$this->api->debug_log('Raid data error for guild ' . $guild_id . ' (' . $diff . '): ' . $data->get_error_message());
				$output .= $this->render_error_inline(
					sprintf(
						__('Error loading %s data for guild %s: %s', 'wow-raid-progress'),
						ucfirst($diff),
						$guild_id,
						$data->get_error_message()
					)
				);
				continue;
			}

			if (empty($data) || empty($data['raidRankings'])) {
				$output .= $this->render_difficulty_section(
					(is_array($data) ? $data : ['raidRankings' => []]),
					$diff, $raid_info, $all_difficulties_data,
					$show_icons, $show_killed, $use_blizzard_icons,
					$expansion_id, $icon_map
				);
				continue;
			}

			$output .= $this->render_difficulty_section(
				$data, $diff, $raid_info, $all_difficulties_data,
				$show_icons, $show_killed, $use_blizzard_icons,
				$expansion_id, $icon_map
			);
		}

		if ($output === '') {
			$output = $this->render_error_inline(__('No raid data available for this guild.', 'wow-raid-progress'));
		}

		return $output;
	}

	/**
	 * Render difficulty section
	 *
	 * FIX #8: Uses pre-loaded $icon_map instead of per-boss get_boss_icon_id() calls.
	 *
	 * @param array<string, int> $icon_map Pre-loaded boss_slug => attachment_id map
	 */
	private function render_difficulty_section(
		array $data,
		string $difficulty,
		array $raid_info,
		array $all_difficulties_data,
		bool $show_icons,
		bool $show_killed,
		bool $use_blizzard_icons,
		int $expansion_id,
		array $icon_map = []
	): string {

		$all_bosses = $raid_info['encounters'] ?? [];
		$ranking_entry = $data['raidRankings'][0] ?? null;

		$encounters_defeated = $ranking_entry['encountersDefeated'] ?? [];
		$pulled_encounters = array_column($ranking_entry['encountersPulled'] ?? [], null, 'slug');

		$guild_info = $ranking_entry['guild'] ?? null;
		$rank = $ranking_entry['rank'] ?? null;
		$world_rank = $data['worldRanking'] ?? null;

		if (empty($guild_info) || !is_numeric($rank)) {
			$source_data = $this->find_fallback_data($all_difficulties_data, $difficulty);
			if ($source_data && !is_wp_error($source_data) && !empty($source_data['raidRankings'][0])) {
				$src = $source_data['raidRankings'][0];
				if (empty($guild_info)) {
					$guild_info = $src['guild'] ?? null;
				}
				if (!is_numeric($rank)) {
					$rank = $src['rank'] ?? null;
				}
				if (empty($world_rank)) {
					$world_rank = $source_data['worldRanking'] ?? null;
				}
			}
		}

		if (empty($guild_info)) {
			return $this->render_error_inline(
				sprintf(
					__('No data found for your guild in this raid.', 'wow-raid-progress'),
					ucfirst($difficulty)
				)
			);
		}

		ob_start();
?>
		<div class="wow-raid-section">
			<div class="wow-raid-header">
				<h3 class="wow-raid-title">
					<?php echo esc_html($guild_info['name']); ?>
					<span class="wow-difficulty-badge <?php echo esc_attr($difficulty); ?>">
						<?php echo esc_html(ucfirst($difficulty)); ?>
					</span>
				</h3>
				<div class="wow-raid-meta">
					<span class="wow-realm"><?php echo esc_html($guild_info['realm']['name']); ?></span>
					<span class="wow-region"><?php echo esc_html(strtoupper($guild_info['region']['short_name'])); ?></span>
				</div>
				<div class="wow-raid-ranks">
					<span class="wow-rank realm"><?php esc_html_e('Realm:', 'wow-raid-progress'); ?> <strong>#<?php echo esc_html($rank); ?></strong></span>
					<?php if (!empty($world_rank)): ?>
						<span class="wow-rank world"><?php esc_html_e('World:', 'wow-raid-progress'); ?> <strong>#<?php echo esc_html($world_rank); ?></strong></span>
					<?php endif; ?>
				</div>
			</div>
			<div class="wow-raid-progress">
				<?php
				$progress_percent = count($all_bosses) > 0 ?
					(count($encounters_defeated) / count($all_bosses)) * 100 : 0;
				?>
				<div class="wow-progress-bar">
					<div class="wow-progress-fill <?php echo esc_attr($difficulty); ?>"
						style="width: <?php echo esc_attr($progress_percent); ?>%"></div>
					<div class="wow-progress-text">
						<?php echo esc_html(count($encounters_defeated) . '/' . count($all_bosses)); ?>
						<?php esc_html_e('Bosses', 'wow-raid-progress'); ?>
					</div>
				</div>
				<div class="wow-boss-list">
					<?php
					$defeated_slugs = array_column($encounters_defeated, 'slug');
					foreach ($all_bosses as $boss):
						$is_defeated = in_array($boss['slug'], $defeated_slugs, true);

						if (!$show_killed && $is_defeated) {
							continue;
						}

						$status_class = $is_defeated ? 'defeated' : (isset($pulled_encounters[$boss['slug']]) ? 'in-progress' : 'not-started');
					?>
						<div class="wow-boss-item <?php echo esc_attr($status_class); ?>">
							<?php if ($show_icons): ?>
								<div class="wow-boss-icon wow-boss-portrait">
									<?php
									if ($use_blizzard_icons) {
										// FIX #8: Use pre-loaded icon map instead of per-boss query
										$attachment_id = $icon_map[$boss['slug']] ?? null;

										// Fall back to individual lookup only if not in batch map
										if (!$attachment_id) {
											$attachment_id = $this->api->get_boss_icon_id(
												$boss['slug'],
												$raid_info['slug'],
												$boss['name'],
												$expansion_id
											);
										}

										if ($attachment_id) {
											echo wp_get_attachment_image(
												$attachment_id,
												[40, 40],
												false,
												['alt' => esc_attr($boss['name'])]
											);
										} else {
											echo '<span>-</span>';
										}
									} else {
										echo '<span>-</span>';
									}
									?>
								</div>
							<?php endif; ?>
							<div class="wow-boss-info">
								<div class="wow-boss-name"><?php echo esc_html($boss['name']); ?></div>
								<div class="wow-boss-stats">
									<?php if ($is_defeated): ?>
										<span class="wow-defeated-label"><?php esc_html_e('Defeated', 'wow-raid-progress'); ?></span>
									<?php elseif (isset($pulled_encounters[$boss['slug']])): ?>
										<span class="wow-pulls">
											<?php esc_html_e('Pulls:', 'wow-raid-progress'); ?>
											<?php echo esc_html($pulled_encounters[$boss['slug']]['numPulls']); ?>
										</span>
										<span class="wow-percent">
											<?php esc_html_e('Best:', 'wow-raid-progress'); ?>
											<?php echo esc_html(number_format($pulled_encounters[$boss['slug']]['bestPercent'], 1)); ?>%
										</span>
									<?php else: ?>
										<span class="wow-not-started"><?php esc_html_e('Not Started', 'wow-raid-progress'); ?></span>
									<?php endif; ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
<?php
		return ob_get_clean();
	}

	/**
	 * Find highest progress difficulty
	 */
	private function find_highest_progress_difficulty(array $all_difficulties_data, array $raid_info): string {
		$total_bosses = count($raid_info['encounters']);
		$target_diff = 'normal';

		$normal_kills = 0;
		if (
			!is_wp_error($all_difficulties_data['normal']) &&
			!empty($all_difficulties_data['normal']['raidRankings'][0])
		) {
			$normal_kills = count($all_difficulties_data['normal']['raidRankings'][0]['encountersDefeated'] ?? []);
		}

		$heroic_kills = 0;
		if (
			!is_wp_error($all_difficulties_data['heroic']) &&
			!empty($all_difficulties_data['heroic']['raidRankings'][0])
		) {
			$heroic_kills = count($all_difficulties_data['heroic']['raidRankings'][0]['encountersDefeated'] ?? []);
		}

		$mythic_kills = 0;
		if (
			!is_wp_error($all_difficulties_data['mythic']) &&
			!empty($all_difficulties_data['mythic']['raidRankings'][0])
		) {
			$mythic_kills = count($all_difficulties_data['mythic']['raidRankings'][0]['encountersDefeated'] ?? []);
		}

		if ($normal_kills > 0) $target_diff = 'heroic';
		if ($heroic_kills >= $total_bosses) $target_diff = 'mythic';

		foreach (['mythic', 'heroic', 'normal'] as $diff) {
			if (
				!is_wp_error($all_difficulties_data[$diff]) &&
				!empty($all_difficulties_data[$diff]['raidRankings'][0])
			) {
				$kills = count($all_difficulties_data[$diff]['raidRankings'][0]['encountersDefeated'] ?? []);
				if ($kills > 0 && $kills < $total_bosses) {
					$target_diff = $diff;
					break;
				}
			}
		}

		return $target_diff;
	}

	/**
	 * Find fallback data from lower difficulties
	 */
	private function find_fallback_data(array $all_difficulties_data, string $current_difficulty): ?array {
		$fallback_order = [
			'mythic' => ['heroic', 'normal'],
			'heroic' => ['normal'],
			'normal' => [],
		];

		$order = $fallback_order[$current_difficulty] ?? [];

		foreach ($order as $diff) {
			if (
				isset($all_difficulties_data[$diff]) &&
				!is_wp_error($all_difficulties_data[$diff]) &&
				!empty($all_difficulties_data[$diff]['raidRankings'][0])
			) {
				return $all_difficulties_data[$diff];
			}
		}
		return null;
	}

	/**
	 * Validate difficulty setting
	 */
	private function validate_difficulty(string $difficulty): string {
		$valid = ['all', 'normal', 'heroic', 'mythic', 'highest'];
		$difficulty = strtolower(sanitize_text_field($difficulty));
		return in_array($difficulty, $valid, true) ? $difficulty : 'normal';
	}

	/**
	 * Find raid info in static data
	 */
	private function find_raid_info(array $static_data, string $raid_slug): ?array {
		if (!isset($static_data['raids'])) {
			return null;
		}

		foreach ($static_data['raids'] as $raid_data) {
			if ($raid_data['slug'] === $raid_slug) {
				return $raid_data;
			}
		}

		return null;
	}

	/**
	 * Render error message
	 */
	private function render_error(string $message): string {
		return sprintf(
			'<div class="wow-raid-error">%s</div>',
			esc_html($message)
		);
	}

	/**
	 * Render inline error message
	 */
	private function render_error_inline(string $message): string {
		return sprintf(
			'<div class="wow-raid-error-inline">%s</div>',
			esc_html($message)
		);
	}

	/**
	 * Difficulty fallback order
	 *
	 * @return array<string>
	 */
	private function difficulty_fallback_order(string $difficulty): array {
		return match ($difficulty) {
			'mythic' => ['mythic', 'heroic', 'normal'],
			'heroic' => ['heroic', 'normal'],
			default => ['normal'],
		};
	}

	/**
	 * Try requested difficulty, then fall back to lower ones until data appears
	 *
	 * @return array{0: array|WP_Error, 1: string, 2: array}
	 */
	private function fetch_with_fallback(
		string $raid_slug,
		string $difficulty,
		string $region,
		string $realm,
		string $guilds,
		int $cache_minutes,
		int $limit,
		int $page
	): array {
		$results = [];
		foreach ($this->difficulty_fallback_order($difficulty) as $diff) {
			$data = $this->api->fetch_raid_data($raid_slug, $diff, $region, $realm, $guilds, $cache_minutes, $limit, $page);
			$results[$diff] = $data;
			if (!is_wp_error($data) && !empty($data['raidRankings'])) {
				return [$data, $diff, $results];
			}
		}
		$first = reset($results);
		$first_diff = array_key_first($results);
		return [$first, $first_diff, $results];
	}

	private function is_rate_limit_error(mixed $err): bool {
		if (!is_wp_error($err)) return false;
		$msg = $err->get_error_message();
		return stripos($msg, '429') !== false || stripos($msg, 'rate limit') !== false;
	}
}
