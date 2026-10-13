<?php
/**
 * Main plugin bootstrap.
 */

declare(strict_types=1);

namespace KitgenixImageOptimizer;

defined( 'ABSPATH' ) || exit;

use KitgenixImageOptimizer\Admin\Attachment_Panel;
use KitgenixImageOptimizer\Admin\Settings_UI;
use KitgenixImageOptimizer\Core\CLI;
use KitgenixImageOptimizer\Core\Event_Log;
use KitgenixImageOptimizer\Core\Lazy_Load;
use KitgenixImageOptimizer\Core\Media_Column;
use KitgenixImageOptimizer\Core\Migrations;
use KitgenixImageOptimizer\Core\Optimizer;
use KitgenixImageOptimizer\Core\Settings;
use KitgenixImageOptimizer\Core\Site_Health;
use KitgenixImageOptimizer\Core\Webp_Delivery;

final class Plugin {
	public static function init(): void {
		// Ensure defaults exist.
		Settings::ensure_defaults();

		// Register settings.
		\add_action( 'admin_init', [ Settings::class, 'register_settings' ] );

		// Versioned data migrations (runs at most once per upgrade).
		Migrations::init();

		// Event log retention housekeeping (daily cron prune).
		Event_Log::init();

		// Admin-only features.
		if ( \is_admin() ) {
			Settings_UI::init();
			Media_Column::init();
			Attachment_Panel::init();
		}

		// Core optimizer hooks (also boots the bulk Queue).
		Optimizer::init();

		// Frontend lazy loading (when enabled).
		Lazy_Load::init();

		// WebP frontend delivery (when enabled).
		Webp_Delivery::init();

		// Site Health diagnostics (capability matrix, failure counts).
		Site_Health::init();

		// WP-CLI commands.
		if ( \defined( 'WP_CLI' ) && \WP_CLI ) {
			CLI::register();
		}
	}
}
