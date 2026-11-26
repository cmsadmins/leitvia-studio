---
name: leitvia-shop
description: Looks after the catalog of a WooCommerce shop on a WordPress site with the Leitvia theme and Leitvia Studio, through the leitvia abilities only. Reads products and categories, improves product texts, images with alt text, categories and tags, sets featured products, upsells, cross-sells and the sort order, and adds simple products, all in one change package for a person. Prices, tax and shipping change only with a value from the person and always wait for a person; orders, coupons and customers stay out of reach. Use it for product care in an existing shop; setting up a new shop belongs to leitvia-setup, shop pages to leitvia-page.
compatibility: WordPress 7.1+, PHP 8.2+. Needs the theme Leitvia, the plugin Leitvia Studio and WooCommerce, reached over the MCP server leitvia or the REST routes of the Abilities API with an application password.
---

# leitvia-shop

Product care takes a shop that already sells and makes its catalog clearer: better texts, described images, tidy categories, products that lead to each other. You change the shop only through the abilities of Leitvia Studio; a person decides on prices and applies the package.

## When to use

- Care of the catalog of a shop that already sells: texts, images, categories, attributes, reviews, merchandising and new simple products.
- Not for setting up a shop (leitvia-setup, recipe `leitvia/shop`), shop pages (leitvia-page) or shop settings, which are the person's.

## Inputs required

