# leitvia/set-motion

Generated from Leitvia Studio 1.3.61. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set motion.** Sets the motion of the whole site: off (no effect on scroll and no hover motion of the theme), subtle (800 ms, the default) or bold (twice the way, 1200 ms). It is the strength and duration of every effect of leitviaAnimation without leitviaMotion, so one setting keeps the motion of all pages the same. When a person wants more or less motion, change this setting instead of giving single blocks effects or leitviaMotion. base_hash is empty. Like every setting it waits in a change package for a person.

MCP tool `leitvia-set-motion`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `motion` | one of `off`, `subtle`, `bold` | yes | off, subtle or bold. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
