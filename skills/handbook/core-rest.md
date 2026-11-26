# Core REST without the leitvia MCP server

Generated from Leitvia Studio 1.3.61. Do not edit. Hashes, autonomy and rejections: [agent handbook](README.md).

Without the MCP server `leitvia`, for example when an agent works with the WordPress skills alone, every Leitvia ability runs over the REST API of the Abilities API in WordPress core. Sign in as the agent user with its application password (HTTP Basic auth). Checks, autonomy levels, change packages and the rate limit are the same as over MCP.

- **Discovery:** `GET /wp-json/wp-abilities/v1/abilities?category=leitvia` lists the abilities with their input and output schemas and annotations; `GET /wp-json/wp-abilities/v1/abilities/<name>` returns one.
- **Reads:** read only abilities run with GET at `/wp-json/wp-abilities/v1/abilities/<name>/run`, the input as query arguments: `?input[post]=12`, lists as `input[ids][]=4&input[ids][]=7`.
- **Writes:** run with POST and the JSON body `{"input": {...}}`; destructive abilities that are idempotent take DELETE with the input as query arguments. The page of each ability names its method.
- **`mode` `package`:** the write was not carried out. It waits in a change package until a person decides in the inbox of Leitvia Studio. Report the package ID and do not try to get the change through another way.
- **409:** `leitvia_stale_hash` means the target changed since you read it. Read it again, or take `data.current_hash` when no read ability returns its hash, and repeat the call once.
- **429:** `leitvia_rate_limited` comes with the header `Retry-After`. Wait that many seconds, then send the same call again.
- **MCP Adapter:** not needed; when active, its default server lists the Leitvia abilities as well, with the same checks.
