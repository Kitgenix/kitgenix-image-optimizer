<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

use KitgenixImageOptimizer\Core\Capabilities;
use KitgenixImageOptimizer\Core\Event_Log;
use KitgenixImageOptimizer\Core\Optimizer;
use KitgenixImageOptimizer\Core\Queue;
use KitgenixImageOptimizer\Core\Settings;

final class Settings_UI {
	/** @var string|null */
	private static $page_hook = null;

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_menu' ], 50 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ], 20 );
		add_action( 'admin_notices', [ self::class, 'render_admin_notices' ] );
		add_action( 'admin_post_kitgenix_image_optimizer_clear_event_log', [ self::class, 'handle_clear_event_log' ] );
		add_action( 'admin_post_kitgenix_image_optimizer_save_log_retention', [ self::class, 'handle_save_log_retention' ] );
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

		$admin_css_file       = $base_dir ? $base_dir . 'assets/css/admin.css' : '';
		$admin_js_file        = $base_dir ? $base_dir . 'assets/js/admin.js' : '';
		$ui_js_file           = $base_dir ? $base_dir . 'assets/js/kitgenix-admin-tabs.js' : '';
		$components_js_file   = $base_dir ? $base_dir . 'assets/js/kitgenix-admin-components.js' : '';

		$admin_css_ver     = ( $admin_css_file && file_exists( $admin_css_file ) ) ? (string) filemtime( $admin_css_file ) : $ver;
		$admin_js_ver      = ( $admin_js_file && file_exists( $admin_js_file ) ) ? (string) filemtime( $admin_js_file ) : $ver;
		$ui_js_ver         = ( $ui_js_file && file_exists( $ui_js_file ) ) ? (string) filemtime( $ui_js_file ) : $ver;
		$components_js_ver = ( $components_js_file && file_exists( $components_js_file ) ) ? (string) filemtime( $components_js_file ) : $ver;

		wp_enqueue_style( 'kitgenix-image-optimizer-admin-ui' );

		wp_enqueue_style(
			'kitgenix-image-optimizer-admin',
			plugins_url( 'assets/css/admin.css', $plugin_file ),
			[ 'kitgenix-image-optimizer-admin-ui' ],
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
			'kitgenix-admin-components',
			plugins_url( 'assets/js/kitgenix-admin-components.js', $plugin_file ),
			[],
			$components_js_ver,
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
		$queue_progress    = Queue::progress();

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
				'queue'             => $queue_progress,
				'i18n'              => [
					'optimizing'   => __( 'Optimizing…', 'kitgenix-image-optimizer' ),
					'done'         => __( 'All done! Every image has been optimized.', 'kitgenix-image-optimizer' ),
					'allOptimized' => __( 'All Images Optimized', 'kitgenix-image-optimizer' ),
					'error'        => __( 'An error occurred. Please try again.', 'kitgenix-image-optimizer' ),
					'bulkStart'           => __( 'Start Bulk Optimization', 'kitgenix-image-optimizer' ),
					'bulkPause'           => __( 'Pause', 'kitgenix-image-optimizer' ),
					'bulkResume'          => __( 'Resume', 'kitgenix-image-optimizer' ),
					'bulkStop'            => __( 'Stop', 'kitgenix-image-optimizer' ),
					'bulkReoptimizeStart' => __( 'Bulk Re-Optimize', 'kitgenix-image-optimizer' ),
					'reoptimizeDone'      => __( 'All images have been re-optimized.', 'kitgenix-image-optimizer' ),
					/* translators: %d: number of failed images to retry. */
					'retryFailed'         => __( 'Retry %d Failed', 'kitgenix-image-optimizer' ),
					'retrying'            => __( 'Retrying…', 'kitgenix-image-optimizer' ),
					'recalculating'       => __( 'Recalculating…', 'kitgenix-image-optimizer' ),
					'recalculated'        => __( 'Stats recalculated.', 'kitgenix-image-optimizer' ),
					'optimized'    => __( 'Optimized', 'kitgenix-image-optimizer' ),
					'pending'      => __( 'Pending', 'kitgenix-image-optimizer' ),
					'saved'        => __( 'saved', 'kitgenix-image-optimizer' ),
					'of'           => __( 'of', 'kitgenix-image-optimizer' ),
					'images'       => __( 'images', 'kitgenix-image-optimizer' ),
					'restoring'    => __( 'Restoring…', 'kitgenix-image-optimizer' ),
					'restored'     => __( 'Restored – ready to re-optimize', 'kitgenix-image-optimizer' ),
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
	 * Handle settings saved hook – log the event.
	 *
	 * @param mixed $old_value
	 * @param mixed $new_value
	 * @param string $option
	 */
	public static function on_settings_saved( $old_value, $new_value, string $option ): void {
		Event_Log::record( 'settings-saved', 'success', 'Settings updated via admin.', 'settings-saved' );
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

	/**
	 * Handle the log-retention admin-post action.
	 */
	public static function handle_save_log_retention(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'kitgenix-image-optimizer' ) );
		}

		check_admin_referer( 'kitgenix_image_optimizer_save_log_retention' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_admin_referer() above
		$days = isset( $_POST['log_retention_days'] ) ? (int) $_POST['log_retention_days'] : Event_Log::get_retention_days();
		Event_Log::set_retention_days( $days );

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'kitgenix-image-optimizer', 'tab' => 'log', 'log-retention-saved' => '1' ],
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
			if ( in_array( $maybe_tab, [ 'settings', 'images', 'diagnostics', 'support', 'log' ], true ) ) {
				$default_tab = $maybe_tab;
			}
		}

		$logo        = plugins_url( 'assets/images/logos/kitgenix-primary-favicon.svg', $plugin_file );
		$social_base = plugins_url( 'assets/images/social-media/', $plugin_file );
		$base_tab_url = admin_url( 'admin.php?page=' . rawurlencode( 'kitgenix-image-optimizer' ) );

		echo '<div class="wrap kitgenix-admin-app kitgenix-image-optimizer-use-top-tabs kitgenix-image-optimizer-scope" data-kitgenix-tabs data-kitgenix-default-tab="' . esc_attr( $default_tab ) . '" id="kitgenix-image-optimizer-admin-app">';

