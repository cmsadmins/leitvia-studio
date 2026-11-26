# leitvia/update-product-review

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Update product review.** Sets rating, verified flag or status of a product review. Its text and author stay. Read the review with leitvia/list-product-reviews and send its hash as base_hash. The average rating and the review count of the product follow. Without the capability of the reviews screen of WooCommerce (moderate_comments) only a missing rating of an approved review can be set.

MCP tool `leitvia-update-product-review`, REST POST. Annotations: readonly false, destructive true, idempotent false. Only registered while woocommerce is active.

| Input | Type | Required | Description |
|---|---|---|---|
| `review` | integer, min `1` | yes | Review ID from leitvia/list-product-reviews. |
| `rating` | integer, min `1`, max `5` |  |  |
| `verified` | boolean |  |  |
| `status` | one of `approved`, `hold`, `spam`, `trash` |  |  |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
