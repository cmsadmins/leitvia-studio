# leitvia/list-content

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**List content.** Lists posts, pages and entries of the content model, newest first, with hash, terms and the open package that waits for each. Per item: ID, type, status, title, slug, date, modified, author (ID) and author_name as visitors see it, excerpt, terms, fields, rows of repeaters, link, hash and package. Filter by type, status, search text, modified since and published after a date. Only content agents may read; no comments, users or form entries.

MCP tool `leitvia-list-content`, REST GET. Annotations: readonly true, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `post_type` | string |  | One post type; empty for all content types. |
| `status` | one of `publish`, `future`, `draft`, `pending`, `any` |  |  |
| `search` | string, max length `100` |  |  |
| `since` | string, pattern `^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$` |  | Date in the time zone of the site, YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS. |
| `after` | string, pattern `^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$` |  | Date in the time zone of the site, YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS. |
| `limit` | integer, min `1`, max `100` |  |  |
| `page` | integer, min `1` |  |  |

Output: `items` array of object; `total` integer; `pages` integer.
