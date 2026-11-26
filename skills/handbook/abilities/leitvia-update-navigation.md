# leitvia/update-navigation

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Update navigation.** Replaces the links of a navigation menu, by default the one published last, which headers without a chosen menu show. Send items (with one level of sub items) or block markup of navigation links, submenus, page lists and mega menus. Links point to this site, use https, mailto: or tel:, and need a text that names their target. An item with type mega and panel is a mega menu: a link to the overview page in url and a button that opens the panel, a template part in the area Mega menu that leitvia/update-template-part fills first (part mega-menu-\<name>, pattern and edits). icon puts an icon of the icon set (enum of the attribute icon of leitvia/icon in leitvia/list-blocks) before the text of an item or sub item. The change publishes the menu, a draft one too, since core shows no draft menu. The base hash is the hash of the menu, empty when the site has none. leitvia/get-contract returns it per published menu under hashes.navigation.

MCP tool `leitvia-update-navigation`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `navigation` | integer, min `1` |  |  |
| `items` | array of object { label\*, url, type, panel, description, icon, items }, min items `1`, max items `30` |  |  |
| `content` | string, min length `1` |  |  |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
