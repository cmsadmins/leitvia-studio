# Token format, contrast and limits

Details for `leitvia-design`. Read "Format" before you change a `$value`, "Contrast" before you create the set, "Fonts" and "Limits" before your report.

## Roles

| Role | Typical brand value |
|---|---|
| `surface`, `surface-alt` | Page background and a tinted band |
| `ink`, `ink-muted` | Text and secondary text |
| `accent`, `on-accent` | Brand color for links and buttons, and the text on it |
| `stroke` | Borders and rules |
| `focus` | Keyboard focus ring |
| `success`, `warning`, `danger` | Status colors |

A dark set (`surface` darker than `ink`) keeps inverse bands dark, also on a light variation. There inverse lies on `surface-alt`, so prices, check marks and eyebrows keep the accent and muted text its color; on light palettes they take the band text color.

## Button and band colors

By default the primary button and the dark bands (Inverse block style) take `ink`, and a button on a dark band takes `accent`. A token set separates them with the optional roles of `optionalRoles` in `leitvia/get-contract`. Add only the ones the brand needs; a role you leave out keeps its fallback, and a set without any of them looks as before.

| Optional role | Falls back to | Sets |
|---|---|---|
| `button` | `ink` | Fill and border of the primary button on light surfaces |
| `on-button` | `surface` | Label on `button` |
| `band` | `ink` | Background of dark bands in a light set; `ink` stays the text color |
| `band-button` | `accent` | Fill and border of the primary button on dark bands |
| `on-band-button` | `on-accent` | Label on `band-button` |
| `band-accent` | `surface` | Accent text on dark grounds ([dark pages](dark-pages.md)) |
| `button-hover` | `accent` | Fill and border of the primary button on hover |
| `on-button-hover` | `on-accent` | Label on `button-hover` |

Add each one as a color token next to `ink`, in the same value format:

```json
"button": { "$type": "color", "$value": { "colorSpace": "srgb", "components": [0.122, 0.373, 0.749], "hex": "#1f5fbf" } }
```

Example: a brand with mid blue buttons and white labels, near black blue text, deep blue bands and white buttons on them sets `ink` `#0b1b33`, `button` `#1f5fbf`, `on-button` `#ffffff`, `band` `#0b2a5b`, `band-button` `#ffffff` and `on-band-button` `#0b2a5b`.

- The set is then also checked against `optionalPairs`: the label on its button (4.5:1, here 6.1:1 and 14.0:1), and `surface`, `stroke` and the focus ring on `band`. A role you left out counts with its fallback, so `button` alone still needs `surface` to read on it.
- Hover is `button-hover` on `on-button-hover`, else `accent`. A `button` equal to `accent` needs a `button-hover` shade, or hover shows no change.
- `band` applies to the Inverse block style in a light set. A dark set keeps `surface-alt`, and a plain `ink` background stays `ink`.
- Outline buttons, form buttons of Gravity Forms and the token editor of Leitvia Studio do not know these roles yet: outline and form buttons keep `ink`, the editor keeps the roles when it saves but has no fields for them. Change them through `leitvia/create-token-set`.

## Font weights

Optional group `fontWeight`, numbers from 100 to 900: `heading` sets h1, h2, h4, h5 and h6 (such as 300), `heading-s` sets h3 and the titles of cards and features (without it h3 takes `heading`), `eyebrow` the block style Eyebrow (without it 500). Without the group the theme (400) or the style variation decides. Studio 1.3.52 and theme 1.2.57 or later.

```json
"fontWeight": { "heading": { "$type": "fontWeight", "$value": 300 }, "heading-s": { "$type": "fontWeight", "$value": 600 }, "eyebrow": { "$type": "fontWeight", "$value": 700 } }
```

## Icon line width

Optional top-level token `iconStroke`, a number from 1 to 3: the line width of the Lucide icons of Leitvia Studio (icon and icon list) on their 24 grid, such as 1.5 for a lighter line that fits a light typeface. Without it the width is 2. The lead symbol of the Pro pattern `hero-symbol` draws 0.25 thinner.

```json
"iconStroke": { "$type": "number", "$value": 1.5 }
```

## Format

