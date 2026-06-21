<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const OPTION_NAME = 'kitgenix_image_optimizer_settings';

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
			'compression_level'           => 'normal',

			// Manual quality (used when compression_level = 'custom')
			'quality_jpeg'                => 82,
			'quality_webp'                => 82,
			'quality_png_compression'     => 6,

			// Resize on upload
			'max_width'                   => 2560,
			'max_height'                  => 2560,

			// Format conversion
			'convert_to_webp'             => 0,
			'convert_png_to_jpeg'         => 0, // Convert PNG→JPEG when no transparency

			// Processing options
			'strip_metadata'              => 1,
			'optimize_thumbnails'         => 1,
			'backup_originals'            => 0,
			'skip_animated_gif'           => 1,   // Protect animated GIFs

			// Lazy loading
			'lazy_load'                   => 0,

			// Exclusions
			'exclude_below_kb'            => 0,   // Skip files smaller than N KB (0 = disabled)
			'excluded_filename_patterns'  => '',  // Comma-separated substrings to skip

			// Data retention
			'keep_data_on_uninstall'      => 0,
		];
	}

	public static function ensure_defaults(): void {
		$existing = get_option( self::OPTION_NAME, null );
		if ( $existing === null ) {
			add_option( self::OPTION_NAME, self::defaults() );
			return;
		}

		if ( is_array( $existing ) ) {
			$merged = array_merge( self::defaults(), $existing );
			if ( $merged !== $existing ) {
				update_option( self::OPTION_NAME, $merged );
			}
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_settings(): array {
		$saved = get_option( self::OPTION_NAME, [] );
		return is_array( $saved ) ? array_merge( self::defaults(), $saved ) : self::defaults();
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
				'enabled', 'auto_optimize_on_upload', 'convert_to_webp', 'convert_png_to_jpeg',
				'strip_metadata', 'optimize_thumbnails', 'backup_originals', 'skip_animated_gif',
				'lazy_load', 'keep_data_on_uninstall',
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
		$out['quality_png_compression'] = isset( $input['quality_png_compression'] ) ? max( 0, min( 9, absint( $input['quality_png_compression'] ) ) ) : $defaults['quality_png_compression'];

		// Resize
		$out['max_width']  = isset( $input['max_width'] )  ? max( 0, absint( $input['max_width'] ) )  : $defaults['max_width'];
		$out['max_height'] = isset( $input['max_height'] ) ? max( 0, absint( $input['max_height'] ) ) : $defaults['max_height'];

		// Exclusions
		$out['exclude_below_kb']           = isset( $input['exclude_below_kb'] ) ? max( 0, absint( $input['exclude_below_kb'] ) ) : 0;
		$out['excluded_filename_patterns'] = isset( $input['excluded_filename_patterns'] ) ? sanitize_textarea_field( wp_unslash( (string) $input['excluded_filename_patterns'] ) ) : '';

		return $out;
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

	public static function skip_animated_gif(): bool {
		return (bool) ( self::get_settings()['skip_animated_gif'] ?? true );
	}

	public static function lazy_load(): bool {
		return (bool) ( self::get_settings()['lazy_load'] ?? false );
	}

	public static function convert_png_to_jpeg(): bool {
		return (bool) ( self::get_settings()['convert_png_to_jpeg'] ?? false );
	}

	public static function exclude_below_kb(): int {
		return (int) ( self::get_settings()['exclude_below_kb'] ?? 0 );
	}

	public static function excluded_filename_patterns(): string {
		return (string) ( self::get_settings()['excluded_filename_patterns'] ?? '' );
	}

	public static function keep_data_on_uninstall(): bool {
		return (bool) ( self::get_settings()['keep_data_on_uninstall'] ?? false );
	}
}
