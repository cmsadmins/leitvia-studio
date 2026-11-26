# leitvia/preview-token-set

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Preview token set.** Returns a signed address that shows the site with a token set, for that request only. The page looks as the activation of the set would make it (colors, fonts, sizes, widths); nothing changes for visitors and nothing is saved. Open it in a browser or screenshot tool without signing in; it expires after an hour, carries noindex and is not cached. Compare style variants this way instead of activating them. The set ID comes from leitvia/get-tokens or leitvia/create-token-set; path is an address of this site such as /about/, the front page when empty.

MCP tool `leitvia-preview-token-set`, REST GET. Annotations: readonly true, destructive false, idempotent true.

| Input | Type | Required | Description |
|---|---|---|---|
| `set` | integer, min `1` | yes | Token set ID from the sets of leitvia/get-tokens or from leitvia/create-token-set. |
| `path` | string, max length `2000`, pattern `^/` |  |  |

Output: `url` string; `expires` string.
