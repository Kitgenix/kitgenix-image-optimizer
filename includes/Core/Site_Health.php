<?php

declare(strict_types=1);

namespace KitgenixImageOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Site Health integration: surfaces the same capability matrix used by the
 * admin settings screen inside Tools → Site Health, plus a real status test
 * that warns when a setting is enabled but the server can't actually deliver
 * it (e.g. AVIF conversion turned on without AVIF support).
 */
final class Site_Health {

	public static function init(): void {
		add_filter( 'debug_information', [ self::class, 'add_debug_information' ] );
		add_filter( 'site_status_tests', [ self::class, 'add_status_tests' ] );
	}

	/**
	 * @param array<string,mixed> $info
	 * @return array<string,mixed>
	 */
	public static function add_debug_information( array $info ): array {
		$report = Capabilities::get_report();
		$stats  = Stats::get();
		$queue  = Queue::progress();

		$fields = [
			'gd'               => [
				'label' => __( 'GD extension', 'kitgenix-image-optimizer' ),
				'value' => $report['gd_available']
					? sprintf( /* translators: %s: GD version string */ __( 'Available (%s)', 'kitgenix-image-optimizer' ), $report['gd_version'] ?: __( 'version unknown', 'kitgenix-image-optimizer' ) )
					: __( 'Not available', 'kitgenix-image-optimizer' ),
			],
			'imagick'          => [
				'label' => __( 'Imagick extension', 'kitgenix-image-optimizer' ),
				'value' => $report['imagick_available']
					? sprintf( /* translators: %s: Imagick version string */ __( 'Available (%s)', 'kitgenix-image-optimizer' ), $report['imagick_version'] ?: __( 'version unknown', 'kitgenix-image-optimizer' ) )
					: __( 'Not available', 'kitgenix-image-optimizer' ),
			],
			'preferred_editor' => [
				'label' => __( 'Active image editor', 'kitgenix-image-optimizer' ),
				'value' => $report['preferred_editor'],
			],
			'memory_limit'     => [
				'label' => __( 'PHP memory_limit', 'kitgenix-image-optimizer' ),
				'value' => $report['memory_limit_text'],
			],
		];

		foreach ( $report['formats'] as $format => $data ) {
			$fields[ 'format_' . $format ] = [
				'label' => sprintf( /* translators: %s: image format name, e.g. JPEG */ __( '%s support', 'kitgenix-image-optimizer' ), strtoupper( $format ) ),
				'value' => $data['wp_supported']
					? __( 'Supported (encode + decode)', 'kitgenix-image-optimizer' )
					: __( 'Not supported by this server', 'kitgenix-image-optimizer' ),
			];
		}

		$fields['optimized_count'] = [
			'label' => __( 'Images optimized', 'kitgenix-image-optimizer' ),
			'value' => number_format_i18n( (int) $stats['optimized'] ),
		];
		$fields['failed_count'] = [
			'label' => __( 'Recent optimization failures', 'kitgenix-image-optimizer' ),
			'value' => number_format_i18n( (int) $stats['failed'] ),
		];
		$fields['savings'] = [
			'label' => __( 'Total space saved', 'kitgenix-image-optimizer' ),
			'value' => size_format( Stats::total_savings_bytes() ),
		];
		$fields['queue_status'] = [
			'label' => __( 'Bulk queue status', 'kitgenix-image-optimizer' ),
			'value' => sprintf(
				/* translators: 1: queue status (idle/running/paused/completed), 2: number of items awaiting retry */
				__( '%1$s (%2$d item(s) awaiting retry)', 'kitgenix-image-optimizer' ),
				(string) $queue['status'],
				(int) $queue['failed_count']
			),
		];

		$info['kitgenix-image-optimizer'] = [
			'label'  => __( 'Kitgenix Image Optimizer', 'kitgenix-image-optimizer' ),
			'fields' => $fields,
		];

		return $info;
	}

	/**
	 * @param array<string,mixed> $tests
	 * @return array<string,mixed>
	 */
	public static function add_status_tests( array $tests ): array {
		$tests['direct']['kitgenix_image_optimizer_capabilities'] = [
			'label' => __( 'Kitgenix Image Optimizer capabilities', 'kitgenix-image-optimizer' ),
			'test'  => [ self::class, 'run_status_test' ],
		];
		return $tests;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function run_status_test(): array {
		$report = Capabilities::get_report();

		$result = [
			'label'       => __( 'Your server can process images for Kitgenix Image Optimizer', 'kitgenix-image-optimizer' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'Media', 'kitgenix-image-optimizer' ),
				'color' => 'blue',
			],
			'description' => sprintf(
				'<p>%s</p>',
				esc_html__( 'GD or Imagick is available and can encode the image formats this plugin needs.', 'kitgenix-image-optimizer' )
			),
			'actions'     => '',
			'test'        => 'kitgenix_image_optimizer_capabilities',
		];

		$problems = [];

		if ( ! $report['gd_available'] && ! $report['imagick_available'] ) {
			$problems[] = __( 'Neither the GD nor Imagick PHP extension is available, so WordPress cannot process images at all. Ask your host to enable one of them.', 'kitgenix-image-optimizer' );
		}

		if ( Settings::convert_to_webp() && empty( $report['formats']['webp']['wp_supported'] ) ) {
			$problems[] = __( '"Convert to WebP" is enabled, but this server\'s image library cannot encode WebP. WebP sidecars will not be generated until the server is upgraded.', 'kitgenix-image-optimizer' );
		}

		if ( Settings::convert_to_avif() && empty( $report['formats']['avif']['wp_supported'] ) ) {
			$problems[] = __( '"Convert to AVIF" is enabled, but this server\'s image library cannot encode AVIF. AVIF sidecars will not be generated until the server is upgraded.', 'kitgenix-image-optimizer' );
		}

		$failed = Stats::failed_count();
		if ( $failed >= 10 ) {
			$problems[] = sprintf(
				/* translators: %d: number of attachments currently in an error state */
				__( '%d image(s) currently show an optimization error. Review the Log tab under Kitgenix → Image Optimizer.', 'kitgenix-image-optimizer' ),
				$failed
			);
		}

		if ( ! empty( $problems ) ) {
			$result['status']      = ( ! $report['gd_available'] && ! $report['imagick_available'] ) ? 'critical' : 'recommended';
			$result['label']       = __( 'Kitgenix Image Optimizer found a configuration issue', 'kitgenix-image-optimizer' );
			$result['description'] = '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $problems ) ) . '</li></ul>';
			$result['actions']     = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=kitgenix-image-optimizer&tab=diagnostics' ) ),
				esc_html__( 'Review diagnostics', 'kitgenix-image-optimizer' )
			);
		}

		return $result;
	}
}
