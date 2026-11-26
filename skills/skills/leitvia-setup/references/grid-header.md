# Header, footer and menu in grid cells

For a graphic, grid based look (agencies, studios, portfolios) the theme has a header and a footer made of cells framed by 1 px lines in the text color, edge to edge over the full window, and two menu patterns for the overlay. Build them with `leitvia/update-template-part` and `pattern` plus `edits`; no markup of your own. On a dark page the lines and text turn light with the page.

## Header: `leitvia/header-grid`

Two rows on one grid, the side cells about 18 % wide, the middle wide:

| ID | Block | Change |
|---|---|---|
| `p:0.0.0.0`, `p:0.0.0.1` | service row left, two links (`Message`, `Phone`) | `text` and `url` (`mailto:`, `tel:`), `remove` one |
| `p:0.0.1.0` | service row right, one link (`LinkedIn`) | `text`, `url`; `repeat` for a second one |
| `p:0.1.0` | main row left, one link (`Work`) | `text`, `url` |
| `p:0.1.1.0`, `p:0.1.1.1` | site logo and site title in the middle | logo `width`; `remove` the title when the logo carries the name |
| `p:0.1.2` | navigation, menu button `Menu` at every width, as large as the link on the left | `text` for its own word (`MENU`); items with `leitvia/update-navigation` |

Links in the service row are separated by a bar screen readers skip; service row and main row are set in capitals by CSS, so type them in normal case. Text keeps the inner space `l` from the cell lines (`m` below 600 px). Below 600 px the service row stays on top, link and menu button share the next row and the logo takes a row of its own.

```json
{ "part": "header", "pattern": "leitvia/header-grid", "edits": [
  { "block": "p:0.0.0.0", "text": "Message", "url": "mailto:hello@example.org" },
  { "block": "p:0.0.0.1", "remove": true },
  { "block": "p:0.0.1.0", "text": "LinkedIn", "url": "https://www.linkedin.com/company/example/" },
  { "block": "p:0.1.0", "text": "References", "url": "/references/" },
  { "block": "p:0.1.1.1", "remove": true },
  { "block": "p:0.1.2", "text": "Menu" }
] }
```

## Footer: `leitvia/footer-grid`

One row of cells in small capitals (`p:0.0`, a navigation labelled Footer): `p:0.0.0` Contact, `p:0.0.1` Work, `p:0.0.2` Privacy policy, `p:0.0.3` Imprint, `p:0.0.4` Cookie settings (always shows; without a service that needs consent its dialog says that the site uses no cookies or services that need consent). The cells at the edges are as wide as those of the header. Below 600 px the cells form two columns. `p:0.1` is the copyright line (binding, never type the year). Change label and address of each link with an edit at its ID (`text`, `url`), or the items as a whole with `leitvia/update-navigation`.

## Menu: the part `navigation-overlay`

Every header with a menu button opens the part `navigation-overlay`. Two patterns for it, both with the close button at the top right (`p:0.0.0`), the menu items large in capitals and left-aligned (`p:0.1`, the items of the main menu), and a footer row at the bottom with a small logo (`p:0.2.0`) and one contact link (`p:0.2.1`). For a logo mark instead of the line logo insert an image before the logo and remove the logo: `{ "block": "p:0.2.0", "insert": { "block": "core/image", "image": { "id": 12, "decorative": true }, "attributes": { "width": "3rem" } }, "position": "before" }`, then `{ "block": "p:0.2.1", "remove": true }` for the logo, which moved to `p:0.2.1` (decorative when the mark repeats the name of the site beside it, else an alt with the name):

- `leitvia/navigation-overlay-panel`: a panel from the right, up to 36rem wide, over the dimmed page, on `surface-alt`. For a brand color as the ground give the panel a surface of the token set: `{ "block": "p:0", "attributes": { "backgroundColor": "surface-extra-<name>" } }` (ink text, 4.5:1 by token check). `overlayBackgroundColor` on the navigation of the header does not reach this part (core leaves it to the part) and is refused.
- `leitvia/navigation-overlay-fill`: the whole screen on `accent` with `on-accent` text.

```json
{ "part": "navigation-overlay", "pattern": "leitvia/navigation-overlay-panel", "edits": [
  { "block": "p:0.2.1", "text": "hello@example.org", "url": "mailto:hello@example.org" }
] }
```

**Numbered menu with lines** (Leitvia Pro): `{ "block": "p:0.1", "attributes": { "className": "is-style-numbered" } }` sets each item large (`heading-l`) between thin lines with its number from 00 in mono before it; screen readers read the menu as before. A word such as Menu at the top left: `{ "block": "p:0.0.0", "insert": { "block": "core/paragraph", "text": "Menu", "attributes": { "fontFamily": "mono", "fontSize": "meta" } }, "position": "before" }`, it stands at the start of the row of the close button.

Focus moves into the menu and stays there, Escape and the close button close it and return focus to the menu button (core). The panel slides in only without reduced motion.
