<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles image optimization using WordPress's WP_Image_Editor (GD/Imagick).
 *
 * Processing hooks into `wp_generate_attachment_metadata` – AFTER core has
 * already applied `big_image_size_threshold` scaling and generated every
 * registered sub-size – rather than the raw upload filter. That ordering
 * matters: optimizing before core's own resize step meant this plugin was
 * silently pre-empting `big_image_size_threshold`'s scaled-original
 * mechanism instead of working with it. See `align_big_image_size_threshold()`.
 */
final class Optimizer {

	/** Meta key storing per-attachment optimization data. */
	public const META_KEY = '_kitgenix_image_optimizer';

	/** MIME types this plugin will ever attempt to touch. Matches Settings::OPTIMIZABLE_MIMES. */
	private const SUPPORTED_MIMES = Settings::OPTIMIZABLE_MIMES;

	public static function init(): void {
		if ( ! Settings::is_enabled() ) {
			return;
		}

		add_filter( 'big_image_size_threshold', [ self::class, 'align_big_image_size_threshold' ], 20 );

		if ( Settings::auto_optimize_on_upload() ) {
			add_filter( 'wp_generate_attachment_metadata', [ self::class, 'handle_generated_metadata' ], 999, 2 );
		}

		// AJAX handler for single-image optimization from media library.
		add_action( 'wp_ajax_kitgenix_image_optimizer_optimize_single', [ self::class, 'ajax_optimize_single' ] );

		// AJAX handler for restoring a backup.
		add_action( 'wp_ajax_kitgenix_image_optimizer_restore_backup', [ self::class, 'ajax_restore_backup' ] );

		// AJAX handler to force a full stats recount (admin-triggered only).
		add_action( 'wp_ajax_kitgenix_image_optimizer_recalculate_stats', [ self::class, 'ajax_recalculate_stats' ] );

		// REST endpoint for optimization status + wp/v2/media field.
		add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );

