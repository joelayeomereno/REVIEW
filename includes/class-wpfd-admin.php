<?php
defined( 'ABSPATH' ) || exit;

class WPFD_Admin {

    const PAGE_SLUG = 'wp-folder-deployer';

    public function register_menu(): void {
        add_menu_page(
            'WP Folder Deployer',
            'Folder Deployer',
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ],
            'dashicons-upload',
            75
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpfd-admin',
            WPFD_PLUGIN_URL . 'admin/css/deployer.css',
            [],
            WPFD_VERSION
        );

        wp_enqueue_script(
            'wpfd-admin',
            WPFD_PLUGIN_URL . 'admin/js/deployer.js',
            [],
            WPFD_VERSION,
            true
        );

        $has_zip     = class_exists( 'ZipArchive' );
        $max_upload  = self::parse_size( ini_get( 'upload_max_filesize' ) );
        $max_post    = self::parse_size( ini_get( 'post_max_size' ) );
        $max_server  = min( $max_upload, $max_post );

        wp_localize_script( 'wpfd-admin', 'WPFD', [
            'restUrl'        => rest_url( 'wpfd/v1' ),
            'nonce'          => WPFD_Security::create_nonce(),
            'wpNonce'        => wp_create_nonce( 'wp_rest' ),
            'version'        => WPFD_VERSION,
            'hasZip'         => $has_zip,
            'serverMaxUpload'=> $max_server,
            'maxFileUploads' => (int) ini_get( 'max_file_uploads' ),
            'pluginsUrl'     => admin_url( 'plugins.php' ),
            'jsZipUrl'       => 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
        ] );
    }

    public function render_page(): void {
        include WPFD_PLUGIN_DIR . 'admin/views/main.php';
    }

    private static function parse_size( string $size ): int {
        $size = trim( $size );
        if ( empty( $size ) ) return 64 * 1024 * 1024;
        $last = strtolower( $size[ strlen( $size ) - 1 ] );
        $val  = (int) $size;
        switch ( $last ) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }
        return $val;
    }
}
