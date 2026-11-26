# Fixes, manual tests and the statement

Details for `leitvia-a11y`. Read "Fixes" before step 3, "Manual tests" for part 3 of the report, "Accessibility statement" when that page is in scope.

## Fixes

Read the post with `leitvia/get-page-outline` (`hash`, block IDs) and the block with `leitvia/get-block` (attributes, markup). Then:

- **Alt text:** write it yourself from what the image shows and why it is on the page: one short sentence in the language of the page, no "image of", no file names. Text in an image goes into the alt text. `leitvia/set-alt-text` with `image` (the `attributes.id` of the block), `alt`, `post`, `block` and `base_hash`. When the AI plugin of WordPress offers the ability `ai/alt-text-generation` and the person agreed that images go to the AI provider of the site, use it for a first draft and correct it before you send it.
- **Decorative images:** an image whose meaning the text around it already carries gets an empty alt and the mark: `leitvia/update-block` with `image: { "id": <attachment>, "decorative": true }`.
- **Video captions:** a video with speech needs a track with `kind` `captions` (WCAG 1.2.2): add the `.vtt` file with `leitvia/add-media`, set it with `tracks` on `leitvia/update-block`, see [block edits](../../leitvia-page/references/block-edits.md).
- **Link texts:** the link text names the target. For a text with one link use `leitvia/update-block` with `text` (the whole new text), `link_text` (the part that stays linked) and `url` when the target changes. A link to `#` needs the real target; when you cannot find it on the site, ask (checkpoint **Link targets**).
- **Empty headings:** give them text with `leitvia/update-content` (`blocks` with `block` and `text`), or ask the person what the section is about.
- **Heading levels:** a skipped level or a second h1 gets the level that fits the outline: `leitvia/update-block` with `attributes: { "level": <n> }` (1 to 6), for example h3 after h1 becomes h2. Work from the top down, so each fix sees the level before it. A heading that is missing altogether needs its text from the person.
- **Animation:** `leitviaAnimation` (in the `attributes` of `leitvia/get-block`) on the header, a form, a notice, a call to action or the first view, or on most sections of a page, goes: remove it with `leitvia/update-block` and `attributes: { "leitviaAnimation": "none" }` on that block (works on every block, only the block comment changes); every `leitviaMotion` goes with `"leitviaMotion": null` (strength comes from `leitvia/set-motion`); the same fixes `leitvia/motion` of `leitvia/audit-site`. A scroll effect in the last screen goes the same way. A text that refers to the motion gets a new wording. Rules: [animation](../../leitvia-pattern/references/animation.md).
- **Embeds behind consent:** each `leitvia/consent-embed` needs a `title` that names the content; it titles the frame for screen readers (`leitvia/update-block`). By keyboard, after loading the focus lands on the frame, not at the top of the page; report what you saw.

## What the abilities cannot fix

- **Landmarks and the h1 of a template:** template work for a person in the Site Editor.
- **Classic content, Custom HTML blocks, blocks of other plugins:** report them; agents may not write them.
- **Palette contrast:** skill `leitvia-design`.

## Manual tests

No ability or automated check decides these. List each one that applies to the checked pages, with the page:

- **Screen readers:** reading order, names, roles and states as heard with NVDA and Firefox, VoiceOver and Safari, TalkBack on Android.
- **Keyboard and focus** across the whole page, including menus, dialogs and widgets of other plugins.
- **Meaning of alt texts and link texts:** whether your texts say what matters on this page.
- **Plain language and easy-to-read language** (Leichte Sprache): whether the texts are understandable for the audience, and whether the site needs an easy-to-read version (BITV 2.0 asks public bodies for one).
- **Language changes** inside a text: passages in another language need `lang` on their element, which no ability sets.
- **Videos and audio:** captions, audio description, transcripts; media embedded from other hosts also need a consent placeholder.
- **Forms of other plugins** (contact forms, newsletter, shop checkout): labels, error messages, keyboard use; the checks of Studio do not see them. Gravity Forms: [rules](../../leitvia-page/references/gravity-forms.md#accessibility). Fluent Booking: the theme makes the event title of its calendar an h2 in the size of a card title, so the page keeps one h1 ([contact form](../../leitvia-page/references/contact-form.md)). Content inside an embedded frame belongs to its provider: list it.
- **Documents:** PDF and office files linked from the pages.
- **Motion:** with reduced motion turned on in the system every animated block is there at once; with it off, a block reached by keyboard shows itself when it gets focus, and no content depends on the effect.
- **Zoom and reflow:** 200 % text zoom and 400 % zoom at 320 px width on the real content.

## Accessibility statement

A page with the page kind `accessibility` is a legal text: changes wait for a person, and its content comes from the person. Derive the state of the site from the site: what you found, what stays open, which manual tests are missing. Do not copy the test report of the theme into it. Leave legal statements (enforcement body, contact for feedback) to the person.
