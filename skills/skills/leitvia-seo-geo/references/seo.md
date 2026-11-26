# SEO and GEO details

Load this file when the skill names it. It holds the rules behind steps 2 to 6 of [leitvia-seo-geo](../SKILL.md).

## Site visibility

Check this before any page. A site that tells search engines to stay away gains nothing from good titles.

| Check | Where | Finding |
|---|---|---|
| Indexing | `indexable` of `leitvia/get-seo` (false when "Discourage search engines" is ticked in Settings, Reading) | false on a live site: high |
| Robots of the home page | `<meta name="robots">` of the public home page | `noindex`: high |
| robots.txt | `/robots.txt` | `Disallow: /` for all crawlers: high; no `Sitemap:` line: low |
| Sitemap | `/wp-sitemap.xml` or the sitemap of the SEO plugin | missing or empty: medium. With `indexable` false WordPress serves no sitemap and no `Sitemap:` line: no finding of its own, it comes back with indexing |
| News sitemap | `news_sitemap` and `news_sitemap_held_back` of `leitvia/get-seo`, `/news.xml` | missing on a site with news: medium. With `indexable` false (`news_sitemap_held_back` says why) no finding of its own, as for the sitemap |

`leitvia/update-seo` refuses a title over 60 characters (the front page 75, for the name of the site and its claim) and a description under 50 or over 160 characters; the rejection names the limit. Shorten a text from the source to fit before the call and say so in the report; never leave a page without its lines because the source was too long. After `leitvia/create-content` with `seo`, read `leitvia/get-seo` to see what was stored.

Only the person changes indexing, in Settings, Reading. Propose it plainly: "The site is live but asks search engines not to index it. Untick 'Discourage search engines' in Settings, Reading." A site that is not live yet may stay hidden; say so and move on.

## Titles and descriptions

| Field | Rule |
|---|---|
| Title | At most 60 characters, the main topic first, unique on the site. Name what the page offers, not "Home" or "Welcome". An SEO plugin usually adds the site name; do not add it twice. Without one the title goes out as written. The front page may join name and claim up to 75 characters (`Name | Claim`); without a stored title it shows the site title and the tagline of `leitvia/set-site-identity` joined by ` | `. |
| Description | 120 to 160 characters, one or two full sentences that say what a visitor finds and why it helps. Unique per page. No list of keywords, no quotation marks around the whole text. |
| Language | The language of the page. A page in German gets a German title, whatever the language of the person. |
| Numbers | Only numbers the page holds: prices, years, counts. `leitvia/update-seo` rejects the others with `unsupported_fact`. |

Search engines may still show another text when theirs fits a search better; a good description makes that rarer, never impossible.

Open Graph (the preview of a shared link): `leitvia/update-seo` writes `og_title` (at most 90 characters), `og_description` (at most 200) and `og_image` (image ID, 0 hands it back), only where the preview should differ from SEO texts and featured image. Without an SEO plugin Studio prints Open Graph and `twitter:card` on every page, falling back to SEO title and description, featured image, then logo or site icon; suggest a featured image for pages people share.

Fields `leitvia/get-seo` reads but `leitvia/update-seo` does not write: canonical, focus keyword and schema type. Report problems with them for the person to fix in the SEO plugin. Without an SEO plugin Leitvia Studio prints the canonical of archives. A canonical that points to another page is often deliberate (a copy of a page); ask before you call it a mistake.

The archive of a content type (such as `/docs/`) takes title and description with `archive` (the type key) in place of `post` and an empty `base_hash`, without an SEO plugin only. The 404 page and the other archives (categories, tags, authors) have no post for `leitvia/update-seo`. WordPress builds their titles from the name of the archive or "Page not found" and the site name; the 404 page is never indexed. Report a wish for other titles there as a setting of the SEO plugin for the person. The visible heading of the 404 page comes from the template `404` (`leitvia/update-template`).

Common findings and what to do:

| Finding | Do |
|---|---|
| Two pages with the same title | Give each the topic that sets it apart. |
| Description empty | Write one from the first paragraph; the plugin would otherwise take any text of the page. |
| Description from `defaults` only (no plugin) | List it as a suggestion; WordPress prints no meta description by itself. |
| `noindex` on a page that should be found | Checkpoint Indexing; never change it on your own. |
| Legal page, search page or thank-you page indexed | Same checkpoint; many sites keep them out of search. |

## Structure for AI answers

AI search (Google AI Overviews, ChatGPT search, Perplexity, Copilot) quotes short passages that make sense alone. What helps, all of it also good for people:

- The first paragraph answers the main question of the page in two or three sentences: who offers what, where, for whom.
- Headings name their section ("Prices for a bike service", not "More"). One `h1`, then `h2` and `h3` in order.
- Steps as numbered lists, comparisons as tables, facts with their unit and date.
- Articles show their date and author; keep both visible.
- Names stay the same everywhere: the organization, products and places as on the imprint.
- Questions people ask may become headings, answered right below, when the page really answers them. No invented FAQ.

