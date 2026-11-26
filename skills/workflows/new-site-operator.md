# Workflow new-site: steps of the operator

Details for the operator of [new-site](new-site.md): what happens on the server with WP-CLI, which the agent never does.

## MCP server

The MCP server `leitvia` (`/wp-json/leitvia-studio/v1/mcp`) comes with Leitvia Studio; there is nothing to install. `leitvia/get-site-health` shows it under `mcp`: the address in `url`, the revisions in `protocols` and, with MCP Adapter active, its version in `version`. When a client cannot connect, the agent works over the REST API at `/wp-json/wp-abilities/v1/abilities/<name>/run` with the same application password, which does everything MCP does.

Optional: for agents that use the default server of the plugin MCP Adapter, install it in the tested version:

```sh
wp plugin install https://downloads.wordpress.org/plugin/mcp-adapter.0.7.2.zip --activate
```

Leave the experiment MCP Access of the AI plugin off on such a site: it installs the newest MCP Adapter from WordPress.org.

## JSON error answers of the REST API

Every rejection of an ability is an answer with a 4xx status and a JSON body (`code`, `message`, `data`). A web server that replaces it with an error page of its own hides every rejection from the agent, which then sees only HTML. nginx does this with `fastcgi_intercept_errors on`, which ISPConfig sets when "Own error pages" is on for the website (`errordocs=1`): turn that option off for the site, or set `fastcgi_intercept_errors off;` for `/wp-json/`. Check it before the first run:

```sh
curl -s https://<site>/wp-json/wp/v2/nonexistent
```

The answer must be JSON with `"code":"rest_no_route"` and status 404 (`curl -s -o /dev/null -w '%{http_code}' <url>` prints the status), not an HTML page.

## Brand fonts

A family that is not bundled comes into the Font Library of WordPress, on the site itself. Leitvia Studio then gives a token set the faces of that family, no font CDN is involved. The agent cannot do this: the routes need `edit_theme_options`. Run it before the token set is activated, with an application password of an administrator, once per family and once per file:

```sh
curl -u '<admin>:<password>' https://<site>/wp-json/wp/v2/font-families \
  -F theme_json_version=3 \
  -F 'font_family_settings={"name":"Brand Sans","slug":"brand-sans","fontFamily":"\"Brand Sans\", sans-serif"}'
# id of the answer:
curl -u '<admin>:<password>' https://<site>/wp-json/wp/v2/font-families/<id>/font-faces \
  -F theme_json_version=3 \
  -F 'font_face_settings={"fontFamily":"Brand Sans","fontWeight":"400","fontStyle":"normal","src":"file-0"}' \
  -F file-0=@brand-sans-400.woff2
```

A variable font is one file with `"fontWeight":"100 900"`. Check the license before you upload.

## Child theme from the start

A site with a design of its own (design file, Figma, brand rules beyond colors and fonts), or one that will differ from the patterns, runs on Leitvia Child from the first day, so site styles have their place and no switch comes later. In the bootstrap of [new-site](new-site.md), in place of `wp theme install leitvia --activate`:

```sh
wp theme install leitvia
wp theme install leitvia-child.zip --activate
```

`leitvia-child.zip` is the zip of the latest release of cmsadmins/leitvia-child; Leitvia stays installed as its parent. Then Leitvia Studio and the rest of the bootstrap as usual. Check it before the first run: `leitvia/get-site-health` reports `theme.slug` `leitvia-child` and `theme.parent` `Leitvia`.

A site that already runs on Leitvia switches once Leitvia Studio is active (Appearance, Themes, or `wp theme activate leitvia-child`). Studio copies global styles with the active token set, edited templates and edited template parts to the child theme when it has none of its own; nothing in the target is overwritten, and the originals stay with Leitvia.

Updates never activate the parent: `wp theme update leitvia`, or for a fixed version `wp theme install leitvia.zip --force` without `--activate`. `--activate` on Leitvia replaces the child theme as the active theme, and templates and parts edited from then on land in the other theme, so the site has them split over two. Leitvia Child is updated the same way, with `--force` and without `--activate` while it is active. After every update `theme.slug` of `leitvia/get-site-health` is still `leitvia-child`.

## Site styles in the child theme

