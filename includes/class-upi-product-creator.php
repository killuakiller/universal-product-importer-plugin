<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Biến canonical product (upi_products) + Template thành WooCommerce
 * Simple Product draft. KHÔNG bao giờ tạo Variable Product/variation —
 * WPCA hay bất kỳ plugin product-options nào khác nằm ngoài phạm vi, không
 * bị đụng tới.
 *
 * Thứ tự ưu tiên dữ liệu: Template Defaults → Imported Product Data →
 * User Overrides (field nằm trong overrides_json luôn thắng).
 */
class UPI_Product_Creator {

	const BULK_DELETE_HOOK          = 'upi_process_bulk_delete_chunk';
	const BULK_CHANGE_TEMPLATE_HOOK = 'upi_process_bulk_change_template_chunk';
	const BULK_CHUNK_SIZE           = 10; // Số sản phẩm xử lý mỗi đợt nền — nhỏ để mỗi đợt luôn xong nhanh, không phụ thuộc PHP execution time.

	/**
	 * Đăng ký hook Action Scheduler cho xử lý nền (xoá/đổi Template hàng
	 * loạt) — gọi ở bootstrap (không chỉ trong wp-admin), giống UPI_Jobs::init()
	 * và UPI_Publish_Queue::init(), để hook luôn sẵn sàng kể cả khi Action
	 * Scheduler tự chạy action qua request nền/WP-Cron riêng.
	 */
	public static function init() {
		add_action( self::BULK_DELETE_HOOK, array( __CLASS__, 'process_bulk_delete_chunk' ), 10, 1 );
		add_action( self::BULK_CHANGE_TEMPLATE_HOOK, array( __CLASS__, 'process_bulk_change_template_chunk' ), 10, 2 );
	}

	/**
	 * Xoá hàng loạt CHẠY NỀN theo từng đợt nhỏ (BULK_CHUNK_SIZE) qua Action
	 * Scheduler — dùng khi số lượng chọn xoá quá lớn để xử lý an toàn trong
	 * 1 request PHP (xoá post + tải file ảnh trên đĩa khá tốn cho mỗi sản
	 * phẩm). Trả về số sản phẩm đã lên lịch.
	 */
	public static function schedule_bulk_delete( array $post_ids ): int {
		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );
		$chunks   = array_chunk( $post_ids, self::BULK_CHUNK_SIZE );

