# News brief, duplicate check and post format

Details for `leitvia-news`. Read "News brief" before the first run, "Duplicate check" before you choose a topic, "Post format" before you write and "SEO and GEO" before step 5.

## News brief

The operator keeps the brief in a file next to the task (for example `news-brief.md`) or names it in the task. The skill reads it on every run and never changes it.

| Field | Example | Required |
|---|---|---|
| Topics | "Repairs and maintenance of city bikes", "cycling in Leipzig" | yes |
| Sources | `https://example.org/feed/`, `https://city.example/press/` (feeds or pages) | yes |
| Language | `en-GB` | yes |
| Cadence | one post per week | yes |
| Audience and tone | owners of everyday bikes; plain, friendly, no hype | no |
| Category and tags | category `News`, tags from the topic | no |
| Length | 300 to 600 words | no |
| Avoid | competitors by name, prices, medical advice | no |
| Images | featured image, screenshots, video with script | no |

A topic or a source outside the brief is not used. A brief without topics or sources: stop (checkpoint **Brief**).

## Duplicate check

1. Existing posts: `leitvia/list-content` with `post_type` `post` (or the type of the brief), `status` `any` and `after` set to a date about a year back lists published posts, drafts and scheduled posts, newest first, with `title`, `date`, `excerpt`, `terms` and `package` (the open change package a draft waits in). Page with `page` while `pages` is larger. The first item with `status` `publish` in the news category gives the date of the latest news post. Drafts that wait only in a package as new posts do not exist yet: `leitvia/list-packages` with `state: open` and `leitvia/get-package` show their titles.
2. A candidate is a duplicate when an existing post or a waiting draft covers the same event or claim, even with other words: same source URL, same named event, product, date or figure. Compare the meaning, not only the title.
3. Duplicates are dropped and named in the report. When every candidate is a duplicate, write nothing in this run.

## Sources

- Read the source items of the brief that are newer than the last post of the site. Use only items you could open and read in full; a teaser or a headline alone is no source.
- Every fact of the post (names, dates, figures, quotes) comes from a source item you list under the post. A fact in no source stays out.
- Quote at most two short sentences per source, marked as a quote with the source named. The rest is your own wording; never copy paragraphs.
- Sources behind a paywall or login, and sources that forbid reuse, are not used.

## Post format

- Title: names the news, at most 60 characters, no clickbait, no question you do not answer. It is the h1 of the post page; the SEO title says the same.
- First paragraph: what happened, when, where, why it matters to the audience of the brief.
- Body: short paragraphs with h2 subheadings where the post is longer than 300 words; only core blocks (`core/paragraph`, `core/heading`, `core/list`, `core/quote`, `core/image` from the media library, `core/video`, `core/embed`).
- Sources: a heading "Sources" and a list with one link per source item (title of the item, publisher, date).
- AI notice: `ai_generated` true on `leitvia/create-content`. Studio then shows a note before the text that AI wrote it, in the language of the site; a person removes it after their own review by deleting the custom field `leitvia_ai_generated`. Do not add a notice of your own to the content.
- Excerpt: one or two sentences, 120 to 160 characters. It is the meta description of the post.
- Images only from the brief: images the operator may use; an image from a news source is never copied. Featured image: `featured_image`, with alt text. Screenshots: one or two real screens, no mockups, with alt text and caption. Video: only with its script, uploaded with `leitvia/add-media`; `core/video` with `poster`, `controls`, `preload` `none` and caption, never `autoplay`, then `core/details` "Transcript of the video", one list item per scene, as in `leitvia/content-video`.

Rules of Google News that the post and the site have to meet:

- Date and author are visible on the post page; the theme prints both. Never change the date of an older post to make it look new.
- A real author with a biographical info in the profile is better than an anonymous team account. When the posts appear under a team account, name it in the report as a step of the person. An author without an account: `author_name` plus `author_page` (ID of a page about them), the url of the Person. As biography the audit counts a published `author_page` or a `people` profile of the same name with `description`.
- A post about a service of the site: `leitvia/set-page-structure` with `post` and `hub` (the service page) shows a card beside it.
- One featured image per post is required: at least 1200 px wide, fit for a 16:9 crop, with alt text. Without one the post is written all the same and the image is an open point of the report.
- The site needs an editorial page (page kind `editorial`) that says who writes, how sources are checked and how corrections work. The author box of a post links it when Studio knows the page (the only page of that kind, or `editorial_page` of `leitvia/set-organization`); when the public post shows no such link, the report names it as open for `leitvia-page`.

## SEO and GEO

| Field | Limit |
|---|---|
| Title | At most 60 characters, the news first, the same text as the h1. No site name; an SEO plugin adds it. |
| Description (excerpt) | 120 to 160 characters, one or two full sentences from the post. Only numbers the post holds. |
| First paragraph | Two or three sentences, at most 60 words with a figure or date: who, what, when, where, why. It makes sense without the rest of the post, so AI answers can quote it. |
| Headings | Each h2 names its section ("New lane between Felsenkeller and Plagwitz", not "Details"). |
| Internal links | Two links in the text to pages or posts of the site on the same topic, `link` from `leitvia/list-content`. None fits: none, and say so in the report. |
| Image | Featured image of at least 1200 px width with alt text. |

`schema_type` of a published news post in `leitvia/get-seo` names its structured data: `NewsArticle`, or `BlogPosting` for posts outside the news categories set in Studio. `indexable` and `news_sitemap` are site settings of the person; report them, change nothing.
