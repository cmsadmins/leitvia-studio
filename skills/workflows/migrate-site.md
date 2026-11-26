# Workflow: migrate-site

From a classic theme to the Leitvia theme: inventory, theme switch by a person, brand, content as blocks, accessibility check. The workflow chains three skills and stops at fixed checkpoints; each skill keeps its own rules and checkpoints. How to call the abilities, hashes and rejections: [agent handbook](../handbook/README.md).

Chain: [leitvia-migrate](../skills/leitvia-migrate/SKILL.md) (inventory), the theme switch by a person, [leitvia-design](../skills/leitvia-design/SKILL.md), leitvia-migrate again (content and menu), then [leitvia-a11y](../skills/leitvia-a11y/SKILL.md).

## Choose the mode at the start

Ask the person once, before the first write, and keep the answer for the whole migration.

- **package** (default): choose one key for the migration, for example `migrate-2026-10`, and send it as `package` on every write of every step. Every write lands in the change package of that key; nothing goes live, whatever the autonomy levels of the site allow. A step that needs what an earlier package creates (the activation of a new token set) comes after the checkpoint **Package applied**; the same key then starts the next package.
- **direct:** send no key, the autonomy levels of the site decide. Legal texts, prices, slugs and organization data stay a proposal all the same: send these writes with a key, so they wait in a package. With the default levels changes to published posts wait in a package anyway.

Every rejection is corrected at most once (follow `message`, `next_ability` and `retry`); a second rejection of the same call, or `retry: never`, is a checkpoint: stop and show the person the rejection.

When no person answers, the workflow runs in several unattended runs with the same key. Each checkpoint takes the answer of the section Unattended of its skill, and every write carries the key. A run ends at the next checkpoint "Package applied" with its report. The next run starts with `leitvia/list-packages`: while the package of the key is still `open`, it reports that it waits and writes nothing; once a person applied it, the run goes on with the step after that checkpoint. Nothing goes live without a person applying a package.

## Steps

### 1. Inventory

- **Skill:** leitvia-migrate, step 1, while the classic theme is still active.
- **Input:** the address of the site; `leitvia/audit-site`, `leitvia/get-page-outline`, `leitvia/get-block` and the public pages.
- **Writes:** none.
- **Checkpoint "Inventory confirmed":** "These are the posts, menus, widgets and brand values I found, and these items I cannot move: <table>. Is the list complete?"

### 2. Theme switch, done by a person

Agents cannot switch themes or activate plugins; the area `admin` is locked. Ask the person to do it in one of two ways:

- WP-CLI on the server: `wp theme activate leitvia`, and `wp plugin activate leitvia-studio` when Studio is not active yet.
- Backend: Appearance, Themes, Leitvia, Activate.

**Checkpoint "Theme switch":** "Please activate the theme Leitvia (and Leitvia Studio, if it is not active). Tell me when it is done." Then call `leitvia/get-contract`: it answers with the contract of Leitvia, and `site.url` is the address of the site. When it still answers `leitvia_no_contract`, the switch did not happen; ask again and write nothing.

### 3. Brand

- **Skill:** leitvia-design, steps 1 to 5.
- **Input:** the brand values of the inventory (colors with their use, fonts, logo).
- **Writes:** `leitvia/create-token-set` with the key. Skip the step when the person wants to keep the brand for later.
- **Checkpoint:** "Contrast correction accepted", only when you changed a brand value: "Your link color <old> misses <pair> (<ratio>). <new> reaches <ratio>. May I use <new>?"

### 4. Content and menu

- **Skill:** leitvia-migrate, steps 4 to 6.
- **Input:** the posts of the inventory, read again after the switch (`document_hash` of `leitvia/get-block`).
- **Writes:** `leitvia/update-content` per post with the whole block markup, `leitvia/update-navigation` for the main menu, both with the key. Several writes to one post in the same package build on each other.
- **Checkpoint "Sample of three":** "I translated <three posts>. Please compare them with the old pages before I write the rest." Then "Open items": "These items have no block in the migration: <list>. What should happen with each?"

### 5. Package

- `leitvia/list-packages` with `state: open`: in mode `package` exactly one package with your key. `leitvia/get-package` with its `id` gives the diff per operation and the `preview` link.
- **Checkpoint "Package applied":** "Package <id> holds <n> changes: <short list>. Please review it in the inbox at `/wp-admin/admin.php?page=leitvia-studio-agents&tab=inbox&package=<id>` (block diff and preview) and apply it. Shall I wait?"
- After it is applied, activate the token set (leitvia-design, step 6) with the same key; that is the next package and the same checkpoint.

### 6. Accessibility check

- **Skill:** leitvia-a11y.
- **Input:** every migrated page and post, after the packages of step 5 were applied, through the [page check](../skills/leitvia-a11y/references/page-check.md) with `--old` (words, links, widths 1920 to 320, other hosts).
- **Writes:** fixes with the same key, one more package when there is anything to fix.
- **Checkpoints:** "Link targets", "AI for alt text" and "Package applied" of the skill.

## Report

In the language of the person: the packages with ID, state and inbox link; per post its address before and after (they must match) and any heading level you changed; the brand values and their roles; open items with the reason; what only a person can test. Never call the site accessible or the migration complete while an open item remains.

## Checkpoints

1. **Inventory confirmed** (step 1).
2. **Theme switch**, done by the person (step 2).
3. **Contrast correction accepted** (step 3, only when a brand value changed).
4. **Sample of three** and **Open items** (step 4).
5. **Package applied** after steps 4, 5 and 6.
6. **Second rejection** of the same call, or a rejection with `retry: never`, in any step.
