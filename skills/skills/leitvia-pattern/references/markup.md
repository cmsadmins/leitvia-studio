# Markup rules and deprecated patterns

Details for `leitvia-pattern`. Read "Markup rules" before you write or vary markup, "Replace deprecated patterns" when an audit reports them.

## Markup rules

Read `leitvia/get-contract` (`contract.roles.color`, `contract.pairs`, `contract.blockStyles`, `contract.compositionRules.sectionPadding`) and `leitvia/list-blocks` (blocks and attributes you may use). Take the markup of the closest existing pattern from `leitvia/get-pattern` as the starting point, and change structure and texts from there.

- **Colors only as roles:** `backgroundColor` and `textColor` attributes with role slugs such as `surface`, `surface-alt`, `ink`, `ink-muted`, `accent`, `on-accent`, and the matching classes (`has-ink-background-color`). Text and background must form a pair from `pairs` in the contract or reach 4.5:1 in the active palette; the role descriptions name the usual combinations (a dark band is `ink` with `surface` text). Accent is for one band per page at most. Roles name a job, not a lightness: in a dark palette `ink` is the light text color, so a pair from the contract keeps its contrast in every palette. Judge contrast from `pairs`, `inversePairs` and `leitvia/get-tokens`, not from the names of the roles.
- **Spacing, sizes and fonts only as presets:** `var:preset|spacing|l` in attributes and `var(--wp--preset--spacing--l)` in the saved style, font sizes by slug (`fontSize: "lead"`). The outer padding follows the density of the section: `compositionRules.sectionPadding` (airy `xxl`, regular `xl`, dense `l`).
- **No core preset slugs:** `base`, `contrast`, `primary`, spacing `20` to `80` and sizes `small` to `xx-large` come from the default themes of WordPress and do not exist here. The theme maps each of them to a role (the alias table in `design.md` of the theme, `coreAliases` in `leitvia-contract.json`) and Studio rewrites them when it saves, but write the role yourself. Any other unknown slug is rejected by the token check.
- **Never raw values:** no hex colors, no px values, no `!important`, no numbered presets such as `--wp--preset--font-size--2-xl`. The token check rejects them with `leitvia_check_failed`. No custom CSS and no inline styles beyond what the block editor saves for presets.
- **Block styles** from `blockStyles` in the contract (for example `is-style-eyebrow` on a paragraph, `is-style-outline` on a button) instead of own classes.
- **Categories** are the block pattern categories of WordPress core (`about`, `audio`, `banner`, `buttons`, `call-to-action`, `columns`, `contact`, `featured`, `footer`, `gallery`, `header`, `media`, `portfolio`, `posts`, `query`, `services`, `team`, `testimonials`, `text`, `videos`) plus `leitvia-shop` of the theme, which exists while WooCommerce runs, and the categories of building blocks and add-ons. `leitvia/create-pattern` takes no category; use them to filter `leitvia/list-patterns`. Slugs such as `pricing` or `newsletter` are no categories here.
- **One root block** (usually a group with `align: full`) with a readable `metadata.name`, so the editor and the Lab show what the section is.
- **Width and edge** (`layout` in the contract): text that is not centered as a whole starts at the wide edge like the page head, so inside a full-width band it sits in a block with `align: wide`. Width only through `align` and the layout of the block, never through `contentSize`, a max width or margins of your own.
- **Attribute order as the editor saves it:** in a block comment the keys come in the order the block editor serializes them: first the attributes the block declares, in the order of its block.json, then those its supports add, in the order core registers them (for example a paragraph: `style`, `textColor`, `fontSize`; a block with `align` and `layout`: `align`, `layout`, `style`); nested objects the same way. Never sort or reorder keys by hand. Take the order from the markup of `leitvia/get-pattern` or `leitvia/get-block` and change only values. Markup in another order renders the same but is rewritten when someone opens it in the editor, and the round trip check of Leitvia refuses it.
- **Anchors, tables:** heading `{"anchor":"x"}`, `<h2 id="x" class=…>`; table without `has-fixed-layout`: `"hasFixedLayout":false` first.
- **Headings** start at h2. Only a hero carries an h1, and a page has one hero at most.
- **Links** need real targets in reusable patterns too: no `#`. Use a button whose link the page edit sets, or leave the link out.
- **Images** from the media library only, with an alt text that fits every page the pattern will appear on, or `metadata.leitviaDecorative` with an empty alt. Add them with `leitvia/add-media`.
- **Never** scripts, event attributes, Custom HTML blocks, classic blocks or Base64 data; they are rejected with `leitvia_unsafe_content`. No HTML comments besides block delimiters: between blocks they turn into classic content, inside a block they break it.
- **Effects on scroll** (`leitviaAnimation`) only as the [animation rules](animation.md) allow.
- Keep every text editable by a page edit: paragraphs, headings, list items, buttons, the `cite` of a quote and the `summary` of a details block, each with at most one link. Captions change later with `caption` in the edits of `leitvia/compose-page` and with `leitvia/update-block`.

## Replace deprecated patterns

When a person deprecates a pattern and names its replacement, `leitvia/audit-site` reports the pages that still use it, and `leitvia/compose-page` rejects it with `leitvia_deprecated_pattern` and `replaced_by`.

1. `leitvia/audit-site`, page through with `next`; note the posts with deprecated patterns.
2. Per post `leitvia/get-page-outline` for the `hash` and the section IDs.
3. `leitvia/replace-pattern` with `post`, `base_hash` and optionally `block` for one section. Texts and button links move to the replacement in order per block type; check the result with `leitvia/get-page-outline` and fix moved texts with `leitvia/update-content`.
4. `leitvia_no_replacement` (`retry: never`): the section has no replacement. Stop and ask the person which pattern should take its place; a new page with `leitvia/compose-page` is the way to rebuild it.
