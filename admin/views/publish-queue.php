<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$paged         = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$per_page      = 50;

$result = UPI_Publish_Queue::get_list( $status_filter, $per_page, $paged );
$rows   = $result['rows'];
$total  = $result['total'];
$counts = UPI_Publish_Queue::counts();

$scheduled_notice   = isset( $_GET['scheduled'] ) ? absint( $_GET['scheduled'] ) : null;
$rescheduled_notice = isset( $_GET['rescheduled'] );
$auto_queued_notice = isset( $_GET['auto_queued'] ) ? absint( $_GET['auto_queued'] ) : null;

$status_labels = array(
	'pending'   => 'Đang chờ',
	'published' => 'Đã publish',
	'failed'    => 'Lỗi',
	'cancelled' => 'Đã huỷ',
);
$datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

// Draft (WooCommerce, do plugin tạo) hiện KHÔNG có dòng "pending" nào trong
// hàng đợi — tức chưa từng được "Hẹn giờ Publish…" hay "Publish Selected"
// lần nào. Chỉ để gợi ý người dùng quay lại Drafts xử lý tiếp, không phải
// dữ liệu bắt buộc của trang này.
global $wpdb;
$queue_table       = UPI_DB::publish_queue_table();
$unscheduled_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_source_marketplace'
	 WHERE p.post_type = 'product' AND p.post_status = 'draft'
	 AND p.ID NOT IN ( SELECT post_id FROM {$queue_table} WHERE status = 'pending' )"
);

