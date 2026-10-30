<?php
/**
 * Uninstall: always removes credentials, caches and scheduled actions; removes settings and
 * Varizet order metadata only when «حذف داده‌ها هنگام حذف افزونه» is enabled (confirmation).
 *
 * @package Varizet\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans one site.
 */
function varizet_wc_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'woocommerce_varizet_settings', array() );
	$purge    = is_array( $settings ) && isset( $settings['delete_data'] ) && 'yes' === $settings['delete_data'];

	// Secrets never outlive the plugin.
	delete_option( 'varizet_credentials' );
	delete_option( 'varizet_log_buffer' );
	delete_option( 'varizet_webhook_stats' );
	delete_option( 'varizet_sync_state' );
	delete_transient( 'varizet_health_api' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_varizet\\_%' OR option_name LIKE '\\_transient\\_timeout\\_varizet\\_%'" );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'varizet_sync_open_orders' );
		as_unschedule_all_actions( 'varizet_sync_order' );
	}

	if ( ! $purge ) {
		return;
	}

	delete_option( 'woocommerce_varizet_settings' );
	delete_option( 'varizet_install_id' );
	delete_option( 'varizet_version' );

	// Order metadata in both storages (HPOS table may not exist).
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_varizet\\_%'" );
	$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta ) ) === $hpos_meta ) {
		$wpdb->query( "DELETE FROM {$hpos_meta} WHERE meta_key LIKE '\\_varizet\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

if ( is_multisite() ) {
	$varizet_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $varizet_sites as $varizet_site_id ) {
		switch_to_blog( (int) $varizet_site_id );
		varizet_wc_uninstall_site();
		restore_current_blog();
	}
} else {
	varizet_wc_uninstall_site();
}
