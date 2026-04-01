<?php
/**
 * WPFD_Downloader — Secure File Download Engine
 *
 * Generates time-limited, single-use download tokens and streams files
 * (or on-the-fly ZIPs for directories) through PHP so absolute server
 * paths are never exposed to the browser.
 *
 * @package    WP_Folder_Deployer
 * @since      6.0.0
 */

defined( 'ABSPATH' ) || exit;

class WPFD_Downloader {

    /** Transient prefix for download tokens */
    const TOKEN_PREFIX = 'wpfd_dl_';

    /** Token lifetime in seconds (5 minutes) */
    const TOKEN_TTL = 300;

    /**
     * Generate a single-use download token for a file or directory.
     *
     * @param string $abs_path Validated absolute path.
     * @return string 64-char hex token.
     */
    public static function create_token( string $abs_path ): string {
        $token = bin2hex( random_bytes( 32 ) );
        $data  = [
            'path'    => $abs_path,
            'user_id' => get_current_user_id(),
            'created' => time(),
        ];
        set_transient( self::TOKEN_PREFIX . hash( 'sha256', $token ), $data, self::TOKEN_TTL );
        return $token;
    }

    /**
     * Generate a multi-file download token (ZIP bundle).
     *
     * @param array $abs_paths Array of validated absolute paths.
     * @return string 64-char hex token.
     */
    public static function create_multi_token( array $abs_paths ): string {
        $token = bin2hex( random_bytes( 32 ) );
        $data  = [
            'paths'   => $abs_paths,
            'user_id' => get_current_user_id(),
            'created' => time(),
        ];
        set_transient( self::TOKEN_PREFIX . hash( 'sha256', $token ), $data, self::TOKEN_TTL );
        return $token;
    }

    /**
     * Validate a token and return the stored data. Consumes (deletes) the token.
     *
     * @param string $token Raw hex token from the request.
     * @return array|false Token data or false if invalid/expired.
     */
    public static function consume_token( string $token ) {
        $key  = self::TOKEN_PREFIX . hash( 'sha256', $token );
        $data = get_transient( $key );
        if ( ! $data ) {
            return false;
        }
        // Single-use: delete immediately
        delete_transient( $key );

        // Verify token hasn't exceeded TTL (belt-and-suspenders with transient expiry)
        if ( isset( $data['created'] ) && ( time() - $data['created'] ) > self::TOKEN_TTL ) {
            return false;
        }

        return $data;
    }

    /**
     * Stream a single file to the browser.
     *
     * @param string $abs_path Absolute path to the file.
     */
    public static function stream_file( string $abs_path ): void {
        if ( ! is_file( $abs_path ) || ! is_readable( $abs_path ) ) {
            wp_die( 'File not found or not readable.', 'Download Error', [ 'response' => 404 ] );
        }

        $filename = basename( $abs_path );
        $size     = filesize( $abs_path );

        // Clean any output buffers
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        header( 'Content-Length: ' . $size );
        header( 'X-Content-Type-Options: nosniff' );

        readfile( $abs_path );
        exit;
    }

    /**
     * Stream a directory as a ZIP archive.
     * Requires the ZipArchive extension.
     *
     * @param string $abs_path Absolute path to the directory.
     */
    public static function stream_dir_zip( string $abs_path ): void {
        if ( ! is_dir( $abs_path ) || ! is_readable( $abs_path ) ) {
            wp_die( 'Directory not found or not readable.', 'Download Error', [ 'response' => 404 ] );
        }

        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_die( 'ZipArchive extension is not available on this server.', 'Download Error', [ 'response' => 500 ] );
        }

        $dir_name = basename( $abs_path );
        $tmp_file = wp_tempnam( 'wpfd_dl_' );

        $zip = new ZipArchive();
        if ( $zip->open( $tmp_file, ZipArchive::OVERWRITE ) !== true ) {
            @unlink( $tmp_file );
            wp_die( 'Failed to create temporary ZIP file.', 'Download Error', [ 'response' => 500 ] );
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $abs_path, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $item ) {
                $relative = $dir_name . '/' . $iterator->getSubPathname();
                if ( $item->isDir() ) {
                    $zip->addEmptyDir( $relative );
                } else {
                    $zip->addFile( $item->getRealPath(), $relative );
                }
            }

            $zip->close();
        } catch ( \Exception $e ) {
            @unlink( $tmp_file );
            wp_die( 'ZIP creation failed: ' . esc_html( $e->getMessage() ), 'Download Error', [ 'response' => 500 ] );
        }

        // Stream the ZIP
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        $zip_size = filesize( $tmp_file );
        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $dir_name ) . '.zip"' );
        header( 'Content-Length: ' . $zip_size );
        header( 'X-Content-Type-Options: nosniff' );

        readfile( $tmp_file );
        @unlink( $tmp_file );
        exit;
    }

    /**
     * Stream multiple files/dirs as a single ZIP.
     *
     * @param array $abs_paths Array of absolute paths.
     */
    public static function stream_multi_zip( array $abs_paths ): void {
        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_die( 'ZipArchive extension is not available on this server.', 'Download Error', [ 'response' => 500 ] );
        }

        $tmp_file = wp_tempnam( 'wpfd_multi_dl_' );
        $zip      = new ZipArchive();
        if ( $zip->open( $tmp_file, ZipArchive::OVERWRITE ) !== true ) {
            wp_die( 'Failed to create temporary ZIP file.', 'Download Error', [ 'response' => 500 ] );
        }

        foreach ( $abs_paths as $abs_path ) {
            if ( ! file_exists( $abs_path ) || ! is_readable( $abs_path ) ) {
                continue;
            }

            if ( is_file( $abs_path ) ) {
                $zip->addFile( $abs_path, basename( $abs_path ) );
            } elseif ( is_dir( $abs_path ) ) {
                $dir_name = basename( $abs_path );
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $abs_path, RecursiveDirectoryIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ( $iterator as $item ) {
                    $relative = $dir_name . '/' . $iterator->getSubPathname();
                    if ( $item->isDir() ) {
                        $zip->addEmptyDir( $relative );
                    } else {
                        $zip->addFile( $item->getRealPath(), $relative );
                    }
                }
            }
        }

        $zip->close();

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        $zip_size = filesize( $tmp_file );
        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="wpfd-download-' . gmdate( 'Y-m-d_H-i-s' ) . '.zip"' );
        header( 'Content-Length: ' . $zip_size );
        header( 'X-Content-Type-Options: nosniff' );

        readfile( $tmp_file );
        @unlink( $tmp_file );
        exit;
    }
}
