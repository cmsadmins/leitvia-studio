# Translating classic HTML into blocks

Details for step 4 of `leitvia-migrate`. Markup examples for each block: [block markup](block-markup.md).

## Tag to block

| Classic HTML | Block |
|---|---|
| Text separated by an empty line, `<p>` | `core/paragraph`; a single line break inside stays as `<br>` |
| Inline `<a>`, `<strong>`, `<em>`, `<code>`, `<br>` | Stay inside the paragraph, heading, list item or cell |
| `<h1>` to `<h6>` | `core/heading`. The template prints the title as h1: an h1 in the content becomes h2, and a skipped level moves up (h3 after the title becomes h2) |
| `<ul>`, `<ol>` with `<li>` | `core/list` (`ordered` for `<ol>`) with one `core/list-item` per item; nested lists stay nested |
| `<img>` | `core/image` with `id` from `attachments` of `get-block` (the entry with this `url`), `src` and `alt` as in the source, character for character (`url` of `attachments` only finds the ID), `align` from `alignleft`, `aligncenter`, `alignright` |
| `[caption]<img> Text[/caption]` | `core/image` with the text as `<figcaption>` |
| `[gallery ids="1,2,3"]` | `core/gallery` with one `core/image` per ID |
| `<table>` | `core/table`; a first row of bold cells or `<th>` becomes `<thead>` with `<th>` |
| `<blockquote>` | `core/quote` with a paragraph inside; a `<cite>` becomes the citation |
| `<iframe>` of a video or audio service, a bare URL on its own line, `[embed]` | `core/embed` with the public URL of the item (YouTube `https://www.youtube.com/watch?v=<id>` for `/embed/<id>` of youtube.com or youtube-nocookie.com, Vimeo `https://vimeo.com/<id>` for `player.vimeo.com/video/<id>`). Leitvia Studio puts it behind the consent placeholder |
| `<hr>` | `core/separator` |
| `<pre>`, `<pre><code>` | `core/preformatted` or `core/code` |
| `<!--more-->` | `core/more` |
| Hover-only explanation of a term (glossary tooltip, `<abbr title>`, `title` or `data-*` text) | Visible text, since keyboard and touch cannot reach hover: one sentence in brackets after the term, a longer one as `core/details` after the paragraph, summary "What is <term>?" in the language of the page ([example](block-markup.md#explanation-of-a-term)). Also in product descriptions. No `core/footnotes`: no ability writes their meta, and products keep none |

Everything else (`<script>`, `<style>`, `<form>`, shortcodes, page builders) has no block in this skill; inline styles and map blocks follow [inline style](inline-style.md). Stop at checkpoint **Open items** and let the person decide; never wrap it in a Custom HTML or classic block.

## Images

- An image without alt text gets one: one short sentence from what the image shows and why it is on the page, using caption and text around it. An image that only decorates gets an empty alt and `"metadata":{"leitviaDecorative":true}` on the block. List every alt text you wrote.
- An image from another host is not fetched and must not load from there. Ask the person for the file and add it with `leitvia/add-media` as Base64 data, or leave it out with their consent (checkpoint **Open items**).
- Text burned into an image goes into the content as text; cut the image to the part without it with `leitvia/crop-media`, never outside the abilities. In a package a crop has no ID until applied.

## Compare before you write

Strip the tags of the source (the text of a caption and a hover explanation count, shortcode brackets do not) and of your block markup, collapse whitespace and compare: the two texts are the same, word for word. The images are the same attachments (or their crops), in the same order. Links point to the same targets. Only alt texts you added and heading levels you moved may differ, and both go into the report.

## Rejections

- `leitvia_unsafe_content` with `reason` `html_block` or `classic_block`: a `core/html` or `core/freeform` block slipped into the markup (`target.block` names it). Replace it with the core block of the table and send again.
- `leitvia_unsafe_content` with `reason` `markup`: an attribute or tag the content may not hold; `found` names block, tag, attribute and an excerpt. Remove it (`mso-*` styles and empty `o:p` of Word are dropped for you); when the content needs it, checkpoint **Open items**.
- `leitvia_check_failed`: the findings name the block and the check (alt text, link text, heading levels). Fix them and send again.
- `leitvia_stale_hash`: the post changed since you read it. Read it again with `leitvia/get-block`, translate the current state, send once more.
