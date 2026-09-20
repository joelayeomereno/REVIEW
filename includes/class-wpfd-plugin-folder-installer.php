<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Upgrader_Skin' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

class WPFD_Plugin_Folder_Installer_Skin extends WP_Upgrader_Skin {

    public array $messages = [];

    public function header(): void {}

    public function footer(): void {}

    public function feedback( $feedback, ...$args ): void {
        if ( is_wp_error( $feedback ) ) {
            $this->messages[] = $feedback->get_error_message();
            return;
        }

        if ( ! is_string( $feedback ) || $feedback === '' ) {
            return;
        }

        if ( ! empty( $args ) ) {
            $feedback = vsprintf( $feedback, $args );
        }

        $this->messages[] = wp_strip_all_tags( $feedback );
    }
}

class WPFD_Plugin_Folder_Installer {

    private const LOG_TABLE_SUFFIX = 'wpfd_plugin_installer_logs';
    private const TEMP_DIR_NAME    = 'wpfd-plugin-folder-installer';

    private static bool $bootstrapped = false;

    public static function bootstrap(): void {
        if ( self::$bootstrapped ) {
            return;
        }

        self::ensure_log_table();
        self::ensure_temp_root();
        self::$bootstrapped = true;
    }

    public static function ensure_log_table(): void {
        global $wpdb;

        static $created = false;
        if ( $created ) {
            return;
        }

        $table   = self::log_table_name();
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            original_folder_name VARCHAR(191) NOT NULL,
            file_count INT UNSIGNED NOT NULL DEFAULT 0,
            zip_result VARCHAR(20) NOT NULL DEFAULT 'pending',
            zip_path TEXT DEFAULT '',
            plugin_header_result VARCHAR(20) NOT NULL DEFAULT 'pending',
            plugin_file VARCHAR(255) DEFAULT '',
            install_result VARCHAR(20) NOT NULL DEFAULT 'pending',
            activation_result VARCHAR(20) NOT NULL DEFAULT 'pending',
            backup_path TEXT DEFAULT '',
            error_message LONGTEXT DEFAULT NULL,
            details_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            admin_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY original_folder_name (original_folder_name),
            KEY created_at (created_at),
            KEY admin_user_id (admin_user_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        $created = true;
    }

    public static function process_uploaded_payload( array $files, array $relative_paths, int $user_id ): array {
        self::bootstrap();

        $entries = self::extract_uploaded_entries( $files, $relative_paths );
        if ( is_wp_error( $entries ) ) {
            return [
                'success'       => false,
                'results'       => [],
                'message'       => $entries->get_error_message(),
                'error_details' => self::wp_error_details( $entries ),
            ];
        }

        $groups  = self::group_entries_by_root( $entries );
        $results = [];

        foreach ( $groups as $root => $group_entries ) {
            try {
                $results[] = self::process_folder_group( $root, $group_entries, $user_id );
            } catch ( \Throwable $throwable ) {
                $failed = self::base_result( $root, count( $group_entries ) );
                $failed['message']              = 'Unhandled installer error.';
                $failed['status']               = 'failed';
                $failed['error_details'][]      = $throwable->getMessage();
                $failed['steps']['validation']  = self::step( 'failed', 'Folder processing aborted.' );
                $failed['steps']['zip']         = self::step( 'failed', 'ZIP creation did not start.' );
                $failed['steps']['install']     = self::step( 'failed', 'Plugin installation did not start.' );
                $failed['steps']['activation']  = self::step( 'failed', 'Plugin activation did not start.' );
                $failed['log_id']               = self::insert_log_entry( $failed, $user_id );
                $results[]                      = $failed;
            }
        }

        return [
            'success' => ! empty( $results ) && count( array_filter( $results, static fn( array $result ): bool => ! empty( $result['success'] ) ) ) === count( $results ),
            'results' => $results,
        ];
    }

    public static function process_uploaded_package( array $files, string $declared_root, int $user_id ): array {
        self::bootstrap();

        $package         = $files['package'] ?? null;
        $fallback_root   = trim( str_replace( '\\', '/', $declared_root ) );
        $result_root     = ( '' !== $fallback_root ) ? $fallback_root : 'uploaded-package';
        $normalized_root = self::normalize_package_root_name( $declared_root, is_array( $package ) ? $package : [] );

        if ( is_wp_error( $normalized_root ) ) {
            $failed                     = self::base_result( $result_root, 0 );
            $failed['message']          = $normalized_root->get_error_message();
            $failed['error_details']    = self::wp_error_details( $normalized_root );
            $failed['steps']['validation'] = self::step( 'failed', $normalized_root->get_error_message() );
            $failed['steps']['zip']        = self::step( 'failed', 'ZIP package validation did not start.' );
            $failed['steps']['install']    = self::step( 'failed', 'Plugin installation did not start.' );
            $failed['steps']['activation'] = self::step( 'failed', 'Plugin activation did not start.' );

            return [
                'success' => false,
                'results' => [ $failed ],
            ];
        }

        if ( ! is_array( $package ) ) {
            $failed                     = self::base_result( $normalized_root, 0 );
            $failed['message']          = 'No uploaded ZIP package was received.';
            $failed['error_details'][]  = 'The installer expected a packaged plugin ZIP for this request.';
            $failed['steps']['validation'] = self::step( 'failed', 'Folder validation did not start.' );
            $failed['steps']['zip']        = self::step( 'failed', 'No uploaded ZIP package was received.' );
            $failed['steps']['install']    = self::step( 'failed', 'Plugin installation did not start.' );
            $failed['steps']['activation'] = self::step( 'failed', 'Plugin activation did not start.' );

            return [
                'success' => false,
                'results' => [ $failed ],
            ];
        }

        try {
            $result = self::process_uploaded_package_group( $normalized_root, $package, $user_id );
        } catch ( \Throwable $throwable ) {
            $failed                     = self::base_result( $normalized_root, 0 );
            $failed['message']          = 'Unhandled installer error.';
            $failed['status']           = 'failed';
            $failed['error_details'][]  = $throwable->getMessage();
            $failed['steps']['validation'] = self::step( 'failed', 'Folder processing aborted.' );
            $failed['steps']['zip']        = self::step( 'failed', 'ZIP package processing aborted.' );
            $failed['steps']['install']    = self::step( 'failed', 'Plugin installation did not start.' );
            $failed['steps']['activation'] = self::step( 'failed', 'Plugin activation did not start.' );
            $failed['log_id']              = self::insert_log_entry( $failed, $user_id );
            $result                        = $failed;
        }

        return [
            'success' => ! empty( $result['success'] ),
            'results' => [ $result ],
        ];
    }

    public static function detect_main_plugin_file( string $plugin_slug ): ?string {
        $plugin_dir = WPFD_DEPLOY_DIR . $plugin_slug . '/';
        if ( is_dir( $plugin_dir ) ) {
            if ( ! function_exists( 'get_file_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            foreach ( glob( $plugin_dir . '*.php' ) ?: [] as $candidate ) {
                $data = get_file_data( $candidate, [ 'Name' => 'Plugin Name' ], 'plugin' );
                if ( ! empty( $data['Name'] ) ) {
                    return $plugin_slug . '/' . basename( $candidate );
                }
            }
        }

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

    public static function get_install_history( int $limit = 50 ): array {
        global $wpdb;

        self::ensure_log_table();

        $limit = max( 1, $limit );
        $table = self::log_table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT SQL_CALC_FOUND_ROWS l.*, u.user_login
                 FROM {$table} l
                 LEFT JOIN {$wpdb->users} u ON u.ID = l.admin_user_id
                 ORDER BY l.created_at DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];

        $total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );

        $mapped_rows = array_map( static function ( array $row ): array {
            $details = [];
            if ( ! empty( $row['details_json'] ) && is_string( $row['details_json'] ) ) {
                $decoded = json_decode( $row['details_json'], true );
                if ( is_array( $decoded ) ) {
                    $details = $decoded;
                }
            }

            $timings = ( ! empty( $details['timings'] ) && is_array( $details['timings'] ) ) ? $details['timings'] : [];
            $plugin_slug = '';

            if ( ! empty( $details['installed_slug'] ) && is_string( $details['installed_slug'] ) ) {
                $plugin_slug = $details['installed_slug'];
            } elseif ( ! empty( $details['original_folder_name'] ) && is_string( $details['original_folder_name'] ) ) {
                $plugin_slug = $details['original_folder_name'];
            } else {
                $plugin_slug = (string) $row['original_folder_name'];
            }

            $status = ( 'success' === ( $row['install_result'] ?? '' ) && 'success' === ( $row['activation_result'] ?? '' ) )
                ? 'success'
                : 'failed';

            return array_merge( $row, [
                'plugin_slug' => sanitize_key( $plugin_slug ),
                'version'     => ( ! empty( $details['version'] ) && is_string( $details['version'] ) ) ? $details['version'] : '',
                'deploy_time' => $row['created_at'],
                'status'      => $status,
                'deploy_mode' => 'plugin-folder-installer',
                'skipped'     => 0,
                'elapsed_ms'  => isset( $timings['total_ms'] ) ? (int) $timings['total_ms'] : 0,
                'deployed_by' => (int) $row['admin_user_id'],
                'notes'       => '',
            ] );
        }, $rows );

        return [
            'rows'      => $mapped_rows,
            'total'     => $total,
            'truncated' => $total > $limit,
        ];
    }

    public static function delete_install_history( array $ids ): int {
        global $wpdb;

        self::ensure_log_table();

        $int_ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
        if ( empty( $int_ids ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $int_ids ), '%d' ) );
        $table        = self::log_table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE id IN ({$placeholders})",
            ...$int_ids
        ) );

        return (int) $deleted;
    }

    private static function process_uploaded_package_group( string $declared_root, array $package, int $user_id ): array {
        $job_id      = wp_generate_uuid4();
        $job_dir     = trailingslashit( self::ensure_temp_root() ) . $job_id . '/';
        $source_dir  = $job_dir . 'source/';
        $package_dir = $job_dir . 'packages/';

        self::create_protected_dir( $job_dir );
        WPFD_Filesystem::mkdir_recursive( $source_dir );
        self::create_protected_dir( $package_dir );

        $result = self::base_result( $declared_root, 0 );
        self::append_debug_trace( $result, 'temp', 'Prepared temporary workspace for uploaded package processing.', [
            'package_size' => (int) ( $package['size'] ?? 0 ),
        ] );

        try {
            $uploaded_zip_path = self::stage_uploaded_package( $package_dir, $declared_root, $package );
            self::append_debug_trace( $result, 'upload', 'Accepted uploaded ZIP package for folder installation.', [
                'package_size' => file_exists( $uploaded_zip_path ) ? (int) filesize( $uploaded_zip_path ) : 0,
            ] );

            $extracted = self::extract_uploaded_package_to_source( $uploaded_zip_path, $source_dir, $declared_root );
            if ( is_wp_error( $extracted ) ) {
                $result['message']              = $extracted->get_error_message();
                $result['error_details']        = self::wp_error_details( $extracted );
                $result['steps']['validation']  = self::step( 'failed', 'Folder validation did not start because the uploaded ZIP package was invalid.' );
                $result['steps']['zip']         = self::step( 'failed', $extracted->get_error_message() );
                $result['steps']['install']     = self::step( 'failed', 'Plugin installation did not start.' );
                $result['steps']['activation']  = self::step( 'failed', 'Plugin activation did not start.' );
                $result['log_id']               = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            $plugin_root = $extracted['plugin_root'];
            $root        = $extracted['root'];
            self::append_debug_trace( $result, 'zip', 'Extracted the uploaded ZIP package into the temporary workspace.', [
                'root' => $root,
            ] );

            $validation = self::validate_folder_tree( $plugin_root, $root );
            if ( empty( $validation['success'] ) ) {
                self::append_debug_trace( $result, 'validation', 'Folder validation failed.', [
                    'error_count' => count( $validation['errors'] ),
                ] );
                $result['message']              = $validation['message'];
                $result['file_count']           = (int) ( $validation['file_count'] ?? 0 );
                $result['steps']['validation']  = self::step( 'failed', $validation['message'] );
                $result['steps']['zip']         = self::step( 'success', 'Uploaded ZIP package was extracted successfully.' );
                $result['steps']['install']     = self::step( 'failed', 'Plugin installation skipped.' );
                $result['steps']['activation']  = self::step( 'failed', 'Plugin activation skipped.' );
                $result['error_details']        = $validation['errors'];
                $result['log_id']               = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            $result['file_count']            = (int) ( $validation['file_count'] ?? 0 );
            $result['header_detected']       = true;
            $result['header_relative_path']  = $validation['header_relative_path'];
            $result['plugin_name']           = $validation['plugin_name'];
            $result['steps']['validation']   = self::step( 'success', $validation['message'] );
            self::append_debug_trace( $result, 'validation', 'Folder validation succeeded.', [
                'plugin_name'          => $validation['plugin_name'],
                'header_relative_path' => $validation['header_relative_path'],
                'file_count'           => (int) ( $validation['file_count'] ?? 0 ),
            ] );

            $zip_path = $package_dir . $root . '.zip';
            $zip      = self::create_plugin_zip( $plugin_root, $root, $zip_path );
            if ( empty( $zip['success'] ) ) {
                self::append_debug_trace( $result, 'zip', 'Temporary ZIP package creation failed.', [
                    'error_count' => count( $zip['errors'] ),
                ] );
                $result['message']              = $zip['message'];
                $result['zip_path']             = $zip_path;
                $result['steps']['zip']         = self::step( 'failed', $zip['message'] );
                $result['steps']['install']     = self::step( 'failed', 'Plugin installation skipped.' );
                $result['steps']['activation']  = self::step( 'failed', 'Plugin activation skipped.' );
                $result['error_details']        = array_merge( $result['error_details'], $zip['errors'] );
                $result['log_id']               = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            $result['zip_path']              = $zip_path;
            $result['steps']['zip']          = self::step( 'success', $zip['message'] );
            self::append_debug_trace( $result, 'zip', 'Created and verified the temporary ZIP package.', [
                'zip_size' => file_exists( $zip_path ) ? (int) filesize( $zip_path ) : 0,
            ] );

            $backup = WPFD_Rollback::stage_deploy_backup( $root );
            if ( empty( $backup['success'] ) ) {
                self::append_debug_trace( $result, 'backup', 'Deploy backup staging failed.', [] );
                $result['message']              = $backup['message'];
                $result['steps']['install']     = self::step( 'failed', $backup['message'] );
                $result['steps']['activation']  = self::step( 'failed', 'Plugin activation skipped.' );
                $result['error_details'][]      = $backup['message'];
                $result['log_id']               = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            self::attach_backup_metadata( $result, $backup );
            self::append_debug_trace( $result, 'backup', 'Prepared rollback backup for the destination plugin.', [
                'has_backup' => ( '' !== $result['backup_path'] ),
                'strategy'   => $result['backup_meta']['strategy'] ?? '',
                'verified'   => ! empty( $result['backup_meta']['verified'] ),
                'files'      => (int) ( $result['backup_meta']['files'] ?? 0 ),
                'bytes'      => (int) ( $result['backup_meta']['bytes'] ?? 0 ),
            ] );

            $install = self::install_plugin_zip( $zip_path, $result['backup_path'] !== '' );
            if ( empty( $install['success'] ) ) {
                self::append_debug_trace( $result, 'install', 'Plugin installation from the generated ZIP failed.', [
                    'error_count' => count( $install['errors'] ),
                ] );
                $result['message']              = $install['message'];
                $result['steps']['install']     = self::step( 'failed', $install['message'] );
                $result['steps']['activation']  = self::step( 'failed', 'Plugin activation skipped.' );
                $result['error_details']        = array_merge( $result['error_details'], $install['errors'] );

                if ( $result['backup_path'] !== '' ) {
                    $rollback = WPFD_Rollback::restore( $root, $result['backup_path'] );
                    $result['rollback'] = $rollback;
                    if ( ! empty( $rollback['message'] ) ) {
                        $result['error_details'][] = $rollback['message'];
                    }
                }

                $result['log_id'] = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            $installed_slug             = $install['installed_slug'];
            $result['steps']['install'] = self::step( 'success', $install['message'] );
            $result['installed_slug']   = $installed_slug;
            self::append_debug_trace( $result, 'install', 'Plugin installation from the generated ZIP succeeded.', [
                'installed_slug' => $installed_slug,
            ] );

            $plugin_file = self::detect_installed_plugin_file( $installed_slug, $validation['header_relative_path'] );
            if ( $plugin_file === '' ) {
                self::append_debug_trace( $result, 'activation', 'Installed plugin file could not be resolved after installation.', [] );
                $result['message']              = 'Plugin installed, but no activatable plugin file was found.';
                $result['steps']['activation']  = self::step( 'failed', 'No plugin header file could be resolved after install.' );
                $result['error_details'][]      = 'The installed plugin directory did not expose a detectable WordPress plugin header.';

                if ( $result['backup_path'] !== '' ) {
                    $rollback = WPFD_Rollback::restore( $root, $result['backup_path'] );
                    $result['rollback'] = $rollback;
                    if ( ! empty( $rollback['message'] ) ) {
                        $result['error_details'][] = $rollback['message'];
                    }
                }

                $result['log_id'] = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            $activation = activate_plugin( $plugin_file );
            if ( is_wp_error( $activation ) ) {
                self::append_debug_trace( $result, 'activation', 'Plugin activation failed.', [
                    'plugin_file' => $plugin_file,
                ] );
                $result['plugin_file']          = $plugin_file;
                $result['message']              = 'Plugin installed, but activation failed.';
                $result['steps']['activation']  = self::step( 'failed', $activation->get_error_message() );
                $result['error_details'][]      = $activation->get_error_message();

                if ( $result['backup_path'] !== '' ) {
                    $rollback = WPFD_Rollback::restore( $root, $result['backup_path'] );
                    $result['rollback'] = $rollback;
                    if ( ! empty( $rollback['message'] ) ) {
                        $result['error_details'][] = $rollback['message'];
                    }
                }

                $result['log_id'] = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            $result['success']             = true;
            $result['status']              = 'success';
            $result['plugin_file']         = $plugin_file;
            $result['message']             = 'Installed and activated successfully.';
            $result['steps']['activation'] = self::step( 'success', 'Plugin activated successfully.' );
            self::append_debug_trace( $result, 'activation', 'Plugin activation succeeded.', [
                'plugin_file' => $plugin_file,
            ] );
            $result['log_id']              = self::insert_log_entry( $result, $user_id );

            return $result;
        } finally {
            self::write_debug_log( 'cleanup', 'Cleaning temporary installer workspace.', [
                'root' => $declared_root,
            ] );
            if ( is_dir( $job_dir ) ) {
                WPFD_Filesystem::delete_dir( $job_dir );
            }
        }
    }

    private static function normalize_package_root_name( string $declared_root, array $package ) {
        $candidate = trim( str_replace( '\\', '/', $declared_root ) );
        if ( '' === $candidate && ! empty( $package['name'] ) && is_string( $package['name'] ) ) {
            $candidate = pathinfo( $package['name'], PATHINFO_FILENAME );
        }

        if ( '' === $candidate ) {
            return new WP_Error( 'wpfd_installer_missing_root', 'The uploaded package did not include a plugin folder name.' );
        }

        $validated = self::validate_relative_path( trim( $candidate, '/' ) . '/placeholder.php' );
        if ( is_wp_error( $validated ) ) {
            return new WP_Error(
                'wpfd_installer_invalid_package_root',
                'The uploaded package used an invalid plugin folder name.',
                $validated->get_error_data()
            );
        }

        $segments = explode( '/', $validated );
        return (string) array_shift( $segments );
    }

    private static function stage_uploaded_package( string $package_dir, string $declared_root, array $package ): string {
        if ( (int) ( $package['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
            throw new RuntimeException( 'The uploaded ZIP package failed during transfer.' );
        }

        $tmp_name = (string) ( $package['tmp_name'] ?? '' );
        if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
            throw new RuntimeException( 'The uploaded ZIP package is invalid.' );
        }

        $original_name = (string) ( $package['name'] ?? '' );
        if ( strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) !== 'zip' ) {
            throw new RuntimeException( 'The uploaded package must be a ZIP archive.' );
        }

        $staged_path = trailingslashit( $package_dir ) . $declared_root . '--upload.zip';
        if ( ! @copy( $tmp_name, $staged_path ) ) {
            throw new RuntimeException( 'The installer could not stage the uploaded ZIP package.' );
        }

        @chmod( $staged_path, 0644 );
        return $staged_path;
    }

    private static function extract_uploaded_package_to_source( string $zip_path, string $source_dir, string $declared_root ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();
        $unzipped = unzip_file( $zip_path, $source_dir );
        if ( is_wp_error( $unzipped ) ) {
            return new WP_Error(
                'wpfd_installer_package_extract_failed',
                'The uploaded ZIP package could not be extracted into temporary storage.',
                [ 'reason' => $unzipped->get_error_message() ]
            );
        }

        return self::resolve_extracted_package_root( $source_dir, $declared_root );
    }

    private static function resolve_extracted_package_root( string $source_dir, string $declared_root ) {
        $entries = array_values( array_filter( scandir( $source_dir ) ?: [], static function ( string $entry ): bool {
            if ( in_array( $entry, [ '.', '..' ], true ) ) {
                return false;
            }

            return ! self::should_ignore_package_root_entry( $entry );
        } ) );

        $directories = [];
        $files       = [];

        foreach ( $entries as $entry ) {
            $full_path = trailingslashit( $source_dir ) . $entry;
            if ( is_dir( $full_path ) ) {
                $directories[] = $entry;
                continue;
            }

            $files[] = $entry;
        }

        if ( count( $directories ) !== 1 || ! empty( $files ) ) {
            return new WP_Error(
                'wpfd_installer_invalid_package_structure',
                'The uploaded ZIP package must contain exactly one top-level plugin folder.',
                [
                    'top_level_directories' => implode( ', ', $directories ),
                    'top_level_files'       => implode( ', ', $files ),
                ]
            );
        }

        $resolved_root = $directories[0];
        if ( '' !== $declared_root && $resolved_root !== $declared_root ) {
            return new WP_Error(
                'wpfd_installer_package_root_mismatch',
                'The uploaded ZIP package did not preserve the selected plugin folder name.',
                [
                    'expected_root' => $declared_root,
                    'found_root'    => $resolved_root,
                ]
            );
        }

        return [
            'root'        => $resolved_root,
            'plugin_root' => trailingslashit( $source_dir ) . $resolved_root,
        ];
    }

    private static function should_ignore_package_root_entry( string $entry ): bool {
        return ( $entry === '__MACOSX' || str_starts_with( $entry, '.' ) );
    }

    private static function wp_error_details( WP_Error $error ): array {
        $error_data    = $error->get_error_data();
        $error_details = [];
        if ( is_array( $error_data ) ) {
            foreach ( $error_data as $key => $value ) {
                if ( is_scalar( $value ) ) {
                    $error_details[] = sprintf( '%s: %s', (string) $key, (string) $value );
                    continue;
                }

                if ( is_array( $value ) ) {
                    $error_details[] = sprintf( '%s: %s', (string) $key, wp_json_encode( $value ) );
                }
            }
        }

        return $error_details;
    }

    private static function process_folder_group( string $root, array $entries, int $user_id ): array {
        $job_id      = wp_generate_uuid4();
        $job_dir     = trailingslashit( self::ensure_temp_root() ) . $job_id . '/';
        $source_dir  = $job_dir . 'source/';
        $package_dir = $job_dir . 'packages/';

        self::create_protected_dir( $job_dir );
        WPFD_Filesystem::mkdir_recursive( $source_dir );
        self::create_protected_dir( $package_dir );

        $result = self::base_result( $root, count( $entries ) );
        self::append_debug_trace( $result, 'temp', 'Prepared temporary workspace for uploaded folder processing.', [
            'file_count' => count( $entries ),
        ] );

        try {
            $plugin_root = self::stage_group_files( $source_dir, $root, $entries );
            self::append_debug_trace( $result, 'staging', 'Rebuilt the uploaded folder tree in temporary storage.', [
                'root'       => $root,
                'file_count' => count( $entries ),
            ] );

            $validation = self::validate_folder_tree( $plugin_root, $root );
            if ( empty( $validation['success'] ) ) {
                self::append_debug_trace( $result, 'validation', 'Folder validation failed.', [
                    'error_count' => count( $validation['errors'] ),
                ] );
                $result['status']                      = 'failed';
                $result['message']                     = $validation['message'];
                $result['steps']['validation']         = self::step( 'failed', $validation['message'] );
                $result['steps']['zip']                = self::step( 'failed', 'ZIP creation skipped.' );
                $result['steps']['install']            = self::step( 'failed', 'Plugin installation skipped.' );
                $result['steps']['activation']         = self::step( 'failed', 'Plugin activation skipped.' );
                $result['header_detected']             = false;
                $result['error_details']               = $validation['errors'];
                $result['log_id']                      = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            $result['header_detected']             = true;
            $result['header_relative_path']        = $validation['header_relative_path'];
            $result['plugin_name']                 = $validation['plugin_name'];
            $result['steps']['validation']         = self::step( 'success', $validation['message'] );
            self::append_debug_trace( $result, 'validation', 'Folder validation succeeded.', [
                'plugin_name'          => $validation['plugin_name'],
                'header_relative_path' => $validation['header_relative_path'],
            ] );

            $zip_path = $package_dir . $root . '.zip';
            $zip      = self::create_plugin_zip( $plugin_root, $root, $zip_path );
            if ( empty( $zip['success'] ) ) {
                self::append_debug_trace( $result, 'zip', 'Temporary ZIP package creation failed.', [
                    'error_count' => count( $zip['errors'] ),
                ] );
                $result['status']                      = 'failed';
                $result['message']                     = $zip['message'];
                $result['zip_path']                    = $zip_path;
                $result['steps']['zip']                = self::step( 'failed', $zip['message'] );
                $result['steps']['install']            = self::step( 'failed', 'Plugin installation skipped.' );
                $result['steps']['activation']         = self::step( 'failed', 'Plugin activation skipped.' );
                $result['error_details']               = array_merge( $result['error_details'], $zip['errors'] );
                $result['log_id']                      = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            $result['zip_path']                = $zip_path;
            $result['steps']['zip']            = self::step( 'success', $zip['message'] );
            self::append_debug_trace( $result, 'zip', 'Created and verified the temporary ZIP package.', [
                'zip_size' => file_exists( $zip_path ) ? (int) filesize( $zip_path ) : 0,
            ] );

            $backup = WPFD_Rollback::stage_deploy_backup( $root );
            if ( empty( $backup['success'] ) ) {
                self::append_debug_trace( $result, 'backup', 'Deploy backup staging failed.', [] );
                $result['status']                      = 'failed';
                $result['message']                     = $backup['message'];
                $result['steps']['install']            = self::step( 'failed', $backup['message'] );
                $result['steps']['activation']         = self::step( 'failed', 'Plugin activation skipped.' );
                $result['error_details'][]             = $backup['message'];
                $result['log_id']                      = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            self::attach_backup_metadata( $result, $backup );
            self::append_debug_trace( $result, 'backup', 'Prepared rollback backup for the destination plugin.', [
                'has_backup' => ( '' !== $result['backup_path'] ),
                'strategy'   => $result['backup_meta']['strategy'] ?? '',
                'verified'   => ! empty( $result['backup_meta']['verified'] ),
                'files'      => (int) ( $result['backup_meta']['files'] ?? 0 ),
                'bytes'      => (int) ( $result['backup_meta']['bytes'] ?? 0 ),
            ] );

            $install = self::install_plugin_zip( $zip_path, $result['backup_path'] !== '' );
            if ( empty( $install['success'] ) ) {
                self::append_debug_trace( $result, 'install', 'Plugin installation from the generated ZIP failed.', [
                    'error_count' => count( $install['errors'] ),
                ] );
                $result['status']               = 'failed';
                $result['message']              = $install['message'];
                $result['steps']['install']     = self::step( 'failed', $install['message'] );
                $result['steps']['activation']  = self::step( 'failed', 'Plugin activation skipped.' );
                $result['error_details']        = array_merge( $result['error_details'], $install['errors'] );

                if ( $result['backup_path'] !== '' ) {
                    $rollback = WPFD_Rollback::restore( $root, $result['backup_path'] );
                    $result['rollback'] = $rollback;
                    if ( ! empty( $rollback['message'] ) ) {
                        $result['error_details'][] = $rollback['message'];
                    }
                }

                $result['log_id'] = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            $installed_slug               = $install['installed_slug'];
            $result['steps']['install']   = self::step( 'success', $install['message'] );
            $result['installed_slug']     = $installed_slug;
            self::append_debug_trace( $result, 'install', 'Plugin installation from the generated ZIP succeeded.', [
                'installed_slug' => $installed_slug,
            ] );

            $plugin_file = self::detect_installed_plugin_file( $installed_slug, $validation['header_relative_path'] );
            if ( $plugin_file === '' ) {
                self::append_debug_trace( $result, 'activation', 'Installed plugin file could not be resolved after installation.', [] );
                $result['status']               = 'failed';
                $result['message']              = 'Plugin installed, but no activatable plugin file was found.';
                $result['steps']['activation']  = self::step( 'failed', 'No plugin header file could be resolved after install.' );
                $result['error_details'][]      = 'The installed plugin directory did not expose a detectable WordPress plugin header.';

                if ( $result['backup_path'] !== '' ) {
                    $rollback = WPFD_Rollback::restore( $root, $result['backup_path'] );
                    $result['rollback'] = $rollback;
                    if ( ! empty( $rollback['message'] ) ) {
                        $result['error_details'][] = $rollback['message'];
                    }
                }

                $result['log_id'] = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            $activation = activate_plugin( $plugin_file );
            if ( is_wp_error( $activation ) ) {
                self::append_debug_trace( $result, 'activation', 'Plugin activation failed.', [
                    'plugin_file' => $plugin_file,
                ] );
                $result['status']               = 'failed';
                $result['plugin_file']          = $plugin_file;
                $result['message']              = 'Plugin installed, but activation failed.';
                $result['steps']['activation']  = self::step( 'failed', $activation->get_error_message() );
                $result['error_details'][]      = $activation->get_error_message();

                if ( $result['backup_path'] !== '' ) {
                    $rollback = WPFD_Rollback::restore( $root, $result['backup_path'] );
                    $result['rollback'] = $rollback;
                    if ( ! empty( $rollback['message'] ) ) {
                        $result['error_details'][] = $rollback['message'];
                    }
                }

                $result['log_id'] = self::insert_log_entry( $result, $user_id );
                return $result;
            }

            $result['success']                = true;
            $result['status']                 = 'success';
            $result['plugin_file']            = $plugin_file;
            $result['message']                = 'Installed and activated successfully.';
            $result['steps']['activation']    = self::step( 'success', 'Plugin activated successfully.' );
            self::append_debug_trace( $result, 'activation', 'Plugin activation succeeded.', [
                'plugin_file' => $plugin_file,
            ] );
            $result['log_id']                 = self::insert_log_entry( $result, $user_id );

            return $result;
        } finally {
            self::write_debug_log( 'cleanup', 'Cleaning temporary installer workspace.', [
                'root' => $root,
            ] );
            if ( is_dir( $job_dir ) ) {
                WPFD_Filesystem::delete_dir( $job_dir );
            }
        }
    }

    private static function extract_uploaded_entries( array $files, array $relative_paths ) {
        $normalized_files = self::normalize_files_array( $files );
        if ( empty( $normalized_files ) ) {
            return new WP_Error( 'wpfd_installer_no_files', 'No uploaded files were received.' );
        }

        if ( count( $normalized_files ) !== count( $relative_paths ) ) {
            return new WP_Error( 'wpfd_installer_manifest_mismatch', 'Uploaded file manifest does not match the received files.', [
                'received_files'  => count( $normalized_files ),
                'manifest_paths'  => count( $relative_paths ),
                'file_param_keys' => implode( ', ', array_keys( $files ) ),
                'ordered_fields'  => implode( ', ', array_map( static fn( array $file ): string => (string) ( $file['field_key'] ?? 'unknown' ), $normalized_files ) ),
            ] );
        }

        $entries = [];
        foreach ( $normalized_files as $index => $file ) {
            if ( (int) $file['error'] !== UPLOAD_ERR_OK ) {
                return new WP_Error( 'wpfd_installer_upload_error', 'One or more files failed to upload.' );
            }

            if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
                return new WP_Error( 'wpfd_installer_invalid_upload', 'One or more uploaded files are invalid.' );
            }

            $raw_path = (string) $relative_paths[ $index ];
            $path     = self::validate_relative_path( $raw_path );
            if ( is_wp_error( $path ) ) {
                return $path;
            }

            $segments = explode( '/', $path );
            $root     = array_shift( $segments );
            if ( $root === '' ) {
                return new WP_Error( 'wpfd_installer_invalid_root', 'Uploaded files must preserve a top-level plugin folder.' );
            }

            $entries[] = [
                'root'          => $root,
                'relative_path' => $path,
                'tmp_name'      => $file['tmp_name'],
                'name'          => (string) $file['name'],
                'size'          => (int) $file['size'],
            ];
        }

        return $entries;
    }

    private static function normalize_files_array( array $files ): array {
        return self::sort_uploaded_files_by_client_index( self::collect_uploaded_files( $files ) );
    }

    private static function collect_uploaded_files( array $files, string $field_key = '' ): array {
        if ( isset( $files['tmp_name'] ) && ! is_array( $files['tmp_name'] ) ) {
            return [ [
                'name'     => $files['name'] ?? '',
                'type'     => $files['type'] ?? '',
                'tmp_name' => $files['tmp_name'] ?? '',
                'error'    => $files['error'] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $files['size'] ?? 0,
                'field_key'    => $field_key,
                'client_index' => self::extract_uploaded_file_index( $field_key ),
            ] ];
        }

        if ( isset( $files['tmp_name'] ) && is_array( $files['tmp_name'] ) ) {
            $normalized = [];
            foreach ( array_keys( $files['tmp_name'] ) as $index ) {
                $child_field_key = self::append_file_field_key( $field_key, (string) $index );
                $normalized = array_merge( $normalized, self::collect_uploaded_files( [
                    'name'     => $files['name'][ $index ] ?? '',
                    'type'     => $files['type'][ $index ] ?? '',
                    'tmp_name' => $files['tmp_name'][ $index ] ?? '',
                    'error'    => $files['error'][ $index ] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $files['size'][ $index ] ?? 0,
                ], $child_field_key ) );
            }
            return $normalized;
        }

        $normalized = [];
        foreach ( $files as $key => $file ) {
            if ( ! is_array( $file ) ) {
                continue;
            }

            $normalized = array_merge( $normalized, self::collect_uploaded_files( $file, (string) $key ) );
        }

        return $normalized;
    }

    private static function sort_uploaded_files_by_client_index( array $files ): array {
        $decorated = [];

        foreach ( $files as $order => $file ) {
            $file['discovery_order'] = $order;
            $decorated[]             = $file;
        }

        usort( $decorated, static function ( array $left, array $right ): int {
            $left_has_index  = array_key_exists( 'client_index', $left ) && null !== $left['client_index'];
            $right_has_index = array_key_exists( 'client_index', $right ) && null !== $right['client_index'];

            if ( $left_has_index && $right_has_index && $left['client_index'] !== $right['client_index'] ) {
                return $left['client_index'] <=> $right['client_index'];
            }

            if ( $left_has_index !== $right_has_index ) {
                return $left_has_index ? -1 : 1;
            }

            return (int) ( $left['discovery_order'] ?? 0 ) <=> (int) ( $right['discovery_order'] ?? 0 );
        } );

        return array_map( static function ( array $file ): array {
            unset( $file['discovery_order'] );
            return $file;
        }, $decorated );
    }

    private static function append_file_field_key( string $field_key, string $segment ): string {
        if ( $field_key === '' ) {
            return $segment;
        }

        return sprintf( '%s[%s]', $field_key, $segment );
    }

    private static function extract_uploaded_file_index( string $field_key ): ?int {
        if ( $field_key === '' ) {
            return null;
        }

        if ( preg_match( '/(?:^|_)file_(\d+)$/', $field_key, $match ) ) {
            return (int) $match[1];
        }

        if ( preg_match( '/\[(\d+)\](?!.*\[\d+\])/', $field_key, $match ) ) {
            return (int) $match[1];
        }

        return null;
    }

    private static function validate_relative_path( string $raw_path ) {
        $normalized = str_replace( '\\', '/', trim( $raw_path ) );
        if ( $normalized === '' ) {
            return new WP_Error( 'wpfd_installer_empty_path', 'Uploaded files must include their original relative path.' );
        }

        if ( str_contains( $normalized, "\0" ) || str_starts_with( $normalized, '/' ) || preg_match( '/^[A-Za-z]:\//', $normalized ) ) {
            return new WP_Error( 'wpfd_installer_unsafe_path', 'Unsafe absolute file path detected in upload payload.' );
        }

        if ( str_contains( $normalized, '../' ) || str_contains( $normalized, '/..' ) || preg_match( '#(^|/)\.{1,2}(/|$)#', $normalized ) ) {
            return new WP_Error( 'wpfd_installer_traversal', 'Path traversal was detected in the uploaded folder structure.' );
        }

        if ( str_contains( $normalized, '//' ) ) {
            return new WP_Error( 'wpfd_installer_invalid_path', 'Uploaded paths must preserve a valid relative directory structure.' );
        }

        $segments = explode( '/', $normalized );
        foreach ( $segments as $segment ) {
            if ( $segment === '' ) {
                return new WP_Error( 'wpfd_installer_invalid_path', 'Uploaded paths must preserve a valid relative directory structure.' );
            }

            if ( preg_match( '/[\x00-\x1f\x7f]/', $segment ) ) {
                return new WP_Error( 'wpfd_installer_unsafe_path', 'Unsafe control characters were detected in an uploaded file path.' );
            }

            if ( strpbrk( $segment, '<>:"|?*' ) !== false ) {
                return new WP_Error( 'wpfd_installer_unsafe_path', 'Unsafe file or folder names were detected in the uploaded path.' );
            }
        }

        return $normalized;
    }

    private static function group_entries_by_root( array $entries ): array {
        $grouped = [];
        foreach ( $entries as $entry ) {
            $grouped[ $entry['root'] ][] = $entry;
        }
        return $grouped;
    }

    private static function stage_group_files( string $source_dir, string $root, array $entries ): string {
        $plugin_root = trailingslashit( $source_dir ) . $root;
        WPFD_Filesystem::mkdir_recursive( $plugin_root );

        foreach ( $entries as $entry ) {
            if ( $entry['root'] !== $root ) {
                throw new RuntimeException( 'Mismatched root folder detected while staging upload payload.' );
            }

            $destination = self::safe_join_path( $source_dir, $entry['relative_path'] );
            if ( $destination === '' ) {
                throw new RuntimeException( 'A file path escaped the temporary working directory.' );
            }

            WPFD_Filesystem::mkdir_recursive( dirname( $destination ) );
            if ( ! @copy( $entry['tmp_name'], $destination ) ) {
                throw new RuntimeException( sprintf( 'Failed to stage uploaded file: %s', $entry['relative_path'] ) );
            }

            @chmod( $destination, 0644 );
        }

        return $plugin_root;
    }

    private static function validate_folder_tree( string $plugin_root, string $root ): array {
        $errors    = [];
        $file_count = 0;
        $php_files  = [];

        if ( ! is_dir( $plugin_root ) ) {
            return [
                'success' => false,
                'message' => 'Uploaded plugin folder could not be rebuilt in the temporary workspace.',
                'errors'  => [ 'Temporary plugin folder is missing.' ],
            ];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $plugin_root, RecursiveDirectoryIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $item ) {
            if ( ! $item->isFile() ) {
                continue;
            }

            $file_count++;
            if ( strtolower( $item->getExtension() ) === 'php' ) {
                $php_files[] = $item->getPathname();
            }
        }

        if ( $file_count < 1 ) {
            $errors[] = 'The uploaded plugin folder is empty.';
        }

        if ( empty( $php_files ) ) {
            $errors[] = 'No PHP files were found in the uploaded plugin folder.';
        }

        if ( ! empty( $errors ) ) {
            return [
                'success' => false,
                'message' => $errors[0],
                'errors'  => $errors,
            ];
        }

        if ( ! function_exists( 'get_file_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $header_relative_path = '';
        $plugin_name          = '';

        foreach ( $php_files as $php_file ) {
            $header = get_file_data( $php_file, [ 'Name' => 'Plugin Name' ], 'plugin' );
            if ( ! empty( $header['Name'] ) ) {
                $header_relative_path = ltrim( str_replace( wp_normalize_path( $plugin_root ), '', wp_normalize_path( $php_file ) ), '/' );
                $plugin_name          = $header['Name'];
                break;
            }
        }

        if ( $header_relative_path === '' ) {
            return [
                'success' => false,
                'message' => 'No valid WordPress plugin header was found.',
                'errors'  => [ 'At least one PHP file in the uploaded folder must contain a valid WordPress plugin header.' ],
            ];
        }

        return [
            'success'              => true,
            'message'              => sprintf( 'Validated %s with %d files and a detected plugin header.', $root, $file_count ),
            'errors'               => [],
            'file_count'           => $file_count,
            'plugin_name'          => $plugin_name,
            'header_relative_path' => $header_relative_path,
        ];
    }

    private static function create_plugin_zip( string $plugin_root, string $root, string $zip_path ): array {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return [
                'success' => false,
                'message' => 'ZipArchive is not available on this server.',
                'errors'  => [ 'The PHP ZipArchive extension is required for plugin folder installation.' ],
            ];
        }

        WPFD_Filesystem::mkdir_recursive( dirname( $zip_path ) );

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            return [
                'success' => false,
                'message' => 'ZIP creation failed.',
                'errors'  => [ 'The installer could not create the temporary plugin ZIP package.' ],
            ];
        }

        $zip->addEmptyDir( $root );

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $plugin_root, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $normalized_plugin_root = trailingslashit( wp_normalize_path( $plugin_root ) );

        foreach ( $iterator as $item ) {
            $item_path      = wp_normalize_path( $item->getPathname() );
            $relative_path  = ltrim( str_replace( $normalized_plugin_root, '', $item_path ), '/' );
            if ( $relative_path === '' ) {
                continue;
            }

            $local_path = $root . '/' . $relative_path;
            if ( $item->isDir() ) {
                $zip->addEmptyDir( rtrim( $local_path, '/' ) );
                continue;
            }

            if ( ! $zip->addFile( $item->getPathname(), $local_path ) ) {
                $zip->close();
                return [
                    'success' => false,
                    'message' => 'ZIP creation failed while packaging plugin files.',
                    'errors'  => [ sprintf( 'The installer could not add %s to the ZIP archive.', $local_path ) ],
                ];
            }
        }

        if ( ! $zip->close() || ! file_exists( $zip_path ) || (int) filesize( $zip_path ) < 1 ) {
            return [
                'success' => false,
                'message' => 'ZIP creation failed while finalizing the plugin package.',
                'errors'  => [ 'The temporary plugin ZIP package could not be finalized or was empty after creation.' ],
            ];
        }

        $zip_validation = self::validate_zip_structure( $zip_path, $root );
        if ( empty( $zip_validation['success'] ) ) {
            return $zip_validation;
        }

        return [
            'success' => true,
            'message' => sprintf( 'Created %s.zip with the original plugin root folder intact.', $root ),
            'errors'  => [],
        ];
    }

    private static function validate_zip_structure( string $zip_path, string $root ): array {
        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) !== true ) {
            return [
                'success' => false,
                'message' => 'ZIP verification failed.',
                'errors'  => [ 'The generated ZIP archive could not be reopened for validation.' ],
            ];
        }

        $root_seen = false;
        $file_seen = false;

        for ( $index = 0; $index < $zip->numFiles; $index++ ) {
            $entry = str_replace( '\\', '/', (string) $zip->getNameIndex( $index ) );
            if ( $entry === $root || $entry === $root . '/' ) {
                $root_seen = true;
                continue;
            }

            if ( ! str_starts_with( $entry, $root . '/' ) ) {
                $zip->close();
                return [
                    'success' => false,
                    'message' => 'ZIP verification failed because the plugin root folder was flattened.',
                    'errors'  => [ 'The generated ZIP archive contains files outside the expected top-level plugin folder.' ],
                ];
            }

            if ( substr( $entry, -1 ) !== '/' ) {
                $file_seen = true;
            }
        }

        $zip->close();

        if ( ! $root_seen || ! $file_seen ) {
            return [
                'success' => false,
                'message' => 'ZIP verification failed because the archive structure is incomplete.',
                'errors'  => [ 'The generated ZIP archive does not contain the expected plugin root folder and files.' ],
            ];
        }

        return [
            'success' => true,
            'message' => 'ZIP structure verified.',
            'errors'  => [],
        ];
    }

    private static function install_plugin_zip( string $zip_path, bool $overwrite_package ): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();
        wp_clean_plugins_cache( true );

        $skin     = new WPFD_Plugin_Folder_Installer_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $installed = $upgrader->install( $zip_path, [ 'overwrite_package' => $overwrite_package ] );

        if ( is_wp_error( $installed ) ) {
            return [
                'success'        => false,
                'installed_slug' => '',
                'message'        => 'Plugin installation failed.',
                'errors'         => [ $installed->get_error_message() ],
            ];
        }

        if ( is_wp_error( $upgrader->result ) ) {
            return [
                'success'        => false,
                'installed_slug' => '',
                'message'        => 'Plugin installation failed.',
                'errors'         => [ $upgrader->result->get_error_message() ],
            ];
        }

        if ( ! $installed || ! is_array( $upgrader->result ) ) {
            return [
                'success'        => false,
                'installed_slug' => '',
                'message'        => 'Plugin installation failed.',
                'errors'         => ! empty( $skin->messages ) ? $skin->messages : [ 'WordPress did not report a successful plugin install result.' ],
            ];
        }

        $destination_name = sanitize_key( (string) ( $upgrader->result['destination_name'] ?? '' ) );
        if ( $destination_name === '' ) {
            $destination      = (string) ( $upgrader->result['destination'] ?? '' );
            $destination_name = $destination !== '' ? sanitize_key( basename( untrailingslashit( $destination ) ) ) : '';
        }

        if ( $destination_name === '' ) {
            return [
                'success'        => false,
                'installed_slug' => '',
                'message'        => 'Plugin installation failed because the installed folder name could not be resolved.',
                'errors'         => [ 'WordPress did not expose the installed plugin directory after extracting the ZIP package.' ],
            ];
        }

        return [
            'success'        => true,
            'installed_slug' => $destination_name,
            'message'        => sprintf( 'Installed %s via Plugin_Upgrader.', $destination_name ),
            'errors'         => [],
        ];
    }

    private static function detect_installed_plugin_file( string $plugin_slug, string $preferred_relative_path ): string {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        if ( $preferred_relative_path !== '' ) {
            $preferred = $plugin_slug . '/' . ltrim( str_replace( '\\', '/', $preferred_relative_path ), '/' );
            if ( isset( $plugins[ $preferred ] ) ) {
                return $preferred;
            }
        }

        foreach ( $plugins as $plugin_file => $plugin_data ) {
            if ( ! str_starts_with( $plugin_file, $plugin_slug . '/' ) ) {
                continue;
            }

            if ( ! empty( $plugin_data['Name'] ) ) {
                return $plugin_file;
            }
        }

        $plugin_dir = WPFD_DEPLOY_DIR . $plugin_slug;
        if ( ! is_dir( $plugin_dir ) ) {
            return '';
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $item ) {
            if ( ! $item->isFile() || strtolower( $item->getExtension() ) !== 'php' ) {
                continue;
            }

            $header = get_file_data( $item->getPathname(), [ 'Name' => 'Plugin Name' ], 'plugin' );
            if ( empty( $header['Name'] ) ) {
                continue;
            }

            return ltrim( str_replace( wp_normalize_path( WPFD_DEPLOY_DIR ), '', wp_normalize_path( $item->getPathname() ) ), '/' );
        }

        return '';
    }

    private static function attach_backup_metadata( array &$result, array $backup ): void {
        $result['backup_path'] = (string) ( $backup['backup_path'] ?? '' );
        $result['backup_meta'] = [
            'strategy'         => (string) ( $backup['backup_strategy'] ?? '' ),
            'replacement_mode' => (string) ( $backup['replacement_mode'] ?? '' ),
            'verified'         => ! empty( $backup['verified'] ),
            'files'            => (int) ( $backup['backup_files'] ?? 0 ),
            'bytes'            => (int) ( $backup['backup_bytes'] ?? 0 ),
        ];
    }

    private static function insert_log_entry( array $result, int $user_id ): int {
        global $wpdb;

        self::ensure_log_table();

        $wpdb->insert(
            self::log_table_name(),
            [
                'original_folder_name' => $result['original_folder_name'],
                'file_count'           => $result['file_count'],
                'zip_result'           => $result['steps']['zip']['status'],
                'zip_path'             => $result['zip_path'],
                'plugin_header_result' => $result['steps']['validation']['status'],
                'plugin_file'          => $result['plugin_file'],
                'install_result'       => $result['steps']['install']['status'],
                'activation_result'    => $result['steps']['activation']['status'],
                'backup_path'          => $result['backup_path'],
                'error_message'        => empty( $result['error_details'] ) ? '' : implode( "\n", $result['error_details'] ),
                'details_json'         => wp_json_encode( $result ),
                'created_at'           => current_time( 'mysql', true ),
                'admin_user_id'        => $user_id,
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
        );

        return (int) $wpdb->insert_id;
    }

    private static function base_result( string $root, int $file_count ): array {
        return [
            'success'               => false,
            'status'                => 'failed',
            'message'               => '',
            'original_folder_name'  => $root,
            'file_count'            => $file_count,
            'zip_path'              => '',
            'backup_path'           => '',
            'plugin_file'           => '',
            'header_detected'       => false,
            'header_relative_path'  => '',
            'plugin_name'           => '',
            'error_details'         => [],
            'debug_trace'           => [],
            'steps'                 => [
                'validation' => self::step( 'pending', 'Awaiting validation.' ),
                'zip'        => self::step( 'pending', 'Awaiting ZIP creation.' ),
                'install'    => self::step( 'pending', 'Awaiting plugin installation.' ),
                'activation' => self::step( 'pending', 'Awaiting plugin activation.' ),
            ],
            'log_id'                => 0,
        ];
    }

    private static function step( string $status, string $message ): array {
        return [
            'status'  => $status,
            'message' => $message,
        ];
    }

    private static function append_debug_trace( array &$result, string $stage, string $message, array $context = [] ): void {
        $trace = [
            'stage'   => $stage,
            'message' => $message,
        ];

        $safe_context = self::sanitize_debug_context( $context );
        if ( ! empty( $safe_context ) ) {
            $trace['context'] = $safe_context;
        }

        $result['debug_trace'][] = $trace;
        self::write_debug_log( $stage, $message, $safe_context );
    }

    private static function sanitize_debug_context( array $context ): array {
        $sanitized = [];

        foreach ( $context as $key => $value ) {
            if ( is_scalar( $value ) || null === $value ) {
                $sanitized[ (string) $key ] = $value;
                continue;
            }

            if ( is_array( $value ) ) {
                $sanitized[ (string) $key ] = wp_json_encode( $value );
            }
        }

        return $sanitized;
    }

    private static function write_debug_log( string $stage, string $message, array $context = [] ): void {
        if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            return;
        }

        $context_suffix = '';
        if ( ! empty( $context ) ) {
            $context_suffix = ' ' . wp_json_encode( $context );
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( sprintf( '[WPFD Folder Installer][%s] %s%s', $stage, $message, $context_suffix ) );
    }

    private static function ensure_temp_root(): string {
        $base = trailingslashit( get_temp_dir() ) . self::TEMP_DIR_NAME . '/';

        self::create_protected_dir( $base );

        return untrailingslashit( $base );
    }

    private static function create_protected_dir( string $path ): void {
        WPFD_Filesystem::mkdir_recursive( $path );

        $htaccess = trailingslashit( $path ) . '.htaccess';
        $index    = trailingslashit( $path ) . 'index.php';

        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\n" );
        }

        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php // Silence is golden.\n" );
        }
    }

    private static function safe_join_path( string $base, string $relative_path ): string {
        $normalized_base = trailingslashit( wp_normalize_path( $base ) );
        $candidate       = wp_normalize_path( $normalized_base . ltrim( $relative_path, '/' ) );

        if ( ! str_starts_with( $candidate, $normalized_base ) ) {
            return '';
        }

        return $candidate;
    }

    private static function log_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::LOG_TABLE_SUFFIX;
    }
}