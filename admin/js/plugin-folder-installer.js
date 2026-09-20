( function () {
    'use strict';

    const config = window.WPFDPluginFolderInstaller || {};
    const restUrl = config.restUrl || '';
    const installAction = config.installAction || 'wpfd_process_install';
    let nonce = normalizeNonceValue( config.nonce || '' );
    let wpNonce = normalizeNonceValue( config.wpNonce || '' );
    const ajaxUrl = config.ajaxUrl || '';
    const canManageOptions = Boolean( config.canManageOptions );
    const maxUploadBytes = Number( config.maxUploadBytes || 0 );
    const maxFileUploads = Math.max( 1, Number( config.maxFileUploads || 20 ) );
    const pageRoot = document.querySelector( '.wpfd-pfi-page' );
    const viewMode = pageRoot?.getAttribute( 'data-wpfd-view-mode' ) || 'installer';
    const initialToolPanel = pageRoot?.getAttribute( 'data-wpfd-initial-panel' ) || '';

    const state = {
        items: [],
        currentBatchId: '',
        processing: false,
        picking: false,
        pendingBatch: {
            folders: [],
        },
        localBrowser: {
            open: false,
            roots: [],
            activeRootId: '',
            pathStack: [],
            entries: [],
            selectedHandles: {},
            loading: false,
            error: '',
        },
        tools: {
            activePanel: initialToolPanel,
            loaded: {
                plugins: false,
                history: false,
                browser: false,
                settings: false,
            },
            loading: {
                plugins: false,
                history: false,
                browser: false,
                settings: false,
            },
            plugins: [],
            history: {
                rows: [],
                total: 0,
                truncated: false,
            },
            backupsBySlug: {},
            browser: {
                roots: {},
                root: 'plugins',
                path: '',
                entries: [],
                summary: null,
                preview: null,
            },
            settings: {
                backup_dir: 'wp-content/wpfd-backups/',
                backup_retention: 5,
            },
            settingsDisplay: {
                backup_dir: 'wp-content/wpfd-backups/',
                resolved_backup_dir: '',
                resolved_display: '',
            },
            settingsHealth: {
                ziparchive: false,
                backup_dir_writable: false,
                last_test: {},
            },
        },
    };

    const input = document.getElementById( 'wpfd-pfi-input' );
    const dropzone = document.getElementById( 'wpfd-pfi-dropzone' );
    const batchList = document.getElementById( 'wpfd-pfi-batch-list' );
    const batchBrowse = document.getElementById( 'wpfd-pfi-batch-browse' );
    const batchClear = document.getElementById( 'wpfd-pfi-batch-clear' );
    const batchQueue = document.getElementById( 'wpfd-pfi-batch-queue' );
    const batchCount = document.getElementById( 'wpfd-pfi-batch-count' );
    const localBrowserModal = document.getElementById( 'wpfd-pfi-local-browser' );
    const localBrowserRoot = document.getElementById( 'wpfd-pfi-local-browser-root' );
    const localBrowserConnect = document.getElementById( 'wpfd-pfi-local-browser-connect' );
    const localBrowserUp = document.getElementById( 'wpfd-pfi-local-browser-up' );
    const localBrowserBreadcrumb = document.getElementById( 'wpfd-pfi-local-browser-breadcrumb' );
    const localBrowserError = document.getElementById( 'wpfd-pfi-local-browser-error' );
    const localBrowserList = document.getElementById( 'wpfd-pfi-local-browser-list' );
    const localBrowserAdd = document.getElementById( 'wpfd-pfi-local-browser-add' );
    const localBrowserSelected = document.getElementById( 'wpfd-pfi-local-browser-selected' );
    const localBrowserCloseButtons = Array.from( document.querySelectorAll( '[data-wpfd-local-browser-close]' ) );
    const results = document.getElementById( 'wpfd-pfi-results' );
    const notice = document.getElementById( 'wpfd-pfi-notice' );
    const currentBatch = document.getElementById( 'wpfd-pfi-current-batch' );
    const resultsMeta = document.getElementById( 'wpfd-pfi-results-meta' );
    const clearCompleted = document.getElementById( 'wpfd-pfi-clear-completed' );
    const openHistory = document.getElementById( 'wpfd-pfi-open-history' );
    const historyModal = document.getElementById( 'wpfd-pfi-history-modal' );
    const historyCloseButtons = Array.from( document.querySelectorAll( '[data-wpfd-history-close]' ) );
    const toolTabs = Array.from( document.querySelectorAll( '[data-wpfd-tool-tab]' ) );
    const toolPanels = Array.from( document.querySelectorAll( '[data-wpfd-tool-panel]' ) );
    const toolNotice = document.getElementById( 'wpfd-pfi-tool-notice' );
    const refreshPlugins = document.getElementById( 'wpfd-pfi-refresh-plugins' );
    const pluginsPanel = document.getElementById( 'wpfd-pfi-plugins-panel' );
    const refreshHistory = document.getElementById( 'wpfd-pfi-refresh-history' );
    const historyPanel = document.getElementById( 'wpfd-pfi-history-panel' );
    const browserRoot = document.getElementById( 'wpfd-pfi-browser-root' );
    const browserPath = document.getElementById( 'wpfd-pfi-browser-path' );
    const browserScan = document.getElementById( 'wpfd-pfi-browser-scan' );
    const browserUp = document.getElementById( 'wpfd-pfi-browser-up' );
    const browserInspect = document.getElementById( 'wpfd-pfi-browser-inspect' );
    const browserNuke = document.getElementById( 'wpfd-pfi-browser-nuke' );
    const browserMeta = document.getElementById( 'wpfd-pfi-browser-meta' );
    const browserResults = document.getElementById( 'wpfd-pfi-browser-results' );
    const browserPreview = document.getElementById( 'wpfd-pfi-browser-preview' );
    const settingsForm = document.getElementById( 'wpfd-pfi-settings-form' );
    const settingsRetention = document.getElementById( 'wpfd-pfi-settings-retention' );
    const settingsBackupDir = document.getElementById( 'wpfd-pfi-settings-backup-dir' );
    const settingsValidateBackupDir = document.getElementById( 'wpfd-pfi-settings-validate-backup-dir' );
    const settingsHealth = document.getElementById( 'wpfd-pfi-settings-health' );
    const settingsStatus = document.getElementById( 'wpfd-pfi-settings-status' );
    const multiFolderPickerMode = document.getElementById( 'wpfd-pfi-multi-folder-picker' );

    const hasInstallerSurface = Boolean( input && dropzone && results && notice && clearCompleted );
    const hasToolSurface = Boolean( toolPanels.length || pluginsPanel || historyPanel || browserResults || settingsForm );

    if ( ! pageRoot || ( ! hasInstallerSurface && ! hasToolSurface ) ) {
        return;
    }

    const stepLabels = [ 'upload', 'validation', 'zip', 'install', 'activation' ];
    const stepTitles = {
        upload: 'Upload',
        validation: 'Check',
        zip: 'Package',
        install: 'Install',
        activation: 'Activate',
    };

    const LOCAL_ROOTS_DB = 'wpfd-local-roots';
    const LOCAL_ROOTS_STORE = 'roots';

    function supportsLocalFolderBrowser() {
        return typeof window.showDirectoryPicker === 'function';
    }

    function createId() {
        return `${ Date.now().toString( 36 ) }-${ Math.random().toString( 36 ).slice( 2, 8 ) }`;
    }

    function createBatchId() {
        const stamp = new Date().toISOString().replace( /[-:TZ.]/g, '' ).slice( 2, 14 );
        return `B-${ stamp }-${ Math.random().toString( 36 ).slice( 2, 6 ).toUpperCase() }`;
    }

    function escapeHtml( value ) {
        const div = document.createElement( 'div' );
        div.textContent = String( value ?? '' );
        return div.innerHTML;
    }

    function normalizeRelativePath( value ) {
        return String( value || '' ).replace( /\\/g, '/' ).replace( /^\/+/, '' ).replace( /\/+/g, '/' );
    }

    function formatTraceEntry( entry ) {
        if ( ! entry || typeof entry !== 'object' ) {
            return '';
        }

        const message = typeof entry.message === 'string' ? entry.message : '';
        if ( ! message ) {
            return '';
        }

        const stage = typeof entry.stage === 'string' && entry.stage ? `[${ entry.stage }] ` : '';
        const context = entry.context && typeof entry.context === 'object'
            ? Object.entries( entry.context )
                .filter( ( [ , value ] ) => value !== '' && value !== null && typeof value !== 'undefined' )
                .map( ( [ key, value ] ) => `${ key }: ${ value }` )
                .join( ', ' )
            : '';

        return context ? `${ stage }${ message } (${ context })` : `${ stage }${ message }`;
    }

    function buildInvalidJsonDetails( xhr, error ) {
        const details = [];
        const parseMessage = error instanceof Error ? error.message : 'Unknown JSON parse error.';
        details.push( parseMessage );

        if ( xhr.status ) {
            const statusText = xhr.statusText ? ` ${ xhr.statusText }` : '';
            details.push( `HTTP ${ xhr.status }${ statusText }` );
        }

        const contentType = xhr.getResponseHeader( 'Content-Type' );
        if ( contentType ) {
            details.push( `Content-Type: ${ contentType }` );
        }

        const rawResponse = String( xhr.responseText || '' ).replace( /^[\uFEFF\u200B]+/, '' ).trim();
        if ( /^<!DOCTYPE|^<html/i.test( rawResponse ) ) {
            details.push( 'The server returned HTML instead of REST JSON. This usually means a PHP upload limit, authentication screen, or host error page interrupted the request.' );
        }

        if ( rawResponse ) {
            details.push( `Response excerpt: ${ rawResponse.replace( /\s+/g, ' ' ).slice( 0, 220 ) }` );
        }

        return details;
    }

    async function buildPackageBlob( item ) {
        const zip = new JSZip();
        item.message = 'Packaging the selected folder into a ZIP for upload.';
        render();

        item.files.forEach( ( file ) => {
            const relativePath = normalizeRelativePath( file._wpfdRelativePath || file.webkitRelativePath || file.name );
            zip.file( relativePath, file );
        } );

        return zip.generateAsync(
            {
                type: 'blob',
                compression: 'DEFLATE',
                compressionOptions: {
                    level: item.totalBytes < 2 * 1024 * 1024 ? 1 : 6,
                },
            },
            ( metadata ) => {
                const percent = Math.max( 1, Math.min( 99, Math.round( Number( metadata?.percent || 0 ) ) ) );
                item.message = `Packaging the selected folder into a ZIP for upload (${ percent }%).`;
                render();
            }
        );
    }

    function bytesToLabel( bytes ) {
        const numericBytes = Number( bytes || 0 );

        if ( numericBytes < 1024 ) {
            return `${ numericBytes } B`;
        }

        if ( numericBytes < 1048576 ) {
            return `${ ( numericBytes / 1024 ).toFixed( 1 ) } KB`;
        }

        return `${ ( numericBytes / 1048576 ).toFixed( 2 ) } MB`;
    }

    function formatDateLabel( value ) {
        if ( ! value && value !== 0 ) {
            return 'Unknown';
        }

        if ( typeof value === 'number' || /^\d+$/.test( String( value ) ) ) {
            const numeric = Number( value );
            const date = new Date( numeric < 2000000000 ? numeric * 1000 : numeric );

            if ( ! Number.isNaN( date.getTime() ) ) {
                return date.toLocaleString();
            }
        }

        if ( typeof value === 'string' ) {
            const normalized = value.includes( 'T' ) ? value : `${ value.replace( ' ', 'T' ) }Z`;
            const date = new Date( normalized );

            if ( ! Number.isNaN( date.getTime() ) ) {
                return date.toLocaleString();
            }
        }

        return String( value );
    }

    function formatRelativeTimeAgo( value ) {
        const timestamp = Number( value );
        if ( ! timestamp ) {
            return '';
        }

        const seconds = Math.max( 0, Math.floor( ( Date.now() - timestamp ) / 1000 ) );
        if ( seconds < 10 ) {
            return 'just now';
        }
        if ( seconds < 60 ) {
            return `${ seconds }s ago`;
        }

        const minutes = Math.floor( seconds / 60 );
        if ( minutes < 60 ) {
            return `${ minutes }m ago`;
        }

        const hours = Math.floor( minutes / 60 );
        if ( hours < 24 ) {
            return `${ hours }h ago`;
        }

        const days = Math.floor( hours / 24 );
        return `${ days }d ago`;
    }

    function markItemFinished( item ) {
        if ( item ) {
            item.finishedAt = Date.now();
        }
    }

    function getItemFinishedAt( item ) {
        if ( ! item ) {
            return 0;
        }

        if ( item.finishedAt ) {
            return item.finishedAt;
        }

        if ( item.status === 'success' || item.status === 'failed' ) {
            item.finishedAt = item.createdAt || Date.now();
        }

        return item.finishedAt || 0;
    }

    function getDisplayItems() {
        return state.items.slice().sort( ( left, right ) => {
            const leftRank = left.status === 'running' ? 2 : ( left.status === 'queued' ? 1 : 0 );
            const rightRank = right.status === 'running' ? 2 : ( right.status === 'queued' ? 1 : 0 );

            if ( leftRank !== rightRank ) {
                return rightRank - leftRank;
            }

            return ( right.createdAt || 0 ) - ( left.createdAt || 0 );
        } );
    }

    function formatDurationLabel( value ) {
        const duration = Number( value || 0 );
        if ( duration <= 0 ) {
            return 'n/a';
        }

        if ( duration < 1000 ) {
            return `${ duration } ms`;
        }

        return `${ ( duration / 1000 ).toFixed( 1 ) } s`;
    }

    function parentPath( value ) {
        const parts = normalizeRelativePath( value ).split( '/' ).filter( Boolean );
        return parts.slice( 0, -1 ).join( '/' );
    }

    function joinRelativePath( basePath, leaf ) {
        return normalizeRelativePath( [ basePath, leaf ].filter( Boolean ).join( '/' ) );
    }

    function setNotice( message ) {
        if ( ! notice ) {
            return;
        }

        if ( ! message ) {
            notice.hidden = true;
            notice.textContent = '';
            return;
        }

        notice.hidden = false;
        notice.textContent = message;
    }

    function setToolNotice( message, tone ) {
        if ( ! toolNotice ) {
            return;
        }

        toolNotice.hidden = ! message;
        toolNotice.textContent = message || '';
        toolNotice.classList.remove( 'is-success', 'is-warning', 'is-error' );

        if ( message ) {
            toolNotice.classList.add( tone === 'success' ? 'is-success' : ( tone === 'warning' ? 'is-warning' : 'is-error' ) );
        }
    }

    function setSettingsStatus( message, tone ) {
        if ( ! settingsStatus ) {
            return;
        }

        settingsStatus.textContent = message || '';
        settingsStatus.classList.remove( 'is-success', 'is-error' );

        if ( message ) {
            settingsStatus.classList.add( tone === 'success' ? 'is-success' : 'is-error' );
        }
    }

    function openHistoryModal() {
        if ( ! historyModal ) {
            return;
        }

        historyModal.hidden = false;
        document.body.classList.add( 'wpfd-pfi-modal-open' );
        void loadHistory( false );
    }

    function closeHistoryModal() {
        if ( ! historyModal ) {
            return;
        }

        historyModal.hidden = true;
        document.body.classList.remove( 'wpfd-pfi-modal-open' );
    }

    function updateLiveSummary() {
        const pendingCount = state.pendingBatch.folders.length;

        if ( batchCount ) {
            batchCount.textContent = String( pendingCount );
            batchCount.classList.toggle( 'is-empty', pendingCount === 0 );
        }

        if ( currentBatch ) {
            currentBatch.textContent = state.currentBatchId || 'Awaiting queue';
        }

        if ( ! resultsMeta ) {
            if ( clearCompleted ) {
                clearCompleted.disabled = true;
            }
            return;
        }

        if ( ! state.items.length ) {
            resultsMeta.textContent = 'Awaiting queue';
            if ( clearCompleted ) {
                clearCompleted.disabled = true;
            }
            return;
        }

        const queuedCount = state.items.filter( ( item ) => item.status === 'queued' ).length;
        const runningCount = state.items.filter( ( item ) => item.status === 'running' ).length;
        const successCount = state.items.filter( ( item ) => item.status === 'success' ).length;
        const failedCount = state.items.filter( ( item ) => item.status === 'failed' ).length;
        const parts = [
            queuedCount ? `${ queuedCount } queued` : '',
            runningCount ? `${ runningCount } processing` : '',
            successCount ? `${ successCount } successful` : '',
            failedCount ? `${ failedCount } failed` : '',
        ].filter( Boolean );

        resultsMeta.textContent = parts.join( ' · ' ) || 'Awaiting queue';
        if ( clearCompleted ) {
            clearCompleted.disabled = successCount === 0;
        }
    }

    function formatStatusLabel( value ) {
        const label = String( value || '' ).trim();
        return label ? `${ label.charAt( 0 ).toUpperCase() }${ label.slice( 1 ) }` : '';
    }

    function getStatusClass( value ) {
        if ( value === 'success' ) {
            return 'is-success';
        }

        if ( value === 'failed' ) {
            return 'is-failed';
        }

        if ( value === 'running' ) {
            return 'is-running';
        }

        return 'is-queued';
    }

    function getStepTone( value ) {
        if ( value === 'success' ) {
            return 'is-success';
        }

        if ( value === 'failed' ) {
            return 'is-failed';
        }

        if ( value === 'running' ) {
            return 'is-running';
        }

        return 'is-neutral';
    }

    function getCompactProgressLabel( item ) {
        const failedLabel = stepLabels.find( ( label ) => item.steps[ label ]?.status === 'failed' );
        if ( failedLabel ) {
            return `${ stepTitles[ failedLabel ] } needs attention`;
        }

        const runningLabel = stepLabels.find( ( label ) => item.steps[ label ]?.status === 'running' );
        if ( runningLabel ) {
            return `${ stepTitles[ runningLabel ] } in progress`;
        }

        const completedCount = stepLabels.filter( ( label ) => item.steps[ label ]?.status === 'success' ).length;
        if ( item.status === 'success' ) {
            return 'All steps completed';
        }

        if ( completedCount > 0 ) {
            return `${ completedCount } of ${ stepLabels.length } steps completed`;
        }

        return item.status === 'queued' ? 'Waiting in queue' : 'Preparing';
    }

    function buildActivityDetailsMarkup( item ) {
        const hasDetails = item.status !== 'queued' || item.errorDetails.length || item.pluginFile || item.logId;
        if ( ! hasDetails ) {
            return '';
        }

        const facts = [
            item.pluginFile ? `<span>Plugin file: ${ escapeHtml( item.pluginFile ) }</span>` : '',
            item.logId ? `<span>Log #${ escapeHtml( item.logId ) }</span>` : '',
        ].filter( Boolean ).join( '' );

        const stepsMarkup = stepLabels.map( ( label ) => {
            const step = item.steps[ label ] || createStep( 'pending', '' );
            const toneClass = getStepTone( step.status );
            return `
                <div class="wpfd-pfi-step-detail">
                    <div class="wpfd-pfi-step-detail-head">
                        <strong>${ escapeHtml( stepTitles[ label ] || formatStatusLabel( label ) ) }</strong>
                        <span class="wpfd-pfi-step-status ${ toneClass }">${ escapeHtml( formatStatusLabel( step.status ) || 'Pending' ) }</span>
                    </div>
                    <p>${ escapeHtml( step.message || 'No detail provided.' ) }</p>
                </div>
            `;
        } ).join( '' );

        const issuesLabel = item.errorDetails.length === 1 ? '1 issue' : `${ item.errorDetails.length } issues`;
        const errorsMarkup = item.errorDetails.length
            ? `
                <div class="wpfd-pfi-detail-card is-error">
                    <div class="wpfd-pfi-detail-card-head">
                        <h3>Issues</h3>
                        <button type="button" class="button button-secondary" data-copy-errors="${ escapeHtml( item.id ) }">Copy errors</button>
                    </div>
                    <ul>
                        ${ item.errorDetails.map( ( error ) => `<li>${ escapeHtml( error ) }</li>` ).join( '' ) }
                    </ul>
                </div>
            `
            : '';

        return `
            <details class="wpfd-pfi-activity-details">
                <summary>${ escapeHtml( item.errorDetails.length ? `Details (${ issuesLabel })` : 'Details' ) }</summary>
                <div class="wpfd-pfi-activity-details-body">
                    ${ facts ? `<div class="wpfd-pfi-detail-facts">${ facts }</div>` : '' }
                    <div class="wpfd-pfi-step-list">${ stepsMarkup }</div>
                    ${ errorsMarkup }
                </div>
            </details>
        `;
    }

    function createStep( status, message ) {
        return {
            status: status,
            message: message,
        };
    }

    async function copyTextToClipboard( text ) {
        const value = String( text || '' );
        if ( ! value ) {
            return false;
        }

        if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
            try {
                await navigator.clipboard.writeText( value );
                return true;
            } catch ( error ) {
                // Fall through to legacy copy.
            }
        }

        const textarea = document.createElement( 'textarea' );
        textarea.value = value;
        textarea.setAttribute( 'readonly', 'readonly' );
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild( textarea );
        textarea.select();

        let copied = false;
        try {
            copied = document.execCommand( 'copy' );
        } catch ( error ) {
            copied = false;
        }

        textarea.remove();
        return copied;
    }

    async function copyItemErrors( itemId, button ) {
        const item = state.items.find( ( entry ) => entry.id === itemId );
        if ( ! item || ! item.errorDetails.length ) {
            setNotice( 'No errors are available to copy for this item.' );
            return;
        }

        const copied = await copyTextToClipboard( item.errorDetails.join( '\n' ) );
        if ( ! copied ) {
            setNotice( 'Could not copy errors to the clipboard.' );
            return;
        }

        if ( button ) {
            const originalLabel = button.textContent;
            button.textContent = 'Copied';
            window.setTimeout( () => {
                button.textContent = originalLabel;
            }, 1600 );
        }
    }

    function createFormData( values ) {
        const formData = new FormData();

        Object.entries( values || {} ).forEach( ( [ key, value ] ) => {
            if ( Array.isArray( value ) ) {
                value.forEach( ( entry ) => {
                    formData.append( `${ key }[]`, String( entry ) );
                } );
                return;
            }

            if ( value !== null && typeof value !== 'undefined' ) {
                formData.append( key, String( value ) );
            }
        } );

        return formData;
    }

    function buildQuery( values ) {
        const params = new URLSearchParams();

        Object.entries( values || {} ).forEach( ( [ key, value ] ) => {
            if ( value === null || typeof value === 'undefined' ) {
                return;
            }

            params.append( key, String( value ) );
        } );

        const queryString = params.toString();
        return queryString ? `?${ queryString }` : '';
    }

    function isInstallAuthFailure( status, payload ) {
        return Number( status ) === 403 && (
            isCookieAuthFailure( status, payload )
            || payload?.code === 'wpfd_invalid_nonce'
        );
    }

    function isCookieAuthFailure( status, payload ) {
        return Number( status ) === 403 && (
            payload?.code === 'rest_cookie_invalid'
            || payload?.code === 'rest_cookie_invalid_nonce'
            || String( payload?.message || '' ).includes( 'Cookie check failed' )
        );
    }

    function normalizeNonceValue( raw ) {
        const cleaned = String( raw || '' ).replace( /[\uFEFF\u200B\u2060]/g, '' ).trim();

        if ( ! /^[a-zA-Z0-9]{5,20}$/.test( cleaned ) ) {
            return '';
        }

        return cleaned;
    }

    function stripResponseText( raw ) {
        return String( raw || '' ).replace( /[\uFEFF\u200B\u2060]/g, '' ).trim();
    }

    async function refreshAuthNonces() {
        if ( ! ajaxUrl ) {
            return false;
        }

        let refreshedWp = false;
        let refreshedWpfd = false;

        try {
            const wpRestResponse = await fetch( `${ ajaxUrl }?action=rest-nonce`, {
                credentials: 'same-origin',
                cache: 'no-store',
            } );
            const wpRestText = normalizeNonceValue( stripResponseText( await wpRestResponse.text() ) );

            if ( wpRestResponse.ok && wpRestText ) {
                wpNonce = wpRestText;
                refreshedWp = true;
            }
        } catch ( error ) {
            // Ignore and fall back to the JSON refresh endpoint.
        }

        try {
            const wpfdResponse = await fetch( `${ ajaxUrl }?action=wpfd_refresh_wpfd_nonce`, {
                credentials: 'same-origin',
                cache: 'no-store',
            } );
            const wpfdText = normalizeNonceValue( stripResponseText( await wpfdResponse.text() ) );

            if ( wpfdResponse.ok && wpfdText ) {
                nonce = wpfdText;
                refreshedWpfd = true;
            }
        } catch ( error ) {
            // Ignore and fall back to the JSON refresh endpoint.
        }

        if ( ! refreshedWp || ! refreshedWpfd ) {
            try {
                const response = await fetch( `${ ajaxUrl }?action=wpfd_refresh_nonces`, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                } );
                const rawText = stripResponseText( await response.text() );
                const payload = rawText ? JSON.parse( rawText ) : null;

                if ( response.ok && payload?.success && payload?.data ) {
                    const nextWpNonce = normalizeNonceValue( payload.data.wp_rest_nonce || '' );
                    const nextWpfdNonce = normalizeNonceValue( payload.data.wpfd_nonce || '' );

                    if ( nextWpNonce ) {
                        wpNonce = nextWpNonce;
                        refreshedWp = true;
                    }

                    if ( nextWpfdNonce ) {
                        nonce = nextWpfdNonce;
                        refreshedWpfd = true;
                    }
                }
            } catch ( error ) {
                // Ignore and use whatever nonces were already refreshed.
            }
        }

        return refreshedWp;
    }

    async function requestRest( method, path, options, allowAuthRetry = true ) {
        const headers = {
            'X-WPFD-Nonce': nonce,
            'X-WP-Nonce': wpNonce,
        };

        const requestOptions = {
            method,
            headers,
            credentials: 'same-origin',
        };

        if ( options?.json ) {
            headers[ 'Content-Type' ] = 'application/json';
            requestOptions.body = JSON.stringify( options.json );
        } else if ( options?.formData ) {
            requestOptions.body = options.formData;
        }

        const response = await fetch( `${ restUrl }${ path }${ buildQuery( {
            ...( options?.query || {} ),
            _wpnonce: wpNonce,
        } ) }`, requestOptions );
        const rawText = String( await response.text() || '' ).replace( /^[\uFEFF\u200B]+/, '' ).trim();
        let payload = null;

        if ( rawText ) {
            try {
                payload = JSON.parse( rawText );
            } catch ( error ) {
                throw new Error( `Invalid JSON response from ${ path }. ${ rawText.replace( /\s+/g, ' ' ).slice( 0, 220 ) }` );
            }
        }

        if ( ! response.ok ) {
            if ( allowAuthRetry && isCookieAuthFailure( response.status, payload ) ) {
                const refreshed = await refreshAuthNonces();
                if ( refreshed ) {
                    return requestRest( method, path, options, false );
                }
            }

            throw new Error( payload?.message || `Request failed with HTTP ${ response.status }.` );
        }

        if (
            payload
            && ! Array.isArray( payload )
            && typeof payload === 'object'
            && Object.prototype.hasOwnProperty.call( payload, 'success' )
            && ! payload.success
        ) {
            throw new Error( payload.message || 'Request failed.' );
        }

        return payload;
    }

    function createItem( folderName, files ) {
        const totalBytes = files.reduce( ( sum, file ) => sum + ( file.size || 0 ), 0 );
        return {
            id: createId(),
            folderName,
            files,
            totalBytes,
            progress: 0,
            status: 'queued',
            message: 'Queued for upload.',
            steps: {
                upload: createStep( 'pending', 'Waiting to upload.' ),
                validation: createStep( 'pending', 'Waiting to validate.' ),
                zip: createStep( 'pending', 'Waiting to create ZIP.' ),
                install: createStep( 'pending', 'Waiting to install plugin.' ),
                activation: createStep( 'pending', 'Waiting to activate plugin.' ),
            },
            errorDetails: [],
            pluginFile: '',
            logId: 0,
            running: false,
            createdAt: Date.now(),
            finishedAt: 0,
        };
    }

    async function iterateDirectoryHandleChildren( handle ) {
        if ( typeof handle.values === 'function' ) {
            try {
                const children = [];

                for await ( const child of handle.values() ) {
                    children.push( child );
                }

                return children;
            } catch ( error ) {
                // Fall through to entries().
            }
        }

        if ( typeof handle.entries === 'function' ) {
            const children = [];

            for await ( const entry of handle.entries() ) {
                children.push( Array.isArray( entry ) ? entry[ 1 ] : entry );
            }

            return children;
        }

        return [];
    }

    async function readDirectoryHandle( handle, prefix, files ) {
        if ( ! handle ) {
            return;
        }

        if ( handle.kind === 'file' ) {
            const file = await handle.getFile();
            file._wpfdRelativePath = `${ prefix }${ handle.name }`;
            files.push( file );
            return;
        }

        const dirPrefix = prefix ? `${ prefix }${ handle.name }/` : `${ handle.name }/`;
        const children = await iterateDirectoryHandleChildren( handle );

        for ( const child of children ) {
            if ( child.kind === 'file' ) {
                const file = await child.getFile();
                file._wpfdRelativePath = `${ dirPrefix }${ child.name }`;
                files.push( file );
                continue;
            }

            await readDirectoryHandle( child, dirPrefix, files );
        }
    }

    async function readFileSystemEntry( entry, prefix, files ) {
        if ( entry.isFile ) {
            await new Promise( ( resolve ) => {
                entry.file( ( file ) => {
                    file._wpfdRelativePath = `${ prefix }${ file.name }`;
                    files.push( file );
                    resolve();
                }, () => resolve() );
            } );
            return;
        }

        const reader = entry.createReader();
        const allEntries = [];
        let batch = [];

        do {
            batch = await new Promise( ( resolve, reject ) => reader.readEntries( resolve, reject ) );
            allEntries.push( ...batch );
        } while ( batch.length );

        for ( const child of allEntries ) {
            await readFileSystemEntry( child, `${ prefix }${ entry.name }/`, files );
        }
    }

    async function readDirectoryHandles( handles ) {
        const files = [];

        for ( const handle of handles ) {
            try {
                await readDirectoryHandle( handle, '', files );
            } catch ( error ) {
                // Skip unreadable handles and keep successful reads.
            }
        }

        return files;
    }

    function isDirectoryHandle( handle ) {
        if ( ! handle ) {
            return false;
        }

        if ( handle.kind === 'file' ) {
            return false;
        }

        if ( handle.kind === 'directory' ) {
            return true;
        }

        return typeof handle.values === 'function' || typeof handle.entries === 'function';
    }

    function filesFromDataTransferList( dataTransfer ) {
        return Array.from( dataTransfer?.files || [] ).filter( ( file ) => {
            const relativePath = normalizeRelativePath( file.webkitRelativePath || file._wpfdRelativePath || '' );
            return relativePath.includes( '/' );
        } ).map( ( file ) => {
            if ( ! file._wpfdRelativePath ) {
                file._wpfdRelativePath = normalizeRelativePath( file.webkitRelativePath || file.name );
            }

            return file;
        } );
    }

    async function normalizeDirectoryPickerResult( result ) {
        if ( ! result ) {
            return [];
        }

        if ( Array.isArray( result ) ) {
            return result;
        }

        if ( typeof result.length === 'number' && typeof result !== 'string' ) {
            try {
                return Array.from( result );
            } catch ( error ) {
                // Fall through to other normalizers.
            }
        }

        if ( typeof result[ Symbol.iterator ] === 'function' ) {
            try {
                return Array.from( result );
            } catch ( error ) {
                // Fall through to async iteration.
            }
        }

        if ( typeof result[ Symbol.asyncIterator ] === 'function' ) {
            const handles = [];

            for await ( const handle of result ) {
                handles.push( handle );
            }

            return handles;
        }

        return [ result ];
    }

    async function collectFilesFromDrop( dataTransfer ) {
        const transferFiles = filesFromDataTransferList( dataTransfer );

        if ( transferFiles.length ) {
            return transferFiles;
        }

        const items = Array.from( dataTransfer?.items || [] );
        const directoryEntries = [];

        items.forEach( ( item ) => {
            const entry = item.webkitGetAsEntry?.();

            if ( entry?.isDirectory ) {
                directoryEntries.push( entry );
            }
        } );

        if ( ! directoryEntries.length ) {
            return [];
        }

        const files = [];

        for ( const entry of directoryEntries ) {
            await readFileSystemEntry( entry, '', files );
        }

        return files;
    }

    function updatePickerUi() {
        const disabled = state.picking;

        [ batchBrowse, batchClear, batchQueue, localBrowserConnect, localBrowserAdd, localBrowserUp ].forEach( ( button ) => {
            if ( button ) {
                button.disabled = disabled;
            }
        } );

        if ( dropzone ) {
            dropzone.classList.toggle( 'is-picking', disabled );
            dropzone.setAttribute( 'aria-busy', disabled ? 'true' : 'false' );
        }
    }

    function renderBatchPanel() {
        if ( ! batchList ) {
            return;
        }

        updateLiveSummary();

        if ( ! state.pendingBatch.folders.length ) {
            batchList.innerHTML = '<p class="wpfd-pfi-batch-empty">No folders added yet. Use step 1 to browse or drop plugin folders.</p>';
            return;
        }

        batchList.innerHTML = state.pendingBatch.folders.map( ( folder ) => `
            <div class="wpfd-pfi-batch-row">
                <span class="wpfd-pfi-batch-icon" aria-hidden="true"></span>
                <span class="wpfd-pfi-batch-name">${ escapeHtml( folder.folderName ) }</span>
                <span class="wpfd-pfi-batch-meta">${ folder.files.length } files · ${ escapeHtml( bytesToLabel( folder.totalBytes ) ) }</span>
                <button type="button" class="button-link-delete" data-batch-remove="${ escapeHtml( folder.id ) }">Remove</button>
            </div>
        ` ).join( '' );
    }

    function appendGroupsToPendingBatch( groups, source ) {
        let addedCount = 0;
        const duplicates = [];

        groups.forEach( ( folderFiles, folderName ) => {
            const existing = state.pendingBatch.folders.find( ( folder ) => folder.folderName === folderName );

            if ( existing ) {
                duplicates.push( folderName );
                return;
            }

            state.pendingBatch.folders.push( {
                id: createId(),
                folderName,
                files: folderFiles,
                totalBytes: folderFiles.reduce( ( sum, file ) => sum + ( file.size || 0 ), 0 ),
                source,
            } );
            addedCount += 1;
        } );

        if ( duplicates.length ) {
            setNotice( `Already in batch: ${ duplicates.join( ', ' ) }. Remove the existing entry first to replace it.` );
        } else if ( addedCount ) {
            setNotice( '' );
        }

        renderBatchPanel();
        return addedCount;
    }

    function addFilesToPendingBatch( files, source ) {
        const normalized = Array.from( files || [] );

        if ( ! normalized.length ) {
            setNotice( 'Drop one or more complete plugin folders (not individual files).' );
            return false;
        }

        const { groups, rejected } = groupFilesByRoot( normalized );

        if ( rejected.length && ! groups.size ) {
            setNotice( 'Drop one or more complete plugin folders (not individual files).' );
            return false;
        }

        if ( rejected.length ) {
            setNotice( 'Some items were ignored because they were not inside a top-level plugin folder.' );
        }

        if ( ! groups.size ) {
            renderBatchPanel();
            return false;
        }

        return appendGroupsToPendingBatch( groups, source ) > 0;
    }

    function removePendingFolder( folderId ) {
        state.pendingBatch.folders = state.pendingBatch.folders.filter( ( folder ) => folder.id !== folderId );
        renderBatchPanel();
    }

    function clearPendingBatch() {
        state.pendingBatch.folders = [];
        renderBatchPanel();
        setNotice( '' );
    }

    function queuePendingBatch() {
        if ( ! state.pendingBatch.folders.length ) {
            setNotice( 'Add at least one plugin folder to the batch first.' );
            return;
        }

        const groups = new Map();

        state.pendingBatch.folders.forEach( ( folder ) => {
            groups.set( folder.folderName, folder.files );
        } );

        state.currentBatchId = createBatchId();
        state.pendingBatch.folders = [];
        renderBatchPanel();
        setNotice( '' );
        queueGroups( groups );
    }

    function pickFolderViaWebkitInput() {
        return new Promise( ( resolve ) => {
            if ( ! input ) {
                resolve( [] );
                return;
            }

            const onChange = ( event ) => {
                input.removeEventListener( 'change', onChange );
                resolve( Array.from( event.target.files || [] ) );
                input.value = '';
            };

            input.addEventListener( 'change', onChange );
            input.click();
        } );
    }

    async function addFoldersViaBrowseFallback() {
        if ( state.picking || state.processing ) {
            return;
        }

        state.picking = true;
        updatePickerUi();

        try {
            const files = await pickFolderViaWebkitInput();

            if ( files.length ) {
                addFilesToPendingBatch( files, 'browse' );
            }
        } finally {
            state.picking = false;
            updatePickerUi();
        }
    }

    async function queryLocalPermission( handle ) {
        if ( ! handle || typeof handle.queryPermission !== 'function' ) {
            return 'granted';
        }

        return handle.queryPermission( { mode: 'read' } );
    }

    async function requestLocalPermission( handle ) {
        if ( ! handle || typeof handle.requestPermission !== 'function' ) {
            return 'granted';
        }

        return handle.requestPermission( { mode: 'read' } );
    }

    async function listLocalDirectory( handle ) {
        const children = await iterateDirectoryHandleChildren( handle );
        const directories = [];

        children.forEach( ( child ) => {
            if ( child.kind === 'file' ) {
                return;
            }

            if ( child.kind === 'directory' || isDirectoryHandle( child ) ) {
                directories.push( child );
            }
        } );

        directories.sort( ( left, right ) => left.name.localeCompare( right.name, undefined, { sensitivity: 'base' } ) );
        return directories;
    }

    function getActiveLocalRoot() {
        return state.localBrowser.roots.find( ( root ) => root.id === state.localBrowser.activeRootId ) || null;
    }

    function getCurrentLocalDirectoryHandle() {
        const stack = state.localBrowser.pathStack;

        if ( stack.length ) {
            return stack[ stack.length - 1 ].handle;
        }

        return getActiveLocalRoot()?.handle || null;
    }

    function getLocalBrowserPathKey() {
        const segments = state.localBrowser.pathStack.map( ( entry ) => entry.name );
        return segments.length ? segments.join( '/' ) : '.';
    }

    function openLocalRootsDb() {
        return new Promise( ( resolve, reject ) => {
            if ( ! window.indexedDB ) {
                resolve( null );
                return;
            }

            const request = indexedDB.open( LOCAL_ROOTS_DB, 1 );

            request.onupgradeneeded = () => {
                if ( ! request.result.objectStoreNames.contains( LOCAL_ROOTS_STORE ) ) {
                    request.result.createObjectStore( LOCAL_ROOTS_STORE, { keyPath: 'id' } );
                }
            };

            request.onsuccess = () => resolve( request.result );
            request.onerror = () => reject( request.error );
        } );
    }

    async function persistLocalRoot( root ) {
        const db = await openLocalRootsDb();

        if ( ! db ) {
            return;
        }

        await new Promise( ( resolve, reject ) => {
            const tx = db.transaction( LOCAL_ROOTS_STORE, 'readwrite' );
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject( tx.error );
            tx.objectStore( LOCAL_ROOTS_STORE ).put( {
                id: root.id,
                label: root.label,
                handle: root.handle,
            } );
        } );
    }

    async function restoreLocalRoots() {
        const db = await openLocalRootsDb();

        if ( ! db ) {
            return;
        }

        const stored = await new Promise( ( resolve, reject ) => {
            const tx = db.transaction( LOCAL_ROOTS_STORE, 'readonly' );
            const request = tx.objectStore( LOCAL_ROOTS_STORE ).getAll();
            request.onsuccess = () => resolve( request.result || [] );
            request.onerror = () => reject( request.error );
        } );

        const restored = [];

        for ( const row of stored ) {
            if ( ! row?.handle ) {
                continue;
            }

            let permission = await queryLocalPermission( row.handle );

            if ( permission === 'prompt' ) {
                permission = await requestLocalPermission( row.handle );
            }

            if ( permission !== 'granted' ) {
                continue;
            }

            restored.push( {
                id: row.id || createId(),
                label: row.label || row.handle.name || 'Connected folder',
                handle: row.handle,
            } );
        }

        if ( ! restored.length ) {
            return;
        }

        state.localBrowser.roots = restored;

        if ( ! state.localBrowser.activeRootId || ! restored.some( ( root ) => root.id === state.localBrowser.activeRootId ) ) {
            state.localBrowser.activeRootId = restored[ 0 ].id;
        }
    }

    async function registerLocalRoot( handle ) {
        const permission = await requestLocalPermission( handle );

        if ( permission !== 'granted' ) {
            state.localBrowser.error = 'Folder access was not granted.';
            renderLocalBrowser();
            return false;
        }

        const existing = state.localBrowser.roots.find( ( root ) => root.label === handle.name );

        if ( existing ) {
            state.localBrowser.activeRootId = existing.id;
            state.localBrowser.pathStack = [];
            state.localBrowser.error = '';
            await refreshLocalBrowserEntries();
            return true;
        }

        const root = {
            id: createId(),
            label: handle.name,
            handle,
        };

        state.localBrowser.roots.push( root );
        state.localBrowser.activeRootId = root.id;
        state.localBrowser.pathStack = [];
        state.localBrowser.error = '';
        await persistLocalRoot( root );
        await refreshLocalBrowserEntries();
        return true;
    }

    async function connectLocalRoot() {
        if ( ! supportsLocalFolderBrowser() ) {
            state.localBrowser.error = 'Your browser does not support the in-app PC folder browser. Use drag-and-drop instead.';
            renderLocalBrowser();
            return;
        }

        try {
            const handle = await window.showDirectoryPicker();
            await registerLocalRoot( handle );
        } catch ( error ) {
            if ( error?.name !== 'AbortError' ) {
                state.localBrowser.error = error instanceof Error ? error.message : 'Could not connect folder.';
                renderLocalBrowser();
            }
        }
    }

    async function refreshLocalBrowserEntries() {
        const handle = getCurrentLocalDirectoryHandle();

        state.localBrowser.loading = true;
        renderLocalBrowser();

        if ( ! handle ) {
            state.localBrowser.entries = [];
            state.localBrowser.loading = false;
            renderLocalBrowser();
            return;
        }

        try {
            let permission = await queryLocalPermission( handle );

            if ( permission !== 'granted' ) {
                permission = await requestLocalPermission( handle );
            }

            if ( permission !== 'granted' ) {
                state.localBrowser.error = 'Folder access was revoked. Connect the folder again.';
                state.localBrowser.entries = [];
                state.localBrowser.loading = false;
                renderLocalBrowser();
                return;
            }

            const directories = await listLocalDirectory( handle );
            const pathKey = getLocalBrowserPathKey();

            state.localBrowser.entries = directories.map( ( directory ) => ( {
                id: `${ state.localBrowser.activeRootId }:${ pathKey }/${ directory.name }`,
                name: directory.name,
                handle: directory,
            } ) );
            state.localBrowser.error = '';
        } catch ( error ) {
            state.localBrowser.error = error instanceof Error ? error.message : 'Could not list this folder.';
            state.localBrowser.entries = [];
        }

        state.localBrowser.loading = false;
        renderLocalBrowser();
    }

    function setLocalBrowserRoot( rootId ) {
        if ( ! state.localBrowser.roots.some( ( root ) => root.id === rootId ) ) {
            return;
        }

        state.localBrowser.activeRootId = rootId;
        state.localBrowser.pathStack = [];
        state.localBrowser.selectedHandles = {};
        void refreshLocalBrowserEntries();
    }

    async function openLocalBrowserEntry( entry ) {
        state.localBrowser.pathStack.push( {
            name: entry.name,
            handle: entry.handle,
        } );
        await refreshLocalBrowserEntries();
    }

    async function navigateLocalBrowserUp() {
        if ( ! state.localBrowser.pathStack.length ) {
            return;
        }

        state.localBrowser.pathStack.pop();
        await refreshLocalBrowserEntries();
    }

    async function navigateLocalBrowserToCrumb( index ) {
        if ( index < 0 ) {
            state.localBrowser.pathStack = [];
        } else {
            state.localBrowser.pathStack = state.localBrowser.pathStack.slice( 0, index + 1 );
        }

        await refreshLocalBrowserEntries();
    }

    function toggleLocalBrowserSelection( entryId, handle, checked ) {
        if ( checked ) {
            state.localBrowser.selectedHandles[ entryId ] = handle;
        } else {
            delete state.localBrowser.selectedHandles[ entryId ];
        }

        renderLocalBrowser();
    }

    function buildLocalBrowserRow( options ) {
        const {
            entryId,
            name,
            checked = false,
            isCurrent = false,
            canOpen = false,
        } = options;
        const rowClass = checked ? ' is-selected' : '';
        const nameMarkup = canOpen
            ? `<button type="button" class="wpfd-pfi-local-browser-name" data-local-open="${ escapeHtml( entryId ) }">
                    <span class="wpfd-pfi-local-browser-folder" aria-hidden="true"></span>
                    <span class="wpfd-pfi-local-browser-name-text">${ escapeHtml( name ) }</span>
               </button>`
            : `<span class="wpfd-pfi-local-browser-name is-static">
                    <span class="wpfd-pfi-local-browser-folder" aria-hidden="true"></span>
                    <span class="wpfd-pfi-local-browser-name-text">${ escapeHtml( name ) }</span>
               </span>`;
        const badgeMarkup = isCurrent
            ? '<span class="wpfd-pfi-local-browser-badge">Current</span>'
            : '<span class="wpfd-pfi-local-browser-badge-spacer" aria-hidden="true"></span>';
        const openMarkup = canOpen
            ? `<button type="button" class="button button-small wpfd-pfi-local-browser-open-btn" data-local-open="${ escapeHtml( entryId ) }">Open</button>`
            : '';

        return `
            <div class="wpfd-pfi-local-browser-row${ rowClass }">
                <label class="wpfd-pfi-local-browser-check">
                    <span class="screen-reader-text">Select ${ escapeHtml( name ) }</span>
                    <input type="checkbox" data-local-select="${ escapeHtml( entryId ) }"${ checked ? ' checked' : '' }>
                </label>
                ${ nameMarkup }
                ${ badgeMarkup }
                ${ openMarkup }
            </div>
        `;
    }

    function renderLocalBrowser() {
        if ( ! localBrowserModal ) {
            return;
        }

        const activeRoot = getActiveLocalRoot();
        const selectedCount = Object.keys( state.localBrowser.selectedHandles ).length;

        if ( localBrowserRoot ) {
            if ( ! state.localBrowser.roots.length ) {
                localBrowserRoot.innerHTML = '<option value="">No connected folders</option>';
                localBrowserRoot.disabled = true;
            } else {
                localBrowserRoot.disabled = false;
                localBrowserRoot.innerHTML = state.localBrowser.roots.map( ( root ) => `
                    <option value="${ escapeHtml( root.id ) }"${ root.id === state.localBrowser.activeRootId ? ' selected' : '' }>${ escapeHtml( root.label ) }</option>
                ` ).join( '' );
            }
        }

        if ( localBrowserUp ) {
            localBrowserUp.disabled = ! state.localBrowser.pathStack.length || state.localBrowser.loading;
        }

        if ( localBrowserAdd ) {
            localBrowserAdd.disabled = ! selectedCount || state.localBrowser.loading || state.picking;
            localBrowserAdd.textContent = selectedCount
                ? `Add ${ selectedCount } to batch`
                : 'Add selected to batch';
        }

        if ( localBrowserSelected ) {
            localBrowserSelected.textContent = `${ selectedCount } selected`;
        }

        if ( localBrowserConnect ) {
            localBrowserConnect.disabled = state.localBrowser.loading || state.picking;
        }

        if ( localBrowserError ) {
            if ( state.localBrowser.error ) {
                localBrowserError.hidden = false;
                localBrowserError.textContent = state.localBrowser.error;
            } else {
                localBrowserError.hidden = true;
                localBrowserError.textContent = '';
            }
        }

        if ( localBrowserBreadcrumb ) {
            if ( ! activeRoot ) {
                localBrowserBreadcrumb.innerHTML = '<span class="wpfd-pfi-local-browser-crumb is-current">Not connected</span>';
            } else {
                const crumbs = [
                    `<button type="button" class="wpfd-pfi-local-browser-crumb" data-local-crumb="-1">${ escapeHtml( activeRoot.label ) }</button>`,
                ];

                state.localBrowser.pathStack.forEach( ( entry, index ) => {
                    const isCurrent = index === state.localBrowser.pathStack.length - 1;
                    crumbs.push( '<span class="wpfd-pfi-local-browser-sep">/</span>' );

                    if ( isCurrent ) {
                        crumbs.push( `<span class="wpfd-pfi-local-browser-crumb is-current">${ escapeHtml( entry.name ) }</span>` );
                    } else {
                        crumbs.push( `<button type="button" class="wpfd-pfi-local-browser-crumb" data-local-crumb="${ index }">${ escapeHtml( entry.name ) }</button>` );
                    }
                } );

                localBrowserBreadcrumb.innerHTML = crumbs.join( '' );
            }
        }

        if ( ! localBrowserList ) {
            return;
        }

        if ( state.localBrowser.loading ) {
            localBrowserList.innerHTML = '<p class="wpfd-pfi-local-browser-loading">Loading folders…</p>';
            return;
        }

        if ( ! activeRoot ) {
            localBrowserList.innerHTML = '<p class="wpfd-pfi-local-browser-empty">Connect a folder on your PC to start browsing.</p>';
            return;
        }

        if ( ! state.localBrowser.entries.length ) {
            const currentId = `${ state.localBrowser.activeRootId }:${ getLocalBrowserPathKey() }:__current__`;
            const currentChecked = Object.prototype.hasOwnProperty.call( state.localBrowser.selectedHandles, currentId );
            const currentName = state.localBrowser.pathStack.length
                ? state.localBrowser.pathStack[ state.localBrowser.pathStack.length - 1 ].name
                : activeRoot.label;

            localBrowserList.innerHTML = `
                ${ buildLocalBrowserRow( {
                    entryId: currentId,
                    name: `${ currentName }/`,
                    checked: currentChecked,
                    isCurrent: true,
                    canOpen: false,
                } ) }
                <p class="wpfd-pfi-local-browser-empty">This folder has no subfolders.</p>
            `;
            return;
        }

        const currentId = `${ state.localBrowser.activeRootId }:${ getLocalBrowserPathKey() }:__current__`;
        const currentChecked = Object.prototype.hasOwnProperty.call( state.localBrowser.selectedHandles, currentId );
        const currentName = state.localBrowser.pathStack.length
            ? state.localBrowser.pathStack[ state.localBrowser.pathStack.length - 1 ].name
            : activeRoot.label;
        const currentRow = buildLocalBrowserRow( {
            entryId: currentId,
            name: `${ currentName }/`,
            checked: currentChecked,
            isCurrent: true,
            canOpen: false,
        } );

        localBrowserList.innerHTML = currentRow + state.localBrowser.entries.map( ( entry ) => {
            const checked = Object.prototype.hasOwnProperty.call( state.localBrowser.selectedHandles, entry.id );
            return buildLocalBrowserRow( {
                entryId: entry.id,
                name: `${ entry.name }/`,
                checked,
                isCurrent: false,
                canOpen: true,
            } );
        } ).join( '' );
    }

    function resolveLocalBrowserEntryHandle( entryId ) {
        if ( String( entryId ).endsWith( ':__current__' ) ) {
            return getCurrentLocalDirectoryHandle();
        }

        return state.localBrowser.entries.find( ( entry ) => entry.id === entryId )?.handle || null;
    }

    function closeLocalFolderBrowser() {
        state.localBrowser.open = false;

        if ( localBrowserModal ) {
            localBrowserModal.hidden = true;
        }

        document.body.classList.remove( 'wpfd-pfi-modal-open' );
    }

    async function openLocalFolderBrowser() {
        if ( state.picking || state.processing ) {
            return;
        }

        if ( ! supportsLocalFolderBrowser() ) {
            setNotice( 'In-app PC folder browser requires Chrome or Edge. Use drag-and-drop, or pick one folder at a time.' );
            void addFoldersViaBrowseFallback();
            return;
        }

        state.localBrowser.open = true;

        if ( localBrowserModal ) {
            localBrowserModal.hidden = false;
        }

        document.body.classList.add( 'wpfd-pfi-modal-open' );

        await restoreLocalRoots();

        if ( ! state.localBrowser.activeRootId && state.localBrowser.roots.length ) {
            state.localBrowser.activeRootId = state.localBrowser.roots[ 0 ].id;
        }

        await refreshLocalBrowserEntries();
        renderLocalBrowser();
    }

    async function addSelectedLocalFoldersToBatch() {
        const handles = Object.values( state.localBrowser.selectedHandles );

        if ( ! handles.length || state.picking || state.processing ) {
            return;
        }

        state.localBrowser.loading = true;
        state.picking = true;
        updatePickerUi();
        renderLocalBrowser();

        try {
            let addedAny = false;

            for ( const handle of handles ) {
                const files = [];

                try {
                    await readDirectoryHandle( handle, '', files );
                } catch ( error ) {
                    state.localBrowser.error = error instanceof Error ? error.message : 'Could not read one of the selected folders.';
                    renderLocalBrowser();
                    continue;
                }

                if ( files.length && addFilesToPendingBatch( files, 'browse' ) ) {
                    addedAny = true;
                }
            }

            if ( addedAny ) {
                state.localBrowser.selectedHandles = {};
                closeLocalFolderBrowser();
                setNotice( '' );
            } else if ( ! state.localBrowser.error ) {
                state.localBrowser.error = 'Selected folders did not contain valid plugin folder structures.';
                renderLocalBrowser();
            }
        } finally {
            state.localBrowser.loading = false;
            state.picking = false;
            updatePickerUi();
            renderLocalBrowser();
        }
    }

    async function handleDroppedFolders( dataTransfer ) {
        const files = await collectFilesFromDrop( dataTransfer );

        if ( ! files.length ) {
            setNotice( 'Drop one or more complete plugin folders (not individual files).' );
            return;
        }

        addFilesToPendingBatch( files, 'drop' );
    }

    function groupFilesByRoot( files ) {
        const groups = new Map();
        const rejected = [];

        files.forEach( ( file ) => {
            const relativePath = normalizeRelativePath( file._wpfdRelativePath || file.webkitRelativePath || file.name );
            const segments = relativePath.split( '/' ).filter( Boolean );
            if ( segments.length < 2 ) {
                rejected.push( file.name );
                return;
            }

            const [ root ] = segments;
            file._wpfdRelativePath = relativePath;

            if ( ! groups.has( root ) ) {
                groups.set( root, [] );
            }

            groups.get( root ).push( file );
        } );

        return { groups, rejected };
    }

    function queueGroups( groups ) {
        const newItems = [];

        groups.forEach( ( files, folderName ) => {
            newItems.push( createItem( folderName, files ) );
        } );

        state.items = newItems.concat( state.items );

        render();
        processQueue();
    }

    async function processQueue() {
        if ( state.processing ) {
            return;
        }

        state.processing = true;

        while ( true ) {
            const nextItem = state.items.find( ( item ) => item.status === 'queued' && ! item.running );
            if ( ! nextItem ) {
                break;
            }

            nextItem.running = true;
            await processItem( nextItem );
            nextItem.running = false;
            render();
        }

        state.processing = false;
    }

    function processItem( item ) {
        return processItemUpload( item, false );
    }

    function processItemUpload( item, isAuthRetry ) {
        return new Promise( ( resolve ) => {
            const requiresPackagedUpload = item.files.length > maxFileUploads;

            if ( requiresPackagedUpload && typeof JSZip === 'undefined' ) {
                item.status = 'failed';
                markItemFinished( item );
                item.message = 'The selected folder exceeds the server file-count limit, and ZIP packaging is unavailable.';
                item.errorDetails = [
                    `Folder file count: ${ item.files.length }`,
                    `Server file-upload limit: ${ maxFileUploads }`,
                    'The installer prevented a request that would likely be rejected by PHP before WordPress could return JSON.',
                ];
                item.steps.upload = createStep( 'failed', item.message );
                item.steps.validation = createStep( 'failed', 'Validation was skipped.' );
                item.steps.zip = createStep( 'failed', 'ZIP packaging could not start because JSZip was unavailable.' );
                item.steps.install = createStep( 'failed', 'Installation was skipped.' );
                item.steps.activation = createStep( 'failed', 'Activation was skipped.' );
                render();
                resolve();
                return;
            }

            if ( ! requiresPackagedUpload && maxUploadBytes > 0 && item.totalBytes > maxUploadBytes ) {
                item.status = 'failed';
                markItemFinished( item );
                item.message = 'The selected folder exceeds the current server upload limit.';
                item.errorDetails = [
                    `Folder size: ${ bytesToLabel( item.totalBytes ) }`,
                    `Server limit: ${ bytesToLabel( maxUploadBytes ) }`,
                ];
                item.steps.upload = createStep( 'failed', item.message );
                item.steps.validation = createStep( 'failed', 'Validation was skipped.' );
                item.steps.zip = createStep( 'failed', 'ZIP creation was skipped.' );
                item.steps.install = createStep( 'failed', 'Installation was skipped.' );
                item.steps.activation = createStep( 'failed', 'Activation was skipped.' );
                render();
                resolve();
                return;
            }

            const xhr = new XMLHttpRequest();

            item.status = 'running';
            item.finishedAt = 0;
            item.progress = 0;
            item.message = requiresPackagedUpload
                ? 'Packaging the selected folder into a ZIP for safe upload.'
                : ( config.labels?.uploading || 'Uploading files' );
            item.steps.upload = createStep(
                'running',
                requiresPackagedUpload
                    ? 'Packaging the selected folder and uploading a single ZIP package.'
                    : 'Uploading plugin folder files.'
            );
            render();

            xhr.upload.onprogress = ( event ) => {
                if ( ! event.lengthComputable ) {
                    return;
                }

                item.progress = Math.max( 1, Math.round( ( event.loaded / event.total ) * 100 ) );
                item.message = requiresPackagedUpload
                    ? `Uploading packaged plugin ZIP (${ item.progress }%)`
                    : `${ config.labels?.uploading || 'Uploading files' } (${ item.progress }%)`;
                render();
            };

            xhr.upload.onload = () => {
                item.steps.upload = createStep(
                    'success',
                    requiresPackagedUpload
                        ? 'Uploaded the packaged plugin ZIP.'
                        : `Uploaded ${ item.files.length } files.`
                );
                item.steps.validation = createStep( 'running', 'Validating plugin folder structure.' );
                item.steps.zip = createStep(
                    'running',
                    requiresPackagedUpload
                        ? 'Verifying the uploaded ZIP package and preparing it for installation.'
                        : 'Creating temporary ZIP package.'
                );
                item.steps.install = createStep( 'running', 'Installing plugin with WordPress.' );
                item.steps.activation = createStep( 'running', 'Activating installed plugin.' );
                item.message = config.labels?.processing || 'Processing uploaded plugin folder';
                render();
            };

            xhr.onerror = () => {
                item.status = 'failed';
                markItemFinished( item );
                item.message = 'The upload failed before the server could process the plugin folder.';
                item.steps.upload = createStep( 'failed', item.message );
                item.steps.validation = createStep( 'failed', 'Validation did not start.' );
                item.steps.zip = createStep( 'failed', 'ZIP creation did not start.' );
                item.steps.install = createStep( 'failed', 'Installation did not start.' );
                item.steps.activation = createStep( 'failed', 'Activation did not start.' );
                item.errorDetails = [ 'Network error while uploading the folder.' ];
                render();
                resolve();
            };

            xhr.onload = () => {
                let payload = null;

                try {
                    const text = String( xhr.responseText || '' ).replace( /^[\uFEFF\u200B]+/, '' );
                    payload = JSON.parse( text );
                } catch ( error ) {
                    item.status = 'failed';
                    markItemFinished( item );
                    item.message = 'The server returned an invalid JSON response.';
                    item.steps.validation = createStep( 'failed', item.message );
                    item.steps.zip = createStep( 'failed', 'ZIP creation state is unknown.' );
                    item.steps.install = createStep( 'failed', 'Installation state is unknown.' );
                    item.steps.activation = createStep( 'failed', 'Activation state is unknown.' );
                    item.errorDetails = buildInvalidJsonDetails( xhr, error );
                    render();
                    resolve();
                    return;
                }

                if ( isInstallAuthFailure( xhr.status, payload ) && ! isAuthRetry ) {
                    void refreshAuthNonces().then( ( refreshed ) => {
                        if ( ! refreshed ) {
                            item.status = 'failed';
                            markItemFinished( item );
                            item.message = payload?.message || 'Security check failed.';
                            item.steps.upload = createStep( 'failed', item.message );
                            item.steps.validation = createStep( 'failed', 'Validation did not start.' );
                            item.steps.zip = createStep( 'failed', 'ZIP creation did not start.' );
                            item.steps.install = createStep( 'failed', 'Installation did not start.' );
                            item.steps.activation = createStep( 'failed', 'Activation did not start.' );
                            item.errorDetails = [ item.message ];
                            render();
                            resolve();
                            return;
                        }

                        void processItemUpload( item, true ).then( resolve );
                    } );
                    return;
                }

                const folderResult = Array.isArray( payload?.results )
                    ? payload.results.find( ( entry ) => entry.original_folder_name === item.folderName )
                    : null;

                if ( ! folderResult ) {
                    item.status = 'failed';
                    markItemFinished( item );
                    item.message = payload?.message || 'The server did not return a result for this folder.';
                    item.steps.validation = createStep( 'failed', item.message );
                    item.steps.zip = createStep( 'failed', 'ZIP creation state is unknown.' );
                    item.steps.install = createStep( 'failed', 'Installation state is unknown.' );
                    item.steps.activation = createStep( 'failed', 'Activation state is unknown.' );
                    item.errorDetails = Array.isArray( payload?.error_details ) && payload.error_details.length
                        ? payload.error_details
                        : ( payload?.message ? [ payload.message ] : [ 'No folder result payload was returned.' ] );
                    render();
                    resolve();
                    return;
                }

                item.progress = 100;
                item.status = folderResult.success ? 'success' : 'failed';
                markItemFinished( item );
                item.message = folderResult.message || ( folderResult.success ? 'Installed and activated successfully.' : 'Plugin folder installation failed.' );
                item.steps.validation = folderResult.steps?.validation || createStep( folderResult.success ? 'success' : 'failed', '' );
                item.steps.zip = folderResult.steps?.zip || createStep( folderResult.success ? 'success' : 'failed', '' );
                item.steps.install = folderResult.steps?.install || createStep( folderResult.success ? 'success' : 'failed', '' );
                item.steps.activation = folderResult.steps?.activation || createStep( folderResult.success ? 'success' : 'failed', '' );

                const traceDetails = Array.isArray( folderResult.debug_trace )
                    ? folderResult.debug_trace.map( formatTraceEntry ).filter( Boolean )
                    : [];

                item.errorDetails = Array.from( new Set( [
                    ...( Array.isArray( folderResult.error_details ) ? folderResult.error_details : [] ),
                    ...( folderResult.success ? [] : traceDetails ),
                ] ) );
                item.pluginFile = folderResult.plugin_file || '';
                item.logId = Number( folderResult.log_id || 0 );
                state.tools.loaded.plugins = false;
                state.tools.loaded.history = false;
                state.tools.backupsBySlug = {};
                render();

                if ( folderResult.success && canManageOptions ) {
                    if ( historyPanel && historyModal && ! historyModal.hidden ) {
                        void loadHistory( true );
                    }

                    if ( state.tools.activePanel ) {
                        void loadPanelData( state.tools.activePanel, true );
                    }
                }

                resolve();
            };

            const prepareAndSend = async () => {
                const formData = new FormData();
                formData.append( 'action', installAction );
                formData.append( 'wpfd_nonce', nonce );

                if ( requiresPackagedUpload ) {
                    const packageBlob = await buildPackageBlob( item );

                    if ( maxUploadBytes > 0 && packageBlob.size > maxUploadBytes ) {
                        item.status = 'failed';
                        markItemFinished( item );
                        item.message = 'The packaged ZIP exceeds the current server upload limit.';
                        item.errorDetails = [
                            `ZIP size: ${ bytesToLabel( packageBlob.size ) }`,
                            `Server limit: ${ bytesToLabel( maxUploadBytes ) }`,
                        ];
                        item.steps.upload = createStep( 'failed', item.message );
                        item.steps.validation = createStep( 'failed', 'Validation was skipped.' );
                        item.steps.zip = createStep( 'failed', 'ZIP packaging completed, but upload was blocked by the server size limit.' );
                        item.steps.install = createStep( 'failed', 'Installation was skipped.' );
                        item.steps.activation = createStep( 'failed', 'Activation was skipped.' );
                        render();
                        resolve();
                        return;
                    }

                    formData.append( 'package', packageBlob, `${ item.folderName }.zip` );
                    formData.append( 'folder_name', item.folderName );
                    formData.append( 'payload_mode', 'package' );
                } else {
                    const relativePaths = item.files.map( ( file ) => normalizeRelativePath( file._wpfdRelativePath || file.webkitRelativePath || file.name ) );

                    item.files.forEach( ( file, index ) => {
                        formData.append( `file_${ index }`, file, file.name );
                    } );

                    formData.append( 'relative_paths', JSON.stringify( relativePaths ) );
                }

                xhr.send( formData );
            };

            const beginUpload = async () => {
                await refreshAuthNonces();

                if ( ! nonce ) {
                    throw new Error( 'Could not refresh the installer security nonce.' );
                }

                if ( ! ajaxUrl ) {
                    throw new Error( 'The WordPress admin AJAX endpoint is unavailable.' );
                }

                xhr.open( 'POST', ajaxUrl );

                await prepareAndSend();
            };

            beginUpload().catch( ( error ) => {
                item.status = 'failed';
                markItemFinished( item );
                item.message = 'The folder could not be prepared for upload.';
                item.steps.upload = createStep( 'failed', item.message );
                item.steps.validation = createStep( 'failed', 'Validation did not start.' );
                item.steps.zip = createStep( 'failed', error instanceof Error ? error.message : 'ZIP packaging failed.' );
                item.steps.install = createStep( 'failed', 'Installation did not start.' );
                item.steps.activation = createStep( 'failed', 'Activation did not start.' );
                item.errorDetails = [ error instanceof Error ? error.message : 'Unexpected packaging error.' ];
                render();
                resolve();
            } );
        } );
    }

    function retryItem( itemId ) {
        const item = state.items.find( ( entry ) => entry.id === itemId );
        if ( ! item ) {
            return;
        }

        item.progress = 0;
        item.status = 'queued';
        item.finishedAt = 0;
        item.message = 'Queued for retry.';
        item.errorDetails = [];
        item.pluginFile = '';
        item.logId = 0;
        item.steps = {
            upload: createStep( 'pending', 'Waiting to upload.' ),
            validation: createStep( 'pending', 'Waiting to validate.' ),
            zip: createStep( 'pending', 'Waiting to create ZIP.' ),
            install: createStep( 'pending', 'Waiting to install plugin.' ),
            activation: createStep( 'pending', 'Waiting to activate plugin.' ),
        };
        render();
        processQueue();
    }

    function clearCompletedItems() {
        state.items = state.items.filter( ( item ) => item.status !== 'success' );
        if ( ! state.items.length ) {
            state.currentBatchId = '';
        }
        render();
    }

    function getToolLoadingMarkup( message, type ) {
        const className = type === 'empty' ? 'wpfd-pfi-empty' : 'wpfd-pfi-loading';
        return `<div class="${ className }">${ escapeHtml( message ) }</div>`;
    }

    function renderToolListCard( options ) {
        const title = options.title || '';
        const meta = options.meta || '';
        const badgeClass = options.badgeClass || 'is-neutral';
        const badgeLabel = options.badgeLabel || '';
        const body = options.body || '';
        const actions = options.actions || '';

        return `
            <article class="wpfd-pfi-list-card">
                <div class="wpfd-pfi-list-card-head">
                    <span class="wpfd-pfi-list-card-icon" aria-hidden="true"></span>
                    <div class="wpfd-pfi-list-card-copy">
                        <h3>${ escapeHtml( title ) }</h3>
                        ${ meta ? `<p class="wpfd-pfi-list-card-meta">${ escapeHtml( meta ) }</p>` : '' }
                    </div>
                    ${ badgeLabel ? `<span class="wpfd-pfi-badge ${ badgeClass }">${ escapeHtml( badgeLabel ) }</span>` : '' }
                </div>
                ${ body ? `<div class="wpfd-pfi-list-card-body">${ body }</div>` : '' }
                ${ actions ? `<div class="wpfd-pfi-list-card-actions">${ actions }</div>` : '' }
            </article>
        `;
    }

    function renderBrowserPreviewMarkup() {
        const preview = state.tools.browser.preview;
        if ( ! preview ) {
            return '';
        }

        if ( preview.type === 'file' ) {
            return `
                <div class="wpfd-pfi-preview-head">
                    <strong>${ escapeHtml( preview.filename ) }</strong>
                    <span>${ escapeHtml( preview.path ) }</span>
                </div>
                <pre>${ escapeHtml( preview.content ) }</pre>
            `;
        }

        if ( preview.type === 'dir' ) {
            return `
                <div class="wpfd-pfi-preview-head">
                    <strong>${ escapeHtml( preview.dirname ) }</strong>
                    <span>${ escapeHtml( `${ preview.count } text files${ preview.truncated ? ' (truncated)' : '' }` ) }</span>
                </div>
                <div class="wpfd-pfi-preview-files">
                    ${ preview.files.map( ( file ) => `
                        <details>
                            <summary>${ escapeHtml( file.path ) }</summary>
                            <pre>${ escapeHtml( file.content ) }</pre>
                        </details>
                    ` ).join( '' ) }
                </div>
            `;
        }

        return '';
    }

    function renderPluginsPanel() {
        if ( ! pluginsPanel ) {
            return;
        }

        if ( state.tools.loading.plugins && ! state.tools.plugins.length ) {
            pluginsPanel.innerHTML = getToolLoadingMarkup( 'Loading installed plugins and backup actions...' );
            return;
        }

        if ( ! state.tools.plugins.length ) {
            pluginsPanel.innerHTML = getToolLoadingMarkup( 'No installed plugins were returned by the REST API.', 'empty' );
            return;
        }

        pluginsPanel.innerHTML = state.tools.plugins.map( ( plugin ) => {
            const pluginFile = plugin.file || '';
            const pluginSlug = plugin.slug || pluginFile;
            const statusBadgeClass = plugin.active ? 'is-success' : 'is-neutral';
            const backups = state.tools.backupsBySlug[ pluginSlug ] || {
                visible: false,
                loading: false,
                loaded: false,
                items: [],
            };
            const backupsMarkup = ! backups.visible
                ? ''
                : `
                    <div class="wpfd-pfi-substack">
                        ${ backups.loading
                            ? '<div class="wpfd-pfi-mini-copy">Loading rollback backups...</div>'
                            : ( backups.items.length
                                ? backups.items.map( ( backup ) => `
                                    <div class="wpfd-pfi-subcard">
                                        <div>
                                            <strong>${ escapeHtml( backup.name || backup.path ) }</strong>
                                            <p class="wpfd-pfi-mini-copy">${ escapeHtml( `${ formatDateLabel( backup.timestamp ) } · ${ bytesToLabel( backup.size || 0 ) } · ${ Number( backup.files || 0 ) } files${ backup.verified ? ' · verified' : '' }${ backup.strategy ? ` · ${ backup.strategy }` : '' }` ) }</p>
                                        </div>
                                        <div class="wpfd-pfi-actions-row">
                                            <button type="button" class="button" data-plugin-action="download-snapshot" data-plugin-slug="${ escapeHtml( pluginSlug ) }" data-backup-path="${ escapeHtml( backup.path ) }">Download Snapshot</button>
                                            <button type="button" class="button" data-plugin-action="restore-backup" data-plugin-slug="${ escapeHtml( pluginSlug ) }" data-backup-path="${ escapeHtml( backup.path ) }">Restore</button>
                                            <button type="button" class="button button-secondary wpfd-pfi-danger-button" data-plugin-action="delete-backup" data-plugin-slug="${ escapeHtml( pluginSlug ) }" data-backup-path="${ escapeHtml( backup.path ) }">Delete</button>
                                        </div>
                                    </div>
                                ` ).join( '' )
                                : '<div class="wpfd-pfi-mini-copy">No rollback backups were found for this plugin.</div>' ) }
                    </div>
                `;

            return renderToolListCard( {
                title: plugin.name || pluginSlug,
                meta: `${ pluginSlug } · ${ pluginFile || 'Unknown plugin file' }${ plugin.version ? ` · v${ plugin.version }` : '' }`,
                badgeClass: statusBadgeClass,
                badgeLabel: plugin.active ? 'active' : 'inactive',
                actions: `
                    <button type="button" class="button" data-plugin-action="${ plugin.active ? 'deactivate' : 'activate' }" data-plugin-file="${ escapeHtml( pluginFile ) }">${ escapeHtml( plugin.active ? 'Deactivate' : 'Activate' ) }</button>
                    <button type="button" class="button" data-plugin-action="download-installed" data-plugin-slug="${ escapeHtml( pluginSlug ) }">Download</button>
                    <button type="button" class="button button-secondary" data-plugin-action="toggle-backups" data-plugin-slug="${ escapeHtml( pluginSlug ) }">${ escapeHtml( backups.visible ? 'Hide backups' : 'Show backups' ) }</button>
                `,
                body: backupsMarkup,
            } );
        } ).join( '' );

        if ( refreshPlugins ) {
            refreshPlugins.disabled = state.tools.loading.plugins;
        }
    }

    function renderHistoryPanel() {
        if ( ! historyPanel ) {
            return;
        }

        if ( state.tools.loading.history && ! state.tools.history.rows.length ) {
            historyPanel.innerHTML = getToolLoadingMarkup( 'Loading installer history...' );
            return;
        }

        if ( ! state.tools.history.rows.length ) {
            historyPanel.innerHTML = getToolLoadingMarkup( 'No installer history is available yet.', 'empty' );
            return;
        }

        historyPanel.innerHTML = state.tools.history.rows.map( ( row ) => renderToolListCard( {
            title: row.plugin_name || row.plugin_slug || 'Installer Record',
            meta: `${ row.plugin_slug || 'unknown-slug' } · ${ formatDateLabel( row.created_at || row.install_time || row.deploy_time || row.timestamp ) }`,
            badgeClass: row.status === 'success' ? 'is-success' : 'is-failed',
            badgeLabel: row.status || 'unknown',
            body: `
                <div class="wpfd-pfi-grid-meta">
                    <span>${ escapeHtml( `Plugin file: ${ row.plugin_file || 'n/a' }` ) }</span>
                    <span>${ escapeHtml( `Duration: ${ formatDurationLabel( row.elapsed_ms ) }` ) }</span>
                    <span>${ escapeHtml( `Files: ${ Number( row.file_count || 0 ) }` ) }</span>
                    <span>${ escapeHtml( `By: ${ row.user_login || 'unknown' }` ) }</span>
                </div>
            `,
            actions: `
                <button type="button" class="button button-secondary wpfd-pfi-danger-button" data-history-action="delete" data-history-id="${ escapeHtml( row.id ) }">Delete log entry</button>
            `,
        } ) ).join( '' );

        if ( refreshHistory ) {
            refreshHistory.disabled = state.tools.loading.history;
        }
    }

    function renderBrowserPanel() {
        if ( ! browserResults || ! browserRoot || ! browserPath ) {
            return;
        }

        const roots = state.tools.browser.roots;
        browserRoot.innerHTML = Object.entries( roots ).map( ( [ key, label ] ) => `<option value="${ escapeHtml( key ) }">${ escapeHtml( label ) }</option>` ).join( '' );

        if ( state.tools.browser.root && roots[ state.tools.browser.root ] ) {
            browserRoot.value = state.tools.browser.root;
        }

        browserPath.value = state.tools.browser.path;
        browserUp.disabled = ! state.tools.browser.path || state.tools.loading.browser;
        browserInspect.disabled = ! state.tools.browser.path || state.tools.loading.browser;
        browserNuke.disabled = ! state.tools.browser.path || state.tools.loading.browser;
        browserScan.disabled = state.tools.loading.browser;
        browserRoot.disabled = state.tools.loading.browser;
        browserPath.disabled = state.tools.loading.browser;

        const summary = state.tools.browser.summary;
        browserMeta.innerHTML = summary
            ? `
                <div class="wpfd-pfi-grid-meta">
                    <span>${ escapeHtml( `Root: ${ state.tools.browser.root }` ) }</span>
                    <span>${ escapeHtml( `Path: ${ state.tools.browser.path || '/' }` ) }</span>
                    ${ summary.abs ? `<span>${ escapeHtml( `Absolute: ${ summary.abs }` ) }</span>` : '' }
                    ${ typeof summary.count !== 'undefined' ? `<span>${ escapeHtml( `${ summary.count } entries` ) }</span>` : '' }
                    ${ typeof summary.file_count !== 'undefined' ? `<span>${ escapeHtml( `${ summary.file_count } files` ) }</span>` : '' }
                    ${ typeof summary.total_bytes !== 'undefined' ? `<span>${ escapeHtml( `Size: ${ bytesToLabel( summary.total_bytes || 0 ) }` ) }</span>` : '' }
                    ${ typeof summary.readonly !== 'undefined' ? `<span>${ escapeHtml( `Read-only: ${ Number( summary.readonly || 0 ) }` ) }</span>` : '' }
                    ${ summary.error ? `<span>${ escapeHtml( `Error: ${ summary.error }` ) }</span>` : '' }
                </div>
            `
            : '';

        if ( state.tools.loading.browser && ! state.tools.browser.entries.length ) {
            browserResults.innerHTML = getToolLoadingMarkup( 'Loading browser entries...' );
        } else if ( ! state.tools.browser.entries.length ) {
            browserResults.innerHTML = getToolLoadingMarkup( 'No files or folders were returned for this path.', 'empty' );
        } else {
            browserResults.innerHTML = state.tools.browser.entries.map( ( entry ) => {
                const entryPath = joinRelativePath( state.tools.browser.path, entry.name );
                const entryMeta = entry.type === 'dir'
                    ? `Directory${ typeof entry.children !== 'undefined' ? ` · ${ entry.children } children` : '' }`
                    : `File${ typeof entry.size !== 'undefined' ? ` · ${ bytesToLabel( entry.size || 0 ) }` : '' }`;

                return renderToolListCard( {
                    title: entry.name,
                    meta: `${ entryMeta } · ${ entryPath }`,
                    badgeLabel: entry.type === 'dir' ? 'folder' : 'file',
                    badgeClass: entry.type === 'dir' ? 'is-neutral' : 'is-queued',
                    actions: `
                        ${ entry.type === 'dir'
                            ? `<button type="button" class="button" data-browser-action="open" data-browser-path="${ escapeHtml( entryPath ) }">Open</button>`
                            : `<button type="button" class="button" data-browser-action="read" data-browser-path="${ escapeHtml( entryPath ) }">Read</button>` }
                        ${ entry.type === 'dir'
                            ? `<button type="button" class="button" data-browser-action="extract" data-browser-path="${ escapeHtml( entryPath ) }">Extract</button>`
                            : '' }
                        <button type="button" class="button" data-browser-action="download" data-browser-path="${ escapeHtml( entryPath ) }">Download</button>
                        <button type="button" class="button" data-browser-action="inspect" data-browser-path="${ escapeHtml( entryPath ) }">Nuke scan</button>
                        <button type="button" class="button button-secondary wpfd-pfi-danger-button" data-browser-action="nuke" data-browser-path="${ escapeHtml( entryPath ) }">Delete</button>
                    `,
                } );
            } ).join( '' );
        }

        const previewMarkup = renderBrowserPreviewMarkup();
        browserPreview.hidden = ! previewMarkup;
        browserPreview.innerHTML = previewMarkup;
    }

    function renderSettingsPanel() {
        if ( ! settingsRetention || ! settingsForm ) {
            return;
        }

        if ( settingsBackupDir ) {
            settingsBackupDir.value = state.tools.settingsDisplay.backup_dir || state.tools.settings.backup_dir || 'wp-content/wpfd-backups/';
            settingsBackupDir.disabled = state.tools.loading.settings;
        }

        settingsRetention.value = String( Number( state.tools.settings.backup_retention || 5 ) );
        settingsRetention.disabled = state.tools.loading.settings;

        const submitButton = settingsForm.querySelector( 'button[type="submit"]' );
        if ( submitButton ) {
            submitButton.disabled = state.tools.loading.settings;
        }

        if ( settingsValidateBackupDir ) {
            settingsValidateBackupDir.disabled = state.tools.loading.settings;
        }

        if ( settingsHealth ) {
            const health = state.tools.settingsHealth || {};
            const lastTest = health.last_test && typeof health.last_test === 'object' ? health.last_test : {};
            const lastTestLabel = lastTest.timestamp
                ? formatDateLabel( Number( lastTest.timestamp ) * 1000 )
                : 'never';
            const resolvedDisplay = state.tools.settingsDisplay.resolved_display || '…/wpfd-backups';

            settingsHealth.textContent = [
                `Resolved backup path: ${ resolvedDisplay }`,
                `ZipArchive (installer only): ${ health.ziparchive ? 'available' : 'missing' }`,
                `Backup directory writable: ${ health.backup_dir_writable ? 'yes' : 'not verified' }`,
                `Last validation: ${ lastTestLabel }`,
            ].join( ' · ' );

            // Downloads use a built-in streaming ZIP writer (no ZipArchive
            // needed), with no file-count or size cap. Large folders are
            // automatically served as a static file so they aren't truncated by a
            // server "dynamic response body" limit (e.g. LiteSpeed). The only
            // ceiling left is the web server's request timeout on huge transfers.
            settingsHealth.title =
                'Folder downloads package as a ZIP of any size. Large folders are served as a static '
                + 'file to bypass dynamic-response limits. If a very large download is cut off, raise the '
                + "web server request timeout, or define WPFD_XSENDFILE for native sendfile delivery.";
        }
    }

    function renderWorkbench() {
        if ( ! toolPanels.length ) {
            return;
        }

        if ( toolTabs.length ) {
            toolTabs.forEach( ( button ) => {
                const isActive = button.getAttribute( 'data-wpfd-tool-tab' ) === state.tools.activePanel;
                button.classList.toggle( 'is-active', isActive );
                button.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
            } );
        }

        toolPanels.forEach( ( panel ) => {
            const isActive = panel.getAttribute( 'data-wpfd-tool-panel' ) === state.tools.activePanel;
            panel.hidden = ! isActive;
            panel.classList.toggle( 'is-active', isActive );
        } );

        renderPluginsPanel();
        renderHistoryPanel();
        renderBrowserPanel();
        renderSettingsPanel();
    }

    async function loadPlugins( forceRefresh ) {
        if ( ! pluginsPanel || ( state.tools.loaded.plugins && ! forceRefresh ) ) {
            renderPluginsPanel();
            return;
        }

        state.tools.loading.plugins = true;
        renderPluginsPanel();

        try {
            const payload = await requestRest( 'GET', '/plugins' );
            state.tools.plugins = Array.isArray( payload ) ? payload : [];
            state.tools.loaded.plugins = true;
        } catch ( error ) {
            setToolNotice( error instanceof Error ? error.message : 'Failed to load plugins.', 'error' );
        } finally {
            state.tools.loading.plugins = false;
            renderPluginsPanel();
        }
    }

    async function loadBackups( pluginSlug, forceRefresh ) {
        const backups = state.tools.backupsBySlug[ pluginSlug ] || {
            visible: true,
            loading: false,
            loaded: false,
            items: [],
        };

        if ( backups.loaded && ! forceRefresh ) {
            backups.visible = true;
            state.tools.backupsBySlug[ pluginSlug ] = backups;
            renderPluginsPanel();
            return;
        }

        backups.visible = true;
        backups.loading = true;
        state.tools.backupsBySlug[ pluginSlug ] = backups;
        renderPluginsPanel();

        try {
            const payload = await requestRest( 'GET', '/backups', { query: { slug: pluginSlug } } );
            backups.items = Array.isArray( payload ) ? payload : [];
            backups.loaded = true;
        } catch ( error ) {
            backups.items = [];
            setToolNotice( error instanceof Error ? error.message : 'Failed to load backups.', 'error' );
        } finally {
            backups.loading = false;
            state.tools.backupsBySlug[ pluginSlug ] = backups;
            renderPluginsPanel();
        }
    }

    async function loadHistory( forceRefresh ) {
        if ( ! historyPanel || ( state.tools.loaded.history && ! forceRefresh ) ) {
            renderHistoryPanel();
            return;
        }

        state.tools.loading.history = true;
        renderHistoryPanel();

        try {
            const payload = await requestRest( 'GET', '/history' );
            state.tools.history = {
                rows: Array.isArray( payload?.rows ) ? payload.rows : [],
                total: Number( payload?.total || 0 ),
                truncated: Boolean( payload?.truncated ),
            };
            state.tools.loaded.history = true;
        } catch ( error ) {
            setToolNotice( error instanceof Error ? error.message : 'Failed to load history.', 'error' );
        } finally {
            state.tools.loading.history = false;
            renderHistoryPanel();
        }
    }

    async function scanBrowserPath( rootAlias, relativePath ) {
        if ( ! browserResults ) {
            return;
        }

        state.tools.loading.browser = true;
        state.tools.browser.root = rootAlias;
        state.tools.browser.path = normalizeRelativePath( relativePath );
        renderBrowserPanel();

        try {
            const payload = await requestRest( 'GET', '/browser/scan', {
                query: {
                    root: rootAlias,
                    path: state.tools.browser.path,
                },
            } );

            state.tools.browser.root = rootAlias;
            state.tools.browser.path = normalizeRelativePath( payload?.path || relativePath );
            state.tools.browser.entries = Array.isArray( payload?.entries ) ? payload.entries : [];
            state.tools.browser.summary = {
                abs: payload?.abs || '',
                count: state.tools.browser.entries.length,
            };
            state.tools.browser.preview = null;
            state.tools.loaded.browser = true;
        } catch ( error ) {
            setToolNotice( error instanceof Error ? error.message : 'Failed to scan the selected path.', 'error' );
        } finally {
            state.tools.loading.browser = false;
            renderBrowserPanel();
        }
    }

    async function loadBrowser( forceRefresh ) {
        if ( ! browserResults ) {
            return;
        }

        if ( state.tools.loaded.browser && ! forceRefresh && Object.keys( state.tools.browser.roots ).length ) {
            renderBrowserPanel();
            return;
        }

        state.tools.loading.browser = true;
        renderBrowserPanel();

        try {
            const payload = await requestRest( 'GET', '/browser/roots' );
            state.tools.browser.roots = payload?.roots && typeof payload.roots === 'object' ? payload.roots : {};

            if ( ! state.tools.browser.roots[ state.tools.browser.root ] ) {
                state.tools.browser.root = Object.keys( state.tools.browser.roots )[ 0 ] || 'plugins';
            }
        } catch ( error ) {
            state.tools.loading.browser = false;
            renderBrowserPanel();
            setToolNotice( error instanceof Error ? error.message : 'Failed to load browser roots.', 'error' );
            return;
        }

        state.tools.loading.browser = false;
        await scanBrowserPath( state.tools.browser.root, state.tools.browser.path );
    }

    async function inspectBrowserPath( rootAlias, relativePath ) {
        state.tools.loading.browser = true;
        renderBrowserPanel();

        try {
            const payload = await requestRest( 'POST', '/browser/nuke-scan', {
                formData: createFormData( {
                    root: rootAlias,
                    path: normalizeRelativePath( relativePath ),
                } ),
            } );

            state.tools.browser.summary = {
                file_count: Number( payload?.file_count || 0 ),
                total_bytes: Number( payload?.total_bytes || 0 ),
                readonly: Number( payload?.readonly || 0 ),
                error: payload?.error || '',
            };
        } catch ( error ) {
            setToolNotice( error instanceof Error ? error.message : 'Failed to inspect the selected path.', 'error' );
        } finally {
            state.tools.loading.browser = false;
            renderBrowserPanel();
        }
    }

    async function readBrowserFile( rootAlias, relativePath ) {
        state.tools.loading.browser = true;
        renderBrowserPanel();

        try {
            const payload = await requestRest( 'POST', '/browser/read-file', {
                formData: createFormData( {
                    root: rootAlias,
                    path: normalizeRelativePath( relativePath ),
                } ),
            } );

            state.tools.browser.preview = {
                type: 'file',
                filename: payload?.filename || relativePath,
                path: normalizeRelativePath( relativePath ),
                content: payload?.content || '',
            };
        } catch ( error ) {
            setToolNotice( error instanceof Error ? error.message : 'Failed to read the selected file.', 'error' );
        } finally {
            state.tools.loading.browser = false;
            renderBrowserPanel();
        }
    }

    async function extractBrowserDirectory( rootAlias, relativePath ) {
        state.tools.loading.browser = true;
        renderBrowserPanel();

        try {
            const payload = await requestRest( 'POST', '/browser/extract-dir', {
                formData: createFormData( {
                    root: rootAlias,
                    path: normalizeRelativePath( relativePath ),
                } ),
            } );

            state.tools.browser.preview = {
                type: 'dir',
                dirname: payload?.dirname || relativePath,
                count: Number( payload?.count || 0 ),
                truncated: Boolean( payload?.truncated ),
                files: Array.isArray( payload?.files ) ? payload.files : [],
            };
        } catch ( error ) {
            setToolNotice( error instanceof Error ? error.message : 'Failed to extract the selected directory.', 'error' );
        } finally {
            state.tools.loading.browser = false;
            renderBrowserPanel();
        }
    }

    function triggerTokenDownload( url ) {
        if ( url ) {
            window.location.assign( url );
        }
    }

    async function downloadPlugin( pluginSlug, backupPath ) {
        try {
            const payload = await requestRest( 'POST', '/download/plugin-token', {
                formData: createFormData( {
                    slug: pluginSlug,
                    backup_path: backupPath || '',
                } ),
            } );

            if ( payload?.url ) {
                triggerTokenDownload( payload.url );
            }
        } catch ( error ) {
            setToolNotice( error instanceof Error ? error.message : 'Failed to prepare the plugin download.', 'error' );
        }
    }

    async function downloadBrowserPath( rootAlias, relativePath ) {
        try {
            const payload = await requestRest( 'POST', '/download/token', {
                formData: createFormData( {
                    root: rootAlias,
                    path: normalizeRelativePath( relativePath ),
                } ),
            } );

            if ( payload?.url ) {
                triggerTokenDownload( payload.url );
            }
        } catch ( error ) {
            setToolNotice( error instanceof Error ? error.message : 'Failed to prepare the download.', 'error' );
        }
    }

    async function validateBackupDirSetting() {
        if ( ! settingsBackupDir ) {
            return;
        }

        state.tools.loading.settings = true;
        renderSettingsPanel();

        try {
            const payload = await requestRest( 'POST', '/settings/validate-backup-dir', {
                json: {
                    backup_dir: settingsBackupDir.value || 'wp-content/wpfd-backups/',
                },
            } );

            if ( payload?.resolved_path ) {
                state.tools.settingsDisplay.resolved_backup_dir = payload.resolved_path;
                state.tools.settingsDisplay.resolved_display = payload.display_path || state.tools.settingsDisplay.resolved_display;
            }

            state.tools.settingsHealth.backup_dir_writable = true;
            state.tools.settingsHealth.last_test = {
                timestamp: Math.floor( Date.now() / 1000 ),
                path: payload?.resolved_path || '',
                success: true,
            };

            setSettingsStatus( payload?.message || 'Backup directory is valid and writable.', 'success' );
        } catch ( error ) {
            setSettingsStatus( error instanceof Error ? error.message : 'Backup directory validation failed.', 'error' );
        } finally {
            state.tools.loading.settings = false;
            renderSettingsPanel();
        }
    }

    async function nukeBrowserPath( rootAlias, relativePath ) {
        const normalizedPath = normalizeRelativePath( relativePath );
        if ( ! normalizedPath ) {
            return;
        }

        if ( ! window.confirm( `Delete ${ normalizedPath } from ${ rootAlias }? This cannot be undone.` ) ) {
            return;
        }

        state.tools.loading.browser = true;
        renderBrowserPanel();

        try {
            await requestRest( 'POST', '/browser/nuke', {
                formData: createFormData( {
                    root: rootAlias,
                    path: normalizedPath,
                } ),
            } );

            setToolNotice( `Deleted ${ normalizedPath }.`, 'success' );
            const nextPath = normalizedPath === state.tools.browser.path ? parentPath( normalizedPath ) : state.tools.browser.path;
            await scanBrowserPath( rootAlias, nextPath );
            return;
        } catch ( error ) {
            setToolNotice( error instanceof Error ? error.message : 'Failed to delete the selected path.', 'error' );
        } finally {
            state.tools.loading.browser = false;
            renderBrowserPanel();
        }
    }

    async function loadSettings( forceRefresh ) {
        if ( ! settingsForm || ( state.tools.loaded.settings && ! forceRefresh ) ) {
            renderSettingsPanel();
            return;
        }

        state.tools.loading.settings = true;
        renderSettingsPanel();

        try {
            const payload = await requestRest( 'GET', '/settings' );
            state.tools.settings = payload?.settings && typeof payload.settings === 'object'
                ? payload.settings
                : { backup_dir: 'wp-content/wpfd-backups/', backup_retention: 5 };
            state.tools.settingsDisplay = payload?.display && typeof payload.display === 'object'
                ? payload.display
                : {
                    backup_dir: state.tools.settings.backup_dir || 'wp-content/wpfd-backups/',
                    resolved_backup_dir: '',
                    resolved_display: '',
                };
            state.tools.settingsHealth = payload?.health && typeof payload.health === 'object'
                ? payload.health
                : state.tools.settingsHealth;
            state.tools.loaded.settings = true;
            setSettingsStatus( '', 'error' );
        } catch ( error ) {
            setToolNotice( error instanceof Error ? error.message : 'Failed to load settings.', 'error' );
        } finally {
            state.tools.loading.settings = false;
            renderSettingsPanel();
        }
    }

    async function saveSettings() {
        if ( ! settingsRetention ) {
            return;
        }

        state.tools.loading.settings = true;
        renderSettingsPanel();

        try {
            const payload = await requestRest( 'POST', '/settings', {
                json: {
                    backup_dir: settingsBackupDir ? settingsBackupDir.value : 'wp-content/wpfd-backups/',
                    backup_retention: Number( settingsRetention.value || 5 ),
                },
            } );

            state.tools.settings = payload?.settings && typeof payload.settings === 'object'
                ? payload.settings
                : {
                    backup_dir: settingsBackupDir ? settingsBackupDir.value : 'wp-content/wpfd-backups/',
                    backup_retention: Number( settingsRetention.value || 5 ),
                };
            state.tools.settingsDisplay = payload?.display && typeof payload.display === 'object'
                ? payload.display
                : state.tools.settingsDisplay;
            state.tools.settingsHealth = payload?.health && typeof payload.health === 'object'
                ? payload.health
                : state.tools.settingsHealth;
            state.tools.loaded.settings = true;
            setSettingsStatus( 'Settings saved.', 'success' );
        } catch ( error ) {
            setSettingsStatus( error instanceof Error ? error.message : 'Failed to save settings.', 'error' );
        } finally {
            state.tools.loading.settings = false;
            renderSettingsPanel();
        }
    }

    async function loadPanelData( panelName, forceRefresh ) {
        if ( ! canManageOptions ) {
            return;
        }

        if ( panelName === 'plugins' ) {
            await loadPlugins( forceRefresh );
            return;
        }

        if ( panelName === 'history' ) {
            await loadHistory( forceRefresh );
            return;
        }

        if ( panelName === 'browser' ) {
            await loadBrowser( forceRefresh );
            return;
        }

        if ( panelName === 'settings' ) {
            await loadSettings( forceRefresh );
        }
    }

    function switchToolPanel( panelName ) {
        if ( ! panelName ) {
            return;
        }

        state.tools.activePanel = panelName;
        renderWorkbench();
        void loadPanelData( panelName );
    }

    async function handlePluginPanelAction( action, button ) {
        const pluginFile = button.getAttribute( 'data-plugin-file' ) || '';
        const pluginSlug = button.getAttribute( 'data-plugin-slug' ) || '';
        const backupPath = button.getAttribute( 'data-backup-path' ) || '';

        if ( action === 'toggle-backups' ) {
            const backups = state.tools.backupsBySlug[ pluginSlug ] || {
                visible: false,
                loading: false,
                loaded: false,
                items: [],
            };

            if ( backups.visible ) {
                backups.visible = false;
                state.tools.backupsBySlug[ pluginSlug ] = backups;
                renderPluginsPanel();
                return;
            }

            await loadBackups( pluginSlug );
            return;
        }

        if ( action === 'activate' || action === 'deactivate' ) {
            try {
                await requestRest( 'POST', `/${ action }`, {
                    formData: createFormData( {
                        plugin_file: pluginFile,
                    } ),
                } );

                setToolNotice( `Plugin ${ action }d successfully.`, 'success' );
                await loadPlugins( true );
                return;
            } catch ( error ) {
                setToolNotice( error instanceof Error ? error.message : `Failed to ${ action } plugin.`, 'error' );
                return;
            }
        }

        if ( action === 'restore-backup' ) {
            if ( ! window.confirm( `Restore backup for ${ pluginSlug }? This overwrites the current plugin folder.` ) ) {
                return;
            }

            try {
                await requestRest( 'POST', '/rollback', {
                    formData: createFormData( {
                        plugin_slug: pluginSlug,
                        backup_path: backupPath,
                    } ),
                } );

                setToolNotice( `Restored backup for ${ pluginSlug }.`, 'success' );
                await loadPlugins( true );
                await loadBackups( pluginSlug, true );
                return;
            } catch ( error ) {
                setToolNotice( error instanceof Error ? error.message : 'Failed to restore backup.', 'error' );
                return;
            }
        }

        if ( action === 'delete-backup' ) {
            if ( ! window.confirm( `Delete the selected backup for ${ pluginSlug }?` ) ) {
                return;
            }

            try {
                await requestRest( 'POST', '/backups/delete', {
                    formData: createFormData( {
                        backup_path: backupPath,
                    } ),
                } );

                setToolNotice( `Deleted backup for ${ pluginSlug }.`, 'success' );
                await loadBackups( pluginSlug, true );
                return;
            } catch ( error ) {
                setToolNotice( error instanceof Error ? error.message : 'Failed to delete backup.', 'error' );
            }
        }

        if ( action === 'download-installed' ) {
            await downloadPlugin( pluginSlug );
            return;
        }

        if ( action === 'download-snapshot' ) {
            await downloadPlugin( pluginSlug, backupPath );
        }
    }

    async function handleHistoryAction( action, button ) {
        if ( action !== 'delete' ) {
            return;
        }

        const historyId = Number( button.getAttribute( 'data-history-id' ) || 0 );
        if ( ! historyId ) {
            return;
        }

        if ( ! window.confirm( `Delete installer history record #${ historyId }?` ) ) {
            return;
        }

        try {
            await requestRest( 'POST', '/history/delete', {
                formData: createFormData( {
                    ids: [ historyId ],
                } ),
            } );

            setToolNotice( `Deleted history record #${ historyId }.`, 'success' );
            await loadHistory( true );
        } catch ( error ) {
            setToolNotice( error instanceof Error ? error.message : 'Failed to delete history record.', 'error' );
        }
    }

    async function handleBrowserAction( action, button ) {
        const relativePath = button.getAttribute( 'data-browser-path' ) || '';
        const rootAlias = state.tools.browser.root;

        if ( action === 'open' ) {
            await scanBrowserPath( rootAlias, relativePath );
            return;
        }

        if ( action === 'read' ) {
            await readBrowserFile( rootAlias, relativePath );
            return;
        }

        if ( action === 'extract' ) {
            await extractBrowserDirectory( rootAlias, relativePath );
            return;
        }

        if ( action === 'download' ) {
            await downloadBrowserPath( rootAlias, relativePath );
            return;
        }

        if ( action === 'inspect' ) {
            await inspectBrowserPath( rootAlias, relativePath );
            return;
        }

        if ( action === 'nuke' ) {
            await nukeBrowserPath( rootAlias, relativePath );
        }
    }

    function render() {
        if ( ! results ) {
            return;
        }

        renderBatchPanel();
        updateLiveSummary();

        if ( ! state.items.length ) {
            results.innerHTML = '<div class="wpfd-pfi-empty">No installs queued yet. Add folders to the batch and click Queue for install.</div>';
            return;
        }

        results.innerHTML = getDisplayItems().map( ( item ) => {
            const isFinished = item.status === 'success' || item.status === 'failed';
            const finishedAgo = isFinished ? formatRelativeTimeAgo( getItemFinishedAt( item ) ) : '';
            const meta = [
                `${ item.files.length } files`,
                bytesToLabel( item.totalBytes ),
                item.status === 'failed' && item.errorDetails.length
                    ? `${ item.errorDetails.length } issue${ item.errorDetails.length === 1 ? '' : 's' }`
                    : '',
            ].filter( Boolean ).map( ( entry ) => `<span>${ escapeHtml( entry ) }</span>` ).join( '' );
            const badgeClass = getStatusClass( item.status );
            const stepStrip = stepLabels.map( ( label ) => {
                const step = item.steps[ label ] || createStep( 'pending', '' );
                const toneClass = getStepTone( step.status );
                const title = step.message || formatStatusLabel( step.status ) || 'Pending';
                return `<span class="wpfd-pfi-mini-step ${ toneClass }" title="${ escapeHtml( title ) }">${ escapeHtml( stepTitles[ label ] || label ) }</span>`;
            } ).join( '' );
            const stepStripMarkup = isFinished ? '' : `<div class="wpfd-pfi-step-strip">${ stepStrip }</div>`;
            const details = buildActivityDetailsMarkup( item );
            const retryAction = item.status === 'failed'
                ? `<div class="wpfd-pfi-result-actions"><button type="button" class="button button-secondary" data-retry="${ escapeHtml( item.id ) }">Retry</button></div>`
                : '';

            return `
                <article class="wpfd-pfi-card wpfd-pfi-result-item${ item.status === 'running' ? ' is-processing' : '' }${ item.status === 'success' ? ' is-success' : '' }${ item.status === 'failed' ? ' is-failed' : '' }">
                    <div class="wpfd-pfi-result-head">
                        <div class="wpfd-pfi-result-title">
                            <h2>${ escapeHtml( item.folderName ) }</h2>
                            <p class="wpfd-pfi-result-caption">${ escapeHtml( getCompactProgressLabel( item ) ) }</p>
                            ${ finishedAgo ? `<p class="wpfd-pfi-finished-timer">${ escapeHtml( finishedAgo ) }</p>` : '' }
                        </div>
                        <span class="wpfd-pfi-badge ${ badgeClass }${ item.status === 'running' ? ' wpfd-pfi-status-processing' : '' }">${ escapeHtml( formatStatusLabel( item.status ) ) }</span>
                    </div>
                    <div class="wpfd-pfi-grid-meta">${ meta }</div>
                    <div class="wpfd-pfi-result-progress-row">
                        <div class="wpfd-pfi-progress"><span style="width:${ item.progress }%"></span></div>
                        <span class="wpfd-pfi-result-progress-label">${ item.progress }%</span>
                    </div>
                    <p class="wpfd-pfi-summary">${ escapeHtml( item.message ) }</p>
                    ${ stepStripMarkup }
                    ${ details }
                    ${ retryAction }
                </article>
            `;
        } ).join( '' );
    }

    if ( batchBrowse ) {
        batchBrowse.addEventListener( 'click', () => {
            void openLocalFolderBrowser();
        } );
    }

    if ( batchClear ) {
        batchClear.addEventListener( 'click', clearPendingBatch );
    }

    if ( batchQueue ) {
        batchQueue.addEventListener( 'click', queuePendingBatch );
    }

    if ( batchList ) {
        batchList.addEventListener( 'click', ( event ) => {
            const button = event.target.closest( '[data-batch-remove]' );

            if ( ! button ) {
                return;
            }

            removePendingFolder( button.getAttribute( 'data-batch-remove' ) || '' );
        } );
    }

    if ( dropzone ) {
        dropzone.addEventListener( 'dragover', ( event ) => {
            event.preventDefault();
            dropzone.classList.add( 'is-dragover' );
        } );

        dropzone.addEventListener( 'dragleave', () => {
            dropzone.classList.remove( 'is-dragover' );
        } );

        dropzone.addEventListener( 'drop', async ( event ) => {
            event.preventDefault();
            dropzone.classList.remove( 'is-dragover' );

            try {
                await handleDroppedFolders( event.dataTransfer );
            } catch ( error ) {
                setNotice( error instanceof Error ? error.message : 'Could not read the dropped folders.' );
            }
        } );

        dropzone.addEventListener( 'click', () => {
            void openLocalFolderBrowser();
        } );
        dropzone.addEventListener( 'keydown', ( event ) => {
            if ( event.key === 'Enter' || event.key === ' ' ) {
                event.preventDefault();
                void openLocalFolderBrowser();
            }
        } );
    }

    if ( localBrowserConnect ) {
        localBrowserConnect.addEventListener( 'click', () => {
            void connectLocalRoot();
        } );
    }

    if ( localBrowserRoot ) {
        localBrowserRoot.addEventListener( 'change', () => {
            setLocalBrowserRoot( localBrowserRoot.value );
        } );
    }

    if ( localBrowserUp ) {
        localBrowserUp.addEventListener( 'click', () => {
            void navigateLocalBrowserUp();
        } );
    }

    if ( localBrowserAdd ) {
        localBrowserAdd.addEventListener( 'click', () => {
            void addSelectedLocalFoldersToBatch();
        } );
    }

    if ( localBrowserCloseButtons.length ) {
        localBrowserCloseButtons.forEach( ( button ) => {
            button.addEventListener( 'click', closeLocalFolderBrowser );
        } );
    }

    if ( localBrowserBreadcrumb ) {
        localBrowserBreadcrumb.addEventListener( 'click', ( event ) => {
            const button = event.target.closest( '[data-local-crumb]' );

            if ( ! button ) {
                return;
            }

            void navigateLocalBrowserToCrumb( Number( button.getAttribute( 'data-local-crumb' ) ) );
        } );
    }

    if ( localBrowserList ) {
        localBrowserList.addEventListener( 'click', ( event ) => {
            const openButton = event.target.closest( '[data-local-open]' );

            if ( ! openButton ) {
                return;
            }

            const entry = state.localBrowser.entries.find( ( item ) => item.id === openButton.getAttribute( 'data-local-open' ) );

            if ( entry ) {
                void openLocalBrowserEntry( entry );
            }
        } );

        localBrowserList.addEventListener( 'change', ( event ) => {
            const checkbox = event.target.closest( '[data-local-select]' );

            if ( ! checkbox || event.target.tagName !== 'INPUT' ) {
                return;
            }

            const entryId = checkbox.getAttribute( 'data-local-select' ) || '';
            const handle = resolveLocalBrowserEntryHandle( entryId );

            if ( handle ) {
                toggleLocalBrowserSelection( entryId, handle, checkbox.checked );
            }
        } );
    }

    if ( clearCompleted ) {
        clearCompleted.addEventListener( 'click', clearCompletedItems );
    }

    if ( results ) {
        results.addEventListener( 'click', ( event ) => {
            const copyButton = event.target.closest( '[data-copy-errors]' );
            if ( copyButton ) {
                void copyItemErrors( copyButton.getAttribute( 'data-copy-errors' ) || '', copyButton );
                return;
            }

            const button = event.target.closest( '[data-retry]' );
            if ( ! button ) {
                return;
            }

            retryItem( button.getAttribute( 'data-retry' ) );
        } );
    }

    if ( openHistory ) {
        openHistory.addEventListener( 'click', openHistoryModal );
    }

    if ( historyCloseButtons.length ) {
        historyCloseButtons.forEach( ( button ) => {
            button.addEventListener( 'click', closeHistoryModal );
        } );
    }

    document.addEventListener( 'keydown', ( event ) => {
        if ( event.key === 'Escape' && historyModal && ! historyModal.hidden ) {
            closeHistoryModal();
            return;
        }

        if ( event.key === 'Escape' && localBrowserModal && ! localBrowserModal.hidden ) {
            closeLocalFolderBrowser();
        }
    } );

    if ( toolTabs.length ) {
        toolTabs.forEach( ( button ) => {
            button.addEventListener( 'click', () => {
                switchToolPanel( button.getAttribute( 'data-wpfd-tool-tab' ) );
            } );
        } );
    }

    if ( refreshPlugins ) {
        refreshPlugins.addEventListener( 'click', () => {
            void loadPlugins( true );
        } );
    }

    if ( pluginsPanel ) {
        pluginsPanel.addEventListener( 'click', ( event ) => {
            const button = event.target.closest( '[data-plugin-action]' );
            if ( ! button ) {
                return;
            }

            void handlePluginPanelAction( button.getAttribute( 'data-plugin-action' ), button );
        } );
    }

    if ( refreshHistory ) {
        refreshHistory.addEventListener( 'click', () => {
            void loadHistory( true );
        } );
    }

    if ( historyPanel ) {
        historyPanel.addEventListener( 'click', ( event ) => {
            const button = event.target.closest( '[data-history-action]' );
            if ( ! button ) {
                return;
            }

            void handleHistoryAction( button.getAttribute( 'data-history-action' ), button );
        } );
    }

    if ( browserRoot ) {
        browserRoot.addEventListener( 'change', () => {
            state.tools.browser.root = browserRoot.value;
            state.tools.browser.path = '';
            void scanBrowserPath( state.tools.browser.root, '' );
        } );
    }

    if ( browserPath ) {
        browserPath.addEventListener( 'input', () => {
            state.tools.browser.path = normalizeRelativePath( browserPath.value );
        } );

        browserPath.addEventListener( 'keydown', ( event ) => {
            if ( event.key === 'Enter' ) {
                event.preventDefault();
                void scanBrowserPath( state.tools.browser.root, browserPath.value );
            }
        } );
    }

    if ( browserScan ) {
        browserScan.addEventListener( 'click', () => {
            void scanBrowserPath( state.tools.browser.root, browserPath?.value || '' );
        } );
    }

    if ( browserUp ) {
        browserUp.addEventListener( 'click', () => {
            void scanBrowserPath( state.tools.browser.root, parentPath( browserPath?.value || '' ) );
        } );
    }

    if ( browserInspect ) {
        browserInspect.addEventListener( 'click', () => {
            void inspectBrowserPath( state.tools.browser.root, browserPath?.value || '' );
        } );
    }

    if ( browserNuke ) {
        browserNuke.addEventListener( 'click', () => {
            void nukeBrowserPath( state.tools.browser.root, browserPath?.value || '' );
        } );
    }

    if ( browserResults ) {
        browserResults.addEventListener( 'click', ( event ) => {
            const button = event.target.closest( '[data-browser-action]' );
            if ( ! button ) {
                return;
            }

            void handleBrowserAction( button.getAttribute( 'data-browser-action' ), button );
        } );
    }

    if ( settingsForm ) {
        settingsForm.addEventListener( 'submit', ( event ) => {
            event.preventDefault();
            void saveSettings();
        } );
    }

    if ( settingsValidateBackupDir ) {
        settingsValidateBackupDir.addEventListener( 'click', () => {
            void validateBackupDirSetting();
        } );
    }

    renderWorkbench();
    renderBatchPanel();
    if ( multiFolderPickerMode ) {
        multiFolderPickerMode.textContent = supportsLocalFolderBrowser()
            ? 'In-app PC browser · Chrome/Edge recommended'
            : 'Drag-and-drop only · in-app PC browser unavailable';
    }
    if ( canManageOptions && state.tools.activePanel ) {
        void loadPanelData( state.tools.activePanel );
    } else if ( viewMode === 'installer' && historyPanel ) {
        renderHistoryPanel();
    }
}() );