---
name: leitvia-design
description: Derives a Leitvia token set from a brand (colors, fonts, radii, spacing, logo) and activates it through the leitvia abilities only. Maps brand values to the color roles of the design contract, computes every contrast pair before writing, fixes a contrast rejection itself once, and hands the set to a person as a change package; without a brand of its own it applies a style variation of the theme instead. Use it when a site should carry a brand or another look; site structure belongs to leitvia-setup, pages to leitvia-page.
compatibility: WordPress 7.1+, PHP 8.2+. Needs the theme Leitvia and the plugin Leitvia Studio, reached over the MCP server leitvia (application password or OAuth token) or the REST routes of the Abilities API (application password only).
---

# leitvia-design

Bring a brand onto a Leitvia site as a token set: one DTCG document with every role of the design contract, checked for contrast, activated by a person. You change the site only through the abilities of Leitvia Studio.

## When to use

- A site should carry a brand, or one of the looks of the theme (style variation).
- Not for site structure (leitvia-setup), pages (leitvia-page) or new sections (leitvia-pattern).

## Inputs required

- The brand source the person names (style guide, design file, existing site), or the request to design the brand.
- Read before the first call: the [agent handbook](../../handbook/README.md). Read the page of each ability before you first call it (the handbook index links them): `get-contract`, `get-tokens`, `create-token-set`, `preview-token-set`, `activate-token-set`, `set-variation`, `audit-site`. Roles, token format, the contrast formula, fonts and what a token set cannot carry: [token details](references/tokens.md).

## Ground rules

- No CSS and no child theme of your own.
- Token sets and their activation are in the area `design`, which by default only suggests: every write lands in a change package for a person. Send one `package` key for the task, for example `brand-2026-10`.
- Brand values are facts too: they come from the brand source the person names (style guide, design file, existing site). Never guess a brand color or font; a value you cannot find is a question to the person. The one exception: the person asks you to design the brand ("own brand, your proposal", an invented demo site). Then choose the values, compute every pair and call the set a proposal in its `$description` and in your report.
- Roles, slugs and the contrast pairs come from `leitvia/get-contract`, never from memory or from files of the theme.

## Procedure

### 1. Collect the brand

Ask for, or read from the source the person named: colors with their intended use, fonts (family, weights, license, whether the files are available), radii (fields, buttons), spacing grid, logo files for light and dark backgrounds. Write down brand rules that are not values, for example "the accent color is never a large surface" or "headings bold".

Checkpoint "Brand confirmed".

### 2. Read the site

- `leitvia/get-contract`: `contract.roles` (every color, font size, font family, spacing, radius and shadow slug), `contract.pairs` (foreground, background, minimum ratio, optional `ring`), `contract.optionalRoles` and `contract.optionalPairs` (button and band colors a set may add).
- `leitvia/get-tokens`: the current `document` (start from it), `sets` (existing token sets), `contrast` (findings of the current palette) and `hash` (of the global styles).
- The front page: its `--wp--preset--color--*` and `--wp--preset--font-family--*` values are what visitors see. When they differ from the `document`, tell the person which values differ and ask which ones to keep before you write; never build a set on values the site does not show.
- A style variation of a recipe (such as `corporate-light`, in the same package as the recipe) and a token set do not add up: once the set is active, its colors and fonts replace those of the variation. Build the set on the `document` of `leitvia/get-tokens`; a variation that still waits in an open package is no reason to ask.

### 3. Map brand values to roles

