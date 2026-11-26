# leitvia/create-product

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Build. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Create product.** Creates a simple product. It takes title, slug, texts as block markup or plain text (a long description in plain text becomes paragraph blocks, one per block of text between blank lines), prices, SKU, stock, categories and an image of the media library; base_hash is empty. Categories take IDs, slugs or names; a name no category has yet creates that category. The image needs alt text (leitvia/set-alt-text). The status is capped by the autonomy level, and a product with a price lands at most as a suggestion unless a person unlocked prices.

MCP tool `leitvia-create-product`, REST POST. Annotations: readonly false, destructive false, idempotent false. Only registered while woocommerce is active.

| Input | Type | Required | Description |
|---|---|---|---|
| `title` | string, min length `1`, max length `200` | yes | Name of the new product. |
| `slug` | string, pattern `^[a-z0-9-]+$` |  |  |
| `description` | string |  | Long description, block markup or plain text. Plain text without HTML becomes paragraph blocks: a blank line starts a new paragraph, the text is escaped. No fixed length, as with the content of leitvia/update-content; a change package shows the comparison up to 1 MB for before and after together. |
| `short_description` | string, max length `5000` |  | Short description next to the price, block markup or plain text. |
| `status` | one of `draft`, `pending`, `publish` |  |  |
| `regular_price` | string, max length `20`, pattern `^([0-9]+([.][0-9]+)?)?$` |  | Price as a decimal number with a dot, empty for none. A price change lands at most as a suggestion. |
| `sale_price` | string, max length `20`, pattern `^([0-9]+([.][0-9]+)?)?$` |  | Price as a decimal number with a dot, empty for none. A price change lands at most as a suggestion. |
| `sku` | string, min length `1`, max length `100` |  |  |
| `stock` | integer, min `0` |  | Units in stock; turns on stock management for the product. Without it the product is in stock without a count. |
| `categories` | array of integer or string, min length `1`, max length `200`, max items `20` |  | Product categories as IDs (numbers), slugs or names (text). A name no category has yet creates the category. |
| `image` | integer, min `1` |  | Attachment ID of an image with alt text. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
