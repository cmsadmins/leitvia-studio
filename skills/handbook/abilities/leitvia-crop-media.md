# leitvia/crop-media

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Build. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Crop media.** Crops an image of the media library into a new image. The rectangle is in percent of the image size; the original stays. The new image takes the alt text of the original and its title with an addition. Use it for images with text burned in: keep the part without the text band. Only JPEG, PNG, GIF, WebP and AVIF, no SVG; it needs the right to upload files. base_hash is empty. A live crop gets a change package, so leitvia/rollback deletes the new image with its files. In a package the new image has no ID before a person applies it (target.post 0); applied.post of leitvia/get-package gives it, so a further step on it goes into the next package.

MCP tool `leitvia-crop-media`, REST POST. Annotations: readonly false, destructive false, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `id` | integer, min `1` | yes | ID of the image in the media library, from leitvia/list-media. |
| `x` | number, min `0`, max `100` | yes | Left edge of the rectangle in percent of the image width. |
| `y` | number, min `0`, max `100` | yes | Top edge of the rectangle in percent of the image height. |
| `width` | number, min `0`, max `100` | yes | Width of the rectangle in percent of the image width, above 0; x plus width is at most 100. |
| `height` | number, min `0`, max `100` | yes | Height of the rectangle in percent of the image height, above 0; y plus height is at most 100. |
| `title_suffix` | string, max length `100` |  | Addition to the title of the original, in brackets after it; without it the addition is cropped. |
| `square` | boolean |  | true makes the new image square: the rectangle stays whole and centred, a transparent margin fills the shorter side, saved as PNG. For a site icon from a logo that is not square. |
| `min_size` | integer, min `0`, max `2048` |  | With square: the least width and height of the new image in pixels, the margin grows to it, such as 512 for a site icon; the image itself is never scaled up. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
