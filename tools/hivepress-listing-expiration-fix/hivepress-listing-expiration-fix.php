<?php
/**
 * Plugin Name: HivePress Listing Expiration Fix
 * Plugin URI: https://github.com/irapidchris-del/Persistent-Account-Menu-for-HivePress/tree/main/tools/hivepress-listing-expiration-fix
 * Description: Stops the hourly HivePress job from expiring, drafting and trashing listings that are not due, and restores the listings it already took down.
 * Version: 1.0.0
 * Author: ChrisB @ HivePress Community
 * Author URI: https://community.hivepress.io/u/chrisb/summary
 * Text Domain: hivepress-listing-expiration-fix
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
 *
 * @package HivePressListingExpirationFix
 */

namespace HivePressListingExpirationFix;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 */
const VERSION = '1.0.0';

/**
 * Number of listings processed per pass, matching HivePress core.
 */
const BATCH_SIZE = 10;

/**
 * Expiration timestamp meta key.
 */
const META_EXPIRED = 'hp_expired_time';

/**
 * Featuring timestamp meta key.
 */
const META_FEATURED = 'hp_featured_time';

/**
 * Option holding the last run report.
 */
const OPTION_REPORT = 'hplef_last_report';

/**
 * Option holding the restore log.
 */
const OPTION_RESTORED = 'hplef_restored';

/**
 * Maximum listings restored per click.
 */
const RESTORE_LIMIT = 200;

/**
 * Takes over the hourly expiration job from HivePress.
 *
 * HivePress builds the expiration queries from its own model field
 * definitions. When that machinery misbehaves the resulting query stops
 * being a filter and starts matching every live listing, and the job then
 * drafts and trashes ten of them an hour. Both known failures are silent,
 * so the job is replaced rather than patched around.
 */
