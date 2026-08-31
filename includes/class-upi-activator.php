<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UPI_Activator {

	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$templates_table = $wpdb->prefix . 'upi_templates';
		$imports_table   = $wpdb->prefix . 'upi_imports';
		$products_table  = $wpdb->prefix . 'upi_products';
		$listings_table  = $wpdb->prefix . 'upi_listings';
		$logs_table      = $wpdb->prefix . 'upi_logs';
		$tokens_table    = $wpdb->prefix . 'upi_tokens';
		$publish_queue_table = $wpdb->prefix . 'upi_publish_queue';

		$sql = "CREATE TABLE {$templates_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			category_id BIGINT UNSIGNED NULL,
			category_ids_json LONGTEXT NULL,
			tags TEXT NULL,
			regular_price DECIMAL(10,2) NULL,
			sale_price DECIMAL(10,2) NULL,
			shipping_class VARCHAR(191) NULL,
			shipping_class_id BIGINT UNSIGNED NULL,
			brand VARCHAR(191) NULL,
			description LONGTEXT NULL,
			short_description TEXT NULL,
			seo_title VARCHAR(191) NULL,
			seo_description TEXT NULL,
			sku_prefix VARCHAR(50) NULL,
			gallery_images_json LONGTEXT NULL,
			meta_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};

		CREATE TABLE {$imports_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(20) NOT NULL,
			source_id VARCHAR(191) NOT NULL,
			source_sku VARCHAR(191) NULL,
			source_url TEXT NULL,
			title TEXT NULL,
			description LONGTEXT NULL,
			price DECIMAL(10,2) NULL,
			currency VARCHAR(10) NULL,
			seller_name VARCHAR(191) NULL,
			images_json LONGTEXT NULL,
			raw_data_json LONGTEXT NULL,
			crawled_at DATETIME NULL,
			imported_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_source_id (source, source_id)
		) {$charset_collate};

		CREATE TABLE {$products_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			import_id BIGINT UNSIGNED NOT NULL,
			classification VARCHAR(50) NULL,
			title TEXT NULL,
			edited_title TEXT NULL,
			description LONGTEXT NULL,
			edited_description LONGTEXT NULL,
			short_description TEXT NULL,
			images_json LONGTEXT NULL,
			regular_price DECIMAL(10,2) NULL,
			sale_price DECIMAL(10,2) NULL,
			category_id BIGINT UNSIGNED NULL,
			extra_category_ids_json LONGTEXT NULL,
			tags_json LONGTEXT NULL,
			attributes_json LONGTEXT NULL,
			template_id BIGINT UNSIGNED NULL,
			overrides_json LONGTEXT NULL,
			sku VARCHAR(100) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'crawled',
			wc_product_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY import_id (import_id),
			KEY status (status),
			KEY classification (classification),
			KEY template_id (template_id)
		) {$charset_collate};

		CREATE TABLE {$listings_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			marketplace VARCHAR(20) NOT NULL,
			external_listing_id VARCHAR(191) NULL,
			external_url TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			sync_status VARCHAR(20) NOT NULL DEFAULT 'out_of_sync',
			last_synced_at DATETIME NULL,
			last_error TEXT NULL,
			marketplace_metadata_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY marketplace (marketplace)
		) {$charset_collate};

		CREATE TABLE {$logs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			source VARCHAR(20) NULL,
			import_id BIGINT UNSIGNED NULL,
			message TEXT NOT NULL,
			context_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};

		CREATE TABLE {$tokens_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			token_hash VARCHAR(64) NOT NULL,
			label VARCHAR(191) NULL,
			created_at DATETIME NOT NULL,
			last_used_at DATETIME NULL,
			revoked_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash)
		) {$charset_collate};

		CREATE TABLE {$publish_queue_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id VARCHAR(40) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			scheduled_at DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			published_at DATETIME NULL,
			error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY status (status),
			KEY scheduled_at (scheduled_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'upi_db_version', '7' );
	}
}
