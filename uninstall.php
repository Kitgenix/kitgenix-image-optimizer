<?php
/**
 * Uninstall handler.
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Delete plugin options.
delete_option( 'kitgenix_image_optimizer_settings' );
delete_option( 'kitgenix_image_optimizer_event_log' );
delete_transient( 'kitgenix_image_optimizer_do_activation_redirect' );

// Delete per-attachment optimization meta for all attachments.
global $wpdb;
if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_kitgenix_image_optimizer' ) );
}
