=== Leitvia Studio ===
Contributors: contexlabs
Tags: ai, mcp, block theme, accessibility, design tokens
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.3.38
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A gateway for AI agents, a pattern lab, design tokens and site recipes for block themes, stored in WordPress core structures.

== Description ==

Leitvia Studio lets people and AI agents build and maintain sites with block themes without breaking design, accessibility or content. Everything it creates is stored in WordPress core structures, so the site keeps working when the plugin is deactivated. It works with any block theme and fits best with the theme Leitvia.

**Gateway for AI agents.** Agents work through Leitvia abilities of the WordPress Abilities API: read the design contract, tokens, patterns and page outlines, then build pages from patterns, change single blocks, fix links and alt text or set up navigation. Every write runs through one pipeline with identity, scope, a hash check against stale reads, content checks (headings, alt text, link text, contrast, landmarks), a snapshot and an audit entry. An autonomy level per area decides whether a change goes live, lands as a draft or waits as a change package. People review packages in the inbox with a block diff and apply, reject or roll them back.

**Agents are tools you choose.** An agent is a program you connect with the application password of a "Leitvia Agent" user, created and revoked on the Agents screen. It never installs or changes plugins, themes, users or code, and settings, content model and global styles always wait for a person.

**MCP, REST and WP-CLI.** With the plugin MCP Adapter active, Studio adds its own MCP server "leitvia" with exactly the Leitvia abilities as tools. Without it, every ability is available over the REST API. `wp leitvia` runs the same abilities from the command line: token sets, patterns, content model, site audit, change packages and recipes.

**Site recipes.** One command or one agent call sets up a site from a bundle of style variation, header, footer, pages from patterns, navigation and settings that send less data: Blog, Company, Publication, Documentation and, with WooCommerce, Shop. A second run adds nothing, a summary first shows what changes, and the Inbox keeps a rollback.

**Building blocks.** One content feature of an add-on, such as events or a team: content types, the sections that show them and sample drafts. Adding one leaves design and pages alone. A section whose building block is missing says so in the editor and sets it up in one click; visitors never see it empty. Developers use the registry building_blocks, agents leitvia/add-building-block.

**Pattern Lab, tokens and content model.** The Pattern Lab under Leitvia Studio shows all theme and own patterns with live previews in every style variation at 390, 782 and 1440 px, state variants (long text, empty, error, disabled) and an axe check per preview. Token sets are imported, exported, checked for contrast and scheduled as overlays. The content model declares post types and fields that blocks can show through block bindings.

Posts, archives and author pages get structured data for search engines (JSON-LD): NewsArticle or BlogPosting, CollectionPage and ProfilePage, with three image sizes of 1200 px made on upload. A news sitemap for Google News lists the last 48 hours at /news.xml. While an SEO plugin such as Yoast SEO or Rank Math is active, Leitvia Studio prints none, so that plugin stays the only source.

Embedded videos, posts and other content from sites such as YouTube, Vimeo or X load nothing from the provider until the visitor agrees. A placeholder names the provider, links to its privacy policy and to the content and offers to load it; the Consent Settings block, best placed on your privacy policy page, lists and revokes what a visitor always allows.

Pages in easy language (Leichte Sprache), which public bodies offer under BITV 2.0, are normal pages: the page sidebar marks one as the easy language version of another, and the block "Easy language switch" links both. The block "Accessibility bar" for the header collects the links to easy language, the accessibility statement and sign language and, with JavaScript, adds text size, high contrast and read aloud. It is an extra, not a fix: pages must be accessible without it.

Profile pictures come from your own media library; everyone without one, people who comment included, gets a neutral placeholder that ships with the plugin. Gravatar stays off unless an administrator switches it on.

For WooCommerce shops Leitvia Studio adds the block "Product comparison", gateway abilities for products and categories and the site recipe "Shop", only while WooCommerce is active; it is never required (see the questions below).

