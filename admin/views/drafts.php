<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$published_count         = isset( $_GET['published'] ) ? absint( $_GET['published'] ) : null;
$deleted_count            = isset( $_GET['deleted'] ) ? absint( $_GET['deleted'] ) : null;
$template_changed         = isset( $_GET['template_changed'] );
$template_change_failed   = isset( $_GET['template_change_failed'] );
$delete_queued_count      = isset( $_GET['delete_queued'] ) ? absint( $_GET['delete_queued'] ) : null;
$template_change_queued   = isset( $_GET['template_change_queued'] ) ? absint( $_GET['template_change_queued'] ) : null;
$bulk_template_changed    = isset( $_GET['bulk_template_changed'] ) ? absint( $_GET['bulk_template_changed'] ) : null;
$bulk_template_failed     = isset( $_GET['bulk_template_failed'] ) ? absint( $_GET['bulk_template_failed'] ) : 0;

// Bộ lọc + phân trang — trước đây trang này lấy cứng 100 Draft, KHÔNG có
// trang tiếp theo, nên Draft thứ 101 trở đi âm thầm không hiện được dù vẫn
// còn trong DB. Giờ có 'paged' + search/filter, danh sách dài vẫn xem đủ.
$search_term         = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$filter_cat_id       = isset( $_GET['product_cat'] ) ? absint( $_GET['product_cat'] ) : 0;
$filter_template_raw = isset( $_GET['template_id'] ) ? sanitize_text_field( wp_unslash( $_GET['template_id'] ) ) : '';
$paged               = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$per_page            = 50;

$templates = UPI_Templates::all();

$query_args = array(
	'post_type'      => 'product',
	'post_status'    => 'draft',
	'posts_per_page' => $per_page,
	'paged'          => $paged,
	'meta_query'     => array(
		array( 'key' => '_source_marketplace', 'compare' => 'EXISTS' ),
	),
	'orderby'        => 'date',
	'order'          => 'DESC',
);

if ( $search_term ) {
	$query_args['s'] = $search_term;
}
if ( $filter_cat_id ) {
	$query_args['tax_query'] = array(
		array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $filter_cat_id ),
	);
}
if ( '' !== $filter_template_raw ) {
	global $wpdb;
	$products_table = UPI_DB::products_table();
	if ( 'none' === $filter_template_raw ) {
		$matching_wc_ids = $wpdb->get_col( "SELECT wc_product_id FROM {$products_table} WHERE wc_product_id IS NOT NULL AND template_id IS NULL" );
	} else {
		$matching_wc_ids = $wpdb->get_col( $wpdb->prepare( "SELECT wc_product_id FROM {$products_table} WHERE wc_product_id IS NOT NULL AND template_id = %d", absint( $filter_template_raw ) ) );
	}
	// array(0) thay vì mảng rỗng — post__in rỗng bị WP_Query BỎ QUA (coi như
	// không lọc), trong khi array(0) chắc chắn trả về 0 kết quả (không có
	// post ID nào = 0), đúng ý "lọc không khớp gì" thay vì âm thầm bỏ lọc.
	$query_args['post__in'] = $matching_wc_ids ?: array( 0 );
}

$query = new WP_Query( $query_args );

$post_ids       = wp_list_pluck( $query->posts, 'ID' );
$canonical_by_wc = UPI_Products::find_all_by_wc_product_ids( $post_ids );

$has_active_filter = '' !== $search_term || $filter_cat_id || '' !== $filter_template_raw;

