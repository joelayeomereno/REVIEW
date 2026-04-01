<?php
defined( 'ABSPATH' ) || exit;

class WPFD_Deployer {

    /**
     * Write a single file chunk to disk.
     * Called once per file during chunked upload.
     *
     * @param string $relative_path  e.g. "my-plugin/includes/class-main.php"
     * @param string $tmp_path       Path to the uploaded temp file
     * @param bool   $is_first_file  True if this is the first file — triggers backup
     * @param string $plugin_slug    Derived from the root folder name
     * @return array{success:bool, message:string, backup_path?:string}
     */
    public static function write_file( string $relative_path, string $tmp_path, bool $is_first_file, string $plugin_slug, string &$backup_path ): array {

        // Sanitize and validate
        $safe_relative = WPFD_Security::sanitize_relative_path( $relative_path );
        if ( empty( $safe_relative ) ) {
            return [ 'success' => false, 'message' => 'Invalid file path.' ];
        }

        $filename = basename( $safe_relative );
        if ( ! WPFD_Security::is_allowed_extension( $filename ) ) {
            return [ 'success' => false, 'message' => "Blocked file type: {$filename}" ];
        }

        $full_path = WPFD_DEPLOY_DIR . $safe_relative;

        if ( ! WPFD_Security::validate_deploy_path( $full_path ) ) {
            return [ 'success' => false, 'message' => 'Path traversal detected — file rejected.' ];
        }

        // Backup is created by /prepare-deploy endpoint before any uploads.
        // Do NOT create backup here — concurrent batch workers would race.

        WPFD_Filesystem::mkdir_recursive( dirname( $full_path ) );
        $written = @copy( $tmp_path, $full_path );
        if ( $written ) {
            @chmod( $full_path, 0644 );
        }
        if ( ! $written ) {
            return [ 'success' => false, 'message' => "Failed to write: {$safe_relative}" ];
        }

        return [ 'success' => true, 'message' => "Written: {$safe_relative}" ];
    }

