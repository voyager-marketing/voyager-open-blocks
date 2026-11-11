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

## 2. Purge

**Owner:** Runtime.

How a change to a field reaches a page cache.

- The action, fired with the URLs to purge:

  ```php
  do_action( 'voyager_open_blocks_purge', list<string> $urls, array $context );
  ```

  `$context` holds `source`, `keys`, `post_ids`, `templates`, `reason` (`site-data` or `post-meta`) and `purge_all` (bool).
- The filter `voyager_open_blocks_purge_urls` runs on the list before the action.
- A key used in a template or a template part sets `purge_all`, because every page may show it.
- Cache adapters come from the filter `voyager_open_blocks_cache_adapters`, which returns objects implementing `VoyagerOpenBlocks\Cache\Adapter`:

  ```php
  id(): string
  label(): string
  active(): bool
  supports_urls(): bool
  purge_urls( list<string> $urls ): void
  purge_all(): void
  ```

- At most one purge per request: purges are collected, deduplicated and run on `shutdown`. The last purge is recorded in the option `voyager_open_blocks_last_purge`: its time, the keys, the URL count and the adapters used.

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
- The filter `voyager_open_blocks_style_roles` returns role => colour preset slug. The pack ships maps for Twenty Twenty-Five, Twenty Twenty-Four and the Voyager design system v2 slugs, and, for any other theme, a fallback built from `currentColor` and `color-mix()`.

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
- Site Data keys are shared with the voyager-blocks plugin through the fixture `tests/fixtures/site-data-keys.json`, which both repositories carry byte for byte and pin to the same sha256 (`npm run check` compares it). Adding these keys is therefore a change to both repositories, in pull requests that land together.
