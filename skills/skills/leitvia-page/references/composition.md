# Composition rules and templates

Details for `leitvia-page`. Read "Rules" when you score an outline, "Templates and the h1" before you build.

## Rules

`leitvia/check-composition` scores an outline with the rules of the contract (`compositionRules`):

- no two neighbors with the same layout and tone (`noAdjacentRepeat`); the same pattern twice in a row passes on another ground or with its media on the other side;
- a break at least every three sections: an image, accent or ink tone, a full bleed layout, `leitvia/content-quote` or a media section (`breakWithin`, `breakWhen`);
- accent sections at least two sections apart (`accentMinGap`), and in practice one accent band per page;
- at most two sections of the same density in a row (`maxDensityRun`); a media section stands outside the run and starts it anew;
- a hero only as the first section and only on a template without a printed title (`heroFirstOnly`, `heroTemplate`);
- at most `maxSections` sections; frames (header, footer) do not count.

A saved page (`post`) counts as built. The tone of a section is the ground on its outer block: `ink` for `is-style-inverse` or an ink ground, otherwise the slug of its `backgroundColor`. A section with the block style Media right (`is-style-media-right`) counts as the mirrored layout. An outline (`slugs`) scores the tone and layout of the patterns: name every section whose ground your edits will change in `tones`, by its place in `slugs` from 0, such as `{ "2": "ink" }` for `is-style-inverse` on the third section or `{ "2": "surface-alt" }` for a `backgroundColor`, and every section that gets Media right in `mirrored`, such as `[ 3 ]`. Then the score of the outline matches the page. With `post`, both lie over what the page sets and answer what an edit would change.

A media section is a pattern with a video, an embed, a gallery or a wide image. It is a break, stands outside the density run and repeats only another media section of the same layout and tone.

Text between the sections is running text and no section: paragraphs, headings, lists, images, galleries, accordions and other single blocks, also in a plain group. It needs no pattern, costs no points and separates its neighbors, and an image, a gallery or a video in it relieves the run of plain sections. A layout built by hand (columns, cover, media and text) and an unknown slug stay `unknownPattern`; replace them with a pattern.

Each finding names the section by its number, for a saved page also by its block ID in `block` (use it for `leitvia/update-block` or `leitvia/replace-pattern`), and says what to change.

The page starts at 100 and loses the points in `compositionRules.scoring` per finding. On a template that starts with a hero (`page-landing`) running text above the first section is `unknownPattern`: move it below the hero. `min_score` (80 by default) is the score below which a write is held for a person.

The narrative order of the roles is `hook`, `context`, `proof`, `detail`, `offer`, `close` (`compositionRules.narrative`): open with the promise, explain, prove, go into detail, make the offer, close with the next step. Section padding already follows the density of each pattern; do not change spacing.

## Page frames with required sections

A frame may name `requires` and `recommends`: kinds of `sectionKinds` in the contract (`answer`, `facts`, `packages`, `tiles`, `steps`, `faq`, `related`) or roles (`hook`, `context`, `close`). A section fills the kinds of `provides` of its pattern and the kind of its role; `leitvia/list-patterns` with `provides` lists the patterns of a kind. `leitvia/check-composition` with `frame` (or a page composed with a frame) reports a missing required kind as `missingSection` (10 points) and a missing recommended one as `recommendedSection` (no points); both carry `kind`, the index after the last section, and suggestions with patterns to add (`add`).

| Frame | Requires | Recommends |
|---|---|---|
| `hub` (overview of a topic) | hook, answer, facts, packages, tiles, steps, faq, close | |
| `audience` (one group of people) | hook, context, related, steps, faq, close | |
| `service` | | answer, facts, faq, related |

- **Stage without a photo:** a service, product or other page below a hub that has no photo of its own opens with `leitvia/hero-text`: eyebrow, h1, lead and two buttons at the wide left edge, where the sections below start. `hero-centered` is for a landing page with one message for everyone; a lead symbol (Leitvia Pro `hero-symbol`) belongs to hubs and audience pages only. With a photo: `hero-split-image` or `hero-overlap`.
- **Answer and facts:** `leitvia/content-answer-facts` right after the hero: the main question as h2, the answer in one or two sentences, three to five facts (label and value) and the date line. Only facts of the person or the page; `remove` a fact you lack, never invent a price or a duration.
- **Tiles and related:** `teaser-icon-tiles`, `teaser-services` (with an icon per entry `leitvia-studio/teaser-icon-areas`, related pages as `leitvia-studio/teaser-link-cards`, see [icon sections](icon-sections.md)), `teaser-cards-3` or `teaser-pictogram-list`, one tile per page of the topic (hub) or per fitting service (audience), each linked.
- **After the build:** give every page of the topic its hub with `leitvia/set-page-structure` (`hub`, the ID of the hub page): the breadcrumbs then show Home, hub and page while the addresses stay flat. A service page with a price gets `service` with `name`, `price`, `unit` and `vat_included` there, only as the page states them. When an add-on of the site keeps the packages with their prices (`leitvia/get-content-model` lists its content type), link the page to them with `offerings` (`line`, the slug of their line, or `skus`) instead of typing the prices into `service`: the add-on then shows them and gives the page its service.
- **One section of two patterns:** a pattern that names its neighbor in `continues` (Pro: price cards after the row of their parts) counts with it as one section, so the pair is no `noAdjacentRepeat`.

