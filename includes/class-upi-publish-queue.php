<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hàng đợi Publish có hẹn giờ — cho phép publish hàng loạt Draft nhưng RẢI
 * ĐỀU theo thời gian (mỗi sản phẩm cách nhau N giây) thay vì publish hết
 * trong 1 request PHP duy nhất. Chạy nền qua Action Scheduler (đã có sẵn vì
 * WooCommerce phụ thuộc nó) — KHÔNG phụ thuộc tab trình duyệt hay kết nối
 * HTTP còn sống hay không. Xem tiến độ bất cứ lúc nào ở
 * Product Importer → Publish Queue (kể cả sau khi tắt máy rồi mở lại).
 *
 * Khác với UPI_Jobs (LEGACY, xử lý theo batch dồn dập để TẠO draft mới) —
 * class này xử lý TỪNG SẢN PHẨM MỘT với delay cấu hình được giữa các lần,
 * dùng để PUBLISH draft có sẵn (không tạo mới). Trạng thái lưu ở bảng DB
 * riêng (`upi_publish_queue`, xem class-upi-activator.php) — KHÔNG dùng
 * transient, vì job có thể kéo dài nhiều giờ (vd. 100 sản phẩm × 120s =
 * ~3.3 giờ) và transient có thể bị dọn sớm hơn dự kiến trên site dùng
 * object cache.
 */
class UPI_Publish_Queue {

