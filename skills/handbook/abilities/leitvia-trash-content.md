# leitvia/trash-content

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Trash content.** Moves a post, page or entry to the trash. It stays restorable with its address: leitvia/rollback of the applied package or Restore in the trash of the admin bring it back, until WordPress empties the trash (after 30 days unless the site changed that). Removing content is the decision of a person: the change always waits in a change package, and applying it needs the right to delete the post. Read the post with leitvia/get-page-outline first and send its hash as base_hash. Refused for the front page, the posts page and the privacy policy page (point the setting to another page first) and for a page with subpages (move them with parent of leitvia/update-content, or trash them first). notice and report.links name the posts whose links lead nowhere afterwards. Files go with leitvia/delete-media.

MCP tool `leitvia-trash-content`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `post` | integer, min `1` | yes | Post ID from leitvia/list-content. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
