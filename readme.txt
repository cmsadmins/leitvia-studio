=== Leitvia Studio ===
Contributors: contexlabs
Tags: ai, mcp, block theme, accessibility, design tokens
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A gateway for AI agents, a pattern lab, design tokens and site recipes for block themes, stored in WordPress core structures.

== Description ==

Leitvia Studio lets people and AI agents build and maintain sites with block themes without breaking design, accessibility or content. Everything it creates is stored in WordPress core structures, so the site keeps working when the plugin is deactivated. It works with any block theme and fits best with the theme Leitvia.

**Gateway for AI agents.** Agents work through Leitvia abilities of the WordPress Abilities API: read the design contract, tokens, patterns and page outlines, then build pages from patterns, change single blocks, fix links and alt text or set up navigation. Every write runs through one pipeline with identity, scope, a hash check against stale reads, content checks (headings, alt text, link text, contrast, landmarks), a snapshot and an audit entry. An autonomy level per area decides whether a change goes live, lands as a draft or waits as a change package. People review packages in the inbox with a block diff and apply, reject or roll them back. Agents sign in with an application password of the role "Leitvia Agent" and never reach plugins, users or settings.

**MCP, REST and WP-CLI.** With the plugin MCP Adapter active, Studio adds its own MCP server "leitvia" with exactly the Leitvia abilities as tools. Without it, every ability is available over the REST API. `wp leitvia` runs the same abilities from the command line: token sets, patterns, content model, site audit, change packages and recipes.

**Site recipes.** One command or one agent call sets up a site from a bundle of style variation, header, footer, pages from patterns, navigation and settings that send less data: Blog, Company, Publication, Documentation and, with WooCommerce, Shop. A second run adds nothing.

**Pattern Lab, tokens and content model.** The Pattern Lab under Leitvia Studio shows all theme and own patterns with live previews in every style variation at 390, 782 and 1440 px, state variants (long text, empty, error, disabled) and an axe check per preview. Token sets are imported, exported, checked for contrast and scheduled as overlays. The content model declares post types and fields that blocks can show through block bindings.

Posts and author pages get structured data for search engines (JSON-LD): a BlogPosting, or a NewsArticle for categories you mark as news, with headline, dates, authors, publisher and logo, and a ProfilePage on author pages. Three image sizes at 1200 px width (16:9, 4:3 and 1:1) are made on upload for it. While an SEO plugin such as Yoast SEO, Rank Math, SEOPress, All in One SEO or The SEO Framework is active, Leitvia Studio prints no structured data, so that plugin stays the only source.

Embedded videos, posts and other content from sites such as YouTube, Vimeo or X load nothing from the provider until the visitor agrees. A placeholder names the provider, links to its privacy policy and to the content, and offers a button to load it; visitors can always allow a provider and revoke that with the Consent Settings block, best placed on your privacy policy page.

Pages in easy language (Leichte Sprache), which public bodies offer under BITV 2.0, are normal pages: in the page sidebar, "Easy language" marks a page as the easy language version of another page, and the block "Easy language switch" links the two in both directions once both are published. The block "Accessibility bar" for the header collects the links to easy language, the accessibility statement and sign language; with JavaScript it adds text size (100 to 200 %), high contrast and read aloud. The bar is an extra, not a fix: pages must be accessible without it.

Profile pictures come from your own media library. Users pick one on their profile screen; everyone without a picture, including people who leave comments, gets a neutral placeholder that ships with the plugin. Gravatar stays off unless an administrator switches it on.

For shops built with WooCommerce, Leitvia Studio adds the block "Product comparison", gateway abilities for products and categories, and the site recipe "Shop". All three are available only while WooCommerce is active; WooCommerce is never required (see the questions below).

== Accessibility ==

Leitvia Studio is built to meet WCAG 2.2 level AA and BITV 2.0 in its admin screens and in the blocks it adds to the front end:

* The admin screens Lab, Tokens, Content Model, Inbox, Agents and Settings work with the keyboard, keep a visible focus, label every field and announce results in a status message. Wide tables scroll in their own box at 320 px instead of the page.
* The inbox shows each change of an agent as a block diff in plain text with the text before and after, never by color alone. Revoking an agent and the profile "Fully autonomous" ask for a confirmation.
* Blocks keep their content readable without JavaScript: tabs, table of contents, mega menu links, pricing period switch, comparison tables and the form error summary. Moving blocks (marquee, counter, reveal) stand still when the operating system asks for reduced motion, and the marquee has a Pause button.
* Before an agent change goes live, the gateway checks heading structure, alt text, link text, color pairs of the active palette and the landmarks of the rendered page, and refuses changes that bring new findings.
* The Pattern Lab runs axe in every preview, and the block "Accessibility bar" and pages in easy language help with the extra requirements of BITV 2.0.

