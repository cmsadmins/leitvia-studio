# leitvia/export

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Export site.** Exports the site as a portable JSON package, page by page. Pages (block markup), navigation menus and token sets (DTCG document) come in items; the first call (offset 0) also carries the template parts of the active theme and the content model. Each item carries its hash. Items of one call stay under 1048576 bytes; call again with offset set to next until next is null. Choose parts to export less. Writes nothing; drafts are included, private and password protected content is not.

MCP tool `leitvia-export`, REST GET. Annotations: readonly true, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `parts` | array of one of `pages`, `navigation`, `token_sets`, `template_parts`, `content_model` |  | Parts to export; all when empty. |
| `offset` | integer, min `0`, default `0` |  |  |
| `limit` | integer, min `1`, max `50`, default `20` |  |  |

Output: `format` string; `site` object; `parts` array of string; `content_model` object or null; `template_parts` array of object; `items` array of object; `next` integer or null.
