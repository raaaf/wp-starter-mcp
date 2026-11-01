# Data protection notes

These notes describe what the plugin does, to help with a data processing agreement (AVV) and a works council discussion. They are not legal advice. The controller of the site and its lawyers decide what applies.

## Data that goes to Anthropic

The plugin sends nothing to Anthropic by itself. Claude, run by a person with their own Claude account, calls the tools on this site and the answers go to Anthropic as part of that conversation. Per tool:

- Page titles, status, modified time and public URLs (`list-pages`, `get-page`).
- The full text and fields of every section of the pages Claude reads, drafts included.
- Module and field descriptions and design tokens.
- Media title, alt text, size and URL of images.
- Edit and preview URLs of drafts.

Not sent: passwords, tokens, email addresses, private or password-protected pages, pages the user may not edit. Names of people appear only if they are in page content. What the person types into Claude, and what Claude writes back, also sits with Anthropic. Anthropic's role, retention and training rules depend on the Claude plan used (consumer, Team, Enterprise); check them for the plan in use and conclude the agreement with Anthropic that fits. Before switching on, an administrator must confirm that the transfer is clarified.

Claude connects from Anthropic's servers; the site sees their addresses, not the user's.

## Other outgoing requests

The update channel asks GitHub for releases and `min-version.json`; GitHub sees the server's address. The mail on a new connection goes through the site's own mail setup.

## What the plugin stores

See "Data stored" in [SPEC.md](SPEC.md). In short:

- **Connections:** user ID, client ID, redirect, scope and times, with token hashes. Rows are removed one day after expiry.
- **Audit list:** time, user ID, event, page ID, client host (for example `claude.ai`). No IP address, no titles, no page content, no prompts. Kept 90 days, then deleted daily. Administrators see the last 50 entries on Tools > Claude; editors do not see the list.
- **Other:** a connection intent in user meta (10 minutes), MCP adapter sessions in user meta (removed on revoke), copy and snapshot information on pages and revisions.
- **Error log:** warnings with family ID, user ID and client host, in the server's PHP error log, subject to its own retention.

The audit list shows who made a draft through Claude and when. It does not record what was asked or the exact changes. It is not a tool for measuring a person's performance. Say so in the works agreement if that is the intent.

Page content that Claude writes becomes ordinary WordPress content (drafts and revisions) under the site's normal rules.

## Access, export and erasure

- The plugin registers a personal data exporter and eraser for WordPress's privacy tools (Tools > Export / Erase Personal Data). The exporter lists the user's audit entries, connection rows (type, client, created, revoked) and MCP session IDs.
- The eraser revokes the user's connections, deletes their audit entries and connection rows, and removes their sessions and intent. It leaves drafts, revisions and page meta, which are site content.
- Deleting a user revokes their connections.
- Uninstalling with "Beim Löschen des Plugins alle Daten entfernen" ticked removes tables, options, user meta and plugin post meta. Without the tick, the data stays.

## For a works council

Claude can only act with a signed-in person's rights and only on drafts; every connection is visible to administrators and can be revoked at any time. The administrator can switch the feature off, which disconnects everyone at once. Each person decides to connect and approves each connection on a consent page.
