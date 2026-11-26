# leitvia/check-composition

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Check composition.** Scores a page against the composition rules of the contract: pass pattern slugs in page order (block:\<ID> for a Lab pattern; only approved ones count as known), or a post ID to read the sections of a saved page from metadata.patternName. A saved page counts as built: the ground each section sets (backgroundColor, block style Inverse) instead of the tone of its pattern, and the side of its media (block style Media right). With slugs, name both yourself: tones for the sections an edit will give another ground, mirrored for those it will give the block style Media right, each by 0-based index. Text, lists, images, galleries and accordions between the sections are running text and no finding. A section with a video, a gallery or a wide image is a break and stands outside the density run. With frame (or the frame a saved page was composed for) the page also needs a section of every kind the page frame requires (missingSection, 10 points) and is told about the kinds it recommends (recommendedSection, no points); both carry the kind and the index after the last section, and their suggestions name patterns to add. Returns the findings (rule, 0-based section index, for a post the block ID, and what to do), the score from 100 down, the minimum score for autonomous writes and per finding up to three patterns that would fix it. For a post also unregistered: block types that render nothing, the finding newsTopic (no points) when the page has the pattern Related news without a topic of its news, and the finding firstScreen (no points) when the first button of the stage ends below the first screen at 1440 x 900 or 390 x 844: estimated from the lines of the heading and the text before it with the measured model of the stage pattern and the service bar of the header, or for a stage without a model from the lengths its pattern allows.

MCP tool `leitvia-check-composition`, REST GET. Annotations: readonly true, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `slugs` | array of string, max length `100`, max items `40` |  |  |
| `tones` | object |  | Tone per 0-based index in slugs for sections whose ground an edit changes, for example {"2": "ink"} for a third section with className is-style-inverse, or the slug of its backgroundColor such as surface-alt. With post it replaces what the page sets for that section. |
| `mirrored` | array of integer, min `0`, max items `40` |  | 0-based indexes in slugs of the sections an edit gives the block style Media right, for example [3]. A saved page reads them from its blocks. |
| `post` | integer, min `1` |  |  |
| `template` | string, max length `100` |  | Template slug, for example page-landing; for a post its saved template is used. |
| `frame` | string, max length `40` |  | Type of a page frame of the contract, for example hub or service, whose required and recommended sections count; for a post the frame it was composed for. |

Output: `sections` array of string; `issues` array of object { rule, index, block, message, kind }; `score` integer; `frame` string; `min_score` integer; `suggestions` array of object; `unregistered` object.
