# leitvia/update-product-category

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Update product category.** Updates name, description, parent and image of a product category, or name and description of a product tag. Use the hash of the term from list-products or get-product as base_hash. The image needs alt text.

MCP tool `leitvia-update-product-category`, REST POST. Annotations: readonly false, destructive true, idempotent false. Only registered while woocommerce is active.

| Input | Type | Required | Description |
|---|---|---|---|
| `term` | integer, min `1` | yes | Term ID of a product category or tag from leitvia/list-products or leitvia/get-product. |
| `name` | string, min length `1`, max length `200` |  |  |
| `description` | string, max length `5000` |  |  |
| `parent` | integer, min `0` |  |  |
| `image` | integer, min `0` |  | Attachment ID of an image with alt text, 0 to remove. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
