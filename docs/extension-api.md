# Leitvia Studio Extension API

Leitvia Studio is extended through eight typed registries. Studio itself uses the same registries as every add-on, so an add-on docks on without any change to Studio. This document describes API version 1 (`LEITVIA_STUDIO_API`); add-ons written for any version from `LEITVIA_STUDIO_API_MIN` up to it are accepted.

Studio contains no code for keys or paid unlocks. Add-ons that need one handle it themselves.

## Lifecycle

1. Studio boots on `plugins_loaded`.
2. On `init` (priority 5) Studio, always listed as the add-on `leitvia-studio`, fires `leitvia_studio_init` with the `LeitviaStudio\Extension\Studio` instance. This is the only moment in which add-ons and registry entries can register. Registering earlier or later triggers `_doing_it_wrong()` and is ignored.
3. On `init` (priority 20) entries of the `blocks` registry are registered with core.
4. On `wp_abilities_api_categories_init` and `wp_abilities_api_init` entries of the `abilities` registry are handed to the core Abilities API. Core builds its abilities registry once, on the first call to `wp_get_abilities()`, `wp_get_ability()` or a similar function. A call on `init` before priority 5 builds it before Studio's entries exist, so they never reach core; query abilities later.
5. All other registries are read by the Studio modules that need them, when they need them.

`leitvia_studio()` returns the same `Studio` instance at any time after `plugins_loaded`.

## Registering an add-on

```php
add_action(
	'leitvia_studio_init',
	static function ( \LeitviaStudio\Extension\Studio $studio ): void {
		$studio->extensions()->register(
			'my-addon',
			array(
				'label'           => __( 'My Add-on', 'my-addon' ),
				'version'         => '1.0.0',
				'requires_studio' => '0.1.0',
				'requires_api'    => 1,
			)
		);
	}
);
```

| Field | Type | Rule |
|---|---|---|
| slug (first argument) | string | Lowercase letters, digits and hyphens. Unique. Usually the plugin slug. `leitvia` and `leitvia-studio` are reserved. |
| `label` | string | Shown on the Studio settings screen. Not empty. |
| `version` | string | Semantic version of the add-on, for example `1.4.0`. |
| `requires_studio` | string | Lowest Studio version the add-on works with. Compared with `version_compare( LEITVIA_STUDIO_VERSION, $requires_studio, '>=' )`. |
| `requires_api` | int | Extension API version the add-on was written for. Accepted when `LEITVIA_STUDIO_API_MIN <= requires_api <= LEITVIA_STUDIO_API`. |

`register()` returns `true` when the add-on is registered and compatible. A malformed registration (missing field, bad slug, duplicate slug, wrong moment) triggers `_doing_it_wrong()` and returns `false`. An incompatible add-on is kept with `compatible => false` and `reason` set to `requires_studio` or `requires_api`, so the settings screen can tell the site owner what to update. An incompatible add-on cannot register registry entries; stop when `register()` returns `false`.

`$studio->extensions()->all()` returns every registered add-on with `slug`, `label`, `version`, `requires_studio`, `requires_api`, `compatible` and `reason`.

## Registries

`$studio->registry( $type )` returns one registry; an unknown type throws `InvalidArgumentException`. Each registry checks its required fields:

| Registry | Required fields | Used by |
|---|---|---|
| `abilities` | `args` (array, the arguments of `wp_register_ability()`), `group` (`read`, `setup`, `build` or `maintain`) | Core Abilities API |
| `blocks` | `path` (string, folder or `block.json` passed to `register_block_type()`) | Core block registry |
| `lab_panels` | `label` (string), `script` (string, script handle of the panel), `order` (int), `capability` (string) | Pattern Lab detail view |
| `token_sources` | `label` (string), `callback` (callable) | Design tokens |
| `sync_targets` | `label` (string), `export` (callable) | Sync |
| `checks` | `label` (string), `scope` (`token_set`, `pattern`, `content` or `page`), `callback` (callable) | Studio checks, Gateway pipeline |
| `recipes` | `label` (string), `path` (string, absolute path to a `recipe.json`) | Gateway abilities `leitvia/list-recipes` and `leitvia/apply-recipe` |
| `patterns` | `title` (string), `description` (string), `area` (`hero`, `content`, `teaser`, `proof`, `cta` or `offer`), `composition` (array) | Contract data (`addonPatterns`), composition checks, `leitvia/list-patterns` |

