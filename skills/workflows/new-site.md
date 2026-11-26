# Workflow: new-site

From a briefing to a finished site with the Leitvia theme and Leitvia Studio: setup, brand, extra pages, accessibility check. The workflow chains four skills and stops at fixed checkpoints; each skill keeps its own rules and checkpoints. How to call the abilities, hashes and rejections: [agent handbook](../handbook/README.md).

Chain: [leitvia-setup](../skills/leitvia-setup/SKILL.md), then [leitvia-design](../skills/leitvia-design/SKILL.md), then [leitvia-page](../skills/leitvia-page/SKILL.md), then [leitvia-a11y](../skills/leitvia-a11y/SKILL.md).

## Bootstrap (the operator or the runner, before the agent)

An empty WordPress installation has neither the theme nor Leitvia Studio, and installing them is no ability: the area `admin` stays locked for agents. The operator, or the script that starts an unattended run, prepares the site once with WP-CLI on the server (WordPress 7.1 or later, PHP 8.2 or later, HTTPS):

```sh
wp theme install leitvia --activate
wp plugin install leitvia-studio --activate
wp user create leitvia-agent agent@example.org --role=leitvia_agent --display_name="Leitvia agent"
wp user application-password create leitvia-agent "leitvia agent" --porcelain
```

Theme and Leitvia Studio come from WordPress.org; a fixed version comes from the zips in the releases of cmsadmins/leitvia and cmsadmins/leitvia-studio. The MCP server comes with Leitvia Studio; MCP Adapter is optional (0.7.2 is the tested version, [operator steps](new-site-operator.md#mcp-server)). The last command prints the application password once; store it as `LEITVIA_AUTH` (Base64 of `leitvia-agent:<password>`) for the agent, never in a file you commit. For a shop also `wp plugin install woocommerce --activate` before the run. A site with a design of its own (design file, Figma, brand rules beyond colors and fonts) gets Leitvia Child as its active theme in the same bootstrap, Leitvia only installed, and no update of Leitvia ever carries `--activate`: [child theme from the start](new-site-operator.md#child-theme-from-the-start). The agent itself never runs WP-CLI; its first call is `leitvia/get-contract`, which proves the bootstrap worked. Zips, server check: [operator steps](new-site-operator.md).

Without a server, for a test or demo run on your own machine: [WordPress Playground](../README.md#test-site-with-wordpress-playground) from a blueprint.

## Choose the mode at the start

Ask the person once, before the first write, and keep the answer for the whole workflow.

- **package** (default for a site that is live or has visitors): choose one key for the task, for example `new-site-2026-10`, and send it as `package` on every write of every step. A write with a key always lands in the change package of that key; nothing goes live, whatever the autonomy levels of the site allow. Writes that need an object the package creates (texts on a page of the package, the activation of a new token set) come after the checkpoint **Package applied**; keep the key, which then starts the next package (a new key works as well, one key keeps the report short).
- **direct** (only with a person present, on a site nobody visits yet): send no key, the autonomy levels of the site decide. With the default levels new pages become drafts that only the person publishes. Legal texts, prices, slugs and organization data stay a proposal all the same: send these writes with a key, so they wait in a package for the person.

In both modes Leitvia Studio itself writes nothing outside the abilities, and every rejection is corrected at most once (follow `message`, `next_ability` and `retry`); a second rejection of the same call, or `retry: never`, is a checkpoint: stop and show the person the rejection.

When no person answers, the workflow runs in several unattended runs with the same key. Each checkpoint takes the answer of the section Unattended of its skill, and every write carries the key, even when the briefing says nobody visits the site: mode `direct` needs a person present. A run ends at the next checkpoint "Package applied" with its report. The next run starts with `leitvia/list-packages`: while the package of the key is still `open`, it reports that it waits and writes nothing; once a person applied it, the run goes on with the step after that checkpoint. Nothing goes live without a person applying a package.

## Steps of the person

Some steps belong to a person by design: the area `admin` is locked for agents, and a package waits for a person unless all of it is autonomous. Unattended, the agent lists them in its report and stops at the next checkpoint that needs one.

| Step | Where | Needed for |
|---|---|---|
| Apply the change package | Inbox `/wp-admin/admin.php?page=leitvia-studio-agents&tab=inbox&package=<id>`, or `leitvia/apply-package` called by an administrator | Everything after "Package applied": entries of new types, texts on recipe pages, token set activation, checks of the live site |
| Shop settings (recipe `leitvia/shop`) | WooCommerce, Settings: payment method, store address with state (`DE:DE-SN`), currency, taxes, a shipping zone with a shipping method, store country and currency, visibility "Live" on a live site (on a dev site behind folder protection "Coming soon" stays off, or the shop cannot be tested), vouchers "Virtual" ([recipe details](../skills/leitvia-setup/references/recipe.md#shop-sites)) | The checkout |
| Brand fonts that are not bundled (warning `font_not_bundled`) | Appearance, Editor, Styles, Typography, Fonts, tab Upload (WOFF2 files, license checked; not the tab with the font catalog), before the token set is activated. Operator: [commands](new-site-operator.md#brand-fonts) | The brand font; without it the site shows the system font |
| Admin settings | Settings, General: site title and tagline (the agent proposes both from the briefing); logo (a PNG, [no SVG](../skills/leitvia-design/references/tokens.md#limits)) and site icon, avatars off (Gravatar is a third party), the sample post "Hello world!" and the "Sample Page" deleted. No ability changes them. | A finished site: header, footer and the browser tab show the site title |
| Search visibility | Settings, Reading: untick "Discourage search engines" once the site is live | Being found; without it the report lists it as open, high |
| Leitvia Child as active theme (design of its own) | Operator, [child theme from the start](new-site-operator.md#child-theme-from-the-start); site style findings go into `assets/css/site.css` of the child theme | Every site style; `theme.slug` of `leitvia/get-site-health` is `leitvia-child` |
| Publish drafts (mode `direct`) | Pages, Publish | Pages of a run in mode `direct` |
| Open a content type to other AI tools (`showInAbilities`) | Leitvia Studio, Content Model (`/wp-admin/admin.php?page=leitvia-studio-model`) | Only abilities outside Leitvia; Leitvia's own abilities do not need it |

A second language: pages with slugs of their own, the rest is the [operator's](new-site-operator.md#a-second-language-polylang).

The report lists the open ones under the heading "For the person", numbered, each with what to do, where, and which step of the workflow waits for it, for example: "1. Apply package 10 (26 operations) at `/wp-admin/admin.php?page=leitvia-studio-agents&tab=inbox&package=10`. Step 4 waits for it." The next run goes on as the section on the mode says, and checks the shop settings on the checkout page.

## Invented sites

A briefing that says the site is invented (a demo, a test, a fictional brand) is the named source for invented content: brand name, colors and fonts, products and prices, dates, people, quotes and figures. Mark everything invented as such in the report. Never invent the real-world facts: who operates the site, addresses and contacts outside `example.org` or `example.com`, and legal texts; their placeholders stay. Every write still follows the mode.

## Steps

### 1. Setup

- **Skill:** leitvia-setup, steps 1 to 4.
- **Input:** the briefing of the person.
- **Writes:** `leitvia/apply-recipe` with the recipe and, in mode `package`, the key. Settings that point to recipe pages (front page, privacy policy page) wait in the same package and are set when it is applied. Own content types with `leitvia/define-content-type`, or a building block of an add-on (`leitvia/list-building-blocks`) with `leitvia/add-building-block`, for a shop the products with `leitvia/create-product`, same key.
- **Checkpoints:** "Briefing confirmed": "Is this right: operator <name>, kind of site <kind>, mode <mode>, fixed URLs <list>, legal texts from <person>?" Then "Recipe chosen": "I will use the recipe <name>. It creates the pages <titles> with the addresses <slugs>. Shall I apply it?"

### 2. Brand

- **Skill:** leitvia-design, steps 1 to 5.
- **Input:** the brand source the person names in the briefing (style guide, design file, existing site), the contract from step 1.
- **Writes:** `leitvia/create-token-set` with the same key. Skip the step when the person has no brand yet and did not ask you to design one, and say so in the report. When the briefing asks for a brand without values, design it as a proposal (leitvia-design, ground rules).
- **Checkpoint:** "Contrast correction accepted", only when you changed a brand value: "Your accent <old> misses <pair> (<ratio>). <new> reaches <ratio>. May I use <new>?"

### 3. Extra pages

- **Skill:** leitvia-page.
- **Input:** pages the briefing asks for beyond the recipe, with goal and content points; the page frames and patterns of the contract.
- **Writes:** `leitvia/compose-page` with the same key. Only approved patterns; a Lab pattern in draft or review is refused with `leitvia_pattern_not_approved`.
- **Deviations from the design:** an existing pattern restyled by a site style for the child theme before a new pattern; the agent reports site styles as findings and writes no CSS ([deviations](../skills/leitvia-page/references/deviations.md)).
- **Checkpoint:** "Outline confirmed": "This is the outline of <page>: <sections with pattern and content>. Shall I build it?"

### 4. Review of the package

- `leitvia/list-packages` with `state: open` shows exactly one package with your key; `leitvia/get-package` with its `id` lists every operation with its diff and the `preview` link. `preview` stays empty while the package only creates pages and nothing it changes has an address yet; the inbox shows the block diff all the same.
- **Checkpoint "Package applied":** "Package <id> holds <n> changes: <short list>. Please review it in the inbox at `/wp-admin/admin.php?page=leitvia-studio-agents&tab=inbox&package=<id>` (block diff and preview) and apply it. Shall I wait?" Do not continue before the person applied it.
- After it is applied: `leitvia/apply-recipe` with the same recipe and key reports `package: 0` and only `exists` (leitvia-setup, step 5).

### 5. Legal texts and activation

- **Skills:** leitvia-setup step 6 (legal texts), leitvia-design step 6 (activate the token set).
- **Input:** page IDs from the check run of step 4, the ID of the new token set from `leitvia/get-tokens`, texts the person supplied verbatim.
- **Writes:** `leitvia/update-content` on the legal pages and `leitvia/activate-token-set`, both with the key; they form the second package.
- **Checkpoints:** "Legal texts supplied by the human": "Please send the texts of imprint and privacy policy (and for a shop terms and withdrawal), and the facts of the accessibility statement. Until then the placeholders stay and the site is not finished." Then "Package applied" for the second package, with the same question as in step 4.

### 6. Accessibility check

- **Skill:** leitvia-a11y.
- **Input:** every published page of the site after step 5, through the [page check](../skills/leitvia-a11y/references/page-check.md).
- **Writes:** fixes with the same key, a third package when there is anything to fix.
- **Checkpoints:** "Link targets", "AI for alt text" and "Package applied" of the skill, with the questions it names.

### 7. Sources against the result

- **Input:** the briefing and every other source the person named, read again from the file or link, not from your memory of them. They can change during a run.
- **Do:** compare them with the site: pages, texts, facts, brand values. Anything new or changed since you read it last is a task of its own: small corrections go into a package with the same key, the rest into the report.
- **Writes:** fixes with the same key.
- **Checkpoint:** "Package applied", only when there is anything to fix.

## Checking pages

At most two passes per round: the changed pages after a step, then every page once at 1440 and 390 px before the report. Check a later fix only where it is, never with another full pass. Screenshots stay out of version control (`shots/` in `.gitignore`).

## Report

Give the person, in their language: the packages with ID, state and inbox link; the pages with their public addresses; the findings of the accessibility check before and after; the site style findings for the child theme; what stays open (legal texts, links you could not resolve, tests only a person can do). Never call the site accessible or finished while a legal placeholder is visible.

## Checkpoints

1. **Briefing confirmed** and **Recipe chosen** (step 1).
2. **Contrast correction accepted** (step 2, only when a brand value changed).
3. **Outline confirmed** per extra page (step 3).
4. **Package applied** after steps 3, 5 and 6.
5. **Legal texts supplied by the human** (step 5).
