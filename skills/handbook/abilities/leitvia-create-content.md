# leitvia/create-content

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Build. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Create content.** Creates a post, a page or an entry of the content model from block markup or patterns; base_hash is empty. Post types come from leitvia/get-content-model, sections work as in leitvia/compose-page. The content runs through the same checks as leitvia/update-content. Terms take IDs, names or slugs; a name no term has yet creates the term with the content, in categories at the top level and only for a user who may manage them, and in a change package it stays a name until the package is applied. Fields follow the content model; required fields must be set before publishing. rows fill the repeaters of an entry. The featured image needs alt text (leitvia/set-alt-text) or the decorative mark, or names the file of an image that waits in the same package from leitvia/add-media. The status is capped by the autonomy level. ai_generated adds a visible note to the text.

MCP tool `leitvia-create-content`, REST POST. Annotations: readonly false, destructive false, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `post_type` | string | yes | post, page or a post type of the content model. |
| `title` | string, min length `1`, max length `200` | yes | Title of the new post. |
| `content` | string, min length `1` |  | Block markup; not together with sections. |
| `sections` | array of object { pattern\*, edits }, min items `1`, max items `30` |  | Sections of the page in order, each a pattern with optional edits at block IDs of its outline. |
| `excerpt` | string, max length `1000` |  |  |
| `slug` | string, pattern `^[a-z0-9-]+$` |  |  |
| `parent` | integer, min `1` |  | ID of the parent entry of the same hierarchical post type. |
| `date` | string, pattern `^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$` |  | Date in the time zone of the site, YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS. |
| `template` | string |  | Template of the contract: page templates such as page-docs for pages, single templates for the other types. |
| `kind` | one of `imprint`, `privacy`, `terms`, `withdrawal`, `accessibility`, `editorial`, `contact`, `authors`, `about` |  | Kind of a page. |
| `author` | integer, min `1` |  | User ID of the author, as author in leitvia/list-content shows it; the user must be able to edit this post type. Without it a new post belongs to the user who writes it. An author without a display name of their own shows as the name of the site, never as the login. |
| `author_name` | string, max length `100` |  | Name of an author without an account here, for example from a migration: the post shows it as its author name and in its structured data (Person), and no user is created. Empty removes it, then the post shows its user again. |
| `author_page` | integer, min `0` |  | ID of a published page about the author of author_name, such as an about or team page: the author name links to it and the structured data (Person) names it as url, which Google News asks for. 0 removes it. |
| `terms` | object |  | Terms by taxonomy, such as {"category": ["News"], "post_tag": ["events", 12]}: IDs as numbers, names or slugs as text. They replace the terms of that taxonomy. |
| `fields` | object |  | Values of the fields and relations of the content model by meta key; null empties a field. |
| `rows` | object |  | Rows of the repeaters of an entry by repeater key, such as {"event_agenda": [{"title": "Welcome", "session_time": "09:00"}]}: title and the field keys of the repeater from leitvia/get-content-model. A list replaces the rows of its repeater in this order, an empty list removes them; repeaters you leave out stay. |
| `featured_image` | object { id, decorative, file } |  |  |
| `status` | one of `draft`, `publish`, `future` |  |  |
| `comment_status` | one of `open`, `closed` |  | Whether visitors may comment; without it a new post is closed for comments, whatever the default of the site. Pingbacks and trackbacks are closed on every new post. |
| `ai_generated` | boolean |  | Whether AI wrote the text; it then shows a note that says so. |
| `sources` | array of string, max length `2000`, format `uri`, max items `20` |  | URLs of the sources of the text, stored with it. |
| `seo` | object { title, description } |  | SEO title and meta description, checked and written as by leitvia/update-seo. With an unsupported SEO plugin they are left out and notice says so. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
