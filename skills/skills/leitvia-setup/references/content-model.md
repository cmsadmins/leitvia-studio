# Content model declaration

Details for `leitvia-setup`, step 2. Read it before the first `leitvia/define-content-type`. The schema of Leitvia Studio and its checks on the server are the source of this page; a rejection `leitvia_check_failed` names the path (`/postTypes/course/fields/course_level`) and the rule.

## Example: courses with an instructor

```json
{
	"version": 1,
	"postTypes": {
		"course": {
			"label": "Courses",
			"singularLabel": "Course",
			"public": true,
			"slug": "course",
			"supports": [ "title", "editor", "excerpt", "thumbnail" ],
			"icon": "dashicons-welcome-learn-more",
			"fields": {
				"course_date": { "type": "date", "label": "Date", "required": true },
				"course_start": { "type": "time", "label": "Start time" },
				"course_duration": { "type": "text", "label": "Duration", "maxLength": 60 },
				"course_price": { "type": "number", "label": "Price in euros", "min": 0 },
				"course_seats": { "type": "integer", "label": "Free places", "min": 0, "max": 30 },
				"course_level": {
					"type": "select",
					"label": "Level",
					"options": { "beginner": "Beginner", "intermediate": "Intermediate", "advanced": "Advanced" }
				}
			}
		},
		"instructor": {
			"label": "Instructors",
			"singularLabel": "Instructor",
			"public": true,
			"slug": "instructor",
			"supports": [ "title", "editor", "thumbnail" ],
			"fields": {
				"instructor_craft": { "type": "text", "label": "Craft", "maxLength": 80 }
			}
		}
	},
	"taxonomies": {
		"course_topic": { "label": "Topics", "singularLabel": "Topic", "public": true, "slug": "topic", "postTypes": [ "course" ] }
	},
	"relations": {
		"course_instructor": { "label": "Instructor", "from": "course", "to": "instructor" }
	}
}
```

An entry of it with `leitvia/create-content`: `"post_type": "course"`, `"fields": {"course_date": "2026-11-14", "course_start": "10:00", "course_level": "beginner", "course_seats": 8, "course_instructor": [42]}`, `"terms": {"course_topic": ["Bookbinding"]}`.

## Sections

`version` is required and always `1`. The others are optional: `postTypes`, `taxonomies`, `relations`, `settings`. `leitvia/define-content-type` adds entries by their key and keeps the others. A post type or taxonomy you send again is merged: the keys you send change, fields, repeaters and supports you leave out stay. A relation or setting you send replaces that one entry whole. Nothing is removed through the ability; a person removes fields on the Model screen.

Fields belong to the post types of the model. `page` and `post` get no fields, and a relation starts at a type of the model (`to` may be `page`). A value that every page needs, such as opening hours or a phone number, is a setting with `bindable: true`.

### postTypes

Key: 2 to 20 characters, `a-z`, `0-9`, `_`, starting with a letter, not starting with `wp_`.

