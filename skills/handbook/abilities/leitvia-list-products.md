# leitvia/list-products

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**List products.** Lists the products of the shop, 20 per page, with title, type, status, price (read only), stock status, featured flag and hash, plus all product categories with their hash. Filter by category or search text. Orders, coupons and customers are never listed.

MCP tool `leitvia-list-products`, REST GET. Annotations: readonly true, destructive false, idempotent true. Only registered while woocommerce is active.

| Input | Type | Required | Description |
|---|---|---|---|
| `category` | integer, min `1` |  |  |
| `search` | string, max length `100` |  |  |
| `page` | integer, min `1` |  |  |

Output: `products` array of object; `total` integer; `pages` integer; `categories` array of object.
