<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Simple per-plugin activity log for Kitgenix Image Optimizer.
 *
 * Records admin actions (settings saves, optimizations, bulk runs) to a
 * capped WP option so they are visible in the admin Log tab.
 *
 * Storage format (per entry):
 *   [ 'time' => int, 'context' => string, 'outcome' => string, 'note' => string, 'code' => string ]
 */
final class Event_Log {

	private const OPTION_KEY  = 'kitgenix_image_optimizer_event_log';
	private const MAX_ENTRIES = 100;

	/**
	 * Record an event to the log.
	 *
	 * @param string $context  Short slug (e.g. 'settings-saved', 'image-optimized').
	 * @param string $outcome  'success', 'error', 'skipped', or any short outcome label.
	 * @param string $note     Optional plain-English detail.
	 * @param string $code     Optional stable code identifying the reason; see get_category_and_note().
	 */
	public static function record( string $context, string $outcome, string $note = '', string $code = '' ): void {
		$log   = self::get_raw_log();
		$log[] = [
			'time'    => time(),
			'context' => sanitize_text_field( $context ),
			'outcome' => sanitize_text_field( $outcome ),
			'note'    => sanitize_text_field( $note ),
			'code'    => sanitize_text_field( $code ),
		];

		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $log, false );
	}

	/**
	 * Return all stored entries (oldest first).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_raw_log(): array {
		$log = get_option( self::OPTION_KEY, [] );
		return is_array( $log ) ? $log : [];
	}

	/** Delete all stored entries. */
	public static function clear(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Return the log as a formatted multi-line string for the admin textarea.
	 */
	public static function get_log_text(): string {
		$entries = self::get_raw_log();
		if ( empty( $entries ) ) {
			return __( 'No recent events recorded yet.', 'kitgenix-image-optimizer' );
		}

		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
		$lines  = [ '# Columns: timestamp | context | outcome | note' ];

		foreach ( array_reverse( $entries ) as $entry ) {
			$time = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
			if ( function_exists( 'wp_date' ) ) {
				$when = $time ? (string) wp_date( $format, $time ) : '';
			} else {
				$when = $time ? (string) date_i18n( $format, $time ) : '';
			}

			$lines[] = sprintf(
				'%1$s | %2$s | %3$s | %4$s',
				$when ?: __( 'Unknown time', 'kitgenix-image-optimizer' ),
				isset( $entry['context'] ) ? (string) $entry['context'] : '',
				isset( $entry['outcome'] ) ? (string) $entry['outcome'] : '',
				isset( $entry['note'] ) ? (string) $entry['note'] : ''
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Map an event code to a short category slug and a plain-English note.
	 *
	 * Used by the settings-page reference table so site owners can tell genuine
	 * problems (need action) apart from things that happen by design or by
	 * environment limits (informational only).
	 *
	 * @return array{category: string, note: string}
	 */
	public static function get_category_and_note( string $code ): array {
		$map = [
			'settings-saved' => [
				'category' => 'settings-saved',
				'note'     => __( 'Plugin settings were updated from the admin screen.', 'kitgenix-image-optimizer' ),
			],
			'image-optimized' => [
				'category' => 'optimized',
				'note'     => __( 'The image was resized and/or compressed and saved successfully.', 'kitgenix-image-optimizer' ),
			],
			'animated_gif_skipped' => [
				'category' => 'skipped-by-design',
				'note'     => __( 'Animated GIFs are skipped on purpose so the animation is not destroyed.', 'kitgenix-image-optimizer' ),
			],
			'excluded_by_rule' => [
				'category' => 'skipped-by-design',
				'note'     => __( 'The file matched one of your exclusion rules (minimum size or filename pattern) and was skipped on purpose.', 'kitgenix-image-optimizer' ),
			],
			'not_optimizable' => [
				'category' => 'skipped-by-design',
				'note'     => __( 'This file type is not supported for optimization. Only JPEG, PNG, WebP, and GIF are processed.', 'kitgenix-image-optimizer' ),
			],
			'webp_sidecar_failed' => [
				'category' => 'webp-not-generated',
				'note'     => __( 'The optional WebP copy could not be generated, but the main image was still optimized normally. Common when the server\'s GD/Imagick build lacks WebP support.', 'kitgenix-image-optimizer' ),
			],
			'not_writable' => [
				'category' => 'file-permissions',
				'note'     => __( 'The file could not be read or written by PHP.', 'kitgenix-image-optimizer' ),
			],
			'filesize_read_failed' => [
				'category' => 'filesystem-error',
				'note'     => __( 'PHP could not read the file\'s size from disk.', 'kitgenix-image-optimizer' ),
			],
			'editor_init_failed' => [
				'category' => 'missing-image-library',
				'note'     => __( 'WordPress could not create an image editor for this file – usually a missing GD/Imagick extension or an unsupported format.', 'kitgenix-image-optimizer' ),
			],
			'resize_failed' => [
				'category' => 'resize-error',
				'note'     => __( 'The image library failed to resize this file. It may be corrupted or use an unsupported color profile.', 'kitgenix-image-optimizer' ),
			],
			'save_failed' => [
				'category' => 'save-error',
				'note'     => __( 'The image library failed to save the optimized file back to disk.', 'kitgenix-image-optimizer' ),
			],
			'not_found' => [
				'category' => 'file-missing',
				'note'     => __( 'The attachment record points to a file that no longer exists on disk.', 'kitgenix-image-optimizer' ),
			],
			'attachment_not_found' => [
				'category' => 'file-missing',
				'note'     => __( 'The attachment file could not be found on disk, so there was nothing to restore.', 'kitgenix-image-optimizer' ),
			],
			'no_backup_file' => [
				'category' => 'no-backup-available',
				'note'     => __( 'No backup copy exists for this image. Backups are only created when "Backup Originals" was enabled at optimization time.', 'kitgenix-image-optimizer' ),
			],
			'copy_failed' => [
				'category' => 'restore-error',
				'note'     => __( 'The backup file could not be copied back over the optimized file.', 'kitgenix-image-optimizer' ),
			],
		];

		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}

		return [
			'category' => 'other',
			'note'     => __( 'No further detail available for this event.', 'kitgenix-image-optimizer' ),
		];
	}
}