		Queue::init();
	}

	/**
	 * Keep core's own upload-time scaling in sync with our configured max
	 * dimensions instead of resizing a second time after core already has.
	 * When resizing is disabled here (both dimensions 0), core's sensible
	 * default (2560px) is left alone rather than turning scaling off entirely.
	 */
	public static function align_big_image_size_threshold( $threshold ) {
		$max_w = Settings::max_width();
		$max_h = Settings::max_height();

		if ( 0 === $max_w && 0 === $max_h ) {
			return $threshold;
		}

		$candidates = array_filter( [ $max_w, $max_h ] );
		if ( empty( $candidates ) ) {
			return $threshold;
		}

		return max( $candidates );
	}

	/**
	 * Fires after WordPress generates (or regenerates) attachment metadata –
	 * i.e. after all sub-sizes exist and any core scaling has already happened.
	 * Optimizes the main file and every sub-size in place; never touches
	 * core's separately-stored full-resolution `original_image` backup.
	 *
	 * @param array<string,mixed> $metadata
	 */
	public static function handle_generated_metadata( array $metadata, int $attachment_id ): array {
		self::optimize_attachment( $attachment_id );
		return $metadata;
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	/**
	 * AJAX: optimize a single attachment by ID.
	 */
	public static function ajax_optimize_single(): void {
		check_ajax_referer( 'kitgenix_image_optimizer_nonce', 'nonce' );

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! self::current_user_can_manage_attachment( $attachment_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'kitgenix-image-optimizer' ) ], 403 );
		}

		if ( ! $attachment_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid attachment ID.', 'kitgenix-image-optimizer' ) ], 400 );
		}

		// force=true: this is an explicit user click (Optimize/Re-optimize), so
		// it must always re-run even if the file's fingerprint is unchanged.
		$result = self::optimize_attachment( $attachment_id, true );
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
	 * AJAX: force an on-demand full recount of the Stats aggregate.
	 * Deliberately admin-triggered only – never runs automatically.
	 */
	public static function ajax_recalculate_stats(): void {
		check_ajax_referer( 'kitgenix_image_optimizer_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'kitgenix-image-optimizer' ) ], 403 );
		}

		Stats::recalculate();

		wp_send_json_success( [
			'optimized'         => Stats::optimized_count(),
			'failed'            => Stats::failed_count(),
			'savings_bytes'     => Stats::total_savings_bytes(),
			'savings_formatted' => size_format( Stats::total_savings_bytes() ),
		] );
	}

	/**
	 * Whether the current user may optimize/restore a given attachment.
	 * Requires the general upload capability AND edit rights on that specific
	 * post, so an Author-level user with `upload_files` can't act on other
	 * users' media by guessing attachment IDs.
	 */
	private static function current_user_can_manage_attachment( int $attachment_id ): bool {
		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}
		if ( $attachment_id <= 0 ) {
			// Let the caller report "invalid ID"; capability itself checks out.
			return true;
		}
		return current_user_can( 'edit_post', $attachment_id );
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

		register_rest_route(
			'kitgenix-image-optimizer/v1',
			'/capabilities',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function (): \WP_REST_Response {
					return new \WP_REST_Response( Capabilities::get_report(), 200 );
				},
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
	 */
	public static function rest_status( \WP_REST_Request $request ): \WP_REST_Response {
		$total       = self::count_total_optimizable_attachments();
		$optimized   = self::count_optimized_attachments();
		$unoptimized = max( 0, $total - $optimized );

		return new \WP_REST_Response(
			[
				'total'         => $total,
				'optimized'     => $optimized,
				'unoptimized'   => $unoptimized,
				'savings_bytes' => self::get_total_savings_bytes(),
				'queue'         => Queue::progress(),
			],
			200
		);
	}

	// -------------------------------------------------------------------------
	// Attachment-level orchestration
	// -------------------------------------------------------------------------

	/**
	 * Optimize the main file and every sub-size of an attachment.
	 *
	 * @return array{optimized_at:int,original_size:int,optimized_size:int,savings_bytes:int,savings_percent:float}|\WP_Error
	 */
	public static function optimize_attachment( int $attachment_id, bool $force = false ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			$message = __( 'Attachment file not found.', 'kitgenix-image-optimizer' );
			self::record_failure( $attachment_id, 'not_found', $message );
			return new \WP_Error( 'not_found', $message );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! self::is_optimizable_mime( $mime ) ) {
			$message = __( 'File type is not supported for optimization.', 'kitgenix-image-optimizer' );
			Event_Log::record( 'attachment-optimize-skipped', 'skipped', sprintf( '#%1$d – %2$s', $attachment_id, $message ), 'not_optimizable' );
			return new \WP_Error( 'not_optimizable', $message );
		}

		if ( in_array( $attachment_id, Settings::excluded_attachment_ids(), true ) ) {
			$message = __( 'This attachment is on the exclusion list.', 'kitgenix-image-optimizer' );
			Event_Log::record( 'attachment-optimize-skipped', 'skipped', sprintf( '#%1$d – %2$s', $attachment_id, $message ), 'excluded_by_rule' );
			return new \WP_Error( 'excluded_by_rule', $message );
		}

		$existing_meta = get_post_meta( $attachment_id, self::META_KEY, true );
		$existing_meta = is_array( $existing_meta ) ? $existing_meta : null;
		$existing_files = is_array( $existing_meta['files'] ?? null ) ? $existing_meta['files'] : [];

		/** @var array<string,array<string,mixed>> $files_result */
		$files_result   = [];
		$total_original = 0;
		$total_optimized = 0;
		$any_processed  = false;
		$last_error     = null;

		// Main file.
		$main_key = wp_basename( $file );
		$main_outcome = self::optimize_one_file( $file, $mime, $main_key, $existing_files[ $main_key ] ?? null, $force );
		if ( is_wp_error( $main_outcome ) ) {
			$last_error = $main_outcome;
		} else {
			$any_processed = true;
			$files_result[ $main_key ] = $main_outcome;
			$total_original  += $main_outcome['original_size'];
			$total_optimized += $main_outcome['optimized_size'];
		}

		// Registered sub-sizes.
		if ( Settings::optimize_thumbnails() ) {
			$attach_meta = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $attach_meta ) && ! empty( $attach_meta['sizes'] ) && is_array( $attach_meta['sizes'] ) ) {
				$base_dir = trailingslashit( dirname( $file ) );

				foreach ( $attach_meta['sizes'] as $size_data ) {
					if ( ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
						continue;
					}
					$thumb_key  = (string) $size_data['file'];
					$thumb_file = $base_dir . $thumb_key;
					$thumb_mime = isset( $size_data['mime-type'] ) ? (string) $size_data['mime-type'] : $mime;

					if ( ! file_exists( $thumb_file ) ) {
						continue;
					}

					$thumb_outcome = self::optimize_one_file( $thumb_file, $thumb_mime, $thumb_key, $existing_files[ $thumb_key ] ?? null, $force );
					if ( is_wp_error( $thumb_outcome ) ) {
						continue; // A single failed sub-size shouldn't fail the whole attachment.
					}

					$any_processed = true;
					$files_result[ $thumb_key ] = $thumb_outcome;
					$total_original  += $thumb_outcome['original_size'];
					$total_optimized += $thumb_outcome['optimized_size'];
				}
			}
		}

		// Carry forward any previously-processed files we didn't touch this run
		// (e.g. thumbnail optimization was toggled off after an earlier run) –
		// but only while the file still exists, so a deleted sub-size doesn't
		// keep inflating the aggregate totals forever.
		$carry_forward_dir = trailingslashit( dirname( $file ) );
		foreach ( $existing_files as $key => $data ) {
			if ( ! isset( $files_result[ $key ] ) && is_array( $data ) && file_exists( $carry_forward_dir . $key ) ) {
				$files_result[ $key ] = $data;
				$total_original  += (int) ( $data['original_size'] ?? 0 );
				$total_optimized += (int) ( $data['optimized_size'] ?? 0 );
			}
		}

		if ( ! $any_processed ) {
			$message = $last_error instanceof \WP_Error ? $last_error->get_error_message() : __( 'Nothing was optimized.', 'kitgenix-image-optimizer' );
			$code    = $last_error instanceof \WP_Error ? $last_error->get_error_code() : 'nothing_processed';
			$is_skip = in_array( $code, [ 'animated_gif_skipped', 'excluded_by_rule' ], true );

			if ( ! $is_skip ) {
				self::record_failure( $attachment_id, $code, $message );
			} else {
				Event_Log::record( 'attachment-optimize-skipped', 'skipped', sprintf( '#%1$d – %2$s', $attachment_id, $message ), $code );
			}

			return new \WP_Error( $code, $message );
		}

		$savings_bytes = max( 0, $total_original - $total_optimized );
		$savings_pct   = $total_original > 0 ? round( ( $savings_bytes / $total_original ) * 100, 1 ) : 0.0;

		$opt_meta = [
			'status'          => 'optimized',
			'optimized_at'    => time(),
			'original_size'   => $total_original,
			'optimized_size'  => $total_optimized,
			'savings_bytes'   => $savings_bytes,
			'savings_percent' => $savings_pct,
			'files'           => $files_result,
		];

		update_post_meta( $attachment_id, self::META_KEY, $opt_meta );
		Stats::record_optimized( $existing_meta, $total_original, $total_optimized );

		Event_Log::record(
			'image-optimized',
			'success',
			sprintf(
				'#%1$d – %2$s files, before: %3$s, after: %4$s, saved: %5$s (%6$.1f%%)',
				$attachment_id,
				number_format_i18n( count( $files_result ) ),
				size_format( $total_original ),
				size_format( $total_optimized ),
				size_format( $savings_bytes ),
				$savings_pct
			),
			'image-optimized'
		);

		return $opt_meta;
	}

	private static function record_failure( int $attachment_id, string $code, string $message ): void {
		$existing_meta = get_post_meta( $attachment_id, self::META_KEY, true );
		$existing_meta = is_array( $existing_meta ) ? $existing_meta : null;

		update_post_meta( $attachment_id, self::META_KEY, [
			'status'        => 'error',
			'error_message' => $message,
			'error_at'      => time(),
			'files'         => $existing_meta['files'] ?? [],
		] );
		Stats::record_failed( $existing_meta );

		Event_Log::record( 'attachment-optimize-failed', 'error', sprintf( '#%1$d – %2$s', $attachment_id, $message ), $code );
	}

	/**
	 * Optimize a single file belonging to an attachment, skipping it (returning
	 * the previous result unchanged) if its fingerprint matches the last run –
	 * this is what stops thumbnail regeneration from re-compressing files that
	 * never actually changed.
	 *
	 * @param array<string,mixed>|null $previous
	 * @param bool $force Skip the fingerprint short-circuit – used for explicit,
	 *                    user-initiated (re-)optimize actions, where re-running
	 *                    with new settings should always take effect. Automatic
	 *                    passes (upload, thumbnail regeneration) leave this false.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function optimize_one_file( string $file, string $mime, string $key, ?array $previous, bool $force = false ) {
		$fingerprint = self::fingerprint( $file );

		if ( ! $force && is_array( $previous ) && ( $previous['fingerprint'] ?? null ) === $fingerprint && ! empty( $previous['optimized'] ) ) {
			return $previous;
		}

		$outcome = self::optimize_file( $file, $mime );
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		clearstatcache( true, $file );

		return [
			'fingerprint'     => self::fingerprint( $file ),
			'original_size'   => $outcome['original_size'],
			'optimized_size'  => $outcome['optimized_size'],
			'kept_original'   => $outcome['kept_original'],
			'optimized'       => true,
			'optimized_at'    => time(),
		];
	}

	/** Cheap change-detection signature; a real content hash would be safer but far more expensive on large libraries. */
	private static function fingerprint( string $file ): string {
		clearstatcache( true, $file );
		$size  = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort
		$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort
		return ( false === $size ? '0' : (string) $size ) . ':' . ( false === $mtime ? '0' : (string) $mtime );
	}

	// -------------------------------------------------------------------------
	// Single-file optimization
	// -------------------------------------------------------------------------

	/**
	 * Optimize a file on disk. Returns savings data array on success, WP_Error on failure/skip.
	 * Writes are atomic (temp file + rename) so a killed process never leaves
	 * a half-written image in place, and a result that isn't actually smaller
	 * than the original is discarded rather than kept.
	 *
	 * @return array{original_size:int,optimized_size:int,savings_bytes:int,savings_percent:float,kept_original:bool}|\WP_Error
	 */
	public static function optimize_file( string $file, string $mime_type ): array|\WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- no WP_Filesystem equivalent without credential prompts
		if ( ! is_readable( $file ) || ! is_writable( $file ) ) {
			return new \WP_Error( 'not_writable', __( 'File is not readable or writable.', 'kitgenix-image-optimizer' ) );
		}

		if ( 'image/gif' === $mime_type && Settings::skip_animated_gif() && self::is_animated_gif( $file ) ) {
			return new \WP_Error( 'animated_gif_skipped', __( 'Animated GIF skipped to preserve animation.', 'kitgenix-image-optimizer' ) );
		}

		$dims = Image_Guard::probe( $file );

		if ( self::is_excluded_file( $file, $mime_type, $dims ) ) {
			return new \WP_Error( 'excluded_by_rule', __( 'File excluded by an exclusion rule.', 'kitgenix-image-optimizer' ) );
		}

		$guard = Image_Guard::validate( $file, $mime_type, $dims );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$size_before = filesize( $file );
		if ( false === $size_before ) {
			return new \WP_Error( 'filesize_read_failed', __( 'Could not read the file size.', 'kitgenix-image-optimizer' ) );
		}

		if ( Settings::backup_originals() ) {
			$backup = self::backup_path_for( $file );
			if ( '' !== $backup && $backup !== $file && ! file_exists( $backup ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort backup; failure is non-fatal
				@copy( $file, $backup );
			}
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return new \WP_Error( 'editor_init_failed', __( 'Could not initialize an image editor for this file.', 'kitgenix-image-optimizer' ) );
		}

		// Resize fallback. After align_big_image_size_threshold(), core handles
		// this for normal uploads; this remains for files that reach us outside
		// that flow (direct API sideloads, legacy attachments, CLI runs).
		$max_w = Settings::max_width();
		$max_h = Settings::max_height();

		if ( $max_w > 0 || $max_h > 0 ) {
			$size = $editor->get_size();
			if ( is_array( $size ) ) {
				$img_w = isset( $size['width'] ) ? (int) $size['width'] : 0;
				$img_h = isset( $size['height'] ) ? (int) $size['height'] : 0;

				if ( ( $max_w > 0 && $img_w > $max_w ) || ( $max_h > 0 && $img_h > $max_h ) ) {
					$resize_result = $editor->resize( $max_w ?: null, $max_h ?: null, false );
					if ( is_wp_error( $resize_result ) ) {
						return new \WP_Error( 'resize_failed', __( 'Failed to resize the image.', 'kitgenix-image-optimizer' ) );
					}
				}
			}
		}

		if ( Settings::strip_metadata() && method_exists( $editor, 'strip_meta' ) ) {
			try {
				$editor->strip_meta();
			} catch ( \Throwable $e ) {
				// Non-fatal: proceed without stripping if the editor can't do it for this file.
			}
		}

		$editor->set_quality( self::quality_for_mime( $mime_type ) );

		// Atomic save: write to a temp file in the same directory, verify it,
		// then rename over the original. rename() is atomic on the same filesystem,
		// so a process killed mid-write can never leave a corrupt/partial image.
		$extension = pathinfo( $file, PATHINFO_EXTENSION );
		$tmp_file  = $file . '.kitgenix-tmp-' . uniqid( '', true ) . ( $extension ? '.' . $extension : '' );

		$save_result = $editor->save( $tmp_file, $mime_type );
		if ( is_wp_error( $save_result ) ) {
			self::delete_quietly( $tmp_file );
			return new \WP_Error( 'save_failed', __( 'Failed to save the optimized image.', 'kitgenix-image-optimizer' ) );
		}

		$tmp_path = is_array( $save_result ) && ! empty( $save_result['path'] ) ? (string) $save_result['path'] : $tmp_file;
		if ( ! file_exists( $tmp_path ) ) {
			return new \WP_Error( 'save_failed', __( 'The optimized image was not written to disk.', 'kitgenix-image-optimizer' ) );
		}

		clearstatcache( true, $tmp_path );
		$tmp_size = filesize( $tmp_path );

		$kept_original = false;

		if ( false === $tmp_size || $tmp_size <= 0 || $tmp_size >= $size_before ) {
			// The optimized result isn't actually smaller – never trade quality
			// for a larger (or failed) file. Keep the original untouched.
			self::delete_quietly( $tmp_path );
			$kept_original = true;
			$size_after    = $size_before;
		} else {
			if ( ! self::replace_file( $tmp_path, $file ) ) {
				return new \WP_Error( 'save_failed', __( 'Failed to replace the original file with the optimized version.', 'kitgenix-image-optimizer' ) );
			}
			clearstatcache( true, $file );
			$raw_after  = filesize( $file );
			$size_after = false !== $raw_after ? (int) $raw_after : $size_before;
		}

		// Sidecar formats (WebP/AVIF) – best-effort, independent of the main
		// file's outcome above, and only attempted when the server can actually
		// encode the target format.
		self::maybe_generate_sidecars( $file, $mime_type );

		$savings_bytes = max( 0, (int) $size_before - $size_after );
		$savings_pct   = $size_before > 0 ? round( ( $savings_bytes / $size_before ) * 100, 1 ) : 0.0;

		return [
			'original_size'   => (int) $size_before,
			'optimized_size'  => $size_after,
			'savings_bytes'   => $savings_bytes,
			'savings_percent' => $savings_pct,
			'kept_original'   => $kept_original,
		];
	}

	private static function delete_quietly( string $path ): void {
		if ( '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Atomically replace $destination with $source: rename first, falling back to
	 * copy+delete-source when rename() can't (e.g. tmp file on a different device)
	 * – the same fallback WP_Filesystem::move() itself uses internally. $source is
	 * cleaned up on total failure so no tmp file is ever left behind.
	 */
	private static function replace_file( string $source, string $destination ): bool {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( $wp_filesystem->move( $source, $destination, true ) ) {
			return true;
		}

		self::delete_quietly( $source );
		return false;
	}

	private static function maybe_generate_sidecars( string $file, string $mime_type ): void {
		if ( ! in_array( $mime_type, [ 'image/jpeg', 'image/png' ], true ) ) {
			return;
		}

		if ( Settings::convert_to_webp() && Capabilities::supports_webp() ) {
			if ( ! self::generate_sidecar( $file, 'webp', 'image/webp', Settings::effective_quality_webp() ) ) {
				Event_Log::record(
					'webp-sidecar-skipped',
					'skipped',
					sprintf(
						/* translators: %s: file name */
						__( '%s – WebP copy could not be generated; the main image was still optimized.', 'kitgenix-image-optimizer' ),
						basename( $file )
					),
					'webp_sidecar_failed'
				);
			}
		}

		if ( Settings::convert_to_avif() && Capabilities::supports_avif() ) {
			if ( ! self::generate_sidecar( $file, 'avif', 'image/avif', Settings::effective_quality_avif() ) ) {
				Event_Log::record(
					'avif-sidecar-skipped',
					'skipped',
					sprintf(
						/* translators: %s: file name */
						__( '%s – AVIF copy could not be generated; the main image was still optimized.', 'kitgenix-image-optimizer' ),
						basename( $file )
					),
					'avif_sidecar_failed'
				);
			}
		}
	}

	/**
	 * Generate a `<file>.<ext>` sidecar copy in a different format (e.g. photo.jpg.webp).
	 * Uses a fresh editor instance so state from the primary save doesn't carry over.
	 */
	private static function generate_sidecar( string $file, string $ext, string $target_mime, int $quality ): bool {
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return false;
		}

		if ( Settings::strip_metadata() && method_exists( $editor, 'strip_meta' ) ) {
			try {
				$editor->strip_meta();
			} catch ( \Throwable $e ) {
				// Non-fatal.
			}
		}

		$editor->set_quality( $quality );

		$sidecar_path = $file . '.' . $ext;
		$tmp_path     = $file . '.kitgenix-tmp-' . uniqid( '', true ) . '.' . $ext;

		$result = $editor->save( $tmp_path, $target_mime );
		if ( is_wp_error( $result ) ) {
			self::delete_quietly( $tmp_path );
			return false;
		}

		$actual_tmp = is_array( $result ) && ! empty( $result['path'] ) ? (string) $result['path'] : $tmp_path;
		if ( ! file_exists( $actual_tmp ) ) {
			return false;
		}

		if ( ! self::replace_file( $actual_tmp, $sidecar_path ) ) {
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Counts & savings (backed by Stats – no per-page-load database scans)
	// -------------------------------------------------------------------------

	/**
	 * Count total image attachments eligible for optimization.
	 * `wp_count_attachments()` is cached by core, so this is cheap.
	 */
	public static function count_total_optimizable_attachments(): int {
		$counts = wp_count_attachments();
		$total  = 0;
		foreach ( Settings::OPTIMIZABLE_MIMES as $mime ) {
			if ( isset( $counts->$mime ) ) {
				$total += (int) $counts->$mime;
			}
		}
		return $total;
	}

	public static function count_optimized_attachments(): int {
		return Stats::optimized_count();
	}

	public static function count_unoptimized_attachments(): int {
		return max( 0, self::count_total_optimizable_attachments() - self::count_optimized_attachments() );
	}

	public static function get_total_savings_bytes(): int {
		return Stats::total_savings_bytes();
	}

	/**
	 * Returns the quality value to use for a given MIME type.
	 * Respects the compression_level preset from Settings, except for PNG,
	 * which always uses the dedicated "PNG Compression" (0-9) setting – that
	 * field was previously read from Settings but never actually passed to
	 * the image editor, so it had no effect regardless of what it was set to.
	 */
	private static function quality_for_mime( string $mime ): int {
		return match ( $mime ) {
			'image/webp' => Settings::effective_quality_webp(),
			'image/avif' => Settings::effective_quality_avif(),
			'image/png'  => self::png_quality_from_compression_level(),
			default      => Settings::effective_quality_jpeg(),
		};
	}

	/**
	 * Translates the 0-9 "PNG Compression" setting to the 0-100 quality scale
	 * `WP_Image_Editor::set_quality()` expects, following ImageMagick's own
	 * documented PNG quality convention (compression_level * 10 + filter_type,
	 * filter_type fixed at 5 for adaptive filtering).
	 */
	private static function png_quality_from_compression_level(): int {
		$level = Settings::quality_png_compression();
		return max( 0, min( 100, ( $level * 10 ) + 5 ) );
	}

	/**
	 * Whether a MIME type is supported for optimization.
	 */
	public static function is_optimizable_mime( string $mime ): bool {
		return in_array( $mime, self::SUPPORTED_MIMES, true ) && ! in_array( $mime, Settings::excluded_mime_types(), true );
	}

	// -------------------------------------------------------------------------
	// Backup + restore
	// -------------------------------------------------------------------------

	public static function backup_path_for( string $file ): string {
		return (string) preg_replace( '/(\.([^.]+))$/', '-kitgenix-original$1', $file );
	}

	/**
	 * AJAX: restore a single attachment from its backup file.
	 */
	public static function ajax_restore_backup(): void {
		check_ajax_referer( 'kitgenix_image_optimizer_nonce', 'nonce' );

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! self::current_user_can_manage_attachment( $attachment_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'kitgenix-image-optimizer' ) ], 403 );
		}

		if ( ! $attachment_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid attachment ID.', 'kitgenix-image-optimizer' ) ], 400 );
		}

		$result = self::restore_attachment( $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ] );
		}

		wp_send_json_success( [ 'message' => __( 'Image restored to original.', 'kitgenix-image-optimizer' ) ] );
	}

	/**
	 * Restore an attachment's main file from its backup, then ask WordPress to
	 * regenerate metadata/sub-sizes from the restored master – keeping
	 * `_wp_attachment_metadata` (dimensions, sub-size filenames) consistent
	 * with what's actually on disk instead of leaving stale optimized-size
	 * values behind. Freshly regenerated sub-sizes pass back through the
	 * normal `wp_generate_attachment_metadata` optimization hook.
	 *
	 * @return true|\WP_Error
	 */
	public static function restore_attachment( int $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			$message = __( 'Attachment file not found.', 'kitgenix-image-optimizer' );
			Event_Log::record( 'restore-failed', 'error', sprintf( '#%1$d – %2$s', $attachment_id, $message ), 'attachment_not_found' );
			return new \WP_Error( 'attachment_not_found', $message );
		}

		$backup = self::backup_path_for( $file );
		if ( ! file_exists( $backup ) ) {
			$message = __( 'No backup file found. Backups are only created when "Backup Originals" was enabled at optimization time.', 'kitgenix-image-optimizer' );
			Event_Log::record( 'restore-failed', 'error', sprintf( '#%1$d – %2$s', $attachment_id, $message ), 'no_backup_file' );
			return new \WP_Error( 'no_backup_file', $message );
		}

		$tmp_path = $file . '.kitgenix-restore-' . uniqid( '', true );
		if ( ! @copy( $backup, $tmp_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked immediately below
			$message = __( 'Could not read the backup file.', 'kitgenix-image-optimizer' );
			Event_Log::record( 'restore-failed', 'error', sprintf( '#%1$d – %2$s', $attachment_id, $message ), 'copy_failed' );
			return new \WP_Error( 'copy_failed', $message );
		}

		if ( ! self::replace_file( $tmp_path, $file ) ) {
			$message = __( 'Could not restore the backup file.', 'kitgenix-image-optimizer' );
			Event_Log::record( 'restore-failed', 'error', sprintf( '#%1$d – %2$s', $attachment_id, $message ), 'copy_failed' );
			return new \WP_Error( 'copy_failed', $message );
		}

		// Remove sidecar formats derived from the (now replaced) optimized file.
		foreach ( [ 'webp', 'avif' ] as $ext ) {
			$sidecar = $file . '.' . $ext;
			if ( file_exists( $sidecar ) ) {
				wp_delete_file( $sidecar );
			}
		}

		$existing_meta = get_post_meta( $attachment_id, self::META_KEY, true );
		$existing_meta = is_array( $existing_meta ) ? $existing_meta : null;

		delete_post_meta( $attachment_id, self::META_KEY );
		Stats::record_restored( $existing_meta );

		// Regenerate metadata/sub-sizes from the restored master so stored
		// dimensions and sub-size files match what's actually on disk again.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$new_metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( is_array( $new_metadata ) && ! empty( $new_metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $new_metadata );
		}

		Event_Log::record( 'image-restored', 'success', sprintf( '#%1$d – restored from backup', $attachment_id ), 'restored' );

		return true;
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
	 * Whether a file should be excluded from optimization, per the configured
	 * exclusion rules (MIME type, size range, filename pattern, dimensions,
	 * or containing directory).
	 *
	 * @param array{width:int,height:int}|null $dims Pre-probed dimensions, if available.
	 */
	private static function is_excluded_file( string $file, string $mime, ?array $dims = null ): bool {
		if ( in_array( $mime, Settings::excluded_mime_types(), true ) ) {
			return true;
		}

		$bytes = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort
		if ( false !== $bytes ) {
			$kb = $bytes / 1024;

			$min_kb = Settings::exclude_below_kb();
			if ( $min_kb > 0 && $kb < $min_kb ) {
				return true;
			}

			$max_kb = Settings::exclude_above_kb();
			if ( $max_kb > 0 && $kb > $max_kb ) {
				return true;
			}
		}

		$patterns_raw = Settings::excluded_filename_patterns();
		if ( '' !== $patterns_raw ) {
			$filename = basename( $file );
			$patterns = array_filter( array_map( 'trim', explode( ',', $patterns_raw ) ) );
			foreach ( $patterns as $pattern ) {
				if ( '' !== $pattern && ( str_contains( $filename, $pattern ) || fnmatch( $pattern, $filename ) ) ) {
					return true;
				}
			}
		}

		$directories = Settings::excluded_directories();
		if ( ! empty( $directories ) ) {
			$upload_dir  = wp_get_upload_dir();
			$base_dir    = trailingslashit( (string) ( $upload_dir['basedir'] ?? '' ) );
			$relative    = str_starts_with( $file, $base_dir ) ? substr( $file, strlen( $base_dir ) ) : $file;
			foreach ( $directories as $dir_pattern ) {
				if ( '' !== $dir_pattern && str_contains( $relative, $dir_pattern ) ) {
					return true;
				}
			}
		}

		if ( is_array( $dims ) ) {
			$min_w = Settings::excluded_min_width();
			$min_h = Settings::excluded_min_height();
			$max_w = Settings::excluded_max_width();
			$max_h = Settings::excluded_max_height();

			if ( $min_w > 0 && $dims['width'] < $min_w ) {
				return true;
			}
			if ( $min_h > 0 && $dims['height'] < $min_h ) {
				return true;
			}
			if ( $max_w > 0 && $dims['width'] > $max_w ) {
				return true;
			}
			if ( $max_h > 0 && $dims['height'] > $max_h ) {
				return true;
			}
		}

		return false;
	}
}
