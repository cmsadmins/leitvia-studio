---
name: leitvia-page
description: Compose a page on a WordPress site with the Leitvia theme and Leitvia Studio from a goal and content points. Plans the outline from the page frames of the design contract, picks patterns by their composition metadata, fills every text, link and image, scores the composition and builds the page through the Leitvia abilities. Use when someone asks for a new landing page, service page, about page, contact page or any page built from sections.
compatibility: WordPress 7.1+, PHP 8.2+. Needs the theme Leitvia and the plugin Leitvia Studio, reached over the MCP server leitvia (application password or OAuth token) or the REST routes of the Abilities API (application password only).
---

# leitvia-page

You turn a goal and a few content points into a page that reads as designed: a clear opening, sections that alternate in tone and layout, one accent at most, real texts and real links. You build it only through the Leitvia abilities.

## When to use

- A new landing, service, about or contact page, or any page built from sections; also entries of a content type.
- Not for a whole site (leitvia-setup), news posts (leitvia-news), a section no pattern covers (leitvia-pattern) or texts of existing pages for search (leitvia-seo-geo).

## Inputs required

- Access as the agent user: [handbook, Access over MCP and REST](../../handbook/README.md#access-over-mcp-and-rest).
- The brief of step 1.
- Read before the first call: the [agent handbook](../../handbook/README.md). Read the page of each ability before you first call it (the handbook index links them): `get-contract`, `list-patterns`, `plan-page`, `check-composition`, `get-pattern`, `add-media`, `compose-page`, `get-page-outline`, `insert-section`, `audit-site`. Composition, templates, edits, later changes: [composition details](references/composition.md); several photos of people, trips or groups: [photo collage](references/photo-collage.md). Texts, links, images: [content rules](references/content.md). Entries of a content type and their overview: [entries](references/entries.md). A blog or journal: [blog](references/blog.md). Effects on scroll come with the patterns, one motion per site (`set-motion`): [animation](../leitvia-pattern/references/animation.md). Businesses with a history, sub-brands, samples: [business pages](references/business.md).

## Modes

- **direct:** The autonomy levels of the site decide. With the default levels a new page is created as a draft; the person publishes it (no ability does), or the owner sets the area `content` to `autonomous`. Only with a person present. Send no `package` key: a key always means a package.
- **package:** send the same `package` key on every write of the task (for example `landing-bike-repair`), so a person reviews one change package in the inbox of Leitvia Studio. Use it on live sites unless the person asked for direct work. With a key nothing goes live, whatever the autonomy levels allow.

The result of every write says what happened in `mode`: `live`, `draft`, `package` or `unchanged`. Report it as it is; never claim a page is live when the result says `draft` or `package`.

## Ground rules

- Follow the [rules for every task](../../handbook/README.md#rules-for-every-task).
- Custom blocks go into a plugin from `leitvia-block-starter` (WordPress skill `wp-block-development`), never into a theme.
- **Facts are never guessed.** Organization data, prices, legal texts, figures, quotes and people come only from a source you can name: the person, a page of the site or a read ability, with the source in your report. Without one, stop at a checkpoint; never write a placeholder that looks like a fact.
- Here that covers prices, legal texts (imprint, privacy, terms, withdrawal, accessibility statement), company name, address, phone, e-mail, register numbers, opening hours, figures, testimonials and team members. Missing facts are a visible question in your report, not a placeholder on the page.
- Blocks that keep their content in HTML, steps, image sizes, rows, sections and a section under every post: [page rules](references/page-rules.md); icon per entry: [icon sections](references/icon-sections.md). Fonts, alignment and italics per edit, menu links: [block edits](references/block-edits.md); marquee and leftover sample text: [page rules](references/page-rules.md).
- Texts of about 1500 words or more: [long texts](references/long-texts.md).
- **A design that differs from the pattern:** pattern, block style or edit first; a difference of look alone is a site style finding for the child theme, never CSS of yours: [deviations](references/deviations.md).
- Every text you write passes the anti-AI gate and the SEO and GEO gate before you send it: [text gates](references/text-gates.md).
- Replace every sample text of every pattern you use, and never fill a slot with an assumption about the business ([content rules](references/content.md#texts)).

## Procedure

### 1. Clarify the brief

Collect, before any call: the goal of the page (what should a visitor do), the content points, the audience, the language of the page, the links the buttons should lead to, and the images you may use. Facts you do not have stay open questions. Then read:

- `leitvia/get-contract`: `contract.pageFrames` (frame types, their template, slots and the section kinds they require or recommend), `contract.sectionKinds`, `contract.compositionRules`, `contract.templates`, `contract.roles` and `contract.blockStyles`.
- `leitvia/list-patterns`: filter by `role`, `tone`, `layout` or `provides` (a section kind such as `faq`); each item has `composition`, `images`, `useWhen`, `avoidWhen`, `provides`, `continues`, `status`, `replacedBy` and `successor` (the newer form of an older pattern that stays approved, [sections with icons](references/icon-sections.md)). Use only patterns with `status` `approved` ([why](../../handbook/README.md#read-before-write)); with the tool page up to `pages` (20 per page); over MCP the resource `leitvia://patterns` holds every page.
- [`leitvia/list-building-blocks`](../../handbook/abilities/leitvia-list-building-blocks.md): a pattern in `patterns` of a block with `installed: false` shows nothing until the block is on the site. Add just that block with [`leitvia/add-building-block`](../../handbook/abilities/leitvia-add-building-block.md) (`name`, your `package` key; sample entries come as drafts) once the person agreed, never a whole recipe: a recipe also replaces design, header and front page.

### 2. Plan the outline

Call `leitvia/plan-page` with `goal`, `points` and `frame` (`home`, `service`, `hub`, `audience`, `about`, `contact`, `article`). A hub is the overview of a topic, an audience page is for one group of people; both have sections they require: [page frames with required sections](references/composition.md#page-frames-with-required-sections). Without an AI connector the plan comes from the rules of the contract (`source: rules`) and is the same for the same input; that is normal, not a fault. The rules leave out patterns that need images while the media library has none, post teasers below three posts, and proof or offer patterns, which carry facts of the person; on a shop the home plan uses the shop patterns. Leave `ai` false unless the person wants the goal and points sent to the AI provider the site owner set up.

The plan gives per section the pattern, its role, its slot, text lengths (`length.heading`, `length.text` in characters) and the points it carries. Treat it as a first outline, not as the answer. Replace a planned section when:

- it needs facts nobody gave you ([patterns that need facts](references/composition.md#patterns-that-need-facts));
- it has texts you cannot replace, for example a caption;
- its `avoidWhen` matches your content, or it needs an image you do not have;
- visitors should write from the page: [contact form](references/contact-form.md).

Pick the replacement with the same `role` from `leitvia/list-patterns`, and prefer the one whose `tone` and `layout` differ from both neighbors. Show the outline (section, pattern, what it says) to the person before you build: checkpoint **Outline confirmed**.

### 3. Score before you build

Call `leitvia/check-composition` with the pattern slugs in page order, the `template` and the `frame`. The rules and the scoring: [composition details](references/composition.md#rules). `min_score` (80 by default) is the score below which a write is held for a person.

Below `min_score`: revise the outline **once**. Use `suggestions`: each names the section index and up to three patterns of the same role that remove the finding (`replace_with`); take the first one whose needs you can meet (an image, facts). Score the revised outline again. Score two outlines, the first and the revision, not a series of variants in search of a higher score; a revision at or above `min_score` is the outline you show. Still below `min_score`: stop and show the person both outlines with their findings. Never pad a page with sections it does not need to raise the score.

### 4. Fill the sections

For each section call `leitvia/get-pattern` and read its markup. Write one edit per block by path ID with `text`, `url`, `image`, or `remove` and `repeat` for fewer or more entries than the pattern ([edits](references/composition.md#edits)), following the [content rules](references/content.md) for texts, links and images.

### 5. Build the page

Call `leitvia/compose-page` with `title`, `frame`, `sections` (`pattern` and `edits` each), `base_hash: ""`, an optional `slug`, and `package`. A page that exists and should get new sections throughout: the same call with `post` and the `hash` of `leitvia/get-page-outline` as `base_hash` replaces all its sections in one step, and the checks see only the finished page; never rebuild it with many `insert-section` and `remove-section` calls, the state in between (two h1) is refused. `template` only when the person asked for another page template of the contract (for example `page-docs`); `kind` only for legal, editorial, contact, authors or about pages. Each section keeps `metadata.patternName`, so the composition can be checked later. Exactly one h1 per page; which template prints the title: [templates and the h1](references/composition.md#templates-and-the-h1).

A post or an entry of a post type of the content model is no page: `leitvia/compose-page` creates pages, and posts only with the frame `article` (`type` post, or page for the template `page-article`). For other entries, [`leitvia/create-content`](../../handbook/abilities/leitvia-create-content.md) takes `post_type`, the `sections` of the outline (or `content` as block markup), `terms` and `fields`, with the same `package` key. Entry pages with their fields and the overview page of a type: [entries](references/entries.md). Products come from `leitvia/create-product`.

## Verification

- `leitvia/check-composition` with `post` set to the new page: the score must match the score of the outline.
- `leitvia/get-page-outline` with `post`: status, template and the sections.
- `leitvia/audit-site` for published pages: pass the ID before the page as `after` and `limit: 1`, or page through with `next`. The page needs no findings.
- When the page is live, run the [page check](../leitvia-a11y/references/page-check.md) on its URL: 1920 to 320 px, axe, other hosts.

## Failure modes / debugging

Every code with its retry rule: [rejections](../../handbook/rejections.md).

- `leitvia_pattern_not_approved`: a Lab or `unreviewed` pattern waits for approval; take an approved one of the same role.
- `leitvia_unknown_pattern` or `leitvia_not_found`: slug or path ID is wrong; read `leitvia/list-patterns` or the markup again.
- `leitvia_check_failed` with `leitvia/bindings`, or a bound block shows its saved text: [bindings](references/entries.md#values-of-fields-on-the-front-end).
- `leitvia_unsafe_content`: scripts, Custom HTML or Base64 in an edit; use registered blocks and media of the library.
- Two `h1` or none on the page: the template prints the title or not ([templates and the h1](references/composition.md#templates-and-the-h1)).

## Report

Page ID, URL, `mode` of the write, score, open findings, the open questions from your texts and the package ID if there is one. Later changes to the page: [after the page exists](references/composition.md#after-the-page-exists).

## Escalation

Stop and ask the person at these checkpoints, then continue with the answer:

- **Brief unclear:** goal, audience, language or link targets are missing.
- **Outline confirmed:** show the sections with pattern and content before `leitvia/compose-page`.
- **Facts from the person:** prices, legal texts, organization data, figures, quotes and team members. The pipeline also holds writes of the content kinds `prices`, `legal` and `organization` at least as suggestions for a person.
- **Score still too low:** below `min_score` after the one revision.
- **Second rejection** of the same call, or a rejection with `retry: never`.
- **Held for a person:** the result says `package` or `draft` and the person expected a live page. Give the package ID; a person applies it in the inbox.

## Unattended

When no person answers (a scheduled or hosted run), do not wait at a checkpoint. Send the `package` key on every write, even when the briefing says nobody visits the site (mode `direct` needs a person who is present); never apply, roll back or publish anything. Take facts only from a source you name in the report (briefing, a page of the site, a read ability); without one the fact stays out and becomes an open question. A second rejection, or `retry: never`, stops that part. End with the package ID, what it holds and the open questions. Each checkpoint gets this answer:

| Checkpoint | Answer without a person |
|---|---|
| Brief unclear | No goal in the briefing: stop. Missing link targets: leave the button out or link a page of the site the briefing names. Language: the language of the site from `leitvia/get-contract`. |
| Outline confirmed | Build the outline at or above `min_score` without waiting; list it in the report. |
| Facts from the person | Choose patterns without the missing facts; list each missing fact as a question. On an invented site the briefing allows invented facts, except operator and legal facts. |
| Score still too low | Build nothing; report both outlines with their findings. |
| Second rejection | Build nothing for this page; report code and message. |
| Held for a person | Expected: the page waits in the package; report its ID. |
| New images | An image added with `leitvia/add-media` waits in the package and has no ID before a person applies it: choose a pattern without an image slot, or leave the page for the next run. |
| New content type | Entries of a type that waits in the package come in the next run, after a person applied it. |

Steps only a person can do go into the report as a list: [new-site, steps of the person](../../workflows/new-site.md#steps-of-the-person). A briefing that asks for an invented site is the source of its invented content: [invented sites](../../workflows/new-site.md#invented-sites).
