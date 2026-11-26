# Workflow: maintenance-cycle

A regular maintenance run on a site with the Leitvia theme and Leitvia Studio: repair what broke, check accessibility, report to the person. The workflow chains two skills (three on a shop) and a report and stops at fixed checkpoints; each skill keeps its own rules and checkpoints. How to call the abilities, hashes and rejections: [agent handbook](../handbook/README.md).

Chain: [leitvia-maintain](../skills/leitvia-maintain/SKILL.md), then [leitvia-a11y](../skills/leitvia-a11y/SKILL.md), on a shop optionally [leitvia-shop](../skills/leitvia-shop/SKILL.md), then the report.

## Choose the mode at the start

Ask the person once, before the first write, and keep the answer for the whole run.

- **package** (default): choose one key for the run, for example `maintenance-2026-10`, and send it as `package` on every write of both skills. Every write lands in that one change package, corrections included; nothing goes live before the person applies it.
- **direct:** send no key, the autonomy levels of the site decide. With the default levels (`corrections` autonomous, `content` draft) repaired links and alt text go live at once, each recorded as an applied package of its own that `leitvia/rollback` can undo, and changes of texts on published pages wait in a package for the person. Legal texts, prices, slugs and organization data stay a proposal all the same: send these writes with a key, so they wait in a package.

Every rejection is corrected at most once (follow `message`, `next_ability` and `retry`); a second rejection of the same call, or `retry: never`, is a checkpoint: stop and show the person the rejection.

When no person answers, the workflow runs in several unattended runs with the same key. Each checkpoint takes the answer of the section Unattended of its skill, and every write carries the key. A run ends at the next checkpoint "Package applied" with its report. The next run starts with `leitvia/list-packages`: while the package of the key is still `open`, it reports that it waits and writes nothing; once a person applied it, the run goes on with the step after that checkpoint. Nothing goes live without a person applying a package.

## Steps

### 1. Maintenance

- **Skill:** leitvia-maintain, steps 1 to 6.
- **Input:** the request of the person; the site itself through `leitvia/audit-site` (keep the result, it is the "before" of the report).
- **Writes:** `leitvia/repair-links`, `leitvia/set-alt-text`, `leitvia/replace-pattern`, `leitvia/update-content`, with the key in mode `package`.
- **Checkpoints:** "Link targets": "The link <text> on <page> leads to <old address>, which does not exist. Which page should it lead to?" "Section without replacement": "The section <pattern> on <page> is deprecated and has no replacement. Keep it, remove it or rebuild it?" "Facts from the person": "<text> on <page> looks outdated. What is the current value?"

### 2. Accessibility

- **Skill:** leitvia-a11y, steps 1 to 4.
- **Input:** the pages of step 1 and their findings after the maintenance writes; the same audit, the public pages.
- **Writes:** alt text, decorative images, link texts, empty headings, heading levels, with the same key in mode `package`.
- **Checkpoints:** "Link targets" and "AI for alt text" of the skill: "May the images of <pages> go to the AI provider of the site for a first draft of their alt text?"

### 3. Shop catalog, optional

- **Only** when WooCommerce is active (`leitvia/list-products` is an ability of the site) and the person asks for it, or the briefing names it. Otherwise skip this step.
- **Skill:** leitvia-shop, steps 1 to 7.
- **Input:** `leitvia/list-products` and `leitvia/get-product`; products without alt text on their images, empty short descriptions and empty categories are the usual findings.
- **Writes:** `leitvia/update-product`, `leitvia/update-product-category`, `leitvia/set-merchandising`, with the same key in mode `package`. Prices, tax and shipping always wait for a person, in mode `direct` too.
- **Checkpoints:** "Plan confirmed" and "Facts from the person" of the skill: "The price of <product> is <old>. Which price should it have?"

### 4. Package

- `leitvia/list-packages` with `state: open`: in mode `package` exactly one package with your key; in mode `direct` the packages of the writes the levels held back. `leitvia/get-package` with each `id` gives the diff per operation and the `preview` link.
- **Checkpoint "Package applied":** "Package <id> holds <n> changes: <short list>. Please review it in the inbox at `/wp-admin/admin.php?page=leitvia-studio-agents&tab=inbox&package=<id>` (block diff and preview) and apply it. Shall I wait?"
- After it is applied: `GET /wp-cron.php` where WP-Cron does not run by itself, then `leitvia/audit-site` again; that is the "after" of the report. A `conflict` in the applied package means someone changed the post in between: read it again and repeat that change once in a new run.

### 5. Report

In the language of the person:

1. **Packages:** ID, state and inbox link of each package; in mode `direct` also the corrections that went live, with the ID of their recorded package.
2. **Before and after:** findings per page from the first and the last audit, counted per check.
3. **Fixed:** per page what changed (links from and to, alt texts, replaced sections, texts, heading levels), and per product of step 3.
4. **Open with reason:** findings no ability fixes, links in header, footer or navigation, external links not checked, conflicts.
5. **For a person:** the tests leitvia-a11y lists in its step 6 (keyboard, screen reader, zoom). Never call the site accessible or compliant.

## Checkpoints

1. **Link targets**, **Section without replacement** and **Facts from the person** (step 1).
2. **AI for alt text** (step 2).
3. **Plan confirmed** and **Facts from the person** of leitvia-shop (step 3), only on a shop.
4. **Package applied** (step 4), before the audit after.
5. **Rollback confirmed**, only when the person asks to undo a package (leitvia-maintain, Rollback).
6. **Second rejection** of the same call, or a rejection with `retry: never`, in any step.
