# leitvia/create-pattern

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Build. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Create pattern.** Creates a pattern in the Lab as a draft with version 0.1.0 from block markup; base_hash is empty. Blocks and their attributes come from leitvia/list-blocks. The checks run on its markup, design tokens included. To build on an existing pattern use leitvia/vary-pattern.

MCP tool `leitvia-create-pattern`, REST POST. Annotations: readonly false, destructive false, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `title` | string, min length `1`, max length `200` | yes | Title of the pattern in the Lab. |
| `content` | string, min length `1` | yes | Block markup of the pattern. |
| `synced` | boolean |  | Whether a person inserts it in the editor as a synced pattern. Abilities insert every pattern as a copy, so pattern overrides never apply there; a value that every page shows, such as opening hours, is a site setting with bindable true (leitvia/update-site-values). |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
