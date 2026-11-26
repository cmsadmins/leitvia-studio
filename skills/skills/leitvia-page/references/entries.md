# Entries of a content type: overview and entry pages

Details for `leitvia-page`. Read it when the briefing asks for entries of a post type of the content model (courses, events, team members) and a page that lists them. The type and its fields come from `leitvia-setup` ([content model](../../leitvia-setup/references/content-model.md)); they must be live (package applied) before entries exist.

## Values of fields on the front end

Fields reach the page through block bindings of Leitvia Studio. A block whose `metadata.bindings` names a source shows the value instead of its saved attribute:

| Source | `args` | Shows |
|---|---|---|
| `leitvia/meta` | `{"key": "<field key>"}` | The field of the current entry: dates and times in the format of the site, the label of a `select` option, Yes or No for a boolean. |
| `leitvia/relation` | `{"relation": "<relation key>", "field": "title"}` | The related entry: `title`, `url`, `excerpt` or a field key of the target type. `index` picks the second or later entry of a `multiple` relation. |
| `leitvia/setting` | `{"key": "<setting key>"}` | A site setting with `bindable: true`, on any page. |

Bind only the attributes WordPress binds; `leitvia/get-content-model` lists them under `bindings`, and the check `leitvia/bindings` refuses others:

| Block | Attribute | Field |
|---|---|---|
| `core/paragraph`, `core/heading`, `core/list-item` | `content` | text, number, date, select, boolean |
| `core/button` | `text`, `url` | text; a `url` field or `leitvia/relation` with `field` `url` |
| `core/image` | `url` and `alt` (and `id`), `caption`, `title` | an `image` field, the same key on each; the caption and title come from the media library |
| `core/post-date` | `datetime` | a `date` or `datetime` field; the `format` of the block sets the display |
| `core/list` | `content` | a `textarea` field, one item per line (Leitvia only, see below) |

Other blocks and attributes, such as a cover image or the link of a paragraph, keep their saved markup.

Write the saved text of a bound block empty (`<p></p>`); a value that is missing then shows as an empty line, never as a sample text. Visitors see values of published entries only. `leitvia/meta` also takes `prefix` and `suffix`, text around a value that is not empty, such as `"suffix":" €"` for a price.

A list of an entry, such as what a service includes, is a `textarea` field with one item per line (`"fields": {"service_scope": "Audit\nReport\nTraining"}`) and a `core/list` bound like a paragraph: `{"metadata":{"bindings":{"content":{"source":"leitvia/meta","args":{"key":"service_scope"}}}}}` on the list block, with one empty list item as saved markup. Each line becomes an item, an empty field shows no list. The editor shows the saved item, not the lines. Choose a repeater only when an item has fields of its own.

`leitvia/meta` and `leitvia/relation` read the entry in context: on its own page, in its template, or in a post template of a query loop. On a page or a post they show nothing, since pages have no fields; use `leitvia/setting` there, or a query of the type.

To change a bound value, send `fields` with `leitvia/update-content`, or `leitvia/update-site-values` for a setting. A text edit of the bound attribute is refused (`leitvia_invalid_input`, reason `bound`), since visitors would never see it.

A bare value such as `12` needs a label. On an entry page use the pattern `leitvia-studio/content-entry-facts` (below). In a row of the overview, put label and value side by side:

```html
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>Free places:</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"leitvia/meta","args":{"key":"course_seats"}}}}} -->
<p></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
```

## Entry pages

Create each entry with `leitvia/create-content`: `post_type` the type, `title`, `excerpt`, `fields` with the values (a relation takes a list of IDs, `[12]`), `terms` for its taxonomies, and `content` as block markup. Create the entries a relation points to first (the instructor before the course).

Leitvia Studio gives every public type a template `single-<type>`: breadcrumbs (with the [overview page](#overview-page) after Home), the title as h1, the excerpt, the featured image, the content, then a section "More <type>" with three other entries. It shows no byline, date or reading time. A theme or Site Editor template of the same name wins.

Compose the `content` of an entry page in this order:

1. Introduction: one or two paragraphs on what the entry offers and for whom (the excerpt above it is the summary, do not repeat it, and do not start the content with the featured image: the template shows both).
2. Facts: the markup of `leitvia/get-pattern` `leitvia-studio/content-entry-facts`, one row per field that matters (date, time, duration, price, free places, level). Keep two paragraphs per row, the label first and the value bound to the field: set `key` to your field keys, add or remove rows, keep `"suffix":" €"` on a price in euros. A relation such as the instructor is a row with `leitvia/relation`. The rows render as a description list.
3. Call to action: how to book or ask, for example `leitvia/cta-banner` with its button to the contact page. It spans the width of the entry like the image; keep the button text short ("Book this course"), the heading names the entry. Only link to pages that exist.
4. Nothing more: the template adds "More <type>".

The facts and the call to action carry no `metadata.patternName`: the composition check is for the sections of pages, and on an entry it counts the introduction as a section with an unknown pattern (score 50, a warning that sends the entry to a package).

A price in a `number` field is no content kind `prices` of the gateway: in mode `direct` it goes live like any field. When the price is a fact of the person, send the entry with the `package` key.

## Overview page

No recipe and no page frame lists entries of a type; the overview is a page you write as markup:

1. `leitvia/get-pattern` with `leitvia-studio/teaser-type-entries`. Its query lists posts.
2. In the markup set `"postType":"post"` to your type, change the heading, and replace `<!-- wp:post-excerpt {"excerptLength":30,"textColor":"ink-muted"} /-->` with the rows of the fields that matter in a list (date, level, instructor). Keep everything else as it is, `queryId` too: with a filter (`leitvia/query-filter`) or `leitvia/load-more` in the query, a query without its own `queryId` renders nothing, and the check `query-context` rejects it. Two queries on one page need two IDs.
3. `leitvia/create-content` with `post_type` `page`, `title` (the template `page` prints it as h1; the pattern starts with an h2), `content` the changed markup and your `package` key.

`leitvia/query-filter` filters in place in every query, with or without `enhancedPagination`: no page load, focus stays on the control, the status region announces the count, Back and Forward work, and the Apply button hides. Without JavaScript it stays a GET form with that button, so filtered addresses (`?qf-<queryId>-<facet>[]=<slug>`) can be shared. Add no script of your own. Put `core/query-no-results` in the query if the empty list needs more than the status message; `leitvia/load-more` and `core/query-pagination` keep working after a change.

The query of a page is live: it shows every published entry, newest first, so entries added later appear without changing the page. The query cannot sort by a field such as the date of a course. When the order matters, give the entries a publication `date` in reverse order of display (the first entry the newest); the template shows no date.

## Slugs

A public type has entry URLs under its `slug` (`/course/<entry>/`) and, with `hasArchive`, an archive at that slug. A page with the same slug collides with the archive and its subpages: `leitvia/define-content-type` refuses a slug that a published page or another type has. Give the type a singular slug and the overview page the plural (`course` and `courses`), and leave `hasArchive` off when the overview page lists the entries; the archive of the theme shows only titles and excerpts.

## Patterns and overrides

Abilities copy a pattern into the page, a synced one too, so WordPress pattern overrides (`core/pattern-overrides`) never apply there. A value that repeats on several pages is a bindable setting; a value per entry is a field.
