# leitvia/get-product

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Get product.** Returns one product or variation: texts, images with alt text, categories and tags with their hash, attributes, price (read only), tax and shipping class, stock status, merchandising and the hash to use as base_hash. No sales, order or customer figures.

MCP tool `leitvia-get-product`, REST GET. Annotations: readonly true, destructive false, idempotent true. Only registered while woocommerce is active.

| Input | Type | Required | Description |
|---|---|---|---|
| `product` | integer, min `1` | yes | Product or variation ID from leitvia/list-products. |

Output: `id` integer; `type` string; `title` string; `description` string; `short_description` string; `images` array of object; `categories` array of object; `tags` array of object; `attributes` array of object; `price` object; `stock_status` string; `hash` string.