A `token_sources` callback takes no arguments and returns a list of `array( 'title' => string, 'document' => array )`, each document a DTCG token document. `POST /leitvia-studio/v1/token-sets` with `source`, `index` and an optional `title` creates a set from one of them; it passes the same checks as an import (size, depth, contract roles, CSS allowlist, contrast). Studio registers `leitvia/active-theme` and `leitvia/style-variations`.

A `sync_targets` entry gets `export( array $package )` with `id`, `title` and `document` (the stored token document) and returns the exported result or a `WP_Error`. Studio registers `leitvia/download`, which returns the document; `GET /leitvia-studio/v1/token-sets/<id>/export?download=1` sends it as `<slug>.tokens.json`. Every other target appears with its `label` as an action in the token list and exports through `?download=1&target=<name>` as `<slug>.<second part of the name>.tokens.json` (`my-addon/flat` gives `brand.flat.tokens.json`); an unknown target answers 404. The stored document is the imported one with only `$extensions.com.leitvia.contractVersion` set, `{}`, `[]` and `1.0` included. JSON answers of the REST API, the download among them, write whole floats as `1` because core encodes them without `JSON_PRESERVE_ZERO_FRACTION`; JSON treats both as the same number, so this is accepted. The size limit of 512 KB applies to the stored, pretty printed form; larger documents are rejected with status 413.

Checks with the scope `pattern` receive the block markup of a pattern and return a list of findings, each an array with `rule` and `match`; Studio's own entry `leitvia/tokens` works this way.

Checks with the scope `content` or `page` run before every write of an agent (Gateway pipeline, step Checks) and in the ability `leitvia/audit-site`. The callback gets `( string $content, array $context )`: the new block markup and a context with `post` (0 for a new post), `post_type` and `ability`. It returns a list of findings, each an array with `severity` (`error` rejects the write with `leitvia_check_failed`, `warning` turns a live write into a change package), `block` (a `metadata.leitviaId` or a path ID such as `p:0.2.1`, empty for the whole page), `message` and `fix`; `check` defaults to the entry name. `content` checks look at the markup alone, `page` checks at the page as visitors get it: Studio's `leitvia/page-structure` renders the block template with `do_blocks()`, the content in place of the post content block, without an HTTP request. A check that needs another plugin is registered only when that plugin is active; it is missing without it, never failing.

Studio's own entries: `leitvia/headings`, `leitvia/alt-text`, `leitvia/link-text`, `leitvia/block-validity`, `leitvia/content-tokens`, `leitvia/content-contrast` (scope `content`), `leitvia/page-structure` and `leitvia/composition` (scope `page`). They skip post types without the editor. `leitvia/composition` scores pages built from patterns (`metadata.patternName` of the root blocks) and warns below the minimum score of the gateway settings when the write also lowers the saved score. An image with an empty `alt` counts as decorative only with `metadata.leitviaDecorative` set to `true` on its block; without the mark it is a warning.

A field spec is one of `string` (not empty), `int`, `array`, `callable` or a list of allowed values. Additional fields are kept as they are.

```php
$studio->registry( 'checks' )->register( 'my-addon/contrast', $item, 'my-addon' );
$studio->registry( 'checks' )->get( 'my-addon/contrast' );       // Entry plus 'name' and 'owner', or null.
$studio->registry( 'checks' )->all( array( 'scope' => 'pattern' ) ); // Filter on scalar fields.
```

`register()` returns `false` and triggers `_doing_it_wrong()` when the name is invalid or taken, a required field is missing or invalid, the owner is not a registered, compatible add-on, or the call happens outside `leitvia_studio_init`.

### Naming

Entry names have the form `<owner>/<name>`, lowercase letters, digits and hyphens on both sides. The part before the slash is the slug of the registering add-on. Studio's own entries use the owner `leitvia-studio` and the prefix `leitvia/`; the slugs `leitvia` and `leitvia-studio` are reserved, so no add-on gets that prefix.

