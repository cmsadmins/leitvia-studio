# Business pages

Details for `leitvia-page`. Read this for a business whose history and craft sell it: a workshop in its second or third generation, a builder, a bakery, a family company. Also for a part of a business with its own audience, and whenever a page goes out with sample images or texts.

## Business with a history

1. **Stage:** `leitvia/hero-cover-image` with a real photo of the work or the team; one sentence on what the business does and where, one button to the inquiry. A dark stage is a dark token set (Leitvia Pro brings `workshop-dark`, the colors of the brand go on top), not the inverse style on every section. Light and dark bands then alternate: after the page exists, set `className` `is-style-inverse` on the group of a section with `leitvia/update-block`, `null` takes it off.
2. **Figures:** `leitvia-studio/content-stats-counter`, only with figures the person gave (year of foundation, people, projects). No figure without a source.
3. **History:** `leitvia-studio/content-timeline`, one entry per time with its year, a heading and one or two sentences; four to six entries, with `remove` or `repeat`. Leitvia Pro adds a history pattern with a large photo per station; its skill names it. Years and stations are facts of the person, like figures.
4. **How a job runs:** `leitvia/content-steps`; with a photo or a drawing per step `leitvia/content-steps-images`, three to five steps on one screen (`remove` or `repeat` a column, a title of a few words, one or two sentences). With Leitvia Pro a scroll story, but only when every step needs a large photo and a longer text; never one section with a large photo per step.
5. **Proof and close:** projects with place and year (many photos of one service: `leitvia/content-gallery`, see [photo collage and gallery](photo-collage.md)), testimonials, then the inquiry. One strong quote: `leitvia/proof-testimonial`. Two or three: `leitvia/proof-testimonials-grid`, the longest in the large slot. Four to six long quotes: a slider block when `leitvia/list-blocks` lists one, one quote per slide with its name; otherwise the grid with the three strongest and the rest on the page of references. Never two of these bands next to each other, both are dark. Inquiries with photos or in several steps: [Gravity Forms](gravity-forms.md).

Cards for services or plans: `is-style-card` on each group of a grid, `is-style-card-accent` on the one that stands out (at most one per row, it takes the primary button, the others outline buttons). Two quotes of the same weight keep the same `fontSize`; set the large one of `leitvia/proof-testimonials-grid` to the size of the others, or `null`. Eyebrows above headings, over price hints and as footer heads are paragraphs with `is-style-eyebrow` typed in normal case; the style sets the capitals. Leitvia Pro adds sections for a sober company site (stage with a box, cards with icons, price cards, panels on a photo); its skill names them.

A cover on the inverse style keeps its overlay at 70 % or more; the theme raises lower values on inverse covers, so a light photo never takes the text below 4.5:1.

## Sub-brand

A part of the business with its own audience, such as the apprenticeship or a second trade, gets its own section or page, never its own design:

- the same token set, logo, header and footer as the rest of the site;
- its own tone in the texts: an apprenticeship speaks to school leavers in short sentences, and in the informal form where the language of the site has one;
- its own heading and, for a page of its own, its own entry in the navigation or the footer;
- a logo of its own only when the person supplies it and asks for it; then it stands in the section, never in the header.

Job openings of the sub-brand: one page per position, structured data in [job postings](../../leitvia-seo-geo/references/local-business.md).

## Sample images and sample texts

Until the person sends real photos and texts, a page may carry samples, marked so nobody takes them for the real thing:

- **Alt text** of a sample image starts with "Sample image:" in the language of the page, then what it shows.
- **Media library:** add it with `leitvia/add-media` and a `title` that starts with "Sample", so the person finds and replaces every one.
- **Texts:** a text you wrote without a source is a question in your report, with its block. Never sample figures, names, prices, quotes or testimonials; those slots stay out until the person gives them.
- **Acceptance:** before the page is published, the report lists every sample image and every sample text with page and block; the person replaces or approves each. A published page with a sample left is a finding of your report.
