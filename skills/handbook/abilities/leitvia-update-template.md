# leitvia/update-template

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Update template.** Replaces a template with a template pattern made for it or with changed block markup. Slugs, such as single, single-product or taxonomy-product_cat, come from the templates of leitvia/get-contract and the templates the theme ships; WooCommerce templates need WooCommerce. A pattern fits when its templateTypes hold the slug; the rejection leitvia_unknown_pattern lists the patterns that fit in patterns. Templates for single posts and pages keep a core/post-content block. The change publishes the template; where the area level is draft a new one waits in a change package. A rollback restores the template as it was, the one of the theme when there was no saved one. The base hash is the hash of the saved template, empty when the theme template has not been saved yet. leitvia/get-contract returns it per template under hashes.templates.

MCP tool `leitvia-update-template`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `template` | string, pattern `^[a-z0-9_-]+$` | yes | Template slug from leitvia/get-contract or of the theme, such as single or taxonomy-product_cat. |
| `pattern` | string |  |  |
| `content` | string, min length `1` |  |  |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
