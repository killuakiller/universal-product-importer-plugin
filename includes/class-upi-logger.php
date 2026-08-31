<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPI_Logger {

	public static function log( string $level, string $message, ?string $source = null, ?int $import_id = null, array $context = array() ) {
		global $wpdb;
		$table = UPI_DB::logs_table();

		$result = $wpdb->insert(
			$table,
			array(
				'level'        => $level,
				'source'       => $source,
				'import_id'    => $import_id,
				'message'      => $message,
				'context_json' => wp_json_encode( $context ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			error_log( sprintf( '[Universal Product Importer] %s: %s', $level, $message ) );
		}
	}

	public static function info( string $message, ?string $source = null, ?int $import_id = null, array $context = array() ) {
		self::log( 'info', $message, $source, $import_id, $context );
	}
	public static function warning( string $message, ?string $source = null, ?int $import_id = null, array $context = array() ) {
		self::log( 'warning', $message, $source, $import_id, $context );
	}
	public static function error( string $message, ?string $source = null, ?int $import_id = null, array $context = array() ) {
		self::log( 'error', $message, $source, $import_id, $context );
	}

	public static function get_recent( int $limit = 100 ) {
		global $wpdb;
		$table = UPI_DB::logs_table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit ) );
	}

	/** Danh sách log có phân trang + lọc theo level — dùng cho trang Logs (wp-admin). */
	public static function get_list( string $level_filter = '', int $per_page = 50, int $page = 1 ): array {
		global $wpdb;
		$table = UPI_DB::logs_table();

		$allowed_levels = array( 'info', 'warning', 'error' );
		$where          = '';
		$args           = array();
		if ( $level_filter && in_array( $level_filter, $allowed_levels, true ) ) {
			$where  = 'WHERE level = %s';
			$args[] = $level_filter;
		}

		$total = $args
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $args ) )
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$offset   = max( 0, ( $page - 1 ) * $per_page );
		$sql_args = array_merge( $args, array( $per_page, $offset ) );
		$rows     = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", $sql_args )
		);

		return array( 'rows' => $rows, 'total' => $total );
	}

	/** Đếm nhanh theo level — dùng cho các tab bộ lọc trên trang Logs. */
	public static function counts_by_level(): array {
		global $wpdb;
		$table = UPI_DB::logs_table();
		$rows  = $wpdb->get_results( "SELECT level, COUNT(*) as c FROM {$table} GROUP BY level" );
		$out   = array( 'info' => 0, 'warning' => 0, 'error' => 0 );
		foreach ( $rows as $r ) {
			if ( isset( $out[ $r->level ] ) ) {
				$out[ $r->level ] = (int) $r->c;
			}
		}
		return $out;
	}
}
