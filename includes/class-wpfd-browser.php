<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPFD_Browser â€” Section B
 * Server-side directory listing with path-traversal protection.
 * All paths are validated against ABSPATH before any filesystem operation.
 */
class WPFD_Browser {

    /** Ordered list of supported root aliases. */
    private static array $aliases = [
        'plugins',
        'themes',
        'uploads',
        'mu-plugins',
        'content',
        'root',
    ];

    /** ------------------------------------------------------------------ *
     *  Resolve an alias to a real absolute path.
     *  Returns false if the alias is unknown or the path is outside ABSPATH.
     * ------------------------------------------------------------------ */
    public static function resolve_root( string $alias ): string|false {
        if ( ! in_array( $alias, self::$aliases, true ) ) {
            return false;
        }

        switch ( $alias ) {
            case 'plugins':
                $path = WP_PLUGIN_DIR;
                break;
            case 'themes':
                $path = get_theme_root();
                break;
            case 'uploads':
                $info = wp_upload_dir();
                $path = $info['basedir'];
                break;
            case 'mu-plugins':
                $path = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
                break;
            case 'content':
                $path = WP_CONTENT_DIR;
                break;
            case 'root':
                $path = ABSPATH;
                break;
            default:
                return false;
        }

        $real = realpath( $path );
        $base = realpath( ABSPATH );

        if ( ! $real || ! $base ) {
            return false;
        }

        // Resolved path must begin with (or equal) ABSPATH.
        $sep = DIRECTORY_SEPARATOR;
        if ( $real !== $base && strpos( $real . $sep, $base . $sep ) !== 0 ) {
            return false;
        }

        return $real;
    }

    /** ------------------------------------------------------------------ *
     *  Scan one directory level.
     *  $abs_path must already be validated (resolve_root + path join + realpath check).
     *
     *  @param string $abs_path   Absolute path to the directory.
     *  @return list<array{name:string,type:string,size:int,perms:string,modified:int,readable:bool,writable:bool}>
     * ------------------------------------------------------------------ */
    public static function scan_directory( string $abs_path ): array {
        if ( ! is_dir( $abs_path ) || ! is_readable( $abs_path ) ) {
            return [];
        }

        $items = @scandir( $abs_path );
        if ( $items === false ) {
            return [];
        }

        $entries = [];
        foreach ( $items as $name ) {
            if ( $name === '.' || $name === '..' ) {
                continue;
            }
            $full   = $abs_path . DIRECTORY_SEPARATOR . $name;
            $is_dir = is_dir( $full );
            $entries[] = [
                'name'     => $name,
                'type'     => $is_dir ? 'dir' : 'file',
                'size'     => $is_dir ? 0 : (int) @filesize( $full ),
                'perms'    => substr( sprintf( '%o', (int) @fileperms( $full ) ), -4 ),
                'modified' => (int) @filemtime( $full ),
                'readable' => is_readable( $full ),
                'writable' => is_writable( $full ),
            ];
        }

        // Directories first; within each group, alphabetical case-insensitive.
        usort( $entries, static function ( array $a, array $b ): int {
            if ( $a['type'] !== $b['type'] ) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        return $entries;
    }

    /** ------------------------------------------------------------------ *
     *  Sanitise a relative path supplied by the client.
     *  Strips traversal components so the result can be safely joined with
     *  an absolute root path.
     * ------------------------------------------------------------------ */
    public static function sanitize_rel_path( string $raw ): string {
        // Split on both forward and back slashes.
        $parts = preg_split( '#[/\\\\]+#', $raw );
        $clean = [];
        foreach ( $parts as $part ) {
            $part = trim( $part );
            // Drop empty, dot, double-dot, and any component with null bytes.
            if ( $part === '' || $part === '.' || $part === '..' || strpos( $part, "\0" ) !== false ) {
                continue;
            }
            $clean[] = $part;
        }
        return implode( '/', $clean );
    }

    /** Return the list of all aliases (used by /browser/roots). */
    public static function get_aliases(): array {
        return self::$aliases;
    }
}