$reschedule_url_base = wp_nonce_url( admin_url( 'admin-post.php?action=upi_reschedule_publish' ), 'upi_reschedule_publish' );
$cancel_all_url      = wp_nonce_url( admin_url( 'admin-post.php?action=upi_cancel_all_pending' ), 'upi_cancel_all_pending' );
$cancelled_all_notice = isset( $_GET['cancelled_all'] ) ? absint( $_GET['cancelled_all'] ) : null;
?>
<div class="wrap upi-wrap">
	<h1>Publish Queue</h1>
	<p class="description">
		Tiến độ publish hàng loạt đã hẹn giờ từ trang Drafts — chạy nền rải
		đều theo thời gian, KHÔNG cần giữ tab hay trình duyệt mở. Xem trang
		này bất cứ lúc nào (kể cả sau khi tắt máy rồi mở lại) để biết tiến
		độ đã chạy tới đâu.
	</p>

	<?php if ( null !== $scheduled_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p>Đã hẹn giờ publish <?php echo esc_html( $scheduled_notice ); ?> sản phẩm — xem tiến độ ở bảng dưới.</p></div>
	<?php endif; ?>
	<?php if ( $rescheduled_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p>Đã lên lịch lại — xem dòng "Đang chờ" mới nhất bên dưới.</p></div>
	<?php endif; ?>
	<?php if ( null !== $auto_queued_notice ) : ?>
		<div class="notice notice-info is-dismissible"><p>Số lượng chọn ở trang Drafts khá lớn (<?php echo esc_html( $auto_queued_notice ); ?> sản phẩm) — đã tự động chuyển sang hàng đợi nền để tránh treo trang, rải đều cách nhau tối thiểu 5 giây/sản phẩm. Xem tiến độ ở bảng dưới.</p></div>
	<?php endif; ?>
	<?php if ( null !== $cancelled_all_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p>Đã huỷ <?php echo esc_html( $cancelled_all_notice ); ?> dòng đang chờ.</p></div>
	<?php endif; ?>

	<div class="upi-stat-grid">
		<div class="upi-stat upi-stat-pending">
			<span class="dashicons dashicons-clock upi-stat-icon"></span>
			<div><span class="upi-stat-num"><?php echo esc_html( $counts['pending'] ); ?></span><span class="upi-stat-label">Đang chờ</span></div>
		</div>
		<div class="upi-stat upi-stat-published">
			<span class="dashicons dashicons-yes-alt upi-stat-icon"></span>
			<div><span class="upi-stat-num"><?php echo esc_html( $counts['published'] ); ?></span><span class="upi-stat-label">Đã publish</span></div>
		</div>
		<div class="upi-stat upi-stat-failed">
			<span class="dashicons dashicons-warning upi-stat-icon"></span>
			<div><span class="upi-stat-num"><?php echo esc_html( $counts['failed'] ); ?></span><span class="upi-stat-label">Lỗi</span></div>
		</div>
		<div class="upi-stat upi-stat-cancelled">
			<span class="dashicons dashicons-dismiss upi-stat-icon"></span>
			<div><span class="upi-stat-num"><?php echo esc_html( $counts['cancelled'] ); ?></span><span class="upi-stat-label">Đã huỷ</span></div>
		</div>
	</div>

	<?php if ( $counts['pending'] > 0 ) : ?>
		<p>
			<a href="<?php echo esc_url( $cancel_all_url ); ?>" class="button" onclick="return confirm('Huỷ TẤT CẢ <?php echo esc_js( $counts['pending'] ); ?> dòng đang chờ trong hàng đợi? Không thể hoàn tác.');">
				Huỷ tất cả (<?php echo esc_html( $counts['pending'] ); ?> đang chờ)
			</a>
		</p>
	<?php endif; ?>

	<?php if ( $unscheduled_count > 0 ) : ?>
		<div class="notice notice-info">
			<p>
				Còn <strong><?php echo esc_html( $unscheduled_count ); ?></strong> Draft chưa được lên lịch publish (không có dòng "Đang chờ" nào trong hàng đợi).
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-drafts' ) ); ?>">Sang trang Drafts để chọn &amp; Hẹn giờ Publish…</a>
			</p>
		</div>
	<?php endif; ?>

	<ul class="subsubsub">
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-publish-queue' ) ); ?>" class="<?php echo '' === $status_filter ? 'current' : ''; ?>">
				Tất cả <span class="count">(<?php echo esc_html( array_sum( $counts ) ); ?>)</span>
			</a> |
		</li>
		<?php
		$labels_count = count( $status_labels );
		$i            = 0;
		foreach ( $status_labels as $key => $label ) :
			$i++;
			?>
			<li>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-publish-queue&status=' . $key ) ); ?>" class="<?php echo $status_filter === $key ? 'current' : ''; ?>">
					<?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( $counts[ $key ] ); ?>)</span>
				</a><?php echo $i < $labels_count ? ' |' : ''; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( empty( $rows ) ) : ?>
		<div class="upi-placeholder-box">
			<p><strong>Chưa có mục nào<?php echo $status_filter ? ' ở trạng thái này' : ''; ?>.</strong></p>
			<?php if ( ! $status_filter ) : ?>
				<p>Hẹn giờ publish từ trang <a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-drafts' ) ); ?>">Drafts</a> để thấy chúng ở đây.</p>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:48px;">Ảnh</th>
					<th>Title</th>
					<th style="width:110px;">Trạng thái</th>
					<th style="width:180px;">Dự kiến / Đã publish lúc</th>
					<th>Ghi chú</th>
					<th style="width:110px;">Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) :
					$post_id    = (int) $row->post_id;
					$product    = $post_id ? wc_get_product( $post_id ) : null;
					$cancel_url = wp_nonce_url(
						add_query_arg(
							array( 'action' => 'upi_cancel_scheduled_publish', 'queue_id' => $row->id ),
							admin_url( 'admin-post.php' )
						),
						'upi_cancel_scheduled_publish'
					);
					$reschedule_url = add_query_arg( 'post_id', $post_id, $reschedule_url_base );
					?>
					<tr>
						<td><?php echo $post_id ? get_the_post_thumbnail( $post_id, array( 40, 40 ) ) : '—'; ?></td>
						<td>
							<?php if ( $product ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" target="_blank"><?php echo esc_html( $product->get_name() ); ?></a>
							<?php else : ?>
								<em>Sản phẩm #<?php echo esc_html( $post_id ); ?> (không còn tồn tại)</em>
							<?php endif; ?>
						</td>
						<td><span class="upi-status-badge upi-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $status_labels[ $row->status ] ?? $row->status ); ?></span></td>
						<td>
							<?php
							if ( 'published' === $row->status && $row->published_at ) {
								echo esc_html( mysql2date( $datetime_format, $row->published_at ) );
							} else {
								echo esc_html( mysql2date( $datetime_format, $row->scheduled_at ) );
							}
							?>
						</td>
						<td><?php echo $row->error_message ? esc_html( $row->error_message ) : '—'; ?></td>
						<td>
							<?php if ( 'pending' === $row->status ) : ?>
								<a href="<?php echo esc_url( $cancel_url ); ?>" class="button button-small" onclick="return confirm('Huỷ lịch publish sản phẩm này?');">Huỷ</a>
							<?php elseif ( in_array( $row->status, array( 'failed', 'cancelled' ), true ) && $product ) : ?>
								<a href="<?php echo esc_url( $reschedule_url ); ?>" class="button button-small" onclick="return confirm('Lên lịch lại publish sản phẩm này ngay?');">Lên lịch lại</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages > 1 ) :
			?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $total_pages,
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
