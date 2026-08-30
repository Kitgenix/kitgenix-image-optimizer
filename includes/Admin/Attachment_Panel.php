<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

use KitgenixImageOptimizer\Core\Optimizer;

/**
 * Adds an "Image Optimizer" meta box to the attachment edit screen.
 *
 * Shows optimization status, savings statistics, and Optimize / Re-optimize /
 * Restore Original action buttons for the current attachment.
 */
final class Attachment_Panel {

	public static function init(): void {
		add_action( 'add_meta_boxes', [ self::class, 'register_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_scripts' ] );
	}

	// -------------------------------------------------------------------------
	// Meta box registration
	// -------------------------------------------------------------------------

	public static function register_meta_box(): void {
		add_meta_box(
			'kitgenix-image-optimizer-attachment-panel',
			__( 'Image Optimizer', 'kitgenix-image-optimizer' ),
			[ self::class, 'render_meta_box' ],
			'attachment',
			'side',
			'default'
		);
	}

	// -------------------------------------------------------------------------
	// Meta box render
	// -------------------------------------------------------------------------

	public static function render_meta_box( \WP_Post $post ): void {
		$attachment_id = (int) $post->ID;
		$mime          = (string) get_post_mime_type( $attachment_id );

		// Only for supported image types.
		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ], true ) ) {
			echo '<p class="description">' . esc_html__( 'Optimization is not available for this file type.', 'kitgenix-image-optimizer' ) . '</p>';
			return;
		}

		$opt_meta     = get_post_meta( $attachment_id, Optimizer::META_KEY, true );
		$is_optimized = is_array( $opt_meta ) && ! empty( $opt_meta['optimized_at'] );
		$is_error     = is_array( $opt_meta ) && isset( $opt_meta['status'] ) && 'error' === $opt_meta['status'];

		// Backup check.
		$file_path   = (string) get_attached_file( $attachment_id );
		$backup_path = $file_path ? Optimizer::backup_path_for( $file_path ) : '';
		$has_backup  = $backup_path && file_exists( $backup_path );

		$nonce     = wp_create_nonce( 'kitgenix_image_optimizer_nonce' );
		$status_id = 'kitgenix-attachment-panel-status-' . $attachment_id;

		echo '<div class="kitgenix-attachment-panel">';

		if ( $is_error ) {
			// ---- Error state ----
			$err_msg = isset( $opt_meta['error_message'] ) ? (string) $opt_meta['error_message'] : __( 'Optimization failed.', 'kitgenix-image-optimizer' );
			echo '<p><span class="kitgenix-badge danger">' . esc_html__( 'Error', 'kitgenix-image-optimizer' ) . '</span></p>';
			echo '<p class="description">' . esc_html( $err_msg ) . '</p>';
			echo '<p><button type="button" class="button button-small kitgenix-attachment-optimize" data-attachment-id="' . absint( $attachment_id ) . '" data-nonce="' . esc_attr( $nonce ) . '">'
				. esc_html__( 'Try Again', 'kitgenix-image-optimizer' )
				. '</button></p>';

		} elseif ( $is_optimized ) {
			// ---- Optimized state ----
			$original_size  = isset( $opt_meta['original_size'] ) ? (int) $opt_meta['original_size'] : 0;
			$optimized_size = isset( $opt_meta['optimized_size'] ) ? (int) $opt_meta['optimized_size'] : 0;
			$savings_bytes  = isset( $opt_meta['savings_bytes'] ) ? (int) $opt_meta['savings_bytes'] : 0;
			$savings_pct    = isset( $opt_meta['savings_percent'] ) ? (float) $opt_meta['savings_percent'] : 0.0;
			$optimized_at   = isset( $opt_meta['optimized_at'] ) ? (int) $opt_meta['optimized_at'] : 0;

			echo '<p><span class="kitgenix-badge success">' . esc_html__( 'Optimized', 'kitgenix-image-optimizer' ) . '</span></p>';

			if ( $savings_bytes > 0 ) {
				$date_label = '';
				if ( $optimized_at ) {
					$fmt        = (string) get_option( 'date_format' );
					$date_label = function_exists( 'wp_date' )
						? (string) wp_date( $fmt, $optimized_at )
						: (string) date_i18n( $fmt, $optimized_at ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date_i18n
				}

				echo '<table class="kitgenix-attachment-panel-stats">';
				echo '<tr><th>' . esc_html__( 'Original', 'kitgenix-image-optimizer' ) . '</th><td>' . esc_html( size_format( $original_size ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Optimized', 'kitgenix-image-optimizer' ) . '</th><td>' . esc_html( size_format( $optimized_size ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Saved', 'kitgenix-image-optimizer' ) . '</th>'
					. '<td><strong class="kitgenix-attachment-panel-saved">'
					. esc_html( number_format( $savings_pct, 1 ) . '% · ' . size_format( $savings_bytes ) )
					. '</strong></td></tr>';
				if ( $date_label ) {
					echo '<tr><th>' . esc_html__( 'Date', 'kitgenix-image-optimizer' ) . '</th><td>' . esc_html( $date_label ) . '</td></tr>';
				}
				echo '</table>';
			}

			echo '<div class="kitgenix-button-group">';
			echo '<button type="button" class="button button-small kitgenix-attachment-optimize" data-attachment-id="' . absint( $attachment_id ) . '" data-nonce="' . esc_attr( $nonce ) . '">'
				. esc_html__( 'Re-optimize', 'kitgenix-image-optimizer' )
				. '</button>';
			if ( $has_backup ) {
				echo '<button type="button" class="button button-small kitgenix-attachment-restore kitgenix-attachment-restore-btn" data-attachment-id="' . absint( $attachment_id ) . '" data-nonce="' . esc_attr( $nonce ) . '">'
					. esc_html__( 'Restore Original', 'kitgenix-image-optimizer' )
					. '</button>';
			}
			echo '</div>';

		} else {
			// ---- Not yet optimized ----
			echo '<p><span class="kitgenix-badge neutral">' . esc_html__( 'Not Optimized', 'kitgenix-image-optimizer' ) . '</span></p>';
			echo '<p class="description">' . esc_html__( 'This image has not been optimized yet.', 'kitgenix-image-optimizer' ) . '</p>';
			echo '<p><button type="button" class="button button-primary button-small kitgenix-attachment-optimize" data-attachment-id="' . absint( $attachment_id ) . '" data-nonce="' . esc_attr( $nonce ) . '">'
				. esc_html__( 'Optimize Now', 'kitgenix-image-optimizer' )
				. '</button></p>';
		}

		echo '<div id="' . esc_attr( $status_id ) . '" class="kitgenix-attachment-panel-status"></div>';
		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Scripts
	// -------------------------------------------------------------------------

	public static function enqueue_scripts( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->post_type !== 'attachment' ) {
			return;
		}

		wp_enqueue_style( 'kitgenix-image-optimizer-admin-ui' );

		$plugin_file = defined( 'KITGENIX_IMAGE_OPTIMIZER_FILE' ) ? (string) KITGENIX_IMAGE_OPTIMIZER_FILE : '';
		if ( $plugin_file ) {
			$base_dir = defined( 'KITGENIX_IMAGE_OPTIMIZER_DIR' ) ? (string) KITGENIX_IMAGE_OPTIMIZER_DIR : '';
			$css_file = $base_dir ? $base_dir . 'assets/css/admin.css' : '';
			$css_ver  = ( $css_file && file_exists( $css_file ) ) ? (string) filemtime( $css_file ) : null;

			wp_enqueue_style(
				'kitgenix-image-optimizer-admin',
				plugins_url( 'assets/css/admin.css', $plugin_file ),
				[ 'kitgenix-image-optimizer-admin-ui' ],
				$css_ver
			);
		}

		// Inline JS – AJAX handlers for Optimize and Restore buttons.
		$nonce_json    = wp_json_encode( wp_create_nonce( 'kitgenix_image_optimizer_nonce' ) );
		$ajax_url_json = wp_json_encode( admin_url( 'admin-ajax.php' ) );
		$t_optimizing  = wp_json_encode( __( 'Optimizing…', 'kitgenix-image-optimizer' ) );
		$t_done        = wp_json_encode( __( 'Optimized!', 'kitgenix-image-optimizer' ) );
		$t_fail        = wp_json_encode( __( 'Failed – please retry.', 'kitgenix-image-optimizer' ) );
		$t_restoring   = wp_json_encode( __( 'Restoring…', 'kitgenix-image-optimizer' ) );
		$t_confirm     = wp_json_encode( __( 'Restore original image? This will undo the optimization.', 'kitgenix-image-optimizer' ) );
		$t_restore_err = wp_json_encode( __( 'Restore failed.', 'kitgenix-image-optimizer' ) );
		$t_reoptimize  = wp_json_encode( __( 'Re-optimize', 'kitgenix-image-optimizer' ) );

		$js = "(function(\$){"
			. "var nonce={$nonce_json},ajaxUrl={$ajax_url_json};"
			. "\$(document).on('click','.kitgenix-attachment-optimize',function(){"
			. "var btn=\$(this),id=btn.data('attachment-id'),statusEl=\$('#kitgenix-attachment-panel-status-'+id);"
			. "btn.prop('disabled',true).text({$t_optimizing});"
			. "statusEl.text('').css('color','');"
			. "\$.post(ajaxUrl,{action:'kitgenix_image_optimizer_optimize_single',attachment_id:id,nonce:nonce},function(r){"
			. "if(r&&r.success){"
			. "var msg=r.data&&r.data.savings?r.data.savings:{$t_done};"
			. "statusEl.css('color','#1a7f4b').text(msg);"
			. "btn.prop('disabled',false).text({$t_reoptimize});"
			. "}else{"
			. "var err=r&&r.data?r.data:{$t_fail};"
			. "statusEl.css('color','#c00').text(err);"
			. "btn.prop('disabled',false);"
			. "}"
			. "}).fail(function(){statusEl.css('color','#c00').text({$t_fail});btn.prop('disabled',false);});"
			. "});"
			. "\$(document).on('click','.kitgenix-attachment-restore',function(){"
			. "var btn=\$(this),id=btn.data('attachment-id');"
			. "if(!confirm({$t_confirm}))return;"
			. "btn.prop('disabled',true).text({$t_restoring});"
			. "\$.post(ajaxUrl,{action:'kitgenix_image_optimizer_restore_backup',attachment_id:id,nonce:nonce},function(r){"
			. "if(r&&r.success){location.reload();}else{"
			. "alert({$t_restore_err});"
			. "btn.prop('disabled',false).text('Restore Original');}"
			. "});"
			. "});"
			. "}(jQuery));";

		wp_add_inline_script( 'jquery', $js );
	}
}
