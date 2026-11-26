---
name: leitvia-setup
description: Sets up a WordPress site with the Leitvia theme and Leitvia Studio from a briefing, through the leitvia abilities only. Picks a site recipe (company, shop, blog, publication, documentation), applies content model, style variation, header, footer, navigation and base pages, and hands everything to a person as a change package. Use it for a new site or a fresh start of an existing one; brand colors and fonts belong to leitvia-design, single pages to leitvia-page.
compatibility: WordPress 7.1+, PHP 8.2+. Needs the theme Leitvia and the plugin Leitvia Studio, reached over the MCP server leitvia (application password or OAuth token) or the REST routes of the Abilities API (application password only). The recipe for a shop also needs WooCommerce.
---

# leitvia-setup

Turn a briefing into a working site: recipe, style variation, header, footer, navigation and the base pages, ready for a person to review. You change the site only through the abilities of Leitvia Studio.

## When to use

- A new site, or a fresh start of an existing one, from a briefing.
- Not for brand colors and fonts (leitvia-design), single pages (leitvia-page) or moving a classic site (leitvia-migrate).

## Inputs required

- Access as the agent user: [handbook, Access over MCP and REST](../../handbook/README.md#access-over-mcp-and-rest).
- The answers of step 1. For a shop, WooCommerce is active.
- Read before the first call: the [agent handbook](../../handbook/README.md). Read the page of each ability before you first call it (the handbook index links them): `get-contract`, `list-recipes`, `get-content-model`, `define-content-type`, `apply-recipe`, `compose-page`, `get-package`, `get-page-outline`, `update-content`, `audit-site`, `list-packages`, `get-seo`, for a shop `create-product`. Statuses of a recipe run, shop sites and what a recipe does not cover: [recipe details](references/recipe.md). Format of own content types: [content model](references/content-model.md). Header and footer in grid cells and the menu overlay: [grid header](references/grid-header.md).

## Ground rules

- Follow the [rules for every task](../../handbook/README.md#rules-for-every-task). Reading the operator's other sites is allowed.
- Mode `package` is the default for a live site: send the same `package` key on every write of the task, for example `setup-2026-10`. Use mode `direct` only when a person is present and says the site is not live yet; the drafts it leaves are theirs to publish.
- **Facts are never guessed.** Organization data, prices, legal texts, figures, quotes and people come only from a source you can name: the person, a page of the site or a read ability, with the source in your report. Without one, stop at a checkpoint; never write a placeholder that looks like a fact.
- Here that covers company name, address, register entries, VAT ID, managing directors, data protection officer, server location and retention periods.
- Take numbers about the site (patterns, page frames, variations, template parts) from `leitvia/get-contract` and `leitvia/list-patterns` (`total`), never from prose, marketing pages or memory.
- The round of an empty `base_hash` with `leitvia_stale_hash` is protocol, not a rejection.

## Procedure

### 1. Read the briefing and ask

Before any call that writes, collect the answers to these questions. Ask only what the briefing leaves open.

1. Who operates the site (legal entity)? Does the operator have legal pages elsewhere (imprint, privacy policy, accessibility statement) that this site should reuse?
2. Who supplies the legal texts? You draft structure, never the legal content.
3. Which URLs must stay fixed because other sites already link to them (for example `/legal-notice/` or `/privacy-policy/`)? Slugs of a recipe are not sacred; fixed links are.
4. Is the site live (mode `package`) or a fresh install nobody visits yet (mode `direct`)?
5. Which kind of site: company, shop, blog, publication or documentation? A shop needs WooCommerce active before you start.
6. Du or Sie? Sie: `leitvia/set-organization` with `address` `formal`.
7. Does the site have a design of its own (design file, Figma, brand rules beyond colors and fonts), or will it differ from the patterns? Then the child theme Leitvia Child is the active theme from the start, set up by the operator before your first write ([child theme from the start](../../workflows/new-site-operator.md#child-theme-from-the-start)).

When the operator has legal pages elsewhere, fetch them, copy the facts verbatim and compare the facts of the pages with each other (name, address, contact on imprint versus privacy policy). Report every contradiction to the person; never decide which one is right.

Checkpoint "Briefing confirmed".

### 2. Read the site

- `leitvia/get-contract`: page frames, template parts, style variations, contrast pairs.
- `leitvia/list-recipes`: name, pages, `available` and `reason`. A recipe with `available: false` names the plugin it needs; tell the person, do not install plugins.
- `leitvia/get-content-model` when the briefing asks for own content types (team, events, courses): add them with `leitvia/define-content-type` (post types, fields, select options, relations, taxonomies; format and a complete example: [content model](references/content-model.md)) in the same package; their entries come after the package was applied, from `leitvia-page`, `leitvia-migrate` or `leitvia-news`.

### 3. Choose the recipe

Pick the recipe whose `description` and `pages` fit the briefing:

| Briefing | Recipe |
|---|---|
| Company, agency, freelancer | `leitvia/company` |
| Online shop with WooCommerce | `leitvia/shop` |
| Personal or team blog | `leitvia/blog` |
| Magazine, newsroom | `leitvia/publication` |
| Product or project documentation | `leitvia/documentation` |

Tell the person which pages the recipe creates and which slugs they get. A fixed slug from step 1 that differs goes to `leitvia/apply-recipe` as `slugs` (`{"imprint": "legal-notice"}`); navigation, footer and settings follow.

Checkpoint "Recipe chosen".

### 4. Apply the recipe

Call `leitvia/apply-recipe` with `recipe` and your `package` key. With a key nothing goes live: every part lands in that one change package, whatever the autonomy of the site allows. In mode `direct` send no key; then the autonomy levels decide and what they hold back still lands in one package. With the default levels new pages become drafts the person publishes. The person rejects a package left by a switch to `package`. Read the `steps` of the result (statuses: [recipe details](references/recipe.md#status-of-a-step)). For a shop, create the products of the briefing with `leitvia/create-product` and the same key; they need nothing from the package ([shop sites](references/recipe.md#shop-sites)).

A site that already has design and pages gets a dry run first (`dryRun: true`) and then only the parts it needs; a single content feature is a building block, not a recipe ([parts and dry run](references/recipe.md#parts-and-dry-run)).

The recipe pages carry the sample texts of their patterns. Read the diff with `leitvia/get-package` and list every sample text with its page before the person applies the package ([recipe details](references/recipe.md#sample-texts-of-the-recipe)). Tell the person what the package contains and that it needs their decision in the inbox of Leitvia Studio.

Checkpoint "Package applied".

### 5. Confirm the setup after the package was applied

After the person applied the package, call `leitvia/apply-recipe` once more with the same key. It must report `package: 0` and only `exists` or `unchanged` (or `unavailable` for parts the site lacks); that is your proof the setup is complete, and its `steps` hold the IDs of the pages (`target.post`). Anything else means an operation of the package stayed open: read it with `leitvia/get-package` and report it.

### 6. Legal pages

Recipe legal pages (imprint, privacy policy, terms, withdrawal) contain placeholder text that says it is a placeholder. Replace it only with text the person supplied:

1. Find the page ID in the `steps` of the recipe run (`target.post`) and read it with `leitvia/get-page-outline`; its `hash` is the `base_hash`.
2. Write the supplied text with `leitvia/update-content` (block IDs from the outline) and the same `package` key. Legal pages always land in a package (content kind `legal`), whatever the area allows. Copy each sentence character for character, punctuation included: no reformatting into labels, no merged or split sentences. A fact that is still missing (VAT ID, e-mail, phone) stays out of the page; never write "still open" or similar onto a legal page, list it in your report.
3. Until the person supplies the texts, keep the placeholders and list the open pages in your report. Never publish a shop or site as finished while a legal placeholder is visible.

Checkpoint "Legal texts supplied by the human".

## Verification

- Step 5 reports `package: 0`.
- Design of its own (question 7): `leitvia/get-site-health` reports `theme.slug` `leitvia-child` and `theme.parent` `Leitvia`. `leitvia` instead: list the child theme as a step of the person.
- `leitvia/audit-site` (pass `next` as `after` until `next` is null): every item without findings, except `legal-placeholder` on legal pages whose texts the person has not supplied yet (list them as open).
- Fetch the public URL of every page the recipe created and the front page: status 200 and exactly one `h1`. A 404 often means the page waits in a package or stayed a draft.
- `leitvia/list-packages` with `state` `open`: empty when the person applied everything.
- **Search visibility on** before the site counts as finished: `indexable` of `leitvia/get-seo` is true and the front page has no `noindex`. Only the person changes it (Settings, Reading, "Discourage search engines"); while it is off, it is an open point of the report, high.
- For a shop, the WooCommerce pages and the privacy options: [recipe details](references/recipe.md#shop-sites). Tell the person what a recipe does not cover (avatars, page language, admin settings): [recipe details](references/recipe.md#what-a-recipe-does-not-cover). Site title, tagline, logo and icon: `leitvia/set-site-identity` in the same package, values from the briefing.

## Failure modes / debugging

Every code with its retry rule: [rejections](../../handbook/rejections.md).

- `leitvia_recipe_unavailable`: the recipe needs a plugin; `leitvia/list-recipes` names it in `reason`. Tell the person.
- `leitvia_forbidden_field` from `leitvia/define-content-type`: drop `showInAbilities`; opening a type to agents is the decision of a person.
- `leitvia_page_missing`: a setting points to a page that still waits in the package. It works once the person applied it.
- Step 5 lists an operation that is not `exists`: the person left it open in the inbox; report it, never apply it.

## Escalation

Stop and wait for the person at each of these checkpoints. Report what you did, what is open and the exact question.

1. **Briefing confirmed:** operator, kind of site, mode, fixed slugs and who supplies legal texts are answered.
2. **Recipe chosen:** the person agreed to the recipe and the list of pages and slugs it creates.
3. **Legal texts supplied by the human:** imprint, privacy policy and, for shops, terms and withdrawal text come from the person, verbatim. Without them the placeholders stay and the site is not finished.
4. **Package applied:** the person reviewed and applied the change package in the inbox. Confirm the setup afterwards (step 5); the package of the legal texts is the same checkpoint.
5. **Shop settings** (recipe `leitvia/shop`): the person set up WooCommerce as [shop sites](references/recipe.md#shop-sites) lists it. No ability does it; until then the checkout cannot take an order.

Also stop after the second rejection of the same call, and whenever two sources of the operator contradict each other.

## Unattended

When no person answers (a scheduled or hosted run), do not wait at a checkpoint. Send the `package` key on every write, even when the briefing says nobody visits the site (mode `direct` needs a person who is present); never apply, roll back or publish anything. Take facts only from a source you name in the report (briefing, a page of the site, a read ability); without one the fact stays out and becomes an open question. A second rejection, or `retry: never`, stops that part. End with the package ID, what it holds and the open questions. Each checkpoint gets this answer:

| Checkpoint | Answer without a person |
|---|---|
| Briefing confirmed | Take operator, kind of site and fixed URLs from the briefing only; mode is `package`, whatever the briefing says about visitors. When the kind of site is missing, stop. |
| Recipe chosen | The recipe the table of step 3 names for the kind of site in the briefing; when two fit, stop. |
| Legal texts supplied by the human | Keep the placeholders; list the legal pages as open. |
| Package applied | Stop after step 4; the package waits for a person. Steps 5 and 6 and the verification belong to the next run. |
| Shop settings | List the shop settings of [shop sites](references/recipe.md#shop-sites) as steps of the person. |
| Contradicting sources | Write neither value; list both with their sources. |

Steps only a person can do go into the report as a list: [new-site, steps of the person](../../workflows/new-site.md#steps-of-the-person). A briefing that asks for an invented site is the source of its invented content: [invented sites](../../workflows/new-site.md#invented-sites).
