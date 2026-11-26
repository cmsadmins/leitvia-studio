# leitvia/rollback

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Roll back change package.** Rolls back the applied operations of a change package from their snapshots. Package IDs come from leitvia/list-packages. If anyone changed a target since, nothing is rolled back and leitvia_rollback_conflict lists both hashes and the diff. Agents may only roll back operations whose autonomy level is autonomous.

MCP tool `leitvia-rollback`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `id` | integer, min `1` | yes | Change package ID. |

Output: `package` integer; `state` one of `open`, `applied`, `partial`, `rejected`, `rolled_back`; `applied` array of integer; `conflicts` array of object; `errors` array of object; `rolled_back` array of integer.
