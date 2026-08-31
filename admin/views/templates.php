<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$templates = UPI_Templates::all();
$edit_id   = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
$editing   = $edit_id ? UPI_Templates::find( $edit_id ) : null;
$gallery_ids = $editing ? UPI_Templates::gallery_attachment_ids( $editing ) : array();
$selected_category_ids = $editing ? UPI_Templates::category_ids( $editing ) : array();
$template_deleted = isset( $_GET['template_deleted'] );
?>
<div class="wrap upi-wrap">
	<h1>Templates</h1>
	<p class="description">
		Template quyết định category/shipping class/giá/brand/mô tả/Template Gallery khi tạo WooCommerce Draft.
		Tags là dữ liệu riêng của từng sản phẩm (sửa trong Local Staging của Chrome Extension trước khi gửi), không thuộc Template.
		Mọi giá trị đều do bạn cấu hình — không có gì bị hard-code trong code, và không có field nào liên quan tới
		WPCA hay bất kỳ plugin product-options nào khác (nằm ngoài phạm vi của Universal Product Importer).
	</p>

	<?php if ( $template_deleted ) : ?>
		<div class="notice notice-success is-dismissible"><p>Đã xoá Template. Draft đã tạo trước đó từ Template này không bị ảnh hưởng.</p></div>
	<?php endif; ?>

	<h2>Templates đã tạo</h2>
	<?php if ( empty( $templates ) ) : ?>
		<div class="upi-placeholder-box">
			<p><strong>Chưa có Template nào.</strong></p>
			<p>Tạo Template đầu tiên ở form phía dưới để tự động áp category/giá/SKU/ảnh mỗi khi tạo WooCommerce Draft.</p>
		</div>
	<?php else : ?>
		<div class="upi-template-grid">
			<?php foreach ( $templates as $t ) :
				$cat_names   = array();
				foreach ( UPI_Templates::category_ids( $t ) as $cat_id ) {
					$cat_term = get_term( $cat_id, 'product_cat' );
					if ( $cat_term && ! is_wp_error( $cat_term ) ) {
						$cat_names[] = $cat_term->name;
					}
				}
				$shipping    = $t->shipping_class_id ? get_term( $t->shipping_class_id, 'product_shipping_class' ) : null;
				$tpl_gallery = UPI_Templates::gallery_attachment_ids( $t );
				$cover_id    = $tpl_gallery[0] ?? 0;
				$used_count  = UPI_Templates::count_products_using( (int) $t->id );
				$delete_confirm = 'Xoá vĩnh viễn Template "' . $t->name . '"? '
					. ( $used_count > 0
						? "Có {$used_count} sản phẩm đang gán Template này — Draft đã tạo sẽ KHÔNG bị ảnh hưởng, nhưng sau khi xoá sẽ không thể chọn lại Template này cho các thao tác tiếp theo (vd. Đổi Template). "
						: '' )
					. 'Không thể hoàn tác.';
				?>
				<div class="upi-template-card">
					<div class="upi-template-card-cover">
						<?php if ( $cover_id ) : ?>
							<?php echo wp_get_attachment_image( $cover_id, array( 160, 120 ) ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-portfolio"></span>
						<?php endif; ?>
					</div>
					<div class="upi-template-card-body">
						<h3><?php echo esc_html( $t->name ); ?></h3>
						<div class="upi-template-card-meta">
							<span class="upi-badge"><?php echo $cat_names ? esc_html( implode( ', ', $cat_names ) ) : 'Chưa gán category'; ?></span>
							<?php if ( $shipping && ! is_wp_error( $shipping ) ) : ?>
								<span class="upi-badge"><?php echo esc_html( $shipping->name ); ?></span>
							<?php endif; ?>
							<?php if ( $t->regular_price ) : ?>
								<span class="upi-badge"><?php echo wp_kses_post( wc_price( $t->regular_price ) ); ?></span>
							<?php endif; ?>
							<?php if ( $t->sku_prefix ) : ?>
								<span class="upi-badge upi-badge-muted">SKU: <?php echo esc_html( $t->sku_prefix ); ?></span>
							<?php endif; ?>
							<span class="upi-badge upi-badge-muted"><?php echo count( $tpl_gallery ); ?> ảnh gallery</span>
							<?php if ( $used_count > 0 ) : ?>
								<span class="upi-badge upi-badge-info"><?php echo esc_html( $used_count ); ?> sản phẩm đang dùng</span>
							<?php endif; ?>
						</div>
					</div>
					<div class="upi-template-card-actions">
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'upi-templates', 'edit' => $t->id ), admin_url( 'admin.php' ) ) ); ?>" class="button button-small">Sửa</a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'upi_duplicate_template' ); ?>
							<input type="hidden" name="action" value="upi_duplicate_template" />
							<input type="hidden" name="template_id" value="<?php echo esc_attr( $t->id ); ?>" />
							<button type="submit" class="button button-small">Duplicate</button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo wp_json_encode( $delete_confirm ); ?>);">
							<?php wp_nonce_field( 'upi_delete_template' ); ?>
							<input type="hidden" name="action" value="upi_delete_template" />
							<input type="hidden" name="template_id" value="<?php echo esc_attr( $t->id ); ?>" />
							<button type="submit" class="button button-small upi-btn-danger">Xoá</button>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<h2>
		<?php echo $editing ? 'Sửa Template: ' . esc_html( $editing->name ) : 'Tạo Template mới'; ?>
		<?php if ( $editing ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=upi-templates' ) ); ?>" class="button upi-inline-btn-sm">+ Tạo Template mới</a>
		<?php endif; ?>
	</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="upi-template-form">
		<?php wp_nonce_field( 'upi_save_template' ); ?>
		<input type="hidden" name="action" value="upi_save_template" />
		<?php if ( $editing ) : ?><input type="hidden" name="template_id" value="<?php echo esc_attr( $editing->id ); ?>" /><?php endif; ?>
		<input type="hidden" name="gallery_image_ids" id="upi-tpl-gallery-ids" value="<?php echo esc_attr( implode( ',', $gallery_ids ) ); ?>" />

		<table class="form-table">
			<tr>
				<th><label>Tên Template</label></th>
				<td><input type="text" name="name" value="<?php echo esc_attr( $editing->name ?? '' ); ?>" class="regular-text" placeholder="Ví dụ: T-Shirt" required /></td>
			</tr>
			<tr>
				<th><label>WooCommerce Category</label></th>
				<td>
					<?php
					// Checklist category THẬT của WooCommerce, cho phép chọn
					// NHIỀU category cùng lúc — dùng đúng UI checklist gốc của
					// WordPress (giống metabox Category khi sửa Post/Product)
					// thay vì dropdown chỉ chọn được 1 (Bug 8 + yêu cầu multi-select).
					// Bọc trong 1 dropdown ĐÓNG sẵn (Bug: site nhiều category
					// khiến danh sách dài, chiếm hết trang) — bấm vào mới mở ra,
					// có ô tìm kiếm để lọc nhanh bên trong. Khi tạo/đổi Draft,
					// sản phẩm sẽ được gán TẤT CẢ category đã chọn ở đây.
					?>
					<div class="upi-cat-dropdown" id="upi-cat-dropdown">
						<button type="button" class="upi-cat-dropdown-toggle" id="upi-cat-dropdown-toggle" aria-expanded="false">
							<span class="upi-cat-dropdown-summary" id="upi-cat-dropdown-summary">— Chọn category —</span>
							<span class="dashicons dashicons-arrow-down-alt2 upi-cat-dropdown-arrow"></span>
						</button>
						<div class="upi-cat-dropdown-panel hidden" id="upi-cat-dropdown-panel">
							<input type="search" id="upi-cat-search" class="upi-cat-search" placeholder="Tìm category..." autocomplete="off" />
							<div class="upi-cat-checklist" id="upi-cat-checklist-wrap">
								<ul class="categorychecklist form-no-clear">
									<?php
									wp_terms_checklist(
										0,
										array(
											'taxonomy'      => 'product_cat',
											'selected_cats' => $selected_category_ids,
										)
									);
									?>
								</ul>
								<p class="upi-cat-search-empty hidden">Không tìm thấy category nào khớp.</p>
							</div>
						</div>
					</div>
					<p class="description">Có thể chọn nhiều category — danh sách lấy trực tiếp từ WooCommerce trên site này. Bấm vào ô trên để mở/đóng, gõ tìm kiếm bên trong để lọc nhanh nếu site có nhiều category.</p>
				</td>
			</tr>
			<tr>
				<th><label>Shipping Class</label></th>
				<td>
					<?php
					// Selector shipping class THẬT — lấy đúng danh sách đã tạo
					// sẵn trong WooCommerce → Settings → Shipping → Shipping
					// Classes, không hard-code bất kỳ class nào.
					$shipping_classes = get_terms(
						array(
							'taxonomy'   => 'product_shipping_class',
							'hide_empty' => false,
						)
					);
					$selected_shipping_class = $editing->shipping_class_id ?? 0;
					?>
					<select name="shipping_class_id">
						<option value="0">— Không gán Shipping Class —</option>
						<?php if ( ! is_wp_error( $shipping_classes ) ) : ?>
							<?php foreach ( $shipping_classes as $term ) : ?>
								<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $selected_shipping_class, $term->term_id ); ?>>
									<?php echo esc_html( $term->name ); ?>
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
					<p class="description">
						Danh sách lấy trực tiếp từ WooCommerce → Settings → Shipping → Shipping Classes trên site này.
						<?php if ( ! is_wp_error( $shipping_classes ) && empty( $shipping_classes ) ) : ?>
							<strong>Chưa có Shipping Class nào — tạo trước trong WooCommerce Settings nếu muốn dùng.</strong>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label>Giá gốc (Regular Price)</label></th>
				<td><input type="number" step="0.01" name="regular_price" value="<?php echo esc_attr( $editing->regular_price ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label>Giá khuyến mãi (Sale Price)</label></th>
				<td><input type="number" step="0.01" name="sale_price" value="<?php echo esc_attr( $editing->sale_price ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label>Brand</label></th>
				<td><input type="text" name="brand" value="<?php echo esc_attr( $editing->brand ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label>SKU Prefix</label></th>
				<td><input type="text" name="sku_prefix" value="<?php echo esc_attr( $editing->sku_prefix ?? '' ); ?>" placeholder="Ví dụ: TSH" /></td>
			</tr>
			<tr>
				<th><label>Mô tả chung (Template)</label></th>
				<td>
					<textarea name="description" rows="5" class="large-text"><?php echo esc_textarea( $editing->description ?? '' ); ?></textarea>
					<p class="description">Mô tả cuối cùng của sản phẩm = giới thiệu riêng (user tự viết trong Local Staging của Chrome Extension trước khi gửi) + mô tả chung này, nối sau. Không ghi đè phần giới thiệu riêng của từng sản phẩm.</p>
				</td>
			</tr>
			<tr>
				<th><label>Mô tả ngắn (Short Description)</label></th>
				<td><textarea name="short_description" rows="2" class="large-text"><?php echo esc_textarea( $editing->short_description ?? '' ); ?></textarea></td>
			</tr>
			<tr>
				<th><label>Template Gallery</label></th>
				<td>
					<p class="description">Ảnh chung áp dụng cho MỌI sản phẩm dùng template này (vd. size chart, color chart, hướng dẫn giặt ủi...). Khi tạo WooCommerce Draft, ảnh sản phẩm crawl được luôn đứng TRƯỚC, ảnh Template Gallery luôn nối vào SAU — không bao giờ đảo ngược thứ tự.</p>
					<div id="upi-tpl-gallery-thumbs" class="upi-thumbs"></div>
					<button type="button" class="button" id="upi-tpl-gallery-add">+ Add Image</button>
				</td>
			</tr>
		</table>

		<button type="submit" class="button button-primary"><?php echo $editing ? 'Lưu thay đổi' : 'Tạo Template'; ?></button>
	</form>
