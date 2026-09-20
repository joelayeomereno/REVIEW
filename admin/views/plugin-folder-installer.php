<?php
defined( 'ABSPATH' ) || exit;

$can_manage_tools  = current_user_can( 'manage_options' );
$max_file_uploads  = (int) ini_get( 'max_file_uploads' );
$max_upload_bytes  = wp_max_upload_size();
$max_upload_label  = function_exists( 'size_format' ) ? size_format( $max_upload_bytes ) : (string) $max_upload_bytes . ' B';
$upload_max_size   = (string) ini_get( 'upload_max_filesize' );
$post_max_size     = (string) ini_get( 'post_max_size' );
$view_mode         = isset( $view_mode ) && 'tool' === $view_mode ? 'tool' : 'installer';
$active_tool_panel = isset( $active_tool_panel ) && is_string( $active_tool_panel ) ? $active_tool_panel : '';
$tool_page         = isset( $tool_page ) && is_array( $tool_page ) ? $tool_page : [];
$page_urls         = isset( $page_urls ) && is_array( $page_urls ) ? $page_urls : [];
$current_page_key  = 'installer' === $view_mode ? 'installer' : $active_tool_panel;
$page_links        = [
    'installer' => 'Installer',
    'plugins'   => 'Plugins & Backups',
    'browser'   => 'Browser & Nuker',
    'settings'  => 'Settings',
];
$page_eyebrow      = 'installer' === $view_mode ? 'Plugin Installer' : (string) ( $tool_page['eyebrow'] ?? 'Tool Page' );
$page_title        = 'installer' === $view_mode ? 'Plugin Folder Installer' : (string) ( $tool_page['title'] ?? 'Installer Tools' );
$page_lead         = 'installer' === $view_mode
    ? 'Add plugin folders from your PC, review the batch, then queue installs.'
    : (string) ( $tool_page['lead'] ?? 'Manage this installer tool on its own page.' );