		// Header.
		echo '<div class="kitgenix-topbar">'
			. '<div class="kitgenix-topbar-left">'
			. '<a class="kitgenix-topbar-brand" href="' . esc_url( admin_url( 'admin.php?page=kitgenix' ) ) . '" title="Kitgenix">'
			. '<img class="kitgenix-topbar-logo" src="' . esc_url( $logo ) . '" alt="Kitgenix" width="28" height="28" />'
			. '</a>'
			. '<span class="kitgenix-topbar-divider" aria-hidden="true"></span>'
			. '<div class="kitgenix-topbar-plugin-info">'
			. '<span class="kitgenix-topbar-title">' . esc_html__( 'Image Optimizer', 'kitgenix-image-optimizer' ) . '</span>'
			. '<span class="kitgenix-topbar-version">v' . esc_html( $ver ) . '</span>'
			. '</div>'
			. '</div>'
			. '<div class="kitgenix-topbar-center">'
			. '<ul class="kitgenix-topbar-menu" role="tablist">'
			. '<li class="kitgenix-menu-item ' . ( $default_tab === 'settings' ? 'kitgenix-active' : '' ) . '"><a class="kitgenix-menu-link kitgenix-tab-trigger ' . ( $default_tab === 'settings' ? 'kitgenix-active' : '' ) . '" href="' . esc_url( $base_tab_url . '&tab=settings#kitgenix-tab-settings' ) . '" data-kitgenix-tab="settings">' . esc_html__( 'Settings', 'kitgenix-image-optimizer' ) . '</a></li>'
			. '<li class="kitgenix-menu-item ' . ( $default_tab === 'images' ? 'kitgenix-active' : '' ) . '"><a class="kitgenix-menu-link kitgenix-tab-trigger ' . ( $default_tab === 'images' ? 'kitgenix-active' : '' ) . '" href="' . esc_url( $base_tab_url . '&tab=images#kitgenix-tab-images' ) . '" data-kitgenix-tab="images">' . esc_html__( 'Images', 'kitgenix-image-optimizer' ) . '</a></li>'
			. '<li class="kitgenix-menu-item ' . ( $default_tab === 'diagnostics' ? 'kitgenix-active' : '' ) . '"><a class="kitgenix-menu-link kitgenix-tab-trigger ' . ( $default_tab === 'diagnostics' ? 'kitgenix-active' : '' ) . '" href="' . esc_url( $base_tab_url . '&tab=diagnostics#kitgenix-tab-diagnostics' ) . '" data-kitgenix-tab="diagnostics">' . esc_html__( 'Diagnostics', 'kitgenix-image-optimizer' ) . '</a></li>'
			. '<li class="kitgenix-menu-item ' . ( $default_tab === 'log' ? 'kitgenix-active' : '' ) . '"><a class="kitgenix-menu-link kitgenix-tab-trigger ' . ( $default_tab === 'log' ? 'kitgenix-active' : '' ) . '" href="' . esc_url( $base_tab_url . '&tab=log#kitgenix-tab-log' ) . '" data-kitgenix-tab="log">' . esc_html__( 'Log', 'kitgenix-image-optimizer' ) . '</a></li>'
			. '<li class="kitgenix-menu-item ' . ( $default_tab === 'support' ? 'kitgenix-active' : '' ) . '"><a class="kitgenix-menu-link kitgenix-tab-trigger ' . ( $default_tab === 'support' ? 'kitgenix-active' : '' ) . '" href="' . esc_url( $base_tab_url . '&tab=support#kitgenix-tab-support' ) . '" data-kitgenix-tab="support">' . esc_html__( 'Support', 'kitgenix-image-optimizer' ) . '</a></li>'
			. '</ul>'
			. '</div>'
			. '<div class="kitgenix-topbar-right" aria-label="Topbar actions">'
			. '<div class="kitgenix-topbar-search">'
			. '<button type="button" class="kitgenix-topbar-icon-btn kitgenix-search-toggle" aria-label="' . esc_attr__( 'Search settings', 'kitgenix-image-optimizer' ) . '" aria-expanded="false"><svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.5232 13.4627L17.7355 16.6742L16.6742 17.7355L13.4627 14.5232C12.2678 15.4812 10.7815 16.0022 9.25 16C5.524 16 2.5 12.976 2.5 9.25C2.5 5.524 5.524 2.5 9.25 2.5C12.976 2.5 16 5.524 16 9.25C16.0022 10.7815 15.4812 12.2678 14.5232 13.4627ZM13.0187 12.9062C13.9706 11.9274 14.5021 10.6153 14.5 9.25C14.5 6.349 12.1502 4 9.25 4C6.349 4 4 6.349 4 9.25C4 12.1502 6.349 14.5 9.25 14.5C10.6153 14.5021 11.9274 13.9706 12.9062 13.0187L13.0187 12.9062V12.9062Z" fill="currentColor"></path></svg></button>'
			. '<div class="kitgenix-search-panel">'
			. '<span class="kitgenix-search-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.5232 13.4627L17.7355 16.6742L16.6742 17.7355L13.4627 14.5232C12.2678 15.4812 10.7815 16.0022 9.25 16C5.524 16 2.5 12.976 2.5 9.25C2.5 5.524 5.524 2.5 9.25 2.5C12.976 2.5 16 5.524 16 9.25C16.0022 10.7815 15.4812 12.2678 14.5232 13.4627ZM13.0187 12.9062C13.9706 11.9274 14.5021 10.6153 14.5 9.25C14.5 6.349 12.1502 4 9.25 4C6.349 4 4 6.349 4 9.25C4 12.1502 6.349 14.5 9.25 14.5C10.6153 14.5021 11.9274 13.9706 12.9062 13.0187L13.0187 12.9062V12.9062Z" fill="currentColor"></path></svg></span>'
			. '<input type="search" class="kitgenix-topbar-search-input" placeholder="' . esc_attr__( 'Search settings…', 'kitgenix-image-optimizer' ) . '" aria-label="' . esc_attr__( 'Search settings', 'kitgenix-image-optimizer' ) . '" autocomplete="off" />'
			. '<kbd class="kitgenix-search-kbd" title="Press / or ⌘K to search">/</kbd>'
			. '<button type="button" class="kitgenix-search-clear" aria-label="' . esc_attr__( 'Clear search', 'kitgenix-image-optimizer' ) . '" style="display:none;">&times;</button>'
			. '</div>'
			. '</div>'
			. '<button type="button" class="kitgenix-topbar-icon-btn kitgenix-theme-toggle" aria-label="' . esc_attr__( 'Toggle color theme', 'kitgenix-image-optimizer' ) . '" title="' . esc_attr__( 'Toggle color theme', 'kitgenix-image-optimizer' ) . '">'
			. '<svg class="kitgenix-theme-icon-light" width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="3.25" stroke="currentColor" stroke-width="1.3"></circle><path d="M10 2.5v2M10 15.5v2M17.5 10h-2M4.5 10h-2M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4M15.3 15.3l-1.4-1.4M6.1 6.1 4.7 4.7" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path></svg>'
			. '<svg class="kitgenix-theme-icon-dark" width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16.5 12.3A6.5 6.5 0 0 1 7.7 3.5a6.5 6.5 0 1 0 8.8 8.8Z" fill="currentColor"></path></svg>'
			. '</button>'
			. '<a class="kitgenix-topbar-icon-btn" href="' . esc_url( admin_url( 'admin.php?page=kitgenix' ) ) . '" title="' . esc_attr__( 'Kitgenix Hub', 'kitgenix-image-optimizer' ) . '" aria-label="Kitgenix Hub"><svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.25 3.25H9.25V9.25H3.25V3.25ZM10.75 3.25H16.75V9.25H10.75V3.25ZM3.25 10.75H9.25V16.75H3.25V10.75ZM10.75 10.75H16.75V16.75H10.75V10.75Z" fill="currentColor"></path></svg></a>'
			. '<button type="button" class="kitgenix-topbar-hamburger" aria-label="' . esc_attr__( 'Toggle navigation', 'kitgenix-image-optimizer' ) . '"><span></span><span></span><span></span></button>'
			. '</div>'
			. '</div>';

		echo '<div class="kitgenix-image-optimizer-settings-layout kitgenix-settings-layout"><div class="kitgenix-image-optimizer-settings-content kitgenix-settings-content" id="kitgenix-image-optimizer-settings-content" tabindex="-1">';

		echo '<div id="kitgenix-tab-settings" data-kitgenix-tab-panel="settings"' . ( $default_tab !== 'settings' ? ' hidden="hidden"' : '' ) . '>';
		self::render_settings_tab( $settings );
		echo '</div>';

		echo '<div id="kitgenix-tab-images" data-kitgenix-tab-panel="images"' . ( $default_tab !== 'images' ? ' hidden="hidden"' : '' ) . '>';
		self::render_images_tab();
		echo '</div>';

		echo '<div id="kitgenix-tab-diagnostics" data-kitgenix-tab-panel="diagnostics"' . ( $default_tab !== 'diagnostics' ? ' hidden="hidden"' : '' ) . '>';
		self::render_diagnostics_tab();
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
	 * Small inline icon library for card headers, kept intentionally tiny
	 * and dependency-free (no icon font, no external requests).
	 */
	private static function icon( string $name ): string {
		$icons = [
			'toggle'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.4-6.4-1.4 1.4M6 18l-1.4 1.4M18 18l1.4 1.4M6 6 4.6 4.6"/><circle cx="12" cy="12" r="4"/></svg>',
			'quality'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>',
			'resize'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>',
			'advanced' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.04 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.36a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.64 15a1.7 1.7 0 0 0-1.56-1.04H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.64 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.64a1.7 1.7 0 0 0 1.04-1.56V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1.04 1.56 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.36 9a1.7 1.7 0 0 0 1.56 1.04H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.56 1.04z"/></svg>',
			'exclude'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16l-6.5 8.5V19l-3 2v-8.5z"/></svg>',  // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- icon-library array key ('exclude' icon name), not a get_posts()/WP_Query parameter.
			'bulk'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 11-14h-7l1-6z"/></svg>',
			'images'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>',
			'log'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 3"/></svg>',
			'heart'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.6Z"/></svg>',
			'chart'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18M7 15v3M12 10v8M17 6v12"/></svg>',
			'help'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.9.4-1.5 1-1.5 2v.3"/><path d="M12 17h.01"/></svg>',
			'group'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
			'share'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.5 6.8-3.9M8.6 13.5l6.8 3.9"/></svg>',
			'trash'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>',
			'chat'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-7l-4 3.5v-3.5H6a2 2 0 0 1-2-2z"/></svg>',
			'star'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 2.5 5.1 5.6.8-4 4 1 5.6L12 16l-5 2.5 1-5.6-4-4 5.6-.8z"/></svg>',
			'copy'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
			'sync'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
			'tools'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg>',
			'check'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="m20 6-11 11-5-5"/></svg>',
			'docs'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8"/></svg>',
			'search'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>',
			'shield'   => '<svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2.5l6 2.2v4.4c0 4.2-2.6 7.4-6 8.4-3.4-1-6-4.2-6-8.4V4.7l6-2.2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7.3 10.2l1.9 1.9 3.5-3.9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			'paypal'   => '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6.7 15.5H4.9a.3.3 0 0 1-.3-.35L6.5 3.8a.5.5 0 0 1 .5-.4h4.4c2 0 3.4 1.2 3.1 3.2-.3 2.4-2.1 3.7-4.3 3.7H8.6a.5.5 0 0 0-.5.4l-.7 3.9a.5.5 0 0 1-.5.4Z" fill="currentColor" opacity=".55"/><path d="M9.2 15.5H7.4a.3.3 0 0 1-.3-.35L9 3.8a.5.5 0 0 1 .5-.4h4.4c2 0 3.4 1.2 3.1 3.2-.3 2.4-2.1 3.7-4.3 3.7h-1.6a.5.5 0 0 0-.5.4l-.7 3.9a.5.5 0 0 1-.5.4Z" fill="currentColor"/></svg>',
		];

		return $icons[ $name ] ?? '';
	}

