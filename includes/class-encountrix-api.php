<?php

/**
 * Encountrix API Handler
 *
 * Handles all API interactions with Raider.io and Blizzard APIs
 * Includes caching, rate limiting, and icon management
 *
 * @package Encountrix
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class EncountrixApi {

	private string $api_base_url = 'https://raider.io/api/v1/raiding/raid-rankings';
	private string $static_data_url = 'https://raider.io/api/v1/raiding/static-data';
	private string $cache_prefix = 'encountrix_';
	private string $static_cache_prefix = 'encountrix_static_';
	private string $blizzard_cache_prefix = 'encountrix_blizzard_';
	private int $http_timeout = 8;

	// Rate limiting configuration
	private string $rate_limit_key = 'encountrix_api_rate_limit';
	private int $max_requests_per_minute = 30;

	/**
	 * Get base headers for all API requests
	 *
	 * @return array<string, string>
	 */
	private function base_headers(): array {
		return [
			'User-Agent' => 'Encountrix/' . (defined('ENCOUNTRIX_VERSION') ? ENCOUNTRIX_VERSION : 'dev') .
				' WordPress/' . get_bloginfo('version') . ' (' . home_url() . ')'
		];
	}


	/**
	 * Validate remote media URL host before downloading to avoid SSRF-style abuse.
	 */
	private function is_allowed_media_url(string $url): bool {
		if (!wp_http_validate_url($url)) {
			return false;
		}

		$host = wp_parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return false;
		}

		$allowed_hosts = [
			'assets.worldofwarcraft.blizzard.com',
			'blz-contentstack-assets.akamaized.net',
		];

		return in_array(strtolower($host), $allowed_hosts, true);
	}

	/**
	 * Delete temporary files created by download_url().
	 */
	private function cleanup_temp_file(string $tmp_file): void {
		if (is_file($tmp_file)) {
			wp_delete_file($tmp_file);
		}
	}

	/** @var array<int, array{name: string, category_name: string}> */
	private array $expansions = [
		11 => ['name' => 'Midnight', 'category_name' => 'Midnight Raid'],
		10 => ['name' => 'The War Within', 'category_name' => 'The War Within Raid'],
		9  => ['name' => 'Dragonflight', 'category_name' => 'Dragonflight Raid'],
		8  => ['name' => 'Shadowlands', 'category_name' => 'Shadowlands Raid'],
		7  => ['name' => 'Battle for Azeroth', 'category_name' => 'Battle for Azeroth Raid']
	];

	/** @var array<string, array{oauth: string, api: string}> */
	private array $regional_endpoints = [
		'us' => ['oauth' => 'https://oauth.battle.net/token', 'api' => 'https://us.api.blizzard.com'],
		'eu' => ['oauth' => 'https://oauth.battle.net/token', 'api' => 'https://eu.api.blizzard.com'],
		'kr' => ['oauth' => 'https://oauth.battle.net/token', 'api' => 'https://kr.api.blizzard.com'],
		'tw' => ['oauth' => 'https://oauth.battle.net/token', 'api' => 'https://tw.api.blizzard.com']
	];

	/** @var array<string, int> */
	private array $achievement_category_ids = [
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

	/** @var array<string, string> Boss name mappings for achievement matching */
	private array $boss_name_overrides = [
		'dimensius' => 'Dimensius, the All-Devouring',
		'the-nine' => 'The Nine',
		'fatescribe-roh-kalo' => 'Fatescribe Roh-Kalo',
		'kelthuzad' => 'Kel\'Thuzad',
		'sylvanas-windrunner' => 'Sylvanas Windrunner'
	];

	/**
	 * Multi-raid tier mappings.
	 *
	 * @var array<string, array<string>>
	 */
	private array $tier_journal_map = [
		'tier-mn-1' => ['Voidspire', 'Dreamrift', 'March on Quel\'Danas'],
	];

	/**
	 * Delete transients by option name prefix using prepared statements.
	 *
	 * @param array<string> $prefixes Option name prefixes (without trailing %).
	 */
	private function delete_transients_by_prefix(array $prefixes): void {
		global $wpdb;
		foreach ($prefixes as $prefix) {
			$wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like($prefix) . '%'
			));
		}
	}

	/**
	 * Check and enforce rate limiting
	 */
	private function check_rate_limit(): bool {
		$current_time = time();
		$rate_data = get_transient($this->rate_limit_key);

		if (!$rate_data) {
			$rate_data = [
				'requests' => [],
				'window_start' => $current_time
			];
		}

		// Clean old requests outside the 60-second window
		$rate_data['requests'] = array_filter($rate_data['requests'], static function (int $timestamp) use ($current_time): bool {
			return ($current_time - $timestamp) < 60;
		});

		if (count($rate_data['requests']) >= $this->max_requests_per_minute) {
			$this->debug_log('Rate limit exceeded. Waiting before next request.');
			return false;
		}

		$rate_data['requests'][] = $current_time;
		set_transient($this->rate_limit_key, $rate_data, 60);

		return true;
	}

	/**
	 * Wait for rate limit to clear if needed
	 */
	private function wait_for_rate_limit(int $max_wait = 5): bool {
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
	 * @return array|WP_Error Raid data or error
	 */
	public function fetch_raid_data(
		string $raid,
		string $difficulty,
		string $region,
		string $realm,
		string $guilds,
		int $cache_minutes,
		int $limit = 50,
		int $page = 0
	): array|WP_Error {
		$in_progress_key = null;

		try {
			// Check if cron mode is enabled
			if (function_exists('encountrix_should_use_cron') && encountrix_should_use_cron() && !defined('DOING_CRON')) {
				$request_key = hash('sha256', (string) wp_json_encode([
					'raid' => $raid,
					'difficulty' => $difficulty,
					'region' => $region,
					'realm' => $realm,
					'guilds' => $guilds,
					'limit' => $limit,
					'page' => $page,
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

				$cache_key = $this->cache_prefix . $request_key;
				$cached_data = get_transient($cache_key);

				if ($cached_data !== false) {
					$this->debug_log("Cron mode: Returning cached data for frontend request");
					return $cached_data;
				}

				return [
					'raidRankings' => [],
					'message' => __('Data is being updated in the background. Please check back shortly.', 'encountrix')
				];
			}

			// Input validation
			if (empty($raid)) {
				return new WP_Error('invalid_raid', __('Raid name is required.', 'encountrix'));
			}

			if (!in_array($difficulty, ['normal', 'heroic', 'mythic'], true)) {
				return new WP_Error('invalid_difficulty', __('Invalid difficulty specified.', 'encountrix'));
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
			$request_key = hash('sha256', (string) wp_json_encode([
				'raid' => $raid,
				'difficulty' => $difficulty,
				'region' => $region,
				'realm' => $realm,
				'guilds' => $guilds,
				'limit' => $limit,
				'page' => $page,
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

			$cache_key = $this->cache_prefix . $request_key;

			// Check cache FIRST
			if ($cache_minutes > 0) {
				$cached_data = get_transient($cache_key);
				if ($cached_data !== false) {
					$this->debug_log("Cache HIT for request: $raid/$difficulty/$region (key: $request_key)");
					return $cached_data;
				}
			}

			// Check if this exact request is currently in progress
			$in_progress_key = 'encountrix_request_lock_' . $request_key;
			$in_progress = get_transient($in_progress_key);

			if ($in_progress !== false) {
				$this->debug_log("Request already in progress, waiting for: $raid/$difficulty/$region");

				for ($i = 0; $i < 10; $i++) {
					usleep(500000);

					$cached_data = get_transient($cache_key);
					if ($cached_data !== false) {
						$this->debug_log("Got data from parallel request: $raid/$difficulty/$region");
						return $cached_data;
					}

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
			$api_key = get_option('encountrix_api_key');
			if (empty($api_key)) {
				delete_transient($in_progress_key);
				return new WP_Error('no_api_key', __('Raider.io API key not configured.', 'encountrix'));
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
				$error_msg = __('API rate limit exceeded. Please try again in a moment.', 'encountrix');
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
			$in_progress_key = null; // Prevent double-delete in catch

			if (is_wp_error($response)) {
				$msg = $response->get_error_message();
				set_transient($error_cache_key, $msg, 120);
				$this->debug_log("API request failed: $msg");
				return new WP_Error('api_request_failed', sprintf(__('Failed to connect to Raider.io API: %s', 'encountrix'), $msg));
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
					sprintf(__('Raider.io API error (HTTP %d): %s', 'encountrix'), $response_code, $error_message)
				);
			}

			// Parse JSON response
			$data = json_decode($body, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->debug_log('JSON parse error: ' . json_last_error_msg());
				return new WP_Error('json_parse_error', __('Failed to parse API response.', 'encountrix'));
			}

			// Validate response structure
			if (!isset($data['raidRankings'])) {
				return new WP_Error('invalid_response', __('Invalid API response structure.', 'encountrix'));
			}

			// Fetch world ranking if needed
			if (!empty($data['raidRankings']) && !empty($guilds) && $region !== 'world') {
				$this->debug_log("Fetching world ranking for guild: $guilds");

				$world_cache_key = $this->cache_prefix . md5("world_rank_{$raid}_{$difficulty}_{$guilds}");
				$cached_world_rank = get_transient($world_cache_key);

				if ($cached_world_rank !== false) {
					$data['worldRanking'] = $cached_world_rank;
					$this->debug_log("Using cached world ranking: $cached_world_rank");
				} else {
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
								set_transient($world_cache_key, $data['worldRanking'], $cache_minutes * MINUTE_IN_SECONDS);
								$this->debug_log("World ranking obtained: " . $data['worldRanking']);
							}
						}
					}
				}
			}

			// Enforce a minimum cache duration of 5 minutes.
			$actual_cache_time = max($cache_minutes, 5);
			set_transient($cache_key, $data, $actual_cache_time * MINUTE_IN_SECONDS);
			$this->debug_log("Cached API response for $actual_cache_time minutes: $raid/$difficulty/$region (key: $request_key)");

			return $data;
		} catch (\Exception $e) {
			if ($in_progress_key !== null) {
				delete_transient($in_progress_key);
			}
			$this->debug_log('Exception in fetch_raid_data: ' . $e->getMessage());
			return new WP_Error('unexpected_error', sprintf(__('An unexpected error occurred: %s', 'encountrix'), $e->getMessage()));
		}
	}

	/**
	 * Fetch static raid data for an expansion
	 *
	 * @return array|WP_Error Static raid data or error
	 */
	public function fetch_static_raid_data_by_expansion(int $expansion_id, int $cache_minutes = 1440): array|WP_Error {
		try {
			$cache_key = $this->static_cache_prefix . 'expansion_' . $expansion_id;
			$cache_duration = max($cache_minutes, 1440);

			$cached_data = get_transient($cache_key);
			if ($cached_data !== false) {
				$this->debug_log("Using cached static data for expansion $expansion_id");
				return $cached_data;
			}

			$api_key = get_option('encountrix_api_key');
			if (empty($api_key)) {
				return new WP_Error('no_api_key', __('API key not configured', 'encountrix'));
			}

			$url = add_query_arg([
				'access_key' => $api_key,
				'expansion_id' => absint($expansion_id)
			], $this->static_data_url);

			if (!$this->wait_for_rate_limit(10)) {
				$this->debug_log("Rate limit timeout for static data fetch");
				return new WP_Error('rate_limit', __('API rate limit exceeded. Please try again in a moment.', 'encountrix'));
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
				return new WP_Error('api_error', __('Failed to fetch static raid data', 'encountrix'));
			}

			$body = wp_remote_retrieve_body($response);
			$data = json_decode($body, true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->debug_log("JSON parse error for static data: " . json_last_error_msg());
				return new WP_Error('json_error', __('Failed to parse static data', 'encountrix'));
			}

			set_transient($cache_key, $data, $cache_duration * MINUTE_IN_SECONDS);
			$this->debug_log("Cached static data for $cache_duration minutes");

			return $data;
		} catch (\Exception $e) {
			$this->debug_log('Exception in fetch_static_raid_data: ' . $e->getMessage());
			return new WP_Error('unexpected_error', $e->getMessage());
		}
	}

	/**
	 * Get Blizzard OAuth token with caching
	 *
	 * @return string|false OAuth token or false on failure
	 */
	public function get_blizzard_token(?string $region = null): string|false {
		try {
			if (!$region) {
				$region = get_option('encountrix_blizzard_region', 'eu');
			}

			$cache_key = $this->blizzard_cache_prefix . 'oauth_token_' . $region;
			$cached_token = get_transient($cache_key);
			if ($cached_token !== false) {
				$this->debug_log("Using cached Blizzard token for region: $region");
				return $cached_token;
			}

			$client_id = get_option('encountrix_blizzard_client_id');
			$client_secret = get_option('encountrix_blizzard_client_secret');

			if (empty($client_id) || empty($client_secret)) {
				$this->debug_log("Blizzard API credentials not configured");
				return false;
			}

			$oauth_url = $this->regional_endpoints[$region]['oauth'] ?? $this->regional_endpoints['us']['oauth'];

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
		} catch (\Exception $e) {
			$this->debug_log('Exception in get_blizzard_token: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get achievement map for a raid with proper boss name matching
	 *
	 * @return array<string, int> Achievement ID map keyed by boss slug
	 */
	public function get_achievement_map(string $raid_slug, int $expansion_id): array {
		try {
			$cache_key = $this->blizzard_cache_prefix . 'achieve_map_' . $raid_slug;
			$cached_map = get_transient($cache_key);
			if ($cached_map !== false) {
				$this->debug_log("Using cached achievement map for raid: $raid_slug");
				return $cached_map;
			}

			$region = get_option('encountrix_blizzard_region', 'eu');
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
				if (isset($ach['name']) && str_starts_with($ach['name'], 'Mythic:')) {
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
		} catch (\Exception $e) {
			$this->debug_log('Exception in get_achievement_map: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * Get boss icon attachment ID with strict duplicate prevention
	 *
	 * @return int|false Attachment ID or false if not found
	 */
	public function get_boss_icon_id(string $boss_slug, string $raid_slug, ?string $boss_name = null, ?int $expansion_id = null): int|false {
		$boss_slug = sanitize_key($boss_slug);
		$raid_slug = sanitize_key($raid_slug);

		$lock_key = 'encountrix_icon_lock_' . $raid_slug . '_' . $boss_slug;
		$cache_key = 'encountrix_icon_id_' . $raid_slug . '_' . $boss_slug;

		// Check if another process is downloading this icon
		if (get_transient($lock_key) !== false) {
			$this->debug_log("Icon download in progress for $boss_slug, waiting...");

			for ($i = 0; $i < 20; $i++) {
				usleep(500000);
				$cached_id = get_transient($cache_key);
				if ($cached_id !== false && $cached_id > 0) {
					return (int) $cached_id;
				}
			}
			return false;
		}

		// Check cache first
		$cached_id = get_transient($cache_key);
		if ($cached_id !== false) {
			if ($cached_id === 0) {
				return false;
			}
			if (get_post($cached_id)) {
				return (int) $cached_id;
			}
			delete_transient($cache_key);
		}

		// Search for existing attachment
		global $wpdb;
		$attachment_id = $wpdb->get_var($wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id
			 INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
			 WHERE p.post_type = 'attachment'
			 AND p.post_status = 'inherit'
			 AND pm1.meta_key = '_encountrix_boss'
			 AND pm1.meta_value = %s
			 AND pm2.meta_key = '_encountrix_raid'
			 AND pm2.meta_value = %s
			 LIMIT 1",
			$boss_slug,
			$raid_slug
		));

		if ($attachment_id) {
			$attachment_id = (int) $attachment_id;
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
			$attachment_id = (int) $attachment_id;
			update_post_meta($attachment_id, '_encountrix_icon', 'boss');
			update_post_meta($attachment_id, '_encountrix_raid', $raid_slug);
			update_post_meta($attachment_id, '_encountrix_boss', $boss_slug);

			set_transient($cache_key, $attachment_id, 30 * DAY_IN_SECONDS);
			$this->debug_log("Found existing icon by filename for $boss_slug: attachment ID $attachment_id");
			return $attachment_id;
		}

		// Check if we should attempt download
		$failure_key = 'encountrix_icon_fail_' . $raid_slug . '_' . $boss_slug;
		if (get_transient($failure_key) !== false) {
			$this->debug_log("Skipping download for $boss_slug (recent failure)");
			set_transient($cache_key, 0, 300);
			return false;
		}

		// Attempt download if we have the necessary data
		if ($boss_name !== null && $expansion_id !== null) {
			$client_id = get_option('encountrix_blizzard_client_id');
			$client_secret = get_option('encountrix_blizzard_client_secret');

			if (!empty($client_id) && !empty($client_secret)) {
				set_transient($lock_key, 1, 60);

				$this->debug_log("Attempting to download icon for $boss_slug (locked)");
				$result = $this->import_single_boss_icon($boss_slug, $boss_name, $raid_slug, $expansion_id);

				delete_transient($lock_key);

				if (is_int($result)) {
					set_transient($cache_key, $result, 30 * DAY_IN_SECONDS);
					$this->debug_log("Successfully downloaded icon for $boss_slug: attachment ID $result");
					return $result;
				}

				// $result === true means it already existed (handled above), false means failure
				if ($result === false) {
					set_transient($failure_key, time(), 1800);
					set_transient($cache_key, 0, 300);
					$this->debug_log("Failed to download icon for $boss_slug");
				}
			}
		}

		return false;
	}

	/**
	 * Batch-load all boss icon attachment IDs for a given raid.
	 *
	 * Uses a single query to retrieve every boss icon associated with
	 * the raid, avoiding N+1 lookups in render loops.
	 *
	 * @param string $raid_slug Sanitized raid slug.
	 * @return array<string, int> Map of boss_slug => attachment_id.
	 */
	public function get_all_boss_icon_ids(string $raid_slug): array {
		global $wpdb;

		$raid_slug = sanitize_key($raid_slug);

		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT pm_boss.meta_value AS boss_slug, pm_boss.post_id
			 FROM {$wpdb->postmeta} pm_boss
			 INNER JOIN {$wpdb->postmeta} pm_raid
			   ON pm_boss.post_id = pm_raid.post_id
			 INNER JOIN {$wpdb->posts} p
			   ON p.ID = pm_boss.post_id
			 WHERE pm_boss.meta_key = '_encountrix_boss'
			   AND pm_raid.meta_key = '_encountrix_raid'
			   AND pm_raid.meta_value = %s
			   AND p.post_type = 'attachment'
			   AND p.post_status = 'inherit'",
			$raid_slug
		), OBJECT);

		$map = [];
		foreach ($results as $row) {
			$map[$row->boss_slug] = (int) $row->post_id;
		}
		return $map;
	}

	/**
	 * Import a single boss icon from the Blizzard API.
	 *
	 * Returns the new attachment ID on a fresh import, true when an
	 * existing attachment was found (no download needed), or false
	 * on failure.
	 *
	 * @param string   $boss_slug    Boss identifier slug.
	 * @param string   $boss_name    Human-readable boss name.
	 * @param string   $raid_slug    Raid identifier slug.
	 * @param int      $expansion_id Expansion numeric ID.
	 * @return int|true|false New attachment ID, true if already exists, false on failure.
	 */
	public function import_single_boss_icon(string $boss_slug, string $boss_name, string $raid_slug, int $expansion_id): int|bool {
		try {
			$boss_slug = sanitize_key($boss_slug);
			$raid_slug = sanitize_key($raid_slug);

			// Check for existing attachment FIRST
			global $wpdb;
			$existing_id = $wpdb->get_var($wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id
				 INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
				 WHERE p.post_type = 'attachment'
				 AND p.post_status = 'inherit'
				 AND pm1.meta_key = '_encountrix_boss'
				 AND pm1.meta_value = %s
				 AND pm2.meta_key = '_encountrix_raid'
				 AND pm2.meta_value = %s
				 LIMIT 1",
				$boss_slug,
				$raid_slug
			));

			if ($existing_id && get_post($existing_id)) {
				$this->debug_log("Icon already exists for $boss_slug: attachment ID $existing_id");
				$cache_key = 'encountrix_icon_id_' . $raid_slug . '_' . $boss_slug;
				set_transient($cache_key, (int) $existing_id, 30 * DAY_IN_SECONDS);
				return true; // Already exists; skip download.
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
				$existing_id = (int) $existing_id;
				update_post_meta($existing_id, '_encountrix_icon', 'boss');
				update_post_meta($existing_id, '_encountrix_raid', $raid_slug);
				update_post_meta($existing_id, '_encountrix_boss', $boss_slug);

				$this->debug_log("Found existing icon by filename for $boss_slug: attachment ID $existing_id");
				$cache_key = 'encountrix_icon_id_' . $raid_slug . '_' . $boss_slug;
				set_transient($cache_key, $existing_id, 30 * DAY_IN_SECONDS);
				return true; // Already exists; skip download.
			}

			// Get achievement map
			$achievement_map = $this->get_achievement_map($raid_slug, $expansion_id);
			if (empty($achievement_map) || !isset($achievement_map[$boss_slug])) {
				$this->debug_log("No achievement ID found for boss $boss_slug in raid $raid_slug");
				return false;
			}

			$achievement_id = $achievement_map[$boss_slug];
			$this->debug_log("Downloading icon for $boss_slug using achievement ID $achievement_id");

			$region = get_option('encountrix_blizzard_region', 'eu');
			$token = $this->get_blizzard_token($region);
			if (!$token) {
				return false;
			}

			$api_base = $this->regional_endpoints[$region]['api'];
			$media_url = add_query_arg([
				'namespace' => 'static-' . $region,
				'locale' => 'en_US',
			], "{$api_base}/data/wow/media/achievement/{$achievement_id}");

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

			if (!$icon_url || !$this->is_allowed_media_url($icon_url)) {
				return false;
			}

			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$tmp = download_url($icon_url, $this->http_timeout, true);
			if (is_wp_error($tmp)) {
				return false;
			}

			$file_array = [
				'name' => sanitize_file_name("{$raid_slug}_{$boss_slug}.jpg"),
				'tmp_name' => $tmp,
			];

			$attachment_id = media_handle_sideload($file_array, 0, $boss_name . ' Icon');
			$this->cleanup_temp_file($tmp);

			if (is_wp_error($attachment_id)) {
				return false;
			}

			// Add ALL metadata immediately
			update_post_meta($attachment_id, '_encountrix_icon', 'boss');
			update_post_meta($attachment_id, '_encountrix_raid', $raid_slug);
			update_post_meta($attachment_id, '_encountrix_boss', $boss_slug);
			update_post_meta($attachment_id, '_encountrix_expansion', $expansion_id);

			$cache_key = 'encountrix_icon_id_' . $raid_slug . '_' . $boss_slug;
			set_transient($cache_key, $attachment_id, 30 * DAY_IN_SECONDS);

			$this->debug_log("Successfully imported NEW icon for $boss_slug: attachment ID $attachment_id");
			return (int) $attachment_id; // Explicit int for new imports
		} catch (\Exception $e) {
			$this->debug_log('Exception in import_single_boss_icon: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Delete all plugin-imported icon attachments.
	 *
	 * Only removes attachments that carry Encountrix plugin metadata,
	 * then clears all related transient caches.
	 *
	 * @return int Number of attachments deleted.
	 */
	public function delete_all_icons(): int {
		global $wpdb;

		$deleted = 0;

		// Find all attachments with any plugin metadata
		$attachment_ids = $wpdb->get_col(
			"SELECT DISTINCT post_id
			 FROM {$wpdb->postmeta}
			 WHERE meta_key IN (
				 '_encountrix_icon',
				 '_encountrix_raid',
				 '_encountrix_boss',
				 '_encountrix_expansion'
			 )"
		);

		foreach ($attachment_ids as $attachment_id) {
			$attachment_id = (int) $attachment_id;
			$post = get_post($attachment_id);
			if ($post && $post->post_type === 'attachment') {
				if (wp_delete_attachment($attachment_id, true)) {
					$deleted++;
					$this->debug_log("Deleted attachment ID $attachment_id: " . $post->post_name);
				}
			}
		}

		// Clear all icon-related caches
		$this->delete_transients_by_prefix([
			'_transient_encountrix_icon_',
			'_transient_timeout_encountrix_icon_',
			'_transient_encountrix_blizzard_',
			'_transient_timeout_encountrix_blizzard_',
			'_transient_encountrix_request_lock_',
			'_transient_timeout_encountrix_request_lock_',
		]);

		$this->debug_log("Deleted $deleted icon attachments and cleared all caches");
		return $deleted;
	}

	/**
	 * Parse API error message for user-friendly display
	 */
	private function parse_api_error(string $body, int $code): string {
		$data = json_decode($body, true);

		$error_detail = '';
		if (isset($data['error'])) {
			$error_detail = is_array($data['error'])
				? ($data['error']['message'] ?? json_encode($data['error']))
				: $data['error'];
		} elseif (isset($data['message'])) {
			$error_detail = $data['message'];
		}

		return match (true) {
			$code === 401 => sprintf(
				__('API authentication failed. Please check your API key. Details: %s', 'encountrix'),
				$error_detail ?: __('Invalid or expired API key', 'encountrix')
			),
			$code === 403 => sprintf(
				__('Access forbidden. Please verify API key permissions. Details: %s', 'encountrix'),
				$error_detail ?: __('Insufficient permissions', 'encountrix')
			),
			$code === 404 => sprintf(
				__('Resource not found. Please check raid name and parameters. Details: %s', 'encountrix'),
				$error_detail ?: __('The requested raid or guild was not found', 'encountrix')
			),
			$code === 429 => __('API rate limit exceeded. Please try again in a few minutes.', 'encountrix'),
			$code >= 500 && $code <= 503 => sprintf(
				__('API server error. The service may be temporarily unavailable. Details: %s', 'encountrix'),
				$error_detail ?: __('Please try again later', 'encountrix')
			),
			default => sprintf(
				__('API Error (HTTP %d): %s', 'encountrix'),
				$code,
				$error_detail ?: __('Unknown error occurred', 'encountrix')
			),
		};
	}

	/**
	 * Normalize a realm string to the slug format expected by Raider.io
	 */
	public function sanitize_realm(string $realm): string {
		if (empty($realm)) {
			return '';
		}

		if (strlen($realm) > 50) {
			return '';
		}

		$realm = sanitize_text_field(stripslashes($realm));
		$realm = remove_accents($realm);
		$realm = strtolower($realm);
		$realm = preg_replace('/[^a-z0-9\- ]+/', '', $realm);
		$realm = preg_replace('/\s+/', '-', trim($realm));

		return trim($realm, '-');
	}

	/**
	 * Normalize guild IDs to a comma-separated list of numeric IDs
	 */
	public function sanitize_guilds(string|array $guilds): string {
		if (empty($guilds)) {
			return '';
		}

		if (!is_array($guilds)) {
			$guilds = preg_split('/[,\s]+/', (string) $guilds, -1, PREG_SPLIT_NO_EMPTY);
		}

		$guilds = array_map('sanitize_text_field', $guilds);
		$guilds = array_map('trim', $guilds);

		$guilds = array_filter($guilds, static function (string $g): bool {
			return (bool) preg_match('/^\d+$/', $g);
		});

		if (empty($guilds)) {
			return '';
		}

		$guilds = array_values(array_unique($guilds));
		$guilds = array_slice($guilds, 0, 10);

		return implode(',', $guilds);
	}

	/**
	 * Get available realms from Blizzard API
	 *
	 * @return array<array{slug: string, name: string, id: int}>
	 */
	public function get_realms_list(string $region = 'eu'): array {
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
				if (isset($realm['slug'], $realm['name'])) {
					$realms[] = [
						'slug' => $realm['slug'],
						'name' => $realm['name'],
						'id' => $realm['id'] ?? 0
					];
				}
			}

			usort($realms, static function (array $a, array $b): int {
				return strcasecmp($a['name'], $b['name']);
			});

			set_transient($cache_key, $realms, DAY_IN_SECONDS);
			$this->debug_log("Cached " . count($realms) . " realms for region $region");

			return $realms;
		} catch (\Exception $e) {
			$this->debug_log('Exception in get_realms_list: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * Get raid icon attachment ID with auto-download
	 */
	public function get_raid_icon_id(string $raid_slug, int $expansion_id): int|false {
		$raid_slug = sanitize_key($raid_slug);
		$cache_key = $this->blizzard_cache_prefix . 'raid_icon_' . $raid_slug;

		$attachment_id = get_transient($cache_key);
		if ($attachment_id && get_post($attachment_id)) {
			return (int) $attachment_id;
		}

		$q = new WP_Query([
			'post_type' => 'attachment',
			'post_status' => 'any',
			'posts_per_page' => 1,
			'meta_query' => [
				['key' => '_encountrix_icon', 'value' => 'raid'],
				['key' => '_encountrix_raid', 'value' => $raid_slug],
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

		$client_id = get_option('encountrix_blizzard_client_id');
		$client_secret = get_option('encountrix_blizzard_client_secret');

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
	 */
	public function get_raid_achievement_id(string $raid_slug, int $expansion_id): int|false {
		try {
			$cache_key = $this->blizzard_cache_prefix . 'raid_achieve_' . $raid_slug;
			$cached_id = get_transient($cache_key);
			if ($cached_id !== false) {
				return (int) $cached_id;
			}

			$region = get_option('encountrix_blizzard_region', 'eu');
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

			foreach ($data['achievements'] as $ach) {
				if (isset($ach['name']) && (
					stripos($ach['name'], $raid_name) !== false ||
					(stripos($ach['name'], 'Glory of') !== false && stripos($ach['name'], 'Raider') !== false)
				)) {
					set_transient($cache_key, $ach['id'], 30 * DAY_IN_SECONDS);
					return (int) $ach['id'];
				}
			}

			return false;
		} catch (\Exception $e) {
			$this->debug_log('Exception in get_raid_achievement_id: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Download raid icon from Blizzard API
	 */
	private function download_raid_icon(string $raid_slug, int $achievement_id, int $expansion_id): int|false {
		try {
			$region = get_option('encountrix_blizzard_region', 'eu');
			$token = $this->get_blizzard_token($region);
			if (!$token) {
				return false;
			}

			$api_base = $this->regional_endpoints[$region]['api'];
			$media_url = add_query_arg([
				'namespace' => 'static-' . $region,
				'locale' => 'en_US'
			], "{$api_base}/data/wow/media/achievement/{$achievement_id}");

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

			if (!$icon_url || !$this->is_allowed_media_url($icon_url)) {
				return false;
			}

			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$tmp = download_url($icon_url, $this->http_timeout, true);
			if (is_wp_error($tmp)) {
				return false;
			}

			$file_array = [
				'name' => sanitize_file_name("raid_{$raid_slug}.jpg"),
				'tmp_name' => $tmp
			];

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
			$this->cleanup_temp_file($tmp);

			if (is_wp_error($attachment_id)) {
				return false;
			}

			update_post_meta($attachment_id, '_encountrix_icon', 'raid');
			update_post_meta($attachment_id, '_encountrix_raid', $raid_slug);

			return (int) $attachment_id;
		} catch (\Exception $e) {
			$this->debug_log('Exception in download_raid_icon: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get journal instance ID for a raid
	 */
	public function get_journal_instance_id(string $raid_slug, int $expansion_id): int|false {
		try {
			$cache_key = $this->blizzard_cache_prefix . 'journal_id_' . $raid_slug;
			$cached_id = get_transient($cache_key);
			if ($cached_id !== false) {
				return (int) $cached_id;
			}

			$region = get_option('encountrix_blizzard_region', 'eu');
			$token = $this->get_blizzard_token($region);
			if (!$token) {
				return false;
			}

			$api_base = $this->regional_endpoints[$region]['api'];
			$instances_url = add_query_arg([
				'namespace' => 'static-' . $region,
				'locale' => 'en_US'
			], "{$api_base}/data/wow/journal-instance/index");

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

			// Tier map: handle multi-raid tiers
			if (isset($this->tier_journal_map[$raid_slug])) {
				$search_names = $this->tier_journal_map[$raid_slug];
				$this->debug_log("Tier map active for $raid_slug, searching for: " . implode(', ', $search_names));

				foreach ($search_names as $search_name) {
					foreach ($data['instances'] as $instance) {
						if (!isset($instance['name'], $instance['id'])) {
							continue;
						}
						if (
							strcasecmp($instance['name'], $search_name) === 0 ||
							stripos($instance['name'], $search_name) !== false
						) {
							$this->debug_log("Tier map match: {$instance['name']} (ID: {$instance['id']})");
							set_transient($cache_key, $instance['id'], 30 * DAY_IN_SECONDS);
							return (int) $instance['id'];
						}
					}
				}

				$this->debug_log("No journal instance found via tier map for: $raid_slug");
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

			foreach ($data['instances'] as $instance) {
				if (
					isset($instance['name']) &&
					(strcasecmp($instance['name'], $raid_name) === 0 ||
						stripos($instance['name'], $raid_name) !== false)
				) {
					set_transient($cache_key, $instance['id'], 30 * DAY_IN_SECONDS);
					return (int) $instance['id'];
				}
			}

			return false;
		} catch (\Exception $e) {
			$this->debug_log('Exception in get_journal_instance_id: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get raid background image attachment ID with auto-download
	 */
	public function get_journal_instance_image_id(string $raid_slug, int $expansion_id): int|false {
		try {
			$raid_slug = sanitize_key($raid_slug);
			$cache_key = $this->blizzard_cache_prefix . 'journal_img_' . $raid_slug;

			$attachment_id = get_transient($cache_key);
			if ($attachment_id && get_post($attachment_id)) {
				return (int) $attachment_id;
			}

			$q = new WP_Query([
				'post_type' => 'attachment',
				'post_status' => 'any',
				'posts_per_page' => 1,
				'meta_query' => [
					['key' => '_encountrix_icon', 'value' => 'raid_bg'],
					['key' => '_encountrix_raid', 'value' => $raid_slug],
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
		} catch (\Exception $e) {
			$this->debug_log('Exception in get_journal_instance_image_id: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Download raid background image from Blizzard API
	 */
	private function download_journal_instance_image(string $raid_slug, int $journal_id, int $expansion_id): int|false {
		$region = get_option('encountrix_blizzard_region', 'eu');
		$token = $this->get_blizzard_token($region);
		if (!$token) {
			return false;
		}

		$api_base = $this->regional_endpoints[$region]['api'];
		$media_url = add_query_arg([
			'namespace' => 'static-' . $region,
			'locale' => 'en_US',
		], "{$api_base}/data/wow/media/journal-instance/{$journal_id}");

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

		if (!$image_url || !$this->is_allowed_media_url($image_url)) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url($image_url, $this->http_timeout, true);
		if (is_wp_error($tmp)) {
			return false;
		}

		$file_array = [
			'name' => sanitize_file_name("raid_bg_{$raid_slug}.jpg"),
			'tmp_name' => $tmp,
		];

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
		$this->cleanup_temp_file($tmp);

		if (is_wp_error($attachment_id)) {
			return false;
		}

		update_post_meta($attachment_id, '_encountrix_icon', 'raid_bg');
		update_post_meta($attachment_id, '_encountrix_raid', $raid_slug);

		return (int) $attachment_id;
	}

	/**
	 * Get expansions list
	 *
	 * @return array<int, array{name: string, category_name: string}>
	 */
	public function get_expansions(): array {
		return $this->expansions;
	}

	/**
	 * Get regional endpoints
	 *
	 * @return array<string, array{oauth: string, api: string}>
	 */
	public function get_regional_endpoints(): array {
		return $this->regional_endpoints;
	}

	/**
	 * Log debug message if debug mode is enabled
	 */
	public function debug_log(string $message): void {
		$enabled = get_option('encountrix_debug_mode', 'false');

		if ($enabled === 'true' || (defined('ENCOUNTRIX_DEBUG') && ENCOUNTRIX_DEBUG)) {
			$log = get_transient('encountrix_debug_log') ?: [];
			$log[] = date('H:i:s') . ' - ' . $message;
			$log = array_slice($log, -100);
			set_transient('encountrix_debug_log', $log, HOUR_IN_SECONDS);

			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('Encountrix: ' . $message);
			}
		}
	}
}
