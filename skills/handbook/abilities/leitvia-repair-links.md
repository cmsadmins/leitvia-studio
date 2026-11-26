# leitvia/repair-links

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Repair links.** Repairs internal links of one post: from pairs of old and new link, or with auto through the old slugs WordPress keeps for redirects. The base hash is the hash of the post from leitvia/get-page-outline. Only links of this site change. External links are never requested, only listed in the report, as are internal links that lead nowhere and cannot be resolved. Links with an empty href or a # without target are listed under empty, with in (content or page) and their class.

MCP tool `leitvia-repair-links`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `post` | integer, min `1` | yes | Post ID from leitvia/list-content. |
| `links` | array of object { from\*, to\* }, max items `50` |  |  |
| `auto` | boolean |  | Find links to posts whose slug changed and point them to the current address. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
