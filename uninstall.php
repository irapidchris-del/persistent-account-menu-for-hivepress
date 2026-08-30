<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen, never on deactivation, so
 * switching the plugin off temporarily loses nothing at all.
 *
 * **Deleting the plugin keeps the owner's settings by default.** Someone who deletes a
 * plugin by accident, or removes it to install a clean copy, gets their menu choices and
 * button wording back when they reinstall. Destruction is opt-in, through the "Delete All
 * Data" checkbox in the Removing the Plugin section of the settings tab, and is never a
 * surprise.
 *
 * There is no way to ask at delete time. The confirmation form in
 * wp-admin/plugins.php:398-410 is hard-coded with no do_action or apply_filters inside it,
 * so a checkbox cannot be added to that screen; the setting has to live on our own tab.
 * Worse, WordPress prints "(will also delete its data)" on that screen whenever an
 * uninstall.php exists at all (wp-admin/plugins.php:376-380), whatever the file actually
 * does, so the setting's own description tells the owner that the core warning does not
 * apply unless they ticked the box.
 *
 * The cached release lookup goes either way, because it is regenerable runtime junk rather
 * than anything the owner made. The updater schedules a background release refresh, cleared below.
 *
 * @package PersistentAccountMenu
 */

// Exit if accessed directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Read the owner's choice first, before anything is touched.
$hppam_delete_all = (bool) get_option( 'hp_hppam_delete_data' );

/*
 * ---------------------------------------------------------------------------------------
 * Always cleaned, whichever way the setting is set.
 * ---------------------------------------------------------------------------------------
 */

// The updater's cached release lookup. A site transient lives under its own prefix, so
// neither the option sweep below nor a plain delete_option() would ever reach it.
delete_site_transient( 'hppam_github_release' );

/*
 * The updater's background release refresh, which used to be left scheduled.
 *
 * It is a queued job whose callback stops existing the moment the plugin does, so it is worse
 * than debris: cron keeps firing a hook nothing answers. Unscheduled from both places it can
 * live, because the refresh is queued through HivePress's scheduler (Action Scheduler) when
 * HivePress is present and through WP-Cron when it is not.
 *
 * The updater's other site transients go the same way. Core's daily sweep clears expired site
 * transients within about a day on single-site, which is why leaving them read as harmless; on
 * multisite they live in wp_sitemeta and are only purged when something asks for them.
 */
delete_site_transient( 'hppam_github_release_reason' );
delete_site_transient( 'hppam_github_release_rate_limit' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'hppam_github_release_refresh', [], 'hivepress' );
	as_unschedule_all_actions( 'hppam_github_release_refresh' );
}

wp_clear_scheduled_hook( 'hppam_github_release_refresh' );

// The retirement notice's stored dismissal. Deleted whichever way the "delete all data"
// setting is set: it is a record of an interface being dismissed, not anything the owner
// configured, and it sits outside the hp_hppam_ namespace the option sweep below matches,
// so nothing else would ever reach it.
delete_option( 'hppam_retirement_dismissed' );

// Any other transient the plugin has ever set. Nothing writes one today, but a transient is
// stored as "_transient_{name}" plus a separate "_transient_timeout_{name}" row, so the
// prefix sweep used for options further down cannot match them: it anchors on "hp_hppam" at
// the start of the name. Leaving a timeout row behind with no value row is the classic
// orphan.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$hppam_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $wpdb->esc_like( 'hppam' ) . '%',
		'_transient_timeout_' . $wpdb->esc_like( 'hppam' ) . '%'
	)
);

foreach ( (array) $hppam_transients as $hppam_transient_name ) {
	delete_option( $hppam_transient_name );
}

/*
 * ---------------------------------------------------------------------------------------
 * Everything below happens only when the owner asked for it.
 * ---------------------------------------------------------------------------------------
 */

if ( $hppam_delete_all ) {

	// Delete the options. The names are matched on the plugin's prefix because two of them
	// are dynamic: one button label and one button URL per managed menu item, and which
	// items exist depends on the extensions that were active. This runs once, while the
	// plugin is being deleted, so there is nothing worth caching.
	//
	// The "delete all data" option itself is excluded here and removed at the very end. If
	// this run fails part-way through, the flag is still set, so a second attempt finishes
	// the job. Sweeping it away first would silently flip the site back to "retain" with
	// half the settings still lying around.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$hppam_option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s",
			$wpdb->esc_like( 'hp_hppam' ) . '%',
			'hp_hppam_delete_data'
		)
	);

	foreach ( (array) $hppam_option_names as $hppam_option_name ) {
		delete_option( $hppam_option_name );
	}

	// Last, and only once everything above has succeeded.
	delete_option( 'hp_hppam_delete_data' );
}
