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
- Off by default. While off only the update channel, privacy tools, setup page, replace snapshots and the revocation hooks hook (no OAuth, REST, bearer or ability hook); `McpPluginTest` pins the exact list. Below the manifest minimum version nothing endpoint-like registers.
- Updates: checksum `.sha256` plus detached Ed25519 `.sig` are both mandatory (public keys in `Updates::RELEASE_PUBLIC_KEYS`, private key only on the owner's machine, `scripts/release-signing.php`). The signature does not cover the version, so the zip's own `Version` header must be above `WPSMCP_VERSION`; the plugin file is matched by `plugin_basename(WPSMCP_FILE)`, never by a hard-coded folder. Unreachable manifest never fails closed.
- Revision meta must use `update_metadata('post', ...)`, not `update_post_meta()` (that redirects a revision ID to its parent).
- Never reference a theme class. The theme is reached only through the filter `wp_starter_mcp_contract` (`Contract`). No usable contract (missing, unknown version, invalid) fails closed: discovery, authorize, token and the MCP server 404 and `Contract::message()` says why.
- Writes are default deny (`SectionValidator`): only the contract's `writable` layouts, unknown fields refused, own kses for rich text (post allowlist minus form and code tags, shortcodes stripped until stable except `[icon]`, media only from this site's uploads, no CSS `url()`), http/https links, image attachments, embed hosts via the contract, then ACF validation (theme validators) on field-key rows, then the contract's `validate_sections`. Every rule is pinned by `SectionValidatorTest`.
- `get_field(..., false)` returns rows keyed by field key, not name; `PageContent` reads both. Rows Claude cannot write come back as `keep` references (index plus HMAC of the row) and are copied server-side.
- Abilities never publish, delete or trash. `update-draft-page` accepts `post_status = draft` only, refuses an empty `sections`, is annotated destructive and stores an unpinned snapshot (`ReplaceOriginal::snapshot`) before it overwrites; live pages go through `create-draft-copy`. Replace confirmation: the nonce binds the original's modified time and a digest of the copy's title and rows; new hosts include `//host`, `/\host` and entity-encoded links. Embed fields are identified by layout and field name (`SectionValidator::EMBED_FIELDS`).
- Bearer tokens authenticate only on the MCP path and only when the effective REST route is the MCP route (`rest_route` from body or query, any other value, empty included, means no authentication; plain permalinks require it, because an empty one skips REST and renders pages as the token's user), never before `after_setup_theme`, and `rest_pre_dispatch` refuses a Bearer request on the MCP path whose route differs (`?rest_route=` overrides the path). Bearer requests on other paths are left alone, they belong to other plugins such as JWT. `determineUser` has a reentrancy guard: `user_can()` inside it reaches `determine_current_user` again through `map_meta_cap` filters (Yoast) and segfaulted PHP. Pinned by `BearerGateTest`.
- Invalid client or redirect renders a local page and never redirects. The client map is static and holds only verified entries; the Claude Code entry is a TODO for step 11, do not invent it.
- Consent needs a login, `edit_pages` and a fresh per-user intent (`Intent`, created by the setup page, `tools.php?page=wpsmcp`, step 9).
- Tokens are stored as SHA-256 hashes. Consume with an atomic `UPDATE ... used_at IS NULL AND revoked_at IS NULL`; `issuePair` rechecks the family after inserting and revokes the new pair if it was revoked meanwhile. A refresh token reused after 30 s revokes its family and logs.
- Never log tokens, codes, verifiers or state. `Logger` writes allowlisted identifiers only.
- Password change, user deletion, role loss (`set_user_role`) and theme switch revoke through `Lifecycle`, registered whenever the plugin loads (a site without tables is skipped); on a network a password change or user deletion covers every site of `get_blogs_of_user()`.
- Revoking a token also deletes the user's `mcp_adapter_sessions` meta.
- The plugin defines `WPSMCP_VERSION` (agreed with the theme).
