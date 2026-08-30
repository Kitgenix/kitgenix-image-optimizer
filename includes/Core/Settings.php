<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const OPTION_NAME = 'kitgenix_image_optimizer_settings';

	/** Bumped whenever the stored settings/meta shape changes; see Migrations::run(). */
	public const DB_VERSION = 2;

	public const OPTIMIZABLE_MIMES = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ];

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			// Core
			'enabled'                     => 1,
			'auto_optimize_on_upload'     => 1,

			// Compression level preset ('lossless'|'normal'|'aggressive'|'ultra')
			// When set to anything other than 'custom', overrides the manual quality fields.
			// Displayed to users as Conservative / Balanced / Aggressive / Maximum.
			'compression_level'           => 'normal',

			// Manual quality (used when compression_level = 'custom')
			'quality_jpeg'                => 82,
			'quality_webp'                => 82,
			'quality_avif'                => 60,
			'quality_png_compression'     => 6,

			// Resize on upload (also aligned with core's big_image_size_threshold)
			'max_width'                   => 2560,
			'max_height'                  => 2560,

			// Format conversion
			'convert_to_webp'             => 0,
			'convert_to_avif'             => 0,

			// Processing options
			'strip_metadata'              => 1,
			'optimize_thumbnails'         => 1,
			'backup_originals'            => 0,
			'skip_animated_gif'           => 1,   // Protect animated GIFs

			// Lazy loading
			'lazy_load'                   => 0,

			// Exclusions
			'exclude_below_kb'            => 0,   // Skip files smaller than N KB (0 = disabled)
			'exclude_above_kb'            => 0,   // Skip files larger than N KB (0 = disabled)
			'excluded_filename_patterns'  => '',  // Comma-separated substrings/globs to skip
			'excluded_mime_types'         => [],  // Subset of OPTIMIZABLE_MIMES to never process
			'excluded_min_width'          => 0,   // Skip images narrower than N px (0 = disabled)
			'excluded_min_height'         => 0,   // Skip images shorter than N px (0 = disabled)
			'excluded_max_width'          => 0,   // Skip images wider than N px (0 = disabled)
			'excluded_max_height'         => 0,   // Skip images taller than N px (0 = disabled)
			'excluded_attachment_ids'     => '',  // Comma-separated attachment IDs to never process
			'excluded_directories'        => '',  // Comma-separated substrings of the uploads-relative path

			// Data retention
			'keep_data_on_uninstall'      => 0,   // Keep settings/logs/per-image meta on uninstall
			'delete_backups_on_uninstall' => 0,   // Also delete -kitgenix-original backup files on uninstall
		];
	}

	public static function ensure_defaults(): void {
		$existing = get_option( self::OPTION_NAME, null );
		if ( $existing === null ) {
			add_option( self::OPTION_NAME, self::defaults(), '', false );
			return;
		}

		if ( is_array( $existing ) ) {
			$merged = array_merge( self::defaults(), $existing );
			if ( $merged !== $existing ) {
				update_option( self::OPTION_NAME, $merged, false );
			}
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_settings(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$saved = get_option( self::OPTION_NAME, [] );
		$cache = is_array( $saved ) ? array_merge( self::defaults(), $saved ) : self::defaults();
		return $cache;
	}

	/** Clear the in-request settings cache (used by tests and after direct option writes). */
	public static function flush_cache(): void {
		// no-op placeholder retained for API stability; static cache is per-request only.
	}

	public static function register_settings(): void {
		register_setting(
			'kitgenix_image_optimizer_settings_group',
			self::OPTION_NAME,
			[
				'sanitize_callback' => [ self::class, 'sanitize' ],
			]
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return self::defaults();
		}

		$defaults = self::defaults();
		$out      = [];

		// Booleans (toggles)
		foreach (
			[
				'enabled', 'auto_optimize_on_upload', 'convert_to_webp', 'convert_to_avif',
				'strip_metadata', 'optimize_thumbnails', 'backup_originals', 'skip_animated_gif',
				'lazy_load', 'keep_data_on_uninstall', 'delete_backups_on_uninstall',
			] as $bool_key
		) {
			$out[ $bool_key ] = ! empty( $input[ $bool_key ] ) ? 1 : 0;
		}

		// Compression level
		$valid_levels            = [ 'lossless', 'normal', 'aggressive', 'ultra', 'custom' ];
		$out['compression_level'] = isset( $input['compression_level'] ) && in_array( $input['compression_level'], $valid_levels, true )
			? $input['compression_level']
			: $defaults['compression_level'];

		// Manual quality fields
		$out['quality_jpeg']            = isset( $input['quality_jpeg'] ) ? max( 1, min( 100, absint( $input['quality_jpeg'] ) ) ) : $defaults['quality_jpeg'];
		$out['quality_webp']            = isset( $input['quality_webp'] ) ? max( 1, min( 100, absint( $input['quality_webp'] ) ) ) : $defaults['quality_webp'];
		$out['quality_avif']            = isset( $input['quality_avif'] ) ? max( 1, min( 100, absint( $input['quality_avif'] ) ) ) : $defaults['quality_avif'];
		$out['quality_png_compression'] = isset( $input['quality_png_compression'] ) ? max( 0, min( 9, absint( $input['quality_png_compression'] ) ) ) : $defaults['quality_png_compression'];

		// Resize
		$out['max_width']  = isset( $input['max_width'] )  ? max( 0, absint( $input['max_width'] ) )  : $defaults['max_width'];
		$out['max_height'] = isset( $input['max_height'] ) ? max( 0, absint( $input['max_height'] ) ) : $defaults['max_height'];

		// Exclusions
		$out['exclude_below_kb']           = isset( $input['exclude_below_kb'] ) ? max( 0, absint( $input['exclude_below_kb'] ) ) : 0;
		$out['exclude_above_kb']           = isset( $input['exclude_above_kb'] ) ? max( 0, absint( $input['exclude_above_kb'] ) ) : 0;
		$out['excluded_filename_patterns'] = isset( $input['excluded_filename_patterns'] ) ? sanitize_textarea_field( wp_unslash( (string) $input['excluded_filename_patterns'] ) ) : '';

		$out['excluded_mime_types'] = [];
		if ( isset( $input['excluded_mime_types'] ) && is_array( $input['excluded_mime_types'] ) ) {
			foreach ( $input['excluded_mime_types'] as $mime ) {
				$mime = is_string( $mime ) ? sanitize_mime_type( $mime ) : '';
				if ( in_array( $mime, self::OPTIMIZABLE_MIMES, true ) ) {
					$out['excluded_mime_types'][] = $mime;
				}
			}
			$out['excluded_mime_types'] = array_values( array_unique( $out['excluded_mime_types'] ) );
		}

		foreach ( [ 'excluded_min_width', 'excluded_min_height', 'excluded_max_width', 'excluded_max_height' ] as $dim_key ) {
			$out[ $dim_key ] = isset( $input[ $dim_key ] ) ? max( 0, absint( $input[ $dim_key ] ) ) : 0;
		}

		$out['excluded_attachment_ids'] = isset( $input['excluded_attachment_ids'] )
			? self::sanitize_id_list( (string) $input['excluded_attachment_ids'] )
			: '';

		$out['excluded_directories'] = isset( $input['excluded_directories'] )
			? sanitize_textarea_field( wp_unslash( (string) $input['excluded_directories'] ) )
			: '';

		return $out;
	}

	/**
	 * Normalises a free-text comma/space separated ID list to a clean comma list of positive integers.
	 */
	private static function sanitize_id_list( string $raw ): string {
		$raw   = wp_unslash( $raw );
		$parts = preg_split( '/[\s,]+/', $raw ) ?: [];
		$ids   = [];
		foreach ( $parts as $part ) {
			$id = absint( $part );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return implode( ',', array_values( array_unique( $ids ) ) );
	}

	public static function is_enabled(): bool {
		return (bool) ( self::get_settings()['enabled'] ?? true );
	}

	public static function auto_optimize_on_upload(): bool {
		return (bool) ( self::get_settings()['auto_optimize_on_upload'] ?? true );
	}

	public static function quality_jpeg(): int {
		return (int) ( self::get_settings()['quality_jpeg'] ?? 82 );
	}

	public static function quality_webp(): int {
		return (int) ( self::get_settings()['quality_webp'] ?? 82 );
	}

	public static function quality_avif(): int {
		return (int) ( self::get_settings()['quality_avif'] ?? 60 );
	}

	public static function quality_png_compression(): int {
		return (int) ( self::get_settings()['quality_png_compression'] ?? 6 );
	}

	public static function max_width(): int {
		return (int) ( self::get_settings()['max_width'] ?? 2560 );
	}

	public static function max_height(): int {
		return (int) ( self::get_settings()['max_height'] ?? 2560 );
	}

	public static function convert_to_webp(): bool {
		return (bool) ( self::get_settings()['convert_to_webp'] ?? false );
	}

	public static function convert_to_avif(): bool {
		return (bool) ( self::get_settings()['convert_to_avif'] ?? false );
	}

	public static function strip_metadata(): bool {
		return (bool) ( self::get_settings()['strip_metadata'] ?? true );
	}

	public static function optimize_thumbnails(): bool {
		return (bool) ( self::get_settings()['optimize_thumbnails'] ?? true );
	}

	public static function backup_originals(): bool {
		return (bool) ( self::get_settings()['backup_originals'] ?? false );
	}

	public static function compression_level(): string {
		$v = (string) ( self::get_settings()['compression_level'] ?? 'normal' );
		return in_array( $v, [ 'lossless', 'normal', 'aggressive', 'ultra', 'custom' ], true ) ? $v : 'normal';
	}

	/**
	 * Effective JPEG quality based on compression_level preset or manual value.
	 */
	public static function effective_quality_jpeg(): int {
		return match ( self::compression_level() ) {
			'lossless'   => 96,
			'aggressive' => 78,
			'ultra'      => 65,
			'custom'     => self::quality_jpeg(),
			default      => 82, // 'normal'
		};
	}

	/**
	 * Effective WebP quality based on compression_level preset or manual value.
	 */
	public static function effective_quality_webp(): int {
		return match ( self::compression_level() ) {
			'lossless'   => 96,
			'aggressive' => 76,
			'ultra'      => 63,
			'custom'     => self::quality_webp(),
			default      => 80, // 'normal'
		};
	}

	/**
	 * Effective AVIF quality based on compression_level preset or manual value.
	 * AVIF's perceptual quality curve differs from JPEG/WebP, so presets run lower.
	 */
	public static function effective_quality_avif(): int {
		return match ( self::compression_level() ) {
			'lossless'   => 90,
			'aggressive' => 55,
			'ultra'      => 42,
			'custom'     => self::quality_avif(),
			default      => 60, // 'normal'
		};
	}

	public static function skip_animated_gif(): bool {
		return (bool) ( self::get_settings()['skip_animated_gif'] ?? true );
	}

	public static function lazy_load(): bool {
		return (bool) ( self::get_settings()['lazy_load'] ?? false );
	}

	public static function exclude_below_kb(): int {
		return (int) ( self::get_settings()['exclude_below_kb'] ?? 0 );
	}

	public static function exclude_above_kb(): int {
		return (int) ( self::get_settings()['exclude_above_kb'] ?? 0 );
	}

	public static function excluded_filename_patterns(): string {
		return (string) ( self::get_settings()['excluded_filename_patterns'] ?? '' );
	}

	/**
	 * @return string[]
	 */
	public static function excluded_mime_types(): array {
		$v = self::get_settings()['excluded_mime_types'] ?? [];
		return is_array( $v ) ? array_values( array_filter( array_map( 'strval', $v ) ) ) : [];
	}

	public static function excluded_min_width(): int {
		return (int) ( self::get_settings()['excluded_min_width'] ?? 0 );
	}

	public static function excluded_min_height(): int {
		return (int) ( self::get_settings()['excluded_min_height'] ?? 0 );
	}

	public static function excluded_max_width(): int {
		return (int) ( self::get_settings()['excluded_max_width'] ?? 0 );
	}

	public static function excluded_max_height(): int {
		return (int) ( self::get_settings()['excluded_max_height'] ?? 0 );
	}

	/**
	 * @return int[]
	 */
	public static function excluded_attachment_ids(): array {
		$raw = (string) ( self::get_settings()['excluded_attachment_ids'] ?? '' );
		if ( '' === $raw ) {
			return [];
		}
		return array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
	}

	/**
	 * @return string[]
	 */
	public static function excluded_directories(): array {
		$raw = (string) ( self::get_settings()['excluded_directories'] ?? '' );
		if ( '' === $raw ) {
			return [];
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	public static function keep_data_on_uninstall(): bool {
		return (bool) ( self::get_settings()['keep_data_on_uninstall'] ?? false );
	}

	public static function delete_backups_on_uninstall(): bool {
		return (bool) ( self::get_settings()['delete_backups_on_uninstall'] ?? false );
	}
}
