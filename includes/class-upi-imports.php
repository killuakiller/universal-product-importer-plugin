<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ĐỔI KIẾN TRÚC: Local Staging (extension) giờ đã xử lý toàn bộ việc sửa
 * title/description/tags/ảnh/chọn Template TRƯỚC khi gửi — nên bước
 * "Product Workspace" trung gian (sửa lại lần nữa trên WordPress) không
 * còn ý nghĩa. Từ bản này, gửi 1 sản phẩm từ Staging = tạo THẲNG
 * WooCommerce Simple Product Draft trong CÙNG 1 request, không cần bước
 * "Create WooCommerce Draft" riêng nữa.
 *
 * Vẫn giữ `upi_imports` (dữ liệu crawl gốc, bất biến, dùng để chống trùng
 * + trace nguồn gốc) và `upi_products` (canonical, dùng nội bộ làm input
 * cho UPI_Product_Creator) — nhưng KHÔNG còn màn hình admin nào cho user
 * thao tác trên 2 bảng này nữa. Xem WordPress → Products (lọc Draft) để
 * xem/publish kết quả.
 */
class UPI_Imports {

	const ALLOWED_SOURCES = array( 'etsy', 'amazon', 'ebay' );

	public static function find_by_source( string $source, string $source_id ) {
		global $wpdb;
		$table = UPI_DB::imports_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s AND source_id = %s", $source, $source_id )
		);
	}

	public static function find( int $id ) {
		global $wpdb;
		$table = UPI_DB::imports_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Danh sách import (mọi nguồn) có phân trang + lọc theo source/search,
	 * kèm sẵn wc_product_id/product_status của canonical product tương ứng
	 * (nếu có) — dùng cho trang Import History (wp-admin), không có API
	 * riêng nào khác cần join này.
	 */
	public static function get_list( string $source_filter = '', string $search = '', int $per_page = 50, int $page = 1 ): array {
		global $wpdb;
		$imports_table  = UPI_DB::imports_table();
		$products_table = UPI_DB::products_table();

		$where = array( '1=1' );
		$args  = array();

		if ( $source_filter && in_array( $source_filter, self::ALLOWED_SOURCES, true ) ) {
			$where[] = 'i.source = %s';
			$args[]  = $source_filter;
		}
		if ( '' !== $search ) {
			$where[] = 'i.title LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$imports_table} i WHERE {$where_sql}";
		$total     = $args ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : (int) $wpdb->get_var( $count_sql );

		$offset    = max( 0, ( $page - 1 ) * $per_page );
		$list_sql  = "SELECT i.*, p.wc_product_id AS wc_product_id, p.status AS product_status
			FROM {$imports_table} i
			LEFT JOIN {$products_table} p ON p.import_id = i.id
			WHERE {$where_sql}
			ORDER BY i.imported_at DESC
			LIMIT %d OFFSET %d";
		$list_args = array_merge( $args, array( $per_page, $offset ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ) );

		return array( 'rows' => $rows, 'total' => $total );
	}

	/** Đếm nhanh theo source — dùng cho các tab bộ lọc trên trang Import History. */
	public static function counts_by_source(): array {
		global $wpdb;
		$table = UPI_DB::imports_table();
		$rows  = $wpdb->get_results( "SELECT source, COUNT(*) as c FROM {$table} GROUP BY source" );
		$out   = array_fill_keys( self::ALLOWED_SOURCES, 0 );
		foreach ( $rows as $r ) {
			if ( isset( $out[ $r->source ] ) ) {
				$out[ $r->source ] = (int) $r->c;
			}
		}
		return $out;
	}

	/**
	 * Nhận payload ĐẦY ĐỦ từ Local Staging (title/description/tags/
	 * template_id/images đã hoàn thiện) và trong CÙNG 1 request:
	 *  1. Chống trùng theo source+source_id.
	 *  2. Tải ảnh về Media Library (bao gồm cả ảnh custom base64).
	 *  3. Tạo/cập nhật canonical product (upi_products) — dùng nội bộ,
	 *     không có UI riêng.
	 *  4. Tạo THẲNG WooCommerce Simple Product Draft.
	 *
	 * @return array{id:int, product_id:int, wc_product_id:int|null, duplicate:bool, images_downloaded:int, images_failed:int, draft_error:string|null}|WP_Error
	 */
	public static function import_product( array $payload ) {
		$source    = isset( $payload['source'] ) ? sanitize_key( $payload['source'] ) : '';
		$source_id = isset( $payload['source_id'] ) ? sanitize_text_field( (string) $payload['source_id'] ) : '';

		if ( ! in_array( $source, self::ALLOWED_SOURCES, true ) ) {
			return new WP_Error( 'invalid_source', 'Marketplace nguồn không được hỗ trợ.', array( 'status' => 400 ) );
		}
		if ( empty( $source_id ) ) {
			return new WP_Error( 'missing_source_id', 'Cần source_id để chống trùng.', array( 'status' => 400 ) );
		}

		$existing = self::find_by_source( $source, $source_id );

		if ( $existing ) {
			$product = UPI_Products::find_by_import_id( (int) $existing->id );

			if ( $product && $product->wc_product_id ) {
				UPI_Logger::info( "Bỏ qua sản phẩm trùng {$source}:{$source_id} (đã có WooCommerce Draft #{$product->wc_product_id})", $source, (int) $existing->id );
				return array(
					'id'                => (int) $existing->id,
					'product_id'        => (int) $product->id,
					'wc_product_id'     => (int) $product->wc_product_id,
					'duplicate'         => true,
					'images_downloaded' => 0,
					'images_failed'     => 0,
					'draft_error'       => null,
				);
			}

			$product_id = $product ? (int) $product->id : UPI_Products::create_from_import( (int) $existing->id );
			if ( is_wp_error( $product_id ) ) {
				return $product_id;
			}

			return self::finalize( $product_id, (int) $existing->id, $payload, true );
		}

		$title = isset( $payload['title'] ) ? sanitize_text_field( $payload['title'] ) : '';

		$download_result = self::download_images( $payload['images'] ?? array(), $title, $source_id );
		$images          = $download_result['images'];

		global $wpdb;
		$table = UPI_DB::imports_table();

		$inserted = $wpdb->insert(
			$table,
			array(
				'source'        => $source,
				'source_id'     => $source_id,
				'source_sku'    => isset( $payload['source_sku'] ) && $payload['source_sku'] !== null ? sanitize_text_field( $payload['source_sku'] ) : null,
				'source_url'    => isset( $payload['source_url'] ) ? esc_url_raw( $payload['source_url'] ) : '',
				'title'         => $title,
				'price'         => isset( $payload['price'] ) && is_numeric( $payload['price'] ) ? floatval( $payload['price'] ) : null,
				'currency'      => isset( $payload['currency'] ) ? sanitize_text_field( $payload['currency'] ) : null,
				'seller_name'   => isset( $payload['seller_name'] ) ? sanitize_text_field( $payload['seller_name'] ) : null,
				'images_json'   => wp_json_encode( $images ),
				'raw_data_json' => wp_json_encode( isset( $payload['raw_data'] ) ? $payload['raw_data'] : array() ),
				'crawled_at'    => isset( $payload['crawled_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $payload['crawled_at'] ) ) : current_time( 'mysql' ),
				'imported_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			UPI_Logger::error( "Không lưu được import cho {$source}:{$source_id}: " . $wpdb->last_error, $source );
			return new WP_Error( 'db_error', 'Không thể lưu sản phẩm crawl.', array( 'status' => 500 ) );
		}

		$import_id = $wpdb->insert_id;

		$product_id = UPI_Products::create_from_import( $import_id );
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		$result = self::finalize( $product_id, $import_id, $payload, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['images_downloaded'] = $download_result['succeeded'];
		$result['images_failed']     = $download_result['failed'];

		$msg = "Đã crawl {$source}:{$source_id} — ảnh: {$download_result['succeeded']}/{$download_result['total']} tải thành công";
		if ( $download_result['failed'] > 0 ) {
			$msg .= ", {$download_result['failed']} lỗi";
		}
		if ( $result['wc_product_id'] ) {
			$msg .= " — đã tạo WooCommerce Draft #{$result['wc_product_id']}";
		} elseif ( $result['draft_error'] ) {
			$msg .= " — LỖI tạo Draft: {$result['draft_error']}";
		}
		UPI_Logger::info( $msg, $source, $import_id );

		return $result;
	}

	/**
	 * Áp title/description/tags/template_id/category_ids (extra_category_ids)
	 * từ payload vào canonical product, rồi tạo THẲNG WooCommerce Draft —
	 * bước gộp thay thế hoàn toàn cho luồng cũ "Product Workspace → bấm
	 * Create Draft riêng".
	 */
	private static function finalize( int $product_id, int $import_id, array $payload, bool $is_retry ) {
		$update_data = array();
		if ( isset( $payload['tags'] ) && is_array( $payload['tags'] ) ) {
			$update_data['tags'] = $payload['tags'];
		}
		if ( array_key_exists( 'template_id', $payload ) ) {
			$update_data['template_id'] = $payload['template_id'] ?: null;
		}
		if ( array_key_exists( 'category_ids', $payload ) ) {
			$update_data['extra_category_ids'] = is_array( $payload['category_ids'] ) ? $payload['category_ids'] : array();
		}
		if ( isset( $payload['description'] ) && $payload['description'] !== '' ) {
			$update_data['edited_description'] = $payload['description'];
		}
		if ( $is_retry && isset( $payload['images'] ) && is_array( $payload['images'] ) ) {
			$update_data['images'] = $payload['images'];
		}

		if ( $update_data ) {
			$update_result = UPI_Products::update( $product_id, $update_data );
			if ( is_wp_error( $update_result ) ) {
				UPI_Logger::error( 'Không áp được tags/template/description: ' . $update_result->get_error_message(), null, $product_id );
			}
		}

		$draft_result = UPI_Product_Creator::create_draft( $product_id );

		if ( is_wp_error( $draft_result ) ) {
			return array(
				'id'                => $import_id,
				'product_id'        => $product_id,
				'wc_product_id'     => null,
				'duplicate'         => $is_retry,
				'images_downloaded' => 0,
				'images_failed'     => 0,
				'draft_error'       => $draft_result->get_error_message(),
			);
		}

		return array(
			'id'                => $import_id,
			'product_id'        => $product_id,
			'wc_product_id'     => (int) $draft_result,
			'duplicate'         => $is_retry,
			'images_downloaded' => 0,
			'images_failed'     => 0,
			'draft_error'       => null,
		);
	}

	/**
	 * Tải toàn bộ ảnh của 1 sản phẩm về Media Library ngay lúc crawl.
	 * Chấp nhận 3 kiểu ảnh trong cùng 1 mảng:
	 *  - {thumbnail_url, full_url, position} — ảnh crawl, full_url là bản
	 *    độ phân giải cao đã được extension resolve/verify.
	 *  - {url, position} — dạng cũ, coi cả thumbnail_url lẫn full_url là
	 *    giá trị này.
	 *  - {data_base64, position} — ảnh user tự upload/kéo-thả từ máy trong
	 *    Local Staging, giải mã trực tiếp (không có URL để tải).
	 *
	 * 1 ảnh lỗi KHÔNG làm fail cả sản phẩm — bỏ qua, ghi log, tiếp tục các
	 * ảnh còn lại.
	 */
	private static function download_images( $images, string $title_for_alt, string $source_id ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$result = array( 'images' => array(), 'total' => 0, 'succeeded' => 0, 'failed' => 0 );
		if ( ! is_array( $images ) ) {
			return $result;
		}

		$position = 0;
		foreach ( $images as $img ) {
			$position++;

			if ( ! empty( $img['data_base64'] ) ) {
				$result['total']++;
				// Tên file: {source_id}-{counter 2 chữ số} — dễ trace, filesystem-safe,
				// KHÔNG dùng title (có thể chứa ký tự lạ/quá dài).
				$filename_hint = $source_id . '-' . str_pad( (string) $position, 2, '0', STR_PAD_LEFT );
				$attachment_id = UPI_Media::save_base64_image( $img['data_base64'], 0, $title_for_alt, $filename_hint );

				if ( is_wp_error( $attachment_id ) ) {
					$result['failed']++;
					UPI_Logger::error( 'Ảnh custom upload thất bại lúc import: ' . $attachment_id->get_error_message() );
					$result['images'][] = array(
						'attachment_id'    => 0,
						'source_url'       => '',
						'full_source_url'  => '',
						'url'              => '',
						'position'         => $position,
						'selected'         => true,
						'is_custom_upload' => true,
					);
					continue;
				}

				$result['succeeded']++;
				$result['images'][] = array(
					'attachment_id'    => (int) $attachment_id,
					'source_url'       => '',
					'full_source_url'  => '',
					'url'              => wp_get_attachment_url( $attachment_id ),
					'position'         => $position,
					'selected'         => true,
					'is_custom_upload' => true,
				);
				continue;
			}

			$thumbnail_url = isset( $img['thumbnail_url'] ) ? esc_url_raw( $img['thumbnail_url'] ) : ( isset( $img['url'] ) ? esc_url_raw( $img['url'] ) : '' );
			$full_url      = isset( $img['full_url'] ) ? esc_url_raw( $img['full_url'] ) : $thumbnail_url;

			if ( ! $full_url ) {
				continue;
			}
			$result['total']++;

			// Tên file: {source_id}-{counter 2 chữ số} — vd. "4549279421-01.webp" —
			// thay vì giữ tên gốc ngẫu nhiên từ marketplace. Đuôi file luôn lấy theo
			// MIME thật sau khi tải (xử lý trong UPI_Media::sideload), không đoán
			// từ URL — rename không bao giờ làm sai định dạng thật của ảnh.
			$filename_hint = $source_id . '-' . str_pad( (string) $position, 2, '0', STR_PAD_LEFT );
			$attachment_id = UPI_Media::sideload( $full_url, 0, $title_for_alt, $filename_hint );

			if ( is_wp_error( $attachment_id ) ) {
				$result['failed']++;
				UPI_Logger::error( 'Ảnh tải thất bại lúc import: ' . $attachment_id->get_error_message(), null, null, array( 'url' => $full_url ) );
				$result['images'][] = array(
					'attachment_id'    => 0,
					'source_url'       => $thumbnail_url,
					'full_source_url'  => $full_url,
					'url'              => $thumbnail_url,
					'position'         => $position,
					'selected'         => true,
					'is_custom_upload' => false,
				);
				continue;
			}

			$result['succeeded']++;
			$result['images'][] = array(
				'attachment_id'    => (int) $attachment_id,
				'source_url'       => $thumbnail_url,
				'full_source_url'  => $full_url,
				'url'              => wp_get_attachment_url( $attachment_id ),
				'position'         => $position,
				'selected'         => true,
				'is_custom_upload' => false,
			);
		}

		return $result;
	}
}