Some findings of an agent are only a matter of look: a line, a hover color, a size between two steps, on a pattern that already fits. The agent writes them as findings of the type site style, with selector, effect and role values ([deviations](../skills/leitvia-page/references/deviations.md)); it writes no CSS. Before a new pattern is built, the operator restyles the existing one for this site in `assets/css/site.css` of Leitvia Child, which loads after the styles of Leitvia, on the front end and in the editor, once it holds a rule. Rules: role custom properties only (`var(--wp--preset--color--accent)`), no hex, no `!important`, nothing from another host, select by block style or block class, never by generated ids, motion only under `prefers-reduced-motion: no-preference`. A rule every site would want is a finding for Leitvia, not a site style. The file survives updates of Leitvia; keep a copy of it with the site (in its repository or backup), since an update of Leitvia Child with `--force` replaces it.

## A second language (Polylang)

No ability sets the language of a post or links translations. With Polylang, which the theme supports, a second language comes about in three parts:

1. The agent builds the pages of the second language like any other page, with `leitvia/compose-page` and slugs of their own (for example `leistungen` next to `services`), in the same package.
2. After the package was applied, the operator runs a script with WP-CLI that adds the languages, gives every post without a language the default one, sets the second language on the new pages and links each pair (`pll_set_post_language`, `pll_save_post_translations`). The model is `site/leitvia.com/polylang.php` in the Leitvia monorepo (`wp eval-file polylang.php <folder>`), which reads the pairs from a table of slugs.
3. Header and footer: while Polylang shows a language other than its default, the theme looks for the template part `<slug>-<language>` (for example `header-de`, `footer-de`) and shows it in place of `<slug>`; without one the part of the default language stays. The agent writes them with `leitvia/update-template-part` and `part` set to `header-de` (a pattern of the area, or `content`), in the same package, once the language exists in Polylang; the part is created in the area of its base part. Without the agent the operator creates them with WP-CLI, as `site/leitvia.com/import.sh` does (`part_upsert <slug> <title> <area>`). The navigation of the second language is a menu of its own.

## Pages of a legal plugin (Germanized)

WooCommerce Germanized, common in German shops, prints notes with links next to prices, in the cart and at the checkout: shipping costs, payment methods, how reviews are checked. It takes the target of each link from its settings; without a page there the link stays in the page with an empty address, which `leitvia/audit-site` reports as `empty-link` with the class it comes from. No ability changes plugin settings. Once the pages exist (the agent writes them like any page, a person applies the package), assign them in WooCommerce, Settings, Germanized, Legal, or with WP-CLI:

```sh
wp option list --search='woocommerce_*_page_id' --fields=option_name,option_value
wp option update woocommerce_shipping_costs_page_id <page ID>
```

Set every page option the list shows empty or 0 (for example `woocommerce_payment_methods_page_id`, `woocommerce_review_authenticity_page_id`), then run `leitvia/audit-site` again: no `empty-link` may remain on the product pages.

## Scripts of plugins from other providers

Leitvia Studio holds back a script until the visitor allows its category, `statistics` or `marketing`, when the filter `leitvia_studio_consent_scripts` names it by host (subdomains included) or by script handle. The script stays in the page as `type="text/plain"`, so the browser asks the host nothing; once the category is allowed, Studio runs it, its inline scripts and the scripts that depend on it in their order. Visitors allow and revoke in the block `leitvia/consent-settings` on the privacy policy page, a consent manager with the event `leitvia:consent-granted`. Find the hosts in the source of a page (`<script src=`) or the network tab of the browser, then add a small mu-plugin, for example `wp-content/mu-plugins/consent-scripts.php`:

```php
<?php
add_filter( 'leitvia_studio_consent_scripts', fn( array $entries ) => array_merge( $entries, array(
	array( 'host' => 'widgets.example.com', 'category' => 'marketing' ),
	array( 'handle' => 'example-stats', 'category' => 'statistics' ),
) ) );
```

Name every such provider, its purpose and its privacy policy in the privacy policy of the site. Load a page in a private window afterwards: the network tab shows no request to the host until the category is allowed.

Not covered: scripts a plugin echoes itself instead of enqueuing them, script modules, iframes, images and fonts that a plugin loads from another host. A loader that puts the URL together at run time, or a script that calls the host with `fetch`, is caught only by its handle: enter the handle, not the host. Check the network tab; for a plugin that still asks the host, ask its vendor for a consent option, or replace it.
