# Security

## Reporting a problem

Email a@rafaelalex.de with what you found, the plugin version and steps to reproduce. Please do not open a public issue for a vulnerability. Expect an answer within a few days. Do not test against sites you do not own.

## What the plugin protects against

- **Consent phishing:** a connection needs a login, the right to edit pages and a fresh intent that the person created on Tools > Claude. The consent page names the hosts of client ID and redirect, not the client's own name, and every new connection sends an email.
- **Rogue clients and open redirects:** a static client map with exact redirects; no registration endpoint, no metadata fetch. Invalid requests show a local page.
- **Code and token theft:** PKCE S256, 60 second single-use codes, hashed tokens, 1 hour access tokens, rotating refresh tokens, family revocation on reuse. Tokens, codes, verifiers and state are never logged.
- **Token use outside the MCP route:** Bearer tokens authenticate only on the MCP path and route, so they cannot read pages or REST endpoints elsewhere.
- **Brute force:** rate limits keyed by code and token family, with a high per-address cap.
- **Harmful content from Claude:** writes are default deny (allowlisted layouts and fields, own HTML filter, no scripts or forms, no foreign media, checked embed hosts). Nothing is published, deleted or trashed. A person reviews every draft.
- **Loss of work:** a snapshot revision is kept before a draft is overwritten and before a live page is replaced.
- **Stale access:** password change, user deletion, role loss, theme switch, switching off and deactivation revoke connections.
- **Malicious updates:** a package is installed only with a matching `.sha256`, a valid Ed25519 `.sig` and a higher version. A minimum-version manifest switches old versions off.

Not protected against: a person with `edit_pages` who connects Claude and approves what it drafts; a compromised WordPress administrator account; a compromised signing key. An application password used with Claude Code acts with the full rights of its user.

## Release procedure

Releases are built and signed by the maintainer, on the maintainer's own machine. The private key never goes into the repository, CI, the build or a chat.

1. Raise `Version` in `wp-starter-mcp.php` and the constant `WPSMCP_VERSION` to the same number. Run `composer test` and `composer lint`.
2. Run `composer package`. It writes `dist/wp-starter-mcp-<version>.zip` and `.sha256` and prints the next commands.
3. Sign the zip on the machine that holds the private key: `php scripts/release-signing.php sign dist/wp-starter-mcp-<version>.zip <private-key-file>`. This writes `<zip>.sig`. Check it with `php scripts/release-signing.php verify <zip> <public-key>`.
4. Create the GitHub release `v<version>` and upload the zip, `.sha256` and `.sig`. Without all three, installed sites refuse the update.
5. For a security fix, raise `minimum` in `min-version.json` on `main` to the fixed version. Sites below it switch the endpoints off at the next daily check.
6. Rotating the key means shipping a release signed with the old key that adds the new public key to `Updates::RELEASE_PUBLIC_KEYS`, and only then signing with the new one.
