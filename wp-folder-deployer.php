<?php
/**
 * Plugin Name: WP Folder Deployer
 * Plugin URI:  https://xoseller.com
 * Description: Deploy one or many WordPress plugins from local folders — no zipping required.
 *              Multi-target queues, parallel workers, delta diff, ZIP mode, chunked large files, instant rollback,
 *              token-based downloads, bulk delete, browser search/sort/keyboard nav, settings panel.
 * Version:     6.1.0
 * Author:      XO Xoseller Technologies Limited
 * Author URI:  https://xoseller.com
 * License:     GPL-2.0+
 * Text Domain: wp-folder-deployer
 */

defined( 'ABSPATH' ) || exit;

define( 'WPFD_VERSION',    '6.1.0' );
define( 'WPFD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPFD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPFD_DEPLOY_DIR', WP_CONTENT_DIR . '/plugins/' );

require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-security.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-filesystem.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-deployer.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-rollback.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-browser.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-nuker.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-downloader.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-rest-api.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-admin.php';

function wpfd_init(): void {
    if ( ! class_exists( 'WPFD_Admin' ) || ! class_exists( 'WPFD_REST_API' ) ) {
        return;
    }
    $admin = new WPFD_Admin();
    $rest  = new WPFD_REST_API();
    add_action( 'admin_menu',            [ $admin, 'register_menu' ] );
    add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_assets' ] );
    add_action( 'rest_api_init',         [ $rest,  'register_routes' ] );

    /* Schedule token cleanup cron if not already scheduled */
    if ( ! wp_next_scheduled( 'wpfd_cleanup_download_tokens' ) ) {
        wp_schedule_event( time(), 'hourly', 'wpfd_cleanup_download_tokens' );
    }
}
add_action( 'plugins_loaded', 'wpfd_init' );

/* Clean up expired download tokens from the options table */
add_action( 'wpfd_cleanup_download_tokens', function (): void {
    global $wpdb;
    $prefix = WPFD_Downloader::TOKEN_PREFIX;
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
        $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%',
        time()
    ) );
} );

register_activation_hook( __FILE__, function (): void {
    global $wpdb;
    $table   = $wpdb->prefix . 'wpfd_deployments';
    $charset = $wpdb->get_charset_collate();
    $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        plugin_slug  VARCHAR(200)    NOT NULL,
        version      VARCHAR(50)     DEFAULT '',
        deploy_time  DATETIME        NOT NULL,
        file_count   INT UNSIGNED    DEFAULT 0,
        backup_path  TEXT            DEFAULT '',
        status       VARCHAR(20)     DEFAULT 'success',
        deploy_mode  VARCHAR(20)     DEFAULT 'batch',
        skipped      INT UNSIGNED    DEFAULT 0,
        elapsed_ms   INT UNSIGNED    DEFAULT 0,
        deployed_by  BIGINT UNSIGNED DEFAULT 0,
        notes        TEXT            DEFAULT '',
        PRIMARY KEY (id),
        KEY plugin_slug (plugin_slug),
        KEY deploy_time (deploy_time)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'wpfd_db_version', '6.0' );
    update_option( 'wpfd_version', WPFD_VERSION );

    $chunk_dir = wp_upload_dir()['basedir'] . '/wpfd-chunks/';
    if ( ! is_dir( $chunk_dir ) ) {
        wp_mkdir_p( $chunk_dir );
        file_put_contents( $chunk_dir . '.htaccess', 'Deny from all' );
        file_put_contents( $chunk_dir . 'index.php', '<?php // Silence is golden.' );
    }
} );

register_deactivation_hook( __FILE__, function (): void {
    wp_clear_scheduled_hook( 'wpfd_cleanup_download_tokens' );
} );
