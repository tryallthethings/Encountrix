<?php

/**
 * Encountrix widget and shortcode rendering.
 *
 * Handles the [encountrix] shortcode output, including raid progress display,
 * boss icon rendering (with batch-loaded icons to avoid N+1 queries), and
 * multi-guild / multi-difficulty layouts.
 *
 * @package Encountrix
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EncountrixWidget {

	/**
	 * API instance used for data fetching.
	 *
	 * @var EncountrixApi
	 */
	private EncountrixApi $api;

	/**
	 * Constructor.
	 *
	 * @param EncountrixApi $api API handler instance.
	 */
	public function __construct( EncountrixApi $api ) {
		$this->api = $api;
	}

	/**
	 * Render the [encountrix] shortcode.
	 *
	 * Enqueues front-end assets, resolves shortcode attributes against saved
	 * defaults, and returns the complete HTML output for raid progress.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_shortcode( array $atts ): string {

		if ( defined( 'ENCOUNTRIX_PLUGIN_URL' ) ) {
			wp_enqueue_style(
				'encountrix',
				ENCOUNTRIX_PLUGIN_URL . 'assets/css/encountrix.css',
				array(),
				defined( 'ENCOUNTRIX_VERSION' ) ? ENCOUNTRIX_VERSION : null
			);
			wp_enqueue_script(
				'encountrix',
				ENCOUNTRIX_PLUGIN_URL . 'assets/js/encountrix.js',
				array( 'jquery' ),
				defined( 'ENCOUNTRIX_VERSION' ) ? ENCOUNTRIX_VERSION : null,
				true
			);
		}

		// Get default values from settings
		$defaults = array(
			'raid'               => get_option( 'encountrix_raid', '' ),
			'difficulty'         => get_option( 'encountrix_difficulty', 'highest' ),
			'region'             => get_option( 'encountrix_region', 'eu' ),
			'realm'              => get_option( 'encountrix_realm', '' ),
			'guilds'             => get_option( 'encountrix_guild_ids', '' ),
			'cache'              => get_option( 'encountrix_cache_time', 60 ),
			'show_icons'         => get_option( 'encountrix_show_icons', 'true' ),
			'show_killed'        => get_option( 'encountrix_show_killed', 'false' ),
			'use_blizzard_icons' => get_option( 'encountrix_use_blizzard_icons', 'true' ),
			'show_raid_name'     => get_option( 'encountrix_show_raid_name', 'false' ),
			'show_raid_icon'     => get_option( 'encountrix_show_raid_icon', 'false' ),
			'limit'              => get_option( 'encountrix_limit', 50 ),
			'page'               => 0,
		);

		$atts = shortcode_atts( $defaults, $atts, 'encountrix' );

		if ( empty( $atts['raid'] ) ) {
			return $this->render_error( __( 'No raid specified. Please configure a default raid in the plugin settings or specify one in the shortcode.', 'encountrix' ) );
		}

		if ( empty( $atts['region'] ) ) {
			return $this->render_error( __( 'No region specified. Please configure a default region in the plugin settings or specify one in the shortcode.', 'encountrix' ) );
		}

		// Sanitize inputs
		$raid_slug          = sanitize_text_field( $atts['raid'] );
		$difficulty         = $this->validate_difficulty( $atts['difficulty'] );
		$region             = sanitize_text_field( $atts['region'] );
		$realm              = $this->api->sanitize_realm( $atts['realm'] );
		$guilds             = $this->api->sanitize_guilds( $atts['guilds'] );
		$cache_minutes      = absint( $atts['cache'] );
		$show_icons         = filter_var( $atts['show_icons'], FILTER_VALIDATE_BOOLEAN );
		$show_killed        = filter_var( $atts['show_killed'], FILTER_VALIDATE_BOOLEAN );
		$use_blizzard_icons = filter_var( $atts['use_blizzard_icons'], FILTER_VALIDATE_BOOLEAN );
		$show_raid_name     = filter_var( $atts['show_raid_name'], FILTER_VALIDATE_BOOLEAN );
		$show_raid_icon     = filter_var( $atts['show_raid_icon'], FILTER_VALIDATE_BOOLEAN );
		$limit              = max( 1, min( 100, absint( $atts['limit'] ) ) );
		$page               = max( 0, absint( $atts['page'] ) );

		$expansion_id = get_option( 'encountrix_expansion', 10 );

		$static_data = $this->api->fetch_static_raid_data_by_expansion( $expansion_id, $cache_minutes );
		if ( is_wp_error( $static_data ) ) {
			return $this->render_error(
				sprintf(
					__( 'Error fetching raid information: %s', 'encountrix' ),
					$static_data->get_error_message()
				)
			);
		}

		$raid_info = $this->find_raid_info( $static_data, $raid_slug );
		if ( ! $raid_info ) {
			return $this->render_error(
				sprintf(
					__( 'Raid "%s" not found. Please check the raid name.', 'encountrix' ),
					esc_html( $raid_slug )
				)
			);
		}

		// Batch-load all boss icons for this raid in a single query.
		$icon_map = array();
		if ( $show_icons && $use_blizzard_icons ) {
			$icon_map = $this->api->get_all_boss_icon_ids( $raid_slug );
		}

		// Handle multiple guilds
		$guild_ids = ! empty( $guilds ) ? explode( ',', $guilds ) : array();
		$guild_ids = array_map( 'trim', $guild_ids );
		$guild_ids = array_filter( $guild_ids );

		if ( empty( $guild_ids ) ) {
			return $this->render_rankings(
				$raid_info,
				$raid_slug,
				$difficulty,
				$region,
				$realm,
				'',
				$cache_minutes,
				$limit,
				$page,
				$show_icons,
				$show_killed,
				$use_blizzard_icons,
				$show_raid_name,
				$show_raid_icon,
				$expansion_id,
				$icon_map
			);
		}

		$output = '<div class="encountrix-container">';

		if ( $show_raid_name || $show_raid_icon ) {
			$output .= $this->render_raid_header( $raid_info, $show_raid_name, $show_raid_icon, $use_blizzard_icons, $expansion_id );
		}

		foreach ( $guild_ids as $guild_id ) {
			$output .= $this->render_guild_progress(
				$raid_info,
				$raid_slug,
				$difficulty,
				$region,
				$realm,
				$guild_id,
				$cache_minutes,
				$show_icons,
				$show_killed,
				$use_blizzard_icons,
				$expansion_id,
				$icon_map
			);
		}

		if ( $use_blizzard_icons ) {
			$output .= '<div class="encountrix-attribution">';
			$output .= '<small>' . __( 'Boss artwork © Blizzard Entertainment', 'encountrix' ) . '</small>';
			$output .= '</div>';
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Render the raid header with optional hero image.
	 *
	 * @param array $raid_info          Raid static data (name, slug, encounters).
	 * @param bool  $show_raid_name     Whether to display the raid name.
	 * @param bool  $show_raid_icon     Whether to display the raid background image.
	 * @param bool  $use_blizzard_icons Whether to fetch Blizzard media assets.
	 * @param int   $expansion_id       Current expansion ID.
	 * @return string Rendered HTML.
	 */
	private function render_raid_header(
		array $raid_info,
		bool $show_raid_name,
		bool $show_raid_icon,
		bool $use_blizzard_icons,
		int $expansion_id
	): string {
		if ( ! $show_raid_name && ! $show_raid_icon ) {
			return '';
		}

		$raid_name = ! empty( $raid_info['name'] ) ? $raid_info['name'] : '';
		$raid_slug = ! empty( $raid_info['slug'] ) ? $raid_info['slug'] : '';

		$bg_image_url = '';
		if ( $show_raid_icon && $use_blizzard_icons ) {
			$bg_image_id = $this->api->get_journal_instance_image_id( $raid_slug, $expansion_id );
			if ( $bg_image_id ) {
				$bg_image_url = wp_get_attachment_image_url( $bg_image_id, 'full' );
			}
		}

		$output = '<div class="encountrix-header-container">';

		if ( $bg_image_url ) {
			$output .= '<div class="encountrix-header-hero" style="background-image: url(' . esc_url( $bg_image_url ) . ');">';
			if ( $show_raid_name ) {
				$output .= '<div class="encountrix-header-overlay">';
				$output .= '<h2 class="encountrix-header-title" data-text="' . esc_attr( $raid_name ) . '">';
				$output .= '<span class="encountrix-header-title-text">' . esc_html( $raid_name ) . '</span>';
				$output .= '</h2>';
				$output .= '</div>';
			}
			$output .= '</div>';
		} elseif ( $show_raid_name ) {
			$output .= '<div class="encountrix-header-simple">';
			$output .= '<h2 class="encountrix-header-title">' . esc_html( $raid_name ) . '</h2>';
			$output .= '</div>';
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Render general rankings when no specific guild is selected.
	 *
	 * @param array              $raid_info          Raid static data.
	 * @param string             $raid_slug          Raid slug identifier.
	 * @param string             $difficulty         Requested difficulty.
	 * @param string             $region             Region code (e.g. "eu", "us").
	 * @param string             $realm              Realm slug.
	 * @param string             $guilds             Comma-separated guild IDs (empty for open rankings).
	 * @param int                $cache_minutes      Cache TTL in minutes.
	 * @param int                $limit              Maximum results per page.
	 * @param int                $page               Zero-based page offset.
	 * @param bool               $show_icons         Whether to show boss icons.
	 * @param bool               $show_killed        Whether to include defeated bosses.
	 * @param bool               $use_blizzard_icons Whether to use Blizzard media.
	 * @param bool               $show_raid_name     Whether to display the raid name.
	 * @param bool               $show_raid_icon     Whether to display the raid image.
	 * @param int                $expansion_id       Current expansion ID.
	 * @param array<string, int> $icon_map           Pre-loaded boss slug to attachment ID map.
	 * @return string Rendered HTML.
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
		$output = '<div class="encountrix">';

		if ( $show_raid_name || $show_raid_icon ) {
			$output .= $this->render_raid_header( $raid_info, $show_raid_name, $show_raid_icon, $use_blizzard_icons, $expansion_id );
		}

		if ( $difficulty === 'highest' ) {
			$all = array(
				'normal' => $this->api->fetch_raid_data( $raid_slug, 'normal', $region, $realm, $guilds, $cache_minutes, $limit, $page ),
				'heroic' => $this->api->fetch_raid_data( $raid_slug, 'heroic', $region, $realm, $guilds, $cache_minutes, $limit, $page ),
				'mythic' => $this->api->fetch_raid_data( $raid_slug, 'mythic', $region, $realm, $guilds, $cache_minutes, $limit, $page ),
			);

			$target = $this->find_highest_progress_difficulty( $all, $raid_info );
			$data   = $all[ $target ] ?? array( 'raidRankings' => array() );

			if ( is_wp_error( $data ) ) {
				if ( ! $this->is_rate_limit_error( $data ) ) {
					$output .= $this->render_error_inline(
						sprintf( __( 'Error loading %1$s data: %2$s', 'encountrix' ), ucfirst( $target ), $data->get_error_message() )
					);
				}
			} else {
				$output .= $this->render_difficulty_section(
					is_array( $data ) ? $data : array( 'raidRankings' => array() ),
					$target,
					$raid_info,
					$all,
					$show_icons,
					$show_killed,
					$use_blizzard_icons,
					$expansion_id,
					$icon_map
				);
			}
		} else {
			$difficulties_to_render = is_array( $difficulty ) ? $difficulty : array( $difficulty );

			foreach ( $difficulties_to_render as $diff ) {
				[$data, $used_diff, $all_attempts] = $this->fetch_with_fallback(
					$raid_slug,
					$diff,
					$region,
					$realm,
					$guilds,
					$cache_minutes,
					$limit,
					$page
				);

				if ( is_wp_error( $data ) ) {
					if ( ! $this->is_rate_limit_error( $data ) ) {
						$output .= $this->render_error_inline(
							sprintf( __( 'Error loading %1$s data: %2$s', 'encountrix' ), ucfirst( $diff ), $data->get_error_message() )
						);
					}
					continue;
				}

				$current_tier_data = ( isset( $all_attempts[ $diff ] ) && is_array( $all_attempts[ $diff ] ) )
					? $all_attempts[ $diff ]
					: array( 'raidRankings' => array() );

				$output .= $this->render_difficulty_section(
					$current_tier_data,
					$diff,
					$raid_info,
					$all_attempts,
					$show_icons,
					$show_killed,
					$use_blizzard_icons,
					$expansion_id,
					$icon_map
				);
			}
		}

		if ( $use_blizzard_icons ) {
			$output .= '<div class="encountrix-attribution"><small>' . __( 'Data and media © Blizzard Entertainment', 'encountrix' ) . '</small></div>';
		}

		$output .= '</div>';
		return $output;
	}

	/**
	 * Render raid progress for a specific guild.
	 *
	 * Fetches data for all difficulties and renders the appropriate section(s)
	 * based on the requested difficulty setting.
	 *
	 * @param array              $raid_info          Raid static data.
	 * @param string             $raid_slug          Raid slug identifier.
	 * @param string             $difficulty         Requested difficulty.
	 * @param string             $region             Region code.
	 * @param string             $realm              Realm slug.
	 * @param string             $guild_id           Numeric guild ID.
	 * @param int                $cache_minutes      Cache TTL in minutes.
	 * @param bool               $show_icons         Whether to show boss icons.
	 * @param bool               $show_killed        Whether to include defeated bosses.
	 * @param bool               $use_blizzard_icons Whether to use Blizzard media.
	 * @param int                $expansion_id       Current expansion ID.
	 * @param array<string, int> $icon_map           Pre-loaded boss slug to attachment ID map.
	 * @return string Rendered HTML.
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

		if ( ! preg_match( '/^\d+$/', $guild_id ) ) {
			return $this->render_error_inline(
				sprintf( __( 'Invalid guild ID: %s', 'encountrix' ), esc_html( $guild_id ) )
			);
		}

		$all_difficulties_data = array();
		foreach ( array( 'normal', 'heroic', 'mythic' ) as $diff_key ) {
			$all_difficulties_data[ $diff_key ] = $this->api->fetch_raid_data(
				$raid_slug,
				$diff_key,
				$region,
				$realm,
				$guild_id,
				$cache_minutes,
				50,
				0
			);
		}

		$difficulties_to_render = array();

		if ( $difficulty === 'all' ) {
			$difficulties_to_render = array( 'normal', 'heroic', 'mythic' );
		} elseif ( $difficulty === 'highest' ) {
			$target_diff              = $this->find_highest_progress_difficulty( $all_difficulties_data, $raid_info );
			$difficulties_to_render[] = $target_diff;
		} else {
			$difficulties_to_render[] = $difficulty;
		}

		$output = '';

		foreach ( $difficulties_to_render as $diff ) {
			$data = $all_difficulties_data[ $diff ] ?? null;

			if ( is_wp_error( $data ) ) {
				$this->api->debug_log( 'Raid data error for guild ' . $guild_id . ' (' . $diff . '): ' . $data->get_error_message() );
				$output .= $this->render_error_inline(
					sprintf(
						/* translators: %1$s: difficulty name, %2$s: error message */
						__( 'Error loading %1$s data: %2$s', 'encountrix' ),
						ucfirst( $diff ),
						$data->get_error_message()
					)
				);
				continue;
			}

			if ( empty( $data ) || empty( $data['raidRankings'] ) ) {
				$output .= $this->render_difficulty_section(
					( is_array( $data ) ? $data : array( 'raidRankings' => array() ) ),
					$diff,
					$raid_info,
					$all_difficulties_data,
					$show_icons,
					$show_killed,
					$use_blizzard_icons,
					$expansion_id,
					$icon_map,
					$guild_id
				);
				continue;
			}

			$output .= $this->render_difficulty_section(
				$data,
				$diff,
				$raid_info,
				$all_difficulties_data,
				$show_icons,
				$show_killed,
				$use_blizzard_icons,
				$expansion_id,
				$icon_map,
				$guild_id
			);
		}

		if ( $output === '' ) {
			$output = $this->render_error_inline( __( 'No raid data available for this guild.', 'encountrix' ) );
		}

		return $output;
	}

	/**
	 * Render a single difficulty section with boss list and progress bar.
	 *
	 * Uses a pre-loaded icon map for batch icon resolution, falling back to
	 * individual lookups only when a boss is missing from the batch result.
	 *
	 * @param array              $data                 Raid ranking data for this difficulty.
	 * @param string             $difficulty            Difficulty key (normal, heroic, mythic).
	 * @param array              $raid_info             Raid static data.
	 * @param array              $all_difficulties_data All fetched difficulty data for fallback.
	 * @param bool               $show_icons            Whether to show boss icons.
	 * @param bool               $show_killed           Whether to include defeated bosses.
	 * @param bool               $use_blizzard_icons    Whether to use Blizzard media.
	 * @param int                $expansion_id          Current expansion ID.
	 * @param array<string, int> $icon_map              Pre-loaded boss slug to attachment ID map.
	 * @param string             $guild_id              Optional guild ID for guild-specific view.
	 * @return string Rendered HTML.
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
		array $icon_map = array(),
		string $guild_id = ''
	): string {

		$all_bosses    = $raid_info['encounters'] ?? array();
		$ranking_entry = $data['raidRankings'][0] ?? null;

		$encounters_defeated = $ranking_entry['encountersDefeated'] ?? array();
		$pulled_encounters   = array_column( $ranking_entry['encountersPulled'] ?? array(), null, 'slug' );

		$guild_info = $ranking_entry['guild'] ?? null;
		$rank       = $ranking_entry['rank'] ?? null;
		$world_rank = $data['worldRanking'] ?? null;

		if ( empty( $guild_info ) || ! is_numeric( $rank ) ) {
			$source_data = $this->find_fallback_data( $all_difficulties_data, $difficulty );
			if ( $source_data && ! is_wp_error( $source_data ) && ! empty( $source_data['raidRankings'][0] ) ) {
				$src = $source_data['raidRankings'][0];
				if ( empty( $guild_info ) ) {
					$guild_info = $src['guild'] ?? null;
				}
				if ( ! is_numeric( $rank ) ) {
					$rank = $src['rank'] ?? null;
				}
				if ( empty( $world_rank ) ) {
					$world_rank = $source_data['worldRanking'] ?? null;
				}
			}
		}

		if ( empty( $guild_info ) ) {
			// Attempt to find a guild name from any difficulty for the error message.
			$guild_display_name = '';
			foreach ( $all_difficulties_data as $diff_data ) {
				if ( ! is_wp_error( $diff_data ) && ! empty( $diff_data['raidRankings'][0]['guild']['name'] ) ) {
					$guild_display_name = $diff_data['raidRankings'][0]['guild']['name'];
					break;
				}
			}

			if ( $guild_display_name !== '' ) {
				return $this->render_error_inline(
					sprintf(
						/* translators: %s: guild name */
						__( 'No data found for guild %s in this raid.', 'encountrix' ),
						esc_html( $guild_display_name )
					)
				);
			}

			return $this->render_error_inline(
				__( 'No data found for your guild in this raid.', 'encountrix' )
			);
		}

		ob_start();
		?>
		<div class="encountrix-section">
			<div class="encountrix-header">
				<h3 class="encountrix-title">
					<?php echo esc_html( $guild_info['name'] ); ?>
					<span class="encountrix-difficulty-badge <?php echo esc_attr( $difficulty ); ?>">
						<?php echo esc_html( ucfirst( $difficulty ) ); ?>
					</span>
				</h3>
				<div class="encountrix-meta">
					<span class="encountrix-realm"><?php echo esc_html( $guild_info['realm']['name'] ); ?></span>
					<span class="encountrix-region"><?php echo esc_html( strtoupper( $guild_info['region']['short_name'] ) ); ?></span>
				</div>
				<div class="encountrix-ranks">
					<span class="encountrix-rank realm"><?php esc_html_e( 'Realm:', 'encountrix' ); ?> <strong>#<?php echo esc_html( $rank ); ?></strong></span>
					<?php if ( ! empty( $world_rank ) ) : ?>
						<span class="encountrix-rank world"><?php esc_html_e( 'World:', 'encountrix' ); ?> <strong>#<?php echo esc_html( $world_rank ); ?></strong></span>
					<?php endif; ?>
				</div>
			</div>
			<div class="encountrix">
				<?php
				$progress_percent = count( $all_bosses ) > 0 ?
					( count( $encounters_defeated ) / count( $all_bosses ) ) * 100 : 0;
				?>
				<div class="encountrix-progress-bar">
					<div class="encountrix-progress-fill <?php echo esc_attr( $difficulty ); ?>"
						style="width: <?php echo esc_attr( $progress_percent ); ?>%"></div>
					<div class="encountrix-progress-text">
						<?php echo esc_html( count( $encounters_defeated ) . '/' . count( $all_bosses ) ); ?>
						<?php esc_html_e( 'Bosses', 'encountrix' ); ?>
					</div>
				</div>
				<div class="encountrix-boss-list">
					<?php
					$defeated_slugs = array_column( $encounters_defeated, 'slug' );
					foreach ( $all_bosses as $boss ) :
						$is_defeated = in_array( $boss['slug'], $defeated_slugs, true );

						if ( ! $show_killed && $is_defeated ) {
							continue;
						}

						$status_class = $is_defeated ? 'defeated' : ( isset( $pulled_encounters[ $boss['slug'] ] ) ? 'in-progress' : 'not-started' );
						?>
						<div class="encountrix-boss-item <?php echo esc_attr( $status_class ); ?>">
							<?php if ( $show_icons ) : ?>
								<div class="encountrix-boss-icon encountrix-boss-portrait">
									<?php
									if ( $use_blizzard_icons ) {
										$attachment_id = $icon_map[ $boss['slug'] ] ?? null;

										// Fall back to individual lookup if not in the batch map.
										if ( ! $attachment_id ) {
											$attachment_id = $this->api->get_boss_icon_id(
												$boss['slug'],
												$raid_info['slug'],
												$boss['name'],
												$expansion_id
											);
										}

										if ( $attachment_id ) {
											echo wp_get_attachment_image(
												$attachment_id,
												array( 40, 40 ),
												false,
												array( 'alt' => esc_attr( $boss['name'] ) )
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
							<div class="encountrix-boss-info">
								<div class="encountrix-boss-name"><?php echo esc_html( $boss['name'] ); ?></div>
								<div class="encountrix-boss-stats">
									<?php if ( $is_defeated ) : ?>
										<span class="encountrix-defeated-label"><?php esc_html_e( 'Defeated', 'encountrix' ); ?></span>
									<?php elseif ( isset( $pulled_encounters[ $boss['slug'] ] ) ) : ?>
										<span class="encountrix-pulls">
											<?php esc_html_e( 'Pulls:', 'encountrix' ); ?>
											<?php echo esc_html( $pulled_encounters[ $boss['slug'] ]['numPulls'] ); ?>
										</span>
										<span class="encountrix-percent">
											<?php esc_html_e( 'Best:', 'encountrix' ); ?>
											<?php echo esc_html( number_format( $pulled_encounters[ $boss['slug'] ]['bestPercent'], 1 ) ); ?>%
										</span>
									<?php else : ?>
										<span class="encountrix-not-started"><?php esc_html_e( 'Not Started', 'encountrix' ); ?></span>
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
	 * Determine which difficulty has the most meaningful progress.
	 *
	 * Promotes to the next tier when all bosses on the current tier are cleared,
	 * and prefers a tier that still has active (partial) progress.
	 *
	 * @param array $all_difficulties_data Fetched data keyed by difficulty.
	 * @param array $raid_info             Raid static data including encounters.
	 * @return string Difficulty key (normal, heroic, or mythic).
	 */
	private function find_highest_progress_difficulty( array $all_difficulties_data, array $raid_info ): string {
		$total_bosses = count( $raid_info['encounters'] );
		$target_diff  = 'normal';

		$normal_kills = 0;
		if (
			! is_wp_error( $all_difficulties_data['normal'] ) &&
			! empty( $all_difficulties_data['normal']['raidRankings'][0] )
		) {
			$normal_kills = count( $all_difficulties_data['normal']['raidRankings'][0]['encountersDefeated'] ?? array() );
		}

		$heroic_kills = 0;
		if (
			! is_wp_error( $all_difficulties_data['heroic'] ) &&
			! empty( $all_difficulties_data['heroic']['raidRankings'][0] )
		) {
			$heroic_kills = count( $all_difficulties_data['heroic']['raidRankings'][0]['encountersDefeated'] ?? array() );
		}

		$mythic_kills = 0;
		if (
			! is_wp_error( $all_difficulties_data['mythic'] ) &&
			! empty( $all_difficulties_data['mythic']['raidRankings'][0] )
		) {
			$mythic_kills = count( $all_difficulties_data['mythic']['raidRankings'][0]['encountersDefeated'] ?? array() );
		}

		if ( $normal_kills > 0 ) {
			$target_diff = 'heroic';
		}
		if ( $heroic_kills >= $total_bosses ) {
			$target_diff = 'mythic';
		}

		foreach ( array( 'mythic', 'heroic', 'normal' ) as $diff ) {
			if (
				! is_wp_error( $all_difficulties_data[ $diff ] ) &&
				! empty( $all_difficulties_data[ $diff ]['raidRankings'][0] )
			) {
				$kills = count( $all_difficulties_data[ $diff ]['raidRankings'][0]['encountersDefeated'] ?? array() );
				if ( $kills > 0 && $kills < $total_bosses ) {
					$target_diff = $diff;
					break;
				}
			}
		}

		return $target_diff;
	}

	/**
	 * Find fallback ranking data from a lower difficulty.
	 *
	 * Walks down from the current difficulty to retrieve guild metadata
	 * (name, realm, rank) when the requested tier has no data.
	 *
	 * @param array  $all_difficulties_data All fetched difficulty data.
	 * @param string $current_difficulty    The difficulty that lacked data.
	 * @return array|null Ranking data array or null if nothing found.
	 */
	private function find_fallback_data( array $all_difficulties_data, string $current_difficulty ): ?array {
		$fallback_order = array(
			'mythic' => array( 'heroic', 'normal' ),
			'heroic' => array( 'normal' ),
			'normal' => array(),
		);

		$order = $fallback_order[ $current_difficulty ] ?? array();

		foreach ( $order as $diff ) {
			if (
				isset( $all_difficulties_data[ $diff ] ) &&
				! is_wp_error( $all_difficulties_data[ $diff ] ) &&
				! empty( $all_difficulties_data[ $diff ]['raidRankings'][0] )
			) {
				return $all_difficulties_data[ $diff ];
			}
		}
		return null;
	}

	/**
	 * Validate and sanitize a difficulty value.
	 *
	 * @param string $difficulty Raw difficulty input.
	 * @return string Sanitized difficulty key; defaults to 'normal' if invalid.
	 */
	private function validate_difficulty( string $difficulty ): string {
		$valid      = array( 'all', 'normal', 'heroic', 'mythic', 'highest' );
		$difficulty = strtolower( sanitize_text_field( $difficulty ) );
		return in_array( $difficulty, $valid, true ) ? $difficulty : 'normal';
	}

	/**
	 * Look up a raid by slug in the static expansion data.
	 *
	 * @param array  $static_data Static raid data from the API.
	 * @param string $raid_slug   The raid slug to search for.
	 * @return array|null Raid data array or null if not found.
	 */
	private function find_raid_info( array $static_data, string $raid_slug ): ?array {
		if ( ! isset( $static_data['raids'] ) ) {
			return null;
		}

		foreach ( $static_data['raids'] as $raid_data ) {
			if ( $raid_data['slug'] === $raid_slug ) {
				return $raid_data;
			}
		}

		return null;
	}

	/**
	 * Render a block-level error message.
	 *
	 * @param string $message Error text to display.
	 * @return string HTML div with the error.
	 */
	private function render_error( string $message ): string {
		return sprintf(
			'<div class="encountrix-error">%s</div>',
			esc_html( $message )
		);
	}

	/**
	 * Render an inline error message within a section.
	 *
	 * @param string $message Error text to display.
	 * @return string HTML div with the inline error.
	 */
	private function render_error_inline( string $message ): string {
		return sprintf(
			'<div class="encountrix-error-inline">%s</div>',
			esc_html( $message )
		);
	}

	/**
	 * Return the fallback order for a given difficulty.
	 *
	 * @param string $difficulty Starting difficulty.
	 * @return array<string> Ordered list of difficulties to try.
	 */
	private function difficulty_fallback_order( string $difficulty ): array {
		return match ( $difficulty ) {
			'mythic' => array( 'mythic', 'heroic', 'normal' ),
			'heroic' => array( 'heroic', 'normal' ),
			default => array( 'normal' ),
		};
	}

	/**
	 * Fetch raid data for the requested difficulty, falling back to lower tiers.
	 *
	 * Iterates through the fallback order and returns the first difficulty
	 * that contains ranking data. If none succeed, the first result is returned.
	 *
	 * @param string $raid_slug     Raid slug identifier.
	 * @param string $difficulty    Requested difficulty.
	 * @param string $region        Region code.
	 * @param string $realm         Realm slug.
	 * @param string $guilds        Comma-separated guild IDs.
	 * @param int    $cache_minutes Cache TTL in minutes.
	 * @param int    $limit         Maximum results per page.
	 * @param int    $page          Zero-based page offset.
	 * @return array{0: array|WP_Error, 1: string, 2: array} Data, used difficulty, all attempts.
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
		$results = array();
		foreach ( $this->difficulty_fallback_order( $difficulty ) as $diff ) {
			$data             = $this->api->fetch_raid_data( $raid_slug, $diff, $region, $realm, $guilds, $cache_minutes, $limit, $page );
			$results[ $diff ] = $data;
			if ( ! is_wp_error( $data ) && ! empty( $data['raidRankings'] ) ) {
				return array( $data, $diff, $results );
			}
		}
		$first      = reset( $results );
		$first_diff = array_key_first( $results );
		return array( $first, $first_diff, $results );
	}

	/**
	 * Check whether a WP_Error represents an API rate-limit response.
	 *
	 * @param mixed $err Value to inspect (typically a WP_Error).
	 * @return bool True if the error indicates a 429 / rate-limit condition.
	 */
	private function is_rate_limit_error( mixed $err ): bool {
		if ( ! is_wp_error( $err ) ) {
			return false;
		}
		$msg = $err->get_error_message();
		return stripos( $msg, '429' ) !== false || stripos( $msg, 'rate limit' ) !== false;
	}
}
