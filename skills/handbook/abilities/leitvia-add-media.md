# leitvia/add-media

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Build. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Add media.** Adds an image to the media library from a URL of this site or as Base64 data up to 5 MB, with alt text or marked as decorative. It also takes a WebVTT file with captions for a video (.vtt, UTF-8, starts with WEBVTT, no alt text) and a video hosted on this site, MP4 (H.264) or WebM up to 50 MB, with poster and caption instead of alt text (poster_file names a poster image that still waits in the same package), and a PDF up to 5 MB (.pdf, with the PDF signature at its start) with a title that says what the document is, without alt text; no other document type. It needs the right to upload files. URLs of other hosts are rejected; nothing is fetched from them. base_hash is empty. With replace the file takes the place of the file of an attachment under the same ID and address, for a new cut of a video or a corrected PDF; a person applies that. Images already in the library are used by their ID in compose-page and update-block, a WebVTT file by its ID in tracks of update-block, a video by its ID in the field video of update-block, a PDF by its URL from the result or leitvia/list-media in url or add_link of update-block.

MCP tool `leitvia-add-media`, REST POST. Annotations: readonly false, destructive false, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `url` | string, format `uri` |  |  |
| `data` | string, max length `69905072` |  |  |
| `filename` | string, max length `200` |  |  |
| `title` | string, max length `200` |  | Title in the media library, the file name without it. Give a PDF a title that says what the document is. |
| `alt` | string, max length `500` |  |  |
| `decorative` | boolean |  |  |
| `replace` | integer, min `1` |  | Attachment ID of a file of the media library whose file this one replaces: ID, address, title, alt text and every place that uses it stay, the metadata and the image sizes are made anew. The new file must have the same type. The old file is gone for good, so the change always waits in a change package for a person. Send only the file with it; base_hash may stay empty. |
| `poster` | integer, min `1` |  | Video only: attachment ID of an image of the media library that shows before the video plays, a still of it. Also set it as poster of the core/video. |
| `poster_file` | string, max length `200` |  | Video only, instead of poster: file name of an image an earlier leitvia/add-media added under the same package key and that still waits there. Applying the package writes the image first and sets it as poster of the video, so image and video go into one package. |
| `caption` | string, max length `2000` |  | Video only: what the video shows, in plain text, as caption of the file in the media library. A video without speech needs this text on the page as well. |
| `ai_generated` | boolean |  | Image or video only: the file was made or changed with AI. Stored on the file (leitvia/list-media returns it, leitvia/set-alt-text changes it later); a site may show a short note at such images. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
