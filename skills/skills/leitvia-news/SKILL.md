---
name: leitvia-news
description: Writes a regular news post for the blog of a WordPress site with the Leitvia theme and Leitvia Studio, for example once a week, through the leitvia abilities only. Takes topics and sources from a news brief of the operator, checks candidates against the posts the site already has, writes the post from sources it lists under the post, makes it fit for search, Google News and AI answers (SEO and GEO: title, description, first paragraph, internal links, image, a site visible to search engines), marks it as written by AI and leaves it as a draft in a change package for a person. Use it for scheduled news runs or when someone asks for a news post from given sources; pages belong to leitvia-page.
compatibility: WordPress 7.1+, PHP 8.2+. Needs the theme Leitvia and the plugin Leitvia Studio, reached over the MCP server leitvia (application password or OAuth token) or the REST routes of the Abilities API (application password only).
---

# leitvia-news

One run, at most one post: find what is new in the sources of the brief, drop what the site already covered, write a short post with its sources and an AI notice, make it fit for search and AI answers, and leave it as a draft in a change package. Nothing is published by this skill.

## When to use

- A regular news post from the sources of a news brief, as a draft for a person.
- Not for pages (leitvia-page), SEO of existing pages (leitvia-seo-geo) or setting up the blog (leitvia-setup).

## Inputs required

- The news brief of the operator (step 1).
- Read before the first call: the [agent handbook](../../handbook/README.md). Read the page of each ability before you first call it (the handbook index links them): `get-contract`, `list-content`, `create-content`, `get-seo`, `update-seo`, `list-packages`, `get-package`. The brief, the duplicate check, the sources, the post format and the limits for SEO and GEO: [news details](references/brief.md). Overview, archives and post page come from the theme; setting up the blog itself: [blog or journal](../leitvia-page/references/blog.md).

## Modes

- **package** (the only mode of this skill): one key per run, for example `news-2026-43` (year and week). The post is created as a draft in that package; a person reviews, publishes or rejects it in the inbox.

## Ground rules

- Reading the sources in the brief is fine.
- In a news post the source of every fact is a source item of the brief, listed under the post. Quotes are short, marked and attributed; the rest is your own wording.
- At most one post per run, and never one that covers what the site already has (duplicate check).
- Never publish or schedule, never delete existing posts and never change them beyond the SEO title and description of step 5.

## Procedure

### 1. Read the brief

Read the news brief of the operator: topics, sources, language, cadence and the optional fields ([news brief](references/brief.md#news-brief)). Missing topics or sources: checkpoint **Brief**.

### 2. Read the site

- `leitvia/get-contract`: `site.url` and `site.language`.
- Existing posts and waiting drafts for the duplicate check, and the date of the latest post: [duplicate check](references/brief.md#duplicate-check).
- When the latest news post is younger than the cadence of the brief allows (a weekly brief and a news post from three days ago), write nothing and report it. A news post is a post in the category of the brief, or, without a category, a post with a list of sources; the sample post of WordPress and other posts do not count.

### 3. Collect candidates

Read the sources of the brief and note items newer than the latest post that fit a topic of the brief, each with URL, title, publisher and date ([sources](references/brief.md#sources)). Drop duplicates. Pick the one candidate that matters most to the audience of the brief; with none left, write nothing and report why.

### 4. Write the post

Write title, excerpt and content as block markup following the [post format](references/brief.md#post-format): the news first, short paragraphs, a list of sources with links. Check every fact against its source item once more, and the post against the [text gates](../leitvia-page/references/text-gates.md) (anti-AI, SEO and GEO), before you write. A post of about 1500 words or more gets the form of [long texts](../leitvia-page/references/long-texts.md): table of contents, fact box, a heading at least every 300 words, reading breaks with the author tip, many images in a scroll gallery.

### 5. SEO and GEO

Required for every post; limits and Google News rules: [SEO and GEO](references/brief.md#seo-and-geo).

- `leitvia/get-seo` with the ID of the latest news post of step 2 (without one, any published page of `leitvia/list-content`; `indexable` is a site value): `indexable` false means the site is hidden from search engines (checkpoint **Site hidden from search**); `plugin` says whether an SEO plugin is active.
- Title and excerpt within the limits: they are the SEO title and description of the draft, which has no ID until the package is applied. Published news posts of earlier runs with an empty `seo.description` get title and description through `leitvia/update-seo` and the same `package` key, at most five per run.
- A first paragraph that answers who, what, when, where and why; h2 that name their section; two internal links, `link` from `leitvia/list-content`; a featured image of at least 1200 px width with alt text, or an open point in the report.

### 6. Create the draft

Call `leitvia/create-content` with `post_type` `post` (or the type of the content model the brief names, with its `fields`), `title`, `content`, `excerpt`, `slug`, `terms` (category and tags from the brief by name; new ones are created with the post, in a package when it is applied), `status` `draft`, `ai_generated` true (Studio shows readers that AI wrote it), `sources` (the URLs), `featured_image` when step 5 found one, empty `base_hash` and the `package` key. A user who may not create the category (`leitvia_forbidden`, reason `capability`): send the call once more without it and name the category in the report.

## Verification

- `leitvia/get-package` with the `package` ID of your writes: the package holds the draft and the SEO writes of step 5, nothing else.
- Open the preview link of the package from `leitvia/list-packages`: the post shows date and author, and its JSON-LD has `@type` `NewsArticle`, or `BlogPosting` when `news_categories` of Studio leaves out the category of the post. Anything else goes into the report.

## Failure modes / debugging

- `leitvia_check_failed`: the featured image needs alt text; set it with `leitvia/set-alt-text` or mark the image as decorative.
- `leitvia_out_of_scope`: a status other than `draft`; this skill never sets one.

## Report

A hidden site first, as high. Then package ID, Inbox link `/wp-admin/admin.php?page=leitvia-studio-agents&tab=inbox&package=<id>`, title, sources, the duplicates you dropped with the post they match, the SEO title and description, and open questions (image, SEO plugin, editorial page).

## Escalation

Stop and ask the person at these checkpoints, then continue with the answer:

- **Brief:** topics or sources are missing, or a source asks for a login.
- **No news:** every candidate is a duplicate, or the cadence says it is too early. Report it; nothing to write.
- **Facts from the person:** a fact the post needs is in no source item.
- **Site hidden from search:** `indexable` is false ("Discourage search engines" in the reading settings). Only the person turns it off.
- **Package applied:** a person reviews the draft, publishes or rejects it.

## Unattended

| Checkpoint | Answer without a person |
|---|---|
| Brief | Stop; report what the brief lacks. |
| No news | Write nothing; report the candidates and why each was dropped. |
| Facts from the person | Leave the fact out; when the post makes no sense without it, write nothing. |
| Site hidden from search | Write the draft all the same; name it first in the report, as high, with the step of the person: Settings, Reading, untick "Discourage search engines". |
| Package applied | Expected: the draft waits in the package; the run ends with the report. |
