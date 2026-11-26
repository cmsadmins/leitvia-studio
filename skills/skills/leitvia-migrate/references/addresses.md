# Addresses and redirects

Details for `leitvia-migrate` when addresses change. A migration keeps titles, slugs, parent pages and post dates; a slug change is a URL change and no part of it.

- **New addresses** (a relaunch with a new structure, on the person's word): every old path gets its permanent redirect with `leitvia/set-redirects` (rows of `from` and `to`, no loops), in the same package as the new pages. The list replaces the saved one (at most 1000 rows): read the current rows with `leitvia/get-seo` (`redirects`) first and keep them.
- **SEO plugin active:** the rows stay inactive while it runs; the redirects then belong in that plugin, an open item for the person.
- **Permalink structure** of the old site: `leitvia/set-permalinks` (`structure` such as `/news/%postname%/`, `category_base`, `tag_base`), before the posts are imported. It waits for a person, and its `notice` says when published posts move. Pages keep their addresses either way. A post that lay without the prefix on the old site gets a row from its old path.
- **Status:** dropped for good `"status": 410` without `to`; moved for a while `302`.
- **Old addresses with a query** (`/?p=45`) get that query in `from`. The row also matches requests with further parameters (such as `?von=`), which go along to `to`, and the row with the most parameters wins.
