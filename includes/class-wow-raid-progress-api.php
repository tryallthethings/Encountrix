<?php

/**
 * WoW Raid Progress API Handler
 *
 * Handles all API interactions with Raider.io and Blizzard APIs
 * Includes caching, rate limiting, and icon management
 *
 * @package WoWRaidProgress
 * @since 5.0.0
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

	// Rate limiting configuration
	private $rate_limit_key = 'wow_raid_api_rate_limit';
	private $max_requests_per_minute = 30;
	private $icon_retry_interval = 1800; // 30 minutes

	/**
	 * Get base headers for all API requests
	 *
	 * @return array HTTP headers
	 */
	private function base_headers() {
		return [
			'User-Agent' => 'WoWRaidProgress/' . (defined('WOW_RAID_PROGRESS_VERSION') ? WOW_RAID_PROGRESS_VERSION : 'dev') .
				' WordPress/' . get_bloginfo('version') . ' (' . home_url() . ')'
		];
	}

	private $expansions = [
		11 => ['name' => 'Midnight', 'category_name' => 'Midnight Raid'],
		10 => ['name' => 'The War Within', 'category_name' => 'The War Within Raid'],
		9  => ['name' => 'Dragonflight', 'category_name' => 'Dragonflight Raid'],
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
		'Midnight' => 15566,
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

	// Boss name mappings for achievement matching
	private $boss_name_overrides = [
		'dimensius' => 'Dimensius, the All-Devouring',
		'the-nine' => 'The Nine',
		'fatescribe-roh-kalo' => 'Fatescribe Roh-Kalo',
		'kelthuzad' => 'Kel\'Thuzad',
		'sylvanas-windrunner' => 'Sylvanas Windrunner'
	];

    /**
     * Multi-raid tier mappings.
     *
     * Raider.io sometimes groups several raids into one tier slug.
     * This map resolves the tier slug to a Blizzard journal instance
     * for fetching background images. 'primary' is tried first.
     */
    private $tier_journal_map = [
        'tier-mn-1' => ['Voidspire', 'Dreamrift', 'March on Quel\'Danas'],
    ];

	/**
	 * Check and enforce rate limiting
	 *
	 * @return bool True if request is allowed, false if rate limited
	 */
	private function check_rate_limit() {
		$current_time = time();
		$rate_data = get_transient($this->rate_limit_key);

		if (!$rate_data) {
			$rate_data = [
				'requests' => [],
				'window_start' => $current_time
			];
		}

		// Clean old requests outside the 60-second window
		$rate_data['requests'] = array_filter($rate_data['requests'], function ($timestamp) use ($current_time) {
			return ($current_time - $timestamp) < 60;
		});

		// Check if we've exceeded the limit
		if (count($rate_data['requests']) >= $this->max_requests_per_minute) {
			$this->debug_log('Rate limit exceeded. Waiting before next request.');
			return false;
		}

		// Add current request
		$rate_data['requests'][] = $current_time;
		set_transient($this->rate_limit_key, $rate_data, 60);

		return true;
	}

	/**
	 * Wait for rate limit to clear if needed
	 *
	 * @param int $max_wait Maximum seconds to wait
	 * @return bool True if can proceed, false if timed out
	 */
	private function wait_for_rate_limit($max_wait = 5) {
		$waited = 0;
		while (!$this->check_rate_limit() && $waited < $max_wait) {
			sleep(1);
			$waited++;
		}
		return $waited < $max_wait;
	}

	/**
	 * Fetch raid data with comprehensive error handling and rate limiting
	 *
	 * @param string $raid Raid slug
	 * @param string $difficulty Difficulty level
	 * @param string $region Region code
	 * @param string $realm Realm name
	 * @param string $guilds Guild IDs
	 * @param int $cache_minutes Cache duration
	 * @param int $limit Results limit
	 * @param int $page Page number
	 * @return array|WP_Error Raid data or error
	 */
	public function fetch_raid_data($raid, $difficulty, $region, $realm, $guilds, $cache_minutes, $limit = 50, $page = 0) {
		try {
			// Check if cron mode is enabled
			if (function_exists('wow_raid_progress_should_use_cron') && wow_raid_progress_should_use_cron() && !defined('DOING_CRON')) {
				// For frontend requests in cron mode, ONLY use cache
				$request_key = md5(serialize([
					'raid' => $raid,
					'difficulty' => $difficulty,
					'region' => $region,
					'realm' => $realm,
					'guilds' => $guilds,
					'limit' => $limit,
					'page' => $page
				]));

				$cache_key = $this->cache_prefix . $request_key;
				$cached_data = get_transient($cache_key);

				if ($cached_data !== false) {
					$this->debug_log("Cron mode: Returning cached data for frontend request");
					return $cached_data;
				}

				// Return placeholder if no cache exists
				return [
					'raidRankings' => [],
					'message' => __('Data is being updated in the background. Please check back shortly.', 'wow-raid-progress')
				];
			}

			// Input validation
			if (empty($raid)) {
				return new WP_Error('invalid_raid', __('Raid name is required.', 'wow-raid-progress'));
			}

			if (!in_array($difficulty, ['normal', 'heroic', 'mythic'])) {
				return new WP_Error('invalid_difficulty', __('Invalid difficulty specified.', 'wow-raid-progress'));
			}

			// Sanitize inputs
			$raid = sanitize_key($raid);
			$difficulty = sanitize_key($difficulty);
			$region = strtolower(sanitize_text_field($region));
			$realm = $this->sanitize_realm($realm);
			$guilds = $this->sanitize_guilds($guilds);
			$limit = max(1, min(100, absint($limit)));
			$page = max(0, absint($page));

			// Create unique request key
			$request_key = md5(serialize([
				'raid' => $raid,
				'difficulty' => $difficulty,
				'region' => $region,
				'realm' => $realm,
				'guilds' => $guilds,
				'limit' => $limit,
				'page' => $page
			]));

			$cache_key = $this->cache_prefix . $request_key;

			// CRITICAL: Check cache FIRST before anything else
			if ($cache_minutes > 0) {
				$cached_data = get_transient($cache_key);
				if ($cached_data !== false) {
					$this->debug_log("Cache HIT for request: $raid/$difficulty/$region (key: $request_key)");
					return $cached_data;
				}
			}

			// Check if this exact request is currently in progress
			$in_progress_key = 'wow_request_lock_' . $request_key;
			$in_progress = get_transient($in_progress_key);

			if ($in_progress !== false) {
				$this->debug_log("Request already in progress, waiting for: $raid/$difficulty/$region");

				// Wait for the other request to complete (max 5 seconds)
				for ($i = 0; $i < 10; $i++) {
					usleep(500000); // 0.5 seconds

					$cached_data = get_transient($cache_key);
					if ($cached_data !== false) {
						$this->debug_log("Got data from parallel request: $raid/$difficulty/$region");
						return $cached_data;
					}

					// Check if lock is still active
					if (get_transient($in_progress_key) === false) {
						break;
					}
				}
			}

			// Set lock for this request
			set_transient($in_progress_key, 1, 30);

			// Check for recent errors
			$error_cache_key = $cache_key . '_error';
			$cached_error = get_transient($error_cache_key);
			if ($cached_error !== false) {
				delete_transient($in_progress_key);
				$this->debug_log("Returning cached error for: $raid/$difficulty/$region");
				return new WP_Error('cached_error', $cached_error);
			}

			// Check API key
			$api_key = get_option('wow_raid_progress_api_key');
			if (empty($api_key)) {
				delete_transient($in_progress_key);
				return new WP_Error('no_api_key', __('Raider.io API key not configured.', 'wow-raid-progress'));
			}

			// Build API request
			$params = [
				'access_key' => $api_key,
				'raid' => $raid,
				'difficulty' => $difficulty,
				'region' => $region,
				'limit' => $limit,
				'page' => $page,
			];

			if (!empty($realm)) {
				$params['realm'] = $realm;
			}
			if (!empty($guilds)) {
				$params['guilds'] = $guilds;
			}

			$url = add_query_arg($params, $this->api_base_url);

			// Rate limiting check
			if (!$this->wait_for_rate_limit(5)) {
				delete_transient($in_progress_key);
				$error_msg = __('API rate limit exceeded. Please try again in a moment.', 'wow-raid-progress');
				set_transient($error_cache_key, $error_msg, 60);
				$this->debug_log("Rate limit exceeded for: $raid/$difficulty/$region");
				return new WP_Error('rate_limit', $error_msg);
			}

			$this->debug_log("Making API request for: $raid/$difficulty/$region/$realm/$guilds (key: $request_key)");

			$response = wp_remote_get($url, [
				'timeout' => $this->http_timeout,
				'headers' => $this->base_headers(),
			]);

			// Clear the lock
			delete_transient($in_progress_key);

			if (is_wp_error($response)) {
				$msg = $response->get_error_message();
				set_transient($error_cache_key, $msg, 120);
				$this->debug_log("API request failed: $msg");
				return new WP_Error('api_request_failed', sprintf(__('Failed to connect to Raider.io API: %s', 'wow-raid-progress'), $msg));
			}

			$response_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($response_code !== 200) {
				$error_message = $this->parse_api_error($body, $response_code);
				$ttl = ($response_code === 429) ? 300 : 120;
				set_transient($error_cache_key, $error_message, $ttl);
				$this->debug_log("API error $response_code for: $raid/$difficulty/$region");

				return new WP_Error(
					'api_http_error',
					sprintf(__('Raider.io API error (HTTP %d): %s', 'wow-raid-progress'), $response_code, $error_message)
				);
			}

			// Parse JSON response
			$data = json_decode($body, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->debug_log('JSON parse error: ' . json_last_error_msg());
				return new WP_Error('json_parse_error', __('Failed to parse API response.', 'wow-raid-progress'));
			}

			// Validate response structure
			if (!isset($data['raidRankings'])) {
				return new WP_Error('invalid_response', __('Invalid API response structure.', 'wow-raid-progress'));
			}

			// Fetch world ranking if needed
			if (!empty($data['raidRankings']) && !empty($guilds) && $region !== 'world') {
				$this->debug_log("Fetching world ranking for guild: $guilds");

				// Check if world ranking is already cached
				$world_cache_key = $this->cache_prefix . md5("world_rank_{$raid}_{$difficulty}_{$guilds}");
				$cached_world_rank = get_transient($world_cache_key);

				if ($cached_world_rank !== false) {
					$data['worldRanking'] = $cached_world_rank;
					$this->debug_log("Using cached world ranking: $cached_world_rank");
				} else {
					// Make a separate request for world ranking
					$world_params = [
						'access_key' => $api_key,
						'raid' => $raid,
						'difficulty' => $difficulty,
						'region' => 'world',
						'guilds' => $guilds,
						'limit' => 1,
						'page' => 0
					];

					$world_url = add_query_arg($world_params, $this->api_base_url);

					// Wait for rate limit
					if ($this->wait_for_rate_limit(5)) {
						$this->debug_log("Fetching world ranking from: $world_url");

						$world_response = wp_remote_get($world_url, [
							'timeout' => $this->http_timeout,
							'headers' => $this->base_headers(),
						]);

						if (!is_wp_error($world_response) && wp_remote_retrieve_response_code($world_response) === 200) {
							$world_data = json_decode(wp_remote_retrieve_body($world_response), true);
							if (!empty($world_data['raidRankings'][0]['rank'])) {
								$data['worldRanking'] = $world_data['raidRankings'][0]['rank'];
								// Cache world ranking separately
								set_transient($world_cache_key, $data['worldRanking'], $cache_minutes * MINUTE_IN_SECONDS);
								$this->debug_log("World ranking obtained: " . $data['worldRanking']);
							}
						}
					}
				}
			}

			// Cache successful response (KEEP THIS ORIGINAL CODE)
			if ($cache_minutes > 0) {
				set_transient($cache_key, $data, $cache_minutes * MINUTE_IN_SECONDS);
			}

			// Cache successful response with MINIMUM 5 minute cache
			$actual_cache_time = max($cache_minutes, 5);
			set_transient($cache_key, $data, $actual_cache_time * MINUTE_IN_SECONDS);
			$this->debug_log("Cached API response for $actual_cache_time minutes: $raid/$difficulty/$region (key: $request_key)");

			return $data;
		} catch (Exception $e) {
			if (isset($in_progress_key)) {
				delete_transient($in_progress_key);
			}
			$this->debug_log('Exception in fetch_raid_data: ' . $e->getMessage());
			return new WP_Error('unexpected_error', sprintf(__('An unexpected error occurred: %s', 'wow-raid-progress'), $e->getMessage()));
		}
	}

	/**
	 * Fetch static raid data for an expansion
	 *
	 * @param int $expansion_id Expansion ID
	 * @param int $cache_minutes Cache duration (minimum 24 hours)
	 * @return array|WP_Error Static raid data or error
	 */
	public function fetch_static_raid_data_by_expansion($expansion_id, $cache_minutes = 1440) {
		try {
			$cache_key = $this->static_cache_prefix . 'expansion_' . $expansion_id;
			$cache_duration = max($cache_minutes, 1440); // Minimum 24 hours for static data

			$cached_data = get_transient($cache_key);
			if ($cached_data !== false) {
				$this->debug_log("Using cached static data for expansion $expansion_id");
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

			// Wait for rate limit
			if (!$this->wait_for_rate_limit(10)) {
				$this->debug_log("Rate limit timeout for static data fetch");
				return new WP_Error('rate_limit', __('API rate limit exceeded. Please try again in a moment.', 'wow-raid-progress'));
			}

			$this->debug_log("Fetching static data for expansion $expansion_id");

			$response = wp_remote_get($url, [
				'timeout' => $this->http_timeout,
				'headers' => $this->base_headers(),
			]);

			if (is_wp_error($response)) {
				$this->debug_log("Failed to fetch static data: " . $response->get_error_message());
				return $response;
			}

			if (wp_remote_retrieve_response_code($response) !== 200) {
				$this->debug_log("Failed to fetch static data: HTTP " . wp_remote_retrieve_response_code($response));
				return new WP_Error('api_error', __('Failed to fetch static raid data', 'wow-raid-progress'));
			}

			$body = wp_remote_retrieve_body($response);
			$data = json_decode($body, true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->debug_log("JSON parse error for static data: " . json_last_error_msg());
				return new WP_Error('json_error', __('Failed to parse static data', 'wow-raid-progress'));
			}

			set_transient($cache_key, $data, $cache_duration * MINUTE_IN_SECONDS);
			$this->debug_log("Cached static data for $cache_duration minutes");

			return $data;
		} catch (Exception $e) {
			$this->debug_log('Exception in fetch_static_raid_data: ' . $e->getMessage());
			return new WP_Error('unexpected_error', $e->getMessage());
		}
	}

	/**
	 * Get Blizzard OAuth token with caching
	 *
	 * @param string|null $region Region code
	 * @return string|false OAuth token or false on failure
	 */
	public function get_blizzard_token($region = null) {
		try {
			if (!$region) {
				$region = get_option('wow_raid_progress_blizzard_region', 'eu');
			}

			$cache_key = $this->blizzard_cache_prefix . 'oauth_token_' . $region;
			$cached_token = get_transient($cache_key);
			if ($cached_token !== false) {
				$this->debug_log("Using cached Blizzard token for region: $region");
				return $cached_token;
			}

			$client_id = get_option('wow_raid_progress_blizzard_client_id');
			$client_secret = get_option('wow_raid_progress_blizzard_client_secret');

			if (empty($client_id) || empty($client_secret)) {
				$this->debug_log("Blizzard API credentials not configured");
				return false;
			}

			$oauth_url = $this->regional_endpoints[$region]['oauth'] ?? $this->regional_endpoints['us']['oauth'];

			// Wait for rate limit
			if (!$this->wait_for_rate_limit(10)) {
				$this->debug_log("Rate limit timeout for Blizzard token request");
				return false;
			}

			$this->debug_log("Requesting Blizzard token for region: $region");

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
				$this->debug_log("Failed to get Blizzard token: " . $response->get_error_message());
				return false;
			}

			$response_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($response_code !== 200) {
				$this->debug_log("Blizzard token request failed with HTTP $response_code");
				return false;
			}

			$data = json_decode($body, true);
			if (isset($data['access_token'])) {
				$expires_in = isset($data['expires_in']) ? intval($data['expires_in']) : 3600;
				set_transient($cache_key, $data['access_token'], $expires_in - 60);
				$this->debug_log("Blizzard token obtained for region $region, expires in $expires_in seconds");
				return $data['access_token'];
			}

			$this->debug_log("No access token in Blizzard response");
			return false;
		} catch (Exception $e) {
			$this->debug_log('Exception in get_blizzard_token: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get achievement map for a raid with proper boss name matching
	 *
	 * @param string $raid_slug Raid slug
	 * @param int $expansion_id Expansion ID
	 * @return array Achievement ID map keyed by boss slug
	 */
	public function get_achievement_map($raid_slug, $expansion_id) {
		try {
			$cache_key = $this->blizzard_cache_prefix . 'achieve_map_' . $raid_slug;
			$cached_map = get_transient($cache_key);
			if ($cached_map !== false) {
				$this->debug_log("Using cached achievement map for raid: $raid_slug");
				return $cached_map;
			}

			$region = get_option('wow_raid_progress_blizzard_region', 'eu');
			$token = $this->get_blizzard_token($region);
			if (!$token) {
				$this->debug_log("Cannot get achievement map: no Blizzard token");
				return [];
			}

			$expansion_name = $this->expansions[$expansion_id]['name'] ?? null;
			$category_id = $this->achievement_category_ids[$expansion_name] ?? null;
			if (!$category_id) {
				$this->debug_log("No achievement category ID for expansion: $expansion_name");
				return [];
			}

			$api_base = $this->regional_endpoints[$region]['api'];
			$achievements_url = add_query_arg([
				'namespace' => 'static-' . $region,
				'locale' => 'en_US'
			], "{$api_base}/data/wow/achievement-category/{$category_id}");

			// Wait for rate limit
			if (!$this->wait_for_rate_limit(10)) {
				$this->debug_log("Rate limit timeout for achievement map fetch");
				return [];
			}

			$this->debug_log("Fetching achievements for category $category_id");

			$response = wp_remote_get($achievements_url, [
				'timeout' => $this->http_timeout,
				'headers' => array_merge($this->base_headers(), [
					'Authorization' => 'Bearer ' . $token
				]),
			]);

			if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
				$this->debug_log("Failed to fetch achievement category data");
				return [];
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);
			if (!isset($data['achievements'])) {
				$this->debug_log("No achievements in category response");
				return [];
			}

			// Extract all Mythic achievements
			$all_mythic_achievements = [];
			foreach ($data['achievements'] as $ach) {
				if (isset($ach['name']) && strpos($ach['name'], 'Mythic:') === 0) {
					// Remove "Mythic: " prefix
					$boss_name = trim(substr($ach['name'], 7));
					$all_mythic_achievements[] = [
						'id' => $ach['id'],
						'boss_name' => $boss_name
					];
					$this->debug_log("Found achievement: {$ach['name']} (ID: {$ach['id']}, Boss: $boss_name)");
				}
			}

			// Get raid info from static data
			$static_data = $this->fetch_static_raid_data_by_expansion($expansion_id, 1440);
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
				$this->debug_log("No raid info found for slug: $raid_slug");
				return [];
			}

			// Build achievement map with improved matching
			$achievement_map = [];
			foreach ($raid_info['encounters'] as $boss) {
				$boss_slug = $boss['slug'];
				$boss_name = $boss['name'];

				// Check for override first
				$lookup_name = $this->boss_name_overrides[$boss_slug] ?? $boss_name;

				$this->debug_log("Matching boss: $boss_name (slug: $boss_slug, lookup: $lookup_name)");

				// Try exact match first
				foreach ($all_mythic_achievements as $ach) {
					if (strcasecmp($lookup_name, $ach['boss_name']) === 0) {
						$achievement_map[$boss_slug] = $ach['id'];
						$this->debug_log("Exact match found for $boss_slug: achievement ID {$ach['id']}");
						break;
					}
				}

				// If no exact match, try partial match
				if (!isset($achievement_map[$boss_slug])) {
					foreach ($all_mythic_achievements as $ach) {
						// Check if achievement contains boss name or vice versa
						if (
							stripos($ach['boss_name'], $boss_name) !== false ||
							stripos($boss_name, $ach['boss_name']) !== false
						) {
							$achievement_map[$boss_slug] = $ach['id'];
							$this->debug_log("Partial match found for $boss_slug: achievement ID {$ach['id']}");
							break;
						}
					}
				}

				if (!isset($achievement_map[$boss_slug])) {
					$this->debug_log("No achievement match found for boss: $boss_name (slug: $boss_slug)");
				}
			}

			set_transient($cache_key, $achievement_map, 30 * DAY_IN_SECONDS);
			$this->debug_log("Cached achievement map with " . count($achievement_map) . " entries");

			return $achievement_map;
		} catch (Exception $e) {
			$this->debug_log('Exception in get_achievement_map: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * Get boss icon attachment ID with strict duplicate prevention
	 *
	 * @param string $boss_slug Boss identifier
	 * @param string $raid_slug Raid identifier
	 * @param string|null $boss_name Boss display name for auto-download
	 * @param int|null $expansion_id Expansion ID for auto-download
	 * @return int|false Attachment ID or false if not found
	 */
	public function get_boss_icon_id($boss_slug, $raid_slug, $boss_name = null, $expansion_id = null) {
		$boss_slug = sanitize_key($boss_slug);
		$raid_slug = sanitize_key($raid_slug);

		// Use a lock to prevent concurrent downloads
		$lock_key = 'wow_icon_lock_' . $raid_slug . '_' . $boss_slug;
		$cache_key = 'wow_icon_id_' . $raid_slug . '_' . $boss_slug;

		// Check if another process is downloading this icon
		if (get_transient($lock_key) !== false) {
			$this->debug_log("Icon download in progress for $boss_slug, waiting...");

			// Wait up to 10 seconds for the other process to complete
			for ($i = 0; $i < 20; $i++) {
				usleep(500000); // 0.5 seconds
				$cached_id = get_transient($cache_key);
				if ($cached_id !== false && $cached_id > 0) {
					return $cached_id;
				}
			}
			return false;
		}

		// Check cache first
		$cached_id = get_transient($cache_key);
		if ($cached_id !== false) {
			if ($cached_id === 0) {
				// We've already tried and failed recently
				return false;
			}
			if (get_post($cached_id)) {
				return $cached_id;
			}
			// Cache is invalid, clear it
			delete_transient($cache_key);
		}

		// Search for existing attachment - check ALL possible metadata combinations
		global $wpdb;
		$attachment_id = $wpdb->get_var($wpdb->prepare(
			"SELECT p.ID
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id
         INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
         WHERE p.post_type = 'attachment'
         AND p.post_status = 'inherit'
         AND pm1.meta_key = '_wow_raid_progress_boss'
         AND pm1.meta_value = %s
         AND pm2.meta_key = '_wow_raid_progress_raid'
         AND pm2.meta_value = %s
         LIMIT 1",
			$boss_slug,
			$raid_slug
		));

		if ($attachment_id) {
			set_transient($cache_key, $attachment_id, 30 * DAY_IN_SECONDS);
			$this->debug_log("Found existing icon for $boss_slug: attachment ID $attachment_id");
			return $attachment_id;
		}

		// Also check by filename pattern
		$expected_filename = "{$raid_slug}_{$boss_slug}";
		$attachment_id = $wpdb->get_var($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
         AND post_status = 'inherit'
         AND post_name = %s
         LIMIT 1",
			$expected_filename
		));

		if ($attachment_id && get_post($attachment_id)) {
			// Add missing metadata
			update_post_meta($attachment_id, '_wow_raid_progress_icon', 'boss');
			update_post_meta($attachment_id, '_wow_raid_progress_raid', $raid_slug);
			update_post_meta($attachment_id, '_wow_raid_progress_boss', $boss_slug);

			set_transient($cache_key, $attachment_id, 30 * DAY_IN_SECONDS);
			$this->debug_log("Found existing icon by filename for $boss_slug: attachment ID $attachment_id");
			return $attachment_id;
		}

		// Check if we should attempt download
		$failure_key = 'wow_icon_fail_' . $raid_slug . '_' . $boss_slug;
		if (get_transient($failure_key) !== false) {
			$this->debug_log("Skipping download for $boss_slug (recent failure)");
			set_transient($cache_key, 0, 300); // Cache the failure for 5 minutes
			return false;
		}

		// Attempt download if we have the necessary data
		if ($boss_name && $expansion_id) {
			$client_id = get_option('wow_raid_progress_blizzard_client_id');
			$client_secret = get_option('wow_raid_progress_blizzard_client_secret');

			if (!empty($client_id) && !empty($client_secret)) {
				// Set lock to prevent concurrent downloads
				set_transient($lock_key, 1, 60);

				$this->debug_log("Attempting to download icon for $boss_slug (locked)");
				$result = $this->import_single_boss_icon($boss_slug, $boss_name, $raid_slug, $expansion_id);

				// Remove lock
				delete_transient($lock_key);

				if ($result && is_numeric($result)) {
					set_transient($cache_key, $result, 30 * DAY_IN_SECONDS);
					$this->debug_log("Successfully downloaded icon for $boss_slug: attachment ID $result");
					return $result;
				} else {
					set_transient($failure_key, time(), 1800); // Don't retry for 30 minutes
					set_transient($cache_key, 0, 300); // Cache the failure for 5 minutes
					$this->debug_log("Failed to download icon for $boss_slug");
				}
			}
		}

		return false;
	}

	/**
	 * Find existing boss icon attachment
	 *
	 * @param string $boss_slug Boss slug
	 * @param string $raid_slug Raid slug
	 * @return int|false Attachment ID or false
	 */
	private function find_boss_icon_attachment($boss_slug, $raid_slug) {
		// Search by meta data
		$args = [
			'post_type' => 'attachment',
			'post_status' => 'any',
			'posts_per_page' => 1,
			'meta_query' => [
				[
					'key' => '_wow_raid_progress_boss',
					'value' => $boss_slug,
				],
				[
					'key' => '_wow_raid_progress_raid',
					'value' => $raid_slug,
				],
			],
			'fields' => 'ids',
			'no_found_rows' => true,
		];

		$query = new WP_Query($args);
		if (!empty($query->posts)) {
			return (int) $query->posts[0];
		}

		// Try by filename
		$filename = sanitize_file_name("{$raid_slug}_{$boss_slug}.jpg");
		return $this->find_attachment_by_filename($filename, [
			'_wow_raid_progress_icon' => 'boss',
			'_wow_raid_progress_raid' => $raid_slug,
			'_wow_raid_progress_boss' => $boss_slug,
		]);
	}

	/**
	 * Check if we should attempt to download an icon
	 *
	 * @param string $boss_slug Boss slug
	 * @param string $raid_slug Raid slug
	 * @return bool True if download should be attempted
	 */
	private function should_attempt_icon_download($boss_slug, $raid_slug) {
		$failure_key = $this->blizzard_cache_prefix . 'icon_fail_' . $raid_slug . '_' . $boss_slug;
		$last_failure = get_transient($failure_key);

		// Don't retry if failed recently
		return $last_failure === false;
	}

	/**
	 * Mark icon download as failed
	 *
	 * @param string $boss_slug Boss slug
	 * @param string $raid_slug Raid slug
	 */
	private function mark_icon_download_failed($boss_slug, $raid_slug) {
		$failure_key = $this->blizzard_cache_prefix . 'icon_fail_' . $raid_slug . '_' . $boss_slug;
		set_transient($failure_key, time(), $this->icon_retry_interval);
	}

	/**
	 * Import single boss icon from Blizzard API
	 *
	 * @param string $boss_slug Boss slug
	 * @param string $boss_name Boss name
	 * @param string $raid_slug Raid slug
	 * @param int $expansion_id Expansion ID
	 * @return int|false Attachment ID or false on failure
	 */
	public function import_single_boss_icon($boss_slug, $boss_name, $raid_slug, $expansion_id) {
		try {
			$boss_slug = sanitize_key($boss_slug);
			$raid_slug = sanitize_key($raid_slug);

			// CRITICAL: Check for existing attachment FIRST
			global $wpdb;
			$existing_id = $wpdb->get_var($wpdb->prepare(
				"SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id
             INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
             WHERE p.post_type = 'attachment'
             AND p.post_status = 'inherit'
             AND pm1.meta_key = '_wow_raid_progress_boss'
             AND pm1.meta_value = %s
             AND pm2.meta_key = '_wow_raid_progress_raid'
             AND pm2.meta_value = %s
             LIMIT 1",
				$boss_slug,
				$raid_slug
			));

			if ($existing_id && get_post($existing_id)) {
				$this->debug_log("Icon already exists for $boss_slug: attachment ID $existing_id");

				// Update cache
				$cache_key = 'wow_icon_id_' . $raid_slug . '_' . $boss_slug;
				set_transient($cache_key, $existing_id, 30 * DAY_IN_SECONDS);

				return $existing_id;
			}

			// Also check by filename
			$expected_filename = "{$raid_slug}_{$boss_slug}";
			$existing_id = $wpdb->get_var($wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_status = 'inherit'
             AND post_name = %s
             LIMIT 1",
				$expected_filename
			));

			if ($existing_id && get_post($existing_id)) {
				// Add metadata if missing
				update_post_meta($existing_id, '_wow_raid_progress_icon', 'boss');
				update_post_meta($existing_id, '_wow_raid_progress_raid', $raid_slug);
				update_post_meta($existing_id, '_wow_raid_progress_boss', $boss_slug);

				$this->debug_log("Found existing icon by filename for $boss_slug: attachment ID $existing_id");

				// Update cache
				$cache_key = 'wow_icon_id_' . $raid_slug . '_' . $boss_slug;
				set_transient($cache_key, $existing_id, 30 * DAY_IN_SECONDS);

				return $existing_id;
			}

			// Get achievement map
			$achievement_map = $this->get_achievement_map($raid_slug, $expansion_id);
			if (empty($achievement_map) || !isset($achievement_map[$boss_slug])) {
				$this->debug_log("No achievement ID found for boss $boss_slug in raid $raid_slug");
				return false;
			}

			$achievement_id = $achievement_map[$boss_slug];
			$this->debug_log("Downloading icon for $boss_slug using achievement ID $achievement_id");

			$region = get_option('wow_raid_progress_blizzard_region', 'eu');
			$token = $this->get_blizzard_token($region);
			if (!$token) {
				return false;
			}

			$api_base = $this->regional_endpoints[$region]['api'];
			$media_url = add_query_arg([
				'namespace' => 'static-' . $region,
				'locale' => 'en_US',
			], "{$api_base}/data/wow/media/achievement/{$achievement_id}");

			// Rate limit check
			if (!$this->wait_for_rate_limit(10)) {
				return false;
			}

			$response = wp_remote_get($media_url, [
				'timeout' => $this->http_timeout,
				'headers' => array_merge($this->base_headers(), [
					'Authorization' => 'Bearer ' . $token,
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

			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$tmp = download_url($icon_url);
			if (is_wp_error($tmp)) {
				return false;
			}

			$file_array = [
				'name' => sanitize_file_name("{$raid_slug}_{$boss_slug}.jpg"),
				'tmp_name' => $tmp,
			];

			$attachment_id = media_handle_sideload($file_array, 0, $boss_name . ' Icon');
			@unlink($tmp);

			if (is_wp_error($attachment_id)) {
				return false;
			}

			// Add ALL metadata immediately
			update_post_meta($attachment_id, '_wow_raid_progress_icon', 'boss');
			update_post_meta($attachment_id, '_wow_raid_progress_raid', $raid_slug);
			update_post_meta($attachment_id, '_wow_raid_progress_boss', $boss_slug);
			update_post_meta($attachment_id, '_wow_raid_progress_expansion', $expansion_id);

			// Update cache
			$cache_key = 'wow_icon_id_' . $raid_slug . '_' . $boss_slug;
			set_transient($cache_key, $attachment_id, 30 * DAY_IN_SECONDS);

			$this->debug_log("Successfully imported NEW icon for $boss_slug: attachment ID $attachment_id");
			return $attachment_id;
		} catch (Exception $e) {
			$this->debug_log('Exception in import_single_boss_icon: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Delete all plugin-imported icons
	 *
	 * @return int Number of deleted attachments
	 */
	public function delete_all_icons() {
		global $wpdb;

		$deleted = 0;

		// Method 1: Find by any plugin metadata
		$attachment_ids = $wpdb->get_col(
			"SELECT DISTINCT post_id
         FROM {$wpdb->postmeta}
         WHERE meta_key IN (
             '_wow_raid_progress_icon',
             '_wow_raid_progress_raid',
             '_wow_raid_progress_boss',
             '_wow_raid_progress_expansion'
         )"
		);

		// Method 2: Find by filename pattern
		$pattern_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
         AND post_status = 'inherit'
         AND (
             post_name LIKE 'raid_%'
             OR post_name LIKE 'nerub-ar-palace_%'
             OR post_name LIKE 'vault-of-the-incarnates_%'
             OR post_name LIKE 'amirdrassil%'
             OR post_name LIKE 'aberrus%'
             OR post_name REGEXP '^[a-z-]+_[a-z-]+$'
         )"
		);

		// Method 3: Find by title pattern
		$title_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
         AND post_status = 'inherit'
         AND (
             post_title LIKE '% Icon'
             OR post_title LIKE '% Background'
         )"
		);

		// Combine all IDs and deduplicate
		$all_ids = array_unique(array_merge($attachment_ids, $pattern_ids, $title_ids));

		foreach ($all_ids as $attachment_id) {
			// Double-check this is a plugin attachment
			$has_plugin_meta = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE post_id = %d
             AND meta_key LIKE '_wow_raid_progress_%'",
				$attachment_id
			));

			// Only delete if it has plugin metadata OR matches strict filename pattern
			$post = get_post($attachment_id);
			if ($post && ($has_plugin_meta > 0 || preg_match('/^(raid_|[a-z-]+_[a-z-]+)/', $post->post_name))) {
				if (wp_delete_attachment($attachment_id, true)) {
					$deleted++;
					$this->debug_log("Deleted attachment ID $attachment_id: " . $post->post_name);
				}
			}
		}

		// Clear all icon-related caches
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_wow_icon_%'
         OR option_name LIKE '_transient_timeout_wow_icon_%'
         OR option_name LIKE '_transient_wow_blizzard_%'
         OR option_name LIKE '_transient_timeout_wow_blizzard_%'
         OR option_name LIKE '_transient_wow_request_lock_%'
         OR option_name LIKE '_transient_timeout_wow_request_lock_%'"
		);

		$this->debug_log("Deleted $deleted icon attachments and cleared all caches");
		return $deleted;
	}

	/**
	 * Clear all icon-related caches
	 */
	private function clear_icon_caches() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_wow_blizzard_boss_icon_%'
			 OR option_name LIKE '_transient_timeout_wow_blizzard_boss_icon_%'
			 OR option_name LIKE '_transient_wow_blizzard_raid_icon_%'
			 OR option_name LIKE '_transient_timeout_wow_blizzard_raid_icon_%'
			 OR option_name LIKE '_transient_wow_blizzard_journal_img_%'
			 OR option_name LIKE '_transient_timeout_wow_blizzard_journal_img_%'
			 OR option_name LIKE '_transient_wow_blizzard_icon_fail_%'
			 OR option_name LIKE '_transient_timeout_wow_blizzard_icon_fail_%'"
		);
	}

	/**
	 * Parse API error message for user-friendly display
	 *
	 * @param string $body Response body
	 * @param int $code HTTP status code
	 * @return string User-friendly error message
	 */
	private function parse_api_error($body, $code) {
		$data = json_decode($body, true);

		// Extract detailed error message
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
	 * Get error from cache
	 *
	 * @param string $key Cache key
	 * @return WP_Error|false Cached error or false
	 */
	private function get_error_cache($key) {
		$err = get_transient($key . '_error');
		return $err ? new WP_Error('cached_error', $err) : false;
	}

	/**
	 * Set error in cache
	 *
	 * @param string $key Cache key
	 * @param string $message Error message
	 * @param int $ttl Time to live in seconds
	 */
	private function set_error_cache($key, $message, $ttl = 120) {
		set_transient($key . '_error', $message, $ttl);
	}

	/**
	 * Normalize a realm string to the slug format expected by Raider.io
	 *
	 * @param string $realm Realm name
	 * @return string Normalized realm slug
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

		// Allow letters, numbers, spaces, and hyphens; convert spaces to hyphens
		$realm = preg_replace('/[^a-z0-9\- ]+/', '', $realm);
		$realm = preg_replace('/\s+/', '-', trim($realm));

		return trim($realm, '-');
	}

	/**
	 * Normalize guild IDs to a comma-separated list of numeric IDs
	 *
	 * @param string|array $guilds Guild IDs
	 * @return string Comma-separated guild IDs
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
		$guilds = array_filter($guilds, function ($g) {
			return (bool) preg_match('/^\d+$/', $g);
		});

		if (empty($guilds)) {
			return '';
		}

		// De-duplicate, maintain order, limit to 10 guilds
		$guilds = array_values(array_unique($guilds));
		$guilds = array_slice($guilds, 0, 10);

		return implode(',', $guilds);
	}

	/**
	 * Get available realms from Blizzard API
	 *
	 * @param string $region Region code
	 * @return array List of realms
	 */
	public function get_realms_list($region = 'eu') {
		try {
			$cache_key = $this->blizzard_cache_prefix . 'realms_' . $region;
			$cached_realms = get_transient($cache_key);
			if ($cached_realms !== false) {
				$this->debug_log("Using cached realms for region: $region");
				return $cached_realms;
			}

			$token = $this->get_blizzard_token($region);
			if (!$token) {
				$this->debug_log("Cannot get realms: no Blizzard token for region $region");
				return [];
			}

			$api_base = $this->regional_endpoints[$region]['api'] ?? $this->regional_endpoints['eu']['api'];
			$realms_url = add_query_arg([
				'namespace' => 'dynamic-' . $region,
				'locale' => 'en_US'
			], "{$api_base}/data/wow/realm/index");

			// Wait for rate limit
			if (!$this->wait_for_rate_limit(10)) {
				$this->debug_log("Rate limit timeout for realms fetch");
				return [];
			}

			$this->debug_log("Fetching realms for region: $region");

			$response = wp_remote_get($realms_url, [
				'timeout' => $this->http_timeout,
				'headers' => array_merge($this->base_headers(), [
					'Authorization' => 'Bearer ' . $token
				]),
			]);

			if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
				$this->debug_log("Failed to fetch realms for region $region");
				return [];
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);
			if (!isset($data['realms'])) {
				$this->debug_log("No realms in response for region $region");
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

			// Sort realms alphabetically
			usort($realms, function ($a, $b) {
				return strcasecmp($a['name'], $b['name']);
			});

			set_transient($cache_key, $realms, DAY_IN_SECONDS);
			$this->debug_log("Cached " . count($realms) . " realms for region $region");

			return $realms;
		} catch (Exception $e) {
			$this->debug_log('Exception in get_realms_list: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * Find attachment by filename and add metadata if missing
	 *
	 * @param string $filename Target filename
	 * @param array $meta Metadata to add if missing
	 * @return int|false Attachment ID or false
	 */
	private function find_attachment_by_filename($filename, array $meta) {
		$name = sanitize_title(pathinfo($filename, PATHINFO_FILENAME));

		$existing = get_posts([
			'post_type' => 'attachment',
			'name' => $name,
			'post_status' => 'any',
			'posts_per_page' => 1,
			'fields' => 'ids',
		]);

		if (!empty($existing)) {
			$attachment_id = (int) $existing[0];

			// Add missing metadata
			foreach ($meta as $key => $value) {
				if (!metadata_exists('post', $attachment_id, $key)) {
					update_post_meta($attachment_id, $key, $value);
				}
			}

			return $attachment_id;
		}

		return false;
	}

	/**
	 * Get raid icon attachment ID with auto-download
	 *
	 * @param string $raid_slug Raid slug
	 * @param int $expansion_id Expansion ID
	 * @return int|false Attachment ID or false
	 */
	public function get_raid_icon_id($raid_slug, $expansion_id) {
		$raid_slug = sanitize_key($raid_slug);
		$cache_key = $this->blizzard_cache_prefix . 'raid_icon_' . $raid_slug;

		// Check cached attachment ID
		$attachment_id = get_transient($cache_key);
		if ($attachment_id && get_post($attachment_id)) {
			return $attachment_id;
		}

		// Search for existing attachment
		$q = new WP_Query([
			'post_type' => 'attachment',
			'post_status' => 'any',
			'posts_per_page' => 1,
			'meta_query' => [
				[
					'key' => '_wow_raid_progress_icon',
					'value' => 'raid',
				],
				[
					'key' => '_wow_raid_progress_raid',
					'value' => $raid_slug,
				],
			],
			'fields' => 'ids',
			'no_found_rows' => true,
		]);

		if (!empty($q->posts)) {
			$attachment_id = (int) $q->posts[0];
			if (get_post($attachment_id)) {
				set_transient($cache_key, $attachment_id, 30 * DAY_IN_SECONDS);
				return $attachment_id;
			}
		}

		// Try to download if credentials are available
		$client_id = get_option('wow_raid_progress_blizzard_client_id');
		$client_secret = get_option('wow_raid_progress_blizzard_client_secret');

		if (!empty($client_id) && !empty($client_secret)) {
			$achievement_id = $this->get_raid_achievement_id($raid_slug, $expansion_id);
			if ($achievement_id) {
				$result = $this->download_raid_icon($raid_slug, $achievement_id, $expansion_id);
				if ($result) {
					set_transient($cache_key, $result, 30 * DAY_IN_SECONDS);
					return $result;
				}
			}
		}

		return false;
	}

	/**
	 * Get raid achievement ID
	 *
	 * @param string $raid_slug Raid slug
	 * @param int $expansion_id Expansion ID
	 * @return int|false Achievement ID or false
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

			// Wait for rate limit
			if (!$this->wait_for_rate_limit(10)) {
				return false;
			}

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

			// Get raid name from static data
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

			// Look for achievement matching raid name
			foreach ($data['achievements'] as $ach) {
				if (isset($ach['name']) && (
					stripos($ach['name'], $raid_name) !== false ||
					(stripos($ach['name'], 'Glory of') !== false && stripos($ach['name'], 'Raider') !== false)
				)) {
					set_transient($cache_key, $ach['id'], 30 * DAY_IN_SECONDS);
					return $ach['id'];
				}
			}

			return false;
		} catch (Exception $e) {
			$this->debug_log('Exception in get_raid_achievement_id: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Download raid icon from Blizzard API
	 *
	 * @param string $raid_slug Raid slug
	 * @param int $achievement_id Achievement ID
	 * @param int $expansion_id Expansion ID
	 * @return int|false Attachment ID or false
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

			// Wait for rate limit
			if (!$this->wait_for_rate_limit(10)) {
				return false;
			}

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

			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

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
			$static_data = $this->fetch_static_raid_data_by_expansion($expansion_id, 1440);
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

			// Mark as plugin attachment
			update_post_meta($attachment_id, '_wow_raid_progress_icon', 'raid');
			update_post_meta($attachment_id, '_wow_raid_progress_raid', $raid_slug);

			return $attachment_id;
		} catch (Exception $e) {
			$this->debug_log('Exception in download_raid_icon: ' . $e->getMessage());
			return false;
		}
	}

	/**
     * Get journal instance ID for a raid
     *
     * @param string $raid_slug Raid slug
     * @param int $expansion_id Expansion ID
     * @return int|false Journal instance ID or false
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
            $instances_url = add_query_arg([
                'namespace' => 'static-' . $region,
                'locale' => 'en_US'
            ], "{$api_base}/data/wow/journal-instance/index");

            // Wait for rate limit
            if (!$this->wait_for_rate_limit(10)) {
                return false;
            }

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

            // === TIER MAP: handle multi-raid tiers (e.g. Midnight tier-mn-1) ===
            if (isset($this->tier_journal_map[$raid_slug])) {
                $search_names = $this->tier_journal_map[$raid_slug];
                $this->debug_log("Tier map active for $raid_slug, searching for: " . implode(', ', $search_names));

			foreach ($search_names as $search_name) {
				foreach ($data['instances'] as $instance) {
					if (!isset($instance['name']) || !isset($instance['id'])) {
						continue;
					}
                        if (
                            strcasecmp($instance['name'], $search_name) === 0 ||
                            stripos($instance['name'], $search_name) !== false
                        ) {
                            $this->debug_log("Tier map match: {$instance['name']} (ID: {$instance['id']})");
                            set_transient($cache_key, $instance['id'], 30 * DAY_IN_SECONDS);
                            return $instance['id'];
                        }
                    }
                }

                $this->debug_log("No journal instance found via tier map for: $raid_slug");
                return false;
            }
            // === END TIER MAP ===

            // Get raid name from static data
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
            $this->debug_log('Exception in get_journal_instance_id: ' . $e->getMessage());
            return false;
        }
    }

	/**
	 * Get raid background image attachment ID with auto-download
	 *
	 * @param string $raid_slug Raid identifier
	 * @param int $expansion_id Expansion ID
	 * @return int|false Attachment ID or false if unavailable
	 */
	public function get_journal_instance_image_id($raid_slug, $expansion_id) {
		try {
			$raid_slug = sanitize_key($raid_slug);
			$cache_key = $this->blizzard_cache_prefix . 'journal_img_' . $raid_slug;

			// Check cached attachment ID
			$attachment_id = get_transient($cache_key);
			if ($attachment_id && get_post($attachment_id)) {
				return $attachment_id;
			}

			// Search for existing attachment
			$q = new WP_Query([
				'post_type' => 'attachment',
				'post_status' => 'any',
				'posts_per_page' => 1,
				'meta_query' => [
					[
						'key' => '_wow_raid_progress_icon',
						'value' => 'raid_bg',
					],
					[
						'key' => '_wow_raid_progress_raid',
						'value' => $raid_slug,
					],
				],
				'fields' => 'ids',
				'no_found_rows' => true,
			]);

			if (!empty($q->posts)) {
				$attachment_id = (int) $q->posts[0];
				if (get_post($attachment_id)) {
					set_transient($cache_key, $attachment_id, 30 * DAY_IN_SECONDS);
					return $attachment_id;
				}
			}

			// Try to download from Blizzard
			$journal_id = $this->get_journal_instance_id($raid_slug, $expansion_id);
			if (!$journal_id) {
				return false;
			}

			$attachment_id = $this->download_journal_instance_image($raid_slug, $journal_id, $expansion_id);
			if ($attachment_id) {
				set_transient($cache_key, $attachment_id, 30 * DAY_IN_SECONDS);
				return $attachment_id;
			}

			return false;
		} catch (Exception $e) {
			$this->debug_log('Exception in get_journal_instance_image_id: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Download raid background image from Blizzard API
	 *
	 * @param string $raid_slug Raid identifier
	 * @param int $journal_id Journal instance ID
	 * @param int $expansion_id Expansion identifier
	 * @return int|false Attachment ID or false on failure
	 */
	private function download_journal_instance_image($raid_slug, $journal_id, $expansion_id) {
		$region = get_option('wow_raid_progress_blizzard_region', 'eu');
		$token = $this->get_blizzard_token($region);
		if (!$token) {
			return false;
		}

		$api_base = $this->regional_endpoints[$region]['api'];
		$media_url = add_query_arg([
			'namespace' => 'static-' . $region,
			'locale' => 'en_US',
		], "{$api_base}/data/wow/media/journal-instance/{$journal_id}");

		// Wait for rate limit
		if (!$this->wait_for_rate_limit(10)) {
			return false;
		}

		$response = wp_remote_get($media_url, [
			'timeout' => $this->http_timeout,
			'headers' => array_merge($this->base_headers(), [
				'Authorization' => 'Bearer ' . $token,
			]),
		]);

		if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
			return false;
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);
		$image_url = null;

		if (isset($data['assets'])) {
			foreach ($data['assets'] as $asset) {
				if ($asset['key'] === 'tile') {
					$image_url = $asset['value'];
					break;
				}
			}
		}

		if (!$image_url) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url($image_url);
		if (is_wp_error($tmp)) {
			return false;
		}

		$file_array = [
			'name' => sanitize_file_name("raid_bg_{$raid_slug}.jpg"),
			'tmp_name' => $tmp,
		];

		// Get raid name for attachment title
		$raid_name = $raid_slug;
		$static_data = $this->fetch_static_raid_data_by_expansion($expansion_id, 1440);
		if (!is_wp_error($static_data) && isset($static_data['raids'])) {
			foreach ($static_data['raids'] as $raid) {
				if ($raid['slug'] === $raid_slug) {
					$raid_name = $raid['name'];
					break;
				}
			}
		}

		$attachment_id = media_handle_sideload($file_array, 0, $raid_name . ' Background');
		@unlink($tmp);

		if (is_wp_error($attachment_id)) {
			return false;
		}

		update_post_meta($attachment_id, '_wow_raid_progress_icon', 'raid_bg');
		update_post_meta($attachment_id, '_wow_raid_progress_raid', $raid_slug);

		return $attachment_id;
	}

	/**
	 * Get expansions list
	 *
	 * @return array Expansions
	 */
	public function get_expansions() {
		return $this->expansions;
	}

	/**
	 * Get regional endpoints
	 *
	 * @return array Regional endpoints
	 */
	public function get_regional_endpoints() {
		return $this->regional_endpoints;
	}

	/**
	 * Log debug message if debug mode is enabled
	 *
	 * @param string $message Message to log
	 */
	public function debug_log($message) {
		$enabled = get_option('wow_raid_progress_debug_mode', 'false');

		if ($enabled === 'true' || (defined('WOW_RAID_PROGRESS_DEBUG') && WOW_RAID_PROGRESS_DEBUG)) {
			// Add to transient log for admin display
			$log = get_transient('wow_raid_debug_log') ?: [];
			$log[] = date('H:i:s') . ' - ' . $message;
			$log = array_slice($log, -100); // Keep last 100 entries
			set_transient('wow_raid_debug_log', $log, HOUR_IN_SECONDS);

			// Also log to error_log if WP_DEBUG is enabled
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('WoW Raid Progress: ' . $message);
			}
		}
	}
}
