<?php
defined( 'ABSPATH' ) || exit;

class WPFD_REST_API {

    const NAMESPACE = 'wpfd/v1';

    public function register_routes(): void {
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

        register_rest_route( self::NAMESPACE, '/backup-download', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'backup_download_stream' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/plugin-folder-installer/process', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'process_plugin_folder_install' ],
            'permission_callback' => [ $this, 'check_installer_permission' ],
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

        register_rest_route( self::NAMESPACE, '/download/plugin-token', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'download_plugin_token' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/download/serve', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'download_serve' ],
            'permission_callback' => function ( WP_REST_Request $request ) {
                $token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
                if ( empty( $token ) ) {
                    return false;
                }
                return WPFD_Downloader::validate_token( $token );
            },
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

        register_rest_route( self::NAMESPACE, '/settings/validate-backup-dir', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'validate_backup_dir_setting' ],
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

        return $this->verify_request_nonce( $request );
    }

    public function check_installer_permission( WP_REST_Request $request ): bool {
        if ( ! current_user_can( 'install_plugins' ) ) {
            return false;
        }

        return $this->verify_request_nonce( $request );
    }

    private function verify_request_nonce( WP_REST_Request $request ): bool {
        // Backup downloads hit the REST route through a native browser request,
        // so the WP REST cookie nonce and the WPFD action nonce travel separately.
        $nonce = $request->get_header( 'X-WPFD-Nonce' );
        if ( empty( $nonce ) ) {
            $nonce = $request->get_param( 'wpfd_nonce' );
        }
        if ( empty( $nonce ) ) {
            $nonce = $request->get_param( '_wpnonce' );
        }

        return (bool) wp_verify_nonce( $nonce, WPFD_Security::NONCE_ACTION );
    }

    public function process_plugin_folder_install( WP_REST_Request $request ): WP_REST_Response {
        $file_params = $request->get_file_params();
        if ( ! empty( $file_params['package'] ) ) {
            $result = WPFD_Plugin_Folder_Installer::process_uploaded_package(
                $file_params,
                sanitize_text_field( (string) $request->get_param( 'folder_name' ) ),
                get_current_user_id()
            );

            $status = empty( $result['results'] ) ? 400 : 200;
            return new WP_REST_Response( $result, $status );
        }

        $raw_relative_paths = $this->get_installer_relative_paths_param( $request );
        if ( is_wp_error( $raw_relative_paths ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'results' => [],
                'message' => $raw_relative_paths->get_error_message(),
            ], 400 );
        }

        $relative_paths = array_map(
            static fn( $path ): string => is_scalar( $path ) ? (string) $path : '',
            array_values( $raw_relative_paths )
        );

        $result = WPFD_Plugin_Folder_Installer::process_uploaded_payload(
            $file_params,
            $relative_paths,
            get_current_user_id()
        );

        $status = empty( $result['results'] ) ? 400 : 200;
        return new WP_REST_Response( $result, $status );
    }

    private function get_installer_relative_paths_param( WP_REST_Request $request ) {
        $candidates = [
            $request->get_param( 'relative_paths' ),
            $request->get_param( 'relative_paths[]' ),
            $_POST['relative_paths'] ?? null,
            $_POST['relative_paths[]'] ?? null,
        ];

        $relative_paths = [];
        foreach ( $candidates as $candidate ) {
            $normalized = $this->normalize_installer_relative_paths_param( $candidate );
            if ( ! empty( $normalized ) ) {
                $relative_paths = $normalized;
                break;
            }
        }

        if ( empty( $relative_paths ) ) {
            return new WP_Error( 'wpfd_installer_missing_manifest', 'A relative path manifest is required for folder installation.' );
        }

        return $relative_paths;
    }

    private function normalize_installer_relative_paths_param( $value ): array {
        if ( null === $value || '' === $value ) {
            return [];
        }

        if ( is_string( $value ) ) {
            $decoded = json_decode( wp_unslash( $value ), true );
            if ( is_array( $decoded ) ) {
                return $this->normalize_installer_relative_paths_param( $decoded );
            }

            $trimmed = trim( wp_unslash( $value ) );
            return ( '' === $trimmed ) ? [] : [ $trimmed ];
        }

        if ( ! is_array( $value ) ) {
            return [];
        }

        $normalized = [];
        foreach ( $value as $item ) {
            $normalized = array_merge( $normalized, $this->normalize_installer_relative_paths_param( $item ) );
        }

        return array_values( array_filter( $normalized, static fn( $path ): bool => is_string( $path ) && $path !== '' ) );
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
        return new WP_REST_Response( WPFD_Plugin_Folder_Installer::get_install_history( 50 ), 200 );
    }

    public function get_plugins(): WP_REST_Response {
        return new WP_REST_Response( WPFD_Plugin_Folder_Installer::get_installed_plugins(), 200 );
    }

    public function activate_plugin_route( WP_REST_Request $request ): WP_REST_Response {
        $plugin_file = sanitize_text_field( $request->get_param( 'plugin_file' ) );
        if ( ! function_exists( 'activate_plugin' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // M-22: Validate plugin_file against installed plugins whitelist
        $installed = get_plugins();
        if ( ! isset( $installed[ $plugin_file ] ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Plugin not found.' ], 404 );
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
        // M-22: Validate plugin_file against installed plugins whitelist
        $installed = get_plugins();
        if ( ! isset( $installed[ $plugin_file ] ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Plugin not found.' ], 404 );
        }
        deactivate_plugins( $plugin_file );
        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /** ------------------------------------------------------------------ *
     *  Backup download — deprecated; use POST /download/plugin-token.
     * ------------------------------------------------------------------ */
    public function backup_download_stream( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response(
            [
                'success' => false,
                'message' => 'Direct backup downloads are deprecated. Use the token-based download flow instead.',
            ],
            410
        );
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
                // Obfuscate absolute path: show only the last 2 directory components.
                $parts = array_filter( explode( DIRECTORY_SEPARATOR, $path ) );
                $display = '…/' . implode( '/', array_slice( $parts, -2 ) );
                $roots[ $alias ] = $display;
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

        // Block sensitive file extensions from being read via browser.
        if ( WPFD_Security::is_browser_blocked_file( $abs_path ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'This file type cannot be read via browser.' ], 403 );
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

        if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'File appears to be binary and cannot be displayed as text.',
            ], 415 );
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
            'svg', 'map', 'lock', 'pot', 'po',
        ];

        $max_files  = 200;
        $max_total  = 10 * 1024 * 1024; // 10 MB total
        $max_single = 512 * 1024;       // 512 KB per file
        $files      = [];
        $total_size = 0;
        $truncated  = false;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $abs_path, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( count( $files ) >= $max_files ) {
                $truncated = true;
                break;
            }
            if ( ! $file->isFile() || ! $file->isReadable() ) {
                continue;
            }

            $ext = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, $text_exts, true ) ) {
                continue;
            }

            $real_file = $file->getRealPath();
            if ( WPFD_Security::is_browser_blocked_file( $real_file ) ) {
                continue;
            }

            $fsize = $file->getSize();
            if ( $fsize > $max_single ) {
                continue; // skip oversized individual files silently
            }
            if ( $total_size + $fsize > $max_total ) {
                $truncated = true;
                break;
            }

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
            'truncated' => $truncated,
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

        if ( count( $items ) > 500 ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Too many items.' ], 400 );
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

    /** Generate a download token for an installed plugin or rollback snapshot. */
    public function download_plugin_token( WP_REST_Request $request ): WP_REST_Response {
        // No ZipArchive gate: directory downloads use the built-in streaming ZIP
        // writer (WPFD_Zip_Stream), which is pure PHP and needs no extension.
        $slug        = sanitize_key( $request->get_param( 'slug' ) ?? '' );
        $backup_path = sanitize_text_field( $request->get_param( 'backup_path' ) ?? '' );

        if ( $slug === '' ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Plugin slug required.' ], 400 );
        }

        $timestamp = gmdate( 'Y-m-d_H-i-s' );

        if ( $backup_path !== '' ) {
            if ( ! WPFD_Rollback::is_backup_snapshot_path( $backup_path ) || ! is_dir( $backup_path ) ) {
                return new WP_REST_Response( [ 'success' => false, 'message' => 'Backup snapshot not found.' ], 404 );
            }

            $abs_path      = (string) realpath( $backup_path );
            $download_name = $slug . '--snapshot--' . $timestamp;
        } else {
            $plugin_dir = rtrim( WPFD_DEPLOY_DIR, '/\\' ) . DIRECTORY_SEPARATOR . $slug;
            if ( ! is_dir( $plugin_dir ) ) {
                return new WP_REST_Response( [ 'success' => false, 'message' => 'Plugin directory not found.' ], 404 );
            }

            $abs_path      = (string) realpath( $plugin_dir );
            $download_name = $slug . '--installed--' . $timestamp;
        }

        $token = WPFD_Downloader::create_token(
            $abs_path,
            [
                'download_name' => $download_name,
                'zip_root_name' => $slug,
            ]
        );

        return new WP_REST_Response( [
            'success' => true,
            'token'   => $token,
            'url'     => rest_url( self::NAMESPACE . '/download/serve' ) . '?token=' . $token,
        ], 200 );
    }

    /** Serve a download — validates the token and streams the file. */
    public function download_serve( WP_REST_Request $request ): WP_REST_Response {
        $token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
        if ( empty( $token ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Token required.' ], 400 );
        }

        // A Range request is one segment of a resumable / parallel download, so it
        // must NOT burn the single-use token — peek instead, leaving the token
        // valid for the other segments. Its 5-minute TTL bounds the lifetime, and
        // IP binding stops replay. A plain (non-Range) GET consumes the token as
        // before, preserving single-use semantics for ordinary one-shot downloads.
        $is_range = ! empty( $_SERVER['HTTP_RANGE'] );
        $data     = $is_range
            ? WPFD_Downloader::peek_token( $token )
            : WPFD_Downloader::consume_token( $token );

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
            WPFD_Downloader::stream_dir_zip(
                $abs_path,
                (string) ( $data['download_name'] ?? '' ),
                (string) ( $data['zip_root_name'] ?? '' )
            );
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
            'backup_dir'       => WPFD_Rollback::get_default_backup_dir(),
            'backup_retention' => 5,
        ];
        $settings = wp_parse_args( get_option( 'wpfd_settings', [] ), $defaults );

        if ( ! empty( $settings['backup_dir'] ) ) {
            $settings['backup_dir'] = trailingslashit( wp_normalize_path( untrailingslashit( (string) $settings['backup_dir'] ) ) );
        }

        $abspath_prefix = trailingslashit( wp_normalize_path( ABSPATH ) );
        $display_dir    = str_starts_with( $settings['backup_dir'], $abspath_prefix )
            ? ltrim( substr( $settings['backup_dir'], strlen( $abspath_prefix ) ), '/' )
            : $settings['backup_dir'];

        $last_test = get_option( 'wpfd_backup_dir_last_test', [] );
        $parts     = array_filter( explode( '/', wp_normalize_path( (string) $settings['backup_dir'] ) ) );

        return new WP_REST_Response(
            [
                'success'  => true,
                'settings' => $settings,
                'display'  => [
                    'backup_dir'         => $display_dir,
                    'resolved_backup_dir'=> $settings['backup_dir'],
                    'resolved_display'   => '…/' . implode( '/', array_slice( $parts, -2 ) ),
                ],
                'health'   => [
                    'ziparchive'          => class_exists( 'ZipArchive' ),
                    'backup_dir_writable' => ! empty( $last_test['success'] ),
                    'last_test'           => is_array( $last_test ) ? $last_test : [],
                ],
            ],
            200
        );
    }

    /** Save plugin settings. */
    public function save_settings( WP_REST_Request $request ): WP_REST_Response {
        $input = $request->get_json_params();

        $settings = get_option( 'wpfd_settings', [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        $previous_backup_dir = isset( $settings['backup_dir'] ) ? (string) $settings['backup_dir'] : '';

        if ( isset( $input['backup_retention'] ) ) {
            $val = absint( $input['backup_retention'] );
            $settings['backup_retention'] = max( 1, min( 50, $val ) );
        }

        if ( isset( $input['backup_dir'] ) ) {
            $resolved = trailingslashit(
                wp_normalize_path(
                    untrailingslashit(
                        WPFD_Security::resolve_backup_dir_input( (string) $input['backup_dir'] )
                    )
                )
            );

            $validation = WPFD_Security::validate_backup_path( $resolved );
            if ( $validation !== true ) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => is_string( $validation ) ? $validation : 'Invalid backup directory.',
                    ],
                    422
                );
            }

            $test = WPFD_Rollback::test_backup_dir( $resolved );
            if ( empty( $test['success'] ) ) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => $test['message'] ?? 'Backup directory validation failed.',
                    ],
                    422
                );
            }

            $settings['backup_dir'] = $resolved;
        }

        update_option( 'wpfd_settings', $settings );

        if ( $previous_backup_dir !== '' && $previous_backup_dir !== ( $settings['backup_dir'] ?? '' ) ) {
            delete_transient( 'wpfd_bl_all' );
        }

        return $this->get_settings();
    }

    /** Validate a backup directory without saving settings. */
    public function validate_backup_dir_setting( WP_REST_Request $request ): WP_REST_Response {
        $input = $request->get_json_params();
        $path  = is_array( $input ) ? (string) ( $input['backup_dir'] ?? '' ) : '';

        $result = WPFD_Rollback::test_backup_dir( $path );
        $status = ! empty( $result['success'] ) ? 200 : 422;

        return new WP_REST_Response( $result, $status );
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

        if ( count( $items ) > 500 ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Too many items (max 500).' ], 400 );
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

        $int_ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

        if ( empty( $int_ids ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'No valid IDs.' ], 400 );
        }

        $deleted = WPFD_Plugin_Folder_Installer::delete_install_history( $int_ids );

        return new WP_REST_Response( [ 'success' => true, 'deleted' => $deleted ], 200 );
    }
}