		foreach ( $chunks as $i => $chunk ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $i * 2, self::BULK_DELETE_HOOK, array( $chunk ), 'upi' );
			} else {
				// Không có Action Scheduler: xử lý ngay, chấp nhận rủi ro timeout
				// với batch rất lớn (nhất quán với cách UPI_Jobs/UPI_Publish_Queue
				// xử lý khi thiếu Action Scheduler).
				self::process_bulk_delete_chunk( $chunk );
			}
		}

		UPI_Logger::info( 'Đã lên lịch xoá nền ' . count( $post_ids ) . ' Draft, chia thành ' . count( $chunks ) . ' đợt nhỏ.' );

		return count( $post_ids );
	}

	public static function process_bulk_delete_chunk( array $post_ids ) {
		foreach ( $post_ids as $id ) {
			$result = self::delete_draft( (int) $id );
			if ( is_wp_error( $result ) ) {
				UPI_Logger::error( "Xoá hàng loạt (nền) thất bại cho Draft #{$id}: " . $result->get_error_message() );
			}
		}
	}

	/**
	 * Đổi Template hàng loạt CHẠY NỀN theo từng đợt nhỏ — cùng lý do/kiến
	 * trúc với schedule_bulk_delete() ở trên.
	 */
	public static function schedule_bulk_change_template( array $post_ids, ?int $template_id ): int {
		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );
		$chunks   = array_chunk( $post_ids, self::BULK_CHUNK_SIZE );

		foreach ( $chunks as $i => $chunk ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $i * 2, self::BULK_CHANGE_TEMPLATE_HOOK, array( $chunk, $template_id ), 'upi' );
			} else {
				self::process_bulk_change_template_chunk( $chunk, $template_id );
			}
		}

		UPI_Logger::info( 'Đã lên lịch đổi Template nền cho ' . count( $post_ids ) . ' Draft, chia thành ' . count( $chunks ) . ' đợt nhỏ.' );

		return count( $post_ids );
	}

	public static function process_bulk_change_template_chunk( array $post_ids, ?int $template_id ) {
		foreach ( $post_ids as $id ) {
			$result = self::change_template( (int) $id, $template_id );
			if ( is_wp_error( $result ) ) {
				UPI_Logger::error( "Đổi Template hàng loạt (nền) thất bại cho Draft #{$id}: " . $result->get_error_message() );
			}
		}
	}

	/**
	 * @return int|WP_Error WooCommerce product ID.
	 */
	public static function create_draft( int $product_id ) {
		$product = UPI_Products::find( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Không tìm thấy sản phẩm.', array( 'status' => 404 ) );
		}
		if ( $product->wc_product_id ) {
			return new WP_Error( 'already_created', 'Sản phẩm này đã có WooCommerce draft.', array( 'status' => 409 ) );
		}

		$import     = UPI_Imports::find( (int) $product->import_id );
		$template   = $product->template_id ? UPI_Templates::find( (int) $product->template_id ) : null;
		$overrides  = json_decode( $product->overrides_json ?: '[]', true );
		$overrides  = is_array( $overrides ) ? $overrides : array();

		// VALIDATE trước khi tạo — nếu sản phẩm đã được gán template_id
		// nhưng Template đó không còn tồn tại (bị xoá sau khi gán), FAIL
		// rõ ràng thay vì âm thầm tạo sản phẩm thiếu category/giá/mô tả.
		if ( $product->template_id && ! $template ) {
			return new WP_Error( 'template_not_found', "Sản phẩm được gán template_id={$product->template_id} nhưng Template đó không còn tồn tại.", array( 'status' => 400 ) );
		}

		// Nếu Template có category_ids, verify từng category đó THẬT SỰ tồn
		// tại trong taxonomy product_cat trước khi dùng — không âm thầm bỏ
		// qua nếu category đã bị xoá khỏi WooCommerce sau khi cấu hình Template.
		if ( $template ) {
			foreach ( UPI_Templates::category_ids( $template ) as $tpl_category_id ) {
				if ( ! term_exists( $tpl_category_id, 'product_cat' ) ) {
					UPI_Logger::error(
						"Template #{$template->id} ({$template->name}) trỏ tới category_id={$tpl_category_id} nhưng category này không còn tồn tại trong WooCommerce.",
						null,
						$product_id
					);
				}
			}
		}

		global $wpdb;
		$wpdb->update(
			UPI_DB::products_table(),
			array( 'status' => 'draft', 'updated_at' => current_time( 'mysql' ) ), // trạng thái tạm trong lúc xử lý
			array( 'id' => $product_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		do_action( 'upi_before_create_draft', $product, $template, $import );

		$wc_product = new WC_Product_Simple();

		// TITLE — CHỈ 2 nguồn: edited_title (nếu user đã sửa) hoặc title gốc
		// (đã copy từ crawl/Staging lúc tạo canonical product). TUYỆT ĐỐI
		// KHÔNG fallback sang $template->name — Template chỉ là 1 cấu hình
		// nội bộ (category/giá/mô tả chung...), KHÔNG BAO GIỜ được phép cung
		// cấp title cho sản phẩm. Đây chính là nguyên nhân bug cũ tạo ra
		// draft mang tên Template (vd. "Test" hay tên category) khi title
		// thật bị rỗng vì lý do khác ở pipeline phía trước.
		$title = $product->edited_title ?: $product->title;
		if ( ! $title ) {
			// KHÔNG âm thầm tạo sản phẩm không tên/sai tên — fail rõ ràng để
			// user biết ngay đây là lỗi dữ liệu ở bước trước (Staging/Import),
			// không phải lỗi ở bước tạo Draft.
			$wpdb->update(
				UPI_DB::products_table(),
				array( 'status' => 'rejected', 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $product_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return new WP_Error( 'missing_title', 'Sản phẩm không có title (cả title gốc lẫn edited_title đều rỗng) — kiểm tra lại bước Import/Staging.', array( 'status' => 400 ) );
		}
		$wc_product->set_name( wp_strip_all_tags( $title ) );

		// DESCRIPTION — ghép: mô tả riêng của sản phẩm (user tự viết ở Local
		// Staging của extension TRƯỚC khi gửi — không phải crawl tự động từ
		// marketplace) + mô tả chung của Template, nối sau. Nếu sản phẩm
		// không có mô tả riêng, chỉ dùng mô tả Template.
		$product_intro     = $product->edited_description ?: '';
		$template_body     = $template->description ?? '';
		$description       = trim( $product_intro . ( $product_intro && $template_body ? "\n\n" : '' ) . $template_body );
		if ( $description ) {
			$wc_product->set_description( $description );
		}

		$short_description = $template->short_description ?? '';
		if ( $short_description ) {
			$wc_product->set_short_description( $short_description );
		}

		// GIÁ — LUÔN lấy từ template, không có override cấp sản phẩm nữa
		// (giá thuộc về Template, theo đúng yêu cầu — Workspace không còn ô
		// nhập giá cho từng sản phẩm). Dùng wc_format_decimal() — API chính
		// thức của WooCommerce để chuẩn hoá số thập phân — thay vì ép kiểu
		// string thô, tránh trường hợp input dạng "18,99" (dấu phẩy) bị PHP
		// hiểu sai thành "18".
		if ( $template && $template->regular_price ) {
			$wc_product->set_regular_price( wc_format_decimal( $template->regular_price ) );
		}
		if ( $template && $template->sale_price ) {
			$wc_product->set_sale_price( wc_format_decimal( $template->sale_price ) );
		}

		// CATEGORY — CỘNG DỒN 2 nguồn: category của Template (base, dùng
		// chung cho mọi sản phẩm dùng template đó) + category CHỌN THÊM trực
		// tiếp trong Local Staging của extension (extra_category_ids, riêng
		// cho từng sản phẩm — vd. Halloween, Women). KHÔNG có nguồn nào thay
		// thế nguồn nào, chỉ hợp nhất + khử trùng.
		$template_category_ids = $template ? UPI_Templates::category_ids( $template ) : array();
		$extra_category_ids    = UPI_Products::extra_category_ids( $product );
		foreach ( $extra_category_ids as $extra_category_id ) {
			if ( ! term_exists( $extra_category_id, 'product_cat' ) ) {
				UPI_Logger::error(
					"Category chọn thêm (extra_category_ids) id={$extra_category_id} không còn tồn tại trong WooCommerce.",
					null,
					$product_id
				);
			}
		}
		$category_ids = array_values( array_unique( array_merge( $template_category_ids, $extra_category_ids ) ) );
		if ( $category_ids ) {
			$wc_product->set_category_ids( $category_ids );
		}

		// SHIPPING CLASS — lấy từ Template (danh sách thật của WooCommerce,
		// chọn qua dropdown trong Template editor). Verify term còn tồn tại
		// trước khi gán, không âm thầm bỏ qua nếu đã bị xoá khỏi WooCommerce.
		if ( $template && $template->shipping_class_id ) {
			$shipping_term = get_term( (int) $template->shipping_class_id, 'product_shipping_class' );
			if ( $shipping_term && ! is_wp_error( $shipping_term ) ) {
				$wc_product->set_shipping_class_id( (int) $template->shipping_class_id );
			} else {
				UPI_Logger::error(
					"Template #{$template->id} ({$template->name}) trỏ tới shipping_class_id={$template->shipping_class_id} nhưng term này không còn tồn tại.",
					null,
					$product_id
				);
			}
		}

		// SKU = Template SKU Prefix + Source Product ID (vd. "TEE-4549279421",
		// "TEE-B0D5LK9RWK"). Đây là format hiển thị/truy vết tiện lợi, KHÔNG
		// phải trực tiếp copy source_product_id làm SKU trần trụi — vẫn luôn
		// đi kèm sku_prefix của Template để tránh trùng SKU giữa các
		// marketplace khác nhau.
		//
		// Admin có thể tự gõ prefix kèm sẵn dấu gạch (vd. "Tee-") — bỏ dấu
		// gạch/khoảng trắng cuối trước khi tự nối thêm 1 dấu gạch, tránh ra
		// "Tee--4549279421" (gạch kép).
		$sku = null;
		if ( $template && $template->sku_prefix && $import && $import->source_id ) {
			$prefix = rtrim( sanitize_text_field( $template->sku_prefix ), " \t\n\r\0\x0B-" );
			$sku    = $prefix . '-' . sanitize_text_field( $import->source_id );
		}
		if ( $sku ) {
			$wc_product->set_sku( $sku );
		}

		$wc_product->set_status( 'draft' ); // Không bao giờ tự động publish.

		$wc_product_id = $wc_product->save();

		if ( ! $wc_product_id ) {
			UPI_Logger::error( 'Tạo WooCommerce product thất bại', $import->source ?? null, $product_id );
			$wpdb->update(
				UPI_DB::products_table(),
				array( 'status' => 'rejected', 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $product_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return new WP_Error( 'product_creation_failed', 'Không thể tạo WooCommerce product.', array( 'status' => 500 ) );
		}

		// TAGS — LUÔN là dữ liệu cấp sản phẩm (Product Workspace), KHÔNG bao
		// giờ lấy từ Template — Template không còn field tags nữa.
		$tags = $product->tags_json ? json_decode( $product->tags_json, true ) : array();
		if ( is_array( $tags ) && $tags ) {
			wp_set_object_terms( $wc_product_id, $tags, 'product_tag' );
		}

		if ( $template && $template->brand ) {
			update_post_meta( $wc_product_id, '_upi_brand', sanitize_text_field( $template->brand ) );
		}

		// METADATA NGUỒN GỐC — luôn giữ lại để trace, dù dùng destination nào.
		// LƯU Ý: source_id (listing ID/ASIN) và source_sku (nếu marketplace
		// có công bố SKU riêng) chỉ lưu để trace — KHÔNG BAO GIỜ tự động gán
		// làm WooCommerce SKU của sản phẩm (SKU thật vẫn theo sku_prefix của
		// template + ID nội bộ, xem phía trên).
		if ( $import ) {
			update_post_meta( $wc_product_id, '_source_marketplace', $import->source );
			update_post_meta( $wc_product_id, '_source_product_id', $import->source_id );
			if ( ! empty( $import->source_sku ) ) {
				update_post_meta( $wc_product_id, '_source_sku', $import->source_sku );
			}
			update_post_meta( $wc_product_id, '_source_url', $import->source_url );
			update_post_meta( $wc_product_id, '_source_original_title', $import->title );
			update_post_meta( $wc_product_id, '_source_crawled_at', $import->crawled_at );
		}
		update_post_meta( $wc_product_id, '_source_imported_at', current_time( 'mysql' ) );

		// ẢNH — QUAN TRỌNG: ảnh đã được TẢI VỀ Media Library từ lúc IMPORT
		// (xem UPI_Imports::download_images), không phải bây giờ. Ở bước
		// tạo draft này, chỉ REUSE lại đúng attachment_id đã có sẵn —
		// KHÔNG BAO GIỜ gọi sideload() lại lần 2 cho cùng 1 ảnh.
		//
		// THỨ TỰ BẮT BUỘC: ảnh sản phẩm (imported) LUÔN đứng trước, ảnh
		// Template Gallery (size chart, color chart...) LUÔN nối vào SAU —
		// không bao giờ đảo ngược. Ảnh #1 của sản phẩm luôn là Featured Image.
		$images = json_decode( $product->images_json, true );
		$attachment_ids = array();
		$total_selected = 0;
		$missing_count  = 0;

		if ( is_array( $images ) && $images ) {
			$images = array_filter( $images, fn( $img ) => ! isset( $img['selected'] ) || $img['selected'] );
			usort( $images, fn( $a, $b ) => ( $a['position'] ?? 0 ) <=> ( $b['position'] ?? 0 ) );
			$total_selected = count( $images );

			foreach ( $images as $img ) {
				$attachment_id = ! empty( $img['attachment_id'] ) ? (int) $img['attachment_id'] : 0;
				if ( $attachment_id && get_post( $attachment_id ) ) {
					$attachment_ids[] = $attachment_id;
				} else {
					// Ảnh này chưa có attachment (tải lỗi lúc import, hoặc dữ
					// liệu cũ từ trước khi có cơ chế tải-lúc-import). KHÔNG tự
					// động tải lại ở đây — chỉ bỏ qua và ghi log, để user tự
					// thêm ảnh thay thế trong Workspace nếu cần.
					$missing_count++;
					UPI_Logger::warning( 'Bỏ qua 1 ảnh chưa có attachment (tải lỗi lúc import trước đó)', $import->source ?? null, $product_id, array( 'entry' => $img ) );
				}
			}
		}

		// Template Gallery — ảnh chung của template (đã là attachment ID sẵn
		// có trong Media Library từ lúc admin cấu hình template, không cần
		// tải lại) — LUÔN nối vào SAU ảnh sản phẩm, giữ đúng thứ tự đã sắp.
		$gallery_ids_before_template = count( $attachment_ids );
		if ( $template ) {
			foreach ( UPI_Templates::gallery_attachment_ids( $template ) as $tpl_attachment_id ) {
				if ( $tpl_attachment_id && get_post( $tpl_attachment_id ) ) {
					$attachment_ids[] = $tpl_attachment_id;
				}
			}
		}


		if ( $attachment_ids ) {
			$wc_product->set_image_id( $attachment_ids[0] );
			if ( count( $attachment_ids ) > 1 ) {
				$wc_product->set_gallery_image_ids( array_slice( $attachment_ids, 1 ) );
			}
			$wc_product->save();
		}

		if ( $total_selected > 0 ) {
			$attached = $gallery_ids_before_template;
			UPI_Logger::info(
				"Ảnh sản phẩm: {$attached}/{$total_selected} có sẵn attachment" . ( $missing_count ? ", {$missing_count} ảnh thiếu attachment (bỏ qua)" : '' ),
				$import->source ?? null,
				$product_id
			);
		}

		$wpdb->update(
			UPI_DB::products_table(),
			array(
				'status'        => 'draft',
				'wc_product_id' => $wc_product_id,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $product_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		UPI_Logger::info( "Đã tạo WooCommerce draft #{$wc_product_id} từ sản phẩm #{$product_id}", $import->source ?? null, $product_id );

		self::verify_draft( $wc_product_id, $product_id, $title, $category_ids, $tags, $template, $sku, $import->source ?? null );

		do_action( 'upi_after_create_draft', $wc_product_id, $product, $template, $import );

		return $wc_product_id;
	}

	/**
	 * Đọc lại chính WooCommerce product vừa lưu và so sánh với giá trị
	 * KỲ VỌNG (title/category/tags/giá/SKU) — không âm thầm tin rằng
	 * save() thành công là mọi field đã đúng. Lệch chỗ nào log rõ chỗ đó
	 * kèm giá trị mong đợi vs thực tế, để debug được thay vì đoán mò.
	 */
	private static function verify_draft( int $wc_product_id, int $product_id, string $expected_title, array $expected_category_ids, array $expected_tags, ?object $template, ?string $expected_sku, ?string $source ) {
		$wc_product = wc_get_product( $wc_product_id );
		if ( ! $wc_product ) {
			UPI_Logger::error( "Verify thất bại: không đọc lại được WooCommerce product #{$wc_product_id} vừa tạo.", $source, $product_id );
			return;
		}

		$mismatches = array();

		if ( $wc_product->get_name() !== $expected_title ) {
			$mismatches[] = "title: mong đợi \"{$expected_title}\", thực tế \"" . $wc_product->get_name() . '"';
		}

		if ( $expected_category_ids ) {
			$actual_categories  = $wc_product->get_category_ids();
			$missing_categories = array_diff( $expected_category_ids, $actual_categories );
			if ( $missing_categories ) {
				$mismatches[] = 'category: mong đợi [' . implode( ',', $expected_category_ids ) . '], thiếu [' . implode( ',', $missing_categories ) . '] — thực tế [' . implode( ',', $actual_categories ) . ']';
			}
		}

		if ( $expected_tags ) {
			$actual_tag_names = wp_list_pluck( wp_get_object_terms( $wc_product_id, 'product_tag' ), 'name' );
			$missing_tags     = array_diff( $expected_tags, $actual_tag_names );
			if ( $missing_tags ) {
				$mismatches[] = 'tags: thiếu [' . implode( ',', $missing_tags ) . '] — thực tế có [' . implode( ',', $actual_tag_names ) . ']';
			}
		}

		if ( $template && $template->regular_price ) {
			$expected_price = wc_format_decimal( $template->regular_price );
			$actual_price   = wc_format_decimal( $wc_product->get_regular_price() );
			if ( $expected_price !== $actual_price ) {
				$mismatches[] = "regular_price: mong đợi {$expected_price}, thực tế \"{$actual_price}\"";
			}
		}

		if ( $expected_sku && $wc_product->get_sku() !== $expected_sku ) {
			$mismatches[] = "sku: mong đợi \"{$expected_sku}\", thực tế \"" . $wc_product->get_sku() . '"';
		}

		if ( $template && $template->shipping_class_id ) {
			$actual_shipping_class_id = $wc_product->get_shipping_class_id();
			if ( (int) $template->shipping_class_id !== (int) $actual_shipping_class_id ) {
				$mismatches[] = "shipping_class_id: mong đợi {$template->shipping_class_id}, thực tế {$actual_shipping_class_id}";
			}
		}

		if ( $mismatches ) {
			UPI_Logger::error(
				"Verify sau khi tạo Draft #{$wc_product_id} phát hiện lệch dữ liệu: " . implode( ' | ', $mismatches ),
				$source,
				$product_id
			);
		}
	}

	/**
	 * Tạo draft cho nhiều sản phẩm — dùng cho nút "Create WooCommerce
	 * Drafts" trong Bulk Workspace. Xử lý tuần tự trong 1 request cho batch
	 * nhỏ; batch lớn nên được đẩy qua Action Scheduler (xem
	 * UPI_Jobs::queue_bulk_create_drafts) để tránh timeout PHP.
	 *
	 * @return array{created:int[], failed:array<int,string>}
	 */
	public static function create_drafts_bulk( array $product_ids ): array {
		$created = array();
		$failed  = array();

		foreach ( $product_ids as $id ) {
			$result = self::create_draft( (int) $id );
			if ( is_wp_error( $result ) ) {
				$failed[ (int) $id ] = $result->get_error_message();
			} else {
				$created[] = $result;
			}
		}

		return array( 'created' => $created, 'failed' => $failed );
	}

	/**
	 * Đổi Template cho 1 Draft ĐÃ TẠO — áp lại category/giá/SKU/shipping
	 * class/mô tả theo Template mới, ĐÚNG LOGIC như lúc tạo draft lần đầu
	 * (create_draft() ở trên), không thêm nhánh ưu tiên nào khác. Ảnh sản
	 * phẩm gốc (crawl/custom upload, lưu trong canonical images_json) được
	 * giữ nguyên — chỉ phần ảnh Template Gallery nối phía sau bị thay bằng
	 * Template mới.
	 *
	 * Yêu cầu Draft phải có canonical product tương ứng (mọi Draft do plugin
	 * này tạo đều có) — không suy đoán dữ liệu nếu thiếu.
	 *
	 * @param int      $wc_product_id WooCommerce product ID của Draft.
	 * @param int|null $new_template_id Template mới, hoặc null để bỏ Template (về "không áp dụng field nào").
	 * @return true|WP_Error
	 */
	public static function change_template( int $wc_product_id, ?int $new_template_id ) {
		$wc_product = wc_get_product( $wc_product_id );
		if ( ! $wc_product ) {
			return new WP_Error( 'not_found', 'Không tìm thấy sản phẩm.', array( 'status' => 404 ) );
		}

		$canonical = UPI_Products::find_by_wc_product_id( $wc_product_id );
		if ( ! $canonical ) {
			return new WP_Error( 'canonical_not_found', 'Không tìm thấy dữ liệu sản phẩm gốc của Draft này — không thể đổi Template an toàn.', array( 'status' => 400 ) );
		}

		$template = $new_template_id ? UPI_Templates::find( $new_template_id ) : null;
		if ( $new_template_id && ! $template ) {
			return new WP_Error( 'template_not_found', 'Template không tồn tại.', array( 'status' => 404 ) );
		}

		$source_id = get_post_meta( $wc_product_id, '_source_product_id', true );

		// GIÁ / SHIPPING CLASS / SKU — luôn từ Template, hệt logic create_draft().
		// Không có template → xoá các field này (đồng bộ với nguyên tắc "field
		// rỗng nghĩa là không áp dụng"). CATEGORY xem khối riêng ngay dưới đây.
		$wc_product->set_regular_price( $template && $template->regular_price ? wc_format_decimal( $template->regular_price ) : '' );
		$wc_product->set_sale_price( $template && $template->sale_price ? wc_format_decimal( $template->sale_price ) : '' );
		// CATEGORY — cùng nguyên tắc cộng dồn như create_draft(): category của
		// Template mới + extra_category_ids đã chọn thêm ở canonical (KHÔNG
		// đổi khi đổi Template, gắn với sản phẩm chứ không gắn với Template).
		$template_category_ids = $template ? UPI_Templates::category_ids( $template ) : array();
		$extra_category_ids    = UPI_Products::extra_category_ids( $canonical );
		$wc_product->set_category_ids( array_values( array_unique( array_merge( $template_category_ids, $extra_category_ids ) ) ) );

		if ( $template && $template->shipping_class_id ) {
			$shipping_term = get_term( (int) $template->shipping_class_id, 'product_shipping_class' );
			$wc_product->set_shipping_class_id( ( $shipping_term && ! is_wp_error( $shipping_term ) ) ? (int) $template->shipping_class_id : 0 );
		} else {
			$wc_product->set_shipping_class_id( 0 );
		}

		$sku = '';
		if ( $template && $template->sku_prefix && $source_id ) {
			$prefix = rtrim( sanitize_text_field( $template->sku_prefix ), " \t\n\r\0\x0B-" );
			$sku    = $prefix . '-' . sanitize_text_field( $source_id );
		}
		$wc_product->set_sku( $sku );

		// MÔ TẢ — mô tả riêng (đã lưu ở canonical, user viết ở Local Staging)
		// + mô tả chung của Template mới, nối sau. Đúng thứ tự như create_draft().
		$product_intro = $canonical->edited_description ?: '';
		$template_body = $template->description ?? '';
		$description   = trim( $product_intro . ( $product_intro && $template_body ? "\n\n" : '' ) . $template_body );
		$wc_product->set_description( $description );
		$wc_product->set_short_description( $template->short_description ?? '' );

		// ẢNH — ảnh sản phẩm gốc (canonical, selected + đúng thứ tự) LUÔN
		// đứng trước, ảnh Template Gallery MỚI nối sau — không đảo ngược,
		// giống hệt create_draft().
		$attachment_ids = array();
		$images         = json_decode( $canonical->images_json ?: '[]', true );
		if ( is_array( $images ) && $images ) {
			$images = array_filter( $images, fn( $img ) => ! isset( $img['selected'] ) || $img['selected'] );
			usort( $images, fn( $a, $b ) => ( $a['position'] ?? 0 ) <=> ( $b['position'] ?? 0 ) );
			foreach ( $images as $img ) {
				$attachment_id = ! empty( $img['attachment_id'] ) ? (int) $img['attachment_id'] : 0;
				if ( $attachment_id && get_post( $attachment_id ) ) {
					$attachment_ids[] = $attachment_id;
				}
			}
		}
		if ( $template ) {
			foreach ( UPI_Templates::gallery_attachment_ids( $template ) as $tpl_attachment_id ) {
				if ( $tpl_attachment_id && get_post( $tpl_attachment_id ) ) {
					$attachment_ids[] = $tpl_attachment_id;
				}
			}
		}

		if ( $attachment_ids ) {
			$wc_product->set_image_id( $attachment_ids[0] );
			$wc_product->set_gallery_image_ids( array_slice( $attachment_ids, 1 ) );
		} else {
			$wc_product->set_image_id( '' );
			$wc_product->set_gallery_image_ids( array() );
		}

		$wc_product->save();

		global $wpdb;
		$wpdb->update(
			UPI_DB::products_table(),
			array(
				'template_id' => $new_template_id ?: null,
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $canonical->id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		UPI_Logger::info( "Đã đổi Template cho Draft #{$wc_product_id} sang template_id=" . ( $new_template_id ?: '(none)' ), null, (int) $canonical->id );

		return true;
	}

	/**
	 * Xoá VĨNH VIỄN 1 Draft — xoá WooCommerce product + xoá luôn ảnh đã tải
	 * (Featured + Gallery) khỏi Media Library, TRỪ ảnh thuộc Template
	 * Gallery (tài sản dùng chung, có thể đang được sản phẩm khác tham
	 * chiếu — không bao giờ xoá). Dọn luôn canonical product (upi_products)
	 * và mọi dòng "pending" trong Publish Queue của sản phẩm này, để không
	 * để lại rác ở Media Library lẫn hàng đợi publish, và để nếu sau này
	 * import lại đúng sản phẩm nguồn, hệ thống tạo Draft mới sạch thay vì
	 * âm thầm coi là trùng lặp rồi bỏ qua.
	 */
	public static function delete_draft( int $wc_product_id ) {
		if ( ! get_post( $wc_product_id ) || 'product' !== get_post_type( $wc_product_id ) ) {
			return new WP_Error( 'not_found', 'Không tìm thấy sản phẩm.', array( 'status' => 404 ) );
		}

		$canonical = UPI_Products::find_by_wc_product_id( $wc_product_id );

		// Ảnh Template Gallery đang gán cho sản phẩm này — KHÔNG được xoá dù
		// nó có nằm trong Featured/Gallery của product, vì đây là tài sản
		// dùng chung của Template (nhiều sản phẩm khác có thể đang dùng lại).
		$keep_ids = array();
		if ( $canonical && $canonical->template_id ) {
			$template = UPI_Templates::find( (int) $canonical->template_id );
			if ( $template ) {
				$keep_ids = UPI_Templates::gallery_attachment_ids( $template );
			}
		}

		$wc_product = wc_get_product( $wc_product_id );
		$image_ids  = array();
		if ( $wc_product ) {
			$thumb_id = $wc_product->get_image_id();
			if ( $thumb_id ) {
				$image_ids[] = (int) $thumb_id;
			}
			foreach ( $wc_product->get_gallery_image_ids() as $gallery_id ) {
				$image_ids[] = (int) $gallery_id;
			}
		}
		$image_ids = array_diff( array_unique( $image_ids ), $keep_ids );

		// Xoá post trước (vĩnh viễn, bỏ qua Trash — đúng yêu cầu "tránh rác
		// site"), rồi mới xoá attachment.
		wp_delete_post( $wc_product_id, true );

		foreach ( $image_ids as $attachment_id ) {
			if ( get_post( $attachment_id ) && 'attachment' === get_post_type( $attachment_id ) ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}

		UPI_Publish_Queue::cancel_all_pending_for_post( $wc_product_id );

		if ( $canonical ) {
			UPI_Products::delete( (int) $canonical->id );
		}

		UPI_Logger::info( "Đã xoá Draft #{$wc_product_id} và " . count( $image_ids ) . ' ảnh liên quan (giữ lại ảnh Template Gallery nếu có).' );

		return true;
	}
}
