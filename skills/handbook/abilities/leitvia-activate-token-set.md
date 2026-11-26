# leitvia/activate-token-set

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Activate token set.** Writes the presets of a token set into the global styles of the active theme. The set ID comes from leitvia/create-token-set or the sets of leitvia/get-tokens. The base hash is the hash of the global styles. leitvia/get-tokens returns it as hash.

MCP tool `leitvia-activate-token-set`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `set` | integer, min `1` | yes | Token set ID from the sets of leitvia/get-tokens or from leitvia/create-token-set. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
