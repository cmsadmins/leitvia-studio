# leitvia/update-seo

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Update SEO data.** Writes SEO title, meta description, robots and Open Graph of one post through the active SEO plugin. The heading of the page stays. og_title (at most 90 characters) and og_description (at most 200) are the title and text of the preview when the page is shared, og_image its image as the ID of an image from leitvia/list-media (0 hands it back); without them the preview takes the SEO title and description and the featured image, else the logo or site icon. Without an SEO plugin Leitvia Studio prints them as Open Graph and Twitter Card tags. It covers posts, pages and entries in Yoast SEO, Rank Math, SEOPress or The SEO Framework; without an SEO plugin Leitvia Studio stores them and prints them in the head, the title as written, followed by " | " and the site name where it fits (leitvia/set-seo-titles); with another SEO plugin it is rejected with leitvia_no_seo_plugin. Read the post with leitvia/get-seo first and send its hash as base_hash. A title has at most 60 characters (the front page 75, for the name of the site and its claim), a description 50 to 160; an empty string hands the field back to the template of the plugin, without a plugin to what WordPress prints. Say only what the page says: every number in title or description must appear in the title, excerpt or content of the post. A star rating (a number beside a star sign or written as 4.8/5) needs the confirmed aggregate_rating of leitvia/set-organization and must equal its value; its count of reviews counts as said. Autonomy and change packages work as for leitvia/update-content; a live write gets a change package for leitvia/rollback.

MCP tool `leitvia-update-seo`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `post` | integer, min `1` |  | Post ID from leitvia/list-content. |
| `archive` | string, max length `20` |  | In place of post: the key of a content type whose archive (the overview at its address, such as /docs/) takes the title and description, without an SEO plugin only. base_hash is empty then; like every setting it waits in a change package for a person. |
| `title` | string, max length `200` |  |  |
| `description` | string, max length `500` |  |  |
| `noindex` | boolean |  | Keep the page out of search results. false hands it back to the default of the plugin. |
| `nofollow` | boolean |  | Ask search engines not to follow the links of the page. |
| `og_title` | string, max length `200` |  |  |
| `og_description` | string, max length `500` |  |  |
| `og_image` | integer, min `0` |  | ID of an image from leitvia/list-media for the preview when the page is shared, at least 1200 pixels wide; 0 hands it back to the featured image. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
