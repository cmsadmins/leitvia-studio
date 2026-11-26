# leitvia/get-consent-providers

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Get consent providers.** Returns the providers of embedded content from the privacy settings. providers holds key, label, hosts, purpose and privacy of each, services the services the consent placeholder knows without an entry (key and label, such as YouTube), hash the base_hash of leitvia/set-consent-providers. Content from a host of a provider or service waits behind a consent placeholder named after it.

MCP tool `leitvia-get-consent-providers`, REST GET. Annotations: readonly true, destructive false, idempotent true.

No input.

Output: `providers` array of object { key, label\*, hosts\*, purpose, privacy }; `services` array of object { key, label }; `hash` string.
