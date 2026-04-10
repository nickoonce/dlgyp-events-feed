<?php
/**
 * Plugin Name: DLGYP Events
 * Description: Minimal events calendar with iCalendar (ICS) subscription feeds and single-event downloads.
 * Version: 1.1.11
 * Author: DLGYP.ORG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Clamp_Events_iCal_Feed {

	const CPT            = 'clamp_event';
	const TIMEZONE_ID    = 'America/Los_Angeles';
	const REST_NAMESPACE = 'clamp-events/v1';
	const REST_ROUTE     = '/feed';
	const VERSION        = '1.1.11';

	/**
	 * Plugin basename for action links.
	 *
	 * @var string
	 */
	private $plugin_basename;

	public function __construct() {
		$this->plugin_basename = plugin_basename( __FILE__ );

		// Core hooks.
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		add_action( 'save_post_' . self::CPT, [ $this, 'save_event_meta' ] );

		// Allow standard categories on events.
		add_action( 'init', [ $this, 'attach_categories_to_events' ], 11 );

		// REST endpoint.
		add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );

		// One-time cache flush on version change is currently disabled for runtime safety.


		// Cache flushing.
		add_action( 'save_post_' . self::CPT, [ $this, 'flush_event_feeds_cache' ], 20, 2 );
		add_action( 'trash_post', [ $this, 'maybe_flush_on_trash' ], 10, 1 );
		add_action( 'created_term', [ $this, 'flush_event_feeds_cache' ], 10, 3 );
		add_action( 'edited_term', [ $this, 'flush_event_feeds_cache' ], 10, 3 );
		add_action( 'delete_term', [ $this, 'flush_event_feeds_cache' ], 10, 3 );

		// Shortcode.
		add_shortcode( 'clamp_events_list', [ $this, 'shortcode_events_list' ] );
		add_shortcode( 'clamp_events_remote', [ $this, 'shortcode_events_remote' ] );
		add_shortcode( 'next_bastardos_event', [ $this, 'shortcode_next_bastardos_event' ] );

		// Admin menu & Info page.
		add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
		add_filter( 'manage_' . self::CPT . '_posts_columns', [ $this, 'set_event_admin_columns' ] );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', [ $this, 'render_event_admin_column' ], 10, 2 );
		add_filter( 'manage_edit-' . self::CPT . '_sortable_columns', [ $this, 'set_event_admin_sortable_columns' ] );
		add_action( 'pre_get_posts', [ $this, 'sort_events_admin_list' ] );
		add_filter( 'months_dropdown_results', [ $this, 'filter_event_date_dropdown' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ $this, 'render_event_year_filter' ] );

        // Raw ICS output for REST route (bypass JSON encoding).
        add_filter( 'rest_pre_serve_request', [ $this, 'serve_ics_response' ], 10, 4 );

		// Plugin row "Info" link.
		add_filter(
			'plugin_action_links_' . $this->plugin_basename,
			[ $this, 'add_plugin_action_links' ]
		);
	}

	/**
	 * Register custom post type for events.
	 */
	public function register_cpt() {
		$labels = [
			'name'               => __( 'Clamp Events', 'clamp-events' ),
			'singular_name'      => __( 'Clamp Event', 'clamp-events' ),
			'add_new'            => __( 'Add New Event', 'clamp-events' ),
			'add_new_item'       => __( 'Add New Clamp Event', 'clamp-events' ),
			'edit_item'          => __( 'Edit Clamp Event', 'clamp-events' ),
			'new_item'           => __( 'New Clamp Event', 'clamp-events' ),
			'view_item'          => __( 'View Clamp Event', 'clamp-events' ),
			'search_items'       => __( 'Search Clamp Events', 'clamp-events' ),
			'not_found'          => __( 'No events found', 'clamp-events' ),
			'not_found_in_trash' => __( 'No events found in Trash', 'clamp-events' ),
			'menu_name'          => __( 'Clamp Events', 'clamp-events' ),
		];

		$args = [
			'labels'             => $labels,
			'public'             => true,
			'show_in_rest'       => true,
			'has_archive'        => true,
			'menu_position'      => 20,
			'menu_icon'          => 'dashicons-calendar-alt',
			'supports'           => [ 'title', 'editor', 'excerpt' ],
		];

		register_post_type( self::CPT, $args );
	}

	/**
	 * Attach built-in 'category' taxonomy to events so we can use Chapter/Bastardos/BoBs.
	 */
	public function attach_categories_to_events() {
		register_taxonomy_for_object_type( 'category', self::CPT );
	}

	/**
	 * Register event meta boxes for start/end datetime and venue details.
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'clamp_event_details',
			__( 'Event Details', 'clamp-events' ),
			[ $this, 'render_event_meta_box' ],
			self::CPT,
			'normal',
			'high'
		);
	}

	/**
	 * Render the meta box fields.
	 */
	public function render_event_meta_box( $post ) {
		wp_nonce_field( 'clamp_event_save_meta', 'clamp_event_meta_nonce' );

		$start         = get_post_meta( $post->ID, '_clamp_event_start', true );
		$end           = get_post_meta( $post->ID, '_clamp_event_end', true );
		$time_text     = get_post_meta( $post->ID, '_clamp_event_time_text', true );
		$time_text     = '' !== trim( (string) $time_text ) ? $time_text : '6-8 P.M.';
		$venue_name    = get_post_meta( $post->ID, '_clamp_event_venue_name', true );
		$venue_address = get_post_meta( $post->ID, '_clamp_event_venue_address', true );
		$event_url     = get_post_meta( $post->ID, '_clamp_event_url', true );
		$nf_form_id    = get_post_meta( $post->ID, '_clamp_event_nf_form_id', true );

		// Backward compatibility with old location meta key.
		if ( '' === trim( (string) $venue_address ) ) {
			$venue_address = get_post_meta( $post->ID, '_clamp_event_location', true );
		}

		$known_venues = $this->get_known_venues();

		// Known addresses for datalist.
		$known_addresses = $this->get_known_venue_addresses();

		$selected_venue = '';
		if ( '' !== trim( (string) $venue_name ) && isset( $known_venues[ $venue_name ] ) ) {
			$selected_venue = $venue_name;
		} elseif ( '' !== trim( (string) $venue_address ) ) {
			foreach ( $known_venues as $known_name => $known_address ) {
				if ( 0 === strcasecmp( trim( (string) $venue_address ), trim( (string) $known_address ) ) ) {
					$selected_venue = $known_name;
					break;
				}
			}
		}
		?>
		<p>
			<label for="clamp_event_start"><strong><?php esc_html_e( 'Start Date', 'clamp-events' ); ?></strong></label><br />
			<input type="date" id="clamp_event_start" name="clamp_event_start"
			       value="<?php echo esc_attr( $this->date_for_input( $start ) ); ?>" style="max-width: 100%;" required />
		</p>
		<p>
			<label for="clamp_event_end"><strong><?php esc_html_e( 'End Date (Optional)', 'clamp-events' ); ?></strong></label><br />
			<input type="date" id="clamp_event_end" name="clamp_event_end"
			       value="<?php echo esc_attr( $this->date_for_input( $end ) ); ?>" style="max-width: 100%;" />
		</p>
		<p>
			<label for="clamp_event_time_text"><strong><?php esc_html_e( 'Time', 'clamp-events' ); ?></strong></label><br />
			<input type="text" id="clamp_event_time_text" name="clamp_event_time_text"
			       value="<?php echo esc_attr( $time_text ); ?>" style="max-width: 100%;" placeholder="<?php esc_attr_e( 'e.g., 6:30 PM or 6:30 PM - 8:30 PM', 'clamp-events' ); ?>" />
		</p>
		<p class="description">
			<?php esc_html_e( 'Start/End are date-only. Use Time for display text.', 'clamp-events' ); ?>
		</p>
		<hr />
		<p>
			<label for="clamp_event_venue_select"><strong><?php esc_html_e( 'Select Existing Venue', 'clamp-events' ); ?></strong></label><br />
			<select id="clamp_event_venue_select" style="width: 100%; max-width: 100%;">
				<option value=""><?php esc_html_e( '— Enter a new venue below —', 'clamp-events' ); ?></option>
				<?php foreach ( $known_venues as $known_name => $known_address ) : ?>
					<option value="<?php echo esc_attr( $known_name ); ?>" <?php selected( $selected_venue, $known_name ); ?>>
						<?php echo esc_html( $known_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="description">
				<?php esc_html_e( 'Select a saved venue to auto-fill the address, or enter a new venue manually.', 'clamp-events' ); ?>
			</span>
		</p>
		<p>
			<label for="clamp_event_venue_name"><strong><?php esc_html_e( 'Venue Name', 'clamp-events' ); ?></strong></label><br />
			<input
				type="text"
				id="clamp_event_venue_name"
				name="clamp_event_venue_name"
				value="<?php echo esc_attr( $venue_name ); ?>"
				style="width: 100%;"
			/>
		</p>
		<p>
			<label for="clamp_event_venue_address"><strong><?php esc_html_e( 'Venue Address', 'clamp-events' ); ?></strong></label><br />
			<input
				type="text"
				id="clamp_event_venue_address"
				name="clamp_event_venue_address"
				list="clamp_event_venue_address_list"
				value="<?php echo esc_attr( $venue_address ); ?>"
				style="width: 100%;"
			/>
			<?php if ( ! empty( $known_addresses ) ) : ?>
				<datalist id="clamp_event_venue_address_list">
					<?php foreach ( $known_addresses as $loc ) : ?>
						<option value="<?php echo esc_attr( $loc ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<span class="description">
					<?php esc_html_e( 'Choose a previous address or type a new one.', 'clamp-events' ); ?>
				</span>
			<?php else : ?>
				<span class="description">
					<?php esc_html_e( 'Enter the venue address.', 'clamp-events' ); ?>
				</span>
			<?php endif; ?>
		</p>
		<p>
			<label for="clamp_event_url"><strong><?php esc_html_e( 'Event URL (Optional)', 'clamp-events' ); ?></strong></label><br />
			<input
				type="url"
				id="clamp_event_url"
				name="clamp_event_url"
				value="<?php echo esc_attr( $event_url ); ?>"
				style="width: 100%;"
				placeholder="<?php esc_attr_e( 'https://example.com/event-page', 'clamp-events' ); ?>"
			/>
		</p>
		<p>
			<label for="clamp_event_nf_form_id"><strong><?php esc_html_e( 'Ninja Forms Form ID (Optional)', 'clamp-events' ); ?></strong></label><br />
			<input
				type="number"
				id="clamp_event_nf_form_id"
				name="clamp_event_nf_form_id"
				value="<?php echo esc_attr( $nf_form_id ); ?>"
				style="max-width: 120px;"
				min="1"
				placeholder="<?php esc_attr_e( 'e.g. 3', 'clamp-events' ); ?>"
			/>
			<span class="description"><?php esc_html_e( 'Enter the Ninja Forms form ID to embed a form on this event.', 'clamp-events' ); ?></span>
		</p>
		<script>
		(function () {
			var venueMap = <?php echo wp_json_encode( $known_venues ); ?>;
			var selectEl = document.getElementById('clamp_event_venue_select');
			var nameEl = document.getElementById('clamp_event_venue_name');
			var addressEl = document.getElementById('clamp_event_venue_address');

			if (!selectEl || !nameEl || !addressEl) {
				return;
			}

			selectEl.addEventListener('change', function () {
				var selectedName = (this.value || '').trim();
				if (!selectedName) {
					return;
				}

				nameEl.value = selectedName;
				if (venueMap[selectedName]) {
					addressEl.value = venueMap[selectedName];
				}
			});

			nameEl.addEventListener('change', function () {
				var typedName = (this.value || '').trim();
				if (!typedName) {
					return;
				}

				if (venueMap[typedName] && !(addressEl.value || '').trim()) {
					addressEl.value = venueMap[typedName];
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Get known venues as [venue_name => venue_address].
	 */
	private function get_known_venues() {
		$venues = [];

		$args  = [
			'post_type'      => self::CPT,
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'meta_query'     => [
				'relation' => 'OR',
				[
					'key'     => '_clamp_event_venue_name',
					'compare' => 'EXISTS',
				],
				[
					'key'     => '_clamp_event_venue_address',
					'compare' => 'EXISTS',
				],
				[
					'key'     => '_clamp_event_location',
					'compare' => 'EXISTS',
				],
			],
			'fields'         => 'ids',
		];
		$posts = get_posts( $args );

		if ( ! empty( $posts ) ) {
			foreach ( $posts as $event_id ) {
				$name    = trim( (string) get_post_meta( $event_id, '_clamp_event_venue_name', true ) );
				$address = trim( (string) get_post_meta( $event_id, '_clamp_event_venue_address', true ) );

				if ( '' === $address ) {
					$address = trim( (string) get_post_meta( $event_id, '_clamp_event_location', true ) );
				}

				if ( '' !== $name && '' !== $address && ! isset( $venues[ $name ] ) ) {
					$venues[ $name ] = $address;
				}
			}
		}

		if ( ! empty( $venues ) ) {
			uksort( $venues, 'strnatcasecmp' );
		}

		return $venues;
	}

	/**
	 * Get unique known venue addresses from existing events.
	 */
	private function get_known_venue_addresses() {
		$locations = [];

		$args  = [
			'post_type'      => self::CPT,
			'post_status'    => 'any',
			'posts_per_page' => 200, // reasonable cap; adjust if needed.
			'meta_query'     => [
				'relation' => 'OR',
				[
					'key'     => '_clamp_event_venue_address',
					'compare' => 'EXISTS',
				],
				[
					'key'     => '_clamp_event_location',
					'compare' => 'EXISTS',
				],
			],
			'fields'         => 'ids',
		];
		$posts = get_posts( $args );

		if ( ! empty( $posts ) ) {
			foreach ( $posts as $event_id ) {
				$loc = get_post_meta( $event_id, '_clamp_event_venue_address', true );
				if ( '' === trim( (string) $loc ) ) {
					$loc = get_post_meta( $event_id, '_clamp_event_location', true );
				}
				$loc = trim( (string) $loc );
				if ( $loc !== '' ) {
					$locations[ $loc ] = true;
				}
			}
		}

		$locations = array_keys( $locations );
		sort( $locations, SORT_NATURAL | SORT_FLAG_CASE );

		return $locations;
	}

	/**
	 * Format stored datetime for HTML date input.
	 */
	private function date_for_input( $value ) {
		if ( empty( $value ) ) {
			return '';
		}
		
		// Stored in 'Y-m-d H:i:s' format in America/Los_Angeles timezone.
		try {
			$tz = new DateTimeZone( self::TIMEZONE_ID );
			$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $value, $tz );
			
			if ( ! $dt ) {
				return '';
			}
			
			// Return in format for date input.
			return $dt->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Extract HH:MM:SS from stored datetime, fallback to default.
	 */
	private function time_part_for_storage( $stored_datetime, $default = '00:00:00' ) {
		$stored_datetime = trim( (string) $stored_datetime );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s(\d{2}:\d{2}:\d{2})$/', $stored_datetime, $matches ) ) {
			return $matches[1];
		}

		return $default;
	}

	/**
	 * Parse stored event date/datetime into DateTime.
	 */
	private function parse_event_datetime( $value, DateTimeZone $timezone ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		$formats = [ 'Y-m-d H:i:s', 'Y-m-d' ];
		foreach ( $formats as $format ) {
			$dt = DateTime::createFromFormat( $format, $value, $timezone );
			if ( $dt instanceof DateTime ) {
				if ( 'Y-m-d' === $format ) {
					$dt->setTime( 0, 0, 0 );
				}

				return $dt;
			}
		}

		return null;
	}

	/**
	 * Save event meta (start/end, venue name/address).
	 */
	public function save_event_meta( $post_id ) {
		if ( ! isset( $_POST['clamp_event_meta_nonce'] ) || ! wp_verify_nonce( $_POST['clamp_event_meta_nonce'], 'clamp_event_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$tz = new DateTimeZone( self::TIMEZONE_ID );
		$existing_start = get_post_meta( $post_id, '_clamp_event_start', true );
		$existing_end   = get_post_meta( $post_id, '_clamp_event_end', true );
		$start_time     = $this->time_part_for_storage( $existing_start, '00:00:00' );
		$end_time       = $this->time_part_for_storage( $existing_end, $start_time );

		// Start date (required).
		if ( isset( $_POST['clamp_event_start'] ) ) {
			$date_str = sanitize_text_field( wp_unslash( $_POST['clamp_event_start'] ) );
			if ( '' !== $date_str ) {
				$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $date_str . ' ' . $start_time, $tz );
				if ( $dt ) {
					update_post_meta( $post_id, '_clamp_event_start', $dt->format( 'Y-m-d H:i:s' ) );
				}
			}
		}

		// End date (optional).
		if ( isset( $_POST['clamp_event_end'] ) ) {
			$date_str = sanitize_text_field( wp_unslash( $_POST['clamp_event_end'] ) );
			if ( '' !== $date_str ) {
				$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $date_str . ' ' . $end_time, $tz );
				if ( $dt ) {
					update_post_meta( $post_id, '_clamp_event_end', $dt->format( 'Y-m-d H:i:s' ) );
				}
			} else {
				delete_post_meta( $post_id, '_clamp_event_end' );
			}
		}

		// Plain-text time for display.
		if ( isset( $_POST['clamp_event_time_text'] ) ) {
			$time_text = sanitize_text_field( wp_unslash( $_POST['clamp_event_time_text'] ) );
			if ( $time_text !== '' ) {
				update_post_meta( $post_id, '_clamp_event_time_text', $time_text );
			} else {
				delete_post_meta( $post_id, '_clamp_event_time_text' );
			}
		}

		// Venue name.
		$venue_name = null;
		if ( isset( $_POST['clamp_event_venue_name'] ) ) {
			$venue_name = sanitize_text_field( wp_unslash( $_POST['clamp_event_venue_name'] ) );
			if ( $venue_name !== '' ) {
				update_post_meta( $post_id, '_clamp_event_venue_name', $venue_name );
			} else {
				delete_post_meta( $post_id, '_clamp_event_venue_name' );
			}
		}

		// Venue address.
		if ( isset( $_POST['clamp_event_venue_address'] ) ) {
			$venue_address = sanitize_text_field( wp_unslash( $_POST['clamp_event_venue_address'] ) );

			if ( '' === $venue_address && '' !== trim( (string) $venue_name ) ) {
				$known_venues = $this->get_known_venues();
				if ( isset( $known_venues[ $venue_name ] ) ) {
					$venue_address = $known_venues[ $venue_name ];
				}
			}

			if ( $venue_address !== '' ) {
				update_post_meta( $post_id, '_clamp_event_venue_address', $venue_address );
				// Keep legacy key in sync for backward compatibility.
				update_post_meta( $post_id, '_clamp_event_location', $venue_address );
			} else {
				delete_post_meta( $post_id, '_clamp_event_venue_address' );
				delete_post_meta( $post_id, '_clamp_event_location' );
			}
		}

		// Event URL.
		if ( isset( $_POST['clamp_event_url'] ) ) {
			$event_url = esc_url_raw( trim( (string) wp_unslash( $_POST['clamp_event_url'] ) ) );

			if ( '' !== $event_url ) {
				update_post_meta( $post_id, '_clamp_event_url', $event_url );
			} else {
				delete_post_meta( $post_id, '_clamp_event_url' );
			}
		}

		// Ninja Forms form ID (optional).
		if ( isset( $_POST['clamp_event_nf_form_id'] ) ) {
			$nf_form_id = absint( wp_unslash( $_POST['clamp_event_nf_form_id'] ) );

			if ( $nf_form_id > 0 ) {
				update_post_meta( $post_id, '_clamp_event_nf_form_id', $nf_form_id );
			} else {
				delete_post_meta( $post_id, '_clamp_event_nf_form_id' );
			}
		}
	}

	/**
	 * Register REST route for ICS output.
	 */
	public function register_rest_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_feed_callback' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'event_id' => [
						'description' => 'Single event ID for ICS download.',
						'type'        => 'integer',
						'required'    => false,
					],
					'category' => [
						'description' => 'Category slug (chapter, bastardos, bobs).',
						'type'        => 'string',
						'required'    => false,
					],
					'name' => [
						'description' => 'Optional calendar display name for subscribers.',
						'type'        => 'string',
						'required'    => false,
					],
				],
			]
		);

		// JSON endpoint for cross-site event display.
		register_rest_route(
			self::REST_NAMESPACE,
			'/events',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_events_json_callback' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'category' => [
						'description' => 'Category slug to filter by.',
						'type'        => 'string',
						'required'    => false,
					],
					'limit' => [
						'description' => 'Number of events to return.',
						'type'        => 'integer',
						'required'    => false,
						'default'     => 10,
					],
				],
			]
		);
	}

	/**
	 * REST callback: output full calendar or single-event ICS.
	 */
	public function rest_feed_callback( WP_REST_Request $request ) {
		$event_id      = intval( $request->get_param( 'event_id' ) );
		$category      = sanitize_key( $request->get_param( 'category' ) );
		$name_override = sanitize_text_field( (string) $request->get_param( 'name' ) );

		if ( $event_id ) {
			$ics      = $this->build_single_event_ics( $event_id, $name_override );
			$filename = 'event-' . $event_id . '.ics';
			$calendar_name = $name_override;
		} else {
			$calendar_name = $name_override ? $name_override : $this->get_default_calendar_name( $category );

			if ( $name_override ) {
				$ics = $this->build_full_calendar_ics( $category, $name_override );
			} else {
				$ics = $this->get_cached_full_feed( $category );
			}

			$filename = 'events' . ( $category ? '-' . $category : '' ) . '.ics';
		}

		if ( ! $ics ) {
			if ( $event_id ) {
				return new WP_Error( 'clamp_events_no_content', __( 'No events found or invalid request.', 'clamp-events' ), [ 'status' => 404 ] );
			}

			// For feed subscriptions, always return a valid (possibly empty) calendar.
			$ics = $this->wrap_calendar( [], $calendar_name );
		}

		$response = new WP_REST_Response( $ics );
		$response->set_headers(
			[
				'Content-Type'        => 'text/calendar; charset=utf-8',
				'Content-Disposition' => 'inline; filename="' . $filename . '"',
			]
		);

		return $response;
	}

	/**
	 * REST callback: return events as JSON for cross-site display.
	 */
	public function rest_events_json_callback( WP_REST_Request $request ) {
		$category = sanitize_key( $request->get_param( 'category' ) );
		$limit    = intval( $request->get_param( 'limit' ) );
		$limit    = $limit > 0 ? $limit : 10;
		$today_date = current_time( 'Y-m-d' );

		$args = [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'meta_key'       => '_clamp_event_start',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'     => '_clamp_event_start',
					'value'   => $today_date,
					'compare' => '>=',
					'type'    => 'DATE',
				],
			],
		];

		if ( $category ) {
			$args['tax_query'] = [
				[
					'taxonomy' => 'category',
					'field'    => 'slug',
					'terms'    => $category,
				],
			];
		}

		$query  = new WP_Query( $args );
		$events = [];

		if ( $query->have_posts() ) {
			$tz = new DateTimeZone( self::TIMEZONE_ID );

			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();

				$start_raw      = get_post_meta( $post_id, '_clamp_event_start', true );
				$end_raw        = get_post_meta( $post_id, '_clamp_event_end', true );
				$venue_name     = get_post_meta( $post_id, '_clamp_event_venue_name', true );
				$venue_address  = get_post_meta( $post_id, '_clamp_event_venue_address', true );
				$event_url      = get_post_meta( $post_id, '_clamp_event_url', true );
				$time_text      = get_post_meta( $post_id, '_clamp_event_time_text', true );
				if ( '' === trim( (string) $venue_address ) ) {
					$venue_address = get_post_meta( $post_id, '_clamp_event_location', true );
				}

				$dt_start = $this->parse_event_datetime( $start_raw, $tz );
				$dt_end   = $this->parse_event_datetime( $end_raw, $tz );

				$events[] = [
					'id'          => $post_id,
					'title'       => get_the_title(),
					'content'     => get_the_content(),
					'excerpt'     => get_the_excerpt(),
					'permalink'   => get_permalink( $post_id ),
					'start'       => $start_raw,
					'end'         => $end_raw,
					'start_formatted' => $dt_start ? $dt_start->format( 'l, M j, Y' ) : '',
					'end_formatted'   => $dt_end ? $dt_end->format( 'l, M j, Y' ) : '',
					'time_text'   => $time_text,
					'venue_name'  => $venue_name,
					'venue_address' => $venue_address,
					'event_url'   => $event_url,
					'location'    => $venue_address,
					'nf_form_id'  => absint( get_post_meta( $post_id, '_clamp_event_nf_form_id', true ) ),
					'ics_url'     => add_query_arg(
						[ 'event_id' => $post_id ],
						rest_url( self::REST_NAMESPACE . self::REST_ROUTE )
					),
				];
			}
			wp_reset_postdata();
		}

		return new WP_REST_Response( $events, 200 );
	}

    /**
     * Serve ICS responses as raw text/calendar instead of JSON-encoded.
     *
     * This prevents "BEGIN:VCALENDAR\r\n..." JSON strings and ensures
     * the client receives a proper .ics file.
     */
    public function serve_ics_response( $served, $result, $request, $server ) {
        // If something else already served the response, leave it alone.
        if ( $served ) {
            return $served;
        }

        // This filter runs for ALL REST responses. We only care about our feed route.
		$route        = untrailingslashit( (string) $request->get_route() );
		$target_route = untrailingslashit( '/' . self::REST_NAMESPACE . self::REST_ROUTE );
		if ( $route !== $target_route ) {
            return $served;
        }

        // Only handle normal REST responses, not errors.
        if ( ! $result instanceof WP_REST_Response ) {
            return $served;
        }

        $data = $result->get_data();

        // Only handle our ICS string payloads.
        if ( ! is_string( $data ) ) {
            return $served;
        }

        // Send headers from the response object (includes Content-Type we set).
        $headers = $result->get_headers();
        foreach ( $headers as $name => $value ) {
            $server->send_header( $name, $value );
        }

		// Status code.
		status_header( (int) $result->get_status() );

        // Output raw ICS and tell WordPress we've served the request.
        echo $data;

        return true;
    }

	/**
	 * Fetch full feed with caching by category.
	 */
	private function get_cached_full_feed( $category_slug = '' ) {
		$category_slug = strtolower( $category_slug );
		$allowed_cats  = [ 'chapter', 'bastardos', 'bobs' ];

		if ( $category_slug && ! in_array( $category_slug, $allowed_cats, true ) ) {
			// Unknown category; treat as "all".
			$category_slug = '';
		}

		$key    = 'clamp_events_ics_v2_' . ( $category_slug ? $category_slug : 'all' );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$ics = $this->build_full_calendar_ics( $category_slug );

		// Cache for 15 minutes if we have something.
		if ( $ics ) {
			set_transient( $key, $ics, 15 * MINUTE_IN_SECONDS );
		}

		return $ics;
	}

	/**
	 * Build ICS for full calendar feed.
	 */
	private function build_full_calendar_ics( $category_slug = '', $calendar_name = '' ) {
		$today_date = current_time( 'Y-m-d' );
		$args = [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'meta_key'       => '_clamp_event_start',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'     => '_clamp_event_start',
					'value'   => $today_date,
					'compare' => '>=',
					'type'    => 'DATE',
				],
			],
		];

		if ( $category_slug ) {
			$args['tax_query'] = [
				[
					'taxonomy' => 'category',
					'field'    => 'slug',
					'terms'    => $category_slug,
				],
			];
		}

		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			if ( '' === trim( (string) $calendar_name ) ) {
				$calendar_name = $this->get_default_calendar_name( $category_slug );
			}

			return $this->wrap_calendar( [], $calendar_name );
		}

		$events = [];

		while ( $query->have_posts() ) {
			$query->the_post();
			$post = get_post();

			$vevent = $this->build_vevent( $post );
			if ( $vevent ) {
				$events[] = $vevent;
			}
		}
		wp_reset_postdata();

		if ( '' === trim( (string) $calendar_name ) ) {
			$calendar_name = $this->get_default_calendar_name( $category_slug );
		}

		return $this->wrap_calendar( $events, $calendar_name );
	}

	/**
	 * Build ICS for single event.
	 */
	private function build_single_event_ics( $event_id, $calendar_name = '' ) {
		$post = get_post( $event_id );
		if ( ! $post || self::CPT !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}

		$vevent = $this->build_vevent( $post );
		if ( ! $vevent ) {
			return '';
		}

		$events = [ $vevent ];

		if ( '' === trim( (string) $calendar_name ) ) {
			$calendar_name = get_the_title( $post );
		}

		return $this->wrap_calendar( $events, $calendar_name );
	}

	/**
	 * Build default calendar display name.
	 */
	private function get_default_calendar_name( $category_slug = '' ) {
		return 'ECV 1.5 Events';
	}

	/**
	 * Build VEVENT block for a given event post.
	 */
	private function build_vevent( WP_Post $post ) {
		$start = get_post_meta( $post->ID, '_clamp_event_start', true );
		if ( ! $start ) {
			return '';
		}

		$end       = get_post_meta( $post->ID, '_clamp_event_end', true );
		$time_text = get_post_meta( $post->ID, '_clamp_event_time_text', true );
		$tz        = new DateTimeZone( self::TIMEZONE_ID );
		
		$host = parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? preg_replace( '/^www\./', '', $host ) : 'localhost';
		$uid  = 'clamp_event_' . $post->ID . '@' . $host;

		$dtstart = $this->parse_event_datetime( $start, $tz );
		if ( ! $dtstart ) {
			return '';
		}

		if ( $end ) {
			$dtend = $this->parse_event_datetime( $end, $tz );
		} else {
			$dtend = clone $dtstart;
			$dtend->modify( '+1 hour' );
		}

		$parsed_time_range = $this->parse_time_text_range( $time_text );
		if ( $parsed_time_range ) {
			$dtstart->setTime(
				intval( substr( $parsed_time_range['start'], 0, 2 ) ),
				intval( substr( $parsed_time_range['start'], 3, 2 ) ),
				intval( substr( $parsed_time_range['start'], 6, 2 ) )
			);

			if ( $dtend ) {
				$end_hms = $parsed_time_range['end'] ? $parsed_time_range['end'] : $parsed_time_range['start'];
				$dtend->setTime(
					intval( substr( $end_hms, 0, 2 ) ),
					intval( substr( $end_hms, 3, 2 ) ),
					intval( substr( $end_hms, 6, 2 ) )
				);
			}

			if ( ! $end && $dtend && $dtend <= $dtstart ) {
				$dtend->modify( '+1 day' );
			}
		}

		// Guard: if end is not after start, force a default 2-hour duration.
		if ( ! $dtend || $dtend <= $dtstart ) {
			$dtend = clone $dtstart;
			$dtend->modify( '+2 hours' );
		}

		// Decode HTML entities first so we get real punctuation, then escape for ICS.
		// 1) Decode HTML entities from WP.
		$summary_raw     = 'ECV 1.5: ' . html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		$description_raw = html_entity_decode( wp_strip_all_tags( $post->post_content ), ENT_QUOTES, 'UTF-8' );
		$venue_name_meta = get_post_meta( $post->ID, '_clamp_event_venue_name', true );
		$location_meta   = get_post_meta( $post->ID, '_clamp_event_venue_address', true );
		if ( '' === trim( (string) $location_meta ) ) {
			$location_meta = get_post_meta( $post->ID, '_clamp_event_location', true );
		}
		$venue_name_raw  = html_entity_decode( (string) $venue_name_meta, ENT_QUOTES, 'UTF-8' );
		$address_raw     = html_entity_decode( (string) $location_meta, ENT_QUOTES, 'UTF-8' );
		if ( '' !== trim( $venue_name_raw ) && '' !== trim( $address_raw ) ) {
			$location_raw = $venue_name_raw . ', ' . $address_raw;
		} elseif ( '' !== trim( $venue_name_raw ) ) {
			$location_raw = $venue_name_raw;
		} else {
			$location_raw = $address_raw;
		}

		// 2) Now escape for ICS.
		$summary     = $this->esc_ical_text( $summary_raw );
		$description = $this->esc_ical_text( $description_raw );
		$location    = $this->esc_ical_text( $location_raw );

		$lines   = [];
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $uid;
		$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', time() );
		$lines[] = 'DTSTART;TZID=' . self::TIMEZONE_ID . ':' . $dtstart->format( 'Ymd\THis' );
		$lines[] = 'DTEND;TZID=' . self::TIMEZONE_ID . ':' . $dtend->format( 'Ymd\THis' );
		$lines[] = 'SUMMARY:' . $summary;

		if ( $description !== '' ) {
			$lines[] = 'DESCRIPTION:' . $description;
		}

		if ( $location !== '' ) {
			$lines[] = 'LOCATION:' . $location;
		}

		$lines[] = 'END:VEVENT';

		return $this->fold_lines( $lines );
	}

	/**
	 * Parse time text into start/end HH:MM:SS values.
	 */
	private function parse_time_text_range( $time_text ) {
		$time_text = trim( (string) $time_text );
		if ( '' === $time_text ) {
			return null;
		}

		$normalized = strtoupper( $time_text );
		$normalized = str_replace( [ '.', '–', '—', ' TO ' ], [ '', '-', '-', '-' ], $normalized );
		$normalized = preg_replace( '/\s+/', ' ', $normalized );

		if ( preg_match( '/^\s*(\d{1,2}(?::\d{2})?)\s*-\s*(\d{1,2}(?::\d{2})?)\s*(AM|PM)\s*$/', $normalized, $m ) ) {
			$start = $this->normalize_clock_time( $m[1] . ' ' . $m[3] );
			$end   = $this->normalize_clock_time( $m[2] . ' ' . $m[3] );
			if ( $start && $end ) {
				return [ 'start' => $start, 'end' => $end ];
			}
		}

		if ( preg_match( '/^\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM))\s*-\s*(\d{1,2}(?::\d{2})?\s*(?:AM|PM))\s*$/', $normalized, $m ) ) {
			$start = $this->normalize_clock_time( $m[1] );
			$end   = $this->normalize_clock_time( $m[2] );
			if ( $start && $end ) {
				return [ 'start' => $start, 'end' => $end ];
			}
		}

		$single = $this->normalize_clock_time( $normalized );
		if ( $single ) {
			return [ 'start' => $single, 'end' => '' ];
		}

		return null;
	}

	/**
	 * Normalize a clock time string to HH:MM:SS.
	 */
	private function normalize_clock_time( $time_value ) {
		$time_value = strtoupper( trim( (string) $time_value ) );
		if ( '' === $time_value ) {
			return '';
		}

		$formats = [ 'g:i A', 'g A', 'g:iA', 'gA' ];
		foreach ( $formats as $format ) {
			$dt = DateTime::createFromFormat( $format, $time_value );
			if ( $dt instanceof DateTime ) {
				return $dt->format( 'H:i:s' );
			}
		}

		return '';
	}

	/**
	 * Wrap VEVENTs in VCALENDAR with VTIMEZONE.
	 */
	private function wrap_calendar( array $vevents, $calendar_name = '' ) {
		$lines   = [];
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'PRODID:-//Clamp Events iCal Feed//EN';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'METHOD:PUBLISH';

		$calendar_name = trim( (string) $calendar_name );
		if ( '' !== $calendar_name ) {
			$cal_name = $this->esc_ical_text( $calendar_name );
			$lines[]  = 'X-WR-CALNAME:' . $cal_name;
			$lines[]  = 'NAME:' . $cal_name;
		}

		// Static VTIMEZONE for America/Los_Angeles.
		$lines = array_merge( $lines, $this->get_vtimezone_block() );

		foreach ( $vevents as $vevent ) {
			$vevent_lines = explode( "\r\n", $vevent );
			$lines        = array_merge( $lines, $vevent_lines );
		}

		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Static VTIMEZONE block for America/Los_Angeles.
	 */
	private function get_vtimezone_block() {
		return [
			'BEGIN:VTIMEZONE',
			'TZID:' . self::TIMEZONE_ID,
			'X-LIC-LOCATION:' . self::TIMEZONE_ID,
			'BEGIN:DAYLIGHT',
			'TZOFFSETFROM:-0800',
			'TZOFFSETTO:-0700',
			'TZNAME:PDT',
			'DTSTART:19700308T020000',
			'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU',
			'END:DAYLIGHT',
			'BEGIN:STANDARD',
			'TZOFFSETFROM:-0700',
			'TZOFFSETTO:-0800',
			'TZNAME:PST',
			'DTSTART:19701101T020000',
			'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU',
			'END:STANDARD',
			'END:VTIMEZONE',
		];
	}

	/**
	 * Escape text for iCal.
	 */
	private function esc_ical_text( $text ) {
		$text = (string) $text;
		$text = str_replace(
			[ '\\', ';', ',', "\r\n", "\r", "\n" ],
			[ '\\\\', '\;', '\,', '\\n', '', '\\n' ],
			$text
		);
		return $text;
	}

	/**
	 * Fold lines to max 75 octets (roughly 75 chars) per iCal spec.
	 */
	private function fold_lines( array $lines ) {
		$out = [];

		foreach ( $lines as $line ) {
			$line = (string) $line;
			while ( strlen( $line ) > 75 ) {
				$out[] = substr( $line, 0, 75 );
				$line  = ' ' . substr( $line, 75 );
			}
			$out[] = $line;
		}

		return implode( "\r\n", $out );
	}

	/**
	 * Build map URL for a venue address.
	 */
	private function get_venue_map_url( $venue_address ) {
		$venue_address = trim( (string) $venue_address );
		if ( '' === $venue_address ) {
			return '';
		}

		return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $venue_address );
	}

	/**
	 * Build venue display HTML; venue text plus map icon link if address exists.
	 */
	private function get_venue_display_html( $venue_name, $venue_address ) {
		$venue_name    = trim( (string) $venue_name );
		$venue_address = trim( (string) $venue_address );
		$map_icon_url  = 'https://dlgyp.org/wp-content/uploads/sites/2/2026/02/1.5Map.png';

		if ( '' === $venue_name && '' === $venue_address ) {
			return '';
		}

		$display_text = '' !== $venue_name ? $venue_name : $venue_address;

		if ( '' === $venue_address ) {
			return esc_html( $display_text );
		}

		$map_url = $this->get_venue_map_url( $venue_address );
		if ( '' === $map_url ) {
			return esc_html( $display_text );
		}

		return esc_html( $display_text ) . ' <a href="' . esc_url( $map_url ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $map_icon_url ) . '" alt="' . esc_attr__( 'View map', 'clamp-events' ) . '" width="20" height="20" /></a>';
	}

	/**
	 * Rough TZ offset in seconds for TIMEZONE_ID relative to UTC now (used only for admin display).
	 */
	private function tz_offset_seconds() {
		try {
			$tz = new DateTimeZone( self::TIMEZONE_ID );
			$dt = new DateTime( 'now', $tz );
			return $dt->getOffset();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	/**
	 * Read plugin version from header.
	 */
	private function get_plugin_version() {
		return self::VERSION;
	}

	/**
	 * Flush caches once when plugin version changes.
	 */
	public function maybe_flush_cache_on_version_change() {
		$current_version = $this->get_plugin_version();
		if ( '' === $current_version ) {
			return;
		}

		$option_key     = 'clamp_events_cache_plugin_version';
		$stored_version = get_option( $option_key, '' );

		if ( $stored_version === $current_version ) {
			return;
		}

		$this->flush_event_feeds_cache();
		$this->flush_remote_shortcode_cache();

		update_option( $option_key, $current_version, false );
	}

	/**
	 * Flush cached remote shortcode HTML transients.
	 */
	private function flush_remote_shortcode_cache() {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}

		$patterns = [
			'_transient_clamp_remote_events_%',
			'_transient_timeout_clamp_remote_events_%',
		];

		foreach ( $patterns as $pattern ) {
			$like = $wpdb->esc_like( $pattern );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like
				)
			);
		}
	}

	/**
	 * Flush cached feeds when events or related terms change.
	 */
	public function flush_event_feeds_cache() {
		$slugs = [ '', 'chapter', 'bastardos', 'bobs' ];
		foreach ( $slugs as $slug ) {
			$key = 'clamp_events_ics_v2_' . ( $slug ? $slug : 'all' );
			delete_transient( $key );
		}
	}

	/**
	 * Flush when an event is trashed.
	 */
	public function maybe_flush_on_trash( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && self::CPT === $post->post_type ) {
			$this->flush_event_feeds_cache();
		}
	}

	/* ---------------------------------------------------------------------
	 * SHORTCODE: [clamp_events_list]
	 * ------------------------------------------------------------------ */

	/**
	 * Shortcode handler for [clamp_events_list].
	 *
	 * Attributes:
	 * - category: category slug to filter by (e.g., chapter, bastardos, bobs).
	 * - limit: number of events to show (default 10).
	 */
	public function shortcode_events_list( $atts ) {
		$atts = shortcode_atts(
			[
				'category' => '',
				'limit'    => 10,
			],
			$atts,
			'clamp_events_list'
		);

		$limit    = intval( $atts['limit'] );
		$limit    = $limit > 0 ? $limit : 10;
		$category = sanitize_key( $atts['category'] );
		$today_date = current_time( 'Y-m-d' );

		$args = [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'meta_key'       => '_clamp_event_start',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'     => '_clamp_event_start',
					'value'   => $today_date,
					'compare' => '>=',
					'type'    => 'DATE',
				],
			],
		];

		if ( $category ) {
			$args['tax_query'] = [
				[
					'taxonomy' => 'category',
					'field'    => 'slug',
					'terms'    => $category,
				],
			];
		}

		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return '<div class="clamp-events-list clamp-events-empty">' . esc_html__( 'No upcoming events found.', 'clamp-events' ) . '</div>';
		}

		$tz   = new DateTimeZone( self::TIMEZONE_ID );
		$html = '<div class="clamp-events-list"><h3 class="wp-block-heading">Scheduled Events</h3><ul>';

		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id   = get_the_ID();
			$title     = get_the_title();
			$event_url = get_post_meta( $post_id, '_clamp_event_url', true );
			$time_text = get_post_meta( $post_id, '_clamp_event_time_text', true );
			$venue_name    = get_post_meta( $post_id, '_clamp_event_venue_name', true );
			$venue_address = get_post_meta( $post_id, '_clamp_event_venue_address', true );
			if ( '' === trim( (string) $venue_address ) ) {
				$venue_address = get_post_meta( $post_id, '_clamp_event_location', true );
			}

			$start_raw = get_post_meta( $post_id, '_clamp_event_start', true );
			$end_raw   = get_post_meta( $post_id, '_clamp_event_end', true );

			$dt_start = $this->parse_event_datetime( $start_raw, $tz );
			$dt_end   = $this->parse_event_datetime( $end_raw, $tz );

			$datetime_str = '';
			if ( $dt_start ) {
				if ( $dt_end && $dt_start->format( 'Ymd' ) !== $dt_end->format( 'Ymd' ) ) {
					$datetime_str = esc_html( $dt_start->format( 'm/d/Y' ) . ' - ' . $dt_end->format( 'm/d/Y' ) );
				} else {
					$datetime_str = esc_html( $dt_start->format( 'm/d/Y' ) );
					if ( '' !== trim( (string) $time_text ) ) {
						$datetime_str .= ', ' . esc_html( $time_text );
					} elseif ( $dt_end ) {
						$datetime_str .= ', ' . esc_html( $dt_start->format( 'g:i a' ) . ' - ' . $dt_end->format( 'g:i a' ) );
					}
				}
			}

			$ics_url = esc_url(
				add_query_arg(
					[
						'event_id' => $post_id,
					],
					rest_url( self::REST_NAMESPACE . self::REST_ROUTE )
				)
			);

			$html .= '<li class="clamp-event-item">';
			$html .= '<div class="clamp-event-title"><b>' . esc_html( $title ) . '</b>';
			if ( '' !== trim( (string) $event_url ) ) {
				$html .= ' - <a href="' . esc_url( $event_url ) . '" target="_self">' . esc_html__( 'RSVP', 'clamp-events' ) . '</a>';
			}
			$html .= '</div>';

			if ( $datetime_str ) {
				$html .= '<div class="clamp-event-datetime">' . $datetime_str . '</div>';
			}

			$venue_html = $this->get_venue_display_html( $venue_name, $venue_address );
			if ( '' !== $venue_html ) {
				$html .= '<div class="clamp-event-location">' . $venue_html . '</div>';
			}

			$html .= '</li>';
		}
		wp_reset_postdata();

		$html .= '</ul><center><i>Events Subject to Change</i><br>';
		$html .= '<a href="https://bastardos.dlgyp.org/wp-json/clamp-events/v1/feed?name=ECV%201.5%20Events">Subscribe ';
        $html .= 'to this Calendar</a></center></div>';

		return $html;
	}

	/**
	 * Shortcode handler for [next_bastardos_event].
	 *
	 * Fetches the next upcoming Bastardos event from dlgyp.org via REST API
	 * and displays the title, date, venue name, venue address, and Ninja Forms
	 * form (if a form ID is set on the event).
	 */
	public function shortcode_next_bastardos_event( $atts ) {
		$source_url = 'https://dlgyp.org';

		$api_url = add_query_arg(
			[
				'category' => 'bastardos',
				'limit'    => 1,
			],
			trailingslashit( $source_url ) . 'wp-json/' . self::REST_NAMESPACE . '/events'
		);

		$cache_key = 'clamp_next_bastardos_v1_' . md5( $api_url );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			// Append the NF form fresh on every request so NF can enqueue its scripts.
			$nf_form_id = absint( $cached['nf_form_id'] );
			$html       = $cached['html'];
			if ( $nf_form_id > 0 ) {
				$html .= '<div class="clamp-event-form">' . do_shortcode( '[ninja_form id=' . $nf_form_id . ']' ) . '</div>';
			}
			$html .= '</div>';
			return $html;
		}

		$response = wp_remote_get(
			$api_url,
			[
				'timeout' => 10,
				'headers' => [ 'Accept' => 'application/json' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return '<div class="next-bastardos-event clamp-events-error">' .
			       esc_html__( 'Error fetching events: ', 'clamp-events' ) .
			       esc_html( $response->get_error_message() ) .
			       '</div>';
		}

		$events = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $events ) || empty( $events ) ) {
			$html = '<div class="next-bastardos-event clamp-events-empty">' . esc_html__( 'No upcoming Bastardos events found.', 'clamp-events' ) . '</div>';
			set_transient( $cache_key, [ 'html' => $html, 'nf_form_id' => 0 ], 5 * MINUTE_IN_SECONDS );
			return $html;
		}

		$event         = $events[0];
		$title         = isset( $event['title'] ) ? $event['title'] : '';
		$start_raw     = isset( $event['start'] ) ? $event['start'] : '';
		$end_raw       = isset( $event['end'] ) ? $event['end'] : '';
		$time_text     = isset( $event['time_text'] ) ? $event['time_text'] : '';
		$venue_name    = isset( $event['venue_name'] ) ? $event['venue_name'] : '';
		$venue_address = isset( $event['venue_address'] ) ? $event['venue_address'] : '';
		if ( '' === trim( (string) $venue_address ) ) {
			$venue_address = isset( $event['location'] ) ? $event['location'] : '';
		}
		$nf_form_id = isset( $event['nf_form_id'] ) ? absint( $event['nf_form_id'] ) : 0;

		$tz       = new DateTimeZone( self::TIMEZONE_ID );
		$dt_start = $this->parse_event_datetime( $start_raw, $tz );
		$dt_end   = $this->parse_event_datetime( $end_raw, $tz );

		$datetime_str = '';
		if ( $dt_start ) {
			if ( $dt_end && $dt_start->format( 'Ymd' ) !== $dt_end->format( 'Ymd' ) ) {
				$datetime_str = $dt_start->format( 'm/d/Y' ) . ' - ' . $dt_end->format( 'm/d/Y' );
			} else {
				$datetime_str = $dt_start->format( 'm/d/Y' );
				if ( '' !== trim( (string) $time_text ) ) {
					$datetime_str .= ', ' . trim( (string) $time_text );
				}
			}
		}

		// Build the cacheable portion (everything except the NF form and closing div).
		$html  = '<div class="next-bastardos-event">';
		$html .= '<table class="table table-bordered dlgyp-particulars"';
		$html .= ' style="color: rgb(0, 0, 0); font-size: 16px;">';
		$html .= '<tbody>';
		$html .= '<tr><td>Occasion:</td><td><strong>' . esc_html( $title ) . '</strong></td></tr>';

		$venue_html = $this->get_venue_display_html( $venue_name, $venue_address );
		if ( '' !== $venue_html ) {
			$html .= '<tr><td>Location:</td><td><strong>' . $venue_html . '</strong></td></tr>';
		}
		if ( $dt_start ) {
			$html .= '<tr><td>Date:</td><td><strong>' . esc_html( $dt_start->format( 'm/d/Y' ) ) . '</strong></td></tr>';
		}
		$html .= '<tr><td>Schedule:</td><td><strong>6 PM Libations &amp; Fraternization<br>7 PM Victuals</strong></td></tr>';
		$html .= '<tr><td>Spread:</td><td><strong>TBD</strong></td></tr>';
		$html .= '</tbody></table><br>';
		$html .= '<h2 style="text-align: center;">Indenture of Supper &amp; Settlement</h2>';

		// Cache without the NF form so it can be rendered fresh each request.
		set_transient( $cache_key, [ 'html' => $html, 'nf_form_id' => $nf_form_id ], 15 * MINUTE_IN_SECONDS );

		if ( $nf_form_id > 0 ) {
			$html .= '<div class="clamp-event-form">' . do_shortcode( '[ninja_form id=' . $nf_form_id . ']' ) . '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Shortcode handler for [clamp_events_remote].
	 *
	 * Fetches and displays events from a remote site via REST API.
	 * 
	 * Attributes:
	 * - url: Full URL to the source website (required, e.g., https://bastardos.dlgyp.org).
	 * - category: category slug to filter by (e.g., chapter, bastardos, bobs).
	 * - limit: number of events to show (default 10).
	 */
	public function shortcode_events_remote( $atts ) {
		$atts = shortcode_atts(
			[
				'url'      => '',
				'category' => '',
				'limit'    => 10,
			],
			$atts,
			'clamp_events_remote'
		);

		$source_url = esc_url_raw( $atts['url'] );
		if ( ! $source_url ) {
			return '<div class="clamp-events-list clamp-events-error">' . esc_html__( 'Error: Please provide a source URL.', 'clamp-events' ) . '</div>';
		}

		$limit    = intval( $atts['limit'] );
		$limit    = $limit > 0 ? $limit : 10;
		$category = sanitize_key( $atts['category'] );

		// Build the API endpoint URL.
		$api_url = trailingslashit( $source_url ) . 'wp-json/' . self::REST_NAMESPACE . '/events';
		$api_url = add_query_arg(
			[
				'limit'    => $limit,
				'category' => $category,
			],
			$api_url
		);

		// Check cache first.
		$cache_key = 'clamp_remote_events_v6_' . md5( $api_url );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$normalized_cached = str_replace( 'webcal://', 'https://', (string) $cached );
			if ( $normalized_cached !== $cached ) {
				set_transient( $cache_key, $normalized_cached, 15 * MINUTE_IN_SECONDS );
			}

			return $normalized_cached;
		}

		// Fetch events from remote site.
		$response = wp_remote_get(
			$api_url,
			[
				'timeout' => 10,
				'headers' => [
					'Accept' => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return '<div class="clamp-events-list clamp-events-error">' . 
			       esc_html__( 'Error fetching events: ', 'clamp-events' ) . 
			       esc_html( $response->get_error_message() ) . 
			       '</div>';
		}

		$body   = wp_remote_retrieve_body( $response );
		$events = json_decode( $body, true );

		if ( ! is_array( $events ) || empty( $events ) ) {
			$html = '<div class="clamp-events-list clamp-events-empty">' . esc_html__( 'No upcoming events found.', 'clamp-events' ) . '</div>';
			set_transient( $cache_key, $html, 5 * MINUTE_IN_SECONDS );
			return $html;
		}

		// Build HTML output.
		$html = '<div class="clamp-events-list"><h3 class="wp-block-heading">Scheduled Events</h3><ul>';
		$tz   = new DateTimeZone( self::TIMEZONE_ID );

		foreach ( $events as $event ) {
			$title        = isset( $event['title'] ) ? $event['title'] : '';
			$event_url    = isset( $event['event_url'] ) ? $event['event_url'] : '';
			$venue_name   = isset( $event['venue_name'] ) ? $event['venue_name'] : '';
			$venue_address = isset( $event['venue_address'] ) ? $event['venue_address'] : '';
			if ( '' === trim( (string) $venue_address ) ) {
				$venue_address = isset( $event['location'] ) ? $event['location'] : '';
			}
			$start_raw    = isset( $event['start'] ) ? $event['start'] : '';
			$end_raw      = isset( $event['end'] ) ? $event['end'] : '';
			$time_text    = isset( $event['time_text'] ) ? $event['time_text'] : '';
			$start_format = isset( $event['start_formatted'] ) ? $event['start_formatted'] : '';
			$end_format   = isset( $event['end_formatted'] ) ? $event['end_formatted'] : '';

			$datetime_str = '';
			$dt_start     = $this->parse_event_datetime( $start_raw, $tz );
			$dt_end       = $this->parse_event_datetime( $end_raw, $tz );

			if ( $dt_start && $dt_end ) {
				if ( $dt_start->format( 'Ymd' ) === $dt_end->format( 'Ymd' ) ) {
					$datetime_str = esc_html( $dt_start->format( 'm/d/Y' ) );
					if ( '' !== trim( (string) $time_text ) ) {
						$datetime_str .= ', ' . esc_html( $time_text );
					} else {
						$datetime_str .= ', ' . esc_html( $dt_start->format( 'g:i a' ) . ' - ' . $dt_end->format( 'g:i a' ) );
					}
				} else {
					$datetime_str = esc_html( $dt_start->format( 'm/d/Y' ) . ' - ' . $dt_end->format( 'm/d/Y' ) );
				}
			} elseif ( $dt_start ) {
				$datetime_str = esc_html( $dt_start->format( 'm/d/Y' ) );
				if ( '' !== trim( (string) $time_text ) ) {
					$datetime_str .= ', ' . esc_html( $time_text );
				}
			} elseif ( $start_format ) {
				$datetime_str = esc_html( $start_format );
				if ( '' !== trim( (string) $time_text ) ) {
					$datetime_str .= ', ' . esc_html( $time_text );
				} elseif ( $end_format ) {
					$datetime_str .= ' - ' . esc_html( $end_format );
				}
			}

			$html .= '<li class="clamp-event-item">';
			$html .= '<div class="clamp-event-title"><b>' . esc_html( $title ) . '</b>';
			if ( '' !== trim( (string) $event_url ) ) {
				$html .= ' - <a href="' . esc_url( $event_url ) . '" target="_self">' . esc_html__( 'RSVP', 'clamp-events' ) . '</a>';
			}
			$html .= '</div>';

			if ( $datetime_str ) {
				$html .= '<div class="clamp-event-datetime">' . $datetime_str . '</div>';
			}

			$venue_html = $this->get_venue_display_html( $venue_name, $venue_address );
			if ( '' !== $venue_html ) {
				$html .= '<div class="clamp-event-location">' . $venue_html . '</div>';
			}

			$html .= '</li>';
		}

		$html .= '</ul><center><i>Events Subject to Change</i><br>';
		
		// Build subscribe URL.
		$subscribe_url = trailingslashit( $source_url ) . 'wp-json/' . self::REST_NAMESPACE . self::REST_ROUTE;
		if ( $category ) {
			$subscribe_url = add_query_arg( 'category', $category, $subscribe_url );
		}
		$subscribe_url = add_query_arg( 'name', $this->get_default_calendar_name(), $subscribe_url );
		$subscribe_url = set_url_scheme( $subscribe_url, 'https' );
		
		$html .= '<a href="' . esc_url( $subscribe_url ) . '">Subscribe to this Calendar</a></center></div>';

		// Cache for 15 minutes.
		set_transient( $cache_key, $html, 15 * MINUTE_IN_SECONDS );

		return $html;
	}

	/* ---------------------------------------------------------------------
	 * ADMIN: Info page & plugin links
	 * ------------------------------------------------------------------ */

	/**
	 * Register "Info" admin page under Clamp Events.
	 */
	public function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=' . self::CPT,
			__( 'Clamp Events Info', 'clamp-events' ),
			__( 'Info', 'clamp-events' ),
			'manage_options',
			'clamp-events-info',
			[ $this, 'render_info_page' ]
		);
	}

	/**
	 * Replace publish date column with event dates for event admin list.
	 */
	public function set_event_admin_columns( $columns ) {
		$updated = [];

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$updated['event_venue'] = __( 'Venue', 'clamp-events' );
				$updated['event_dates'] = __( 'Event Dates', 'clamp-events' );
				continue;
			}

			$updated[ $key ] = $label;
		}

		if ( ! isset( $updated['event_venue'] ) ) {
			$updated['event_venue'] = __( 'Venue', 'clamp-events' );
		}

		if ( ! isset( $updated['event_dates'] ) ) {
			$updated['event_dates'] = __( 'Event Dates', 'clamp-events' );
		}

		return $updated;
	}

	/**
	 * Render custom event dates column.
	 */
	public function render_event_admin_column( $column, $post_id ) {
		if ( 'event_venue' === $column ) {
			$venue_name    = trim( (string) get_post_meta( $post_id, '_clamp_event_venue_name', true ) );
			$venue_address = trim( (string) get_post_meta( $post_id, '_clamp_event_venue_address', true ) );

			if ( '' === $venue_address ) {
				$venue_address = trim( (string) get_post_meta( $post_id, '_clamp_event_location', true ) );
			}

			if ( '' !== $venue_name ) {
				echo esc_html( $venue_name );
				return;
			}

			if ( '' !== $venue_address ) {
				echo esc_html( $venue_address );
				return;
			}

			echo '&mdash;';
			return;
		}

		if ( 'event_dates' !== $column ) {
			return;
		}

		$tz        = new DateTimeZone( self::TIMEZONE_ID );
		$start_raw = get_post_meta( $post_id, '_clamp_event_start', true );
		$end_raw   = get_post_meta( $post_id, '_clamp_event_end', true );

		$dt_start = $this->parse_event_datetime( $start_raw, $tz );
		$dt_end   = $this->parse_event_datetime( $end_raw, $tz );

		if ( ! $dt_start ) {
			echo '&mdash;';
			return;
		}

		if ( $dt_end && $dt_start->format( 'Ymd' ) !== $dt_end->format( 'Ymd' ) ) {
			echo esc_html( $dt_start->format( 'm/d/Y' ) . ' - ' . $dt_end->format( 'm/d/Y' ) );
			return;
		}

		echo esc_html( $dt_start->format( 'm/d/Y' ) );
	}

	/**
	 * Make event dates column sortable.
	 */
	public function set_event_admin_sortable_columns( $columns ) {
		$columns['event_dates'] = 'event_dates';

		return $columns;
	}

	/**
	 * Apply start-date sorting and date filtering for the event admin list.
	 * Default view shows upcoming events only; optional year filter shows all events for that year.
	 */
	public function sort_events_admin_list( $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}

		if ( self::CPT !== $query->get( 'post_type' ) ) {
			return;
		}

		// Always clear WordPress's built-in post-date filter.
		$query->set( 'date_query', [] );

		$meta_query = (array) $query->get( 'meta_query' );
		$event_year = isset( $_GET['event_year'] ) ? intval( $_GET['event_year'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $event_year > 0 ) {
			// Year filter: show all events (past and future) for the selected year.
			$meta_query[] = [
				'key'     => '_clamp_event_start',
				'value'   => [ sprintf( '%04d-01-01', $event_year ), sprintf( '%04d-12-31', $event_year ) ],
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			];
		} else {
			// Default: show only upcoming events (today and future).
			$meta_query[] = [
				'key'     => '_clamp_event_start',
				'value'   => current_time( 'Y-m-d' ),
				'compare' => '>=',
				'type'    => 'DATE',
			];
		}

		$query->set( 'meta_query', $meta_query );

		$orderby = (string) $query->get( 'orderby' );

		// Default list sort: nearest event date first.
		if ( '' === $orderby ) {
			$query->set( 'orderby', 'event_dates' );
			$query->set( 'order', 'ASC' );
			$orderby = 'event_dates';
		}

		if ( 'event_dates' !== $orderby ) {
			return;
		}

		$query->set( 'meta_key', '_clamp_event_start' );
		$query->set( 'orderby', 'meta_value' );
		$query->set( 'meta_type', 'DATETIME' );

		if ( '' === (string) $query->get( 'order' ) ) {
			$query->set( 'order', 'ASC' );
		}
	}

	/**
	 * Suppress the default Date dropdown for Clamp Events; replaced by the year filter.
	 */
	public function filter_event_date_dropdown( $months, $post_type ) {
		if ( self::CPT !== $post_type ) {
			return $months;
		}

		return [];
	}

	/**
	 * Render a year filter dropdown above the Clamp Events admin list.
	 */
	public function render_event_year_filter( $post_type ) {
		if ( self::CPT !== $post_type ) {
			return;
		}

		global $wpdb;

		$years = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT YEAR(pm.meta_value)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s
				  AND pm.meta_value != ''
				  AND p.post_type = %s
				  AND p.post_status != 'trash'
				ORDER BY YEAR(pm.meta_value) ASC",
				'_clamp_event_start',
				self::CPT
			)
		);

		if ( empty( $years ) ) {
			return;
		}

		$selected_year = isset( $_GET['event_year'] ) ? intval( $_GET['event_year'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<select name="event_year" id="filter-by-event-year">
			<option value="0"><?php esc_html_e( 'Upcoming Events', 'clamp-events' ); ?></option>
			<?php foreach ( $years as $year ) : ?>
				<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $selected_year, intval( $year ) ); ?>>
					<?php echo esc_html( $year ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render the Info admin page.
	 */
	public function render_info_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$rest_base = rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
		?>
		<div class="wrap clamp-events-info-page">
			<h1><?php esc_html_e( 'Clamp Events – Info & Shortcodes', 'clamp-events' ); ?></h1>

			<p><?php esc_html_e( 'This plugin provides a simple Clamp Events custom post type, iCalendar feeds for subscriptions, and a shortcode for listing upcoming events.', 'clamp-events' ); ?></p>

			<hr />

			<h2><?php esc_html_e( 'Shortcode: [clamp_events_list]', 'clamp-events' ); ?></h2>

			<p><?php esc_html_e( 'Use this shortcode in any post or page to display upcoming events.', 'clamp-events' ); ?></p>

			<pre><code>[clamp_events_list]</code></pre>

			<p><?php esc_html_e( 'By default, this shows up to 10 upcoming Clamp Events (ordered by start date/time).', 'clamp-events' ); ?></p>

			<h3><?php esc_html_e( 'Shortcode Attributes', 'clamp-events' ); ?></h3>
			<ul>
				<li><code>limit</code> – <?php esc_html_e( 'Maximum number of events to display (default: 10).', 'clamp-events' ); ?></li>
				<li><code>category</code> – <?php esc_html_e( 'Category slug to filter events by (e.g., chapter, bastardos, bobs).', 'clamp-events' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Examples', 'clamp-events' ); ?></h3>

			<pre><code>[clamp_events_list]

[clamp_events_list limit="5"]

[clamp_events_list category="chapter" limit="20"]

[clamp_events_list category="bastardos"]</code></pre>

			<p><?php esc_html_e( 'Each event row includes an "Add to Calendar (.ics)" link for single-event downloads.', 'clamp-events' ); ?></p>

			<hr />

			<h2><?php esc_html_e( 'Shortcode: [clamp_events_remote]', 'clamp-events' ); ?></h2>

			<p><?php esc_html_e( 'Display events from a DIFFERENT WordPress site on this site. This allows you to publish events from one central location and display them across multiple websites.', 'clamp-events' ); ?></p>

			<h3><?php esc_html_e( 'Required Attribute', 'clamp-events' ); ?></h3>
			<ul>
				<li><code>url</code> – <?php esc_html_e( 'Full URL of the source website (e.g., https://bastardos.dlgyp.org)', 'clamp-events' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Optional Attributes', 'clamp-events' ); ?></h3>
			<ul>
				<li><code>limit</code> – <?php esc_html_e( 'Maximum number of events to display (default: 10).', 'clamp-events' ); ?></li>
				<li><code>category</code> – <?php esc_html_e( 'Category slug to filter events by (e.g., chapter, bastardos, bobs).', 'clamp-events' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Examples', 'clamp-events' ); ?></h3>

			<pre><code>[clamp_events_remote url="https://bastardos.dlgyp.org"]

[clamp_events_remote url="https://bastardos.dlgyp.org" limit="5"]

[clamp_events_remote url="https://bastardos.dlgyp.org" category="chapter"]</code></pre>

			<p><strong><?php esc_html_e( 'Note:', 'clamp-events' ); ?></strong> <?php esc_html_e( 'The remote site must have this plugin installed and activated. Events are cached for 15 minutes.', 'clamp-events' ); ?></p>

			<hr />

			<h2><?php esc_html_e( 'Calendar Subscription Feeds (ICS)', 'clamp-events' ); ?></h2>

			<p><?php esc_html_e( 'The plugin exposes iCalendar feeds via the WordPress REST API. These can be used for mobile calendar subscriptions (iOS, Google Calendar, etc.).', 'clamp-events' ); ?></p>

			<h3><?php esc_html_e( 'Base feed URL', 'clamp-events' ); ?></h3>
			<p>
				<code><?php echo esc_html( $rest_base ); ?></code>
			</p>

			<h3><?php esc_html_e( 'All upcoming events', 'clamp-events' ); ?></h3>
			<p>
				<code><?php echo esc_html( $rest_base ); ?></code>
			</p>

			<h3><?php esc_html_e( 'Category-specific feeds', 'clamp-events' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Chapter', 'clamp-events' ); ?></strong><br />
					<code><?php echo esc_html( add_query_arg( 'category', 'chapter', $rest_base ) ); ?></code>
				</li>
				<li><strong><?php esc_html_e( 'Bastardos', 'clamp-events' ); ?></strong><br />
					<code><?php echo esc_html( add_query_arg( 'category', 'bastardos', $rest_base ) ); ?></code>
				</li>
				<li><strong><?php esc_html_e( 'BoBs', 'clamp-events' ); ?></strong><br />
					<code><?php echo esc_html( add_query_arg( 'category', 'bobs', $rest_base ) ); ?></code>
				</li>
			</ul>

			<h3><?php esc_html_e( 'Single-event ICS downloads', 'clamp-events' ); ?></h3>
			<p><?php esc_html_e( 'For a given event ID (for example, 123), the single-event .ics URL is:', 'clamp-events' ); ?></p>
			<p>
				<code><?php echo esc_html( add_query_arg( 'event_id', '123', $rest_base ) ); ?></code>
			</p>
			<p><?php esc_html_e( 'The shortcode automatically generates this URL for each event\'s "Add to Calendar" link.', 'clamp-events' ); ?></p>

			<hr />

			<h2><?php esc_html_e( 'Mobile Calendar Subscription Tips', 'clamp-events' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'On iOS, you can open the feed URL in Safari and choose "Subscribe" to add it to the Calendar app.', 'clamp-events' ); ?></li>
				<li><?php esc_html_e( 'In Google Calendar (web), choose "Add calendar" → "From URL" and paste the feed URL.', 'clamp-events' ); ?></li>
				<li><?php esc_html_e( 'Once subscribed, users can manage alerts/notifications in their calendar app settings.', 'clamp-events' ); ?></li>
			</ul>

			<p class="description">
				<?php esc_html_e( 'Note: Reminder notifications are ultimately controlled by each user\'s calendar app and device settings.', 'clamp-events' ); ?>
			</p>

			<hr />

			<h2><?php esc_html_e( 'JSON API for Cross-Site Display', 'clamp-events' ); ?></h2>

			<p><?php esc_html_e( 'For developers or advanced use, the plugin provides a JSON API endpoint that returns event data:', 'clamp-events' ); ?></p>

			<h3><?php esc_html_e( 'JSON API Endpoint', 'clamp-events' ); ?></h3>
			<p>
				<code><?php echo esc_html( rest_url( self::REST_NAMESPACE . '/events' ) ); ?></code>
			</p>

			<h3><?php esc_html_e( 'Parameters', 'clamp-events' ); ?></h3>
			<ul>
				<li><code>limit</code> – <?php esc_html_e( 'Number of events to return (default: 10)', 'clamp-events' ); ?></li>
				<li><code>category</code> – <?php esc_html_e( 'Filter by category slug', 'clamp-events' ); ?></li>
			</ul>

			<p><?php esc_html_e( 'This endpoint is used by the [clamp_events_remote] shortcode but can also be accessed directly for custom integrations.', 'clamp-events' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Add "Info" link to plugin row on Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_plugin_action_links( $links ) {
		$url   = admin_url( 'edit.php?post_type=' . self::CPT . '&page=clamp-events-info' );
		$info  = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Info', 'clamp-events' ) . '</a>';
		$links = (array) $links;
		array_unshift( $links, $info );
		return $links;
	}
}

new Clamp_Events_iCal_Feed();
