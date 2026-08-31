<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tải ảnh marketplace vào Media Library — được gọi lúc xử lý POST /imports
 * (từ v0.6.0), KHÔNG phải lúc tạo WooCommerce draft. create_draft() chỉ
 * REUSE attachment_id đã tải sẵn, không bao giờ tải lại ảnh. Chỉ áp dụng
 * cho các ảnh người dùng đã chọn (selected: true). Không hotlink. Có bảo vệ
 * SSRF đầy đủ.
 */
class UPI_Media {

	const MAX_BYTES       = 15 * 1024 * 1024;
	const TIMEOUT_SECONDS = 10;
	const ALLOWED_MIME    = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

	public static function is_url_safe( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}

		$host = $parts['host'];
		$ip   = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		// Loại bỏ localhost, private range, link-local, reserved (bao gồm
		// dải metadata endpoint 169.254.169.254 thuộc link-local).
		$is_public = filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);

		return false !== $is_public;
	}

	/**
	 * Tải + sideload một ảnh, gắn vào $post_id. Trả về attachment ID hoặc WP_Error.
	 * WordPress's download_url() đã tự theo dõi & giới hạn redirect hợp lý;
	 * ta xác thực lại URL gốc và MIME/size sau khi tải xong.
	 */
	/**
	 * @param string      $url Marketplace image URL (đã resolve full-res).
	 * @param int         $post_id Post gắn attachment (0 = chưa gắn).
	 * @param string      $description Alt text / tiêu đề attachment.
	 * @param string|null $filename_hint Tên file mong muốn KHÔNG kèm đuôi
	 *   (vd. "4549279421-01") — nếu có, dùng thay cho tên gốc lấy từ URL.
	 *   Đuôi file luôn lấy theo MIME THẬT của ảnh sau khi tải về (không tin
	 *   theo phần mở rộng trong URL), nên rename không bao giờ làm sai lệch
	 *   định dạng thật của file.
	 */
	public static function sideload( string $url, int $post_id, string $description = '', ?string $filename_hint = null ) {
		if ( ! self::is_url_safe( $url ) ) {
			return new WP_Error( 'unsafe_url', 'URL ảnh không vượt qua kiểm tra an toàn.', array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, self::TIMEOUT_SECONDS );

		if ( is_wp_error( $tmp ) ) {
			UPI_Logger::error( 'Tải ảnh thất bại: ' . $tmp->get_error_message(), null, $post_id, array( 'url' => $url ) );
			return $tmp;
		}

		$size = filesize( $tmp );
		if ( false === $size || $size > self::MAX_BYTES ) {
			@unlink( $tmp );
			return new WP_Error( 'image_too_large', 'Ảnh vượt quá kích thước cho phép.', array( 'status' => 400 ) );
		}

		$mime = wp_get_image_mime( $tmp );
		if ( ! $mime || ! in_array( $mime, self::ALLOWED_MIME, true ) ) {
			@unlink( $tmp );
			return new WP_Error( 'invalid_image_type', 'Định dạng ảnh không được hỗ trợ.', array( 'status' => 400 ) );
		}

		$filename = $filename_hint
			? sanitize_file_name( $filename_hint ) . '.' . self::mime_to_extension( $mime )
			: sanitize_file_name( basename( wp_parse_url( $url, PHP_URL_PATH ) ) ?: ( 'import-image.' . self::mime_to_extension( $mime ) ) );

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		// Giảm số size ảnh WordPress tự sinh trong lúc sideload — đây thường
		// là phần TỐN THỜI GIAN NHẤT khi import nhiều ảnh (mỗi ảnh gốc mặc
		// định bị resize ra 5-8 size khác nhau: thumbnail, medium,
		// medium_large, large, 1536x1536, 2048x2048...). Chỉ giữ lại
		// thumbnail + medium + large (đủ cho hầu hết theme/WooCommerce hiển
		// thị), tạm tắt các size lớn không cần thiết ngay lúc import — theme
		///WooCommerce vẫn tự dùng ảnh gốc (full) khi cần độ phân giải cao.
		add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'reduce_image_sizes_during_import' ), 999 );

		$attachment_id = media_handle_sideload( $file_array, $post_id, $description );

		remove_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'reduce_image_sizes_during_import' ), 999 );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			UPI_Logger::error( 'Sideload media thất bại: ' . $attachment_id->get_error_message(), null, $post_id, array( 'url' => $url ) );
			return $attachment_id;
		}

		return $attachment_id;
	}

	/** Suy ra đúng đuôi file từ MIME THẬT (không phải từ URL) — đảm bảo rename không bao giờ làm sai định dạng. */
	private static function mime_to_extension( string $mime ): string {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);
		return $map[ $mime ] ?? 'jpg';
	}

	/**
	 * Callback cho filter `intermediate_image_sizes_advanced` — chỉ bật
	 * trong lúc sideload/tạo attachment lúc import, giữ lại các size phổ
	 * biến nhất (thumbnail/medium/large), bỏ các size lớn ít dùng
	 * (medium_large, 1536x1536, 2048x2048, và size do theme đăng ký thêm)
	 * để giảm thời gian xử lý ảnh — nguyên nhân chính khiến việc import
	 * nhiều ảnh có thể vượt quá thời gian chờ.
	 */
	public static function reduce_image_sizes_during_import( array $sizes ): array {
		$keep = array( 'thumbnail', 'medium', 'large' );
		return array_intersect_key( $sizes, array_flip( $keep ) );
	}

	public static function sideload_batch( array $urls, int $post_id ): array {
		$results = array();
		foreach ( $urls as $url ) {
			$results[] = self::sideload( $url, $post_id );
		}
		return $results;
	}

	/**
	 * Giải mã 1 ảnh dạng data URL (`data:image/png;base64,...`) — dùng cho
	 * ảnh user tự upload/kéo-thả từ máy trong Local Staging của extension
	 * (không có URL thật để tải, chỉ có bytes base64 gửi kèm payload).
	 * Validate MIME + kích thước giống hệt `sideload()`, không có ngoại lệ
	 * nào về an toàn chỉ vì nguồn là "do chính user upload".
	 */
	public static function save_base64_image( string $data_url, int $post_id, string $description = '', ?string $filename_hint = null ) {
		if ( ! preg_match( '/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/', $data_url, $matches ) ) {
			return new WP_Error( 'invalid_data_url', 'Dữ liệu ảnh upload không hợp lệ.', array( 'status' => 400 ) );
		}

		$mime      = strtolower( $matches[1] );
		$base64    = $matches[2];

		if ( ! in_array( $mime, self::ALLOWED_MIME, true ) ) {
			return new WP_Error( 'invalid_image_type', 'Định dạng ảnh không được hỗ trợ.', array( 'status' => 400 ) );
		}

		$bytes = base64_decode( $base64, true );
		if ( false === $bytes ) {
			return new WP_Error( 'invalid_base64', 'Không giải mã được dữ liệu ảnh.', array( 'status' => 400 ) );
		}

		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return new WP_Error( 'image_too_large', 'Ảnh vượt quá kích thước cho phép.', array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ext      = self::mime_to_extension( $mime );
		$filename = $filename_hint ? sanitize_file_name( $filename_hint ) . '.' . $ext : ( 'upi-upload-' . wp_generate_password( 8, false ) . '.' . $ext );

		$upload = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_failed', $upload['error'], array( 'status' => 500 ) );
		}

		// Xác thực lại MIME thật của file đã ghi ra đĩa — không tin tuyệt đối
		// vào phần khai báo MIME trong data URL do client tự gửi lên.
		$real_mime = wp_get_image_mime( $upload['file'] );
		if ( ! $real_mime || ! in_array( $real_mime, self::ALLOWED_MIME, true ) ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'invalid_image_type', 'Định dạng ảnh thực tế không hợp lệ.', array( 'status' => 400 ) );
		}

		$attachment = array(
			'post_mime_type' => $real_mime,
			'post_title'      => $description ?: 'Uploaded image',
			'post_status'     => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'], $post_id ?: 0 );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $upload['file'] );
			return $attachment_id;
		}

		add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'reduce_image_sizes_during_import' ), 999 );
		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		remove_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'reduce_image_sizes_during_import' ), 999 );

		wp_update_attachment_metadata( $attachment_id, $attachment_data );

		return $attachment_id;
	}
}
