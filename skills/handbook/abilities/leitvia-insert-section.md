# leitvia/insert-section

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Insert section.** Inserts sections into the content of an existing post, page or entry, or moves one of its top-level sections; the rest of the content stays as it is. sections takes patterns with edits as in leitvia/compose-page, with the same checks; move takes the ID of a top-level section of the post instead. The place is after or before a top-level section (block ID or path ID from the outline) or position start or end; without a place the sections go to the end. wrap takes the ID of a top-level block instead, for example a shortcode or a block of another plugin, and puts it unchanged into a new section at its place: full width with the spacing of a section, the ground surface, surface-alt or inverse, and with heading an h2 above it. faq turns a range of top-level blocks (from and to, IDs from the outline) into one section of leitvia/content-faq at its place: each question is a heading, a paragraph that starts with a bold question (the rest of the paragraph starts the answer) or a short paragraph of its own that ends with a question mark, and the blocks up to the next question are its answer. The answers keep their blocks with code, bold, italics and links; the summary keeps the formatting of the question without links, and the id of a heading moves to its question. heading is the h2 of the section, intro the text below it (empty for none). template switches the post to another template of the contract in the same operation, so a page with the template page gets a hero with its h1 and page-landing, which prints no title, at once and keeps its other blocks as they are. An anchor of a new section must be unique on the whole page. Read the post with leitvia/get-page-outline first and send its hash as base_hash. leitvia/remove-section takes a section out, leitvia/replace-pattern gives one another pattern.

MCP tool `leitvia-insert-section`, REST POST. Annotations: readonly false, destructive false, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `post` | integer, min `1` | yes | Post ID from leitvia/list-content. |
| `sections` | array of object { pattern\*, edits }, min items `1`, max items `30` |  | Sections of the page in order, each a pattern with optional edits at block IDs of its outline. |
| `move` | string, min length `1`, max length `200` |  | Block ID or path ID of a top-level section of the post that moves to the place, instead of sections. |
| `wrap` | object { block\*, ground, heading } |  | A top-level block that goes unchanged into a new section at its place, instead of sections or move. |
| `faq` | object { from\*, to\*, heading\*, intro } |  | A range of top-level blocks with questions and answers that becomes one section of leitvia/content-faq at its place, instead of sections, move or wrap. |
| `after` | string, min length `1`, max length `200` |  | Block ID or path ID of the top-level section the new sections follow. |
| `before` | string, min length `1`, max length `200` |  | Block ID or path ID of the top-level section the new sections precede. |
| `position` | one of `start`, `end` |  | Start or end of the content, instead of after or before. |
| `template` | string |  | Template of the contract the post switches to in the same operation, such as page-landing for a page that gets a hero. Empty or default: the standard template. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
