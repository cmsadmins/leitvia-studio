# Animation on scroll

Details for `leitvia-pattern` and `leitvia-page`. Read it before you give a block an effect, or when an audit or a person asks about motion.

Leitvia Studio gives every block two attributes, core blocks included. `leitviaAnimation` is the effect: `none` (the default) or one of the values below. `leitviaMotion` is its strength: `subtle` or `bold`; without it the site default of the Studio settings applies (subtle unless the site owner changed it). Only the block comment carries the values; the saved HTML stays as it is, and Studio adds the classes `has-lv-animation is-lv-animation-<effect> is-lv-motion-<strength>` on the front end. The block `leitvia/reveal` (attribute `effect`: fade, rise, stagger) uses the same style and script. The attributes are missing on blocks without markup of their own and on the navigation: `core/html`, `core/shortcode`, `core/freeform`, `core/block`, `core/pattern`, `core/template-part`, `core/post-content`, `core/navigation` and its links, `core/page-list`, `leitvia/reveal`, `leitvia/mega-menu` and a few more. `leitvia/list-blocks` lists them, with their descriptions, in the attributes of the blocks of its catalog.

| Effect | What it does (subtle / bold) | Fits |
|---|---|---|
| `fade` | fades in | text, quiet sections |
| `rise` | fades in while it moves up 2.5rem / 5rem | text sections, single cards |
| `zoom` | fades in from 0.92 / 0.84 of its size | images, a single card, a figure |
| `slide-start` | fades in from 2.5rem / 5rem before the start of the line (left, right in right-to-left languages) | media and text side by side, the media half |
| `slide-end` | the same from the end of the line | the other half |
| `wipe-up` | uncovers itself from the bottom up (the whole element, never split into words) | headings, images, covers |
| `wipe-start` | uncovers itself from the start of the line | headings, images, covers |
| `blur` | fades in out of an 8 px / 16 px blur | images, a quote |
| `mask` | an image uncovers itself from its centre, 15 % / 30 % of each side | images, galleries |
| `stagger` | each direct child as `rise`, each when it comes into view itself; children that come into view together start 100 ms apart up to the sixth. A child with an effect of its own keeps it | cards, columns, icon lists, grids |
| `parallax` | bound to the scroll position: the background of a cover moves by up to 4 % / 8 % of its height, the line of a timeline (`leitvia/timeline`) draws itself along | `core/cover`, `leitvia/timeline` only |

Every reveal runs once, in 800 ms (subtle) or 1200 ms (bold) on a soft curve (GSAP power3.out, bold expo.out). Parallax only runs in browsers with scroll-driven animations; elsewhere the cover and the line stand still. Effects use opacity, transform, clip-path and filter only, so nothing moves the layout.

What a visitor sees: the script hides only blocks that start below the visible area when the page loads; what is in view stays as it is, so nothing flickers and LCP and CLS do not change. A hidden block starts once a fifth of it is above a line 12 % over the bottom edge of the window; a block taller than the window starts once a third of the window height of it is in view. Keyboard focus inside a hidden block and a jump to an anchor (the target and everything above it) show it at once, without the effect. Without JavaScript, with reduced motion (`prefers-reduced-motion: reduce`), with the switch "Motion off" of the accessibility bar, in print and in the editor every block is simply there. With the site setting "Motion off" Studio loads neither style nor script.

## Markup

The values go into the block comment, nothing else changes:

```html
<!-- wp:group {"metadata":{"name":"Services"},"leitviaAnimation":"fade","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">...</div>
<!-- /wp:group -->

<!-- wp:columns {"leitviaAnimation":"stagger","align":"wide"} -->
<div class="wp-block-columns alignwide">...</div>
<!-- /wp:columns -->

<!-- wp:image {"id":12,"sizeSlug":"large","leitviaAnimation":"mask"} -->

<!-- wp:leitvia/icon-list {"icon":"circle-check","leitviaAnimation":"stagger","fontSize":"lead"} -->
```

Key order as the editor saves it: `leitviaAnimation`, then `leitviaMotion`, come after the attributes of the block itself (`icon`, `id`, `sizeSlug`, `verticalAlignment`, `postType`), after `lock` and `metadata`, and before every attribute of the block supports: `align`, `className`, `style`, the colors, `fontSize`, `fontFamily`, `layout` and `anchor`. Leave out `"leitviaAnimation":"none"`, it is the default and the editor does not save it; leave out `leitviaMotion` (see below). A value outside the lists is ignored on the front end. As everywhere, copy the order from markup the editor saved and never sort keys.

## One motion per site

Every page of a site moves alike; strength and duration come from the site setting `motion` (`off`, `subtle`, `bold`).

