# Sections with icons

Details for `leitvia-page` and `leitvia-maintain`. Read this when a section lists areas, steps, related pages or what a plan includes. Five patterns of Leitvia Studio (Free) give each entry an icon of its own; build them with `leitvia/compose-page`, `leitvia/insert-section` or `leitvia/replace-pattern`, change them later with `leitvia/update-block`.

| Pattern | For | Entries (path ID) | Style of the entries |
|---|---|---|---|
| `leitvia-studio/teaser-icon-areas` | areas or services with a page each | `p:0.1.<n>`: icon `.0`, h3 `.1`, text `.2`, link `.3` | icon `is-style-field` |
| `leitvia-studio/content-process-icons` | what happens next, three to five steps | `p:0.1.<n>`: icon `.0`, label `.1`, h3 `.2`, text `.3`; closing line `p:0.2.0` | icon `is-style-field-filled`, grid `is-style-steps-line` |
| `leitvia-studio/content-process-timeline` | a project with a fixed course and a key figure | `p:0.1.<n>` as above; key figure `p:0.0.1.0`, its label `p:0.0.1.1` | icon `is-style-field-accent`, grid `is-style-steps-line` |
| `leitvia-studio/teaser-link-cards` | related pages and guides at the end of a page | `p:0.1.<n>`: icon `.0`, h3 with link `.1.0`, text `.1.1` | card `is-style-link-card` |
| `leitvia-studio/content-scope-grid` | what a plan or a service includes | `p:0.0.1.0.<n>`: icon `.0`, h3 `.1`, text `.2`; intro column `p:0.0.0` | grid `is-style-grid-net` |

Each pattern starts with a section mark: icon `p:0.0.0.0` and eyebrow `p:0.0.0.1` (in the timeline `p:0.0.0.0.0` and `.1`, in the scope grid `p:0.0.0.0.0` and `.1`), then the h2.

## Rules

- **Icon per entry:** an edit with `attributes: { "icon": "<name>" }` on the icon of each entry. Names come from `leitvia/list-blocks` (block `leitvia/icon`). Every entry gets another icon, and none repeats the icon of the section mark. `leitvia/repeated-icons` warns when two icons of a section are the same; `repeat` copies the icon of its model, so set the icon of every copy.
- **Numbers or icons:** areas with a fitting symbol each use `teaser-icon-areas`; the numbered `leitvia/teaser-services` stays for entries without one. Steps with a title each use `content-process-icons`; the numbered `leitvia/content-steps` stays for four steps without icons. Both stay approved, the contract names the icon pattern as their `successor` (in `leitvia/list-patterns`), and `leitvia/audit-site` reports each section with `leitvia/successor-pattern` (notice).
- **Successors:**

  | Older pattern | Successor |
  |---|---|
  | `leitvia/teaser-services` | `leitvia-studio/teaser-icon-areas` |
  | `leitvia/content-steps` | `leitvia-studio/content-process-icons` |
  | `leitvia/content-feature-grid` | `leitvia-studio/content-scope-grid` |
  | Steps with icon fields of an add-on (its `successor`) | `leitvia-studio/content-process-icons` |

- **Switch an existing section:** `leitvia/replace-pattern` with `post`, `base_hash` and `block`, without `pattern`: the section gets its successor. With `pattern` you pick another one; both move the content over the same way, no script of your own. A section of entries moves over entry by entry: the new section gets as many entries as the old one, then headings, eyebrows, texts, links (with their address) and icons go to their counterpart in the same entry. A number such as `01` has no counterpart. An entry without a heading gives its eyebrow to the h3 of the new entry, and the eyebrow counts the step (Step 1, Step 2); an eyebrow that only counts (Step 3) stays the eyebrow, and the h3 goes. Texts and sample links of the pattern without a counterpart are removed: the section mark when the old head had no eyebrow, the closing line of the steps. Afterwards set the icon of each entry (they come from the pattern where the old section had none) and, if wanted, an eyebrow or closing line with `leitvia/update-block` or `leitvia/insert-section`.
- **More or fewer entries:** `repeat` on an entry or `remove`, first, then the edits by the new path IDs (a `remove` moves later entries up one place). Areas and link cards stay at three, four, six or eight, never an orphan in the last row.
- **Links:** titles and links of the patterns are sample links without an address. Set `url` with the `text` edit of each, or the write is refused. The whole link card is the link of its title; the arrow is drawn by the style.
- **Variant of a step:** `attributes: { "className": "is-style-field" }` (framed), `"is-style-field-accent"` (framed in accent) or `"is-style-field-filled"` (filled in accent) on the icon. `className: null` on the grid with `is-style-steps-line` removes the line between the steps. The line runs across while all steps share one row, down between the icon fields when they stack in one column (phones, the text then stands beside the icon field, so the line crosses no text), and not at all in a grid of several rows; nothing to set.
- **Without parts:** remove the key figure of the timeline (`remove` on `p:0.0.1`), the closing line of the steps (`p:0.2`) or the intro column of the scope grid (`p:0.0.0`, the grid then takes the full width).
- **Leitvia Studio needed:** without the icon block these sections cannot render; on a site without Studio use the numbered theme patterns.

## Example

```json
{ "pattern": "leitvia-studio/content-process-icons", "edits": [
  { "block": "p:0.0.0.1", "text": "How it works" },
  { "block": "p:0.0.1", "text": "What happens after your request" },
  { "block": "p:0.1.0.0", "attributes": { "icon": "list-checks" } },
  { "block": "p:0.1.0.2", "text": "Choose a plan" },
  { "block": "p:0.1.1.0", "attributes": { "icon": "send" } },
  { "block": "p:0.1.2.0", "attributes": { "icon": "phone" } },
  { "block": "p:0.2.0", "text": "Not sure? We advise you for free.", "link_text": "We advise you for free", "url": "/contact/" }
] }
```
