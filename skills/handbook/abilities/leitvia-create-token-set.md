# leitvia/create-token-set

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Create token set.** Creates a token set from a DTCG document. Start from the document leitvia/get-tokens returns; base_hash is empty for the new set. It must compile against the contract and pass every token set check; contrast findings name the failing pair and the ratio it needs. Optional group size: page widths. Look at it with leitvia/preview-token-set, activate it with leitvia/activate-token-set.

MCP tool `leitvia-create-token-set`, REST POST. Annotations: readonly false, destructive false, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `title` | string, min length `1`, max length `200` | yes | Name of the new token set. |
| `document` | object | yes | DTCG token document, as leitvia/get-tokens returns it. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
