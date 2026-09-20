<?php
/**
 * Plugin Folder Installer — Uninstall
 *
 * Fired when the plugin is deleted (not deactivated) via the WordPress admin.
 * Removes all plugin data: custom table, options, transients, chunk directory,
 * and backup directory.
 *
 * @package WPFD
 */

// Safety: Only run when called by WordPress uninstall mechanism.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

/* 1. Drop plugin installer log tables. */
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpfd_plugin_installer_logs" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpfd_deployments" );

/* 2. Resolve backup directories before deleting options. */
$settings   = get_option( 'wpfd_settings', [] );
$backup_dir = is_array( $settings ) && ! empty( $settings['backup_dir'] )
    ? (string) $settings['backup_dir']
    : WP_CONTENT_DIR . '/wpfd-backups/';

$backup_dirs = array_unique(
    array_filter(
        [
            trailingslashit( wp_normalize_path( untrailingslashit( $backup_dir ) ) ),
            trailingslashit( wp_normalize_path( WP_CONTENT_DIR . '/wpfd-backups' ) ),
        ]
    )
);

delete_option( 'wpfd_db_version' );
delete_option( 'wpfd_version' );
delete_option( 'wpfd_settings' );
delete_option( 'wpfd_backup_dir_last_test' );

/* 3. Delete download-token and backup-listing transients. */
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like( '_transient_wpfd_dl_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_wpfd_dl_' ) . '%'
) );
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like( '_transient_wpfd_mdl_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_wpfd_mdl_' ) . '%'
) );
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like( '_transient_wpfd_bl_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_wpfd_bl_' ) . '%'
) );
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like( '_transient_wpfd_backup_lock_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_wpfd_backup_lock_' ) . '%'
) );

/* 4. Remove chunk upload directory. */
$chunk_dir = wp_upload_dir()['basedir'] . '/wpfd-chunks/';
if ( is_dir( $chunk_dir ) ) {
    $it = new RecursiveDirectoryIterator( $chunk_dir, RecursiveDirectoryIterator::SKIP_DOTS );
    $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $file ) {
        if ( $file->isDir() ) {
            @rmdir( $file->getRealPath() );
        } else {
            @unlink( $file->getRealPath() );
        }
    }
    @rmdir( $chunk_dir );
}

/* 5. Remove configured backup directories (only on full uninstall — data is expendable). */
foreach ( $backup_dirs as $dir ) {
    if ( ! is_dir( $dir ) ) {
        continue;
    }

    $it = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
    $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $file ) {
        if ( $file->isDir() ) {
            @rmdir( $file->getRealPath() );
        } else {
            @unlink( $file->getRealPath() );
        }
    }
    @rmdir( $dir );
}

/* 6. Clear any remaining cron events (safety net — deactivation should have cleared these). */
wp_clear_scheduled_hook( 'wpfd_cleanup_download_tokens' );
