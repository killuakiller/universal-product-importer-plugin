<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPI_REST_API {

	const NS = 'product-importer/v1';

	public function register_routes() {

		register_rest_route( self::NS, '/auth/pair', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'pair' ),
			'permission_callback' => '__return_true', // pairing code chính là credential
			'args'                => array( 'pairing_code' => array( 'required' => true, 'type' => 'string' ) ),
		) );

		register_rest_route( self::NS, '/site', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'site_info' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// Import Library (chỉ đọc + tạo mới từ extension) ------------------
		register_rest_route( self::NS, '/imports', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'crawl_product' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );
		register_rest_route( self::NS, '/imports/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_import' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// Canonical Products — LEGACY / INTERNAL ONLY. Đây là API còn sót lại
		// từ "Bulk Product Workspace" đã bỏ hẳn ở v0.9.0. KHÔNG còn caller
		// nào (extension đã bỏ getProduct()/updateProduct() khỏi client.js).
		// Giữ lại route + DB layer (UPI_Products) để không vỡ tương thích
		// ngược nếu có tích hợp ngoài dựa vào REST API này — KHÔNG dùng cho
		// tính năng mới, không phát triển thêm.
		register_rest_route( self::NS, '/products', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_products' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );
		register_rest_route( self::NS, '/products/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_product' ), 'permission_callback' => array( $this, 'check_auth' ) ),
			array( 'methods' => 'PUT', 'callback' => array( $this, 'update_product' ), 'permission_callback' => array( $this, 'check_auth' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, 'delete_product' ), 'permission_callback' => array( $this, 'check_auth' ) ),
		) );
		register_rest_route( self::NS, '/products/bulk-assign', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bulk_assign' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );
		register_rest_route( self::NS, '/products/bulk-delete', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bulk_delete' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );
		register_rest_route( self::NS, '/products/bulk-create-drafts', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bulk_create_drafts' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );
		register_rest_route( self::NS, '/products/jobs/(?P<job_id>[a-zA-Z0-9_]+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'job_status' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// Templates -----------------------------------------------------------
		register_rest_route( self::NS, '/templates', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'list_templates' ), 'permission_callback' => array( $this, 'check_auth' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'create_template' ), 'permission_callback' => array( $this, 'check_auth' ) ),
		) );
		register_rest_route( self::NS, '/templates/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'update_template' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// WooCommerce Categories — chỉ đọc, dùng cho dropdown "Category (thêm)"
		// trong Local Staging của extension (chọn thêm category NGOÀI category
		// đã gán sẵn trong Template, xem UPI_Product_Creator::create_draft()).
		register_rest_route( self::NS, '/categories', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_categories' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// Marketplace (schema sẵn, chưa hoạt động) -----------------------------
		register_rest_route( self::NS, '/listings', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_listings' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// WooCommerce Drafts (do plugin này tạo) — xem/publish ngay trong Local Staging.
		register_rest_route( self::NS, '/drafts', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_drafts' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );
		register_rest_route( self::NS, '/drafts/bulk-publish', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bulk_publish_drafts' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// Logs ------------------------------------------------------------------
		register_rest_route( self::NS, '/logs', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_logs' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );
	}

	/**
	 * Chấp nhận 2 kiểu client:
	 *  - Chrome Extension: Bearer token (xem UPI_Auth).
	 *  - Bulk Product Workspace trong wp-admin: session admin đang đăng
	 *    nhập + nonce chuẩn của WordPress (WP core đã tự xác thực cookie
	 *    trước khi permission_callback chạy, ta chỉ cần kiểm tra quyền).
	 */
	public function check_auth( WP_REST_Request $request ) {
		if ( $request->get_header( 'authorization' ) ) {
			$result = UPI_Auth::authenticate_request( $request );
			return is_wp_error( $result ) ? $result : true;
		}

		if ( is_user_logged_in() && current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new WP_Error( 'rest_forbidden', 'Yêu cầu xác thực.', array( 'status' => 401 ) );
	}

	public function pair( WP_REST_Request $request ) {
		$code  = $request->get_param( 'pairing_code' );
		$label = $request->get_param( 'label' ) ?: 'Chrome Extension';
		$token = UPI_Auth::redeem_pairing_code( $code, $label );

		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return new WP_REST_Response( array( 'token' => $token ), 200 );
	}

	public function site_info() {
		return new WP_REST_Response(
			array(
				'name'    => get_bloginfo( 'name' ),
				'url'     => home_url(),
				'version' => UPI_VERSION,
			),
			200
		);
	}

	public function crawl_product( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'invalid_payload', 'Cần payload JSON hợp lệ.', array( 'status' => 400 ) );
		}
		$result = UPI_Imports::import_product( $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, $result['duplicate'] ? 200 : 201 );
	}

	public function get_import( WP_REST_Request $request ) {
		$import = UPI_Imports::find( (int) $request['id'] );
		if ( ! $import ) {
			return new WP_Error( 'not_found', 'Không tìm thấy.', array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $import, 200 );
	}

	public function list_products( WP_REST_Request $request ) {
		$args = array(
			'status'         => $request->get_param( 'status' ),
			'classification' => $request->get_param( 'classification' ),
			'template_id'    => $request->get_param( 'template_id' ),
			'source'         => $request->get_param( 'source' ),
			'search'         => $request->get_param( 'search' ),
			'page'           => $request->get_param( 'page' ) ?: 1,
			'per_page'       => $request->get_param( 'per_page' ) ?: 50,
		);
		return new WP_REST_Response( UPI_Products::query( $args ), 200 );
	}

	public function get_product( WP_REST_Request $request ) {
		$product = UPI_Products::find( (int) $request['id'] );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Không tìm thấy.', array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $product, 200 );
	}

	public function update_product( WP_REST_Request $request ) {
		$data   = $request->get_json_params();
		$result = UPI_Products::update( (int) $request['id'], is_array( $data ) ? $data : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( UPI_Products::find( (int) $request['id'] ), 200 );
	}

	public function delete_product( WP_REST_Request $request ) {
		UPI_Products::delete( (int) $request['id'] );
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	public function bulk_assign( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		$ids  = isset( $data['ids'] ) && is_array( $data['ids'] ) ? $data['ids'] : array();
		if ( empty( $ids ) ) {
			return new WP_Error( 'invalid_request', 'ids[] là bắt buộc.', array( 'status' => 400 ) );
		}
		$updated = UPI_Products::bulk_assign( $ids, $data );
		return new WP_REST_Response( array( 'updated' => $updated ), 200 );
	}

	public function bulk_delete( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		$ids  = isset( $data['ids'] ) && is_array( $data['ids'] ) ? $data['ids'] : array();
		$deleted = UPI_Products::bulk_delete( $ids );
		return new WP_REST_Response( array( 'deleted' => (int) $deleted ), 200 );
	}

	public function bulk_create_drafts( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		$ids  = isset( $data['ids'] ) && is_array( $data['ids'] ) ? array_map( 'absint', $data['ids'] ) : array();
		if ( empty( $ids ) ) {
			return new WP_Error( 'invalid_request', 'ids[] là bắt buộc.', array( 'status' => 400 ) );
		}

		// Batch nhỏ (<=10): xử lý ngay, trả kết quả luôn cho phản hồi nhanh.
		if ( count( $ids ) <= 10 ) {
			$result = UPI_Product_Creator::create_drafts_bulk( $ids );
			return new WP_REST_Response( array_merge( array( 'sync' => true ), $result ), 200 );
		}

		// Batch lớn: đẩy qua Action Scheduler, trả job_id để client poll.
		$job_id = UPI_Jobs::queue_bulk_create_drafts( $ids );
		return new WP_REST_Response( array( 'sync' => false, 'job_id' => $job_id ), 202 );
	}

	public function job_status( WP_REST_Request $request ) {
		$status = UPI_Jobs::get_status( $request['job_id'] );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		return new WP_REST_Response( $status, 200 );
	}

	public function list_templates() {
		return new WP_REST_Response( UPI_Templates::all(), 200 );
	}

	public function create_template( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		$id   = UPI_Templates::create( is_array( $data ) ? $data : array() );
		return new WP_REST_Response( UPI_Templates::find( $id ), 201 );
	}

	public function update_template( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		UPI_Templates::update( (int) $request['id'], is_array( $data ) ? $data : array() );
		return new WP_REST_Response( UPI_Templates::find( (int) $request['id'] ), 200 );
	}

	/** Toàn bộ WooCommerce product category (kể cả rỗng), phẳng kèm parent để client tự dựng cây/thụt lề. */
	public function list_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return new WP_REST_Response( array(), 200 );
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'id'     => (int) $term->term_id,
				'name'   => $term->name,
				'parent' => (int) $term->parent,
			);
		}

		return new WP_REST_Response( $items, 200 );
	}

	public function list_listings() {
		return new WP_REST_Response( UPI_Listings::all(), 200 );
	}

	/**
	 * Liệt kê WooCommerce Draft do CHÍNH plugin này tạo (nhận diện qua
	 * meta `_source_marketplace`) — để hiển thị trong tab "WooCommerce
	 * Drafts" của Local Staging, không cần rời sang WordPress → Products.
	 */
	public function list_drafts( WP_REST_Request $request ) {
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'draft',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'meta_query'     => array(
					array( 'key' => '_source_marketplace', 'compare' => 'EXISTS' ),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}
			$categories = wp_list_pluck( wc_get_product_terms( $post->ID, 'product_cat' ), 'name' );
			$items[]    = array(
				'id'          => $post->ID,
				'title'       => $product->get_name(),
				'sku'         => $product->get_sku(),
				'price'       => $product->get_regular_price(),
				'category'    => $categories ? implode( ', ', $categories ) : '',
				'thumbnail'   => get_the_post_thumbnail_url( $post->ID, 'thumbnail' ) ?: '',
				'edit_url'    => admin_url( "post.php?post={$post->ID}&action=edit" ),
			);
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => (int) $query->found_posts,
				'page'  => $page,
			),
			200
		);
	}

	/**
	 * Publish hàng loạt — xử lý TUẦN TỰ, 1 sản phẩm lỗi không chặn các sản
	 * phẩm còn lại. Đây là thao tác publish thật của WooCommerce
	 * (`post_status = publish`), không có gì đặc biệt của plugin này.
	 */
	public function bulk_publish_drafts( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		$ids  = isset( $data['ids'] ) && is_array( $data['ids'] ) ? array_map( 'absint', $data['ids'] ) : array();

		if ( ! $ids ) {
			return new WP_Error( 'invalid_request', 'ids[] là bắt buộc.', array( 'status' => 400 ) );
		}

		$results = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'product' !== $post->post_type ) {
				$results[] = array( 'id' => $id, 'ok' => false, 'error' => 'Không tìm thấy sản phẩm.' );
				continue;
			}

			$updated = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true );
			if ( is_wp_error( $updated ) ) {
				$results[] = array( 'id' => $id, 'ok' => false, 'error' => $updated->get_error_message() );
				UPI_Logger::error( "Publish thất bại cho product #{$id}: " . $updated->get_error_message() );
				continue;
			}

			$results[] = array( 'id' => $id, 'ok' => true );
			UPI_Logger::info( "Đã publish product #{$id}" );
		}

		return new WP_REST_Response( array( 'results' => $results ), 200 );
	}

	public function list_logs( WP_REST_Request $request ) {
		$limit = $request->get_param( 'limit' ) ?: 100;
		return new WP_REST_Response( UPI_Logger::get_recent( (int) $limit ), 200 );
	}
}
