# leitvia/set-posts-page

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set posts page.** Makes an existing page the posts page of the reading settings, the address that lists all posts; 0 clears it. base_hash is empty. The page must exist, so create it first and wait until a package with it is applied. Like every setting it waits in a change package for a person. It takes effect with a static front page; notice says so while the site has none, or while the page is not published.

MCP tool `leitvia-set-posts-page`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `page` | integer, min `0` | yes | ID of a page from leitvia/list-content, 0 to clear the setting. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
