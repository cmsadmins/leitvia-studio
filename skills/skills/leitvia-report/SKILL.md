---
name: leitvia-report
description: Writes a report on a WordPress site with the Leitvia theme and Leitvia Studio for the person who owns or runs it, from read abilities only (audit-site, get-site-health, get-seo, get-tokens, list-packages) and the public pages. Covers content quality and accessibility findings, search data, versions and updates, design contrast and open change packages, each figure with its source and date, in plain language with the most important points first, and on request a JSON export of pages, menus and token sets for a handover. Changes nothing. Use it when someone asks for a status, health, quality or SEO report, a monthly summary or a handover document; fixing belongs to leitvia-maintain, leitvia-a11y and leitvia-seo-geo.
compatibility: WordPress 7.1+, PHP 8.2+. Needs the theme Leitvia and the plugin Leitvia Studio, reached over the MCP server leitvia or the REST routes of the Abilities API with an application password.
---

# leitvia-report

You read a site and tell its owner, in their words, how it stands and what to do next. Every statement rests on a source you name. You change nothing.

## When to use

- A status report for the owner, an agency or a handover, or an export of the content; read only.
- Not for fixing what it finds: leitvia-maintain, leitvia-a11y, leitvia-seo-geo and leitvia-design do that.

## Inputs required

- Access: MCP server `leitvia` or the REST routes of the Abilities API, as the agent user with its application password: [handbook, Access over MCP and REST](../../handbook/README.md#access-over-mcp-and-rest). Paths start at the address of the site, not at the host.
- Reader, purpose and format of the report (step 1).
- Read before the first call: the [agent handbook](../../handbook/README.md) (access, hashes, autonomy, packages, rejections, index of all abilities). Read the page of each ability before you first call it (the handbook index links them): `audit-site`, `get-site-health`, `list-content`, `get-seo`, `get-tokens`, `list-packages`, for an export `export`. Layout, severity and wording: [report format](references/format.md).

## Ground rules

- Call `leitvia/get-contract` first and compare `site.url` with the address of your task; when they differ, ask the person. Never use SQL, WP-CLI or the REST routes of WordPress core (`/wp-json/wp/v2/...`), not even to read. Reading public pages of the site is fine and part of the report.
- **Read only.** This skill calls no write ability and sends no `package` key. A problem you find goes into the report with the skill that fixes it.
- **Facts are never guessed.** Organization data, prices, legal texts, figures, quotes and people come only from a source you can name: the person, a page of the site or a read ability, with the source in your report. Without one, stop at a checkpoint; never write a placeholder that looks like a fact.
- **Every figure has a source:** the ability or public URL and the date you read it. A count you did not finish (paging stopped, a rejection) is marked as partial.
- **No verdicts the data cannot carry.** Automated checks never make a site accessible, secure or legally compliant; say what was checked and what was not.
- **No personal data in the report** beyond what the person gave you: no user names, emails or comments. Plugin and theme names are fine.
- A rejection is corrected at most once, following `message`, `next_ability` and `retry`. If the second call is rejected too, or `retry` is `never`, that source is missing; say so in the report.

## Procedure

### 1. Reader and purpose

Ask who reads the report and what for (checkpoint **Reader**): an owner without technical knowledge, an agency, a technical person, a handover. Ask for the format when it matters (checkpoint **Format**). Default: owner, Markdown in the chat, the language of the person.

### 2. Collect

- `leitvia/get-contract`: site address, theme and Studio version, name of the site.
- `leitvia/get-site-health`: versions of WordPress, PHP, theme and plugins and the updates WordPress knows of. A `leitvia_forbidden` rejection means the agent user may not read versions; note it.
- `leitvia/audit-site` with `limit: 20`, paging with `after` set to `next` until `next` is `null`: findings per page with `check` and `severity`.
- `leitvia/list-content` with `status: publish`, all pages of it, then `leitvia/get-seo` with up to 50 IDs per call: the SEO plugin, per page title, description, `noindex` and `schema_type`, and for the site `indexable` and `news_sitemap`. Open `/robots.txt`, `/wp-sitemap.xml` and, with news, `/news.xml`.
- `leitvia/get-tokens`: `contrast` lists color pairs of the palette that miss their minimum.
- `leitvia/list-packages` with `state: open`: changes that wait for a person (`total` counts all; fetch `page` 2 and on while `page` is below `pages`). `audit-site` names packages open for more than a day (`leitvia/stale-package`).
- Public pages: the home page and the pages the person named; note whether they load, the `<title>` and anything plainly broken.

### 3. Weigh

Sort what you found into high, medium and low with the [severity scale](references/format.md#severity), count per area, and pick at most five points for the summary. Name for each point the skill that fixes it (`leitvia-maintain`, `leitvia-a11y`, `leitvia-seo-geo`, `leitvia-design`) or the person, when it needs a decision or access agents do not have (plugin updates, settings, legal texts).

### 4. Write

Use the [layout](references/format.md#layout): summary, key figures, findings by area, open decisions, what the report does not cover, sources. Plain words for the reader of step 1; technical names only in the sources and for a technical reader. Compare with an earlier report when the person gives you one, and say what changed.

### 5. Export, when asked

For a handover or a copy of the content, call `leitvia/export`: pages (block markup), navigation menus and token sets come as `items`, the first call also brings template parts and the content model. Call again with `offset` set to `next` until `next` is `null`; `parts` narrows it. Save the items of all calls in one JSON file and name its counts in the report. Say what it lacks: media files, posts, products and settings; drafts are in it, and it restores nothing by itself.

## Verification

- Every figure in the report names its ability or URL and the date; partial counts are marked.
- Every point of the summary names the skill or the person that fixes it.
- An export file holds the items of all calls, and its counts match the report.

## Failure modes / debugging

Every code with its retry rule: [rejections](../../handbook/rejections.md).

- `leitvia_forbidden` from `leitvia/get-site-health`: the agent user may not read versions; note it as missing.
- `leitvia_rate_limited`: wait the seconds of `after:<seconds>` and read again.
- `leitvia_no_contract`: the active theme is not Leitvia, or its contract is broken; say so in the report.

## Report

The report itself is the result. Deliver it in the format of step 1 and end the chat message with the date, the scope (pages and sources read) and the sentence that the report is based on automated reads and does not replace a review by a person.

## Escalation

Stop and ask the person at these checkpoints, then continue with the answer:

- **Reader:** who reads the report and what for, when the request does not say it.
- **Format:** chat, a Markdown file or another format the person needs.
- **Scope:** a site with more than 200 published pages: all, or the most important ones.
- **Second rejection** of the same call, or a rejection with `retry: never`.

## Unattended

When no person answers (a scheduled or hosted run), do not wait at a checkpoint. Send the `package` key on every write, even when the briefing says nobody visits the site (mode `direct` needs a person who is present); never apply, roll back or publish anything. Take facts only from a source you name in the report (briefing, a page of the site, a read ability); without one the fact stays out and becomes an open question. A second rejection, or `retry: never`, stops that part. End with the package ID, what it holds and the open questions. Each checkpoint gets this answer:

| Checkpoint | Answer without a person |
|---|---|
| Reader | The owner, without technical knowledge. |
| Format | Markdown, in the language of the briefing. |
| Scope | All published pages; mark counts as partial when paging stops early. |
| Second rejection | Leave that source out and name it as missing. |

This skill writes nothing, so an unattended run ends with the report and no package.
