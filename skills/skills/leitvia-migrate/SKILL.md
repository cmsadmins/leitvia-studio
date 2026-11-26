---
name: leitvia-migrate
description: Moves a WordPress site from a classic theme to the Leitvia theme through the leitvia abilities only. Takes an inventory of pages, posts, menus, widgets and brand, leaves the theme switch to a person, hands the brand to leitvia-design, translates the classic HTML of every page and post into core blocks (paragraph, heading, list, image with alt text, table, quote, embed behind the consent placeholder) without losing a word or an image, rebuilds the main menu with its submenus and bundles every write into one change package. Also moves the public pages of a site outside WordPress (Wix, Squarespace, static HTML) page by page into blocks, with images, navigation and a reconciliation report. Use it when a site with classic content (no blocks) or another site moves to Leitvia; readers for page builders and ACF blocks are not part of it.
compatibility: WordPress 7.1+, PHP 8.2+. Needs the plugin Leitvia Studio, before the switch with its classic theme and after it with the theme Leitvia, reached over the MCP server leitvia (application password or OAuth token) or the REST routes of the Abilities API (application password only).
---

# leitvia-migrate

A migration takes a site built with a classic theme and the classic editor and leaves it on Leitvia with the same content as blocks: same pages, same URLs, same texts and images, the same menu, the brand as a token set. You change the site only through the abilities of Leitvia Studio.

## When to use

- A site with a classic theme and the classic editor moves to Leitvia, or content comes over from another site.
- Not for a fresh site (leitvia-setup) or content of page builders and other plugins, which stays an open item.

## Inputs required

- The person who switches the theme, and the source site when it is another one.
- Read before the first call: the [agent handbook](../../handbook/README.md). Read the page of each ability before you first call it (the handbook index links them): `audit-site`, `get-page-outline`, `get-block`, `get-contract`, `get-content-model`, `compose-page`, `update-content`, `add-media`, `update-navigation`, `update-template-part`, `list-packages`, `get-package`. Tag to block, images, the comparison and rejections of step 4: [translation details](references/translate.md); markup examples: [block markup](references/block-markup.md).

## Source outside this site

When the source is another site (any system, for example Wix), read only its public pages as a visitor, never its admin or an export, and follow [external source](references/external.md): images first in one package, then the entries and the menu in a second one, and a reconciliation report. Steps 2 and 3 below do not apply.

## Modes

- **package** (default): one key for the migration, for example `migrate-2026-10`.
- **direct:** only when the person asks. Pages and posts are in the area `content` (`draft` by default), so changes to published content wait in a package either way.

## Ground rules

