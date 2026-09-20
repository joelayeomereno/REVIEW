<?php
defined( 'ABSPATH' ) || exit;

class WPFD_Security {

    const NONCE_ACTION = 'wpfd_deploy_action';

    /** Allowed file extensions for upload — union of REST-API and receiver allowlists */
    private static array $allowed_extensions = [
        // PHP / templating
        'php', 'phtml', 'inc',
        // JavaScript
        'js', 'mjs', 'cjs', 'jsx',
        // TypeScript
        'ts', 'tsx',
        // Styles
        'css', 'scss', 'sass', 'less', 'vue',
        // Markup
        'html', 'htm',
        // Data / config
        'json', 'xml', 'yaml', 'yml', 'toml', 'ini', 'neon', 'csv',
        // Text / docs
        'txt', 'md', 'markdown',
        // Images
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico',
        // Fonts
        'woff', 'woff2', 'ttf', 'eot',
        // Build / tooling
        'map', 'lock', 'dist', 'stub',
        // Templating engines
        'twig', 'blade', 'njk', 'hbs',
        // i18n
        'pot', 'po', 'mo',
        // Database
        'sql',
        // Dot-config files (extension == everything after the last dot, e.g. ".gitignore" → ext "gitignore")
        'gitignore', 'editorconfig', 'env', 'htaccess', 'bak',
    ];

    /** Explicitly blocked extensions — never allow these */
    private static array $blocked_extensions = [
        'exe', 'sh', 'bash', 'bat', 'cmd', 'com', 'msi', 'dll', 'so',
        'phar', 'cgi', 'pl', 'py', 'rb', 'go',
    ];

    /** Extensions blocked from browser read/extract to prevent sensitive file exposure */
    private static array $browser_blocked_extensions = [
        'env', 'htaccess', 'htpasswd', 'conf', 'sql', 'bak', 'key', 'pem', 'crt', 'pfx', 'p12',
    ];

