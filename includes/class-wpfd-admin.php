<?php
defined( 'ABSPATH' ) || exit;

class WPFD_Admin {

    const PAGE_SLUG          = 'wpfd-plugin-folder-installer';
    const LEGACY_PAGE_SLUG   = 'wp-folder-deployer';
    const PLUGINS_PAGE_SLUG  = 'wpfd-plugin-folder-installer-plugins';
    const BROWSER_PAGE_SLUG  = 'wpfd-plugin-folder-installer-browser';
    const SETTINGS_PAGE_SLUG = 'wpfd-plugin-folder-installer-settings';

    private array $page_hooks = [];

    public function register_menu(): void {
        $this->page_hooks[] = add_menu_page(
            'Plugin Folder Installer',
            'Plugin Folder Installer',
            'install_plugins',
            self::PAGE_SLUG,
            [ $this, 'render_installer_page' ],
            'dashicons-upload',
            75
        );

        $this->page_hooks[] = add_submenu_page(
            self::PAGE_SLUG,
            'Installer',
            'Installer',
            'install_plugins',
            self::PAGE_SLUG,
            [ $this, 'render_installer_page' ]
        );

        $this->page_hooks[] = add_submenu_page(
            self::PAGE_SLUG,
            'Plugins & Backups',
            'Plugins & Backups',
            'manage_options',
            self::PLUGINS_PAGE_SLUG,
            [ $this, 'render_plugins_page' ]
        );

        $this->page_hooks[] = add_submenu_page(
            self::PAGE_SLUG,
            'Browser & Nuker',
            'Browser & Nuker',
            'manage_options',
            self::BROWSER_PAGE_SLUG,
            [ $this, 'render_browser_page' ]
        );

        $this->page_hooks[] = add_submenu_page(
            self::PAGE_SLUG,
            'Settings',
            'Settings',
            'manage_options',
            self::SETTINGS_PAGE_SLUG,
            [ $this, 'render_settings_page' ]
        );

        $this->page_hooks[] = add_submenu_page(
            'plugins.php',
            'Plugin Folder Installer',
            'Plugin Folder Installer',
            'install_plugins',
            self::PAGE_SLUG,
            [ $this, 'render_installer_page' ]
        );

        $legacy_hook = add_submenu_page(
            null,
            'Plugin Folder Installer',
            'Plugin Folder Installer',
            'install_plugins',
            self::LEGACY_PAGE_SLUG,
            [ $this, 'render_installer_page' ]
        );

        if ( false !== $legacy_hook ) {
            $this->page_hooks[] = $legacy_hook;
        }
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, $this->page_hooks, true ) ) {
            return;
        }

        $installer_css_mtime = file_exists( WPFD_PLUGIN_DIR . 'admin/css/plugin-folder-installer.css' )
            ? (int) filemtime( WPFD_PLUGIN_DIR . 'admin/css/plugin-folder-installer.css' )
            : 0;
        $installer_js_mtime = file_exists( WPFD_PLUGIN_DIR . 'admin/js/plugin-folder-installer.js' )
            ? (int) filemtime( WPFD_PLUGIN_DIR . 'admin/js/plugin-folder-installer.js' )
            : 0;
        $installer_css_version = WPFD_VERSION . '.' . $installer_css_mtime;
        $installer_js_version  = WPFD_VERSION . '.' . $installer_js_mtime;

        wp_enqueue_script(
            'wpfd-jszip',
            WPFD_PLUGIN_URL . 'admin/js/jszip.min.js',
            [],
            '3.10.1',
            true
        );

        wp_enqueue_style(
            'wpfd-plugin-folder-installer',
            WPFD_PLUGIN_URL . 'admin/css/plugin-folder-installer.css',
            [],
            $installer_css_version
        );

        wp_enqueue_script(
            'wpfd-plugin-folder-installer',
            WPFD_PLUGIN_URL . 'admin/js/plugin-folder-installer.js',
            [ 'wpfd-jszip' ],
            $installer_js_version,
            true
        );

        wp_localize_script( 'wpfd-plugin-folder-installer', 'WPFDPluginFolderInstaller', [
            'restUrl'         => rest_url( 'wpfd/v1' ),
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => WPFD_Security::create_nonce(),
            'wpNonce'         => wp_create_nonce( 'wp_rest' ),
            'endpoint'        => '/plugin-folder-installer/process',
            'installAction'   => 'wpfd_process_install',
            'canManageOptions'   => current_user_can( 'manage_options' ),
            'maxFileUploads'  => (int) ini_get( 'max_file_uploads' ),
            'maxUploadBytes'  => wp_max_upload_size(),
            'labels'          => [
                'idle'       => 'Queued',
                'uploading'  => 'Uploading files',
                'processing' => 'Validating, zipping, installing, and activating',
            ],
            'appVersion'      => WPFD_VERSION,
        ] );
    }

    public function render_page(): void {
        $this->render_installer_page();
    }

    public function render_installer_page(): void {
        if ( ! current_user_can( 'install_plugins' ) ) {
            wp_die( esc_html__( 'You do not have permission to install plugins.', 'wpfd-plugin-folder-installer' ) );
        }

        $view_mode         = 'installer';
        $active_tool_panel = '';
        $tool_page         = [];
        $page_urls         = $this->get_page_urls();

        WPFD_Plugin_Folder_Installer::bootstrap();
        include WPFD_PLUGIN_DIR . 'admin/views/plugin-folder-installer.php';
    }

    public function render_plugins_page(): void {
        $this->render_tool_page( 'plugins' );
    }

    public function render_browser_page(): void {
        $this->render_tool_page( 'browser' );
    }

    public function render_settings_page(): void {
        $this->render_tool_page( 'settings' );
    }

    private function render_tool_page( string $panel ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'wpfd-plugin-folder-installer' ) );
        }

        $view_mode         = 'tool';
        $active_tool_panel = $panel;
        $tool_page         = $this->get_tool_page_config( $panel );
        $page_urls         = $this->get_page_urls();

        WPFD_Plugin_Folder_Installer::bootstrap();
        include WPFD_PLUGIN_DIR . 'admin/views/plugin-folder-installer.php';
    }

    private function get_page_urls(): array {
        return [
            'installer' => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
            'plugins'   => admin_url( 'admin.php?page=' . self::PLUGINS_PAGE_SLUG ),
            'browser'   => admin_url( 'admin.php?page=' . self::BROWSER_PAGE_SLUG ),
            'settings'  => admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG ),
        ];
    }

    private function get_tool_page_config( string $panel ): array {
        $tool_pages = [
            'plugins' => [
                'eyebrow' => 'Tool Page',
                'title'   => 'Plugins & Backups',
                'lead'    => 'Manage installed plugins and rollback backups on a dedicated page.',
            ],
            'browser' => [
                'eyebrow' => 'Tool Page',
                'title'   => 'Browser & Nuker',
                'lead'    => 'Browse safe roots, inspect paths, and run deliberate file actions on a dedicated page.',
            ],
            'settings' => [
                'eyebrow' => 'Tool Page',
                'title'   => 'Settings',
                'lead'    => 'Adjust installer retention settings without leaving a focused tool page.',
            ],
        ];

        return $tool_pages[ $panel ] ?? [
            'eyebrow' => 'Tool Page',
            'title'   => 'Tools',
            'lead'    => 'Manage this installer tool on its own page.',
        ];
    }
}
