# leitvia/update-product

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Update product.** Updates texts, status, images, categories and tags of one product or variation, and its prices, tax and shipping class. Read the product with leitvia/get-product first and send its hash as base_hash. Images need alt text (set it with leitvia/set-alt-text first). Changes to prices, taxes or shipping land at most as a suggestion unless a person unlocked them.

MCP tool `leitvia-update-product`, REST POST. Annotations: readonly false, destructive true, idempotent false. Only registered while woocommerce is active.

| Input | Type | Required | Description |
|---|---|---|---|
| `product` | integer, min `1` | yes | Product or variation ID from leitvia/list-products. |
| `title` | string, min length `1`, max length `200` |  |  |
| `description` | string |  | Long description, block markup or plain text. Plain text without HTML becomes paragraph blocks: a blank line starts a new paragraph, the text is escaped. No fixed length, as with the content of leitvia/update-content; a change package shows the comparison up to 1 MB for before and after together. |
| `short_description` | string, max length `5000` |  |  |
| `status` | one of `draft`, `pending`, `publish` |  |  |
| `image` | integer, min `0` |  | Attachment ID of an image with alt text, 0 to remove. |
| `gallery` | array of integer, min `1`, max items `20` |  |  |
| `categories` | array of integer, min `1`, max items `20` |  |  |
| `tags` | array of integer, min `1`, max items `20` |  |  |
| `regular_price` | string, max length `20`, pattern `^([0-9]+([.][0-9]+)?)?$` |  | Price as a decimal number with a dot, empty for none. A price change lands at most as a suggestion. |
| `sale_price` | string, max length `20`, pattern `^([0-9]+([.][0-9]+)?)?$` |  | Price as a decimal number with a dot, empty for none. A price change lands at most as a suggestion. |
| `tax_status` | one of `taxable`, `shipping`, `none` |  |  |
| `tax_class` | string, max length `200` |  |  |
| `shipping_class` | integer, min `0` |  | Term ID of the shipping class, 0 for none. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
