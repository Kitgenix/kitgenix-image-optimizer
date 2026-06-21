<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands for Kitgenix Image Optimizer.
 *
 * Usage:
 *   wp kitgenix-optimizer optimize            Optimize all unoptimized images.
 *   wp kitgenix-optimizer optimize --id=123   Optimize a single attachment.
 *   wp kitgenix-optimizer stats               Show optimization statistics.
 *   wp kitgenix-optimizer restore --id=123    Restore a single image from backup.
 */
final class CLI {

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'kitgenix-optimizer', self::class );
	}

	// -------------------------------------------------------------------------
	// Commands
	// -------------------------------------------------------------------------

	/**
	 * Optimize images in the media library.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<id>]
	 * : Attachment ID to optimize. If omitted, all unoptimized images are processed.
	 *
	 * [--force]
	 * : Re-optimize images that have already been optimized.
	 *
	 * ## EXAMPLES
	 *
	 *   wp kitgenix-optimizer optimize
	 *   wp kitgenix-optimizer optimize --id=42
	 *   wp kitgenix-optimizer optimize --force
	 *
	 * @subcommand optimize
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function optimize( array $args, array $assoc_args ): void {
		$id    = isset( $assoc_args['id'] ) ? absint( $assoc_args['id'] ) : 0;
		$force = isset( $assoc_args['force'] );

		if ( $id > 0 ) {
			$this->optimize_single( $id );
			return;
		}

		$query_args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
		];

		if ( ! $force ) {
			$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => Optimizer::META_KEY,
					'compare' => 'NOT EXISTS',
				],
			];
		}

		$query = new \WP_Query( $query_args );
		$ids   = is_array( $query->posts ) ? array_map( 'absint', $query->posts ) : [];
		$total = count( $ids );

		if ( 0 === $total ) {
			\WP_CLI::success( __( 'All images are already optimized.', 'kitgenix-image-optimizer' ) );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar(
			sprintf( /* translators: %d: total image count */ __( 'Optimizing %d images…', 'kitgenix-image-optimizer' ), $total ),
			$total
		);

		$optimized = 0;
		$skipped   = 0;
		$errors    = 0;

		foreach ( $ids as $attachment_id ) {
			$result = Optimizer::optimize_attachment( $attachment_id );

			if ( is_wp_error( $result ) ) {
				++$errors;
				\WP_CLI::warning( sprintf(
					/* translators: 1: attachment ID, 2: error message */
					__( 'ID %1$d — %2$s', 'kitgenix-image-optimizer' ),
					$attachment_id,
					$result->get_error_message()
				) );
			} else {
				++$optimized;
			}

			$progress->tick();
		}

		$progress->finish();

		\WP_CLI::success( sprintf(
			/* translators: 1: optimized count, 2: total, 3: errors */
			__( 'Done. Optimized %1$d of %2$d images. Errors: %3$d.', 'kitgenix-image-optimizer' ),
			$optimized,
			$total,
			$errors
		) );
	}

	/**
	 * Show optimization statistics.
	 *
	 * ## EXAMPLES
	 *
	 *   wp kitgenix-optimizer stats
	 *
	 * @subcommand stats
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function stats( array $args, array $assoc_args ): void {
		$total       = Optimizer::count_total_optimizable_attachments();
		$optimized   = Optimizer::count_optimized_attachments();
		$unoptimized = max( 0, $total - $optimized );
		$savings     = Optimizer::get_total_savings_bytes();
		$pct         = $total > 0 ? round( ( $optimized / $total ) * 100, 1 ) : 0.0;

		$rows = [
			[ 'Metric', 'Value' ],
			[ __( 'Total images', 'kitgenix-image-optimizer' ), number_format_i18n( $total ) ],
			[ __( 'Optimized', 'kitgenix-image-optimizer' ), number_format_i18n( $optimized ) . ' (' . $pct . '%)' ],
			[ __( 'Pending', 'kitgenix-image-optimizer' ), number_format_i18n( $unoptimized ) ],
			[ __( 'Total space saved', 'kitgenix-image-optimizer' ), size_format( $savings ) ],
		];

		$header = array_shift( $rows );
		\WP_CLI\Utils\format_items( 'table', $rows, $header );
	}

	/**
	 * Restore a single image from its backup.
	 *
	 * ## OPTIONS
	 *
	 * --id=<id>
	 * : Attachment ID to restore.
	 *
	 * ## EXAMPLES
	 *
	 *   wp kitgenix-optimizer restore --id=42
	 *
	 * @subcommand restore
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function restore( array $args, array $assoc_args ): void {
		$id = isset( $assoc_args['id'] ) ? absint( $assoc_args['id'] ) : 0;
		if ( ! $id ) {
			\WP_CLI::error( __( 'Please provide --id=<attachment_id>.', 'kitgenix-image-optimizer' ) );
		}

		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			\WP_CLI::error( __( 'Attachment file not found.', 'kitgenix-image-optimizer' ) );
		}

		$backup = (string) preg_replace( '/(\.[^.]+)$/', '-kitgenix-original$1', (string) $file );
		if ( ! file_exists( $backup ) ) {
			\WP_CLI::error( __( 'No backup file found. Was "Backup Originals" enabled when this image was optimized?', 'kitgenix-image-optimizer' ) );
		}

		if ( ! copy( $backup, (string) $file ) ) {
			\WP_CLI::error( __( 'Could not restore backup file.', 'kitgenix-image-optimizer' ) );
		}

		delete_post_meta( $id, Optimizer::META_KEY );
		delete_transient( 'kitgenix_image_optimizer_total_savings' );

		\WP_CLI::success( sprintf(
			/* translators: %d: attachment ID */
			__( 'Attachment #%d restored successfully.', 'kitgenix-image-optimizer' ),
			$id
		) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function optimize_single( int $id ): void {
		$result = Optimizer::optimize_attachment( $id );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( sprintf(
				/* translators: 1: attachment ID, 2: error */
				__( 'ID %1$d — %2$s', 'kitgenix-image-optimizer' ),
				$id,
				$result->get_error_message()
			) );
		}
		\WP_CLI::success( sprintf(
			/* translators: 1: attachment ID, 2: space saved */
			__( 'Attachment #%1$d optimized. Saved %2$s.', 'kitgenix-image-optimizer' ),
			$id,
			size_format( (int) ( $result['savings_bytes'] ?? 0 ) )
		) );
	}
}