    /** Exact filenames blocked from browser read/extract */
    private static array $browser_blocked_files = [
        '.env', '.htaccess', '.htpasswd', 'wp-config.php',
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
     *
     * Uses strpos() instead of str_starts_with() for PHP 7.4 compatibility
     * (str_starts_with requires PHP 8.0+).
     */
    public static function validate_deploy_path( string $path ): bool {
        $real_plugins = realpath( WPFD_DEPLOY_DIR );
        $real_path    = realpath( dirname( $path ) );

        if ( $real_plugins === false ) {
            return false;
        }
        if ( $real_path === false ) {
            // Directory doesn't exist yet — validate the prefix.
            return strpos( $path, $real_plugins . DIRECTORY_SEPARATOR ) === 0;
        }
        return strpos( $real_path . DIRECTORY_SEPARATOR, $real_plugins . DIRECTORY_SEPARATOR ) === 0;
    }

    /**
     * Validate a backup directory path.
     *
     * Path must live inside the WordPress install tree and must not overlap the plugins deploy directory.
     *
     * @return true|string True on success, or an error message string.
     */
    public static function validate_backup_path( string $path ) {
        $path = trailingslashit( wp_normalize_path( untrailingslashit( $path ) ) );

        $abspath = realpath( ABSPATH );
        if ( $abspath === false ) {
            return 'WordPress root directory could not be resolved.';
        }
        $abspath = trailingslashit( wp_normalize_path( $abspath ) );

        $resolved = realpath( untrailingslashit( $path ) );
        if ( $resolved !== false ) {
            $check = trailingslashit( wp_normalize_path( $resolved ) );
        } else {
            $parent = dirname( untrailingslashit( $path ) );
            $parent_real = realpath( $parent );
            if ( $parent_real === false ) {
                return 'Backup directory must be inside the WordPress installation tree.';
            }
            $check = trailingslashit( wp_normalize_path( $parent_real ) ) . basename( untrailingslashit( $path ) ) . '/';
        }

        if ( ! str_starts_with( $check, $abspath ) ) {
            return 'Backup directory must be inside the WordPress installation tree.';
        }

        $deploy = realpath( WPFD_DEPLOY_DIR );
        if ( $deploy !== false ) {
            $deploy = trailingslashit( wp_normalize_path( $deploy ) );
            if ( str_starts_with( $check, $deploy ) ) {
                return 'Backup directory cannot be inside the plugins directory.';
            }
        }

        return true;
    }

    /**
     * Resolve admin-entered backup directory input to a normalized absolute path.
     */
    public static function resolve_backup_dir_input( string $input ): string {
        $input = trim( str_replace( '\\', '/', $input ) );
        if ( $input === '' ) {
            return trailingslashit( wp_normalize_path( WP_CONTENT_DIR . '/wpfd-backups' ) );
        }

        if ( preg_match( '~^(?:[a-zA-Z]:)?/~', $input ) ) {
            return trailingslashit( wp_normalize_path( untrailingslashit( $input ) ) );
        }

        return trailingslashit( wp_normalize_path( ABSPATH . ltrim( $input, '/' ) ) );
    }

    /**
     * Check whether a file should be blocked from browser read/extract endpoints.
     *
     * @param string $abs_path Full absolute path to the file.
     * @return bool True if the file is blocked.
     */
    public static function is_browser_blocked_file( string $abs_path ): bool {
        $file_ext  = strtolower( pathinfo( $abs_path, PATHINFO_EXTENSION ) );
        $file_base = strtolower( basename( $abs_path ) );
        return in_array( $file_ext, self::$browser_blocked_extensions, true )
            || in_array( $file_base, self::$browser_blocked_files, true );
    }

    /**
     * Sanitize a relative path — strip traversal sequences and dangerous characters
     * while PRESERVING legitimate file-name conventions such as leading dots
     * (.gitignore, .htaccess, .env), leading underscores (_variables.scss),
     * and leading dashes.
     *
     * WARNING: Do NOT replace this with sanitize_file_name() calls per segment.
     * sanitize_file_name() calls trim($name, '.-_') which silently strips leading
     * dots and underscores, causing _variables.scss → variables.scss and
     * .gitignore → gitignore — files deployed to wrong paths with no error.
     */
    public static function sanitize_relative_path( string $path ): string {
        // Remove null bytes.
        $path = str_replace( "\0", '', $path );
        // Normalize separators.
        $path = str_replace( '\\', '/', $path );
        // Remove leading slashes.
        $path = ltrim( $path, '/' );
        // Split and sanitize per-segment.
        $parts = explode( '/', $path );
        $safe  = [];
        foreach ( $parts as $part ) {
            if ( $part === '..' || $part === '.' || $part === '' ) {
                continue;
            }
            $clean = self::sanitize_path_segment( $part );
            if ( strtolower( $clean ) === '.git' ) {
                return '';
            }
            if ( $clean !== '' ) {
                $safe[] = $clean;
            }
        }
        return implode( '/', $safe );
    }

    /**
     * Sanitize a single path component (one directory name or file name — no slashes).
     *
     * Only strips characters that are genuinely dangerous or filesystem-illegal:
     *  - ASCII control characters (0x00–0x1F, 0x7F)
     *  - Characters forbidden in Windows paths: < > : " / \ | ? *
     *  - Repeated-dot sequences that could be exploited for traversal (e.g. "...")
     *
     * Deliberately preserved (unlike sanitize_file_name):
     *  - Leading dot  → .gitignore, .htaccess, .env
     *  - Leading underscore → _variables.scss, _functions.php
     *  - Leading dash  → rarely used but valid on Linux
     *
     * @param string $segment A single file or directory name (no path separators).
     * @return string Sanitized segment; empty string if entire input was stripped.
     */
    private static function sanitize_path_segment( string $segment ): string {
        // Strip null bytes and ASCII control characters.
        $segment = (string) preg_replace( '/[\x00-\x1f\x7f]/', '', $segment );
        // Strip characters forbidden in Windows file-system paths (and path separators).
        $segment = str_replace( [ '/', '\\', '<', '>', ':', '"', '|', '?', '*' ], '', $segment );
        // Collapse repeated dots to a single dot (prevents traversal variants like "...").
        $segment = (string) preg_replace( '/\.{2,}/', '.', $segment );
        return $segment;
    }
}