Structured data: with an SEO plugin, the plugin prints it (its settings decide the types). Without one, Leitvia Studio prints, when its setting for structured data is on: `WebSite` with the organization on the front page, `BlogPosting` or `NewsArticle` with the organization as publisher and `BreadcrumbList` on posts (the author is the organization when the account of the author carries its name, else a `Person`), `BreadcrumbList` on every page that shows breadcrumbs, with the same items as on screen, `CollectionPage` on the posts page and on archives, `ProfilePage` on author pages and the page of a person, `AboutPage` and `ContactPage` by `kind` (`about`, `contact`), `Service` or, with `service.type` `software`, `SoftwareApplication` (`application_category`, `operating_system`, `download_url`), and `FAQPage` from the FAQ block; `defaults.schema` of `leitvia/get-seo` names what a post gets. On the front page the organization is `Organization` or a subtype of `LocalBusiness` with its details, which `organization` of `leitvia/get-seo` shows: [local business and jobs](local-business.md). Per page `leitvia/set-page-structure` sets the hub (breadcrumbs Home, hub of the hub, hub, page; the hub wins over a parent page) and a service with its price as `Service` and `Offer` (net with `vat_included` false; a range with `max_price`; packages with `offers`, one `Offer` each in an `AggregateOffer`, a package with a range its own `max_price`; `highPrice` is the top of all). With an SEO plugin Studio prints none of this. Never add JSON-LD in content blocks; a type of its own for entries (a `Service`) is code of a plugin on the filter `leitvia_studio_schema_nodes`.

People: `people` of `leitvia/set-organization` (profiles with `name`, `job_title`, `description`, `image`, `same_as`, `knows_about`, `page`) gives founder, authors of the same name and the `ProfilePage` on `page` one `Person` `@id`. A person with a `page` gets the `ProfilePage` there only; an `author_page` of a post that names another page (a booking page) adds none. Only what the site says about the person. No profile for founder or news authors: low, medium on a news site.

## Google News

Google decides which sites appear in Google News; the person applies in the Google Publisher Center. What the site can do:

- Structured data `NewsArticle` on news posts, from Leitvia Studio or the SEO plugin; `schema_type` of `leitvia/get-seo` shows it per post.
- The news sitemap `/news.xml` with the posts of the last 48 hours, listed in `robots.txt`.
- Transparency pages: imprint, contact, an editorial page (who writes, how sources are checked, how corrections work) and author pages with a biographical info. Missing ones go into the report for `leitvia-page` and the person.
- A featured image of at least 1200 px width on every news post, and `max-image-preview:large` in the robots meta, so large previews may show. Most SEO plugins print it; report it when the public post lacks it.
- Visible date and author, a title without clickbait that matches the h1, no new date on old posts. Studio keeps "Updated" and `dateModified` when a save leaves title, excerpt and words alone (meta, markup, patterns).

## llms.txt

`llms.txt` is a proposed plain text file at the root of a site (`/llms.txt`) that tells language models what the site is and which pages matter. Not every AI service reads it; it costs little and harms nothing. Without an SEO plugin Leitvia Studio serves it on each request (no file): `# site name`, `> summary` (setting of `leitvia/set-llms`, else the tagline), then `## Topics` (pages with the page frame hub), `## Services and prices` (pages with a service and price of `leitvia/set-page-structure`) `## Pages` (the `pages` of `leitvia/set-llms`) and, with `news` above 0, `## Latest posts` (that many newest posts, at most 50), each a link with its excerpt (a post without one gets its first words). Keep the excerpts of these pages one quotable sentence. With an SEO plugin draft the file as below for the person, who puts it on the server or uses the plugin.

Draft from pages you read, in Markdown:

```
# Name of the organization

> One sentence: what the organization offers, where and for whom.

## Main pages

- [Services](https://example.org/services/): what is offered, with prices
- [About](https://example.org/about/): team and history

## Optional

- [News](https://example.org/news/): recent articles
```

Only published pages that should be found, each with a short note from its description. No pages with `noindex`, no private data.

## AI crawlers

`robots.txt` decides which crawlers may read the site, AI crawlers included (for example `GPTBot`, `OAI-SearchBot`, `PerplexityBot`, `Google-Extended`, `ClaudeBot`). Blocking them keeps the site out of the answers of those services; allowing them lets the services use the texts. That is a decision of the person about their content. Report what `robots.txt` says today and what each choice means; change nothing. Paths the person wants closed to every crawler (a download folder, for example) go in `disallow` of `leitvia/set-llms`, each with a leading slash; Studio writes them into the group `User-agent: *`.
