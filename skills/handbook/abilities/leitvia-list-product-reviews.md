# leitvia/list-product-reviews

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**List product reviews.** Lists product reviews with rating and hash, 20 per page, oldest first; per review ID, product, author name, date, text, rating (1 to 5, 0 for none), verified owner and status. unrated lists only reviews without a rating; unrated_total counts them. Without the capability of the reviews screen of WooCommerce (moderate_comments) only approved reviews of published products, which visitors see anyway; status in the output names the status listed. No email address, IP address or user account of a reviewer.

MCP tool `leitvia-list-product-reviews`, REST GET. Annotations: readonly true, destructive false, idempotent true. Only registered while woocommerce is active.

| Input | Type | Required | Description |
|---|---|---|---|
| `product` | integer, min `1` |  |  |
| `status` | one of `approved`, `hold`, `spam`, `trash` |  |  |
| `unrated` | boolean |  |  |
| `page` | integer, min `1` |  |  |

Output: `reviews` array of object; `total` integer; `pages` integer; `unrated_total` integer; `status` string.