</div>

<script>
(function () {
	var hiddenInput = document.getElementById('upi-tpl-gallery-ids');
	var thumbsWrap = document.getElementById('upi-tpl-gallery-thumbs');
	var addBtn = document.getElementById('upi-tpl-gallery-add');

	function currentIds() {
		return (hiddenInput.value || '').split(',').filter(Boolean).map(Number);
	}

	function renderThumbs(ids, urlsById) {
		thumbsWrap.innerHTML = ids
			.map(function (id, i) {
				var url = urlsById[id] || '';
				return '<span class="upi-thumb" draggable="true" data-idx="' + i + '" data-id="' + id + '">' +
					(url ? '<img src="' + url + '" />' : '') +
					'<button type="button" class="upi-thumb-del" data-idx="' + i + '">×</button>' +
					'</span>';
			})
			.join('');
	}

	// Tải URL hiển thị cho các attachment ID đã lưu sẵn (khi mở sửa template).
	function bootstrapExisting() {
		var ids = currentIds();
		if (!ids.length || !window.wp || !wp.media) {
			renderThumbs(ids, {});
			return;
		}
		var urlsById = {};
		var remaining = ids.length;
		ids.forEach(function (id) {
			var attachment = wp.media.attachment(id);
			attachment.fetch().always(function () {
				urlsById[id] = (attachment.get('sizes') && attachment.get('sizes').thumbnail)
					? attachment.get('sizes').thumbnail.url
					: attachment.get('url');
				remaining--;
				if (remaining <= 0) renderThumbs(ids, urlsById);
			});
		});
	}

	addBtn.addEventListener('click', function () {
		if (!window.wp || !wp.media) return;
		var frame = wp.media({ title: 'Chọn ảnh cho Template Gallery', multiple: true, library: { type: 'image' } });
		frame.on('select', function () {
			var selection = frame.state().get('selection').toJSON();
			var ids = currentIds();
			var urlsById = {};
			selection.forEach(function (att) {
				ids.push(att.id);
				urlsById[att.id] = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
			});
			hiddenInput.value = ids.join(',');
			renderThumbs(ids, urlsById);
			bootstrapExisting(); // đảm bảo ảnh cũ cũng có URL hiển thị đúng
		});
		frame.open();
	});

	thumbsWrap.addEventListener('click', function (e) {
		if (!e.target.classList.contains('upi-thumb-del')) return;
		var idx = Number(e.target.dataset.idx);
		var ids = currentIds();
		ids.splice(idx, 1);
		hiddenInput.value = ids.join(',');
		bootstrapExisting();
	});

	var dragSrcIdx = null;
	thumbsWrap.addEventListener('dragstart', function (e) {
		if (e.target.classList.contains('upi-thumb')) dragSrcIdx = Number(e.target.dataset.idx);
	});
	thumbsWrap.addEventListener('dragover', function (e) {
		if (e.target.closest('.upi-thumb')) e.preventDefault();
	});
	thumbsWrap.addEventListener('drop', function (e) {
		var target = e.target.closest('.upi-thumb');
		if (!target || dragSrcIdx === null) return;
		e.preventDefault();
		var targetIdx = Number(target.dataset.idx);
		var ids = currentIds();
		var moved = ids.splice(dragSrcIdx, 1)[0];
		ids.splice(targetIdx, 0, moved);
		hiddenInput.value = ids.join(',');
		bootstrapExisting();
		dragSrcIdx = null;
	});

	bootstrapExisting();
})();

