# Sticky header

The header stays at the top of the screen by default, on every screen; no recipe and no step is needed for it. Change it only when the briefing asks, with one block style of the theme on the outermost group of the header part, the group named Header, through `leitvia/update-template-part`:

- `is-style-scrolling-header`: the header scrolls with the page. For a header that should stay in the flow, or when the person asks for it.
- `is-style-sticky-header-desktop`: sticks from 600 px wide only. For headers with several rows or a large logo.
- `is-style-sticky-header-mobile`: sticks below 600 px wide only, where the row with logo and menu button stays in reach.
- `is-style-sticky-header`: on every screen, the same as no style.

Read the part with `leitvia/get-block` on its root block, add the class to `className` of that group and to the class list of its `div`, change nothing else, and send the markup as `content` with the `package` key. Header parts live in the area `design`, so a person decides.

The theme keeps it accessible by itself: in windows lower than 500 px or not wider than 320 px, at 200 % zoom, with enlarged text and in print the header scrolls away, and focused elements and jump targets stop below it (WCAG 2.4.11). With Leitvia Studio a header taller than a quarter of the window scrolls away as well. On phones a service bar on top (`header-utility`) scrolls away and only the row below it sticks; keep the bar the first block of the group named Header, or the whole header sticks. The row needs no compact variant: nothing shrinks or hides on scroll, so do not add a script or classes for that.

## Header over the stage

On a site whose pages open with a full-width photo cover, the header can lie over the photo until the page moves: add `is-style-overlay-header` to the same group (it keeps sticking, `sticky-header` is the default and needs no class); with `sticky-header-desktop` or `sticky-header-mobile` both go space separated in one `className` edit, also in `leitvia/update-template-part` with `pattern` and `edits`. Landing pages show the breadcrumbs below the header; over such a cover they take the text color of the stage. It needs Leitvia Studio, which measures the header, and browsers with scroll-driven animations; others keep the header with its ground. Give those covers a dimming of 60 % or more (block style Inverse), and a dark logo `is-style-on-dark`. For a sober technical or corporate menu, the navigation block of the header takes `is-style-uppercase` (items in capitals, typed in normal case).

## Logo in the header

A logo with a line of small text (a name with a subline) is hard to read at the width that fits beside the menu button on a phone. Give the `core/site-logo` block of the header the block style `is-style-large-on-desktop` and the width it should have from 1024 px up, about 1.4 times the phone width (260 px on a phone becomes 360). Below 1024 px the theme shows it at 70 %. On a dark band it keeps `is-style-on-dark` as a second class. Both go through an edit of `leitvia/update-template-part` (with `pattern` and `edits`) or `leitvia/update-block` at the ID of the logo: `"attributes": { "width": 360, "className": "is-style-large-on-desktop" }` (width 20 to 600 px), no markup of your own. In a footer the same edit sets the logo width, for example to the height the brand asks for.

## Navigation in the header

Menu items change with `leitvia/update-navigation`, which leaves the header as it is. When you send a header part as `content`, keep the `core/navigation` block of the theme pattern with all its attributes, above all `"submenuVisibility":"click"`, `overlay` and `ariaLabel`; change only the items. `leitvia/update-template-part` puts back these settings where the new navigation lacks them, but a value you send stays.

## Mega menu

For a main item with many sub pages (services in four groups, a shop with lines), build a mega menu instead of a long submenu, in two steps and the same `package` key:

