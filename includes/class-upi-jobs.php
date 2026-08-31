<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LEGACY / INTERNAL ONLY — không còn caller nào từ khi bỏ "Product
 * Workspace" ở v0.9.0. Batch job chạy nền cho "Create WooCommerce Drafts"
 * hàng loạt (dùng Action Scheduler). Trạng thái job lưu tạm trong
 * wp_options (transient), đọc qua GET /products/jobs/{job_id} (cũng LEGACY).
 * Giữ lại nguyên vẹn để không vỡ tương thích ngược — KHÔNG dùng cho tính
 * năng mới, không phát triển thêm. init() vẫn được gọi ở bootstrap để
 * add_action() hook không lỗi nếu có job cũ còn treo trong queue.
 */
class UPI_Jobs {

	const BATCH_SIZE      = 8;
	const HOOK            = 'upi_process_draft_batch';
	const JOB_TTL_SECONDS = 6 * HOUR_IN_SECONDS;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'process_batch' ), 10, 2 );
	}

	/**
	 * Tạo 1 job mới và lên lịch xử lý theo batch. Trả về job_id ngay lập
	 * tức để client poll tiến độ, không chờ xử lý xong trong request này.
	 */
	public static function queue_bulk_create_drafts( array $product_ids ): string {
		$job_id = 'upi_job_' . wp_generate_password( 12, false, false );

		$state = array(
			'total'     => count( $product_ids ),
			'processed' => 0,
			'created'   => array(),
			'failed'    => array(),
			'status'    => 'queued',
		);
		set_transient( $job_id, $state, self::JOB_TTL_SECONDS );

		$batches = array_chunk( $product_ids, self::BATCH_SIZE );
		foreach ( $batches as $i => $batch ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $i * 3, self::HOOK, array( $job_id, $batch ), 'upi' );
			} else {
				// Fallback nếu Action Scheduler không sẵn sàng: xử lý ngay
				// (chấp nhận rủi ro timeout với batch rất lớn).
				self::process_batch( $job_id, $batch );
			}
		}

		return $job_id;
	}

	public static function process_batch( string $job_id, array $product_ids ) {
		$state = get_transient( $job_id );
		if ( ! $state ) {
			return;
		}

		$state['status'] = 'processing';

		foreach ( $product_ids as $id ) {
			$result = UPI_Product_Creator::create_draft( (int) $id );
			if ( is_wp_error( $result ) ) {
				$state['failed'][ (int) $id ] = $result->get_error_message();
			} else {
				$state['created'][] = $result;
			}
			$state['processed']++;
		}

		if ( $state['processed'] >= $state['total'] ) {
			$state['status'] = 'done';
		}

		set_transient( $job_id, $state, self::JOB_TTL_SECONDS );
	}

	public static function get_status( string $job_id ) {
		$state = get_transient( $job_id );
		if ( ! $state ) {
			return new WP_Error( 'job_not_found', 'Không tìm thấy job hoặc đã hết hạn.', array( 'status' => 404 ) );
		}
		return $state;
	}
}
