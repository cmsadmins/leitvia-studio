# leitvia/compose-page

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Build. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Compose page.** Creates a page from patterns in the order of a page frame of the contract, or rebuilds an existing page (post). With post all its sections are replaced in one step, and the checks (one h1, structure, composition) see only the finished page, never a state in between. Plan it with leitvia/plan-page first; base_hash is empty for a new page and the hash of leitvia/get-page-outline for post. The page remembers its frame, so check-composition counts the sections the frame requires (hub, audience) or recommends (service). Per section texts (also quote citations and details summaries), links, images, captions, catalog attributes, an HTML anchor and the columns of a grid at block IDs of the pattern outline can change, remove and repeat take blocks from a parent or add copies, for fewer or more entries than the pattern, and insert adds a block of leitvia/list-blocks after a block, given as name with attributes, anchor and inner blocks, never as markup (text with link_text and url for a link inside it, table for the cells of a table, any number of list items as inner); table fills the head, rows and foot of a core/table or leitvia/comparison-table accessibly; a text that would drop a link is refused. Edits apply in order. Each section keeps metadata.patternName (core/block/\<ID> for a Lab pattern). Unknown patterns are rejected with a pointer to leitvia/list-patterns, deprecated ones with their replacement, Lab patterns that are not approved (also through a part) and patterns of the sources site and plugin before their approval in the Lab with leitvia_pattern_not_approved. Later changes go through leitvia/update-content and leitvia/update-block, further sections through leitvia/insert-section.

MCP tool `leitvia-compose-page`, REST POST. Annotations: readonly false, destructive false, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `title` | string, min length `1`, max length `200` | yes | Title of the new page, or the new title of the page of post. |
| `post` | integer, min `1` |  | ID of an existing page (or post for the frame article) to rebuild: its content becomes the sections, its address stays unless slug is given. |
| `frame` | string | yes | Type of a page frame from pageFrames in the contract, for example home or service. |
| `sections` | array of object { pattern\*, edits }, min items `1`, max items `30` | yes | Sections of the page in order, each a pattern with optional edits at block IDs of its outline. |
| `kind` | one of `imprint`, `privacy`, `terms`, `withdrawal`, `accessibility`, `editorial`, `contact`, `authors`, `about` |  |  |
| `slug` | string, pattern `^[a-z0-9-]+$` |  |  |
| `parent` | integer, min `1` |  | ID of the parent page. |
| `template` | string |  | Page template of the contract instead of the one of the frame, for example page-docs. |
| `type` | one of `post`, `page` |  | For the frame article only: page makes a guide or long read under a page address (template page-article: title, byline with author and dates, author box, and an Article in the structured data), post (the default) a post. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
