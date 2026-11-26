# Wording stays, form is new

Details for `leitvia-migrate` on texts and links, for classic content and for a [source outside this site](external.md). It adds to [compare before you write](translate.md#compare-before-you-write).

A migration keeps the words of the source and gives them a new form. Sentences are never reworded, shortened or extended, and style and spelling of the source stay, its mistakes included (list them in the report). Allowed: structure, lists and tables made from enumerations the text already has, moving sections, and the blocks of the [tag to block table](translate.md#tag-to-block). Long posts get the form of [long texts](../../leitvia-page/references/long-texts.md): table of contents, fact box from facts the source states, reading breaks. A heading you wrote yourself, where the source had none, is marked in the report as a suggestion for the person.

For published pages the [page check](../../leitvia-a11y/references/page-check.md) with `--old` compares words, links and shortcodes of all pages at once; the commands below are for a single page or the preview of a package.

Fixed step before the person reviews the package (checkpoints **Sample of three** and **Package applied**): compare source and target of every page as plain text, without markup and whitespace. Save the public page of the source and of the target (the preview of the package) as `source.html` and `target.html`, cut both to the main content, and run:

```bash
diff <(sed 's/<[^>]*>//g' source.html | tr -s '[:space:]' ' ') <(sed 's/<[^>]*>//g' target.html | tr -s '[:space:]' ' ')
```

The only differences allowed are the headings marked as suggestions, the fact box, alt texts and the entries of the table of contents; each one goes into the report. Any other difference is fixed before the review.

**Compare with the rendered page.** The source text is the public page as a visitor sees it, not `post_content`: reusable blocks (`core/block` with `ref`), ACF blocks and shortcodes add text that the saved content does not hold (a donation appeal or promo cards vanish unnoticed otherwise). Resolve each reusable block before the migration: write its content inline into the post; when it holds blocks of another plugin, rebuild them from their text with the blocks of the theme and watch for `leitvia_unregistered_block`. Compare per post, and list every part that only the rendered page had.

**No shortcode left.** Fixed step on the same `target.html`: the rendered page holds no shortcode as text; `grep -oE '\[/?[a-z][a-z0-9_-]*( [a-z_-]+=|/?\])' target.html` must find nothing besides placeholders the source shows as text. Each hit is a shortcode no plugin handles (`leitvia_unknown_shortcode`): rebuild its content with blocks, or list it as open when only a plugin can render it (a form, a list of payment methods).

## Links stay

Fixed step next to the text comparison, on the same `source.html` and `target.html`: every internal link and every image link of the rendered source is in the target, with the same destination. A link whose page moved counts when the move has a redirect (old address to new one); list each such pair in the report.

```bash
# Destinations of links on this site: same host or relative, no anchors, mail or phone links.
links() { tr '\n' ' ' < "$1" | grep -oE '<a [^>]*href="[^"]*"' | sed -E 's/.*href="([^"]*)"/\1/; s#^https?://'"$2"'##' | grep -vE '^(#|[a-z]+:)' | sort -u; }
# Destinations of links that hold an image, in order.
imagelinks() { tr '\n' ' ' < "$1" | grep -oE '<a [^>]*href="[^"]*"[^>]*>[[:space:]]*(<(picture|source|span|figure)[^>]*>[[:space:]]*)*<img' | sed -E 's/^<a [^>]*href="([^"]*)".*/\1/; s#^https?://'"$2"'##'; }
comm -23 <(links source.html old.example) <(links target.html new.example)
diff <(imagelinks source.html old.example) <(imagelinks target.html new.example)
```

Use the host of each site for `old.example` and `new.example`. Every line of `comm` is an internal link the target lost, every line of `diff` an image link that is missing, extra or points elsewhere; fix each one before the review.

**A linked image stays a link.** An image inside a link in the source becomes an image with that link (`href` on `core/image`), a card or a teaser that links the same destination. It never becomes a gallery or an image with lightbox: that drops the link. Lightbox is only for images the source shows without a link.
