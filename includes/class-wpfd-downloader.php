<?php
/**
 * WPFD_Downloader — Secure File Download Engine
 *
 * Generates time-limited, single-use download tokens and streams files
 * (or on-the-fly ZIPs for directories) through PHP so absolute server
 * paths are never exposed to the browser.
 *
 * @package    WPFD
 * @since      6.0.0
 */

defined( 'ABSPATH' ) || exit;

class WPFD_Downloader {

    /** Transient prefix for download tokens */
    const TOKEN_PREFIX = 'wpfd_dl_';

    /** Token lifetime in seconds (5 minutes) */
    const TOKEN_TTL = 300;

    /**
     * Request-scoped cache: keyed by transient key, value is token data array or false.
     * Populated by validate_token() so consume_token() can skip a second DB read.
     */
    private static array $token_cache = [];

    /**
     * Generate a single-use download token for a file or directory.
     *
     * @param string               $abs_path Validated absolute path.
     * @param array<string,mixed>  $meta     Optional metadata (download_name, zip_root_name).
     * @return string 64-char hex token.
     */
    public static function create_token( string $abs_path, array $meta = [] ): string {
        $token = bin2hex( random_bytes( 32 ) );
        $data  = array_merge(
            [
                'path'    => $abs_path,
                'user_id' => get_current_user_id(),
                'created' => time(),
                'ip'      => self::get_client_ip(),
            ],
            $meta
        );
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
            'ip'      => self::get_client_ip(),
        ];
        set_transient( self::TOKEN_PREFIX . hash( 'sha256', $token ), $data, self::TOKEN_TTL );
        return $token;
    }

    /**
     * Validate a token without consuming it (read-only check).
     *
     * Used in REST permission_callback to gate access before the handler runs.
     * The handler must still call consume_token() to delete the token.
     * Token data is cached request-locally so consume_token() skips a second DB read.
     *
     * @param string $token Raw hex token from the request.
     * @return bool True if the token exists, is within TTL, and IP matches.
     */
    public static function validate_token( string $token ): bool {
        $key = self::TOKEN_PREFIX . hash( 'sha256', $token );

        // Return from request-scoped cache if already fetched this request.
        if ( array_key_exists( $key, self::$token_cache ) ) {
            return self::$token_cache[ $key ] !== false;
        }

        $data = get_transient( $key );
        if ( ! $data ) {
            self::$token_cache[ $key ] = false;
            return false;
        }
        if ( isset( $data['created'] ) && ( time() - $data['created'] ) > self::TOKEN_TTL ) {
            self::$token_cache[ $key ] = false;
            return false;
        }
        if ( ! empty( $data['ip'] ) && $data['ip'] !== self::get_client_ip() ) {
            self::$token_cache[ $key ] = false;
            return false;
        }
        self::$token_cache[ $key ] = $data;
        return true;
    }

    /**
     * Validate a token and return the stored data WITHOUT consuming it.
     *
     * Range / resume / parallel-segment downloads issue several GETs against the
     * same URL, so the token must survive every request inside its TTL. The
     * transient's own 5-minute expiry is the lifetime bound; IP binding keeps it
     * from being replayed elsewhere.
     *
     * @param string $token Raw hex token from the request.
     * @return array|false Token data or false if invalid/expired/IP-mismatch.
     */
    public static function peek_token( string $token ) {
        $key = self::TOKEN_PREFIX . hash( 'sha256', $token );

        if ( array_key_exists( $key, self::$token_cache ) && self::$token_cache[ $key ] !== false ) {
            $data = self::$token_cache[ $key ];
        } else {
            $data = get_transient( $key );
        }

        if ( ! $data ) {
            return false;
        }
        if ( isset( $data['created'] ) && ( time() - $data['created'] ) > self::TOKEN_TTL ) {
            return false;
        }
        if ( ! empty( $data['ip'] ) && $data['ip'] !== self::get_client_ip() ) {
            return false;
        }

        return $data;
    }

    /**
     * Validate a token and return the stored data. Consumes (deletes) the token.
     *
     * @param string $token Raw hex token from the request.
     * @return array|false Token data or false if invalid/expired.
     */
    public static function consume_token( string $token ) {
        $key = self::TOKEN_PREFIX . hash( 'sha256', $token );

        // Reuse data already fetched by validate_token() in this request.
        if ( array_key_exists( $key, self::$token_cache ) ) {
            $data = self::$token_cache[ $key ];
        } else {
            $data = get_transient( $key );
        }

        if ( ! $data ) {
            return false;
        }
        // Single-use: delete immediately
        delete_transient( $key );
        unset( self::$token_cache[ $key ] ); // clear from request cache too

        // Verify token hasn't exceeded TTL (belt-and-suspenders with transient expiry)
        if ( isset( $data['created'] ) && ( time() - $data['created'] ) > self::TOKEN_TTL ) {
            return false;
        }

        // Verify IP matches the token creator (prevents token theft)
        if ( ! empty( $data['ip'] ) && $data['ip'] !== self::get_client_ip() ) {
            return false;
        }

        return $data;
    }

    /**
     * Get the client IP address in a proxy-aware manner.
     */
    private static function get_client_ip(): string {
        return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    }

    /** Bytes per read/write iteration when streaming through PHP (8 MB). */
    const STREAM_CHUNK = 8 * 1024 * 1024;

    /**
     * Above this archive size (bytes), a directory ZIP is built to a temp file and
     * served as a STATIC file instead of streamed through PHP. Servers like
     * LiteSpeed cap dynamic (PHP) response bodies ("Max Dynamic Response Body
     * Size"), truncating big streamed downloads; static files are exempt. Default
     * 256 MB stays well under common caps. Override with WPFD_DYNAMIC_BODY_LIMIT.
     */
    private static function dynamic_body_limit(): int {
        if ( defined( 'WPFD_DYNAMIC_BODY_LIMIT' ) && (int) WPFD_DYNAMIC_BODY_LIMIT > 0 ) {
            return (int) WPFD_DYNAMIC_BODY_LIMIT;
        }
        return 256 * 1024 * 1024;
    }

    /**
     * Prepare the PHP output pipeline for a byte-exact, high-throughput transfer.
     *
     * Disables compression (which would corrupt Content-Length), tears down every
     * inherited output buffer, removes the execution time limit, and keeps the
     * transfer alive if the client briefly disconnects (so resumes stay clean).
     */
    private static function begin_transfer(): void {
        // zlib compression sits below PHP's buffers and would silently re-encode
        // the body after Content-Length is set — a length mismatch corrupts the file.
        @ini_set( 'zlib.output_compression', 'Off' );
        @ini_set( 'output_buffering', '0' );
        @ini_set( 'implicit_flush', '1' );

        // Drop any buffers WordPress / other plugins left open so nothing is
        // injected into the binary stream.
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        // Large files must not die on max_execution_time mid-stream.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        // Finish the transfer even if the browser momentarily drops the socket.
        ignore_user_abort( true );
    }

    /**
     * Escape a filename for the Content-Disposition header without mangling
     * leading dots (sanitize_file_name() would turn .gitignore into gitignore).
     */
    private static function disposition_name( string $filename ): string {
        return str_replace( [ "\r", "\n", '"', '\\' ], [ '', '', '\\"', '\\\\' ], $filename );
    }

    /**
     * Parse an HTTP Range header into a single [start, end] byte pair.
     *
     * Supports the common single-range forms used by browsers and download
     * managers: "bytes=START-END", "bytes=START-", and "bytes=-SUFFIX".
     * Multi-range requests fall back to a full-body response (still correct).
     *
     * @param string $header Raw Range header value.
     * @param int    $size   Total file size in bytes.
     * @return array{0:int,1:int}|null [start, end] inclusive, or null for a full body.
     */
    private static function parse_range( string $header, int $size ): ?array {
        if ( $size <= 0 || stripos( $header, 'bytes=' ) !== 0 ) {
            return null;
        }

        $spec = substr( $header, 6 );
        // Only honour a single range; commas mean multipart which we decline.
        if ( strpos( $spec, ',' ) !== false ) {
            return null;
        }

        $dash = strpos( $spec, '-' );
        if ( $dash === false ) {
            return null;
        }

        $start_raw = trim( substr( $spec, 0, $dash ) );
        $end_raw   = trim( substr( $spec, $dash + 1 ) );

        if ( $start_raw === '' ) {
            // Suffix range: last N bytes.
            $suffix = (int) $end_raw;
            if ( $suffix <= 0 ) {
                return null;
            }
            $start = max( 0, $size - $suffix );
            $end   = $size - 1;
        } else {
            $start = (int) $start_raw;
            $end   = ( $end_raw === '' ) ? $size - 1 : (int) $end_raw;
        }

        if ( $start > $end || $start >= $size ) {
            return [ -1, -1 ]; // Sentinel: unsatisfiable.
        }

        $end = min( $end, $size - 1 );
        return [ $start, $end ];
    }

    /**
     * Hand a file off to the web server's native sendfile path when available.
     *
     * Apache (mod_xsendfile) and Nginx (internal location + X-Accel-Redirect)
     * stream the file from the kernel with zero PHP I/O — the fastest possible
     * path. Returns true if the offload header was emitted (caller must exit).
     *
     * Disabled unless WPFD_XSENDFILE is defined, because it requires explicit
     * server configuration to work and would otherwise yield an empty download.
     *
     * @param string $abs_path Absolute file path.
     * @param string $type     Content-Type for the response.
     * @param string $safe_name Pre-escaped Content-Disposition filename.
     */
    private static function maybe_offload( string $abs_path, string $type, string $safe_name ): bool {
        if ( ! defined( 'WPFD_XSENDFILE' ) || ! WPFD_XSENDFILE ) {
            return false;
        }

        $mode = is_string( WPFD_XSENDFILE ) ? strtolower( WPFD_XSENDFILE ) : 'apache';

        nocache_headers();
        header( 'Content-Type: ' . $type );
        header( 'Content-Disposition: attachment; filename="' . $safe_name . '"' );
        header( 'X-Content-Type-Options: nosniff' );

        if ( $mode === 'nginx' ) {
            // The path must map to an `internal` Nginx location; configure
            // WPFD_XSENDFILE_NGINX_PREFIX to rewrite the filesystem path.
            $internal = defined( 'WPFD_XSENDFILE_NGINX_PREFIX' )
                ? rtrim( (string) WPFD_XSENDFILE_NGINX_PREFIX, '/' ) . '/' . ltrim( basename( $abs_path ), '/' )
                : $abs_path;
            header( 'X-Accel-Redirect: ' . $internal );
        } elseif ( $mode === 'litespeed' ) {
            // LiteSpeed / OpenLiteSpeed internal redirect. The path must map to an
            // `internal` context; configure WPFD_XSENDFILE_NGINX_PREFIX to rewrite.
            $internal = defined( 'WPFD_XSENDFILE_NGINX_PREFIX' )
                ? rtrim( (string) WPFD_XSENDFILE_NGINX_PREFIX, '/' ) . '/' . ltrim( basename( $abs_path ), '/' )
                : $abs_path;
            header( 'X-LiteSpeed-Location: ' . $internal );
        } else {
            header( 'X-Sendfile: ' . $abs_path );
        }
        return true;
    }

    /**
     * Copy a byte range from a file handle to the output stream in fixed chunks.
     *
     * Constant memory regardless of file size, and byte-for-byte exact — no
     * transformation is applied to the data.
     *
     * @param resource $fh    Open read handle, already seeked to $start.
     * @param int      $start First byte offset (inclusive).
     * @param int      $end   Last byte offset (inclusive).
     */
    private static function copy_range( $fh, int $start, int $end ): void {
        $remaining = $end - $start + 1;
        while ( $remaining > 0 && ! feof( $fh ) ) {
            $read  = ( $remaining > self::STREAM_CHUNK ) ? self::STREAM_CHUNK : $remaining;
            $buf   = fread( $fh, $read );
            if ( $buf === false ) {
                break;
            }
            echo $buf;
            $remaining -= strlen( $buf );
            flush();

            // Stop wasting CPU if the client has gone away for good.
            if ( connection_aborted() ) {
                break;
            }
        }
    }

    /**
     * Stream a single file to the browser with Range / resume support.
     *
     * @param string $abs_path Absolute path to the file.
     */
    public static function stream_file( string $abs_path ): void {
        if ( ! is_file( $abs_path ) || ! is_readable( $abs_path ) ) {
            wp_die( 'File not found or not readable.', 'Download Error', [ 'response' => 404 ] );
        }

        $size      = (int) filesize( $abs_path );
        $safe_name = self::disposition_name( basename( $abs_path ) );

        // Fastest path: let the web server stream the file from the kernel.
        if ( self::maybe_offload( $abs_path, 'application/octet-stream', $safe_name ) ) {
            exit;
        }

        self::begin_transfer();
        self::serve_handle( $abs_path, $size, 'application/octet-stream', $safe_name );
        exit;
    }

    /**
     * Open a file and emit it (full body or a single Range) with the correct
     * status line and headers. Shared by file and ZIP streaming.
     *
     * @param string $abs_path  Absolute path to the readable file.
     * @param int    $size      File size in bytes.
     * @param string $type      Content-Type.
     * @param string $safe_name Pre-escaped Content-Disposition filename.
     */
    private static function serve_handle( string $abs_path, int $size, string $type, string $safe_name ): void {
        $fh = fopen( $abs_path, 'rb' );
        if ( $fh === false ) {
            wp_die( 'File could not be opened.', 'Download Error', [ 'response' => 500 ] );
        }

        // Stable validators let clients verify integrity and safely resume.
        $mtime = (int) @filemtime( $abs_path );
        $etag  = '"' . md5( $abs_path . '|' . $size . '|' . $mtime ) . '"';

        header( 'Content-Type: ' . $type );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Content-Disposition: attachment; filename="' . $safe_name . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Accept-Ranges: bytes' );
        header( 'ETag: ' . $etag );
        if ( $mtime > 0 ) {
            header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
        }
        // Token URLs are single-use; downloads themselves must never be cached.
        header( 'Cache-Control: private, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $range_header = isset( $_SERVER['HTTP_RANGE'] ) ? (string) wp_unslash( $_SERVER['HTTP_RANGE'] ) : '';
        $range        = $range_header !== '' ? self::parse_range( $range_header, $size ) : null;

        if ( is_array( $range ) && $range[0] === -1 ) {
            // Range Not Satisfiable.
            header( 'Content-Range: bytes */' . $size );
            status_header( 416 );
            fclose( $fh );
            return;
        }

        if ( is_array( $range ) ) {
            [ $start, $end ] = $range;
            $length = $end - $start + 1;
            status_header( 206 );
            header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
            header( 'Content-Length: ' . $length );
            fseek( $fh, $start );
            self::copy_range( $fh, $start, $end );
        } else {
            status_header( 200 );
            header( 'Content-Length: ' . $size );
            if ( $size > 0 ) {
                self::copy_range( $fh, 0, $size - 1 );
            }
        }

        fclose( $fh );
    }

    /**
     * Stream a directory as a ZIP archive of any size.
     *
     * Uses the built-in streaming ZIP64 writer (no ZipArchive extension, no temp
     * file, constant memory), so directories are unbounded in file count and
     * total bytes. The only practical ceiling is the web server's request
     * timeout for very large transfers.
     *
     * @param string $abs_path      Absolute path to the directory.
     * @param string $download_name Optional attachment filename without .zip extension.
     * @param string $zip_root_name Optional top-level folder name inside the ZIP.
     */
    public static function stream_dir_zip( string $abs_path, string $download_name = '', string $zip_root_name = '' ): void {
        if ( ! is_dir( $abs_path ) || ! is_readable( $abs_path ) ) {
            wp_die( 'Directory not found or not readable.', 'Download Error', [ 'response' => 404 ] );
        }

        $dir_name  = $zip_root_name !== '' ? $zip_root_name : basename( $abs_path );
        $safe_name = self::disposition_name( ( $download_name !== '' ? $download_name : $dir_name ) . '.zip' );

        // A ZIP stores file bytes verbatim, so the archive is ~the sum of file
        // sizes plus a little overhead. Pre-scan to decide the delivery path.
        $est = self::estimate_zip_size( [ $abs_path ] );

        if ( $est <= self::dynamic_body_limit() ) {
            // Small enough: stream straight to the client (no temp file, constant
            // memory). Safely under the server's dynamic-response cap.
            self::begin_zip_transfer( $safe_name );
            $zip = new WPFD_Zip_Stream();
            self::add_dir_to_zip( $zip, $abs_path, $dir_name );
            $zip->close();
            exit;
        }

        // Large: build the ZIP to disk, then serve it as a STATIC file so it is
        // not subject to the dynamic (PHP) response-body limit that truncates big
        // streamed downloads on servers like LiteSpeed.
        self::serve_large_zip(
            static function ( WPFD_Zip_Stream $zip ) use ( $abs_path, $dir_name ): void {
                self::add_dir_to_zip( $zip, $abs_path, $dir_name );
            },
            $safe_name
        );
        exit;
    }

    /** Walk a directory and add every readable file/empty-dir to the ZIP stream. */
    private static function add_dir_to_zip( WPFD_Zip_Stream $zip, string $abs_path, string $dir_name ): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $abs_path, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ( $iterator as $item ) {
            if ( connection_aborted() ) {
                break;
            }
            $relative = $dir_name . '/' . $iterator->getSubPathname();
            if ( $item->isDir() ) {
                $zip->add_empty_dir( $relative );
            } elseif ( $item->isFile() && $item->isReadable() ) {
                $zip->add_file( $item->getRealPath(), $relative );
            }
        }
    }

    /**
     * Stream multiple files/dirs as a single ZIP.
     *
     * @param array $abs_paths Array of absolute paths.
     */
    public static function stream_multi_zip( array $abs_paths ): void {
        $safe_name = self::disposition_name( 'wpfd-download-' . gmdate( 'Y-m-d_H-i-s' ) . '.zip' );

        $est = self::estimate_zip_size( $abs_paths );

        if ( $est <= self::dynamic_body_limit() ) {
            self::begin_zip_transfer( $safe_name );
            $zip = new WPFD_Zip_Stream();
            self::add_paths_to_zip( $zip, $abs_paths );
            $zip->close();
            exit;
        }

        self::serve_large_zip(
            static function ( WPFD_Zip_Stream $zip ) use ( $abs_paths ): void {
                self::add_paths_to_zip( $zip, $abs_paths );
            },
            $safe_name
        );
        exit;
    }

    /** Add a mixed list of files/dirs to the ZIP stream (index-prefixed files). */
    private static function add_paths_to_zip( WPFD_Zip_Stream $zip, array $abs_paths ): void {
        $file_idx = 0;
        foreach ( $abs_paths as $abs_path ) {
            if ( connection_aborted() ) {
                break;
            }
            if ( ! file_exists( $abs_path ) || ! is_readable( $abs_path ) ) {
                continue;
            }

            if ( is_file( $abs_path ) ) {
                // Prefix with an index to prevent basename collisions (e.g. two index.php files)
                $entry_name = sprintf( '%03d_%s', ++$file_idx, basename( $abs_path ) );
                $zip->add_file( $abs_path, $entry_name );
            } elseif ( is_dir( $abs_path ) ) {
                self::add_dir_to_zip( $zip, $abs_path, basename( $abs_path ) );
            }
        }
    }

    /**
     * Estimate the resulting ZIP size (sum of file bytes + ~per-entry overhead).
     *
     * Stored (uncompressed) entries make the archive essentially the total of the
     * file sizes, so this is accurate enough to choose the delivery path. A scan
     * error falls back to PHP_INT_MAX so the safe static path is chosen.
     *
     * @param array $abs_paths Files and/or directories.
     */
    private static function estimate_zip_size( array $abs_paths ): int {
        $total   = 0;
        $entries = 0;
        try {
            foreach ( $abs_paths as $p ) {
                if ( is_file( $p ) ) {
                    $total += (int) filesize( $p );
                    $entries++;
                } elseif ( is_dir( $p ) ) {
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator( $p, RecursiveDirectoryIterator::SKIP_DOTS ),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ( $it as $item ) {
                        $entries++;
                        if ( $item->isFile() ) {
                            $total += (int) $item->getSize();
                        }
                    }
                }
            }
        } catch ( \Throwable $e ) {
            return PHP_INT_MAX;
        }
        // ~150 bytes of headers/descriptor/central-record per entry.
        return $total + ( $entries * 150 );
    }

    /**
     * Build a large ZIP to disk and serve it as a STATIC download, bypassing the
     * dynamic (PHP) response-body limit that truncates big streamed downloads on
     * servers like LiteSpeed.
     *
     * The archive is written directly into uploads/wpfd-downloads/<random>/ — the
     * uploads dir is guaranteed-writable for the web user, so this avoids the
     * system temp directory entirely (some shared hosts jail /tmp, so fopen() on a
     * wp_tempnam() path fails). Delivery, in order:
     *   1. Sendfile offload (X-LiteSpeed-Location / X-Sendfile / X-Accel-Redirect)
     *      when WPFD_XSENDFILE is configured — server streams the file, fastest.
     *   2. 302-redirect to the file's static URL — config-free, works anywhere;
     *      static files are exempt from the dynamic-response cap, so any size
     *      completes. The unguessable folder name is the access secret, an
     *      .htaccess blocks listing, and stale files are swept on each run.
     *
     * @param callable $add_entries fn(WPFD_Zip_Stream): void — adds all entries.
     * @param string   $safe_name   Pre-escaped Content-Disposition filename (.zip).
     */
    private static function serve_large_zip( callable $add_entries, string $safe_name ): void {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        ignore_user_abort( true );

        $uploads = wp_get_upload_dir();
        if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
            wp_die( 'The uploads directory is not available for staging the download.', 'Download Error', [ 'response' => 500 ] );
        }

        $base_dir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'wpfd-downloads';
        $base_url = trailingslashit( $uploads['baseurl'] ) . 'wpfd-downloads';

        if ( ! wp_mkdir_p( $base_dir ) ) {
            wp_die( 'Could not create the downloads staging directory.', 'Download Error', [ 'response' => 500 ] );
        }
        self::protect_downloads_dir( $base_dir );
        self::sweep_stale_downloads( $base_dir );

        // Unguessable per-download subfolder so the URL can't be enumerated.
        $slug    = bin2hex( random_bytes( 16 ) );
        $sub_dir = $base_dir . '/' . $slug;
        if ( ! wp_mkdir_p( $sub_dir ) ) {
            wp_die( 'Could not create the download staging folder.', 'Download Error', [ 'response' => 500 ] );
        }

        $final_name = preg_replace( '/[^A-Za-z0-9._-]/', '_', $safe_name );
        if ( $final_name === '' || $final_name === null ) {
            $final_name = 'download.zip';
        }
        $dest = $sub_dir . '/' . $final_name;

        // Build the archive straight into its final static location.
        self::build_zip_into( $dest, $add_entries );

        // Fastest path: let the server stream the file itself if configured.
        if ( self::maybe_offload( $dest, 'application/zip', $safe_name ) ) {
            exit;
        }

        // Config-free path: redirect to the static URL.
        $url = $base_url . '/' . $slug . '/' . rawurlencode( $final_name );
        while ( ob_get_level() ) {
            ob_end_clean();
        }
        nocache_headers();
        wp_safe_redirect( $url, 302 );
        exit;
    }

    /**
     * Run the streaming ZIP writer with its output captured into $dest on disk.
     *
     * Reuses the exact byte-exact ZIP64 writer used for direct streaming — the
     * only difference is an output-buffer callback that appends each flush to the
     * destination file, so peak memory stays at one chunk regardless of size.
     *
     * @param string   $dest        Absolute path to write the ZIP to.
     * @param callable $add_entries fn(WPFD_Zip_Stream): void.
     */
    private static function build_zip_into( string $dest, callable $add_entries ): void {
        $fh = fopen( $dest, 'wb' );
        if ( $fh === false ) {
            wp_die( 'Failed to create the archive file on disk.', 'Download Error', [ 'response' => 500 ] );
        }

        ob_start(
            static function ( string $buffer ) use ( $fh ): string {
                if ( $buffer !== '' ) {
                    fwrite( $fh, $buffer );
                }
                return ''; // Swallow — nothing goes to the client during the build.
            },
            self::STREAM_CHUNK
        );

        try {
            $zip = new WPFD_Zip_Stream();
            $add_entries( $zip );
            $zip->close();
        } catch ( \Throwable $e ) {
            while ( ob_get_level() ) {
                ob_end_clean();
            }
            fclose( $fh );
            @unlink( $dest );
            wp_die( 'Archive build failed: ' . esc_html( $e->getMessage() ), 'Download Error', [ 'response' => 500 ] );
        }

        while ( ob_get_level() ) {
            ob_end_flush();
        }
        fclose( $fh );
    }

    /** Write index/.htaccess guards so the downloads dir can't be browsed. */
    private static function protect_downloads_dir( string $dir ): void {
        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php // Silence is golden.\n" );
        }
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            // Block directory listing; allow direct file hits (the random slug is the secret).
            @file_put_contents( $htaccess, "Options -Indexes\n" );
        }
    }

    /**
     * Delete download subfolders older than the token TTL so temp ZIPs don't
     * accumulate. Runs opportunistically on each large download.
     */
    private static function sweep_stale_downloads( string $base_dir ): void {
        $cutoff = time() - max( self::TOKEN_TTL, 600 );
        $items  = @scandir( $base_dir );
        if ( ! is_array( $items ) ) {
            return;
        }
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' || $item === 'index.php' || $item === '.htaccess' ) {
                continue;
            }
            $path = $base_dir . '/' . $item;
            if ( ! is_dir( $path ) ) {
                continue;
            }
            if ( (int) @filemtime( $path ) > $cutoff ) {
                continue;
            }
            // Remove the stale subfolder and its single ZIP.
            $inner = @scandir( $path );
            if ( is_array( $inner ) ) {
                foreach ( $inner as $f ) {
                    if ( $f !== '.' && $f !== '..' ) {
                        @unlink( $path . '/' . $f );
                    }
                }
            }
            @rmdir( $path );
        }
    }

    /**
     * Open a chunked, uncompressed response for an on-the-fly ZIP stream.
     *
     * A streamed archive's final size is unknown when headers are sent, so it
     * uses HTTP chunked transfer (no Content-Length) instead of Range/resume.
     * That trade is deliberate: it removes the temp-file and disk-space ceiling
     * entirely, so directories of any size download with constant memory.
     *
     * @param string $safe_name Pre-escaped Content-Disposition filename.
     */
    private static function begin_zip_transfer( string $safe_name ): void {
        self::begin_transfer();

        nocache_headers();
        status_header( 200 );
        header( 'Content-Type: application/zip' );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Content-Disposition: attachment; filename="' . $safe_name . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        // The size is not known up front; never advertise Range support here.
        header( 'Accept-Ranges: none' );
        header( 'Cache-Control: private, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
    }
}
