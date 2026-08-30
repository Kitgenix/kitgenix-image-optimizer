<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded, resumable bulk-optimization queue.
 *
 * Replaces the previous "browser tab drives an AJAX loop, one attachment per
 * request" approach with a small persisted state machine:
 *
 *  - status:     idle | running | paused | completed
 *  - mode:       bulk (only unoptimized images) | reoptimize (every eligible image)
 *  - cursor:     last attachment ID processed, used only in reoptimize mode to
 *                walk forward through the library without an offset that
 *                drifts when attachments are added/removed mid-run
 *  - failed:     capped list of {id, code, message, time} for explicit retry
 *  - retry_ids:  specific IDs queued by "Retry failed", processed before
 *                falling back to the normal scan
 *
 * The AJAX "next" endpoint and a WP-Cron heartbeat both call `advance()`, so
 * a bulk run keeps making progress even if the admin closes the browser tab –
 * without pulling in a job-queue library the plugin doesn't otherwise need.
 * A short transient lock stops the two triggers from processing the same
 * item twice at once.
 */
final class Queue {
	private const OPTION_KEY   = 'kitgenix_image_optimizer_queue';
	private const LOCK_KEY     = 'kitgenix_image_optimizer_queue_lock';
	private const CRON_HOOK    = 'kitgenix_image_optimizer_queue_tick';
	private const CRON_SCHEDULE = 'kitgenix_one_minute';
	private const MAX_FAILED_ENTRIES = 200;
	/** Wall-clock budget per cron tick, so a shared host's cron runner never stalls. */
	private const CRON_BATCH_SECONDS = 20;

