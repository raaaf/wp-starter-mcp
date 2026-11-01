# Specification

Plugin version 1.0.0, theme contract version 1, schema version 1. Namespace `WpStarterMcp`, prefix `wpsmcp_`.

## State

The feature is on when option `wpsmcp_enabled` is `1`. While off, only these hooks register: the update channel, the privacy exporter and eraser, the setup page, the replace-original admin screens and snapshot protection, and the revocation hooks (theme switch, user deletion, password change, role change). While on, and not below the minimum version, the OAuth, REST, Bearer and ability hooks are added. Without a usable theme contract the endpoints below answer 404 (the MCP server is not created).

## Endpoints

| Path | Method | Purpose |
|---|---|---|
| `/.well-known/oauth-protected-resource[/<mcp path>]` | GET | Resource metadata: `resource`, `authorization_servers`, `bearer_methods_supported: [header]`, `scopes_supported` |
| `/.well-known/oauth-authorization-server`, `/.well-known/openid-configuration` | GET | Server metadata: PKCE `S256`, `token_endpoint_auth_methods_supported: [none]`, grants `authorization_code` and `refresh_token`, `client_id_metadata_document_supported: true`. No registration endpoint |
| `/wpsmcp-authorize` | GET, POST | Authorization endpoint (consent page, German) |
| `/wp-json/wpsmcp/v1/token` | POST | Token endpoint, form body |
| `/wp-json/wpsmcp/v1/mcp` | POST | MCP server `wp-starter-mcp` (HTTP transport of the adapter). The adapter's default server is switched off. Clients must send `MCP-Protocol-Version` |

Discovery and token responses carry `Cache-Control: no-store`. Discovery is not rate limited per address, because Anthropic fetches it from shared addresses. A 401 on the MCP route carries `WWW-Authenticate: Bearer resource_metadata="<url>"`.

## OAuth flow

1. A person with `edit_pages` presses "Verbindung starten" on Tools > Claude. This stores a connection intent in user meta; it is valid for 10 minutes and one connection.
2. Claude sends the user to `/wpsmcp-authorize` with `response_type=code`, `code_challenge_method=S256`, a 43 character challenge, `client_id`, `redirect_uri`, optional `resource`, `scope`, `state` (at most 512 characters).
3. The client map decides. Known clients:
   - `https://claude.ai/oauth/mcp-oauth-client-metadata` with exactly `https://claude.ai/api/mcp/auth_callback`.
   - `https://claude.ai/oauth/claude-code-client-metadata` with `http://localhost` or `http://127.0.0.1`, any port 1 to 65535 (or none), path `/callback`.
   The map is static; the metadata documents are not fetched. An unknown client or a redirect that does not match renders a local error page and never redirects.
