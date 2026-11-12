# v2 contracts

Version 2 of the pack is built in lanes that work at the same time: Runtime, Abilities, Design, Blocks and data, CI and evals, and Skills and docs. [AGENTS.md](../AGENTS.md#lanes) lists the files each lane owns. This page holds the shapes that more than one lane builds against, so a lane can write code against a shape before the lane that owns it has shipped it.

Each contract names its owner. The owner implements it and is the only lane that changes it. A change to a shape lands here first, in a pull request of its own that names the lanes it affects, and the code follows.

None of this is in a release yet. Until a contract ships, [use-in-your-theme.md](use-in-your-theme.md) stays the public API, and nothing here is promised to themes or other plugins.

## 1. Usage index

**Owner:** Runtime.

Which posts and templates use each Site Data or post meta field, so a change to a field can refresh only the pages that show it.

- Post meta `_voyager_open_blocks_uses` on every post that stores blocks, `wp_template`, `wp_template_part` and `wp_block` included. It is non-unique post meta: one row per `<source>:<key>` string, such as `voyager/site-data:phone` or `voyager/post-meta-text:price`, added with `add_post_meta( $post_id, '_voyager_open_blocks_uses', $use, false )`. A save deletes the post's rows and adds the current set, so a post that binds nothing has no rows.
- References count. A post that contains a synced pattern (`core/block` with `ref`) or a registered pattern (`core/pattern` with `slug`) uses whatever that pattern binds. A registered pattern's bindings come from `Patterns\bound_keys()`.
- Theme templates and template parts that are only files, not posts, are indexed in the option `voyager_open_blocks_theme_uses`, keyed by template ID. It is rebuilt on `switch_theme` and on demand.
- PHP API, in the namespace `VoyagerOpenBlocks\Usage`:

  ```php
  uses( int $post_id ): list<string>
  posts_using( string $source, string $key ): list<int>
  templates_using( string $source, string $key ): list<string>
  rebuild( int $batch = 50 ): int
  ```

  `uses()` returns the post's rows, sorted and without duplicates. `posts_using()` is an exact-match meta query (`meta_value` equal to the `<source>:<key>` string), never `LIKE`.
- Three choices the index made, which the purge relies on:
  - `templates_using()` returns the active theme's file-only templates and parts, from `voyager_open_blocks_theme_uses`, plus its templates and parts saved in the Site Editor, each once, as `<theme>//<slug>`. A saved one replaces its file. A non-empty result therefore always means `purge_all`.
  - A second, internal meta key, `_voyager_open_blocks_refs`, holds one row per synced pattern (`wp_block`) ID a post shows, nested ones included. When a synced pattern changes, the posts that show it are indexed again: up to 50 in the same request, the rest through WP-Cron, 50 per run. Those wait in the option `voyager_open_blocks_usage_queue`, a sorted list of post IDs, and the event `voyager_open_blocks_usage_queue` runs them. The option is read and written back without a lock, so two requests that queue posts in the same instant can lose one request's IDs; those posts are indexed again on their next save, or by `wp voyager-open-blocks reindex`.
  - A `voyager/post-meta-text` key with a dot path records only the field, the part before the first dot: `stats.0.value` is the row `voyager/post-meta-text:stats`.
- How posts get indexed: on save (`wp_after_insert_post`), by a backfill, and by WP-CLI. Activation starts the backfill, and so does the first request after the plugin's version changes: the event `voyager_open_blocks_usage_backfill` indexes 50 posts by ID per run, keeps its place in the option `voyager_open_blocks_usage_cursor`, and records the version in `voyager_open_blocks_usage_version`. `wp voyager-open-blocks reindex` indexes every post and the theme's templates at once, and clears the backfill and the queue. `npm run check:usage-index` deletes the index on a test site and checks that the backfill and the command each put every row back.
- What reads it: get-catalog's `used_on` and the "Shown on" line under each field on Settings > Site Data. Both count published posts of the post types the purge lists (contract 2), plus the active theme's templates and parts. The line names up to 10 pages, sorted by title in the database's order, so "Page 10" comes before "Page 2".

## 2. Purge

**Owner:** Runtime.

How a change to a field reaches a page cache.

- The action, fired with the URLs to purge:

  ```php
  do_action( 'voyager_open_blocks_purge', list<string> $urls, array $context );
  ```

  `$context` holds `source`, `keys`, `post_ids`, `templates`, `reason` (`site-data` or `post-meta`) and `purge_all` (bool).
- What starts a purge, and which URLs it lists:
  - A `voyager_site_*` option added, updated or deleted: the published pages that show that Site Data key, or a key made from it (`phone_link` for `phone`), plus the front page. `reason` is `site-data`.
  - The Site Title (`blogname`) or the Tagline (`blogdescription`) added, updated or deleted: the same, for the key that reads it, `company` or `tagline`. `copyright` is made from `company`, so a new Site Title refreshes the pages that show either. Site Data reads both through `get_bloginfo()`, which returns those options (`wp-includes/general-template.php`), and `/wp/v2/settings` names them `title` and `description` (`register_initial_settings()` in `wp-includes/option.php`). `keys` holds `company` or `tagline`.
  - A new year: the same, for `year`, so the pages that show `year` or `copyright` refresh, once. Site Data resolves the year as `wp_date( 'Y' )` in the site's time zone and stores it nowhere, so a daily WP-Cron event, `voyager_open_blocks_year_check`, compares it with the option `voyager_open_blocks_year` and stores the new one. When no year is stored yet, scheduling the event stores it, and nothing is refreshed. The event first runs at 01:00 site time and then every 24 hours, so the check falls soon after midnight, at the next request that runs WP-Cron. On `init` the pack only asks `wp_next_scheduled()`, which reads the cron option WordPress autoloads, so a normal request runs no query for it.
  - An allowlisted bindable post meta key added, updated or deleted: that post only, when it or a template shows the key, and no front page. `reason` is `post-meta`.
  - Nothing shows the key: no purge.
- Post meta shown on another page is not purged. A Query Loop on page B that shows post A's `price`, or a template that lists posts with their `price`, keeps its cached copy when A's `price` changes, until the cache expires it or something else refreshes that page. The index records that a page shows `voyager/post-meta-text:price`, not whose value it shows, so it cannot tell page B from the pages that show their own price. Purging every page that shows the key would refresh all of those on every save, and past 50 of them purge everything. It is recorded here as a limit, on 2026-11-11, rather than purged as the first 50 such pages plus `purge_all`.
- On a multisite network, each change is noted with the site that was current when it was made, so one made under `switch_to_blog()` is purged on that site: on `shutdown`, each site's changes are purged inside `switch_to_blog()` for it, and the current site's without switching. Its pages, index, templates, adapters and last purge are then that site's. `switch_to_blog()` moves the tables, the object cache and the site ID (`wp-includes/ms-blogs.php`), not the plugins loaded, so the listeners and adapters are the current request's.
- The filter `voyager_open_blocks_purge_urls` runs on the list before the action. When it leaves no URLs and `purge_all` is false, nothing more happens: no post cache is cleaned, the action does not fire and no adapter is called. That is how a site switches the purge off.
- A Site Data key used in a template or a template part sets `purge_all`, because every page may show it. A post meta key does not, since a template shows each post its own value.
- Only published posts of the post types registered `public => true` (`get_post_types( [ 'public' => true ] )`), attachments aside, are listed and cleaned: a draft, a private post, a synced pattern or a template has no cached page of its own. A type registered `public => false, publicly_queryable => true` has front-end URLs, and its posts are indexed, but a change does not list or clean them.
- A purge looks up, lists and cleans at most 50 posts, by ID. When more pages show a Site Data key, it lists and cleans the first 50 and sets `purge_all`. When more than 50 posts change post meta in one request, it looks at the first 50, lists and cleans the ones that show the key, and sets `purge_all`. With `purge_all`, the URL list is not complete, and `purge_all` is what tells a cache adapter to purge everything. With no adapter, the pages past the first 50 keep their cached copy until the cache expires it. This was chosen on 2026-11-11 over cleaning the rest through WP-Cron, 50 per run. On WP Super Cache, SpinupWP, Nginx Helper, LiteSpeed Cache, WP Rocket, Cloudflare and W3 Total Cache, pages past the first 50 refresh too: the pack's own adapter for each purges every page on `purge_all`.
- Before the action, `clean_post_cache()` runs for each post in `post_ids`. Some page caches listen to it: WP Super Cache 3.1.5 purges the post's page on `clean_post_cache` (`wpsc_register_post_hooks()` in `wp-cache-phase2.php`), and WP Rocket 3.24 cleans it with `rocket_clean_post()` (`inc/common/purge.php`).
- Cache adapters come from the filter `voyager_open_blocks_cache_adapters`, which returns objects implementing `VoyagerOpenBlocks\Cache\Adapter`:

  ```php
  id(): string
  label(): string
  active(): bool
  supports_urls(): bool
  purge_urls( list<string> $urls ): void
  purge_all(): void
  ```

- An active adapter gets `purge_all()` when the purge is `purge_all` or `supports_urls()` is false, and `purge_urls()` otherwise. An adapter that throws is recorded as failed, and the others still run.
- The pack ships seven adapters, each in `inc/cache/` and each active only when its plugin is loaded. Five are also active only while, by the plugin's own test, its page cache is on. WP Rocket has no such switch, and the Cloudflare plugin makes that test itself before it sends a purge. `wp-super-cache` (`inc/cache/wp-super-cache.php`) is for WP Super Cache 3.1.5. It is active when WP Super Cache is loaded, its caching is on (its `$cache_enabled` setting) and it is not in Lock Down. Lock Down is WP Super Cache's mode for a traffic spike, the constant `WPLOCKDOWN` set to anything but `'0'`: while it is on, WP Super Cache 3.1.5 hooks none of its own purges, `clean_post_cache` included (`wpsc_register_post_hooks()` in `wp-cache-phase2.php`), and the pack leaves WP Super Cache's files alone too, so a change during Lock Down refreshes no page until Lock Down ends or the cache expires. `purge_urls()` calls `wpsc_delete_url_cache()` once per URL. `purge_all()` calls `wp_cache_clear_cache()` with the current site's ID on a multisite network, so WP Super Cache clears that site's pages and not the whole network's, and with 0 on a single site. On a subdirectory network the main site's cache directory holds the other sites', so a change on the main site clears them too: that is how WP Super Cache lays out its cache. A site that does not want it removes it in a later `voyager_open_blocks_cache_adapters` filter.
- `spinupwp` (`inc/cache/spinupwp.php`) is for SpinupWP 1.9.1, active when `SPINUPWP_CACHE_PATH` is set. `purge_urls()` calls `spinupwp_purge_url()` once per URL, and `purge_all()` calls `spinupwp_purge_site()`, which empties SpinupWP's whole cache, every site's on a multisite network.
- `nginx-helper` (`inc/cache/nginx-helper.php`) is for Nginx Helper 2.4.1, active while its Enable Purge setting is on. `purge_urls()` calls `purge_url( $url, false )` on its `$nginx_purger` global once per URL, leaving out the page's comment feeds, and `purge_all()` calls `purge_all()` on it.
- `litespeed-cache` (`inc/cache/litespeed-cache.php`) is for LiteSpeed Cache 7.9.3, active when `LITESPEED_ON` is defined and Disable All Features is off. `purge_urls()` fires `litespeed_purge_url` once per URL, and `purge_all()` fires `litespeed_purge_all`, LiteSpeed's Purge All, which also empties its CSS and JS files, object cache and OPcache, as it does itself when a menu changes.
- `wp-rocket` (`inc/cache/wp-rocket.php`) is for WP Rocket 3.24, active when `rocket_clean_files()` and `rocket_clean_domain()` exist (`inc/functions/files.php`). `purge_urls()` calls `rocket_clean_files()` once with the URLs as the purge gave them, and `purge_all()` calls `rocket_clean_domain()` with no language, which cleans the site's cache in every language, and only this site's on a multisite network.
- `cloudflare` (`inc/cache/cloudflare.php`) is for Cloudflare 4.14.5, active when the plugin's `$cloudflareHooks` global is its `Cloudflare\APO\WordPress\Hooks` object (`cloudflare.loader.php`). The plugin purges single pages only by post ID, so `supports_urls()` is false and every purge is `purge_all()`, which calls `purgeCacheEverything()` on that object (`src/WordPress/Hooks.php`): the plugin asks Cloudflare to purge the whole zone when its page cache or APO is on, and does nothing otherwise.
- `w3-total-cache` (`inc/cache/w3-total-cache.php`) is for W3 Total Cache 2.11.0, active when `w3tc_config()`, `w3tc_flush_url()` and `w3tc_flush_posts()` exist (`w3-total-cache-api.php`) and `w3tc_config()->get_boolean( 'pgcache.enabled' )` is true: Page Cache under its General Settings, the test its own loader makes before it loads the page cache (`Root_Loader.php`). `purge_urls()` calls `w3tc_flush_url()` once per URL, and `purge_all()` calls `w3tc_flush_posts()`, which empties the page cache and, when they are on, runs W3 Total Cache's own Varnish, full-site CDN and Cloudflare extension purges, as it does itself when a menu changes. It never calls `w3tc_flush_all()`, which also empties the object, database and minify caches and OPcache.
- None of SpinupWP, Nginx Helper, LiteSpeed Cache, WP Rocket and W3 Total Cache has a mode like WP Super Cache's Lock Down that keeps cached pages through a change. LiteSpeed's Disable All Features stops it caching, and its adapter is inactive then.
- The six other than WP Super Cache fail soft (`inc/cache/fail-soft.php`): when the plugin throws, the adapter reports it through `wp_trigger_error()`, purges the rest when it purges URL by URL, then throws once, so the purge records it as failed.
- At most one purge per request for each reason on each site: changes are collected, deduplicated and run on `shutdown`, so a form that saves eleven Site Data fields, the Site Title and the Tagline purges once. A request that changes both Site Data and post meta runs one of each. The last purge is recorded in the option `voyager_open_blocks_last_purge`: `time`, `source`, `reason`, `keys`, `urls` (the URL count), `purge_all`, `adapters` (the IDs told) and `failed` (the IDs that threw). Settings > Site Data shows it.

## 3. Copy rules

**Owner:** Design writes the rules in the pattern intents. Abilities enforces them.

What text each named block in a pattern takes, so an agent's copy fits the design.

- Each intent in `patterns/intents/` carries a `copy_rules` object, keyed by a block's `metadata.name`:

  ```json
  "copy_rules": {
  	"<metadata.name>": {
  		"purpose": "string",
  		"min": 0,
  		"max": 60,
  		"required": true,
  		"claim": false
  	}
  }
  ```

  `min` and `max` count characters of plain text.
- `get-catalog` returns `copy_rules` for each pattern. The `copy: true` it already puts on each named block in a pattern's `blocks` list stays exactly as it is.
- A copy value, in `insert`'s `copy` and in a `compose` section's `copy`, is either a string or an object:

  ```
  "<slot>": "<string>"
  "<slot>": { "text": "<string>", "source": "user" }
  ```

  `insert`'s `copy` takes only strings today; the object form arrives with copy rules.
- `insert` and `compose` refuse text longer than `max`, and a missing `required` slot, and name the slot.
- `claim: true` marks a factual claim, such as a credential, a year or a rating. Its text comes from Site Data or the business profile (contract 7). Only the object form, with `"source": "user"`, may fill a claim slot with text from anywhere else.

## 4. Section style roles

**Owner:** Design.

How a section's background and text colours follow the active theme.

- Block style variations on `core/group`: `voyager-open-panel`, `voyager-open-inverted` and `voyager-open-accent`. A section with no style is plain.
- Roles: `surface`, `on-surface`, `muted`, `accent`, `on-accent` and `line`.
- The filter `voyager_open_blocks_style_roles` returns role => colour preset slug. The pack ships maps for Twenty Twenty-Five, Twenty Twenty-Four and the Voyager design system v2 slugs, and, for any other theme, a fallback built from `currentColor` and `color-mix()` on the site's text and background colours: the ones saved in the Site Editor's Global Styles, else the theme's `styles.color` in theme.json.
- How a map is chosen. The palette in use is the theme's, or the one a style variation chosen in the Site Editor saved, which replaces it. The built-in map is the one whose slugs that palette declares, else the one whose slugs the theme declares. Each role keeps its slug only where the palette in use declares it, in pairs (`surface` with `on-surface`, `accent` with `on-accent`), and `on-accent` moves to the map's `surface` or `on-surface` slug when its own reads under 4.6:1 on `accent`. The filter gets the built-in map and the slugs of the palette in use, and a map it changes is used as it returns it. The fallback's `accent` mixes 75% of the text into the background, or more in steps of 5% until the background reads at 4.6:1 on it.

## 5. Recipes

**Owner:** Design writes recipes. Abilities reads them.

A page as an ordered list of sections, for `compose` to build.

- One file per recipe, `recipes/<slug>.json`:

  ```json
  {
  	"title": "string",
  	"description": "string",
  	"page_type": "home|services|service|location|contact|about",
  	"sections": [
  		{
  			"pattern": "voyager-open/<slug>",
  			"layout": "<name>|null",
  			"style": "plain|panel|inverted|accent",
  			"copy_guidance": { "<slot>": "string" }
  		}
  	]
  }
  ```

- Two sections next to each other never share a style other than `plain`.
- **Layouts.** A section can come in more than one layout, such as the hero's image beside the text and text over a background. Each layout is its own intent file in `patterns/intents/` and its own registered pattern:
  - The section's first layout keeps the section's slug: `patterns/intents/hero.json` is `voyager-open/hero`.
  - Every other layout is the section's slug, a hyphen and the layout's name: `patterns/intents/hero-cover.json` is `voyager-open/hero-cover`.
  - Each intent of a section with layouts carries two top-level keys beside `title`: `section`, the section's slug without `voyager-open/`, and `layout`, the layout's name.

    ```json
    { "title": "Hero", "section": "hero", "layout": "split" }
    { "title": "Hero over a background", "section": "hero", "layout": "cover" }
    ```

  - An intent with no `section` key is a section with one layout, as the CTA band, proof strip, logo marquee, closing CTA and contact section are. It has no `layout` key either.
- How a recipe section finds its layout. `pattern` names the section by its slug, and `layout` picks one of its layouts:
  - `"pattern": "voyager-open/hero", "layout": "cover"` resolves to the intent whose `section` is `hero` and whose `layout` is `cover`, so the pattern `voyager-open/hero-cover`.
  - `"layout": null`, or no `layout` key, is the section's first layout, the pattern that keeps the section's slug: `voyager-open/hero`.
  - A section with one layout takes `"layout": null` only. A `layout` that no intent of the section carries names no pattern, and `compose` refuses it as it refuses an unknown pattern.
  - `get-catalog` and `compose` read `section` and `layout` from the intent files, so a layout needs no other registration.

## 6. compose

**Owner:** Abilities.

A new ability that builds a draft page from a recipe or a list of sections.

- Input, where `?` marks an optional field:

  ```
  {
  	"post_id"?: int,
  	"title"?: string,
  	"recipe"?: string,
  	"sections"?: [ { "pattern", "layout"?, "style"?, "copy"?: { "<slot>": copy value } } ],
  	"dry_run"?: bool
  }
  ```

  Exactly one of `post_id` and `title`, and exactly one of `recipe` and `sections`. A copy value is a string or `{ "text", "source": "user" }`, as in contract 3.
- `compose` writes drafts only. It refuses a published post; changes to a live page go through `insert` and `configure`.
- Given `post_id` of a draft, `compose` replaces that post's content, because it builds whole pages.
- Refusal works like `insert`'s: if any slot is refused, nothing is saved. `saved` is `false`, and `refused` lists every refused slot, not only the first.
- `dry_run` returns the same shape, with `saved: false`.
- Output:

  ```
  {
  	"post_id", "revision_id", "preview_url", "saved", "validation",
  	"empty": [ "<site-data key>" ],
  	"refused": [ { "slot", "reason" } ]
  }
  ```

## 7. Business profile keys

**Owner:** Blocks and data. This contract spans two repositories.

New Site Data fields for what a local business page says about itself.

- New options: `voyager_site_services`, `voyager_site_service_area` and `voyager_site_credentials` (one item per line), `voyager_site_founded` (a year), `voyager_site_license`, `voyager_site_rating` (0 to 5, one decimal), `voyager_site_review_count`, `voyager_site_cta_label` and `voyager_site_cta_url`.
- Each option's binding key is its name without `voyager_site_`, as for every stored key. `$value` below is the stored option, trimmed, and every key resolves to a string:

  | Key | Resolves to |
  | -- | -- |
  | `services`, `service_area`, `credentials` | the value split on `\r\n`, `\r` or `\n`, each line trimmed, empty lines dropped, joined with `\n` |
  | `founded` | `$value` when it matches `/^[0-9]{4}$/`, else `''` |
  | `license`, `cta_label` | `$value` |
  | `rating` | when `is_numeric( $value )` and it is from 0 to 5, `number_format( (float) $value, 1, '.', '' )`, else `''`. So `4.80` is `4.8`, `5` is `5.0` and `-0` is `0.0` |
  | `review_count` | when `$value` matches `/^[0-9]+$/`, `(string) (int) $value`, so `0127` is `127`, else `''` |
  | `cta_url` | `esc_url_raw( $value )`, as the social keys |

- Saving, on Settings > Site Data and at `/wp/v2/settings` alike: the lists go through `sanitize_textarea_field()`, which keeps their lines, `cta_url` is trimmed and goes through `esc_url_raw()`, the others through `sanitize_text_field()`. `founded`, `rating` and `review_count` are then saved in their resolved form. A value that does not resolve is refused as core's `sanitize_option()` refuses one: the option keeps its previous value and the Settings screen shows an error. Each is registered with `show_in_rest`, like the other options.
- Site Data keys are shared with the voyager-blocks plugin through the fixture `tests/fixtures/site-data-keys.json`, which both repositories carry byte for byte and pin to the same sha256 (`npm run check` compares it). Adding these keys is therefore a change to both repositories, in pull requests that land together. The fixture pins 27 keys.