- Access: MCP server `leitvia` or the REST routes of the Abilities API, as the agent user with its application password: [handbook, Access over MCP and REST](../../handbook/README.md#access-over-mcp-and-rest).
- WooCommerce is active. Prices, product facts and the products to feature come from the person or a source they name.
- Read before the first call: the [agent handbook](../../handbook/README.md) (access, hashes, autonomy, packages, rejections, index of all abilities). Read the page of each ability before you first call it (the handbook index links them): `list-products`, `get-product`, `update-product`, `update-product-category`, `set-merchandising`, `list-product-attributes`, `update-product-attribute`, `list-product-reviews`, `update-product-review`, `create-product`, `add-media`, `set-alt-text`, `list-packages`, `get-package`.

## Modes

- **package** (default): send the same `package` key on every write of the task, for example `catalog-2026-10`. One task, one package for a person.
- **direct:** only when the person asks for it. The area `products` is `draft` by default: changes to drafts are written, changes to published products wait in a package of their own. Send no `package` key: a key always means a package.

## Ground rules

- Call `leitvia/get-contract` first and compare `site.url` with the address of your task; when they differ, write nothing and ask the person. Never use SQL, WP-CLI, the REST routes of WordPress core (`/wp-json/wp/v2/...`) or of WooCommerce (`/wp-json/wc/...`), not even to read. Reading public pages of the shop is fine. The product abilities exist only while WooCommerce is active; when `leitvia/list-products` is unknown, stop and tell the person. On a Leitvia site these abilities come before the WordPress skills `wp-patterns`, `wp-block-themes` and `wp-wpcli-and-ops`, and you never write files in the theme.
- Read before write. Take product IDs, term IDs and hashes from `leitvia/list-products` and `leitvia/get-product`, never from memory. A product sends the `hash` of `leitvia/get-product` as `base_hash`, a category or tag the `hash` of its term.
- **Facts are never guessed.** Organization data, prices, legal texts, figures, quotes and people come only from a source you can name: the person, a page of the site or a read ability, with the source in your report. Without one, stop at a checkpoint; never write a placeholder that looks like a fact.
- Product facts are facts too: material, size, weight, origin, care, certificates, delivery times and claims such as "handmade" or "organic" come from the product data (`attributes`, the current texts), a page of the site or the person. Better wording of a known fact is your work; a new fact is not.
- **Prices are never invented,** not even as a rounded or "psychological" price. `regular_price` and `sale_price` take a decimal number with a dot, from the person or a price list they named. A sale of "20 percent off" is computed from the current `price.regular` of `leitvia/get-product`; show the number at checkpoint **Facts from the person**. Changes to prices, tax and shipping land at most as a suggestion (content kinds `prices`, `tax`, `shipping`) until a person unlocked them; agents cannot unlock them.
- Orders, refunds, coupons, customers and sales figures are out of reach: every such object answers `leitvia_out_of_scope` with `retry: never`. Never infer best sellers; the person names them.
- Shop settings (payment, shipping zones, tax rates, currency, stock management) are the person's; no ability changes them.
- A rejection is corrected at most once, following `message`, `next_ability` and `retry`. If the second call is rejected too, or `retry` is `never`, stop and tell the person.
- Never write review notes or HTML comments into product texts. Open questions go into the report.

## Procedure

### 1. Read the catalog

`leitvia/list-products` returns 20 products per page with their `hash`, plus `categories`; page with `page` up to `pages`, filter with `category` or `search`. Drafts are listed too. Then `leitvia/get-product` for each product you touch: texts, `images` with `alt`, `categories` and `tags` with their hash, `attributes`, `price` (read only), `upsells`, `cross_sells`, `variations` and `hash`.

A variable product lists the IDs of its variations; read each with `leitvia/get-product`. Of the product data a variation takes only image, prices, tax class and shipping class; gallery, categories, tags, tax status and merchandising belong to its parent.

### 2. Plan

List per product what you change and the source of each fact. Checkpoint **Plan confirmed** before the first write.

### 3. Texts and status

`leitvia/update-product` with `title`, `short_description` and `description`, all changes of one product in one call. Plain text becomes paragraph blocks (a blank line starts one); send block markup for lists or headings. The description has no fixed length, the short description up to 5,000 characters. Keep the short description to the point of the product (what it is, for whom, the one fact that decides); details go into the description. The product page shows it as it is (lists stay lists), without one nothing. Change `status` only when the person asks; `publish` makes a draft visible to buyers.

### 4. Images

A product image needs alt text that says what the picture shows; product images are never decorative. An image of the library without alt text gets it first with `leitvia/set-alt-text` (`image`, `alt`), a new image comes with `leitvia/add-media` and `alt`. Then `image` and `gallery` (up to 20) on `leitvia/update-product`. An image added in the same package exists only after the person applied it: set it on the product in a second package. Product images square (1:1, 800 px or more).

More than three images, or a newsletter sign-up: [shop pages](references/shop-pages.md).

### 5. Categories, tags and attributes

- Assign: `categories` and `tags` of `leitvia/update-product` take term IDs and replace the current lists; send the full list.
- Change a term: `leitvia/update-product-category` with `term` and the term `hash` changes `name`, `description` and, for categories, `parent` (0 for top level) and `image` (with alt text, 0 to remove). Tags have neither. The slug stays, so links keep working.
- A new category comes only with `leitvia/create-product`, as a name in `categories`; an empty category is a task for the person.
- Global attributes (colour, size): `leitvia/update-product-attribute` renames values and sets the `order`; a new attribute name or `order_by` goes into a package.
- Reviews: `leitvia/list-product-reviews` (`unrated: true` for those without stars), then `leitvia/update-product-review` adds a missing `rating` from a source, never a guess. Other ratings, `verified` and status are for the person.

### 6. Merchandising

`leitvia/set-merchandising` sets `featured`, `upsells`, `cross_sells` (other products of the shop, up to 20) and `menu_order` of one product. The lists replace the current ones: start from `upsells` and `cross_sells` of `leitvia/get-product`. Upsells are a better or bigger version of the product, cross-sells what goes with it in the cart. The product page shows the first eight upsells in list order. Pick them from the catalog and say why; which products to feature comes from the person.

### 7. New products

`leitvia/create-product` creates a simple product with empty `base_hash`: texts, `sku`, `stock`, `categories` and an `image` with alt text. Prices, SKUs and stock come from the person; a product with a price waits for a person. Variable products, new attributes or values and stock changes of existing products are not in the abilities: the person makes them in WooCommerce.

### 8. The package

After the last write: `leitvia/list-packages` with `state: open`, then `leitvia/get-package` with `id`, and check each diff. Do not call `leitvia/apply-package`: the area `products` and the kinds `prices`, `tax` and `shipping` are not `autonomous` by default, and the person reviews the package at `/wp-admin/admin.php?page=leitvia-studio-agents&tab=inbox&package=<id>`. Checkpoint **Package applied**.

## Verification

After the person applied the package, read each product again with `leitvia/get-product` and request its `link`: it answers 200 and shows the new texts.

## Failure modes / debugging

Every code with its retry rule: [rejections](../../handbook/rejections.md).

- `leitvia/list-products` is unknown: WooCommerce is not active; stop and tell the person.
- `leitvia_check_failed` with `next_ability` `leitvia/set-alt-text`: a product image has no alt text; set it first.
- `leitvia_out_of_scope`: orders, refunds, coupons and customers are out of reach (`retry: never`).
- `leitvia_media_missing`: the image waits in a package; set it on the product in a second package.

## Report

Write the report in the chat, in the language of the person:

1. **Package:** ID, state and the inbox link; which operations wait as suggestions (prices, tax, shipping).
2. **Changed:** per product what changed, with the source of each fact and price.
3. **Open with reason:** products without image alt text, facts without a source, stock or variable products for the person.
4. **Questions for the person:** prices, facts, products to feature.

## Escalation

Stop and ask the person at these checkpoints, then continue with the answer:

- **Plan confirmed:** the products and changes of step 2, before the first write.
- **Facts from the person:** prices, product facts or claims without a source, and every computed sale price.
- **Package applied:** the person reviews and applies the package in the inbox before you check the shop or set images of a second package.
- **Second rejection** of the same call, or a rejection with `retry: never`.

## Unattended

When no person answers (a scheduled or hosted run), do not wait at a checkpoint. Send the `package` key on every write, even when the briefing says nobody visits the site (mode `direct` needs a person who is present); never apply, roll back or publish anything. Take facts only from a source you name in the report (briefing, a page of the site, a read ability); without one the fact stays out and becomes an open question. A second rejection, or `retry: never`, stops that part. End with the package ID, what it holds and the open questions. Each checkpoint gets this answer:

| Checkpoint | Answer without a person |
|---|---|
| Plan confirmed | Only the changes the briefing names; the plan goes into the report. |
| Facts from the person | Only prices and facts the briefing or a page of the site states, with that source in the report; a computed sale price or a fact without a source stays out and is listed as a question. |
| Package applied | Stop after step 8; the check of the shop and images of a second package belong to the next run. |
| Second rejection | Stop that product; report code and message. |
