# MCP server leitvia

Generated from Leitvia Studio 1.3.61. Do not edit. Hashes, autonomy and rejections: [agent handbook](README.md).

The MCP server `leitvia` is part of Leitvia Studio. It answers at `POST <address of the site>/wp-json/leitvia-studio/v1/mcp` over streamable HTTP and speaks the revisions `2026-07-28` and `2025-11-25` of MCP. Its tools are the abilities of the [handbook](README.md#abilities), with the same checks, autonomy levels and change packages as over REST.

## Revisions

**2026-07-28.** No `initialize` and no session. Every request carries `io.modelcontextprotocol/protocolVersion`, `io.modelcontextprotocol/clientCapabilities` and `io.modelcontextprotocol/clientInfo` in `_meta`, and the headers `MCP-Protocol-Version` and `Mcp-Method`; `tools/call`, `resources/read`, `prompts/get` and the methods of tasks add `Mcp-Name` (tool name, resource URI, prompt name or `taskId`). A header that differs from the body gets HTTP 400 with `-32020`. `server/discover` returns `supportedVersions`, `capabilities` with `extensions`, `instructions` that follow the state of the site, `ttlMs` and `cacheScope`. Every result carries `resultType`. A revision the server does not speak gets `-32022` with `data.supported`.

**2025-11-25** (also `2025-06-18` and `2025-03-26`). Send `initialize`, then `notifications/initialized`; the response carries the header `Mcp-Session-Id`, which every further request sends with `MCP-Protocol-Version`. A session lives 30 minutes after its last request and belongs to the user who started it. DELETE ends it. GET opens no stream and gets 405.

## Sign-in

- **Application password:** `Authorization: Basic` with user and application password of the agent user.
- **OAuth 2.1:** `Authorization: Bearer` with an access token. A 401 carries `WWW-Authenticate` with `resource_metadata`, where the client finds the authorization server of the site. A person agrees on the page Connect agent and picks an agent user or themselves. Scopes: `leitvia:read`, `leitvia:write` (agent users only) and `leitvia:approve` (a person for themselves, only for the tools of an add-on that shows packages for approval; without such an add-on the connection reads only). Access tokens last 1 hour, refresh tokens 30 days and rotate with every use. `leitvia:approve` gets no refresh token (after an hour the person agrees again) and needs a person with manage_options who is no agent. Every connection ends 90 days after consent.
- **Rate limit per OAuth connection and tool:** 120 calls per minute for tools that read, 30 for tools that write. Over it: HTTP 429 with `Retry-After`; wait, then send the same call.
- **Discovery blocked:** a host whose nginx has a rule such as `location ~ /\.` (the template of ISPConfig) answers 403 to every path with `/.well-known/` before WordPress runs, and the client finds no authorization server. Add these directives to the site, in ISPConfig under Website, Options, nginx Directives: `location ^~ /.well-known/oauth-protected-resource { try_files $uri /index.php?$args; }` and `location ^~ /.well-known/oauth-authorization-server { try_files $uri /index.php?$args; }`. The screen Agents of Leitvia Studio shows when the discovery of the site is blocked.

## Tools

`tools/list` returns the tools in a fixed order with `inputSchema`, `outputSchema` and annotations. A result carries `structuredContent`. Tools whose `_meta.ui.visibility` holds only `app` belong to a view, not to the model; they appear only for a client that shows views.

## Resources

Fixed resources: `leitvia://contract`, `leitvia://tokens`, `leitvia://patterns` and `leitvia://inbox` (open change packages). Templates: `leitvia://page/{id}/outline`, `leitvia://pattern/{slug}` and `leitvia://skills/{owner}/{+path}` (Markdown files the prompts link to). The others are JSON, the result of the matching read ability; `leitvia://patterns` holds every page. Lists and reads carry `cacheScope` `private` and `ttlMs` 60000. A resource that does not exist gets `-32602` with `data.uri` (under 2025-11-25 `-32002`). `completion/complete` completes pattern slugs, page IDs, recipes and the arguments of prompts.

## Subscriptions

Only under 2026-07-28. `subscriptions/listen` takes `params.notifications` with `resourcesListChanged`, `resourceSubscriptions` (`["leitvia://patterns"]`) and `promptsListChanged`. The answer is an event stream: first `notifications/subscriptions/acknowledged`, then the changes, a keep-alive every 15 seconds, after 55 seconds the result. Listen again after it. A user holds at most 2 streams; a third gets 429. Under 2025-11-25 read the list again when you need it.

## Questions

Only under 2026-07-28, for a client that declares form elicitation. Before a call runs, the server may answer `resultType` `input_required` with `inputRequests` and a `requestState`: `choose` when a required argument is missing and has a closed list of values, `confirm` before a destructive tool that deletes, removes, rejects or rolls back (`delete-`, `remove-`, `reject-`, `rollback`) and would change the site, that is without a package key and without a dry run. Repeat the call with `inputResponses` and the same `requestState`; the state lasts 10 minutes and works once. A no, decline or cancel ends the call with `leitvia_cancelled`. A yes is no approval: what would land in a change package still does.

## Tasks

Only under 2026-07-28, for a client that declares the extension `io.modelcontextprotocol/tasks`. `leitvia-apply-recipe`, `leitvia-audit-site` and `leitvia-export` then answer at once with `resultType` `task`, a `taskId` and `pollIntervalMs` 2000. Call `tasks/get` until `status` is no longer `working` (`completed`, `failed` or `cancelled`); the last state holds the tool result. `tasks/cancel` stops a task only before it starts. A task lasts 1 hour and belongs to its user and connection. A user holds at most 3 running tasks; further calls run in the request. Without the extension these tools run in the request as well.

## Progress

A `tools/call` with `_meta.progressToken` that accepts `text/event-stream` gets `notifications/progress` on the response stream, the result as last event. Tasks report progress in `statusMessage` instead.

## Prompts

Every skill and workflow of Leitvia except `new-site-operator` (steps of a person) is a prompt named `leitvia-<name>`: `leitvia-page` takes `goal` and `points`, `leitvia-setup` takes `briefing` and `recipe`, `leitvia-design` takes `brand`, all others `task`. Links in a prompt are resources `leitvia://skills/…`. Prompts have the version of the installed Leitvia Studio; add-ons can add their own.

## Views in the chat

An add-on can attach a view to a tool (MCP Apps, `_meta.ui.resourceUri`, extension `io.modelcontextprotocol/ui`). A client without views gets the same result as text and `structuredContent`. With Leitvia Pro: [pro.md](https://leitvia.com/docs/pro/).

## MCP Adapter

Not needed. When the plugin MCP Adapter is active, its default server lists the Leitvia abilities through `mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info` and `mcp-adapter-execute-ability`, with the same checks, but offers no resources, prompts, questions, tasks or views; prefer the server `leitvia`.