The owner argument is not authenticated: PHP cannot tell which plugin makes a call, and any code on the site can pass any registered slug. The prefix rule prevents accidental name collisions between add-ons, not deliberate misuse. Code running on the site is trusted as a whole, as everywhere in WordPress.

### Block catalog

Every entry of the `blocks` registry appears in the block catalog with its add-on as `owner`, so agents find add-on blocks next to Studio's own. The catalog reads the registered block type from core, never the files: name, title, description, category, `parent`, `ancestor`, the agent-relevant `supports`, the attributes of `block.json` as JSON schema (`type`, `enum`, `default`, `items`, `properties`, `source`) and the allowed children (`allowedBlocks`, otherwise the blocks naming it as `parent`). Core blocks Studio changes (`core/code`, `core/embed`, `core/tabs`) are listed with the owner `core` and an `extension` text.

Usage rules live in `catalog.json` next to `block.json`, because the `block.json` schema allows no own keys:

```json
{
	"usage": {
		"useWhen": "When the block is the right choice.",
		"avoidWhen": "When something else fits better, and what.",
		"a11y": "What authors must do to keep it accessible."
	}
}
```

Copy the file into the build next to `block.json` (Studio and add-ons with blocks add a `copy-webpack-plugin` pattern `blocks/*/catalog.json` to their webpack config). Without the file `usage` is `null`. `GET /wp-json/leitvia-studio/v1/blocks` (capability `edit_posts`) and `leitvia_studio_contract()->blocks()` return the catalog, described by `schemas/block-catalog.schema.json`; the filter `leitvia_studio_block_catalog` changes it.

### Abilities

Entries of the `abilities` registry are handed to `wp_register_ability()` on `wp_abilities_api_init`. Studio registers the ability category `leitvia` when at least one entry exists and uses it when `args` has no `category`.

When the entry is registered, before any hand-over, Studio checks that

- `args.permission_callback` is callable, and
- `args.meta.annotations` contains `readonly`, `destructive` and `idempotent` as booleans. Read abilities should also set `openWorldHint` to `false`, the key the MCP Adapter passes on to clients.

MCP clients assume the worst case when annotations are missing, so `register()` rejects an entry without them with `_doing_it_wrong()` and it never reaches core.

Studio never sets `meta.public`, `meta.show_in_rest` or `meta.mcp`. An ability is exposed to REST or other clients only when its own `args` say so; exposure is not a permission, `permission_callback` always applies. Studio bundles no MCP Adapter and does not require one: abilities reach MCP clients through the separately installed MCP Adapter plugin.

Add-ons that call AI models use the WordPress AI Client (`wp_ai_client_prompt()`) and the site's Connectors; Studio ships no provider integration.

### Recipes

A recipe bundles what a site needs to start: style variation, optional token set and content model, header and footer, pages from page frames and patterns, navigation, reading settings and options that make the site collect or send less data. The format is `schemas/recipe.schema.json`; paths inside a recipe (`tokenSet`, `contentModel`) are relative to the recipe file and never leave its folder. Studio registers its base recipes `leitvia/blog`, `leitvia/company`, `leitvia/publication`, `leitvia/documentation` and `leitvia/shop` (requires `woocommerce`) in the same registry, from `recipes/<name>/recipe.json`.

```php
$studio->registry( 'recipes' )->register(
	'my-addon/restaurant',
	array(
		'label' => 'Restaurant',
		'path'  => __DIR__ . '/recipes/restaurant/recipe.json',
	),
	'my-addon'
);
```

`leitvia/list-recipes` lists every entry with `owner`, `title`, `description`, `requires`, the page slugs, `available` and a `reason`: a recipe whose `requires` names a plugin that is not active (matched by its folder name, for example `woocommerce`) is listed with `available: false`, and `leitvia/apply-recipe` refuses it with `leitvia_recipe_unavailable`. An invalid recipe file is listed with the validation message as reason.

