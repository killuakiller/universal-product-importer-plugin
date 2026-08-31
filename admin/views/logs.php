<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$level_filter = isset( $_GET['level'] ) ? sanitize_key( $_GET['level'] ) : '';
$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$per_page     = 50;

$result = UPI_Logger::get_list( $level_filter, $per_page, $paged );
$logs   = $result['rows'];
$total  = $result['total'];
$counts = UPI_Logger::counts_by_level();

$level_labels = array(
	'info'    => 'Info',
	'warning' => 'Warning',
	'error'   => 'Error',
);
?>
<div class="wrap upi-wrap">
	<h1>Logs</h1>

	<ul class="subsubsub">
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-logs' ) ); ?>" class="<?php echo '' === $level_filter ? 'current' : ''; ?>">
				Tất cả <span class="count">(<?php echo esc_html( array_sum( $counts ) ); ?>)</span>
			</a> |
		</li>
		<?php
		$labels_count = count( $level_labels );
		$i            = 0;
		foreach ( $level_labels as $key => $label ) :
			$i++;
			?>
			<li>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-logs&level=' . $key ) ); ?>" class="<?php echo $level_filter === $key ? 'current' : ''; ?>">
					<?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( $counts[ $key ] ); ?>)</span>
				</a><?php echo $i < $labels_count ? ' |' : ''; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( empty( $logs ) ) : ?>
		<div class="upi-placeholder-box">
			<p><strong>Chưa có log nào<?php echo $level_filter ? ' ở mức này' : ''; ?>.</strong></p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr><th style="width:160px;">Thời gian</th><th style="width:80px;">Mức</th><th style="width:100px;">Nguồn</th><th>Nội dung</th></tr></thead>
			<tbody>
			<?php foreach ( $logs as $log ) : ?>
				<tr>
					<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $log->created_at ) ); ?></td>
					<td><span class="upi-log-level upi-log-<?php echo esc_attr( $log->level ); ?>"><?php echo esc_html( $log->level ); ?></span></td>
					<td><?php echo esc_html( $log->source ); ?></td>
					<td><?php echo esc_html( $log->message ); ?></td>
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
