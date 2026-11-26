# Page check

Details for `leitvia-a11y`, used by every skill that checks public pages after a build: `leitvia-page`, `leitvia-migrate`, `leitvia-shop` and the workflows. The script [check-pages.mjs](../scripts/check-pages.mjs) checks many pages at once in one browser and writes one JSON report. It reads the public pages only, like a visitor, and writes nothing to the site.

## Run it

Once per project folder, with Node.js 20 or later, in a folder of its own with a `package.json`. Without one, npm installs into the next parent folder that has a `package.json`, which may be another project. Keep the folder out of Git (`tmp/` in `.gitignore`):

```sh
mkdir -p tmp/page-check
(cd tmp/page-check && npm init -y && npm install playwright axe-core && npx playwright install chromium)
```

Then, from the project folder (the path of the skills folder may differ in your agent), with `--modules` pointing to that folder:

```sh
node .claude/skills/leitvia-a11y/scripts/check-pages.mjs --modules tmp/page-check --site https://example.org --sitemap --out check.json --shots shots
```

- **Pages:** `--sitemap` takes every address of `/wp-sitemap.xml`; or a file `--pages pages.txt` with one path per line (`/about-us/`); or the paths after `--site`. In Git Bash a path that starts with `/` becomes a Windows path: use the file, or set `MSYS_NO_PATHCONV=1`.
- **Old site:** `--old https://old.example.org` compares each page with the same path on the old site (a migration keeps the URLs). A line `/new-path/ https://old.example.org/old-path/` in the pages file names another old page. `--old-selector` picks the content of the old page (default `main`, then `#content`, `.entry-content`, `body`), so sidebars of the old theme do not count.
- **Folder protection** of a development site: `LEITVIA_HTTP_AUTH="user:password"` in the environment, never on the command line.
- `--concurrency 4` pages at a time (default). More makes a small server slow and the timing checks unreliable.
- Subdomains of the site count as its own hosts. `--allow <host>` for each other host the person allowed, for example the host of a payment provider on the checkout only.
- `--shots <folder>` saves JPEG screenshots at 1920, 1440 and 390 px of each page with a finding; `--all-shots` of every page. Look at them before you report.
- `--modules <folder>`: the folder where playwright and axe-core are installed (see above), relative to the project folder or absolute.

Twelve pages take about 20 seconds; the same checks one page and one width at a time took about four minutes. Exit code 1: at least one page has a finding or an `error` (not loaded). Exit code 2: the run did not start (no pages, sitemap or pages file not readable, no browser) and wrote no report.

## What it checks

Each page loads once with reduced motion, scrolls through once (lazy images, embeds), then the same tab takes the widths 1920, 1440, 390 and 320 px. `findings` in the report holds per page only what it found, each line starting with the width:

| Finding | Width | What it means |
|---|---|---|
| `overflow` | all | The page is wider than the window (it scrolls sideways or cuts content off), with the innermost elements that stick out or whose text runs out of its box (a long word, an address, a button). WCAG 1.4.10 at 320. |
| `squeezed` | 1920, 1440 | A paragraph or list item in `main` narrower than 20 characters or with fewer than 25 characters per line, or a heading with fewer than 12 characters per line: a text column squeezed by a sidebar, a column or a padding. Large headings with 15 to 20 characters per line are normal and do not count, nor do form fields (a paragraph or item holding an input, select or textarea). |
| `axe` | 1440, 390 | Rules of axe-core for WCAG 2.2 A and AA, with the number of elements and up to three selectors. |
| `foreignHosts` | | Requests to other hosts before any consent. |
| `httpErrors`, `brokenImages` | | Answers 400 and above, images that did not load. |
| `h1` | | Not exactly one h1. |
| `redirect` | | The page ended at another address (a login, another host). |
| `shortcodes` | | Text such as `[vc_row]` or `[/x]` in the content: a shortcode no plugin renders. A bare word such as `[date]` is a placeholder of a pattern and no finding; replace it before going live. |
| `words`, `links` | | With an old page: words (with how many are missing) and link destinations of the old page that the new one lacks. Text of closed details blocks and hidden tab panels (product tabs) counts on both pages, and so do all parts of a post split into parts (the links of its page numbers). A link of the old page to an address of the old site that redirects counts by its destination. `old` when the old page or one of its parts did not answer 200 or not at all; the new page is still checked. |
| `parts` | | A part of the new page (post split into parts) that did not load; its text is not in the comparison. |

Why 1920: some faults show only on wide screens, for example a block with full width inside a column that takes the padding of the window and squeezes its text. At 1440 they are hard to see.

## What to do with a finding

- `squeezed`, `overflow`: find the block on the page. A block with `align: full` that is not on the top level of the content, a column too narrow for its text or a fixed width are yours to fix with the abilities. A fault in a template, a pattern or the theme is a finding for the product: report it with page, width and the line of the report, never work around it in the content.
- `foreignHosts`: every host must have a consent placeholder or an entry the person approved. Plugins for payment, reviews or marketing often load scripts of their provider on every page as soon as they are active, even before an account is connected (a payment SDK on product pages). Before a site goes live, list each host with the plugin that loads it; the operator holds the script back with the consent filter ([scripts of plugins from other providers](../../../workflows/new-site-operator.md#scripts-of-plugins-from-other-providers)) or the person turns the plugin off.
- `axe`: fix what the abilities fix ([fixes](checks.md#fixes)); the rest is open with the reason.
- `words`, `links`: every missing word or link is a loss until you find it on the new page in another form. Allowed differences are listed in [wording stays](../../leitvia-migrate/references/wording.md).
- `shortcodes`, `brokenImages`, `httpErrors`: fix the content, or list the item as open.

The script checks published pages. Pages that wait in a package have no public address yet: check them after the person applied the package.