function bootstrap() {
	if ( ! function_exists( 'hivepress' ) ) {
		return;
	}

	// HivePress reaches its components through __get() without a matching
	// __isset(), so the component has to be read before it can be tested.
	$component = hivepress()->listing;

	if ( ! is_object( $component ) || ! method_exists( $component, 'expire_listings' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_detach_notice' );

		return;
	}

	// Detach the core job. Nothing else is touched if this fails, because
	// running both jobs would leave the unsafe one in place anyway.
	if ( ! remove_action( 'hivepress/v1/events/hourly', [ $component, 'expire_listings' ] ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_detach_notice' );

		return;
	}

	add_action( 'hivepress/v1/events/hourly', __NAMESPACE__ . '\\expire_listings' );
}

add_action( 'hivepress/v1/setup', __NAMESPACE__ . '\\bootstrap' );

/**
 * Expires, stores and un-features listings that are genuinely due.
 *
 * Every candidate is checked twice: the SQL asks for an explicitly numeric
 * comparison against the stored timestamp, and the timestamp is then read
 * back and compared again in PHP before anything destructive happens.
 */
function expire_listings() {
	$now    = time();
	$report = [
		'time'       => $now,
		'expired'    => 0,
		'trashed'    => 0,
		'unfeatured' => 0,
		'skipped'    => 0,
	];

	// Expire listings whose expiration date has passed.
	foreach ( find_listings( [ 'pending', 'publish' ], META_EXPIRED, $now ) as $listing_id ) {
		$expired_time = read_timestamp( $listing_id, META_EXPIRED );

		if ( is_null( $expired_time ) || $expired_time > $now ) {
			++$report['skipped'];

			continue;
		}

		$listing = get_listing( $listing_id );

		if ( ! $listing ) {
			continue;
		}

		$listing->set_status( 'draft' )->save_status();

		send_expiration_email( $listing );

		++$report['expired'];
	}

	// Trash listings that have been expired for longer than the storage period.
	$storage_period = absint( get_option( 'hp_listing_storage_period' ) );

	if ( $storage_period ) {
		$cutoff = $now - DAY_IN_SECONDS * $storage_period;

		foreach ( find_listings( [ 'draft' ], META_EXPIRED, $cutoff ) as $listing_id ) {
			$expired_time = read_timestamp( $listing_id, META_EXPIRED );

			if ( is_null( $expired_time ) || $expired_time > $cutoff ) {
				++$report['skipped'];

				continue;
			}

			$listing = get_listing( $listing_id );

			if ( ! $listing ) {
				continue;
			}

			$listing->trash();

			++$report['trashed'];
		}
	}

	// Un-feature listings whose featuring period has passed.
	foreach ( find_listings( [ 'draft', 'pending', 'publish' ], META_FEATURED, $now ) as $listing_id ) {
		$featured_time = read_timestamp( $listing_id, META_FEATURED );

		if ( is_null( $featured_time ) || $featured_time > $now ) {
			++$report['skipped'];

			continue;
		}

		$listing = get_listing( $listing_id );

		if ( ! $listing ) {
			continue;
		}

		$listing->fill(
			[
				'featured'      => false,
				'featured_time' => null,
			]
		)->save( [ 'featured', 'featured_time' ] );

		++$report['unfeatured'];
	}

	update_option( OPTION_REPORT, $report, false );
}

/**
 * Finds listing IDs whose timestamp meta value is at or before a moment.
 *
 * The meta comparison is pinned to NUMERIC here. HivePress derives that
 * type from the field class registered for the model field, which is what
 * broke in 1.7.29: the type became DATE, MySQL could not read a Unix
 * timestamp as a date, and the comparison then matched every row.
 *
 * @param array  $statuses Post statuses to consider.
 * @param string $meta_key Timestamp meta key.
 * @param int    $before Latest timestamp to match.
 * @return array Listing IDs.
 */
function find_listings( $statuses, $meta_key, $before ) {
	return get_posts(
		[
			'post_type'           => get_post_type_name(),
			'post_status'         => $statuses,
			'posts_per_page'      => BATCH_SIZE,
			'orderby'             => 'rand',
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'suppress_filters'    => ! is_multilingual(),

			'meta_query'          => [
				[
					'key'     => $meta_key,
					'value'   => $before,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				],
			],
		]
	);
}

/**
 * Reads a HivePress timestamp meta value.
 *
 * HivePress stores these as Unix timestamps. Anything else, including an
 * empty string or a formatted date, is reported as absent so that the job
 * leaves the listing alone instead of reading it as long expired.
 *
 * @param int    $listing_id Listing ID.
 * @param string $meta_key Timestamp meta key.
 * @return int|null Timestamp, or null when unusable.
 */
function read_timestamp( $listing_id, $meta_key ) {
	$value = get_post_meta( $listing_id, $meta_key, true );

	if ( ! is_scalar( $value ) ) {
		return null;
	}

	$value = trim( (string) $value );

	if ( '' === $value || ! ctype_digit( $value ) ) {
		return null;
	}

	$value = (int) $value;

	return $value > 0 ? $value : null;
}

/**
 * Gets a HivePress listing object.
 *
 * @param int $listing_id Listing ID.
 * @return object|null
 */
function get_listing( $listing_id ) {
	if ( ! class_exists( '\HivePress\Models\Listing' ) ) {
		return null;
	}

	$listing = \HivePress\Models\Listing::query()->get_by_id( $listing_id );

	return $listing ? $listing : null;
}

/**
 * Sends the listing expiration email, as HivePress core does.
 *
 * @param object $listing Listing object.
 */
function send_expiration_email( $listing ) {
	if ( ! class_exists( '\HivePress\Emails\Listing_Expire' ) ) {
		return;
	}

	$user = $listing->get_user();

	if ( ! $user ) {
		return;
	}

	( new \HivePress\Emails\Listing_Expire(
		[
			'recipient' => $user->get_email(),

			'tokens'    => [
				'user'          => $user,
				'listing'       => $listing,
				'user_name'     => $user->get_display_name(),
				'listing_title' => $listing->get_title(),
				'listing_url'   => hivepress()->router->get_url( 'listing_edit_page', [ 'listing_id' => $listing->get_id() ] ),
			],
		]
	) )->send();
}

/**
 * Gets the listing post type name.
 *
 * @return string
 */
function get_post_type_name() {
	if ( class_exists( '\HivePress\Models\Listing' ) ) {
		$alias = \HivePress\Models\Listing::_get_meta( 'alias' );

		if ( $alias ) {
			return $alias;
		}
	}

	return 'hp_listing';
}

/**
 * Checks whether the site runs a multilingual plugin HivePress knows about.
 *
 * @return bool
 */
function is_multilingual() {
	if ( ! function_exists( 'hivepress' ) ) {
		return false;
	}

	$translator = hivepress()->translator;

	return is_object( $translator ) && method_exists( $translator, 'is_multilingual' ) && $translator->is_multilingual();
}

/**
 * Describes how HivePress would compare the expiration timestamp in SQL.
 *
 * @return array {
 *     @type string $state One of `safe`, `unsafe` or `missing`.
 *     @type string $type Meta comparison type, empty when the field is missing.
 * }
 */
function inspect_expiration_field() {
	if ( ! class_exists( '\HivePress\Models\Listing' ) ) {
		return [
			'state' => 'missing',
			'type'  => '',
		];
	}

	$fields = ( new \HivePress\Models\Listing() )->_get_fields();
	$field  = isset( $fields['expired_time'] ) ? $fields['expired_time'] : null;

	// A field HivePress cannot build is dropped from the model, and the
	// query builder then drops the filter that depends on it without a
	// word, leaving the destructive job with no expiration condition.
	if ( ! $field ) {
		return [
			'state' => 'missing',
			'type'  => '',
		];
	}

	$type = (string) $field::get_meta( 'type' );

	return [
		'state' => in_array( $type, [ 'DECIMAL', 'NUMERIC', 'SIGNED', 'UNSIGNED' ], true ) ? 'safe' : 'unsafe',
		'type'  => $type,
	];
}

/**
 * Queries listings that were taken down while their expiration date is still ahead.
 *
 * @param int $limit Maximum listings to return.
 * @param int $offset Number of listings to skip.
 * @return \WP_Query
 */
function query_wrongly_expired( $limit, $offset = 0 ) {
	return new \WP_Query(
		[
			'post_type'           => get_post_type_name(),
			'post_status'         => [ 'draft', 'trash' ],
			'posts_per_page'      => $limit,
			'offset'              => $offset,
			'orderby'             => 'modified',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'suppress_filters'    => ! is_multilingual(),

			'meta_query'          => [
				[
					'key'     => META_EXPIRED,
					'value'   => time(),
					'compare' => '>',
					'type'    => 'NUMERIC',
				],
			],
		]
	);
}

/**
 * Republishes listings that were taken down while still within their expiration date.
 *
 * HivePress's own status-change hooks are suspended for the duration.
 * Republishing is a repair, not a submission: it must not re-send approval
 * emails, and on sites running Paid Listings it must not spend another
 * listing from the owner's package.
 *
 * @return array {
 *     @type int $restored Number of listings republished.
 *     @type int $remaining Number of listings still waiting.
 * }
 */
function restore_listings() {
	global $wp_filter;

	$query = query_wrongly_expired( RESTORE_LIMIT );
	$ids   = wp_list_pluck( $query->posts, 'ID' );
	$log   = get_option( OPTION_RESTORED, [] );

	if ( ! is_array( $log ) ) {
		$log = [];
	}

	// Suspend the listing hooks for the length of the repair. Saving and
	// putting back the whole hook object is the only way to do this without
	// knowing every callback other plugins have registered.
	$hooks = [ 'hivepress/v1/models/listing/update', 'hivepress/v1/models/listing/update_status' ];
	$saved = [];

	foreach ( $hooks as $hook ) {
		if ( isset( $wp_filter[ $hook ] ) ) {
			$saved[ $hook ] = $wp_filter[ $hook ];

			unset( $wp_filter[ $hook ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	$restored = 0;

	foreach ( $ids as $listing_id ) {
		$status = get_post_status( $listing_id );

		if ( 'trash' === $status ) {
			wp_untrash_post( $listing_id );
		}

		if ( 'publish' !== get_post_status( $listing_id ) ) {
			wp_update_post(
				[
					'ID'          => $listing_id,
					'post_status' => 'publish',
				]
			);
		}

		$log[] = [
			'id'   => $listing_id,
			'from' => $status,
			'time' => time(),
		];

		++$restored;
	}

	// Put the hooks back exactly as they were.
	foreach ( $saved as $hook => $callbacks ) {
		$wp_filter[ $hook ] = $callbacks; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	update_option( OPTION_RESTORED, array_slice( $log, -500 ), false );

	return [
		'restored'  => $restored,
		'remaining' => query_wrongly_expired( 1 )->found_posts,
	];
}

/**
 * Registers the tools screen.
 */
function register_page() {
	add_management_page(
		__( 'Listing Expiration Fix', 'hivepress-listing-expiration-fix' ),
		__( 'Listing Expiration Fix', 'hivepress-listing-expiration-fix' ),
		'manage_options',
		'hplef',
		__NAMESPACE__ . '\\render_page'
	);
}

add_action( 'admin_menu', __NAMESPACE__ . '\\register_page' );

/**
 * Renders the tools screen.
 */
function render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$result = null;

	if ( isset( $_POST['hplef_restore'] ) && check_admin_referer( 'hplef_restore' ) ) {
		$result = restore_listings();
	}

	$field    = inspect_expiration_field();
	$affected = query_wrongly_expired( 20 );
	$report   = get_option( OPTION_REPORT, [] );

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Listing Expiration Fix', 'hivepress-listing-expiration-fix' ) . '</h1>';

	if ( $result ) {
		echo '<div class="notice notice-success"><p>';
		printf(
			/* translators: 1: number of listings republished, 2: number still waiting. */
			esc_html__( 'Republished %1$s listings. %2$s still waiting.', 'hivepress-listing-expiration-fix' ),
			esc_html( number_format_i18n( $result['restored'] ) ),
			esc_html( number_format_i18n( $result['remaining'] ) )
		);
		echo '</p></div>';
	}

	// The state of the expiration filter HivePress itself would build.
	echo '<h2>' . esc_html__( 'This site', 'hivepress-listing-expiration-fix' ) . '</h2>';
	echo '<table class="widefat striped" style="max-width:60em"><tbody>';

	$messages = [
		'safe'    => __( 'HivePress compares expiration dates as numbers, which is correct.', 'hivepress-listing-expiration-fix' ),
		'unsafe'  => __( 'HivePress compares expiration dates as calendar dates, which matches every listing. This is the 1.7.29 fault.', 'hivepress-listing-expiration-fix' ),
		'missing' => __( 'HivePress cannot build the expiration field, so its own expiration filter would be dropped and every live listing would match.', 'hivepress-listing-expiration-fix' ),
	];

	render_row(
		__( 'HivePress expiration filter', 'hivepress-listing-expiration-fix' ),
		$messages[ $field['state'] ] . ( $field['type'] ? ' (' . $field['type'] . ')' : '' )
	);

	render_row(
		__( 'Hourly job', 'hivepress-listing-expiration-fix' ),
		has_action( 'hivepress/v1/events/hourly', __NAMESPACE__ . '\\expire_listings' )
			? __( 'Handled by this plugin, so listings that are not due are left alone.', 'hivepress-listing-expiration-fix' )
			: __( 'Still handled by HivePress. This plugin could not take it over.', 'hivepress-listing-expiration-fix' )
	);

	if ( $report && isset( $report['time'] ) ) {
		render_row(
			__( 'Last run', 'hivepress-listing-expiration-fix' ),
			sprintf(
				/* translators: 1: date and time, 2: expired count, 3: trashed count, 4: skipped count. */
				__( '%1$s — expired %2$s, trashed %3$s, left alone %4$s', 'hivepress-listing-expiration-fix' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $report['time'] ),
				number_format_i18n( $report['expired'] ),
				number_format_i18n( $report['trashed'] ),
				number_format_i18n( $report['skipped'] )
			)
		);
	}

	echo '</tbody></table>';

	// Listings taken down before their time.
	echo '<h2>' . esc_html__( 'Listings taken down early', 'hivepress-listing-expiration-fix' ) . '</h2>';

	if ( ! $affected->found_posts ) {
		echo '<p>' . esc_html__( 'No draft or trashed listing has an expiration date still ahead of it. There is nothing to put back.', 'hivepress-listing-expiration-fix' ) . '</p>';
		echo '</div>';

		return;
	}

	echo '<p>';
	printf(
		/* translators: %s: number of listings. */
		esc_html__( '%s listings are in draft or in the trash even though their expiration date has not arrived yet.', 'hivepress-listing-expiration-fix' ),
		'<strong>' . esc_html( number_format_i18n( $affected->found_posts ) ) . '</strong>'
	);
	echo '</p>';

	echo '<p>' . esc_html__( 'Republishing sets them back to Published without re-sending approval emails and without spending a listing from the owner\'s package. Check the list first: a listing you hid yourself will be republished too if its expiration date is still ahead.', 'hivepress-listing-expiration-fix' ) . '</p>';

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html__( 'Listing', 'hivepress-listing-expiration-fix' ) . '</th>';
	echo '<th>' . esc_html__( 'Status', 'hivepress-listing-expiration-fix' ) . '</th>';
	echo '<th>' . esc_html__( 'Expires', 'hivepress-listing-expiration-fix' ) . '</th>';
	echo '<th>' . esc_html__( 'Last changed', 'hivepress-listing-expiration-fix' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $affected->posts as $post ) {
		$expired_time = read_timestamp( $post->ID, META_EXPIRED );

		echo '<tr>';
		echo '<td><a href="' . esc_url( (string) get_edit_post_link( $post->ID ) ) . '">' . esc_html( get_the_title( $post->ID ) ) . '</a></td>';
		echo '<td>' . esc_html( $post->post_status ) . '</td>';
		echo '<td>' . esc_html( $expired_time ? wp_date( get_option( 'date_format' ), $expired_time ) : '—' ) . '</td>';
		echo '<td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) get_post_timestamp( $post, 'modified' ) ) ) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	if ( $affected->found_posts > $affected->post_count ) {
		echo '<p><em>' . esc_html__( 'Only the most recently changed listings are shown.', 'hivepress-listing-expiration-fix' ) . '</em></p>';
	}

	echo '<form method="post">';
	wp_nonce_field( 'hplef_restore' );
	echo '<p><button type="submit" name="hplef_restore" value="1" class="button button-primary">';
	printf(
		/* translators: %s: number of listings republished per click. */
		esc_html__( 'Republish up to %s listings', 'hivepress-listing-expiration-fix' ),
		esc_html( number_format_i18n( RESTORE_LIMIT ) )
	);
	echo '</button></p>';
	echo '</form>';

	echo '</div>';
}

/**
 * Renders one row of the status table.
 *
 * @param string $label Row label.
 * @param string $value Row value.
 */
function render_row( $label, $value ) {
	echo '<tr><td style="width:16em"><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( $value ) . '</td></tr>';
}

/**
 * Warns that the core job could not be taken over.
 */
function render_detach_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'HivePress Listing Expiration Fix could not take over the hourly expiration job, so it is not protecting this site. HivePress may have been changed since this plugin was written.', 'hivepress-listing-expiration-fix' );
	echo '</p></div>';
}
