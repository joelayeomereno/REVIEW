<?php
defined( 'ABSPATH' ) || exit;

class WPFD_Filesystem {

    private static ?object $fs = null;

    public static function init(): bool {
        global $wp_filesystem;

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if ( WP_Filesystem() && $wp_filesystem ) {
            self::$fs = $wp_filesystem;
            return true;
        }
        return false;
    }

    public static function get(): ?object {
        if ( ! self::$fs ) {
            self::init();
        }
        return self::$fs;
    }

    public static function mkdir_recursive( string $path ): bool {
        if ( is_dir( $path ) ) {
            return true;
        }
        return wp_mkdir_p( $path );
    }

    public static function put_contents( string $path, string $contents ): bool {
        self::mkdir_recursive( dirname( $path ) );
        $fs = self::get();
        if ( $fs ) {
            return $fs->put_contents( $path, $contents, FS_CHMOD_FILE );
        }
        // Fallback to direct write with proper permissions
        $written = file_put_contents( $path, $contents );
        if ( $written !== false ) {
            chmod( $path, 0644 );
            return true;
        }
        return false;
    }

    public static function delete_dir( string $path ): bool {
        $fs = self::get();
        if ( $fs ) {
            return $fs->delete( $path, true );
        }
        return self::rmdir_recursive( $path );
    }

