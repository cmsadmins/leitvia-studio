=== Leitvia Studio: AI Agents, MCP and Design Tokens for Block Themes ===
Contributors: contexlabs
Tags: ai agent, mcp, abilities api, design tokens, block theme
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.3.45
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI agents such as Claude Code build and maintain block theme sites over MCP or REST. Every change is checked, reviewable and can be rolled back.

== Description ==

Leitvia Studio lets AI agents such as Claude Code, Codex or Cursor build and maintain WordPress sites with block themes through 59 abilities, and every change passes checks for design, accessibility and content before it goes live. It is free and made for developers, agencies and site owners who want an AI agent to do the work without administrator rights.

The abilities belong to the WordPress [Abilities API](https://developer.wordpress.org/apis/abilities-api/) and run over MCP or the REST API. Everything Studio creates is stored in WordPress core structures (patterns, global styles, post types, block bindings), so the site keeps working when you deactivate the plugin. It works with any block theme and fits best with the free theme [Leitvia](https://github.com/cmsadmins/leitvia).

= What it does =

* **AI agent gateway with review and rollback.** Agents read the design contract, tokens, patterns and page outlines, then build pages from patterns, change single blocks, fix links and alt text or set up navigation. Every write runs through one pipeline: identity, scope, a hash check against stale reads, content checks (headings, alt text, link text, contrast, landmarks), a snapshot and an audit entry. An autonomy level per area decides whether a change goes live, lands as a draft or waits in the inbox as a change package with a block diff, which a person applies, rejects or rolls back.
* **MCP server for WordPress.** With the plugin [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) active, Studio adds its own MCP server "leitvia" with one tool per ability; the default server of the adapter offers the same abilities. Without the adapter, every ability runs over the REST API, and `wp leitvia` runs them from WP-CLI.
* **Limited rights for agents.** An agent signs in as a "Leitvia Agent" user with an application password that an administrator creates and revokes on the Agents screen. It never installs or changes plugins, themes, users or code. Site settings, the content model and global styles always wait for a person.
* **Design tokens.** Token sets in the [W3C Design Tokens format (DTCG)](https://www.designtokens.org/) are imported, exported, kept with revisions and compiled into the global styles. A set whose text or control colors miss WCAG 2.2 AA contrast cannot be saved, and overlays change tokens for a scheduled period.
* **Pattern Lab.** All patterns with live previews in every style variation at 390, 782 and 1440 px, state variants, an axe accessibility check per preview, status, version and a usage index.
* **Content model and block bindings.** Post types, taxonomies, fields, relations and site settings declared as JSON, registered through core APIs and shown in paragraphs, headings, buttons and images through block bindings.
* **Site recipes.** One command or one agent call sets up a site from style variation, header, footer, pages from patterns, navigation and settings that send less data: Blog, Company, Publication, Documentation and, with WooCommerce, Shop. A second run adds nothing.
* **Privacy by default.** Embeds from YouTube, Vimeo, X and other sites wait behind a consent placeholder, the Map block loads OpenStreetMap tiles only after consent, Gravatar and the emoji script of WordPress stay off, and fonts are served from your own site.
* **Accessibility blocks.** An accessibility bar, an easy language switch for pages in easy language (Leichte Sprache), a form error summary, a table of contents and tabs that stay readable without JavaScript.
* **Structured data.** NewsArticle or BlogPosting, CollectionPage and ProfilePage as JSON-LD and a news sitemap, switched off while an SEO plugin such as Yoast SEO or Rank Math is active.
* **WooCommerce.** A product comparison block, 10 abilities for products and categories and the recipe Shop, all registered only while WooCommerce is active.

= Leitvia Pro =

Everything above is free and stays free, and no feature is locked. The separate paid add-on Leitvia Pro adds the blocks Slider, Scroll story, Before and after and Entry list, and eight industry kits with their own brand, pages and patterns. See [Leitvia plans and prices](https://leitvia.com/pricing/).

== Works with the official WordPress agent skills ==

Leitvia Studio follows the standards WordPress sets for AI agents and will keep following them:

* [WordPress agent skills](https://github.com/WordPress/agent-skills): an AI agent with only the official skills finds the Leitvia abilities with `GET /wp-json/wp-abilities/v1/abilities?category=leitvia`.
* [Abilities API](https://developer.wordpress.org/apis/abilities-api/): every ability has described input fields, an output schema and annotations (readonly, destructive, idempotent), and runs under `/wp-json/wp-abilities/v1`.
* [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) 0.7: its default server lists the Leitvia abilities through discover-abilities and execute-ability, with the same checks.

The [Leitvia agent skills on GitHub](https://github.com/cmsadmins/leitvia-skills) add eleven skills for setup, design, pages, patterns, maintenance, accessibility, migration, shop catalog, news, SEO and reports, and three workflows. They work side by side with the WordPress skills. The [Leitvia developer guide](https://leitvia.com/docs/developers/) shows the calls with real answers.

Core preset slugs such as base, contrast or spacing 50 map to the roles of the design contract, so content written by AI agents trained on default themes still fits. Theme files of your own go into the child theme [Leitvia Child](https://github.com/cmsadmins/leitvia-child), blocks of your own into a plugin built from [Leitvia Block Starter](https://github.com/cmsadmins/leitvia-block-starter). No agent writes files into the theme.

== Privacy ==

Leitvia Studio sends no visitor data anywhere and sets no cookies. Embeds from other sites (the Embed block and its variations such as YouTube, and iframes from other sites in classic content and in Custom HTML blocks) show a placeholder until the visitor clicks "Load content from <provider>"; only then does the visitor's browser contact the provider. When a visitor ticks "Always allow <provider> on this site", the list of allowed providers is stored in the visitor's browser (localStorage, key `leitvia-consent`), never on the server; the Consent Settings block lists and revokes these entries. Without JavaScript the placeholder only links to the content on the provider's site. The setting "Ask visitors before loading embedded content from other sites" turns the placeholder off; deactivating Leitvia Studio also brings back the embeds as WordPress renders them. When you save a post, WordPress itself asks the provider for the embed code (oEmbed), as without the plugin; that request comes from your server and carries no visitor data.

The list carries the path of the site in its key (`leitvia-consent:/` for a site at the root, `leitvia-consent:/club/` for a site below `/club/`), because sites of a subdirectory network or installations in folders of one domain share the local storage of the browser. Consent given on one of them never applies to another. A list stored by an older version under `leitvia-consent` carries over on a site at the root path only.

The Accessibility bar stores text size and high contrast in the visitor's browser (localStorage, key `leitvia-a11y`), only after the visitor changes one of them. Read aloud uses the speech synthesis of the browser with a voice installed on the device; the button only appears when such a voice exists for the language of the page, and voices from online services are never used.

The Contact form block sends the name, email address and message a visitor enters by email to the address set in the block, or to the administration address of the site, and stores none of it on the site. To limit the messages per connection it keeps a counter for one hour under a salted hash of the IP address; the address itself is not stored. It sets no cookies and contacts no other service. Name the form in your privacy policy.

== External services ==

This plugin does not connect to any external service by default. The check after an AI agent changed a page live loads that page from your own site (a loopback request, see the question on requests from your server); it contacts no other host.

AI provider of the site owner: only when an agent or a user calls the ability "Plan page" (leitvia/plan-page) with ai set to true, Leitvia Studio sends one request through the AI client of WordPress to the AI connector an administrator set up under Settings, Connectors (for example OpenAI, Anthropic or Google). Leitvia Studio has no provider of its own and stores no keys. The request holds the goal and the content points given in that call, the page frame and composition rules of the contract, and the names and metadata of the patterns; no posts, no user data and no other content of the database. Without that call, nothing is sent. The terms and privacy policy of the provider the administrator chose apply.

Embedded content: when a visitor clicks "Load content from <provider>" in the placeholder of an embed, or has chosen to always allow that provider, the visitor's browser loads the embedded content from the provider. The provider receives the visitor's IP address, browser details and the page address, and may set cookies. Nothing is loaded before that action. The providers Leitvia Studio names, with their privacy policies (other embed providers are named after their host):

* YouTube (Google): [privacy policy](https://policies.google.com/privacy), [terms](https://www.youtube.com/t/terms)
* Vimeo: [privacy policy](https://vimeo.com/privacy), [terms](https://vimeo.com/terms)
* X: [privacy policy](https://x.com/en/privacy), [terms](https://x.com/en/tos)
* Spotify: [privacy policy](https://www.spotify.com/legal/privacy-policy/), [terms](https://www.spotify.com/legal/end-user-agreement/)
* SoundCloud: [privacy policy](https://soundcloud.com/pages/privacy), [terms](https://soundcloud.com/terms-of-use)
* TikTok: [privacy policy](https://www.tiktok.com/legal/privacy-policy), [terms](https://www.tiktok.com/legal/terms-of-service)

Map tiles: the Map block shows a list of places with links to openstreetmap.org and a placeholder for the interactive map. Only when a visitor clicks "Load content from OpenStreetMap", or has chosen to always allow it, does the visitor's browser load map images (tiles) from the tile server. The tile server receives the visitor's IP address, browser details and the page address. Nothing is loaded before that action, and the block editor shows no map. The default tile server is the one of the OpenStreetMap Foundation; administrators can enter another one under Leitvia Studio, Settings, "Map tile server" (https only), in which case the placeholder names that server. Check the terms of use and the privacy policy of the server you use.

* [OpenStreetMap tile usage policy](https://operations.osmfoundation.org/policies/tiles/)
* [OpenStreetMap Foundation privacy policy](https://osmfoundation.org/wiki/Privacy_Policy)

Emoji images: the emoji script of WordPress is off while Leitvia Studio is active. Only if an administrator switches on "Load the emoji script of WordPress" in the Leitvia Studio settings, browsers without their own emoji fonts load emoji images from s.w.org, a server of WordPress.org (Automattic Inc. on its behalf), which receives the visitor's IP address, browser details and the page address; feeds and emails then link these images as well.

* [WordPress.org privacy policy](https://wordpress.org/about/privacy/)

The other exception is Gravatar, a service of Automattic Inc., and only if an administrator switches on "Allow Gravatar for users and commenters without a local picture" in the Leitvia Studio settings. Then, on every page view that shows a profile picture of someone without a picture from the media library, the visitor's browser requests that picture from Gravatar. Gravatar receives the visitor's IP address and a hash of the email address of the user or commenter.

* [Gravatar terms of service](https://automattic.com/tos/)
* [Automattic privacy policy](https://automattic.com/privacy/)

== Third-party libraries ==

The accessibility check bundles [axe-core](https://github.com/dequelabs/axe-core) 4.13.0 by Deque Systems, Inc. into `build/axe-runner.js`. axe-core is licensed under the Mozilla Public License 2.0 (MPL-2.0); the license text is in `licenses/AXE-CORE.txt` and the notices of the libraries it includes are in `licenses/AXE-CORE-THIRD-PARTY.txt`. MPL-2.0 is compatible with the GPL: its section 3.3 allows combining the covered code with GPL-licensed code, and axe-core does not use the "Incompatible With Secondary Licenses" notice. The unmodified source code is available from the axe-core repository and the npm package `axe-core`. The file is served from your own site and makes no external requests.

The headings of the Studio admin screens use [Open Sans](https://github.com/googlefonts/opensans) by The Open Sans Project Authors, weights 600 and 700, reduced to the Latin characters and stored as WOFF2 in `assets/fonts/open-sans/`. Open Sans is licensed under the SIL Open Font License 1.1; the license text is in `licenses/OPEN-SANS.txt`.

Token sets can name [Libre Franklin](https://github.com/googlefonts/Libre-Franklin) by The Libre Franklin Project Authors (one variable file each for upright and italic, weights 100 to 900, in `assets/fonts/libre-franklin/`, license `licenses/LIBRE-FRANKLIN.txt`) and [Young Serif](https://github.com/noirblancrouge/YoungSerif) by The Young Serif Project Authors (regular weight without an italic cut, in `assets/fonts/young-serif/`, license `licenses/YOUNG-SERIF.txt`). Both are reduced to the Latin characters, stored as WOFF2, licensed under the SIL Open Font License 1.1, served from your own site and loaded only on sites whose active token set uses them.

The Map block bundles [Leaflet](https://leafletjs.com/) 1.9.4 by Volodymyr Agafonkin and contributors into `build/blocks/map/leaflet.js`, loaded only after the visitor agrees to load the map, and ships its stylesheet as `build/blocks/map/leaflet.css`. Leaflet is licensed under the BSD 2-Clause License, which is compatible with the GPL; the license text is in `licenses/LEAFLET.txt`.

Icons: 116 icons of [Lucide](https://lucide.dev/) 1.60.0, ISC License (some from Feather, MIT), in `assets/icons/lucide.json`, license in `licenses/LUCIDE.txt`.

The pattern illustration `assets/images/landscape.webp` was drawn for Leitvia by CMS ADMINS and is licensed under the GPL, version 2 or later, like the plugin.

== Source code ==

The `src/` folder contains the source code of the files in `build/`, and `webpack.config.js` and `bin/guard-blocks-manifest.mjs` are the build configuration. To create `build/` yourself, run `npm ci` and then `npm run build` in the plugin folder with Node.js 24 (or 22.22.2 and later). `package-lock.json` holds the exact versions of all build tools and libraries, so the result matches the files in `build/` of this release. The same source is in the [Leitvia Studio repository on GitHub](https://github.com/cmsadmins/leitvia-studio).

== Installation ==

1. Install the plugin on the Plugins screen (search for Leitvia Studio), or upload the `leitvia-studio` folder to `/wp-content/plugins/`. With WP-CLI: `wp plugin install leitvia-studio --activate`.
2. Activate the plugin. The menu Leitvia Studio appears with Pattern Lab, Tokens, Content Model, Recipes, Agents and Settings.
3. Optional, to connect an AI agent over MCP: install and activate [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/). Without it, agents use the REST API.
4. Under Leitvia Studio, Agents, create an agent user, copy its application password (it is shown once) and set the autonomy level per area.
5. Point the agent at `https://your-site.example/wp-json/leitvia-studio/v1/mcp` with that password. The [getting started guide](https://leitvia.com/docs/getting-started/) has the configuration for Claude Code, Codex CLI, Gemini CLI and Cursor.

== Frequently Asked Questions ==

= What is Leitvia Studio? =

Leitvia Studio is a free WordPress plugin that lets AI agents build and maintain sites with block themes through 59 abilities of the WordPress Abilities API, with checks, an autonomy level per area, a review inbox and rollback. It also adds a Pattern Lab, design tokens in the DTCG format, a content model with block bindings, site recipes and blocks for privacy, accessibility and offers. It needs WordPress 7.1 and PHP 8.2 or later.

= Does Leitvia Studio work with Claude, ChatGPT or Cursor? =

Leitvia Studio works with any MCP client that connects over HTTP and can send an Authorization header. The AI agent signs in with the application password of a Leitvia Agent user. Studio offers no OAuth sign-in, so a client that connects to MCP servers only through OAuth cannot reach the server "leitvia" directly. Any tool that can call a REST API with Basic auth can use the same abilities under `/wp-json/wp-abilities/v1`.

= How do AI agents use Leitvia? =

An AI agent such as Claude Code, Codex or Cursor connects to your site over MCP or the REST API with the application password of an agent user from Leitvia Studio, Agents. It reads the design contract and the outline of a page first, then calls the Leitvia abilities, and every change runs through the gateway checks and your autonomy levels. No service of ours sits in between: the agent signs in as a user of your site that an administrator created, with the role Leitvia Agent and without administrator rights. It works on content, media, navigation, template parts and token set drafts. It cannot install, activate, update or remove plugins or themes, manage users, log in to wp-admin or edit code. The role itself stores no capability: Studio gives an agent the rights for content, media and theme options only while it runs. Deactivate Studio or remove its folder and the agent has the rights of a user without a role: it can sign in and change its own profile, as any WordPress user can, and nothing on the site. Its application password stays and works again when you activate Studio. Deleting Studio in the admin removes the application passwords of agents and the role; the users and their content stay. Application passwords come into being only when an administrator creates an agent or a new password on the Agents screen, and the password is shown there once. Changes to site settings (such as the front page or the comment defaults), the content model, the active token set or the global styles only ever reach the inbox as a suggestion that a person applies or rejects, whatever the autonomy profile. Ready-made agent skills for setup, design, pages, patterns, maintenance, accessibility and migration, with the full reference of all abilities, are described in the [Leitvia documentation](https://leitvia.com/docs/).

= Does Leitvia Studio need an API key or an account? =

No. Leitvia Studio needs no API key, no account and no service of ours. The AI agent is a program you choose, and it signs in to your site with an application password that an administrator creates. Only the ability "Plan page" with ai set to true uses an AI provider, and only the one an administrator set up under Settings, Connectors (see External services).

= Is Leitvia Studio free? =

Yes. Leitvia Studio is free under the GPL, version 2 or later, with no locked features and no upsell notices. Leitvia Pro is a separate paid plugin with extra blocks and industry kits.

= Is Leitvia Studio GDPR compliant? =

Leitvia Studio sends no visitor data to other hosts and sets no cookies, which makes a GDPR compliant site easier to run; compliance itself depends on your whole site and how you run it. Embeds and map tiles load only after the visitor agrees, Gravatar and the emoji images of WordPress stay off unless an administrator switches them on, and fonts come from your own site. Apart from the optional AI planning call described under External services, its only server side request goes to your own site. Every exception is listed under Privacy and External services.

= Does Leitvia Studio make my site accessible under WCAG 2.2, the EAA or the BFSG? =

No plugin can make a site accessible on its own, and Leitvia Studio does not claim to. Its admin screens and blocks are built to meet WCAG 2.2 level AA and BITV 2.0, and before an AI agent change goes live the gateway checks headings, alt text, link text, contrast and landmarks and refuses changes that bring new findings. Whether your site meets WCAG, the European Accessibility Act or the German BFSG also depends on the theme, the content and other plugins, and needs a test by people.

= How accessible are the admin screens and blocks? =

Leitvia Studio is built to meet WCAG 2.2 level AA and BITV 2.0 in its admin screens and in the blocks it adds to the front end:

* The admin screens Pattern Lab, Tokens, Content Model, Recipes, Agents with its Inbox tab and Settings work with the keyboard, keep a visible focus, label every field and announce results in a status message. Wide tables scroll in their own box at 320 px instead of the page.
* The inbox shows each change of an agent as a block diff in plain text with the text before and after, never by color alone. Revoking an agent and the profile "Fully autonomous" ask for a confirmation.
* Blocks keep their content readable without JavaScript: tabs, table of contents, mega menu links, pricing period switch, comparison tables and the form error summary. Moving blocks (marquee, counter, reveal) stand still when the operating system asks for reduced motion, and the marquee has a Pause button.
* Before an agent change goes live, the gateway checks heading structure, alt text, link text, color pairs of the active palette and the landmarks of the rendered page, and refuses changes that bring new findings.

Automated tests run axe in three widths and keyboard tests on the admin screens, and axe on every block. They do not replace a test by people, and the accessibility of a site also depends on its theme, content and other plugins. Report accessibility problems on the [Leitvia accessibility page](https://leitvia.com/accessibility/).

= What are pages in easy language? =

Pages in easy language (Leichte Sprache), which public bodies in Germany offer under BITV 2.0, are normal pages: the page sidebar marks one as the easy language version of another, and the block "Easy language switch" links both. The block "Accessibility bar" for the header collects the links to easy language, the accessibility statement and sign language and, with JavaScript, adds text size, high contrast and read aloud. It is an extra, not a fix: pages must be accessible without it.

= Does the plugin work without the Leitvia theme? =

Yes. It works with any block theme. The theme Leitvia adds a design contract with fixed roles for colors, spacing and font sizes, 70 patterns and shop templates, which AI agents and the Pattern Lab read.

= What happens when I deactivate Leitvia Studio? =

Your posts, pages, patterns, global styles and images stay, because Studio stores them in WordPress core structures. Blocks connected to content model fields show the content saved in the block, embeds render as WordPress renders them, and AI agent users lose every right on the site until you activate Studio again. If Gravatar was off, Studio switches "Show Avatars" off so that avatars do not start loading from Gravatar; the questions below explain both steps in detail.

= Does the plugin change my front end? =

Only where you use its features. It loads scripts and styles for visitors only on pages with its blocks or an embed placeholder. It does change front end output in eight places: structured data in the head of posts, archives and author pages with the news sitemap and its line in robots.txt, while no SEO plugin is active the canonical link of archives and a stored SEO title, description and robots, profile pictures and the placeholder replace Gravatar images wherever WordPress shows avatars, embeds from other sites wait behind a consent placeholder, a scheduled token overlay changes the styles while it runs, the "Child entries" query lists the rows of a content model entry, code blocks get a "Copy code" button that shows once JavaScript runs, and the emoji script of WordPress is left out (see below). The Lab preview, which logged-in editors open from the admin, loads its own scripts, and axe-core only while an accessibility check runs.

= What happens to blocks connected to content model fields when I deactivate the plugin? =

Paragraphs, headings, buttons and images can show content model fields, site settings and related entries through block bindings. A site setting is only available to blocks when its declaration sets "bindable" to true, because its value is then shown to every visitor. Without Leitvia Studio, WordPress shows the content saved in the block instead. When you connect an empty block, the editor saves the field name as that content, so the block is never left empty.

= What are building blocks? =

A building block is one content feature of an add-on, such as events or a team: content types, the sections that show them and sample drafts. Adding one leaves design and pages alone. A section whose building block is missing says so in the editor and sets it up in one click; visitors never see it empty. Developers use the registry building_blocks, AI agents the ability leitvia/add-building-block.

= How do I mark posts as news articles? =

Every post is a news article until you choose categories under Studio, Settings, Design and search, "Posts that are news"; posts outside them get the type BlogPosting and stay out of the news sitemap. The filter `leitvia_studio_news_categories` receives that list and can return other slugs or IDs. The setting "Add structured data to posts and author pages" switches the structured data off, "Publish a news sitemap for Google News" the sitemap; the sitemap also stays off while the site discourages search engines or another plugin makes a news sitemap (filter `leitvia_studio_news_sitemap_enabled`). The filter `leitvia_studio_seo_plugin_active` tells Leitvia Studio about an SEO plugin it does not detect.

Posts, archives and author pages get structured data for search engines (JSON-LD): NewsArticle or BlogPosting, CollectionPage and ProfilePage, with three image sizes of 1200 px made on upload. The news sitemap for Google News lists the last 48 hours at /news.xml. While an SEO plugin such as Yoast SEO or Rank Math is active, Leitvia Studio prints none, so that plugin stays the only source.

= Which forms does the "Form error summary" block work with? =

With every form inside it: forms of core such as the Search block and the comment form, forms of plugins such as Gravity Forms, and your own HTML form in a Custom HTML block. When a visitor submits a form with errors, the block lists them above the form with links to the fields, describes each error at its field and moves the focus to the list. Errors a plugin reports after sending the form (fields marked with aria-invalid="true", Gravity Forms field errors) show in the list right on load, also without JavaScript. Without JavaScript the browser and the form plugin validate as before. The error page of core for comments is a page of its own and stays as it is.

= Why does the emoji script of WordPress no longer load? =

WordPress prints a small script into every page that checks whether the browser can show emoji. Browsers without emoji fonts then load every emoji as an image from s.w.org, a server of WordPress.org, and feeds and emails link emoji images from there as well. That is a request to another host without the visitor's consent. Leitvia Studio leaves the script, its styles and the emoji images in feeds and emails out. Current browsers show emoji with their own fonts, so nothing changes for most visitors. Under Leitvia Studio, Settings, "Load the emoji script of WordPress" brings it back.

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

Only to your own site. After an AI agent changed something live, a WordPress cron event loads the changed page once, as a visitor would see it (a loopback request to the address of your site, nowhere else). It checks the HTTP status, PHP warnings and errors of themes and plugins, one h1, the header, main and footer landmarks, block markup or JSON showing on the page, and the color pairs of the active palette. If the check fails, a change the agent made at the level "autonomous" is rolled back, unless someone changed the page in the meantime; otherwise the change package in the inbox shows the result. Some hosts and firewalls block loopback requests; the check then counts as "unverifiable", never rolls anything back and shows a note in the inbox. The setting "Verify after writing" of the gateway turns the check off.

= Can I run Leitvia Studio from the command line? =

Yes. With WP-CLI, `wp leitvia tokens|patterns|model|audit|package|recipe` lists, exports and imports token sets, patterns and the content model, runs the site audit, works with change packages and applies recipes. Commands that write need `--user` and go through the same checks as AI agents; `wp help leitvia` lists them all.

= Can AI agents connect over MCP? =

Yes, when the plugin MCP Adapter (version 0.7.x) is installed and active; Leitvia Studio neither bundles nor requires it. Studio then adds its own MCP server "leitvia" at `/wp-json/leitvia-studio/v1/mcp` (HTTP) that offers exactly the Leitvia abilities as tools, with read only, destructive and idempotent hints. The default server of the adapter (`/wp-json/mcp/mcp-adapter-default-server`) offers the same abilities through its tools discover-abilities and execute-ability, for AI agents that only know the WordPress agent skills. Agents sign in with an application password of a user with the role "Leitvia Agent"; the adapter checks the capability and every write still runs through the gateway pipeline. Rejections carry their code and data as JSON in the error text, on both servers. Over WP-CLI the same server runs as `wp mcp-adapter serve --server=leitvia --user=<agent>`. Without the adapter all abilities stay available over the REST API.

Users with the role "Leitvia Agent" read through any REST route, but they change the site only through the Leitvia abilities and the MCP server; other REST routes refuse their writes and XML-RPC refuses them completely.

= How are images protected that wait in a change package? =

An image an AI agent adds while a change package waits for a decision lies in a folder with a random name below `wp-content/uploads/leitvia-studio/private/` until a person applies the package; rejecting the package deletes it. Apache reads the `.htaccess` file there and denies access. nginx ignores it, so add this rule to the server block of the site (adjust the path if your uploads folder lives elsewhere):

`location ^~ /wp-content/uploads/leitvia-studio/private/ { deny all; }`

Versions before 1.3.10 used `wp-content/uploads/leitvia-private/`. The first page load after the update moves the waiting images to the new folder and removes the old one, and waiting packages keep working; an nginx rule for the old folder can go after that.

= Which HTML may users without unfiltered_html save? =

WordPress filters the posts of users without the capability unfiltered_html (authors, editors on a network, AI agents) through its list of allowed HTML. The pricing switch, the feature filter and the wizard steps of Leitvia Studio are radio buttons and checkboxes, which that list does not contain, so Leitvia Studio adds the element `input` for post content, with the attributes type, name, value, checked and required only. The type must be radio or checkbox; an input of any other type is removed, so these users cannot build text or password fields, and event handlers, style, formaction and src stay out. Nothing else changes, and users with unfiltered_html are not affected.

= Does Leitvia Studio work in a multisite network? =

Yes. Every site keeps its own settings, token sets, content model, audit log, snapshots and change packages; Studio installs a new site of the network by itself and removes its tables and data when the site is deleted. Activate Studio for the whole network: only then is Gravatar also blocked in the network admin and on every site.

* AI agents: users belong to the network. A new agent needs the capability to create users (for site administrators the network setting "Add New Users"). A site administrator manages only agents that work on no other site; agents on several sites are managed by a network administrator. An application password created on the Agents screen works on its own site only.
* Agents address a site by its address, in a subdirectory network with its path (`https://example.org/club/wp-json/...`). The ability "Get contract" names the address and REST base of the site.
* Site administrators have no `unfiltered_html`: imported patterns are filtered like any post, and CSS in global styles needs `edit_css`. Profile pictures belong to the media library of their site.
* With `DISABLE_WP_CRON` every site needs its own cron run, for example `wp site list --field=url | xargs -I{} wp cron event run --due-now --url={}`.

= What stays after deleting Studio? =

Deleting the plugin always removes its settings, change packages, audit log, snapshots, the agent role and the application passwords of agents. Your posts, pages, patterns, global styles, images and agent users stay. Token sets, the content model declaration, Lab metadata, profile picture assignments and the marks that agents leave on posts (AI generated, sources, recipe hash) go only when "Delete all Studio data when the plugin is deleted" is on under Leitvia Studio, Settings.

"Show Avatars" under Settings, Discussion stays off if Studio switched it off: without Studio, WordPress loads avatars from Gravatar, which sends the IP address of every visitor to Automattic. Switch it on only if you want that or use another avatar plugin.

== Screenshots ==

1. Agents: connection status, application passwords, checks and the MCP Adapter at a glance, plus the AI agents with access to the site.
2. Pattern Lab: a block pattern in the preview at three viewport widths, with variants, checks and usage.
3. Token set editor: WCAG contrast check for every color pair, scheduling as an overlay and a live preview of theme patterns.
4. Change package of an AI agent: check result and the block diff per operation, ready to apply or reject.
5. Applied change package with Roll back.
6. Checks before and after every write: the list of checks, the results of the last 30 days and verify after writing.

== Support ==

Leitvia Studio is developed and maintained by [CMS ADMINS](https://www.cms-admins.de/). Ask questions in the support forum of the plugin on WordPress.org or write to support@cms-admins.de. Report security issues and accessibility barriers to the same address; we answer within five working days.

== Legal ==

Leitvia Studio is a product of CMS ADMINS, a brand of Patrick Schlesinger, Parkstraße 19, 80339 München, Germany. Contact: support@cms-admins.de.

Disclaimer: Leitvia Studio is free software under the GNU GPL, version 2 or later. It is provided as is, without any warranty, to the extent permitted by applicable law; see sections 11 and 12 of the GPL. You are responsible for checking that your site meets the legal requirements that apply to it, such as accessibility, privacy and imprint duties; Leitvia Studio helps with these but does not replace that check.

* [Imprint](https://leitvia.com/legal-notice/) ([German original](https://leitvia.com/de/impressum/))
* [Privacy policy of leitvia.com](https://leitvia.com/privacy-policy/)

== Changelog ==

= 1.3.45 =
* The plugin is listed as Leitvia Studio: AI Agents, MCP and Design Tokens for Block Themes; the slug stays leitvia-studio, and the screens keep the name Leitvia Studio. The readme answers the common questions on AI agents, MCP clients, privacy and accessibility, and keeps the last five versions, older ones are in changelog.txt.
* Fields, site values, Lab notes and token documents clean their text for its type on every write, not only over REST: tags and scripts are never stored, line breaks stay only in text areas, and a URL, email address, number, date or option keeps only a value of its type.
* Dates bound to the post date block give WordPress a value it can read, so the date shows on sites in every language; an image field gives the caption and title of the media library, never its address.
* core/post-meta shows the fields of the content model like leitvia/meta (option labels, dates in the site format, types without single pages); in the editor such a binding becomes leitvia/meta, so typed text is stored the way the field type needs.
* The check leitvia/bindings refuses bindings that would render nothing (a source that is not registered, an attribute WordPress does not bind, a field that is not declared), and a text edit of a bound attribute is refused with the way to change the value.
* leitvia/get-content-model lists the binding sources, the attributes WordPress binds per block and the values of bindable site settings with their hash. The new ability leitvia/update-site-values sets those values, such as opening hours, in a change package, and create-content and update-content write repeater rows through rows.

= 1.3.44 =
* Every REST route of Studio describes its answer in a schema, and OPTIONS shows it; a test keeps it that way for new routes.
* Child themes of Leitvia: a child theme contract with "extends": "leitvia" adds its patterns to the contract of the parent. Switching between Leitvia and its child theme copies global styles, the active token set and edited templates and template parts to the new theme, without overwriting anything there.

= 1.3.43 =
* The abilities pass the checks of the WordPress agent skills: every required input has a description, the output of every write says what mode, level, hash, package and snapshot mean, and the annotations match what the abilities do (apply-package, apply-recipe, define-content-type, repair-links and set-alt-text are destructive, plan-page with AI reaches an outside service).
* The default server of the MCP Adapter lists the Leitvia abilities as well (discover-abilities, execute-ability), with the same checks, autonomy levels and change packages, and returns a rejection in full with code, message and data.
* get-tokens, get-content-model and get-contract return the hashes the write abilities need; a stale hash is named in the message of the rejection. get-contract also returns the core preset aliases of the theme.
* Content written with core preset names (base, contrast, primary, spacing 50 and the like) is saved with the roles of the theme. The token check rejects a preset the theme does not have (rule no-unknown-preset) and names the roles to use instead.
* REST routes check their input against a schema before they run, and OPTIONS shows it. A rate limit answer carries the header Retry-After.
* The token of the contact form expires after 7 days (filter leitvia_studio_contact_form_max_age). Deleting Studio with its data also removes the meta fields of AI generated posts and their sources.
* Fixes: the consent settings block stays valid when the editor runs in another language than the one it was saved in; list-product-reviews with unrated false over GET no longer filters; icons whose SVG contains a dollar sign render whole; the rating block in the editor formats numbers in the language of the site.

= 1.3.42 =
* Icons train front and bike (116 Lucide icons), for the stops of the route in Leitvia Pro.

= 1.3.41 =
* New ability leitvia/preview-token-set: a signed address that shows any page of the site with a token set, for that request only and as its activation would (colors, fonts, sizes, widths). It opens without signing in, so agents and screenshot tools can use it; it expires after an hour, stops when its user loses edit_theme_options or the set no longer passes the checks, carries noindex and no-cache headers and changes nothing for other visitors. A change package that activates a token set links to this preview.
* Token set checks: the warning font_no_italic names a body or heading font without an italic cut, which the browser would slant. Libre Franklin now ships with its italic cut.
* Lab preview of a token set: the inverse style of group and cover follows the scheme of the set, as on activation.

Older versions, 1.0.0 to 1.3.40: [changelog.txt](https://github.com/cmsadmins/leitvia-studio/blob/main/changelog.txt).
