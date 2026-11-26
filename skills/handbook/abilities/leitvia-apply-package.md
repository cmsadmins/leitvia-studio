# leitvia/apply-package

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Apply change package.** Applies the open operations of a change package, all or the listed ones. Find the package with leitvia/list-packages and check its diff with leitvia/get-package first. Each operation checks its base hash; one whose target changed stays open with a conflict, the others are written with snapshot and audit entry. Agents may only apply operations whose autonomy level is autonomous.

MCP tool `leitvia-apply-package`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `id` | integer, min `1` | yes | Change package ID. |
| `operations` | array of integer, min `1` |  | Operation IDs to apply; empty or missing for every open operation. |

Output: `package` integer; `state` one of `open`, `applied`, `partial`, `rejected`, `rolled_back`; `applied` array of integer; `conflicts` array of object; `errors` array of object; `rolled_back` array of integer.