Automated tests run axe in three widths and keyboard tests on the admin screens, and axe on every block. They do not replace a test by people, and the accessibility of a site also depends on its theme, content and other plugins. Report accessibility problems at https://leitvia.com/accessibility/.

== Installation ==

1. Upload the `leitvia-studio` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate the plugin on the Plugins screen.

== Frequently Asked Questions ==

= How do AI agents use Leitvia? =

An agent such as Claude Code, Codex or Cursor connects to your site over MCP or the REST API with the application password of an agent user from Leitvia Studio, Agents. It reads the design contract and the outline of a page first, then calls the Leitvia abilities, and every change runs through the gateway checks and your autonomy levels. Ready-made agent skills for setup, design, pages, patterns, maintenance, accessibility and migration, with the full reference of all abilities, are described at https://leitvia.com/docs/.

= Does the plugin change my front end? =

Only where you use its features. It loads scripts and styles for visitors only on pages with its blocks or an embed placeholder. It does change front end output in seven places: structured data in the head of posts and author pages, profile pictures and the placeholder replace Gravatar images wherever WordPress shows avatars, embeds from other sites wait behind a consent placeholder, a scheduled token overlay changes the styles while it runs, the "Child entries" query lists the rows of a content model entry, code blocks get a "Copy code" button that shows once JavaScript runs, and the emoji script of WordPress is left out (see below). The Lab preview, which logged-in editors open from the admin, loads its own scripts, and axe-core only while an accessibility check runs.

= What happens to blocks connected to content model fields when I deactivate the plugin? =

Paragraphs, headings, buttons and images can show content model fields, site settings and related entries through block bindings. A site setting is only available to blocks when its declaration sets "bindable" to true, because its value is then shown to every visitor. Without Leitvia Studio, WordPress shows the content saved in the block instead. When you connect an empty block, the editor saves the field name as that content, so the block is never left empty.

= How do I mark posts as news articles? =

Return the slugs or IDs of your news categories from the filter `leitvia_studio_news_categories`; posts in them get the type NewsArticle instead of BlogPosting. The setting "Add structured data to posts and author pages" switches the output off. The filter `leitvia_studio_seo_plugin_active` tells Leitvia Studio about an SEO plugin it does not detect.

= Which forms does the "Form error summary" block work with? =

With every form inside it: forms of core such as the Search block and the comment form, forms of plugins such as Gravity Forms, and your own HTML form in a Custom HTML block. When a visitor submits a form with errors, the block lists them above the form with links to the fields, describes each error at its field and moves the focus to the list. Errors a plugin reports after sending the form (fields marked with aria-invalid="true", Gravity Forms field errors) show in the list right on load, also without JavaScript. Without JavaScript the browser and the form plugin validate as before. The error page of core for comments is a page of its own and stays as it is.

= Why does the emoji script of WordPress no longer load? =

WordPress prints a small script into every page that checks whether the browser can show emoji. Browsers without emoji fonts then load every emoji as an image from s.w.org, a server of WordPress.org, and feeds and emails link emoji images from there as well. That is a request to another host without the visitor's consent. Leitvia Studio leaves the script, its styles and the emoji images in feeds and emails out. Current browsers show emoji with their own fonts, so nothing changes for most visitors. Under Leitvia Studio, Settings, "Load the emoji script of WordPress" brings it back.

= Does the plugin work without the Leitvia theme? =

Yes. It works with any block theme.

= What does Leitvia Studio add for WooCommerce? =

Three things, registered only while WooCommerce is active:

* The block "Product comparison" shows two to four products in a table with caption, column and row headers: live prices with the tax note, rating, the attributes you choose, availability and an "Add to cart" link that names its product. On small screens each product becomes a card, and the table is readable without JavaScript. Prices come from your own shop, never from another host.
* Gateway abilities let AI agents list and read products, update products, categories and tags, and set featured products, upsells, cross-sells and the sort order. They work in the area "Products", which starts at the level "draft", with snapshot and rollback like every other change. Prices, taxes and shipping reach the shop at most as a suggestion a person has to apply. Orders, refunds, coupons and customer data stay out of reach of agents, and reading a product never returns sales figures.
* The recipe "Shop" sets up a shop with the Leitvia theme: shop header with product search, account and mini cart, a home page from the shop patterns, about and contact pages, and the legal pages imprint, privacy policy, terms, right of withdrawal and shipping and payment with placeholder text you replace. It switches off order attribution, usage tracking, marketplace suggestions and remote logging of WooCommerce. Apply it with `wp leitvia recipe apply leitvia/shop --user=<admin>` or through an agent.

