# leitvia/set-redirects

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set redirects.** Sets the redirects of moved and removed addresses, for a migration or a new structure. Rows of from, a path of this site such as /old-page/, status and to. A from with a query, such as /?p=45 or /?attachment_id=123 of an old site, matches every request with these parameters and values (in any order) and comes before the row of its bare path; further parameters of the request, such as ?von= of a button, go along to to, and of several rows the one with the most parameters wins; a from without a query matches its path with any query. status 301 (the default) moves for good, 302 for a while; both need to, a path of this site or an https address. status 410 says the page is gone for good (search engines drop it faster than a 404) and takes no to; the visitor sees the 404 page of the theme. The list replaces the saved one (at most 1000 rows, read the current one with leitvia/get-seo); an empty list removes all. A request of a from path gets its answer before the page loads; a trailing slash and the case do not matter. Rows that lead back to a path they passed (a loop) are refused, as are the start page as from, a target equal to its from and a 410 with a target. Inactive while an SEO plugin is active, which brings its own redirects. base_hash is empty. Like every setting it waits in a change package for a person.

MCP tool `leitvia-set-redirects`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `redirects` | array of object { from\*, to, status }, max items `1000` | yes | Every redirect of the site; the list replaces the saved one. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
