# leitvia/set-privacy-page

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set privacy policy page.** Makes an existing page the privacy policy page of the site; 0 clears it. Contact forms, comment forms and the footer link to it. base_hash is empty. The page must exist, so create it first and wait until a package with it is applied. Like every setting it waits in a change package for a person. notice says so while the page is not published, and leitvia/audit-site reports contact forms without such a page.

MCP tool `leitvia-set-privacy-page`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `page` | integer, min `0` | yes | ID of a page from leitvia/list-content, 0 to clear the setting. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
