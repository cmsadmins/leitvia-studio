# Block markup for classic HTML

Markup that `leitvia/update-content` accepts as `content`, one example per kind of classic HTML. Blocks are separated by an empty line. Keep the texts, link targets, image IDs and sources of the source; only the wrapping changes.

Blocks of a type that is not registered (`acf/*`, blocks of a plugin that is gone) render nothing: `leitvia/audit-site` and the findings of a write report them as `leitvia_unregistered_block` with types and count. Rebuild each from the text in its saved HTML with the blocks below; never leave one in a page that goes live.

Shortcodes that no plugin handles (rests of page builders such as `[vc_row]`, `[us_btn]`, `[et_pb_section]`, `[fusion_text]`) show as text: the findings of a write warn with `leitvia_unknown_shortcode`, name and block, and `leitvia/get-page-outline` lists them under `shortcodes`. Take their text into the blocks below and drop the shortcode tags.

## Paragraph and heading

```html
<!-- wp:paragraph -->
<p>For larger orders write to <a href="mailto:orders@example.org">orders@example.org</a> two days ahead.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Why it takes so long</h2>
<!-- /wp:heading -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Rye</h3>
<!-- /wp:heading -->
```

## List

```html
<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li>Shape the rye loaves.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Heat the stone oven to 260 degrees.</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->
```

Without `{"ordered":true}` and with `<ul class="wp-block-list">` for a bulleted list.

## Image, with caption, decorative

`id` is the N of `wp-image-N` in the source.

```html
<!-- wp:image {"id":4,"sizeSlug":"large","linkDestination":"none","align":"center"} -->
<figure class="wp-block-image aligncenter size-large"><img src="https://example.org/wp-content/uploads/sourdough-loaf.png" alt="A round sourdough loaf with a dark crust on a wooden board" class="wp-image-4"/></figure>
<!-- /wp:image -->

<!-- wp:image {"id":5,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://example.org/wp-content/uploads/stone-oven.png" alt="The stone oven of the bakery" class="wp-image-5"/><figcaption class="wp-element-caption">The stone oven at four in the morning</figcaption></figure>
<!-- /wp:image -->

<!-- wp:image {"id":7,"sizeSlug":"large","linkDestination":"none","metadata":{"leitviaDecorative":true}} -->
<figure class="wp-block-image size-large"><img src="https://example.org/wp-content/uploads/divider.png" alt="" class="wp-image-7"/></figure>
<!-- /wp:image -->
```

Leave `width` and `height` of the source out: the image then keeps its own proportions in every column. A fixed height without `aspectRatio` stretches the image (warning `leitvia_image_fixed_height`). For a crop set both, `"aspectRatio":"4/3","scale":"cover"`, and the image style `aspect-ratio:4/3;object-fit:cover`. Images already written with a fixed height: `leitvia/update-block` with `attributes` `{"height":null}` (and `"width":null`) per image block.

## Columns

`verticalAlignment` goes on each `core/column` as well as on `core/columns`; core aligns only columns that carry it. A write copies the value of `core/columns` to columns without one of their own, visible in the package.

`align` `full` only on blocks at the top level of the content. A group, cover or band inside a column, details, tabs or a card in a grid takes no alignment: it cannot reach the edge of the window there, so theme 1.2.41 and later show it with the normal edge, older themes with the padding of the whole window.

```html
<!-- wp:columns {"verticalAlignment":"center"} -->
<div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center"><!-- wp:paragraph -->
<p>Text beside the image.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
```

## Table

```html
<!-- wp:table -->
<figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th>Day</th><th>Shop</th></tr></thead><tbody><tr><td>Saturday</td><td>7:00 to 14:00</td></tr></tbody></table></figure>
<!-- /wp:table -->
```

## Quote

```html
<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>The oven tells you when it is ready, not the clock.</p>
<!-- /wp:paragraph --><cite>Head baker</cite></blockquote>
<!-- /wp:quote -->
```

## Embed

The public URL of the video, not the URL of the iframe. Leitvia Studio shows the consent placeholder in its place until the visitor agrees.

```html
<!-- wp:embed {"url":"https://www.youtube.com/watch?v=aqz-KE-bpKQ","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=aqz-KE-bpKQ
</div></figure>
<!-- /wp:embed -->
```

For Vimeo: `"providerNameSlug":"vimeo"`, class `is-provider-vimeo wp-block-embed-vimeo`, URL `https://vimeo.com/<id>`.

## Separator, code, more

```html
<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->

<!-- wp:code -->
<pre class="wp-block-code"><code>flour 1000 g, water 700 g</code></pre>
<!-- /wp:code -->

<!-- wp:more -->
<!--more-->
<!-- /wp:more -->
```

## Explanation of a term

A hover explanation of the source, longer than one sentence, right after the paragraph with the term.

```html
<!-- wp:details -->
<details class="wp-block-details"><summary>What is a sourdough starter?</summary><!-- wp:paragraph -->
<p>A mix of flour and water in which wild yeast and lactic acid bacteria live. It raises the dough and gives the bread its sour taste.</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->
```
