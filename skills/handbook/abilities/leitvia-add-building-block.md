# leitvia/add-building-block

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Add building block.** Adds a building block of leitvia/list-building-blocks to the site: its content types, taxonomies, relations and site settings through leitvia/define-content-type, merged with the entries that exist so fields a person added stay; the default values of its site settings that are empty; and its sample entries with their terms as drafts. Design, header, footer, pages and front page stay as they are. What the autonomy does not let go live waits in one change package. With settle, a person (manage_options, not the agent role) in the admin, neither signed in with an application password nor calling over MCP, gets that package applied right away: applied lists it, partial names a package applied in part with its errors. Entries that exist are kept, so the block can be added again to fill in what is missing.

MCP tool `leitvia-add-building-block`, REST POST. Annotations: readonly false, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `name` | string, pattern `^[a-z0-9-]+/[a-z0-9-]+$` | yes | Name of the building block, for example my-addon/events. |
| `samples` | boolean, default `true` |  | Whether to add the sample entries as drafts. |
| `package` | string, max length `64` |  | Key of the task: every part goes into the change package of that key, nothing goes live. |
| `settle` | boolean, default `false` |  | Apply what collects at once instead of leaving it in the Inbox. Only for a person in the admin; agents, application passwords and MCP clients get the autonomy. |

Output: `buildingBlock` string; `package` integer; `steps` array of object; `applied` array of integer; `partial` array of object { package, errors }.
