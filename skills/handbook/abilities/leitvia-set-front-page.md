# leitvia/set-front-page

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set front page.** Makes an existing page the static front page; 0 shows the latest posts there again. The front page is the page the address of the site shows. base_hash is empty. The page must exist, so create it first and wait until a package with it is applied; the posts page cannot be the front page as well. Like every setting it waits in a change package for a person. notice says so while the page is not published. Set the posts page with leitvia/set-posts-page.

MCP tool `leitvia-set-front-page`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `page` | integer, min `0` | yes | ID of a page from leitvia/list-content, 0 for the latest posts. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
