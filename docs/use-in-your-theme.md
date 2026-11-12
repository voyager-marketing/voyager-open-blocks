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

Args are always `{"key": "<key>"}`. The 27 keys are in [Site Data keys](#site-data-keys). Use `phone_link` and `email_link` for a `url`: they carry the `tel:` and `mailto:` prefixes.

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

The plugin is pre-1.0. The supported surface is the helper function, the two binding sources and their args, the options, the filters, the abilities, the icon and facts blocks, the block styles and the pattern slugs. Functions under the `VoyagerOpenBlocks\` namespace work and are listed where a theme might call them, but they can change in a minor release.

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
| `services` | One service per line: each line trimmed, empty lines dropped, joined with `\n` | `voyager_site_services` |
| `service_area` | One town or area per line, the same way | `voyager_site_service_area` |
| `credentials` | One license, certification or membership per line, the same way | `voyager_site_credentials` |
| `founded` | Year founded, only when it is four digits | `voyager_site_founded` |
| `license` | License number | `voyager_site_license` |
| `rating` | Average rating with one decimal, such as `4.8`, only when it is a number from 0 to 5 | `voyager_site_rating` |
| `review_count` | Number of reviews, such as `127`, only when it is digits | `voyager_site_review_count` |
| `cta_label` | Text of the main call to action | `voyager_site_cta_label` |
| `cta_url` | Link of the main call to action, through `esc_url_raw()` | `voyager_site_cta_url` |

Stored values are trimmed before use. A list splits on `\r\n`, `\r` or `\n`. A binding fills an attribute with one string, so show a list with the `items` of a [`voyager-open/facts`](#block-styles-and-patterns) block, which prints one list item per line. The site owner types the rating and review count in from their own review profile: the pack calls no review service. A value the rule does not accept resolves to `''`, so the authored text shows.

### Bindable attributes

WordPress decides which attributes take a binding. The ones the pack's patterns use: `content` on `core/paragraph` and `core/heading`, `text` and `url` on `core/button`. The plugin adds `label` and `linkUrl` on `voyager-open/icon`, and `items` on `voyager-open/facts`. For any other block, ask WordPress with `get_block_bindings_supported_attributes( 'core/list-item' )`, or read `bindings.supportedAttributes` in a Wesper manifest.

### Functions

`voyager_open_blocks_register_bindable_meta( string $post_type, string $key, string $label ): bool`

Makes a post meta field bindable through `voyager/post-meta-text`. Defined in `voyager-open-blocks.php`, it calls `VoyagerOpenBlocks\PostMetaText\register_field()`. Returns `true` when the meta was registered. Rules are in [recipe 2](#recipe-2-make-a-post-meta-field-bindable).

Read-only helpers a theme can call (pre-1.0, see above):

| Function | Returns |
|---|---|
| `VoyagerOpenBlocks\SiteData\values(): array` | Every Site Data key with its current value, `''` when unset. Not passed through `voyager_site_data_value`. |
| `VoyagerOpenBlocks\SiteData\fields(): array` | Key => `label`, `type` (`text`, `url`, or `list` for one item per line) and, for stored keys, `option`. |
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
| `voyager_site_phone`, `voyager_site_address`, `voyager_site_address_city`, `voyager_site_address_state`, `voyager_site_address_zip`, `voyager_site_hours`, `voyager_site_license`, `voyager_site_cta_label` | string, default `''` | `sanitize_text_field` | yes |
| `voyager_site_email` | string, default `''` | `sanitize_email` | yes |
| `voyager_site_social_facebook`, `voyager_site_social_instagram`, `voyager_site_social_linkedin`, `voyager_site_social_x`, `voyager_site_cta_url` | string, default `''` | trimmed, then `esc_url_raw` | yes |
| `voyager_site_services`, `voyager_site_service_area`, `voyager_site_credentials` | string, one item per line, default `''` | `sanitize_textarea_field`, which keeps the lines | yes |
| `voyager_site_founded`, `voyager_site_rating`, `voyager_site_review_count` | string, default `''` | `sanitize_text_field`, then saved as its key resolves, so `4.80` saves as `4.8`. A value that does not resolve is refused: the option keeps its previous value, and Settings > Site Data shows an error | yes |
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

`block_bindings_supported_attributes_voyager-open/facts`: the core filter the plugin hooks to add `items`.

`voyager_open_blocks_style_roles`: the colour preset each role of the [section styles](#block-styles-and-patterns) uses, role => palette slug. The roles are `surface`, `on-surface`, `muted`, `accent`, `on-accent` and `line`. The plugin passes its built-in map when your palette matches Twenty Twenty-Five, Twenty Twenty-Four or the Voyager design system, else `[]`. A slug your theme does not declare, and a role not in the list, are dropped. A role the map leaves out is mixed from the site's own text and background colours with `color-mix()`: the ones saved in the Site Editor's Global Styles, else `styles.color` in your theme.json.

```php
apply_filters( 'voyager_open_blocks_style_roles', array $map, array $palette_slugs );
```

`voyager_open_blocks_purge_urls`: the URLs a [purge](#actions) is about to send, before the action fires. Return `[]` to switch the purge off: when the list is empty and `purge_all` is false, no post cache is cleaned, the action does not fire and no adapter is called.

```php
apply_filters( 'voyager_open_blocks_purge_urls', array $urls, array $context );
```

`voyager_open_blocks_cache_adapters`: return your page cache's adapters, objects that implement `VoyagerOpenBlocks\Cache\Adapter` (`inc/cache/adapter.php`). Anything else in the list is ignored.

```php
add_filter(
	'voyager_open_blocks_cache_adapters',
	static function ( array $adapters ): array {
		$adapters[] = new My_Cache_Adapter(); // implements VoyagerOpenBlocks\Cache\Adapter
		return $adapters;
	}
);
```

| Adapter method | What it does |
|---|---|
| `id(): string` | A stable lowercase ID, such as `my-cache`. |
| `label(): string` | The cache's name, shown on Settings > Site Data. |
| `active(): bool` | Whether the cache is installed and on. An inactive adapter is skipped. |
| `supports_urls(): bool` | Whether the cache can purge single pages. When it cannot, it gets `purge_all()` instead of `purge_urls()`. |
| `purge_urls( array $urls ): void` | Purge these absolute URLs. |
| `purge_all(): void` | Purge every page. |

Each active adapter is called once per purge: `purge_all()` when the purge is `purge_all` or `supports_urls()` is false, `purge_urls()` otherwise. An adapter that throws is recorded as failed, and the others still run. The pack ships six adapters, each active when its plugin is loaded: `wp-super-cache` (WP Super Cache), `spinupwp` (SpinupWP), `nginx-helper` (Nginx Helper, with Enable Purge on) and `litespeed-cache` (LiteSpeed Cache), each also only while its page cache is on, `wp-rocket` (WP Rocket), and `cloudflare` (the Cloudflare plugin, which purges everything, since it cannot purge a page by URL). To drop one, remove it from the list in a filter of your own at a later priority.

### Actions

`voyager_open_blocks_purge`: fired at most once per request for each reason, on `shutdown`, after a Site Data field or an allowlisted bindable post meta field changes and a published page or a template shows it. Listen to it to purge a cache that has no adapter.

```php
do_action( 'voyager_open_blocks_purge', array $urls, array $context );
```

- `$urls`: the pages to purge, absolute, after `voyager_open_blocks_purge_urls`. For Site Data, the published posts that show the field, or a field made from it (`phone_link` for `phone`), and the front page. For post meta, that post only.
- `$context`: `source` (`voyager/site-data` or `voyager/post-meta-text`), `keys` (the keys that changed), `post_ids`, `templates` (the active theme's templates and parts that show a key), `reason` (`site-data` or `post-meta`) and `purge_all` (bool).
- `purge_all` is true when the URL list cannot be complete: a Site Data field shown in a template or template part, or more than 50 posts to purge, of which the list holds the first 50. An adapter then gets `purge_all()`.
- Before the action, `clean_post_cache()` runs on each post in `post_ids`, which some page caches listen to. Only published posts of `public` post types are listed; a type registered `public => false, publicly_queryable => true` is not.
- Nothing shows the field, or the filter emptied the list: no action. The last purge is shown on Settings > Site Data.

Which pages show a field comes from the plugin's usage index, post meta `_voyager_open_blocks_uses` on each post, kept on save. `wp voyager-open-blocks reindex` rebuilds it for every post at once, and activation starts a background rebuild through WP-Cron.

The plugin hooks these:

| Hook | Priority | What runs |
|---|---|---|
| `init` | 10 | Registers the icon and facts blocks, the patterns and their category, and the block styles, and starts the usage index's background rebuild when the plugin version changed |
| `init` | 15 | Registers the bindable post meta rows from the option |
| `init` | 20 | Registers both binding sources, their options and the settings screen hooks |
| `admin_init`, `admin_menu` | 10 | Settings > Site Data, when the plugin owns `voyager/site-data` |
| `admin_init` | 9 | The last purge's section on Settings > Site Data |
| `enqueue_block_editor_assets` | 10 | The editor half of both sources: field lists, live values, in-place editing of post meta |
| `wp_abilities_api_categories_init`, `wp_abilities_api_init` | 10 | The ability category and the eight abilities |
| `wp_after_insert_post`, `after_delete_post` | 10 | Indexes the saved post, and the posts that show a changed synced pattern |
| `switch_theme`, `upgrader_process_complete` | 10 | Indexes the theme's template and template part files |
| `added_option`, `updated_option`, `deleted_option`, `added_post_meta`, `updated_post_meta`, `deleted_post_meta` | 10 | Notes a Site Data or bindable post meta change for the purge |
| `shutdown` | 0 | Runs the purge for the changes the request noted |
| `cli_init` | 10 | Adds `wp voyager-open-blocks reindex` |

### Abilities

Category `voyager-open-blocks`. REST at `/wp-json/wp-abilities/v1/abilities/<name>/run`: `GET` with `input[<field>]` query parameters for the four read-only abilities, `POST` with a `{"input": {...}}` body for the rest. The WordPress MCP Adapter serves the same abilities as MCP tools.

| Ability | Method | Permission | Input |
|---|---|---|---|
| `voyager-open-blocks/get-catalog` | GET | `edit_posts` | `post_type` (default `page`) |
| `voyager-open-blocks/find-posts` | GET | `edit_posts`; the post type's own `edit_posts` capability, or `edit_theme_options` for `wp_template` and `wp_template_part`; each result needs `edit_post` and `read_post` | `search` (words in the title; for templates, the title or slug), `slug` (exact), `post_type` (default `page`; any type shown in REST with editor support, including `wp_template` and `wp_template_part`), `front_page` (default `false`; returns the home page and ignores the rest), `limit` (default `10`, at most `20`) |
| `voyager-open-blocks/get-post-blocks` | GET | `edit_post` on `post_id` | `post_id` (required) |
| `voyager-open-blocks/insert` | POST | `edit_post` on `post_id` | `post_id`, `item` (required); `parent` (path of the block to insert into; left out, the top level), `position` (default `-1`, appends; an index among `parent`'s inner blocks when it is given), `mode` (`copy` or `reference`, default `copy`), `attributes` (blocks only), `copy` (patterns in copy mode only, label => plain text), `omit` (patterns in copy mode only, labels of blocks to leave out), `repeat` (patterns in copy mode only, label => labels of copies added right after it), `dry_run` (default `false`) |
| `voyager-open-blocks/configure` | POST | `edit_post` on `post_id` | `post_id`, `path` (both required), and one of `attributes` (blocks that save no HTML only; `null` resets an attribute) or `style` (`plain`, `panel`, `inverted` or `accent`, on a `core/group` only; `plain` takes the style off). Optional: `dry_run` (default `false`). |
| `voyager-open-blocks/bind` | POST | `edit_post` on `post_id` | `post_id`, `path`, `attribute`, `source` (`voyager/site-data`, `voyager/post-meta-text` or `core/post-meta`), `args` (all required); `post_type` (the post type whose fields `args` must match; only in a template, template part, synced pattern or Query Loop, default the post's own type), `dry_run` (default `false`) |
| `voyager-open-blocks/undo` | POST | `edit_post` on `post_id` | `post_id`, `revision_id` (both required; the `revision_id` an `insert`, `configure` or `bind` save returned); `force` (default `false`; reverse the saves made after `revision_id` too) |
| `voyager-open-blocks/validate` | GET | `edit_post` on `post_id` when one is given, otherwise `edit_posts` | `markup` or `post_id` (one of them, not both); `post_type` (for binding args, default the post's type or `page`) |

What they return:

- `get-catalog`: `blocks` (attribute schema and bindable attributes), `patterns` (named blocks, their bindings, `copy: true` on those `insert` can rewrite), `sources` (each with `fields`, every one with its exact `args`), `styles` (the section styles, each with its class, and which role map the theme's colours come from) and `used_on`: for each Site Data and bindable post meta field, keyed `<source>:<key>` such as `voyager/site-data:phone`, how many published `posts`, `templates` and `template_parts` show it. The "Shown on" line under each field on Settings > Site Data reads the same index and links up to 10 of those pages, sorted by title in the database's order.
- `configure` with `style`: the style's class replaces any other `is-style-*` class on the group, in the block comment and on its saved wrapper, as the editor's Styles panel does. Anything but a `core/group` or one of the four names is refused with `voyager_open_blocks_invalid_style`.
- `find-posts`: `posts`, each with `id` (`null` for a template or template part that is only a theme file, never saved in the Site Editor), `title`, `slug`, `post_type`, `status`, `link`, `front_page`, and for templates `source` (`theme` or `custom`) and `note`, and for template parts `area`; `has_more`; and `note` when there is something to explain, such as a home page that lists the latest posts.
- `get-post-blocks`: `post_id` and `blocks`, each with `path`, `block`, `label` (its `metadata.name`), `text` (the first 80 characters of its own text, when it has any), `pattern` (for a `core/pattern` reference) and `bindings`. Every binding carries `resolution`: `status` (`resolved` or `fell_back`), `reason` (`null`, `source_unregistered`, `unknown_key` or `empty_value`) and `value_preview` (the first 80 characters).
- `insert`: `post_id`, `item`, `index`, `saved`, `markup`, `blocks` (the inserted blocks with their paths) and `validation`. With `parent`, also `parent`, `section_markup` (the whole top-level block that now holds them) and `validation.section_round_trip`.
- `configure` and `bind`: `post_id`, `path`, `block`, `attributes`, `saved`, `markup` and `validation`.
- `insert`, `configure` and `bind` also return `revision_id`, the revision WordPress made for the save, which `undo` takes. With `dry_run` they run every check, refusals included, save nothing and return `saved: false` and `revision_id: null`. `revision_id` is also `null` when a save made no revision, with `revision_note` saying why: revisions are off for the post, the post is an auto-draft, or nothing changed. `revision_note` also says when the site keeps too few revisions for `undo` to restore the content a save replaced.
- `undo`: `post_id`, `undone` (the `revision_id` given), `restored` (the revision whose content the post now has), `reversed` (the save undone and, with `force`, every save after it, oldest first, each with `revision_id`, `author_id`, `author` and `date_gmt`), `matches` (the content now equals the restored revision byte for byte), and the undo's own `revision_id` and `revision_note`.
- `validate`: `valid`, `post_type`, `round_trip`, `blocks` (each with `path`, `block`, `errors` and `warnings`), `errors`, `warnings`, `post_id` when it checked a saved post, and `save_output`, which says what it can't check: saved HTML against each block's `save()`. `npx block-runner validate` checks that. A warning starts with its block, or with a code followed by the block and its path: `stale_fallback` (a bound block's fallback looks like a phone number or an email address), `placeholder` (one of the pack's placeholder images, matched by the end of its URL on any host, or the contact section's form stand-in, still in its Form slot column) or `missing_alt` (any other `core/image` or `core/media-text` image with empty or missing alt text). Warnings never change `valid`.

A path is the list of indices from the top level down, such as `2/0/1`. Errors are `WP_Error` codes `voyager_open_blocks_invalid_<field>`, where the field is `post_id`, `post_type`, `path`, `parent`, `item`, `attributes`, `attribute`, `source`, `args`, `copy`, `omit`, `repeat`, `markup`, `revision` or `style`. WordPress cannot check saved HTML from PHP, so pipe every returned `markup` and `section_markup` through `npx block-runner validate --strict --json`.

`undo` restores the revision just before `revision_id` with `wp_restore_post_revision()`, `post_content` only, so the title and excerpt stay as they are. Like every revision restore in WordPress, it also replaces the post meta WordPress keeps in revisions (core's footnotes, and meta registered with `revisions_enabled`) with that revision's copy. Restoring an older revision reverses every save after it, so when the post was saved after `revision_id`, by a person or by another agent, `undo` refuses with `voyager_open_blocks_undo_conflict` (status 409), changes nothing, and lists those saves in the error's `later`. Pass `force: true` to reverse them too. Before it restores, `undo` stores the post as it stands as a revision, so the `revision_id` it returns puts back whatever it replaced.

`insert` with `parent` follows the rules the editor's inserter follows, from block.json: the parent must allow the new block (`allowedBlocks`, or its own `allowedBlocks` attribute), each new block's `parent` and `ancestor` must hold, and the parent must not be locked (`templateLock` `all`, `insert` or `contentOnly`, its own or the nearest one set around it). The parent must already hold a block, because where an empty container's HTML holds its inner blocks is known only to its JavaScript `save()`.

### Block, styles and patterns

**`voyager-open/icon`** is a dynamic block that saves no HTML. Attributes: `icon` (one of 35 names, default `star`), `size` (pixels, default `48`, clamped to 8 to 512), `label` (accessible name, empty means decorative), `linkUrl`, `linkTarget` (boolean), `animation` (`bounce`, `pulse`, `spin`, `float`, `wiggle`, `fade-in`, `zoom-in`), `animationTrigger` (`hover`, `scroll` or `always`, default `hover`). It supports text and background colour, margin, padding, anchor and left, center or right alignment. The icon strokes with `currentColor`. The names are the `icon` enum in [`src/icon/block.json`](../src/icon/block.json). A link with no label takes the icon's name as its accessible name.

**`voyager-open/facts`** is a dynamic block that saves no HTML. One attribute, `items` (string, default `''`, bindable): one item per line. It prints a `ul` with the block's wrapper attributes and one `li` per line, each line trimmed and empty lines dropped. Each item is decoded once and escaped once, so a value that arrives escaped, as voyager-blocks returns Site Data, prints the same as one that arrives raw. With no items it prints nothing. Bound to a Site Data list that is empty, it shows its own `items`: the source returns `null`, and WordPress keeps the stored value. It supports text and background colour, font size, line height, margin, padding and anchor.

**Block styles:**

| Block | Style | Class | What it does |
|---|---|---|---|
| `core/list`, `voyager-open/facts` | Ticks | `is-style-voyager-open-ticks` | Checkmarked items in one wrapping row. In a flex group narrower than 32em, they stack. |
| `core/group` | Marquee | `is-style-voyager-open-marquee` | The group inside it drifts sideways when it is wider than the marquee, then back. Paused on hover and focus. Still and wrapped under reduced motion. Set `--voyager-open-marquee-duration` to change the speed (default `24s`). |
| `core/group` | Panel | `is-style-voyager-open-panel` | A section on a quiet tint of the page's background, with its headings, links and buttons coloured to match. |
| `core/group` | Inverted | `is-style-voyager-open-inverted` | A section in the page's text colour, with the page's background colour as its text. |
| `core/group` | Accent | `is-style-voyager-open-accent` | A section in the theme's accent colour, with the text colour the theme pairs with it. |

Panel, Inverted and Accent are the section styles; a section with none is Plain. Their colours come from six roles mapped to your palette, which the `voyager_open_blocks_style_roles` filter can replace (see [Filters](#filters)). They are registered with `style_data`, so a theme can still restyle one in its theme.json under `styles.blocks.core/group.variations.voyager-open-panel` and the like, and that wins. An agent puts one on a section with `configure`'s `style`.

**Pattern category:** slug `voyager-open`, shown as "Business sections (Open Blocks)".

| Pattern | Also in | Named blocks (`copy` labels in bold) | Bindings |
|---|---|---|---|
| `voyager-open/cta-band` | call-to-action | **Eyebrow**, **Heading**, **Body**, **Primary button**, **Click to call** | Click to call: `text` <- `phone`, `url` <- `phone_link` |
| `voyager-open/proof-strip` | about | **Credential 1** to **Credential 4** | none |
| `voyager-open/logo-marquee` | media | **Eyebrow** | none |
| `voyager-open/closing-cta` | call-to-action | **Heading**, **Body**, **Primary button**, **Email button** | Email button: `url` <- `email_link` |
| `voyager-open/contact-section` | contact | **Heading**, **Body**, **Phone**, **Email**, **Street address**, **City, state, postal code**, **Hours**, **Click to call**, **Form heading**, **Form body**, **Email button**; the group "Form" is the form slot | Phone `phone`, Email `email`, Street address `address`, City line `address_locality`, Hours `hours` (all `content`); Click to call `url` <- `phone_link`; Email button `url` <- `email_link` |

Every binding above uses `voyager/site-data`. Each pattern's full block tree is its intent in [`patterns/intents/`](../patterns/intents/).
