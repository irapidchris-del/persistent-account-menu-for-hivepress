<?php
/**
 * Removes the plugin's stored data.
 *
 * @package HivePressListingExpirationFix
 */

// Exit if accessed directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'hplef_last_report' );
delete_option( 'hplef_restored' );
