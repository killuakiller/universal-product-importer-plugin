<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pairing_code = isset( $_GET['upi_pairing_code'] ) ? sanitize_text_field( wp_unslash( $_GET['upi_pairing_code'] ) ) : '';
$tokens       = UPI_Auth::list_tokens();
?>
<div class="wrap upi-wrap">
	<h1>Settings</h1>

	<h2>Kết nối Chrome Extension</h2>
	<p class="description">Tạo pairing code, sau đó trong popup extension bấm "+ Add Site", nhập URL của site này + pairing code. Mã sống 10 phút, dùng được đúng 1 lần. Extension không bao giờ lưu username/password WordPress — chỉ lưu token được sinh ra ở bước này.</p>

	<?php if ( $pairing_code ) : ?>
		<div class="notice notice-success">
			<p>Pairing code: <code class="upi-pairing-code"><?php echo esc_html( $pairing_code ); ?></code> — hết hạn sau 10 phút.</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'upi_generate_pairing_code' ); ?>
		<input type="hidden" name="action" value="upi_generate_pairing_code" />
		<button type="submit" class="button button-primary">Generate Pairing Code</button>
	</form>

	<h2 class="upi-section-gap">Site đang kết nối (token)</h2>
	<table class="wp-list-table widefat fixed striped upi-tokens-table">
		<thead><tr><th>Nhãn</th><th>Tạo lúc</th><th>Dùng lần cuối</th><th>Trạng thái</th><th></th></tr></thead>
		<tbody>
		<?php if ( empty( $tokens ) ) : ?>
			<tr><td colspan="5">Chưa có extension nào kết nối.</td></tr>
		<?php else : ?>
			<?php foreach ( $tokens as $t ) : ?>
				<tr>
					<td><?php echo esc_html( $t->label ); ?></td>
					<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $t->created_at ) ); ?></td>
					<td><?php echo $t->last_used_at ? esc_html( mysql2date( 'Y-m-d H:i', $t->last_used_at ) ) : '—'; ?></td>
					<td><?php echo $t->revoked_at ? 'Đã thu hồi' : 'Đang hoạt động'; ?></td>
					<td>
						<?php if ( ! $t->revoked_at ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Thu hồi token này? Extension sẽ không thể kết nối site này nữa.');">
								<?php wp_nonce_field( 'upi_revoke_token' ); ?>
								<input type="hidden" name="action" value="upi_revoke_token" />
								<input type="hidden" name="token_id" value="<?php echo esc_attr( $t->id ); ?>" />
								<button type="submit" class="button-link-delete">Revoke</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<h2 class="upi-section-gap">REST API Base</h2>
	<p><code><?php echo esc_url( rest_url( 'product-importer/v1' ) ); ?></code></p>
</div>
