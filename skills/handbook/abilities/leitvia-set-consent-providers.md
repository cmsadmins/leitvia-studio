# leitvia/set-consent-providers

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set consent providers.** Replaces the providers of embedded content in the privacy settings. Send the whole list, at most 20; a provider you leave out is removed. Read them with leitvia/get-consent-providers first, send its hash as base_hash and the key of every provider that stays. A built-in service such as YouTube needs no entry. Hosts lose scheme, port and path; a provider without a valid host of another site is rejected. Use only providers the site embeds and privacy policies the provider publishes. Like every setting it waits in a change package for a person.

MCP tool `leitvia-set-consent-providers`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `providers` | array of object { key, label\*, hosts\*, purpose, privacy }, max items `20` | yes | The whole new list of providers; an empty list removes all. |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
