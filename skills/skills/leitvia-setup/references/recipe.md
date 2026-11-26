# Recipe run, shop sites and limits

Details for `leitvia-setup`.

## Status of a step

`leitvia/apply-recipe` returns `steps`, one per part of the recipe:

| Status | Meaning |
|---|---|
| `live` | Written and visible. |
| `draft` | Written as a draft; a person publishes it. |
| `package` | Waits in the change package (ID in `package` of the result). |
| `exists` | Already there, or changed since the recipe wrote it (an active token set counts for the variation); nothing changed. |
| `unchanged` | Variation, template part or navigation already hold what the recipe writes; nothing changed. |
| `waiting` | Needs a page that is neither on the site nor in the package (`page_missing`), or a parent page. |
| `rejected`, `unavailable` | Read `code` and `message`; report it. |

The recipe runs content model, token set, style variation, header and footer, pages, navigation, reading settings and the options that save data, each through its own ability. The footer links the pages the recipe names for it (the legal pages included), not the sample links of its pattern.

Settings that point to recipe pages (front page, privacy policy page, terms page of the shop) wait in the same package and get the ID of their page when the person applies it, so one package covers the whole setup. You cannot apply operations of areas that are not autonomous; `leitvia/apply-package` answers `leitvia_needs_human` and that is expected.

## Parts and dry run

A recipe runs whole by default. On a site that already has its design, pages and front page, take only what the task needs:

- `dryRun: true` writes nothing. Each step says `new`, `replace`, `unchanged`, `exists`, `package` or `waiting`, and `replaces` lists the parts that would overwrite something (style variation, header, footer, navigation, a setting). Show `replaces` to the person before the real run, then send the same selection without `dryRun`.
- `parts` limits the run: `buildingBlocks`, `samples`, `design`, `header`, `footer`, `pages`, `navigation`, `frontPage`, `options`. `pages` takes recipe page slugs, `buildingBlocks` names of the blocks of the recipe.
- `slugs` gives recipe pages other addresses, by the slug of the recipe: `{"imprint": "impressum", "contact": "kontakt"}`. Navigation, footer, reading settings and links of the recipe follow; `pages` keeps the recipe slugs. Send the same `slugs` on every later run, also the confirmation run, or a page without a kind is created again under its recipe slug.
- One content feature on its own (events, team, services) is a building block: `leitvia/list-building-blocks` lists them with `installed`, `leitvia/add-building-block` adds one with its types, settings that are empty and sample entries as drafts. Design, header, footer, pages and front page stay. Prefer it to a recipe run on an existing site.

## Sample texts of the recipe

Recipe pages, header and footer are built from patterns and carry the sample texts of their patterns (names, addresses, prices, quotes). Legal pages and, in the recipe `leitvia/shop`, About and Contact hold only placeholders that say so; the home page and the footer still carry sample texts. Read the diff of the package (`leitvia/get-package`) and list every such text with its page before the person applies it, so they know what goes live. After it was applied, read header and footer with `leitvia/get-page-outline` (the template part ID is `target.post` of the step `templateParts` in the confirmation run, setup step 5): `links` of each block shows where it leads, and a link to `#`, a link without an address or a link to a page the site lacks is a finding to fix with `leitvia/update-template-part`. New links (accessibility statement, overview pages): edit the `markup` of `leitvia/get-block` on the root block of the part, send it as `content`. Replacing them is work for `leitvia-page` after the setup, not a reason to rebuild the recipe by hand. On a site that is already live, ask whether the package should wait until those texts are replaced.

## Shop sites