**Leitvia Pro.** Everything above is free and stays free. The paid add-on Leitvia Pro adds the blocks Slider, Scroll story, Before and after and Entry list, and eight industry kits (SaaS, law firm, trades, medical practice, restaurant, public authority, publisher, association) with their own brand, pages and patterns. Plans and prices: https://leitvia.com/pricing/

== Accessibility ==

Leitvia Studio is built to meet WCAG 2.2 level AA and BITV 2.0 in its admin screens and in the blocks it adds to the front end:

* The admin screens Pattern Lab, Tokens, Content Model, Recipes, Agents with its Inbox tab and Settings work with the keyboard, keep a visible focus, label every field and announce results in a status message. Wide tables scroll in their own box at 320 px instead of the page.
* The inbox shows each change of an agent as a block diff in plain text with the text before and after, never by color alone. Revoking an agent and the profile "Fully autonomous" ask for a confirmation.
* Blocks keep their content readable without JavaScript: tabs, table of contents, mega menu links, pricing period switch, comparison tables and the form error summary. Moving blocks (marquee, counter, reveal) stand still when the operating system asks for reduced motion, and the marquee has a Pause button.
* Before an agent change goes live, the gateway checks heading structure, alt text, link text, color pairs of the active palette and the landmarks of the rendered page, and refuses changes that bring new findings.

Automated tests run axe in three widths and keyboard tests on the admin screens, and axe on every block. They do not replace a test by people, and the accessibility of a site also depends on its theme, content and other plugins. Report accessibility problems at https://leitvia.com/accessibility/.

== Installation ==

1. Upload the `leitvia-studio` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate the plugin on the Plugins screen.

== Frequently Asked Questions ==

= How do AI agents use Leitvia? =

An agent such as Claude Code, Codex or Cursor connects to your site over MCP or the REST API with the application password of an agent user from Leitvia Studio, Agents. It reads the design contract and the outline of a page first, then calls the Leitvia abilities, and every change runs through the gateway checks and your autonomy levels. No service of ours sits in between: the agent signs in as a user of your site that an administrator created, with the role Leitvia Agent and without administrator rights. It works on content, media, navigation, template parts and token set drafts. It cannot install, activate, update or remove plugins or themes, manage users, log in to wp-admin or edit code. The role itself stores no capability: Studio gives an agent the rights for content, media and theme options only while it runs. Deactivate Studio or remove its folder and the agent has the rights of a user without a role: it can sign in and change its own profile, as any WordPress user can, and nothing on the site. Its application password stays and works again when you activate Studio. Deleting Studio in the admin removes the application passwords of agents and the role; the users and their content stay. Application passwords come into being only when an administrator creates an agent or a new password on the Agents screen, and the password is shown there once. Changes to site settings (such as the front page or the comment defaults), the content model, the active token set or the global styles only ever reach the inbox as a suggestion that a person applies or rejects, whatever the autonomy profile. Ready-made agent skills for setup, design, pages, patterns, maintenance, accessibility and migration, with the full reference of all abilities, are described at https://leitvia.com/docs/.

= Does the plugin change my front end? =

Only where you use its features. It loads scripts and styles for visitors only on pages with its blocks or an embed placeholder. It does change front end output in eight places: structured data in the head of posts, archives and author pages with the news sitemap and its line in robots.txt, while no SEO plugin is active the canonical link of archives and a stored SEO title, description and robots, profile pictures and the placeholder replace Gravatar images wherever WordPress shows avatars, embeds from other sites wait behind a consent placeholder, a scheduled token overlay changes the styles while it runs, the "Child entries" query lists the rows of a content model entry, code blocks get a "Copy code" button that shows once JavaScript runs, and the emoji script of WordPress is left out (see below). The Lab preview, which logged-in editors open from the admin, loads its own scripts, and axe-core only while an accessibility check runs.

= What happens to blocks connected to content model fields when I deactivate the plugin? =

Paragraphs, headings, buttons and images can show content model fields, site settings and related entries through block bindings. A site setting is only available to blocks when its declaration sets "bindable" to true, because its value is then shown to every visitor. Without Leitvia Studio, WordPress shows the content saved in the block instead. When you connect an empty block, the editor saves the field name as that content, so the block is never left empty.

