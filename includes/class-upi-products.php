<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lớp 2 — Canonical Product.
 *
 * Đây là mô hình sản phẩm trung lập, không phụ thuộc WooCommerce. Mọi thao
 * tác trong Bulk Product Workspace (sửa title, ảnh, giá, gán template hàng
 * loạt...) đều đọc/ghi vào bảng này. `overrides_json` ghi lại field nào
 * người dùng đã tự sửa tay, để khi đổi template sau đó không bị ghi đè mất
 * — đúng thứ tự ưu tiên: Template Defaults → Imported Data → User Overrides.
 */
class UPI_Products {

	const ALLOWED_STATUSES = array( 'crawled', 'classified', 'editing', 'ready', 'draft', 'published', 'rejected' );

	/** Các field mà khi user tự sửa sẽ được "khoá" khỏi default của template. */
	// Giá KHÔNG còn override cấp sản phẩm nữa — giá thuộc về Template
	// (regular_price/sale_price bị loại khỏi danh sách này theo yêu cầu:
	// "Product Workspace không cần ô nhập giá, giá luôn do Template quyết
	// định"). Cột DB regular_price/sale_price của upi_products vẫn còn
	// nhưng không còn được ghi qua update() nữa — xem bên dưới.
	const OVERRIDABLE_FIELDS = array( 'title', 'description', 'short_description', 'category_id', 'tags', 'sku' );

	/**
	 * Category CHỌN THÊM trực tiếp từ extension (Local Staging) — độc lập với
	 * category của Template. Khi tạo Draft, danh sách này được CỘNG DỒN vào
	 * category của Template (không thay thế), xem UPI_Product_Creator.
	 */
	public static function extra_category_ids( object $product ): array {
		$ids = json_decode( $product->extra_category_ids_json ?? '', true );
		return is_array( $ids ) ? array_values( array_unique( array_map( 'absint', $ids ) ) ) : array();
	}