Copy the `document` of `leitvia/get-tokens` and change only `$value` entries. Keep every slug and add none, except the optional [button and band colors](#button-and-band-colors), [font weights](#font-weights) and [icon line width](#icon-line-width); see [`radius.media`](media-radius.md).

Colors as `{"colorSpace": "srgb", "components": [r, g, b], "hex": "#rrggbb"}` with components from 0 to 1; dimensions as `{"value": 1.5, "unit": "rem"}`; `clamp()` values through `$extensions` `com.leitvia` `css`, fluid font sizes through `com.leitvia` `fluid`, as in the document you started from.

## Extra surfaces

Extra surfaces go in `color.surface-extra`: at most eight color tokens, names of lowercase letters and hyphens (`mint`), label in `com.leitvia` `name`. `ink` needs 4.5:1 on each. Errors: `leitvia_studio_dtcg_extra_contrast`, `leitvia_studio_dtcg_extra_limit`, `leitvia_studio_dtcg_extra_slug`. Use them for cards only, `"backgroundColor":"surface-extra-mint"`, text `ink`. Links turn `ink` where `accent` is below 4.5:1 (`accent_on_extra`, class `has-ink-links` on the group).

## Contrast

For every entry of `pairs`, and of `optionalPairs` when the set carries one of its optional roles, compute the WCAG 2.2 ratio of `fg` on `bg`: relative luminance L of each color (sRGB channel c from 0 to 1, linear `c / 12.92` when c is at most 0.04045, else `((c + 0.055) / 1.055) ^ 2.4`; L = 0.2126 R + 0.7152 G + 0.0722 B), ratio = (L1 + 0.05) / (L2 + 0.05) with L1 the lighter one. A pair with `ring` passes when the better of `fg` on `bg` and `ring` on `bg` reaches `min`, and `fg` on `ring` reaches 3:1 (the double focus ring).

Check all pairs, not only the ones the brand guide mentions. Brand guides often list a muted text or border color that fails on the brand's own background.

When `leitvia/create-token-set` is rejected with `leitvia_check_failed`, each finding names the pair, the ratio and the minimum (`accent on surface: 2.90:1, needs 4.5:1`) and a `fix`. Correct exactly once:

1. Change the brand side of the pair (usually `accent`, `ink-muted` or `stroke`), not `surface` or `ink`, unless the person said otherwise.
2. Keep the hue, darken on light surfaces, lighten on dark ones, in small steps until every failing pair of the finding list reaches its minimum by your own computation. One new value often fixes several findings (accent on surface, on surface-alt, on-accent on accent).
3. Send the corrected document once. Tell the person which value replaced which and the ratios it reaches.

Findings with severity `warning` (`font_not_bundled`; `leitvia_studio_dtcg_unknown_group`, see [widths](widths.md)) do not block; create and activate return them. Report them.

## Fonts

A token set gets font faces when its first family is available on the site. Bundled today: Newsreader, Inter Tight, IBM Plex Sans and IBM Plex Mono from the theme and its style variations, Libre Franklin and Young Serif from Leitvia Studio. With Leitvia Pro also Instrument Serif, Instrument Sans, Atkinson Hyperlegible Next, Archivo, Figtree, Fraunces Soft, Schibsted Grotesk and Public Sans with italics, Gloock, Archivo Black, Bricolage Grotesque, Martian Mono, Bodoni Moda, Big Shoulders Display and Manrope without. A family in the Font Library of the site counts too. All are served from the site, no other host. Any other name gives the warning `font_not_bundled` and falls back to the system font: take the exact name from these lists (`Fraunces Soft`, not `Fraunces`); `data.suggestion` names a bundled family spelled almost the same. A body or heading family without an italic cut gives `font_no_italic`, since the browser slants upright letters. Upload the italic files with the brand font (step 1 below) when the brand uses italics.

A brand font that is not bundled is a step of the person or the operator, no ability uploads files of this kind:

1. Ask whether the license allows self-hosting and for the files as WOFF2, one per weight and style.
2. Before the token set is activated, the person uploads them, or the operator runs the [commands](../../../workflows/new-site-operator.md#brand-fonts). Never link a font CDN.
3. Keep the family name of the upload as the first entry of the stack and a generic family last. The name decides: `font_not_bundled` disappears from the check of the next `leitvia/create-token-set` once it matches.
4. Fonts uploaded after the activation need the set activated again, because the faces are copied into the global styles at that moment. Then check that the front page requests fonts only from the site.

## Limits

Tell the person; there is no ability for these:

- Page widths: [widths](widths.md).
- The logo: setting the site logo is an admin setting, and it needs a raster image. WordPress refuses SVG uploads, for administrators too, `leitvia/add-media` takes JPEG, PNG, GIF, WebP and AVIF, and Leitvia has no SVG sanitizer; do not suggest a plugin that lifts the limit. From an SVG, render a PNG with a transparent background at twice the display width or more: 400 px wide covers the header patterns (`width` up to 200), 960 px the masthead. Add it with `leitvia/add-media`, name it to the person for the site logo, and afterwards check on the front end that the logo image has a width and a height.
- A light logo for dark bands: on an Inverse band the Site Logo block takes the block style On dark band, which draws a transparent logo in white (the pattern `leitvia/footer-mega` has it). When the brand has its own light file in several colors, add it with `leitvia/add-media` and write a `core/image` block linked to the start page in place of the Site Logo block with `leitvia/update-template-part`.
- Brand rules about composition, for example "accent never as a section background", are not a contrast question. Pass them on to `leitvia-page` and check the front end for `has-accent-background-color` on sections.