## Patterns that need facts

Replace a planned section when it needs facts nobody gave you: prices (`leitvia/offer-pricing-3`, `leitvia/content-price-list`), figures (`leitvia/content-stats`), customer quotes and testimonials (`leitvia/content-quote`, `leitvia/proof-testimonial`), logos, team members, files to download (`leitvia/content-downloads`, [files to download](content.md#files-to-download)), videos (`leitvia/content-video`, [videos](content.md#videos)).

## Templates and the h1

- `page-landing` (frames `home`, `service`, `about`) prints no title: the hero carries the only h1.
- `page` (frame `contact`) prints the title as h1: start the content with h2 and do not use a hero.
- `page-docs` prints the title as h1 and a page list.
- `page-legal` prints the title as h1 in display across the wide width, then on the left the table of contents of the h2 (filled by Studio, lines between the entries, jump links) and on the right the text in a reading width of 46rem. For imprint, privacy policy and terms: switch an imported or written legal page to it with `template: "page-legal"` in `leitvia/update-content`; the text stays as it is, its h2 become the entries. Never rebuild a legal text from patterns to get this look.
- A page needs exactly one h1; the structure check rejects a write with none or several.
- Width and edge come from the template (`layout` in the contract): head and running text start on one left edge, the wide edge, at most `contentSize` wide. Never center running text and never set `align`, `contentSize` or a max width on a block; `wide` and `full` come with the patterns for media, grids and bands.

## Edits

Edits address blocks by path ID: `p:0` is the root block of the pattern, `p:0.1` its second child, `p:0.3.0` the first child of the fourth child, counted over blocks only (block comments, not HTML elements). One edit per block, with:

- `text`: plain text of a paragraph, heading, list item or button. Setting the text of a paragraph that holds a link drops the link; write a sentence that stands on its own.
- `url`: the link of a button.
- `image`: `{ "id": <attachment>, "alt": "..." }` or `{ "id": <attachment>, "decorative": true }` for an image or cover block.
- `caption`: text under an image, video, audio, gallery, table or embed; empty removes it.
- `attributes`: as in `leitvia/update-block`, such as `date`, `dateLabel` and `heading` of a timeline entry.
- `anchor`: HTML id for links to `#contact`, unique on the page.
- `columns`: column count of a grid or a gallery ([page rules](page-rules.md)).
- `insert`: a block of `leitvia/list-blocks` after the block, never markup: `{ "block": "leitvia/form-errors", "inner": [ { "block": "gravityforms/form", "attributes": { "formId": "1" } } ] }`, also with `attributes` and `anchor`. A static block starts from its `example`. Only blocks of `leitvia/list-blocks` go in: a paragraph, list or button of core does not. Take a pattern that has the block, or `repeat` one that is there and write its text.
- `remove: true`: takes the block out of its parent, for three tiles of a six-tile pattern. The outer block of a pattern and the last child of a parent stay.
- `repeat: <n>`: adds n copies right after the block, for more people or entries than the pattern has. Blocks with an HTML anchor cannot be copied.

`remove`, `repeat` and `insert` go in an edit of their own and never into `leitvia/update-content`. Edits apply in order, and each one sees the IDs the one before left: remove from the last block to the first, repeat first and then write the texts of the copies by their new IDs. Never rebuild the markup of a section for this. The checks run on the result; a layout that needs a certain number of entries is a reason for another pattern.

## After the page exists

Later changes go through `leitvia/update-content` (texts, title and links of several blocks; per block `text`, `url` and `link_text` as in compose-page, `add_link` and `caption`; `status` publishes a draft), `leitvia/update-block` (attributes, one image) and `leitvia/set-alt-text`, each with the `hash` of `leitvia/get-page-outline` as `base_hash`. All sections of a page that exists, new from patterns: `leitvia/compose-page` with `post` (one step, checked on the finished page). A new section on a page that exists: `leitvia/insert-section` with `sections` (patterns and edits as in compose-page) and its place, `after` or `before` a top-level ID of the outline or `position` `start` or `end`. The same ability moves a section (`move` with its ID and the place), `leitvia/remove-section` takes one out. Never send the whole content again to add, move or drop a section. After it, run `leitvia/check-composition` on the new order of patterns. Another page template for a page that exists, for example `page-landing` once a hero carries the h1: `template` of `leitvia/update-content`, a page template of the contract; the page needs exactly one h1 with the new template ([templates and the h1](#templates-and-the-h1)). A hero for such a page: `template` of `leitvia/insert-section` in the same call ([block edits](block-edits.md)). New section designs belong to the skill `leitvia-pattern`.

New links on words of a text, captions, status, text attributes of static blocks, posts per page of a list, captions of a video, another pattern for a section, the parent page and removing a page: [block edits](block-edits.md).
