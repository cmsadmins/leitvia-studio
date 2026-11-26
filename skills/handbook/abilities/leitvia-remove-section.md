# leitvia/remove-section

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Remove section.** Takes one top-level section out of the content of a post, page or entry; the rest of the content stays as it is. block is its block ID or path ID from the outline. The last section stays: a whole post goes with leitvia/trash-content. leitvia/rollback of the package brings the section back. Read the post with leitvia/get-page-outline first and send its hash as base_hash.

MCP tool `leitvia-remove-section`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `post` | integer, min `1` | yes | Post ID from leitvia/list-content. |
| `block` | string, min length `1`, max length `200` | yes | Block ID or path ID of a top-level section from the outline. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
