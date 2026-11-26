---
name: leitvia-seo-geo
description: Improves how a WordPress site with the Leitvia theme and Leitvia Studio is found in search engines and cited in AI answers (ChatGPT, Perplexity, Google AI Overviews), without tricks. Reads titles, meta descriptions, robots and structured data of every page through the active SEO plugin (Yoast SEO, Rank Math, SEOPress, The SEO Framework), writes better titles and descriptions from what the pages say in a change package, suggests clearer structure and drafts an llms.txt for the person to publish. Checks first that the whole site is visible to search engines and fit for Google News. Use it after a site went live, after new pages, or when someone asks about SEO, rankings, AI search or llms.txt; a full report on a site belongs to leitvia-report.
compatibility: WordPress 7.1+, PHP 8.2+. Needs the theme Leitvia and the plugin Leitvia Studio, reached over the MCP server leitvia (application password or OAuth token) or the REST routes of the Abilities API (application password only). Writing SEO data needs one of the four SEO plugins; without one the skill reads and suggests only.
---

# leitvia-seo-geo

You make each page say clearly, in its title, description and first lines, what it offers, so search engines list it and AI answers can quote it. You change the site only through the abilities of Leitvia Studio and never with tricks.

## When to use

- Titles, descriptions, indexing and structure of existing pages for search engines and AI answers, or an llms.txt draft.
- Not for new pages (leitvia-page), news posts (leitvia-news) or accessibility (leitvia-a11y).

## Inputs required

