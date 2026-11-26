---
name: leitvia-maintain
description: Maintains an existing WordPress site with the Leitvia theme and Leitvia Studio through the leitvia abilities only. Audits the published content, repairs broken internal links, sets missing alt text, replaces deprecated patterns and fixes outdated texts, bundles every write of one maintenance run into one change package and reports the findings before and after with a link to the package. Rolls a package back only when a person asks. Use it for a regular maintenance run or when someone reports broken links, missing alt text or outdated sections; accessibility beyond these checks belongs to leitvia-a11y.
compatibility: WordPress 7.1+, PHP 8.2+. Needs the theme Leitvia and the plugin Leitvia Studio, reached over the MCP server leitvia (application password or OAuth token) or the REST routes of the Abilities API (application password only).
---

# leitvia-maintain

A maintenance run takes a site that already works and leaves it in a better state: audit, fixes per area, one change package for a person, a report with the findings before and after. You change the site only through the abilities of Leitvia Studio.

## When to use

- A regular maintenance run, or a report of broken links, missing alt text or outdated sections.
- Not for accessibility beyond these checks (leitvia-a11y), new pages (leitvia-page) or the catalog of a shop (leitvia-shop).

## Inputs required

- Access as the agent user: [handbook, Access over MCP and REST](../../handbook/README.md#access-over-mcp-and-rest).
- The package key of the run; for outdated content, the sources the person names.
- Read before the first call: the [agent handbook](../../handbook/README.md). Read the page of each ability before you first call it (the handbook index links them): `audit-site`, `get-page-outline`, `get-block`, `repair-links`, `set-alt-text`, `update-block`, `update-content`, `replace-pattern`, `list-packages`, `get-package`, `rollback`. How to fix each area and how to roll back: [fix details](references/fixes.md).

## Modes

- **package** (default for maintenance): send the same `package` key on every write of the run, for example `maintenance-2026-10`. One run, one key, one package for a person.
- **direct:** Only when the person asks for it. The autonomy levels decide what goes live. Send no `package` key: a key always means a package.

Corrections (links, alt text) belong to the area `corrections`, which is `autonomous` by default. With a `package` key every write waits in the package, corrections included. In mode `direct` you send no key: a correction the level lets go live goes live, and Studio records it as an applied package of its own (the `package` of the result), so it can be rolled back; a write the level holds back waits in a package of its own. Report both: the open packages and the corrections that went live.

## Ground rules

- Follow the [rules for every task](../../handbook/README.md#rules-for-every-task).
- **Combine the changes of one post** in one call where the ability allows it (`leitvia/update-content` takes up to 50 blocks; `leitvia/repair-links` repairs all links of a post at once); writes to one post in one package build on each other ([mode package](../../handbook/README.md#mode-package)).
- **Only what a write brings counts:** findings the post already had do not block a write; one that adds a finding or makes one worse is rejected with `leitvia_check_failed`, which names it.
- **Facts are never guessed.** Organization data, prices, legal texts, figures, quotes and people come only from a source you can name: the person, a page of the site or a read ability, with the source in your report. Without one, stop at a checkpoint; never write a placeholder that looks like a fact.
- Never change prices, legal texts or organization data on your own, and never write review notes or HTML comments into content; open questions go into the report.

## Procedure

### 1. Audit

Call `leitvia/audit-site` with `limit: 20` and page through with `after` set to `next` until `next` is `null`. Each item has `post`, `type`, `title`, `slug`, `kind` and `findings` (`check`, `severity`, `block`, `message`, `fix`). Keep this list: it is the "before" of your report.

Sort the findings by area. `check` holds the name of the check with the prefix `leitvia/`, which the table leaves out:

| Check | Area | Ability |
|---|---|---|
| `broken-link` | internal links | `leitvia/repair-links` |
| `alt-text` | alt text | `leitvia/set-alt-text` |
| `link-text` | link texts and `#` links | `leitvia/update-block` (one link), `leitvia/update-content` (several texts) |
| `empty-link` | links without target outside the content | part: `leitvia/update-template-part`, else report |
| `deprecated-pattern`, `successor-pattern` (notice) | sections | `leitvia/replace-pattern` |
| `headings`, `page-structure`, `motion` | structure, motion | skill `leitvia-a11y` |
| `content-tokens`, `token-drift`, `content-contrast` | design | skill `leitvia-design` or a person |
| `composition` | page composition | skill `leitvia-page` |
| `query-context` | query filter or Load more without a working query | `leitvia/update-block` on the `core/query` block (own `queryId`, `inherit` false) |
| `privacy-page` | contact form without a published privacy policy page | `leitvia/set-privacy-page`, a person applies it |
| `stale-package` (part `site` of page 1) | open packages | report with the inbox link |
| `page-kind`, `block-validity`, `legal-placeholder`, `missing-page`, `classic-content`, `unregistered` | needs a person | report only |

### 2. Broken links

One `leitvia/repair-links` call per post, with `auto: true` for renamed posts and `from`/`to` pairs whose `to` you checked on the public site (200, the page the link text names). The audit checks no internal links of header, footer and navigation: check them on the public pages and report them. Details: [broken links](references/fixes.md#broken-links).

### 3. Alt text

Look at each image and its place on the page, write one short sentence, and send it with `leitvia/set-alt-text` including `post` and `block` (many images: one call with `items`); mark a decorative image with `leitvia/update-block`. Details and the AI draft: [alt text](references/fixes.md#alt-text).

### 4. Deprecated patterns

`leitvia/replace-pattern` per post with all deprecated sections in one call; compare the moved texts in the package diff. Details: [deprecated patterns](references/fixes.md#deprecated-patterns).

### 5. Outdated content

Change stale figures, dates and names only against a source the person named or a read ability; open markers (`CHECK:`, `TODO`, sample texts) go into the report. Details: [outdated content](references/fixes.md#outdated-content).

A section that shows nothing on the public page may need a building block the site lacks: `leitvia/list-building-blocks` lists it with `installed: false` and its `patterns`. Report it; add it with `leitvia/add-building-block` and your `package` key only when the person asks.

### 6. The package

Every write of the run carries the same `package` key. After the last write:

- `leitvia/get-package` with the `package` ID of your writes: the diff per operation. Check that every operation does what you meant.
- Do not call `leitvia/apply-package` for operations of areas that are not `autonomous` for you; it answers `leitvia_needs_human`, whose `data.inbox` is the link for the person.
- A package that took a wrong turn: `leitvia/reject-package` with `id`, for your own package. Open operations stay unwritten, waiting files go, applied ones stay; the package and the audit log remain. Start again with a new `package` key.

The person reviews the package in the inbox of Leitvia Studio: `/wp-admin/admin.php?page=leitvia-studio-agents&tab=inbox&package=<id>`. Checkpoint **Package applied**.

## Speed

The target on mobile is 100 in PageSpeed in all four categories; what the theme does and what stays with the person: [speed](references/fixes.md#speed).

## Rollback

Only when a person asks for it, never to tidy up your own run: confirm the package with them, then `leitvia/rollback`. A `leitvia_rollback_conflict` means a person changed a post since; show the diff and stop. Steps: [rollback](references/fixes.md#rollback).

## Verification

After the person applied the package:

- `leitvia/list-packages` with `state: applied`, and `leitvia/get-package`: operations with a `conflict` were not written because someone changed the post in between. Read the post again and repeat that change once in a new run; never force it.
- `leitvia/audit-site` again over all pages: the "after" of your report.
- Request the repaired links again; each answers 200.

## Failure modes / debugging

Every code with its retry rule: [rejections](../../handbook/rejections.md).

- Every write ends with 403 and the abilities answer 404: Leitvia Studio is not active. The role stores no capability, so without Studio the agent can change nothing on the site. Tell the person and stop; do not try core routes. The password works again once a person activates Studio. After Studio was deleted the password is gone and a person creates a new one.
- `leitvia_check_failed`: the write adds a finding or makes one worse; the message names it. Correct once.
- `leitvia_needs_human` from `leitvia/apply-package`: expected; `data.inbox` is the link for the person.

## Report

Write the report in the chat, in the language of the person:

1. **Package:** ID, state and the inbox link of step 6; plus the IDs of corrections that went live on their own.
2. **Before and after:** per post the findings of the first audit and of the audit after the package was applied (or "waiting for the package" until then), counted per check.
3. **Fixed:** per post what you changed (links from and to, alt texts, replaced patterns, texts).
4. **Open with reason:** findings no ability can fix, broken links in template parts, external links not checked, conflicts.
5. **Questions for the person:** link targets, facts, open markers.

## Escalation

Stop and ask the person at these checkpoints, then continue with the answer:

- **Link targets:** a broken link has no address that clearly fits its text.
- **Section without replacement:** `leitvia_no_replacement`.
- **Facts from the person:** prices, legal texts, organization data or figures without a source.
- **Package applied:** the person reviews and applies the package in the inbox before you audit again.
- **Rollback confirmed:** the person names the package to roll back.
- **Second rejection** of the same call, or a rejection with `retry: never`.

## Unattended

When no person answers (a scheduled or hosted run), do not wait at a checkpoint. Send the `package` key on every write, even when the briefing says nobody visits the site (mode `direct` needs a person who is present); never apply, roll back or publish anything. Take facts only from a source you name in the report (briefing, a page of the site, a read ability); without one the fact stays out and becomes an open question. A second rejection, or `retry: never`, stops that part. End with the package ID, what it holds and the open questions. Each checkpoint gets this answer:

| Checkpoint | Answer without a person |
|---|---|
| Link targets | Repair only links whose target is clear; list the others with page and link text. |
| Section without replacement | Leave the section as it is; list post and pattern. |
| Facts from the person | Leave the text as it is; list it with the value you could not confirm. |
| Package applied | Stop after step 6; the audit after belongs to the next run. Report the "before" only. |
| Rollback confirmed | Never roll back without a person. |
| Second rejection | Stop that fix; report code and message. |