1. Panel: `leitvia/update-template-part` with `part` `mega-menu-<name>` (for example `mega-menu-services`) and a pattern of `megaMenu.patterns` from `leitvia/get-contract`. The standard is `leitvia-studio/mega-menu-overview`: four columns headed by an icon and a title in capitals linked to the hub of the topic above a fine line, one-line entries, a link to all of the topic per column, a card of 300 px on the right (eyebrow, title, one sentence, button) and a footer row with a text and a link. Further: `leitvia-studio/mega-menu-columns` (entries with a sentence) and `leitvia-studio/mega-menu-columns-card`. `leitvia/get-pattern` returns each with its markup; change texts, links and icons with `edits` at its path IDs, `remove` or `repeat` an entry or a column. A column head whose topic has no page of its own stays plain text: `url` `""` (without `link_text`) on its heading takes the link out; the column keeps its button in the mobile menu. A price beside an entry: `insert` a `core/paragraph` after the link of the entry with `bind` to the price source of an add-on and `attributes` `textColor` `ink-muted`, `fontSize` `meta`. Add-ons may register further panel patterns; they appear in `megaMenu.patterns`. A panel nobody uses goes with `delete: true` (refused while an item opens it; a person applies it, a rollback brings it back).
2. Item: `leitvia/update-navigation` with an item `{ "label": "Services", "url": "/services/", "type": "mega", "panel": "mega-menu-services" }`. `url` is required: the overview page is the link of the item without JavaScript and for search engines. The panel needs to exist first; `megaMenu.panels` lists them with their hash.

The item is a link with a button beside it (`aria-expanded`), Escape closes the panel and returns focus, hover opens it only after a pause. A panel of four columns takes the width of the header row (`alignwide`) and lines up with logo and last item, without such a row it stands in the middle of the window; it keeps its type small whatever sizes its content sets (entries in `meta` without a size of their own, so set none per entry), fits 1440 x 900 without scrolling inside, and below 1200 px puts the columns two by two with a narrower card beside them, so 1024 x 768 fits too. Without JavaScript the panel links stay in the page and open with the focus. In the mobile overlay the panel is an accordion and each column head its second level. `leitvia/audit-site` reports a panel without links, links to `#`, an item whose panel is gone or that has no `url`, and the findings add-ons add for a panel.

## Icons in the menu

`icon` puts an icon of the icon set (the `enum` of the attribute `icon` of `leitvia/icon` in `leitvia/list-blocks`) before the text of an item or sub item of `leitvia/update-navigation`, and of a mega menu item. It is decorative and hidden from screen readers, so the text still names the target; it takes the accent color and the line width of the token `iconStroke`. At most one icon per item, and only where every item of the row can have one, for example the three audiences of a company; column heads of a panel carry their icon in the pattern. Below 1280 px the theme hides the icons of the top-level items in the header row (submenus, panels and the menu overlay keep them), so five items with icons and a button keep one row at 1024 px.

## Service bar over the stage

`header-utility` (service bar above logo and menu) works with every sticky style and with `is-style-overlay-header`: the bar keeps its dark ground, the row below lies over the cover. Its entries stand right-aligned at the width of the header, its links are underlined only on hover and focus. Mark one link, such as an emergency line, with the paragraph style Highlight (`"attributes": { "className": "is-style-highlight" }` on its paragraph): bold, underlined in the accent, the text keeps the color of the bar. In the open menu on a phone the header button and the bar stay at the bottom while the menu scrolls.

## Dark header and header in two rows

A dark header is the header pattern of the site with its outer group on ink: `leitvia/update-template-part` with `part` `header`, the `pattern` and the edit `{ "block": "p:0", "attributes": { "backgroundColor": "ink" } }`. Only groups of a header part take `ink`; elsewhere a dark band is the block style Inverse. On ink the text and the menu links turn surface, the current page and hover are underlined in `band-accent` (the token set checks it on ink at 4.5:1, without the role the line is surface), submenus and mega menu panels keep the colors of the page. Give a logo that is dark the block style On dark band. Check the result at 1440 and 390 px.

`leitvia/header-two-rows` puts logo and search on the page ground and the menu in a dark bar below them, across the full width. Only the bar sticks: the first row (class `leitvia-header-top`, keep it) scrolls away and comes back while the search has the focus. Use it for portals and magazines with many sections; with the menu in a bar of its own, long labels fit in one row.
