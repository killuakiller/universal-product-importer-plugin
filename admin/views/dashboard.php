<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$products_table = UPI_DB::products_table();

// KHÔNG còn "Product Workspace" — từ khi Local Staging của extension xử
// lý toàn bộ việc sửa title/tags/ảnh/chọn Template, gửi 1 sản phẩm =
// tạo THẲNG WooCommerce Draft trong cùng 1 request. Dashboard chỉ còn
// hiện số liệu tổng quan + link tắt sang WooCommerce Products (Draft).
$counts = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$products_table} GROUP BY status", OBJECT_K );
$get = fn( $status ) => isset( $counts[ $status ] ) ? (int) $counts[ $status ]->total : 0;

$draft_count_query = new WP_Query( array( 'post_type' => 'product', 'post_status' => 'draft', 'posts_per_page' => 1, 'fields' => 'ids' ) );
$woo_draft_count    = $draft_count_query->found_posts;
?>
<div class="wrap upi-wrap">
	<h1>Product Importer — Dashboard</h1>
	<p class="description">Gửi từ Local Staging (Chrome Extension) sẽ tạo thẳng WooCommerce Draft — không còn bước "Product Workspace" trung gian nữa. Xem/publish sản phẩm trực tiếp trong WooCommerce → Products.</p>

	<div class="upi-stat-grid">
		<div class="upi-stat upi-stat-draft">
			<span class="dashicons dashicons-media-document upi-stat-icon"></span>
			<div><span class="upi-stat-num"><?php echo esc_html( $woo_draft_count ); ?></span><span class="upi-stat-label">WooCommerce Draft (chờ publish)</span></div>
		</div>
		<div class="upi-stat upi-stat-published">
			<span class="dashicons dashicons-yes-alt upi-stat-icon"></span>
			<div><span class="upi-stat-num"><?php echo esc_html( $get( 'published' ) ); ?></span><span class="upi-stat-label">Đã tạo & Publish (qua plugin này)</span></div>
		</div>
		<div class="upi-stat upi-stat-failed">
			<span class="dashicons dashicons-warning upi-stat-icon"></span>
			<div><span class="upi-stat-num"><?php echo esc_html( $get( 'rejected' ) ); ?></span><span class="upi-stat-label">Lỗi tạo Draft (Rejected)</span></div>
		</div>
	</div>

	<p>
		<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&post_status=draft' ) ); ?>" class="button button-primary">Xem WooCommerce Draft</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-templates' ) ); ?>" class="button">Quản lý Templates</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-settings' ) ); ?>" class="button">Kết nối Chrome Extension</a>
	</p>
</div>
