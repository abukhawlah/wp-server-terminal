=== WP Server Terminal ===
Contributors: wpserverterminal
Tags: terminal, ssh, server, admin, file-manager, database, wp-cli
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full server access from WordPress admin: shell terminal, WP-CLI runner, file manager, and database manager.

== Description ==

WP Server Terminal gives authorized WordPress administrators SSH-like access to their server directly from the WordPress admin panel.

**Important:** This plugin is intended for VPS/dedicated server administrators only. It is NOT listed on WordPress.org due to its ability to execute arbitrary commands. Distribute and install with care.

= Features =

* **Shell Terminal** — Execute shell commands with output streaming, command history, and working directory tracking
* **WP-CLI Runner** — Run WP-CLI commands with a toggle button, no full path required
* **File Manager** — Browse, edit (CodeMirror syntax highlighting), upload, download, and delete files
* **Database Manager** — Execute SQL queries with a syntax-highlighted editor, results table, and export
* **Audit Log** — Every action is logged with user, IP, timestamp, and result
* **Security** — Custom capability, re-authentication, IP allowlist, command blocklist, rate limiting

= Requirements =

* Linux server (Windows not supported)
* PHP 7.4 or higher
* WordPress 6.0 or higher
* proc_open, exec, or shell_exec must be enabled in PHP
* HTTPS strongly recommended

= Security =

This plugin provides root-equivalent access (limited by the PHP process user). Only grant access to fully trusted administrators. All actions are logged.

== Installation ==

1. Download the plugin zip
2. Upload via Plugins > Add New > Upload Plugin, or extract to wp-content/plugins/wp-server-terminal/
3. Activate the plugin
4. Navigate to Server Terminal in the WordPress admin menu
5. Acknowledge the security warning on first use
6. Enter your WordPress password to authenticate each session

== Frequently Asked Questions ==

= Why does my shell command fail? =

Commands run as the PHP process user (www-data, apache, etc.), not root. You cannot run commands requiring elevated privileges unless sudo is configured for the PHP user.

= Does this work on shared hosting? =

Likely not. Shared hosts typically disable proc_open, exec, and shell_exec. This plugin is designed for VPS and dedicated servers.

= Is this on WordPress.org? =

No. WordPress.org plugin guidelines prohibit arbitrary code execution. Distribute via GitHub or a private channel.

= What happens when I uninstall? =

All plugin data (database tables, settings, options) is removed. Deactivation only removes capabilities and stops cron jobs without deleting data.

= Can interactive commands like vim or top run? =

No. The terminal does not support TTY/interactive programs. Commands requiring interactive input will hang. Use non-interactive alternatives.

== Changelog ==

= 1.0.0 =
* Initial release
* Shell terminal with xterm.js
* WP-CLI runner
* File manager with CodeMirror editor
* Database manager with SQL editor
* Audit logging
* Re-authentication and session management
* IP allowlist and command blocklist
