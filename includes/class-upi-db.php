<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPI_DB {
	public static function imports_table() {
		global $wpdb;
		return $wpdb->prefix . 'upi_imports';
	}
	public static function products_table() {
		global $wpdb;
		return $wpdb->prefix . 'upi_products';
	}
	public static function listings_table() {
		global $wpdb;
		return $wpdb->prefix . 'upi_listings';
	}
	public static function templates_table() {
		global $wpdb;
		return $wpdb->prefix . 'upi_templates';
	}
	public static function logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'upi_logs';
	}
	public static function publish_queue_table() {
		global $wpdb;
		return $wpdb->prefix . 'upi_publish_queue';
	}
	public static function tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'upi_tokens';
	}

	/**
	 * Danh sách classification nội bộ mặc định. KHÔNG phải category
	 * WooCommerce. Có thể mở rộng qua filter 'upi_classifications' —
	 * không hard-code riêng cho bất kỳ site nào.
	 */
	public static function classifications() {
		return apply_filters( 'upi_classifications', array( 'T-Shirt', 'Sweatshirt', 'Hoodie', 'Other' ) );
	}
}
