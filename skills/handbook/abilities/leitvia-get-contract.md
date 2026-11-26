# leitvia/get-contract

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Get contract.** Returns the site this connection works on (address, REST base, name, language, whether it is part of a multisite network) and the design contract of the active theme: version, rules, color roles, contrast pairs, composition rules, page frames (with the section kinds a frame requires or recommends, sectionKinds), template parts, templates, block styles and style variations, plus the base hashes of template parts, templates and navigation menus. Compare site.url with the address of your task before the first write. Patterns come from list-patterns, blocks from list-blocks.

MCP tool `leitvia-get-contract`, REST GET. Annotations: readonly true, destructive false, idempotent true.

No input.

Output: `site` object { url\*, rest\*, name\*, language\*, multisite\* }; `version` string; `contract` object { rules, roles, pairs, optionalRoles, optionalPairs, compositionRules, pageFrames, sectionKinds, templateParts, templates, blockStyles, variations, coreAliases }; `megaMenu` object { panels, patterns }; `hashes` object { templateParts, templates, navigation }.
