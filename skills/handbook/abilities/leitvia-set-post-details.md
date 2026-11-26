# leitvia/set-post-details

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set post details.** Sets texts and choices of every single post in the theme Leitvia: the labels before the publish and update date in byline and sidebar, such as "As of %s" for the date of the last update, the heading of the related posts below the post, where they come from (categories: all categories of the post, primary: its primary category from Yoast SEO or Rank Math, else its first, tags: its tags) and the aspect ratio of the featured image above the text (auto keeps the ratio of each image). Only the fields you send change; "" brings back the default of the theme. leitvia/get-template with template single returns the values in effect as post_details. Blocks after the text or in the sidebar go into the template parts post-after-content and post-aside (leitvia/update-template-part). base_hash is empty. Like every setting it waits in a change package for a person.

MCP tool `leitvia-set-post-details`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `published_label` | string, max length `60`, pattern `^$\|^[^%]\*%s[^%]\*$` |  | Label of the publish date with the placeholder of the date, such as Published %s; "" for the default. |
| `updated_label` | string, max length `60`, pattern `^$\|^[^%]\*%s[^%]\*$` |  | Label of the date of the last update with the placeholder of the date, such as As of %s; "" for the default. |
| `related_heading` | string, max length `80` |  | Heading of the related posts, such as More from this category; "" for the default. |
| `related_by` | one of `categories`, `primary`, `tags` |  | Where the related posts come from: categories (the default), primary or tags. |
| `image_ratio` | one of `auto`, `1`, `4/3`, `3/2`, `16/9`, `21/9` |  | Aspect ratio of the featured image above the text, 16/9 by default; auto keeps the ratio of each image. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