Copy the `document` of `leitvia/get-tokens` and change only `$value` entries, keeping every slug. Roles and the value format: [token details](references/tokens.md#roles). A brand whose buttons or dark bands have another color than its text adds the optional roles `button`, `on-button`, `button-hover`, `on-button-hover`, `band`, `band-button` and `on-band-button`: [button and band colors](references/tokens.md#button-and-band-colors); `band-accent` for an accent that reads only on dark bands. Dark inner pages (templates `page-dark`, `page-landing-dark`) and full-height heroes: [dark pages](references/dark-pages.md). Light or bold headings and eyebrows: [font weights](references/tokens.md#font-weights). A brand color that fits no role (for example a second orange for print) stays out of the set; mention it in the `$description` of the set.

### 4. Compute every contrast pair before writing

For every entry of `pairs`, and of `optionalPairs` when the set carries an optional role, compute the WCAG 2.2 ratio of `fg` on `bg`, rings included, with the formula in [token details](references/tokens.md#contrast). Check all pairs, not only the ones the brand guide mentions.

### 5. Create the token set

Call `leitvia/create-token-set` with `title`, `document`, empty `base_hash` and your `package` key.

When it is rejected with `leitvia_check_failed`, correct exactly once: change the brand side of the failing pair, keep the hue, and send the corrected document once ([token details](references/tokens.md#contrast)). Tell the person which value replaced which and the ratios it reaches. If the second call is rejected too, stop at checkpoint "Contrast correction accepted" with both finding lists.

Checkpoint "Contrast correction accepted" whenever you changed a brand value.

### 6. Preview

The new set exists only after the person applied the package of step 5 (checkpoint "Package applied"). Show it before anything is activated: `leitvia/preview-token-set` with `set` and the `path` of a page (front page when empty) returns a signed `url`, valid for an hour, that opens without signing in and changes nothing for visitors. Take screenshots there or give the addresses to the person. To compare style variants, create one set per variant and preview each; never activate a set just to look at it, visitors would see it. With Leitvia Pro the person can compare sets in the token studio view of `leitvia/get-tokens`.

The warning `font_no_italic` names a role whose family has no italic cut: italic text in it gets slanted upright letters. Keep italics out of that role or choose another family, and tell the person.

### 7. Activate

When the person chose a set:

1. `leitvia/get-tokens`: take the `id` of the new set from `sets` (status `inactive`).
2. `leitvia/activate-token-set` with `set`, the `hash` of `leitvia/get-tokens` as `base_hash` and the same `package` key.
3. The activation lands in a new package. Checkpoint "Package applied" again.

### Style variation instead of a brand

When the person wants one of the looks of the theme and no brand of its own, take the slug from `contract.variations` of `leitvia/get-contract` and call `leitvia/set-variation` with `variation`, the `hash` of `leitvia/get-tokens` as `base_hash` and the `package` key. A full variation replaces the global styles, a colors or typography variation only its part. While a token set is active (`source` of `leitvia/get-tokens` is `set`), its colors and fonts win over any variation, so do not set one; tell the person. Then checkpoint "Package applied" and the verification.

Brand fonts that are not bundled wait for an upload by the person or the operator, before the set is activated. Those, heading weights, the logo and composition rules of the brand: [token details](references/tokens.md#fonts). A brand with many photos may widen the text to the image bands: [widths](references/widths.md). Tell the person what the set cannot carry; a look beyond the roles (a line, a hover, a size between two steps) is a site style finding for the child theme: [deviations](../leitvia-page/references/deviations.md).

## Verification

After the person applied the activation:

- `leitvia/get-tokens`: `source` is `set`, `active` is the ID of your set, `drift` is false, `contrast` is empty.
- Fetch the front page and check that the CSS custom properties carry your values, for example `--wp--preset--color--accent` with your accent hex. Only the front end proves the brand is live.
- `leitvia/audit-site`: no new findings.

## Failure modes / debugging

- `leitvia_check_failed` on `leitvia/create-token-set`: a pair misses its ratio; step 5 has the one correction.
- `leitvia_stale_hash` on activation or variation: the global styles changed since your read; read `leitvia/get-tokens` again and send its `hash` once.
- `leitvia_forbidden` on a preview: the agent user may not edit theme options; a person grants it or previews.
- The front end still shows the old colors: `drift` is true or the package waits; read `leitvia/get-tokens` and the open packages.

## Escalation

Stop and wait for the person at each of these checkpoints. Report the values, the computed ratios and the exact question.

1. **Brand confirmed:** colors with their use, fonts, radii, spacing and logo come from the source the person named.
2. **Contrast correction accepted:** you changed a brand value to meet a contrast pair; the person agrees to the new value (or the second attempt failed and you show both finding lists).
3. **Package applied:** the person applied the package with the token set, and later the package with the activation, in the inbox of Leitvia Studio.

## Unattended

| Checkpoint | Answer without a person |
|---|---|
| Brand confirmed | Only values the briefing or the named brand source states; a role without a value keeps the value of the current `document`. The briefing asks you to design the brand: your proposal, as in the ground rules. No source and no such request: skip the skill and report it; the workflow goes on without a brand. |
| Contrast correction accepted | Make the one correction of step 5 and leave the set in the package; report old and new value with their ratios as a question. |
| Package applied | Stop after step 5; activation and the front end check belong to the next run. |

Steps only a person can do go into the report as a list: [new-site, steps of the person](../../workflows/new-site.md#steps-of-the-person).