4. `resource`, if present, must equal the MCP URL. Scopes must be within `mcp:read mcp:draft`; none given means both.
5. Not logged in: redirect to the login. Logged in without `edit_pages`: 403 page. No fresh intent: a page that points to Tools > Claude.
6. The consent page names the host of `client_id` and of the redirect (never the client's self-declared name), lists what Claude may and may not do, and has "Erlauben" and "Ablehnen". The nonce is bound to a hash of all request parameters. It does not list the scopes.
7. "Ablehnen" redirects with `error=access_denied`. "Erlauben" consumes the intent, stores a code, writes the audit event `connect`, emails the user and redirects with `code` and `state`.
8. The client posts the code with `code_verifier` (43 to 128 characters), `client_id`, `redirect_uri` to the token endpoint.

Authorization code: 60 seconds, single use, bound to client, redirect, challenge, user and resource. It is consumed before any other check, so a wrong verifier burns it. A second use revokes the whole family and logs `code_reuse`.

## Tokens

Opaque random strings, stored only as SHA-256 hashes. A code, an access token and a refresh token of one connection share a family ID.

| Token | Lifetime |
|---|---|
| Access | 1 hour |
| Refresh | 30 days, rotated on every use |

Refresh: consumed with an atomic update. The same refresh token again within 30 seconds is treated as a retry and gets a new pair. Later reuse revokes the family and logs `refresh_reuse`. After issuing, the family is checked again and revoked if it was revoked meanwhile. Code and refresh need the user to still have `edit_pages`.

Revocation (a family, a user, or all) sets `revoked_at` and deletes the user's `mcp_adapter_sessions` meta. Triggers: "Trennen", switching off, deactivation, theme switch, user deletion, password change (also by profile update), role change that removes `edit_pages`, reuse. On a network, user deletion and password change cover every site of the user.

Errors: 400 with `error` (`invalid_request`, `invalid_grant`, `invalid_target`, `unsupported_grant_type`); 429 with `{"error":"temporarily_unavailable"}` and `Retry-After: 60`. Limits per minute: 10 per code, 30 per token family, 600 per address.

## Bearer authentication

A Bearer token authenticates only when the request path is the MCP path and the effective REST route is the MCP route. A `rest_route` parameter (body, then query) with any other value, empty included, authenticates nothing. Authentication runs after application passwords and never before `after_setup_theme`. A Bearer request on the MCP path whose resolved route is another route is refused with 401. Bearer requests on other paths are left alone. Application passwords work on the MCP route as in core, with the user's own capabilities and without scope checks.

## Scopes

`mcp:read` for the six read tools, `mcp:draft` for the three write tools. A token must carry the scope; Claude's connectors request both. Every call also checks the contract, the user's capability and, for a target page, `edit_post` plus the contract's `is_readable`. The server permission is `edit_pages`.

## Tools

Ability names `wpsmcp/<name>`, listed in this order. Unknown input fields are rejected. Clients see them as `wpsmcp-<name>`, confirmed with Claude Code on 2026-11-01.

| Tool | Input | Refuses |
|---|---|---|
| `list-modules` | none | |
| `get-module-schema` | `module` | unknown or read-only module |
| `list-pages` | `search`, `page`, `per_page` (max 20) | Pages in status other than publish, draft, pending; password-protected; not readable by the contract; not editable by the user. Ordered by modified date, newest first |
| `get-page` | `id` | page not readable or not editable |
| `get-design-tokens` | none | |
| `search-media` | `search`, `per_page` (max 20) | users without `upload_files`. Images only |
| `create-draft-page` | `title`, `sections` | empty title; invalid sections; quota |
| `update-draft-page` | `id`, `sections`, optional `title` | any status but draft; empty `sections`; invalid sections; quota; failed snapshot |
| `create-draft-copy` | `id`, optional `title` | page not readable or not editable; quota |

`get-page` returns sections in the format the write tools accept. Rows Claude cannot write come back as `{"acf_fc_layout": ..., "keep": "<index>-<HMAC>"}` and are copied from the stored page on write. `update-draft-page` replaces all sections, keeps an unpinned revision snapshot first, and is annotated destructive. Writes return `edit_url` and `preview_url`. `create-draft-copy` copies sections, the featured image, page template, the contract's `copy_meta` and records the original and its modified time; the default title is "<title> (Kopie)".

Write quota: 60 per hour per token family (per user for application passwords), counted before validation on `create-draft-page`, `update-draft-page` and `create-draft-copy`.

No tool publishes, deletes or trashes.

## Replace original

Not a tool. In the admin, a draft copy gets a row action. The screen names the page, shows draft and live preview, removed sections and new external hosts, and warns when the original changed after the copy was made. It needs `edit_post` on both pages and `publish_pages`. The nonce binds the copy ID, the original's modified time and a digest of the copy's title and rows. On confirmation: the original's sections and copy meta go into a pinned revision, the sections and meta are written to the original, the live title stays, the copy goes to the trash, audit event `replace_original`. The old state is restored from the core revision screen. Pinned revisions cannot be deleted by WordPress until unpinned.

## Theme contract v1

The filter `wp_starter_mcp_contract` returns an array. Version 1 is never dropped. A missing key, a wrong type, an unknown version or a throwing callback denies and never fatals.

| Key | Type |
|---|---|
| `version` | `1` |
| `page_field`, `page_field_key` | non-empty strings (ACF field name and key of the sections field) |
| `writable` | list of layout names Claude may write |
| `copy_meta` | list of post meta keys copied with a page |
| `layouts` | callable returning ACF layout definitions |
| `is_readable` | callable `(int $postId): bool` |
| `after_write` | callable `(int $postId): void` |
| `is_allowed_embed_host` | callable `(string $hostOrUrl): bool` |
| `design_tokens` | callable returning the token description |
| `validate_sections` | callable `(array $sections): list<string>`, empty means valid |

## Validator

Default deny, in this order:

1. At most 40 sections and 256 KB of JSON.
2. Layout must be in `writable`. Unknown fields are refused. Choices, colours, anchors and field limits are enforced per type (text 5000 characters, rich text 100000, lists 200 rows, images 60).
3. Rich text: own `wp_kses` with the post allowlist minus form, script, style, frame and embed tags, also for users with `unfiltered_html`; shortcodes stripped except `[icon]`.
4. Links http or https only, up to 2048 characters, without control characters. Images must be image attachments. Media only from this site's uploads. No CSS `url()`.
5. Embed fields (`embed.url`, `map.embed_url`, `video.video_url`) through the contract's `is_allowed_embed_host`.
6. ACF validation (theme validators) on the rows converted to field keys.
7. The contract's `validate_sections`.

All problems are returned together (first 25 shown); nothing is saved on any error.

## Data stored

| Where | What | Removed |
|---|---|---|
| Table `<prefix>wpsmcp_tokens` | token hash, type, family, user, client ID, redirect, challenge, resource, scope, created, expires, used, revoked, last used | 1 day after expiry; with "Delete all data" at uninstall |
| Table `<prefix>wpsmcp_audit` | time, user ID, event, post ID, client host | after 90 days; eraser; uninstall |
| Options | `wpsmcp_enabled`, `wpsmcp_schema_version`, `wpsmcp_min_version`, `wpsmcp_delete_data` | uninstall |
| User meta | `wpsmcp_intent`; adapter `mcp_adapter_sessions` | intent on use or uninstall; sessions on revoke, eraser, uninstall |
| Post meta | `_wpsmcp_copy_of`, `_wpsmcp_copy_modified_gmt` on copies; `_wpsmcp_snapshot`, `_wpsmcp_pinned` on snapshot revisions | uninstall (the revisions themselves stay) |
| Transients | `wpsmcp_rl_*` rate counters | expire within 1 hour |
| Cron | `wpsmcp_prune` daily, `wpsmcp_manifest` daily | switch off, deactivation, uninstall |

Audit events: `enable`, `disable`, `connect`, `revoke`, `create_draft_page`, `update_draft_page`, `create_draft_copy`, `replace_original`, `code_reuse`, `refresh_reuse`. Warnings (`authorize_rejected`, `rate_limited`, reuse) also go to the PHP error log with family, user ID and client host only.

## Updates

Update checker against the GitHub releases of the repository, running under cron too. `auto_update_plugin` is true for this plugin only, only while the feature is on. Before install: `.sha256` must match, `.sig` must verify against a built-in Ed25519 public key, and the zip's `Version` header must be higher than the running version. `min-version.json` (`{"minimum": "x.y.z"}`) is fetched daily and when switching on; below it, endpoints are not registered and switching on is refused. An unreachable or malformed manifest changes nothing.
