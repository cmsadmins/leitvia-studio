# leitvia/set-alt-text

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set alt text.** Sets the alt text of an image: without post and block on the attachment in the media library (base_hash of the attachment), with post and block on one image or cover block that shows it (base_hash of the post). leitvia/get-page-outline returns both hashes, for the attachment with its ID as post. Many images: items, one call and package. The alt text check applies. ai_generated marks the image in the media library as made with AI, or takes the mark off with false.

MCP tool `leitvia-set-alt-text`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `image` | integer, min `1` |  | Attachment ID of the image. |
| `alt` | string, min length `1`, max length `500` |  | Alt text that says what the image shows. |
| `post` | integer, min `1` |  | Post ID from leitvia/list-content. |
| `block` | string, min length `1`, max length `200` |  | Block ID or path ID of the image or cover block from the outline. |
| `ai_generated` | boolean |  | Without post and block only: true marks the image as made with AI (as ai_generated of leitvia/add-media), false takes the mark off; left out, the mark stays. |
| `items` | array of object { image\*, alt\*, post, block, base_hash\* }, min items `1`, max items `100` |  |  |
| `base_hash` | string |  | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
