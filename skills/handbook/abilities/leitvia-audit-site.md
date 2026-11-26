# leitvia/audit-site

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Audit site.** Runs the content checks (headings, alt text, link text, #anchor links, block validity, unregistered blocks, tokens, contrast, one h1 and landmarks) over published content, at most 20 posts per call in ID order. Also reports classic content without blocks, deprecated patterns, preset slugs missing from the active tokens, internal links that lead to no page or to the old address of a renamed post, links that lead nowhere (empty href, # without target) on the rendered page outside content and template parts, with their class, pages that look like legal texts without a page kind, placeholders left on legal pages (square brackets such as [Organization name] and sentences that say they are a placeholder), a page with the pattern Related news but no topic of its news (leitvia/related-news: it shows the posts with the page as hub, posts in a category or tag with the slug of the page, else the latest posts of the site), and uneven motion (leitvia/motion): leitviaMotion other than the site setting, fade and rise on one page, more than one kind of striking effect, more than 3 striking or 6 animated blocks. The first page (after 0) also returns site: an error when the site is closed to search engines outside a local or staging site, a warning when Gravity Forms loads a captcha from another host (a captcha add-on or a reCAPTCHA field), a warning for open or partly applied change packages older than a day (leitvia/stale-package), an error per header or footer part with such links, warnings for a missing imprint, contact or editorial page while there are posts, and for the latest 20 news posts without a featured image of at least 1200 px width or whose author has no biography. Pass next as after for the following page; next is null at the end.

MCP tool `leitvia-audit-site`, REST GET. Annotations: readonly true, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `after` | integer, min `0`, default `0` |  | Start after this post ID, the next value of the previous call. |
| `limit` | integer, min `1`, max `20`, default `10` |  | Posts per call. |

Output: `items` array of object; `next` integer or null; `site` array of object.
