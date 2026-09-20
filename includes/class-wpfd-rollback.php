<?php
defined( 'ABSPATH' ) || exit;

class WPFD_Rollback {

    /** @deprecated Use get_backup_dir() instead. Kept for backwards compat. */
    const BACKUP_DIR = WP_CONTENT_DIR . '/wpfd-backups/';

    private const LOCK_TTL = 60;

    /**
     * Default backup directory path.
     */
    public static function get_default_backup_dir(): string {
        return trailingslashit( wp_normalize_path( WP_CONTENT_DIR . '/wpfd-backups' ) );
    }

    /**
     * Return the configured backup directory path.
     */
    public static function get_backup_dir(): string {
        $settings = get_option( 'wpfd_settings', [] );
        $dir      = self::get_default_backup_dir();

        if ( is_array( $settings ) && ! empty( $settings['backup_dir'] ) ) {
            $dir = trailingslashit( wp_normalize_path( untrailingslashit( (string) $settings['backup_dir'] ) ) );
        }

        if ( WPFD_Security::validate_backup_path( $dir ) !== true ) {
            return self::get_default_backup_dir();
        }

        return $dir;
    }

    public static function ensure_backup_dir(): void {
        $dir = self::get_backup_dir();
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $htaccess = $dir . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, 'Deny from all' );
        }

        $index = $dir . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, '<?php // Silence is golden.' );
        }
    }

    /**
     * Create a timestamped backup of an existing plugin before overwriting it.
     */
    public static function backup( string $plugin_slug ): string {
        $stage = self::stage_deploy_backup( $plugin_slug );
        if ( empty( $stage['success'] ) ) {
            return '';
        }

        return (string) ( $stage['backup_path'] ?? '' );
    }

    /**
     * Stage an existing plugin for deployment with verified snapshot integrity.
     *
     * @return array{success:bool,backup_path:string,replacement_mode:string,backup_strategy:string,admin_notice:string,reactivate_after_deploy:bool,plugin_file:string,message:string,verified?:bool,backup_files?:int,backup_bytes?:int}
     */
    public static function stage_deploy_backup( string $plugin_slug ): array {
        $source = WPFD_DEPLOY_DIR . $plugin_slug;
        [ $plugin_file, $was_active ] = self::get_activation_state( $plugin_slug );
        $normalized_source = rtrim( wp_normalize_path( $source ), '/' );
        $normalized_self   = rtrim( wp_normalize_path( WPFD_PLUGIN_DIR ), '/' );
        $is_self_deploy    = ( $normalized_source === $normalized_self );

        if ( ! is_dir( $source ) ) {
            return self::stage_success(
                '',
                'fresh-install',
                'none',
                '',
                false,
                $plugin_file
            );
        }

        if ( ! self::acquire_deploy_lock( $plugin_slug ) ) {
            return self::stage_failure(
                'Another backup or restore operation is already in progress for this plugin. Try again shortly.',
                $plugin_file
            );
        }

        try {
            self::ensure_backup_dir();

            $source_manifest = WPFD_Filesystem::build_dir_manifest( $source );
            if ( $source_manifest['files'] < 1 ) {
                return self::stage_failure(
                    'Existing plugin folder appears empty — refusing to overwrite without a verified backup.',
                    $plugin_file
                );
            }

            $timestamp   = gmdate( 'Y-m-d_H-i-s' );
            $backup_path = self::get_backup_dir() . $plugin_slug . '--' . $timestamp;

            if ( ! $is_self_deploy && @rename( $source, $backup_path ) ) {
                $backup_manifest = WPFD_Filesystem::build_dir_manifest( $backup_path );
                if ( ! WPFD_Filesystem::manifests_match( $source_manifest, $backup_manifest ) ) {
                    @rename( $backup_path, $source );
                    self::delete_incomplete_backup( $backup_path );

                    return self::stage_failure(
                        'Atomic backup rename succeeded but integrity verification failed. Installation aborted.',
                        $plugin_file
                    );
                }

                self::write_manifest_sidecar( $backup_path, $plugin_slug, 'rename', $backup_manifest );
                self::refresh_backup_cache( $plugin_slug );

                return self::stage_success(
                    $backup_path,
                    'full-replacement',
                    'rename',
                    '',
                    $was_active,
                    $plugin_file,
                    $backup_manifest
                );
            }

            $copy = WPFD_Filesystem::copy_dir_verified( $source, $backup_path );
            if ( empty( $copy['success'] ) ) {
                self::delete_incomplete_backup( $backup_path );

                return self::stage_failure(
                    $copy['message'] ?? 'WordPress installer blocked the existing plugin folder and the installer could not create a verified backup. Installation aborted.',
                    $plugin_file
                );
            }

            $backup_manifest = is_array( $copy['backup'] ?? null ) ? $copy['backup'] : WPFD_Filesystem::build_dir_manifest( $backup_path );
            self::write_manifest_sidecar( $backup_path, $plugin_slug, 'copy', $backup_manifest );
            self::refresh_backup_cache( $plugin_slug );

            return self::stage_success(
                $backup_path,
                'safe-delta-overwrite',
                'copy',
                $is_self_deploy
                    ? 'Self-install backup kept the current plugin directory in place until the installation completed.'
                    : 'WordPress installer blocked by server. Safe installer replacement mode used instead.',
                $was_active,
                $plugin_file,
                $backup_manifest
            );
        } finally {
            self::release_deploy_lock( $plugin_slug );
        }
    }

    /**
     * Restore a plugin from a backup path.
     */
    public static function restore( string $plugin_slug, string $backup_path ): array {
        if ( ! self::is_backup_snapshot_path( $backup_path ) ) {
            return [ 'success' => false, 'message' => 'Backup path is not inside the configured backup directory.' ];
        }

        if ( ! is_dir( $backup_path ) ) {
            return [ 'success' => false, 'message' => 'Backup path not found.' ];
        }

        $validation = self::validate_snapshot_integrity( $backup_path );
        if ( empty( $validation['valid'] ) ) {
            return [
                'success' => false,
                'message' => $validation['message'] ?? 'Backup appears empty or corrupted — refusing restore.',
            ];
        }

        if ( ! self::acquire_deploy_lock( $plugin_slug ) ) {
            return [ 'success' => false, 'message' => 'Another backup or restore operation is already in progress for this plugin.' ];
        }

        try {
            $target = WPFD_DEPLOY_DIR . $plugin_slug;

            if ( ! function_exists( 'is_plugin_active' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $plugin_file = WPFD_Plugin_Folder_Installer::detect_main_plugin_file( $plugin_slug );
            if ( ! $plugin_file ) {
                $plugin_file = $plugin_slug . '/' . $plugin_slug . '.php';
            }

            $was_active = is_plugin_active( $plugin_file );
            if ( $was_active ) {
                deactivate_plugins( $plugin_file );
            }

            WPFD_Filesystem::delete_dir( $target );

            $copy = WPFD_Filesystem::copy_dir_verified( $backup_path, $target );
            if ( empty( $copy['success'] ) ) {
                return [
                    'success' => false,
                    'message' => $copy['message'] ?? 'Restore failed while copying backup files.',
                ];
            }

            if ( $was_active ) {
                if ( ! function_exists( 'activate_plugin' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }

                $restored_plugin_file = WPFD_Plugin_Folder_Installer::detect_main_plugin_file( $plugin_slug );
                if ( ! $restored_plugin_file ) {
                    return [
                        'success' => false,
                        'message' => 'Plugin restored from backup, but the main plugin file could not be detected for reactivation.',
                    ];
                }

                $activation = activate_plugin( $restored_plugin_file );
                if ( is_wp_error( $activation ) ) {
                    return [
                        'success'          => false,
                        'message'          => 'Plugin restored from backup, but reactivation failed: ' . $activation->get_error_message(),
                        'activation_error' => $activation->get_error_message(),
                    ];
                }
            }

            return [
                'success'     => true,
                'message'     => 'Plugin restored from backup: ' . basename( $backup_path ),
                'reactivated' => $was_active,
            ];
        } finally {
            self::release_deploy_lock( $plugin_slug );
        }
    }

    /**
     * List all backups for a plugin slug.
     */
    public static function list_backups( string $plugin_slug ): array {
        $cache_key = 'wpfd_bl_' . $plugin_slug;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        self::ensure_backup_dir();
        $backups = [];
        $pattern = self::get_backup_dir() . $plugin_slug . '--*';

        foreach ( glob( $pattern ) ?: [] as $path ) {
            if ( ! is_dir( $path ) ) {
                continue;
            }

            $stat     = stat( $path );
            $ds       = WPFD_Filesystem::dir_stats( $path );
            $manifest = self::read_manifest_sidecar( $path );

            $backups[] = [
                'path'      => $path,
                'name'      => basename( $path ),
                'timestamp' => $stat['mtime'],
                'size'      => $ds['size'],
                'files'     => $ds['count'],
                'strategy'  => (string) ( $manifest['strategy'] ?? '' ),
                'verified'  => ! empty( $manifest['verified'] ),
            ];
        }

        usort( $backups, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
        set_transient( $cache_key, $backups, MINUTE_IN_SECONDS );

        return $backups;
    }

    /**
     * Keep only the N most recent backups per plugin slug.
     */
    public static function prune( string $plugin_slug, int $keep = 0 ): void {
        if ( $keep < 1 ) {
            $settings = get_option( 'wpfd_settings', [] );
            $keep     = isset( $settings['backup_retention'] ) ? absint( $settings['backup_retention'] ) : 5;
            if ( $keep < 1 ) {
                $keep = 5;
            }
        }

        $backups = self::list_backup_fast( $plugin_slug );
        if ( count( $backups ) <= $keep ) {
            return;
        }

        $to_delete = array_slice( $backups, $keep );
        foreach ( $to_delete as $backup ) {
            WPFD_Filesystem::delete_dir( $backup['path'] );
        }
    }

    /**
     * Return all backups across all plugins.
     */
    public static function list_all_backups(): array {
        $cache_key = 'wpfd_bl_all';
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        self::ensure_backup_dir();
        $all     = [];
        $pattern = self::get_backup_dir() . '*';

        foreach ( glob( $pattern ) ?: [] as $path ) {
            if ( ! is_dir( $path ) || in_array( basename( $path ), [ '.', '..' ], true ) ) {
                continue;
            }

            $name     = basename( $path );
            $sep      = strrpos( $name, '--' );
            $slug     = $sep !== false ? substr( $name, 0, $sep ) : $name;
            $stat     = stat( $path );
            $ds       = WPFD_Filesystem::dir_stats( $path );
            $manifest = self::read_manifest_sidecar( $path );

            $all[] = [
                'path'      => $path,
                'name'      => $name,
                'slug'      => $slug,
                'timestamp' => $stat['mtime'],
                'size'      => $ds['size'],
                'files'     => $ds['count'],
                'strategy'  => (string) ( $manifest['strategy'] ?? '' ),
                'verified'  => ! empty( $manifest['verified'] ),
            ];
        }

        usort( $all, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
        set_transient( $cache_key, $all, MINUTE_IN_SECONDS );

        return $all;
    }

    public static function delete_backup( string $backup_path ): bool {
        if ( ! self::is_backup_snapshot_path( $backup_path ) ) {
            return false;
        }

        $name = basename( $backup_path );
        $sep  = strrpos( $name, '--' );
        if ( $sep !== false ) {
            delete_transient( 'wpfd_bl_' . substr( $name, 0, $sep ) );
        }
        delete_transient( 'wpfd_bl_all' );

        return WPFD_Filesystem::delete_dir( $backup_path );
    }

    /**
     * Check whether a path is inside the configured backup directory.
     */
    public static function is_backup_snapshot_path( string $backup_path ): bool {
        $real_path = realpath( $backup_path );
        $real_dir  = realpath( self::get_backup_dir() );

        if ( ! $real_path || ! $real_dir ) {
            return false;
        }

        $real_path = trailingslashit( wp_normalize_path( $real_path ) );
        $real_dir  = trailingslashit( wp_normalize_path( $real_dir ) );

        return str_starts_with( $real_path, $real_dir );
    }

    /**
     * Validate backup directory is writable and usable.
     *
     * @return array{success:bool,message:string,resolved_path?:string,display_path?:string}
     */
    public static function test_backup_dir( string $path = '' ): array {
        $dir = $path !== '' ? trailingslashit( wp_normalize_path( untrailingslashit( WPFD_Security::resolve_backup_dir_input( $path ) ) ) ) : self::get_backup_dir();

        $validation = WPFD_Security::validate_backup_path( $dir );
        if ( $validation !== true ) {
            return [
                'success' => false,
                'message' => is_string( $validation ) ? $validation : 'Invalid backup directory.',
            ];
        }

        if ( ! wp_mkdir_p( $dir ) ) {
            return [
                'success' => false,
                'message' => 'Backup directory could not be created.',
            ];
        }

        self::ensure_backup_dir();

        $probe = $dir . '.wpfd-write-test-' . wp_generate_password( 6, false, false );
        $written = file_put_contents( $probe, 'ok' );
        if ( $written === false ) {
            return [
                'success' => false,
                'message' => 'Backup directory is not writable.',
            ];
        }

        @unlink( $probe );

        $parts   = array_filter( explode( '/', wp_normalize_path( $dir ) ) );
        $display = '…/' . implode( '/', array_slice( $parts, -2 ) );

        update_option(
            'wpfd_backup_dir_last_test',
            [
                'timestamp' => time(),
                'path'      => $dir,
                'success'   => true,
            ],
            false
        );

        return [
            'success'       => true,
            'message'       => 'Backup directory is valid and writable.',
            'resolved_path' => $dir,
            'display_path'  => $display,
        ];
    }

    /**
     * @return array{0:string,1:bool}
     */
    private static function get_activation_state( string $plugin_slug ): array {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_file = WPFD_Plugin_Folder_Installer::detect_main_plugin_file( $plugin_slug );
        if ( ! $plugin_file ) {
            $plugin_file = $plugin_slug . '/' . $plugin_slug . '.php';
        }

        return [ $plugin_file, is_plugin_active( $plugin_file ) ];
    }

    private static function refresh_backup_cache( string $plugin_slug ): void {
        delete_transient( 'wpfd_bl_' . $plugin_slug );
        delete_transient( 'wpfd_bl_all' );
        self::prune( $plugin_slug );
    }

    private static function delete_incomplete_backup( string $backup_path ): void {
        if ( is_dir( $backup_path ) ) {
            WPFD_Filesystem::delete_dir( $backup_path );
            return;
        }

        if ( file_exists( $backup_path ) ) {
            @unlink( $backup_path );
        }
    }

    private static function acquire_deploy_lock( string $plugin_slug ): bool {
        $key = 'wpfd_backup_lock_' . sanitize_key( $plugin_slug );
        if ( get_transient( $key ) ) {
            return false;
        }

        set_transient( $key, 1, self::LOCK_TTL );
        return true;
    }

    private static function release_deploy_lock( string $plugin_slug ): void {
        delete_transient( 'wpfd_backup_lock_' . sanitize_key( $plugin_slug ) );
    }

    /**
     * @param array{files?:int,bytes?:int,entries?:array} $manifest
     */
    private static function write_manifest_sidecar( string $backup_path, string $plugin_slug, string $strategy, array $manifest ): void {
        $payload = [
            'slug'         => $plugin_slug,
            'timestamp'    => gmdate( 'c' ),
            'strategy'     => $strategy,
            'files'        => (int) ( $manifest['files'] ?? 0 ),
            'bytes'        => (int) ( $manifest['bytes'] ?? 0 ),
            'verified'     => true,
            'wpfd_version' => defined( 'WPFD_VERSION' ) ? WPFD_VERSION : '',
        ];

        WPFD_Filesystem::put_contents(
            trailingslashit( $backup_path ) . WPFD_Filesystem::MANIFEST_FILENAME,
            wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function read_manifest_sidecar( string $backup_path ): array {
        $manifest_file = trailingslashit( $backup_path ) . WPFD_Filesystem::MANIFEST_FILENAME;
        if ( ! is_file( $manifest_file ) ) {
            return [];
        }

        $decoded = json_decode( (string) file_get_contents( $manifest_file ), true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * @return array{valid:bool,message:string}
     */
    private static function validate_snapshot_integrity( string $backup_path ): array {
        $manifest = self::read_manifest_sidecar( $backup_path );
        $stats    = WPFD_Filesystem::build_dir_manifest( $backup_path );

        if ( $stats['files'] < 1 ) {
            return [
                'valid'   => false,
                'message' => 'Backup appears empty or corrupted — refusing restore.',
            ];
        }

        if ( ! empty( $manifest ) ) {
            if ( (int) ( $manifest['files'] ?? 0 ) !== $stats['files'] || (int) ( $manifest['bytes'] ?? 0 ) !== $stats['bytes'] ) {
                return [
                    'valid'   => false,
                    'message' => 'Backup manifest does not match on-disk contents — refusing restore.',
                ];
            }
        }

        return [
            'valid'   => true,
            'message' => '',
        ];
    }

    /**
     * @param array{files?:int,bytes?:int,entries?:array}|null $manifest
     * @return array{success:bool,backup_path:string,replacement_mode:string,backup_strategy:string,admin_notice:string,reactivate_after_deploy:bool,plugin_file:string,message:string,verified:bool,backup_files:int,backup_bytes:int}
     */
    private static function stage_success(
        string $backup_path,
        string $replacement_mode,
        string $backup_strategy,
        string $admin_notice,
        bool $reactivate_after_deploy,
        string $plugin_file,
        ?array $manifest = null
    ): array {
        return [
            'success'                 => true,
            'backup_path'             => $backup_path,
            'replacement_mode'        => $replacement_mode,
            'backup_strategy'         => $backup_strategy,
            'admin_notice'            => $admin_notice,
            'reactivate_after_deploy' => $reactivate_after_deploy,
            'plugin_file'             => $plugin_file,
            'message'                 => '',
            'verified'                => $backup_path !== '',
            'backup_files'            => (int) ( $manifest['files'] ?? 0 ),
            'backup_bytes'            => (int) ( $manifest['bytes'] ?? 0 ),
        ];
    }

    /**
     * @return array{success:bool,backup_path:string,replacement_mode:string,backup_strategy:string,admin_notice:string,reactivate_after_deploy:bool,plugin_file:string,message:string,verified:bool,backup_files:int,backup_bytes:int}
     */
    private static function stage_failure( string $message, string $plugin_file ): array {
        return [
            'success'                 => false,
            'backup_path'             => '',
            'replacement_mode'        => 'backup-failed',
            'backup_strategy'         => 'none',
            'admin_notice'            => '',
            'reactivate_after_deploy' => false,
            'plugin_file'             => $plugin_file,
            'message'                 => $message,
            'verified'                => false,
            'backup_files'            => 0,
            'backup_bytes'            => 0,
        ];
    }

    /**
     * @return list<array{path:string,timestamp:int}>
     */
    private static function list_backup_fast( string $plugin_slug ): array {
        $list    = [];
        $pattern = self::get_backup_dir() . $plugin_slug . '--*';

        foreach ( glob( $pattern ) ?: [] as $path ) {
            if ( is_dir( $path ) ) {
                $st     = stat( $path );
                $list[] = [ 'path' => $path, 'timestamp' => $st['mtime'] ];
            }
        }

        usort( $list, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
        return $list;
    }
}