`leitvia/apply-recipe` writes nothing itself. It calls `leitvia/define-content-type` (`declaration`), `leitvia/create-token-set` (`title`, `document`), `leitvia/set-variation` (`variation`), `leitvia/update-template-part` (`part`, `pattern`), `leitvia/compose-page` for each page (`title`, `frame`, `sections`, `kind`, `slug`, `parent`, `template`) and `leitvia/update-navigation` (`items` with `label` and `url`), then sets the reading settings, the pages under `connect` (privacy policy, WooCommerce terms) and the options through the pipeline. A navigation slug that is no recipe page links to the published page with that path, such as the WooCommerce shop page. All calls share one package key (`recipe-<owner>-<name>` unless the input names one), so whatever autonomy does not let go live ends up in one change package. A step whose ability is not registered is reported as `unavailable`. Pages that exist (same page kind, otherwise same slug below the same parent) or wait in the open package are skipped, as are settings that already have their value; running a recipe twice adds nothing. `page_on_front`, `page_for_posts` and child pages wait for their page (`waiting`) until it exists, so run the recipe again after the package was applied. The result lists every step with `status` (`live`, `draft`, `package`, `exists`, `waiting`, `unavailable`, `rejected`), `target`, `code` and the package ID.

### Patterns

Section patterns of an add-on join the patterns of the theme and of Studio. An entry describes one pattern in the shape of the `patterns` of the contract: the entry name is the pattern slug, the required fields above plus the optional `useWhen`, `images` and `docs` (`avoidWhen`, `a11y`, `contentLength`, `replacedBy`). `leitvia_studio_contract()->data()` lists the entries as `addonPatterns` with `slug`, so `leitvia/check-composition`, the composition check of pages and `leitvia/compose-page` score them like theme patterns, and `leitvia/list-patterns` shows the registered ones with `source` `addon`. The registry only holds the metadata: the add-on registers the pattern itself with `register_block_pattern()` and decides when the inserter shows it (`inserter`). A page that holds the markup of a pattern keeps rendering whatever the add-on does later, because a pattern is block markup.

```php
$studio->registry( 'patterns' )->register(
	'my-addon/events-upcoming',
	array(
		'title'       => 'Upcoming events',
		'description' => 'Heading above the next events of the content model.',
		'area'        => 'content',
		'useWhen'     => 'An events page.',
		'composition' => array( 'tone' => 'surface', 'density' => 'dense', 'layout' => 'list', 'weight' => 'medium', 'role' => 'detail', 'width' => 'wide' ),
	),
	'my-addon'
);
register_block_pattern( 'my-addon/events-upcoming', array( 'title' => 'Upcoming events', 'filePath' => __DIR__ . '/patterns/events-upcoming.php' ) );
```

Recipes of the add-on may then use the slug in their sections. Edits of a section change `text`, `link_text` (the part of the text that stays linked, for example a phone number) and `url` at block IDs of the pattern outline, as in `leitvia/compose-page`.

The Lab import (`POST /leitvia-studio/v1/patterns/import`, `Pattern_IO::import()`) passes the decoded file through the filter `leitvia_studio_import_pattern_file` before it checks it. An add-on returns the file in Studio's format (`{"__file":"wp_block","title","content","syncStatus","leitvia":{…}}`) for its own format, the input unchanged for anything else, or a `WP_Error` to refuse the file with its message. The usual checks and limits apply to the result; nothing arrives approved.

```php
add_filter(
	'leitvia_studio_import_pattern_file',
	static fn( $data ) => is_array( $data ) && 'my-format' === ( $data['type'] ?? '' ) ? my_addon_to_pattern_file( $data ) : $data
);
```

## Gateway hooks

Add-ons dock onto the gateway through hooks, for example to run one workflow on several sites; none of them bypasses the pipeline.