= How do I mark posts as news articles? =

Every post is a news article until you choose categories under Studio, Settings, Design and search, "Posts that are news"; posts outside them get the type BlogPosting and stay out of the news sitemap. The filter `leitvia_studio_news_categories` receives that list and can return other slugs or IDs. The setting "Add structured data to posts and author pages" switches the structured data off, "Publish a news sitemap for Google News" the sitemap; the sitemap also stays off while the site discourages search engines or another plugin makes a news sitemap (filter `leitvia_studio_news_sitemap_enabled`). The filter `leitvia_studio_seo_plugin_active` tells Leitvia Studio about an SEO plugin it does not detect.

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

An image an agent adds while a change package waits for a decision lies in a folder with a random name below `wp-content/uploads/leitvia-studio/private/` until a person applies the package; rejecting the package deletes it. Apache reads the `.htaccess` file there and denies access. nginx ignores it, so add this rule to the server block of the site (adjust the path if your uploads folder lives elsewhere):

`location ^~ /wp-content/uploads/leitvia-studio/private/ { deny all; }`

Versions before 1.3.10 used `wp-content/uploads/leitvia-private/`. The first page load after the update moves the waiting images to the new folder and removes the old one, and waiting packages keep working; an nginx rule for the old folder can go after that.

= Which HTML may users without unfiltered_html save? =

WordPress filters the posts of users without the capability unfiltered_html (authors, editors on a network, agents) through its list of allowed HTML. The pricing switch, the feature filter and the wizard steps of Leitvia Studio are radio buttons and checkboxes, which that list does not contain, so Leitvia Studio adds the element `input` for post content, with the attributes type, name, value, checked and required only. The type must be radio or checkbox; an input of any other type is removed, so these users cannot build text or password fields, and event handlers, style, formaction and src stay out. Nothing else changes, and users with unfiltered_html are not affected.

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

The Contact form block sends the name, email address and message a visitor enters by email to the address set in the block, or to the administration address of the site, and stores none of it on the site. To limit the messages per connection it keeps a counter for one hour under a salted hash of the IP address; the address itself is not stored. It sets no cookies and contacts no other service. Name the form in your privacy policy.

== External services ==

This plugin does not connect to any external service by default. The check after an agent changed a page live loads that page from your own site (a loopback request, see the question on requests from your server); it contacts no other host.

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

