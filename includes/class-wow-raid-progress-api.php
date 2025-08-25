<?php

/**
 * API handler class
 */

if (!defined('ABSPATH')) {
	exit;
}

class WoWRaidProgressAPI {

	private $api_base_url = 'https://raider.io/api/v1/raiding/raid-rankings';
	private $static_data_url = 'https://raider.io/api/v1/raiding/static-data';
	private $cache_prefix = 'wow_raid_progress_';
	private $static_cache_prefix = 'wow_raid_static_';
	private $blizzard_cache_prefix = 'wow_blizzard_';
	private $http_timeout = 8;

	private function base_headers() {
		return [
			'User-Agent' => 'WoWRaidProgress/' . (defined('WOW_RAID_PROGRESS_VERSION') ? WOW_RAID_PROGRESS_VERSION : 'dev') .
				' WordPress/' . get_bloginfo('version') . ' (' . home_url() . ')'
		];
	}

	private $expansions = [
		10 => ['name' => 'The War Within', 'category_name' => 'The War Within Raid'],
		9  => ['name' => 'Dragonflight', 'category_name' => 'Dragonflight Raids'],
		8  => ['name' => 'Shadowlands', 'category_name' => 'Shadowlands Raid'],
		7  => ['name' => 'Battle for Azeroth', 'category_name' => 'Battle for Azeroth Raid']
	];


	private $regional_endpoints = [
		'us' => ['oauth' => 'https://oauth.battle.net/token', 'api' => 'https://us.api.blizzard.com'],
		'eu' => ['oauth' => 'https://oauth.battle.net/token', 'api' => 'https://eu.api.blizzard.com'],
		'kr' => ['oauth' => 'https://oauth.battle.net/token', 'api' => 'https://kr.api.blizzard.com'],
		'tw' => ['oauth' => 'https://oauth.battle.net/token', 'api' => 'https://tw.api.blizzard.com']
	];

	private $achievement_category_ids = [
		'The War Within' => 15526,
		'Dragonflight' => 15468,
		'Shadowlands' => 15438,
		'Battle for Azeroth' => 15286,
		'Legion' => 15255,
		'Warlords of Draenor' => 15231,
		'Mists of Pandaria' => 15107,
		'Cataclysm' => 15068,
		'Wrath of the Lich King' => 14922
	];

	private $boss_name_overrides = [
		'Dimensius' => 'Dimensius the All-Devouring'
	];

