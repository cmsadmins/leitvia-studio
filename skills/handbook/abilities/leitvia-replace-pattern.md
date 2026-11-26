# leitvia/replace-pattern

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Replace pattern.** Replaces top-level sections of one post with another pattern. Without pattern the sections that use a deprecated pattern (metadata.patternName) get the replacement the contract names, all of them or the one given by block; the one given by block also gets the successor of an older pattern (successor in leitvia/list-patterns, such as leitvia-studio/teaser-icon-areas for leitvia/teaser-services); leitvia/audit-site reports such posts, and a section without replacement is rejected with leitvia_no_replacement. With pattern the one section given by block gets that pattern, any approved one of leitvia/list-patterns, with edits at block IDs of its outline as in leitvia/compose-page (text, link, image, caption, catalog attributes such as the level of a heading, anchor, columns of a grid, remove, repeat and insert; they apply in order, and after a remove the later blocks of the same parent move up one place): so a section of an existing page changes its pattern. Texts and button links of the old section move over in order per block type (carry false keeps the texts of the pattern); a section of entries such as services or steps moves over entry by entry, with as many entries as before, headings, eyebrows, texts, links and icons by role (an eyebrow without a heading becomes the heading, labels count the steps), and texts and sample links of the pattern without a counterpart are removed, a group whose blocks all go as a whole. The edits name the path IDs of the pattern (outline of leitvia/get-pattern) and apply first; carry leaves the blocks they change, so they win and stay without a counterpart, such as a key figure the old section lacked. The HTML anchor of the old section does not move over, set it again with an anchor edit. The base hash is the hash of the post from leitvia/get-page-outline.

MCP tool `leitvia-replace-pattern`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `post` | integer, min `1` | yes | Post ID from leitvia/list-content. |
| `block` | string, min length `1`, max length `200` |  | Block ID or path ID of a top-level section from the outline. |
| `pattern` | string, min length `1` |  | Pattern slug such as leitvia/cta-split, or block:\<ID> for a Lab pattern, that replaces the section given by block. |
| `edits` | array of object { block\*, text, link_text, url, add_link, emphasis, strike, mark, replace_text, caption, anchor, bind, table, columns, image, video, remove, repeat, move, position, insert, attributes }, max items `250` |  |  |
| `carry` | boolean |  | With pattern: whether texts and button links of the old section move over before the edits; true by default. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
