<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPI_Admin {

	// Ngưỡng an toàn cho các thao tác hàng loạt xử lý NGAY trong 1 request
	// (Publish Selected / Xoá đã chọn / Đổi Template hàng loạt trên trang
	// Drafts) — vượt ngưỡng này thì TỰ ĐỘNG chuyển sang xử lý nền theo đợt
	// nhỏ qua Action Scheduler thay vì âm thầm treo trang khi hosting có
	// max_execution_time thấp. KHÔNG ảnh hưởng gì tới batch nhỏ/vừa (hành vi
	// y hệt trước đây — chạy ngay, đồng bộ).
	const BULK_PUBLISH_SYNC_LIMIT         = 30;
	const BULK_DELETE_SYNC_LIMIT          = 25;
	const BULK_CHANGE_TEMPLATE_SYNC_LIMIT = 30;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_failed_queue_notice' ) );
		add_action( 'admin_post_upi_generate_pairing_code', array( $this, 'handle_generate_pairing_code' ) );
		add_action( 'admin_post_upi_revoke_token', array( $this, 'handle_revoke_token' ) );
		add_action( 'admin_post_upi_save_template', array( $this, 'handle_save_template' ) );
		add_action( 'admin_post_upi_duplicate_template', array( $this, 'handle_duplicate_template' ) );
		add_action( 'admin_post_upi_delete_template', array( $this, 'handle_delete_template' ) );
		add_action( 'admin_post_upi_publish_draft', array( $this, 'handle_publish_draft' ) );
		add_action( 'admin_post_upi_bulk_publish_drafts', array( $this, 'handle_bulk_publish_drafts' ) );
		add_action( 'admin_post_upi_schedule_bulk_publish', array( $this, 'handle_schedule_bulk_publish' ) );
		add_action( 'admin_post_upi_cancel_scheduled_publish', array( $this, 'handle_cancel_scheduled_publish' ) );
		add_action( 'admin_post_upi_cancel_all_pending', array( $this, 'handle_cancel_all_pending' ) );
		add_action( 'admin_post_upi_reschedule_publish', array( $this, 'handle_reschedule_publish' ) );
		add_action( 'admin_post_upi_delete_draft', array( $this, 'handle_delete_draft' ) );
		add_action( 'admin_post_upi_bulk_delete_drafts', array( $this, 'handle_bulk_delete_drafts' ) );
		add_action( 'admin_post_upi_change_draft_template', array( $this, 'handle_change_draft_template' ) );
		add_action( 'admin_post_upi_bulk_change_template', array( $this, 'handle_bulk_change_template' ) );
	}

	public function register_menu() {
		$failed_count = UPI_Publish_Queue::counts()['failed'];
		$failed_badge = $failed_count > 0
			? ' <span class="awaiting-mod count-' . $failed_count . '"><span class="pending-count">' . $failed_count . '</span></span>'
			: '';

		add_menu_page(
			'Product Importer',
			'Product Importer' . $failed_badge,
			'manage_woocommerce',
			'upi-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-download',
			56
		);

		add_submenu_page( 'upi-dashboard', 'Dashboard', 'Dashboard', 'manage_woocommerce', 'upi-dashboard', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'upi-dashboard', 'Templates', 'Templates', 'manage_woocommerce', 'upi-templates', array( $this, 'render_templates' ) );
		add_submenu_page( 'upi-dashboard', 'Drafts', 'Drafts', 'manage_woocommerce', 'upi-drafts', array( $this, 'render_drafts' ) );
		add_submenu_page( 'upi-dashboard', 'Import History', 'Import History', 'manage_woocommerce', 'upi-import-history', array( $this, 'render_import_history' ) );
		add_submenu_page( 'upi-dashboard', 'Publish Queue', 'Publish Queue' . $failed_badge, 'manage_woocommerce', 'upi-publish-queue', array( $this, 'render_publish_queue' ) );
		add_submenu_page( 'upi-dashboard', 'Marketplace Connections', 'Marketplace ▸ Connections', 'manage_woocommerce', 'upi-marketplace-connections', array( $this, 'render_marketplace_connections' ) );
		add_submenu_page( 'upi-dashboard', 'Marketplace Listings', 'Marketplace ▸ Listings', 'manage_woocommerce', 'upi-marketplace-listings', array( $this, 'render_marketplace_listings' ) );
		add_submenu_page( 'upi-dashboard', 'Marketplace Settings', 'Marketplace ▸ Settings', 'manage_woocommerce', 'upi-marketplace-settings', array( $this, 'render_marketplace_settings' ) );
		add_submenu_page( 'upi-dashboard', 'Logs', 'Logs', 'manage_woocommerce', 'upi-logs', array( $this, 'render_logs' ) );
		add_submenu_page( 'upi-dashboard', 'Settings', 'Settings', 'manage_woocommerce', 'upi-settings', array( $this, 'render_settings' ) );
	}

	/**
	 * Cảnh báo (notice) khi Publish Queue có dòng "Lỗi" — hiện trên mọi
	 * trang của plugin (trừ chính trang Publish Queue đang lọc theo Lỗi, vì
	 * lúc đó người dùng đã đang nhìn thấy rồi) để không phải tự vào kiểm tra
	 * thủ công mới biết có sản phẩm publish thất bại.
	 */
	public function maybe_show_failed_queue_notice() {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'upi-' ) === false ) {
			return;
		}
		if ( isset( $_GET['page'] ) && 'upi-publish-queue' === $_GET['page'] && isset( $_GET['status'] ) && 'failed' === $_GET['status'] ) {
			return;
		}

		$failed_count = UPI_Publish_Queue::counts()['failed'];
		if ( $failed_count <= 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( sprintf( 'Có %d sản phẩm publish lỗi trong Publish Queue.', $failed_count ) ),
			esc_url( admin_url( 'admin.php?page=upi-publish-queue&status=failed' ) ),
			esc_html( 'Xem chi tiết' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'upi-' ) === false ) {
			return;
		}
		wp_enqueue_style( 'upi-admin', UPI_URL . 'assets/css/admin.css', array(), UPI_VERSION );

		if ( strpos( $hook, 'upi-templates' ) !== false ) {
			wp_enqueue_media(); // dùng wp.media cho Template Gallery manager
		}
	}

	public function render_dashboard() {
		require UPI_PATH . 'admin/views/dashboard.php';
	}
	public function render_templates() {
		require UPI_PATH . 'admin/views/templates.php';
	}
	public function render_drafts() {
		require UPI_PATH . 'admin/views/drafts.php';
	}
	public function render_import_history() {
		require UPI_PATH . 'admin/views/import-history.php';
	}
	public function render_publish_queue() {
		require UPI_PATH . 'admin/views/publish-queue.php';
	}
	public function render_marketplace_connections() {
		require UPI_PATH . 'admin/views/marketplace-placeholder.php';
	}
	public function render_marketplace_listings() {
		require UPI_PATH . 'admin/views/marketplace-placeholder.php';
	}
	public function render_marketplace_settings() {
		require UPI_PATH . 'admin/views/marketplace-placeholder.php';
	}
	public function render_logs() {
		require UPI_PATH . 'admin/views/logs.php';
	}
	public function render_settings() {
		require UPI_PATH . 'admin/views/settings.php';
	}

	public function handle_generate_pairing_code() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_generate_pairing_code' );

		$code = UPI_Auth::generate_pairing_code( get_current_user_id() );

		wp_safe_redirect( add_query_arg( array( 'page' => 'upi-settings', 'upi_pairing_code' => $code ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_revoke_token() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_revoke_token' );

		$token_id = isset( $_POST['token_id'] ) ? absint( $_POST['token_id'] ) : 0;
		if ( $token_id ) {
			UPI_Auth::revoke_token( $token_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=upi-settings' ) );
		exit;
	}

	public function handle_save_template() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_save_template' );

		$gallery_ids = isset( $_POST['gallery_image_ids'] )
			? array_filter( array_map( 'absint', explode( ',', wp_unslash( $_POST['gallery_image_ids'] ) ) ) )
			: array();

		// Checklist product_cat render bởi wp_terms_checklist() gửi lên đúng
		// theo tên field mặc định của WordPress core: tax_input[product_cat][].
		$category_ids = isset( $_POST['tax_input']['product_cat'] )
			? array_filter( array_map( 'absint', (array) $_POST['tax_input']['product_cat'] ) )
			: array();

		// Tags nhập dạng text, cách nhau bằng dấu phẩy (giống UI nhập tag
		// quen thuộc của WordPress) — KHÔNG phải checklist taxonomy như
		// category, vì tag của Template CỘNG DỒN với tag cấp sản phẩm nhập
		// tự do ở Local Staging của extension, không giới hạn trong danh
		// sách term đã có sẵn.
		$tags = isset( $_POST['tags'] )
			? array_filter( array_map( 'trim', explode( ',', wp_unslash( $_POST['tags'] ) ) ) )
			: array();

		$data = array(
			'name'              => wp_unslash( $_POST['name'] ?? '' ),
			'category_ids'      => $category_ids,
			'tags'              => $tags,
			'shipping_class_id' => $_POST['shipping_class_id'] ?? 0,
			'regular_price'     => $_POST['regular_price'] ?? '',
			'sale_price'        => $_POST['sale_price'] ?? '',
			'brand'             => wp_unslash( $_POST['brand'] ?? '' ),
			'description'       => wp_unslash( $_POST['description'] ?? '' ),
			'short_description' => wp_unslash( $_POST['short_description'] ?? '' ),
			'sku_prefix'        => wp_unslash( $_POST['sku_prefix'] ?? '' ),
			'gallery_image_ids' => $gallery_ids,
		);

		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		if ( $template_id ) {
			UPI_Templates::update( $template_id, $data );
		} else {
			UPI_Templates::create( $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=upi-templates' ) );
		exit;
	}

	/** Nhân bản 1 Template có sẵn làm điểm khởi đầu cho Template mới — copy toàn bộ field, đổi tên thêm "(Copy)". */
	public function handle_duplicate_template() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_duplicate_template' );

		$source_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$source    = $source_id ? UPI_Templates::find( $source_id ) : null;

		if ( $source ) {
			$new_id = UPI_Templates::create(
				array(
					'name'              => $source->name . ' (Copy)',
					'category_ids'      => UPI_Templates::category_ids( $source ),
					'tags'              => UPI_Templates::tags( $source ),
					'shipping_class_id' => $source->shipping_class_id,
					'regular_price'     => $source->regular_price,
					'sale_price'        => $source->sale_price,
					'brand'             => $source->brand,
					'description'       => $source->description,
					'short_description' => $source->short_description,
					'sku_prefix'        => $source->sku_prefix,
					'gallery_image_ids' => UPI_Templates::gallery_attachment_ids( $source ),
				)
			);
			wp_safe_redirect( add_query_arg( array( 'page' => 'upi-templates', 'edit' => $new_id ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=upi-templates' ) );
		exit;
	}

	/** Xoá vĩnh viễn 1 Template. Không xoá/ảnh hưởng Draft đã tạo từ template này trước đó. */
	public function handle_delete_template() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_delete_template' );

		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		if ( $template_id ) {
			UPI_Templates::delete( $template_id );
			UPI_Logger::info( "Đã xoá Template #{$template_id}" );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'upi-templates', 'template_deleted' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Publish 1 WooCommerce Draft — thao tác publish thật của WooCommerce, không có gì đặc biệt của plugin. */
	public function handle_publish_draft() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_publish_draft' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id && get_post( $post_id ) && 'product' === get_post_type( $post_id ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
			UPI_Logger::info( "Đã publish product #{$post_id}" );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=upi-drafts' ) );
		exit;
	}

	/**
	 * Publish hàng loạt — TUẦN TỰ, 1 sản phẩm lỗi không chặn các sản phẩm
	 * còn lại. Số lượng chọn vượt BULK_PUBLISH_SYNC_LIMIT sẽ TỰ ĐỘNG chuyển
	 * sang hàng đợi nền (giống "Hẹn giờ Publish", cách nhau tối thiểu) thay
	 * vì publish hết trong 1 request — tránh treo trang/chạm giới hạn thời
	 * gian chạy PHP của hosting khi chọn hàng trăm sản phẩm cùng lúc.
	 */
	public function handle_bulk_publish_drafts() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_bulk_publish_drafts' );

		$ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) $_POST['post_ids'] ) : array();

		if ( count( $ids ) > self::BULK_PUBLISH_SYNC_LIMIT ) {
			UPI_Publish_Queue::schedule_batch( $ids, UPI_Publish_Queue::MIN_INTERVAL, 0 );
			wp_safe_redirect( add_query_arg( array( 'page' => 'upi-publish-queue', 'auto_queued' => count( $ids ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$published = 0;
		foreach ( $ids as $id ) {
			if ( $id && get_post( $id ) && 'product' === get_post_type( $id ) ) {
				$result = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true );
				if ( ! is_wp_error( $result ) ) {
					$published++;
					UPI_Logger::info( "Đã publish product #{$id} (bulk)" );
				} else {
					UPI_Logger::error( "Publish thất bại cho product #{$id}: " . $result->get_error_message() );
				}
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'upi-drafts', 'published' => $published ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Hẹn giờ publish hàng loạt — RẢI ĐỀU theo thời gian (mỗi sản phẩm cách
	 * nhau interval_seconds), chạy nền qua UPI_Publish_Queue/Action Scheduler,
	 * không cần giữ tab hay trình duyệt mở. Dùng chung nonce action với
	 * "Publish Selected" (cùng 1 form ở trang Drafts, cùng ngữ cảnh trang).
	 */
	public function handle_schedule_bulk_publish() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_bulk_publish_drafts' );

		$ids = isset( $_POST['post_ids'] ) ? array_filter( array_map( 'absint', (array) $_POST['post_ids'] ) ) : array();

		if ( empty( $ids ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=upi-drafts' ) );
			exit;
		}

		$interval_seconds    = isset( $_POST['interval_seconds'] ) ? absint( $_POST['interval_seconds'] ) : 120;
		$start_delay_seconds = isset( $_POST['start_delay_seconds'] ) ? absint( $_POST['start_delay_seconds'] ) : 0;

		UPI_Publish_Queue::schedule_batch( $ids, $interval_seconds, $start_delay_seconds );

		wp_safe_redirect( add_query_arg( array( 'page' => 'upi-publish-queue', 'scheduled' => count( $ids ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Huỷ 1 dòng đang chờ trong Publish Queue (chưa tới giờ chạy). */
	public function handle_cancel_scheduled_publish() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_cancel_scheduled_publish' );

		$queue_id = isset( $_GET['queue_id'] ) ? absint( $_GET['queue_id'] ) : 0;
		if ( $queue_id ) {
			UPI_Publish_Queue::cancel( $queue_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=upi-publish-queue' ) );
		exit;
	}

	/** Huỷ MỌI dòng đang chờ trong Publish Queue cùng lúc (hẹn giờ nhầm cả loạt). */
	public function handle_cancel_all_pending() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_cancel_all_pending' );

		$cancelled = UPI_Publish_Queue::cancel_all_pending();

		wp_safe_redirect( add_query_arg( array( 'page' => 'upi-publish-queue', 'cancelled_all' => $cancelled ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** "Lên lịch lại" 1 dòng đã Lỗi/Đã huỷ trong Publish Queue — tạo lại 1 lượt hẹn giờ mới cho đúng post_id đó, không cần quay lại Drafts. */
	public function handle_reschedule_publish() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_reschedule_publish' );

		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( $post_id && get_post( $post_id ) && 'product' === get_post_type( $post_id ) ) {
			UPI_Publish_Queue::reschedule_one( $post_id );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'upi-publish-queue', 'rescheduled' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Xoá vĩnh viễn 1 Draft (+ ảnh liên quan, trừ ảnh Template Gallery dùng chung). */
	public function handle_delete_draft() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_delete_draft' );

		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( $post_id ) {
			UPI_Product_Creator::delete_draft( $post_id );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'upi-drafts', 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Xoá vĩnh viễn hàng loạt Draft đã chọn (+ ảnh liên quan mỗi sản phẩm).
	 * Số lượng chọn vượt BULK_DELETE_SYNC_LIMIT sẽ TỰ ĐỘNG chuyển sang xử
	 * lý nền theo từng đợt nhỏ (xem UPI_Product_Creator::schedule_bulk_delete)
	 * — xoá kèm dọn file ảnh trên đĩa khá tốn cho mỗi sản phẩm, số lượng lớn
	 * dễ chạm giới hạn thời gian chạy PHP nếu xử lý hết trong 1 request.
	 */
	public function handle_bulk_delete_drafts() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_bulk_delete_drafts', '_wpnonce_delete' );

		$ids = isset( $_POST['post_ids'] ) ? array_filter( array_map( 'absint', (array) $_POST['post_ids'] ) ) : array();

		if ( count( $ids ) > self::BULK_DELETE_SYNC_LIMIT ) {
			$scheduled = UPI_Product_Creator::schedule_bulk_delete( $ids );
			wp_safe_redirect( add_query_arg( array( 'page' => 'upi-drafts', 'delete_queued' => $scheduled ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			$result = UPI_Product_Creator::delete_draft( $id );
			if ( ! is_wp_error( $result ) ) {
				$deleted++;
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'upi-drafts', 'deleted' => $deleted ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Đổi Template hàng loạt cho các Draft đã chọn ở trang Drafts — cùng
	 * logic với "Đổi Template" từng dòng (UPI_Product_Creator::change_template),
	 * chỉ khác là áp cho nhiều sản phẩm cùng lúc. Số lượng chọn vượt
	 * BULK_CHANGE_TEMPLATE_SYNC_LIMIT sẽ tự động chuyển sang xử lý nền theo
	 * từng đợt nhỏ, cùng lý do với Publish/Xoá hàng loạt ở trên.
	 */
	public function handle_bulk_change_template() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_bulk_change_template', '_wpnonce_change_template' );

		$ids         = isset( $_POST['post_ids'] ) ? array_filter( array_map( 'absint', (array) $_POST['post_ids'] ) ) : array();
		$template_id = isset( $_POST['bulk_template_id'] ) && '' !== $_POST['bulk_template_id'] ? absint( $_POST['bulk_template_id'] ) : null;

		if ( count( $ids ) > self::BULK_CHANGE_TEMPLATE_SYNC_LIMIT ) {
			$scheduled = UPI_Product_Creator::schedule_bulk_change_template( $ids, $template_id );
			wp_safe_redirect( add_query_arg( array( 'page' => 'upi-drafts', 'template_change_queued' => $scheduled ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$changed = 0;
		$failed  = 0;
		foreach ( $ids as $id ) {
			$result = UPI_Product_Creator::change_template( $id, $template_id );
			if ( is_wp_error( $result ) ) {
				$failed++;
			} else {
				$changed++;
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'upi-drafts', 'bulk_template_changed' => $changed, 'bulk_template_failed' => $failed ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Đổi Template cho 1 Draft đã tạo — áp lại category/giá/SKU/shipping/mô tả/ảnh theo Template mới. */
	public function handle_change_draft_template() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Không đủ quyền.' );
		}
		check_admin_referer( 'upi_change_draft_template' );

		$post_id     = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$template_id = isset( $_GET['template_id'] ) && '' !== $_GET['template_id'] ? absint( $_GET['template_id'] ) : null;

		$notice = 'template_changed';
		if ( $post_id ) {
			$result = UPI_Product_Creator::change_template( $post_id, $template_id );
			if ( is_wp_error( $result ) ) {
				$notice = 'template_change_failed';
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'upi-drafts', $notice => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
