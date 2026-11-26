# leitvia/get-package

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Get change package.** Returns a change package with its operations: status, the post each applied operation wrote, conflicts and, for open operations, a block diff against the current state (per block added, removed, moved or changed with text before and after and the changed attributes). applied.post is the ID a new post got, applied.hash its hash after the write.

MCP tool `leitvia-get-package`, REST GET. Annotations: readonly true, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `id` | integer, min `1` | yes | Change package ID. |

Output: `id` integer; `title` string; `key` string; `state` string; `operations` object; `items` array of object.
