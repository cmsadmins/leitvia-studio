# leitvia/list-content

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**List content.** Lists posts, pages and entries of the content model, newest first, with hash, terms and the open package that waits for each. Per item: ID, type, status, title, slug, date, modified, author (ID) and author_name as visitors see it, excerpt, terms, fields, rows of repeaters, link, hash and package. Filter by type, status, search text, modified since and published after a date. terms_of adds the terms of one taxonomy with their IDs (term_list), such as the categories for taxQuery of a query loop. Only content agents may read; no comments, users or form entries.

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
| `terms_of` | string, pattern `^[a-z0-9_-]+$` |  | A taxonomy of the listed content types, such as category or post_tag: term_list then names all its terms with id, slug, name, count of posts, parent, description and hash, for taxQuery of a query loop, terms of a post or leitvia/update-term. Combine with limit 1 when only the terms count. |

Output: `items` array of object; `total` integer; `pages` integer; `term_list` array of object { id, slug, name, count, parent, description, hash }.
