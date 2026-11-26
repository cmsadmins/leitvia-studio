# leitvia/list-building-blocks

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**List building blocks.** Lists the building blocks of add-ons: name, title, description, whether the site has them, their post types and taxonomies, the patterns that show them and the number of sample entries. Add one with leitvia/add-building-block.

MCP tool `leitvia-list-building-blocks`, REST GET. Annotations: readonly true, destructive false, idempotent true.

No input.

Output: array of object { name, owner, title, description, icon, installed, postTypes, taxonomies, patterns, sampleCount }.
