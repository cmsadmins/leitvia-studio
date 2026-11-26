# leitvia/update-site-values

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Update site values.** Sets values of site settings of the content model declared with bindable true, the values blocks show on every page through the binding source leitvia/setting, such as opening hours or a phone number. values names them by key; the settings you leave out stay. Each value follows the field type of its setting, an empty string empties a text. leitvia/get-content-model returns the values as settings_values and their hash as settings_hash, the base_hash. Like every setting it waits in a change package for a person, and leitvia/rollback undoes it once applied. Declare a new setting with leitvia/define-content-type first.

MCP tool `leitvia-update-site-values`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `values` | object | yes | New values by setting key, for example {"opening_hours": "Mon to Fri 8 to 18"}; only settings with bindable true. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