?>
<div class="wrap wpfd-pfi-page" data-wpfd-view-mode="<?php echo esc_attr( $view_mode ); ?>" data-wpfd-initial-panel="<?php echo esc_attr( $active_tool_panel ); ?>">
    <div class="wpfd-pfi-shell pfi-shell">
        <header class="wpfd-pfi-page-header">
            <div class="wpfd-pfi-page-title">
                <p class="wpfd-pfi-eyebrow"><?php echo esc_html( $page_eyebrow ); ?></p>
                <h1><?php echo esc_html( $page_title ); ?></h1>
                <p class="wpfd-pfi-page-lead"><?php echo esc_html( $page_lead ); ?></p>
            </div>

            <?php if ( $can_manage_tools ) : ?>
                <div class="wpfd-pfi-page-actions">
                    <?php if ( 'installer' === $view_mode ) : ?>
                        <button id="wpfd-pfi-open-history" type="button" class="button wpfd-pfi-history-launch">History</button>
                    <?php endif; ?>

                    <details class="wpfd-pfi-page-switcher">
                        <summary>Open Page</summary>
                        <div class="wpfd-pfi-page-switcher-menu">
                            <?php foreach ( $page_links as $page_key => $page_label ) : ?>
                                <?php $page_url = isset( $page_urls[ $page_key ] ) ? (string) $page_urls[ $page_key ] : '#'; ?>
                                <a class="wpfd-pfi-page-switcher-link<?php echo $current_page_key === $page_key ? ' is-current' : ''; ?>" href="<?php echo esc_url( $page_url ); ?>"><?php echo esc_html( $page_label ); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div>
            <?php endif; ?>
        </header>

        <?php if ( 'installer' === $view_mode ) : ?>
            <div class="wpfd-pfi-app-grid pfi-app-grid">
                <section class="wpfd-pfi-card pfi-card wpfd-pfi-upload-card">
                    <input id="wpfd-pfi-input" type="file" webkitdirectory directory multiple hidden>

                    <div class="wpfd-pfi-install-workflow">
                        <section class="wpfd-pfi-install-step" aria-labelledby="wpfd-pfi-step-add-title">
                            <header class="wpfd-pfi-step-header">
                                <span class="wpfd-pfi-step-num" aria-hidden="true">1</span>
                                <div class="wpfd-pfi-step-heading">
                                    <h2 id="wpfd-pfi-step-add-title">Add folders</h2>
                                    <p class="wpfd-pfi-step-copy">Browse your PC or drop plugin folders below.</p>
                                </div>
                            </header>

                            <div class="wpfd-pfi-step-body wpfd-pfi-step-add">
                                <button type="button" id="wpfd-pfi-batch-browse" class="button button-primary">Browse PC folders</button>

                                <div class="wpfd-pfi-dropzone-shell">
                                    <div id="wpfd-pfi-dropzone" class="wpfd-pfi-dropzone" tabindex="0" role="button" aria-label="Drop plugin folders here or open the PC folder browser">
                                        <span class="wpfd-pfi-dropzone-icon" aria-hidden="true">+</span>
                                        <strong>Drop plugin folders here</strong>
                                        <span class="wpfd-pfi-dropzone-hint">or click to open the PC folder browser</span>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="wpfd-pfi-install-step" aria-labelledby="wpfd-pfi-step-review-title">
                            <header class="wpfd-pfi-step-header">
                                <span class="wpfd-pfi-step-num" aria-hidden="true">2</span>
                                <div class="wpfd-pfi-step-heading">
                                    <h2 id="wpfd-pfi-step-review-title">
                                        Review batch
                                        <span id="wpfd-pfi-batch-count" class="wpfd-pfi-count-badge">0</span>
                                    </h2>
                                    <p class="wpfd-pfi-step-copy">Confirm folders before sending them to the install queue.</p>
                                </div>
                            </header>

                            <div id="wpfd-pfi-folder-batch" class="wpfd-pfi-folder-batch">
                                <div id="wpfd-pfi-batch-list" class="wpfd-pfi-batch-list" aria-live="polite">
                                    <p class="wpfd-pfi-batch-empty">No folders added yet. Use step 1 to browse or drop plugin folders.</p>
                                </div>
                                <div class="wpfd-pfi-batch-actions">
                                    <button type="button" id="wpfd-pfi-batch-clear" class="button">Clear batch</button>
                                    <button type="button" id="wpfd-pfi-batch-queue" class="button button-primary">Queue for install</button>
                                </div>
                            </div>
                        </section>
                    </div>

                    <details class="wpfd-pfi-diagnostics-footer wpfd-pfi-diagnostics">
                        <summary>Server limits and diagnostics</summary>
                        <div class="wpfd-pfi-diagnostic-list">
                            <div class="wpfd-pfi-diagnostic-row">
                                <span>Max file uploads</span>
                                <strong><?php echo esc_html( (string) $max_file_uploads ); ?></strong>
                            </div>
                            <div class="wpfd-pfi-diagnostic-row">
                                <span>WordPress upload limit</span>
                                <strong><?php echo esc_html( $max_upload_label ); ?></strong>
                            </div>
                            <div class="wpfd-pfi-diagnostic-row">
                                <span>PHP upload_max_filesize</span>
                                <strong><?php echo esc_html( $upload_max_size ); ?></strong>
                            </div>
                            <div class="wpfd-pfi-diagnostic-row">
                                <span>PHP post_max_size</span>
                                <strong><?php echo esc_html( $post_max_size ); ?></strong>
                            </div>
                            <div class="wpfd-pfi-diagnostic-row">
                                <span>Installer UI version</span>
                                <strong><?php echo esc_html( defined( 'WPFD_VERSION' ) ? WPFD_VERSION : 'unknown' ); ?></strong>
                            </div>
                            <div class="wpfd-pfi-diagnostic-row">
                                <span>Folder selection</span>
                                <strong id="wpfd-pfi-multi-folder-picker">Detecting…</strong>
                            </div>
                            <div class="wpfd-pfi-diagnostic-row">
                                <span>Queued batch</span>
                                <strong id="wpfd-pfi-current-batch">Awaiting queue</strong>
                            </div>
                        </div>
                    </details>

                </section>

                <aside class="wpfd-pfi-results-panel pfi-results-panel">
                    <div class="wpfd-pfi-results-panel-head">
                        <div class="wpfd-pfi-step-header wpfd-pfi-step-header-inline">
                            <span class="wpfd-pfi-step-num" aria-hidden="true">3</span>
                            <div class="wpfd-pfi-step-heading">
                                <p class="wpfd-pfi-eyebrow">Install queue</p>
                                <h2>Activity</h2>
                                <p class="wpfd-pfi-results-panel-copy">Track uploads and installs. Expand a row for step details.</p>
                            </div>
                        </div>

                        <div class="wpfd-pfi-results-panel-actions">
                            <div id="wpfd-pfi-results-meta" class="wpfd-pfi-results-meta">Awaiting queue</div>
                            <button id="wpfd-pfi-clear-completed" type="button" class="button">Clear done</button>
                        </div>
                    </div>

                    <div id="wpfd-pfi-notice" class="wpfd-pfi-notice" hidden></div>
                    <div id="wpfd-pfi-results" class="wpfd-pfi-results" aria-live="polite"></div>
                </aside>
            </div>

            <div id="wpfd-pfi-local-browser" class="wpfd-pfi-modal wpfd-pfi-local-browser" hidden>
                <div class="wpfd-pfi-modal-backdrop" data-wpfd-local-browser-close></div>
                <div class="wpfd-pfi-modal-dialog wpfd-pfi-local-browser-dialog" role="dialog" aria-modal="true" aria-labelledby="wpfd-pfi-local-browser-title">
                    <header class="wpfd-pfi-local-browser-header">
                        <div class="wpfd-pfi-local-browser-header-copy">
                            <p class="wpfd-pfi-eyebrow">PC folders</p>
                            <h2 id="wpfd-pfi-local-browser-title">Browse PC folders</h2>
                            <p class="wpfd-pfi-panel-copy">Connect a workspace once, then select plugin folders here.</p>
                        </div>
                        <button type="button" class="wpfd-pfi-local-browser-dismiss" data-wpfd-local-browser-close aria-label="Close folder browser">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </header>

                    <div class="wpfd-pfi-local-browser-controls">
                        <div class="wpfd-pfi-local-browser-field">
                            <label for="wpfd-pfi-local-browser-root">Connected root</label>
                            <select id="wpfd-pfi-local-browser-root" class="wpfd-pfi-local-browser-root"></select>
                        </div>
                        <button type="button" id="wpfd-pfi-local-browser-connect" class="button button-secondary">Connect folder</button>
                    </div>

                    <div class="wpfd-pfi-local-browser-nav">
                        <button type="button" id="wpfd-pfi-local-browser-up" class="button button-secondary" disabled aria-label="Go up one folder">Up</button>
                        <div id="wpfd-pfi-local-browser-breadcrumb" class="wpfd-pfi-local-browser-breadcrumb" aria-label="Current folder path"></div>
                    </div>

                    <div id="wpfd-pfi-local-browser-error" class="wpfd-pfi-local-browser-error" hidden></div>

                    <div class="wpfd-pfi-local-browser-panel">
                        <div id="wpfd-pfi-local-browser-list" class="wpfd-pfi-local-browser-list" aria-live="polite">
                            <p class="wpfd-pfi-local-browser-empty">Connect a folder on your PC to start browsing.</p>
                        </div>
                    </div>

                    <footer class="wpfd-pfi-local-browser-footer">
                        <span id="wpfd-pfi-local-browser-selected" class="wpfd-pfi-local-browser-selected" aria-live="polite">0 selected</span>
                        <div class="wpfd-pfi-local-browser-footer-actions">
                            <button type="button" id="wpfd-pfi-local-browser-add" class="button button-primary" disabled>Add to batch</button>
                            <button type="button" class="button button-secondary" data-wpfd-local-browser-close>Close</button>
                        </div>
                    </footer>
                </div>
            </div>

            <?php if ( $can_manage_tools ) : ?>
                <div id="wpfd-pfi-history-modal" class="wpfd-pfi-modal" hidden>
                    <div class="wpfd-pfi-modal-backdrop" data-wpfd-history-close></div>
                    <div class="wpfd-pfi-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="wpfd-pfi-history-title">
                        <div class="wpfd-pfi-modal-head">
                            <div>
                                <p class="wpfd-pfi-eyebrow">History</p>
                                <h2 id="wpfd-pfi-history-title">Installer History</h2>
                                <p class="wpfd-pfi-panel-copy">Review and clean installer history without leaving the main installer page.</p>
                            </div>

                            <div class="wpfd-pfi-modal-actions">
                                <button id="wpfd-pfi-refresh-history" type="button" class="button">Refresh History</button>
                                <button type="button" class="button" data-wpfd-history-close>Close</button>
                            </div>
                        </div>

                        <div id="wpfd-pfi-tool-notice" class="wpfd-pfi-tool-notice" hidden></div>
                        <div id="wpfd-pfi-history-panel" class="wpfd-pfi-stack wpfd-pfi-modal-stack" aria-live="polite"></div>
                    </div>
                </div>
            <?php endif; ?>
        <?php else : ?>
            <section class="wpfd-pfi-card pfi-card wpfd-pfi-tool-page-card">
                <?php if ( $can_manage_tools ) : ?>
                    <div id="wpfd-pfi-tool-notice" class="wpfd-pfi-tool-notice" hidden></div>

                    <?php if ( 'plugins' === $active_tool_panel ) : ?>
                        <section class="wpfd-pfi-tool-panel is-active" data-wpfd-tool-panel="plugins">
                            <div class="wpfd-pfi-panel-toolbar">
                                <div class="wpfd-pfi-panel-toolbar-copy">
                                    <h2>Plugins &amp; backups</h2>
                                    <p class="wpfd-pfi-panel-copy">Manage installed plugins and rollback backups.</p>
                                </div>
                                <button id="wpfd-pfi-refresh-plugins" type="button" class="button">Refresh</button>
                            </div>
                            <div id="wpfd-pfi-plugins-panel" class="wpfd-pfi-stack" aria-live="polite"></div>
                        </section>
                    <?php elseif ( 'browser' === $active_tool_panel ) : ?>
                        <section class="wpfd-pfi-tool-panel is-active" data-wpfd-tool-panel="browser">
                            <div class="wpfd-pfi-panel-toolbar">
                                <div class="wpfd-pfi-panel-toolbar-copy">
                                    <h2>Browser &amp; nuker</h2>
                                    <p class="wpfd-pfi-panel-copy">Browse safe roots, inspect paths, and run deliberate file actions.</p>
                                </div>
                            </div>

                            <div class="wpfd-pfi-browser-controls">
                                <div class="wpfd-pfi-browser-fields">
                                    <label class="wpfd-pfi-field">
                                        <span>Root</span>
                                        <select id="wpfd-pfi-browser-root"></select>
                                    </label>

                                    <label class="wpfd-pfi-field wpfd-pfi-field-wide">
                                        <span>Path</span>
                                        <input id="wpfd-pfi-browser-path" type="text" placeholder="plugin-slug/includes or leave blank for the root listing">
                                    </label>
                                </div>

                                <div class="wpfd-pfi-browser-actions">
                                    <button id="wpfd-pfi-browser-scan" type="button" class="button button-primary">Scan path</button>
                                    <button id="wpfd-pfi-browser-up" type="button" class="button">Up one level</button>
                                    <button id="wpfd-pfi-browser-inspect" type="button" class="button">Nuke scan</button>
                                    <button id="wpfd-pfi-browser-nuke" type="button" class="button button-secondary wpfd-pfi-danger-button">Delete path</button>
                                </div>
                            </div>

                            <div id="wpfd-pfi-browser-meta" class="wpfd-pfi-browser-meta"></div>
                            <div id="wpfd-pfi-browser-results" class="wpfd-pfi-browser-results" aria-live="polite"></div>
                            <div id="wpfd-pfi-browser-preview" class="wpfd-pfi-browser-preview" hidden></div>
                        </section>
                    <?php elseif ( 'settings' === $active_tool_panel ) : ?>
                        <section class="wpfd-pfi-tool-panel is-active" data-wpfd-tool-panel="settings">
                            <div class="wpfd-pfi-panel-toolbar">
                                <div class="wpfd-pfi-panel-toolbar-copy">
                                    <h2>Settings</h2>
                                    <p class="wpfd-pfi-panel-copy">Adjust backup retention and snapshot storage location.</p>
                                </div>
                            </div>

                            <form id="wpfd-pfi-settings-form" class="wpfd-pfi-settings-form">
                                <label class="wpfd-pfi-field wpfd-pfi-field-wide">
                                    <span>Backup directory</span>
                                    <input id="wpfd-pfi-settings-backup-dir" type="text" placeholder="wp-content/wpfd-backups/">
                                    <small>Path relative to the WordPress root, e.g. <code>wp-content/wpfd-backups/</code> or <code>wp-content/private-backups/</code>.</small>
                                </label>

                                <div class="wpfd-pfi-actions-row">
                                    <button type="button" class="button" id="wpfd-pfi-settings-validate-backup-dir">Validate location</button>
                                </div>

                                <label class="wpfd-pfi-field wpfd-pfi-field-wide">
                                    <span>Backup retention</span>
                                    <input id="wpfd-pfi-settings-retention" type="number" min="1" max="50" step="1">
                                    <small>How many rollback backups to keep per plugin before pruning older copies.</small>
                                </label>

                                <button type="submit" class="button button-primary">Save settings</button>
                            </form>

                            <div id="wpfd-pfi-settings-health" class="wpfd-pfi-mini-copy" aria-live="polite"></div>
                            <div id="wpfd-pfi-settings-status" class="wpfd-pfi-mini-copy" aria-live="polite"></div>
                        </section>
                    <?php endif; ?>
                <?php else : ?>
                    <div class="wpfd-pfi-tool-notice is-static">
                        These REST-backed admin tools require the <strong>manage_options</strong> capability. The folder installer remains visible because your account can install plugins.
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>