	/**
	 * Fetch raid data with comprehensive error handling
	 */
	public function fetch_raid_data($raid, $difficulty, $region, $realm, $guilds, $cache_minutes, $limit = 50, $page = 0) {
		try {
			// Validate inputs
			if (empty($raid)) {
				return new WP_Error('invalid_raid', __('Raid name is required.', 'wow-raid-progress'));
			}

			if (!in_array($difficulty, ['normal', 'heroic', 'mythic'])) {
				return new WP_Error('invalid_difficulty', __('Invalid difficulty specified.', 'wow-raid-progress'));
			}

			if (!array_key_exists($region, $this->regional_endpoints) && $region !== 'world') {
				return new WP_Error('invalid_region', __('Invalid region specified.', 'wow-raid-progress'));
			}

			// Check cache
			$cache_key = $this->cache_prefix . md5(serialize(func_get_args()));
			if ($cache_minutes > 0) {
				$cached_data = get_transient($cache_key);
				if ($cached_data !== false) {
					return $cached_data;
				}
			}

			// Get API key
			$api_key = get_option('wow_raid_progress_api_key');
			if (empty($api_key)) {
				return new WP_Error('no_api_key', __('Raider.io API key not configured. Please configure it in the plugin settings.', 'wow-raid-progress'));
			}

			// --- SANITIZE & NORMALIZE INPUTS ---
			$raid       = sanitize_key($raid);
			$difficulty = sanitize_key($difficulty);
			$region     = strtolower(sanitize_text_field($region));

			// realm & guilds normalization
			$realm  = $this->sanitize_realm($realm);
			$guilds = $this->sanitize_guilds($guilds);

			// numeric bounds
			$limit = max(1, min(100, absint($limit)));
			$page  = max(0, absint($page));

			// Build API request
			$params = [
				'access_key' => $api_key,
				'raid'       => $raid,
				'difficulty' => $difficulty,
				'region'     => $region,
				'limit'      => $limit,
				'page'       => $page,
			];

			if ($realm !== '') {
				$params['realm'] = $realm;
			}
			if ($guilds !== '') {
				$params['guilds'] = $guilds;
			}

			$url = add_query_arg($params, $this->api_base_url);

			// bail fast if recent error occurred
			if ($cache_minutes > 0) {
				$cached_error = $this->get_error_cache($cache_key);
				if ($cached_error) return $cached_error;
			}

			$response = wp_remote_get($url, [
				'timeout' => $this->http_timeout,
				'headers' => $this->base_headers(),
			]);

			if (is_wp_error($response)) {
				$msg = $response->get_error_message();
				if ($cache_minutes > 0) $this->set_error_cache($cache_key, $msg, 120);
				return new WP_Error('api_request_failed', sprintf(__('Failed to connect to Raider.io API: %s', 'wow-raid-progress'), $msg));
			}

			$response_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($response_code !== 200) {
				$error_message = $this->parse_api_error($body, $response_code);
				// cache short-lived error, longer if rate-limited
				$ttl = ($response_code === 429) ? 300 : 120;
				if ($cache_minutes > 0) $this->set_error_cache($cache_key, $error_message, $ttl);

				return new WP_Error(
					'api_http_error',
					sprintf(
						__('Raider.io API error (HTTP %d): %s', 'wow-raid-progress'),
						$response_code,
						$error_message
					)
				);
			}

			// Parse JSON response
			$data = json_decode($body, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				error_log('WoW Raid Progress JSON Parse Error: ' . json_last_error_msg());
				return new WP_Error(
					'json_parse_error',
					__('Failed to parse API response. The API may be temporarily unavailable.', 'wow-raid-progress')
				);
			}

			// Validate response structure
			if (!isset($data['raidRankings'])) {
				return new WP_Error(
					'invalid_response',
					__('Invalid API response structure. Please check your settings.', 'wow-raid-progress')
				);
			}

			// Allow empty results
			if (empty($data['raidRankings'])) {
				$data['raidRankings'] = [];
			}

			// Fetch world ranking if needed
			if (!empty($data['raidRankings']) && !empty($guilds)) {
				$world_data = $this->fetch_world_ranking($raid, $difficulty, $guilds, $cache_minutes, $limit, $page);
				if (!is_wp_error($world_data) && !empty($world_data['raidRankings'][0])) {
					$data['worldRanking'] = $world_data['raidRankings'][0]['rank'] ?? null;
				}
			}

			// Cache successful response
			if ($cache_minutes > 0) {
				set_transient($cache_key, $data, $cache_minutes * MINUTE_IN_SECONDS);
			}

			return $data;
		} catch (Exception $e) {
			error_log('WoW Raid Progress Exception: ' . $e->getMessage());
			return new WP_Error(
				'unexpected_error',
				sprintf(__('An unexpected error occurred: %s', 'wow-raid-progress'), $e->getMessage())
			);
		}
	}

	/**
	 * Fetch world ranking data
	 */
	private function fetch_world_ranking($raid, $difficulty, $guilds, $cache_minutes, $limit, $page) {

		$raid       = sanitize_key($raid);
		$difficulty = sanitize_key($difficulty);
		if (!in_array($difficulty, ['normal', 'heroic', 'mythic'], true)) {
			return new WP_Error('invalid_difficulty', __('Invalid difficulty specified.', 'wow-raid-progress'));
		}
		$guilds = $this->sanitize_guilds($guilds);
		$limit  = max(1, min(100, absint($limit)));
		$page   = max(0, absint($page));

		$params = [
			'raid' => $raid,
			'difficulty' => $difficulty,
			'region' => 'world',
			'realm' => '',
			'guilds' => $guilds,
			'cache_minutes' => $cache_minutes,
			'limit' => $limit,
			'page' => $page
		];

		return $this->fetch_raid_data(
			$params['raid'],
			$params['difficulty'],
			$params['region'],
			$params['realm'],
			$params['guilds'],
			$params['cache_minutes'],
			$params['limit'],
			$params['page']
		);
	}

	/**
	 * Fetch static raid data for an expansion
	 */
	public function fetch_static_raid_data_by_expansion($expansion_id, $cache_minutes = 1440) {
		try {
			$cache_key = $this->static_cache_prefix . 'expansion_' . $expansion_id;
			$cache_duration = max($cache_minutes, 1440); // Minimum 24 hours for static data

			$cached_data = get_transient($cache_key);
			if ($cached_data !== false) {
				return $cached_data;
			}

			$api_key = get_option('wow_raid_progress_api_key');
			if (empty($api_key)) {
				return new WP_Error('no_api_key', __('API key not configured', 'wow-raid-progress'));
			}

			$url = add_query_arg([
				'access_key' => $api_key,
				'expansion_id' => absint($expansion_id)
			], $this->static_data_url);

			$response = wp_remote_get($url, [
				'timeout' => $this->http_timeout,
				'headers' => $this->base_headers(),
			]);

			if (is_wp_error($response)) {
				return $response;
			}

			if (wp_remote_retrieve_response_code($response) !== 200) {
				return new WP_Error('api_error', __('Failed to fetch static raid data', 'wow-raid-progress'));
			}

			$body = wp_remote_retrieve_body($response);
			$data = json_decode($body, true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				return new WP_Error('json_error', __('Failed to parse static data', 'wow-raid-progress'));
			}

			set_transient($cache_key, $data, $cache_duration * MINUTE_IN_SECONDS);
			return $data;
		} catch (Exception $e) {
			return new WP_Error('unexpected_error', $e->getMessage());
		}
	}

