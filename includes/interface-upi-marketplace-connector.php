<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hợp đồng cho các Marketplace Connector tương lai (EtsyConnector,
 * EbayConnector, AmazonConnector, TikTokConnector...). KHÔNG có class nào
 * implement interface này ở Phase 1 — mục đích chỉ là khoá kiến trúc lại
 * để khi build tính năng publish-ngược-ra-marketplace, không phải sửa
 * Canonical Product, Template Engine, hay Bulk Workspace.
 *
 * "Soi gương" với MarketplaceAdapter bên Chrome Extension:
 *   Adapter   = đọc dữ liệu VÀO   (marketplace → Import Library)
 *   Connector = đẩy dữ liệu RA    (WooCommerce/canonical → marketplace)
 */
interface UPI_Marketplace_Connector {

	/** Khoá định danh marketplace, vd. 'etsy' | 'ebay' | 'amazon' | 'tiktok'. */
	public function get_marketplace_key(): string;

	public function connect( array $credentials );

	public function disconnect();

	public function authenticate();

	/** @return true|WP_Error */
	public function validate_product( object $canonical_product );

	public function get_categories(): array;

	public function get_attributes( string $category_id ): array;

	/** @return object|WP_Error Dòng upi_listings vừa tạo. */
	public function create_listing( object $canonical_product );

	public function update_listing( object $listing, object $canonical_product );

	public function delete_listing( object $listing );

	public function get_listing( string $external_listing_id );

	public function sync_listing( object $listing );
}

/**
 * Registry rỗng — nơi các Connector tương lai tự đăng ký, cùng pattern với
 * cách MarketplaceAdapter đăng ký ở phía Chrome Extension.
 *
 * Ví dụ dùng sau này (chưa có ở Phase 1):
 *   UPI_Connector_Registry::register( 'etsy', EtsyConnector::class );
 */
class UPI_Connector_Registry {

	private static array $connectors = array();

	public static function register( string $marketplace_key, string $class_name ) {
		self::$connectors[ $marketplace_key ] = $class_name;
	}

	public static function get( string $marketplace_key ): ?string {
		return self::$connectors[ $marketplace_key ] ?? null;
	}

	public static function all(): array {
		return self::$connectors;
	}
}
