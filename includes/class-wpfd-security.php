<?php
defined( 'ABSPATH' ) || exit;

class WPFD_Security {

    const NONCE_ACTION = 'wpfd_deploy_action';

    /** Allowed file extensions for upload */
    private static array $allowed_extensions = [
        'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'sass', 'less',
        'html', 'htm', 'json', 'xml', 'txt', 'md', 'markdown',
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot',
        'map', 'lock', 'gitignore', 'editorconfig', 'pot', 'po', 'mo',
        'csv', 'yaml', 'yml', 'env', 'htaccess', 'bak',
    ];

    /** Explicitly blocked extensions — never allow these */
    private static array $blocked_extensions = [
        'exe', 'sh', 'bash', 'bat', 'cmd', 'com', 'msi', 'dll', 'so',
        'phar', 'cgi', 'pl', 'py', 'rb', 'go',
    ];

    public static function verify_request(): bool {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        $nonce = $_SERVER['HTTP_X_WPFD_NONCE'] ?? ( $_REQUEST['_wpnonce'] ?? '' );
        return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
    }

    public static function create_nonce(): string {
        return wp_create_nonce( self::NONCE_ACTION );
    }

    public static function is_allowed_extension( string $filename ): bool {
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, self::$blocked_extensions, true ) ) {
            return false;
        }
        // Files with no extension (e.g. Makefile, LICENSE) are allowed
        if ( $ext === '' ) {
            return true;
        }
        return in_array( $ext, self::$allowed_extensions, true );
    }

    /**
     * Validate that a resolved path stays within the plugins directory.
     * Prevents path traversal attacks.
     */
    public static function validate_deploy_path( string $path ): bool {
        $real_plugins = realpath( WPFD_DEPLOY_DIR );
        $real_path    = realpath( dirname( $path ) );

        if ( $real_plugins === false ) {
            return false;
        }
        if ( $real_path === false ) {
            // Directory doesn't exist yet — validate the prefix
            return str_starts_with( $path, $real_plugins . DIRECTORY_SEPARATOR );
        }
        return str_starts_with( $real_path . DIRECTORY_SEPARATOR, $real_plugins . DIRECTORY_SEPARATOR );
    }

    /**
     * Sanitize a relative path segment — strip null bytes, dotdot sequences, etc.
     */
    public static function sanitize_relative_path( string $path ): string {
        // Remove null bytes
        $path = str_replace( "\0", '', $path );
        // Normalize separators
        $path = str_replace( '\\', '/', $path );
        // Remove leading slashes
        $path = ltrim( $path, '/' );
        // Collapse .. traversal
        $parts  = explode( '/', $path );
        $safe   = [];
        foreach ( $parts as $part ) {
            if ( $part === '..' || $part === '.' || $part === '' ) {
                continue;
            }
            $safe[] = sanitize_file_name( $part );
        }
        return implode( '/', $safe );
    }
}
