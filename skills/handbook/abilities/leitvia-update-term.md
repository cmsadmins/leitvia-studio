# leitvia/update-term

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Update term.** Changes name and description of a category, a tag or a term of a taxonomy of the content model, such as the intro of a topic archive. The archive of the term shows its description below its title, the h1 of the page, so the description takes paragraphs, lists, links and headings from h2 down, never an h1. IDs, current descriptions and hashes come from terms_of of leitvia/list-content; send the hash of the term as base_hash. The slug stays, so the address of the archive stays. Product categories and tags change with leitvia/update-product-category. Visitors see the change, so where the area content is not autonomous it waits in a change package; leitvia/rollback undoes it.

MCP tool `leitvia-update-term`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `term` | integer, min `1` | yes | Term ID from terms_of of leitvia/list-content. |
| `name` | string, min length `1`, max length `200` |  |  |
| `description` | string, max length `10000` |  | Intro of the archive as HTML (p, h2 to h4, ul, ol, a, strong, em), "" for none. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
