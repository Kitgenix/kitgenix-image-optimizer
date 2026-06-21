<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles image optimization using WordPress's WP_Image_Editor (GD/Imagick).
 */
final class Optimizer {

	/** Meta key storing per-attachment optimization data. */
	public const META_KEY = '_kitgenix_image_optimizer';

	public static function init(): void {
		if ( ! Settings::is_enabled() ) {
			return;
		}

		if ( Settings::auto_optimize_on_upload() ) {
			add_filter( 'wp_handle_upload', [ self::class, 'handle_upload' ], 20, 2 );
		}

		// AJAX handler for single-image optimization from media library.
		add_action( 'wp_ajax_kitgenix_image_optimizer_optimize_single', [ self::class, 'ajax_optimize_single' ] );

		// AJAX handler for bulk optimization (processes one image per request).
		add_action( 'wp_ajax_kitgenix_image_optimizer_bulk_next', [ self::class, 'ajax_bulk_next' ] );

		// AJAX handler for bulk re-optimization (re-processes all images, including already-optimized ones).
		add_action( 'wp_ajax_kitgenix_image_optimizer_bulk_reoptimize_next', [ self::class, 'ajax_bulk_reoptimize_next' ] );

		// AJAX handler for restoring a backup.
		add_action( 'wp_ajax_kitgenix_image_optimizer_restore_backup', [ self::class, 'ajax_restore_backup' ] );

		// REST endpoint for optimization status + wp/v2/media field.
		add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );
	}

	/**
	 * Fired after a file is uploaded. Optimizes the original file in-place.
	 *
	 * @param array<string,string> $upload  Upload data from wp_handle_upload.
	 * @param string               $context Upload context ('upload', 'sideload', etc.).
	 * @return array<string,string>
	 */
	public static function handle_upload( array $upload, string $context ): array {
		if ( 'upload' !== $context ) {
			return $upload;
		}

		if ( empty( $upload['file'] ) || ! is_string( $upload['file'] ) ) {
			return $upload;
		}

		$file = $upload['file'];
		$type = isset( $upload['type'] ) ? (string) $upload['type'] : '';

		if ( ! self::is_optimizable_mime( $type ) ) {
			return $upload;
		}

		self::optimize_file( $file, $type );

		return $upload;
	}

	/**
	 * Optimize a file on disk. Returns savings data array on success, false on failure.
	 *
	 * @param string $file      Absolute path to the file.
	 * @param string $mime_type MIME type string.
	 * @return array{original_size:int,optimized_size:int,savings_bytes:int,savings_percent:float}|false
	 */
	public static function optimize_file( string $file, string $mime_type ): array|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- no WP_Filesystem equivalent without credential prompts
		if ( ! is_readable( $file ) || ! is_writable( $file ) ) {
			return false;
		}

		// Skip animated GIFs to preserve animation.
		if ( 'image/gif' === $mime_type && Settings::skip_animated_gif() && self::is_animated_gif( $file ) ) {
			return false;
		}

		// Skip files matching exclusion rules (size threshold or filename pattern).
		if ( self::is_excluded_file( $file ) ) {
			return false;
		}

		$size_before = filesize( $file );
		if ( false === $size_before ) {
			return false;
		}

		// Backup original before overwriting, if the setting is enabled.
		if ( Settings::backup_originals() ) {
			$backup = (string) preg_replace( '/(\.([^.]+))$/', '-kitgenix-original$1', $file );
			if ( '' !== $backup && $backup !== $file && ! file_exists( $backup ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort backup; failure is non-fatal
				@copy( $file, $backup );
			}
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return false;
		}

		$max_w = Settings::max_width();
		$max_h = Settings::max_height();

		// Resize if the image exceeds the configured maximums (preserves aspect ratio).
		if ( $max_w > 0 || $max_h > 0 ) {
			$size = $editor->get_size();
			if ( is_array( $size ) ) {
				$img_w = isset( $size['width'] ) ? (int) $size['width'] : 0;
				$img_h = isset( $size['height'] ) ? (int) $size['height'] : 0;

				if ( ( $max_w > 0 && $img_w > $max_w ) || ( $max_h > 0 && $img_h > $max_h ) ) {
					$resize_result = $editor->resize( $max_w ?: null, $max_h ?: null, false );
					if ( is_wp_error( $resize_result ) ) {
						return false;
					}
				}
			}
		}

		// Set quality.
		$quality = self::quality_for_mime( $mime_type );
		$editor->set_quality( $quality );

		// Save back to the same path (overwrite).
		$save_result = $editor->save( $file, $mime_type );
		if ( is_wp_error( $save_result ) ) {
			return false;
		}

		// Generate a .webp sidecar (e.g. photo.jpg.webp) when the setting is on.
		// Uses a fresh editor so internal state from the JPEG save doesn't carry over.
		if ( Settings::convert_to_webp() && in_array( $mime_type, [ 'image/jpeg', 'image/png' ], true ) ) {
			$webp_editor = wp_get_image_editor( $file );
			if ( ! is_wp_error( $webp_editor ) ) {
				$webp_editor->set_quality( Settings::effective_quality_webp() );
				$webp_editor->save( $file . '.webp', 'image/webp' );
			}
		}

		// Clear stat cache so filesize() returns fresh data.
		clearstatcache( true, $file );
		$raw_after      = filesize( $file );
		$size_after     = false !== $raw_after ? (int) $raw_after : (int) $size_before;
		$savings_bytes  = max( 0, (int) $size_before - $size_after );
		$savings_pct    = $size_before > 0 ? round( ( $savings_bytes / $size_before ) * 100, 1 ) : 0.0;

		Event_Log::record(
			'image-optimized',
			'success',
			sprintf(
				'%s — before: %s, after: %s, saved: %s (%.1f%%)',
				basename( $file ),
				size_format( $size_before ),
				size_format( $size_after ),
				size_format( $savings_bytes ),
				$savings_pct
			)
		);

		return [
			'original_size'   => (int) $size_before,
			'optimized_size'  => $size_after,
			'savings_bytes'   => $savings_bytes,
			'savings_percent' => $savings_pct,
		];
	}

	/**
	 * AJAX: optimize a single attachment by ID.
	 */
	public static function ajax_optimize_single(): void {
		check_ajax_referer( 'kitgenix_image_optimizer_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'kitgenix-image-optimizer' ) ], 403 );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid attachment ID.', 'kitgenix-image-optimizer' ) ], 400 );
		}

		$result = self::optimize_attachment( $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [
			'message'                  => __( 'Image optimized.', 'kitgenix-image-optimizer' ),
			'savings_bytes'            => $result['savings_bytes'],
			'savings_percent'          => $result['savings_percent'],
			'savings_formatted'        => size_format( (int) $result['savings_bytes'] ),
			'original_size_formatted'  => size_format( (int) $result['original_size'] ),
			'optimized_size_formatted' => size_format( (int) $result['optimized_size'] ),
		] );
	}

	/**
	 * AJAX: process the next unoptimized attachment in a bulk run.
	 */
	public static function ajax_bulk_next(): void {
		check_ajax_referer( 'kitgenix_image_optimizer_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'kitgenix-image-optimizer' ) ], 403 );
		}

		$attachment_id = self::get_next_unoptimized_attachment_id();
		if ( ! $attachment_id ) {
			wp_send_json_success( [ 'done' => true, 'message' => __( 'All images have been optimized.', 'kitgenix-image-optimizer' ) ] );
		}

		$result = self::optimize_attachment( $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'attachment_id' => $attachment_id, 'message' => $result->get_error_message() ] );
		}

		$remaining = self::count_unoptimized_attachments();
		wp_send_json_success( [
			'done'                     => false,
			'attachment_id'            => $attachment_id,
			'remaining'                => $remaining,
			'savings_bytes'            => $result['savings_bytes'],
			'savings_percent'          => $result['savings_percent'],
			'savings_formatted'        => size_format( (int) $result['savings_bytes'] ),
			'original_size_formatted'  => size_format( (int) $result['original_size'] ),
			'optimized_size_formatted' => size_format( (int) $result['optimized_size'] ),
			'message'                  => sprintf(
				/* translators: %1$d: attachment ID, %2$d: remaining images count */
				__( 'Optimized attachment #%1$d. %2$d remaining.', 'kitgenix-image-optimizer' ),
				$attachment_id,
				$remaining
			),
		] );
	}

	/**
	 * AJAX: re-process the attachment at the given offset (re-optimizes already-optimized images too).
	 * The JS passes an incrementing `offset` so each call processes the next image in ID order.
	 */
	public static function ajax_bulk_reoptimize_next(): void {
		check_ajax_referer( 'kitgenix_image_optimizer_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'kitgenix-image-optimizer' ) ], 403 );
		}

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ],
			'posts_per_page' => 1,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];

		$query = new \WP_Query( $args );
		$ids   = $query->posts;

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			wp_send_json_success( [ 'done' => true, 'message' => __( 'All images have been re-optimized.', 'kitgenix-image-optimizer' ) ] );
			return;
		}

		$attachment_id = (int) $ids[0];

		$result = self::optimize_attachment( $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'attachment_id' => $attachment_id, 'message' => $result->get_error_message() ] );
			return;
		}

		wp_send_json_success( [
			'done'                     => false,
			'attachment_id'            => $attachment_id,
			'next_offset'              => $offset + 1,
			'savings_bytes'            => $result['savings_bytes'],
			'savings_percent'          => $result['savings_percent'],
			'savings_formatted'        => size_format( (int) $result['savings_bytes'] ),
			'original_size_formatted'  => size_format( (int) $result['original_size'] ),
			'optimized_size_formatted' => size_format( (int) $result['optimized_size'] ),
			'message'                  => sprintf(
				/* translators: %d: attachment ID */
				__( 'Re-optimized attachment #%d.', 'kitgenix-image-optimizer' ),
				$attachment_id
			),
		] );
	}

	/**
	 * Register REST routes for optimization status queries and the
	 * `kitgenix_optimizer` field on the core wp/v2/media endpoint.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'kitgenix-image-optimizer/v1',
			'/status',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'rest_status' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'upload_files' );
				},
			]
		);

		// Expose optimization data on each attachment in the REST API.
		register_rest_field(
			'attachment',
			'kitgenix_optimizer',
			[
				'get_callback'    => static function ( array $post ): array {
					$meta = get_post_meta( $post['id'], self::META_KEY, true );
					return is_array( $meta ) ? $meta : [ 'status' => 'unoptimized' ];
				},
				'update_callback' => null,
				'schema'          => [
					'type'        => 'object',
					'description' => 'Kitgenix Image Optimizer data for this attachment.',
					'context'     => [ 'view', 'edit' ],
				],
			]
		);
	}

	/**
	 * REST: return optimization statistics.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function rest_status( \WP_REST_Request $request ): \WP_REST_Response {
		$total       = self::count_total_optimizable_attachments();
		$optimized   = self::count_optimized_attachments();
		$unoptimized = max( 0, $total - $optimized );

		return new \WP_REST_Response(
			[
				'total'       => $total,
				'optimized'   => $optimized,
				'unoptimized' => $unoptimized,
			],
			200
		);
	}

	/**
	 * Optimize all image sizes for a given attachment.
	 *
	 * @return array{optimized_at:int,original_size:int,optimized_size:int,savings_bytes:int,savings_percent:float}|\WP_Error
	 */
	public static function optimize_attachment( int $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new \WP_Error( 'not_found', __( 'Attachment file not found.', 'kitgenix-image-optimizer' ) );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! self::is_optimizable_mime( $mime ) ) {
			return new \WP_Error( 'not_optimizable', __( 'File type is not supported for optimization.', 'kitgenix-image-optimizer' ) );
		}

		$file_result = self::optimize_file( $file, $mime );
		if ( false === $file_result ) {
			// Store error state so the Media Library column can show it.
			update_post_meta( $attachment_id, self::META_KEY, [
				'status'        => 'error',
				'error_message' => __( 'Optimization failed. Check that the file is a valid image and is writeable.', 'kitgenix-image-optimizer' ),
				'error_at'      => time(),
			] );
			return new \WP_Error( 'optimization_failed', __( 'Optimization failed.', 'kitgenix-image-optimizer' ) );
		}

		// Optionally optimize registered thumbnail sizes.
		if ( Settings::optimize_thumbnails() ) {
			$attach_meta = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $attach_meta ) && ! empty( $attach_meta['sizes'] ) && is_array( $attach_meta['sizes'] ) ) {
				$base_dir = trailingslashit( dirname( $file ) );

				foreach ( $attach_meta['sizes'] as $size_data ) {
					if ( ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
						continue;
					}
					$thumb_file = $base_dir . $size_data['file'];
					$thumb_mime = isset( $size_data['mime-type'] ) ? (string) $size_data['mime-type'] : $mime;
					if ( file_exists( $thumb_file ) ) {
						self::optimize_file( $thumb_file, $thumb_mime );
					}
				}
			}
		}

		$opt_meta = [
			'status'          => 'optimized',
			'optimized_at'    => time(),
			'original_size'   => $file_result['original_size'],
			'optimized_size'  => $file_result['optimized_size'],
			'savings_bytes'   => $file_result['savings_bytes'],
			'savings_percent' => $file_result['savings_percent'],
		];

		update_post_meta( $attachment_id, self::META_KEY, $opt_meta );
		delete_transient( 'kitgenix_image_optimizer_total_savings' );

		return $opt_meta;
	}

	/**
	 * Returns the next attachment ID that has not yet been optimized.
	 */
	private static function get_next_unoptimized_attachment_id(): int {
		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => self::META_KEY,
					'compare' => 'NOT EXISTS',
				],
			],
		];

		$query = new \WP_Query( $args );
		$ids   = $query->posts;

		return ( is_array( $ids ) && ! empty( $ids ) ) ? (int) $ids[0] : 0;
	}

	/**
	 * Count total image attachments eligible for optimization.
	 */
	public static function count_total_optimizable_attachments(): int {
		$counts = wp_count_attachments();
		$total  = 0;
		foreach ( [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ] as $mime ) {
			if ( isset( $counts->$mime ) ) {
				$total += (int) $counts->$mime;
			}
		}
		return $total;
	}

	/**
	 * Count attachments that have already been optimized.
	 */
	public static function count_optimized_attachments(): int {
		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => self::META_KEY,
					'compare' => 'EXISTS',
				],
			],
		];
		$query = new \WP_Query( $args );
		return (int) $query->found_posts;
	}

	/**
	 * Count attachments that have not yet been optimized.
	 */
	public static function count_unoptimized_attachments(): int {
		return max( 0, self::count_total_optimizable_attachments() - self::count_optimized_attachments() );
	}

	/**
	 * Total bytes saved across all optimized attachments. Cached for 5 minutes.
	 */
	public static function get_total_savings_bytes(): int {
		$cached = get_transient( 'kitgenix_image_optimizer_total_savings' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached via transient
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_KEY
			)
		);

		$total = 0;
		foreach ( $rows as $row ) {
			$data = maybe_unserialize( $row );
			if ( is_array( $data ) && isset( $data['savings_bytes'] ) ) {
				$total += (int) $data['savings_bytes'];
			}
		}

		set_transient( 'kitgenix_image_optimizer_total_savings', $total, 5 * MINUTE_IN_SECONDS );
		return $total;
	}

	/**
	 * Returns the quality value to use for a given MIME type.
	 * Respects the compression_level preset from Settings.
	 */
	private static function quality_for_mime( string $mime ): int {
		return match ( $mime ) {
			'image/webp' => Settings::effective_quality_webp(),
			default      => Settings::effective_quality_jpeg(),
		};
	}

	/**
	 * Whether a MIME type is supported for optimization.
	 */
	private static function is_optimizable_mime( string $mime ): bool {
		return in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ], true );
	}

	// -------------------------------------------------------------------------
	// Restore from backup
	// -------------------------------------------------------------------------

	/**
	 * AJAX: restore a single attachment from its backup file.
	 */
	public static function ajax_restore_backup(): void {
		check_ajax_referer( 'kitgenix_image_optimizer_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'kitgenix-image-optimizer' ) ], 403 );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid attachment ID.', 'kitgenix-image-optimizer' ) ], 400 );
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			wp_send_json_error( [ 'message' => __( 'Attachment file not found.', 'kitgenix-image-optimizer' ) ] );
		}

		$backup = (string) preg_replace( '/(\.([^.]+))$/', '-kitgenix-original$1', $file );
		if ( ! file_exists( $backup ) ) {
			wp_send_json_error( [ 'message' => __( 'No backup file found.', 'kitgenix-image-optimizer' ) ] );
		}

		if ( ! copy( $backup, $file ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not restore the backup file.', 'kitgenix-image-optimizer' ) ] );
		}

		// Remove the WebP sidecar if it exists – the restored original may differ.
		$webp_sidecar = $file . '.webp';
		if ( file_exists( $webp_sidecar ) ) {
			wp_delete_file( $webp_sidecar );
		}

		delete_post_meta( $attachment_id, self::META_KEY );
		delete_transient( 'kitgenix_image_optimizer_total_savings' );

		wp_send_json_success( [ 'message' => __( 'Image restored to original.', 'kitgenix-image-optimizer' ) ] );
	}

	// -------------------------------------------------------------------------
	// Helper: animated GIF detection
	// -------------------------------------------------------------------------

	/**
	 * Detect whether a GIF file contains multiple frames (is animated).
	 * Reads the file header and looks for more than one Graphic Control Extension block.
	 */
	private static function is_animated_gif( string $file ): bool {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- binary byte-scan requires low-level file handle; WP_Filesystem::get_contents() would load the full GIF into memory
		$fp = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $fp ) {
			return false;
		}

		$header = (string) fread( $fp, 6 );
		if ( 'GIF87a' !== $header && 'GIF89a' !== $header ) {
			fclose( $fp );
			return false;
		}

		$frame_count = 0;
		while ( ! feof( $fp ) ) {
			$byte = fread( $fp, 1 );
			if ( false === $byte || '' === $byte ) {
				break;
			}
			// 0x21 = extension introducer, 0xF9 = Graphic Control Extension label.
			if ( "\x21" === $byte ) {
				$label = fread( $fp, 1 );
				if ( "\xF9" === $label ) {
					++$frame_count;
					if ( $frame_count > 1 ) {
						fclose( $fp );
						return true;
					}
				}
			}
		}

		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return false;
	}

	// -------------------------------------------------------------------------
	// Helper: file exclusion rules
	// -------------------------------------------------------------------------

	/**
	 * Whether a file should be excluded from optimization based on size
	 * threshold or filename pattern settings.
	 */
	private static function is_excluded_file( string $file ): bool {
		// Skip files smaller than the configured KB threshold.
		$min_kb = Settings::exclude_below_kb();
		if ( $min_kb > 0 ) {
			$kb = filesize( $file );
			if ( false !== $kb && ( $kb / 1024 ) < $min_kb ) {
				return true;
			}
		}

		// Skip files matching one of the configured filename patterns.
		$patterns_raw = Settings::excluded_filename_patterns();
		if ( '' !== $patterns_raw ) {
			$filename = basename( $file );
			$patterns = array_filter( array_map( 'trim', explode( ',', $patterns_raw ) ) );
			foreach ( $patterns as $pattern ) {
				if ( str_contains( $filename, $pattern ) || fnmatch( $pattern, $filename ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
