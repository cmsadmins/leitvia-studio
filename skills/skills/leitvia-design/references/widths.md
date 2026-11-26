# Page widths

Details for `leitvia-design`, read when a brand wants another text or page width.

The optional group `size` sets the text width and the wide width of every page: `size.content` (30 to 80 rem) and `size.wide` (60 to 100 rem), as dimensions in rem, `size.content` never wider than `size.wide`. Leave the group out to keep the widths of the theme (37 rem text, 80 rem wide); `leitvia/get-tokens` shows it only when a set changed them. Errors: `leitvia_studio_dtcg_size_range`, `leitvia_studio_dtcg_size_order`.

The group `size` needs Leitvia Studio 1.3.34 or later; older versions accept the set and drop the group without a word. From Studio 1.3.35 a group the version on the site does not read comes back from `leitvia/create-token-set` and `leitvia/activate-token-set` as the warning `leitvia_studio_dtcg_unknown_group`, with `data.group` and `data.version`: report it and ask the person to update Studio, do not count on the group. Before 1.3.35 nothing warns, so check the rendered widths after the activation.

```json
"size": { "content": { "$type": "dimension", "$value": { "value": 80, "unit": "rem" } }, "wide": { "$type": "dimension", "$value": { "value": 80, "unit": "rem" } } }
```

A brand with many photos may set `size.content` up to `size.wide`: text, the end of a post and image bands then share one grid. The line length is then the decision of the brand; tell the person that lines get long and propose a larger `fontSize.body` with wide text. Keep the default for text-heavy brands.

**Media after widening the text column.** From theme 1.2.37, with `size.content` above 48 rem, an image directly in the post content without an alignment and without a width stays at 40 rem, and an image with the style Illustration stays at 20 rem in any column (`--leitvia-illustration-max`); wide and full images keep their width. After switching, open every page and post with images (`leitvia/list-content`, then the rendered page) and check images, galleries and groups without a width: give a photo that should fill the column `align` `wide` or a width, keep illustrations small, and report what you changed. With theme 1.2.36 and earlier, such images fill the whole column.
