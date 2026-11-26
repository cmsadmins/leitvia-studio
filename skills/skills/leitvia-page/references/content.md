# Texts, links and images

Details for `leitvia-page`. Read it before you write the edits of step 4.

## Texts

- Replace every sample text of every pattern you use. Sample copy left on a page publishes fiction: invented names, figures, addresses, phone numbers and quotes.
- A pattern with more slots than you have facts (a strip of five items for four points, a step the briefing does not describe) gets a pattern with fewer slots, or the slot repeats a fact the person gave you. Never fill it with an assumption about the business, even one you flag in the report.
- Some blocks hold text you cannot change with a `text` edit: a text with more than one link. Use those patterns only when their sample text fits, otherwise choose another pattern of the same role. The attribution of a quote (`cite`) and the question of a details block (`summary`) take a `text` edit at the quote or details block itself, the caption of an image, video, audio, gallery, table or embed a `caption` edit at that block.
- Keep to the text lengths of the plan; a hero heading of 120 characters breaks the layout the pattern was made for.
- Write in the language of the page. Headings hyphenate on their own (words from 12 letters, at least 5 on each side): never put soft hyphens (`&shy;`) into a heading or a title. Paragraphs and lists hyphenate words of ten letters or more, table cells every word, wherever the language has a dictionary; pages in easy language keep words whole.
- A page in another language than the site needs its own document language. Core has no setting per page; tell the person, who decides on a language plugin.
- Never put review notes or HTML comments into block markup. Open questions go into your report.
- A motto below the h1 of the stage takes the paragraph style Claim (`is-style-claim`), never a heading or a font set by hand; one per page at most.
- A long quote takes the style Compact (`is-style-compact`). A dark stage: `is-style-inverse` on its outer group.
- Forms, embeds and job postings go in with `insert` ([edits](composition.md#edits)), never as new whole content.

## Links

- Every link and button needs a real target: a page of the site, an `https` URL, `mailto:` or `tel:`. A link to `#` and a sample link of a pattern without an address are rejected by the link text check; so is link text that does not name its target.
- Button texts name the action and the target ("Book a repair by e-mail", not "Click here").
- A text with one link, such as a contact line "Write to hello@example.com", keeps its link: send `text`, `link_text` (the part that stays linked) and `url` in one edit. The same in compose-page, update-block and `blocks` of update-content. A text that would drop a link with a real target is rejected with `leitvia_invalid_input`; a sample link to `#` may be replaced by plain text.
- A text without a link gets one with `add_link` (`text`, the exact words, and `url`) on `leitvia/update-block` or per block in `leitvia/update-content`, for example an e-mail address in a contact paragraph with `url` `mailto:hello@example.org`: [block edits](block-edits.md).
- Only link to pages that exist. When a target page is missing, build it first or ask.

## Images

- A site without a posts page has no address that lists its posts. Show the newest posts on a page with the pattern `leitvia/teaser-posts-grid` until `leitvia/set-posts-page` sets the posts page.
- Images come from the media library. Add one with `leitvia/add-media` (Base64 data with a file name, or a URL of this site) and an `alt` that describes it, or `decorative: true` when the text around it carries the meaning. URLs of other hosts are rejected and never fetched.
- A file the person handed you goes through a short script that reads and encodes it, so the Base64 never passes through your own output: over REST it posts `{"input": {"data": ..., "filename": ..., "alt": ...}}` with the application password, over MCP (also with an OAuth token) it sends `tools/call` of `leitvia-add-media` with these `arguments`.
- Look at the image before you write its alt text; when it does not show what the briefing says, ask instead of describing the briefing.
- Use the attachment ID in the `image` edit with the same alt text or `decorative: true` (stored as `metadata.leitviaDecorative`). An image without alt and without that flag is rejected.
- Sample images of a pattern come with a sample alt text that describes the sample picture. Replace the image, or choose a pattern without an image slot.
- `images` in `leitvia/list-patterns` gives aspect ratio and recommended size per slot.
- A landscape photo in a portrait slot (`hero-split-image`, 4:5): set `aspectRatio` `"3/2"` or `"16/9"` with `scale` `cover` on the image (`leitvia/update-block` or the edit of compose-page) instead of cropping the photo.
- A file no page needs any more goes with `leitvia/delete-media`: refused with `leitvia_media_in_use` while published content or the site uses it, the places in `data.usage`. Replace the image there first; `force` only on the word of the person. A person applies it; the file goes for good.
- A corrected file under the same ID and address (new cut of a video, new edition of a PDF): `leitvia/add-media` with `replace` (attachment ID) and only the file, of the same type. No page changes. A person applies it; the old file is gone then.
- Illustrations and diagrams show their whole motif: no overlap pattern, where a card covers them, and no slot with another aspect ratio, which crops them (`scale: contain`, or a pattern whose slot matches). On a dark band use only an image made for dark ground, never a recolored or inverted copy. No mask or wipe animation on fine line art.
- Plans, diagrams and drawings beside a text of 150 to 400 words: `leitvia/content-text-figures`, figures uncropped in their own ratio. `className` `is-style-media-right` on its columns moves them to the right; the gallery goes with `remove: true` when it has no figures. Each figure gets an alt text and a caption, and the text names it. 80 to 300 words without an image: `leitvia/content-text`.
- Services with a pictogram or small drawing each: `leitvia/teaser-pictogram-list` (two to six cards, pictogram whole at 4 rem, title up to 30 characters, one sentence up to 90). `leitvia/teaser-cards-3` is for photos: it crops, on phones to 10 rem in height.
- Decorative drawing: `leitvia/content-text-illustration` (beside a text) or `leitvia/content-ornament` (divider), `decorative: true`. Without a drawn motif `leitvia/content-divider`, a fine line in the accent color. Dividers of both kinds together at most twice per page.
- An ornament of the brand: a flat SVG or transparent PNG (about 8:1, 960 px wide, lines 3:1 against the page background) from `leitvia/add-media`, set with an `image` edit and `decorative: true` on the image of `leitvia/content-ornament`. Never draw one yourself or recolor a file.
- In another slot a cut-out drawing takes the image style Illustration (no radius, no shadow, at most 20 rem): `leitvia/update-block` with `className` `is-style-illustration` and `scale` `contain`. Add `leitviaAnimation` `none` only when the image carries an effect and `leitvia/list-blocks` lists the attribute for the block.

## Files to download

- Upload a PDF with `leitvia/add-media`: `data`, `filename` ending in `.pdf`, up to 5 MB, a `title` that names the document, no alt text. Other documents are not uploaded by an ability: use what the person put into the library.
- Find files with `leitvia/list-media` (`mime` `application/pdf`, or `application` for every document); each item has `url`, `filename`, `mime` and `filesize` in bytes. A file that is missing is an open question, never a link to `#`.
- Use `leitvia/content-downloads`: one list item per file. Its `text` edit holds the link text and one sentence about the file, with `link_text` the part that stays linked and `url` the file URL: `{"block": "p:0.2.0", "text": "Price list 2026 (PDF, 180 KB) All plans and services with prices excluding VAT.", "link_text": "Price list 2026 (PDF, 180 KB)", "url": "<url>"}`. The list style puts the link on its own line.
- The link text names the file, its format in capitals and its size, rounded: KB below 1 MB, MB with one decimal above. Keep each link text unique on the page. Remove list items you have no file for, or choose another pattern; never keep a sample item.
- One file inside running text needs no pattern: link it in the sentence with the same text, "the price list (PDF, 180 KB)".

## Videos

- Videos stay on the site: upload MP4 (H.264) or WebM with `leitvia/add-media`, `poster` (image ID, a still) and `caption`, or take one from `leitvia/list-media` (`mime` `video`). Videos of other hosts only in `leitvia/consent-embed`.
- Where uploads wait in a package, the still has no ID yet: add the image first, then the video with `poster_file` (file name of the image) and the same `package` key.
- Use `leitvia/content-video` (text width), `leitvia/content-video-wide` (the video is the main content) or `leitvia/content-video-split` (up to 300 characters of text beside it). The transcript holds one list item per scene in the words on screen; without the script of the video, ask. Spoken words need captions: a WebVTT file as `tracks`.
- The small paragraph below the transcript labels the video or names its source. An AI-generated image or voice always keeps it ("AI-generated content, AI voice"); otherwise `remove: true`.
- The video block of all three is empty: fill it with a `video` edit, `{"video": {"id": <attachment>, "poster": <image ID>}, "caption": "..."}`, in compose-page or `leitvia/update-block`. It sets `src`, poster, `controls` and `preload` `none`. Never `autoplay` or `loop`. Without `poster` it takes the one the video got in the library. Never write video markup yourself.
- Another video in the same place: the same `video` edit with the new ID; set `tracks` anew when the words changed. A new cut of the same video: `replace` in `leitvia/add-media`.
- A video without speech still needs its content as text (WCAG 1.2.1) in caption and transcript.
- Wider than the text: `align` `wide` or `full` on the `core/video` with `leitvia/update-block`, `null` back to the text width. Also on any block whose `supports.align` has it.
