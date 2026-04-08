# WP Server Terminal

> Full server access from your WordPress admin — shell terminal, WP-CLI, file manager, database manager, and audit log.

**Author:** [Asif Amod](https://github.com/abukhawlah)  
**License:** GPL-2.0-or-later  
**Requires:** WordPress 6.0+, PHP 7.4+, Linux server

---

## What is this?

WP Server Terminal gives authorized WordPress administrators SSH-like access to their server directly from the WordPress admin panel — no SSH client, no cPanel, no FTP needed.

> **Important:** This plugin is for VPS and dedicated server administrators only. It is **not** listed on WordPress.org (arbitrary code execution violates their guidelines). Distribute via GitHub.

---

## Features

### Terminal
- Full shell terminal powered by [xterm.js](https://xtermjs.org/)
- Working directory tracking — `cd` works across commands
- Command history (up/down arrows, per session)
- ANSI color support
- WP-CLI mode toggle — run `wp` commands without typing the full path
- Ctrl+C to abort running commands

### WP-CLI Runner
- Auto-detects the `wp` binary (checks `PATH`, common locations, and `~/wp-cli.phar`)
- Appends `--path` and `--allow-root` automatically
- Structured output display

### File Manager
- Browse the full filesystem (within configured root)
- Syntax-highlighted editor powered by [CodeMirror 5](https://codemirror.net/) — supports PHP, JS, CSS, HTML, SQL, Shell, Python, JSON, YAML, and more
- Upload files via click or drag-and-drop
- Download any file
- Delete (moves to `.wst-trash/` — not permanent)
- Create files and folders
- Rename files
- `wp-config.php` is protected from deletion

### Database Manager
- SQL editor with syntax highlighting
- Browse all tables with row counts and sizes
- Execute any SQL query with results in a paginated table
- Multi-statement support (quote-aware semicolon splitting)
- Export tables as SQL or CSV
- Automatic `LIMIT 1000` on unbounded SELECT queries
- DDL blocking option (configurable in settings)

### Audit Log
- Every action logged: shell commands, WP-CLI, file reads/writes/deletes, SQL queries
- Stored in the database (not a file — tamper-resistant)
- Filterable by user, action type, severity, and date range
- Auto-cleanup via WP-Cron (configurable retention period)

### Security
- Custom capability: `manage_server_terminal` — separate from `manage_options`
- Re-authentication required every session (configurable timeout, default 30 min)
- IP allowlist support
- Command blocklist (patterns: `rm -rf /`, `shutdown`, `reboot`, etc.)
- Per-user rate limiting (60 requests/minute)
- Brute-force lockout after 5 failed re-auth attempts
- Non-dismissible HTTPS warning
- First-run security acknowledgment
- All AJAX endpoints: nonce + capability + re-auth + rate limit
- Security headers on all responses (`X-Content-Type-Options`, `X-Frame-Options`)
- Backups stored outside the webroot (`.wst-backups/` with `.htaccess` protection)

---

## Screenshots

> Terminal, File Manager, Database Manager, Audit Log, Settings

*(Add screenshots to `/screenshots/` and link them here)*

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 7.4 |
| OS | Linux (Windows not supported) |
| PHP functions | `proc_open` preferred; `exec` or `shell_exec` as fallback |
| HTTPS | Strongly recommended (plugin warns if absent) |

> **Shared hosting:** Commands run as the PHP process user (`www-data`, `apache`, etc.). If your host disables `proc_open`, `exec`, and `shell_exec`, the shell terminal will not work. The file manager and database manager will still function.

---

## Installation

### Option 1 — Upload ZIP

1. Download the latest release ZIP from [Releases](https://github.com/abukhawlah/wp-server-terminal/releases)
2. Go to **WP Admin → Plugins → Add New → Upload Plugin**
3. Upload the ZIP and activate

### Option 2 — Clone directly into plugins folder

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/abukhawlah/wp-server-terminal.git
```

Then activate via **WP Admin → Plugins**.

### Option 3 — WP-CLI

```bash
wp plugin install https://github.com/abukhawlah/wp-server-terminal/archive/refs/heads/main.zip --activate
```

---

## First Use

1. After activation, navigate to **Server Terminal** in the WordPress admin sidebar
2. Read and acknowledge the security warning
3. Enter your WordPress password to start a session
4. Start typing commands

---

## Configuration

Go to **Server Terminal → Settings** to configure:

| Setting | Default | Description |
|---|---|---|
| IP Allowlist | *(empty = all)* | Restrict access to specific IP addresses |
| Session Timeout | 1800s (30 min) | Re-auth session duration |
| Command Timeout | 30s | Max time per shell command |
| Max Output Size | 1 MB | Truncate output beyond this size |
| Blocked Commands | See defaults | Patterns that cannot be executed |
| Block DDL | Off | Block DROP/ALTER/TRUNCATE/CREATE in DB manager |
| Enable WP-CLI | On | Show WP-CLI runner |
| Enable File Manager | On | Show file manager |
| Enable Database Manager | On | Show database manager |
| Log Retention | 90 days | Audit log auto-cleanup |
| Allowed Filesystem Roots | WordPress root | Filesystem root for the file manager |

### Default Blocked Commands

```
rm -rf /
mkfs
dd if=
:(){ :|:& };:
shutdown
reboot
halt
poweroff
init 0
init 6
```

---

## Granting Access to Other Users

By default, only WordPress **Administrators** get the `manage_server_terminal` capability.

To grant access to a specific user via WP-CLI:

```bash
wp user add-cap <user_id> manage_server_terminal
```

To revoke:

```bash
wp user remove-cap <user_id> manage_server_terminal
```

---

## Security Notes

This plugin is a **powerful tool**. Please read before using in production:

1. **Commands run as the PHP user** (`www-data`, `apache`, `nginx`) — not root. You cannot escalate privileges unless `sudo` is explicitly configured for the PHP user (not recommended).

2. **Not for shared hosting** — designed for VPS/dedicated servers where you have full control.

3. **Use HTTPS** — all commands and output travel over HTTP. Without TLS, credentials and command output are exposed in plaintext.

4. **Audit every user** — check the Audit Log regularly. Every action is logged with user, IP, timestamp, and result.

5. **Restrict with IP allowlist** — if you always access WP admin from a fixed IP, add it to the allowlist in Settings.

6. **Interactive commands don't work** — `vim`, `top`, `nano`, `htop`, `less`, and other TUI programs require a real TTY. They will hang. Use non-interactive alternatives (e.g., `cat` instead of `less`).

7. **Binary output** — commands that produce binary output (e.g., `cat /bin/ls`) will be flagged and not displayed in the terminal.

---

## FAQ

**Q: Why is this not on WordPress.org?**  
A: WordPress.org plugin guidelines prohibit plugins that execute arbitrary code on the server. This plugin does exactly that by design. Distribute via GitHub.

**Q: Can I run `sudo` commands?**  
A: Only if your server's sudoers file allows the PHP process user to run specific commands without a password. Configuring this is outside the scope of this plugin.

**Q: What happens if I delete `wp-config.php` by accident?**  
A: The file manager hard-blocks deletion of `wp-config.php`. You cannot delete it through this plugin.

**Q: Does it work on Windows?**  
A: No. Shell commands are Linux-only. The plugin will show an error on non-Linux servers.

**Q: What happens when I uninstall?**  
A: All plugin data is removed — database tables, options, and transients. Deactivation (without uninstall) only stops capabilities and cron jobs; data is preserved.

**Q: The terminal shows "No command execution functions available" — what now?**  
A: Your host has disabled `proc_open`, `exec`, and `shell_exec` in `php.ini` via `disable_functions`. Contact your host or switch to a VPS where you control PHP configuration.

---

## File Structure

```
wp-server-terminal/
├── wp-server-terminal.php          # Main plugin bootstrap
├── uninstall.php                   # Cleanup on uninstall
├── readme.txt                      # WordPress.org format readme
├── README.md                       # This file
├── includes/
│   ├── class-activator.php         # Activation: DB tables, caps, cron
│   ├── class-deactivator.php       # Deactivation cleanup
│   ├── class-capabilities.php      # Custom capability management
│   ├── class-admin-menu.php        # Admin menu, page routing, asset enqueueing
│   ├── class-audit-log.php         # Audit logging (write, query, cleanup)
│   ├── class-settings.php          # WordPress Settings API integration
│   ├── class-security.php          # Nonce, cap, re-auth, IP, rate limiting
│   ├── class-terminal.php          # Shell command execution (proc_open)
│   ├── class-wpcli.php             # WP-CLI runner
│   ├── class-file-manager.php      # Filesystem operations
│   ├── class-database-manager.php  # SQL execution and DB introspection
│   └── class-ajax-handler.php      # Central AJAX router (12 endpoints)
├── admin/
│   ├── css/admin.css               # All plugin styles
│   ├── js/
│   │   ├── terminal.js             # xterm.js integration
│   │   ├── file-manager.js         # File manager UI
│   │   └── database.js             # SQL editor + results
│   └── pages/
│       ├── terminal.php            # Terminal page template
│       ├── file-manager.php        # File manager page template
│       ├── database.php            # Database page template
│       ├── audit-log.php           # Audit log (WP_List_Table)
│       ├── settings.php            # Settings page
│       └── first-run-notice.php    # Security acknowledgment
└── assets/
    ├── xterm/                      # xterm.js 5.3.0 (MIT)
    └── codemirror/                 # CodeMirror 5.65.16 (MIT)
```

---

## Third-Party Libraries

| Library | Version | License | Used for |
|---|---|---|---|
| [xterm.js](https://xtermjs.org/) | 5.3.0 | MIT | Terminal emulator |
| [xterm-addon-fit](https://github.com/xtermjs/xterm.js/tree/master/addons/addon-fit) | 0.8.0 | MIT | Terminal auto-resize |
| [CodeMirror](https://codemirror.net/) | 5.65.16 | MIT | Code editor (file manager + SQL) |

All libraries are bundled within the plugin. No external CDN requests are made.

---

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature`
3. Commit your changes
4. Open a pull request

### Development Setup

```bash
git clone https://github.com/abukhawlah/wp-server-terminal.git
cd wp-server-terminal
# No build step required — plain PHP and vanilla JS
# Drop into your wp-content/plugins/ directory and activate
```

### Coding Standards

- PHP: [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- JavaScript: Plain ES5-compatible IIFE (no build step, no bundler)
- All output must be escaped (`esc_html`, `esc_attr`, `esc_url`)
- All inputs must be sanitized (`sanitize_text_field`, `absint`, `wp_unslash`)
- Every AJAX endpoint: nonce + capability + re-auth

---

## Roadmap

- [ ] PTY support for interactive commands (vim, top, etc.)
- [ ] SSH key management
- [ ] Multi-tab terminal
- [ ] File permission editor (chmod UI)
- [ ] Dark/light theme toggle for the admin UI
- [ ] Import SQL files in the database manager
- [ ] Cron job viewer/editor UI
- [ ] WordPress multisite network admin support improvements

---

## Changelog

### 1.0.0 — Initial Release

- Shell terminal with xterm.js, CWD tracking, command history
- WP-CLI runner with auto-detection
- File manager with CodeMirror editor, upload/download, drag-and-drop
- Database manager with SQL editor, table browser, export
- Audit log with WP_List_Table, filters, auto-cleanup
- Re-authentication sessions with brute-force lockout
- IP allowlist, command blocklist, rate limiting
- First-run security acknowledgment
- Health check notices (executor availability, WP-CLI, HTTPS, Linux check)

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) for full text.

---

*Built by [Asif Amod](https://github.com/abukhawlah)*
