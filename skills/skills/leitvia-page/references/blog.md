# Blog or journal

Details for `leitvia-page`. Read it when the briefing asks for a blog, a journal or a news section. Posts themselves are written with `leitvia-news` or `leitvia/create-content`.

## What the theme already does

The blog needs no pattern of yours. The theme templates show:

- **Overview** (`home`): the h1 and lead of the blog, the sticky post as a wide band marked "Featured" on the first page, then ten posts per page with date, linked title, excerpt and a "Continue reading" link, and numbered pages with previous and next links. With a posts page set, the h1 is the title of that page, the lead its excerpt, and its featured image and content follow above the posts.
- **Category, tag and date archives** (`archive`): title, the description of the term, a grid of posts with images. **Author pages** (`author`): name, the biographical info of the profile, the posts.
- **Post page** (`single`): category, title, excerpt as lead (only one or two sentences that the text does not repeat), byline with author, dates and reading time, featured image, then the text in reading width with a sidebar beside it from 64rem (sticky): "On this page" from the h2 and h3 of the post, author, published and updated date, category and, when the post has a hub, a card for that service. Below 64rem the sidebar stands above the text and the card moves to the end. Tables and images with `align` `wide` take the width of the text column. Then tags, an author box, previous and next post, up to three related posts from the same categories, and comments only while they are open or exist.

## Set up a journal

1. Ask the person for the name ("Journal"), one sentence on what it covers, the categories with a description each, and whether readers may comment.
2. Create the page with `leitvia/create-content`: `post_type` `page`, `title` the name, `excerpt` the sentence. A featured image and content of the page show above the posts; content that only repeats the excerpt stays out.
3. The posts page is a reading setting. The recipe `publication` sets it with its page `news`; for any other site call [`leitvia/set-posts-page`](../../../handbook/abilities/leitvia-set-posts-page.md) with the ID of the page, once the page exists (a page that waits in a package needs that package applied first). It waits in a change package for a person, and it takes effect with a static front page (`leitvia/set-front-page`).
4. Link the page in the navigation with `leitvia/update-navigation`.
5. Posts get an `author` (user ID, as `author` in `leitvia/list-content` shows it) when a person named one. An author whose display name is still the login shows as first and last name, else as the name of the site, and has no author page; only the person can set the display name in the profile, so ask for it when the blog names its authors. Posts get one category each, `comment_status` `open` only when the person wants comments (a new post is closed for comments and pingbacks without it), a featured image with alt text (it appears on the archive grid and in the band of the sticky post) and an excerpt of one or two sentences. Category descriptions and the biographical info of authors are set by the person in WordPress; ask for them, an empty description leaves the archive without a lead.
6. On the home page, point to the journal with `leitvia/teaser-posts-list` or `leitvia/teaser-posts-grid`; their heading names the journal.

## Posts of one category or tag

For a page that leads into the newest posts of one topic, place or section, and for a home page with several of them:

- `leitvia/teaser-posts-trio`: heading and lead in one row, three tall picture teasers with date, title and excerpt, then a button "More from this category".
- `leitvia/teaser-posts-row`: a compact row of four small cards with the heading and a link "View all posts" in one line; several of them stack on one page.

The query of both lists the posts of the term in its `taxQuery`, such as `"taxQuery":{"category":[12]}` or `{"post_tag":[34]}` with term IDs. Without a term it shows the latest posts. The button or link has the binding `leitvia/query-archive`: it leads to the archive of the first term of the query, without a term to the posts page, so never set its `url`. The IDs come from `leitvia/list-content` with `terms_of` `category` or `post_tag` (and `limit` 1): `term_list` names each term with `id`, `slug`, `name`, `count` and `parent`. Place the pattern, then set the term on its `core/query` with `leitvia/update-block`, the block ID from `leitvia/get-page-outline`:

```json
{ "post": 52, "block": "p:3.1", "base_hash": "…",
  "attributes": { "query": { "taxQuery": { "category": [ 12 ] } } } }
```

`taxQuery` replaces the filters of the query as a whole, other keys of the query stay; `{}` removes them. The newer format of core works too: `{"include":{"category":[12]},"exclude":{"post_tag":[34]}}`. When the topic has no term yet, name it in your report instead of creating one. Rename the heading to the topic, such as "News from the harbour".

**Portals with pages per place or topic:** when pages, not only posts, belong to a tag (a city portal with a page per district), `leitvia/set-permalinks` with `page_tags` `true` gives pages the tags of posts; it waits in a change package. A tag archive then lists posts and pages, and the query of a teaser pattern still lists only posts. Off by default; turn it on only when the person wants pages in tag archives.

## News section

A blog with news for Google News and AI answers needs a few things more; `leitvia-news` and `leitvia-seo-geo` check them.

1. **Editorial page:** a page with `leitvia/create-content`, `kind` `editorial`: who writes, how sources are checked, how corrections and AI texts are handled, all from the person. While it is the only published page of that kind, Studio takes it as the editorial page; with several, set one with `editorial_page` of `leitvia/set-organization` (a person applies the package). Studio then links it in the author box and names it as `publishingPrinciples` in the structured data.
2. **Categories:** two to six, each with a description. The overview shows them as chips; more than six make the row hard to scan, one makes it useless. From thirteen categories on, the theme keeps the twelve with the most posts and the current one in the row, on the overview and on every category archive alike; the rest stay reachable on the posts. A category archive is titled with the name of the category alone (no "Category:" in front) and shows the description below the title as the intro of the topic: write it with `leitvia/update-term` (IDs and hashes from `terms_of` of `leitvia/list-content`), headings from h2 down, since the title is the h1. The theme shows an h1 of a description as an h2, and `leitvia/audit-site` reports it (`leitvia/term-heading`).
3. **Featured images:** every post has one, at least 1200 px wide, fit for a 16:9 crop, with alt text. The newest post leads the card grid with it.
4. **Authors:** real names with a biographical info in the profile, set by the person. The author box and the author page show it; the website of the profile becomes `sameAs` of the Person. A post by someone without an account takes `author_name` and `author_page` (ID of a published page about that person, such as the team page) in `leitvia/create-content`, `update-content` or `import-post`: the name links there and the NewsArticle names it as `url`, which Google News asks for.
5. **Service of a post:** `leitvia/set-page-structure` with `post` (a post) and `hub` (the page of the service the post is about) shows a card "Related service" with a button beside the post. Entries with a page of their own, such as documentation pages and the types of the content model, take `hub` the same way and show the card at the end of the entry. A post or entry takes only `hub`; for many posts give each call the same `package`, so they land in one change package.
6. **News of a page:** A service or topic page points to its news with the pattern `leitvia/teaser-related-news` (`provides` `related`): three cards of the latest posts with one of the categories or tags the page names in `news` of `leitvia/set-page-structure` (`{"categories":["hosting"],"tags":["backup"]}`, IDs or slugs), never the page itself. Without `news`, or when no post has those terms, it shows the posts with this page as `hub`, then the posts in a category or tag with the slug of the page or of its line of offerings, and only then the latest posts of the site. Set `news` on every page with the pattern: `leitvia/audit-site` (`leitvia/related-news`) and `leitvia/check-composition` (`newsTopic`, no points) name each page without a topic and say what it shows instead.
7. **Imprint and contact** pages exist and are linked in the footer.
8. **Visible to search engines:** in Settings, Reading, "Discourage search engines" is unticked once the site is live, a step of the person.

Check the result on the public URLs: the overview, one category archive, one author page and one post, each with one h1.
