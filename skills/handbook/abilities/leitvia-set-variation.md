# leitvia/set-variation

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set style variation.** Applies a style variation of the contract to the global styles of the active theme: a full variation replaces them, a colors or typography variation replaces only its part. Names come from the style variations of leitvia/get-contract. The base hash is the hash of the global styles. leitvia/get-tokens returns it as hash.

MCP tool `leitvia-set-variation`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `variation` | string, pattern `^[a-z0-9-]+$` | yes | Slug of a style variation from leitvia/get-contract. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
