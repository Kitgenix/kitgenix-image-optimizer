<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands for Kitgenix Image Optimizer.
 *
 * Usage:
 *   wp kitgenix-optimizer status              Server capability + queue diagnostics.
 *   wp kitgenix-optimizer optimize             Optimize all unoptimized images.
 *   wp kitgenix-optimizer optimize --id=123    Optimize a single attachment.
 *   wp kitgenix-optimizer optimize --force     Re-optimize images that were already processed.
 *   wp kitgenix-optimizer stats                Show optimization statistics.
 *   wp kitgenix-optimizer restore --id=123     Restore a single image from backup.
 *   wp kitgenix-optimizer queue <action>       Manage the background bulk queue.
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
	 * Show server capability and queue diagnostics – the same data shown in
	 * the admin Diagnostics tab and Site Health.
	 *
	 * ## EXAMPLES
	 *
	 *   wp kitgenix-optimizer status
	 *
	 * @subcommand status
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		$report = Capabilities::get_report();

		\WP_CLI::log( sprintf( 'GD: %s', $report['gd_available'] ? ( $report['gd_version'] ?: 'available' ) : 'not available' ) );
		\WP_CLI::log( sprintf( 'Imagick: %s', $report['imagick_available'] ? ( $report['imagick_version'] ?: 'available' ) : 'not available' ) );
		\WP_CLI::log( sprintf( 'Active editor: %s', $report['preferred_editor'] ) );
		\WP_CLI::log( sprintf( 'PHP memory_limit: %s', $report['memory_limit_text'] ) );
		\WP_CLI::log( '' );

		$rows = [];
		foreach ( $report['formats'] as $format => $data ) {
			$rows[] = [
				'Format'       => strtoupper( $format ),
				'Decode'       => $data['decode'] ? 'yes' : 'no',
				'Encode'       => $data['encode'] ? 'yes' : 'no',
				'WP supported' => $data['wp_supported'] ? 'yes' : 'no',
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'Format', 'Decode', 'Encode', 'WP supported' ] );

		\WP_CLI::log( '' );
		$queue = Queue::progress();
		\WP_CLI::log( sprintf(
			'Queue: status=%s mode=%s processed=%d failed=%d retry_pending=%d',
			$queue['status'],
			$queue['mode'],
			$queue['processed'],
			$queue['failed_count'],
			$queue['retry_pending']
		) );
	}

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
			'post_mime_type' => Settings::OPTIMIZABLE_MIMES,
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
			$result = Optimizer::optimize_attachment( $attachment_id, $force );

			if ( is_wp_error( $result ) ) {
				if ( in_array( $result->get_error_code(), [ 'animated_gif_skipped', 'excluded_by_rule' ], true ) ) {
					++$skipped;
				} else {
					++$errors;
					\WP_CLI::warning( sprintf(
						/* translators: 1: attachment ID, 2: error message */
						__( 'ID %1$d – %2$s', 'kitgenix-image-optimizer' ),
						$attachment_id,
						$result->get_error_message()
					) );
				}
			} else {
				++$optimized;
			}

			$progress->tick();
		}

		$progress->finish();

		\WP_CLI::success( sprintf(
			/* translators: 1: optimized count, 2: total, 3: skipped by design, 4: errors */
			__( 'Done. Optimized %1$d of %2$d images. Skipped by design: %3$d. Errors: %4$d.', 'kitgenix-image-optimizer' ),
			$optimized,
			$total,
			$skipped,
			$errors
		) );
	}

	/**
	 * Manage the background bulk queue (the same one driving the admin
	 * Images tab's "Start Bulk Optimization" control).
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One of: start, pause, resume, stop, retry, status.
	 *
	 * [--mode=<mode>]
	 * : For `start`: bulk (default, unoptimized only) or reoptimize (every eligible image).
	 *
	 * ## EXAMPLES
	 *
	 *   wp kitgenix-optimizer queue start
	 *   wp kitgenix-optimizer queue start --mode=reoptimize
	 *   wp kitgenix-optimizer queue pause
	 *   wp kitgenix-optimizer queue status
	 *
	 * @subcommand queue
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function queue( array $args, array $assoc_args ): void {
		$action = isset( $args[0] ) ? strtolower( (string) $args[0] ) : '';

		switch ( $action ) {
			case 'start':
				$mode = isset( $assoc_args['mode'] ) ? (string) $assoc_args['mode'] : 'bulk';
				$state = Queue::start( $mode );
				\WP_CLI::success( sprintf( 'Queue started (mode: %s).', $state['mode'] ) );
				break;

			case 'pause':
				Queue::pause();
				\WP_CLI::success( 'Queue paused.' );
				break;

			case 'resume':
				Queue::resume();
				\WP_CLI::success( 'Queue resumed.' );
				break;

			case 'stop':
				Queue::stop();
				\WP_CLI::success( 'Queue stopped and reset.' );
				break;

			case 'retry':
				$state = Queue::retry_failed();
				\WP_CLI::success( sprintf( '%d failed item(s) re-queued.', count( $state['retry_ids'] ?? [] ) ) );
				break;

			case 'status':
			case '':
				$progress = Queue::progress();
				foreach ( $progress as $key => $value ) {
					if ( is_scalar( $value ) ) {
						\WP_CLI::log( sprintf( '%s: %s', $key, (string) $value ) );
					}
				}
				break;

			default:
				\WP_CLI::error( 'Unknown queue action. Use one of: start, pause, resume, stop, retry, status.' );
		}
	}

	/**
	 * Show optimization statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--recalculate]
	 * : Force a full recount from postmeta instead of reading the cached aggregate.
	 *
	 * ## EXAMPLES
	 *
	 *   wp kitgenix-optimizer stats
	 *   wp kitgenix-optimizer stats --recalculate
	 *
	 * @subcommand stats
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function stats( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['recalculate'] ) ) {
			Stats::recalculate();
		}

		$total       = Optimizer::count_total_optimizable_attachments();
		$optimized   = Optimizer::count_optimized_attachments();
		$unoptimized = max( 0, $total - $optimized );
		$savings     = Optimizer::get_total_savings_bytes();
		$failed      = Stats::failed_count();
		$pct         = $total > 0 ? round( ( $optimized / $total ) * 100, 1 ) : 0.0;

		$rows = [
			[ 'Metric', 'Value' ],
			[ __( 'Total images', 'kitgenix-image-optimizer' ), number_format_i18n( $total ) ],
			[ __( 'Optimized', 'kitgenix-image-optimizer' ), number_format_i18n( $optimized ) . ' (' . $pct . '%)' ],
			[ __( 'Pending', 'kitgenix-image-optimizer' ), number_format_i18n( $unoptimized ) ],
			[ __( 'Failed', 'kitgenix-image-optimizer' ), number_format_i18n( $failed ) ],
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
			return;
		}

		$result = Optimizer::restore_attachment( $id );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( sprintf(
				/* translators: 1: attachment ID, 2: error */
				__( 'ID %1$d – %2$s', 'kitgenix-image-optimizer' ),
				$id,
				$result->get_error_message()
			) );
			return;
		}

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
		// An explicit --id=<id> is always a deliberate, single-target request –
		// force a fresh pass regardless of any unchanged fingerprint.
		$result = Optimizer::optimize_attachment( $id, true );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( sprintf(
				/* translators: 1: attachment ID, 2: error */
				__( 'ID %1$d – %2$s', 'kitgenix-image-optimizer' ),
				$id,
				$result->get_error_message()
			) );
			return;
		}
		\WP_CLI::success( sprintf(
			/* translators: 1: attachment ID, 2: space saved */
			__( 'Attachment #%1$d optimized. Saved %2$s.', 'kitgenix-image-optimizer' ),
			$id,
			size_format( (int) ( $result['savings_bytes'] ?? 0 ) )
		) );
	}
}