(function () {
	var searchInput = document.getElementById('upi-cat-search');
	var wrap = document.getElementById('upi-cat-checklist-wrap');
	if (!searchInput || !wrap) return;

	var emptyMsg = wrap.querySelector('.upi-cat-search-empty');

	function applyFilter() {
		var term = searchInput.value.trim().toLowerCase();
		var items = wrap.querySelectorAll('li');

		if (!term) {
			items.forEach(function (li) { li.style.display = ''; });
			emptyMsg.classList.add('hidden');
			return;
		}

		// Category nào khớp thì hiện luôn CẢ nhánh con bên trong nó (để chọn
		// tiếp con nếu cần) VÀ cả đường dẫn cha phía trên (để biết nó thuộc
		// nhóm nào) — không chỉ ẩn/hiện độc lập từng dòng, tránh việc con
		// khớp bị "mồ côi" khi cha không khớp text tìm kiếm.
		var visible = new Set();
		items.forEach(function (li) {
			var label = li.querySelector(':scope > label');
			var text = label ? label.textContent.toLowerCase() : '';
			if (text.indexOf(term) === -1) return;

			visible.add(li);
			li.querySelectorAll('li').forEach(function (child) { visible.add(child); });

			var ancestor = li.parentElement.closest('li');
			while (ancestor) {
				visible.add(ancestor);
				ancestor = ancestor.parentElement.closest('li');
			}
		});

		items.forEach(function (li) {
			li.style.display = visible.has(li) ? '' : 'none';
		});
		emptyMsg.classList.toggle('hidden', visible.size > 0);
	}

	searchInput.addEventListener('input', applyFilter);
	// Enter trong ô tìm kiếm KHÔNG được submit nhầm cả form Template.
	searchInput.addEventListener('keydown', function (e) {
		if (e.key === 'Enter') e.preventDefault();
	});
})();

