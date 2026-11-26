# Source outside this site

Details for `leitvia-migrate` when the content comes from another site: another WordPress, a site builder such as Wix or Squarespace, or static HTML. The target already runs Leitvia and Leitvia Studio; there is no theme switch. You read the source only as a visitor would: its public pages over HTTPS, no login, no export, no API of the source.

A WordPress source (WPBakery, Impreza or plain HTML) goes faster with one call per post: [import a post](import-post.md).

## 1. Inventory of the source

- Start at the address the person named. Read `/sitemap.xml` (and the sitemaps it lists) when the source has one; otherwise follow the links of the main navigation and of the footer, on the same host only.
- Per page note: URL, title, the `h1`, the main content (inside `<main>` or the largest content container, without header, footer, cookie banner and navigation), every image with its `src` and `alt`, every link with its target, embeds (video, map, audio).
- Navigation: label, target and level of each item of the main menu.
- Count the words of the main content per page (text without tags, whitespace collapsed). This number goes into the reconciliation report.
- Never take over: scripts, tracking pixels, analytics and tag managers, chat and cookie widgets, web fonts of the source, forms that post to the source or a third party, inline `style`. List what you left out per page.

The person confirms the inventory (checkpoint **Inventory confirmed**). Pages behind a login, search results and generated archive pages are not part of it.

## 2. Map pages to content types

Read `leitvia/get-content-model`. A source page becomes a page; a page of a dated series (blog, news) becomes a post with its date, categories and tags; a page of a repeated kind with its own fields (team member, event, reference) becomes an entry of the post type of the content model that fits, with its fields and taxonomy terms. A kind of page no post type fits: ask whether `leitvia-setup` should add one with `leitvia/define-content-type`, or take it as a page.

Per source page one target entry: title from the `h1` (or the `<title>` without the site name), slug from the last path part of the source URL without an extension such as `.html`, in lower case with hyphens, so links to the old addresses can be redirected one to one. Two source pages with the same slug: ask. The home page of the source becomes the front page only when the person says so; otherwise a page `home`.

## 3. Images

- Download each image of the source once (a GET of a public file, like reading a page). Files over 5 MB, or formats other than JPEG, PNG, GIF and WebP, go to checkpoint **Open items**.
- Add it with `leitvia/add-media`: `data` (Base64), `filename`, `alt` and the `package` key. Encode in a short script that sends the request (REST with the application password, or `tools/call` of `leitvia-add-media` over MCP), so the Base64 never passes through your own output.
- Alt text: the `alt` of the source character for character when it describes the image; otherwise write one short sentence from what the image shows and why it is on the page, or mark it `decorative: true` when the text around it carries the meaning. List every alt text you wrote.
- Images are never loaded from the source host in the new content; only attachments of this site.
- An image with text burned in: once package A is applied, cut off the text band with `leitvia/crop-media` (the text goes into the content), see [images](translate.md#images).

## 4. Create the entries with their content

Every entry is created with its whole content in one `leitvia/create-content` call; images inside a text need their attachment ID and URL, so the images go first:

1. Run 1, package A: `leitvia/add-media` for every image. Use a file name that is no slug of a target entry (for `workshop.webp` next to a page `workshop`, send `workshop-photo.webp`): an attachment takes the slug of its file name, and an entry created later with the same slug gets `-2`. Checkpoint **Package applied**.
2. Run 2, package B: `leitvia/get-package` of package A gives per operation the `applied` post ID; `leitvia/list-media` with these IDs as `include` gives their `url` in one call. Per source page one `leitvia/create-content` with `post_type` from step 2, `title`, `slug`, `parent`, `status` `publish` (the package waits for the person anyway), `excerpt`, for posts `date` and `terms`, for entries `fields` and `terms`, `featured_image` with the `id` where the source has a lead image, and `content`: the main content translated with the [tag to block table](translate.md#tag-to-block) (the `h1` is the title, so the content starts at h2), images as `core/image` with the attachment `id` and its URL, embeds as `core/embed` (the consent placeholder comes from Studio). Compare before you write, as in [translate](translate.md#compare-before-you-write). Then `leitvia/update-navigation` with the menu of the source mapped to the new slugs. Checkpoint **Package applied**.

When no text holds an image (only lead images), one package is enough: `leitvia/add-media` first, then `leitvia/create-content` with `featured_image` `file` set to the file name of that image and the same `package` key; applying the package resolves it.

## 5. Reconciliation report

One row per source page, after the person applied package B; `leitvia/list-content` gives the new entries with ID, `link` and `status`:

| Source URL | Target page (ID, URL) | Words source | Words target | Images source | Images target | Left out |
|---|---|---|---|---|---|---|

Words target counts the text of the new content the same way as the source, and the text and link comparison of [wording stays, form is new](wording.md) runs per page before the person reviews package B. A difference other than 0 needs a reason in "Left out" (cookie text, a form, a script). Then the navigation (each source item and its new target) and every alt text you wrote.
