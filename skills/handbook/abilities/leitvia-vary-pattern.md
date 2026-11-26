# leitvia/vary-pattern

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Build. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Vary pattern.** Creates a variant of a Lab pattern (block:\<ID>) or a theme pattern (slug) as a draft in the Lab, from new markup or from the original with edits at block IDs; base_hash is empty. Originals come from leitvia/list-patterns, block IDs for edits from leitvia/get-pattern.

MCP tool `leitvia-vary-pattern`, REST POST. Annotations: readonly false, destructive false, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `original` | string | yes | The pattern to vary: block:\<ID> of a Lab pattern or the slug of a theme pattern from leitvia/list-patterns. |
| `title` | string, max length `200` |  |  |
| `content` | string, min length `1` |  |  |
| `edits` | array of object { block\*, text, link_text, url, add_link, emphasis, strike, mark, replace_text, caption, anchor, bind, table, columns, image, video, remove, repeat, move, position, insert, attributes }, max items `250` |  |  |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
