<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

use KitgenixImageOptimizer\Core\Event_Log;
use KitgenixImageOptimizer\Core\Optimizer;
use KitgenixImageOptimizer\Core\Settings;

final class Settings_UI {
	/** @var string|null */
	private static $page_hook = null;

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_menu' ], 50 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ], 20 );
		add_action( 'admin_notices', [ self::class, 'render_admin_notices' ] );
		add_action( 'admin_post_kitgenix_image_optimizer_clear_event_log', [ self::class, 'handle_clear_event_log' ] );
		add_action( 'update_option_' . Settings::OPTION_NAME, [ self::class, 'on_settings_saved' ], 10, 3 );
	}

	private static function is_settings_screen_now(): bool {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && ! empty( self::$page_hook ) && $screen->id === self::$page_hook ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return $page === 'kitgenix-image-optimizer';
	}

	public static function render_admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::is_settings_screen_now() ) {
			return;
		}

		if ( function_exists( 'settings_errors' ) ) {
			settings_errors();
		}
	}

	public static function register_menu(): void {
		if ( function_exists( '\\kitgenix_image_optimizer_ensure_admin_menu' ) ) {
			\kitgenix_image_optimizer_ensure_admin_menu();
		}

		self::$page_hook = add_submenu_page(
			'kitgenix',
			__( 'Kitgenix Image Optimizer', 'kitgenix-image-optimizer' ),
			__( 'Image Optimizer', 'kitgenix-image-optimizer' ),
			'manage_options',
			'kitgenix-image-optimizer',
			[ self::class, 'render_page' ]
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( empty( self::$page_hook ) || $hook !== self::$page_hook ) {
			return;
		}

		$ver          = defined( 'KITGENIX_IMAGE_OPTIMIZER_VERSION' ) ? (string) KITGENIX_IMAGE_OPTIMIZER_VERSION : null;
		$base_dir     = defined( 'KITGENIX_IMAGE_OPTIMIZER_DIR' ) ? (string) KITGENIX_IMAGE_OPTIMIZER_DIR : '';
		$plugin_file  = defined( 'KITGENIX_IMAGE_OPTIMIZER_FILE' ) ? (string) KITGENIX_IMAGE_OPTIMIZER_FILE : '';

		$admin_css_file = $base_dir ? $base_dir . 'assets/css/admin.css' : '';
		$admin_js_file  = $base_dir ? $base_dir . 'assets/js/admin.js' : '';
		$ui_js_file     = $base_dir ? $base_dir . 'assets/js/kitgenix-admin-tabs.js' : '';

		$admin_css_ver = ( $admin_css_file && file_exists( $admin_css_file ) ) ? (string) filemtime( $admin_css_file ) : $ver;
		$admin_js_ver  = ( $admin_js_file && file_exists( $admin_js_file ) ) ? (string) filemtime( $admin_js_file ) : $ver;
		$ui_js_ver     = ( $ui_js_file && file_exists( $ui_js_file ) ) ? (string) filemtime( $ui_js_file ) : $ver;

		wp_enqueue_style( 'kitgenix-admin-ui' );

		wp_enqueue_style(
			'kitgenix-image-optimizer-admin',
			plugins_url( 'assets/css/admin.css', $plugin_file ),
			[ 'kitgenix-admin-ui' ],
			$admin_css_ver
		);

		wp_enqueue_script(
			'kitgenix-admin-tabs',
			plugins_url( 'assets/js/kitgenix-admin-tabs.js', $plugin_file ),
			[],
			$ui_js_ver,
			true
		);

		wp_enqueue_script(
			'kitgenix-image-optimizer-admin',
			plugins_url( 'assets/js/admin.js', $plugin_file ),
			[ 'jquery' ],
			$admin_js_ver,
			true
		);

		$total_optimizable = Optimizer::count_total_optimizable_attachments();
		$total_optimized   = Optimizer::count_optimized_attachments();
		$savings_bytes     = Optimizer::get_total_savings_bytes();

		wp_localize_script(
			'kitgenix-image-optimizer-admin',
			'kitgenix_image_optimizer_admin',
			[
				'ajax_url'          => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'kitgenix_image_optimizer_nonce' ),
				'restore_nonce'     => wp_create_nonce( 'kitgenix_image_optimizer_nonce' ),
				'total'             => $total_optimizable,
				'optimized'         => $total_optimized,
				'unoptimized'       => max( 0, $total_optimizable - $total_optimized ),
				'savings_bytes'     => $savings_bytes,
				'savings_formatted' => size_format( $savings_bytes ),
				'i18n'              => [
					'optimizing'   => __( 'Optimizing…', 'kitgenix-image-optimizer' ),
					'done'         => __( 'All done! Every image has been optimized.', 'kitgenix-image-optimizer' ),
					'allOptimized' => __( 'All Images Optimized', 'kitgenix-image-optimizer' ),
					'error'        => __( 'An error occurred. Please try again.', 'kitgenix-image-optimizer' ),
					'bulkStart'           => __( 'Start Bulk Optimization', 'kitgenix-image-optimizer' ),
					'bulkStop'            => __( 'Stop', 'kitgenix-image-optimizer' ),
					'bulkReoptimizeStart' => __( 'Bulk Re-Optimize', 'kitgenix-image-optimizer' ),
					'reoptimizeDone'      => __( 'All images have been re-optimized.', 'kitgenix-image-optimizer' ),
					'optimized'    => __( 'Optimized', 'kitgenix-image-optimizer' ),
					'pending'      => __( 'Pending', 'kitgenix-image-optimizer' ),
					'saved'        => __( 'saved', 'kitgenix-image-optimizer' ),
					'of'           => __( 'of', 'kitgenix-image-optimizer' ),
					'images'       => __( 'images', 'kitgenix-image-optimizer' ),
					'restoring'    => __( 'Restoring…', 'kitgenix-image-optimizer' ),
					'restored'     => __( 'Restored — ready to re-optimize', 'kitgenix-image-optimizer' ),
					'restoreFail'  => __( 'Restore failed', 'kitgenix-image-optimizer' ),
					'restoreConfirm' => __( 'Restore original image? This will undo the optimization.', 'kitgenix-image-optimizer' ),
				],
			]
		);

		// Enqueue inline script for compression card UI.
		$compression_card_js = "document.querySelectorAll('.kitgenix-compression-card input[type=\"radio\"]').forEach(function(r){r.addEventListener('change',function(){document.querySelectorAll('.kitgenix-compression-card').forEach(function(c){c.classList.toggle('is-selected',c.querySelector('input[type=\"radio\"]').checked);});});});";
		wp_add_inline_script( 'kitgenix-image-optimizer-admin', $compression_card_js );
	}

	/**
	 * Handle settings saved hook — log the event.
	 *
	 * @param mixed $old_value
	 * @param mixed $new_value
	 * @param string $option
	 */
	public static function on_settings_saved( $old_value, $new_value, string $option ): void {
		Event_Log::record( 'settings-saved', 'success', 'Settings updated via admin.' );
	}

	/**
	 * Handle the clear-log admin-post action.
	 */
	public static function handle_clear_event_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'kitgenix-image-optimizer' ) );
		}

		check_admin_referer( 'kitgenix_image_optimizer_clear_event_log' );
		Event_Log::clear();

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'kitgenix-image-optimizer', 'tab' => 'log', 'log-cleared' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = Settings::get_settings();
		$ver         = defined( 'KITGENIX_IMAGE_OPTIMIZER_VERSION' ) ? (string) KITGENIX_IMAGE_OPTIMIZER_VERSION : '';
		$plugin_file = defined( 'KITGENIX_IMAGE_OPTIMIZER_FILE' ) ? (string) KITGENIX_IMAGE_OPTIMIZER_FILE : '';

		$default_tab = 'settings';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['tab'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$maybe_tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
			if ( in_array( $maybe_tab, [ 'settings', 'images', 'support', 'log' ], true ) ) {
				$default_tab = $maybe_tab;
			}
		}

		$logo        = plugins_url( 'assets/images/logos/kitgenix-favicon-purple.svg', $plugin_file );
		$social_base = plugins_url( 'assets/images/social-media/', $plugin_file );
		$base_tab_url = admin_url( 'admin.php?page=' . rawurlencode( 'kitgenix-image-optimizer' ) );

		echo '<div class="wrap kitgenix-admin-app kitgenix-image-optimizer-use-top-tabs kitgenix-image-optimizer-scope" data-kitgenix-tabs data-kitgenix-default-tab="' . esc_attr( $default_tab ) . '" id="kitgenix-image-optimizer-admin-app">';

		// Header.
		echo '<div class="kitgenix-image-optimizer-settings-intro kitgenix-settings-header">'
			. '<div class="kitgenix-settings-header-row">'
			. '<div class="kitgenix-settings-header-main">'
			. '<div class="kitgenix-image-optimizer-settings-brand kitgenix-settings-brand">'
			. '<img class="kitgenix-image-optimizer-settings-logo kitgenix-settings-logo" src="' . esc_url( $logo ) . '" alt="' . esc_attr__( 'Kitgenix', 'kitgenix-image-optimizer' ) . '" />'
			. '<h1 class="kitgenix-image-optimizer-admin-title">' . esc_html__( 'Kitgenix Image Optimizer', 'kitgenix-image-optimizer' ) . '</h1>'
			. '</div>'
			. '<p>' . esc_html__( 'Compress and resize images on upload or in bulk to keep your site fast without sacrificing quality.', 'kitgenix-image-optimizer' ) . '</p>'
			. '<div class="kitgenix-image-optimizer-settings-meta kitgenix-settings-meta">'
			. '<span class="kitgenix-image-optimizer-settings-version kitgenix-settings-version" aria-label="Plugin version">v' . esc_html( $ver ) . '</span>'
			. '</div>'
			. '</div>'
			. '<div class="kitgenix-settings-header-actions">'
			. '<div class="kitgenix-intro-links kitgenix-image-optimizer-intro-links">'
			. '<a href="' . esc_url( 'https://wordpress.org/plugins/kitgenix-image-optimizer/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'kitgenix-image-optimizer' ) . '</a>'
			. '<a href="' . esc_url( 'https://wordpress.org/support/plugin/kitgenix-image-optimizer/reviews/#new-post' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Review Plugin', 'kitgenix-image-optimizer' ) . '</a>'
			. '<a href="' . esc_url( 'https://wordpress.org/support/plugin/kitgenix-image-optimizer/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support Request', 'kitgenix-image-optimizer' ) . '</a>'
			. '<a href="' . esc_url( 'https://buymeacoffee.com/kitgenix' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support Kitgenix', 'kitgenix-image-optimizer' ) . '</a>'
			. '</div>'
			. '<div class="kitgenix-social-links kitgenix-social-links--icons">'
			. '<a href="https://wordpress.org/plugins/kitgenix-image-optimizer/" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="' . esc_url( $social_base . 'globe-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Website</span></a>'
			. '<a href="https://www.facebook.com/groups/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook Community" title="Facebook Community"><img src="' . esc_url( $social_base . 'facebook-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook Community</span></a>'
			. '<a href="https://www.facebook.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><img src="' . esc_url( $social_base . 'facebook-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook</span></a>'
			. '<a href="https://www.instagram.com/kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram"><img src="' . esc_url( $social_base . 'instagram-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Instagram</span></a>'
			. '<a href="https://www.youtube.com/@Kitgenix" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube"><img src="' . esc_url( $social_base . 'youtube-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">YouTube</span></a>'
			. '<a href="https://www.reddit.com/r/Kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Reddit" title="Reddit"><img src="' . esc_url( $social_base . 'reddit-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Reddit</span></a>'
			. '<a href="https://www.linkedin.com/company/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><img src="' . esc_url( $social_base . 'linkedin-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">LinkedIn</span></a>'
			. '<a href="https://x.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="X" title="X"><img src="' . esc_url( $social_base . 'x-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">X</span></a>'
			. '<a href="https://www.tiktok.com/@kitgenix" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok"><img src="' . esc_url( $social_base . 'tiktok-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">TikTok</span></a>'
			. '<a href="https://github.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="GitHub"><img src="' . esc_url( $social_base . 'github-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">GitHub</span></a>'
			. '</div>'
			. '</div>'
			. '</div>'
			. '</div>';

		// Tab nav.
		echo '<h2 class="nav-tab-wrapper kitgenix-nav-tabs" aria-label="Settings navigation">'
			. '<a class="nav-tab kitgenix-tab-trigger ' . ( $default_tab === 'settings' ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base_tab_url . '&tab=settings#kitgenix-tab-settings' ) . '" data-kitgenix-tab="settings">' . esc_html__( 'Settings', 'kitgenix-image-optimizer' ) . '</a>'
			. '<a class="nav-tab kitgenix-tab-trigger ' . ( $default_tab === 'images' ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base_tab_url . '&tab=images#kitgenix-tab-images' ) . '" data-kitgenix-tab="images">' . esc_html__( 'Images', 'kitgenix-image-optimizer' ) . '</a>'
			. '<a class="nav-tab kitgenix-tab-trigger ' . ( $default_tab === 'support' ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base_tab_url . '&tab=support#kitgenix-tab-support' ) . '" data-kitgenix-tab="support">' . esc_html__( 'Support', 'kitgenix-image-optimizer' ) . '</a>'
			. '<a class="nav-tab kitgenix-tab-trigger ' . ( $default_tab === 'log' ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base_tab_url . '&tab=log#kitgenix-tab-log' ) . '" data-kitgenix-tab="log">' . esc_html__( 'Log', 'kitgenix-image-optimizer' ) . '</a>'
			. '</h2>';

		echo '<div class="kitgenix-image-optimizer-settings-layout kitgenix-settings-layout"><div class="kitgenix-image-optimizer-settings-content kitgenix-settings-content" id="kitgenix-image-optimizer-settings-content" tabindex="-1">';

		echo '<div id="kitgenix-tab-settings" data-kitgenix-tab-panel="settings"' . ( $default_tab !== 'settings' ? ' hidden="hidden"' : '' ) . '>';
		self::render_settings_tab( $settings );
		echo '</div>';

		echo '<div id="kitgenix-tab-images" data-kitgenix-tab-panel="images"' . ( $default_tab !== 'images' ? ' hidden="hidden"' : '' ) . '>';
		self::render_images_tab();
		echo '</div>';

		echo '<div id="kitgenix-tab-support" data-kitgenix-tab-panel="support"' . ( $default_tab !== 'support' ? ' hidden="hidden"' : '' ) . '>';
		self::render_support_tab();
		echo '</div>';

		echo '<div id="kitgenix-tab-log" data-kitgenix-tab-panel="log"' . ( $default_tab !== 'log' ? ' hidden="hidden"' : '' ) . '>';
		self::render_log_tab();
		echo '</div>';

		echo '</div>';
		self::render_sidebar();
		echo '</div>';

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private static function render_settings_tab( array $settings ): void {
		echo '<form method="post" action="options.php" autocomplete="off" novalidate>';
		settings_fields( 'kitgenix_image_optimizer_settings_group' );
		wp_nonce_field( 'kitgenix_image_optimizer_settings_save', 'kitgenix_image_optimizer_settings_nonce' );

		// Hidden fields for unchecked checkboxes.
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION_NAME ) . '[enabled]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION_NAME ) . '[auto_optimize_on_upload]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION_NAME ) . '[convert_to_webp]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION_NAME ) . '[strip_metadata]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION_NAME ) . '[optimize_thumbnails]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION_NAME ) . '[backup_originals]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION_NAME ) . '[skip_animated_gif]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION_NAME ) . '[lazy_load]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION_NAME ) . '[convert_png_to_jpeg]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION_NAME ) . '[keep_data_on_uninstall]" value="0" />';

		// General.
		echo '<div class="kitgenix-image-optimizer-card">'
			. '<h3>' . esc_html__( 'General', 'kitgenix-image-optimizer' ) . '</h3>'
			. '<table class="form-table">'
			. '<tr>'
			. '<th scope="row">' . esc_html__( 'Enable Image Optimizer', 'kitgenix-image-optimizer' ) . '</th>'
			. '<td><label class="kitgenix-option-label"><input type="checkbox" name="' . esc_attr( Settings::OPTION_NAME ) . '[enabled]" value="1" ' . checked( ! empty( $settings['enabled'] ), true, false ) . ' /> <span>' . esc_html__( 'Enable image optimization', 'kitgenix-image-optimizer' ) . '</span></label></td>'
			. '</tr>'
			. '<tr>'
			. '<th scope="row">' . esc_html__( 'Optimize on Upload', 'kitgenix-image-optimizer' ) . '</th>'
			. '<td><label class="kitgenix-option-label"><input type="checkbox" name="' . esc_attr( Settings::OPTION_NAME ) . '[auto_optimize_on_upload]" value="1" ' . checked( ! empty( $settings['auto_optimize_on_upload'] ), true, false ) . ' /> <span>' . esc_html__( 'Automatically optimize images when they are uploaded to the media library', 'kitgenix-image-optimizer' ) . '</span></label></td>'
			. '</tr>'
			. '</table>'
			. '</div>';

		// Quality.
		$cur_level = $settings['compression_level'] ?? 'normal';
		$levels    = [
			'lossless'   => [
				'label'   => __( 'Lossless', 'kitgenix-image-optimizer' ),
				'quality' => 'JPEG 96 · WebP 96',
				'desc'    => __( 'Highest quality, smallest savings', 'kitgenix-image-optimizer' ),
			],
			'normal'     => [
				'label'   => __( 'Normal', 'kitgenix-image-optimizer' ),
				'quality' => 'JPEG 82 · WebP 80',
				'desc'    => __( 'Balanced quality and savings', 'kitgenix-image-optimizer' ),
			],
			'aggressive' => [
				'label'   => __( 'Aggressive', 'kitgenix-image-optimizer' ),
				'quality' => 'JPEG 78 · WebP 76',
				'desc'    => __( 'More savings, minor quality loss', 'kitgenix-image-optimizer' ),
			],
			'ultra'      => [
				'label'   => __( 'Ultra', 'kitgenix-image-optimizer' ),
				'quality' => 'JPEG 65 · WebP 63',
				'desc'    => __( 'Max savings, visible quality loss', 'kitgenix-image-optimizer' ),
			],
			'custom'     => [
				'label'   => __( 'Custom', 'kitgenix-image-optimizer' ),
				'quality' => __( 'Custom values', 'kitgenix-image-optimizer' ),
				'desc'    => __( 'Use the quality fields below', 'kitgenix-image-optimizer' ),
			],
		];

		echo '<div class="kitgenix-image-optimizer-card">';
		echo '<h3>' . esc_html__( 'Quality', 'kitgenix-image-optimizer' ) . '</h3>';
		echo '<table class="form-table">';
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Compression Level', 'kitgenix-image-optimizer' ) . '</th>';
		echo '<td>';
		echo '<div class="kitgenix-compression-card-grid">';
		foreach ( $levels as $level_key => $level_data ) {
			$is_selected = ( $cur_level === $level_key );
			echo '<label class="kitgenix-compression-card' . ( $is_selected ? ' is-selected' : '' ) . '">';
			echo '<input type="radio" name="' . esc_attr( Settings::OPTION_NAME ) . '[compression_level]" value="' . esc_attr( $level_key ) . '" ' . checked( $cur_level, $level_key, false ) . ' />';
			echo '<span class="kitgenix-compression-card__name">' . esc_html( $level_data['label'] ) . '</span>';
			echo '<span class="kitgenix-compression-card__quality">' . esc_html( $level_data['quality'] ) . '</span>';
			echo '<span class="kitgenix-compression-card__desc">' . esc_html( $level_data['desc'] ) . '</span>';
			echo '</label>';
		}
		echo '</div>';
		echo '<p class="description" style="margin-top:6px">' . esc_html__( 'Preset levels override the JPEG and WebP quality fields below. Select Custom to use your own values.', 'kitgenix-image-optimizer' ) . '</p>';
		echo '</td></tr>';
		echo '<tr>';
		echo '<th scope="row"><label for="kitgenix_image_optimizer_quality_jpeg">' . esc_html__( 'JPEG Quality', 'kitgenix-image-optimizer' ) . '</label></th>';
		echo '<td><input type="number" min="1" max="100" id="kitgenix_image_optimizer_quality_jpeg" name="' . esc_attr( Settings::OPTION_NAME ) . '[quality_jpeg]" value="' . esc_attr( (string) ( $settings['quality_jpeg'] ?? 82 ) ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Used only when Compression Level is set to Custom.', 'kitgenix-image-optimizer' ) . '</p></td>';
		echo '</tr>';
		echo '<tr>';
		echo '<th scope="row"><label for="kitgenix_image_optimizer_quality_webp">' . esc_html__( 'WebP Quality', 'kitgenix-image-optimizer' ) . '</label></th>';
		echo '<td><input type="number" min="1" max="100" id="kitgenix_image_optimizer_quality_webp" name="' . esc_attr( Settings::OPTION_NAME ) . '[quality_webp]" value="' . esc_attr( (string) ( $settings['quality_webp'] ?? 82 ) ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Used only when Compression Level is set to Custom.', 'kitgenix-image-optimizer' ) . '</p></td>';
		echo '</tr>';
		echo '<tr>';
		echo '<th scope="row"><label for="kitgenix_image_optimizer_quality_png_compression">' . esc_html__( 'PNG Compression', 'kitgenix-image-optimizer' ) . '</label></th>';
		echo '<td><input type="number" min="0" max="9" id="kitgenix_image_optimizer_quality_png_compression" name="' . esc_attr( Settings::OPTION_NAME ) . '[quality_png_compression]" value="' . esc_attr( (string) ( $settings['quality_png_compression'] ?? 6 ) ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'PNG compression level (0–9). Higher = smaller file, slower processing.', 'kitgenix-image-optimizer' ) . '</p></td>';
		echo '</tr>';
		echo '</table>';
		echo '</div>';


		// Resizing.
		echo '<div class="kitgenix-image-optimizer-card">'
			. '<h3>' . esc_html__( 'Resizing', 'kitgenix-image-optimizer' ) . '</h3>'
			. '<table class="form-table">'
			. '<tr>'
			. '<th scope="row"><label for="kitgenix_image_optimizer_max_width">' . esc_html__( 'Max Width (px)', 'kitgenix-image-optimizer' ) . '</label></th>'
			. '<td><input type="number" min="0" id="kitgenix_image_optimizer_max_width" name="' . esc_attr( Settings::OPTION_NAME ) . '[max_width]" value="' . esc_attr( (string) ( $settings['max_width'] ?? 2560 ) ) . '" class="small-text" />'
			. '<p class="description">' . esc_html__( 'Images wider than this will be scaled down (aspect ratio preserved). Set to 0 to disable.', 'kitgenix-image-optimizer' ) . '</p></td>'
			. '</tr>'
			. '<tr>'
			. '<th scope="row"><label for="kitgenix_image_optimizer_max_height">' . esc_html__( 'Max Height (px)', 'kitgenix-image-optimizer' ) . '</label></th>'
			. '<td><input type="number" min="0" id="kitgenix_image_optimizer_max_height" name="' . esc_attr( Settings::OPTION_NAME ) . '[max_height]" value="' . esc_attr( (string) ( $settings['max_height'] ?? 2560 ) ) . '" class="small-text" />'
			. '<p class="description">' . esc_html__( 'Images taller than this will be scaled down (aspect ratio preserved). Set to 0 to disable.', 'kitgenix-image-optimizer' ) . '</p></td>'
			. '</tr>'
			. '</table>'
			. '</div>';

		// Advanced.
		echo '<div class="kitgenix-image-optimizer-card">'
			. '<h3>' . esc_html__( 'Advanced', 'kitgenix-image-optimizer' ) . '</h3>'
			. '<table class="form-table">'
			. '<tr>'
			. '<th scope="row">' . esc_html__( 'Convert to WebP', 'kitgenix-image-optimizer' ) . '</th>'
			. '<td><label class="kitgenix-option-label"><input type="checkbox" name="' . esc_attr( Settings::OPTION_NAME ) . '[convert_to_webp]" value="1" ' . checked( ! empty( $settings['convert_to_webp'] ), true, false ) . ' /> <span>' . esc_html__( 'Convert JPEG and PNG images to WebP format on upload (requires GD or Imagick with WebP support)', 'kitgenix-image-optimizer' ) . '</span></label></td>'
			. '</tr>'
			. '<tr>'
			. '<th scope="row">' . esc_html__( 'Strip Image Metadata', 'kitgenix-image-optimizer' ) . '</th>'
			. '<td><label class="kitgenix-option-label"><input type="checkbox" name="' . esc_attr( Settings::OPTION_NAME ) . '[strip_metadata]" value="1" ' . checked( ! empty( $settings['strip_metadata'] ), true, false ) . ' /> <span>' . esc_html__( 'Remove EXIF and other metadata from images to reduce file size', 'kitgenix-image-optimizer' ) . '</span></label></td>'
			. '</tr>'
			. '<tr>'
			. '<th scope="row">' . esc_html__( 'Optimize Thumbnails', 'kitgenix-image-optimizer' ) . '</th>'
			. '<td><label class="kitgenix-option-label"><input type="checkbox" name="' . esc_attr( Settings::OPTION_NAME ) . '[optimize_thumbnails]" value="1" ' . checked( ! empty( $settings['optimize_thumbnails'] ), true, false ) . ' /> <span>' . esc_html__( 'Also compress registered image sub-sizes when optimizing an attachment', 'kitgenix-image-optimizer' ) . '</span></label></td>'
			. '</tr>'
			. '<tr>'
			. '<th scope="row">' . esc_html__( 'Backup Originals', 'kitgenix-image-optimizer' ) . '</th>'
			. '<td><label class="kitgenix-option-label"><input type="checkbox" name="' . esc_attr( Settings::OPTION_NAME ) . '[backup_originals]" value="1" ' . checked( ! empty( $settings['backup_originals'] ), true, false ) . ' /> <span>' . esc_html__( 'Keep a copy of the original file before optimization (stored alongside the image as filename-kitgenix-original.ext)', 'kitgenix-image-optimizer' ) . '</span></label></td>'
			. '</tr>'
			. '<tr>'
			. '<th scope="row">' . esc_html__( 'Skip Animated GIFs', 'kitgenix-image-optimizer' ) . '</th>'
			. '<td><label class="kitgenix-option-label"><input type="checkbox" name="' . esc_attr( Settings::OPTION_NAME ) . '[skip_animated_gif]" value="1" ' . checked( ! empty( $settings['skip_animated_gif'] ), true, false ) . ' /> <span>' . esc_html__( 'Skip animated GIF files to preserve their animation (recommended)', 'kitgenix-image-optimizer' ) . '</span></label></td>'
			. '</tr>'
			. '<tr>'
			. '<th scope="row">' . esc_html__( 'Lazy Load Images', 'kitgenix-image-optimizer' ) . '</th>'
			. '<td><label class="kitgenix-option-label"><input type="checkbox" name="' . esc_attr( Settings::OPTION_NAME ) . '[lazy_load]" value="1" ' . checked( ! empty( $settings['lazy_load'] ), true, false ) . ' /> <span>' . esc_html__( 'Add loading="lazy" to all frontend images that lack a loading attribute (LCP candidate preserved as eager)', 'kitgenix-image-optimizer' ) . '</span></label></td>'
			. '</tr>'
			. '</table>'
			. '</div>';

		// Exclusions card.
		echo '<div class="kitgenix-image-optimizer-card">'
			. '<h3>' . esc_html__( 'Exclusions', 'kitgenix-image-optimizer' ) . '</h3>'
			. '<table class="form-table">'
			. '<tr>'
			. '<th scope="row"><label for="kitgenix_image_optimizer_exclude_below_kb">' . esc_html__( 'Skip files smaller than (KB)', 'kitgenix-image-optimizer' ) . '</label></th>'
			. '<td><input type="number" min="0" id="kitgenix_image_optimizer_exclude_below_kb" name="' . esc_attr( Settings::OPTION_NAME ) . '[exclude_below_kb]" value="' . esc_attr( (string) ( $settings['exclude_below_kb'] ?? 0 ) ) . '" class="small-text" />'
			. '<p class="description">' . esc_html__( 'Files smaller than this size (in KB) will be skipped. Set to 0 to disable.', 'kitgenix-image-optimizer' ) . '</p></td>'
			. '</tr>'
			. '<tr>'
			. '<th scope="row"><label for="kitgenix_image_optimizer_excluded_filename_patterns">' . esc_html__( 'Skip by Filename Pattern', 'kitgenix-image-optimizer' ) . '</label></th>'
			. '<td><textarea id="kitgenix_image_optimizer_excluded_filename_patterns" name="' . esc_attr( Settings::OPTION_NAME ) . '[excluded_filename_patterns]" rows="4" class="regular-text" placeholder="' . esc_attr__( 'e.g. logo, banner, -thumb', 'kitgenix-image-optimizer' ) . '">' . esc_textarea( (string) ( $settings['excluded_filename_patterns'] ?? '' ) ) . '</textarea>'
			. '<p class="description">' . esc_html__( 'Comma-separated list of filename substrings or glob patterns to skip. Example: logo, banner-*.png', 'kitgenix-image-optimizer' ) . '</p></td>'
			. '</tr>'
			. '</table>'
			. '</div>';

		submit_button( __( 'Save Settings', 'kitgenix-image-optimizer' ) );
		echo '</form>';
	}

	private static function render_images_tab(): void {
		$total       = Optimizer::count_total_optimizable_attachments();
		$optimized   = Optimizer::count_optimized_attachments();
		$unoptimized = max( 0, $total - $optimized );
		$savings     = Optimizer::get_total_savings_bytes();
		$pct         = $total > 0 ? (int) round( ( $optimized / $total ) * 100 ) : 0;

		// KPI stat cards.
		echo '<div class="kitgenix-analytics-grid kitgenix-image-optimizer-stats-grid">';

		echo '<div class="kitgenix-analytics-card">'
			. '<div class="kitgenix-analytics-card__body">'
			. '<div class="kitgenix-analytics-card__title">' . esc_html__( 'Total Images', 'kitgenix-image-optimizer' ) . '</div>'
			. '<div class="kitgenix-analytics-card__value" id="kitgenix-image-optimizer-stat-total">' . esc_html( number_format_i18n( $total ) ) . '</div>'
			. '<div class="kitgenix-analytics-card__sub">' . esc_html__( 'JPEG, PNG, WebP &amp; GIF files', 'kitgenix-image-optimizer' ) . '</div>'
			. '</div></div>';

		echo '<div class="kitgenix-analytics-card">'
			. '<div class="kitgenix-analytics-card__body">'
			. '<div class="kitgenix-analytics-card__title">' . esc_html__( 'Optimized', 'kitgenix-image-optimizer' ) . '</div>'
			. '<div class="kitgenix-analytics-card__value" id="kitgenix-image-optimizer-stat-optimized">' . esc_html( number_format_i18n( $optimized ) ) . '</div>'
			. '<div class="kitgenix-analytics-card__sub">' . esc_html( $pct . '%&nbsp;' . __( 'complete', 'kitgenix-image-optimizer' ) ) . '</div>'
			. '</div></div>';

		echo '<div class="kitgenix-analytics-card">'
			. '<div class="kitgenix-analytics-card__body">'
			. '<div class="kitgenix-analytics-card__title">' . esc_html__( 'Pending', 'kitgenix-image-optimizer' ) . '</div>'
			. '<div class="kitgenix-analytics-card__value kitgenix-image-optimizer-stat--pending" id="kitgenix-image-optimizer-stat-pending">' . esc_html( number_format_i18n( $unoptimized ) ) . '</div>'
			. '<div class="kitgenix-analytics-card__sub">' . esc_html__( 'Not yet processed', 'kitgenix-image-optimizer' ) . '</div>'
			. '</div></div>';

		echo '<div class="kitgenix-analytics-card kitgenix-image-optimizer-savings-card">'
			. '<div class="kitgenix-analytics-card__body">'
			. '<div class="kitgenix-analytics-card__title">' . esc_html__( 'Space Saved', 'kitgenix-image-optimizer' ) . '</div>'
			. '<div class="kitgenix-analytics-card__value kitgenix-image-optimizer-stat--savings" id="kitgenix-image-optimizer-stat-savings">' . esc_html( size_format( $savings ) ) . '</div>'
			. '<div class="kitgenix-analytics-card__sub">' . esc_html__( 'Cumulative disk space reclaimed', 'kitgenix-image-optimizer' ) . '</div>'
			. '</div></div>';

		echo '</div>'; // .kitgenix-analytics-grid

		// Bulk optimization panel.
		echo '<div class="kitgenix-image-optimizer-card">';
		echo '<h3>' . esc_html__( 'Bulk Optimization', 'kitgenix-image-optimizer' ) . '</h3>';
		echo '<p>' . esc_html__( 'Compress all existing images in your media library. Runs one image at a time so it never times out, even on large sites.', 'kitgenix-image-optimizer' ) . '</p>';

		// Progress bar.
		echo '<div class="kitgenix-image-optimizer-progress-wrap">';
		echo '<div class="kitgenix-image-optimizer-progress-track">'
			. '<div class="kitgenix-image-optimizer-progress-fill" id="kitgenix-image-optimizer-progress-fill" style="width:' . esc_attr( $pct . '%' ) . '"></div>'
			. '</div>';
		echo '<span class="kitgenix-image-optimizer-progress-label" id="kitgenix-image-optimizer-progress-label">'
			. esc_html( sprintf(
				/* translators: 1: optimized count, 2: total count, 3: percent */
				__( '%1$s of %2$s images optimized (%3$d%%)', 'kitgenix-image-optimizer' ),
				number_format_i18n( $optimized ),
				number_format_i18n( $total ),
				$pct
			) )
			. '</span>';
		echo '</div>';

		echo '<div id="kitgenix-image-optimizer-bulk-wrap">'
			. '<button type="button" id="kitgenix-image-optimizer-bulk-start" class="button button-primary"' . ( $unoptimized === 0 ? ' disabled' : '' ) . '>'
			. ( $unoptimized === 0 ? esc_html__( 'All Images Optimized', 'kitgenix-image-optimizer' ) : esc_html__( 'Start Bulk Optimization', 'kitgenix-image-optimizer' ) )
			. '</button>'
			. ' <button type="button" id="kitgenix-image-optimizer-bulk-reoptimize" class="button button-secondary" title="' . esc_attr__( 'Re-process all images using current quality settings, including already-optimised ones.', 'kitgenix-image-optimizer' ) . '">'
			. esc_html__( 'Bulk Re-Optimize', 'kitgenix-image-optimizer' )
			. '</button>'
			. '<span id="kitgenix-image-optimizer-bulk-status" class="kitgenix-image-optimizer-bulk-status"></span>'
			. '</div>';
		echo '</div>'; // .kitgenix-image-optimizer-card

		// Recent media library images table.
		$args  = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ],
			'posts_per_page' => 30,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];
		$query = new \WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return;
		}

		$allowed_img = [
			'img'  => [ 'src' => true, 'class' => true, 'width' => true, 'height' => true, 'alt' => true, 'loading' => true, 'decoding' => true ],
			'span' => [ 'class' => true ],
		];
		$allowed_span = [ 'span' => [ 'class' => true ] ];

		echo '<div class="kitgenix-image-optimizer-card">'
			. '<h3>' . esc_html__( 'Recent Images', 'kitgenix-image-optimizer' ) . '</h3>'
			. '<div class="kitgenix-image-optimizer-table-wrap">'
			. '<table class="widefat kitgenix-image-optimizer-images-table">'
			. '<thead><tr>'
			. '<th class="col-thumb">' . esc_html__( 'Preview', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th class="col-file">' . esc_html__( 'File', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th class="col-original">' . esc_html__( 'Original', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th class="col-savings">' . esc_html__( 'Savings', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th class="col-status">' . esc_html__( 'Status', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th class="col-actions">' . esc_html__( 'Actions', 'kitgenix-image-optimizer' ) . '</th>'
			. '</tr></thead>'
			. '<tbody>';

		while ( $query->have_posts() ) {
			$query->the_post();
			$attachment_id = (int) get_the_ID();
			$thumb         = wp_get_attachment_image( $attachment_id, [ 60, 60 ] );
			$filename      = basename( (string) get_attached_file( $attachment_id ) );
			$mime          = (string) get_post_mime_type( $attachment_id );
			$mime_label    = strtoupper( str_replace( 'image/', '', $mime ) );
			$opt_meta      = get_post_meta( $attachment_id, Optimizer::META_KEY, true );
			$is_optimized  = is_array( $opt_meta ) && ! empty( $opt_meta['optimized_at'] );
			$is_error      = is_array( $opt_meta ) && isset( $opt_meta['status'] ) && 'error' === $opt_meta['status'];
			$original_size = $is_optimized && isset( $opt_meta['original_size'] ) ? (int) $opt_meta['original_size'] : 0;
			$savings_bytes = $is_optimized && isset( $opt_meta['savings_bytes'] ) ? (int) $opt_meta['savings_bytes'] : 0;
			$savings_pct   = $is_optimized && isset( $opt_meta['savings_percent'] ) ? (float) $opt_meta['savings_percent'] : 0.0;

			// Backup file check.
			$file_path   = (string) get_attached_file( $attachment_id );
			$backup_path = $file_path ? (string) preg_replace( '/(\.([^.]+))$/', '-kitgenix-original$1', $file_path ) : '';
			$has_backup  = $backup_path && file_exists( $backup_path );

			if ( $is_error ) {
				$err_msg      = isset( $opt_meta['error_message'] ) ? (string) $opt_meta['error_message'] : __( 'Optimization failed', 'kitgenix-image-optimizer' );
				$status_badge = '<span class="kitgenix-badge error" title="' . esc_attr( $err_msg ) . '">' . esc_html__( 'Error', 'kitgenix-image-optimizer' ) . '</span>';
				$savings_chip = '<span class="kitgenix-image-optimizer-savings-chip is-none">&mdash;</span>';
			} elseif ( $is_optimized ) {
				$status_badge = '<span class="kitgenix-badge ok">' . esc_html__( 'Optimized', 'kitgenix-image-optimizer' ) . '</span>';
				$savings_chip = $savings_bytes > 0
					? '<span class="kitgenix-image-optimizer-savings-chip">&darr; ' . esc_html( number_format( $savings_pct, 1 ) . '% &middot; ' . size_format( $savings_bytes ) ) . '</span>'
					: '<span class="kitgenix-image-optimizer-savings-chip is-none">&mdash;</span>';
			} else {
				$status_badge = '<span class="kitgenix-badge muted">' . esc_html__( 'Pending', 'kitgenix-image-optimizer' ) . '</span>';
				$savings_chip = '<span class="kitgenix-image-optimizer-savings-chip is-none">&mdash;</span>';
			}

			$action_html = '<button type="button" class="button button-small kitgenix-image-optimizer-optimize-single" data-attachment-id="' . esc_attr( (string) $attachment_id ) . '">' . esc_html__( 'Optimize', 'kitgenix-image-optimizer' ) . '</button>';
			if ( $is_optimized && $has_backup ) {
				$action_html = '<button type="button" class="button button-small kitgenix-image-optimizer-optimize-single" data-attachment-id="' . esc_attr( (string) $attachment_id ) . '">' . esc_html__( 'Re-optimize', 'kitgenix-image-optimizer' ) . '</button>'
					. ' <button type="button" class="button button-small kitgenix-image-optimizer-restore-backup" data-attachment-id="' . esc_attr( (string) $attachment_id ) . '" style="color:#9c3b4b">' . esc_html__( 'Restore', 'kitgenix-image-optimizer' ) . '</button>';
			} elseif ( $is_optimized ) {
				$action_html = '<button type="button" class="button button-small kitgenix-image-optimizer-optimize-single" data-attachment-id="' . esc_attr( (string) $attachment_id ) . '">' . esc_html__( 'Re-optimize', 'kitgenix-image-optimizer' ) . '</button>';
			} elseif ( $is_error ) {
				$action_html = '<button type="button" class="button button-small kitgenix-image-optimizer-optimize-single" data-attachment-id="' . esc_attr( (string) $attachment_id ) . '">' . esc_html__( 'Re-optimize', 'kitgenix-image-optimizer' ) . '</button>';
			}

			echo '<tr id="kitgenix-image-optimizer-row-' . esc_attr( (string) $attachment_id ) . '">'
				. '<td class="col-thumb">' . wp_kses( $thumb, $allowed_img ) . '</td>'
				. '<td class="col-file"><span class="kitgenix-image-optimizer-filename">' . esc_html( $filename ) . '</span><br><span class="kitgenix-image-optimizer-mime">' . esc_html( $mime_label ) . '</span></td>'
				. '<td class="col-original">' . ( $original_size > 0 ? esc_html( size_format( $original_size ) ) : '<span class="kitgenix-analytics-muted">&mdash;</span>' ) . '</td>'
				. '<td class="col-savings kitgenix-image-optimizer-savings-cell">' . wp_kses( $savings_chip, $allowed_span ) . '</td>'
				. '<td class="col-status kitgenix-image-optimizer-status-cell">' . wp_kses( $status_badge, [ 'span' => [ 'class' => true, 'title' => true ] ] ) . '</td>'
				. '<td class="col-actions">' . wp_kses( $action_html, [ 'button' => [ 'type' => true, 'class' => true, 'data-attachment-id' => true, 'style' => true ] ] ) . '</td>'
				. '</tr>';
		}
		wp_reset_postdata();

		echo '</tbody></table></div></div>';
	}

	private static function render_support_tab(): void {
		$donate_url  = 'https://buymeacoffee.com/kitgenix';
		$member_url  = 'https://buymeacoffee.com/kitgenix/membership';
		$doc_url     = 'https://wordpress.org/plugins/kitgenix-image-optimizer/';
		$support_url = 'https://wordpress.org/support/plugin/kitgenix-image-optimizer/';
		$forum_url   = 'https://wordpress.org/support/plugin/kitgenix-image-optimizer/';
		$review_url  = 'https://wordpress.org/support/plugin/kitgenix-image-optimizer/reviews/#new-post';
		$feature_url = 'https://wordpress.org/support/plugin/kitgenix-image-optimizer/';

		$total     = Optimizer::count_total_optimizable_attachments();
		$optimized = Optimizer::count_optimized_attachments();
		$savings   = Optimizer::get_total_savings_bytes();

		$impact_cards = [
			[
				'label' => __( 'Images optimized', 'kitgenix-image-optimizer' ),
				'value' => number_format_i18n( $optimized ),
				'meta'  => __( 'Media library images already compressed by this plugin.', 'kitgenix-image-optimizer' ),
			],
			[
				'label' => __( 'Images in library', 'kitgenix-image-optimizer' ),
				'value' => number_format_i18n( $total ),
				'meta'  => __( 'Total eligible JPEG, PNG, WebP, and GIF files.', 'kitgenix-image-optimizer' ),
			],
			[
				'label' => __( 'Total space saved', 'kitgenix-image-optimizer' ),
				'value' => size_format( $savings ),
				'meta'  => __( 'Cumulative disk space reclaimed by compression.', 'kitgenix-image-optimizer' ),
			],
		];
		?>
		<div class="kitgenix-image-optimizer-card kitgenix-support-page">
			<div class="kitgenix-support-shell">
				<section class="kitgenix-support-hero">
					<div class="kitgenix-support-hero__copy">
						<span class="kitgenix-support-eyebrow"><?php esc_html_e( 'Help keep Kitgenix independent', 'kitgenix-image-optimizer' ); ?></span>
						<h2 class="kitgenix-support-heading"><?php esc_html_e( 'Support Kitgenix', 'kitgenix-image-optimizer' ); ?></h2>
						<p class="description kitgenix-support-intro"><?php esc_html_e( 'Kitgenix Image Optimizer is free, lightweight, and privacy-first — no external API, no data sent off-site. If it saves you bandwidth costs or keeps your site loading fast for visitors, please consider supporting the work. Your support funds compatibility updates, security hardening, and new features.', 'kitgenix-image-optimizer' ); ?></p>
					</div>
					<div class="kitgenix-support-hero__aside">
						<p class="kitgenix-support-kicker"><?php esc_html_e( 'Support this plugin', 'kitgenix-image-optimizer' ); ?></p>
						<div class="kitgenix-support-actions">
							<a class="button button-primary" href="<?php echo esc_url( $donate_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Buy Me a Coffee', 'kitgenix-image-optimizer' ); ?></a>
							<a class="button button-secondary" href="<?php echo esc_url( $member_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Become a member', 'kitgenix-image-optimizer' ); ?></a>
						</div>
						<p class="kitgenix-support-note"><?php esc_html_e( 'Via Buy Me a Coffee. Cancel anytime.', 'kitgenix-image-optimizer' ); ?></p>
					</div>
				</section>

				<section class="kitgenix-support-section kitgenix-support-section--feature">
					<div class="kitgenix-support-section__header">
						<h3 class="kitgenix-support-subheading"><?php esc_html_e( 'Your site impact', 'kitgenix-image-optimizer' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Here is what Kitgenix Image Optimizer has achieved on your site so far:', 'kitgenix-image-optimizer' ); ?></p>
					</div>
					<div class="kitgenix-support-metric-grid">
						<?php foreach ( $impact_cards as $card ) : ?>
							<div class="kitgenix-support-stat">
								<span class="kitgenix-support-stat__label"><?php echo esc_html( $card['label'] ); ?></span>
								<strong class="kitgenix-support-stat__value"><?php echo esc_html( $card['value'] ); ?></strong>
								<span class="kitgenix-support-stat__meta"><?php echo esc_html( $card['meta'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</section>

			</div>
		</div>
		<?php
	}

	private static function render_log_tab(): void {
		$log_entries = Event_Log::get_raw_log();
		$clear_url   = wp_nonce_url(
			admin_url( 'admin-post.php?action=kitgenix_image_optimizer_clear_event_log' ),
			'kitgenix_image_optimizer_clear_event_log'
		);

		$date_format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		echo '<div class="kitgenix-image-optimizer-card">'
			. '<h3>' . esc_html__( 'Activity Log', 'kitgenix-image-optimizer' ) . '</h3>'
			. '<p class="description">' . esc_html__( 'Recent optimization and settings events (newest first, up to 100 entries).', 'kitgenix-image-optimizer' ) . '</p>';

		if ( empty( $log_entries ) ) {
			echo '<p>' . esc_html__( 'No events recorded yet.', 'kitgenix-image-optimizer' ) . '</p>';
		} else {
			echo '<div class="kitgenix-image-optimizer-table-wrap">'
				. '<table class="widefat kitgenix-image-optimizer-log-table">'
				. '<thead><tr>'
				. '<th>' . esc_html__( 'Time', 'kitgenix-image-optimizer' ) . '</th>'
				. '<th>' . esc_html__( 'Event', 'kitgenix-image-optimizer' ) . '</th>'
				. '<th>' . esc_html__( 'Outcome', 'kitgenix-image-optimizer' ) . '</th>'
				. '<th>' . esc_html__( 'Details', 'kitgenix-image-optimizer' ) . '</th>'
				. '</tr></thead>'
				. '<tbody>';

			foreach ( array_reverse( $log_entries ) as $entry ) {
				$time    = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
				$context = isset( $entry['context'] ) ? (string) $entry['context'] : '';
				$outcome = isset( $entry['outcome'] ) ? (string) $entry['outcome'] : '';
				$note    = isset( $entry['note'] ) ? (string) $entry['note'] : '';

				if ( function_exists( 'wp_date' ) ) {
					$when = $time ? (string) wp_date( $date_format, $time ) : '—';
				} else {
					// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date_i18n
					$when = $time ? (string) date_i18n( $date_format, $time ) : '—';
				}

				$badge_class = match ( $outcome ) {
					'success' => 'ok',
					'error'   => 'error',
					default   => 'neutral',
				};

				echo '<tr>'
					. '<td class="kitgenix-log-time">' . esc_html( $when ) . '</td>'
					. '<td class="kitgenix-log-context"><code>' . esc_html( $context ) . '</code></td>'
					. '<td class="kitgenix-log-outcome"><span class="kitgenix-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $outcome ) . '</span></td>'
					. '<td class="kitgenix-log-note">' . esc_html( $note ) . '</td>'
					. '</tr>';
			}

			echo '</tbody></table></div>';
		}

		echo '<p style="margin-top:12px"><a class="button button-secondary" href="' . esc_url( $clear_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Clear all log entries?', 'kitgenix-image-optimizer' ) ) . '\')">' . esc_html__( 'Clear Log', 'kitgenix-image-optimizer' ) . '</a></p>';
		echo '</div>';
	}

	private static function render_sidebar(): void {
		$plugin_file = defined( 'KITGENIX_IMAGE_OPTIMIZER_FILE' ) ? (string) KITGENIX_IMAGE_OPTIMIZER_FILE : '';
		$social_base = plugins_url( 'assets/images/social-media/', $plugin_file );

		echo '<aside class="kitgenix-settings-sidebar" aria-label="' . esc_attr__( 'Help and links', 'kitgenix-image-optimizer' ) . '">';

		echo '<div class="kitgenix-sidebar-card">'
			. '<h2>' . esc_html__( 'Need Help?', 'kitgenix-image-optimizer' ) . '</h2>'
			. '<p>' . esc_html__( 'Open the documentation for setup guidance or send us a support request if you need help configuring the plugin.', 'kitgenix-image-optimizer' ) . '</p>'
			. '<div class="kitgenix-sidebar-actions">'
			. '<a class="button button-secondary" href="' . esc_url( 'https://wordpress.org/plugins/kitgenix-image-optimizer/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'kitgenix-image-optimizer' ) . '</a>'
			. '<a class="button button-primary" href="' . esc_url( 'https://wordpress.org/support/plugin/kitgenix-image-optimizer/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Request Support', 'kitgenix-image-optimizer' ) . '</a>'
			. '</div>'
			. '</div>';

		echo '<div class="kitgenix-sidebar-card">'
			. '<h2>' . esc_html__( 'Visit Our Official Facebook Group', 'kitgenix-image-optimizer' ) . '</h2>'
			. '<p>' . esc_html__( 'Join the Kitgenix community to ask questions, share feedback, and keep up with product updates.', 'kitgenix-image-optimizer' ) . '</p>'
			. '<div class="kitgenix-sidebar-actions">'
			. '<a class="button button-secondary" href="' . esc_url( 'https://www.facebook.com/groups/kitgenix' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Join Group', 'kitgenix-image-optimizer' ) . '</a>'
			. '</div>'
			. '</div>';

		echo '<div class="kitgenix-sidebar-card">'
			. '<h2>' . esc_html__( 'Follow Us', 'kitgenix-image-optimizer' ) . '</h2>'
			. '<p>' . esc_html__( 'Keep up with new releases, tutorials, and product news across our channels.', 'kitgenix-image-optimizer' ) . '</p>'
			. '<div class="kitgenix-sidebar-social-grid">'
			. '<a class="kitgenix-sidebar-social-link" href="https://wordpress.org/plugins/kitgenix-image-optimizer/" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="' . esc_url( $social_base . 'globe-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
			. '<a class="kitgenix-sidebar-social-link" href="https://www.facebook.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><img src="' . esc_url( $social_base . 'facebook-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
			. '<a class="kitgenix-sidebar-social-link" href="https://www.instagram.com/kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram"><img src="' . esc_url( $social_base . 'instagram-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
			. '<a class="kitgenix-sidebar-social-link" href="https://www.youtube.com/@Kitgenix" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube"><img src="' . esc_url( $social_base . 'youtube-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
			. '<a class="kitgenix-sidebar-social-link" href="https://www.reddit.com/r/Kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Reddit" title="Reddit"><img src="' . esc_url( $social_base . 'reddit-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
			. '<a class="kitgenix-sidebar-social-link" href="https://www.linkedin.com/company/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><img src="' . esc_url( $social_base . 'linkedin-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
			. '<a class="kitgenix-sidebar-social-link" href="https://x.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="X" title="X"><img src="' . esc_url( $social_base . 'x-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
			. '<a class="kitgenix-sidebar-social-link" href="https://www.tiktok.com/@kitgenix" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok"><img src="' . esc_url( $social_base . 'tiktok-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
			. '<a class="kitgenix-sidebar-social-link" href="https://github.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="GitHub"><img src="' . esc_url( $social_base . 'github-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
			. '</div>'
			. '</div>';

		echo '</aside>';
	}
}
