<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Versioned migrations for stored data shapes.
 *
 * The per-attachment meta format is additive-only across versions (old
 * records are always still recognised as "optimized" – see
 * `Optimizer::normalize_meta()`), so no destructive rewrite is ever needed.
 * What DOES need a one-time migration is backfilling the new incremental
 * `Stats` aggregate the first time a site upgrades from a version that
 * computed savings via a live database scan. That backfill runs via a single
 * background cron event, never inline on a page load.
 */
final class Migrations {
	private const OPTION_KEY = 'kitgenix_image_optimizer_db_version';
	private const BACKFILL_HOOK = 'kitgenix_image_optimizer_backfill_stats';

	public static function init(): void {
		add_action( 'admin_init', [ self::class, 'maybe_run' ], 1 );
		add_action( self::BACKFILL_HOOK, [ Stats::class, 'recalculate' ] );
	}

	public static function maybe_run(): void {
		$stored = (int) get_option( self::OPTION_KEY, 0 );

		if ( $stored >= Settings::DB_VERSION ) {
			return;
		}

		if ( $stored < 2 ) {
			// Prior versions had no Stats aggregate at all; schedule one background
			// recount so Media Library / settings pages read correct numbers
			// immediately after upgrade without a blocking scan on this request.
			if ( ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
				wp_schedule_single_event( time() + 30, self::BACKFILL_HOOK );
			}
		}

		update_option( self::OPTION_KEY, Settings::DB_VERSION, false );
	}
}
