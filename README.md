# WP Starter MCP

WordPress plugin that connects Claude to a wp-starter site over MCP with an OAuth sign-in. Draft-only. Off until an administrator switches it on.

Current state: plugin skeleton, schema, pruning cron, token revocation (theme switch, deactivation, user deletion, password change) and the OAuth server (discovery, authorize with intent check and German consent page, token endpoint with refresh rotation). The MCP server is registered without abilities. Setup page, abilities, module validation and the update channel follow in later steps.

Requires WordPress 6.9+, PHP 8.3+ and the `mcp-adapter` plugin. Pretty permalinks are needed.

## Development

```
composer install
composer test
composer lint
composer package
```
