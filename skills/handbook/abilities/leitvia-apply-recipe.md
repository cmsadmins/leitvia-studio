# leitvia/apply-recipe

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Apply recipe.** Sets up the site from a recipe of leitvia/list-recipes: content model and building blocks, token set, style variation, header and footer, pages from patterns, navigation, reading settings and options that save data. Each part runs through its own ability with the autonomy level of its area; what may not go live lands in one change package, and with a package key everything does. Settings that point to a page waiting in that package (front page, privacy policy page) are set when the package is applied. Pages that exist, and variation, header, footer and navigation changed since a recipe wrote them, are kept, as is the variation while a token set is active, so the recipe can run again after the package was applied. parts, pages and buildingBlocks limit the run to a selection, and slugs gives recipe pages other addresses; navigation and footer then link only the pages that are on the site or come in this run, and a navigation without any of them waits. dryRun reports what would change without writing. Review the package with leitvia/get-package. With settle, a person (manage_options, not the agent role) in the admin, neither signed in with an application password nor calling over MCP, who runs it without a package key applies what collects at once: applied lists the packages applied in full, which can be rolled back in the Inbox, and partial the packages applied in part, with their errors. Without settle the package waits.

MCP tool `leitvia-apply-recipe`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `recipe` | string | yes | Name from leitvia/list-recipes, for example leitvia/blog. |
| `package` | string, max length `64` |  | Key of the task: every part goes into the change package of that key, nothing goes live. |
| `parts` | array of one of `buildingBlocks`, `samples`, `design`, `header`, `footer`, `pages`, `navigation`, `frontPage`, `options` |  | Parts to apply, all by default: buildingBlocks (content model and building blocks), samples (their sample entries), design (token set and style variation), header, footer, pages, navigation, frontPage (reading settings) and options (page settings and data-saving options). |
| `pages` | array of string, pattern `^[a-z0-9-]+$` |  | Slugs of the recipe pages to create, all by default. |
| `slugs` | object |  | Other slugs for recipe pages, by the slug of the recipe, such as {"imprint": "impressum"}. Links, navigation and settings follow. Send the same slugs on a later run; pages without a kind are found by their slug. |
| `buildingBlocks` | array of string, pattern `^[a-z0-9-]+/[a-z0-9-]+$` |  | Names of the building blocks of the recipe to add, all by default. With a list only these blocks add content types, not the whole content model of the recipe. |
| `dryRun` | boolean, default `false` |  | Report what each step would do (new, replace, exists, package, waiting) and the parts it replaces, without writing anything. |
| `settle` | boolean, default `false` |  | Apply what collects at once instead of leaving it in the Inbox. Only for a person in the admin; agents, application passwords and MCP clients get the autonomy. |

Output: `recipe` string; `package` integer; `steps` array of object; `dryRun` boolean; `applied` array of integer; `partial` array of object { package, errors }; `replaces` array of string.