| Hook | Type | Arguments | When |
|---|---|---|---|
| `leitvia_studio_gateway_executed` | action | `Operation $op`, `array $result` | After an operation was written live, as a draft or as a change package. |
| `leitvia_studio_package_created` | action | `int $id`, `array $entry` (the first operation) | After a change package was created. |
| `leitvia_studio_package_applied` | action | `int $id` | After a change package was applied, fully or in part. |
| `leitvia_studio_package_rolled_back` | action | `int $id` | After an applied change package was rolled back. |
| `leitvia_studio_gateway_areas` | filter | `array $levels` (level by area), `array $settings` | When the autonomy level of an area is read. Add the area of an add-on or cap levels with a policy shared by several sites. `admin` and areas without a valid level (`locked`, `suggest`, `draft`, `autonomous`) stay locked. |
| `leitvia_studio_gateway_post_types` | filter | `list<string> $post_types` | Post types open to agents; blocked types are dropped whatever the filter returns. |
| `leitvia_studio_ai_note` | filter | `string $note`, `int $post_id` | Text of the note shown before content that `leitvia/create-content` marked with `ai_generated` (meta `leitvia_ai_generated`); an empty string hides it. Deleting the meta in the custom fields of a post removes the note for that post. |
| `leitvia_studio_gateway_content_kind` | filter | `string[] $kinds`, `Operation $op`, `array $before`, `array $after` | Content kinds of an operation; each caps the level at `suggest` unless unlocked. |
| `leitvia_studio_recipe_unavailable` | filter | `string $reason`, `string $name`, `array $recipe` | Why a recipe cannot be applied on this site, `''` when it can. A non-empty reason lists the recipe with `available: false` and makes `leitvia/apply-recipe` refuse it with `leitvia_recipe_unavailable`. An add-on locks its own recipes here. |
| `leitvia_studio_recipe_steps` | filter | `array $steps`, `string $name`, `array $recipe`, `string $key` (package key), `array $pages` (page ID by recipe slug, 0 while not on the site) | After the steps of a recipe run, before the report. An add-on adds the steps of its own recipes (for example sample content), each written through an ability or `Pipeline::run()` with the package key and reported in the shape of the other steps. |

A change package is a private post of the type `leitvia_package` whose author is the user who caused it. Meta `_leitvia_package_state` holds `open`, `applied`, `partial`, `rejected` or `rolled_back`, meta `_leitvia_package_key` the package key. `post_content` holds the operations as a JSON list, each with `id` (from 1), `ability`, `area`, `target` (`post` and `post_type`, or `option`; `media` names the file of an image of `leitvia/add-media` in the same package that becomes the featured image when applied), `base_hash` (the hash the change was based on, empty for a new object), `level`, `kinds`, `state` (the complete new state: the post fields `post_title`, `post_content`, `post_excerpt`, `post_status`, `post_name`, `post_parent`, `menu_order`; for posts, pages and entries of the content model also `post_date`, `terms` (term IDs by taxonomy) and under `meta_input` the featured image and the fields of the content model; for new posts any `meta_input`; or `value` for an option) and `findings`. Because every operation carries its base hash and full new state, a package can move between sites, for example from a staging copy to the live site, and be applied there only where the base hash still matches.

Applying (`leitvia/apply-package`, or `POST /leitvia-studio/v1/packages/<id>/apply` with an optional list `operations` for people with `edit_theme_options`) replays each open operation through the pipeline live: an operation whose target changed since `base_hash` stays open with `conflict` (`op`, `base_hash`, `current_hash`), one the pipeline rejects otherwise with `error`, the others get `applied` (`post`, the `hash` after the write, `snapshot`). The package becomes `applied` when nothing is open, else `partial`. Rolling back (`leitvia/rollback`, `…/rollback`) writes the snapshots back newest first, deletes posts the package created and only runs when every target still has the hash the package left; otherwise it fails with `leitvia_rollback_conflict` and the `conflicts` (`op`, `applied_hash`, `current_hash`, `diff`) and writes nothing. Rejecting (`…/reject`) writes nothing. Agents apply and roll back only operations whose autonomy level is `autonomous` at that moment, anything else is `leitvia_needs_human` with a link to the inbox. `leitvia/get-package` shows `applied` (`post`, `hash`) per applied operation, so an agent learns the ID of a post the package created, and a block diff per open operation (`id`, `change` of `added`, `removed`, `moved` or `changed`, `before_text`, `after_text`, `attrs_changed`), up to 1 MB per operation (`leitvia_too_large` above). `?leitvia_package=<id>&_wpnonce=…` (the `preview` link of a package) shows the changed pages with the open operations applied in memory, only to users who may edit them. An image of `leitvia/add-media` that waits in a package lies in a folder with a random name below `uploads/leitvia-studio/private/` (before 1.3.10 `uploads/leitvia-private/`, moved on update) and moves into the uploads folder when the operation is applied; rejecting the package deletes it. A package moved to another site carries the path, not the file.

## JavaScript API

