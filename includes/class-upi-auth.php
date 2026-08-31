<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pairing + bearer token authentication. Không bao giờ gửi username/password
 * WordPress hay API secret nào cho extension. Token có thể revoke bất kỳ
 * lúc nào từ Settings.
 */
class UPI_Auth {

	const PAIRING_TRANSIENT_PREFIX = 'upi_pairing_';
	const PAIRING_TTL              = 10 * MINUTE_IN_SECONDS;

	public static function generate_pairing_code( int $user_id ): string {
		$code = strtoupper( substr( wp_generate_password( 12, false, false ), 0, 8 ) );
		set_transient( self::PAIRING_TRANSIENT_PREFIX . $code, $user_id, self::PAIRING_TTL );
		return $code;
	}

	public static function redeem_pairing_code( string $code, string $label = '' ) {
		$code      = strtoupper( trim( $code ) );
		$transient = self::PAIRING_TRANSIENT_PREFIX . $code;
		$user_id   = get_transient( $transient );

		if ( ! $user_id ) {
			return new WP_Error( 'invalid_pairing_code', 'Pairing code không hợp lệ hoặc đã hết hạn.', array( 'status' => 400 ) );
		}
		delete_transient( $transient ); // dùng một lần

		if ( ! user_can( $user_id, 'manage_woocommerce' ) ) {
			return new WP_Error( 'insufficient_permission', 'User không có quyền quản lý WooCommerce.', array( 'status' => 403 ) );
		}

		$raw_token = wp_generate_password( 64, false, false ) . bin2hex( random_bytes( 16 ) );
		$hash      = hash( 'sha256', $raw_token );

		global $wpdb;
		$wpdb->insert(
			UPI_DB::tokens_table(),
			array(
				'user_id'    => $user_id,
				'token_hash' => $hash,
				'label'      => $label ? sanitize_text_field( $label ) : 'Chrome Extension',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		return $raw_token;
	}

	public static function authenticate_request( WP_REST_Request $request ) {
		$auth_header = $request->get_header( 'authorization' );
		if ( ! $auth_header || stripos( $auth_header, 'Bearer ' ) !== 0 ) {
			return new WP_Error( 'missing_token', 'Thiếu bearer token.', array( 'status' => 401 ) );
		}

		$token = trim( substr( $auth_header, 7 ) );
		$hash  = hash( 'sha256', $token );

		global $wpdb;
		$table = UPI_DB::tokens_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s AND revoked_at IS NULL", $hash ) );

		if ( ! $row ) {
			return new WP_Error( 'invalid_token', 'Token không hợp lệ hoặc đã bị thu hồi.', array( 'status' => 401 ) );
		}

		$user = get_user_by( 'id', $row->user_id );
		if ( ! $user || ! user_can( $user, 'manage_woocommerce' ) ) {
			return new WP_Error( 'insufficient_permission', 'User gắn với token không đủ quyền.', array( 'status' => 403 ) );
		}

		$wpdb->update(
			$table,
			array( 'last_used_at' => current_time( 'mysql' ) ),
			array( 'id' => $row->id ),
			array( '%s' ),
			array( '%d' )
		);

		wp_set_current_user( $user->ID );
		return $user;
	}

	public static function list_tokens() {
		global $wpdb;
		$table = UPI_DB::tokens_table();
		return $wpdb->get_results( "SELECT id, label, created_at, last_used_at, revoked_at FROM {$table} ORDER BY created_at DESC" );
	}

	public static function revoke_token( int $token_id ) {
		global $wpdb;
		return $wpdb->update(
			UPI_DB::tokens_table(),
			array( 'revoked_at' => current_time( 'mysql' ) ),
			array( 'id' => $token_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