The shop templates, patterns and styles themselves come with the Leitvia theme.

= Does Leitvia Studio use Gravatar? =

No, not unless an administrator switches it on. Users pick their profile picture from the media library on their profile screen. Everyone without a local picture, including people who leave comments, gets a neutral placeholder that ships with the plugin. Under Leitvia Studio, Settings, "Allow Gravatar for users and commenters without a local picture" turns Gravatar on for them; local pictures are always used first.

When you deactivate Leitvia Studio while Gravatar is off and "Show Avatars" under Settings, Discussion is on, it switches "Show Avatars" off, so saved avatar blocks and comment avatars do not start loading Gravatar. Activating Leitvia Studio again switches it back on, but only if Leitvia Studio was the one that switched it off; a setting you changed yourself stays as it is. If the plugin folder is deleted without deactivating the plugin first (for example over FTP), this step cannot run; switch off "Show Avatars" yourself in that case.

= Does Leitvia Studio send requests from my server? =

Only to your own site. After an agent changed something live, a WordPress cron event loads the changed page once, as a visitor would see it (a loopback request to the address of your site, nowhere else). It checks the HTTP status, PHP warnings and errors of themes and plugins, one h1, the header, main and footer landmarks, block markup or JSON showing on the page, and the color pairs of the active palette. If the check fails, a change the agent made at the level "autonomous" is rolled back, unless someone changed the page in the meantime; otherwise the change package in the inbox shows the result. Some hosts and firewalls block loopback requests; the check then counts as "unverifiable", never rolls anything back and shows a note in the inbox. The setting "Verify after writing" of the gateway turns the check off.

= Can I run Leitvia Studio from the command line? =

Yes. With WP-CLI, `wp leitvia tokens|patterns|model|audit|package|recipe` lists, exports and imports token sets, patterns and the content model, runs the site audit, works with change packages and applies recipes. Commands that write need `--user` and go through the same checks as agents; `wp help leitvia` lists them all.

= Can AI agents connect over MCP? =

Yes, when the plugin MCP Adapter (version 0.7.x) is installed and active; Leitvia Studio neither bundles nor requires it. Studio then adds its own MCP server "leitvia" at `/wp-json/leitvia-studio/v1/mcp` (HTTP) that offers exactly the Leitvia abilities as tools, with read only, destructive and idempotent hints. The default server of the adapter lists none of them. Agents sign in with an application password of a user with the role "Leitvia Agent"; the adapter checks the capability and every write still runs through the gateway pipeline. Rejections carry their code and data as JSON in the error text. Over WP-CLI the same server runs as `wp mcp-adapter serve --server=leitvia --user=<agent>`. Without the adapter all abilities stay available over the REST API.

Users with the role "Leitvia Agent" read through any REST route, but they change the site only through the Leitvia abilities and the MCP server; other REST routes refuse their writes and XML-RPC refuses them completely.

= How are images protected that wait in a change package? =

An image an agent adds while a change package waits for a decision lies in a folder with a random name below `wp-content/uploads/leitvia-private/` until a person applies the package; rejecting the package deletes it. Apache reads the `.htaccess` file there and denies access. nginx ignores it, so add this rule to the server block of the site (adjust the path if your uploads folder lives elsewhere):

`location ^~ /wp-content/uploads/leitvia-private/ { deny all; }`

= Does Leitvia Studio work in a multisite network? =

Yes. Every site keeps its own settings, token sets, content model, audit log, snapshots and change packages; Studio installs a new site of the network by itself and removes its tables and data when the site is deleted. Activate Studio for the whole network: only then is Gravatar also blocked in the network admin and on every site.

* Agents: users belong to the network. A new agent needs the capability to create users (for site administrators the network setting "Add New Users"). A site administrator manages only agents that work on no other site; agents on several sites are managed by a network administrator. An application password created on the Agents screen works on its own site only.
* Agents address a site by its address, in a subdirectory network with its path (`https://example.org/club/wp-json/...`). The ability "Get contract" names the address and REST base of the site.
* Site administrators have no `unfiltered_html`: imported patterns are filtered like any post, and CSS in global styles needs `edit_css`. Profile pictures belong to the media library of their site.
* With `DISABLE_WP_CRON` every site needs its own cron run, for example `wp site list --field=url | xargs -I{} wp cron event run --due-now --url={}`.

