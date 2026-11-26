# leitvia/set-permalinks

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set permalinks.** Sets the permalink structure of posts and the bases of category and tag addresses, as in Settings, Permalinks, for a migration that keeps the addresses of the old site: structure such as /news/%postname%/ or /%postname%/ (needs %postname% or %post_id%), category_base and tag_base such as topic (empty for the default). Pages keep their addresses, posts take the structure. Only the fields you send change; the rewrite rules are rebuilt on the next request. base_hash is empty. Like every setting it waits in a change package for a person; notice warns when published posts change their address, which leitvia/set-redirects can lead from the old addresses.

MCP tool `leitvia-set-permalinks`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `structure` | string, max length `200`, pattern `^/[A-Za-z0-9%_/.-]\*$` |  | Permalink structure of posts with tags such as %postname%, %post_id%, %year%, %monthnum%, %category%, starting with a slash. |
| `category_base` | string, max length `100`, pattern `^[a-z0-9-]\*$` |  | Base of category addresses, empty for category. |
| `tag_base` | string, max length `100`, pattern `^[a-z0-9-]\*$` |  | Base of tag addresses, empty for tag. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
