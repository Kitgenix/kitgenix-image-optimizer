<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Small, incrementally-maintained aggregate counters.
 *
 * Earlier versions computed "how many images are optimized" and "total bytes
 * saved" with a `WP_Query`/`$wpdb` scan across every attachment's postmeta –
 * cheap on a test site, but a real per-page-load cost on large libraries,
 * and it ran on every Media Library and plugin-settings page view.
 *
 * This class instead keeps a single small option (autoload disabled) that is
 * nudged up/down by one attachment at a time as optimizations, restores, and
 * failures happen. Reads are then just one `get_option()` call – no scans.
 * `recalculate()` does the full scan, but only ever runs when an admin
 * explicitly asks for it (or once, in the background, after upgrading from
 * a version that didn't track these counters).
 */
final class Stats {
	private const OPTION_KEY = 'kitgenix_image_optimizer_stats';

	/**
	 * @return array{optimized:int,failed:int,original_bytes:int,optimized_bytes:int,recalculated_at:int}
	 */
	public static function get(): array {
		$stats = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $stats ) ) {
			return self::empty_stats();
		}

		return array_merge( self::empty_stats(), $stats );
	}

	/**
	 * @return array{optimized:int,failed:int,original_bytes:int,optimized_bytes:int,recalculated_at:int}
	 */
	private static function empty_stats(): array {
		return [
			'optimized'        => 0,
			'failed'           => 0,
			'original_bytes'   => 0,
			'optimized_bytes'  => 0,
			'recalculated_at'  => 0,
		];
	}

	private static function save( array $stats ): void {
		update_option( self::OPTION_KEY, $stats, false );
	}

	public static function total_savings_bytes(): int {
		$stats = self::get();
		return max( 0, (int) $stats['original_bytes'] - (int) $stats['optimized_bytes'] );
	}

	public static function optimized_count(): int {
		return (int) self::get()['optimized'];
	}

	public static function failed_count(): int {
		return (int) self::get()['failed'];
	}

	/**
	 * Record a successful optimization, replacing any previous contribution
	 * this attachment made to the aggregate (so re-optimizing doesn't double count).
	 *
	 * @param array<string,mixed>|null $previous_meta Previous `_kitgenix_image_optimizer` value, if any.
	 */
	public static function record_optimized( ?array $previous_meta, int $original_bytes, int $optimized_bytes ): void {
		$stats = self::get();

		$was_optimized = is_array( $previous_meta ) && ( ( $previous_meta['status'] ?? '' ) === 'optimized' );
		$was_error     = is_array( $previous_meta ) && ( ( $previous_meta['status'] ?? '' ) === 'error' );

		if ( ! $was_optimized ) {
			++$stats['optimized'];
		} else {
			// Remove the previous contribution before adding the new one.
			$stats['original_bytes']  -= (int) ( $previous_meta['original_size'] ?? 0 );
			$stats['optimized_bytes'] -= (int) ( $previous_meta['optimized_size'] ?? 0 );
		}

		if ( $was_error ) {
			$stats['failed'] = max( 0, $stats['failed'] - 1 );
		}

		$stats['original_bytes']  = max( 0, (int) $stats['original_bytes'] ) + $original_bytes;
		$stats['optimized_bytes'] = max( 0, (int) $stats['optimized_bytes'] ) + $optimized_bytes;

		self::save( $stats );
	}

	/**
	 * @param array<string,mixed>|null $previous_meta
	 */
	public static function record_failed( ?array $previous_meta ): void {
		$stats = self::get();

		$was_optimized = is_array( $previous_meta ) && ( ( $previous_meta['status'] ?? '' ) === 'optimized' );
		$was_error     = is_array( $previous_meta ) && ( ( $previous_meta['status'] ?? '' ) === 'error' );

		if ( $was_optimized ) {
			$stats['optimized']        = max( 0, $stats['optimized'] - 1 );
			$stats['original_bytes']  -= (int) ( $previous_meta['original_size'] ?? 0 );
			$stats['optimized_bytes'] -= (int) ( $previous_meta['optimized_size'] ?? 0 );
			$stats['original_bytes']   = max( 0, (int) $stats['original_bytes'] );
			$stats['optimized_bytes']  = max( 0, (int) $stats['optimized_bytes'] );
		}

		if ( ! $was_error ) {
			++$stats['failed'];
		}

		self::save( $stats );
	}

	/**
	 * Reverse a previously recorded optimization (used by restore-from-backup).
	 *
	 * @param array<string,mixed>|null $previous_meta
	 */
	public static function record_restored( ?array $previous_meta ): void {
		if ( ! is_array( $previous_meta ) ) {
			return;
		}

		$stats = self::get();
		$status = (string) ( $previous_meta['status'] ?? '' );

		if ( 'optimized' === $status ) {
			$stats['optimized']       = max( 0, $stats['optimized'] - 1 );
			$stats['original_bytes']  = max( 0, (int) $stats['original_bytes'] - (int) ( $previous_meta['original_size'] ?? 0 ) );
			$stats['optimized_bytes'] = max( 0, (int) $stats['optimized_bytes'] - (int) ( $previous_meta['optimized_size'] ?? 0 ) );
		} elseif ( 'error' === $status ) {
			$stats['failed'] = max( 0, $stats['failed'] - 1 );
		}

		self::save( $stats );
	}

	/**
	 * Full recount from postmeta. Expensive on large libraries by design –
	 * only ever triggered explicitly (admin "Recalculate" action, WP-CLI, or
	 * a one-off background migration event), never on a normal page load.
	 */
	public static function recalculate(): void {
		global $wpdb;

		$stats = self::empty_stats();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- explicit admin-triggered recount, not a per-request query
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Optimizer::META_KEY
			)
		);

		foreach ( $rows as $row ) {
			$data = maybe_unserialize( $row );
			if ( ! is_array( $data ) ) {
				continue;
			}

			$status = (string) ( $data['status'] ?? '' );
			if ( 'optimized' === $status ) {
				++$stats['optimized'];
				$stats['original_bytes']  += (int) ( $data['original_size'] ?? 0 );
				$stats['optimized_bytes'] += (int) ( $data['optimized_size'] ?? 0 );
			} elseif ( 'error' === $status ) {
				++$stats['failed'];
			}
		}

		$stats['recalculated_at'] = time();
		self::save( $stats );
	}
}
