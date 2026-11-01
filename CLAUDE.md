# wp-starter-mcp

WordPress plugin (PHP 8.3+, WP 6.9+) that lets Claude work with wp-starter sites over MCP, with its own OAuth 2.1 server. Plan: `~/Developer/docs/plans/2026-10-30-wp-starter-mcp-integration.md` (Appendix A is binding). Namespace `WpStarterMcp\`, prefix `wpsmcp_`, text domain `wp-starter-mcp`, German UI strings in `__()`, code in English.

## Commands
- `composer install`
- `composer test` (all) or `vendor/bin/phpunit tests/Unit/TokenTest.php` (one file)
- `composer lint` (PHPStan level 8)
- `composer package` builds `dist/wp-starter-mcp-<version>.zip` plus `.sha256` (runtime files and a production autoloader only)

## Layout
- `src/`: McpPlugin (boot), Config, Schema, Lifecycle, Pruner, BearerGate, Server, Updates (boundary only, step 8), Intent, RateLimiter, Audit, Logger
- `src/OAuth/`: ClientMap, TokenStore, Authorize, ConsentPage, Token, Discovery
- `tests/bootstrap.php`: minimal WordPress stubs and an in-memory `wpdb`; no real WordPress needed

## Critical rules
- Off by default. While off only `Updates` hooks; `McpPlugin` test pins it (`McpPluginTest`).
- Never reference a theme class. The theme is reached only through its contract (later step).
- Bearer tokens authenticate only on the MCP path, never before `after_setup_theme`, and `rest_pre_dispatch` refuses a Bearer request on the MCP path whose route differs (`?rest_route=` overrides the path). Bearer requests on other paths are left alone, they belong to other plugins such as JWT. `determineUser` has a reentrancy guard: `user_can()` inside it reaches `determine_current_user` again through `map_meta_cap` filters (Yoast) and segfaulted PHP. Pinned by `BearerGateTest`.
- Invalid client or redirect renders a local page and never redirects. The client map is static and holds only verified entries; the Claude Code entry is a TODO for step 11, do not invent it.
- Consent needs a login, `edit_pages` and a fresh per-user intent (`Intent`, created by the setup page, `tools.php?page=wpsmcp`, step 9).
- Tokens are stored as SHA-256 hashes. Consume with an atomic `UPDATE ... used_at IS NULL`. A refresh token reused after 30 s revokes its family and logs.
- Never log tokens, codes, verifiers or state. `Logger` writes allowlisted identifiers only.
- Revoking a token also deletes the user's `mcp_adapter_sessions` meta.
- The plugin defines `WPSMCP_VERSION` (agreed with the theme).
