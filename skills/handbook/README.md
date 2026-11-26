# Leitvia agent handbook

Generated from Leitvia Studio 1.3.60. Do not edit.

What an agent needs on a WordPress site with Leitvia Studio through its 74 abilities. Agents change the site only through them: no SQL, no REST routes of WordPress core (not even to read), no WP-CLI. Reading public pages is allowed. Read this file before the first call, with Leitvia Pro also [pro.md](https://leitvia.com/docs/pro/).

## Access over MCP and REST

Sign in as a WordPress user that may edit posts, with an application password (HTTP Basic auth) or an OAuth token. Use a user of its own for the agent; every call is logged under that user.

**Which site.** Every path below starts at the address of the site, not at the host. A site of a multisite network in a subdirectory has a path: on `https://example.org/club/` the MCP server is `https://example.org/club/wp-json/leitvia-studio/v1/mcp`, and `/wp-json/` straight after the host reaches another site of the network. Call `leitvia/get-contract` first and compare `site.url` (the site the connection works on; `site.rest` is its REST base) with the address of your task; stop when they differ. An application password that Leitvia Studio created in a network works on its own site only; another site answers 401.

**MCP.** The server `leitvia` of Leitvia Studio answers at `POST /wp-json/leitvia-studio/v1/mcp`, revisions `2026-07-28` and `2025-11-25`. `tools/list` lists the abilities, named with a hyphen for the slash (`leitvia-get-contract`). `tools/call` takes the input of the ability as `arguments`. A rejection comes back as a tool result with `isError` true whose text is JSON with `code`, `message` and `data`. Details: [mcp.md](mcp.md).

**REST.** Every ability runs at `/wp-json/wp-abilities/v1/abilities/<name>/run`. The HTTP method follows the annotations: read only abilities take GET, abilities that are destructive and idempotent take DELETE, all others POST. GET and DELETE send the input as query arguments (`?input[post]=12`, lists as `input[upsells][]=4&input[upsells][]=7`), POST sends it as JSON body `{"input": {…}}`. Long texts make long URLs under DELETE; send them over MCP instead. A rejection is an error response with `code`, `message` and `data`; the HTTP status is `data.status`. Without the MCP server `leitvia`: [core REST](core-rest.md).

**Check the MCP connection.** The MCP server is the whole route `<address of the site>/wp-json/leitvia-studio/v1/mcp`, never the address of the site alone. Its tools must hold `leitvia-get-contract`; call it first. When the client finds no endpoint or gets 404, the URL lacks that path or Leitvia Studio is not active: do not retry at the host and do not guess paths. On 401 with `WWW-Authenticate: Bearer` (sign-in expired) or 403 `insufficient_scope` (reads only), ask the person. Call `GET <address of the site>/wp-json/wp-abilities/v1/abilities/leitvia/get-contract/run` with the same credentials. When it answers, work over REST and report the broken MCP connection; on 401 or 404 stop and ask.

## Read before write

The read abilities return what a write needs: post IDs, block IDs from `leitvia/get-page-outline`, pattern slugs from `leitvia/list-patterns`, blocks and their attributes from `leitvia/list-blocks`, page frames, color roles and template parts from `leitvia/get-contract`, and the hash of every target. Plan a page with `leitvia/plan-page` and score it with `leitvia/check-composition` before you build it.

The checks after a write (links, contrast) run as a WP-Cron event. Where WP-Cron does not run by itself (WordPress Playground), call `GET /wp-cron.php` after writes; the result lands in the audit log.

## Hashes and base_hash

Every write ability requires `base_hash`: the hash of the target as you last read it, 16 hex characters. When the target changed since, the write is rejected with `leitvia_stale_hash`, which carries `current_hash`. Read the target again and repeat the change on the current state, once.

- A new object (a new page, pattern, token set or image) has no hash: send an empty `base_hash`.
- A post, page, attachment, navigation menu or template part: `hash` of `leitvia/get-page-outline`, which is also `document_hash` of `leitvia/get-block`. Block hashes are for comparing blocks; `base_hash` is always the hash of the whole document.
- A product, product category or tag: its `hash` from `leitvia/get-product` or `leitvia/list-products`; an attribute: from `leitvia/list-product-attributes`.
- Targets no read ability returns a hash for (global styles, a menu, template part or template whose ID you do not know): send an empty `base_hash`, take `current_hash` from the rejection `leitvia_stale_hash` and repeat the call once with it.

The result of a write carries the new `hash`; use it as `base_hash` of the next change of the same target.

## Write results

Most write abilities return the result of the pipeline:

| Field | Meaning |
|---|---|
| `mode` | `live` (written and visible), `draft` (written as a draft), `package` (stored in a change package for a person), `unchanged` (nothing to write) |
| `level` | autonomy level that applied |
| `kinds` | content kinds the change touches |
| `target` | the object, for example `{"post": 12}` |
| `hash` | hash of the target after the write |
| `package` | ID of the change package, 0 for none |
| `snapshot` | ID of the snapshot a rollback restores, 0 for none |
| `findings` | warnings of the checks; errors reject the write. Only findings the write brings or makes worse count |
| `report` | further data of some abilities |
| `slug_changed` | only when the slug you sent is taken or reserved: `requested` and the `slug` the address gets. Tell the person |

## Autonomy levels and content kinds

The site owner sets how far agents may go per area. The pipeline takes the level of the area of a write:

| Level | Effect on a write |
|---|---|
| `locked` | Agents may not change the area. Every write is rejected with `leitvia_locked`. |
| `suggest` | Every write lands in a change package for a person to decide. |
| `draft` | New posts are created as drafts and drafts change directly. Changes that visitors would see (published posts, terms, options) land in a change package. |
| `autonomous` | Writes go live. A write with warnings among its findings lands in a change package. |

Default levels per area (profile standard; the site owner can change them):

| Area | Default | What it covers |
|---|---|---|
| `content` | `draft` | Pages, posts and media. |
| `corrections` | `autonomous` | Small fixes such as alt text and internal links. |
| `structure` | `draft` | Content model, navigation, Lab patterns and sections of pages. |
| `design` | `suggest` | Token sets, global styles and template parts. |
| `admin` | `locked` | Plugins, users and settings. Always locked. |
| `products` | `draft` | Products, product categories, tags and attributes of the shop. |

Some content kinds lower the level to at most `suggest`, whatever the area allows, unless a person unlocked the kind in the settings. The result lists them in `kinds`.

| Kind | Covers |
|---|---|
| `prices` | Pricing blocks, price columns of comparison tables and product prices. Prices in plain text are not detected. |
| `legal` | Pages with a legal page kind (imprint, privacy, terms, withdrawal, accessibility) and the privacy policy page. |
| `slugs` | A changed slug or parent page, which changes a URL. |
| `organization` | Site options such as the site title, the logo and the reading settings. |
| `tax` | Tax status and tax class of products. |
| `shipping` | Shipping class of products. |

Changes of site options land in a change package unless the user may manage options. Agents can neither raise a level nor unlock a kind; tell the person when the work needs it.

## Mode package

Every write ability takes `package`, a key of the task (up to 64 characters). A write with a key always lands in the change package of that key, even where its level would let it go live; the result returns the package ID, and a person applies the package in the inbox of Leitvia Studio. Send the same key on every write of one task, for example `homepage-2026-10`, so the person gets one package instead of many. Without a key the levels decide, and every write they hold back gets a package of its own. The same change sent twice is stored once. Several writes of one task to the same target build on each other in the package (send the hash you read) and are applied in order.

`leitvia/list-packages` lists your packages, `leitvia/get-package` shows the diff per operation. Agents may apply (`leitvia/apply-package`) and roll back (`leitvia/rollback`) only operations whose level is `autonomous`; everything else is `leitvia_needs_human` and waits for a person.

## Rejections

A rejection carries `code`, a `message` that says how to fix it, and `data` with `status` (HTTP), `reason`, `target`, `next_ability` (empty for none) and `retry`, plus details such as `current_hash` or `findings`.

| Retry | What the agent does |
|---|---|
| `once` | Correct the call once, following message and `next_ability`. If the second call is rejected too, stop and ask a person. |
| `never` | Do not repeat the call. Stop and tell the person the message. |
| `after:<seconds>` | Wait the given seconds, then repeat the same call. |

All rejections with their messages: [rejections.md](rejections.md).

## Abilities

Each ability has a page with its inputs and output, named after its MCP tool, for example [`abilities/leitvia-get-contract.md`](abilities/leitvia-get-contract.md). Read only those you call.

### Read

Read abilities change nothing. Call them first: they return the IDs, block IDs and hashes the write abilities need.

| Ability | REST | What it does |
|---|---|---|
| [`leitvia/audit-site`](abilities/leitvia-audit-site.md) | GET | Runs the content checks over published content, at most 20 posts per call in ID order. |
| [`leitvia/check-composition`](abilities/leitvia-check-composition.md) | GET | Scores a page against the composition rules of the contract: pass pattern slugs in page order, or a post ID to read the sections of a saved page from metadata.patternName. |
| [`leitvia/export`](abilities/leitvia-export.md) | GET | Exports the site as a portable JSON package, page by page. |
| [`leitvia/get-block`](abilities/leitvia-get-block.md) | GET | Returns one block of a post by its ID or path ID with all attributes, its markup alone and with children, the image attachments, its hash, the IDs of its children and the document hash. |
| [`leitvia/get-consent-providers`](abilities/leitvia-get-consent-providers.md) | GET | Returns the providers of embedded content from the privacy settings. |
| [`leitvia/get-content-model`](abilities/leitvia-get-content-model.md) | GET | Returns the content model declaration: post types, taxonomies, fields, relations and site settings with their definitions, never content. |
| [`leitvia/get-contract`](abilities/leitvia-get-contract.md) | GET | Returns the site this connection works on and the design contract of the active theme: version, rules, color roles, contrast pairs, composition rules, page frames, template parts, templates, block styles and style variations, plus the base hashes of template parts, templates and navigation menus. |
| [`leitvia/get-package`](abilities/leitvia-get-package.md) | GET | Returns a change package with its operations: status, the post each applied operation wrote, conflicts and, for open operations, a block diff against the current state. |
| [`leitvia/get-page-outline`](abilities/leitvia-get-page-outline.md) | GET | Returns a post as a compact block tree: per block the ID, name, pattern, the first 80 characters of text, the link targets of the block itself, for image and cover blocks the image with attachment ID, alt text and decorative mark, size, hash and children. |
| [`leitvia/get-pattern`](abilities/leitvia-get-pattern.md) | GET | Returns one pattern of the theme or of Leitvia Studio with its markup, metadata and the first 20 posts and templates that use it. |
| [`leitvia/get-product`](abilities/leitvia-get-product.md) | GET | Returns one product or variation: texts, images with alt text, categories and tags with their hash, attributes, price, tax and shipping class, stock status, merchandising and the hash to use as base_hash. |
| [`leitvia/get-seo`](abilities/leitvia-get-seo.md) | GET | Returns the SEO data of posts, pages and entries from the active SEO plugin. |
| [`leitvia/get-site-health`](abilities/leitvia-get-site-health.md) | GET | Returns the versions of WordPress, PHP, the theme and the plugins and the updates WordPress knows of, read from its last update check; nothing is requested. |
| [`leitvia/get-tokens`](abilities/leitvia-get-tokens.md) | GET | Returns the active design tokens as a DTCG document, the token sets with their status, the active style variations, whether the global styles drifted from the active set, the contrast findings of the current palette and the hash of the global styles, the base_hash of leitvia/activate-token-set and leitvia/set-variation. |
| [`leitvia/list-blocks`](abilities/leitvia-list-blocks.md) | GET | Returns the block catalog of Leitvia Studio and its add-ons with attribute schema and usage rules, and the registered block styles of every block, core blocks included, by block name; a style is set as the class is-style-\<name>. |
| [`leitvia/list-building-blocks`](abilities/leitvia-list-building-blocks.md) | GET | Lists the building blocks of add-ons: name, title, description, whether the site has them, their post types and taxonomies, the patterns that show them and the number of sample entries. |
| [`leitvia/list-content`](abilities/leitvia-list-content.md) | GET | Lists posts, pages and entries of the content model, newest first, with hash, terms and the open package that waits for each. |
| [`leitvia/list-media`](abilities/leitvia-list-media.md) | GET | Lists the media library, newest first: ID, URL, file name, MIME type, file size in bytes, alt text, whether it was made with AI, width, height and hash. |
| [`leitvia/list-packages`](abilities/leitvia-list-packages.md) | GET | Lists your change packages, newest first, a page at a time: items with ID, title, key, state, operations per status and a preview link, plus total, page, per_page and pages. |
| [`leitvia/list-patterns`](abilities/leitvia-list-patterns.md) | GET | Lists the patterns of the theme, of Leitvia Studio and of its add-ons with composition metadata, image slots, when to use and avoid them, lab status, the replacement of deprecated ones and the successor of older ones that stay approved. |
| [`leitvia/list-product-attributes`](abilities/leitvia-list-product-attributes.md) | GET | Lists the global product attributes with their values and hash. |
| [`leitvia/list-product-reviews`](abilities/leitvia-list-product-reviews.md) | GET | Lists product reviews with rating and hash, 20 per page, oldest first; per review ID, product, author name, date, text, rating, verified owner and status. |
| [`leitvia/list-products`](abilities/leitvia-list-products.md) | GET | Lists the products of the shop, 20 per page, with title, type, status, price, stock status, featured flag and hash, plus all product categories with their hash. |
| [`leitvia/list-recipes`](abilities/leitvia-list-recipes.md) | GET | Lists the site recipes of Leitvia Studio and its add-ons with owner, pages and whether the plugins they require are active. |
| [`leitvia/plan-page`](abilities/leitvia-plan-page.md) | GET | Proposes a page for a goal and content points: page frame, template, the patterns in page order with role, slot, text lengths and the points each section carries, and the composition score. |
| [`leitvia/preview-token-set`](abilities/leitvia-preview-token-set.md) | GET | Returns a signed address that shows the site with a token set, for that request only. |

### Set up

Content model, design tokens, style variation, template parts, navigation and recipes.

| Ability | REST | What it does |
|---|---|---|
| [`leitvia/activate-token-set`](abilities/leitvia-activate-token-set.md) | POST | Writes the presets of a token set into the global styles of the active theme. |
| [`leitvia/add-building-block`](abilities/leitvia-add-building-block.md) | POST | Adds a building block of leitvia/list-building-blocks to the site: its content types, taxonomies, relations and site settings through leitvia/define-content-type, merged with the entries that exist so fields a person added stay; the default values of its site settings that are empty; and its sample entries with their terms as drafts. |
| [`leitvia/apply-recipe`](abilities/leitvia-apply-recipe.md) | POST | Sets up the site from a recipe of leitvia/list-recipes: content model and building blocks, token set, style variation, header and footer, pages from patterns, navigation, reading settings and options that save data. |
| [`leitvia/create-token-set`](abilities/leitvia-create-token-set.md) | POST | Creates a token set from a DTCG document. |
| [`leitvia/define-content-type`](abilities/leitvia-define-content-type.md) | POST | Adds entries to the content model and keeps the others. |
| [`leitvia/set-consent-providers`](abilities/leitvia-set-consent-providers.md) | POST | Replaces the providers of embedded content in the privacy settings. |
| [`leitvia/set-front-page`](abilities/leitvia-set-front-page.md) | POST | Makes an existing page the static front page; 0 shows the latest posts there again. |
| [`leitvia/set-llms`](abilities/leitvia-set-llms.md) | POST | Sets llms.txt, the file at the root of the site that tells AI assistants what the site is about, and whether robots.txt lets AI crawlers in. |
| [`leitvia/set-motion`](abilities/leitvia-set-motion.md) | POST | Sets the motion of the whole site: off, subtle or bold. |
| [`leitvia/set-organization`](abilities/leitvia-set-organization.md) | POST | Sets the details of the organization behind the site: kind of business, a description of what it does, a logo for structured data, the page with the editorial guidelines, address, phone, email, founding date, founder or owner, people, the copyright line of the footer, area served, opening hours or service times, what phone and email are for, profiles on other sites, the rating on a review site and how the default texts visitors read address them. |
| [`leitvia/set-permalinks`](abilities/leitvia-set-permalinks.md) | POST | Sets the permalink structure of posts and the bases of category and tag addresses, as in Settings, Permalinks, for a migration that keeps the addresses of the old site: structure such as /news/%postname%/ or /%postname%/, category_base and tag_base such as topic. |
| [`leitvia/set-posts-page`](abilities/leitvia-set-posts-page.md) | POST | Makes an existing page the posts page of the reading settings, the address that lists all posts; 0 clears it. |
| [`leitvia/set-privacy-page`](abilities/leitvia-set-privacy-page.md) | POST | Makes an existing page the privacy policy page of the site; 0 clears it. |
| [`leitvia/set-redirects`](abilities/leitvia-set-redirects.md) | POST | Sets the redirects of moved and removed addresses, for a migration or a new structure. |
| [`leitvia/set-seo-titles`](abilities/leitvia-set-seo-titles.md) | DELETE | Sets whether titles in search results end with " \| " and the site name, without an SEO plugin. |
| [`leitvia/set-site-identity`](abilities/leitvia-set-site-identity.md) | POST | Sets the site title, the tagline, the logo and the site icon. |
| [`leitvia/set-variation`](abilities/leitvia-set-variation.md) | POST | Applies a style variation of the contract to the global styles of the active theme: a full variation replaces them, a colors or typography variation replaces only its part. |
| [`leitvia/update-navigation`](abilities/leitvia-update-navigation.md) | POST | Replaces the links of a navigation menu, by default the one published last, which headers without a chosen menu show. |
| [`leitvia/update-site-values`](abilities/leitvia-update-site-values.md) | POST | Sets values of site settings of the content model declared with bindable true, the values blocks show on every page through the binding source leitvia/setting, such as opening hours or a phone number. |
| [`leitvia/update-template`](abilities/leitvia-update-template.md) | POST | Replaces a template with a template pattern made for it or with changed block markup. |
| [`leitvia/update-template-part`](abilities/leitvia-update-template-part.md) | POST | Replaces a template part of the contract, such as header, footer or sidebar, with a pattern made for its area or with changed block markup. |

### Build

Pages from patterns, single blocks, Lab patterns and media.

| Ability | REST | What it does |
|---|---|---|
| [`leitvia/add-media`](abilities/leitvia-add-media.md) | POST | Adds an image to the media library from a URL of this site or as Base64 data up to 5 MB, with alt text or marked as decorative. |
| [`leitvia/compose-page`](abilities/leitvia-compose-page.md) | POST | Creates a page from patterns in the order of a page frame of the contract, or rebuilds an existing page. |
| [`leitvia/create-content`](abilities/leitvia-create-content.md) | POST | Creates a post, a page or an entry of the content model from block markup or patterns; base_hash is empty. |
| [`leitvia/create-pattern`](abilities/leitvia-create-pattern.md) | POST | Creates a pattern in the Lab as a draft with version 0.1.0 from block markup; base_hash is empty. |
| [`leitvia/create-product`](abilities/leitvia-create-product.md) | POST | Creates a simple product. |
| [`leitvia/crop-media`](abilities/leitvia-crop-media.md) | POST | Crops an image of the media library into a new image. |
| [`leitvia/import-post`](abilities/leitvia-import-post.md) | POST | Imports one post, page or entry of the content model from another site for a migration: source is its old address, content its HTML. |
| [`leitvia/update-block`](abilities/leitvia-update-block.md) | POST | Changes one block found by its ID or path ID: attributes from the block catalog and the level of a heading, the text of a paragraph, heading, list item or button, the citation of a quote, the summary of a details block, the link of a button or of the one link in a text, words in a text with replace_text, which keeps all links and inline formatting, also where text is refused for several links, the cells of a core/table or leitvia/comparison-table with table, or the image of an image or cover block with alt text. |
| [`leitvia/vary-pattern`](abilities/leitvia-vary-pattern.md) | POST | Creates a variant of a Lab pattern or a theme pattern as a draft in the Lab, from new markup or from the original with edits at block IDs; base_hash is empty. |

### Maintain

Texts, links, alt text, patterns of sections, trash, media deletion, products and change packages of existing content.

| Ability | REST | What it does |
|---|---|---|
| [`leitvia/apply-package`](abilities/leitvia-apply-package.md) | POST | Applies the open operations of a change package, all or the listed ones. |
| [`leitvia/delete-media`](abilities/leitvia-delete-media.md) | POST | Deletes a file of the media library for good, with all its sizes; there is no way back. |
| [`leitvia/insert-section`](abilities/leitvia-insert-section.md) | POST | Inserts sections into the content of an existing post, page or entry, or moves one of its top-level sections; the rest of the content stays as it is. |
| [`leitvia/reject-package`](abilities/leitvia-reject-package.md) | POST | Discards an open or partly applied change package. |
| [`leitvia/remove-section`](abilities/leitvia-remove-section.md) | POST | Takes one top-level section out of the content of a post, page or entry; the rest of the content stays as it is. |
| [`leitvia/repair-links`](abilities/leitvia-repair-links.md) | POST | Repairs internal links of one post: from pairs of old and new link, or with auto through the old slugs WordPress keeps for redirects. |
| [`leitvia/replace-pattern`](abilities/leitvia-replace-pattern.md) | POST | Replaces top-level sections of one post with another pattern. |
| [`leitvia/rollback`](abilities/leitvia-rollback.md) | POST | Rolls back the applied operations of a change package from their snapshots. |
| [`leitvia/set-alt-text`](abilities/leitvia-set-alt-text.md) | POST | Sets the alt text of an image: without post and block on the attachment in the media library, with post and block on one image or cover block that shows it. |
| [`leitvia/set-merchandising`](abilities/leitvia-set-merchandising.md) | POST | Sets the featured flag, the upsells, the cross-sells and the sort order of one product. |
| [`leitvia/set-page-structure`](abilities/leitvia-set-page-structure.md) | POST | Sets what a page is within the site, for breadcrumbs, structured data, llms.txt and the composition check. |
| [`leitvia/trash-content`](abilities/leitvia-trash-content.md) | POST | Moves a post, page or entry to the trash. |
| [`leitvia/update-content`](abilities/leitvia-update-content.md) | POST | Updates title, excerpt, slug, date, status, author, terms, fields, rows of repeaters, featured image, comment status and the texts and button links of several blocks of one post in one operation, blocks found by their ID from the outline. |
| [`leitvia/update-product`](abilities/leitvia-update-product.md) | POST | Updates texts, status, images, categories and tags of one product or variation, and its prices, tax and shipping class. |
| [`leitvia/update-product-attribute`](abilities/leitvia-update-product-attribute.md) | POST | Renames a global product attribute and its values and sorts the values, in one operation. |
| [`leitvia/update-product-category`](abilities/leitvia-update-product-category.md) | POST | Updates name, description, parent and image of a product category, or name and description of a product tag. |
| [`leitvia/update-product-review`](abilities/leitvia-update-product-review.md) | POST | Sets rating, verified flag or status of a product review. |
| [`leitvia/update-seo`](abilities/leitvia-update-seo.md) | POST | Writes SEO title, meta description, robots and Open Graph of one post through the active SEO plugin. |
