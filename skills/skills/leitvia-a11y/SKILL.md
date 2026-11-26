---
name: leitvia-a11y
description: Checks a WordPress site with the Leitvia theme and Leitvia Studio against WCAG 2.2 AA and BITV 2.0 as far as abilities and public pages allow, fixes what the leitvia abilities can fix (alt text, link texts, empty headings, heading levels, decorative images) in a change package, and reports in three parts what was fixed, what stays open and why, and what only a person can test (screen readers, plain language, videos, forms of other plugins). Never declares a site accessible. Use it after a site or page was built or changed, before a release, or when someone asks for an accessibility check; colors of the palette belong to leitvia-design.
compatibility: WordPress 7.1+, PHP 8.2+. Needs the theme Leitvia and the plugin Leitvia Studio, reached over the MCP server leitvia (application password or OAuth token) or the REST routes of the Abilities API (application password only).
---

# leitvia-a11y

You check a site for barriers, remove the ones the abilities can remove and say clearly which ones remain and which ones no tool can judge. You change the site only through the abilities of Leitvia Studio.

## When to use

- After a site or page was built or changed, before a release, or when someone asks for an accessibility check.
- Not for the colors of the palette (leitvia-design) or texts for search (leitvia-seo-geo).

## Inputs required

- Access as the agent user: [handbook, Access over MCP and REST](../../handbook/README.md#access-over-mcp-and-rest).
- The scope of step 1 and the language of each page.
- Read before the first call: the [agent handbook](../../handbook/README.md). Read the page of each ability before you first call it (the handbook index links them): `audit-site`, `get-tokens`, `get-page-outline`, `get-block`, `set-alt-text`, `update-block`, `update-content`, `list-packages`, `get-package`. Fixes, manual tests and the accessibility statement: [check details](references/checks.md).

## Modes

- **package** (default): send the same `package` key on every write of the check, for example `a11y-2026-10`, so a person reviews one change package.
- **direct:** Only when the person asks for it; the autonomy levels decide. Send no `package` key: a key always means a package.

In mode `package` every fix waits in the package, alt text included. In mode `direct` you send no key: alt text belongs to the area `corrections` (`autonomous` by default) and goes live with its own recorded package, text changes on published pages wait in a package. Report both.

## Ground rules

- Follow the [rules for every task](../../handbook/README.md#rules-for-every-task).
- **Never call a site accessible, barrier free or compliant.** Automated checks find a part of the barriers. Without the tests of a person (see step 6) the report says "no automatically detectable barriers on the checked pages", nothing more.
- **Several text fixes of one post** fit into one `leitvia/update-content` call; writes to one post in one package build on each other ([mode package](../../handbook/README.md#mode-package)).
- **Only what a write brings counts:** findings the post already had do not block a write; one that adds a finding or makes one worse is rejected with `leitvia_check_failed`, which names it.
- **Facts are never guessed.** Organization data, prices, legal texts, figures, quotes and people come only from a source you can name: the person, a page of the site or a read ability, with the source in your report. Without one, stop at a checkpoint; never write a placeholder that looks like a fact.
- The theme comes with its own test report; it covers the theme, not the content of this site. Judge the site by what you find on the site.

## Procedure

### 1. Scope

Ask which pages to check when the person did not say it; default is every published page and post. Note the language of the site and of each page.

### 2. Automated checks

- `leitvia/audit-site` with `limit: 20`, page through with `after` set to `next` until `next` is `null`. The first page also returns `site` (findings of header and footer parts, stale packages): add them to the list. `check` holds the name of the check with the prefix `leitvia/`; the checks that matter here:

| Check | WCAG | What it finds |
|---|---|---|
| `headings` | 1.3.1, 2.4.6 | empty headings, skipped levels, an h1 in content when the template prints the title as h1 |
| `page-structure` | 1.3.1 | not exactly one h1 per page, missing header, main or footer |
| `alt-text` | 1.1.1 | images without alt, alt that is a file name, empty alt without the decorative mark |
| `link-text` | 2.4.4 | empty links, links to `#`, link texts such as "click here", "read more", "hier" |
| `content-contrast` | 1.4.3 | text role of a block on its ground (own, nearest around it, inverse for `is-style-inverse`) that is no pair of the contract and misses 4.5:1 |

- `leitvia/get-tokens`: `contrast` lists the pairs of the active palette that miss their minimum. Fixing the palette is a design change: hand it to the skill `leitvia-design`, do not change colors in content.
- Public pages: run the [page check](references/page-check.md) over the scope, then check what the audit does not see, because it comes from the template or a plugin: `<html lang>` matches the language of the page, `<title>` names the page, exactly one `h1`, every `img` has `alt`, every link and button has a name, the skip link is the first focusable element. Findings in header, footer or navigation belong to the template parts; a person decides on them.

Keep this list: it is the "before" of your report.

### 3. Fix through the pipeline

Alt text, decorative images, link texts, empty headings and heading levels, each with its ability: [fixes](references/checks.md#fixes). In mode `package`, send every write with the `package` key of the check.

### 4. What the abilities cannot fix

Landmarks and the h1 of a template, classic content and blocks of other plugins, and the palette: report them ([check details](references/checks.md#what-the-abilities-cannot-fix)).

### 5. After the person applied the package

The person applies your package (its ID comes with every write) at `/wp-admin/admin.php?page=leitvia-studio-agents&tab=inbox&package=<id>` (checkpoint **Package applied**). Then `leitvia/get-package` for conflicts, `leitvia/audit-site` and the public pages again: the "after" of your report.

### 6. What only a person can test

Screen readers, keyboard and focus, the meaning of your texts, plain and easy-to-read language, language changes, media, forms of other plugins, documents, zoom and reflow. List each that applies, with the page: [manual tests](references/checks.md#manual-tests).

### 7. Accessibility statement

The page kind `accessibility` is a legal text; its content and legal statements come from the person ([accessibility statement](references/checks.md#accessibility-statement)).

## Verification

- The "after" of step 5: `leitvia/get-package` shows no conflicts, and `leitvia/audit-site` and the public pages no longer show the findings you fixed.
- Every fix you report as live is in the result of its write as `live`; everything else waits in the package.

## Failure modes / debugging

Every code with its retry rule: [rejections](../../handbook/rejections.md).

- `leitvia_check_failed`: the write adds a finding or makes one worse; the message names it. Correct once.
- `leitvia_stale_hash` on a second write to the same post: read `leitvia/get-page-outline` again and send its `hash`.
- `leitvia_needs_human` from `leitvia/apply-package`: expected; `data.inbox` is the link for the person.
- A finding in header, footer or navigation: it sits in a template part; report it, a person decides.

## Report

Write the report in the chat, in the language of the person, in three parts, after the package and the checked pages:

1. **Fixed:** per page what you changed, with the check it answers, and whether it is live or waits in the package (ID and inbox link).
2. **Open, with the reason:** per page each finding you did not fix and why: no ability for it (templates, classic content, blocks of other plugins), needs facts from the person, palette for `leitvia-design`.
3. **Only a person can test:** the list of step 6 for the checked pages.

End with the scope (pages, date, language) and the sentence that the report covers automated checks only.

## Escalation

Stop and ask the person at these checkpoints, then continue with the answer:

- **Scope:** pages to check when the request does not say it.
- **Link targets:** a link to `#` or a vague link whose target you cannot tell.
- **AI for alt text:** before images go to the AI provider of the site.
- **Package applied:** the person reviews and applies the package in the inbox.
- **Second rejection** of the same call, or a rejection with `retry: never`.
- **Accessibility statement:** facts and legal statements come from the person.

## Unattended

When no person answers (a scheduled or hosted run), do not wait at a checkpoint. Send the `package` key on every write, even when the briefing says nobody visits the site (mode `direct` needs a person who is present); never apply, roll back or publish anything. Take facts only from a source you name in the report (briefing, a page of the site, a read ability); without one the fact stays out and becomes an open question. A second rejection, or `retry: never`, stops that part. End with the package ID, what it holds and the open questions. Each checkpoint gets this answer:

| Checkpoint | Answer without a person |
|---|---|
| Scope | Every published page and post. |
| Link targets | Fix only links whose target is clear on the site; list the others with page and link text. |
| AI for alt text | No: images go to no AI provider. Write alt texts yourself or list the image as open. |
| Package applied | Stop after step 4; the audit after belongs to the next run. |
| Second rejection | Stop that fix; report code and message. |
| Accessibility statement | Do not change it; list what it should say as a question. |
