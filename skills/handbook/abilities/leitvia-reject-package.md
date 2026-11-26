# leitvia/reject-package

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Discard change package.** Discards an open or partly applied change package. Its open operations stay unwritten and their waiting files are deleted, operations already applied stay. Use it for your own package when a run took a wrong turn; users with manage_options may discard any package. The package stays with the state rejected and the audit log keeps the call. It cannot be undone, so build the change again with a new package key.

MCP tool `leitvia-reject-package`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `id` | integer, min `1` | yes | Change package ID. |

Output: `package` integer; `state` one of `open`, `applied`, `partial`, `rejected`, `rolled_back`; `applied` array of integer; `conflicts` array of object; `errors` array of object; `rolled_back` array of integer.