| Key | Meaning |
|---|---|
| `label`, `singularLabel` | Required. Plural and singular name, no `<` or `>`. |
| `description` | Up to 500 characters. |
| `public` | Default false: no URL, only editors read the entries. True gives entry pages at `/<slug>/<entry>/`. |
| `hasArchive` | Only with `public`: an archive at `/<slug>/`. Leave it off when a page lists the entries ([entries](../../leitvia-page/references/entries.md#slugs)). |
| `slug` | URL part, `a-z`, `0-9`, `-`; default the key. Public types and taxonomies need different slugs, and none may be the slug of a published top-level page: its subpages would lead to a 404, so the ability refuses it. |
| `withFront` | Only with `public`, also on taxonomies: addresses start with the prefix of the post permalinks (`/blog/<slug>/<entry>/`). Off by default, so entries sit at `/<slug>/<entry>/`. |
| `singlePages` | Only with `public`; `false` gives entries no address of their own, no single template and no entry in search or sitemaps. Pages list them with a query loop, which keeps the type, and bindings. Link no title of such an entry: the link would lead nowhere. Use it for FAQs, testimonials, team members without a page. A type with `public` false shows nothing to visitors. |
| `hierarchical` | Entries have parents, like pages. |
| `supports` | Any of `title`, `editor`, `author`, `thumbnail`, `excerpt`, `comments`, `revisions`, `page-attributes`. Default `title`, `editor`. A featured image needs `thumbnail`. |
| `icon` | A Dashicon, `dashicons-<name>`. |
| `fields` | Fields of the entries, see below. |
| `repeaters` | Rows of a sub type per entry (an agenda of an event): key, `postType`, `label`, `singularLabel`, `fields`. Write the rows with `rows` in `leitvia/create-content` or `leitvia/update-content`: a list of `title` and the fields of the repeater, which replaces the rows in its order. |
| `showInAbilities` | Set only by a person, see below. |

### Fields

Key: 3 to 64 characters, `a-z`, `0-9`, `_`, starting with a letter; prefix it with the type (`course_date`). Query words of WordPress such as `title`, `name`, `type`, `status`, `order` or `year` are rejected as keys of types, taxonomies, fields and relations.

| Type | Value in `fields` of create-content | Extra keys |
|---|---|---|
| `text`, `textarea` | Text; a `textarea` with one item per line is the list field of an entry ([binding](../../leitvia-page/references/entries.md#values-of-fields-on-the-front-end)) | `maxLength` |
| `email`, `url` | An e-mail address, a URL | |
| `integer`, `number` | A number | `min`, `max` |
| `boolean` | `true` or `false` | |
| `select` | One key of `options` | `options` (required): `{"<key>": "<label>"}`, keys `a-z`, `0-9`, `_`, `-` |
| `date` | `YYYY-MM-DD` | |
| `datetime` | `YYYY-MM-DDTHH:MM:SS` | |
| `time` | `HH:MM` | |
| `image` | Attachment ID of the media library | |

Every field takes `label` (required), `description` and `required`. A required field must have a value before an entry is published; a draft may leave it empty. There is no type for prices or currencies: use `number`; the page adds the currency with the binding argument `suffix` ([entries](../../leitvia-page/references/entries.md#values-of-fields-on-the-front-end)).

### taxonomies

Key: 2 to 32 characters, same characters as post types. Required: `label`, `singularLabel`, `postTypes` (post types of the model, or `post`). Optional: `public`, `hierarchical`, `slug`, `description`. A name no term has yet creates the term with the entry in create-content and update-content, in a change package when it is applied; hierarchical terms land at the top level and need a user who may manage them. There is no ability for a term on its own or for its parent.

### relations

A link from entries of one type to entries of another, stored on the `from` type as a list of IDs. Key like a field key; it must not equal a field key of the `from` type. Required: `label`, `from` (a type of the model), `to` (a type of the model, or a public type such as `page`). `multiple: true` allows more than one target; without it at most one. In create-content the relation is a field: `"course_instructor": [42]`.

### settings

Values of the whole site, such as a phone number, by key with the field keys above. `bindable: true` lets blocks show the value to visitors through the source `leitvia/setting` ([bindings](../../leitvia-page/references/entries.md#values-of-fields-on-the-front-end)); a setting without it never reaches a page. Set the values with `leitvia/update-site-values`: `values` by key, `base_hash` the `settings_hash` of `leitvia/get-content-model`. It always waits in a change package for a person.

## showInAbilities

Agents may not set `showInAbilities`; a declaration that holds it is rejected with `leitvia_forbidden_field`. The abilities of Leitvia Studio (create-content, list-content, update-content and the others) reach every type of the content model without it. The flag only opens a type to abilities outside Leitvia, of WordPress itself or other plugins; that is a decision of the person and no step of a Leitvia workflow.

## Hash

`leitvia/get-content-model` returns `hash`; send it as `base_hash`. A `leitvia_stale_hash` means a person changed the model since you read it: read it again and send the call once more.

## Coming from the WordPress skills

On a Leitvia site the content model replaces code: no `register_post_type()`, `register_post_meta()`, ACF or a plugin of your own for fields. Leitvia Studio registers every field with `show_in_rest`, a schema, a sanitize callback for its type and an auth callback; read and write values through the abilities, not through `/wp/v2`.

| WordPress way | On a Leitvia site |
|---|---|
| `register_post_type()` | `postTypes` in `leitvia/define-content-type` |
| `register_post_meta()` with `show_in_rest` | `fields` of the type |
| `register_taxonomy()` | `taxonomies` |
| meta with a list of post IDs | `relations` |
| `register_setting()` | `settings` with `bindable`, values with `leitvia/update-site-values` |
| `update_post_meta()`, `meta` over REST | `fields` (and `rows` for repeaters) in `leitvia/create-content` and `leitvia/update-content` |
| binding `core/post-meta` | binding `leitvia/meta`; `core/post-meta` shows the same values, but not relations |
| `core/pattern-overrides` | not used: abilities copy patterns into pages; values per entry are fields, values per site are settings |

`leitvia/get-content-model` lists the binding sources and the attributes WordPress binds per block under `bindings`.
