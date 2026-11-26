# leitvia/get-template

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Get template.** Returns the block markup of a template (template, such as single, archive or page-landing) or a template part (part, such as header, footer or post-aside) as the site shows it: the saved version, or the file of the theme while none is saved (source theme). hash is the base_hash of leitvia/update-template and leitvia/update-template-part ("" while only the file exists), outline the block tree with path IDs, and patterns the patterns the markup places (core/pattern), which leitvia/get-pattern reads. For the template single, post_details holds the texts and choices of leitvia/set-post-details in effect. Change a template by sending the changed markup as content of leitvia/update-template; keep the core/pattern blocks you do not change, so they follow updates of the theme.

MCP tool `leitvia-get-template`, REST GET. Annotations: readonly true, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `template` | string, pattern `^[a-z0-9_-]+$` |  | Template slug from leitvia/get-contract or of the theme, such as single. |
| `part` | string, pattern `^[a-z0-9_-]+$` |  | Template part from templateParts of leitvia/get-contract, such as header. |

Output: `slug` string; `type` string; `source` string; `area` string; `hash` string; `content` string; `outline` array of object; `patterns` array of string; `post_details` object.
