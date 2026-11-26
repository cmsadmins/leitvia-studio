# leitvia/plan-page

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Plan page.** Proposes a page for a goal and content points: page frame, template, the patterns in page order with role, slot, text lengths and the points each section carries, and the composition score. Without AI the plan follows the page frames, examples and composition rules of the contract and is the same for the same input; it leaves out patterns that need images while the media library has none, post teasers below three published posts and proof or offer patterns, which carry facts of the person, and patterns the inserter hides, and plans a shop home from the home page of the shop recipe. A point that starts with the role, slot or area of a section and a colon (Hero: ...) lands on that section. With ai set to true the AI connector the site owner set up in WordPress refines it; the goal, the points and pattern names with their metadata are sent to that provider. Writes nothing; build the page with leitvia/compose-page.

MCP tool `leitvia-plan-page`, REST GET. Annotations: readonly true, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `goal` | string, min length `1`, max length `500` | yes | What the page is for, in a sentence; a page frame type it names (home, service) picks the frame. |
| `points` | array of string, max length `300`, max items `20` |  |  |
| `frame` | one of `home`, `service`, `hub`, `audience`, `article`, `about`, `contact` |  | Type of a page frame; without it the frame named in the goal, else home. |
| `ai` | boolean, default `false` |  | Refine the plan through the AI connector of the site. Sends the goal, the points and pattern names to that provider. |

Output: `frame` string; `template` string; `sections` array of object; `score` integer; `issues` array of object; `source` one of `rules`, `ai`; `dropped` array of string.
