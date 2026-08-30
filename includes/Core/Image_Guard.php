<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-decode safety checks that run BEFORE `wp_get_image_editor()` touches a
 * file, so a hostile or malformed image never reaches GD/Imagick's actual
 * decoder in a state that could exhaust memory or crash the PHP process.
 *
 * GD in particular has no catchable failure mode for a genuine decompression
 * bomb – an oversized `imagecreatefromjpeg()` call can hard-fatal the worker.
 * Reading dimensions via `getimagesize()` first is cheap (it parses only the
 * image header for JPEG/PNG/GIF/WebP) and lets us reject the file before any
 * expensive decode is attempted.
 */
final class Image_Guard {

	/** Hard ceiling on file size we'll even attempt to inspect, before dimension checks. */
	private const MAX_FILE_BYTES = 200 * 1024 * 1024; // 200 MB

	/**
	 * Maximum total pixels (width * height) we're willing to decode.
	 * ~60 megapixels comfortably covers real-world camera/scanner output
	 * while blocking pathological "1 KB file claims to be 60000x60000" bombs.
	 */
	public static function max_pixels(): int {
		return (int) apply_filters( 'kitgenix_image_optimizer_max_pixels', 60_000_000 );
	}

	/** Reject any single dimension above this, even if total pixels are under the ceiling. */
	private static function max_dimension(): int {
		return (int) apply_filters( 'kitgenix_image_optimizer_max_dimension', 40_000 );
	}

	/**
	 * Read image dimensions from the file header only (no pixel decode).
	 * Returns null if the header can't be parsed (e.g. some WebP/AVIF builds
	 * on older PHP versions) rather than guessing.
	 *
	 * @return array{width:int,height:int}|null
	 */
	public static function probe( string $file ): ?array {
		$dims = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- deliberately suppressed; malformed headers are expected input here, not exceptional
		if ( ! is_array( $dims ) || ! isset( $dims[0], $dims[1] ) ) {
			return null;
		}
		return [ 'width' => (int) $dims[0], 'height' => (int) $dims[1] ];
	}

	/**
	 * Validate a file is safe to decode. Returns true, or a WP_Error describing why not.
	 *
	 * @param array{width:int,height:int}|null $dims Pre-fetched dimensions from probe(), if already available.
	 * @return true|\WP_Error
	 */
	public static function validate( string $file, string $mime, ?array $dims = null ) {
		$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort stat
		if ( false === $size ) {
			return new \WP_Error( 'filesize_read_failed', __( 'Could not read the file size.', 'kitgenix-image-optimizer' ) );
		}

		if ( $size <= 0 ) {
			return new \WP_Error( 'empty_file', __( 'The file is empty.', 'kitgenix-image-optimizer' ) );
		}

		if ( $size > self::MAX_FILE_BYTES ) {
			return new \WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: human-readable maximum file size */
					__( 'File exceeds the %s safety limit for image processing.', 'kitgenix-image-optimizer' ),
					size_format( self::MAX_FILE_BYTES )
				)
			);
		}

		if ( null === $dims ) {
			$dims = self::probe( $file );
		}

		if ( is_array( $dims ) ) {
			$dim_check = self::check_dimensions( $dims['width'], $dims['height'] );
			if ( is_wp_error( $dim_check ) ) {
				return $dim_check;
			}
		} elseif ( in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif' ], true ) ) {
			// These formats are always readable by getimagesize(); a failure here
			// means the file is not a valid image of the claimed type.
			return new \WP_Error( 'unreadable_header', __( 'The file could not be identified as a valid image.', 'kitgenix-image-optimizer' ) );
		}
		// For WebP/AVIF on older PHP builds getimagesize() may not parse the header;
		// we fall through to the file-size ceiling above as the remaining safeguard,
		// and rely on WP_Image_Editor's own memory guard (wp_raise_memory_limit) for the decode itself.

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function check_dimensions( int $width, int $height ): bool|\WP_Error {
		if ( $width <= 0 || $height <= 0 ) {
			return new \WP_Error( 'invalid_dimensions', __( 'The image reports invalid dimensions.', 'kitgenix-image-optimizer' ) );
		}

		$max_dim = self::max_dimension();
		if ( $width > $max_dim || $height > $max_dim ) {
			return new \WP_Error(
				'dimension_too_large',
				sprintf(
					/* translators: %d: maximum allowed pixel dimension */
					__( 'Image dimensions exceed the %dpx safety limit.', 'kitgenix-image-optimizer' ),
					$max_dim
				)
			);
		}

		$pixels = $width * $height;
		if ( $pixels > self::max_pixels() ) {
			return new \WP_Error(
				'decompression_bomb_suspected',
				sprintf(
					/* translators: %s: pixel count formatted with thousands separators */
					__( 'Image has %s total pixels, exceeding the safety limit for decoding. This may be a malformed or oversized file.', 'kitgenix-image-optimizer' ),
					number_format_i18n( $pixels )
				)
			);
		}

		$estimated_bytes = self::estimate_decode_bytes( $width, $height );
		$limit           = Capabilities::memory_limit_bytes();

		if ( $limit > 0 ) {
			$available = $limit - memory_get_usage( true );
			// Require enough headroom instead of exactly filling the limit – image
			// libraries need working space beyond the raw pixel buffer.
			if ( $estimated_bytes > (int) ( $available * 0.6 ) ) {
				return new \WP_Error(
					'insufficient_memory',
					sprintf(
						/* translators: 1: estimated memory needed, 2: PHP memory_limit */
						__( 'This image needs an estimated %1$s to decode safely, which exceeds the available PHP memory (limit: %2$s).', 'kitgenix-image-optimizer' ),
						size_format( $estimated_bytes ),
						size_format( $limit )
					)
				);
			}
		}

		return true;
	}

	/**
	 * Rough estimate of the memory an uncompressed decode buffer needs:
	 * 4 bytes/pixel (RGBA) times a safety multiplier for the decoder's own
	 * working copies (matches the shape of core's internal image memory checks).
	 */
	private static function estimate_decode_bytes( int $width, int $height ): int {
		return (int) ( $width * $height * 4 * 2.5 );
	}
}
