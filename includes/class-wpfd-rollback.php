<?php
defined( 'ABSPATH' ) || exit;

class WPFD_Rollback {

    const BACKUP_DIR = WP_CONTENT_DIR . '/wpfd-backups/';

    public static function ensure_backup_dir(): void {
        if ( ! is_dir( self::BACKUP_DIR ) ) {
            wp_mkdir_p( self::BACKUP_DIR );
            // Protect directory from direct web access
            file_put_contents( self::BACKUP_DIR . '.htaccess', 'Deny from all' );
            file_put_contents( self::BACKUP_DIR . 'index.php', '<?php // Silence is golden.' );
        }
    }

    /**
     * Create a timestamped backup of an existing plugin before overwriting it.
     * Returns the backup path or empty string if plugin didn't exist.
     */
    public static function backup( string $plugin_slug ): string {
        $source = WPFD_DEPLOY_DIR . $plugin_slug;
        if ( ! is_dir( $source ) ) {
            return '';
        }

        self::ensure_backup_dir();

        $timestamp   = gmdate( 'Y-m-d_H-i-s' );
        $backup_path = self::BACKUP_DIR . $plugin_slug . '_' . $timestamp;

        WPFD_Filesystem::copy_dir( $source, $backup_path );

        // Prune old backups — keep last 5 per slug
        self::prune( $plugin_slug );

        return $backup_path;
    }

    /**
     * Restore a plugin from a backup path.
     */
    public static function restore( string $plugin_slug, string $backup_path ): array {
        if ( ! is_dir( $backup_path ) ) {
            return [ 'success' => false, 'message' => 'Backup path not found.' ];
        }

        $target = WPFD_DEPLOY_DIR . $plugin_slug;

        // Deactivate plugin if active
        $plugin_file = $plugin_slug . '/' . $plugin_slug . '.php';
        if ( is_plugin_active( $plugin_file ) ) {
            deactivate_plugins( $plugin_file );
        }

        // Remove current version
        WPFD_Filesystem::delete_dir( $target );

        // Restore backup
        WPFD_Filesystem::copy_dir( $backup_path, $target );

        return [
            'success' => true,
            'message' => 'Plugin restored from backup: ' . basename( $backup_path ),
        ];
    }

    /**
     * List all backups for a plugin slug.
     */
    public static function list_backups( string $plugin_slug ): array {
        self::ensure_backup_dir();
        $backups = [];
        foreach ( glob( self::BACKUP_DIR . $plugin_slug . '_*' ) as $path ) {
            if ( is_dir( $path ) ) {
                $stat      = stat( $path );
                $backups[] = [
                    'path'      => $path,
                    'name'      => basename( $path ),
                    'timestamp' => $stat['mtime'],
                    'size'      => WPFD_Filesystem::get_dir_size( $path ),
                    'files'     => WPFD_Filesystem::count_files( $path ),
                ];
            }
        }
        usort( $backups, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
        return $backups;
    }

    /**
     * Keep only the N most recent backups per plugin slug.
     * Reads retention limit from wpfd_settings option; falls back to 5.
     */
    public static function prune( string $plugin_slug, int $keep = 0 ): void {
        if ( $keep < 1 ) {
            $settings = get_option( 'wpfd_settings', [] );
            $keep     = isset( $settings['backup_retention'] ) ? absint( $settings['backup_retention'] ) : 5;
            if ( $keep < 1 ) {
                $keep = 5;
            }
        }
        $backups = self::list_backups( $plugin_slug );
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
        self::ensure_backup_dir();
        $all = [];
        foreach ( glob( self::BACKUP_DIR . '*' ) as $path ) {
            if ( is_dir( $path ) && ! in_array( basename( $path ), [ '.', '..' ], true ) ) {
                $name  = basename( $path );
                $parts = explode( '_', $name );
                // slug is everything before the date portion (last 3 underscore segments)
                $slug  = implode( '_', array_slice( $parts, 0, count( $parts ) - 3 ) );
                $stat  = stat( $path );
                $all[] = [
                    'path'      => $path,
                    'name'      => $name,
                    'slug'      => $slug,
                    'timestamp' => $stat['mtime'],
                    'size'      => WPFD_Filesystem::get_dir_size( $path ),
                    'files'     => WPFD_Filesystem::count_files( $path ),
                ];
            }
        }
        usort( $all, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
        return $all;
    }

    public static function delete_backup( string $backup_path ): bool {
        $real_path = realpath( $backup_path );
        $real_dir  = realpath( self::BACKUP_DIR );
        if ( ! $real_path || ! $real_dir ) {
            return false;
        }
        if ( ! str_starts_with( $real_path, $real_dir ) ) {
            return false;
        }
        return WPFD_Filesystem::delete_dir( $backup_path );
    }
}
