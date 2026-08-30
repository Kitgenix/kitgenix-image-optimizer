<?php
/**
 * Uninstall handler.
 *
 * Runs in isolation (WordPress does not bootstrap the plugin's classes for
 * the standalone uninstall.php convention), so this file is self-contained
 * and never touches a user's actual Media Library images – only plugin
 * options, plugin-generated postmeta, and (only if the site owner opted in
 * via Settings → Uninstall) plugin-generated backup/sidecar files.
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kitgenix_settings = get_option( 'kitgenix_image_optimizer_settings' );
$kitgenix_settings = is_array( $kitgenix_settings ) ? $kitgenix_settings : [];

$kitgenix_keep_data       = ! empty( $kitgenix_settings['keep_data_on_uninstall'] );
$kitgenix_delete_backups  = ! empty( $kitgenix_settings['delete_backups_on_uninstall'] );

// Site owner explicitly asked to keep settings, logs, and per-image data.
// Backup files on disk are also left alone in this case.
if ( $kitgenix_keep_data ) {
	return;
}

// Delete plugin options.
delete_option( 'kitgenix_image_optimizer_settings' );
delete_option( 'kitgenix_image_optimizer_event_log' );
delete_option( 'kitgenix_image_optimizer_stats' );
delete_option( 'kitgenix_image_optimizer_queue' );
delete_option( 'kitgenix_image_optimizer_db_version' );
delete_transient( 'kitgenix_image_optimizer_do_activation_redirect' );

$kitgenix_cron_hook = 'kitgenix_image_optimizer_queue_tick';
$kitgenix_timestamp = wp_next_scheduled( $kitgenix_cron_hook );
if ( $kitgenix_timestamp ) {
	wp_unschedule_event( $kitgenix_timestamp, $kitgenix_cron_hook );
}
wp_clear_scheduled_hook( 'kitgenix_image_optimizer_backfill_stats' );

// Delete per-attachment optimization meta for all attachments.
global $wpdb;
if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_kitgenix_image_optimizer' ) );
}

// Backup files (`photo-kitgenix-original.jpg`) and format sidecars
// (`photo.jpg.webp`, `photo.jpg.avif`) are plugin-generated derivatives, not
// the user's actual Media Library files – but since a backup may be the
// only remaining copy of an image's pre-optimization bytes, they are only
// removed when the site owner explicitly enabled "Delete backups on
// uninstall" in Settings. The live attached files themselves are never
// touched by uninstall, regardless of this setting.
if ( $kitgenix_delete_backups ) {
	$kitgenix_paged = 1;

	do {
		$kitgenix_query = new WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ],
			'posts_per_page' => 500,
			'paged'          => $kitgenix_paged,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );

		$kitgenix_ids = $kitgenix_query->posts;

		foreach ( $kitgenix_ids as $kitgenix_attachment_id ) {
			$kitgenix_file = get_attached_file( (int) $kitgenix_attachment_id );
			if ( ! $kitgenix_file ) {
				continue;
			}

			$kitgenix_backup = (string) preg_replace( '/(\.([^.]+))$/', '-kitgenix-original$1', $kitgenix_file );
			if ( $kitgenix_backup && $kitgenix_backup !== $kitgenix_file && file_exists( $kitgenix_backup ) ) {
				wp_delete_file( $kitgenix_backup );
			}

			foreach ( [ 'webp', 'avif' ] as $kitgenix_ext ) {
				$kitgenix_sidecar = $kitgenix_file . '.' . $kitgenix_ext;
				if ( file_exists( $kitgenix_sidecar ) ) {
					wp_delete_file( $kitgenix_sidecar );
				}
			}
		}

		++$kitgenix_paged;
	} while ( count( $kitgenix_ids ) === 500 );
}
