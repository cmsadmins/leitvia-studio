# leitvia/update-product-attribute

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Update product attribute.** Renames a global product attribute and its values and sorts the values, in one operation. Read the attribute with leitvia/list-product-attributes and send its hash as base_hash. Values are named by their ID; order lists every value ID in the new order and sorts the attribute by it (order_by menu_order). Slugs stay, so filters and links keep working.

MCP tool `leitvia-update-product-attribute`, REST POST. Annotations: readonly false, destructive true, idempotent false. Only registered while woocommerce is active.

| Input | Type | Required | Description |
|---|---|---|---|
| `attribute` | integer, min `1` | yes | Attribute ID from leitvia/list-product-attributes. |
| `name` | string, min length `1`, max length `200` |  |  |
| `values` | array of object { value\*, name\* }, max items `200` |  | New names of values: value is the ID from leitvia/list-product-attributes. |
| `order` | array of integer, min `1`, max items `200` |  | Every value ID of the attribute, in the new order. |
| `order_by` | one of `menu_order`, `name`, `name_num`, `id` |  | How the shop sorts the values: menu_order (the custom order), name, name_num (numbers in names as numbers) or id. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
