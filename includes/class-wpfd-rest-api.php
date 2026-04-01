<?php
defined( 'ABSPATH' ) || exit;

class WPFD_REST_API {

    const NAMESPACE = 'wpfd/v1';

    /** Temporary session store for backup path during a deploy session */
    const TRANSIENT_PREFIX = 'wpfd_session_';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/upload-file', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'upload_file' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/finalise', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'finalise_deploy' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/rollback', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rollback' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/backups', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_backups' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/backups/delete', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'delete_backup' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/history', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_history' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/plugins', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_plugins' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/activate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'activate_plugin_route' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/deactivate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'deactivate_plugin_route' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/upload-batch', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'upload_batch' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/upload-zip', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'upload_zip' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/upload-chunk', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'upload_chunk' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/manifest', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_manifest' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/prepare-deploy', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'prepare_deploy' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        /* ---- Section B: File Browser --------------------------------- */
        register_rest_route( self::NAMESPACE, '/browser/roots', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'browser_roots' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/browser/scan', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'browser_scan' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'root' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => static fn( $v ) => in_array(
                        $v,
                        [ 'plugins', 'themes', 'uploads', 'mu-plugins', 'content', 'root' ],
                        true
                    ),
                ],
                'path' => [
                    'required'          => false,
                    'default'           => '',
                    'sanitize_callback' => [ 'WPFD_Browser', 'sanitize_rel_path' ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/browser/nuke', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'browser_nuke' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/browser/nuke-scan', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'browser_nuke_scan' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/browser/read-file', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'browser_read_file' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/browser/extract-dir', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'browser_extract_dir' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        /* ---- Download system ----------------------------------------- */
        register_rest_route( self::NAMESPACE, '/download/token', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'download_token' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/download/multi-token', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'download_multi_token' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/download/serve', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'download_serve' ],
            'permission_callback' => '__return_true',
        ] );

        /* ---- Settings ------------------------------------------------ */
        register_rest_route( self::NAMESPACE, '/settings', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_settings' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/settings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_settings' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        /* ---- Bulk nuke ----------------------------------------------- */
        register_rest_route( self::NAMESPACE, '/browser/bulk-nuke', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'browser_bulk_nuke' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        /* ---- History nuke (delete from DB) --------------------------- */
        register_rest_route( self::NAMESPACE, '/history/delete', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'history_delete' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    public function check_permission( WP_REST_Request $request ): bool {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        $nonce = $request->get_header( 'X-WPFD-Nonce' ) ?: $request->get_param( '_wpnonce' );
        return (bool) wp_verify_nonce( $nonce, WPFD_Security::NONCE_ACTION );
    }

    /** ------------------------------------------------------------------ *
     *  Upload a single file (called N times — once per file in the folder)
     * ------------------------------------------------------------------ */
    public function upload_file( WP_REST_Request $request ): WP_REST_Response {
        $files        = $request->get_file_params();
        $relative     = sanitize_text_field( $request->get_param( 'relative_path' ) );
        $plugin_slug  = sanitize_key( $request->get_param( 'plugin_slug' ) );
        $is_first     = filter_var( $request->get_param( 'is_first' ), FILTER_VALIDATE_BOOLEAN );
        $session_id   = sanitize_key( $request->get_param( 'session_id' ) );

        if ( empty( $files['file'] ) || $files['file']['error'] !== UPLOAD_ERR_OK ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Upload error.' ], 400 );
        }

        if ( empty( $plugin_slug ) || empty( $relative ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing parameters.' ], 400 );
        }

        // Retrieve/store backup path across the session via transient
        $transient_key = self::TRANSIENT_PREFIX . $session_id;
        $backup_path   = get_transient( $transient_key ) ?: '';

        $result = WPFD_Deployer::write_file(
            $relative,
            $files['file']['tmp_name'],
            $is_first,
            $plugin_slug,
            $backup_path
        );

        // Persist backup path for subsequent files in this session
        if ( $backup_path !== '' ) {
            set_transient( $transient_key, $backup_path, HOUR_IN_SECONDS );
        }

        return new WP_REST_Response( $result, $result['success'] ? 200 : 422 );
    }

    /** ------------------------------------------------------------------ *
     *  Finalise — log deployment, optionally activate
     * ------------------------------------------------------------------ */
    public function finalise_deploy( WP_REST_Request $request ): WP_REST_Response {
        $plugin_slug = sanitize_key( $request->get_param( 'plugin_slug' ) );
        $file_count  = absint( $request->get_param( 'file_count' ) );
        $activate    = filter_var( $request->get_param( 'activate' ), FILTER_VALIDATE_BOOLEAN );
        $version     = sanitize_text_field( $request->get_param( 'version' ) ?? '' );
        $session_id  = sanitize_key( $request->get_param( 'session_id' ) );
        $deploy_mode = sanitize_key( $request->get_param( 'deploy_mode' ) ?? 'batch' );
        $skipped     = absint( $request->get_param( 'skipped' ) );
        $elapsed_ms  = absint( $request->get_param( 'elapsed_ms' ) );

        $backup_path = get_transient( self::TRANSIENT_PREFIX . $session_id ) ?: '';
        delete_transient( self::TRANSIENT_PREFIX . $session_id );

        $result = WPFD_Deployer::finalise( $plugin_slug, $file_count, $backup_path, $activate, $version, $deploy_mode, $skipped, $elapsed_ms );
        return new WP_REST_Response( $result, 200 );
    }

    /** ------------------------------------------------------------------ *
     *  Rollback
     * ------------------------------------------------------------------ */
    public function rollback( WP_REST_Request $request ): WP_REST_Response {
        $plugin_slug = sanitize_key( $request->get_param( 'plugin_slug' ) );
        $backup_path = sanitize_text_field( $request->get_param( 'backup_path' ) );

        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = WPFD_Rollback::restore( $plugin_slug, $backup_path );
        return new WP_REST_Response( $result, $result['success'] ? 200 : 422 );
    }

    public function get_backups( WP_REST_Request $request ): WP_REST_Response {
        $slug = sanitize_key( $request->get_param( 'slug' ) ?? '' );
        $data = $slug ? WPFD_Rollback::list_backups( $slug ) : WPFD_Rollback::list_all_backups();
        return new WP_REST_Response( $data, 200 );
    }

    public function delete_backup( WP_REST_Request $request ): WP_REST_Response {
        $path    = sanitize_text_field( $request->get_param( 'backup_path' ) );
        $deleted = WPFD_Rollback::delete_backup( $path );
        return new WP_REST_Response( [ 'success' => $deleted ], $deleted ? 200 : 422 );
    }

    public function get_history(): WP_REST_Response {
        return new WP_REST_Response( WPFD_Deployer::get_history( 50 ), 200 );
    }

    public function get_plugins(): WP_REST_Response {
        return new WP_REST_Response( WPFD_Deployer::get_installed_plugins(), 200 );
    }

    public function activate_plugin_route( WP_REST_Request $request ): WP_REST_Response {
        $plugin_file = sanitize_text_field( $request->get_param( 'plugin_file' ) );
        if ( ! function_exists( 'activate_plugin' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $result = activate_plugin( $plugin_file );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 422 );
        }
        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    public function deactivate_plugin_route( WP_REST_Request $request ): WP_REST_Response {
        $plugin_file = sanitize_text_field( $request->get_param( 'plugin_file' ) );
        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins( $plugin_file );
        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /** ------------------------------------------------------------------ *
     *  Batch upload — up to 10 files in a single request
     * ------------------------------------------------------------------ */
    public function upload_batch( WP_REST_Request $request ): WP_REST_Response {
        $files        = $request->get_file_params();
        $plugin_slug  = sanitize_key( $request->get_param( 'plugin_slug' ) );
        $session_id   = sanitize_key( $request->get_param( 'session_id' ) );
        $count        = absint( $request->get_param( 'count' ) );

        if ( empty( $plugin_slug ) || $count < 1 || $count > 200 ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid parameters.' ], 400 );
        }

        $transient_key = self::TRANSIENT_PREFIX . $session_id;
        $backup_path   = get_transient( $transient_key ) ?: '';

        /* Defensive fallback: if prepare_deploy was not called, create backup now */
        if ( $backup_path === '' ) {
            $backup_path = WPFD_Rollback::backup( $plugin_slug );
            if ( $backup_path !== '' ) {
                set_transient( $transient_key, $backup_path, HOUR_IN_SECONDS );
            }
        }

        $results = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $file_key = 'file_' . $i;
            $path_key = 'path_' . $i;

            if ( empty( $files[ $file_key ] ) || $files[ $file_key ]['error'] !== UPLOAD_ERR_OK ) {
                $results[] = [ 'success' => false, 'index' => $i, 'message' => 'Upload error.' ];
                continue;
            }

            $relative = sanitize_text_field( $request->get_param( $path_key ) );
            if ( empty( $relative ) ) {
                $results[] = [ 'success' => false, 'index' => $i, 'message' => 'Missing path.' ];
                continue;
            }

            $result    = WPFD_Deployer::write_file( $relative, $files[ $file_key ]['tmp_name'], false, $plugin_slug, $backup_path );
            $result['index'] = $i;
            $results[] = $result;
        }

        if ( $backup_path !== '' ) {
            set_transient( $transient_key, $backup_path, HOUR_IN_SECONDS );
        }

        $written = count( array_filter( $results, fn( $r ) => $r['success'] ) );
        return new WP_REST_Response( [
            'success' => true,
            'results' => $results,
            'written' => $written,
            'failed'  => $count - $written,
        ], 200 );
    }

    /** ------------------------------------------------------------------ *
     *  ZIP upload — entire plugin as a single compressed archive
     * ------------------------------------------------------------------ */
    public function upload_zip( WP_REST_Request $request ): WP_REST_Response {
        $files       = $request->get_file_params();
        $plugin_slug = sanitize_key( $request->get_param( 'plugin_slug' ) );
        $session_id  = sanitize_key( $request->get_param( 'session_id' ) );

        if ( empty( $files['zip'] ) || $files['zip']['error'] !== UPLOAD_ERR_OK ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'ZIP upload error.' ], 400 );
        }

        if ( empty( $plugin_slug ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing plugin slug.' ], 400 );
        }

        $transient_key = self::TRANSIENT_PREFIX . $session_id;
        $backup_path   = get_transient( $transient_key ) ?: '';

        $result = WPFD_Deployer::extract_zip( $files['zip']['tmp_name'], $plugin_slug, $backup_path );

        if ( $backup_path !== '' ) {
            set_transient( $transient_key, $backup_path, HOUR_IN_SECONDS );
        }

        return new WP_REST_Response( $result, $result['success'] ? 200 : 422 );
    }

    /** ------------------------------------------------------------------ *
     *  Chunk upload — large files split into 1 MB pieces
     * ------------------------------------------------------------------ */
    public function upload_chunk( WP_REST_Request $request ): WP_REST_Response {
        $files       = $request->get_file_params();
        $session_id  = sanitize_key( $request->get_param( 'session_id' ) );
        $file_hash   = sanitize_key( $request->get_param( 'file_hash' ) );
        $chunk_index = absint( $request->get_param( 'chunk_index' ) );
        $chunk_total = absint( $request->get_param( 'chunk_total' ) );
        $relative    = sanitize_text_field( $request->get_param( 'relative_path' ) );
        $plugin_slug = sanitize_key( $request->get_param( 'plugin_slug' ) );

        if ( empty( $files['chunk'] ) || $files['chunk']['error'] !== UPLOAD_ERR_OK ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Chunk upload error.' ], 400 );
        }

        if ( empty( $file_hash ) || $chunk_total < 1 ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid chunk parameters.' ], 400 );
        }

        $result = WPFD_Deployer::write_chunk(
            $files['chunk']['tmp_name'], $session_id, $file_hash,
            $chunk_index, $chunk_total, $relative, $plugin_slug
        );

        return new WP_REST_Response( $result, $result['success'] ? 200 : 422 );
    }

    /** ------------------------------------------------------------------ *
     *  Manifest — file hashes for delta deploy
     * ------------------------------------------------------------------ */
    public function get_manifest( WP_REST_Request $request ): WP_REST_Response {
        $plugin_slug = sanitize_key( $request->get_param( 'slug' ) );
        if ( empty( $plugin_slug ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing slug.' ], 400 );
        }
        $manifest = WPFD_Deployer::build_manifest( $plugin_slug );
        return new WP_REST_Response( [ 'success' => true, 'manifest' => $manifest ], 200 );
    }

    /** ------------------------------------------------------------------ *
     *  Section B — browser/roots
     *  Returns all available root aliases and their absolute server paths.
     * ------------------------------------------------------------------ */
    public function browser_roots(): WP_REST_Response {
        $roots = [];
        foreach ( WPFD_Browser::get_aliases() as $alias ) {
            $path = WPFD_Browser::resolve_root( $alias );
            if ( $path !== false ) {
                $roots[ $alias ] = true;
            }
        }
        return new WP_REST_Response( [ 'success' => true, 'roots' => $roots ], 200 );
    }

    /** ------------------------------------------------------------------ *
     *  Section B — browser/scan
     *  GET ?root=plugins&path=my-plugin/includes
     * ------------------------------------------------------------------ */
    public function browser_scan( WP_REST_Request $request ): WP_REST_Response {
        $alias   = $request->get_param( 'root' );
        $relpath = $request->get_param( 'path' ) ?? '';

        $root_abs = WPFD_Browser::resolve_root( $alias );
        if ( $root_abs === false ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid root.' ], 400 );
        }

        // Build and validate the absolute target path.
        $sep      = DIRECTORY_SEPARATOR;
        $joined   = $relpath !== '' ? $root_abs . $sep . str_replace( '/', $sep, $relpath ) : $root_abs;

        // Pre-realpath boundary check on the normalized path.
        $normalized = wp_normalize_path( $joined );
        $norm_root  = wp_normalize_path( $root_abs );
        if ( $normalized !== $norm_root && strpos( $normalized . '/', $norm_root . '/' ) !== 0 ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Path outside root.' ], 403 );
        }

        $abs_path = realpath( $joined );

        if ( $abs_path === false ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Path does not exist.' ], 404 );
        }

        // Post-realpath boundary check (handles symlinks).
        if ( $abs_path !== $root_abs && strpos( $abs_path . $sep, $root_abs . $sep ) !== 0 ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Path outside root.' ], 403 );
        }

        $entries = WPFD_Browser::scan_directory( $abs_path );

        return new WP_REST_Response( [
            'success' => true,
            'root'    => $alias,
            'path'    => $relpath,
            'abs'     => $abs_path,
            'entries' => $entries,
        ], 200 );
    }

    /** ------------------------------------------------------------------ *
     *  Section B/C — browser/nuke
     *  POST { root, path, type:'file'|'dir' }
     *  Force-deletes a file or directory after double path-traversal check.
     * ------------------------------------------------------------------ */
    public function browser_nuke( WP_REST_Request $request ): WP_REST_Response {
        $alias   = sanitize_key( $request->get_param( 'root' ) ?? '' );
        $relpath = WPFD_Browser::sanitize_rel_path( $request->get_param( 'path' ) ?? '' );

        if ( empty( $relpath ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Cannot nuke root.' ], 400 );
        }

        $root_abs = WPFD_Browser::resolve_root( $alias );
        if ( $root_abs === false ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid root.' ], 400 );
        }

        $joined   = $root_abs . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relpath );
        $abs_path = realpath( $joined );
        $sep      = DIRECTORY_SEPARATOR;

        if (
            $abs_path === false
            || strpos( $abs_path . $sep, $root_abs . $sep ) !== 0
        ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid path.' ], 403 );
        }

        // Determine type server-side — never trust the client
        $is_dir = is_dir( $abs_path );

        $result = WPFD_Nuker::nuke( $abs_path, $is_dir );

        return new WP_REST_Response( [
            'success'       => $result['success'],
            'strategy_used' => $result['strategy_used'] ?? 0,
            'elapsed_ms'    => $result['elapsed_ms'] ?? 0,
            'log'           => $result['log'] ?? [],
            'error'         => $result['error'] ?? '',
        ], $result['success'] ? 200 : 422 );
    }

    /** ------------------------------------------------------------------ *
     *  Section C2 — browser/read-file
     *  POST { root, path }
     *  Returns raw text content of a single file (max 2 MB).
     * ------------------------------------------------------------------ */
    public function browser_read_file( WP_REST_Request $request ): WP_REST_Response {
        $alias   = sanitize_key( $request->get_param( 'root' ) ?? '' );
        $relpath = WPFD_Browser::sanitize_rel_path( $request->get_param( 'path' ) ?? '' );

        if ( empty( $relpath ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Path required.' ], 400 );
        }

        $root_abs = WPFD_Browser::resolve_root( $alias );
        if ( $root_abs === false ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid root.' ], 400 );
        }

        $joined   = $root_abs . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relpath );
        $abs_path = realpath( $joined );
        $sep      = DIRECTORY_SEPARATOR;

        if (
            $abs_path === false
            || strpos( $abs_path . $sep, $root_abs . $sep ) !== 0
        ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid path.' ], 403 );
        }

        if ( ! is_file( $abs_path ) || ! is_readable( $abs_path ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Not a readable file.' ], 400 );
        }

        $max_size = 2 * 1024 * 1024; // 2 MB
        $size     = filesize( $abs_path );
        if ( $size > $max_size ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'File too large (max 2 MB).' ], 413 );
        }

        $content = file_get_contents( $abs_path );
        if ( $content === false ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Read failed.' ], 500 );
        }

        return new WP_REST_Response( [
            'success'  => true,
            'filename' => basename( $abs_path ),
            'content'  => $content,
        ], 200 );
    }

    /** ------------------------------------------------------------------ *
     *  Section C3 — browser/extract-dir
     *  POST { root, path }
     *  Recursively reads all text files in a directory and returns them
     *  as an array of { relpath, content } entries for client-side Markdown assembly.
     *  Hard limits: 500 files, 10 MB total, 2 MB per file, text-only extensions.
     * ------------------------------------------------------------------ */
    public function browser_extract_dir( WP_REST_Request $request ): WP_REST_Response {
        $alias   = sanitize_key( $request->get_param( 'root' ) ?? '' );
        $relpath = WPFD_Browser::sanitize_rel_path( $request->get_param( 'path' ) ?? '' );

        if ( empty( $relpath ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Path required.' ], 400 );
        }

        $root_abs = WPFD_Browser::resolve_root( $alias );
        if ( $root_abs === false ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid root.' ], 400 );
        }

        $joined   = $root_abs . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relpath );
        $abs_path = realpath( $joined );
        $sep      = DIRECTORY_SEPARATOR;

        if (
            $abs_path === false
            || strpos( $abs_path . $sep, $root_abs . $sep ) !== 0
        ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid path.' ], 403 );
        }

        if ( ! is_dir( $abs_path ) || ! is_readable( $abs_path ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Not a readable directory.' ], 400 );
        }

        $text_exts = [
            'php', 'inc', 'module', 'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx',
            'css', 'scss', 'sass', 'less', 'html', 'htm', 'twig', 'blade',
            'json', 'xml', 'yaml', 'yml', 'toml', 'sql', 'sh', 'bash', 'zsh',
            'ps1', 'py', 'rb', 'java', 'c', 'h', 'cpp', 'cs', 'go', 'rs',
            'swift', 'kt', 'lua', 'r', 'pl', 'md', 'txt', 'csv', 'log',
            'env', 'htaccess', 'conf', 'svg', 'map', 'lock', 'pot', 'po',
        ];

        $max_files  = 200;
        $max_total  = 10 * 1024 * 1024; // 10 MB total
        $max_single = 512 * 1024;       // 512 KB per file
        $files      = [];
        $total_size = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $abs_path, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( count( $files ) >= $max_files ) {
                break;
            }
            if ( ! $file->isFile() || ! $file->isReadable() ) {
                continue;
            }

            $ext = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, $text_exts, true ) ) {
                continue;
            }

            $fsize = $file->getSize();
            if ( $fsize > $max_single ) {
                continue; // skip oversized individual files silently
            }
            if ( $total_size + $fsize > $max_total ) {
                break;
            }

            $real_file = $file->getRealPath();
            // Safety: must stay within the target directory
            if ( strpos( $real_file . $sep, $abs_path . $sep ) !== 0 ) {
                continue;
            }

            $content = @file_get_contents( $real_file );
            if ( $content === false ) {
                continue;
            }

            // Build relative path from the extracted directory
            $file_rel = str_replace( $sep, '/', substr( $real_file, strlen( $abs_path ) + 1 ) );

            $files[] = [
                'path'    => $file_rel,
                'content' => $content,
            ];
            $total_size += $fsize;
        }

        if ( empty( $files ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'No extractable text files found.' ], 404 );
        }

        return new WP_REST_Response( [
            'success'   => true,
            'dirname'   => basename( $abs_path ),
            'files'     => $files,
            'count'     => count( $files ),
            'truncated' => count( $files ) >= $max_files || $total_size >= $max_total,
        ], 200 );
    }

    /** ------------------------------------------------------------------ *
     *  Section C — browser/nuke-scan
     *  POST { root, path }
     *  Scans a path and returns file count, size, and read-only files.
     * ------------------------------------------------------------------ */
    public function browser_nuke_scan( WP_REST_Request $request ): WP_REST_Response {
        $alias   = sanitize_key( $request->get_param( 'root' ) ?? '' );
        $relpath = WPFD_Browser::sanitize_rel_path( $request->get_param( 'path' ) ?? '' );

        if ( empty( $relpath ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Cannot scan root.' ], 400 );
        }

        $root_abs = WPFD_Browser::resolve_root( $alias );
        if ( $root_abs === false ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid root.' ], 400 );
        }

        $joined   = $root_abs . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relpath );
        $abs_path = realpath( $joined );
        $sep      = DIRECTORY_SEPARATOR;

        if (
            $abs_path === false
            || strpos( $abs_path . $sep, $root_abs . $sep ) !== 0
        ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid path.' ], 403 );
        }

        $scan = WPFD_Nuker::scan( $abs_path );

        return new WP_REST_Response( [
            'success'     => true,
            'exists'      => $scan['exists'] ?? false,
            'is_dir'      => $scan['is_dir'] ?? false,
            'file_count'  => $scan['file_count'] ?? 0,
            'total_bytes' => $scan['total_bytes'] ?? 0,
            'readonly'    => count( $scan['readonly'] ?? [] ),
            'error'       => $scan['error'] ?? '',
        ], 200 );
    }

    /** ------------------------------------------------------------------ *
     *  Prepare deploy — create backup upfront and return session info.
     *  Eliminates the backup race condition from concurrent batch uploads.
     * ------------------------------------------------------------------ */
    public function prepare_deploy( WP_REST_Request $request ): WP_REST_Response {
        $plugin_slug = sanitize_key( $request->get_param( 'plugin_slug' ) );
        $session_id  = sanitize_key( $request->get_param( 'session_id' ) );

        if ( empty( $plugin_slug ) || empty( $session_id ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing parameters.' ], 400 );
        }

        $backup_path = WPFD_Rollback::backup( $plugin_slug );

        $transient_key = self::TRANSIENT_PREFIX . $session_id;
        if ( ! empty( $backup_path ) ) {
            set_transient( $transient_key, $backup_path, HOUR_IN_SECONDS );
        }

        return new WP_REST_Response( [
            'success'     => true,
            'backup_path' => $backup_path,
            'session_id'  => $session_id,
        ], 200 );
    }

    /* ==================================================================
       DOWNLOAD SYSTEM
       ================================================================== */

    /** Generate a single-use download token for a browser path. */
    public function download_token( WP_REST_Request $request ): WP_REST_Response {
        $alias   = sanitize_key( $request->get_param( 'root' ) ?? '' );
        $relpath = WPFD_Browser::sanitize_rel_path( $request->get_param( 'path' ) ?? '' );

        if ( empty( $relpath ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Path required.' ], 400 );
        }

        $root_abs = WPFD_Browser::resolve_root( $alias );
        if ( $root_abs === false ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid root.' ], 400 );
        }

        $joined   = $root_abs . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relpath );
        $abs_path = realpath( $joined );
        $sep      = DIRECTORY_SEPARATOR;

        if (
            $abs_path === false
            || strpos( $abs_path . $sep, $root_abs . $sep ) !== 0
        ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid path.' ], 403 );
        }

        $token = WPFD_Downloader::create_token( $abs_path );

        return new WP_REST_Response( [
            'success' => true,
            'token'   => $token,
            'url'     => rest_url( self::NAMESPACE . '/download/serve' ) . '?token=' . $token,
        ], 200 );
    }

    /** Generate a multi-file download token. */
    public function download_multi_token( WP_REST_Request $request ): WP_REST_Response {
        $items = $request->get_param( 'items' );
        if ( ! is_array( $items ) || empty( $items ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Items required.' ], 400 );
        }

        $abs_paths = [];
        foreach ( $items as $item ) {
            $alias   = sanitize_key( $item['root'] ?? '' );
            $relpath = WPFD_Browser::sanitize_rel_path( $item['path'] ?? '' );
            if ( empty( $relpath ) ) {
                continue;
            }

            $root_abs = WPFD_Browser::resolve_root( $alias );
            if ( $root_abs === false ) {
                continue;
            }

            $joined   = $root_abs . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relpath );
            $abs_path = realpath( $joined );
            $sep      = DIRECTORY_SEPARATOR;

            if (
                $abs_path !== false
                && strpos( $abs_path . $sep, $root_abs . $sep ) === 0
            ) {
                $abs_paths[] = $abs_path;
            }
        }

        if ( empty( $abs_paths ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'No valid paths.' ], 400 );
        }

        $token = WPFD_Downloader::create_multi_token( $abs_paths );

        return new WP_REST_Response( [
            'success' => true,
            'token'   => $token,
            'url'     => rest_url( self::NAMESPACE . '/download/serve' ) . '?token=' . $token,
        ], 200 );
    }

    /** Serve a download — validates and consumes the token. */
    public function download_serve( WP_REST_Request $request ): WP_REST_Response {
        $token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
        if ( empty( $token ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Token required.' ], 400 );
        }

        $data = WPFD_Downloader::consume_token( $token );
        if ( ! $data ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid or expired token.' ], 403 );
        }

        // Multi-file download
        if ( isset( $data['paths'] ) && is_array( $data['paths'] ) ) {
            WPFD_Downloader::stream_multi_zip( $data['paths'] );
            // stream_multi_zip calls exit — this line is never reached
        }

        // Single file/dir download
        $abs_path = $data['path'] ?? '';
        if ( empty( $abs_path ) || ! file_exists( $abs_path ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'File not found.' ], 404 );
        }

        if ( is_dir( $abs_path ) ) {
            WPFD_Downloader::stream_dir_zip( $abs_path );
        } else {
            WPFD_Downloader::stream_file( $abs_path );
        }

        // stream methods call exit — this line is never reached
        return new WP_REST_Response( [ 'success' => false ], 500 );
    }

    /* ==================================================================
       SETTINGS
       ================================================================== */

    /** Return current plugin settings. */
    public function get_settings(): WP_REST_Response {
        $defaults = [
            'backup_retention' => 5,
        ];
        $settings = wp_parse_args( get_option( 'wpfd_settings', [] ), $defaults );
        return new WP_REST_Response( [ 'success' => true, 'settings' => $settings ], 200 );
    }

    /** Save plugin settings. */
    public function save_settings( WP_REST_Request $request ): WP_REST_Response {
        $input = $request->get_json_params();

        $settings = get_option( 'wpfd_settings', [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        if ( isset( $input['backup_retention'] ) ) {
            $val = absint( $input['backup_retention'] );
            $settings['backup_retention'] = max( 1, min( 50, $val ) );
        }

        update_option( 'wpfd_settings', $settings );
        return new WP_REST_Response( [ 'success' => true, 'settings' => $settings ], 200 );
    }

    /* ==================================================================
       BULK NUKE
       ================================================================== */

    /** Delete multiple browser items in one request. */
    public function browser_bulk_nuke( WP_REST_Request $request ): WP_REST_Response {
        $items = $request->get_param( 'items' );
        if ( ! is_array( $items ) || empty( $items ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Items required.' ], 400 );
        }

        $results = [];
        foreach ( $items as $item ) {
            $alias   = sanitize_key( $item['root'] ?? '' );
            $relpath = WPFD_Browser::sanitize_rel_path( $item['path'] ?? '' );
            if ( empty( $relpath ) ) {
                $results[] = [ 'path' => $relpath, 'success' => false, 'message' => 'Empty path.' ];
                continue;
            }

            $root_abs = WPFD_Browser::resolve_root( $alias );
            if ( $root_abs === false ) {
                $results[] = [ 'path' => $relpath, 'success' => false, 'message' => 'Invalid root.' ];
                continue;
            }

            $joined   = $root_abs . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relpath );
            $abs_path = realpath( $joined );
            $sep      = DIRECTORY_SEPARATOR;

            if (
                $abs_path === false
                || strpos( $abs_path . $sep, $root_abs . $sep ) !== 0
            ) {
                $results[] = [ 'path' => $relpath, 'success' => false, 'message' => 'Invalid path.' ];
                continue;
            }

            $is_dir = is_dir( $abs_path );
            $nuke   = WPFD_Nuker::nuke( $abs_path, $is_dir );
            $results[] = [
                'path'    => $relpath,
                'success' => $nuke['success'],
                'error'   => $nuke['error'] ?? '',
            ];
        }

        $ok = count( array_filter( $results, fn( $r ) => $r['success'] ) );
        return new WP_REST_Response( [
            'success' => true,
            'deleted' => $ok,
            'failed'  => count( $results ) - $ok,
            'results' => $results,
        ], 200 );
    }

    /* ==================================================================
       HISTORY DELETE
       ================================================================== */

    /** Delete one or more deployment history records. */
    public function history_delete( WP_REST_Request $request ): WP_REST_Response {
        $ids = $request->get_param( 'ids' );
        if ( ! is_array( $ids ) || empty( $ids ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'IDs required.' ], 400 );
        }

        global $wpdb;
        $table       = $wpdb->prefix . 'wpfd_deployments';
        $int_ids     = array_values( array_filter( array_map( 'absint', $ids ) ) );

        if ( empty( $int_ids ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'No valid IDs.' ], 400 );
        }

        $placeholders = implode( ',', array_fill( 0, count( $int_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE id IN ({$placeholders})",
            $int_ids
        ) );

        return new WP_REST_Response( [ 'success' => true, 'deleted' => $deleted ], 200 );
    }
}
