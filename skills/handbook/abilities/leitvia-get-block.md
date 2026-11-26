# leitvia/get-block

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Get block.** Returns one block of a post by its ID or path ID with all attributes, its markup alone and with children, the image attachments, its hash, the IDs of its children and the document hash. Classic content without blocks is one block p:0 named core/freeform.

MCP tool `leitvia-get-block`, REST GET. Annotations: readonly true, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `post` | integer, min `1` | yes | Post ID. |
| `block` | string, min length `1`, max length `200` | yes | Block ID from the page outline. |

Output: `id` string; `id_stable` boolean; `name` string; `attributes` object; `inner_html` string; `markup` string; `attachments` array of object { id, url }; `hash` string; `children` array of string; `document_hash` string.
