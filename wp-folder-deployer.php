<?php
/**
 * Plugin Name: Plugin Folder Installer
 * Plugin URI:  https://xoseller.com
 * Description: Install one or many WordPress plugins from local folders through a single folder-based workflow.
 *              Validates folder structures, builds temporary ZIP packages when needed, installs safely, logs results,
 *              supports rollback backups, and activates installed plugins automatically.
 * Version:     6.7.1
 * Author:      XO Xoseller Technologies Limited
 * Author URI:  https://xoseller.com
 * License:     GPL-2.0+
 * Requires at least: 5.3
 * Requires PHP:      8.0
 * Text Domain: wpfd-plugin-folder-installer
 */

defined( 'ABSPATH' ) || exit;

define( 'WPFD_VERSION',    '6.7.1' );
define( 'WPFD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPFD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPFD_DEPLOY_DIR', WP_CONTENT_DIR . '/plugins/' );

/**
 * DOWNLOAD ENGINE NOTES
 * ---------------------
 * Folder downloads are packaged as a ZIP of ANY size. Small archives stream
 * straight through PHP; large archives (above WPFD_DYNAMIC_BODY_LIMIT) are built
 * to a temp file and served as a STATIC file so they are NOT truncated by a
 * server "dynamic response body" cap (e.g. LiteSpeed's "Max Dynamic Response
 * Body Size"). The static fallback needs no configuration — it places the ZIP in
 * uploads/wpfd-downloads/<random>/ and redirects to it, then auto-cleans.
 *
 * Optional tuning:
 *
 *   // Size (bytes) above which a directory ZIP is served statically instead of
 *   // streamed. Default 256 MB. Lower it if your host caps dynamic bodies small.
 *   define( 'WPFD_DYNAMIC_BODY_LIMIT', 256 * 1024 * 1024 );
 *
 *   // Optional: hand large downloads to the web server's native sendfile engine
 *   // (fastest, keeps the temp file out of the web root). Requires server config.
 *   define( 'WPFD_XSENDFILE', 'litespeed' ); // requires an `internal` context
 *   define( 'WPFD_XSENDFILE', 'apache'    ); // requires mod_xsendfile + XSendFile On
 *   define( 'WPFD_XSENDFILE', 'nginx'     ); // requires an `internal` location
 *   // For nginx/litespeed, map the filesystem path to the internal location:
 *   // define( 'WPFD_XSENDFILE_NGINX_PREFIX', '/protected-downloads' );
 */

require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-security.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-filesystem.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-rollback.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-browser.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-nuker.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-zip-stream.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-downloader.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-rest-api.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-plugin-folder-installer.php';
require_once WPFD_PLUGIN_DIR . 'includes/class-wpfd-admin.php';

function wpfd_maybe_run_upgrades(): void {
    $installed_version = (string) get_option( 'wpfd_version', '0.0.0' );
    if ( version_compare( $installed_version, WPFD_VERSION, '>=' ) ) {
        return;
    }

    global $wpdb;

    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpfd_deployments" );
    delete_option( 'wpfd_db_version' );
    update_option( 'wpfd_version', WPFD_VERSION );
}

function wpfd_init(): void {
    wpfd_maybe_run_upgrades();

    if ( ! class_exists( 'WPFD_Admin' ) || ! class_exists( 'WPFD_REST_API' ) ) {
        return;
    }
    $admin = new WPFD_Admin();
    $rest  = new WPFD_REST_API();
    add_action( 'admin_menu',            [ $admin, 'register_menu' ] );
    add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_assets' ] );
    add_action( 'rest_api_init',         [ $rest,  'register_routes' ] );

    /* Schedule token cleanup cron if not already scheduled.
     * Restricted to is_admin() to avoid a wp_options read on every public page load.
     * The activation hook handles the initial registration; this guard recovers if
     * the cron is cleared externally (e.g. via WP-CLI or a cron management plugin). */
    if ( is_admin() && ! wp_next_scheduled( 'wpfd_cleanup_download_tokens' ) ) {
        wp_schedule_event( time(), 'hourly', 'wpfd_cleanup_download_tokens' );
    }
}
add_action( 'plugins_loaded', 'wpfd_init' );

function wpfd_ajax_clean_output_buffers(): void {
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }
}

function wpfd_ajax_refresh_nonces(): void {
    wpfd_ajax_clean_output_buffers();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Authentication required.' ], 401 );
    }

    if ( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
    }

    wp_send_json_success( [
        'wp_rest_nonce' => wp_create_nonce( 'wp_rest' ),
        'wpfd_nonce'    => WPFD_Security::create_nonce(),
    ] );
}

function wpfd_ajax_refresh_wpfd_nonce(): void {
    wpfd_ajax_clean_output_buffers();

    if ( ! is_user_logged_in() ) {
        status_header( 401 );
        wp_die( '0' );
    }

    if ( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'manage_options' ) ) {
        status_header( 403 );
        wp_die( '0' );
    }

    nocache_headers();
    header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
    echo WPFD_Security::create_nonce();
    wp_die( '', '', [ 'response' => null ] );
}

