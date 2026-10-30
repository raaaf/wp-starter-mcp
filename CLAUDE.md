# wp-starter-mcp

WordPress plugin (PHP 8.3+, WP 6.9+) that lets Claude work with wp-starter sites over MCP, with its own OAuth 2.1 server. Plan: `~/Developer/docs/plans/2026-10-30-wp-starter-mcp-integration.md` (Appendix A is binding). Namespace `WpStarterMcp\`, prefix `wpsmcp_`, text domain `wp-starter-mcp`, German UI strings in `__()`, code in English.

## Commands
- `composer install`
- `composer test` (all) or `vendor/bin/phpunit tests/Unit/TokenTest.php` (one file)
- `composer lint` (PHPStan level 8)
- `composer package` builds `dist/wp-starter-mcp-<version>.zip` plus `.sha256` (runtime files and a production autoloader only)

## Layout
- `src/`: McpPlugin (boot), Config, Contract (theme contract check), Schema, Lifecycle, Pruner, BearerGate, Server, Abilities, ModuleSchema, SectionValidator, AcfCheck, PageContent, Updates, Privacy, Uninstall, Setup, SetupPage, ReplaceOriginal, View, Intent, RateLimiter, Audit, Logger
- `stubs/acf.php`: ACF function signatures for PHPStan only
- `src/OAuth/`: ClientMap, TokenStore, Authorize, ConsentPage, Token, Discovery
- `tests/bootstrap.php`: minimal WordPress stubs and an in-memory `wpdb`; no real WordPress needed

## Critical rules
- Off by default. While off only the update channel, privacy tools, setup page and replace snapshots hook (no OAuth, REST, bearer or ability hook); `McpPluginTest` pins the exact list. Below the manifest minimum version nothing endpoint-like registers.
- Updates: checksum `.sha256` plus detached Ed25519 `.sig` are both mandatory (public keys in `Updates::RELEASE_PUBLIC_KEYS`, private key only on the owner's machine, `scripts/release-signing.php`). Unreachable manifest never fails closed.
- Revision meta must use `update_metadata('post', ...)`, not `update_post_meta()` (that redirects a revision ID to its parent).
- Never reference a theme class. The theme is reached only through the filter `wp_starter_mcp_contract` (`Contract`). No usable contract (missing, unknown version, invalid) fails closed: discovery, authorize, token and the MCP server 404 and `Contract::message()` says why.
- Writes are default deny (`SectionValidator`): only the contract's `writable` layouts, unknown fields refused, own kses for rich text (post allowlist minus form and code tags, shortcodes stripped except `[icon]`), http/https links, image attachments, embed hosts via the contract, then ACF validation (theme validators) on field-key rows, then the contract's `validate_sections`. Every rule is pinned by `SectionValidatorTest`.
- `get_field(..., false)` returns rows keyed by field key, not name; `PageContent` reads both. Rows Claude cannot write come back as `keep` references (index plus HMAC of the row) and are copied server-side.
- Abilities never publish, delete or trash. `update-draft-page` accepts `post_status = draft` only; live pages go through `create-draft-copy`. Embed fields are identified by layout and field name (`SectionValidator::EMBED_FIELDS`).
- Bearer tokens authenticate only on the MCP path, never before `after_setup_theme`, and `rest_pre_dispatch` refuses a Bearer request on the MCP path whose route differs (`?rest_route=` overrides the path). Bearer requests on other paths are left alone, they belong to other plugins such as JWT. `determineUser` has a reentrancy guard: `user_can()` inside it reaches `determine_current_user` again through `map_meta_cap` filters (Yoast) and segfaulted PHP. Pinned by `BearerGateTest`.
- Invalid client or redirect renders a local page and never redirects. The client map is static and holds only verified entries; the Claude Code entry is a TODO for step 11, do not invent it.
- Consent needs a login, `edit_pages` and a fresh per-user intent (`Intent`, created by the setup page, `tools.php?page=wpsmcp`, step 9).
- Tokens are stored as SHA-256 hashes. Consume with an atomic `UPDATE ... used_at IS NULL`. A refresh token reused after 30 s revokes its family and logs.
- Never log tokens, codes, verifiers or state. `Logger` writes allowlisted identifiers only.
- Revoking a token also deletes the user's `mcp_adapter_sessions` meta.
- The plugin defines `WPSMCP_VERSION` (agreed with the theme).
