# leitvia/get-site-health

Generated from Leitvia Studio 1.3.60. Do not edit. Group: Read. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Get site health.** Returns the versions of WordPress, PHP, the theme and the plugins and the updates WordPress knows of, read from its last update check; nothing is requested. mcp gives the address of the MCP server of Leitvia Studio (url), its protocol revisions (protocols) and the version of an active MCP Adapter (version, empty without one); the server answers without the adapter. Needs a user who may view the Site Health checks, usually an administrator, or the agent role.

MCP tool `leitvia-get-site-health`, REST GET. Annotations: readonly true, destructive false, idempotent true.

No input.

Output: object.
