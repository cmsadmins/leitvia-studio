# leitvia/list-packages

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**List change packages.** Lists your change packages, newest first, a page at a time: items with ID, title, key, state (open, applied, partial, rejected, rolled_back), operations per status and a preview link, plus total (all packages that match), page, per_page and pages. Fetch the next page while page is below pages; filter with state open to find work that waits.

MCP tool `leitvia-list-packages`, REST GET. Annotations: readonly true, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `state` | one of `open`, `applied`, `partial`, `rejected`, `rolled_back` |  |  |
| `page` | integer, min `1`, default `1` |  | Page, from 1. |
| `per_page` | integer, min `1`, max `100`, default `100` |  | Packages per page, at most 100. |

Output: `items` array of object { id, title, key, state, operations }; `total` integer; `page` integer; `per_page` integer; `pages` integer.
