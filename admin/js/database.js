/**
 * Database Manager — WP Server Terminal
 *
 * Drives the interactive SQL editor, table browser, query history,
 * and results renderer on the Database Manager admin page.
 *
 * Expects `wstDatabase` to be localized by WordPress with:
 *   { ajaxurl: string, nonce: string }
 *
 * Dependencies (loaded before this file):
 *   - CodeMirror core
 *   - CodeMirror SQL mode (text/x-sql)
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

( function () {
    'use strict';

    /* -------------------------------------------------------------------------
     * State
     * ---------------------------------------------------------------------- */

    /** @type {CodeMirror.Editor|null} */
    var editor = null;

    /** @type {string[]} Max 50 entries, persisted in localStorage. */
    var queryHistory = [];

    var HISTORY_KEY    = 'wst_query_history';
    var HISTORY_LIMIT  = 50;
    var DISPLAY_LIMIT  = 80;  // chars shown in history dropdown

    /** @type {string} Name of the currently selected table. */
    var currentTable = '';

    /* -------------------------------------------------------------------------
     * Initialisation
     * ---------------------------------------------------------------------- */

    /**
     * Bootstrap the database manager after the DOM is ready.
     */
    function init() {
        // Set up CodeMirror SQL editor
        editor = CodeMirror( document.getElementById( 'wst-sql-editor' ), {
            mode        : 'text/x-sql',
            lineNumbers : true,
            theme       : 'default',
            lineWrapping: true,
            tabSize     : 2,
            extraKeys   : {
                'Ctrl-Enter': executeQuery,
                'Cmd-Enter' : executeQuery
            }
        } );

        loadTableList();
        setupButtons();
        loadHistory();
    }

    /* -------------------------------------------------------------------------
     * Table list
     * ---------------------------------------------------------------------- */

    /**
     * Fetch the list of database tables via AJAX and render the sidebar.
     */
    function loadTableList() {
        var ul = document.getElementById( 'wst-table-list' );
        ul.innerHTML = '<li class="wst-loading">Loading&hellip;</li>';

        ajax( 'wst_sql_tables', {}, function ( ok, data, errMsg ) {
            ul.innerHTML = '';

            if ( ! ok || ! Array.isArray( data ) ) {
                ul.innerHTML = '<li class="wst-error">' +
                    escapeHtml( errMsg || 'Could not load tables.' ) + '</li>';
                return;
            }

            if ( data.length === 0 ) {
                ul.innerHTML = '<li>No tables found.</li>';
                return;
            }

            data.forEach( function ( table ) {
                var li = document.createElement( 'li' );
                li.dataset.table = table.name;

                // Tooltip: row count + size (both optional)
                var tipParts = [];
                if ( table.rows !== undefined && table.rows !== null ) {
                    tipParts.push( table.rows + ' rows' );
                }
                if ( table.size !== undefined && table.size !== null ) {
                    tipParts.push( table.size );
                }
                if ( tipParts.length ) {
                    li.title = tipParts.join( ', ' );
                }

                li.textContent = table.name;

                // Single click: SELECT query, highlight active row
                li.addEventListener( 'click', function () {
                    selectTable( table.name, li );
                } );

                // Double click: DESCRIBE query + immediate execution
                li.addEventListener( 'dblclick', function () {
                    editor.setValue( 'DESCRIBE `' + table.name + '`' );
                    executeQuery();
                } );

                ul.appendChild( li );
            } );
        } );
    }

    /**
     * Handle a single-click selection of a table in the sidebar.
     *
     * @param {string}      name The table name.
     * @param {HTMLElement} li   The clicked list item.
     */
    function selectTable( name, li ) {
        currentTable = name;

        // Highlight the active item
        var siblings = document.querySelectorAll( '#wst-table-list li' );
        siblings.forEach( function ( el ) {
            el.classList.remove( 'active' );
        } );
        li.classList.add( 'active' );

        // Insert a SELECT template into the editor
        editor.setValue( 'SELECT * FROM `' + name + '` LIMIT 100' );
        editor.focus();
    }

    /* -------------------------------------------------------------------------
     * Query execution
     * ---------------------------------------------------------------------- */

    /**
     * Read the editor content, send it to the server, and render results.
     * Can be called directly or as a CodeMirror key binding handler.
     */
    function executeQuery() {
        var sql = editor.getValue().trim();
        if ( ! sql ) {
            return;
        }

        // Loading state
        var btn     = document.getElementById( 'wst-sql-execute' );
        var metaEl  = document.getElementById( 'wst-sql-meta' );
        btn.disabled = true;
        metaEl.textContent = 'Executing\u2026';

        saveToHistory( sql );

        ajax( 'wst_sql_exec', { sql: sql }, function ( ok, data ) {
            btn.disabled = false;
            renderResults( { success: ok, data: data } );
        } );
    }

    /* -------------------------------------------------------------------------
     * Results rendering
     * ---------------------------------------------------------------------- */

    /**
     * Render the server response into the results area.
     *
     * @param {{ success: boolean, data: * }} response
     */
    function renderResults( response ) {
        var errorEl  = document.getElementById( 'wst-sql-error' );
        var metaEl   = document.getElementById( 'wst-sql-meta' );
        var wrapEl   = document.getElementById( 'wst-sql-results-table-wrap' );

        // Reset
        errorEl.style.display = 'none';
        errorEl.textContent   = '';
        wrapEl.innerHTML      = '';

        if ( ! response.success ) {
            var msg;
            if ( response.data && response.data.code === 'reauth_required' ) {
                msg = 'Session expired. Please refresh the page.';
            } else {
                msg = ( response.data && response.data.message )
                    ? response.data.message
                    : 'An unknown error occurred.';
            }
            errorEl.textContent   = msg;
            errorEl.style.display = 'block';
            metaEl.textContent    = 'Query failed';
            return;
        }

        var data = response.data;

        // Multiple statements return an array of result objects
        if ( Array.isArray( data ) ) {
            data.forEach( function ( result ) {
                renderSingleResult( result, metaEl, wrapEl, true );
            } );
            return;
        }

        renderSingleResult( data, metaEl, wrapEl, false );
    }

    /**
     * Render one result object. When `multi` is true a heading separator is
     * prepended so results are visually grouped.
     *
     * @param {object}      data
     * @param {HTMLElement} metaEl
     * @param {HTMLElement} wrapEl
     * @param {boolean}     multi
     */
    function renderSingleResult( data, metaEl, wrapEl, multi ) {
        var type = data && data.type;

        if ( type === 'select' ) {
            // Meta bar
            var rowLabel = data.row_count + ' row' + ( data.row_count !== 1 ? 's' : '' );
            var limitNote = data.limited ? ' (limited to 1000)' : '';
            metaEl.textContent = rowLabel + limitNote + ' \u00b7 ' + data.execution_time_ms + 'ms';

            if ( multi ) {
                var sep = document.createElement( 'p' );
                sep.className = 'wst-result-separator';
                sep.textContent = 'SELECT \u2014 ' + rowLabel + limitNote;
                wrapEl.appendChild( sep );
            }

            if ( ! data.columns || data.columns.length === 0 || ! data.rows || data.rows.length === 0 ) {
                var noRows = document.createElement( 'p' );
                noRows.className = 'wst-no-results';
                noRows.textContent = 'No results returned.';
                wrapEl.appendChild( noRows );
                return;
            }

            // Build table
            var table  = document.createElement( 'table' );
            table.className = 'wst-results-table';

            // thead
            var thead = document.createElement( 'thead' );
            var hrow  = document.createElement( 'tr' );
            data.columns.forEach( function ( col ) {
                var th = document.createElement( 'th' );
                th.textContent = col;
                hrow.appendChild( th );
            } );
            thead.appendChild( hrow );
            table.appendChild( thead );

            // tbody
            var tbody = document.createElement( 'tbody' );
            data.rows.forEach( function ( row ) {
                var tr = document.createElement( 'tr' );
                data.columns.forEach( function ( col ) {
                    var td  = document.createElement( 'td' );
                    var val = row[ col ];

                    if ( val === null || val === undefined ) {
                        // Null displayed distinctly from empty string
                        var em = document.createElement( 'em' );
                        em.className   = 'wst-null';
                        em.textContent = 'NULL';
                        td.appendChild( em );
                    } else {
                        td.textContent = String( val );
                    }

                    tr.appendChild( td );
                } );
                tbody.appendChild( tr );
            } );
            table.appendChild( tbody );
            wrapEl.appendChild( table );

        } else if ( type === 'write' ) {
            var affectedLabel = data.affected_rows + ' row' +
                ( data.affected_rows !== 1 ? 's' : '' ) + ' affected';
            var insertNote = data.insert_id
                ? ' \u00b7 Last insert ID: ' + data.insert_id
                : '';
            metaEl.textContent = affectedLabel + ' \u00b7 ' + data.execution_time_ms + 'ms' + insertNote;

            var successMsg = document.createElement( 'p' );
            successMsg.className = 'wst-write-success';
            successMsg.textContent = affectedLabel + '.';
            wrapEl.appendChild( successMsg );

        } else if ( type === 'ddl' ) {
            var ddlStatus = data.success ? 'Query executed successfully' : 'Query failed';
            metaEl.textContent = ddlStatus + ' \u00b7 ' + data.execution_time_ms + 'ms';

            var ddlMsg = document.createElement( 'p' );
            ddlMsg.className = data.success ? 'wst-ddl-success' : 'wst-ddl-error';
            ddlMsg.textContent = ddlStatus + '.';
            wrapEl.appendChild( ddlMsg );

        } else {
            // Unknown result type — show raw JSON for debugging
            var unknown = document.createElement( 'pre' );
            unknown.textContent = JSON.stringify( data, null, 2 );
            wrapEl.appendChild( unknown );
            metaEl.textContent = 'Unknown result type';
        }
    }

    /* -------------------------------------------------------------------------
     * Query history
     * ---------------------------------------------------------------------- */

    /**
     * Persist a query to localStorage history and rebuild the dropdown.
     *
     * @param {string} sql
     */
    function saveToHistory( sql ) {
        var stored = loadHistoryFromStorage();

        // Deduplicate: remove any existing exact match before prepending
        stored = stored.filter( function ( entry ) {
            return entry !== sql;
        } );

        stored.unshift( sql );

        // Enforce max entries
        if ( stored.length > HISTORY_LIMIT ) {
            stored = stored.slice( 0, HISTORY_LIMIT );
        }

        queryHistory = stored;

        try {
            localStorage.setItem( HISTORY_KEY, JSON.stringify( stored ) );
        } catch ( e ) {
            // localStorage may be unavailable (private browsing, quota exceeded)
        }

        rebuildHistoryDropdown();
    }

    /**
     * Load history from localStorage on page load and populate the dropdown.
     */
    function loadHistory() {
        queryHistory = loadHistoryFromStorage();
        rebuildHistoryDropdown();
    }

    /**
     * Read raw history array from localStorage.
     *
     * @returns {string[]}
     */
    function loadHistoryFromStorage() {
        try {
            var raw = localStorage.getItem( HISTORY_KEY );
            if ( raw ) {
                var parsed = JSON.parse( raw );
                if ( Array.isArray( parsed ) ) {
                    return parsed;
                }
            }
        } catch ( e ) {
            // Corrupt data — start fresh
        }
        return [];
    }

    /**
     * Rebuild the #wst-sql-history <select> from the current queryHistory array.
     */
    function rebuildHistoryDropdown() {
        var select = document.getElementById( 'wst-sql-history' );
        select.innerHTML = '';

        // Placeholder option
        var placeholder = document.createElement( 'option' );
        placeholder.value       = '';
        placeholder.textContent = '-- Select a previous query --';
        placeholder.disabled    = true;
        placeholder.selected    = true;
        select.appendChild( placeholder );

        queryHistory.forEach( function ( sql, idx ) {
            var option = document.createElement( 'option' );
            option.value = sql;
            // Truncate long queries for readability
            option.textContent = sql.length > DISPLAY_LIMIT
                ? sql.slice( 0, DISPLAY_LIMIT ) + '\u2026'
                : sql;
            select.appendChild( option );
        } );
    }

    /* -------------------------------------------------------------------------
     * Button / control setup
     * ---------------------------------------------------------------------- */

    /**
     * Attach event handlers to toolbar controls.
     */
    function setupButtons() {
        var execBtn    = document.getElementById( 'wst-sql-execute' );
        var historyBtn = document.getElementById( 'wst-sql-history-btn' );
        var historyEl  = document.getElementById( 'wst-sql-history' );

        // Execute button
        execBtn.addEventListener( 'click', function () {
            executeQuery();
        } );

        // Toggle history dropdown visibility
        historyBtn.addEventListener( 'click', function () {
            var isHidden = historyEl.style.display === 'none' || historyEl.style.display === '';
            historyEl.style.display = isHidden ? 'inline-block' : 'none';
        } );

        // Load selected query into the editor
        historyEl.addEventListener( 'change', function () {
            var selected = historyEl.value;
            if ( selected ) {
                editor.setValue( selected );
                editor.focus();
                // Collapse dropdown after selection
                historyEl.style.display = 'none';
            }
        } );
    }

    /* -------------------------------------------------------------------------
     * AJAX helper
     * ---------------------------------------------------------------------- */

    /**
     * Generic AJAX POST to wp-admin/admin-ajax.php.
     *
     * @param {string}   action   WordPress AJAX action name.
     * @param {object}   data     Key/value pairs to include in the request body.
     * @param {function} callback Called as callback( success, responseData, errorMessage ).
     */
    function ajax( action, data, callback ) {
        var form = new FormData();
        form.append( 'action', action );
        form.append( 'nonce',  wstDatabase.nonce );

        if ( data ) {
            Object.keys( data ).forEach( function ( key ) {
                form.append( key, data[ key ] );
            } );
        }

        var xhr = new XMLHttpRequest();
        xhr.open( 'POST', wstDatabase.ajaxurl, true );

        xhr.onload = function () {
            if ( xhr.status >= 200 && xhr.status < 300 ) {
                try {
                    var response = JSON.parse( xhr.responseText );
                    if ( response.success ) {
                        callback( true, response.data, null );
                    } else {
                        var msg = ( response.data && response.data.message )
                            ? response.data.message
                            : 'Server returned an error.';
                        callback( false, response.data, msg );
                    }
                } catch ( e ) {
                    callback( false, null, 'Invalid JSON response from server.' );
                }
            } else {
                callback( false, null, 'HTTP ' + xhr.status + ': ' + xhr.statusText );
            }
        };

        xhr.onerror = function () {
            callback( false, null, 'Network error — request could not be sent.' );
        };

        xhr.send( form );
    }

    /* -------------------------------------------------------------------------
     * Utilities
     * ---------------------------------------------------------------------- */

    /**
     * Escape a string for safe insertion into HTML.
     *
     * @param  {string} str
     * @returns {string}
     */
    function escapeHtml( str ) {
        return str
            .replace( /&/g,  '&amp;'  )
            .replace( /</g,  '&lt;'   )
            .replace( />/g,  '&gt;'   )
            .replace( /"/g,  '&quot;' )
            .replace( /'/g,  '&#039;' );
    }

    /* -------------------------------------------------------------------------
     * Entry point
     * ---------------------------------------------------------------------- */

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();
