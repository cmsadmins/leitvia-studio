# leitvia/get-tokens

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Get tokens.** Returns the active design tokens as a DTCG document (of the active token set, else of the current global styles), the token sets with their status, the active style variations, whether the global styles drifted from the active set, the contrast findings of the current palette and the hash of the global styles, the base_hash of leitvia/activate-token-set and leitvia/set-variation.

MCP tool `leitvia-get-tokens`, REST GET. Annotations: readonly true, destructive false, idempotent true.

No input.

Output: `active` integer; `source` one of `set`, `global_styles`; `document` object; `sets` array of object { id, title, status, modified }; `variations` array of string; `drift` boolean; `contrast` array of object; `hash` string.
