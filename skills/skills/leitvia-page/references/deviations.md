# Deviations from a design

A design file, a Figma frame or the brand rules of a site often differ from the closest pattern in small ways: a line, a hover, a size between two steps, a marker, the order inside a cell. Decide each one in this order and stop at the first answer that holds.

## Decision path

1. **The site can do it already.** A pattern, a block style of `blockStyles`, an attribute or an edit ([block edits](block-edits.md), [composition](composition.md#edits)) gives the result: use it. Look in `leitvia/list-patterns` (with `role`, `tone`, `layout`) and in the block styles of `leitvia/get-contract` before you call anything missing.
2. **Only the look differs.** The pattern fits in structure and content, and the difference is a matter of look alone: a line or border, a color from a role, spacing, a size, a hover or focus state, the arrangement of blocks inside the pattern. That is a **site style** for the child theme Leitvia Child. Build the page with the pattern as it is and write the site style as a finding (below). You never write CSS, a file or a theme yourself; the operator adds the rule to `assets/css/site.css` of the child theme ([operator steps](../../../workflows/new-site-operator.md#site-styles-in-the-child-theme)).
3. **Function or structure is missing, or every site would want it.** A block, a setting, a field, an interaction, a layout no pattern has, or a look that would suit every site of the theme: a finding for Leitvia, the product. Until it exists, take the closest pattern and name the gap in your report.

A new pattern of your own (skill `leitvia-pattern`) comes only after step 2 failed: the structure differs, not the look.

## A site style finding

Write it so the operator can copy it without a question:

- **Type:** site style.
- **Where:** the page and the section (pattern slug), and the block it targets.
- **Selector:** a block style (`.is-style-grid-net`), a block class (`.wp-block-leitvia-marquee`) or both, with the structure below it (`.is-style-grid-net > .wp-block-group`). Never a generated ID or class (`wp-container-*`, `wp-elements-*`, a block ID, an anchor), never a page ID (`.page-id-12`); those change with the next edit or apply to one page only.
- **Effect:** what changes, in one sentence, and the state (always, hover, focus, from 782 px).
- **Values:** role custom properties only, for example `var(--wp--preset--color--accent)`, `var(--wp--preset--spacing--l)`, `var(--wp--preset--font-size--heading-m)`. No hex value, no `!important`, nothing from another host. A value between two steps is a `calc()` or `color-mix()` of roles.
- **Rules that stay:** motion only under `prefers-reduced-motion: no-preference`; the focus ring of Leitvia stays visible; every text keeps a contrast of at least 4.5:1 on its ground.

Examples:

| Deviation in the design | Site style |
|---|---|
| Cells of a grid with a thin line in the accent | `.is-style-grid-net`: line color `var(--wp--preset--color--accent)` in place of the line of the theme |
| Eyebrow above headings underlined in the accent | `.is-style-eyebrow`: `text-decoration: underline var(--wp--preset--color--accent)`, `text-underline-offset: 0.3em` |
| Heading a size between `heading-l` and `display` | `.wp-block-group.is-style-inverse h2`: `font-size: calc((var(--wp--preset--font-size--heading-l) + var(--wp--preset--font-size--display)) / 2)` |
| Marquee statements in the muted ink | `.wp-block-leitvia-marquee`: `color: var(--wp--preset--color--ink-muted)` |

## Before the first site style

Site styles need Leitvia Child as the active theme. `leitvia/get-site-health` shows it: `theme.slug` is `leitvia-child` and `theme.parent` is `Leitvia`. With `theme.slug` `leitvia`, add to the finding that the operator installs and activates Leitvia Child first; Leitvia Studio carries global styles, the token set and edited templates and parts over. That is a step of the person, never yours.

Collect the site style findings of a run in one list in your report (or in the findings file of the project) and go on with the page; they block nothing.
