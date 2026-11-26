# leitvia/set-seo-titles

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set SEO titles.** Sets whether titles in search results end with " | " and the site name, without an SEO plugin. site_name true (the default) adds it to the titles of pages, posts, entries and archives, stored with leitvia/update-seo or built by WordPress, as long as the whole title keeps to 60 characters and does not name the site already; the front page keeps its stored title, and without one shows the site name and the tagline joined by " | ". false leaves a stored title as written and the others as WordPress builds them. base_hash is empty. Like every setting it waits in a change package for a person.

MCP tool `leitvia-set-seo-titles`, REST DELETE. Annotations: readonly false, destructive true, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `site_name` | boolean | yes | true adds the site name where it fits, false leaves titles without it. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
