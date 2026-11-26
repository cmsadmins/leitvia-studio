# leitvia/list-blocks

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**List blocks.** Returns the block catalog of Leitvia Studio and its add-ons with attribute schema and usage rules, and the registered block styles of every block, core blocks included, by block name; a style is set as the class is-style-\<name>. With post_type only the blocks the editor allows for that post type.

MCP tool `leitvia-list-blocks`, REST GET. Annotations: readonly true, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `post_type` | string, max length `20` |  | Limit to the blocks allowed for this post type. |

Output: `blocks` array of object { name\*, title\*, description\*, category\*, parent\*, ancestor\*, supports\*, attributes\*, innerBlocks\*, example, usage\*, owner\*, extension }; `styles` object.
