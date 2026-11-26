# leitvia/import-post

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Build. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Import post.** Imports one post, page or entry of the content model from another site for a migration: source is its old address, content its HTML. The HTML becomes core blocks; shortcodes of WPBakery and Impreza (vc_row, vc_column, vc_column_text, vc_single_image, vc_btn, us_text, us_btn and others with text) become blocks, every other shortcode leaves only its text. Only shortcodes count: those registered here, of core or of a known page builder or form plugin; any other text in square brackets stays, and inside pre and code nothing changes. Images of the source host (and of media_hosts) are loaded into the media library once, the featured image from featured_image.url too; links to the source host become paths of this site, heading levels below the title close their gaps. author takes a user ID, login or email of a user of this site; parent an ID or parent_source, the old address of an imported parent. A second call with the same source updates that post instead of creating a new one. The write follows leitvia/create-content or leitvia/update-content with their checks and autonomy. Script, style, noscript, template and form elements go with their content (report.removed counts them per tag); report lists the loaded images and everything that did not carry over. dry_run shows the block markup and the report without loading or writing anything.

MCP tool `leitvia-import-post`, REST POST. Annotations: readonly false, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `source` | string, max length `2000`, format `uri` | yes | Address of the post on the old site, http or https; the key that makes a second import update the same post. |
| `post_type` | string, max length `40` | yes | post, page or a post type of the content model. |
| `title` | string, min length `1`, max length `200` | yes | Title of the post on the old site, without the name of the site. |
| `content` | string, max length `500000` |  | HTML of the post as the old site stores it, shortcodes included. |
| `excerpt` | string, max length `1000` |  |  |
| `slug` | string, pattern `^[a-z0-9-]+$` |  |  |
| `date` | string, pattern `^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$` |  | Date in the time zone of the site, YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS. |
| `status` | one of `draft`, `pending`, `publish` |  |  |
| `author` | integer or string |  | User ID, login or email of an existing user of this site; without a match the post belongs to the user who writes it and report.author says so. |
| `author_name` | string, min length `1`, max length `100` |  | Name of an author of the old site who has no account here, as the old site showed it: the post shows it as its author name and in its structured data (Person), and no user is created. Written on every import, so a second one can add or correct it; leitvia/update-content takes it too. |
| `author_page` | integer, min `0` |  | ID of a published page about the author of author_name, such as an about or team page: the author name links to it and the structured data (Person) names it as url, which Google News asks for. 0 removes it. |
| `parent` | integer, min `1` |  |  |
| `parent_source` | string, max length `2000`, format `uri` |  | Old address of the parent, imported before with leitvia/import-post or waiting in the open package with the same package key; instead of parent. Without such a parent the import is rejected, never placed at the top level. A second import with it moves the post under that parent. |
| `terms` | object |  | Terms by taxonomy, such as {"category": ["News"], "post_tag": ["events", 12]}: IDs as numbers, names or slugs as text. They replace the terms of that taxonomy. |
| `kind` | one of `imprint`, `privacy`, `terms`, `withdrawal`, `accessibility`, `editorial`, `contact`, `authors`, `about` |  | Kind of an imported page, such as imprint or privacy for a legal text, as leitvia/compose-page sets it. A legal kind keeps the page at suggest: the import waits in a change package for a person. |
| `featured_image` | object { url\*, alt } |  |  |
| `images` | object |  | Attachment ID on the old site => address of that image, for image shortcodes such as vc_single_image image="123". |
| `media_hosts` | array of string, max length `253`, max items `5` |  | More hosts images may come from, such as the CDN of the old site. |
| `seo` | object { title, description } |  | SEO title and meta description of the old site, written as by leitvia/update-seo. |
| `faq` | boolean, default `true` |  | Turns the FAQ part of the post into the block leitvia/faq with FAQPage structured data: after a heading such as FAQ or Frequently asked questions, each question as a heading one level below it or a paragraph in bold, its answer the paragraphs and lists up to the next question; report.faq counts the questions. false keeps headings and paragraphs. |
| `dry_run` | boolean |  |  |
| `package` | string, max length `64` |  | Key of the task: every import of a migration with the same key goes into one change package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
