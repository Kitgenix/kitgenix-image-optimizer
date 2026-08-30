<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Detects what the current server's image stack can actually do.
 *
 * Nothing here is assumed – every capability is probed live so the plugin
 * never advertises support (in the admin UI, Site Health, or WP-CLI) that
 * the server cannot deliver. WordPress's own `wp_image_editor_supports()`
 * (available since WP 6.5) is treated as the source of truth for "can
 * WordPress actually read+write this format", since it reflects whichever
 * editor (Imagick or GD) core would really select – we don't re-implement
 * that selection logic ourselves.
 */
final class Capabilities {

	/**
	 * @return array<string,mixed>
	 */
	public static function get_report(): array {
		$gd_available      = extension_loaded( 'gd' ) && function_exists( 'gd_info' );
		$imagick_available = extension_loaded( 'imagick' ) && class_exists( '\Imagick' );

		$formats = [];
		foreach ( [ 'jpeg', 'png', 'gif', 'webp', 'avif' ] as $format ) {
			$formats[ $format ] = self::probe_format( $format, $gd_available, $imagick_available );
		}

		$imagick_version = '';
		if ( $imagick_available ) {
			try {
				$raw = \Imagick::getVersion();
				$imagick_version = is_array( $raw ) && isset( $raw['versionString'] ) ? (string) $raw['versionString'] : '';
			} catch ( \Throwable $e ) {
				$imagick_version = '';
			}
		}

		$gd_version = '';
		if ( $gd_available ) {
			$info       = gd_info();
			$gd_version = is_array( $info ) && isset( $info['GD Version'] ) ? (string) $info['GD Version'] : '';
		}

		return [
			'gd_available'      => $gd_available,
			'gd_version'        => $gd_version,
			'imagick_available' => $imagick_available,
			'imagick_version'   => $imagick_version,
			'preferred_editor'  => self::preferred_editor_label(),
			'formats'           => $formats,
			'memory_limit'      => self::memory_limit_bytes(),
			'memory_limit_text' => ini_get( 'memory_limit' ) ?: __( 'Not set', 'kitgenix-image-optimizer' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ) ?: '',
			'post_max_size'       => ini_get( 'post_max_size' ) ?: '',
			'max_execution_time'  => ini_get( 'max_execution_time' ) ?: '',
			'max_pixels'          => Image_Guard::max_pixels(),
		];
	}

	/**
	 * @return array{decode:bool,encode:bool,wp_supported:bool}
	 */
	private static function probe_format( string $format, bool $gd_available, bool $imagick_available ): array {
		$mime = 'jpeg' === $format ? 'image/jpeg' : 'image/' . $format;

		$decode = false;
		$encode = false;

		if ( $gd_available ) {
			$const_name = 'IMG_' . strtoupper( 'jpeg' === $format ? 'jpg' : $format );
			if ( defined( $const_name ) && function_exists( 'imagetypes' ) ) {
				$mask = (int) constant( $const_name );
				if ( 0 !== ( imagetypes() & $mask ) ) {
					$decode = true;
					$encode = true;
				}
			}

			// GD's AVIF support is a distinct pair of functions gated separately
			// from imagetypes() depending on the libavif build.
			if ( 'avif' === $format ) {
				$decode = $decode || function_exists( 'imagecreatefromavif' );
				$encode = $encode || function_exists( 'imageavif' );
			}
			if ( 'webp' === $format ) {
				$decode = $decode || function_exists( 'imagecreatefromwebp' );
				$encode = $encode || function_exists( 'imagewebp' );
			}
		}

		if ( $imagick_available && ( ! $decode || ! $encode ) ) {
			try {
				$imagick_formats = array_map( 'strtoupper', \Imagick::queryFormats( strtoupper( $format ) ) );
				if ( ! empty( $imagick_formats ) ) {
					$decode = true;
					$encode = true;
				}
			} catch ( \Throwable $e ) {
				// Leave as detected via GD only.
			}
		}

		if ( function_exists( 'wp_image_editor_supports' ) ) {
			// WP 6.5+: ask core directly, since it reflects whichever editor
			// (Imagick or GD) it would actually select at runtime.
			$wp_supported = (bool) wp_image_editor_supports( [ 'mime_type' => $mime ] );
		} else {
			// Pre-6.5 fallback (this plugin supports WP 6.0+): approximate with
			// our own raw GD/Imagick detection above rather than assuming support.
			$wp_supported = $decode && $encode;
		}

		return [
			'decode'       => $decode,
			'encode'       => $encode,
			'wp_supported' => $wp_supported,
		];
	}

	/**
	 * WordPress prefers Imagick when both are available and it declares
	 * support for the request; otherwise it falls back to GD. This mirrors
	 * that preference for display purposes only.
	 */
	private static function preferred_editor_label(): string {
		/** @var string[] $editors */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying WordPress core's own "wp_image_editors" filter to read the editor preference order, not a hook this plugin defines.
		$editors = apply_filters( 'wp_image_editors', [ '\WP_Image_Editor_Imagick', '\WP_Image_Editor_GD' ] );
		foreach ( $editors as $editor_class ) {
			if ( class_exists( $editor_class ) && call_user_func( [ $editor_class, 'test' ], [] ) ) {
				return ltrim( (string) $editor_class, '\\' );
			}
		}
		return __( 'None available', 'kitgenix-image-optimizer' );
	}

	/**
	 * Resolve PHP's memory_limit ini value to a byte count. Returns 0 for
	 * unlimited (-1) so callers can special-case "no limit" explicitly.
	 */
	public static function memory_limit_bytes(): int {
		$raw = (string) ini_get( 'memory_limit' );
		if ( '' === $raw || '-1' === trim( $raw ) ) {
			return 0;
		}

		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			return (int) wp_convert_hr_to_bytes( $raw );
		}

		$raw   = trim( $raw );
		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (int) $raw;

		return match ( $unit ) {
			'g' => $value * 1024 * 1024 * 1024,
			'm' => $value * 1024 * 1024,
			'k' => $value * 1024,
			default => $value,
		};
	}

	public static function supports_webp(): bool {
		$report = self::get_report();
		return ! empty( $report['formats']['webp']['wp_supported'] );
	}

	public static function supports_avif(): bool {
		$report = self::get_report();
		return ! empty( $report['formats']['avif']['wp_supported'] );
	}
}
