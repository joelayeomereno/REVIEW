<?php
/**
 * WPFD_Nuker - Force Delete / Nuke Engine
 *
 * Tries up to 5 progressive strategies to permanently delete a file or directory
 * that may be locked, read-only, or otherwise resistant to deletion.
 *
 * Security boundary: refuses any path outside ABSPATH, refuses the uploads
 * root, wp-config.php, and the deployer plugin's own directory.
 *
 * @package    WP_Folder_Deployer
 * @since      5.0.0
 */

defined( 'ABSPATH' ) || exit;

class WPFD_Nuker {

    /**
     * The one public entry point.
     *
     * @param string $abs_path  Absolute server path to delete.
     * @param bool   $recursive Whether to delete directories recursively (default true).
     * @return array {
     *     @type bool   $success       Whether the path was successfully removed.
     *     @type int    $strategy_used 1-5 (or 0 if security check failed).
     *     @type int    $elapsed_ms    Wall-clock time in milliseconds.
     *     @type string $error         Error message on failure.
     *     @type array  $log           Per-strategy attempt log.
     * }
     */
    public static function nuke( string $abs_path, bool $recursive = true ): array {
        $start = microtime( true );
        $log   = [];

        /* -- Security boundary -------------------------------- */
        $boundary_error = self::check_boundary( $abs_path );
        if ( $boundary_error ) {
            return [
                'success'       => false,
                'strategy_used' => 0,
                'elapsed_ms'    => self::elapsed( $start ),
                'error'         => $boundary_error,
                'log'           => [],
            ];
        }

        /* Resolve to a real, native-separator path before any operation.
           realpath() returns the OS-native separator and resolves symlinks. */
        $user_id = get_current_user_id();
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( sprintf( '[WPFD Nuke] user=%d path=%s', $user_id, $abs_path ) );

        $real = realpath( $abs_path );
        if ( $real !== false ) {
            $abs_path = $real;
        } else {
            /* realpath can fail when path does not exist - normalise manually. */
            $abs_path = wp_normalize_path( $abs_path );
        }

        clearstatcache( true, $abs_path );

        if ( ! file_exists( $abs_path ) ) {
            return [
                'success'       => true,
                'strategy_used' => 0,
                'elapsed_ms'    => self::elapsed( $start ),
                'error'         => '',
                'log'           => [ 'Path did not exist - nothing to delete.' ],
            ];
        }

        /* -- Strategy 1: Standard PHP delete (raw, no WP_Filesystem) -- */
        $log[] = 'Strategy 1: standard PHP delete';
        if ( is_dir( $abs_path ) ) {
            self::rmdir_recursive_raw( $abs_path );
        } else {
            @unlink( $abs_path );
        }
        clearstatcache( true, $abs_path );
        if ( ! file_exists( $abs_path ) ) {
            return self::success( 1, $start, $log );
        }
        $log[] = 'Strategy 1 failed.';

        /* -- Strategy 2: chmod 0777 recursively, then retry -- */
        $log[] = 'Strategy 2: chmod 0777 + retry delete';
        self::chmod_recursive( $abs_path );
        if ( is_dir( $abs_path ) ) {
            self::rmdir_recursive_raw( $abs_path );
        } else {
            @unlink( $abs_path );
        }
        clearstatcache( true, $abs_path );
        if ( ! file_exists( $abs_path ) ) {
            return self::success( 2, $start, $log );
        }
        $log[] = 'Strategy 2 failed.';

        /* -- Strategy 3: WP_Filesystem->delete() -- */
        $log[] = 'Strategy 3: WP_Filesystem API';
        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            if ( ! function_exists( 'WP_Filesystem' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            WP_Filesystem( false, false, true );
        }
        if ( $wp_filesystem ) {
            $wp_filesystem->delete( $abs_path, $recursive );
            clearstatcache( true, $abs_path );
            if ( ! file_exists( $abs_path ) ) {
                return self::success( 3, $start, $log );
            }
        } else {
            $log[] = 'WP_Filesystem not available in this context.';
        }
        $log[] = 'Strategy 3 failed.';

        /* -- Strategy 4: exec() shell command -- */
        $log[] = 'Strategy 4: shell exec';
        if ( function_exists( 'exec' ) ) {
            /* Use native-separator path for shell commands. */
            $native = realpath( $abs_path );
            if ( ! $native ) {
                /* realpath may fail on forward-slash paths on Windows - rebuild it. */
                $native = str_replace( '/', DIRECTORY_SEPARATOR, $abs_path );
            }
            $content_real = realpath( WP_CONTENT_DIR );
            $abspath_real = realpath( ABSPATH );
            $inside_safe  = ( $content_real && strncmp( $native, $content_real, strlen( $content_real ) ) === 0 )
                         || ( $abspath_real && strncmp( $native, $abspath_real, strlen( $abspath_real ) ) === 0 );

            if ( $native && $inside_safe ) {
                $escaped = escapeshellarg( $native );
                $out     = [];
                $ret     = 1;
                if ( PHP_OS_FAMILY === 'Windows' ) {
                    if ( is_dir( $native ) && $recursive ) {
                        exec( 'rd /s /q ' . $escaped . ' 2>&1', $out, $ret );
                    } else {
                        exec( 'del /f /q ' . $escaped . ' 2>&1', $out, $ret );
                    }
                } else {
                    exec( 'rm -rf ' . $escaped . ' 2>&1', $out, $ret );
                }
                clearstatcache( true, $abs_path );
                clearstatcache( true, $native );
                if ( ! file_exists( $abs_path ) && ! file_exists( $native ) ) {
                    return self::success( 4, $start, $log );
                }
                if ( ! empty( $out ) ) {
                    $log[] = 'Shell output: ' . implode( ' ', $out );
                }
            } else {
                $log[] = 'Strategy 4 skipped: path outside allowed boundary or unresolvable.';
            }
        } else {
            $log[] = 'Strategy 4 skipped: exec() disabled.';
        }
        $log[] = 'Strategy 4 failed.';

        /* -- Strategy 5: rename tombstone, then delete -- */
        $log[] = 'Strategy 5: rename to tombstone, then delete';
        $parent    = dirname( $abs_path );
        $tombstone = $parent . '/' . basename( $abs_path ) . '_wpfd_dead_' . time();
        $tomb_err  = self::check_boundary( $tombstone );
        if ( $tomb_err ) {
            $log[] = 'Strategy 5 skipped: tombstone path outside boundary.';
        } elseif ( @rename( $abs_path, $tombstone ) ) {
            clearstatcache( true, $abs_path );
            clearstatcache( true, $tombstone );
            if ( is_dir( $tombstone ) ) {
                self::rmdir_recursive_raw( $tombstone );
            } else {
                @unlink( $tombstone );
            }
            clearstatcache( true, $tombstone );
            if ( ! file_exists( $tombstone ) ) {
                return self::success( 5, $start, $log );
            }
            /* Tombstone exists but original is gone - consider partial success */
            clearstatcache( true, $abs_path );
            if ( ! file_exists( $abs_path ) ) {
                $log[] = 'Tombstone remains at ' . basename( $tombstone ) . ' (harmless).';
                return self::success( 5, $start, $log );
            }
        }
        $log[] = 'Strategy 5 failed.';

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( sprintf( '[WPFD Nuke] FAILED user=%d path=%s — all 5 strategies exhausted', get_current_user_id(), $abs_path ) );

        return [
            'success'       => false,
            'strategy_used' => 5,
            'elapsed_ms'    => self::elapsed( $start ),
            'error'         => 'All 5 strategies exhausted. Manual deletion required.',
            'log'           => $log,
        ];
    }

    /**
     * Scan a path and return info about its files (count, size, read-only list).
     *
     * @param string $abs_path Absolute path to scan.
     * @return array {
     *     @type bool   $exists
     *     @type bool   $is_dir
     *     @type int    $file_count
     *     @type int    $total_bytes
     *     @type array  $readonly    Paths that are not writable.
     *     @type string $error
     * }
     */
    public static function scan( string $abs_path ): array {
        $boundary_error = self::check_boundary( $abs_path );
        if ( $boundary_error ) {
            return [ 'exists' => false, 'error' => $boundary_error ];
        }

        $abs_path = wp_normalize_path( $abs_path );

        if ( ! file_exists( $abs_path ) ) {
            return [ 'exists' => false, 'error' => 'Path does not exist.' ];
        }

        $result = [
            'exists'      => true,
            'is_dir'      => is_dir( $abs_path ),
            'file_count'  => 0,
            'total_bytes' => 0,
            'readonly'    => [],
            'error'       => '',
        ];

        if ( ! is_dir( $abs_path ) ) {
            $result['file_count']  = 1;
            $result['total_bytes'] = (int) filesize( $abs_path );
            if ( ! is_writable( $abs_path ) ) {
                $result['readonly'][] = $abs_path;
            }
            return $result;
        }

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $abs_path, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ( $it as $item ) {
                if ( $item->isFile() ) {
                    $result['file_count']++;
                    $result['total_bytes'] += $item->getSize();
                    if ( ! $item->isWritable() ) {
                        $result['readonly'][] = wp_normalize_path( $item->getRealPath() );
                    }
                }
            }
        } catch ( \Exception $e ) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /* -- Private helpers ------------------------------------- */

    /**
     * Verify the target path is safe to delete.
     *
     * @param string $abs_path
     * @return string  Empty string if safe, error message if not.
     */
    private static function check_boundary( string $abs_path ): string {
        $abs_path = wp_normalize_path( $abs_path );

        /* Must be inside ABSPATH (covers all browsable roots including wp-content) */
        $abspath_norm  = rtrim( wp_normalize_path( ABSPATH ), '/' ) . '/';
        $content_norm  = rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' ) . '/';
        $path_check    = rtrim( $abs_path, '/' ) . '/';
        $in_abspath    = strncmp( $path_check, $abspath_norm, strlen( $abspath_norm ) ) === 0;
        $in_content    = strncmp( $path_check, $content_norm, strlen( $content_norm ) ) === 0;

        if ( ! $in_abspath && ! $in_content ) {
            return 'Refused: path is outside ABSPATH.';
        }

        /* Must not be ABSPATH itself or WP_CONTENT_DIR itself */
        if ( rtrim( $abs_path, '/' ) === rtrim( $abspath_norm, '/' ) ) {
            return 'Refused: cannot delete the WordPress root directory.';
        }
        if ( rtrim( $abs_path, '/' ) === rtrim( $content_norm, '/' ) ) {
            return 'Refused: cannot delete the wp-content directory.';
        }

        /* Must not be the uploads directory root itself */
        $upload_dir = wp_normalize_path( wp_upload_dir()['basedir'] );
        if ( rtrim( $abs_path, '/' ) === rtrim( $upload_dir, '/' ) ) {
            return 'Refused: cannot delete the uploads directory root.';
        }

        /* Must not be this plugin directory */
        $plugin_dir = wp_normalize_path( WPFD_PLUGIN_DIR );
        if ( strncmp( $abs_path, $plugin_dir, strlen( $plugin_dir ) ) === 0 ) {
            return 'Refused: cannot delete the Folder Deployer plugin directory.';
        }

        /* Must not be inside wp-admin or wp-includes (core directories) */
        $wp_admin_norm    = wp_normalize_path( ABSPATH . 'wp-admin' );
        $wp_includes_norm = wp_normalize_path( ABSPATH . 'wp-includes' );
        if ( strncmp( $abs_path, $wp_admin_norm, strlen( $wp_admin_norm ) ) === 0 ) {
            return 'Refused: cannot delete inside wp-admin/.';
        }
        if ( strncmp( $abs_path, $wp_includes_norm, strlen( $wp_includes_norm ) ) === 0 ) {
            return 'Refused: cannot delete inside wp-includes/.';
        }

        /* Must not be wp-config.php */
        $wpconfig = wp_normalize_path( ABSPATH . 'wp-config.php' );
        if ( rtrim( $abs_path, '/' ) === rtrim( $wpconfig, '/' ) ) {
            return 'Refused: cannot delete wp-config.php.';
        }

        /* Non-empty path after normalisation */
        if ( empty( trim( $abs_path, '/' ) ) ) {
            return 'Refused: path is empty after normalisation.';
        }

        return '';
    }

    /**
     * Raw recursive directory removal using native PHP - no WP_Filesystem dependency.
     * Uses CHILD_FIRST iteration to delete contents before containers.
     */
    private static function rmdir_recursive_raw( string $dir ): bool {
        if ( ! is_dir( $dir ) ) {
            return @unlink( $dir );
        }
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $it as $item ) {
                $real_item = $item->getRealPath();
                if ( $item->isDir() ) {
                    @chmod( $real_item, 0777 );
                    @rmdir( $real_item );
                } else {
                    @chmod( $real_item, 0666 );
                    @unlink( $real_item );
                }
            }
        } catch ( \Exception $e ) {
            // best-effort - continue to try rmdir on parent
        }
        @chmod( $dir, 0777 );
        return @rmdir( $dir );
    }

    /** Recursively chmod all files/dirs to a permissive mode. */
    private static function chmod_recursive( string $path ): void {
        if ( is_dir( $path ) ) {
            @chmod( $path, 0777 );
            try {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ( $it as $item ) {
                    @chmod( $item->getRealPath(), $item->isDir() ? 0777 : 0666 );
                }
            } catch ( \Exception $e ) {
                // best-effort
            }
        } else {
            @chmod( $path, 0666 );
        }
    }

    /** Build a success return array. */
    private static function success( int $strategy, float $start, array $log ): array {
        $log[] = "Strategy {$strategy} succeeded.";
        return [
            'success'       => true,
            'strategy_used' => $strategy,
            'elapsed_ms'    => self::elapsed( $start ),
            'error'         => '',
            'log'           => $log,
        ];
    }

    /** Return elapsed milliseconds since $start (microtime float). */
    private static function elapsed( float $start ): int {
        return (int) round( ( microtime( true ) - $start ) * 1000 );
    }
}