- While the classic theme is active, `leitvia/get-contract` answers `leitvia_no_contract`; compare the address of your task with `home` of the public `GET /wp-json/` instead, after the switch with `site.url` of `leitvia/get-contract`. When they differ, write nothing and ask. Public pages are part of the inventory.
- **Nothing gets lost.** Every sentence, list item, table cell, link and image of a post is in its block version, word for word; only the form is new. When you cannot place something, stop and ask; never drop it quietly.
- **No classic block and no Custom HTML.** The pipeline refuses `core/freeform` and `core/html` from agents (`leitvia_unsafe_content`). Every piece of HTML becomes a registered block; the [translation details](references/translate.md#tag-to-block) name the block for each tag.
- **Themes and plugins belong to people.** The area `admin` is locked for agents. The theme switch, activating Leitvia Studio and every plugin change are checkpoints.
- **Same URLs.** Keep titles, slugs, parent pages and post dates; a slug change is no part of a migration. New addresses, the permalink structure of the old site and redirects: [addresses](references/addresses.md).
- **One write per post.** Send the whole new content of a post in one `leitvia/update-content` call. A later write to the same post in the same package builds on it and is applied after it.
- Never write review notes or HTML comments into content. Open questions go into the report.
- Texts you write (alt texts, titles, excerpts, menu labels) pass the [text gates](../leitvia-page/references/text-gates.md); moved wording stays as it was, its findings go into the report.

## Procedure

### 1. Inventory

Do this while the classic theme is still active: after the switch its menus, widgets and Customizer output no longer show on the public pages.

- **Pages, posts and entries of other post types:** `leitvia/audit-site` with `limit: 20`, page through with `after` set to `next` until `next` is `null`. Every item whose findings hold the check `classic-content` is a post to migrate. The audit covers published content; ask the person about drafts and scheduled posts you should move too, and read them by ID.
- **Content per post:** `leitvia/get-page-outline` shows classic content as one block `p:0` (`core/freeform`). `leitvia/get-block` with `block: "p:0"` returns the raw `post_content` in `inner_html` (shortcodes unrendered, no paragraphs added) and in `attachments` the `id` and `url` of each image of the site in it. The public page shows what shortcodes render.
- **Menus:** read the navigation of the public pages, usually `<nav>` in the header with nested `<ul>`: label, target and level of each item. Note menus in the footer or sidebar separately.
- **Widgets:** text, links and images in sidebars and footer areas of the public pages. List them; they move only where the person says (step 6).
- **Shop:** names of product attributes ([keep them](references/shop.md#product-attributes)) and the reviews of each product with their stars ([carry them over](references/shop.md#reviews)).
- **Brand:** site title, logo (`img.custom-logo`), colors the Customizer prints into the page (`body.custom-background`, header text color, link and button colors in the theme stylesheet), fonts. Write down the values with their use.
- **Things this skill does not move:** shortcodes, forms and blocks of other plugins, page builders, ACF blocks, custom post types without an editor. List each with the posts that use it.

Show the person the inventory as a table: posts with their kind of content (images, tables, embeds, shortcodes), menus with their items, widgets, brand values, open items. Checkpoint **Inventory confirmed**.

### 2. Theme switch

The person switches the theme; you cannot and may not. Tell them both ways:

- WP-CLI on the server: `wp theme activate leitvia` (with `wp plugin activate leitvia-studio` when Studio is not active yet).
- Backend: Appearance, Themes, Leitvia, Activate.

Checkpoint **Theme switch**. Then call `leitvia/get-contract`; it must answer with the contract of Leitvia, and `site.url` must match the address of the site. On the first visit after the switch WordPress turns the classic menu into a navigation menu for the header; step 5 replaces its items.

### 3. Brand as token set

Hand the brand values of the inventory to the skill `leitvia-design`; it maps them to the color roles of the contract, checks contrast and puts the token set into a package. Usually: page background to `surface`, header and body text to `ink`, links and buttons to `accent`. A logo goes into the header through `leitvia-design` and its checkpoints. When the person wants to keep the brand for later, skip this step and say so in the report.

### 4. Translate the content

For each post: `leitvia/get-block` with `block: "p:0"` (keep `document_hash` and `attachments`), translate `inner_html` into block markup with the [tag to block table](references/translate.md#tag-to-block), handle [images](references/translate.md#images), [compare](references/translate.md#compare-before-you-write), write. HTML without a block in the table (scripts, forms, inline styles, shortcodes of other plugins, page builders) goes to checkpoint **Open items**.

**Write:** `leitvia/update-content` with `post`, `content` (the whole new block markup), `base_hash` (the `document_hash`) and the `package` key. The result has `mode: package`. Rejections and their fixes: [translation details](references/translate.md#rejections).

**Sample of three first:** translate and write three posts with different content (one with images, one with a table, one with an embed when the site has them), run the [text and link comparison](references/wording.md) and stop at checkpoint **Sample of three**: the person checks the diff with `leitvia/get-package` or in the inbox. Change your translation where they ask, then do the rest.

### 5. Navigation

Rebuild the main menu of the inventory with `leitvia/update-navigation`: `items` with `label` and `url` per entry, one level of sub `items`. Use paths of this site (`/about-us/`) for its own pages; external links need `https`. A deeper level or more than 30 items: ask the person how to fold it (checkpoint **Open items**). The menu WordPress made on the first visit has a hash no ability returns: send `base_hash` empty, take `current_hash` from the rejection `leitvia_stale_hash` and send once more with it. Send the `package` key.

Footer and sidebar menus are links in template parts; change them with `leitvia/update-template-part` only on the person's word.

### 6. Widgets and leftovers

Widgets do not move by themselves. For each widget of the inventory the person decides: into a page, into the footer (`leitvia/update-template-part`), or gone. Do what they decide with the `package` key.

### 7. After the person applied the package

The person applies your package (its ID comes with every write) in the inbox (checkpoint **Package applied**). Then run the checks of the verification.

## Verification

- `leitvia/get-package` for conflicts.
- `leitvia/audit-site` again: no item may still report `classic-content`, `leitvia_unknown_shortcode` or `leitvia_image_fixed_height` ([block markup](references/block-markup.md)).
- The [page check](../leitvia-a11y/references/page-check.md) with `--old`; in the browser: embeds behind consent, submenus.
- Run the skill `leitvia-a11y` over the migrated pages.

## Failure modes / debugging

Codes of the translation: [translation details](references/translate.md#rejections).

- `leitvia_no_contract` before the switch: expected; compare the address with `home` of `GET /wp-json/`.
- `leitvia_unsafe_content`: a classic block or Custom HTML is left; translate it with the [tag to block table](references/translate.md#tag-to-block).
- `leitvia_stale_hash` on the menu: the protocol of step 5.

## Report

Write the report in the chat, in the language of the person:

1. **Migrated:** per post the blocks it has now, alt texts you wrote, heading levels you moved, and the package (ID and inbox link).
2. **Navigation and brand:** the menu as it is now; the token set package from `leitvia-design` or that the brand waits.
3. **Open:** per post or widget what did not move and why (shortcode of a plugin, form, image from another host, page builder, decision of the person pending).
4. **Next:** the `leitvia-a11y` report, and what only a person can test (videos, forms of other plugins).

## Escalation

Stop and ask the person at these checkpoints, then continue with the answer:

- **Inventory confirmed:** pages, posts, menus, widgets, brand values and open items are complete.
- **Theme switch:** the person activates Leitvia (and Leitvia Studio); you check with `leitvia/get-contract`.
- **Sample of three:** the person checked three translated posts before you write the rest.
- **Open items:** content without a block in this skill, images from other hosts, menus deeper than one sub level, widgets.
- **Package applied:** the person reviews and applies the package in the inbox.

## Unattended

| Checkpoint | Answer without a person |
|---|---|
| Inventory confirmed | Take the inventory as complete; it is the first part of the report. |
| Theme switch | Stop when `leitvia/get-contract` answers `leitvia_no_contract`: write nothing and report the inventory. After the switch, go on. |
| Sample of three | Write the three posts into the package and stop; the rest belongs to the next run. |
| Open items | Skip the whole post that holds the item, so nothing gets lost; list post and item. |
| Package applied | Stop; the checks of step 7 belong to the next run. |
