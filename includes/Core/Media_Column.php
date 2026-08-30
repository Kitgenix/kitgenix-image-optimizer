<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an "Optimizer" column to the Media Library list view with per-image
 * status, savings, optimize, re-optimize, and restore actions.
 * Also registers a filter dropdown to filter attachments by optimization status.
 */
final class Media_Column {

	public static function init(): void {
		add_filter( 'manage_media_columns', [ self::class, 'add_column' ] );
		add_action( 'manage_media_custom_column', [ self::class, 'render_column' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ self::class, 'add_filter_dropdown' ] );
		add_action( 'pre_get_posts', [ self::class, 'apply_filter' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_scripts' ] );
                add_action( 'admin_notices', [ self::class, 'render_media_library_bar' ] );
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function add_column( array $columns ): array {
		$columns['kitgenix_optimizer'] = __( 'Compression', 'kitgenix-image-optimizer' );
		return $columns;
	}

	public static function render_column( string $column_name, int $attachment_id ): void {
		if ( 'kitgenix_optimizer' !== $column_name ) {
			return;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ], true ) ) {
			echo '<span class="kitgenix-mcol-na">' . esc_html__( '–', 'kitgenix-image-optimizer' ) . '</span>';
			return;
		}

		$meta = get_post_meta( $attachment_id, Optimizer::META_KEY, true );

		if ( ! is_array( $meta ) ) {
			// Unoptimized.
			printf(
				'<a href="#" class="button button-small kitgenix-mcol-optimize" data-id="%d">%s</a>',
				absint( $attachment_id ),
				esc_html__( 'Optimize', 'kitgenix-image-optimizer' )
			);
			return;
		}

		$status  = isset( $meta['status'] ) ? (string) $meta['status'] : 'optimized';
		$savings = isset( $meta['savings_bytes'] ) ? (int) $meta['savings_bytes'] : 0;
		$pct     = isset( $meta['savings_percent'] ) ? (float) $meta['savings_percent'] : 0.0;

		if ( 'error' === $status ) {
			$err_msg = isset( $meta['error_message'] ) ? (string) $meta['error_message'] : __( 'Optimization failed', 'kitgenix-image-optimizer' );
			printf(
				'<span class="kitgenix-mcol-error" title="%s">%s</span>',
				esc_attr( $err_msg ),
				esc_html__( 'Error', 'kitgenix-image-optimizer' )
			);
			printf(
				'<br><a href="#" class="kitgenix-mcol-optimize" data-id="%d">%s</a>',
				absint( $attachment_id ),
				esc_html__( 'Re-optimize', 'kitgenix-image-optimizer' )
			);
			return;
		}

		// Optimized – show savings.
		if ( $savings > 0 ) {
			printf(
				'<span class="kitgenix-mcol-optimized">%s · %s</span>',
				esc_html( number_format( $pct, 1 ) . '%' ),
				esc_html( size_format( $savings ) )
			);
		} else {
			echo '<span class="kitgenix-mcol-optimized">' . esc_html__( 'Optimized', 'kitgenix-image-optimizer' ) . '</span>';
		}

		// Restore link only when backup file exists.
		$file   = (string) get_attached_file( $attachment_id );
		$backup = $file ? Optimizer::backup_path_for( $file ) : '';

		if ( $backup && file_exists( $backup ) ) {
			printf(
				'<br><a href="#" class="kitgenix-mcol-restore" data-id="%d">%s</a>',
				absint( $attachment_id ),
				esc_html__( 'Restore original', 'kitgenix-image-optimizer' )
			);
		} else {
			printf(
				'<br><a href="#" class="kitgenix-mcol-optimize kitgenix-mcol-reoptimize" data-id="%d">%s</a>',
				absint( $attachment_id ),
				esc_html__( 'Re-optimize', 'kitgenix-image-optimizer' )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Filter dropdown
	// -------------------------------------------------------------------------

	public static function add_filter_dropdown(): void {
		global $pagenow;
		if ( 'upload.php' !== $pagenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['kitgenix_optimizer_filter'] ) ? sanitize_key( wp_unslash( $_GET['kitgenix_optimizer_filter'] ) ) : '';

		$options = [
			''            => __( 'All images (compression)', 'kitgenix-image-optimizer' ),
			'optimized'   => __( 'Optimized', 'kitgenix-image-optimizer' ),
			'unoptimized' => __( 'Not optimized', 'kitgenix-image-optimizer' ),
			'error'       => __( 'Errors', 'kitgenix-image-optimizer' ),
		];

		echo '<select name="kitgenix_optimizer_filter" id="kitgenix-optimizer-filter">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public static function apply_filter( \WP_Query $query ): void {
		global $pagenow;
		if ( 'upload.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['kitgenix_optimizer_filter'] ) ? sanitize_key( wp_unslash( $_GET['kitgenix_optimizer_filter'] ) ) : '';
		if ( '' === $filter ) {
			return;
		}

		if ( 'optimized' === $filter || 'error' === $filter ) {
			$query->set(
				'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					[
						'key'     => Optimizer::META_KEY,
						'compare' => 'EXISTS',
					],
				]
			);
			// For error, do a secondary PHP-level filter since status is inside serialized meta.
			if ( 'error' === $filter ) {
				add_filter( 'the_posts', [ self::class, 'php_filter_errors' ], 10, 2 );
			}
		} elseif ( 'unoptimized' === $filter ) {
			$query->set(
				'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					[
						'key'     => Optimizer::META_KEY,
						'compare' => 'NOT EXISTS',
					],
				]
			);
		}
	}

	/**
	 * @param \WP_Post[] $posts
	 * @return \WP_Post[]
	 */
	public static function php_filter_errors( array $posts, \WP_Query $query ): array {
		remove_filter( 'the_posts', [ self::class, 'php_filter_errors' ], 10 );
		return array_values( array_filter(
			$posts,
			static function ( \WP_Post $post ): bool {
				$meta = get_post_meta( $post->ID, Optimizer::META_KEY, true );
				return is_array( $meta ) && isset( $meta['status'] ) && 'error' === $meta['status'];
			}
		) );
	}

	// -------------------------------------------------------------------------
	// Inline scripts & styles
	// -------------------------------------------------------------------------

	public static function enqueue_scripts( string $hook ): void {
		if ( 'upload.php' !== $hook ) {
			return;
		}

		$plugin_file = defined( 'KITGENIX_IMAGE_OPTIMIZER_FILE' ) ? (string) KITGENIX_IMAGE_OPTIMIZER_FILE : '';
		$base_dir    = defined( 'KITGENIX_IMAGE_OPTIMIZER_DIR' ) ? (string) KITGENIX_IMAGE_OPTIMIZER_DIR : '';

		// Enqueue shared UI CSS so design tokens are available.
		wp_enqueue_style( 'kitgenix-image-optimizer-admin-ui' );

		// Enqueue plugin admin CSS (contains .kitgenix-media-library-bar styles).
		// May already be registered if Settings_UI ran first; register if not.
		if ( ! wp_style_is( 'kitgenix-image-optimizer-admin', 'registered' ) ) {
			$css_file = $base_dir ? $base_dir . 'assets/css/admin.css' : '';
			$ver      = ( $css_file && file_exists( $css_file ) ) ? (string) filemtime( $css_file ) : null;
			wp_register_style(
				'kitgenix-image-optimizer-admin',
				plugins_url( 'assets/css/admin.css', $plugin_file ),
				[ 'kitgenix-image-optimizer-admin-ui' ],
				$ver
			);
		}
		wp_enqueue_style( 'kitgenix-image-optimizer-admin' );

		wp_add_inline_style( 'list-tables', self::inline_css() );

		// We piggyback on jQuery which is always enqueued on admin pages.
		wp_add_inline_script(
			'jquery',
			self::inline_js(
				wp_create_nonce( 'kitgenix_image_optimizer_nonce' ),
				admin_url( 'admin-ajax.php' )
			)
		);
	}

	private static function inline_css(): string {
		return '
.column-kitgenix_optimizer { width: 130px; }
.kitgenix-mcol-optimized { color: #1a7f4b; font-weight: 600; font-size: 12px; }
.kitgenix-mcol-error { color: #d63638; font-weight: 600; font-size: 12px; cursor: help; }
.kitgenix-mcol-na { color: #8c8f94; font-size: 12px; }
.kitgenix-mcol-optimize, .kitgenix-mcol-restore { font-size: 11px; }
.kitgenix-mcol-restore { color: #9c3b4b !important; }
.kitgenix-mcol-restore:hover { color: #d63638 !important; }
.kitgenix-mcol-spinning { opacity: .6; pointer-events: none; }
';
	}

	private static function inline_js( string $nonce, string $ajax_url ): string {
		$n  = wp_json_encode( $nonce );
		$u  = wp_json_encode( $ajax_url );
		$t1 = wp_json_encode( __( 'Optimizing…', 'kitgenix-image-optimizer' ) );
		$t2 = wp_json_encode( __( 'Restoring…', 'kitgenix-image-optimizer' ) );
		$t3 = wp_json_encode( __( 'Restore original image? This will undo the optimization.', 'kitgenix-image-optimizer' ) );
		$t4 = wp_json_encode( __( 'Re-optimize', 'kitgenix-image-optimizer' ) );
		$t5 = wp_json_encode( __( 'Error', 'kitgenix-image-optimizer' ) );
		$t6 = wp_json_encode( __( 'Optimize', 'kitgenix-image-optimizer' ) );

		$lines = [
			'(function($){',
			'  var nonce   = ' . $n . ';',
			'  var ajaxUrl = ' . $u . ';',
			'',
			'  function makeOptimizeLink(id) {',
			'    return \'<a href="#" class="kitgenix-mcol-optimize" data-id="\'+id+\'">\'+ ' . $t6 . ' +\'</a>\';',
			'  }',
			'  function makeReoptimizeLink(id) {',
			'    return \'<a href="#" class="kitgenix-mcol-optimize kitgenix-mcol-reoptimize" data-id="\'+id+\'">\'+ ' . $t4 . ' +\'</a>\';',
			'  }',
			'',
			'  $(document).on(\'click\', \'.kitgenix-mcol-optimize\', function(e){',
			'    e.preventDefault();',
			'    var a    = $(this);',
			'    var id   = a.data(\'id\');',
			'    var cell = a.closest(\'td\');',
			'    a.text(' . $t1 . ').addClass(\'kitgenix-mcol-spinning\');',
			'',
			'    $.post(ajaxUrl, {',
			'      action: \'kitgenix_image_optimizer_optimize_single\',',
			'      nonce: nonce,',
			'      attachment_id: id',
			'    }).done(function(res){',
			'      if (res.success && res.data) {',
			'        var pct  = parseFloat(res.data.savings_percent||0).toFixed(1);',
			'        var fmt  = res.data.savings_formatted || \'\';',
			'        cell.html(\'<span class="kitgenix-mcol-optimized">\'+pct+\'% \xb7 \'+fmt+\'</span><br>\'+makeReoptimizeLink(id));',
			'      } else {',
			'        var msg = (res.data && res.data.message) ? res.data.message : ' . $t5 . ';',
			'        cell.html(\'<span class="kitgenix-mcol-error" title="\'+msg+\'">\' + ' . $t5 . ' + \'</span><br>\'+makeReoptimizeLink(id));',
			'      }',
			'    }).fail(function(){',
			'      cell.html(\'<span class="kitgenix-mcol-error">\' + ' . $t5 . ' + \'</span><br>\'+makeReoptimizeLink(id));',
			'    });',
			'  });',
			'',
			'  $(document).on(\'click\', \'.kitgenix-mcol-restore\', function(e){',
			'    e.preventDefault();',
			'    if (!confirm(' . $t3 . ')) return;',
			'    var a    = $(this);',
			'    var id   = a.data(\'id\');',
			'    var cell = a.closest(\'td\');',
			'    a.text(' . $t2 . ').addClass(\'kitgenix-mcol-spinning\');',
			'',
			'    $.post(ajaxUrl, {',
			'      action: \'kitgenix_image_optimizer_restore_backup\',',
			'      nonce: nonce,',
			'      attachment_id: id',
			'    }).done(function(res){',
			'      if (res.success) {',
			'        cell.html(makeOptimizeLink(id));',
			'      } else {',
			'        var msg = (res.data && res.data.message) ? res.data.message : \'Failed\';',
			'        a.text(msg).removeClass(\'kitgenix-mcol-spinning\');',
			'      }',
			'    }).fail(function(){',
			'      a.text(\'Failed\').removeClass(\'kitgenix-mcol-spinning\');',
			'    });',
			'  });',
			'})(jQuery);',
		];
		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// Media Library stats bar
	// -------------------------------------------------------------------------

	/**
	 * Render a stats summary bar at the top of the Media Library (upload.php).
	 */
	public static function render_media_library_bar(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'upload' ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$total     = Optimizer::count_total_optimizable_attachments();
		$optimized = Optimizer::count_optimized_attachments();
		$savings   = Optimizer::get_total_savings_bytes();
		$pending   = max( 0, $total - $optimized );
		$pct       = $total > 0 ? (int) round( ( $optimized / $total ) * 100 ) : 0;

		$plugin_file = defined( 'KITGENIX_IMAGE_OPTIMIZER_FILE' ) ? (string) KITGENIX_IMAGE_OPTIMIZER_FILE : '';
		$logo        = $plugin_file ? plugins_url( 'assets/images/logos/kitgenix-primary-favicon.svg', $plugin_file ) : '';
		$bulk_url    = admin_url( 'admin.php?page=kitgenix-image-optimizer&tab=images' );

		echo '<div class="kitgenix-media-library-bar">';
		echo '<div class="kitgenix-media-library-bar__inner">';

		// Brand label.
		echo '<div class="kitgenix-media-library-bar__brand">';
		if ( $logo ) {
			echo '<img src="' . esc_url( $logo ) . '" width="16" height="16" alt="" aria-hidden="true" />';
		}
		echo '<span>' . esc_html__( 'Image Optimizer', 'kitgenix-image-optimizer' ) . '</span>';
		echo '</div>';

		// Stats.
		echo '<div class="kitgenix-media-library-bar__stats">';

		echo '<span class="kitgenix-media-library-bar__stat">'
			. '<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong> '
			. esc_html__( 'images in library', 'kitgenix-image-optimizer' )
			. '</span>';

		echo '<span class="kitgenix-media-library-bar__sep" aria-hidden="true">&middot;</span>';

		echo '<span class="kitgenix-media-library-bar__stat kitgenix-media-library-bar__stat--optimized">'
			. '<strong>' . esc_html( number_format_i18n( $optimized ) ) . '</strong> '
			. esc_html__( 'optimized', 'kitgenix-image-optimizer' )
			. ' <span class="kitgenix-media-library-bar__pct">(' . esc_html( (string) $pct ) . '%)</span>'
			. '</span>';

		echo '<span class="kitgenix-media-library-bar__sep" aria-hidden="true">&middot;</span>';

		echo '<span class="kitgenix-media-library-bar__stat">'
			. '<strong>' . esc_html( number_format_i18n( $pending ) ) . '</strong> '
			. esc_html__( 'pending', 'kitgenix-image-optimizer' )
			. '</span>';

		echo '<span class="kitgenix-media-library-bar__sep" aria-hidden="true">&middot;</span>';

		echo '<span class="kitgenix-media-library-bar__stat kitgenix-media-library-bar__stat--savings">'
			. '<strong>' . esc_html( size_format( $savings ) ) . '</strong> '
			. esc_html__( 'space saved', 'kitgenix-image-optimizer' )
			. '</span>';

		echo '</div>';

		// CTA.
		echo '<div class="kitgenix-media-library-bar__cta">'
			. ( $pending > 0
	? '<a href="' . esc_url( $bulk_url ) . '" class="button button-small">' . esc_html__( 'Bulk Optimize &rarr;', 'kitgenix-image-optimizer' ) . '</a>'
	: '<a href="' . esc_url( $bulk_url ) . '" class="button button-small">' . esc_html__( 'Re-Optimize All &rarr;', 'kitgenix-image-optimizer' ) . '</a>'
)
			. '</div>';

		echo '</div>';
		echo '</div>';
	}
}
