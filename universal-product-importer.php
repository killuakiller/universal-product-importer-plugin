<?php
/**
 * Plugin Name: Universal Product Importer
 * Description: Generic, multi-site-capable product research importer: crawl products from marketplaces via the companion Chrome Extension, stage them in an Import Library, classify/edit them, then create WooCommerce Simple Product drafts from site-specific templates. No brand, domain, or third-party plugin (including WPCA) is assumed or touched.
 * Version: 0.9.10
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * Author: Universal Product Importer
 * Text Domain: universal-product-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UPI_VERSION', '0.9.10' );
define( 'UPI_PATH', plugin_dir_path( __FILE__ ) );
define( 'UPI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Self-update from the private GitHub repo (killuakiller/universal-product-importer-plugin).
 *
 * UPI_GH_TOKEN is a read-only, repo-scoped GitHub fine-grained token,
 * defined in wp-config.php. Without it, this site just won't see update
 * notices (repo is private) but nothing else breaks.
 */
if ( is_admin() ) {
	require_once UPI_PATH . 'includes/plugin-update-checker/plugin-update-checker.php';

	$upiUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/killuakiller/universal-product-importer-plugin/',
		__FILE__,
		'universal-product-importer-plugin'
	);
	$upiUpdateChecker->setBranch( 'main' );

	if ( defined( 'UPI_GH_TOKEN' ) && UPI_GH_TOKEN ) {
		$upiUpdateChecker->setAuthentication( UPI_GH_TOKEN );
	}
}

require_once UPI_PATH . 'includes/class-upi-activator.php';
require_once UPI_PATH . 'includes/class-upi-db.php';
require_once UPI_PATH . 'includes/class-upi-logger.php';
require_once UPI_PATH . 'includes/class-upi-auth.php';
require_once UPI_PATH . 'includes/class-upi-media.php';
require_once UPI_PATH . 'includes/class-upi-templates.php';
require_once UPI_PATH . 'includes/class-upi-imports.php';
require_once UPI_PATH . 'includes/class-upi-products.php';
require_once UPI_PATH . 'includes/class-upi-product-creator.php';
require_once UPI_PATH . 'includes/class-upi-jobs.php';
require_once UPI_PATH . 'includes/class-upi-publish-queue.php';
require_once UPI_PATH . 'includes/class-upi-listings.php';
require_once UPI_PATH . 'includes/interface-upi-marketplace-connector.php';
require_once UPI_PATH . 'includes/class-upi-rest-api.php';
require_once UPI_PATH . 'admin/class-upi-admin.php';

register_activation_hook( __FILE__, array( 'UPI_Activator', 'activate' ) );

// Action Scheduler hook cho batch job cần được đăng ký sớm (không chỉ khi
// có WooCommerce active tại đúng lúc job chạy nền), nên gắn ngoài upi_init().
UPI_Jobs::init();
UPI_Publish_Queue::init();
UPI_Product_Creator::init();

function upi_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'Universal Product Importer requires WooCommerce to be active.', 'universal-product-importer' ) .
					'</p></div>';
			}
		);
		return;
	}

	// dbDelta có thể chạy lại an toàn để THÊM cột/bảng mới (không xoá dữ
	// liệu) — dùng để tự nâng cấp schema khi cập nhật plugin, không bắt
	// người dùng phải deactivate/activate lại thủ công.
	if ( get_option( 'upi_db_version' ) !== '7' ) {
		UPI_Activator::activate();
	}

	new UPI_Admin();

	add_action( 'rest_api_init', array( new UPI_REST_API(), 'register_routes' ) );
}
add_action( 'plugins_loaded', 'upi_init' );
