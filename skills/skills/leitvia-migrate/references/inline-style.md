# Imported inline style and plugin blocks

Details for step 4 of `leitvia-migrate`, next to the [tag to block table](translate.md#tag-to-block).

Content that carries style from an old editor is translated by hand with this table; a missing reader for it is no reason to stop (a reader that needs a license the site lacks changes nothing: the table applies, no abort).

| Source | Block |
|---|---|
| Text marker with a hex color | Highlight without a color (the theme style for it where the theme has one); the hex color is not taken over |
| Font size in `px` | The nearest size preset of the theme |
| Radius `0px` or any radius on an image | Left out; the corner of all photos is `radius.media` of the token set ([media radius](../../leitvia-design/references/media-radius.md)) |
| Spacer between or inside columns | `blockGap` or a spacing preset of the columns |
| `style` on `<img>` | Left out |
| Map block of another plugin (for example Google Maps) | `leitvia/map` (consent placeholder from Studio), address or coordinates from the attributes of the source block; never taken over unchanged |

List each dropped style in the report.

## Cookie plugins and maps

- **Wrapper shortcodes of a cookie plugin** (content blockers) are dropped. Take the wrapped content by its own rule (an embed by the table above, a map as below). What the plugin stored for it (for example Base64 in the rendered HTML) is a source for the data of the embedded content.
- **Map plugins with a central list of places** (places with categories, maps as a selection of them): create the places as entries of a content type "Places" (address, latitude, longitude, country, kind, link) and build every map from that type, so the person keeps the places in one spot. With a map block from the catalog that reads the type, one map per selection; without such a block, a `leitvia/map` with one marker per map, and tell the person the places must then be kept per map.
- **Mass work on images:** `leitvia/list-media` with `include` (IDs, up to 100) or `search` (file name) resolves galleries in one call; `leitvia/set-alt-text` takes `items` (up to 100 per call, one package) instead of single calls.
- **Alt texts** describe what the image shows (look at the image), never "Photo for section ...".