	/**
	 * Get Blizzard OAuth token
	 */
	public function get_blizzard_token($region = null) {
		$debug_mode = get_option('wow_raid_progress_debug_mode', 'false') === 'true';

		try {
			if (!$region) {
				$region = get_option('wow_raid_progress_blizzard_region', 'eu');
			}

			$cache_key = $this->blizzard_cache_prefix . 'oauth_token_' . $region;
			$cached_token = get_transient($cache_key);
			if ($cached_token !== false) {
				if ($debug_mode) {
					$this->debug_log("Using cached token for $region");
				}
				return $cached_token;
			}

			$client_id = get_option('wow_raid_progress_blizzard_client_id');
			$client_secret = get_option('wow_raid_progress_blizzard_client_secret');

			if (empty($client_id) || empty($client_secret)) {
				if ($debug_mode) {
					$this->debug_log("ERROR: Blizzard API credentials not configured");
				}
				return false;
			}

			$oauth_url = $this->regional_endpoints[$region]['oauth'];

			if ($debug_mode) {
				$this->debug_log("Requesting token from: $oauth_url");
			}

			$response = wp_remote_post($oauth_url, [
				'timeout' => $this->http_timeout,
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent' => $this->base_headers()['User-Agent']
				],
				'body' => 'grant_type=client_credentials',
				'sslverify' => true
			]);

			if (is_wp_error($response)) {
				if ($debug_mode) {
					$this->debug_log("ERROR getting token: " . $response->get_error_message());
				}
				return false;
			}

			$response_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($debug_mode) {
				$this->debug_log("Token response code: $response_code");
				if ($response_code !== 200) {
					$this->debug_log("Token response body: " . substr($body, 0, 500));
				}
			}

			if ($response_code !== 200) {
				return false;
			}

			$data = json_decode($body, true);
			if (isset($data['access_token'])) {
				$expires_in = isset($data['expires_in']) ? intval($data['expires_in']) : 3600;
				set_transient($cache_key, $data['access_token'], $expires_in - 60);

				if ($debug_mode) {
					$this->debug_log("✓ Token obtained for $region, expires in $expires_in seconds");
				}

				return $data['access_token'];
			}

			return false;
		} catch (Exception $e) {
			if ($debug_mode) {
				$this->debug_log("EXCEPTION in get_blizzard_token: " . $e->getMessage());
			}
			return false;
		}
	}

	/**
	 * Get achievement map for a raid
	 */
	public function get_achievement_map($raid_slug, $expansion_id) {
		try {
			$cache_key = $this->blizzard_cache_prefix . 'achieve_map_' . $raid_slug;
			$cached_map = get_transient($cache_key);
			if ($cached_map) {
				return $cached_map;
			}

			$region = get_option('wow_raid_progress_blizzard_region', 'eu');
			$token = $this->get_blizzard_token($region);
			if (!$token) {
				return [];
			}

			$expansion_name = $this->expansions[$expansion_id]['name'] ?? null;
			$category_id = $this->achievement_category_ids[$expansion_name] ?? null;
			if (!$category_id) {
				return [];
			}

			$api_base = $this->regional_endpoints[$region]['api'];
			$achievements_url = add_query_arg([
				'namespace' => 'static-' . $region,
				'locale' => 'en_US'
			], "{$api_base}/data/wow/achievement-category/{$category_id}");

			$response = wp_remote_get($achievements_url, [
				'timeout' => $this->http_timeout,
				'headers' => array_merge($this->base_headers(), [
					'Authorization' => 'Bearer ' . $token
				]),
			]);

			if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
				return [];
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);
			if (!isset($data['achievements'])) {
				return [];
			}

			$all_mythic_achievements = [];
			foreach ($data['achievements'] as $ach) {
				if (isset($ach['name']) && strpos($ach['name'], 'Mythic:') === 0) {
					$all_mythic_achievements[] = [
						'id' => $ach['id'],
						'boss_name' => trim(substr($ach['name'], 7))
					];
				}
			}

			$static_data = $this->fetch_static_raid_data_by_expansion($expansion_id, 0);
			$raid_info = null;
			if (!is_wp_error($static_data) && isset($static_data['raids'])) {
				foreach ($static_data['raids'] as $raid) {
					if ($raid['slug'] === $raid_slug) {
						$raid_info = $raid;
						break;
					}
				}
			}

			if (!$raid_info || !isset($raid_info['encounters'])) {
				return [];
			}

			$achievement_map = [];
			foreach ($raid_info['encounters'] as $boss) {
				$boss_name = $boss['name'];
				$lookup_name = $this->boss_name_overrides[$boss_name] ?? $boss_name;

				foreach ($all_mythic_achievements as $ach) {
					if (strcasecmp($lookup_name, $ach['boss_name']) === 0) {
						$achievement_map[$boss['slug']] = $ach['id'];
						break;
					}
				}
			}

			set_transient($cache_key, $achievement_map, 30 * DAY_IN_SECONDS);
			return $achievement_map;
		} catch (Exception $e) {
			error_log('Achievement Map Error: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * Get raid achievement ID (for raid icon)
	 */
	public function get_raid_achievement_id($raid_slug, $expansion_id) {
		try {
			$cache_key = $this->blizzard_cache_prefix . 'raid_achieve_' . $raid_slug;
			$cached_id = get_transient($cache_key);
			if ($cached_id !== false) {
				return $cached_id;
			}

			$region = get_option('wow_raid_progress_blizzard_region', 'eu');
			$token = $this->get_blizzard_token($region);
			if (!$token) {
				return false;
			}

			$expansion_name = $this->expansions[$expansion_id]['name'] ?? null;
			$category_id = $this->achievement_category_ids[$expansion_name] ?? null;
			if (!$category_id) {
				return false;
			}

			$api_base = $this->regional_endpoints[$region]['api'];
			$achievements_url = add_query_arg([
				'namespace' => 'static-' . $region,
				'locale' => 'en_US'
			], "{$api_base}/data/wow/achievement-category/{$category_id}");

			$response = wp_remote_get($achievements_url, [
				'timeout' => $this->http_timeout,
				'headers' => array_merge($this->base_headers(), [
					'Authorization' => 'Bearer ' . $token
				]),
			]);

			if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
				return false;
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);
			if (!isset($data['achievements'])) {
				return false;
			}

			// Look for raid meta achievement (usually contains raid name)
			$static_data = $this->fetch_static_raid_data_by_expansion($expansion_id, 0);
			$raid_name = null;
			if (!is_wp_error($static_data) && isset($static_data['raids'])) {
				foreach ($static_data['raids'] as $raid) {
					if ($raid['slug'] === $raid_slug) {
						$raid_name = $raid['name'];
						break;
					}
				}
			}

			if (!$raid_name) {
				return false;
			}

			// Look for achievement matching raid name
			foreach ($data['achievements'] as $ach) {
				if (isset($ach['name']) && (
					stripos($ach['name'], $raid_name) !== false ||
					stripos($ach['name'], 'Glory of') !== false && stripos($ach['name'], 'Raider') !== false
				)) {
					set_transient($cache_key, $ach['id'], 30 * DAY_IN_SECONDS);
					return $ach['id'];
				}
			}

			return false;
		} catch (Exception $e) {
			error_log('Raid Achievement Error: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get boss icon attachment ID with auto-download
	 *
	 * @param string $boss_slug Boss identifier
	 * @param string $raid_slug Raid identifier
	 * @param string|null $boss_name Boss display name for auto-download
	 * @param int|null $expansion_id Expansion ID for auto-download
	 * @return int|false Attachment ID or false if not found
	 */
	function get_boss_icon_id($boss_slug, $raid_slug, $boss_name = null, $expansion_id = null) {
		$cache_key = $this->blizzard_cache_prefix . 'attach_id_' . $raid_slug . '_' . $boss_slug;

		// 1) Use transient if present & valid
		$attachment_id = get_transient($cache_key);
		if ($attachment_id && get_post($attachment_id)) {
			return $attachment_id;
		}

		// 2) Fallback: re-discover an existing attachment by filename
		$filename = sanitize_file_name("{$raid_slug}_{$boss_slug}.jpg");

		$q = new WP_Query([
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'meta_query'     => [[
				'key'     => '_wp_attached_file',
				'value'   => '/' . $filename,
				'compare' => 'LIKE',
			]],
			'fields'                   => 'ids',
			'no_found_rows'            => true,
			'update_post_meta_cache'   => false,
			'update_post_term_cache'   => false,
		]);

		if (!empty($q->posts)) {
			$attachment_id = (int) $q->posts[0];
			if ($attachment_id && get_post($attachment_id)) {
				set_transient($cache_key, $attachment_id, 30 * DAY_IN_SECONDS);
				return $attachment_id;
			}
		}

		// 3) Auto-download if we have the required info and Blizzard API is configured
		if ($boss_name && $expansion_id) {
			$client_id = get_option('wow_raid_progress_blizzard_client_id');
			$client_secret = get_option('wow_raid_progress_blizzard_client_secret');

			if (!empty($client_id) && !empty($client_secret)) {
				$result = $this->import_single_boss_icon($boss_slug, $boss_name, $raid_slug, $expansion_id);
				if (is_numeric($result)) {
					// Successfully downloaded
					set_transient($cache_key, $result, 30 * DAY_IN_SECONDS);
					return $result;
				}
			}
		}

		// 4) No icon available
		return false;
	}

	/**
	 * Get raid icon attachment ID with auto-download
	 */
	function get_raid_icon_id($raid_slug, $expansion_id) {
		$cache_key = $this->blizzard_cache_prefix . 'raid_icon_' . $raid_slug;

		// Check transient cache
		$attachment_id = get_transient($cache_key);
		if ($attachment_id && get_post($attachment_id)) {
			return $attachment_id;
		}

		// Check if already downloaded
		$filename = sanitize_file_name("raid_{$raid_slug}.jpg");
		$q = new WP_Query([
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'meta_query'     => [[
				'key'     => '_wp_attached_file',
				'value'   => '/' . $filename,
				'compare' => 'LIKE',
			]],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);

		if (!empty($q->posts)) {
			$attachment_id = (int) $q->posts[0];
			if ($attachment_id && get_post($attachment_id)) {
				set_transient($cache_key, $attachment_id, 30 * DAY_IN_SECONDS);
				return $attachment_id;
			}
		}

		// Auto-download if Blizzard API is configured
		$client_id = get_option('wow_raid_progress_blizzard_client_id');
		$client_secret = get_option('wow_raid_progress_blizzard_client_secret');

		if (!empty($client_id) && !empty($client_secret)) {
			$achievement_id = $this->get_raid_achievement_id($raid_slug, $expansion_id);
			if ($achievement_id) {
				$result = $this->download_raid_icon($raid_slug, $achievement_id, $expansion_id);
				if (is_numeric($result)) {
					set_transient($cache_key, $result, 30 * DAY_IN_SECONDS);
					return $result;
				}
			}
		}

		return false;
	}

	/**
	 * Download raid icon
	 */
	private function download_raid_icon($raid_slug, $achievement_id, $expansion_id) {
		try {
			$region = get_option('wow_raid_progress_blizzard_region', 'eu');
			$token = $this->get_blizzard_token($region);
			if (!$token) {
				return false;
			}

			$api_base = $this->regional_endpoints[$region]['api'];
			$media_url = add_query_arg([
				'namespace' => 'static-' . $region,
				'locale' => 'en_US'
			], "{$api_base}/data/wow/media/achievement/{$achievement_id}");

			$response = wp_remote_get($media_url, [
				'timeout' => $this->http_timeout,
				'headers' => array_merge($this->base_headers(), [
					'Authorization' => 'Bearer ' . $token
				]),
			]);

			if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
				return false;
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);
			$icon_url = null;
			if (isset($data['assets'])) {
				foreach ($data['assets'] as $asset) {
					if ($asset['key'] === 'icon') {
						$icon_url = $asset['value'];
						break;
					}
				}
			}

			if (!$icon_url) {
				return false;
			}

			require_once(ABSPATH . 'wp-admin/includes/media.php');
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			require_once(ABSPATH . 'wp-admin/includes/image.php');

			$tmp = download_url($icon_url);
			if (is_wp_error($tmp)) {
				return false;
			}

			$file_array = [
				'name' => sanitize_file_name("raid_{$raid_slug}.jpg"),
				'tmp_name' => $tmp
			];

			// Get raid name for description
			$raid_name = $raid_slug;
			$static_data = $this->fetch_static_raid_data_by_expansion($expansion_id, 0);
			if (!is_wp_error($static_data) && isset($static_data['raids'])) {
				foreach ($static_data['raids'] as $raid) {
					if ($raid['slug'] === $raid_slug) {
						$raid_name = $raid['name'];
						break;
					}
				}
			}

			$attachment_id = media_handle_sideload($file_array, 0, $raid_name . ' Icon');
			@unlink($tmp);

			if (is_wp_error($attachment_id)) {
				return false;
			}

			// Mark as plugin attachment for later cleanup
			update_post_meta($attachment_id, '_wow_raid_progress_icon', 'raid');
			update_post_meta($attachment_id, '_wow_raid_progress_raid', $raid_slug);

			return $attachment_id;
		} catch (Exception $e) {
			error_log('Raid Icon Download Error: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Import single boss icon
	 */
	public function import_single_boss_icon($boss_slug, $boss_name, $raid_slug, $expansion_id) {
		try {
			$cache_key = $this->blizzard_cache_prefix . 'attach_id_' . $raid_slug . '_' . $boss_slug;
			$cached_attachment_id = get_transient($cache_key);
			if ($cached_attachment_id && get_post($cached_attachment_id)) {
				return true; // Already exists
			}

			$achievement_map = $this->get_achievement_map($raid_slug, $expansion_id);
			if (empty($achievement_map) || !isset($achievement_map[$boss_slug])) {
				return __('No achievement ID found.', 'wow-raid-progress');
			}

			$achievement_id = $achievement_map[$boss_slug];
			$region = get_option('wow_raid_progress_blizzard_region', 'eu');
			$token = $this->get_blizzard_token($region);
			if (!$token) {
				return __('Could not get Blizzard token.', 'wow-raid-progress');
			}

			$api_base = $this->regional_endpoints[$region]['api'];
			$media_url = add_query_arg([
				'namespace' => 'static-' . $region,
				'locale' => 'en_US'
			], "{$api_base}/data/wow/media/achievement/{$achievement_id}");

			$response = wp_remote_get($media_url, [
				'timeout' => $this->http_timeout,
				'headers' => array_merge($this->base_headers(), [
					'Authorization' => 'Bearer ' . $token
				]),
			]);

			if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
				return __('API call for media failed.', 'wow-raid-progress');
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);
			$icon_asset_url = null;
			if (isset($data['assets'])) {
				foreach ($data['assets'] as $asset) {
					if ($asset['key'] === 'icon') {
						$icon_asset_url = $asset['value'];
						break;
					}
				}
			}

			if (!$icon_asset_url) {
				return __('No icon asset found in API response.', 'wow-raid-progress');
			}

			require_once(ABSPATH . 'wp-admin/includes/media.php');
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			require_once(ABSPATH . 'wp-admin/includes/image.php');

			$tmp = download_url($icon_asset_url);
			if (is_wp_error($tmp)) {
				return sprintf(__('Download failed: %s', 'wow-raid-progress'), $tmp->get_error_message());
			}

			$file_array = [
				'name' => sanitize_file_name("{$raid_slug}_{$boss_slug}.jpg"),
				'tmp_name' => $tmp
			];

			$attachment_id = media_handle_sideload($file_array, 0, $boss_name);

			@unlink($tmp);

			if (is_wp_error($attachment_id)) {
				return sprintf(__('Media import failed: %s', 'wow-raid-progress'), $attachment_id->get_error_message());
			}

			// Mark as plugin attachment for later cleanup
			update_post_meta($attachment_id, '_wow_raid_progress_icon', 'boss');
			update_post_meta($attachment_id, '_wow_raid_progress_raid', $raid_slug);
			update_post_meta($attachment_id, '_wow_raid_progress_boss', $boss_slug);

			set_transient($cache_key, $attachment_id, 30 * DAY_IN_SECONDS);
			return $attachment_id;
		} catch (Exception $e) {
			return sprintf(__('Exception: %s', 'wow-raid-progress'), $e->getMessage());
		}
	}

	/**
	 * Delete all plugin-imported icons
	 */
	public function delete_all_icons() {
		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'     => '_wow_raid_progress_icon',
					'compare' => 'EXISTS',
				],
			],
			'fields' => 'ids',
		];

		$attachments = get_posts($args);
		$deleted = 0;

		foreach ($attachments as $attachment_id) {
			if (wp_delete_attachment($attachment_id, true)) {
				$deleted++;
			}
		}

		// Clear all icon caches
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_wow_blizzard_attach_id_%'
			 OR option_name LIKE '_transient_timeout_wow_blizzard_attach_id_%'
			 OR option_name LIKE '_transient_wow_blizzard_raid_icon_%'
			 OR option_name LIKE '_transient_timeout_wow_blizzard_raid_icon_%'"
		);

		return $deleted;
	}

	/**
	 * Parse API error message
	 */
	// Replace parse_api_error function:
	private function parse_api_error($body, $code) {
		$data = json_decode($body, true);

		// Try to extract detailed error message
		$error_detail = '';
		if (isset($data['error'])) {
			$error_detail = is_array($data['error']) ?
				(isset($data['error']['message']) ? $data['error']['message'] : json_encode($data['error'])) :
				$data['error'];
		} elseif (isset($data['message'])) {
			$error_detail = $data['message'];
		}

		switch ($code) {
			case 401:
				return sprintf(
					__('API authentication failed. Please check your API key. Details: %s', 'wow-raid-progress'),
					$error_detail ?: __('Invalid or expired API key', 'wow-raid-progress')
				);
			case 403:
				return sprintf(
					__('Access forbidden. Please verify API key permissions. Details: %s', 'wow-raid-progress'),
					$error_detail ?: __('Insufficient permissions', 'wow-raid-progress')
				);
			case 404:
				return sprintf(
					__('Resource not found. Please check raid name and parameters. Details: %s', 'wow-raid-progress'),
					$error_detail ?: __('The requested raid or guild was not found', 'wow-raid-progress')
				);
			case 429:
				return __('API rate limit exceeded. Please try again in a few minutes.', 'wow-raid-progress');
			case 500:
			case 502:
			case 503:
				return sprintf(
					__('API server error. The service may be temporarily unavailable. Details: %s', 'wow-raid-progress'),
					$error_detail ?: __('Please try again later', 'wow-raid-progress')
				);
			default:
				return sprintf(
					__('API Error (HTTP %d): %s', 'wow-raid-progress'),
					$code,
					$error_detail ?: __('Unknown error occurred', 'wow-raid-progress')
				);
		}
	}

	/**
	 * Get expansions list
	 */
	public function get_expansions() {
		return $this->expansions;
	}

	/**
	 * Get regional endpoints
	 */
	public function get_regional_endpoints() {
		return $this->regional_endpoints;
	}

	private function get_error_cache($key) {
		$err = get_transient($key . '_error');
		return $err ? new WP_Error('cached_error', $err) : false;
	}

	private function set_error_cache($key, $message, $ttl = 120) {
		set_transient($key . '_error', $message, $ttl);
	}

	/**
	 * Normalize a realm string to the slug Raider.io expects.
	 */
	public function sanitize_realm($realm) {
		if (empty($realm)) {
			return '';
		}
		// Validate realm name length
		if (strlen($realm) > 50) {
			return '';
		}
		$realm = sanitize_text_field(stripslashes($realm));
		$realm = remove_accents($realm);
		$realm = strtolower($realm);
		// Allow letters, numbers, spaces, and hyphens; then space->hyphen
		$realm = preg_replace('/[^a-z0-9\- ]+/', '', $realm);
		$realm = preg_replace('/\s+/', '-', trim($realm));
		return trim($realm, '-');
	}

	/**
	 * Normalize guilds to a comma-separated list of numeric IDs
	 */
	public function sanitize_guilds($guilds) {
		if (empty($guilds)) {
			return '';
		}
		// Accept array or CSV string
		if (!is_array($guilds)) {
			$guilds = preg_split('/[,\s]+/', (string) $guilds, -1, PREG_SPLIT_NO_EMPTY);
		}
		$guilds = array_map('sanitize_text_field', $guilds);
		$guilds = array_map('trim', $guilds);

		// Keep only numeric IDs
		$guilds = array_filter($guilds, static function ($g) {
			return (bool) preg_match('/^\d+$/', $g);
		});

		if (empty($guilds)) {
			return '';
		}

		// De-dupe, stable order, max 10 guilds
		$guilds = array_values(array_unique($guilds));
		$guilds = array_slice($guilds, 0, 10);
		return implode(',', $guilds);
	}

	/**
	 * Get available realms from Blizzard API
	 */
	public function get_realms_list($region = 'eu') {
		try {
			$cache_key = $this->blizzard_cache_prefix . 'realms_' . $region;
			$cached_realms = get_transient($cache_key);
			if ($cached_realms !== false) {
				return $cached_realms;
			}

			$token = $this->get_blizzard_token($region);
			if (!$token) {
				return [];
			}

			$api_base = $this->regional_endpoints[$region]['api'] ?? $this->regional_endpoints['eu']['api'];
			$realms_url = add_query_arg([
				'namespace' => 'dynamic-' . $region,
				'locale' => 'en_US'
			], "{$api_base}/data/wow/realm/index");

			$response = wp_remote_get($realms_url, [
				'timeout' => $this->http_timeout,
				'headers' => array_merge($this->base_headers(), [
					'Authorization' => 'Bearer ' . $token
				]),
			]);

			if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
				return [];
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);
			if (!isset($data['realms'])) {
				return [];
			}

			$realms = [];
			foreach ($data['realms'] as $realm) {
				$realms[] = [
					'slug' => $realm['slug'],
					'name' => $realm['name'],
					'id' => $realm['id']
				];
			}

			// Sort realms alphabetically
			usort($realms, function ($a, $b) {
				return strcasecmp($a['name'], $b['name']);
			});

			set_transient($cache_key, $realms, DAY_IN_SECONDS);
			return $realms;
		} catch (Exception $e) {
			error_log('WoW Raid Progress - Failed to fetch realms: ' . $e->getMessage());
			return [];
		}
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

		$realms = $this->api->get_realms_list($region);

		if (empty($realms)) {
			wp_send_json_error(__('Could not fetch realms. Check Blizzard API settings.', 'wow-raid-progress'));
			return;
		}

		wp_send_json_success($realms);
	}

	/**
	 * Get journal instance ID for a raid
	 */
	public function get_journal_instance_id($raid_slug, $expansion_id) {
		try {
			$cache_key = $this->blizzard_cache_prefix . 'journal_id_' . $raid_slug;
			$cached_id = get_transient($cache_key);
			if ($cached_id !== false) {
				return $cached_id;
			}

			$region = get_option('wow_raid_progress_blizzard_region', 'eu');
			$token = $this->get_blizzard_token($region);
			if (!$token) {
				return false;
			}

			$api_base = $this->regional_endpoints[$region]['api'];

			// Get all journal instances
			$instances_url = add_query_arg([
				'namespace' => 'static-' . $region,
				'locale' => 'en_US'
			], "{$api_base}/data/wow/journal-instance/index");

			$response = wp_remote_get($instances_url, [
				'timeout' => $this->http_timeout,
				'headers' => array_merge($this->base_headers(), [
					'Authorization' => 'Bearer ' . $token
				]),
			]);

			if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
				return false;
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);
			if (!isset($data['instances'])) {
				return false;
			}

			// Get raid name from static data to match
			$static_data = $this->fetch_static_raid_data_by_expansion($expansion_id, 1440);
			$raid_name = null;
			if (!is_wp_error($static_data) && isset($static_data['raids'])) {
				foreach ($static_data['raids'] as $raid) {
					if ($raid['slug'] === $raid_slug) {
						$raid_name = $raid['name'];
						break;
					}
				}
			}

			if (!$raid_name) {
				return false;
			}

			// Find matching journal instance
			foreach ($data['instances'] as $instance) {
				if (
					isset($instance['name']) &&
					(strcasecmp($instance['name'], $raid_name) === 0 ||
						stripos($instance['name'], $raid_name) !== false)
				) {
					set_transient($cache_key, $instance['id'], 30 * DAY_IN_SECONDS);
					return $instance['id'];
				}
			}

			return false;
		} catch (Exception $e) {
			error_log('Journal Instance Error: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get journal instance background image
	 */
	public function get_journal_instance_image($raid_slug, $expansion_id) {
		try {
			$cache_key = $this->blizzard_cache_prefix . 'journal_img_' . $raid_slug;
			$cached_url = get_transient($cache_key);
			if ($cached_url !== false) {
				return $cached_url;
			}

			$journal_id = $this->get_journal_instance_id($raid_slug, $expansion_id);
			if (!$journal_id) {
				return false;
			}

			$region = get_option('wow_raid_progress_blizzard_region', 'eu');
			$token = $this->get_blizzard_token($region);
			if (!$token) {
				return false;
			}

			$api_base = $this->regional_endpoints[$region]['api'];
			$media_url = add_query_arg([
				'namespace' => 'static-' . $region,
				'locale' => 'en_US'
			], "{$api_base}/data/wow/media/journal-instance/{$journal_id}");

			$response = wp_remote_get($media_url, [
				'timeout' => $this->http_timeout,
				'headers' => array_merge($this->base_headers(), [
					'Authorization' => 'Bearer ' . $token
				]),
			]);

			if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
				return false;
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);
			if (isset($data['assets'])) {
				foreach ($data['assets'] as $asset) {
					if ($asset['key'] === 'tile') {
						set_transient($cache_key, $asset['value'], 30 * DAY_IN_SECONDS);
						return $asset['value'];
					}
				}
			}

			return false;
		} catch (Exception $e) {
			error_log('Journal Image Error: ' . $e->getMessage());
			return false;
		}
	}

	public function debug_log($message) {
		if (
			get_option('wow_raid_progress_debug_mode', 'false') === 'true' ||
			(defined('WOW_RAID_PROGRESS_DEBUG') && WOW_RAID_PROGRESS_DEBUG)
		) {
			$log = get_transient('wow_raid_debug_log') ?: [];
			$log[] = date('H:i:s') . ' - ' . $message;
			// Keep only last 100 entries
			$log = array_slice($log, -100);
			set_transient('wow_raid_debug_log', $log, HOUR_IN_SECONDS);

			// Also log to error_log if WP_DEBUG is on
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('WoW Raid Progress: ' . $message);
			}
		}
	}
}
