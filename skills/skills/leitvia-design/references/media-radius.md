# Media radius

Details for `leitvia-design` and for migrations (`leitvia-migrate`, `leitvia-page`).

`radius.media` is the corner of every photo on the site: images, gallery images, covers that are not full width, featured images, media and text, product images and the images of the scroll gallery. The theme applies it centrally through the preset `--wp--preset--border-radius--media`. Its value is one of `radius.none`, `s`, `m` or `l`, best written as an alias, `"$value": "{radius.m}"`; a radius of its own or `{radius.pill}` is rejected with `leitvia_studio_dtcg_invalid_choice`. A set without it gets `none`. Take the brand's photo corners from its guide or website: sharp photos `none`, slightly soft `s`, soft `m`, round `l`.

Never set `style.border.radius` on single images to get rounded photos, and remove such radii when you migrate a page; round portraits keep the image style Rounded or the pill radius, cut-out illustrations on a transparent background take the image style Illustration (no radius, no shadow).