The script handle `leitvia-studio-api` provides `window.leitviaStudio`. Add the handle as a dependency of your panel script.

```js
const { registerLabPanel, getLabPanels } = window.leitviaStudio;

// The name matches the PHP lab_panels entry; the PHP entry decides who sees the panel.
registerLabPanel( 'my-addon/notes', NotesPanel );
```

- `registerLabPanel( name, Component )` registers a React component. A second registration with the same name throws. The Lab detail view shows the panels as tabs below the preview, sorted by `order`, and passes the props `pattern` (the item of `GET /leitvia-studio/v1/patterns`), `block` (the `wp_block` post in the `edit` context, or `null` for theme patterns) and `onChange` (reloads both). Studio enqueues the `script` handle of every panel the user may see on the Lab screen.
- `getLabPanels()` returns a `Map` of name to component, passed through the filter `leitvia-studio.labPanels` of `@wordpress/hooks`.

## Consent for third-party content

Studio replaces every `core/embed` block from another host with a placeholder while the setting "Ask visitors before loading embedded content from other sites" (`consent_embeds`) is on. The placeholder names the provider, links to its privacy policy and to the content, and keeps the embed in a `<template>` until the visitor loads it. "Always allow" stores the service key in `localStorage['leitvia-consent:<path of the site>']` (a JSON list, for example under `leitvia-consent:/`); the block `leitvia/consent-settings` lists these keys and revokes them.

Services come from the filter `leitvia_studio_consent_services`: an array keyed by service with `label`, `hosts` (host names, subdomains included), `privacy` (URL of the privacy policy) and an optional `purpose` (one sentence for the placeholder). An embed from a host without an entry gets a placeholder named after the host, with the host as its key.

```php
add_filter( 'leitvia_studio_consent_services', function ( array $services ): array {
	$services['peertube'] = array(
		'label'   => 'PeerTube',
		'hosts'   => array( 'video.example.org' ),
		'privacy' => 'https://video.example.org/about/instance',
	);
	return $services;
} );
```

`leitvia_studio_consent_placeholder( $service, $html, $fallback_url )` wraps any other third-party HTML the same way and loads the script module `@leitvia/consent`.

A consent management platform docks on through two DOM events:

- `leitvia:consent-request` bubbles from the placeholder (`.leitvia-consent`) before Studio inserts content, after a click or for a service the visitor always allows. It is cancelable and carries `detail.service`. Call `preventDefault()` to stop the insertion, for example to ask in your own dialog first.
- `leitvia:consent-granted`, dispatched by the platform on `document` with `detail.service`, loads every placeholder of that service on the page without asking again. Dispatch it after the module ran (after `DOMContentLoaded`), for example from your platform's consent callback.

```js
document.addEventListener( 'leitvia:consent-request', ( event ) => {
	if ( ! myCmp.hasConsent( event.detail.service ) ) {
		event.preventDefault();
		myCmp.ask( event.detail.service ); // On yes, the CMP dispatches leitvia:consent-granted.
	}
} );
myCmp.onConsent( ( service ) =>
	document.dispatchEvent( new CustomEvent( 'leitvia:consent-granted', { detail: { service } } ) )
);
```

## Brand

`leitvia_studio_brand()` returns the name, menu title, logo URL and support link Studio shows to people. The filter `leitvia_studio_brand` changes them:

```php
add_filter( 'leitvia_studio_brand', function ( array $brand ): array {
	$brand['name']        = 'Acme Sites';
	$brand['logo_url']    = wp_get_attachment_image_url( 42, 'medium' );
	$brand['support_url'] = 'https://acme.example/help/';
	return $brand;
} );
```

| Key | Default | Where it shows |
|---|---|---|
| `name` | `Leitvia Studio` | Page title and product line of the Studio screens, texts of the Agents, Settings and Lab screens, privacy exports, the name of the application passwords Studio creates, descriptions of abilities. `leitvia_studio_brand_short()` (`Leitvia` under the default) names the block and ability category, the MCP server, the agent role (`<short> Agent`), the audit log export and the rejection `leitvia_forbidden`. |
| `menu_title` | the name | Admin menu. |
| `logo_url` | `''`, the Leitvia mark | Product line of the Studio screens, 20 px high, decorative. With a logo the admin menu shows a neutral dashicon. Use an image of the media library so no request leaves the site. |
| `support_url` | `''`, no link | Link "Support" next to the name. Under the default brand the line links to the maker instead. |