	public static function init(): void {
		add_filter( 'cron_schedules', [ self::class, 'register_cron_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::CRON_HOOK, [ self::class, 'run_cron_batch' ] );

		add_action( 'wp_ajax_kitgenix_image_optimizer_queue_start', [ self::class, 'ajax_start' ] );
		add_action( 'wp_ajax_kitgenix_image_optimizer_queue_next', [ self::class, 'ajax_next' ] );
		add_action( 'wp_ajax_kitgenix_image_optimizer_queue_pause', [ self::class, 'ajax_pause' ] );
		add_action( 'wp_ajax_kitgenix_image_optimizer_queue_resume', [ self::class, 'ajax_resume' ] );
		add_action( 'wp_ajax_kitgenix_image_optimizer_queue_stop', [ self::class, 'ajax_stop' ] );
		add_action( 'wp_ajax_kitgenix_image_optimizer_queue_retry', [ self::class, 'ajax_retry' ] );
		add_action( 'wp_ajax_kitgenix_image_optimizer_queue_status', [ self::class, 'ajax_status' ] );
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_cron_schedule( array $schedules ): array {
		$schedules[ self::CRON_SCHEDULE ] = [
			'interval' => 60,
			'display'  => __( 'Every minute (Kitgenix Image Optimizer queue)', 'kitgenix-image-optimizer' ),
		];
		return $schedules;
	}

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	public static function get_state(): array {
		$state = get_option( self::OPTION_KEY, null );
		return array_merge( self::default_state(), is_array( $state ) ? $state : [] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function default_state(): array {
		return [
			'status'         => 'idle',
			'mode'           => 'bulk',
			'cursor'         => 0,
			'total_snapshot' => 0,
			'processed'      => 0,
			'failed'         => [],
			'retry_ids'      => [],
			'started_at'     => 0,
			'updated_at'     => 0,
			'completed_at'   => 0,
		];
	}

	private static function save_state( array $state ): void {
		$state['updated_at'] = time();
		update_option( self::OPTION_KEY, $state, false );
	}

	// -------------------------------------------------------------------------
	// Controls
	// -------------------------------------------------------------------------

	public static function start( string $mode = 'bulk' ): array {
		$mode  = in_array( $mode, [ 'bulk', 'reoptimize' ], true ) ? $mode : 'bulk';
		$state = self::get_state();

		if ( 'paused' === $state['status'] && $state['mode'] === $mode ) {
			$state['status'] = 'running';
			self::save_state( $state );
			self::schedule_cron();
			return $state;
		}

		$state                   = self::default_state();
		$state['mode']           = $mode;
		$state['status']         = 'running';
		$state['total_snapshot'] = Optimizer::count_total_optimizable_attachments();
		$state['started_at']     = time();

		self::save_state( $state );
		self::schedule_cron();

		return $state;
	}

	public static function pause(): array {
		$state           = self::get_state();
		$state['status'] = 'paused';
		self::save_state( $state );
		self::unschedule_cron();
		return $state;
	}

	public static function resume(): array {
		$state = self::get_state();
		if ( 'paused' === $state['status'] ) {
			$state['status'] = 'running';
			self::save_state( $state );
			self::schedule_cron();
		}
		return $state;
	}

	public static function stop(): array {
		$state = self::default_state();
		self::save_state( $state );
		self::unschedule_cron();
		return $state;
	}

	/**
	 * Re-queues failed items for another attempt and resumes the run.
	 */
	public static function retry_failed(): array {
		$state = self::get_state();

		$ids = array_map(
			static function ( array $entry ): int {
				return (int) ( $entry['id'] ?? 0 );
			},
			is_array( $state['failed'] ) ? $state['failed'] : []
		);
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		$state['retry_ids'] = array_values( array_unique( array_merge( $state['retry_ids'] ?? [], $ids ) ) );
		$state['failed']    = [];
		$state['status']    = 'running';

		self::save_state( $state );
		self::schedule_cron();

		return $state;
	}

	private static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	private static function unschedule_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// Progress
	// -------------------------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	public static function progress(): array {
		$state = self::get_state();
		$total = max( 0, (int) $state['total_snapshot'] );

		$optimized_now = Stats::optimized_count();
		$done          = 'reoptimize' === $state['mode']
			? (int) $state['processed']
			: min( $optimized_now, $total );

		return [
			'status'         => $state['status'],
			'mode'           => $state['mode'],
			'total'          => $total,
			'processed'      => (int) $state['processed'],
			'done_estimate'  => $done,
			'percent'        => $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 0,
			'failed_count'   => is_array( $state['failed'] ) ? count( $state['failed'] ) : 0,
			'failed'         => array_slice( is_array( $state['failed'] ) ? $state['failed'] : [], -20 ),
			'retry_pending'  => is_array( $state['retry_ids'] ) ? count( $state['retry_ids'] ) : 0,
			'savings_bytes'  => Stats::total_savings_bytes(),
			'started_at'     => (int) $state['started_at'],
			'updated_at'     => (int) $state['updated_at'],
		];
	}

	// -------------------------------------------------------------------------
	// Work
	// -------------------------------------------------------------------------

	/**
	 * Process exactly one attachment. Returns a result array describing what
	 * happened, or null if the queue isn't in a runnable state or there is
	 * nothing left to do (in which case the queue is marked completed).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function advance(): ?array {
		$state = self::get_state();

		if ( 'running' !== $state['status'] ) {
			return null;
		}

		if ( ! self::acquire_lock() ) {
			return [ 'locked' => true ];
		}

		try {
			// Prune any retry IDs pointing at attachments that no longer exist,
			// so a deleted attachment doesn't linger in the retry list forever.
			if ( ! empty( $state['retry_ids'] ) && is_array( $state['retry_ids'] ) ) {
				$valid_retry_ids = array_values( array_filter(
					$state['retry_ids'],
					static function ( $id ): bool {
						return get_post( (int) $id ) instanceof \WP_Post;
					}
				) );
				if ( $valid_retry_ids !== $state['retry_ids'] ) {
					$state['retry_ids'] = $valid_retry_ids;
					self::save_state( $state );
				}
			}

			$attachment_id = self::next_attachment_id( $state );

			if ( 0 === $attachment_id ) {
				$state['status']       = 'completed';
				$state['completed_at'] = time();
				self::save_state( $state );
				self::unschedule_cron();
				return [ 'done' => true ];
			}

			// Reoptimize mode (and retried items) must always re-run, ignoring the
			// fingerprint short-circuit – that's the whole point of "re-optimize".
			$force  = ( 'reoptimize' === $state['mode'] ) || in_array( $attachment_id, $state['retry_ids'] ?? [], true );
			$result = Optimizer::optimize_attachment( $attachment_id, $force );

			$state = self::get_state(); // Re-read in case settings changed concurrently.
			++$state['processed'];

			if ( 'reoptimize' === $state['mode'] ) {
				$state['cursor'] = max( (int) $state['cursor'], $attachment_id );
			}

			// If this ID came from the retry queue, remove it regardless of outcome
			// (a repeat failure gets a fresh failed entry, not an infinite retry loop).
			if ( isset( $state['retry_ids'] ) && is_array( $state['retry_ids'] ) ) {
				$state['retry_ids'] = array_values( array_diff( $state['retry_ids'], [ $attachment_id ] ) );
			}

			if ( is_wp_error( $result ) ) {
				$state['failed'][] = [
					'id'      => $attachment_id,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'time'    => time(),
				];
				if ( count( $state['failed'] ) > self::MAX_FAILED_ENTRIES ) {
					$state['failed'] = array_slice( $state['failed'], -self::MAX_FAILED_ENTRIES );
				}
				self::save_state( $state );

				return [
					'attachment_id' => $attachment_id,
					'error'         => $result->get_error_message(),
				];
			}

			self::save_state( $state );

			return [
				'attachment_id'   => $attachment_id,
				'savings_bytes'   => $result['savings_bytes'] ?? 0,
				'savings_percent' => $result['savings_percent'] ?? 0,
			];
		} finally {
			self::release_lock();
		}
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private static function next_attachment_id( array $state ): int {
		// Retry queue takes priority over the normal scan.
		if ( ! empty( $state['retry_ids'] ) && is_array( $state['retry_ids'] ) ) {
			foreach ( $state['retry_ids'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 && get_post( $id ) ) {
					return $id;
				}
			}
		}

		$mimes = array_values( array_diff( Settings::OPTIMIZABLE_MIMES, Settings::excluded_mime_types() ) );
		if ( empty( $mimes ) ) {
			return 0;
		}

		if ( 'reoptimize' === $state['mode'] ) {
			$cursor = (int) $state['cursor'];
			add_filter( 'posts_where', [ self::class, 'filter_where_id_greater_than' ] );
			self::$where_cursor = $cursor;

			$query = new \WP_Query( [
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => $mimes,
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			] );

			remove_filter( 'posts_where', [ self::class, 'filter_where_id_greater_than' ] );

			$ids = $query->posts;
			return ( is_array( $ids ) && ! empty( $ids ) ) ? (int) $ids[0] : 0;
		}

		// Bulk mode: first attachment that has never been processed.
		$query = new \WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => $mimes,
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => Optimizer::META_KEY,
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		$ids = $query->posts;
		return ( is_array( $ids ) && ! empty( $ids ) ) ? (int) $ids[0] : 0;
	}

	/** @var int Set immediately before use by next_attachment_id(); consumed by the posts_where filter. */
	private static int $where_cursor = 0;

	public static function filter_where_id_greater_than( string $where ): string {
		global $wpdb;
		return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", self::$where_cursor );
	}

	// -------------------------------------------------------------------------
	// Locking (best-effort – prevents the common case of AJAX + cron overlap,
	// not a distributed lock; acceptable since duplicate work here is merely
	// wasteful, not destructive – optimize_attachment() is idempotent).
	// -------------------------------------------------------------------------

	private static function acquire_lock(): bool {
		if ( false !== get_transient( self::LOCK_KEY ) ) {
			return false;
		}
		set_transient( self::LOCK_KEY, time(), 30 );
		return true;
	}

	private static function release_lock(): void {
		delete_transient( self::LOCK_KEY );
	}

	// -------------------------------------------------------------------------
	// Cron entry point
	// -------------------------------------------------------------------------

	public static function run_cron_batch(): void {
		$state = self::get_state();
		if ( 'running' !== $state['status'] ) {
			self::unschedule_cron();
			return;
		}

		$deadline = microtime( true ) + self::CRON_BATCH_SECONDS;

		while ( microtime( true ) < $deadline ) {
			$result = self::advance();
			if ( null === $result || ! empty( $result['done'] ) || ! empty( $result['locked'] ) ) {
				break;
			}
		}
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	private static function check_ajax_access(): void {
		check_ajax_referer( 'kitgenix_image_optimizer_nonce', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'kitgenix-image-optimizer' ) ], 403 );
		}
	}

	public static function ajax_start(): void {
		self::check_ajax_access();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in check_ajax_access()
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['mode'] ) ) : 'bulk';
		wp_send_json_success( self::start( $mode ) );
	}

	public static function ajax_next(): void {
		self::check_ajax_access();
		$result = self::advance();
		if ( null === $result ) {
			wp_send_json_success( array_merge( [ 'idle' => true ], self::progress() ) );
			return;
		}
		wp_send_json_success( array_merge( $result, self::progress() ) );
	}

	public static function ajax_pause(): void {
		self::check_ajax_access();
		wp_send_json_success( self::pause() );
	}

	public static function ajax_resume(): void {
		self::check_ajax_access();
		wp_send_json_success( self::resume() );
	}

	public static function ajax_stop(): void {
		self::check_ajax_access();
		wp_send_json_success( self::stop() );
	}

	public static function ajax_retry(): void {
		self::check_ajax_access();
		wp_send_json_success( self::retry_failed() );
	}

	public static function ajax_status(): void {
		self::check_ajax_access();
		wp_send_json_success( self::progress() );
	}
}
