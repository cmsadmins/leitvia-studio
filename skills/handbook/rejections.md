# Rejections

Generated from Leitvia Studio 1.3.60. Do not edit. What a retry rule means: [agent handbook](README.md#rejections).

Every rejection of Leitvia Studio with its retry rule and next ability. Messages with `%s` or `%d` get a value at run time; codes with several rows come from several places.

Leitvia uses one code per kind of failure, not one per field as in the error vocabulary of the WordPress agent skills (`<plugin>_missing_<field>`, `<plugin>_invalid_<field>`). A missing or invalid input is `leitvia_invalid_input` with the field in `data.target` and the cause in `data.reason`; an unknown object is `leitvia_not_found` or one of the `leitvia_unknown_*` codes; a missing permission is `leitvia_forbidden`, `leitvia_out_of_scope`, `leitvia_locked` or `leitvia_needs_human`.

| Code | HTTP | Retry | Next ability | Message |
|---|---|---|---|---|
| `leitvia_ai_failed` | 502 | `never` | set at run time | The AI provider refused or failed the request. An administrator checks the connector under Settings, Connectors; until then, call again with ai set to false. |
| `leitvia_ai_invalid_response` | 502 | `once` | set at run time | The AI model answered with data that does not match the expected format. Try once more, or call again with ai set to false. |
| `leitvia_ai_invalid_response` | 502 | `once` | set at run time | The AI model named no pattern of this site. Try once more, or call again with ai set to false. |
| `leitvia_ai_not_approved` | 503 | `never` | set at run time | The AI provider could not be reached, or an administrator has not approved this request for the connector yet. When the AI plugin is active, an administrator approves pending connector requests under Tools, Connector Approvals; until then, call again with ai set to false. |
| `leitvia_ai_timeout` | 504 | `once` | set at run time | The AI provider did not answer within %d seconds. Try once more, or call again with ai set to false. |
| `leitvia_ai_unavailable` | 424 | `never` | set at run time | AI is not available on this site: it needs WordPress with the AI client, AI switched on and a connector with a text model set up by an administrator under Settings, Connectors. Call again with ai set to false for a plan without AI. |
| `leitvia_attributes_lost` | 422 | `once` | none | Saving would drop attributes of these blocks, so nothing was changed. Send each block comment with its attributes as valid JSON, as serialize_block() writes it. |
| `leitvia_cancelled` | 409 | `never` | none | The person declined the call in the client. Nothing was changed; ask what they want before you try again. |
| `leitvia_check_failed` | 422 | `once` | `leitvia/set-alt-text` | A product image needs alt text that describes it. Set it with leitvia/set-alt-text and try again. |
| `leitvia_check_failed` | 422 | `once` | set at run time | Checks found problems. Fix them as each finding suggests and try again. |
| `leitvia_check_failed` | 422 | `once` | none | Checks found problems. Fix them as each finding suggests and try again. |
| `leitvia_check_failed` | 422 | `once` | `check` or none | Checks found problems. Fix them as each finding suggests and try again. |
| `leitvia_check_failed` | 422 | `once` | `leitvia/set-alt-text` | The featured image needs alt text that describes it. Set it with leitvia/set-alt-text, or mark the image as decorative. |
| `leitvia_check_failed` | 422 | `once` | none | The image needs alt text that describes it, or mark it as decorative. |
| `leitvia_cli_no_user` | 401 | `never` | none | This command acts as a user; run with --user=\<agent or admin>. |
| `leitvia_deprecated_pattern` | 422 | `once` | `leitvia/get-pattern` | The pattern is deprecated. Use its replacement instead. |
| `leitvia_empty_block` | 409 | `once` | `leitvia/get-page-outline` | The block holds no markup. Read the page outline again; if the block is still empty there, it has no content to keep. |
| `leitvia_execution_failed` | 500 | `never` | none | The change could not be saved; nothing was changed. Try again later. |
| `leitvia_execution_failed` | 500 | `never` | none | The file could not be saved; nothing was changed. Try again later. |
| `leitvia_execution_failed` | 500 | `never` | none | The image could not be cropped; nothing was changed. Try again later. |
| `leitvia_forbidden` | 403 | `never` | none | Agents change the site through the %s abilities only. |
| `leitvia_forbidden` | 403 | `never` | none | Previews of token sets need the right to edit theme options. |
| `leitvia_forbidden` | 403 | `never` | none | Sign in as a user who may edit content. |
| `leitvia_forbidden` | 403 | `never` | `leitvia/list-packages` | This change package belongs to someone else. |
| `leitvia_forbidden` | 403 | `never` | none | Your user may not assign terms of this taxonomy. |
| `leitvia_forbidden` | 403 | `never` | none | Your user may not change this object. |
| `leitvia_forbidden` | 403 | `never` | none | Your user may not create change packages. |
| `leitvia_forbidden` | 403 | `never` | none | Your user may not create the term %s. Use an existing term. |
| `leitvia_forbidden` | 403 | `never` | none | Your user may not give content to another author. |
| `leitvia_forbidden` | 403 | `never` | none | Your user may not publish this. |
| `leitvia_forbidden` | 403 | `never` | none | Your user may not read the versions of this site. |
| `leitvia_forbidden` | 403 | `never` | none | Your user may not read this token set. |
| `leitvia_forbidden_field` | 403 | `once` | none | Agents may not set showInAbilities. Whether content is open to AI abilities is the decision of a person; remove the field and try again. |
| `leitvia_foreign_host` | 422 | `once` | none | Media from other hosts is not fetched. Send the file as Base64 data instead. |
| `leitvia_import_conflict` | 409 | `never` | none | Targets changed on this site since the package was made. Nothing was imported; compare and export again. |
| `leitvia_invalid_input` | 422 | `once` | set at run time | Built at run time; read the message. |
| `leitvia_invalid_input` | 422 | `never` | none | Built at run time; read the message. |
| `leitvia_invalid_input` | 422 | `never` | `leitvia/list-content` | Built at run time; read the message. |
| `leitvia_invalid_input` | 422 | `never` | none | Coordinates are off on this site. Ask a person to choose a service under Sync, Coordinates; until then leave latitude and longitude empty. |
| `leitvia_invalid_input` | 422 | `never` | none | Every form key needs lower case letters, digits or hyphens and a group name of 1 to 160 characters. |
| `leitvia_invalid_input` | 422 | `never` | none | Every kind needs a key of lower case letters, digits or hyphens, a name, at least one duration of 15, 30 or 60 minutes, at least one time window as HH:MM from and to, and a place: phone, or link with an https link_url. |
| `leitvia_invalid_input` | 422 | `never` | none | Every product needs a unique article number, a name, a net price of 0 or more and one to six terms between 1 and 120 months. |
| `leitvia_invalid_input` | 400 | `never` | none | Name the steps the view showed. |
| `leitvia_invalid_input` | 422 | `never` | none | Name what to change: name, description, icon, page or order. |
| `leitvia_invalid_input` | 422 | `never` | none | No link needs or allows a repair. The report lists external links, internal links that could not be resolved and links that lead nowhere. |
| `leitvia_invalid_input` | 422 | `never` | set at run time | No post imported here has the address in parent_source, nor does an operation of the open package with this package key. Import the parent first, in the same package or applied, then the child; without that the child would land at the top level. |
| `leitvia_invalid_input` | 422 | `never` | none | Only pages have a kind. |
| `leitvia_invalid_input` | 400 | `once` | `leitvia/list-patterns` | Pass pattern slugs, or a post whose sections come from patterns. |
| `leitvia_invalid_input` | 422 | `once` | none | Send a package key: a migration always goes into a change package. |
| `leitvia_invalid_input` | 422 | `never` | none | Send at least one of vat, prenotice, retention, legal and legal_note. |
| `leitvia_invalid_input` | 422 | `never` | none | Send at least one product. A product left out goes back to draft. |
| `leitvia_invalid_input` | 400 | `once` | `leitvia/get-contract` | The contract has no page frame of this type. |
| `leitvia_invalid_input` | 400 | `once` | `leitvia/get-contract` | The contract has no page frame of this type. |
| `leitvia_invalid_input` | 422 | `never` | `leitvia/update-content` | The post holds no WPBakery or Impreza shortcodes, ACF blocks or ACF fields. Classic content moves with leitvia/update-content. |
| `leitvia_invalid_input` | 422 | `never` | none | The value is not valid for this option. |
| `leitvia_invalid_input` | 400 | `never` | none | This decision is not known. |
| `leitvia_invalid_input` | 422 | `never` | `leitvia/add-building-block` | This site has no documentation yet. Add the building block Documentation from leitvia/list-building-blocks with leitvia/add-building-block first. |
| `leitvia_invalid_input` | 422 | `never` | `leitvia/add-building-block` | This site has no products yet. Add the building block Products from leitvia/list-building-blocks with leitvia/add-building-block first; with WooCommerce use its products. |
| `leitvia_invalid_input` | 422 | `never` | `leitvia/add-building-block` | This site has no reviews yet. Add the building block Reviews from leitvia/list-building-blocks with leitvia/add-building-block first. |
| `leitvia_invalid_input` | 422 | `never` | none | With legal request, legal_note needs a way of contact (an email address, a phone number or a link), at most 500 characters. |
| `leitvia_invalid_input` | 422 | `never` | `leitvia/list-content` | order may only hold documentation pages directly below parent. Take the IDs from leitvia/list-content with post_type docs. |
| `leitvia_invalid_input` | 422 | `never` | `leitvia/list-content` | page must be a published page, or 0 for none. |
| `leitvia_invalid_input` | 422 | `never` | none | source must be the http or https address of the post on the old site. |
| `leitvia_invalid_package` | 400 | `never` | none | Built at run time; read the message. |
| `leitvia_locked` | 403 | `never` | none | Agents may not change this area. A person can change the autonomy settings. |
| `leitvia_media_in_use` | 409 | `never` | `leitvia/update-block` | Published content or the site still uses this file: %s. Replace it there with leitvia/update-block first, or send force to delete it anyway; those places then show no image. |
| `leitvia_media_missing` | 422 | `never` | `leitvia/add-media` | The file of a new image is not on this site any more. Add the image again with leitvia/add-media, then export. |
| `leitvia_media_missing` | 409 | `never` | none | The image this content uses is not applied yet. Apply the image first. |
| `leitvia_needs_approve` | 403 | `never` | none | Only a person who connected with the right to approve changes decides in a view. Decide in the Inbox. |
| `leitvia_needs_human` | 403 | `never` | none | A person has to decide on these operations in the inbox. Apply only operations whose area is autonomous for you. |
| `leitvia_no_contract` | 409 | `never` | none | The active theme has no valid Leitvia contract. Activate the Leitvia theme or fix its contract file. |
| `leitvia_no_replacement` | 422 | `never` | `leitvia/list-patterns` | There is no deprecated pattern with an available replacement here. Choose a pattern and change the section by hand or with leitvia/compose-page. |
| `leitvia_no_seo_plugin` | 422 | `never` | `leitvia/update-content` | No SEO plugin Leitvia Studio can write to is active (Yoast SEO, Rank Math, SEOPress or The SEO Framework). Change the excerpt with leitvia/update-content instead, or ask a person to install one. |
| `leitvia_no_seo_plugin` | 422 | `never` | none | The SEO plugin keeps the titles of archives in its own settings; report the wish to the person. |
| `leitvia_not_found` | 404 | `once` | `leitvia/get-page-outline` | The page has no block with this ID. Read the page outline for the current IDs. |
| `leitvia_not_found` | 404 | `once` | `leitvia/get-page-outline` | There is no block with this ID. Read the outline for the current IDs. |
| `leitvia_not_found` | 404 | `once` | `leitvia/list-media` | There is no file with this ID in the media library. List the media for the current IDs. |
| `leitvia_not_found` | 404 | `once` | `leitvia/list-patterns` | There is no pattern with this slug. List the patterns for the current slugs. |
| `leitvia_not_found` | 404 | `once` | `leitvia/list-content` | There is no post, page or entry with this ID that agents can read. Take the ID from leitvia/list-content; files go with leitvia/delete-media. |
| `leitvia_not_found` | 404 | `once` | `leitvia/get-contract` | There is no saved panel with this name. leitvia/get-contract lists the panels under megaMenu.panels. |
| `leitvia_not_found` | 404 | `never` | none | There is no stored order or quote request with this number. Handed-over orders are deleted after the retention period; the number stands in the confirmation and the ticket, such as 2026-00042. |
| `leitvia_not_found` | 404 | `once` | `leitvia/get-page-outline` | There is no top-level section with this ID. Read the outline for the current IDs. |
| `leitvia_out_of_scope` | 403 | `never` | none | Agents may not change this object. Only published or draft content of the allowed post types is open to them. |
| `leitvia_out_of_scope` | 403 | `never` | none | Agents may not read this object. Only published or draft content of the allowed post types is open to them. |
| `leitvia_out_of_scope` | 403 | `never` | none | Agents may not see or change this object. Only products, product categories and tags are open to them, never orders, refunds, coupons or customers. |
| `leitvia_out_of_scope` | 403 | `once` | none | Agents may not set this status. Use draft, pending or publish. |
| `leitvia_package_changed` | 409 | `once` | `leitvia/get-package` | The change package got new steps since the view showed it. Load it again and check them first. |
| `leitvia_package_closed` | 409 | `never` | `leitvia/get-package` | The change package is %s; this step is not possible in that state. |
| `leitvia_page_missing` | 409 | `never` | none | The page this setting points to is not applied yet. Apply the page first. |
| `leitvia_parent_missing` | 409 | `never` | none | The parent of this post is not applied yet. Apply the parent first. |
| `leitvia_pattern_not_approved` | 422 | `once` | `leitvia/list-patterns` | This pattern is not approved yet. Use an approved pattern, or ask a person to approve this one in the Lab. |
| `leitvia_pro_unmapped` | 422 | `once` | set at run time | Some content does not carry over, see unmapped. Show it to the person; send accept_unmapped only when they agree to lose it. |
| `leitvia_rate_limited` | 429 | `after:<seconds>` | none | Too many calls. Try again in %d seconds. |
| `leitvia_recipe_unavailable` | 422 | `never` | `leitvia/list-recipes` | Built at run time; read the message. |
| `leitvia_rollback_conflict` | 409 | `never` | `leitvia/get-package` | Someone changed the content after the package was applied. Nothing was rolled back; compare the changes and decide by hand. |
| `leitvia_seal_invalid` | 409 | `once` | none | The view showed this change package too long ago or not at all. Load it again and decide on what it shows. |
| `leitvia_stale_hash` | 409 | `once` | `leitvia/get-consent-providers` | The providers changed since they were read. Read them with leitvia/get-consent-providers and repeat the change on the current list with base_hash %s. |
| `leitvia_stale_hash` | 409 | `once` | `leitvia/get-content-model` | The site settings changed since they were read. Read them with leitvia/get-content-model and repeat the change on the current values with base_hash %s. |
| `leitvia_stale_hash` | 409 | `once` | none or `leitvia/get-page-outline` | The target changed since it was read. Read it again and repeat the change on the current state with base_hash %s. |
| `leitvia_stale_hash` | 409 | `once` | none or `leitvia/get-page-outline` | The target changed since it was read. Read it again and repeat the change on the current state. |
| `leitvia_static_markup` | 422 | `once` | `leitvia/get-block` | The text holds more than one link, so text cannot keep them. Change words in it with replace_text (find and with), which keeps links and inline formatting, or set a new link on a passage with add_link. |
| `leitvia_static_markup` | 422 | `once` | `leitvia/compose-page` | This change needs markup that cannot be rebuilt safely. Insert the pattern again with leitvia/compose-page, or change a block that supports it. |
| `leitvia_too_large` | 413 | `once` | none | The change is too large to compare (more than %d KB). Split it into smaller changes. |
| `leitvia_unknown_building_block` | 404 | `never` | none | There is no building block with this name. |
| `leitvia_unknown_package` | 404 | `never` | `leitvia/list-packages` | There is no change package with this ID. List the packages and use one of them. |
| `leitvia_unknown_pattern` | 404 | `never` | `leitvia/list-patterns` | The pattern leitvia/content-faq is not the one of the theme Leitvia, so the questions cannot go into it. |
| `leitvia_unknown_pattern` | 404 | `once` | `leitvia/get-contract` | There is no pattern with this name for a mega menu panel. Use one of the patterns the rejection lists in patterns. |
| `leitvia_unknown_pattern` | 404 | `once` | `leitvia/list-patterns` | There is no pattern with this name for this template part. List the patterns of its area and use one of them. |
| `leitvia_unknown_pattern` | 404 | `once` | none | There is no pattern with this name for this template. Use one of the patterns the rejection lists in patterns. |
| `leitvia_unknown_pattern` | 404 | `once` | `leitvia/list-patterns` | There is no pattern with this name. List the patterns and use one of them. |
| `leitvia_unknown_recipe` | 404 | `once` | `leitvia/list-recipes` | There is no recipe with this name. List the recipes and use one of them. |
| `leitvia_unsafe_content` | 422 | `once` | `leitvia/list-blocks` | A block comment is broken: its attributes are no valid JSON, or the comment does not close. Send each block comment as serialize_block() writes it. |
| `leitvia_unsafe_content` | 422 | `once` | `leitvia/list-blocks` | The content holds markup agents may not write: scripts, event attributes, Custom HTML blocks, classic blocks or Base64 data. Use registered blocks and media from the library. |

## Codes of other plugins

Other plugins may replace a rejection of Leitvia Studio with their own code. Over MCP, the server `leitvia` and the default server of the MCP Adapter (`mcp-adapter/execute-ability`) both return a rejection of a Leitvia ability in full, as JSON with code, message and data. Another plugin (MCP Access of the AI plugin) can list Leitvia tools on a server of its own, with the same checks but rejections without hash, next ability and retry rule. Rejections of the input or permission checks of WordPress core arrive before Leitvia Studio runs and carry only their message. Known codes:

| Code | HTTP | Retry | Plugin | What to do |
|---|---|---|---|---|
| `wpai_connector_not_approved` | 403 | `never` | AI 1.5 or later, over REST | The AI plugin blocked a request to the AI provider and replaced the answer. An administrator approves pending requests under Tools, Connector Approvals; until then, call again with ai set to false. |