// html_entity_decode(): wp_nonce_url() tự HTML-escape kết quả ("&" →
// "&amp;") vì mặc định dùng cho thuộc tính href — nhưng $change_template_url
// được nhúng thẳng vào JS (window.location.href) ở dưới, KHÔNG đi qua HTML
// attribute, nên phải giải mã lại "&amp;" về "&" thật, nếu không query
// string sẽ sai (WordPress đọc "amp;_wpnonce" thay vì "_wpnonce").
$change_template_url = html_entity_decode( wp_nonce_url( admin_url( 'admin-post.php?action=upi_change_draft_template' ), 'upi_change_draft_template' ) );
$delete_url_base      = wp_nonce_url( admin_url( 'admin-post.php?action=upi_delete_draft' ), 'upi_delete_draft' );
?>
<div class="wrap upi-wrap">
	<h1>Drafts</h1>
	<p class="description">
		WooCommerce Draft do Universal Product Importer tạo (từ Local Staging của Chrome Extension) — xem, sửa (mở editor gốc của WooCommerce), đổi Template, publish hoặc xoá trực tiếp tại đây.
		Publish/Xoá/Đổi Template hàng loạt với số lượng lớn (hơn vài chục sản phẩm) sẽ TỰ ĐỘNG chuyển sang xử lý nền để tránh treo trang — xem tiến độ ở
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-publish-queue' ) ); ?>">Publish Queue</a> hoặc <a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-logs' ) ); ?>">Logs</a>.
	</p>

	<?php if ( null !== $published_count ) : ?>
		<div class="notice notice-success is-dismissible"><p>Đã publish <?php echo esc_html( $published_count ); ?> sản phẩm.</p></div>
	<?php endif; ?>
	<?php if ( null !== $deleted_count ) : ?>
		<div class="notice notice-success is-dismissible"><p>Đã xoá <?php echo esc_html( $deleted_count ); ?> Draft (kèm ảnh liên quan — trừ ảnh Template Gallery dùng chung).</p></div>
	<?php endif; ?>
	<?php if ( $template_changed ) : ?>
		<div class="notice notice-success is-dismissible"><p>Đã đổi Template và cập nhật lại category/giá/SKU/mô tả/ảnh cho sản phẩm.</p></div>
	<?php endif; ?>
	<?php if ( $template_change_failed ) : ?>
		<div class="notice notice-error is-dismissible"><p>Đổi Template thất bại — kiểm tra Logs để biết chi tiết.</p></div>
	<?php endif; ?>
	<?php if ( null !== $delete_queued_count ) : ?>
		<div class="notice notice-info is-dismissible"><p>Số lượng chọn khá lớn — đã lên lịch xoá NỀN cho <?php echo esc_html( $delete_queued_count ); ?> Draft, chia thành từng đợt nhỏ (không cần giữ trang này mở). Theo dõi tiến độ ở <a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-logs' ) ); ?>">Logs</a>.</p></div>
	<?php endif; ?>
	<?php if ( null !== $template_change_queued ) : ?>
		<div class="notice notice-info is-dismissible"><p>Số lượng chọn khá lớn — đã lên lịch đổi Template NỀN cho <?php echo esc_html( $template_change_queued ); ?> Draft, chia thành từng đợt nhỏ. Theo dõi tiến độ ở <a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-logs' ) ); ?>">Logs</a>.</p></div>
	<?php endif; ?>
	<?php if ( null !== $bulk_template_changed ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			Đã đổi Template cho <?php echo esc_html( $bulk_template_changed ); ?> sản phẩm.
			<?php if ( $bulk_template_failed > 0 ) : ?>
				<?php echo esc_html( $bulk_template_failed ); ?> sản phẩm lỗi — xem <a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-logs' ) ); ?>">Logs</a> để biết chi tiết.
			<?php endif; ?>
		</p></div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="upi-filter-bar">
		<input type="hidden" name="page" value="upi-drafts" />
		<input type="search" name="s" value="<?php echo esc_attr( $search_term ); ?>" placeholder="Tìm theo tên sản phẩm…" class="regular-text" />
		<?php
		wp_dropdown_categories(
			array(
				'taxonomy'          => 'product_cat',
				'name'              => 'product_cat',
				'selected'          => $filter_cat_id,
				'show_option_all'   => 'Tất cả Category',
				'option_none_value' => 0,
				'hierarchical'      => 1,
				'hide_empty'        => 0,
			)
		);
		?>
		<select name="template_id">
			<option value="">Tất cả Template</option>
			<option value="none" <?php selected( $filter_template_raw, 'none' ); ?>>— Không gán Template —</option>
			<?php foreach ( $templates as $tpl ) : ?>
				<option value="<?php echo esc_attr( $tpl->id ); ?>" <?php selected( $filter_template_raw, (string) $tpl->id ); ?>><?php echo esc_html( $tpl->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button">Lọc</button>
		<?php if ( $has_active_filter ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-drafts' ) ); ?>" class="button-link">Xoá bộ lọc</a>
		<?php endif; ?>
	</form>

	<?php if ( ! $query->have_posts() ) : ?>
		<div class="upi-placeholder-box">
			<?php if ( $has_active_filter ) : ?>
				<p><strong>Không có Draft nào khớp bộ lọc.</strong></p>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-drafts' ) ); ?>">Xoá bộ lọc</a> để xem lại toàn bộ.</p>
			<?php else : ?>
				<p><strong>Chưa có Draft nào.</strong></p>
				<p>Gửi sản phẩm từ Local Staging của Chrome Extension để thấy chúng ở đây.</p>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<?php
		// CHỈ 1 form duy nhất bao toàn bộ bảng (cho bulk publish/delete/đổi
		// Template — checkbox cần nằm trong form để submit được). Actions của
		// TỪNG DÒNG (Publish/Xoá) KHÔNG dùng form riêng — trước đây lồng
		// form-trong-form là HTML không hợp lệ, khiến trình duyệt tự "sửa" DOM
		// và hoạt động chập chờn — thay bằng link GET có nonce, an toàn vì <a>
		// không phải form nên không bao giờ lồng được. Đổi Template từng dòng
		// cũng dùng cùng cách (link GET dựng bằng JS khi đổi <select>).
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="upi-drafts-form">
			<?php wp_nonce_field( 'upi_bulk_publish_drafts' ); // nonce mặc định "_wpnonce" — giữ nguyên tên field cho Publish Selected/Hẹn giờ Publish (không đổi hành vi các action đã có). ?>
			<?php wp_nonce_field( 'upi_bulk_delete_drafts', '_wpnonce_delete' ); // nonce riêng tên khác cho Xoá đã chọn, tránh đụng field trên. ?>
			<?php wp_nonce_field( 'upi_bulk_change_template', '_wpnonce_change_template' ); // nonce riêng cho Đổi Template hàng loạt. ?>
			<input type="hidden" name="action" id="upi-drafts-action" value="upi_bulk_publish_drafts" />
			<input type="hidden" name="interval_seconds" id="upi-interval-hidden" value="" />
			<input type="hidden" name="start_delay_seconds" id="upi-delay-hidden" value="" />
			<input type="hidden" name="bulk_template_id" id="upi-bulk-template-id-hidden" value="" />

			<div class="upi-bulk-toolbar">
				<input type="checkbox" id="upi-drafts-select-all" onclick="document.querySelectorAll('.upi-draft-check').forEach(cb => cb.checked = this.checked)" />
				<label for="upi-drafts-select-all">Chọn tất cả</label>
				<button type="submit" class="button button-primary" onclick="return confirm('Publish ngay các sản phẩm đã chọn?');">Publish Selected</button>
				<button type="button" class="button button-primary" id="upi-schedule-btn">Hẹn giờ Publish…</button>
				<button type="button" class="button upi-btn-danger" id="upi-bulk-delete-btn">Xoá đã chọn</button>

				<span class="upi-quick-select">
					<label for="upi-quick-count">Chọn nhanh</label>
					<input type="number" id="upi-quick-count" min="1" value="10" />
					<button type="button" class="button" id="upi-quick-select-btn">sản phẩm đầu tiên → Hẹn giờ Publish</button>
				</span>

				<span class="upi-quick-select">
					<label for="upi-bulk-template-select">Đổi Template</label>
					<select id="upi-bulk-template-select">
						<option value="">— Không Template —</option>
						<?php foreach ( $templates as $tpl ) : ?>
							<option value="<?php echo esc_attr( $tpl->id ); ?>"><?php echo esc_html( $tpl->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button" id="upi-bulk-template-btn">Áp dụng cho đã chọn</button>
				</span>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="check-column"></td>
						<th>Ảnh</th>
						<th>Title</th>
						<th>SKU</th>
						<th>Giá</th>
						<th>Category</th>
						<th style="width:200px;">Template</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php while ( $query->have_posts() ) : $query->the_post();
						$post_id = get_the_ID();
						$product = wc_get_product( $post_id );
						if ( ! $product ) continue;
						$categories = wp_list_pluck( wc_get_product_terms( $post_id, 'product_cat' ), 'name' );
						$publish_url = wp_nonce_url(
							add_query_arg(
								array( 'action' => 'upi_publish_draft', 'post_id' => $post_id ),
								admin_url( 'admin-post.php' )
							),
							'upi_publish_draft'
						);
						$delete_url      = add_query_arg( 'post_id', $post_id, $delete_url_base );
						$current_template_id = isset( $canonical_by_wc[ $post_id ] ) ? (int) $canonical_by_wc[ $post_id ]->template_id : 0;
						?>
						<tr>
							<th class="check-column"><input type="checkbox" name="post_ids[]" value="<?php echo esc_attr( $post_id ); ?>" class="upi-draft-check" /></th>
							<td><?php echo get_the_post_thumbnail( $post_id, array( 40, 40 ) ); ?></td>
							<td><a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" target="_blank"><?php echo esc_html( $product->get_name() ); ?></a></td>
							<td><?php echo esc_html( $product->get_sku() ); ?></td>
							<td><?php echo esc_html( $product->get_regular_price() ); ?></td>
							<td><?php echo esc_html( $categories ? implode( ', ', $categories ) : '—' ); ?></td>
							<td>
								<?php if ( isset( $canonical_by_wc[ $post_id ] ) ) : ?>
									<select class="upi-change-template-select" data-post-id="<?php echo esc_attr( $post_id ); ?>">
										<option value="">— Không Template —</option>
										<?php foreach ( $templates as $tpl ) : ?>
											<option value="<?php echo esc_attr( $tpl->id ); ?>" <?php selected( $current_template_id, (int) $tpl->id ); ?>><?php echo esc_html( $tpl->name ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<span class="upi-muted" title="Draft này không có dữ liệu gốc trong plugin (tạo trước khi có tính năng này hoặc tạo thủ công) — không thể đổi Template an toàn.">—</span>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( $publish_url ); ?>" class="button button-small" onclick="return confirm('Publish sản phẩm này?');">Publish</a>
								<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" target="_blank">Edit</a>
								<a href="<?php echo esc_url( $delete_url ); ?>" class="upi-link-btn upi-link-danger" onclick="return confirm('Xoá vĩnh viễn sản phẩm này? Ảnh đã tải (trừ ảnh Template Gallery dùng chung) sẽ bị xoá luôn khỏi Media Library. Không thể hoàn tác.');">Xoá</a>
							</td>
						</tr>
					<?php endwhile; wp_reset_postdata(); ?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) ceil( $query->found_posts / $per_page );
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
		</form>
	<?php endif; ?>

	<!-- Modal "Hẹn giờ Publish" — chỉ hiện khi bấm nút, ẩn mặc định qua class .hidden. -->
	<div class="upi-modal-overlay hidden" id="upi-schedule-modal">
		<div class="upi-modal-box">
			<h2>Hẹn giờ Publish hàng loạt</h2>
			<p>Sẽ hẹn giờ cho <strong id="upi-schedule-count">0</strong> sản phẩm đã chọn, chạy nền — không cần giữ tab hay trình duyệt mở.</p>

			<div class="upi-modal-field">
				<label for="upi-schedule-interval">Khoảng cách giữa mỗi lần (giây)</label>
				<input type="number" id="upi-schedule-interval" min="5" step="1" value="120" />
			</div>
			<div class="upi-modal-field">
				<label for="upi-schedule-delay">Bắt đầu sau (giây, để 0 nếu chạy ngay)</label>
				<input type="number" id="upi-schedule-delay" min="0" step="1" value="0" />
			</div>

			<div class="upi-modal-eta">Dự kiến hoàn tất lúc: <strong id="upi-schedule-eta">—</strong></div>

			<div class="upi-modal-actions">
				<button type="button" class="button" id="upi-schedule-cancel">Huỷ</button>
				<button type="button" class="button button-primary" id="upi-schedule-confirm">Xác nhận hẹn giờ</button>
			</div>
		</div>
	</div>
</div>

<script>
(function () {
	var scheduleBtn   = document.getElementById('upi-schedule-btn');
	var modal         = document.getElementById('upi-schedule-modal');
	var countLabel    = document.getElementById('upi-schedule-count');
	var etaLabel      = document.getElementById('upi-schedule-eta');
	var intervalInput = document.getElementById('upi-schedule-interval');
	var delayInput    = document.getElementById('upi-schedule-delay');
	var cancelBtn     = document.getElementById('upi-schedule-cancel');
	var confirmBtn    = document.getElementById('upi-schedule-confirm');
	var form          = document.getElementById('upi-drafts-form');
	var actionField   = document.getElementById('upi-drafts-action');
	var intervalHidden = document.getElementById('upi-interval-hidden');
	var delayHidden     = document.getElementById('upi-delay-hidden');
	var bulkDeleteBtn   = document.getElementById('upi-bulk-delete-btn');
	var quickCountInput = document.getElementById('upi-quick-count');
	var quickSelectBtn  = document.getElementById('upi-quick-select-btn');
	var bulkTemplateBtn    = document.getElementById('upi-bulk-template-btn');
	var bulkTemplateSelect = document.getElementById('upi-bulk-template-select');
	var bulkTemplateIdHidden = document.getElementById('upi-bulk-template-id-hidden');
	var changeTemplateUrl = <?php echo wp_json_encode( $change_template_url ); ?>;

	function checkboxes() {
		return document.querySelectorAll('.upi-draft-check');
	}

	function selectedCount() {
		return document.querySelectorAll('.upi-draft-check:checked').length;
	}

	// "Đổi Template" hàng loạt — dùng CHUNG form (đã có sẵn checkbox) nhưng
	// đổi action + nonce field sang bulk-change-template, kèm template_id
	// chọn ở toolbar, trước khi submit.
	if (bulkTemplateBtn) {
		bulkTemplateBtn.addEventListener('click', function () {
			var n = selectedCount();
			if (n === 0) {
				alert('Chọn ít nhất 1 sản phẩm trước khi đổi Template.');
				return;
			}
			var label = bulkTemplateSelect.value ? bulkTemplateSelect.options[bulkTemplateSelect.selectedIndex].text : '(Không Template)';
			if (!confirm('Đổi Template cho ' + n + ' sản phẩm đã chọn sang "' + label + '"? Category/giá/SKU/mô tả/ảnh Template Gallery sẽ được cập nhật lại cho từng sản phẩm.')) {
				return;
			}
			bulkTemplateIdHidden.value = bulkTemplateSelect.value;
			actionField.value = 'upi_bulk_change_template';
			form.submit();
		});
	}

	// Nút "Hẹn giờ Publish…" không tồn tại khi chưa có Draft nào (bảng
	// rỗng) — thoát sớm để không lỗi khi các phần tử modal cũng không có.
	if (!scheduleBtn || !modal) return;

	function updateEta() {
		var n = selectedCount();
		countLabel.textContent = n;
		var interval = parseInt(intervalInput.value, 10) || 0;
		var delay = parseInt(delayInput.value, 10) || 0;
		if (n > 0) {
			// Ước tính hoàn tất = thời điểm sản phẩm CUỐI CÙNG chạy (n-1 khoảng
			// cách sau sản phẩm đầu, cộng thời gian delay bắt đầu).
			var totalSeconds = delay + Math.max(0, n - 1) * interval;
			var eta = new Date(Date.now() + totalSeconds * 1000);
			etaLabel.textContent = eta.toLocaleString();
		} else {
			etaLabel.textContent = '—';
		}
	}

	function openScheduleModal() {
		if (selectedCount() === 0) {
			alert('Chọn ít nhất 1 sản phẩm trước khi hẹn giờ.');
			return;
		}
		updateEta();
		modal.classList.remove('hidden');
	}

	scheduleBtn.addEventListener('click', openScheduleModal);

	intervalInput.addEventListener('input', updateEta);
	delayInput.addEventListener('input', updateEta);

	cancelBtn.addEventListener('click', function () {
		modal.classList.add('hidden');
	});

	confirmBtn.addEventListener('click', function () {
		if (selectedCount() === 0) {
			alert('Chọn ít nhất 1 sản phẩm trước khi hẹn giờ.');
			return;
		}
		var interval = parseInt(intervalInput.value, 10);
		if (!interval || interval < 5) {
			alert('Khoảng cách giữa mỗi lần phải từ 5 giây trở lên.');
			return;
		}
		intervalHidden.value = interval;
		delayHidden.value = parseInt(delayInput.value, 10) || 0;
		actionField.value = 'upi_schedule_bulk_publish';
		form.submit();
	});

	// "Chọn N sản phẩm đầu tiên" — chọn N dòng đầu tiên đang hiện trên bảng
	// (đúng thứ tự đang thấy, KHÔNG đổi lại cách sắp xếp mặc định của
	// trang), rồi mở luôn modal hẹn giờ — thay cho việc tick tay từng dòng.
	if (quickSelectBtn) {
		quickSelectBtn.addEventListener('click', function () {
			var n = parseInt(quickCountInput.value, 10) || 0;
			if (n <= 0) {
				alert('Nhập số lượng lớn hơn 0.');
				return;
			}
			var boxes = checkboxes();
			boxes.forEach(function (cb, idx) { cb.checked = idx < n; });
			openScheduleModal();
		});
	}

	// "Xoá đã chọn" — dùng CHUNG form (đã có sẵn checkbox) nhưng đổi action
	// + nonce field sang bulk-delete trước khi submit.
	if (bulkDeleteBtn) {
		bulkDeleteBtn.addEventListener('click', function () {
			var n = selectedCount();
			if (n === 0) {
				alert('Chọn ít nhất 1 sản phẩm trước khi xoá.');
				return;
			}
			if (!confirm('Xoá vĩnh viễn ' + n + ' sản phẩm đã chọn? Ảnh đã tải (trừ ảnh Template Gallery dùng chung) sẽ bị xoá luôn khỏi Media Library. Không thể hoàn tác.')) {
				return;
			}
			actionField.value = 'upi_bulk_delete_drafts';
			form.submit();
		});
	}

	// Đổi Template ngay trong dòng — điều hướng GET có nonce, không cần form riêng.
	document.querySelectorAll('.upi-change-template-select').forEach(function (select) {
		select.addEventListener('change', function () {
			var postId = select.getAttribute('data-post-id');
			var templateId = select.value;
			var label = templateId ? select.options[select.selectedIndex].text : '(Không Template)';
			if (!confirm('Đổi Template sản phẩm này sang "' + label + '"? Category/giá/SKU/mô tả/ảnh Template Gallery sẽ được cập nhật lại ngay.')) {
				select.value = select.getAttribute('data-current') || '';
				return;
			}
			window.location.href = changeTemplateUrl + '&post_id=' + encodeURIComponent(postId) + '&template_id=' + encodeURIComponent(templateId);
		});
		select.setAttribute('data-current', select.value);
	});
})();
</script>