- WooCommerce must be active before `leitvia/apply-recipe` with `leitvia/shop`; otherwise the recipe is `unavailable` with the reason. Installing plugins is a task for the person.
- The recipe switches off order attribution, usage tracking, marketplace suggestions and remote logging of WooCommerce. The final recipe run reports these steps as `exists`.
- The shop, cart, checkout and account pages come from WooCommerce itself. Check that they answer 200 after setup; the recipe adds `shop` to the navigation only when that page is published.
- Products: `leitvia/create-product` per product (simple products: texts, prices, SKU, stock, categories, image), with the same `package` key. A new category name creates the category. A long description in plain text becomes paragraph blocks (a blank line starts one); send block markup for headings or lists. A product with a price always waits for a person (content kind `prices`). Prices, SKUs and stock come from the briefing or the person, never a guess. Images from `leitvia/add-media` need a second package: `leitvia/update-product`. Product images square (1:1, 800 px or more).
- Germanized links its notes to pages the operator assigns ([operator steps](../../../workflows/new-site-operator.md#pages-of-a-legal-plugin-germanized)); the audit reports `empty-link`.
- Vouchers and other goods without shipping: no ability marks a product as virtual; the person ticks "Virtual" in WooCommerce, otherwise the checkout charges shipping for them.
- The home page hero shows the newest product in stock: create that product last.
- In WordPress Playground (SQLite) every order of a product with `stock` fails with `woocommerce_product_not_enough_stock`; leave `stock` out there.
- Shop settings are the person's (checkpoint "Shop settings"): at least one payment method (for a test shop "Cash on delivery" or "Direct bank transfer", no real payment), the store address, the currency, whether prices include tax and which tax rates apply, and a shipping zone with at least one shipping method (flat rate, free shipping or local pickup), in WooCommerce, Settings (`/wp-admin/admin.php?page=wc-settings`). Without a payment method or a shipping method for the address the checkout cannot take an order. For Germany the store country needs the state (`DE:DE-SN`); WooCommerce ignores a bare `DE` and taxes come out wrong. Site visibility must be "Live": a new WooCommerce hides the shop as "Coming soon". With "Prices include tax", WooCommerce adds tax to the cost of a flat rate: for 5.90 gross at 19 % enter 4.958 as cost. List them in the report; no ability changes them.

## What a recipe does not cover

Tell the person about these points; there is no ability for them:

- Product reviews are on in WooCommerce; whether visitors may review is the person's choice (Settings, Products).
- Avatars come from Gravatar, a third party; recommend switching them off in Settings, Discussion.
- Plugin scripts from other hosts need consent: the operator [lists them](../../../workflows/new-site-operator.md#scripts-of-plugins-from-other-providers).
- A page in another language than the site needs its own document language; core has no per-page setting. With Polylang the operator sets languages and links translations ([second language](../../../workflows/new-site-operator.md#a-second-language-polylang)); header and footer of a language are the parts `header-<language>` and `footer-<language>`, which `leitvia/update-template-part` writes like `header`.
- Plugins, users and the permalink structure are admin settings for the person. Date and time format follow the site language by themselves.
- Site title, tagline, logo and site icon: `leitvia/set-site-identity`, logo and icon as image IDs (upload with `leitvia/add-media`). An SVG logo is refused (it can carry scripts): add it as PNG or WebP at twice its shown width. The icon is square and at least 512 px: from a logo mark that is not, make a copy with `leitvia/crop-media`, the whole image (`x` 0, `y` 0, `width` 100, `height` 100), `square: true` and `min_size: 512` (transparent margin, PNG, never scaled up), then set that copy; in a package it gets its ID once applied.
- Copyright line of the footer: `copyright_since` (YYYY) and `copyright_holder` of `leitvia/set-organization`; the owner goes in `founder`.
- People: `people` of `leitvia/set-organization`, a profile of founder and authors (`name` as in `founder`), from what the site says.
- Header or footer with other texts: `leitvia/update-template-part` with `pattern` and `edits`, as a section of `leitvia/compose-page`.
- Site title and logo: the header shows them through `core/site-title` and `core/site-logo`; never put the logo there as an image block. The front page of a site without a recipe: `leitvia/set-front-page`.
- Embedded content from a provider Studio does not know (a map, a booking tool) shows a placeholder named after its host: add the provider with `leitvia/get-consent-providers` and `leitvia/set-consent-providers`, with name, hosts, purpose and the privacy policy the provider publishes.
- The header sticks by default, the logo size: [header](sticky-header.md).
- A header written as `content` keeps the navigation block of the theme with its attributes, only its items change: [navigation in the header](sticky-header.md#navigation-in-the-header).
- The sample post "Hello world!" and the "Sample Page" of a new WordPress stay; no ability deletes content. Ask the person to delete them.
- No recipe creates an accessibility statement. Build it with `leitvia/compose-page`, `frame` `contact` (the frame the recipes use for legal pages), `kind` `accessibility` and the pattern `leitvia/page-accessibility-statement`; its content is a legal text from the person (skill `leitvia-a11y`). Its placeholders in square brackets, such as `[Organization name]`, stay until the person supplies the facts; `leitvia/audit-site` lists each with the check `legal-placeholder`, an open point for the report.
