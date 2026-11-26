# leitvia/set-site-identity

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set site identity.** Sets the site title, the tagline, the logo and the site icon. logo and icon are image IDs from leitvia/list-media or leitvia/add-media, 0 removes them. The logo is the one core/site-logo shows in the header and WordPress hands out as custom logo, the title is the text of core/site-title and the start of every page title in the browser, the tagline that of core/site-tagline; so the header needs no image block for the logo. The icon is the small image of browser tabs and bookmarks, square and at least 512 pixels wide; notice says so otherwise. Only the fields you send change, an empty string clears the tagline. base_hash is empty. Like every setting it waits in a change package for a person.

MCP tool `leitvia-set-site-identity`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `title` | string, min length `1`, max length `200` |  |  |
| `tagline` | string, max length `300` |  |  |
| `logo` | integer, min `0` |  |  |
| `icon` | integer, min `0` |  |  |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