(function () {
	var dropdown = document.getElementById('upi-cat-dropdown');
	var toggle   = document.getElementById('upi-cat-dropdown-toggle');
	var panel    = document.getElementById('upi-cat-dropdown-panel');
	var summary  = document.getElementById('upi-cat-dropdown-summary');
	var checklistWrap = document.getElementById('upi-cat-checklist-wrap');
	if (!dropdown || !toggle || !panel || !summary || !checklistWrap) return;

	function checkedLabels() {
		return Array.prototype.map.call(
			checklistWrap.querySelectorAll('input[type="checkbox"]:checked'),
			function (input) {
				var label = input.closest('label');
				return label ? label.textContent.trim() : '';
			}
		).filter(Boolean);
	}

	function updateSummary() {
		var names = checkedLabels();
		summary.textContent = names.length ? names.join(', ') : '— Chọn category —';
		summary.title = names.join(', ');
	}

	function openPanel() {
		panel.classList.remove('hidden');
		dropdown.classList.add('open');
		toggle.setAttribute('aria-expanded', 'true');
	}
	function closePanel() {
		panel.classList.add('hidden');
		dropdown.classList.remove('open');
		toggle.setAttribute('aria-expanded', 'false');
	}

	toggle.addEventListener('click', function () {
		if (panel.classList.contains('hidden')) openPanel(); else closePanel();
	});

	// Bấm ra ngoài dropdown thì tự đóng lại — không cần nút "Xong" riêng.
	document.addEventListener('click', function (e) {
		if (!dropdown.contains(e.target)) closePanel();
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') closePanel();
	});

	checklistWrap.addEventListener('change', function (e) {
		if (e.target.matches('input[type="checkbox"]')) updateSummary();
	});

	updateSummary(); // hiện đúng category đã chọn sẵn khi mở form Sửa Template
})();
</script>
