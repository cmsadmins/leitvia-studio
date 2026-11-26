# leitvia/list-media

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**List media.** Lists the media library, newest first: ID, URL, file name, MIME type, file size in bytes, alt text, whether it was made with AI (ai, see ai_generated of leitvia/add-media), width, height and hash (the base_hash for leitvia/set-alt-text). Filter by IDs (include, in their order, for example the images of a gallery), by search text in title, caption and file name, MIME type (image, image/png or application/pdf) and uploaded after a date.

MCP tool `leitvia-list-media`, REST GET. Annotations: readonly true, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `include` | array of integer, min `1`, min items `1`, max items `100` |  | Only these attachment IDs, in this order. IDs that are no media of the library are left out. |
| `search` | string, max length `100` |  | Text in the title, caption or file name. |
| `mime` | string, pattern `^[a-z]+(/[a-z0-9.+-]+)?$` |  |  |
| `after` | string, pattern `^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$` |  | Date in the time zone of the site, YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS. |
| `limit` | integer, min `1`, max `100` |  |  |
| `page` | integer, min `1` |  |  |

Output: `items` array of object; `total` integer; `pages` integer.
