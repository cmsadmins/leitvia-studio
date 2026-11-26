# leitvia/list-recipes

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**List recipes.** Lists the site recipes of Leitvia Studio and its add-ons with owner, pages and whether the plugins they require are active.

MCP tool `leitvia-list-recipes`, REST GET. Annotations: readonly true, destructive false, idempotent true.

No input.

Output: array of object { name, owner, label, title, description, requires, pages, available, reason }.
