# leitvia/define-content-type

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Define content type.** Adds entries to the content model (postTypes, taxonomies, relations, settings) and keeps the others. An entry that exists is merged: the input sets its own keys and fields, fields, repeaters and supports it does not name stay, and the postTypes of a taxonomy are joined; relations and settings are replaced whole. Nothing is removed through this ability: an entry, field, repeater or supports value can only be removed by a person on the Model screen. Read the declaration with leitvia/get-content-model first. The base hash is the hash of the content model. leitvia/get-content-model returns it as hash. showInAbilities stays a decision of a person: agents may not set it. A new address (slug, or the key) that a published page or another public type already has is refused: the subpages of such a page would lead to a 404.

MCP tool `leitvia-define-content-type`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `declaration` | object | yes | Content model declaration with the entries to add or replace, see leitvia/get-content-model. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