== Privacy ==

Leitvia Studio sends no visitor data anywhere and sets no cookies. Embeds from other sites (the Embed block and its variations such as YouTube, and iframes from other sites in classic content and in Custom HTML blocks) show a placeholder until the visitor clicks "Load content from <provider>"; only then does the visitor's browser contact the provider. When a visitor ticks "Always allow <provider> on this site", the list of allowed providers is stored in the visitor's browser (localStorage, key `leitvia-consent`), never on the server; the Consent Settings block lists and revokes these entries. Without JavaScript the placeholder only links to the content on the provider's site. The setting "Ask visitors before loading embedded content from other sites" turns the placeholder off; deactivating Leitvia Studio also brings back the embeds as WordPress renders them. When you save a post, WordPress itself asks the provider for the embed code (oEmbed), as without the plugin; that request comes from your server and carries no visitor data.

The list carries the path of the site in its key (`leitvia-consent:/` for a site at the root, `leitvia-consent:/club/` for a site below `/club/`), because sites of a subdirectory network or installations in folders of one domain share the local storage of the browser. Consent given on one of them never applies to another. A list stored by an older version under `leitvia-consent` carries over on a site at the root path only.

The Accessibility bar stores text size and high contrast in the visitor's browser (localStorage, key `leitvia-a11y`), only after the visitor changes one of them. Read aloud uses the speech synthesis of the browser with a voice installed on the device; the button only appears when such a voice exists for the language of the page, and voices from online services are never used.

== External services ==

This plugin does not connect to any external service by default.

AI provider of the site owner: only when an agent or a user calls the ability "Plan page" (leitvia/plan-page) with ai set to true, Leitvia Studio sends one request through the AI client of WordPress to the AI connector an administrator set up under Settings, Connectors (for example OpenAI, Anthropic or Google). Leitvia Studio has no provider of its own and stores no keys. The request holds the goal and the content points given in that call, the page frame and composition rules of the contract, and the names and metadata of the patterns; no posts, no user data and no other content of the database. Without that call, nothing is sent. The terms and privacy policy of the provider the administrator chose apply.

Embedded content: when a visitor clicks "Load content from <provider>" in the placeholder of an embed, or has chosen to always allow that provider, the visitor's browser loads the embedded content from the provider. The provider receives the visitor's IP address, browser details and the page address, and may set cookies. Nothing is loaded before that action. The providers Leitvia Studio names, with their privacy policies (other embed providers are named after their host):

* YouTube (Google): https://policies.google.com/privacy, terms https://www.youtube.com/t/terms
* Vimeo: https://vimeo.com/privacy, terms https://vimeo.com/terms
* X: https://x.com/en/privacy, terms https://x.com/en/tos
* Spotify: https://www.spotify.com/legal/privacy-policy/, terms https://www.spotify.com/legal/end-user-agreement/
* SoundCloud: https://soundcloud.com/pages/privacy, terms https://soundcloud.com/terms-of-use
* TikTok: https://www.tiktok.com/legal/privacy-policy, terms https://www.tiktok.com/legal/terms-of-service

Map tiles: the Map block shows a list of places with links to openstreetmap.org and a placeholder for the interactive map. Only when a visitor clicks "Load content from OpenStreetMap", or has chosen to always allow it, does the visitor's browser load map images (tiles) from the tile server. The tile server receives the visitor's IP address, browser details and the page address. Nothing is loaded before that action, and the block editor shows no map. The default tile server is the one of the OpenStreetMap Foundation; administrators can enter another one under Leitvia Studio, Settings, "Map tile server" (https only), in which case the placeholder names that server. Check the terms of use and the privacy policy of the server you use.

* OpenStreetMap tile usage policy: https://operations.osmfoundation.org/policies/tiles/
* OpenStreetMap Foundation privacy policy: https://osmfoundation.org/wiki/Privacy_Policy

Emoji images: the emoji script of WordPress is off while Leitvia Studio is active. Only if an administrator switches on "Load the emoji script of WordPress" in the Leitvia Studio settings, browsers without their own emoji fonts load emoji images from s.w.org, a server of WordPress.org (Automattic Inc. on its behalf), which receives the visitor's IP address, browser details and the page address; feeds and emails then link these images as well.

* WordPress.org privacy policy: https://wordpress.org/about/privacy/