	public static function find( int $id ) {
		global $wpdb;
		$table = UPI_DB::products_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function find_by_import_id( int $import_id ) {
		global $wpdb;
		$table = UPI_DB::products_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE import_id = %d", $import_id ) );
	}

	public static function find_by_wc_product_id( int $wc_product_id ) {
		global $wpdb;
		$table = UPI_DB::products_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_product_id = %d", $wc_product_id ) );
	}

	/** Lookup hàng loạt theo wc_product_id — dùng cho trang Drafts (tránh N+1 query khi hiện template hiện tại của từng dòng). @return array<int,object> khoá theo wc_product_id. */
	public static function find_all_by_wc_product_ids( array $wc_product_ids ): array {
		$wc_product_ids = array_filter( array_map( 'absint', $wc_product_ids ) );
		if ( empty( $wc_product_ids ) ) {
			return array();
		}
		global $wpdb;
		$table        = UPI_DB::products_table();
		$placeholders = implode( ',', array_fill( 0, count( $wc_product_ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_product_id IN ({$placeholders})", $wc_product_ids ) );

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->wc_product_id ] = $row;
		}
		return $out;
	}

	/**
	 * Tạo canonical product từ 1 import row vừa crawl xong — copy dữ liệu
	 * ban đầu (title/description/images), chưa có override nào.
	 */
	public static function create_from_import( int $import_id ) {
		$import = UPI_Imports::find( $import_id );
		if ( ! $import ) {
			return new WP_Error( 'import_not_found', 'Không tìm thấy dữ liệu crawl gốc.', array( 'status' => 404 ) );
		}

		global $wpdb;
		$table = UPI_DB::products_table();

		$wpdb->insert(
			$table,
			array(
				'import_id'   => $import_id,
				'title'       => $import->title,
				'description' => $import->description,
				'images_json' => $import->images_json,
				'status'      => 'crawled',
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * Query cho Bulk Product Workspace — hỗ trợ filter theo status,
	 * classification, template, tìm kiếm theo title, phân trang.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$products_table = UPI_DB::products_table();
		$imports_table  = UPI_DB::imports_table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::ALLOWED_STATUSES, true ) ) {
			$where[]  = 'p.status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['classification'] ) ) {
			if ( 'unclassified' === $args['classification'] ) {
				$where[] = 'p.classification IS NULL';
			} else {
				$where[]  = 'p.classification = %s';
				$params[] = sanitize_text_field( $args['classification'] );
			}
		}
		if ( ! empty( $args['template_id'] ) ) {
			$where[]  = 'p.template_id = %d';
			$params[] = absint( $args['template_id'] );
		}
		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'i.source = %s';
			$params[] = sanitize_key( $args['source'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(p.title LIKE %s OR p.edited_title LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$per_page = isset( $args['per_page'] ) ? min( 200, max( 1, absint( $args['per_page'] ) ) ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$sql = "SELECT p.*, i.source AS source, i.source_id AS source_id, i.source_url AS source_url
				FROM {$products_table} p
				INNER JOIN {$imports_table} i ON i.id = p.import_id
				WHERE " . implode( ' AND ', $where ) . '
				ORDER BY p.created_at DESC LIMIT %d OFFSET %d';
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$count_sql    = "SELECT COUNT(*) FROM {$products_table} p INNER JOIN {$imports_table} i ON i.id = p.import_id WHERE " . implode( ' AND ', $where );
		$count_params = array_slice( $params, 0, -2 );
		$total        = $count_params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) ) : $wpdb->get_var( $count_sql );

		return array(
			'items'    => $rows,
			'total'    => (int) $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Ghi field, đồng thời cập nhật overrides_json khi field đó nằm trong
	 * OVERRIDABLE_FIELDS — để bulk-assign template sau này không ghi đè
	 * mất giá trị người dùng đã tự sửa.
	 */
	public static function update( int $id, array $data ) {
		$product = self::find( $id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Không tìm thấy sản phẩm.', array( 'status' => 404 ) );
		}

		global $wpdb;
		$table   = UPI_DB::products_table();
		$clean   = array();
		$formats = array();
		$overrides = json_decode( $product->overrides_json ?: '[]', true );
		if ( ! is_array( $overrides ) ) {
			$overrides = array();
		}

		$mark_override = function ( string $field ) use ( &$overrides ) {
			if ( in_array( $field, UPI_Products::OVERRIDABLE_FIELDS, true ) && ! in_array( $field, $overrides, true ) ) {
				$overrides[] = $field;
			}
		};

		if ( isset( $data['edited_title'] ) ) {
			$clean['edited_title'] = sanitize_text_field( $data['edited_title'] );
			$formats[] = '%s';
			$mark_override( 'title' );
		}
		if ( isset( $data['edited_description'] ) ) {
			$clean['edited_description'] = wp_kses_post( $data['edited_description'] );
			$formats[] = '%s';
			$mark_override( 'description' );
		}
		if ( isset( $data['short_description'] ) ) {
			$clean['short_description'] = wp_kses_post( $data['short_description'] );
			$formats[] = '%s';
			$mark_override( 'short_description' );
		}
		if ( isset( $data['classification'] ) ) {
			$clean['classification'] = sanitize_text_field( $data['classification'] );
			$formats[] = '%s';
			if ( 'crawled' === $product->status ) {
				$clean['status'] = 'classified';
				$formats[] = '%s';
			}
		}
		if ( isset( $data['template_id'] ) ) {
			$clean['template_id'] = $data['template_id'] ? absint( $data['template_id'] ) : null;
			$formats[] = '%d';
		}
		// KHÔNG còn nhận regular_price/sale_price ở đây nữa — giá luôn do
		// Template quyết định (xem UPI_Product_Creator::create_draft()).
		if ( isset( $data['category_id'] ) ) {
			$clean['category_id'] = absint( $data['category_id'] );
			$formats[] = '%d';
			$mark_override( 'category_id' );
		}
		if ( array_key_exists( 'extra_category_ids', $data ) ) {
			$ids = is_array( $data['extra_category_ids'] ) ? $data['extra_category_ids'] : array();
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
			$clean['extra_category_ids_json'] = $ids ? wp_json_encode( $ids ) : null;
			$formats[] = '%s';
		}
		if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
			$clean['tags_json'] = wp_json_encode( array_map( 'sanitize_text_field', $data['tags'] ) );
			$formats[] = '%s';
			$mark_override( 'tags' );
		}
		if ( isset( $data['sku'] ) ) {
			$clean['sku'] = sanitize_text_field( $data['sku'] );
			$formats[] = '%s';
			$mark_override( 'sku' );
		}
		if ( isset( $data['images'] ) && is_array( $data['images'] ) ) {
			$images = array();
			foreach ( $data['images'] as $img ) {
				$attachment_id = isset( $img['attachment_id'] ) ? absint( $img['attachment_id'] ) : 0;

				// Ảnh user tự upload/kéo-thả từ máy trong Local Staging của
				// extension — chỉ có bytes base64, CHƯA có attachment. Giải
				// mã + tạo attachment NGAY tại đây (chỉ 1 lần duy nhất) —
				// KHÔNG lưu base64 thô vào DB (quá nặng cho cột text, và
				// không cần thiết vì đã có attachment_id sau bước này).
				if ( ! $attachment_id && ! empty( $img['data_base64'] ) ) {
					$saved = UPI_Media::save_base64_image( $img['data_base64'], 0, '' );
					if ( is_wp_error( $saved ) ) {
						UPI_Logger::error( 'Upload ảnh từ Local Staging thất bại: ' . $saved->get_error_message(), null, $id );
						continue; // bỏ qua ảnh lỗi, không chặn các ảnh còn lại
					}
					$attachment_id = (int) $saved;
				}

				if ( empty( $img['url'] ) && ! $attachment_id ) {
					continue;
				}

				$images[] = array(
					'url'              => $attachment_id ? wp_get_attachment_url( $attachment_id ) : esc_url_raw( $img['url'] ),
					'source_url'       => isset( $img['source_url'] ) ? esc_url_raw( $img['source_url'] ) : '',
					'full_source_url'  => isset( $img['full_source_url'] ) ? esc_url_raw( $img['full_source_url'] ) : '',
					'position'         => isset( $img['position'] ) ? absint( $img['position'] ) : count( $images ) + 1,
					'selected'         => ! isset( $img['selected'] ) || (bool) $img['selected'],
					'is_custom_upload' => ! empty( $img['is_custom_upload'] ),
					'attachment_id'    => $attachment_id,
				);
			}
			$clean['images_json'] = wp_json_encode( $images );
			$formats[] = '%s';
		}
		if ( isset( $data['status'] ) && in_array( $data['status'], self::ALLOWED_STATUSES, true ) ) {
			$clean['status'] = $data['status'];
			$formats[] = '%s';
		}

		if ( empty( $clean ) ) {
			return new WP_Error( 'no_changes', 'Không có field hợp lệ để cập nhật.', array( 'status' => 400 ) );
		}

		$clean['overrides_json'] = wp_json_encode( array_values( $overrides ) );
		$formats[] = '%s';
		$clean['updated_at'] = current_time( 'mysql' );
		$formats[] = '%s';

		$result = $wpdb->update( $table, $clean, array( 'id' => $id ), $formats, array( '%d' ) );

		if ( false === $result ) {
			return new WP_Error( 'db_error', 'Không thể cập nhật sản phẩm.', array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Gán hàng loạt: template/classification/category/tags cho nhiều sản
	 * phẩm cùng lúc. Field nào sản phẩm đã có override riêng thì bị bỏ qua
	 * cho sản phẩm đó (không ghi đè lựa chọn tay của người dùng).
	 */
	public static function bulk_assign( array $ids, array $data ) {
		$updated = 0;
		foreach ( $ids as $id ) {
			$product = self::find( (int) $id );
			if ( ! $product ) {
				continue;
			}
			$overrides = json_decode( $product->overrides_json ?: '[]', true );
			if ( ! is_array( $overrides ) ) {
				$overrides = array();
			}

			$payload = array();
			if ( isset( $data['template_id'] ) ) {
				$payload['template_id'] = $data['template_id'];
			}
			if ( isset( $data['classification'] ) ) {
				$payload['classification'] = $data['classification'];
			}
			if ( isset( $data['category_id'] ) && ! in_array( 'category_id', $overrides, true ) ) {
				$payload['category_id'] = $data['category_id'];
			}
			if ( ! empty( $data['add_tags'] ) && is_array( $data['add_tags'] ) ) {
				$existing_tags = json_decode( $product->tags_json ?: '[]', true );
				if ( ! is_array( $existing_tags ) ) {
					$existing_tags = array();
				}
				$payload['tags'] = array_values( array_unique( array_merge( $existing_tags, $data['add_tags'] ) ) );
			}
			if ( ! empty( $data['remove_tags'] ) && is_array( $data['remove_tags'] ) ) {
				$existing_tags = json_decode( $product->tags_json ?: '[]', true );
				if ( ! is_array( $existing_tags ) ) {
					$existing_tags = array();
				}
				$payload['tags'] = array_values( array_diff( $existing_tags, $data['remove_tags'] ) );
			}

			if ( empty( $payload ) ) {
				continue;
			}

			$result = self::update( (int) $id, $payload );
			if ( ! is_wp_error( $result ) ) {
				$updated++;
			}
		}
		return $updated;
	}

	public static function bulk_delete( array $ids ) {
		global $wpdb;
		$table = UPI_DB::products_table();
		$ids   = array_map( 'absint', $ids );
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}

	public static function delete( int $id ) {
		global $wpdb;
		return $wpdb->delete( UPI_DB::products_table(), array( 'id' => $id ), array( '%d' ) );
	}
}
