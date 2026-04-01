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
                @chmod( $file->getRealPath(), 0777 );
                @rmdir( $file->getRealPath() );
            } else {
                @chmod( $file->getRealPath(), 0666 );
                @unlink( $file->getRealPath() );
            }
        }
        @chmod( $dir, 0777 );
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
        foreach ( $iterator as $item ) {
            $target = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
            if ( $item->isDir() ) {
                wp_mkdir_p( $target );
            } else {
                copy( $item->getRealPath(), $target );
            }
        }
        return true;
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
}