- **Never `leitviaMotion`:** a block with a strength of its own runs longer or shorter than its neighbours.
- **Sections** get one entry effect for the whole site, `fade` as in every shipped pattern, never `rise` beside it; **cards and grids** `stagger`; **covers and timelines** `parallax`.
- **Striking effects** (zoom, slide, wipe, blur, mask): one kind per page, at most three blocks; shipped patterns use `mask` only, so add no other kind.
- **Nothing else moves** but counter, scroll story, slider, marquee and the effects and styles of add-ons (below). No custom animations, delays or durations.
- **More or less motion:** `leitvia/set-motion` (`bold`, `subtle`, `off`), never effects on more blocks.

`leitvia/audit-site` reports a page that breaks this as `leitvia/motion`.

## When to use it

- **Sparingly.** On a home page three to six sections at most, on other pages one or two, many pages none. Never every section: motion on every block distracts and slows reading. At most one striking effect (zoom, slide, wipe, blur, mask, parallax or anything bold) per screen height.
- **Never in the first screen.** No effect on the hero, the first section after it or the block that holds the largest image or heading of the first view (LCP), parallax included. On a short screen the second section is already in the first view. Nothing fades in on load either, text included: the hero heading is often the LCP element, and a start from opacity 0 delays it.
- **Never** on the header, the navigation, forms, notices and messages (form errors, announcement bar, consent, alerts), legal texts or content a visitor needs right away (opening hours of today, prices next to a checkout, a warning).
- **Calls to action never wait.** No effect on a buttons block or a band whose purpose is the button; in a `stagger` the main button is never one of the later children. A closing band with a call to action stays still.
- **One effect per section,** on its root block or on its grid, not on both and not nested. Text sections: the one entry effect of the site, `fade`, as in every shipped pattern. Cards, icon lists, columns, feature groups, bento and pricing grids: `stagger` on the block whose direct children are the cards or items (`core/columns`, `leitvia/icon-list`, the grid group), not on the outer band. Images and covers: `mask`, the one striking effect of the shipped patterns, at most three per page. Never split a heading into words.
- **Calm sectors** such as a law firm, a medical practice, a public authority, an association, an insurer or a funeral home: `fade` only, `subtle`, or no animation at all. When in doubt, none.
- **Tone of the brand:** lively brands (a restaurant, an agency, a shop for young buyers, software) get the site setting `bold`; a site that reads quiet stays `subtle`.

## Accessibility

- Motion never carries information: the content, its order and its meaning are the same without the effect. Never write a text that refers to the motion ("see the points appear").
- Reduced motion, Motion off, no JavaScript and print show everything at once; that is the state to judge contrast, reading order and focus in, and the state an accessibility check sees.
- A reveal runs once and lasts at most 1.2 s, parallax follows the scroll and stops with it (WCAG 2.2.2 needs no pause button for either); nothing flashes (2.3.1). The accessibility bar has the switch "Motion off" (2.3.3), stored in the browser of the visitor.
- A hidden block shows itself when it gets focus, so keyboard users never land in invisible content (2.4.7, 2.4.11).
- The theme adds a mild hover of its own: a card with a link lifts 2px with the `raised` shadow, linked images scale to 1.03 inside their frame, in 200 ms, the same for keyboard focus, never with reduced motion or Motion off. It needs no attribute; never build hover effects into a pattern. A block style of an add-on that brings its own hover is a style, not markup, and may be used.

## Through the abilities

- **New pattern or variant:** set `leitviaAnimation` and `leitviaMotion` in the markup of `leitvia/create-pattern`, or of `leitvia/vary-pattern` with a full `content`. `edits` of a variant set them too, with `attributes: {"leitviaAnimation": "fade"}` at a block ID, as `leitvia/update-block` does.
- **New page:** `leitvia/compose-page` keeps the attributes the patterns carry; its edits set or remove them the same way.
- **Existing page:** `leitvia/update-block` with `attributes: { "leitviaAnimation": "fade" }` works on every block that lists the attributes, core blocks and blocks with saved markup included, since only the block comment changes; they go with other attributes in the same call. `"leitviaAnimation": "none"` removes the effect, `"leitviaMotion": null` returns to the site setting.
- **Whole site:** `leitvia/set-motion` (`motion`), waits in a package. A block without the attributes (the excluded ones above) is refused with `leitvia_invalid_input`.

## Effects of add-ons

An add-on can add effects of its own to `leitviaAnimation`. Each is an entry effect (plays once like those above) or a scroll effect (follows the scroll position like `parallax`; Studio sets `has-lv-scroll is-lv-scroll-<effect>`). `leitvia/list-blocks` names them, with the blocks they fit and when to use them, in the description of the attribute; on any other block they are ignored. Block styles of an add-on show in the same list with the block.

- **At most two kinds of scroll effect per page** besides `parallax`; `leitvia/audit-site` reports more as `leitvia/motion`.
- **Never in the last screen of a page:** a scroll effect reaches its end state before the block gets to the middle of the window, and at the end of a page it may never get there.
- **The first screen stays still,** as above. A style an add-on describes as the opening stage of a page is the one exception.
- Everything else above holds for them too: one effect per section, none in calm sectors, never `leitviaMotion`.

