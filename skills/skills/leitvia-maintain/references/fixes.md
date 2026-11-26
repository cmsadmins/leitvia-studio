# Fixes per area and rollback

Details for `leitvia-maintain`. Read the part of the area you fix, and "Rollback" only when a person asks to undo a package.

## Broken links

`broken-link` findings name each internal link in the content of a post that leads to no page of the site, or to the old address of a renamed post. The audit requests nothing; external links are not checked, list them in the report as not checked.

Repair them per post with one call of `leitvia/repair-links`: `post`, `base_hash` (the `hash` of `leitvia/get-page-outline`), `auto: true` for links to the old address of a renamed post (WordPress keeps the old slugs of posts, not of pages), and `links` with `from` and `to` pairs for the rest. The `to` of a pair is an address you checked on the public site: it answers 200 and is the page the link text names. When no page fits, ask the person (checkpoint **Link targets**). The result lists `repaired`, `external`, `unresolved` and `empty` (links without target); `leitvia_invalid_input` with reason `nothing_to_repair` means nothing was left to repair.

The audit covers the content of posts, not header, footer or navigation. Open the public pages and check the internal links there once (GET, no external links). Broken links in the header, footer or navigation: report them; they need `leitvia/update-navigation` or `leitvia/update-template-part` in the area `design`, which a person decides.

## Alt text

For each `alt-text` finding read the block with `leitvia/get-block` (`post`, `block`): `attributes.id` is the attachment, `inner_html` holds the image URL. Look at the image and its place on the page and write the alt text yourself: what the image shows that matters here, in one short sentence, in the language of the page, without "image of". An image that only decorates, because the text around it carries the meaning, gets no description: mark it with `leitvia/update-block` and `image: { "id": <attachment>, "decorative": true }`.

Call `leitvia/set-alt-text` with `image`, `alt`, `post`, `block` and the `hash` of the post as `base_hash`. Without `post` and `block` it sets the alt text of the attachment in the media library only, which fixes no page. When the AI plugin of WordPress offers the ability `ai/alt-text-generation` and the person agreed that images go to the AI provider of the site, you may use it for a first draft; read and correct the draft before you send it.

## Deprecated patterns

For each `deprecated-pattern` finding: `leitvia/get-page-outline` for the `hash`, then `leitvia/replace-pattern` with `post` and `base_hash` (all deprecated sections of the post in one call). Texts and button links move to the replacement in order per block type; plain text only, inline links in paragraphs do not move. Read the outline of the result in the package diff (`leitvia/get-package`) and name in the report what the person should compare. `leitvia_no_replacement` (`retry: never`): the pattern has no replacement; ask the person (checkpoint **Section without replacement**).

`successor-pattern` (notice): when the newer form is wanted, `leitvia/replace-pattern` with `block` and no `pattern`, one section per call; content moves over by role ([sections with icons](../../leitvia-page/references/icon-sections.md)), then set the icon of each entry.

A section into a pattern with `pattern` and `carry` (also its own pattern, to get back a group such as the key figure of `leitvia-studio/content-process-timeline`): `leitvia/get-pattern` returns `outline` with the path IDs of the pattern, and `edits` name those. Edits apply first; carry then fills the rest from the old section and leaves the blocks the edits changed, so an edited group stays although the old section had no counterpart. A group of the pattern without counterpart and without edit goes as a whole.

## Outdated content

Texts go stale: figures copied from another source (counts, versions, test numbers), dates, opening hours, names of people who left. Compare them only with a source the person named or a read ability of the site (for example `leitvia/get-contract` or `total` of `leitvia/list-patterns` for figures about the theme). Change a text with `leitvia/update-content` (`post`, `base_hash`, `blocks` with `block` and `text`, `url` for buttons); a text that holds a link keeps it only when the new text still contains the old link text, otherwise use `leitvia/update-block` with `link_text`. In a text with several links or `strong`, `text` is refused with `leitvia_static_markup`: change only the words with `replace_text: { "find": "129 €", "with": "149 €" }` (per block in `update-content` or in `update-block`), links and formatting stay; `find` stands once and within one run of text ([block edits](../../leitvia-page/references/block-edits.md)). When an add-on of the site keeps the products with their prices, a price inside a sentence follows them through the shortcode it names instead of a typed amount. Markers that a decision is open (`CHECK:`, `TODO`, `Lorem ipsum`, the sample texts of patterns) are no fix for you: list them in the report until a person resolves them.

## Rollback

Roll back only when a person asks for it, never to tidy up your own run.

1. `leitvia/list-packages` with `state: applied`: find the package the person means and confirm it with them.
2. `leitvia/rollback` with `id`. It restores the snapshots of every applied operation, newest first.
3. `leitvia_rollback_conflict` (`retry: never`): someone changed a post after the package was applied. Nothing was rolled back. `data.conflicts` lists per operation both hashes and the `diff` (what changed since). Show the person the diff and stop; never overwrite a change of a person, and never rebuild the old state by hand.
4. `leitvia_needs_human`: the operations belong to an area that is not `autonomous` for you; the person rolls back in the inbox.

## Speed

The target on mobile is 100 in PageSpeed for performance, accessibility, best practices and SEO. The theme prints block styles inside the document and preloads only the body, heading and button fonts in the weight they use; Studio loads its script modules last, at low priority. Up to 150 KB of stylesheets go inside the document, smallest first, also those of plugins with files on this site. A `link rel="stylesheet"` that remains is a large plugin stylesheet (WooCommerce keeps a few on shop pages) or one from another host: name it in the report. Scores vary between runs, so judge from the render-blocking list and the filmstrip, not from one number.

What stays with the site, for the person to decide: font files uploaded without a Latin subset (one file above 30 KB delays the first paint) and missing cache lifetimes for static files on the server. List both when you find them.