- Access as the agent user: [handbook, Access over MCP and REST](../../handbook/README.md#access-over-mcp-and-rest).
- The pages that matter and who should find them (step 1).
- Read before the first call: the [agent handbook](../../handbook/README.md). Read the page of each ability before you first call it (the handbook index links them): `list-content`, `get-seo`, `update-seo`, `get-page-outline`, `update-content`, `list-packages`, `get-package`. Rules for site visibility, titles, descriptions, structure, structured data, Google News and llms.txt: [SEO and GEO details](references/seo.md).

## Modes

- **package** (default): send the same `package` key on every write, for example `seo-2026-10`, so a person reviews one change package.
- **direct:** Only when the person asks for it; the autonomy levels decide. Send no `package` key: a key always means a package. SEO data belongs to the area `content`, so changes of published pages still wait in a package at the default level.

## Ground rules

- Follow the [rules for every task](../../handbook/README.md#rules-for-every-task). Reading `robots.txt` and `llms.txt` is part of the work.
- **No tricks.** No keyword lists, no repeated phrases, no hidden text, no texts written for machines rather than people, no structured data for content the page does not show, no fake reviews, ratings or FAQ. They do not help and they can cost the site its place in search.
- **Say only what the page says.** A title or description summarizes the page; `leitvia/update-seo` rejects numbers the post does not contain (`unsupported_fact`). Do not promise what the page does not offer.
- **Facts are never guessed.** Organization data, prices, legal texts, figures, quotes and people come only from a source you can name: the person, a page of the site or a read ability, with the source in your report. Without one, stop at a checkpoint; never write a placeholder that looks like a fact.
- **noindex is the person's decision.** Never set `noindex` or `nofollow` on your own; legal pages and thank-you pages are the usual cases, and the person confirms each (checkpoint **Indexing**).
- **Without an SEO plugin** `plugin.key` is `leitvia`: `leitvia/update-seo` stores title, description and robots and prints them, the title plus " | " and the site name where the whole keeps to 60 characters (Studio setting `title_site_name`, on by default). Write the title without the site name. Never change the title of a page to change its `<title>`: it is the `h1`. Whether titles end with the site name: `leitvia/set-seo-titles` (`site_name`), waits in a package. With an SEO plugin Studio cannot write to, the write is rejected with `leitvia_no_seo_plugin`; list the titles and descriptions you would write.

## Procedure

### 1. Scope and goal

Ask which pages matter most and who should find them (people, region, language) when the person did not say it (checkpoint **Goal**). Default: every published page and post, the language of the site.

### 2. Read

- **Site visibility first:** `indexable` and `news_sitemap` of `leitvia/get-seo` (with any published ID), `<meta name="robots">` of the home page, `/robots.txt`, `/wp-sitemap.xml` and, with news, `/news.xml`: [site visibility](references/seo.md#site-visibility). A live site with `indexable` false is the first finding of the report, high; only the person changes it (checkpoint **Indexing**, with your proposal to turn indexing on).
- `leitvia/list-content` with `status: publish` and `limit: 100`, all pages of it: IDs, types, titles, excerpts.
- `leitvia/get-seo` with up to 50 IDs per call: `plugin`, per item `seo` (what the plugin stores; empty means its template), `defaults` (what WordPress prints, a description suggested from the excerpt) and `hash`.
- Public pages: open the most important ones and note `<title>`, `<meta name="description">`, `<link rel="canonical">`, `<meta name="robots">`, the `h1`, JSON-LD blocks, then `/robots.txt` and `/llms.txt`. What the plugin prints is what counts.

Keep this as the "before" of your report.

### 3. Judge

Per page: title missing, too long or the same as another page; description missing, too short, too long or duplicated; a page with `noindex` that looks like it should be found, or the other way round; canonical pointing elsewhere; no or several `h1`; the first paragraph does not say what the page offers. Details and limits: [titles and descriptions](references/seo.md#titles-and-descriptions).

### 4. Titles and descriptions

For each page that needs it, write a title (at most 60 characters) and a description (120 to 160, see the [text gates](../leitvia-page/references/text-gates.md)) from the text of the page, in its language: `leitvia/update-seo` with `post`, `title`, `description`, `base_hash` from `leitvia/get-seo` and the `package` key. Keep the brand name out of the title when the plugin adds it already (look at the public `<title>`). An empty string hands a field back to the template of the plugin.

### 5. Structure for search and AI answers

AI answers quote passages that stand on their own. Suggest, per page, what would make it clearer: a first paragraph that answers the main question, headings that name their section, lists for steps, the date and author on articles: [structure](references/seo.md#structure-for-ai-answers). Text changes go through `leitvia/update-content` in the same package, only with facts from the page or the person (checkpoint **New text**). Structured data comes from the SEO plugin or Leitvia Studio; you never write JSON-LD. What Studio prints and what `leitvia/set-page-structure` adds (hub, service, prices): [structure](references/seo.md#structure-for-ai-answers); set it only as the page states it. A rating goes in only through `aggregate_rating` of `leitvia/set-organization` with the source address, and `confirmed` true only after the person compared value and count with the source (checkpoint **Rating**); a star in a title must equal that value. Organization data, job postings and the anchors of a one-pager: [local business and jobs](references/local-business.md).

### 6. llms.txt and AI crawlers

Without an SEO plugin Leitvia Studio serves `/llms.txt` itself, built on each request from the site name, a summary (else the tagline), the hubs, the pages with a service and price and extra pages: [llms.txt](references/seo.md#llmstxt). Read `llms` of `leitvia/get-seo`, open `/llms.txt`, and propose `summary`, `pages` and `news` (latest posts) with `leitvia/set-llms` in your package. `requested` is what is saved, `enabled` what takes effect; `held_back` says why a requested file is not served (a site closed to search engines answers 404 until it opens, an SEO plugin serves its own). Report it, do not retry. robots.txt lets AI crawlers in by default; whether they may read the site is the person's decision (checkpoint **AI crawlers**): `ai_crawlers` false only on the person's word. Report what `robots.txt` says today.

## Verification

The person applies your package (its ID comes with every write) in the inbox (checkpoint **Package applied**). Then `leitvia/get-package` for conflicts, `leitvia/get-seo` and the public pages again: the "after" of your report.

## Failure modes / debugging

Every code with its retry rule: [rejections](../../handbook/rejections.md).

- `leitvia_invalid_input`, reason `unsupported_fact`: the title or description names a number the post does not hold; write it from the page.
- `leitvia_no_seo_plugin`: no plugin Studio can write to; list what you would write.
- `unsupported_fact` with a star rating: the title names a rating that is not the confirmed `aggregate_rating`; leave the star out or ask the person (checkpoint **Rating**).
- `leitvia_stale_hash`: the SEO data changed since `leitvia/get-seo`; read it again and repeat once.

## Report

In the chat, in the language of the person:

1. **Visibility:** whether search engines may index the site, and the sitemaps found; a hidden live site comes first.
2. **Changed:** per page the old and new title and description, live or waiting in the package (ID and inbox link).
3. **Suggested:** structure and text changes, Google News gaps, the `llms.txt` draft, an SEO plugin when none is active.
4. **For the person to decide:** indexing, AI crawlers, facts you lacked.

End with the scope, the SEO plugin and the date. Promise no rankings: search engines and AI services decide what they show.

## Escalation

Stop and ask the person at these checkpoints, then continue with the answer:

- **Goal:** pages and audience when the request does not say it.
- **Indexing:** any change of `noindex` or `nofollow`, and a site hidden from search engines.
- **New text:** a text change beyond title and description that needs facts the page does not hold.
- **AI crawlers:** whether AI crawlers may read the site.
- **Rating:** value, count and source of a rating, before `aggregate_rating` with `confirmed` true.
- **Package applied:** the person reviews and applies the package in the inbox.
- **Second rejection** of the same call, or a rejection with `retry: never`.

## Unattended

When no person answers (a scheduled or hosted run), do not wait at a checkpoint. Send the `package` key on every write, even when the briefing says nobody visits the site (mode `direct` needs a person who is present); never apply, roll back or publish anything. Take facts only from a source you name in the report (briefing, a page of the site, a read ability); without one the fact stays out and becomes an open question. A second rejection, or `retry: never`, stops that part. End with the package ID, what it holds and the open questions. Each checkpoint gets this answer:

| Checkpoint | Answer without a person |
|---|---|
| Goal | Every published page and post, the language of the site. |
| Indexing | Change nothing; list pages whose indexing looks wrong. A hidden live site goes first in the report, high, with the step of the person. |
| New text | Only titles and descriptions; list text suggestions in the report. |
| AI crawlers | Change nothing; report what robots.txt says. |
| Rating | No rating and no star; list the rating with its source as an open question. |
| Package applied | Stop after step 6; the check after belongs to the next run. |
| Second rejection | Stop that page; report code and message. |