	/**
	 * Renders a `.kitgenix-card` head block (icon + title + optional description).
	 */
	private static function card_head( string $icon_name, string $title, string $desc = '' ): void {
		echo '<div class="kitgenix-card-head">'
			. '<div class="kitgenix-card-head-main">'
			. '<span class="kitgenix-card-icon" aria-hidden="true">' . self::icon( $icon_name ) . '</span>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
			. '<div class="kitgenix-card-head-text">'
			. '<h2>' . esc_html( $title ) . '</h2>'
			. ( $desc !== '' ? '<p>' . esc_html( $desc ) . '</p>' : '' )
			. '</div>'
			. '</div>'
			. '</div>';
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private static function render_settings_tab( array $settings ): void {
		$opt = Settings::OPTION_NAME;

		echo '<form method="post" action="options.php" autocomplete="off" novalidate>';
		settings_fields( 'kitgenix_image_optimizer_settings_group' );
		wp_nonce_field( 'kitgenix_image_optimizer_settings_save', 'kitgenix_image_optimizer_settings_nonce' );

		// Hidden fields for unchecked checkboxes.
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[enabled]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[auto_optimize_on_upload]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[convert_to_webp]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[convert_to_avif]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[strip_metadata]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[optimize_thumbnails]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[backup_originals]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[skip_animated_gif]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[lazy_load]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[keep_data_on_uninstall]" value="0" />';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[delete_backups_on_uninstall]" value="0" />';

		// ---------------------------------------------------------------
		// General
		// ---------------------------------------------------------------
		echo '<div class="kitgenix-card">';
		self::card_head( 'toggle', __( 'General', 'kitgenix-image-optimizer' ), __( 'Turn optimization on and decide whether new uploads are processed automatically.', 'kitgenix-image-optimizer' ) );
		echo '<div class="kitgenix-card-body"><div class="kitgenix-settings-group">';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_enabled">' . esc_html__( 'Enable Image Optimizer', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Master switch for the plugin.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control">'
			. '<label class="kitgenix-toggle">'
			. '<input type="checkbox" class="kitgenix-toggle-input" id="kitgenix_image_optimizer_enabled" name="' . esc_attr( $opt ) . '[enabled]" value="1" ' . checked( ! empty( $settings['enabled'] ), true, false ) . ' />'
			. '<span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span>'
			. '<span class="kitgenix-toggle-label">' . esc_html__( 'Enable image optimization', 'kitgenix-image-optimizer' ) . '</span>'
			. '</label>'
			. '</div>'
			. '</div>';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_auto">' . esc_html__( 'Optimize on Upload', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Automatically optimize images when they are uploaded to the media library.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control">'
			. '<label class="kitgenix-toggle">'
			. '<input type="checkbox" class="kitgenix-toggle-input" id="kitgenix_image_optimizer_auto" name="' . esc_attr( $opt ) . '[auto_optimize_on_upload]" value="1" ' . checked( ! empty( $settings['auto_optimize_on_upload'] ), true, false ) . ' />'
			. '<span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span>'
			. '</label>'
			. '</div>'
			. '</div>';

		echo '</div></div></div>';

		// ---------------------------------------------------------------
		// Quality
		// ---------------------------------------------------------------
		$cur_level = $settings['compression_level'] ?? 'normal';
		$levels    = [
			'lossless'   => [
				'label'   => __( 'Conservative', 'kitgenix-image-optimizer' ),
				'quality' => 'JPEG 96 · WebP 96 · AVIF 90',
				'desc'    => __( 'Highest quality, smallest savings – visually lossless', 'kitgenix-image-optimizer' ),
			],
			'normal'     => [
				'label'   => __( 'Balanced', 'kitgenix-image-optimizer' ),
				'quality' => 'JPEG 82 · WebP 80 · AVIF 60',
				'desc'    => __( 'Balanced quality and savings – the recommended default', 'kitgenix-image-optimizer' ),
			],
			'aggressive' => [
				'label'   => __( 'Aggressive', 'kitgenix-image-optimizer' ),
				'quality' => 'JPEG 78 · WebP 76 · AVIF 55',
				'desc'    => __( 'More savings, minor quality loss', 'kitgenix-image-optimizer' ),
			],
			'ultra'      => [
				'label'   => __( 'Maximum', 'kitgenix-image-optimizer' ),
				'quality' => 'JPEG 65 · WebP 63 · AVIF 42',
				'desc'    => __( 'Max savings, visible quality loss', 'kitgenix-image-optimizer' ),
			],
			'custom'     => [
				'label'   => __( 'Custom', 'kitgenix-image-optimizer' ),
				'quality' => __( 'Custom values', 'kitgenix-image-optimizer' ),
				'desc'    => __( 'Use the quality fields below', 'kitgenix-image-optimizer' ),
			],
		];

		echo '<div class="kitgenix-card">';
		self::card_head( 'quality', __( 'Quality', 'kitgenix-image-optimizer' ), __( 'Choose a compression preset, or dial in exact values yourself.', 'kitgenix-image-optimizer' ) );
		echo '<div class="kitgenix-card-body">';

		echo '<div class="kitgenix-compression-card-grid">';
		foreach ( $levels as $level_key => $level_data ) {
			$is_selected = ( $cur_level === $level_key );
			echo '<label class="kitgenix-compression-card' . ( $is_selected ? ' is-selected' : '' ) . '">';
			echo '<input type="radio" name="' . esc_attr( $opt ) . '[compression_level]" value="' . esc_attr( $level_key ) . '" ' . checked( $cur_level, $level_key, false ) . ' />';
			echo '<span class="kitgenix-compression-card__name">' . esc_html( $level_data['label'] ) . '</span>';
			echo '<span class="kitgenix-compression-card__quality">' . esc_html( $level_data['quality'] ) . '</span>';
			echo '<span class="kitgenix-compression-card__desc">' . esc_html( $level_data['desc'] ) . '</span>';
			echo '</label>';
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Preset levels override the JPEG and WebP quality fields below. Select Custom to use your own values.', 'kitgenix-image-optimizer' ) . '</p>';

		echo '<div class="kitgenix-settings-group">';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_quality_jpeg">' . esc_html__( 'JPEG Quality', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Used only when Compression Level is set to Custom.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control"><input type="number" min="1" max="100" id="kitgenix_image_optimizer_quality_jpeg" name="' . esc_attr( $opt ) . '[quality_jpeg]" value="' . esc_attr( (string) ( $settings['quality_jpeg'] ?? 82 ) ) . '" class="small-text" /></div>'
			. '</div>';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_quality_webp">' . esc_html__( 'WebP Quality', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Used only when Compression Level is set to Custom.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control"><input type="number" min="1" max="100" id="kitgenix_image_optimizer_quality_webp" name="' . esc_attr( $opt ) . '[quality_webp]" value="' . esc_attr( (string) ( $settings['quality_webp'] ?? 82 ) ) . '" class="small-text" /></div>'
			. '</div>';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_quality_png_compression">' . esc_html__( 'PNG Compression', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'PNG compression level (0–9). Higher = smaller file, slower processing.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control"><input type="number" min="0" max="9" id="kitgenix_image_optimizer_quality_png_compression" name="' . esc_attr( $opt ) . '[quality_png_compression]" value="' . esc_attr( (string) ( $settings['quality_png_compression'] ?? 6 ) ) . '" class="small-text" /></div>'
			. '</div>';

		echo '</div></div></div>';

		// ---------------------------------------------------------------
		// Resizing
		// ---------------------------------------------------------------
		echo '<div class="kitgenix-card">';
		self::card_head( 'resize', __( 'Resizing', 'kitgenix-image-optimizer' ), __( 'Cap the maximum dimensions of uploaded images. Aspect ratio is always preserved.', 'kitgenix-image-optimizer' ) );
		echo '<div class="kitgenix-card-body"><div class="kitgenix-settings-group">';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_max_width">' . esc_html__( 'Max Width (px)', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Images wider than this will be scaled down. Set to 0 to disable.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control"><input type="number" min="0" id="kitgenix_image_optimizer_max_width" name="' . esc_attr( $opt ) . '[max_width]" value="' . esc_attr( (string) ( $settings['max_width'] ?? 2560 ) ) . '" class="small-text" /></div>'
			. '</div>';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_max_height">' . esc_html__( 'Max Height (px)', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Images taller than this will be scaled down. Set to 0 to disable.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control"><input type="number" min="0" id="kitgenix_image_optimizer_max_height" name="' . esc_attr( $opt ) . '[max_height]" value="' . esc_attr( (string) ( $settings['max_height'] ?? 2560 ) ) . '" class="small-text" /></div>'
			. '</div>';

		echo '</div></div></div>';

		// ---------------------------------------------------------------
		// Advanced
		// ---------------------------------------------------------------
		echo '<div class="kitgenix-card">';
		self::card_head( 'advanced', __( 'Advanced', 'kitgenix-image-optimizer' ), __( 'Format conversion, metadata, thumbnails, and other fine-tuning.', 'kitgenix-image-optimizer' ) );
		echo '<div class="kitgenix-card-body"><div class="kitgenix-settings-group">';

		$webp_supported = Capabilities::supports_webp();
		$avif_supported = Capabilities::supports_avif();

		$advanced_toggles = [
			[
				'key'      => 'convert_to_webp',
				'id'       => 'kitgenix_image_optimizer_webp',
				'label'    => __( 'Convert to WebP', 'kitgenix-image-optimizer' ),
				'desc'     => $webp_supported
					? __( 'Save an additional WebP copy alongside JPEG and PNG uploads (e.g. photo.jpg.webp).', 'kitgenix-image-optimizer' )
					: __( 'Unavailable: this server\'s GD/Imagick build cannot encode WebP.', 'kitgenix-image-optimizer' ),
				'disabled' => ! $webp_supported,
			],
			[
				'key'      => 'convert_to_avif',
				'id'       => 'kitgenix_image_optimizer_avif',
				'label'    => __( 'Convert to AVIF', 'kitgenix-image-optimizer' ),
				'desc'     => $avif_supported
					? __( 'Save an additional AVIF copy alongside JPEG and PNG uploads (e.g. photo.jpg.avif). Usually smaller than WebP at the same visual quality.', 'kitgenix-image-optimizer' )
					: __( 'Unavailable: this server\'s GD/Imagick build cannot encode AVIF. See the Diagnostics tab for details.', 'kitgenix-image-optimizer' ),
				'disabled' => ! $avif_supported,
			],
			[ 'key' => 'strip_metadata', 'id' => 'kitgenix_image_optimizer_strip', 'label' => __( 'Strip Image Metadata', 'kitgenix-image-optimizer' ), 'desc' => __( 'Remove EXIF, GPS location, and other embedded metadata from images to reduce file size and improve privacy. Orientation is always applied correctly before metadata is removed.', 'kitgenix-image-optimizer' ) ],
			[ 'key' => 'optimize_thumbnails', 'id' => 'kitgenix_image_optimizer_thumbs', 'label' => __( 'Optimize Thumbnails', 'kitgenix-image-optimizer' ), 'desc' => __( 'Also compress registered image sub-sizes when optimizing an attachment.', 'kitgenix-image-optimizer' ) ],
			[ 'key' => 'backup_originals', 'id' => 'kitgenix_image_optimizer_backup', 'label' => __( 'Backup Originals', 'kitgenix-image-optimizer' ), 'desc' => __( 'Keep a copy of the original file before optimization (stored alongside the image as filename-kitgenix-original.ext), so it can be restored later.', 'kitgenix-image-optimizer' ) ],
			[ 'key' => 'skip_animated_gif', 'id' => 'kitgenix_image_optimizer_gif', 'label' => __( 'Skip Animated GIFs', 'kitgenix-image-optimizer' ), 'desc' => __( 'Skip animated GIF files to preserve their animation (recommended).', 'kitgenix-image-optimizer' ) ],
			[ 'key' => 'lazy_load', 'id' => 'kitgenix_image_optimizer_lazy', 'label' => __( 'Lazy Load Images', 'kitgenix-image-optimizer' ), 'desc' => __( 'Add loading="lazy" to all frontend images that lack a loading attribute (the LCP candidate is kept eager).', 'kitgenix-image-optimizer' ) ],
		];

		foreach ( $advanced_toggles as $row ) {
			$is_disabled = ! empty( $row['disabled'] );
			echo '<div class="kitgenix-setting-row">'
				. '<div class="kitgenix-setting-row-label"><label for="' . esc_attr( $row['id'] ) . '">' . esc_html( $row['label'] ) . '</label>'
				. '<p class="kitgenix-setting-row-desc">' . esc_html( $row['desc'] ) . '</p></div>'
				. '<div class="kitgenix-setting-row-control">'
				. '<label class="kitgenix-toggle' . ( $is_disabled ? ' kitgenix-toggle-disabled' : '' ) . '">'
				. '<input type="checkbox" class="kitgenix-toggle-input" id="' . esc_attr( $row['id'] ) . '" name="' . esc_attr( $opt ) . '[' . esc_attr( $row['key'] ) . ']" value="1" '
				. checked( ! empty( $settings[ $row['key'] ] ) && ! $is_disabled, true, false )
				. ( $is_disabled ? ' disabled="disabled"' : '' ) . ' />'
				. '<span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span>'
				. '</label>'
				. '</div>'
				. '</div>';
		}

		if ( $avif_supported ) {
			echo '<div class="kitgenix-setting-row">'
				. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_quality_avif">' . esc_html__( 'AVIF Quality', 'kitgenix-image-optimizer' ) . '</label>'
				. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Used only when Compression Level is set to Custom.', 'kitgenix-image-optimizer' ) . '</p></div>'
				. '<div class="kitgenix-setting-row-control"><input type="number" min="1" max="100" id="kitgenix_image_optimizer_quality_avif" name="' . esc_attr( $opt ) . '[quality_avif]" value="' . esc_attr( (string) ( $settings['quality_avif'] ?? 60 ) ) . '" class="small-text" /></div>'
				. '</div>';
		}

		echo '</div></div></div>';

		// ---------------------------------------------------------------
		// Exclusions (collapsible – open by default)
		// ---------------------------------------------------------------
		echo '<div class="kitgenix-card" data-kitgenix-collapsible>';
		echo '<button type="button" class="kitgenix-collapsible-trigger" aria-expanded="true">'
			. '<span class="kitgenix-card-head-main">'
			. '<span class="kitgenix-card-icon" aria-hidden="true">' . self::icon( 'exclude' ) . '</span>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
			. '<span class="kitgenix-card-head-text">'
			. '<span class="kitgenix-collapsible-title">' . esc_html__( 'Exclusions', 'kitgenix-image-optimizer' ) . '</span>'
			. '<span class="kitgenix-collapsible-desc">' . esc_html__( 'Skip specific files by size or filename pattern.', 'kitgenix-image-optimizer' ) . '</span>'
			. '</span>'
			. '</span>'
			. '<svg class="kitgenix-collapsible-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>'
			. '</button>';
		echo '<div class="kitgenix-collapsible-panel"><div class="kitgenix-card-body"><div class="kitgenix-settings-group">';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_exclude_below_kb">' . esc_html__( 'Skip files smaller than (KB)', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Files smaller than this size will be skipped. Set to 0 to disable.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control"><input type="number" min="0" id="kitgenix_image_optimizer_exclude_below_kb" name="' . esc_attr( $opt ) . '[exclude_below_kb]" value="' . esc_attr( (string) ( $settings['exclude_below_kb'] ?? 0 ) ) . '" class="small-text" /></div>'
			. '</div>';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_exclude_above_kb">' . esc_html__( 'Skip files larger than (KB)', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Files larger than this size will be skipped. Set to 0 to disable.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control"><input type="number" min="0" id="kitgenix_image_optimizer_exclude_above_kb" name="' . esc_attr( $opt ) . '[exclude_above_kb]" value="' . esc_attr( (string) ( $settings['exclude_above_kb'] ?? 0 ) ) . '" class="small-text" /></div>'
			. '</div>';

		echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked">'
			. '<div class="kitgenix-setting-row-label"><label>' . esc_html__( 'Skip by File Type', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Never process these formats, even if uploaded.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control">';
		$excluded_mimes = is_array( $settings['excluded_mime_types'] ?? null ) ? $settings['excluded_mime_types'] : [];
		foreach ( Settings::OPTIMIZABLE_MIMES as $mime ) {
			$mime_label = strtoupper( str_replace( 'image/', '', $mime ) );
			echo '<label class="kitgenix-checkbox-inline"><input type="checkbox" name="' . esc_attr( $opt ) . '[excluded_mime_types][]" value="' . esc_attr( $mime ) . '" ' . checked( in_array( $mime, $excluded_mimes, true ), true, false ) . ' /> ' . esc_html( $mime_label ) . '</label> ';
		}
		echo '</div></div>';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_excluded_min_width">' . esc_html__( 'Skip images smaller than (px)', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Width and/or height, in pixels. Useful for leaving tiny icons untouched. 0 disables.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control">'
			. '<input type="number" min="0" id="kitgenix_image_optimizer_excluded_min_width" name="' . esc_attr( $opt ) . '[excluded_min_width]" value="' . esc_attr( (string) ( $settings['excluded_min_width'] ?? 0 ) ) . '" class="small-text" placeholder="' . esc_attr__( 'Width', 'kitgenix-image-optimizer' ) . '" /> &times; '
			. '<input type="number" min="0" id="kitgenix_image_optimizer_excluded_min_height" name="' . esc_attr( $opt ) . '[excluded_min_height]" value="' . esc_attr( (string) ( $settings['excluded_min_height'] ?? 0 ) ) . '" class="small-text" placeholder="' . esc_attr__( 'Height', 'kitgenix-image-optimizer' ) . '" />'
			. '</div>'
			. '</div>';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_excluded_max_width">' . esc_html__( 'Skip images larger than (px)', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Width and/or height, in pixels. 0 disables.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control">'
			. '<input type="number" min="0" id="kitgenix_image_optimizer_excluded_max_width" name="' . esc_attr( $opt ) . '[excluded_max_width]" value="' . esc_attr( (string) ( $settings['excluded_max_width'] ?? 0 ) ) . '" class="small-text" placeholder="' . esc_attr__( 'Width', 'kitgenix-image-optimizer' ) . '" /> &times; '
			. '<input type="number" min="0" id="kitgenix_image_optimizer_excluded_max_height" name="' . esc_attr( $opt ) . '[excluded_max_height]" value="' . esc_attr( (string) ( $settings['excluded_max_height'] ?? 0 ) ) . '" class="small-text" placeholder="' . esc_attr__( 'Height', 'kitgenix-image-optimizer' ) . '" />'
			. '</div>'
			. '</div>';

		echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_excluded_filename_patterns">' . esc_html__( 'Skip by Filename Pattern', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Comma-separated list of filename substrings or glob patterns to skip. Example: logo, banner-*.png', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control"><textarea id="kitgenix_image_optimizer_excluded_filename_patterns" name="' . esc_attr( $opt ) . '[excluded_filename_patterns]" rows="3" placeholder="' . esc_attr__( 'e.g. logo, banner, -thumb', 'kitgenix-image-optimizer' ) . '">' . esc_textarea( (string) ( $settings['excluded_filename_patterns'] ?? '' ) ) . '</textarea></div>'
			. '</div>';

		echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_excluded_directories">' . esc_html__( 'Skip by Directory', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Comma-separated substrings of the path relative to the uploads folder. Example: 2023/product-shoots', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control"><textarea id="kitgenix_image_optimizer_excluded_directories" name="' . esc_attr( $opt ) . '[excluded_directories]" rows="2" placeholder="' . esc_attr__( 'e.g. 2023/originals, brand-assets', 'kitgenix-image-optimizer' ) . '">' . esc_textarea( (string) ( $settings['excluded_directories'] ?? '' ) ) . '</textarea></div>'
			. '</div>';

		echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_excluded_attachment_ids">' . esc_html__( 'Skip Specific Attachments', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Comma-separated attachment IDs to never optimize.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control"><input type="text" id="kitgenix_image_optimizer_excluded_attachment_ids" name="' . esc_attr( $opt ) . '[excluded_attachment_ids]" value="' . esc_attr( (string) ( $settings['excluded_attachment_ids'] ?? '' ) ) . '" class="regular-text" placeholder="' . esc_attr__( 'e.g. 42, 108, 233', 'kitgenix-image-optimizer' ) . '" /></div>'
			. '</div>';

		echo '</div></div></div></div>';

		self::render_uninstall_card( $settings, $opt );

		echo '<div class="kitgenix-button-group kitgenix-button-group-end">';
		submit_button( __( 'Save Settings', 'kitgenix-image-optimizer' ), 'primary', 'submit', false );
		echo '</div>';
		echo '</form>';
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private static function render_uninstall_card( array $settings, string $opt ): void {
		echo '<div class="kitgenix-card">';
		self::card_head( 'trash', __( 'Uninstall', 'kitgenix-image-optimizer' ), __( 'Controls what happens to plugin data when Kitgenix Image Optimizer is deleted from Plugins → Installed Plugins.', 'kitgenix-image-optimizer' ) );
		echo '<div class="kitgenix-card-body"><div class="kitgenix-settings-group">';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_keep_data">' . esc_html__( 'Keep Settings & Data', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'When enabled, uninstalling the plugin keeps its settings, activity log, and per-image optimization records – useful if you plan to reinstall later. Your Media Library images are never deleted either way.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control">'
			. '<label class="kitgenix-toggle">'
			. '<input type="checkbox" class="kitgenix-toggle-input" id="kitgenix_image_optimizer_keep_data" name="' . esc_attr( $opt ) . '[keep_data_on_uninstall]" value="1" ' . checked( ! empty( $settings['keep_data_on_uninstall'] ), true, false ) . ' />'
			. '<span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span>'
			. '</label>'
			. '</div>'
			. '</div>';

		echo '<div class="kitgenix-setting-row">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix_image_optimizer_delete_backups">' . esc_html__( 'Delete Backup Files on Uninstall', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'When enabled (and "Keep Settings & Data" above is off), uninstalling also deletes any -kitgenix-original backup files and .webp/.avif sidecar files this plugin created. Off by default – backups are left in place unless you explicitly opt in, since a backup may be the only remaining copy of an image\'s pre-optimization bytes.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control">'
			. '<label class="kitgenix-toggle">'
			. '<input type="checkbox" class="kitgenix-toggle-input" id="kitgenix_image_optimizer_delete_backups" name="' . esc_attr( $opt ) . '[delete_backups_on_uninstall]" value="1" ' . checked( ! empty( $settings['delete_backups_on_uninstall'] ), true, false ) . ' />'
			. '<span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span>'
			. '</label>'
			. '</div>'
			. '</div>';

		echo '</div></div></div>';
	}

	private static function render_images_tab(): void {
		$total       = Optimizer::count_total_optimizable_attachments();
		$optimized   = Optimizer::count_optimized_attachments();
		$unoptimized = max( 0, $total - $optimized );
		$savings     = Optimizer::get_total_savings_bytes();
		$pct         = $total > 0 ? (int) round( ( $optimized / $total ) * 100 ) : 0;

		// KPI stat tiles.
		echo '<div class="kitgenix-stat-grid kitgenix-image-optimizer-stats-grid">';

		echo '<div class="kitgenix-stat-tile">'
			. '<div class="kitgenix-stat-tile-head"><span class="kitgenix-stat-tile-label">' . esc_html__( 'Total Images', 'kitgenix-image-optimizer' ) . '</span>'
			. '<span class="kitgenix-stat-tile-icon" aria-hidden="true">' . self::icon( 'images' ) . '</span></div>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
			. '<div class="kitgenix-stat-tile-value" id="kitgenix-image-optimizer-stat-total">' . esc_html( number_format_i18n( $total ) ) . '</div>'
			. '<div class="kitgenix-stat-tile-trend kitgenix-trend-neutral"><span>' . esc_html__( 'JPEG, PNG, WebP & GIF files', 'kitgenix-image-optimizer' ) . '</span></div>'
			. '</div>';

		echo '<div class="kitgenix-stat-tile">'
			. '<div class="kitgenix-stat-tile-head"><span class="kitgenix-stat-tile-label">' . esc_html__( 'Optimized', 'kitgenix-image-optimizer' ) . '</span>'
			. '<span class="kitgenix-stat-tile-icon" aria-hidden="true">' . self::icon( 'toggle' ) . '</span></div>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
			. '<div class="kitgenix-stat-tile-value" id="kitgenix-image-optimizer-stat-optimized">' . esc_html( number_format_i18n( $optimized ) ) . '</div>'
			. '<div class="kitgenix-stat-tile-trend kitgenix-trend-up"><span>' . esc_html( $pct . '% ' . __( 'complete', 'kitgenix-image-optimizer' ) ) . '</span></div>'
			. '</div>';

		echo '<div class="kitgenix-stat-tile">'
			. '<div class="kitgenix-stat-tile-head"><span class="kitgenix-stat-tile-label">' . esc_html__( 'Pending', 'kitgenix-image-optimizer' ) . '</span>'
			. '<span class="kitgenix-stat-tile-icon" aria-hidden="true">' . self::icon( 'log' ) . '</span></div>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
			. '<div class="kitgenix-stat-tile-value kitgenix-image-optimizer-stat--pending" id="kitgenix-image-optimizer-stat-pending">' . esc_html( number_format_i18n( $unoptimized ) ) . '</div>'
			. '<div class="kitgenix-stat-tile-trend kitgenix-trend-neutral"><span>' . esc_html__( 'Not yet processed', 'kitgenix-image-optimizer' ) . '</span></div>'
			. '</div>';

		echo '<div class="kitgenix-stat-tile kitgenix-image-optimizer-savings-card">'
			. '<div class="kitgenix-stat-tile-head"><span class="kitgenix-stat-tile-label">' . esc_html__( 'Space Saved', 'kitgenix-image-optimizer' ) . '</span>'
			. '<span class="kitgenix-stat-tile-icon" aria-hidden="true">' . self::icon( 'chart' ) . '</span></div>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
			. '<div class="kitgenix-stat-tile-value kitgenix-image-optimizer-stat--savings" id="kitgenix-image-optimizer-stat-savings">' . esc_html( size_format( $savings ) ) . '</div>'
			. '<div class="kitgenix-stat-tile-trend kitgenix-trend-up"><span>' . esc_html__( 'Cumulative disk space reclaimed', 'kitgenix-image-optimizer' ) . '</span></div>'
			. '</div>';

		echo '</div>'; // .kitgenix-stat-grid

		echo '<p class="kitgenix-image-optimizer-recalc-row">'
			. '<button type="button" id="kitgenix-image-optimizer-recalculate-stats" class="button-link">' . esc_html__( 'Recalculate stats from scratch', 'kitgenix-image-optimizer' ) . '</button>'
			. ' <span class="description">' . esc_html__( '(if numbers ever look off after manual file changes)', 'kitgenix-image-optimizer' ) . '</span>'
			. '</p>';

		// Bulk optimization panel.
		echo '<div class="kitgenix-card">';
		self::card_head( 'bulk', __( 'Bulk Optimization', 'kitgenix-image-optimizer' ), __( 'Compress all existing images in your media library. Runs one image at a time so it never times out, even on large sites.', 'kitgenix-image-optimizer' ) );
		echo '<div class="kitgenix-card-body">';

		echo '<div class="kitgenix-image-optimizer-progress-wrap">';
		echo '<div class="kitgenix-progress" role="progressbar" aria-valuenow="' . esc_attr( (string) $pct ) . '" aria-valuemin="0" aria-valuemax="100">'
			. '<div class="kitgenix-progress-bar" id="kitgenix-image-optimizer-progress-fill" style="width:' . esc_attr( $pct . '%' ) . '"></div>'
			. '</div>';
		echo '<p class="kitgenix-progress-label" id="kitgenix-image-optimizer-progress-label">'
			. esc_html( sprintf(
				/* translators: 1: optimized count, 2: total count, 3: percent */
				__( '%1$s of %2$s images optimized (%3$d%%)', 'kitgenix-image-optimizer' ),
				number_format_i18n( $optimized ),
				number_format_i18n( $total ),
				$pct
			) )
			. '</p>';
		echo '</div>';

		$queue_progress = Queue::progress();
		$failed_count   = (int) $queue_progress['failed_count'];

		echo '<div id="kitgenix-image-optimizer-bulk-wrap" class="kitgenix-button-group">'
			. '<button type="button" id="kitgenix-image-optimizer-bulk-start" class="button button-primary"' . ( $unoptimized === 0 ? ' disabled' : '' ) . '>'
			. ( $unoptimized === 0 ? esc_html__( 'All Images Optimized', 'kitgenix-image-optimizer' ) : esc_html__( 'Start Bulk Optimization', 'kitgenix-image-optimizer' ) )
			. '</button>'
			. '<button type="button" id="kitgenix-image-optimizer-bulk-reoptimize" class="button button-secondary" title="' . esc_attr__( 'Re-process all images using current quality settings, including already-optimised ones.', 'kitgenix-image-optimizer' ) . '">'
			. esc_html__( 'Bulk Re-Optimize', 'kitgenix-image-optimizer' )
			. '</button>'
			. '<button type="button" id="kitgenix-image-optimizer-bulk-retry" class="button button-secondary"' . ( $failed_count === 0 ? ' style="display:none"' : '' ) . '>'
			. esc_html( sprintf(
				/* translators: %d: number of images that failed to optimize */
				__( 'Retry %d Failed', 'kitgenix-image-optimizer' ),
				$failed_count
			) )
			. '</button>'
			. '<span id="kitgenix-image-optimizer-bulk-status" class="kitgenix-image-optimizer-bulk-status"></span>'
			. '</div>';
		echo '<p class="kitgenix-setting-row-desc" style="margin-top:8px;">'
			. esc_html__( 'Bulk runs also continue in the background (via a scheduled task) if you close this page, and resume automatically if paused.', 'kitgenix-image-optimizer' )
			. '</p>';
		echo '</div></div>'; // .kitgenix-card-body, .kitgenix-card

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

		echo '<div class="kitgenix-card">';
		self::card_head( 'images', __( 'Recent Images', 'kitgenix-image-optimizer' ) );
		echo '<div class="kitgenix-card-body">';
		echo '<div class="kitgenix-table-wrap">'
			. '<table class="kitgenix-table kitgenix-image-optimizer-images-table">'
			. '<thead><tr>'
			. '<th class="col-thumb">' . esc_html__( 'Preview', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th class="col-file">' . esc_html__( 'File', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th class="col-original">' . esc_html__( 'Original', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th class="col-savings">' . esc_html__( 'Savings', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th class="col-status">' . esc_html__( 'Status', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th class="kitgenix-table-actions-col col-actions">' . esc_html__( 'Actions', 'kitgenix-image-optimizer' ) . '</th>'
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
			$backup_path = $file_path ? Optimizer::backup_path_for( $file_path ) : '';
			$has_backup  = $backup_path && file_exists( $backup_path );

			if ( $is_error ) {
				$err_msg      = isset( $opt_meta['error_message'] ) ? (string) $opt_meta['error_message'] : __( 'Optimization failed', 'kitgenix-image-optimizer' );
				$status_badge = '<span class="kitgenix-badge danger" title="' . esc_attr( $err_msg ) . '">' . esc_html__( 'Error', 'kitgenix-image-optimizer' ) . '</span>';
				$savings_chip = '<span class="kitgenix-image-optimizer-savings-chip is-none">&mdash;</span>';
			} elseif ( $is_optimized ) {
				$status_badge = '<span class="kitgenix-badge success">' . esc_html__( 'Optimized', 'kitgenix-image-optimizer' ) . '</span>';
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
					. '<button type="button" class="button button-small kitgenix-image-optimizer-restore-backup" data-attachment-id="' . esc_attr( (string) $attachment_id ) . '" style="color:#9c3b4b">' . esc_html__( 'Restore', 'kitgenix-image-optimizer' ) . '</button>';
			} elseif ( $is_optimized ) {
				$action_html = '<button type="button" class="button button-small kitgenix-image-optimizer-optimize-single" data-attachment-id="' . esc_attr( (string) $attachment_id ) . '">' . esc_html__( 'Re-optimize', 'kitgenix-image-optimizer' ) . '</button>';
			} elseif ( $is_error ) {
				$action_html = '<button type="button" class="button button-small kitgenix-image-optimizer-optimize-single" data-attachment-id="' . esc_attr( (string) $attachment_id ) . '">' . esc_html__( 'Re-optimize', 'kitgenix-image-optimizer' ) . '</button>';
			}

			echo '<tr id="kitgenix-image-optimizer-row-' . esc_attr( (string) $attachment_id ) . '" data-kitgenix-table-row>'
				. '<td class="col-thumb">' . wp_kses( $thumb, $allowed_img ) . '</td>'
				. '<td class="col-file"><span class="kitgenix-image-optimizer-filename">' . esc_html( $filename ) . '</span><br><span class="kitgenix-image-optimizer-mime">' . esc_html( $mime_label ) . '</span></td>'
				. '<td class="col-original">' . ( $original_size > 0 ? esc_html( size_format( $original_size ) ) : '<span class="kitgenix-sr-only">&mdash;</span><span aria-hidden="true">&mdash;</span>' ) . '</td>'
				. '<td class="col-savings kitgenix-image-optimizer-savings-cell">' . wp_kses( $savings_chip, $allowed_span ) . '</td>'
				. '<td class="col-status kitgenix-image-optimizer-status-cell">' . wp_kses( $status_badge, [ 'span' => [ 'class' => true, 'title' => true ] ] ) . '</td>'
				. '<td class="col-actions"><div class="kitgenix-table-actions">' . wp_kses( $action_html, [ 'button' => [ 'type' => true, 'class' => true, 'data-attachment-id' => true, 'style' => true ] ] ) . '</div></td>'
				. '</tr>';
		}
		wp_reset_postdata();

		echo '</tbody></table></div></div></div>';
	}

	/**
	 * Server capability matrix – what this server can actually encode/decode,
	 * detected live rather than assumed. Mirrors the same data exposed via
	 * Site Health and `wp kitgenix-optimizer status`.
	 */
	private static function render_diagnostics_tab(): void {
		$report = Capabilities::get_report();

		echo '<div class="kitgenix-card">';
		self::card_head( 'tools', __( 'Server Capabilities', 'kitgenix-image-optimizer' ), __( 'Detected live from this server – format availability always depends on what your host\'s GD/Imagick build actually supports.', 'kitgenix-image-optimizer' ) );
		echo '<div class="kitgenix-card-body">';

		echo '<div class="kitgenix-stat-grid">';
		echo '<div class="kitgenix-stat-tile">'
			. '<div class="kitgenix-stat-tile-head"><span class="kitgenix-stat-tile-label">' . esc_html__( 'GD Extension', 'kitgenix-image-optimizer' ) . '</span></div>'
			. '<div class="kitgenix-stat-tile-value">' . ( $report['gd_available'] ? '<span class="kitgenix-badge success">' . esc_html__( 'Available', 'kitgenix-image-optimizer' ) . '</span>' : '<span class="kitgenix-badge muted">' . esc_html__( 'Not available', 'kitgenix-image-optimizer' ) . '</span>' ) . '</div>'
			. '<div class="kitgenix-stat-tile-trend kitgenix-trend-neutral"><span>' . esc_html( $report['gd_version'] ?: '–' ) . '</span></div>'
			. '</div>';
		echo '<div class="kitgenix-stat-tile">'
			. '<div class="kitgenix-stat-tile-head"><span class="kitgenix-stat-tile-label">' . esc_html__( 'Imagick Extension', 'kitgenix-image-optimizer' ) . '</span></div>'
			. '<div class="kitgenix-stat-tile-value">' . ( $report['imagick_available'] ? '<span class="kitgenix-badge success">' . esc_html__( 'Available', 'kitgenix-image-optimizer' ) . '</span>' : '<span class="kitgenix-badge muted">' . esc_html__( 'Not available', 'kitgenix-image-optimizer' ) . '</span>' ) . '</div>'
			. '<div class="kitgenix-stat-tile-trend kitgenix-trend-neutral"><span>' . esc_html( $report['imagick_version'] ?: '–' ) . '</span></div>'
			. '</div>';
		echo '<div class="kitgenix-stat-tile">'
			. '<div class="kitgenix-stat-tile-head"><span class="kitgenix-stat-tile-label">' . esc_html__( 'Active Editor', 'kitgenix-image-optimizer' ) . '</span></div>'
			. '<div class="kitgenix-stat-tile-value" style="font-size:16px;">' . esc_html( $report['preferred_editor'] ) . '</div>'
			. '<div class="kitgenix-stat-tile-trend kitgenix-trend-neutral"><span>' . esc_html__( 'WordPress picks this automatically', 'kitgenix-image-optimizer' ) . '</span></div>'
			. '</div>';
		echo '<div class="kitgenix-stat-tile">'
			. '<div class="kitgenix-stat-tile-head"><span class="kitgenix-stat-tile-label">' . esc_html__( 'PHP memory_limit', 'kitgenix-image-optimizer' ) . '</span></div>'
			. '<div class="kitgenix-stat-tile-value" style="font-size:16px;">' . esc_html( $report['memory_limit_text'] ) . '</div>'
			. '<div class="kitgenix-stat-tile-trend kitgenix-trend-neutral"><span>' . esc_html( sprintf(
				/* translators: %s: maximum total pixel count this server will attempt to decode */
				__( 'Max %s px decoded per image', 'kitgenix-image-optimizer' ),
				number_format_i18n( (int) $report['max_pixels'] )
			) ) . '</span></div>'
			. '</div>';
		echo '</div>';

		echo '<div class="kitgenix-table-wrap" style="margin-top:16px;">'
			. '<table class="kitgenix-table">'
			. '<thead><tr>'
			. '<th>' . esc_html__( 'Format', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th>' . esc_html__( 'Decode', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th>' . esc_html__( 'Encode', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th>' . esc_html__( 'WordPress Will Use It', 'kitgenix-image-optimizer' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $report['formats'] as $format => $data ) {
			$yes = '<span class="kitgenix-badge success">' . esc_html__( 'Yes', 'kitgenix-image-optimizer' ) . '</span>';
			$no  = '<span class="kitgenix-badge muted">' . esc_html__( 'No', 'kitgenix-image-optimizer' ) . '</span>';
			echo '<tr>'
				. '<td><strong>' . esc_html( strtoupper( $format ) ) . '</strong></td>'
				. '<td>' . ( $data['decode'] ? $yes : $no ) . '</td>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $yes/$no are pre-built with esc_html__() above.
				. '<td>' . ( $data['encode'] ? $yes : $no ) . '</td>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $yes/$no are pre-built with esc_html__() above.
				. '<td>' . ( $data['wp_supported'] ? $yes : $no ) . '</td>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $yes/$no are pre-built with esc_html__() above.
				. '</tr>';
		}

		echo '</tbody></table></div>';

		echo '<p class="kitgenix-setting-row-desc" style="margin-top:12px;">'
			. esc_html__( 'AVIF and WebP conversion in the Settings tab automatically disable themselves when the server cannot encode that format – this plugin never claims support it cannot actually deliver.', 'kitgenix-image-optimizer' )
			. '</p>';

		echo '</div></div>';

		// PHP/server limits relevant to large-image handling.
		echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><div class="kitgenix-card-head-text">'
			. '<h3>' . esc_html__( 'Upload Limits', 'kitgenix-image-optimizer' ) . '</h3>'
			. '</div></div></div>';
		echo '<div class="kitgenix-card-body"><div class="kitgenix-table-wrap"><table class="kitgenix-table kitgenix-status-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'upload_max_filesize', 'kitgenix-image-optimizer' ) . '</th><td>' . esc_html( $report['upload_max_filesize'] ?: '–' ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'post_max_size', 'kitgenix-image-optimizer' ) . '</th><td>' . esc_html( $report['post_max_size'] ?: '–' ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'max_execution_time', 'kitgenix-image-optimizer' ) . '</th><td>' . esc_html( $report['max_execution_time'] ?: '–' ) . '</td></tr>';
		echo '</tbody></table></div></div></div>';
	}

	private static function render_support_tab(): void {
		$donate_url      = 'https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2';
		$plugin_page_url = 'https://kitgenix.com/plugins/kitgenix-image-optimizer/';
		$docs_url        = 'https://kitgenix.com/plugins/kitgenix-image-optimizer/documentation/';
		$review_url      = 'https://wordpress.org/support/plugin/kitgenix-image-optimizer/reviews/#new-post';
		$copy_onclick    = "if(window.navigator&&navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(" . wp_json_encode( $plugin_page_url ) . ");}else{window.prompt(" . wp_json_encode( __( 'Copy plugin link:', 'kitgenix-image-optimizer' ) ) . ", " . wp_json_encode( $plugin_page_url ) . ");}return false;";

		echo '<div class="kitgenix-image-optimizer-support-page kitgenix-support-page">'
			. '<div class="kitgenix-card kitgenix-support-hero">'
			. '<div class="kitgenix-support-hero-inner">'

			. '<span class="kitgenix-support-hero-icon" aria-hidden="true">' . self::icon( 'heart' ) . '</span>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
			. '<p class="kitgenix-support-hero-eyebrow">' . esc_html__( 'Support Kitgenix', 'kitgenix-image-optimizer' ) . '</p>'
			. '<h2>' . esc_html__( 'Help us keep building', 'kitgenix-image-optimizer' ) . '</h2>'
			. '<p class="kitgenix-support-hero-body">' . esc_html__( 'Image Optimizer compresses and converts every image in your Media Library right on your own server, so pages load faster without sending a single file to a third-party API. Donations are what keep it maintained, updated, and free for everyone.', 'kitgenix-image-optimizer' ) . '</p>'
			. '<a class="kitgenix-support-hero-button" href="' . esc_url( $donate_url ) . '" target="_blank" rel="noopener noreferrer">'
			. self::icon( 'paypal' )  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
			. esc_html__( 'Donate with PayPal', 'kitgenix-image-optimizer' )
			. '</a>'
			. '<p class="kitgenix-support-hero-caption">'
			. self::icon( 'shield' )  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
			. esc_html__( 'Donations are entirely optional. Kitgenix plugins keep working whether you donate or not.', 'kitgenix-image-optimizer' )
			. '</p>'
			. '</div>'

			. '<div class="kitgenix-support-links">'
			. '<a class="kitgenix-support-link" href="' . esc_url( $review_url ) . '" target="_blank" rel="noopener noreferrer">'
			. self::icon( 'star' )  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
			. esc_html__( 'Leave a review', 'kitgenix-image-optimizer' )
			. '</a>'
			. '<a class="kitgenix-support-link" href="' . esc_url( $docs_url ) . '" target="_blank" rel="noopener noreferrer">'
			. self::icon( 'docs' )  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
			. esc_html__( 'Read the docs', 'kitgenix-image-optimizer' )
			. '</a>'
			. '<button type="button" class="kitgenix-support-link" onclick="' . esc_attr( $copy_onclick ) . '">'
			. self::icon( 'copy' )  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
			. esc_html__( 'Copy plugin link', 'kitgenix-image-optimizer' )
			. '</button>'
			. '</div>'

			. '</div>'
			. '</div>';
	}

	private static function render_log_tab(): void {
		$log_entries = Event_Log::get_raw_log();
		$clear_url   = wp_nonce_url(
			admin_url( 'admin-post.php?action=kitgenix_image_optimizer_clear_event_log' ),
			'kitgenix_image_optimizer_clear_event_log'
		);

		$date_format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		echo '<div class="kitgenix-card">'
			. '<div class="kitgenix-card-head"><div>'
			. '<h2 class="kitgenix-support-subheading">' . esc_html__( 'Activity Log', 'kitgenix-image-optimizer' ) . '</h2>'
			. '<p class="description">' . esc_html__( 'Recent optimization and settings events (newest first, up to 100 entries).', 'kitgenix-image-optimizer' ) . '</p>'
			. '</div></div>';
		echo '<div class="kitgenix-card-body">';

		if ( empty( $log_entries ) ) {
			echo '<div class="kitgenix-empty-state">'
				. '<span class="kitgenix-empty-state-icon" aria-hidden="true">' . self::icon( 'log' ) . '</span>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
				. '<h3 class="kitgenix-empty-state-title">' . esc_html__( 'No events recorded yet', 'kitgenix-image-optimizer' ) . '</h3>'
				. '<p class="kitgenix-empty-state-desc">' . esc_html__( 'Optimization and settings activity will appear here once something happens.', 'kitgenix-image-optimizer' ) . '</p>'
				. '</div>';
		} else {
			echo '<div class="kitgenix-search-bar"><div class="kitgenix-search-bar-input">'
				. '<span class="kitgenix-search-bar-icon" aria-hidden="true">' . self::icon( 'search' ) . '</span>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
				. '<input type="search" placeholder="' . esc_attr__( 'Search log…', 'kitgenix-image-optimizer' ) . '" data-kitgenix-table-search data-kitgenix-table-search-target="#kitgenix-image-optimizer-log-table" />'
				. '</div></div>';

			echo '<div class="kitgenix-table-wrap" id="kitgenix-image-optimizer-log-table" data-kitgenix-table-paginate="25">'
				. '<table class="kitgenix-table kitgenix-image-optimizer-log-table">'
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
					$when = $time ? (string) wp_date( $date_format, $time ) : '–';
				} else {
					// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date_i18n
					$when = $time ? (string) date_i18n( $date_format, $time ) : '–';
				}

				$badge_class = match ( $outcome ) {
					'success' => 'success',
					'error'   => 'danger',
					default   => 'neutral',
				};

				echo '<tr data-kitgenix-table-row>'
					. '<td class="kitgenix-log-time">' . esc_html( $when ) . '</td>'
					. '<td class="kitgenix-log-context"><code>' . esc_html( $context ) . '</code></td>'
					. '<td class="kitgenix-log-outcome"><span class="kitgenix-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $outcome ) . '</span></td>'
					. '<td class="kitgenix-log-note">' . esc_html( $note ) . '</td>'
					. '</tr>';
			}

			echo '</tbody></table>'
				. '<div class="kitgenix-empty-state" data-kitgenix-table-empty style="display:none;">'
				. '<p class="kitgenix-empty-state-title">' . esc_html__( 'No matching log entries', 'kitgenix-image-optimizer' ) . '</p>'
				. '</div>'
				. '</div>';
		}

		self::render_log_reference_table();

		$retention_days = Event_Log::get_retention_days();

		echo '<div class="kitgenix-setting-row" style="margin-top:16px">'
			. '<div class="kitgenix-setting-row-label"><label for="kitgenix-log-retention-days">' . esc_html__( 'Auto-delete logs after', 'kitgenix-image-optimizer' ) . '</label>'
			. '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Log entries older than the selected period are removed automatically.', 'kitgenix-image-optimizer' ) . '</p></div>'
			. '<div class="kitgenix-setting-row-control">'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;align-items:center;gap:8px;">'
			. '<input type="hidden" name="action" value="kitgenix_image_optimizer_save_log_retention" />'
			. wp_nonce_field( 'kitgenix_image_optimizer_save_log_retention', '_wpnonce', true, false )
			. '<select id="kitgenix-log-retention-days" name="log_retention_days">'
			. '<option value="7" ' . selected( $retention_days, 7, false ) . '>' . esc_html__( '7 days', 'kitgenix-image-optimizer' ) . '</option>'
			. '<option value="14" ' . selected( $retention_days, 14, false ) . '>' . esc_html__( '14 days', 'kitgenix-image-optimizer' ) . '</option>'
			. '<option value="30" ' . selected( $retention_days, 30, false ) . '>' . esc_html__( '30 days', 'kitgenix-image-optimizer' ) . '</option>'
			. '</select>'
			. '<button type="submit" class="button button-secondary">' . esc_html__( 'Save', 'kitgenix-image-optimizer' ) . '</button>'
			. '</form>'
			. '</div>'
			. '</div>';

		echo '<div class="kitgenix-button-group" style="margin-top:16px">'
			. '<a class="button button-secondary" href="' . esc_url( $clear_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Clear all log entries?', 'kitgenix-image-optimizer' ) ) . '\')">' . esc_html__( 'Clear Log', 'kitgenix-image-optimizer' ) . '</a>'
			. '</div>';
		echo '</div></div>';
	}

	/**
	 * Renders the "what each event means" reference table for the Log tab –
	 * lets a non-technical site owner tell a genuine problem apart from
	 * something that happens by design or by environment limitation.
	 */
	private static function render_log_reference_table(): void {
		$rows = [
			[ 'settings-saved', __( 'Plugin settings were saved from the admin screen.', 'kitgenix-image-optimizer' ), __( 'None.', 'kitgenix-image-optimizer' ) ],
			[ 'optimized', __( 'An image was resized and/or compressed and saved successfully.', 'kitgenix-image-optimizer' ), __( 'None.', 'kitgenix-image-optimizer' ) ],
			[ 'skipped-by-design', __( 'The file was an animated GIF, matched an exclusion rule, or is an unsupported file type – so it was left alone on purpose.', 'kitgenix-image-optimizer' ), __( 'None – this is expected. Adjust your settings if you want these files processed.', 'kitgenix-image-optimizer' ) ],
			[ 'webp-not-generated', __( 'The optional WebP copy could not be generated, but the main image was still optimized normally.', 'kitgenix-image-optimizer' ), __( 'Usually none. If it happens for every image, your server\'s GD/Imagick build may lack WebP support.', 'kitgenix-image-optimizer' ) ],
			[ 'file-permissions', __( 'The file could not be read or written by PHP.', 'kitgenix-image-optimizer' ), __( 'Check that the uploads folder and this file are writable by the web server.', 'kitgenix-image-optimizer' ) ],
			[ 'filesystem-error', __( 'PHP could not read the file\'s size from disk.', 'kitgenix-image-optimizer' ), __( 'Check server filesystem permissions and disk health.', 'kitgenix-image-optimizer' ) ],
			[ 'missing-image-library', __( 'WordPress could not create an image editor for this file.', 'kitgenix-image-optimizer' ), __( 'The server is likely missing the GD or Imagick PHP extension, or it doesn\'t support this image format – check with your host.', 'kitgenix-image-optimizer' ) ],
			[ 'resize-error', __( 'The image library failed to resize this file.', 'kitgenix-image-optimizer' ), __( 'The file may be corrupted or use an unsupported color profile. Try re-uploading the original.', 'kitgenix-image-optimizer' ) ],
			[ 'save-error', __( 'The image library failed to save the optimized file back to disk.', 'kitgenix-image-optimizer' ), __( 'Check available disk space and file/folder permissions.', 'kitgenix-image-optimizer' ) ],
			[ 'file-missing', __( 'The file for this attachment no longer exists on disk.', 'kitgenix-image-optimizer' ), __( 'The file may have been moved or deleted outside WordPress. Re-upload the image if needed.', 'kitgenix-image-optimizer' ) ],
			[ 'no-backup-available', __( 'No backup copy exists for this image.', 'kitgenix-image-optimizer' ), __( 'None to restore – backups are only made when "Backup Originals" was enabled at optimization time.', 'kitgenix-image-optimizer' ) ],
			[ 'restore-error', __( 'The backup file could not be copied back over the optimized file.', 'kitgenix-image-optimizer' ), __( 'Check file permissions and available disk space.', 'kitgenix-image-optimizer' ) ],
			[ 'restored', __( 'An image was successfully restored from its backup, and its metadata/sub-sizes were regenerated.', 'kitgenix-image-optimizer' ), __( 'None.', 'kitgenix-image-optimizer' ) ],
			[ 'avif-not-generated', __( 'The optional AVIF copy could not be generated, but the main image was still optimized normally.', 'kitgenix-image-optimizer' ), __( 'Usually none. If it happens for every image, check the Diagnostics tab for AVIF support.', 'kitgenix-image-optimizer' ) ],
			[ 'image-safety-rejected', __( 'The file was rejected before decoding – invalid dimensions, an oversized pixel count, or not enough available PHP memory to decode it safely.', 'kitgenix-image-optimizer' ), __( 'Usually none – this is a deliberate safety limit. If it happens on a file you expect to be valid, check the Diagnostics tab for the current memory/pixel limits.', 'kitgenix-image-optimizer' ) ],
		];

		echo '<h3 class="kitgenix-support-subheading" style="margin-top:20px;">' . esc_html__( 'What each event means', 'kitgenix-image-optimizer' ) . '</h3>';

		echo '<div class="kitgenix-table-wrap">'
			. '<table class="kitgenix-table">'
			. '<thead><tr>'
			. '<th>' . esc_html__( 'Category', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th>' . esc_html__( 'What it means', 'kitgenix-image-optimizer' ) . '</th>'
			. '<th>' . esc_html__( 'Action needed?', 'kitgenix-image-optimizer' ) . '</th>'
			. '</tr></thead>'
			. '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>'
				. '<td><code>' . esc_html( $row[0] ) . '</code></td>'
				. '<td>' . esc_html( $row[1] ) . '</td>'
				. '<td>' . esc_html( $row[2] ) . '</td>'
				. '</tr>';
		}

		echo '</tbody></table></div>';
	}

	private static function render_sidebar(): void {
		$plugin_file = defined( 'KITGENIX_IMAGE_OPTIMIZER_FILE' ) ? (string) KITGENIX_IMAGE_OPTIMIZER_FILE : '';
		$social_base = plugins_url( 'assets/images/social-media/', $plugin_file );

		echo '<aside class="kitgenix-settings-sidebar" aria-label="' . esc_attr__( 'Help and links', 'kitgenix-image-optimizer' ) . '">';

		echo '<div class="kitgenix-card"><div class="kitgenix-card-body">'
			. '<h2>' . esc_html__( 'Need Help?', 'kitgenix-image-optimizer' ) . '</h2>'
			. '<p>' . esc_html__( 'Open the documentation for setup guidance or send us a support request if you need help configuring the plugin.', 'kitgenix-image-optimizer' ) . '</p>'
			. '<div class="kitgenix-button-group kitgenix-button-group-stack">'
			. '<a class="button button-secondary" href="' . esc_url( 'https://kitgenix.com/plugins/kitgenix-image-optimizer/documentation/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'kitgenix-image-optimizer' ) . '</a>'
			. '<a class="button button-primary" href="' . esc_url( 'https://kitgenix.com/plugins/kitgenix-image-optimizer/support' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Request Support', 'kitgenix-image-optimizer' ) . '</a>'
			. '<a class="button button-secondary" href="' . esc_url( 'https://roadmap.kitgenix.com/?product=image-optimizer' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Request a Feature', 'kitgenix-image-optimizer' ) . '</a>'
			. '</div>'
			. '</div></div>';

		echo '<div class="kitgenix-card"><div class="kitgenix-card-body">'
			. '<h2>' . esc_html__( 'Visit Our Official Facebook Group', 'kitgenix-image-optimizer' ) . '</h2>'
			. '<p>' . esc_html__( 'Join the Kitgenix community to ask questions, share feedback, and keep up with product updates.', 'kitgenix-image-optimizer' ) . '</p>'
			. '<div class="kitgenix-button-group kitgenix-button-group-stack">'
			. '<a class="button button-secondary" href="' . esc_url( 'https://www.facebook.com/groups/kitgenix' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Join Group', 'kitgenix-image-optimizer' ) . '</a>'
			. '</div>'
			. '</div></div>';

		echo '<div class="kitgenix-card"><div class="kitgenix-card-body">'
			. '<h2>' . esc_html__( 'Follow Us', 'kitgenix-image-optimizer' ) . '</h2>'
			. '<p>' . esc_html__( 'Keep up with new releases, tutorials, and product news across our channels.', 'kitgenix-image-optimizer' ) . '</p>'
			. '<div class="kitgenix-sidebar-social-grid">'
			. '<a class="kitgenix-sidebar-social-link" href="https://kitgenix.com" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="' . esc_url( $social_base . 'globe-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>'
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
		echo '</div>';

		echo '</aside>';
	}
}
