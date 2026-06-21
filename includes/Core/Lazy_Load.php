<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Adds native lazy loading attributes to images on the frontend.
 *
 * WordPress 5.5+ already applies loading="lazy" to images handled by core,
 * but many images in custom HTML, widgets, and page builders are missed.
 * This class uses output buffering to ensure every <img> tag that is not
 * already marked gets loading="lazy". The first <img> on each page is
 * preserved as loading="eager" to avoid delaying the LCP candidate.
 */
final class Lazy_Load {

	/**
	 * Stores the output buffer level created by this class so we only close our own buffer.
	 *
	 * @var int|null
	 */
	private static $buffer_level = null;

	public static function init(): void {
		if ( ! Settings::lazy_load() ) {
			return;
		}

		// Exclude admin, feeds, REST, and preview contexts.
		if ( is_admin() ) {
			return;
		}

		add_action( 'template_redirect', [ self::class, 'start_buffer' ] );
	}

	public static function start_buffer(): void {
		if ( null !== self::$buffer_level ) {
			return;
		}

		$level_before = ob_get_level();
		ob_start( [ self::class, 'process_buffer' ] );
		self::$buffer_level = $level_before + 1;
		add_action( 'shutdown', [ self::class, 'close_buffer' ], 0 );
	}

	/**
	 * Explicitly close the output buffer during shutdown.
	 * This prevents buffer stack misalignment with other WordPress components.
	 */
	public static function close_buffer(): void {
		if ( null === self::$buffer_level ) {
			return;
		}

		if ( ob_get_level() !== self::$buffer_level ) {
			self::$buffer_level = null;
			return;
		}

		$active_buffer = ob_get_status();

		if ( isset( $active_buffer['name'] ) && false !== strpos( (string) $active_buffer['name'], 'KitgenixImageOptimizer\\Core\\Lazy_Load::process_buffer' ) ) {
			ob_end_flush();
		}

		self::$buffer_level = null;
	}

	/**
	 * Process the full page output and inject loading attributes.
	 */
	public static function process_buffer( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}

		// Only process HTML responses.
		if ( false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$first_img = true;

		return (string) preg_replace_callback(
			'/<img\b([^>]*?)>/i',
			static function ( array $match ) use ( &$first_img ): string {
				$tag    = $match[0];
				$attrs  = $match[1];

				// Skip if already has a loading attribute.
				if ( preg_match( '/\bloading\s*=/i', $attrs ) ) {
					$first_img = false;
					return $tag;
				}

				// Skip tracking pixels and base64-embedded images.
				if ( preg_match( '/\bsrc\s*=\s*["\']data:/i', $attrs ) ) {
					return $tag;
				}

				// Preserve the first image as eager (likely the LCP element).
				if ( $first_img ) {
					$first_img = false;
					return '<img loading="eager"' . $attrs . '>';
				}

				return '<img loading="lazy"' . $attrs . '>';
			},
			$html
		);
	}
}
