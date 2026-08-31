<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$source_filter = isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : '';
$search_term   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$paged         = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$per_page      = 50;

$result = UPI_Imports::get_list( $source_filter, $search_term, $per_page, $paged );
$rows   = $result['rows'];
$total  = $result['total'];
$counts = UPI_Imports::counts_by_source();

$source_labels = array(
	'etsy'   => 'Etsy',
	'amazon' => 'Amazon',
	'ebay'   => 'eBay',
);
$datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>
<div class="wrap upi-wrap">
	<h1>Import History</h1>
	<p class="description">
		Toàn bộ sản phẩm đã crawl từ Local Staging của Chrome Extension (bảng <code>upi_imports</code>, dữ liệu gốc bất biến, dùng để chống trùng + trace nguồn gốc) — kể cả những lượt import không tạo được WooCommerce Draft (xem cột "Kết quả"). Dữ liệu ở đây chỉ để xem/trace, không sửa được tại đây.
	</p>

	<ul class="subsubsub">
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-import-history' ) ); ?>" class="<?php echo '' === $source_filter ? 'current' : ''; ?>">
				Tất cả <span class="count">(<?php echo esc_html( array_sum( $counts ) ); ?>)</span>
			</a> |
		</li>
		<?php
		$labels_count = count( $source_labels );
		$i            = 0;
		foreach ( $source_labels as $key => $label ) :
			$i++;
			?>
			<li>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-import-history&source=' . $key ) ); ?>" class="<?php echo $source_filter === $key ? 'current' : ''; ?>">
					<?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( $counts[ $key ] ); ?>)</span>
				</a><?php echo $i < $labels_count ? ' |' : ''; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="upi-filter-bar">
		<input type="hidden" name="page" value="upi-import-history" />
		<?php if ( $source_filter ) : ?><input type="hidden" name="source" value="<?php echo esc_attr( $source_filter ); ?>" /><?php endif; ?>
		<input type="search" name="s" value="<?php echo esc_attr( $search_term ); ?>" placeholder="Tìm theo tên sản phẩm…" class="regular-text" />
		<button type="submit" class="button">Tìm</button>
		<?php if ( $search_term ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 's', false ) ); ?>" class="button-link">Xoá tìm kiếm</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $rows ) ) : ?>
		<div class="upi-placeholder-box">
			<p><strong>Chưa có lượt import nào<?php echo ( $source_filter || $search_term ) ? ' khớp bộ lọc' : ''; ?>.</strong></p>
			<?php if ( ! $source_filter && ! $search_term ) : ?>
				<p>Crawl sản phẩm từ Local Staging của Chrome Extension để thấy chúng ở đây.</p>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:48px;">Ảnh</th>
					<th>Title</th>
					<th style="width:80px;">Nguồn</th>
					<th style="width:100px;">Giá</th>
					<th style="width:160px;">Import lúc</th>
					<th style="width:180px;">Kết quả</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) :
					$thumbnail_id = 0;
					$images       = json_decode( $row->images_json ?: '[]', true );
					if ( is_array( $images ) ) {
						foreach ( $images as $img ) {
							if ( ! empty( $img['attachment_id'] ) ) {
								$thumbnail_id = (int) $img['attachment_id'];
								break;
							}
						}
					}
					?>
					<tr>
						<td><?php echo $thumbnail_id ? wp_get_attachment_image( $thumbnail_id, array( 40, 40 ) ) : '—'; ?></td>
						<td>
							<?php if ( $row->source_url ) : ?>
								<a href="<?php echo esc_url( $row->source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row->title ?: '(không có title)' ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $row->title ?: '(không có title)' ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $source_labels[ $row->source ] ?? $row->source ); ?></td>
						<td><?php echo $row->price ? esc_html( $row->price . ( $row->currency ? ' ' . $row->currency : '' ) ) : '—'; ?></td>
						<td><?php echo esc_html( mysql2date( $datetime_format, $row->imported_at ) ); ?></td>
						<td>
							<?php if ( $row->wc_product_id ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( (int) $row->wc_product_id ) ); ?>" target="_blank">Draft #<?php echo esc_html( $row->wc_product_id ); ?></a>
							<?php elseif ( 'rejected' === $row->product_status ) : ?>
								<span class="upi-status-badge upi-status-failed">Lỗi tạo Draft</span>
							<?php else : ?>
								<span class="upi-muted">Chưa tạo Draft</span>
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
