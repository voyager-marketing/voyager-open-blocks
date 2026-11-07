# Use Voyager Open Blocks in your theme

Your theme or plugin can bind core blocks to the site's business details or to a page's own fields, and ship its own patterns beside the pack's. Three recipes below cover that. The [API reference](#api-reference) after them lists everything the plugin exposes, read from `inc/*.php`.

Two things hold for every recipe:

- **The plugin must be active.** When it is not, WordPress has no `voyager/*` source, so a bound block shows the text you authored. Always author real fallback text, never an empty element.
- **An empty value shows your text too.** A source that returns nothing leaves the authored text in place on the front end. In the editor, an empty Site Data value shows your text, and an empty post meta field shows the field's label.

## Recipe 1: bind a paragraph or a button to Site Data

Put this in a template, a template part or a pattern. The paragraph shows the business hours. The button shows the phone number and dials it.

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"hours"}}}}} -->
<p>Monday to Friday, 9am to 5pm</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"text":{"source":"voyager/site-data","args":{"key":"phone"}},"url":{"source":"voyager/site-data","args":{"key":"phone_link"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Call us</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
```

Args are always `{"key": "<key>"}`. The 18 keys are in [Site Data keys](#site-data-keys). Use `phone_link` and `email_link` for a `url`: they carry the `tel:` and `mailto:` prefixes.

## Recipe 2: make a post meta field bindable

Call the helper on `init`, then bind the field with `voyager/post-meta-text`. Each page then shows its own value.

```php
add_action(
	'init',
	static function (): void {
		if ( function_exists( 'voyager_open_blocks_register_bindable_meta' ) ) {
			voyager_open_blocks_register_bindable_meta( 'page', 'service_eyebrow', 'Service eyebrow' );
		}
	}
);
```

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/post-meta-text","args":{"key":"service_eyebrow"}}}}} -->
<p>Our services</p>
<!-- /wp:paragraph -->
```

The rules the helper applies:

- Pass `''` as the post type for every post type. The helper does not check that the post type exists.
- The key must not be empty, start with `_` or contain a dot. A refused key returns `false` and raises a `_doing_it_wrong` notice.
- It registers single, string, REST-visible post meta with a `''` default and `sanitize_textarea_field`. Only users who can edit the post may change it.
- Call it before `init` priority 15. That is when rows saved on Settings > Site Data register, and a key you register in code wins over a row for the same post type.
- A field that holds a JSON string can be read one value at a time with a dot path, such as `{"key": "service_stats.0.value"}` for a `service_stats` field.
- In the editor, a field bound without a dot path is editable in place: typing in the block writes the post's meta. Dot paths are read-only.

The same fields can be added with no code under Settings > Site Data > Bindable post meta.

## Recipe 3: ship your own pattern

Save this as `patterns/footer-details.php` in your theme. WordPress registers it from the header. The `voyager-open` category lists it beside the pack's sections.

```php
<?php
/**
 * Title: Footer details
 * Slug: my-theme/footer-details
 * Categories: voyager-open
 */
?>
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"copyright"}}}}} -->
<p>© Example Co.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"phone"}}}}} -->
<p>(555) 010-0100</p>
<!-- /wp:paragraph -->
```

From a plugin, call `register_block_pattern( 'my-plugin/footer-details', [ 'title' => 'Footer details', 'categories' => [ 'voyager-open' ], 'content' => $markup ] )` on `init` instead.

Check the markup before you ship it. Save the part under `?>` as an `.html` file and run `npx block-runner validate footer-details.html --strict`. Run on the `.php` file itself, the validator reads the header as an unknown block. Use core blocks and your theme's presets, as the pack's own patterns do.

The abilities list and insert only the pack's `voyager-open/*` patterns, so yours is for the editor's inserter. An agent can still `bind` its blocks once it is on a page.

## API reference

The plugin is pre-1.0. The supported surface is the helper function, the two binding sources and their args, the options, the filters, the abilities, the icon block, the block styles and the pattern slugs. Functions under the `VoyagerOpenBlocks\` namespace work and are listed where a theme might call them, but they can change in a minor release.

### Binding sources

| Source | Editor label | Args | Context | Registered |
|---|---|---|---|---|
| `voyager/site-data` | Site data | `{"key": "<key>"}`, one of the [Site Data keys](#site-data-keys) | none | `init` priority 20, unless another plugin registered it first |
| `voyager/post-meta-text` | Post meta (allowlisted) | `{"key": "<meta key>"}`, or `{"key": "<meta key>.<path>"}` into a JSON value | `postId`, `postType` | `init` priority 20, unless another plugin registered it first |

Both return `null` when there is nothing to show, and WordPress then keeps the attribute as authored.

`voyager/site-data` returns `null` for an unknown key, and for a value that is empty or only whitespace after the `voyager_site_data_value` filter. Values come back raw. WordPress escapes them where they land: `wp_kses_post` for rich text, the HTML API for attributes.

`voyager/post-meta-text` reads the post in block context (`postId`), so it works in a Query Loop, and falls back to the current post. It returns `null` when:

- the key is empty, or the part before the first dot is not allowlisted for the post's type,
- the stored value is not a string, or is empty,
- a dot path does not exist in the JSON, or ends on an array, an object or an empty value,
- the post is password protected and the visitor cannot edit it.

### Site Data keys

| Key | Value | Stored in |
|---|---|---|
| `company` | Site Title | `blogname` (Settings > General) |
| `tagline` | Tagline | `blogdescription` (Settings > General) |
| `phone` | Phone as entered | `voyager_site_phone` |
| `phone_link` | `tel:` plus the digits and `+` of the phone, `''` when there are none | derived from `voyager_site_phone` |
| `email` | Email address | `voyager_site_email` |
| `email_link` | `mailto:` plus the email, only when `is_email()` accepts it | derived from `voyager_site_email` |
| `address` | Street address | `voyager_site_address` |
| `address_city` | City | `voyager_site_address_city` |
| `address_state` | State or region | `voyager_site_address_state` |
| `address_zip` | Postal code | `voyager_site_address_zip` |
| `address_locality` | "City, ST 12345", with no stray comma when a part is empty | derived from the three above |
| `hours` | Business hours | `voyager_site_hours` |
| `year` | Current year, from `wp_date( 'Y' )` | derived |
| `copyright` | "© 2026 Company", with the current year and Site Title | derived |
| `social_facebook` | Profile URL, through `esc_url_raw()` | `voyager_site_social_facebook` |
| `social_instagram` | Profile URL, through `esc_url_raw()` | `voyager_site_social_instagram` |
| `social_linkedin` | Profile URL, through `esc_url_raw()` | `voyager_site_social_linkedin` |
| `social_x` | Profile URL, through `esc_url_raw()` | `voyager_site_social_x` |

Stored values are trimmed before use.

### Bindable attributes

WordPress decides which attributes take a binding. The ones the pack's patterns use: `content` on `core/paragraph` and `core/heading`, `text` and `url` on `core/button`. The plugin adds `label` and `linkUrl` on `voyager-open/icon`. For any other block, ask WordPress with `get_block_bindings_supported_attributes( 'core/list-item' )`, or read `bindings.supportedAttributes` in a Wesper manifest.

### Functions

`voyager_open_blocks_register_bindable_meta( string $post_type, string $key, string $label ): bool`

Makes a post meta field bindable through `voyager/post-meta-text`. Defined in `voyager-open-blocks.php`, it calls `VoyagerOpenBlocks\PostMetaText\register_field()`. Returns `true` when the meta was registered. Rules are in [recipe 2](#recipe-2-make-a-post-meta-field-bindable).

Read-only helpers a theme can call (pre-1.0, see above):

| Function | Returns |
|---|---|
| `VoyagerOpenBlocks\SiteData\values(): array` | Every Site Data key with its current value, `''` when unset. Not passed through `voyager_site_data_value`. |
| `VoyagerOpenBlocks\SiteData\fields(): array` | Key => `label`, `type` (`text` or `url`) and, for stored keys, `option`. |
| `VoyagerOpenBlocks\SiteData\locality( string $city, string $state, string $zip ): string` | "City, ST 12345" without stray punctuation. |
| `VoyagerOpenBlocks\SiteData\owned( bool $set = false ): bool` | Whether this plugin registered `voyager/site-data` on this request. Call it after `init` priority 20, and never pass `true`. |
| `VoyagerOpenBlocks\PostMetaText\allowed_fields(): array` | The allowlist after the filter: post type (`*` for all) => meta key => label. |
| `VoyagerOpenBlocks\PostMetaText\fields_for( string $post_type ): array` | Meta key => label for one post type, including the `*` fields. |
| `VoyagerOpenBlocks\PostMetaText\resolve( int $post_id, string $key ): ?string` | What the source returns for a post and key, dot paths included. Does not apply the password check. |
| `VoyagerOpenBlocks\PostMetaText\owned( bool $set = false ): bool` | Whether this plugin registered `voyager/post-meta-text` on this request. Same rules. |
| `VoyagerOpenBlocks\Icons\svg( string $name, int $size, string $label = '' ): string` | The icon's SVG, decorative without a label. `''` for an unknown name. |

Everything else in `inc/` is internal: hook callbacks, the settings screen, and the block tree code behind the abilities.

### Constants

| Constant | Value |
|---|---|
| `VOYAGER_OPEN_BLOCKS_VERSION` | Plugin version, such as `0.5.0` |
| `VOYAGER_OPEN_BLOCKS_FILE` | Path to `voyager-open-blocks.php` |
| `VOYAGER_OPEN_BLOCKS_DIR` | The plugin's directory |
| `VoyagerOpenBlocks\SiteData\SOURCE` | `voyager/site-data` |
| `VoyagerOpenBlocks\PostMetaText\SOURCE` | `voyager/post-meta-text` |
| `VoyagerOpenBlocks\PostMetaText\OPTION` | `voyager_open_blocks_bindable_meta` |

### Options and REST

| Option | Type | Sanitized with | REST |
|---|---|---|---|
| `voyager_site_phone`, `voyager_site_address`, `voyager_site_address_city`, `voyager_site_address_state`, `voyager_site_address_zip`, `voyager_site_hours` | string, default `''` | `sanitize_text_field` | yes |
| `voyager_site_email` | string, default `''` | `sanitize_email` | yes |
| `voyager_site_social_facebook`, `voyager_site_social_instagram`, `voyager_site_social_linkedin`, `voyager_site_social_x` | string, default `''` | `esc_url_raw` | yes |
| `voyager_open_blocks_bindable_meta` | array of `{"post_type", "key", "label"}` rows, default `[]` | the screen's row rules | no |

All are in the `voyager_site_data` settings group, shown on Settings > Site Data (`options-general.php?page=voyager-site-data`, `manage_options`). They register only when this plugin owns the matching source.

- **`/wp/v2/settings`** reads and writes every `voyager_site_*` option by its option name, for a user with `manage_options`.
- **Bindable post meta** is in the `meta` object of the post's REST route, such as `/wp/v2/pages/<id>`. Anyone who can read the post sees it. Only a user who can edit the post may change it.
- **`voyager_open_blocks_bindable_meta`** is not in REST. With WP-CLI: `wp option update voyager_open_blocks_bindable_meta '[{"post_type":"page","key":"service_eyebrow","label":"Service eyebrow"}]' --format=json`. A row that breaks the key rules is dropped without a message there, so read the option back.

### Filters

`voyager_site_data_value`: filters every value `voyager/site-data` resolves. Return `''` to fall back to the authored text.

```php
apply_filters( 'voyager_site_data_value', string $value, string $key, array $source_args, string $attribute_name );
```

`voyager_post_meta_text_fields`: filters the allowlist of `voyager/post-meta-text`, shaped post type (`'*'` for every type) => meta key => label. A field added here resolves on the front end and is listed by `get-catalog`. It is not registered as post meta, though, so the editor cannot read or save its value and Wesper does not list it, unless you register the meta yourself with `show_in_rest`. Prefer the helper. Add a field here only when it is safe to print on the front end.

```php
apply_filters( 'voyager_post_meta_text_fields', array $fields );
```

`voyager_binding_sources`: read, not added to. When another plugin owns one of the two sources, `get-catalog` and `bind` take that source's fields from the entry in this filter whose `name` matches: a `get_fields_list` callable, or a `fields` map of key => `{label, args?, post_types?}`.

`block_bindings_supported_attributes_voyager-open/icon`: the core filter the plugin hooks to add `label` and `linkUrl`.

### Actions

The plugin fires no actions of its own. It hooks these:

| Hook | Priority | What runs |
|---|---|---|
| `init` | 10 | Registers the icon block, the patterns and their category, and the block styles |
| `init` | 15 | Registers the bindable post meta rows from the option |
| `init` | 20 | Registers both binding sources, their options and the settings screen hooks |
| `admin_init`, `admin_menu` | 10 | Settings > Site Data, when the plugin owns `voyager/site-data` |
| `enqueue_block_editor_assets` | 10 | The editor half of both sources: field lists, live values, in-place editing of post meta |
| `wp_abilities_api_categories_init`, `wp_abilities_api_init` | 10 | The ability category and the seven abilities |

### Abilities

Category `voyager-open-blocks`. REST at `/wp-json/wp-abilities/v1/abilities/<name>/run`: `GET` with `input[<field>]` query parameters for the four read-only abilities, `POST` with a `{"input": {...}}` body for the rest. The WordPress MCP Adapter serves the same abilities as MCP tools.

| Ability | Method | Permission | Input |
|---|---|---|---|
| `voyager-open-blocks/get-catalog` | GET | `edit_posts` | `post_type` (default `page`) |
| `voyager-open-blocks/find-posts` | GET | `edit_posts`; the post type's own `edit_posts` capability, or `edit_theme_options` for `wp_template` and `wp_template_part`; each result needs `edit_post` and `read_post` | `search` (words in the title; for templates, the title or slug), `slug` (exact), `post_type` (default `page`; any type shown in REST with editor support, including `wp_template` and `wp_template_part`), `front_page` (default `false`; returns the home page and ignores the rest), `limit` (default `10`, at most `20`) |
| `voyager-open-blocks/get-post-blocks` | GET | `edit_post` on `post_id` | `post_id` (required) |
| `voyager-open-blocks/insert` | POST | `edit_post` on `post_id` | `post_id`, `item` (required); `parent` (path of the block to insert into; left out, the top level), `position` (default `-1`, appends; an index among `parent`'s inner blocks when it is given), `mode` (`copy` or `reference`, default `copy`), `attributes` (blocks only), `copy` (patterns in copy mode only, label => plain text), `omit` (patterns in copy mode only, labels of blocks to leave out), `repeat` (patterns in copy mode only, label => labels of copies added right after it), `dry_run` (default `false`) |
| `voyager-open-blocks/configure` | POST | `edit_post` on `post_id` | `post_id`, `path`, `attributes` (all required). Blocks that save no HTML only. `null` resets an attribute. |
| `voyager-open-blocks/bind` | POST | `edit_post` on `post_id` | `post_id`, `path`, `attribute`, `source` (`voyager/site-data`, `voyager/post-meta-text` or `core/post-meta`), `args` (all required); `post_type` (the post type whose fields `args` must match; only in a template, template part, synced pattern or Query Loop, default the post's own type) |
| `voyager-open-blocks/validate` | GET | `edit_post` on `post_id` when one is given, otherwise `edit_posts` | `markup` or `post_id` (one of them, not both); `post_type` (for binding args, default the post's type or `page`) |

What they return:

- `get-catalog`: `blocks` (attribute schema and bindable attributes), `patterns` (named blocks, their bindings, `copy: true` on those `insert` can rewrite) and `sources` (each with `fields`, every one with its exact `args`).
- `find-posts`: `posts`, each with `id` (`null` for a template or template part that is only a theme file, never saved in the Site Editor), `title`, `slug`, `post_type`, `status`, `link`, `front_page`, and for templates `source` (`theme` or `custom`) and `note`, and for template parts `area`; `has_more`; and `note` when there is something to explain, such as a home page that lists the latest posts.
- `get-post-blocks`: `post_id` and `blocks`, each with `path`, `block`, `label` (its `metadata.name`), `text` (the first 80 characters of its own text, when it has any), `pattern` (for a `core/pattern` reference) and `bindings`. Every binding carries `resolution`: `status` (`resolved` or `fell_back`), `reason` (`null`, `source_unregistered`, `unknown_key` or `empty_value`) and `value_preview` (the first 80 characters).
- `insert`: `post_id`, `item`, `index`, `saved`, `markup`, `blocks` (the inserted blocks with their paths) and `validation`. With `parent`, also `parent`, `section_markup` (the whole top-level block that now holds them) and `validation.section_round_trip`.
- `configure` and `bind`: `post_id`, `path`, `block`, `attributes`, `markup` and `validation`.
- `validate`: `valid`, `post_type`, `round_trip`, `blocks` (each with `path`, `block`, `errors` and `warnings`), `errors`, `warnings`, `post_id` when it checked a saved post, and `save_output`, which says what it can't check: saved HTML against each block's `save()`. `npx block-runner validate` checks that.

A path is the list of indices from the top level down, such as `2/0/1`. Errors are `WP_Error` codes `voyager_open_blocks_invalid_<field>`, where the field is `post_id`, `post_type`, `path`, `parent`, `item`, `attributes`, `attribute`, `source`, `args`, `copy`, `omit`, `repeat` or `markup`. WordPress cannot check saved HTML from PHP, so pipe every returned `markup` and `section_markup` through `npx block-runner validate --strict --json`.

`insert` with `parent` follows the rules the editor's inserter follows, from block.json: the parent must allow the new block (`allowedBlocks`, or its own `allowedBlocks` attribute), each new block's `parent` and `ancestor` must hold, and the parent must not be locked (`templateLock` `all`, `insert` or `contentOnly`, its own or the nearest one set around it). The parent must already hold a block, because where an empty container's HTML holds its inner blocks is known only to its JavaScript `save()`.

### Block, styles and patterns

**`voyager-open/icon`** is a dynamic block that saves no HTML. Attributes: `icon` (one of 35 names, default `star`), `size` (pixels, default `48`, clamped to 8 to 512), `label` (accessible name, empty means decorative), `linkUrl`, `linkTarget` (boolean), `animation` (`bounce`, `pulse`, `spin`, `float`, `wiggle`, `fade-in`, `zoom-in`), `animationTrigger` (`hover`, `scroll` or `always`, default `hover`). It supports text and background colour, margin, padding, anchor and left, center or right alignment. The icon strokes with `currentColor`. The names are the `icon` enum in [`src/icon/block.json`](../src/icon/block.json). A link with no label takes the icon's name as its accessible name.

**Block styles:**

| Block | Style | Class | What it does |
|---|---|---|---|
| `core/list` | Ticks | `is-style-voyager-open-ticks` | Checkmarked items in one wrapping row. In a flex group narrower than 32em, they stack. |
| `core/group` | Marquee | `is-style-voyager-open-marquee` | The group inside it drifts sideways when it is wider than the marquee, then back. Paused on hover and focus. Still and wrapped under reduced motion. Set `--voyager-open-marquee-duration` to change the speed (default `24s`). |

**Pattern category:** slug `voyager-open`, shown as "Business sections (Open Blocks)".

| Pattern | Also in | Named blocks (`copy` labels in bold) | Bindings |
|---|---|---|---|
| `voyager-open/cta-band` | call-to-action | **Eyebrow**, **Heading**, **Body**, **Primary button**, **Click to call** | Click to call: `text` <- `phone`, `url` <- `phone_link` |
| `voyager-open/proof-strip` | about | **Credential 1** to **Credential 4** | none |
| `voyager-open/logo-marquee` | media | **Eyebrow** | none |
| `voyager-open/closing-cta` | call-to-action | **Heading**, **Body**, **Primary button**, **Email button** | Email button: `url` <- `email_link` |
| `voyager-open/contact-section` | contact | **Heading**, **Body**, **Phone**, **Email**, **Street address**, **City, state, postal code**, **Hours**, **Click to call**, **Form heading**, **Form body**, **Email button**; the group "Form" is the form slot | Phone `phone`, Email `email`, Street address `address`, City line `address_locality`, Hours `hours` (all `content`); Click to call `url` <- `phone_link`; Email button `url` <- `email_link` |

Every binding above uses `voyager/site-data`. Each pattern's full block tree is its intent in [`patterns/intents/`](../patterns/intents/).
