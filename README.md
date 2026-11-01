# WP Starter MCP

WordPress plugin that lets Claude build and edit draft pages on a wp-starter site over MCP, signed in with OAuth. Claude never publishes, deletes or trashes anything. The feature is off until an administrator switches it on.

## Requirements

- WordPress 6.9 or newer, PHP 8.3 or newer, HTTPS.
- A theme that answers the `wp_starter_mcp_contract` filter with contract version 1 (wp-starter does; a fork needs that one theme file). Without it the plugin does nothing and the setup page says why.
- The `mcp-adapter` plugin (declared in the plugin header as a required plugin).
- Advanced Custom Fields (ACF).

## Install

1. Install and activate `mcp-adapter` and ACF.
2. Upload `wp-starter-mcp-<version>.zip` under Plugins > Add New Plugin > Upload, activate it.
3. Open Tools > Claude as an administrator and read the site check. Fix every item marked "Nicht in Ordnung".
4. Tick both acknowledgements (content that Claude reads goes to Anthropic; the plugin updates itself while on) and press "Einschalten".

## Connect

Every person connects from Tools > Claude: press "Verbindung starten" (valid for 10 minutes, one connection), copy the URL, then add it in Claude. The consent page needs a WordPress login with the right to edit pages. An email tells the user about each new connection.

- **Claude on the web and on the phone:** Settings > Connectors > add a custom connector, paste the URL, sign in to WordPress and press "Erlauben". On Team and Enterprise plans the account owner adds the connector. These connectors only support OAuth. An application password does not work there.
- **Claude Code, OAuth:** `claude mcp add --transport http <name> <url>`, then sign in through `/mcp`.
- **Claude Code, application password:** add the server with an `Authorization: Basic` header built from the user name and an application password. The connection acts with the full rights of that user, is not listed on the setup page and cannot be revoked there; delete the application password in the user profile instead. It is also not limited by the two scopes below.

Revoke a connection under Tools > Claude with "Trennen". Switching the feature off, deactivating the plugin, a password change, deleting the user, losing the right to edit pages and switching the theme all revoke every affected connection.

## Tools

The ability names are `wpsmcp/<name>`.

| Tool | What it does |
|---|---|
| `list-modules` | Module (section type) names and labels |
| `get-module-schema` | Fields of one module |
| `list-pages` | Pages you may edit, 20 per call at most |
| `get-page` | One page with its sections |
| `get-design-tokens` | Base colours and token file locations |
| `search-media` | Search images in the media library |
| `create-draft-page` | New draft from modules |
| `update-draft-page` | Replace all sections of an existing draft |
| `create-draft-copy` | Copy a live or draft page into a new draft |

Details and refusals are in [docs/SPEC.md](docs/SPEC.md).

## What Claude cannot do

- Publish, delete or trash a page, or change a live page directly. A live page changes only when a person publishes a draft copy or presses "Replace original" in the admin, after a confirmation screen.
- Upload files, edit users, settings, plugins, themes or credentials.
- Use modules the theme did not mark writable (forms, newsletter, downloads), scripts, form tags, CSS `url()`, shortcodes (except `[icon]`), links other than http and https, images from outside this site, or embeds from hosts the theme does not allow.
- Read private, password-protected or member-gated pages, or pages the signed-in user may not edit.
- More than 60 writes per hour per connection.

## Updates

The plugin updates itself from GitHub releases while the feature is on, and offers updates while it is off. An update is installed only if its `.sha256` and its Ed25519 `.sig` both match and its version is higher than the installed one. A published minimum version switches an outdated plugin off until it is updated. Procedure: [SECURITY.md](SECURITY.md).

## Uninstall

Deactivating revokes all connections and switches the feature off. Deleting the plugin keeps its tables and settings unless "Beim Löschen des Plugins alle Daten entfernen" is ticked under Tools > Claude (Daten). Data details: [docs/DATA-PROTECTION.md](docs/DATA-PROTECTION.md).

## Development

```
composer install
composer test
composer lint
composer package
```