The other exception is Gravatar, a service of Automattic Inc., and only if an administrator switches on "Allow Gravatar for users and commenters without a local picture" in the Leitvia Studio settings. Then, on every page view that shows a profile picture of someone without a picture from the media library, the visitor's browser requests that picture from Gravatar. Gravatar receives the visitor's IP address and a hash of the email address of the user or commenter.

* Terms of service: https://wordpress.com/tos/
* Privacy policy: https://automattic.com/privacy/

== Third-party libraries ==

The accessibility check bundles [axe-core](https://github.com/dequelabs/axe-core) 4.13.0 by Deque Systems, Inc. into `build/axe-runner.js`. axe-core is licensed under the Mozilla Public License 2.0 (MPL-2.0); the license text is in `licenses/AXE-CORE` and the notices of the libraries it includes are in `licenses/AXE-CORE-THIRD-PARTY`. MPL-2.0 is compatible with the GPL: its section 3.3 allows combining the covered code with GPL-licensed code, and axe-core does not use the "Incompatible With Secondary Licenses" notice. The unmodified source code is available from the axe-core repository and the npm package `axe-core`. The file is served from your own site and makes no external requests.

The headings of the Studio admin screens use [Open Sans](https://github.com/googlefonts/opensans) by The Open Sans Project Authors, weights 600 and 700, reduced to the Latin characters and stored as WOFF2 in `assets/fonts/open-sans/`. Open Sans is licensed under the SIL Open Font License 1.1; the license text is in `licenses/OPEN-SANS`. The fonts are served from your own site.

The Map block bundles [Leaflet](https://leafletjs.com/) 1.9.4 by Volodymyr Agafonkin and contributors into `build/blocks/map/leaflet.js` and `build/blocks/map/leaflet.css`, loaded only after the visitor agrees to load the map. Leaflet is licensed under the BSD 2-Clause License, which is compatible with the GPL; the license text is in `licenses/LEAFLET`. The source code is available from the Leaflet repository and the npm package `leaflet`.

== Source code ==

The `src/` folder contains the source code of the files in `build/`. Run `npm install` and `npm run build` in the plugin folder to create them.

== Screenshots ==

1. Inbox: change packages of agents with status, operations, check result and the check after writing.
2. A change package of an agent with the block diff per operation, a checkbox per operation and the buttons to apply or reject it.
3. Agents: agent users with their application passwords, the MCP Adapter state and the autonomy per area.

== Changelog ==

= 1.1.0 =
* New abilities: create-content creates posts, pages and entries of content model types with blocks or patterns, terms, fields and a featured image; list-content and list-media list what an agent may read; get-site-health reports versions and available updates without a request to other servers.
* update-content also changes the date, terms, fields and the featured image; get-package names the post each applied operation created.
* Text that an agent wrote with AI carries a short note above the content (EU AI Act, Article 50), which a person can remove.
* Recipes keep a header or footer that was changed after the recipe wrote it.
* Filter `leitvia_studio_bundled_fonts`: a plugin can ship fonts for token sets, served from the site.
* Images added by an agent or a kit get the smaller sizes for srcset.
* A changed price or fee field of an entry goes to a person for review.

= 1.0.0 =
* First public release.
* Gateway for AI agents: agent role and Agents screen, Leitvia abilities for reading, building, setup and maintenance, one write pipeline with checks, snapshots, audit log and autonomy levels per area, change packages with inbox, block diff, preview and rollback, and a check of every live change after writing.
* MCP server "leitvia" with the plugin MCP Adapter, the same abilities over REST, and `wp leitvia` for tokens, patterns, content model, audit, change packages and recipes.
* Site recipes Blog, Company, Publication, Documentation and, with WooCommerce, Shop.
* Pattern Lab with previews in every style variation, state variants and an axe check; token sets with contrast check, import, export and overlays; content model with block bindings.
* Blocks for timelines, query filters, maps after consent, wizards, pricing, comparison tables, feature groups, announcement bars, table of contents, mega menus, form error summaries, spotlights, bento grids, marquees, counters, reveal effects, easy language and an accessibility bar; a product comparison for WooCommerce.
* Privacy by default: embeds and maps wait for consent, profile pictures from the media library instead of Gravatar, no emoji images from s.w.org, structured data for posts and authors unless an SEO plugin handles it.
* Works in multisite networks, with settings, data and agents kept per site.
* Token sets win over a style variation in the global styles: activation, overlays and Lab previews replace the presets of the variation for every role, `leitvia/get-tokens` returns the values the front end shows, and drift also reports a role of the active set that the front end does not show.