	const HOOK         = 'upi_process_scheduled_publish';
	const MIN_INTERVAL = 5; // giây — chặn nhập 0/âm gây spam nhiều request publish gần như cùng lúc.

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'process_item' ), 10, 1 );
	}

	/**
	 * Lên lịch publish 1 danh sách post_id, rải đều cách nhau
	 * $interval_seconds, bắt đầu sau $start_delay_seconds kể từ lúc gọi.
	 * Trả về batch_id để nhóm các dòng cùng 1 lần hẹn giờ.
	 */
	public static function schedule_batch( array $post_ids, int $interval_seconds, int $start_delay_seconds = 0 ): string {
		$interval_seconds    = max( self::MIN_INTERVAL, $interval_seconds );
		$start_delay_seconds = max( 0, $start_delay_seconds );

		global $wpdb;
		$table    = self::table();
		$batch_id = 'upi_batch_' . wp_generate_password( 12, false, false );

		// QUAN TRỌNG: 2 mốc thời gian khác mục đích, KHÔNG được lẫn lộn.
		// - $utc_base: unix timestamp THẬT (time(), luôn UTC) — bắt buộc dùng
		//   cái này cho Action Scheduler, vì as_schedule_single_action() chạy
		//   theo UTC thật, dùng nhầm giờ local sẽ khiến job chạy lệch giờ.
		// - $local_base: current_time('timestamp') — timestamp đã cộng offset
		//   theo múi giờ site, chỉ dùng để FORMAT chuỗi hiển thị lưu vào cột
		//   scheduled_at (DATETIME), cho khớp quy ước "giờ local" mà toàn bộ
		//   các cột datetime khác trong plugin đang dùng (current_time('mysql')).
		$utc_base   = time();
		$local_base = current_time( 'timestamp' ); // phpcs:ignore -- cố ý, xem comment trên.

		$i = 0;
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( ! $post_id ) {
				continue;
			}

			$offset            = $start_delay_seconds + ( $i * $interval_seconds );
			$run_at_utc        = $utc_base + $offset;
			$scheduled_at_text = gmdate( 'Y-m-d H:i:s', $local_base + $offset );

			$wpdb->insert(
				$table,
				array(
					'batch_id'     => $batch_id,
					'post_id'      => $post_id,
					'scheduled_at' => $scheduled_at_text,
					'status'       => 'pending',
					'created_at'   => current_time( 'mysql' ),
				),
				array( '%s', '%d', '%s', '%s', '%s' )
			);
			$queue_id = (int) $wpdb->insert_id;

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( $run_at_utc, self::HOOK, array( $queue_id ), 'upi' );
			} else {
				// Không có Action Scheduler: không thể hẹn giờ thật — chạy
				// ngay để không mất dữ liệu, chấp nhận mất khả năng rải đều.
				self::process_item( $queue_id );
			}

			$i++;
		}

		UPI_Logger::info( "Đã hẹn giờ publish {$i} sản phẩm (batch {$batch_id}), cách nhau {$interval_seconds}s, bắt đầu sau {$start_delay_seconds}s." );

		return $batch_id;
	}

	/** Chạy đúng 1 dòng trong hàng đợi — publish 1 sản phẩm. Idempotent: bỏ qua nếu không còn 'pending'. */
	public static function process_item( int $queue_id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $queue_id ) );

		if ( ! $row || 'pending' !== $row->status ) {
			return;
		}

		$post_id = (int) $row->post_id;

		if ( ! $post_id || ! get_post( $post_id ) || 'product' !== get_post_type( $post_id ) ) {
			$wpdb->update(
				$table,
				array(
					'status'        => 'failed',
					'error_message' => 'Sản phẩm không còn tồn tại (có thể đã bị xoá).',
				),
				array( 'id' => $queue_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ), true );

		if ( is_wp_error( $result ) ) {
			$wpdb->update(
				$table,
				array(
					'status'        => 'failed',
					'error_message' => $result->get_error_message(),
				),
				array( 'id' => $queue_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			UPI_Logger::error( "Publish theo lịch thất bại cho product #{$post_id} (queue #{$queue_id}): " . $result->get_error_message() );
			return;
		}

		$wpdb->update(
			$table,
			array(
				'status'       => 'published',
				'published_at' => current_time( 'mysql' ),
			),
			array( 'id' => $queue_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		UPI_Logger::info( "Đã publish product #{$post_id} theo lịch (queue #{$queue_id})." );
	}

	/** Huỷ 1 dòng đang 'pending' — unschedule khỏi Action Scheduler + đánh dấu DB. */
	public static function cancel( int $queue_id ): bool {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $queue_id ) );

		if ( ! $row || 'pending' !== $row->status ) {
			return false;
		}

		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::HOOK, array( $queue_id ), 'upi' );
		}

		$wpdb->update(
			$table,
			array( 'status' => 'cancelled' ),
			array( 'id' => $queue_id ),
			array( '%s' ),
			array( '%d' )
		);
		return true;
	}

	/**
	 * Huỷ MỌI dòng đang 'pending' của 1 post_id — dùng khi Draft đó bị xoá,
	 * để hàng đợi không cố publish 1 sản phẩm không còn tồn tại nữa (tránh
	 * rác trạng thái "Lỗi" vô nghĩa trong Publish Queue).
	 */
	public static function cancel_all_pending_for_post( int $post_id ): int {
		global $wpdb;
		$table = self::table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d AND status = 'pending'", $post_id ) );

		$cancelled = 0;
		foreach ( $ids as $id ) {
			if ( self::cancel( (int) $id ) ) {
				$cancelled++;
			}
		}
		return $cancelled;
	}

	/**
	 * "Lên lịch lại" — dùng cho 1 dòng đã 'failed'/'cancelled' trong Publish
	 * Queue, KHÔNG bắt user quay lại trang Drafts tick chọn lại. Tạo 1 dòng
	 * hàng đợi MỚI cho đúng post_id đó (không sửa lại dòng cũ — giữ nguyên
	 * lịch sử), chạy sau $delay_seconds kể từ lúc bấm.
	 */
	public static function reschedule_one( int $post_id, int $delay_seconds = 0 ): string {
		return self::schedule_batch( array( $post_id ), self::MIN_INTERVAL, max( 0, $delay_seconds ) );
	}

	/** Danh sách hàng đợi (mọi batch), sắp theo giờ chạy tăng dần, có phân trang + lọc theo status. */
	public static function get_list( string $status_filter = '', int $per_page = 50, int $page = 1 ): array {
		global $wpdb;
		$table = self::table();

		$allowed_status = array( 'pending', 'published', 'failed', 'cancelled' );
		$where          = '';
		$where_args     = array();
		if ( $status_filter && in_array( $status_filter, $allowed_status, true ) ) {
			$where        = 'WHERE status = %s';
			$where_args[] = $status_filter;
		}

		$total = $where_args
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $where_args ) )
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$offset   = max( 0, ( $page - 1 ) * $per_page );
		$sql_args = array_merge( $where_args, array( $per_page, $offset ) );
		$rows     = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY scheduled_at ASC LIMIT %d OFFSET %d", $sql_args )
		);

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/** Đếm nhanh theo trạng thái — dùng cho các tab bộ lọc trên UI. */
	public static function counts(): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) as c FROM {$table} GROUP BY status" );
		$out   = array(
			'pending'   => 0,
			'published' => 0,
			'failed'    => 0,
			'cancelled' => 0,
		);
		foreach ( $rows as $r ) {
			if ( isset( $out[ $r->status ] ) ) {
				$out[ $r->status ] = (int) $r->c;
			}
		}
		return $out;
	}

	private static function table(): string {
		return UPI_DB::publish_queue_table();
	}
}
