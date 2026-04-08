/* global Terminal, FitAddon, wstTerminal */
/**
 * WP Server Terminal — Terminal UI
 *
 * Drives the xterm.js-based terminal page.
 * Loaded after xterm.js and xterm-addon-fit.js.
 * WordPress localizes `wstTerminal` with: ajaxurl, nonce, plugin_url, initial_cwd.
 */
(function () {
    'use strict';

    // ---- State ----------------------------------------------------------------

    var term         = null;
    var fitAddon     = null;
    var currentLine  = '';
    var commandHistory = [];
    var historyIndex = -1;
    var currentCwd   = (wstTerminal && wstTerminal.initial_cwd) ? wstTerminal.initial_cwd : '';
    var wpcliMode    = false;
    var isExecuting  = false;

    // ---- Init -----------------------------------------------------------------

    /**
     * Entry point — called on DOMContentLoaded.
     */
    function init() {
        var container = document.getElementById('wst-terminal-container');
        if (!container) {
            return;
        }

        loadHistory();
        setupTerminal();
        setupButtons();

        // Welcome banner
        term.write('\x1b[1;32mWP Server Terminal\x1b[0m — type a command and press Enter.\r\n');
        term.write('Use \x1b[36mCtrl+C\x1b[0m to interrupt, \x1b[36mCtrl+L\x1b[0m to clear.\r\n\r\n');
        showPrompt();
    }

    // ---- Terminal setup -------------------------------------------------------

    /**
     * Instantiate xterm.js, load FitAddon, open in DOM, wire event handlers.
     */
    function setupTerminal() {
        term = new Terminal({
            cursorBlink: true,
            fontFamily: "'Menlo', 'Monaco', 'Courier New', monospace",
            fontSize: 14,
            theme: {
                background:   '#1e1e1e',
                foreground:   '#d4d4d4',
                cursor:       '#d4d4d4',
                selection:    'rgba(255,255,255,0.3)',
                black:        '#1e1e1e',
                red:          '#f44747',
                green:        '#4caf50',
                yellow:       '#dcdcaa',
                blue:         '#569cd6',
                magenta:      '#c678dd',
                cyan:         '#56b6c2',
                white:        '#d4d4d4',
                brightBlack:  '#808080',
                brightWhite:  '#ffffff'
            },
            scrollback: 1000,
            convertEol: true
        });

        fitAddon = new FitAddon.FitAddon();
        term.loadAddon(fitAddon);
        term.open(document.getElementById('wst-terminal-container'));
        fitAddon.fit();

        term.onData(handleData);

        window.addEventListener('resize', function () {
            fitAddon.fit();
        });
    }

    // ---- Input handling -------------------------------------------------------

    /**
     * Erase the characters the user has typed on the current line,
     * optionally replacing them with new content.
     *
     * @param {string} [replacement='']
     */
    function clearCurrentInput(replacement) {
        // Move back over every typed character and overwrite with space.
        for (var i = 0; i < currentLine.length; i++) {
            term.write('\b \b');
        }
        currentLine = (replacement !== undefined) ? replacement : '';
        if (currentLine) {
            term.write(currentLine);
        }
    }

    /**
     * Handle raw data from the terminal (keyboard input / paste).
     *
     * @param {string} data
     */
    function handleData(data) {
        // While a command is executing, only Ctrl+C (abort) is acted upon.
        if (isExecuting) {
            if (data === '\x03') {
                sendCommand('', function (response) {
                    // Best-effort abort — ignore the response body.
                    void response;
                }, true);
            }
            return;
        }

        switch (data) {
            // Enter
            case '\r':
                if (currentLine.trim()) {
                    executeCommand(currentLine.trim());
                } else {
                    term.write('\r\n');
                    showPrompt();
                }
                currentLine = '';
                break;

            // Backspace (DEL)
            case '\x7f':
                if (currentLine.length > 0) {
                    currentLine = currentLine.slice(0, -1);
                    term.write('\b \b');
                }
                break;

            // Up arrow — navigate history backward
            case '\x1b[A':
                if (commandHistory.length === 0) {
                    break;
                }
                if (historyIndex === -1) {
                    historyIndex = commandHistory.length - 1;
                } else if (historyIndex > 0) {
                    historyIndex--;
                }
                clearCurrentInput(commandHistory[historyIndex]);
                break;

            // Down arrow — navigate history forward
            case '\x1b[B':
                if (historyIndex === -1) {
                    break;
                }
                if (historyIndex < commandHistory.length - 1) {
                    historyIndex++;
                    clearCurrentInput(commandHistory[historyIndex]);
                } else {
                    historyIndex = -1;
                    clearCurrentInput('');
                }
                break;

            // Ctrl+C — cancel current input
            case '\x03':
                term.write('^C\r\n');
                currentLine  = '';
                historyIndex = -1;
                showPrompt();
                break;

            // Ctrl+L — clear screen
            case '\x0c':
                term.clear();
                showPrompt();
                break;

            default:
                // Printable characters (including multi-byte paste sequences).
                // Filter out remaining escape sequences to avoid corrupting the line.
                if (data >= ' ' || data.charCodeAt(0) > 127) {
                    currentLine += data;
                    term.write(data);
                }
                break;
        }
    }

    // ---- Command execution ----------------------------------------------------

    /**
     * Run a command: update history, send to server, render output.
     *
     * @param {string} command  The trimmed command string typed by the user.
     */
    function executeCommand(command) {
        saveHistory(command);
        term.write('\r\n');

        // Handle 'clear' locally — no round-trip needed.
        if (command === 'clear') {
            term.clear();
            showPrompt();
            return;
        }

        // In WP-CLI mode, prepend 'wp ' to every command.
        var fullCommand = wpcliMode ? ('wp ' + command) : command;

        setExecuting(true);

        sendCommand(fullCommand, function (response) {
            setExecuting(false);

            if (response.success) {
                var data = response.data;

                if (data.stdout) {
                    term.write(data.stdout.replace(/\n/g, '\r\n'));
                }

                if (data.stderr) {
                    term.write('\x1b[31m' + data.stderr.replace(/\n/g, '\r\n') + '\x1b[0m');
                }

                if (data.truncated) {
                    term.write('\r\n\x1b[33m[Output truncated]\x1b[0m\r\n');
                }

                if (data.new_cwd) {
                    updateCwd(data.new_cwd);
                }
            } else {
                var msg = (response.data && response.data.message)
                    ? response.data.message
                    : 'Request failed';

                if (response.data && response.data.code === 'reauth_required') {
                    term.write('\x1b[33m[Session expired. Please re-authenticate.]\x1b[0m\r\n');
                    window.location.reload();
                    return; // showPrompt() would be irrelevant after reload
                } else {
                    term.write('\x1b[31m[Error: ' + msg + ']\x1b[0m\r\n');
                }
            }

            showPrompt();
        });
    }

    // ---- AJAX -----------------------------------------------------------------

    /**
     * Send a command (or abort signal) to the WordPress AJAX endpoint.
     *
     * @param {string}   command   The command to execute (empty string for abort).
     * @param {Function} callback  Called with the parsed JSON response object.
     * @param {boolean}  [abort]   When true, sends abort=1 instead of a command.
     */
    function sendCommand(command, callback, abort) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', wstTerminal.ajaxurl, true);
        xhr.timeout = 35000; // 35 seconds

        var formData = new FormData();
        formData.append('action',  'wst_exec');
        formData.append('nonce',   wstTerminal.nonce);
        formData.append('command', command);
        formData.append('wpcli',   wpcliMode ? '1' : '0');

        if (abort) {
            formData.append('abort', '1');
        }

        xhr.onload = function () {
            var parsed;
            try {
                parsed = JSON.parse(xhr.responseText);
            } catch (e) {
                parsed = { success: false, data: { message: 'Invalid server response' } };
            }
            callback(parsed);
        };

        xhr.onerror = function () {
            callback({ success: false, data: { message: 'Network error' } });
        };

        xhr.ontimeout = function () {
            callback({ success: false, data: { message: 'Request timed out' } });
        };

        xhr.send(formData);
    }

    // ---- UI helpers -----------------------------------------------------------

    /**
     * Update the displayed current working directory.
     *
     * @param {string} cwd  New path returned by the server.
     */
    function updateCwd(cwd) {
        currentCwd = cwd;
        var el = document.getElementById('wst-cwd');
        if (el) {
            el.textContent = cwd;
        }
    }

    /**
     * Write the shell prompt to the terminal and reset the history cursor.
     */
    function showPrompt() {
        var modePrefix = wpcliMode ? '\x1b[35m[WP-CLI] \x1b[0m' : '';
        var promptStr  = modePrefix + '\x1b[32m' + '\x1b[0m\x1b[36m' + currentCwd + '\x1b[0m $ ';
        term.write(promptStr);
        historyIndex = -1;
    }

    /**
     * Toggle the "executing" visual state (status indicator + input lock).
     *
     * @param {boolean} state  true = a command is running; false = idle.
     */
    function setExecuting(state) {
        isExecuting = state;
        var statusEl = document.getElementById('wst-session-status');
        if (!statusEl) {
            return;
        }
        if (state) {
            statusEl.textContent = 'Running...';
            statusEl.style.color = '#f0a500'; // orange
        } else {
            statusEl.textContent = 'Session Active';
            statusEl.style.color = '#4caf50'; // green
        }
    }

    // ---- Buttons --------------------------------------------------------------

    /**
     * Wire up the WP-CLI toggle and Clear buttons.
     */
    function setupButtons() {
        // WP-CLI mode toggle
        var wpcliBtn = document.getElementById('wst-wpcli-toggle');
        if (wpcliBtn) {
            wpcliBtn.addEventListener('click', function () {
                wpcliMode = !wpcliMode;
                wpcliBtn.textContent = wpcliMode ? 'WP-CLI: ON' : 'WP-CLI: OFF';
                if (wpcliMode) {
                    wpcliBtn.classList.add('active');
                } else {
                    wpcliBtn.classList.remove('active');
                }
                // Re-render the prompt to reflect the mode change.
                // Erase any pending input first.
                clearCurrentInput('');
                term.write('\r\n');
                showPrompt();
            });
        }

        // Clear screen button
        var clearBtn = document.getElementById('wst-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                term.clear();
                showPrompt();
            });
        }
    }

    // ---- History --------------------------------------------------------------

    /**
     * Append a command to the in-memory array and persist to sessionStorage.
     * Skips empty strings and consecutive duplicates; caps list at 100 entries.
     *
     * @param {string} cmd
     */
    function saveHistory(cmd) {
        if (!cmd) {
            return;
        }

        // Avoid consecutive duplicates.
        if (commandHistory.length > 0 && commandHistory[commandHistory.length - 1] === cmd) {
            return;
        }

        commandHistory.push(cmd);

        // Keep only the last 100 entries.
        if (commandHistory.length > 100) {
            commandHistory = commandHistory.slice(commandHistory.length - 100);
        }

        try {
            sessionStorage.setItem('wst_cmd_history', JSON.stringify(commandHistory));
        } catch (e) {
            // sessionStorage quota exceeded or unavailable — not critical.
        }
    }

    /**
     * Restore command history from sessionStorage on page load.
     */
    function loadHistory() {
        try {
            var stored = sessionStorage.getItem('wst_cmd_history');
            if (stored) {
                var parsed = JSON.parse(stored);
                if (Array.isArray(parsed)) {
                    // Guard against oversized stored arrays.
                    commandHistory = parsed.slice(-100);
                }
            }
        } catch (e) {
            // Corrupt storage or unavailable — start with empty history.
            commandHistory = [];
        }
    }

    // ---- Bootstrap ------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', init);

}());