function wpfd_ajax_process_install(): void {
    wpfd_ajax_clean_output_buffers();

    if ( ! is_user_logged_in() ) {
        status_header( 401 );
        wp_send_json( [
            'success' => false,
            'code'    => 'wpfd_auth_required',
            'message' => 'Authentication required.',
            'results' => [],
        ] );
    }

    if ( ! current_user_can( 'install_plugins' ) ) {
        status_header( 403 );
        wp_send_json( [
            'success' => false,
            'code'    => 'wpfd_forbidden',
            'message' => 'Insufficient permissions.',
            'results' => [],
        ] );
    }

    $wpfd_nonce = isset( $_POST['wpfd_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wpfd_nonce'] ) ) : '';
    if ( $wpfd_nonce === '' || ! wp_verify_nonce( $wpfd_nonce, WPFD_Security::NONCE_ACTION ) ) {
        status_header( 403 );
        wp_send_json( [
            'success' => false,
            'code'    => 'wpfd_invalid_nonce',
            'message' => 'Security check failed.',
            'results' => [],
        ] );
    }

    WPFD_Plugin_Folder_Installer::bootstrap();

    if ( ! empty( $_FILES['package'] ) ) {
        $result = WPFD_Plugin_Folder_Installer::process_uploaded_package(
            $_FILES,
            sanitize_text_field( (string) ( $_POST['folder_name'] ?? '' ) ),
            get_current_user_id()
        );
    } else {
        $relative_paths_raw = isset( $_POST['relative_paths'] ) ? wp_unslash( $_POST['relative_paths'] ) : '';
        $relative_paths     = json_decode( is_string( $relative_paths_raw ) ? $relative_paths_raw : '', true );

        if ( ! is_array( $relative_paths ) || empty( $relative_paths ) ) {
            status_header( 400 );
            wp_send_json( [
                'success' => false,
                'code'    => 'wpfd_installer_missing_manifest',
                'message' => 'A relative path manifest is required for folder installation.',
                'results' => [],
            ] );
        }

        $relative_paths = array_map(
            static fn( $path ): string => is_scalar( $path ) ? (string) $path : '',
            array_values( $relative_paths )
        );

        $result = WPFD_Plugin_Folder_Installer::process_uploaded_payload(
            $_FILES,
            $relative_paths,
            get_current_user_id()
        );
    }

    $status = empty( $result['results'] ) ? 400 : 200;
    status_header( $status );
    wp_send_json( $result );
}

add_action( 'wp_ajax_wpfd_refresh_nonces', 'wpfd_ajax_refresh_nonces' );
add_action( 'wp_ajax_wpfd_refresh_wpfd_nonce', 'wpfd_ajax_refresh_wpfd_nonce' );
add_action( 'wp_ajax_wpfd_process_install', 'wpfd_ajax_process_install' );

/* Clean up expired download tokens from the options table.
 * Cleans up expired download-token transients AND orphaned chunk directories. */
add_action( 'wpfd_cleanup_download_tokens', function (): void {
    global $wpdb;
    $prefix = WPFD_Downloader::TOKEN_PREFIX;

    // Step 1: Delete timeout rows that have expired.
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
        $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%',
        time()
    ) );

    // Step 2: Delete orphaned data rows whose timeout row no longer exists.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( $wpdb->prepare(
        "DELETE d FROM {$wpdb->options} d
         LEFT JOIN {$wpdb->options} t
             ON t.option_name = REPLACE(d.option_name, '_transient_', '_transient_timeout_')
         WHERE d.option_name LIKE %s
           AND t.option_name IS NULL",
        $wpdb->esc_like( '_transient_' . $prefix ) . '%'
    ) );

    // Step 3: Remove orphaned chunk directories older than 2 hours.
    $chunk_base = wp_upload_dir()['basedir'] . '/wpfd-chunks/';
    if ( is_dir( $chunk_base ) ) {
        $cutoff = time() - 7200; // 2 hours
        foreach ( glob( $chunk_base . '*', GLOB_ONLYDIR ) as $session_dir ) {
            if ( @filemtime( $session_dir ) < $cutoff ) {
                WPFD_Filesystem::delete_dir( $session_dir );
            }
        }
    }
} );

register_activation_hook( __FILE__, function (): void {
    wpfd_maybe_run_upgrades();
    update_option( 'wpfd_version', WPFD_VERSION );

    /* Ensure cron is scheduled on activation (including per-site activation on multisite). */
    if ( ! wp_next_scheduled( 'wpfd_cleanup_download_tokens' ) ) {
        wp_schedule_event( time(), 'hourly', 'wpfd_cleanup_download_tokens' );
    }

    $chunk_dir = wp_upload_dir()['basedir'] . '/wpfd-chunks/';
    if ( ! is_dir( $chunk_dir ) ) {
        wp_mkdir_p( $chunk_dir );

        // Use WP_Filesystem for writing protection files.
        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            if ( ! function_exists( 'WP_Filesystem' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            WP_Filesystem( false, false, true );
        }
        if ( $wp_filesystem ) {
            $wp_filesystem->put_contents( $chunk_dir . '.htaccess', 'Deny from all', FS_CHMOD_FILE );
            $wp_filesystem->put_contents( $chunk_dir . 'index.php', '<?php // Silence is golden.', FS_CHMOD_FILE );
        }
    }

    if ( class_exists( 'WPFD_Plugin_Folder_Installer' ) ) {
        WPFD_Plugin_Folder_Installer::bootstrap();
    }
} );

register_deactivation_hook( __FILE__, function (): void {
    wp_clear_scheduled_hook( 'wpfd_cleanup_download_tokens' );
} );