Empty or non-string values keep the default; unsafe URLs become `''`. Slugs, ability names, the text domain and stored role names never change.

## Fonts for token sets

A token set names its font families by role (`fontFamily.body`, `heading`, `ui`, `mono`). Studio gives a family its font faces when the theme or one of its style variations bundles it; other families fall back to the system font with the warning `font_not_bundled`. An add-on that ships its own fonts adds them with the filter `leitvia_studio_bundled_fonts`: an array of `fontFace` lists in the format of `theme.json`, keyed by the lowercased first family name, with absolute URLs of this site as `src`. Families of the theme always win.

```php
add_filter( 'leitvia_studio_bundled_fonts', function ( array $fonts ): array {
	$fonts['instrument serif'] = array(
		array(
			'fontFamily'   => 'Instrument Serif',
			'fontStyle'    => 'normal',
			'fontWeight'   => '400',
			'fontDisplay'  => 'swap',
			'src'          => array( plugins_url( 'fonts/instrument-serif-latin-400-normal.woff2', __FILE__ ) ),
			'unicodeRange' => 'U+0000-00FF, U+0131, U+0152-0153, U+2000-206F, U+20AC, U+2122',
		),
	);
	return $fonts;
} );
```

Activating the token set copies the faces into the global styles, so the font keeps loading from the add-on folder; serve it from this site, never from another host.

## Versioning and deprecations

`LEITVIA_STUDIO_API` changes only when a change breaks existing add-ons. Studio keeps accepting the previous API versions for two major Studio versions after such a change; `LEITVIA_STUDIO_API_MIN` is raised only then. Deprecated functions, fields and hooks keep working with a `_deprecated_function()` or `_deprecated_argument()` notice for two major Studio versions before they are removed. New registries and optional fields are added without changing the API version.

## REST

`GET /wp-json/leitvia-studio/v1/extensions` (capability `manage_options`) returns `extensions` (the list from `extensions()->all()`) and `registries` (per registry the entry names with their owner). Callables are never part of the response. `GET /wp-json/leitvia-studio/v1/blocks` returns the block catalog, see above.

## Complete example

The example add-on ships with the Studio tests (`tests/fixtures/addon/leitvia-studio-example-addon.php`):

```php
<?php
/**
 * Plugin Name:       Leitvia Studio Example Add-on
 * Description:       Shows how an add-on docks onto the Leitvia Studio Extension API. Used in tests only.
 * Version:           1.0.0
 * Requires at least: 7.1
 * Requires PHP:      8.2
 * Requires Plugins:  leitvia-studio
 * Author:            CMS ADMINS
 * License:           GPL-2.0-or-later
 * Text Domain:       leitvia-studio-example-addon
 *
 * @package LeitviaStudioExampleAddon
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'leitvia_studio_init',
	static function ( \LeitviaStudio\Extension\Studio $studio ): void {
		$slug = 'leitvia-studio-example-addon';
		// An incompatible add-on is listed with its reason and cannot register entries.
		if ( ! $studio->extensions()->register(
			$slug,
			array(
				'label'           => 'Leitvia Studio Example Add-on',
				'version'         => '1.0.0',
				'requires_studio' => '0.1.0',
				'requires_api'    => 1,
			)
		) ) {
			return;
		}

		$studio->registry( 'checks' )->register(
			$slug . '/always-ok',
			array(
				'label'    => 'Always OK',
				'scope'    => 'token_set',
				'callback' => static fn(): array => array(),
			),
			$slug
		);

		// The add-on registers this script handle itself; it calls window.leitviaStudio.registerLabPanel().
		$studio->registry( 'lab_panels' )->register(
			$slug . '/notes',
			array(
				'label'      => 'Notes',
				'script'     => 'leitvia-studio-example-addon-notes',
				'order'      => 50,
				'capability' => 'edit_theme_options',
			),
			$slug
		);
	}
);
```

A real add-on translates its labels with its own text domain and registers the panel script on `admin_enqueue_scripts` with `leitvia-studio-api` as a dependency.
