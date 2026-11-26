# leitvia/get-content-model

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Get content model.** Returns the content model declaration: post types, taxonomies, fields, relations and site settings with their definitions, never content. valid is false when the declaration fails the schema and nothing of it is registered. hash is the base_hash of leitvia/define-content-type. bindings names the sources that show fields, relations and bindable settings in blocks (metadata.bindings) and the attributes WordPress binds per block; settings_values and settings_hash are the values of the bindable settings and the base_hash of leitvia/update-site-values.

MCP tool `leitvia-get-content-model`, REST GET. Annotations: readonly true, destructive false, idempotent true.

No input.

Output: `declaration` object; `valid` boolean; `error` string; `hash` string; `bindings` object { sources\*, attributes\* }; `settings_values` object; `settings_hash` string.