The accessibility check bundles [axe-core](https://github.com/dequelabs/axe-core) 4.13.0 by Deque Systems, Inc. into `build/axe-runner.js`. axe-core is licensed under the Mozilla Public License 2.0 (MPL-2.0); the license text is in `licenses/AXE-CORE.txt` and the notices of the libraries it includes are in `licenses/AXE-CORE-THIRD-PARTY.txt`. MPL-2.0 is compatible with the GPL: its section 3.3 allows combining the covered code with GPL-licensed code, and axe-core does not use the "Incompatible With Secondary Licenses" notice. The unmodified source code is available from the axe-core repository and the npm package `axe-core`. The file is served from your own site and makes no external requests.

The headings of the Studio admin screens use [Open Sans](https://github.com/googlefonts/opensans) by The Open Sans Project Authors, weights 600 and 700, reduced to the Latin characters and stored as WOFF2 in `assets/fonts/open-sans/`. Open Sans is licensed under the SIL Open Font License 1.1; the license text is in `licenses/OPEN-SANS.txt`.

Token sets can name [Libre Franklin](https://github.com/googlefonts/Libre-Franklin) by The Libre Franklin Project Authors (one variable file, weights 100 to 900, in `assets/fonts/libre-franklin/`, license `licenses/LIBRE-FRANKLIN.txt`) and [Young Serif](https://github.com/noirblancrouge/YoungSerif) by The Young Serif Project Authors (regular weight, in `assets/fonts/young-serif/`, license `licenses/YOUNG-SERIF.txt`). Both are reduced to the Latin characters, stored as WOFF2, licensed under the SIL Open Font License 1.1, served from your own site and loaded only on sites whose active token set uses them.

The Map block bundles [Leaflet](https://leafletjs.com/) 1.9.4 by Volodymyr Agafonkin and contributors into `build/blocks/map/leaflet.js`, loaded only after the visitor agrees to load the map, and ships its stylesheet as `build/blocks/map/leaflet.css`. Leaflet is licensed under the BSD 2-Clause License, which is compatible with the GPL; the license text is in `licenses/LEAFLET.txt`.

Icons: 114 icons of [Lucide](https://lucide.dev/) 1.60.0, ISC License (some from Feather, MIT), in `assets/icons/lucide.json`, license in `licenses/LUCIDE.txt`.

The pattern illustration `assets/images/landscape.webp` was drawn for Leitvia by CMS ADMINS and is licensed under the GPL, version 2 or later, like the plugin.

== Source code ==

The `src/` folder contains the source code of the files in `build/`, and `webpack.config.js` and `bin/guard-blocks-manifest.mjs` are the build configuration. To create `build/` yourself, run `npm ci` and then `npm run build` in the plugin folder with Node.js 24 (or 22.22.2 and later). `package-lock.json` holds the exact versions of all build tools and libraries, so the result matches the files in `build/` of this release.

== Screenshots ==

1. Inbox: change packages of agents with status, operations, check result and the check after writing.
2. A change package of an agent with the block diff per operation, a checkbox per operation and the buttons to apply or reject it.
3. Agents: agent users with their application passwords, the MCP Adapter state and the autonomy per area.

== Support ==

Leitvia Studio is developed and maintained by CMS ADMINS, https://cms-admins.de. Ask questions in the support forum of the plugin on WordPress.org or write to support@cms-admins.de. Report security issues and accessibility barriers to the same address; we answer within five working days.

== Legal ==

Leitvia Studio is a product of CMS ADMINS, a brand of Patrick Schlesinger, Parkstraße 19, 80339 München, Germany. Contact: support@cms-admins.de.

Disclaimer: Leitvia Studio is free software under the GNU GPL, version 2 or later. It is provided as is, without any warranty, to the extent permitted by applicable law; see sections 11 and 12 of the GPL. You are responsible for checking that your site meets the legal requirements that apply to it, such as accessibility, privacy and imprint duties; Leitvia Studio helps with these but does not replace that check.

* Imprint: https://leitvia.com/legal-notice/ (German original: https://leitvia.com/de/impressum/)
* Privacy policy: https://leitvia.com/privacy-policy/

== Changelog ==

= 1.3.38 =
* New abilities leitvia/list-product-reviews and leitvia/update-product-review: read the reviews of a product with rating, verified owner, status and hash (never email address, IP address or user account), and set the rating, the verified flag or the status with hash, snapshot, package and rollback; WooCommerce counts the average rating and the review count again. Rights as on the reviews screen of WooCommerce: editing needs edit_products and edit_comment. Without moderate_comments, which agents do not have, a write only adds a missing rating to an approved review; another rating, the verified flag, a new status and held, spam or trashed reviews are moderation. Trash and spam go through the functions of core, so the admin restores such a review as it was.

= 1.3.37 =
* New abilities leitvia/list-product-attributes and leitvia/update-product-attribute for global WooCommerce attributes (pa_*): name, value names and the order of the values in one operation with hash, snapshot, package and rollback; slugs stay. Value names and order follow the autonomy, the name and the sort order of the attribute itself need manage_product_terms like the attributes screen of WooCommerce, otherwise they go into a package.
* update-product and create-product: descriptions run through the same block processing as update-content, so synced patterns keep ref and content with their overrides; a stored Custom HTML block of a person stays, a new one is rejected. Texts reach core's KSES with their slashes only for the one save, the product object, variation titles and hooks see them unslashed.
* A write that would lose block attributes (also in the short description of a product) fails with leitvia_attributes_lost, names block, position and keys and keeps the old state.
* leitvia/audit-site renders each page with its template and reports links without a target (empty href, # without an anchor) outside the content as leitvia/empty-link with the class of their source; header and footer once under site. Rendering restores the global post, query and product, fires loop_end, fetches no oEmbed and stops a page of results after 10 seconds with the cursor for the rest. link-text reports an empty href, repair-links lists such links under empty.

= 1.3.36 =
* In a post split into parts (core/nextpage) a write gives a link to #anchor whose anchor lies in another part the path of that part plus the anchor (Table_Of_Contents::anchor_url(), without the host), once the post is published with pretty permalinks; drafts and plain permalinks keep the link. Only blocks the write changes are touched.
* New content check leitvia/anchors: warning leitvia_anchor_other_part for a link to an anchor of another part (the fix names the path) and leitvia_anchor_missing for an anchor in no part. leitvia/audit-site reports both.
* Table_Of_Contents::anchor_part() and entries() also take a post with content not yet saved.

= 1.3.35 =
* Table of contents in a post split into parts (core/nextpage): every heading knows its part. With scope all (default) the list groups the headings under Part 1, Part 2 and so on, marks the part being read with aria-current and links headings of other parts to the address of their part plus the anchor; scope part lists the part being read alone. One table of contents in part 1 shows at the top of every part. Generated heading ids stay unique across all parts.
* Table_Of_Contents::anchor_url( $post, $anchor ) gives add-ons the address of an anchor in a post with parts.
* Token sets with a group this version does not read (a role of a later version, a typo) come back from creating and activating with the warning leitvia_studio_dtcg_unknown_group, naming the group and the version of Studio, instead of dropping the group without a word.

= 1.3.34 =
* Token sets may set the text width and the wide width with the optional roles size.content (30 to 80 rem) and size.wide (60 to 100 rem, not narrower than size.content); without them the widths of the theme stay.
* New warnings when writing content: leitvia_image_fixed_height for an image with a height but no aspect ratio, which stretches it, and leitvia_unknown_shortcode for shortcodes no plugin handles, such as rests of page builders. leitvia/get-page-outline lists such shortcodes with their block, leitvia/audit-site reports both.
* leitvia/update-block changes align and the block style of image, gallery, cover and media and text blocks, and width, height, aspectRatio, scale, size, link and lightbox of an image; null removes one, so a fixed height goes without rewriting the page.
* Only blocks a write changes are checked for unsafe markup: a stored block of a plugin that is gone no longer holds back every write of its post and stays byte for byte, and the rejection names the block that is unsafe. A new block inside a stored one is checked; a copy of a stored block in the same post passes on purpose, since it brings no markup the post did not hold.
* Writes repair markup agents often get wrong, visible in the package: a vertical alignment of columns reaches the columns without one of their own, and an add-on block whose catalog example wraps its inner blocks in a box (the scroll gallery) gets that box. Only blocks the write changes are repaired, stored blocks keep their markup byte for byte.
* leitvia/update-template-part keeps the settings of the navigation (submenus on click, overlay, name) when new content leaves them out.
* Map: the popup is at least 18rem wide (80 % of a small screen) and at most 24rem, without hyphens in the middle of a word; title in bold, address below it, the link on a line of its own.

= 1.3.33 =
* A rejection of unsafe content names the place: block ID, tag, attribute and a short excerpt, in the message and in the data. Harmless rests of word processors (mso-* styles, empty o:p) are removed from a text the write changes instead of rejecting it; a write of title, meta or status alone leaves the stored content as it is.
* leitvia/get-block returns the markup of Custom HTML blocks and rejects an empty Custom HTML or classic block with leitvia_empty_block instead of an empty result.
* Map: blocks that render the map on the server pass a link per marker for the popup, named with the place, and a view that fits all markers with an upper zoom limit (fit). A popup takes the keyboard focus, on its link or else on its text, and Escape gives it back to the marker. Markers closer than 24 px move apart, and the attribution stands below the map instead of in its corner (WCAG 2.5.8).
* leitvia/update-template takes template slugs with an underscore, such as taxonomy-product_cat and taxonomy-product_tag of WooCommerce, so product categories get a template apart from the shop page.
* The changelog of versions 1.0.0 to 1.3.19 is in changelog.txt.

= 1.3.32 =
* Table of contents with display companion: a chapter button at the bottom edge with the section being read and the reading progress opens a dialog with the list.
* Timeline entries take a free label instead of a date.
* leitvia/list-media filters by IDs (include) and finds files by name; leitvia/set-alt-text takes up to 100 items in one call and one package.
* New checks: blocks without a registered type (warning in content), templates without header, footer or main, accent below 4.5:1 on extra surfaces (links there turn ink). The block check of templates skips blocks that take the post from their context.
* leitvia/get-pattern returns template patterns; add-on abilities run over REST for agents; the plan of a page no longer ends with the series pager.
* Icons: ship and footprints (114 Lucide icons); wide icons keep their proportion at 1em height.
* Token sets may set radius.media, the corner of photos, to radius.none, s, m or l; any other value is rejected with invalid_choice.

= 1.3.31 =
* New ability leitvia/update-template: sets a template of the theme from a template pattern whose templateTypes name it, with snapshot, autonomy, package and rollback. Templates of single posts and pages keep the post content.
* leitvia/update-content takes the field template and gives an existing page or post another template of the contract. The template counts towards the hash; hashes from before still match while the template stays.
* New ability leitvia/crop-media: crops an image of the media library to a rectangle in percent and adds the result as a new attachment with the alt text of the original, which stays.
* Token sets may bring up to eight named surfaces under color.surface-extra, for cards and bands; ink needs a contrast of 4.5:1 on each. The export keeps them.
* create-token-set and activate-token-set report the warning font_not_bundled in their findings.
* New filter leitvia_studio_icons: icon sets of the site for the icon blocks, next to the fixed Lucide names; filled icons render without a stroke.
* New filter leitvia_studio_consent_scripts: scripts of other plugins, named by handle or host with the category statistics or marketing, wait for consent like embeds. Until then the browser does not ask the host, and the block Consent settings offers each category to allow and revoke.
* New filter leitvia_studio_gateway_options: add-ons write options of their own through the pipeline, checked on each write and when a package is applied.

= 1.3.30 =
* The shortest time between loading a page and sending the contact form (Contact_Form::MIN_SECONDS) is public, so extensions with a form of their own that use the same signed time field check the same time.

= 1.3.29 =
* leitvia/add-media takes WebVTT files with captions: right to upload files, .vtt, UTF-8 and the signature WEBVTT. The attribute tracks of leitvia/update-block sets them on a video with kind, srclang and label, and the video prints a track element (WCAG 1.2.2).
* leitvia/update-block and leitvia/update-content set the caption of images, videos, audio, galleries, tables and embeds, and with add_link a new link on a passage of a text.
* leitvia/update-content takes status: it publishes a draft, schedules it or takes a post back, by the same package rules as any change.
* core/query and core/video are in the block catalog. query in leitvia/update-block merges into the settings of a query, so perPage alone keeps the rest.
* leitvia/update-block changes prefix, value, suffix and label of a counter and the text attributes of other static blocks. Saved counters, wizards and consent settings no longer lose their markup for users without unfiltered_html, and every catalog example passes the gateway.
* New ability leitvia/reject-package discards an open change package; the package and the audit log stay.
* New ability leitvia/set-privacy-page proposes the privacy policy page for a person to apply. leitvia/audit-site reports a contact form on a site without a published one (check leitvia/privacy-page).

= 1.3.28 =
* Without an SEO plugin Leitvia Studio takes its place: leitvia/update-seo and the seo field of leitvia/create-content store title, description and robots and print them in the head, without changing the heading of the page.
* Category, tag, author, posts and post type archives get a canonical address with their page number while no SEO plugin is active.
* The structured data of the organization carries address, phone and email from the new fields of the settings or the new ability leitvia/set-organization. With an SEO plugin Studio prints none of it.
* German variants such as de_DE_formal, de_AT and de_CH use the German translation of the zip.
* German paragraphs in bento tiles hyphenate. Consent placeholder, Consent settings and the saving line of pricing plans use neither du nor Sie in German.

= 1.3.27 =
* German on every site: the plugin header names the languages folder. Since WordPress 6.7 core ignored the former path, so PHP texts of blocks and the plugin stayed English on sites without a language pack, such as Name, Email address and the result count of the filter.
* New pattern Counted key figures with the block Counter. Blocks that keep their content in HTML name their saved markup as example in the block catalog, and the check block-validity rejects them when they are empty or carry such attributes in the comment.
* A query loop on a type with singlePages: false lists its entries instead of falling back to posts, with filters and in the post type control of the editor. Relations may point to such entries.
* Contact form: new attributes nameLabel, emailLabel, messageLabel and requiredNote. Empty texts follow the language of the site.
* The default texts saved by the blocks Map, Wizard and Consent settings follow the language of the site, own texts stay.
* Rate limit: reads and changes count apart. New setting read_rate_per_minute (300) next to rate_per_minute (60) and new filter leitvia_studio_rate_limit, so reading during a build no longer blocks changes.

= 1.3.26 =
* New block Contact form: name, email address and message, sent on the server with wp_mail, without JavaScript and without another plugin. Recipient and subject stay in the block attributes and never reach the page. It stores nothing; spam protection uses a honeypot, a signed time field and a rate limit per salted IP hash. New pattern cta-contact-form.
* New ability leitvia/set-posts-page makes an existing page the posts page. Like every setting it goes through a change package; the front page and unknown pages are rejected.
* Author as input: leitvia/create-content and leitvia/update-content take author, leitvia/list-content shows it. No login name reaches the public any more: an author whose display name is the login, or an agent, appears with first and last name, otherwise with the site name, in the byline, author box, feed, schema and REST, and has no author page. The inbox and the review keep showing the stored display name.
* Update note: post_author now counts into the document hash of a post. Change packages that are open during the update report a conflict; review them or create them again.
* Update note: content types and their taxonomies no longer inherit the front of the permalink structure. Addresses like /blog/<type>/... lose the prefix once after the update, the rewrite rules are built again. The content model option withFront keeps the old address.
* New content model option singlePages: false gives the entries of a public type no address of their own, lists and bindings keep showing them.
* leitvia/compose-page removes and repeats blocks of a pattern with the edits remove and repeat, so a section fits fewer or more entries without own markup. Locks, the last child, the outer block and duplicate HTML IDs are rejected.
* leitvia/apply-recipe takes slugs per recipe page. Parents, navigation, footer, reading settings and the links of the edits follow.
* leitvia/create-content and leitvia/update-content take the names of new terms in hierarchical taxonomies too. In a change package the name stays visible, the term is created when it is applied. Agents get manage_categories for this.
* Token sets use the fonts of the Font Library of WordPress, whose files lie on the site. The warning font_not_bundled names the way.
* A textarea field binds to core/list, one item per line; an empty field shows no list.
* Query filter works without a page reload and without an Apply button; without JavaScript it stays a form.
* New check leitvia/query-context: a filter outside a query, in a query that inherits or without queryId is an error, a duplicate queryId a warning. Query filter and Load more report a missing query, the editor warns on an inheriting query. Query filter has the attributes applyLabel and resetLabel.
* The zip brings its translations: .mo and script JSON in languages/, loaded through the header Domain Path and load_script_translation_file. A language pack in WP_LANG_DIR wins.
* leitvia/list-content counts what it shows: the draft of the privacy page is listed for agents, drafts they may not read are missing from items, total and pages alike. Writes return slug_changed when WordPress gives another address, at the latest on publishing.
* leitvia/check-composition no longer counts the introduction before the first pattern as a section on templates that show the title themselves.
* The check content-tokens names a px value in rem and points to leitvia/get-tokens.

= 1.3.25 =
* Security: the stored role Leitvia Agent holds no capability any more. Studio gives an agent its rights only while it runs, so with a deactivated plugin or a removed plugin folder the agent has the rights of a user without a role: it can change its own profile and nothing on the site, also over the REST routes of core. A role from an older version is emptied on the next load.
* Deleting Studio in the admin removes the application passwords of agents. On a network it removes those bound to a site of the network.

= 1.3.24 =
* Script modules of Leitvia load at the end of the page at low priority, behind everything the first paint needs.
* The styles of the Highlight format and of the copy button of code blocks can be printed inside the document.

= 1.3.23 =
* Effect library for Animation: besides fade, rise and one after another now zoom, slide from the start or end (follows the writing direction), wipe up, wipe from the start, blur, mask (an image is revealed from the inside) and parallax (the background of a cover or the line of a timeline moves a little with the scroll position, only where the browser supports scroll-driven animation). Pure CSS on opacity, transform, clip-path and filter, so nothing shifts the layout; the script stays one small module.
* Strength per block (leitviaMotion): subtle moves 2.5rem in 800 ms, bold twice the way in 1200 ms, for lively brands, images and galleries.
* New setting Motion in Studio, Settings: off, subtle (default) or bold. Off loads neither style nor script of the effects and marks the page with leitvia-motion-off, so the theme leaves out its hover motion too.
* The accessibility bar has a switch Motion off (WCAG 2.2.2, 2.3.3): it stops the effects on scroll, the marquee, the counter and the hover motion of the theme at once, and is kept for the next page before the first paint. Marquee and counter also stay still with the site setting off.
* The Animation panel in the editor offers every effect and the strength, with a Preview button.
* Agents can set leitviaAnimation and leitviaMotion with leitvia/update-block on every block, static core blocks included; the saved markup stays as it is.
* Seven Studio patterns use the new effects (Bento features, FAQ, Icon benefits, Spotlight, Spotlight scene, Timeline, Type entries).

= 1.3.22 =
* Animation and Reveal start where you look: a block appears once a fifth of it has passed a line 12 % above the bottom edge of the window (a block taller than the window needs a third of the window height), not with its first pixel, so on a phone the effect no longer runs below the edge.
* One after another watches each child on its own: stacked cards on a phone appear one by one as each comes into view; children that come into view together, as in a row on a large screen, start 100 ms apart.
* Softer timing: 800 ms on the curve of GSAP power3.out (CSS linear(), with a close cubic-bezier where linear() is missing), the lift stays 2.5rem.
* A jump to an anchor, by a link in the page or the address, shows the target and every animated block above it at once, as keyboard focus does. The script stays a module of under 0.8 KB gzip.

= 1.3.21 =
* Sticky header: on pages whose header uses a sticky style of the theme, a script module of under 0.4 KB measures the header, so focused elements and jump targets stop exactly below it, and lets a header taller than a quarter of the window scroll away.
* Animation and Reveal: a block now fades in once when it scrolls into view (600 ms; Rise also moves up 2.5rem, One after another starts each child 100 ms later) instead of following the scroll position, so the effect is clearly visible, also in Firefox and Safari.
* Only blocks that start below the visible area wait hidden; what is in view on load, pages without JavaScript and visitors with reduced motion see the content at once. Keyboard focus inside a waiting block shows it right away.
* The script is a module of under 0.4 KB gzip and loads only on pages with an animated block.

= 1.3.20 =
* Icon set grows from 76 to 112 Lucide icons, among them chef hat, heart with pulse, gavel, hard hat, trophy, code, shopping bag, newspaper, ear and languages for the topics of the kits.
* Every icon SVG carries the class leitvia-icon--<name>, so a theme can style a single icon.
* Code copy: a named code block gives its button the name "Copy code: <name>", so many code blocks on one page have distinct button names.
* A group saved as a list (ul or ol) keeps its list role in Safari when a grid or flex layout hides the bullets.

Older versions: changelog.txt in the plugin folder.
