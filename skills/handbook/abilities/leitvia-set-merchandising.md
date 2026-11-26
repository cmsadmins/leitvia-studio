# leitvia/set-merchandising

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set merchandising.** Sets the featured flag, the upsells, the cross-sells and the sort order of one product. The lists replace the current ones. Read the product with leitvia/get-product first and send its hash as base_hash; product IDs come from leitvia/list-products.

MCP tool `leitvia-set-merchandising`, REST POST. Annotations: readonly false, destructive true, idempotent false. Only registered while woocommerce is active.

| Input | Type | Required | Description |
|---|---|---|---|
| `product` | integer, min `1` | yes | Product or variation ID from leitvia/list-products. |
| `featured` | boolean |  |  |
| `upsells` | array of integer, min `1`, max items `20` |  |  |
| `cross_sells` | array of integer, min `1`, max items `20` |  |  |
| `menu_order` | integer |  |  |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
