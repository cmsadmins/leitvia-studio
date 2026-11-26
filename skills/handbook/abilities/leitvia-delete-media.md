# leitvia/delete-media

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Maintain. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Delete media.** Deletes a file of the media library for good, with all its sizes; there is no way back. First it looks where the file is used: in blocks and links of content, templates, patterns and navigation, as featured image, as site logo or site icon. While published content or the site uses it, the call is refused with leitvia_media_in_use and the places in data.usage: replace the image there with leitvia/update-block, or send force to delete it anyway, and those places show no image. Places in drafts come as notice and report.usage. Removing content is the decision of a person: the change always waits in a change package, and applying it needs the right to delete the file. base_hash is the hash of the attachment from leitvia/get-page-outline with its ID as post.

MCP tool `leitvia-delete-media`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `id` | integer, min `1` | yes | ID of the file in the media library, from leitvia/list-media. |
| `force` | boolean |  | Delete the file although published content or the site still uses it. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
