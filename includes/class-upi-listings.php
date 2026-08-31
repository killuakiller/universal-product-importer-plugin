<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * upi_listings — CHƯA có Marketplace Connector nào ghi vào bảng này ở
 * Phase 1. Class này chỉ cung cấp truy vấn đọc, để route REST /listings
 * hoạt động (trả về rỗng) mà không cần đợi tới khi có Connector thật.
 */
class UPI_Listings {

	public static function for_product( int $product_id ) {
		global $wpdb;
		$table = UPI_DB::listings_table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d", $product_id ) );
	}

	public static function all( array $args = array() ) {
		global $wpdb;
		$table = UPI_DB::listings_table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100" );
	}
}
