# Leitvia Studio

A gateway for AI agents, a pattern lab, design tokens and site recipes for WordPress block themes, stored in WordPress core structures. Agents work through the abilities of the WordPress Abilities API, over MCP (with the plugin MCP Adapter) or REST, and every write runs through checks, an autonomy level per area, a snapshot and, where needed, a change package that a person reviews.

- Website and documentation: https://leitvia.com
- Theme: [Leitvia](https://github.com/cmsadmins/leitvia)
- Agent skills: [Leitvia Skills](https://github.com/cmsadmins/leitvia-skills)
- Child theme for patterns and templates kept as files: [Leitvia Child](https://github.com/cmsadmins/leitvia-child)
- Starter plugin for blocks of your own: [Leitvia Block Starter](https://github.com/cmsadmins/leitvia-block-starter)
- Developer guide: https://leitvia.com/docs/developers/

## WordPress standards

Leitvia supports the standards WordPress sets for AI agents and will keep supporting them: the skills of [WordPress/agent-skills](https://github.com/WordPress/agent-skills), the Abilities API with its REST routes under `/wp-json/wp-abilities/v1` and the default server of the MCP Adapter. The [developer guide](https://leitvia.com/docs/developers/) shows how the packages fit together, with real calls. Every ability carries complete annotations and described input fields, and every REST route of Studio returns its schema on OPTIONS.

## Install

Requires WordPress 7.1 and PHP 8.2 or later. Works with any block theme, fits best with Leitvia.

Until the plugin is listed on WordPress.org, download `leitvia-studio-<version>.zip` from the [releases](https://github.com/cmsadmins/leitvia-studio/releases) and upload it under Plugins, Add New, Upload Plugin. Use the zip: this repository holds the source without the built scripts.

## Development

This repository is a mirror of the package in the Leitvia monorepo, where it is built and tested. Report problems and accessibility barriers as issues here or through https://leitvia.com.

## Copying

GPL-2.0-or-later, like WordPress.