    private static function rmdir_recursive( string $dir ): bool {
        if ( ! is_dir( $dir ) ) {
            return false;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                @chmod( $file->getRealPath(), 0755 );
                @rmdir( $file->getRealPath() );
            } else {
                @chmod( $file->getRealPath(), 0644 );
                @unlink( $file->getRealPath() );
            }
        }
        @chmod( $dir, 0755 );
        return @rmdir( $dir );
    }

    /**
     * Copy entire directory recursively.
     */
    public static function copy_dir( string $src, string $dst ): bool {
        if ( ! is_dir( $src ) ) {
            return false;
        }
        wp_mkdir_p( $dst );
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $all_ok = true;
        foreach ( $iterator as $item ) {
            $target = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
            if ( $item->isDir() ) {
                wp_mkdir_p( $target );
            } else {
                if ( ! @copy( $item->getRealPath(), $target ) ) {
                    $all_ok = false;
                }
            }
        }
        return $all_ok;
    }

    public static function get_dir_size( string $path ): int {
        $size = 0;
        if ( ! is_dir( $path ) ) {
            return 0;
        }
        foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
            if ( $file->isFile() ) {
                $size += $file->getSize();
            }
        }
        return $size;
    }

    public static function count_files( string $path ): int {
        $count = 0;
        if ( ! is_dir( $path ) ) {
            return 0;
        }
        foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
            if ( $file->isFile() ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Return both total size (bytes) and file count in a single directory traversal.
     * Always prefer this over calling get_dir_size() + count_files() separately.
     *
     * @return array{size:int, count:int}
     */
    public static function dir_stats( string $path ): array {
        $size  = 0;
        $count = 0;
        if ( ! is_dir( $path ) ) {
            return [ 'size' => 0, 'count' => 0 ];
        }
        foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
            if ( $file->isFile() ) {
                $size  += $file->getSize();
                $count++;
            }
        }
        return [ 'size' => $size, 'count' => $count ];
    }

    /** Filename written inside rollback snapshots for integrity metadata. */
    public const MANIFEST_FILENAME = '.wpfd-manifest.json';

    /**
     * Build a directory manifest in a single traversal.
     *
     * @return array{files:int,bytes:int,entries:array<int,array{rel:string,size:int}>}
     */
    public static function build_dir_manifest( string $path ): array {
        $files   = 0;
        $bytes   = 0;
        $entries = [];

        if ( ! is_dir( $path ) ) {
            return [ 'files' => 0, 'bytes' => 0, 'entries' => [] ];
        }

        $normalized_root = trailingslashit( wp_normalize_path( $path ) );
        $iterator        = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }

            $rel = ltrim( str_replace( $normalized_root, '', wp_normalize_path( $file->getPathname() ) ), '/' );
            if ( $rel === '' || $rel === self::MANIFEST_FILENAME ) {
                continue;
            }

            $size = (int) $file->getSize();
            $entries[] = [
                'rel'  => $rel,
                'size' => $size,
            ];
            $files++;
            $bytes += $size;
        }

        usort(
            $entries,
            static fn( array $a, array $b ): int => strcmp( $a['rel'], $b['rel'] )
        );

        return [
            'files'   => $files,
            'bytes'   => $bytes,
            'entries' => $entries,
        ];
    }

    /**
     * Compare two directory manifests for exact file count, byte total, and per-file sizes.
     *
     * @param array{files?:int,bytes?:int,entries?:array} $left
     * @param array{files?:int,bytes?:int,entries?:array} $right
     */
    public static function manifests_match( array $left, array $right ): bool {
        if ( (int) ( $left['files'] ?? 0 ) !== (int) ( $right['files'] ?? 0 ) ) {
            return false;
        }
        if ( (int) ( $left['bytes'] ?? 0 ) !== (int) ( $right['bytes'] ?? 0 ) ) {
            return false;
        }

        $left_entries  = is_array( $left['entries'] ?? null ) ? $left['entries'] : [];
        $right_entries = is_array( $right['entries'] ?? null ) ? $right['entries'] : [];

        if ( count( $left_entries ) !== count( $right_entries ) ) {
            return false;
        }

        foreach ( $left_entries as $index => $entry ) {
            $other = $right_entries[ $index ] ?? null;
            if ( ! is_array( $other ) ) {
                return false;
            }
            if ( ( $entry['rel'] ?? '' ) !== ( $other['rel'] ?? '' ) ) {
                return false;
            }
            if ( (int) ( $entry['size'] ?? -1 ) !== (int) ( $other['size'] ?? -2 ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verify that two directories contain the same files and byte sizes.
     */
    public static function verify_dir_match( string $source, string $backup ): bool {
        if ( ! is_dir( $source ) || ! is_dir( $backup ) ) {
            return false;
        }

        return self::manifests_match(
            self::build_dir_manifest( $source ),
            self::build_dir_manifest( $backup )
        );
    }

    /**
     * Copy a directory into a temp folder, verify integrity, then atomically rename to destination.
     *
     * @return array{success:bool,message:string,source:array,backup:array}
     */
    public static function copy_dir_verified( string $src, string $dst ): array {
        $source_manifest = self::build_dir_manifest( $src );
        if ( $source_manifest['files'] < 1 ) {
            return [
                'success' => false,
                'message' => 'Source directory is empty — refusing to create backup.',
                'source'  => $source_manifest,
                'backup'  => [ 'files' => 0, 'bytes' => 0, 'entries' => [] ],
            ];
        }

        $temp_dst = $dst . '.tmp-' . wp_generate_password( 8, false, false );
        if ( is_dir( $temp_dst ) ) {
            self::delete_dir( $temp_dst );
        }

        if ( ! self::copy_dir( $src, $temp_dst ) ) {
            self::delete_dir( $temp_dst );
            return [
                'success' => false,
                'message' => 'Backup copy failed while writing files to the temporary directory.',
                'source'  => $source_manifest,
                'backup'  => self::build_dir_manifest( $temp_dst ),
            ];
        }

        $backup_manifest = self::build_dir_manifest( $temp_dst );
        if ( ! self::manifests_match( $source_manifest, $backup_manifest ) ) {
            self::delete_dir( $temp_dst );
            return [
                'success' => false,
                'message' => 'Verified backup failed integrity check — file count or byte totals do not match the source.',
                'source'  => $source_manifest,
                'backup'  => $backup_manifest,
            ];
        }

        if ( is_dir( $dst ) ) {
            self::delete_dir( $dst );
        }

        if ( ! @rename( $temp_dst, $dst ) ) {
            if ( ! self::copy_dir( $temp_dst, $dst ) ) {
                self::delete_dir( $temp_dst );
                return [
                    'success' => false,
                    'message' => 'Verified backup could not be finalized at the destination path.',
                    'source'  => $source_manifest,
                    'backup'  => $backup_manifest,
                ];
            }
            self::delete_dir( $temp_dst );
        }

        return [
            'success' => true,
            'message' => 'Verified backup created successfully.',
            'source'  => $source_manifest,
            'backup'  => $backup_manifest,
        ];
    }
}
