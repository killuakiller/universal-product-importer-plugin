<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template quyết định thông tin WooCommerce khi tạo draft: category, giá,
 * tags, brand, mô tả, SKU prefix, và Template Gallery (ảnh chung áp dụng
 * cho mọi sản phẩm dùng template này — vd. size chart, color chart).
 *
 * KHÔNG có field nào liên quan WPCA hay bất kỳ plugin product-options nào
 * — nằm ngoài phạm vi tuyệt đối.
 *
 * KHÔNG có Shipping Class — shipping class do category/cấu hình sẵn có
 * của site quyết định, importer không can thiệp (bỏ theo yêu cầu; cột
 * `shipping_class` vẫn còn trong DB cho các bản cài cũ nhưng không còn
 * được đọc/ghi ở đâu nữa, tránh phải chạy migration xoá cột).
 *
 * CATEGORY — Template có thể gán NHIỀU category cùng lúc (lưu JSON ở
 * `category_ids_json`). Cột `category_id` (số ít) cũ vẫn còn trong DB cho
 * các bản cài trước đây nhưng không còn được đọc/ghi ở đâu nữa — cùng quy
 * ước với `shipping_class` ở trên, tránh phải chạy migration xoá cột.
 */
class UPI_Templates {

	private static function fields() {
		return array(
			'name'                => '%s',
			'category_ids_json'   => '%s',
			'shipping_class_id'   => '%d',
			'regular_price'       => '%f',
			'sale_price'          => '%f',
			'brand'               => '%s',
			'description'         => '%s',
			'short_description'   => '%s',
			'seo_title'           => '%s',
			'seo_description'     => '%s',
			'sku_prefix'          => '%s',
			'gallery_images_json' => '%s',
			'meta_json'           => '%s',
		);
	}

	public static function all() {
		global $wpdb;
		$table = UPI_DB::templates_table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );
	}

	public static function find( int $id ) {
		global $wpdb;
		$table = UPI_DB::templates_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function create( array $data ) {
		global $wpdb;
		$table = UPI_DB::templates_table();
		$clean = self::sanitize( $data );
		$clean['created_at'] = current_time( 'mysql' );
		$clean['updated_at'] = current_time( 'mysql' );

		$formats = array_merge( array_values( array_intersect_key( self::fields(), $clean ) ), array( '%s', '%s' ) );
		$wpdb->insert( $table, $clean, $formats );

		return $wpdb->insert_id;
	}

	public static function update( int $id, array $data ) {
		global $wpdb;
		$table = UPI_DB::templates_table();
		$clean = self::sanitize( $data );
		$clean['updated_at'] = current_time( 'mysql' );

		$formats = array_merge( array_values( array_intersect_key( self::fields(), $clean ) ), array( '%s' ) );

		return $wpdb->update( $table, $clean, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/** Xoá vĩnh viễn Template. KHÔNG đụng tới Draft/canonical product đã gán template này — chúng giữ nguyên dữ liệu đã tạo, chỉ không thể chọn lại Template đã xoá cho lần tạo/đổi tiếp theo. */
	public static function delete( int $id ) {
		global $wpdb;
		$table = UPI_DB::templates_table();
		return $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/** Số canonical product (mọi status) đang gán Template này — dùng để cảnh báo trước khi xoá. */
	public static function count_products_using( int $id ): int {
		global $wpdb;
		$products_table = UPI_DB::products_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$products_table} WHERE template_id = %d", $id ) );
	}

	/** Danh sách attachment ID (đã upload sẵn vào Media Library) của Template Gallery, theo đúng thứ tự đã sắp xếp. */
	public static function gallery_attachment_ids( object $template ): array {
		$ids = json_decode( $template->gallery_images_json ?? '[]', true );
		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/** Danh sách category ID (product_cat) đã gán cho Template — có thể rỗng nếu chưa gán category nào. */
	public static function category_ids( object $template ): array {
		$ids = json_decode( $template->category_ids_json ?? '', true );
		return is_array( $ids ) ? array_values( array_unique( array_map( 'absint', $ids ) ) ) : array();
	}

	private static function sanitize( array $data ): array {
		$clean = array();

		// QUY TẮC: "rỗng nghĩa là KHÔNG áp dụng field đó" — KHÔNG được biến
		// input rỗng thành 0/"" rồi lưu vào DB. Với các field numeric,
		// floatval('')/absint('') đều ra 0 — phải tự kiểm tra rỗng TRƯỚC khi
		// convert, nếu rỗng thì lưu thẳng NULL (wpdb tự hiểu null → SQL NULL).
		if ( isset( $data['name'] ) ) $clean['name'] = sanitize_text_field( $data['name'] );

		if ( array_key_exists( 'category_ids', $data ) ) {
			$ids = is_array( $data['category_ids'] ) ? $data['category_ids'] : array();
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
			// "rỗng nghĩa là KHÔNG áp dụng" — chưa chọn category nào thì lưu
			// NULL, không lưu mảng JSON rỗng "[]".
			$clean['category_ids_json'] = $ids ? wp_json_encode( $ids ) : null;
		}
		if ( array_key_exists( 'shipping_class_id', $data ) ) {
			$clean['shipping_class_id'] = ( $data['shipping_class_id'] !== '' && $data['shipping_class_id'] !== null && (int) $data['shipping_class_id'] > 0 )
				? absint( $data['shipping_class_id'] )
				: null;
		}
		if ( array_key_exists( 'regular_price', $data ) ) {
			$clean['regular_price'] = ( $data['regular_price'] !== '' && $data['regular_price'] !== null )
				? floatval( str_replace( ',', '.', $data['regular_price'] ) )
				: null;
		}
		if ( array_key_exists( 'sale_price', $data ) ) {
			$clean['sale_price'] = ( $data['sale_price'] !== '' && $data['sale_price'] !== null )
				? floatval( str_replace( ',', '.', $data['sale_price'] ) )
				: null;
		}
		if ( array_key_exists( 'brand', $data ) ) {
			$trimmed = trim( (string) $data['brand'] );
			$clean['brand'] = $trimmed !== '' ? sanitize_text_field( $trimmed ) : null;
		}
		if ( array_key_exists( 'sku_prefix', $data ) ) {
			$trimmed = trim( (string) $data['sku_prefix'] );
			$clean['sku_prefix'] = $trimmed !== '' ? sanitize_text_field( $trimmed ) : null;
		}

		if ( isset( $data['description'] ) ) $clean['description'] = wp_kses_post( $data['description'] );
		if ( isset( $data['short_description'] ) ) $clean['short_description'] = wp_kses_post( $data['short_description'] );
		if ( isset( $data['seo_title'] ) ) $clean['seo_title'] = sanitize_text_field( $data['seo_title'] );
		if ( isset( $data['seo_description'] ) ) $clean['seo_description'] = sanitize_textarea_field( $data['seo_description'] );
		if ( isset( $data['gallery_image_ids'] ) ) {
			$ids = is_array( $data['gallery_image_ids'] ) ? $data['gallery_image_ids'] : array();
			$clean['gallery_images_json'] = wp_json_encode( array_values( array_map( 'absint', array_filter( $ids ) ) ) );
		}
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) $clean['meta_json'] = wp_json_encode( $data['meta'] );

		return $clean;
	}
}