    /**
     * Finalise a deployment — log it and optionally activate the plugin.
     */
    public static function finalise(
        string $plugin_slug,
        int    $file_count,
        string $backup_path,
        bool   $activate,
        string $version     = '',
        string $deploy_mode = 'batch',
        int    $skipped     = 0,
        int    $elapsed_ms  = 0
    ): array {
        global $wpdb;

        // Log to DB — suppress errors to prevent HTML output from corrupting REST JSON
        $suppress = $wpdb->suppress_errors( true );
        $wpdb->insert(
            $wpdb->prefix . 'wpfd_deployments',
            [
                'plugin_slug' => $plugin_slug,
                'version'     => $version,
                'deploy_time' => current_time( 'mysql', true ),
                'file_count'  => $file_count,
                'backup_path' => $backup_path,
                'status'      => 'success',
                'deploy_mode' => $deploy_mode,
                'skipped'     => $skipped,
                'elapsed_ms'  => $elapsed_ms,
                'deployed_by' => get_current_user_id(),
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d' ]
        );
        $wpdb->suppress_errors( $suppress );

        $result = [
            'success'     => true,
            'plugin_slug' => $plugin_slug,
            'file_count'  => $file_count,
            'backup_path' => $backup_path,
            'activated'   => false,
        ];

        if ( $activate ) {
            // Find the main plugin file
            $main_file = self::detect_main_plugin_file( $plugin_slug );
            if ( $main_file ) {
                if ( ! function_exists( 'activate_plugin' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                try {
                    $activated = activate_plugin( $main_file );
                    $result['activated']  = ( ! is_wp_error( $activated ) );
                    $result['main_file']  = $main_file;
                    if ( is_wp_error( $activated ) ) {
                        $result['activation_error'] = $activated->get_error_message();
                    }
                } catch ( \Throwable $e ) {
                    $result['activated']        = false;
                    $result['main_file']        = $main_file;
                    $result['activation_error'] = $e->getMessage();
                }
            } else {
                $result['activation_error'] = 'Could not detect main plugin file.';
            }
        }

        return $result;
    }

    /**
     * Scan the plugin directory for a file with a Plugin Name header.
     */
    public static function detect_main_plugin_file( string $plugin_slug ): ?string {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        wp_cache_delete( 'plugins', 'plugins' );
        $all_plugins = get_plugins();

        foreach ( array_keys( $all_plugins ) as $plugin_file ) {
            if ( str_starts_with( $plugin_file, $plugin_slug . '/' ) ) {
                return $plugin_file;
            }
        }

        // Fallback: common patterns
        $guesses = [
            $plugin_slug . '/' . $plugin_slug . '.php',
            $plugin_slug . '/plugin.php',
            $plugin_slug . '/index.php',
        ];
        foreach ( $guesses as $guess ) {
            if ( file_exists( WPFD_DEPLOY_DIR . $guess ) ) {
                return $guess;
            }
        }

        return null;
    }

    /**
     * Return deployment history from DB.
     */
    public static function get_history( int $limit = 20 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wpfd_deployments';
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.*, u.user_login FROM {$table} d LEFT JOIN {$wpdb->users} u ON u.ID = d.deployed_by ORDER BY d.deploy_time DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Quick scan of plugins directory to return installed plugin info.
     */
    public static function get_installed_plugins(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        $active  = get_option( 'active_plugins', [] );
        $result  = [];
        foreach ( $plugins as $file => $data ) {
            $slug     = dirname( $file );
            $result[] = [
                'slug'    => $slug,
                'file'    => $file,
                'name'    => $data['Name'],
                'version' => $data['Version'],
                'active'  => in_array( $file, $active, true ),
            ];
        }
        return $result;
    }

    /** ------------------------------------------------------------------ *
     *  Extract ZIP and write all files to the plugin directory
     * ------------------------------------------------------------------ */
    public static function extract_zip( string $zip_path, string $plugin_slug, string &$backup_path ): array {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return [ 'success' => false, 'message' => 'ZipArchive extension not available.' ];
        }

        $zip = new \ZipArchive();
        if ( $zip->open( $zip_path ) !== true ) {
            return [ 'success' => false, 'message' => 'Could not open ZIP file.' ];
        }

        if ( $backup_path === '' ) {
            $backup_path = WPFD_Rollback::backup( $plugin_slug );
        }

        $written = 0;
        $failed  = 0;
        $errors  = [];

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry = $zip->getNameIndex( $i );
            if ( substr( $entry, -1 ) === '/' ) {
                continue;
            }

            $safe = WPFD_Security::sanitize_relative_path( $entry );
            if ( empty( $safe ) ) {
                $failed++;
                continue;
            }

            $filename = basename( $safe );
            if ( ! WPFD_Security::is_allowed_extension( $filename ) ) {
                $failed++;
                $errors[] = "Blocked: {$filename}";
                continue;
            }

            $full_path = WPFD_DEPLOY_DIR . $safe;
            if ( ! WPFD_Security::validate_deploy_path( $full_path ) ) {
                $failed++;
                continue;
            }

            WPFD_Filesystem::mkdir_recursive( dirname( $full_path ) );
            $stream = $zip->getStream( $entry );
            if ( ! $stream ) {
                $failed++;
                continue;
            }

            $out = @fopen( $full_path, 'wb' );
            if ( ! $out ) {
                fclose( $stream );
                $failed++;
                continue;
            }

            stream_copy_to_stream( $stream, $out );
            fclose( $stream );
            fclose( $out );
            @chmod( $full_path, 0644 );
            $written++;
        }

        $zip->close();

        return [
            'success' => true,
            'written' => $written,
            'failed'  => $failed,
            'errors'  => $errors,
        ];
    }

    /** ------------------------------------------------------------------ *
     *  Write a chunk to staging; assemble when all chunks arrive
     * ------------------------------------------------------------------ */
    public static function write_chunk(
        string $tmp_path,
        string $session_id,
        string $file_hash,
        int    $chunk_index,
        int    $chunk_total,
        string $relative_path,
        string $plugin_slug
    ): array {
        $chunk_dir = wp_upload_dir()['basedir'] . '/wpfd-chunks/' . sanitize_key( $session_id ) . '/' . sanitize_key( $file_hash );
        WPFD_Filesystem::mkdir_recursive( $chunk_dir );

        $chunk_file = $chunk_dir . '/' . intval( $chunk_index );
        if ( ! @copy( $tmp_path, $chunk_file ) ) {
            return [ 'success' => false, 'message' => 'Failed to store chunk.' ];
        }

        $received = count( glob( $chunk_dir . '/*' ) );
        if ( $received < $chunk_total ) {
            return [ 'success' => true, 'complete' => false, 'received' => $received ];
        }

        return self::assemble_chunks( $chunk_dir, $chunk_total, $relative_path, $plugin_slug );
    }

    /** ------------------------------------------------------------------ *
     *  Assemble ordered chunks into the final file
     * ------------------------------------------------------------------ */
    private static function assemble_chunks( string $chunk_dir, int $chunk_total, string $relative_path, string $plugin_slug ): array {
        $safe = WPFD_Security::sanitize_relative_path( $relative_path );
        if ( empty( $safe ) ) {
            return [ 'success' => false, 'message' => 'Invalid path.' ];
        }

        $filename = basename( $safe );
        if ( ! WPFD_Security::is_allowed_extension( $filename ) ) {
            return [ 'success' => false, 'message' => "Blocked: {$filename}" ];
        }

        $full_path = WPFD_DEPLOY_DIR . $safe;
        if ( ! WPFD_Security::validate_deploy_path( $full_path ) ) {
            return [ 'success' => false, 'message' => 'Path traversal detected.' ];
        }

        WPFD_Filesystem::mkdir_recursive( dirname( $full_path ) );

        $out = @fopen( $full_path, 'wb' );
        if ( ! $out ) {
            return [ 'success' => false, 'message' => "Cannot write: {$safe}" ];
        }

        for ( $i = 0; $i < $chunk_total; $i++ ) {
            $cf = $chunk_dir . '/' . $i;
            if ( ! file_exists( $cf ) ) {
                fclose( $out );
                return [ 'success' => false, 'message' => "Missing chunk {$i}." ];
            }
            $in = fopen( $cf, 'rb' );
            stream_copy_to_stream( $in, $out );
            fclose( $in );
        }

        fclose( $out );
        @chmod( $full_path, 0644 );
        WPFD_Filesystem::delete_dir( $chunk_dir );

        return [ 'success' => true, 'complete' => true, 'message' => "Written: {$safe}" ];
    }

    /** ------------------------------------------------------------------ *
     *  Build SHA-256 manifest for delta deploy comparison
     * ------------------------------------------------------------------ */
    public static function build_manifest( string $plugin_slug ): array {
        $plugin_dir = WPFD_DEPLOY_DIR . sanitize_key( $plugin_slug );
        if ( ! is_dir( $plugin_dir ) ) {
            return [];
        }

        $manifest = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $plugin_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }
            $relative = $plugin_slug . '/' . ltrim( str_replace( '\\', '/', $iterator->getSubPathname() ), '/' );
            $manifest[ $relative ] = hash_file( 'sha256', $file->getRealPath() );
        }

        return $manifest;
    }
}
