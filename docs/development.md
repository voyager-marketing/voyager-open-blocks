# Development

This page is for working on the pack itself: setting up, the checks, the local site and releases. To use the pack in a theme, read [use-in-your-theme.md](use-in-your-theme.md). The rules every change keeps are in [AGENTS.md](../AGENTS.md) and [CONTRIBUTING.md](../CONTRIBUTING.md). Starter tasks are in [good-first-issues.md](good-first-issues.md).

## Quick start

`npm run dev` boots WordPress Playground with your checkout mounted, the plugin active, demo Site Data and the demo pages. Start here when you want to see a change in a browser.

```bash
git clone https://github.com/voyager-marketing/voyager-open-blocks.git
cd voyager-open-blocks
npm ci
npm run dev
```

## Set up

You need Node `^22.23.4 || ^24.22.1 || >=26.12.1`, Block Runner 1.0.0's range. `.nvmrc` pins 24.22.1. The local site below also needs PHP 8.1+ and WP-CLI on `PATH`.

```bash
npm install
npm run build              # blocks
npm run patterns:build     # intents -> patterns/*.html
npm run check              # every offline gate, below
```

## How it is built

- **Blocks** live in `src/<name>/` and build with `@wordpress/scripts` (`npm run build`, which writes `build/blocks-manifest.php`). The plugin registers everything in that manifest. `build/` is committed so a Git install works without a build step. CI fails when `build/` is stale.
- **Patterns** start as Block Runner intent trees in `patterns/intents/<name>.json`, with the pattern's title, description and categories beside the tree. `npm run patterns:build` assembles each one into `patterns/<name>.html` and fails on any invalid block, any dropped attribute, and two blocks in one pattern with the same `metadata.name`. Do not edit the `.html` files by hand.
- **Binding sources** are in `inc/site-data.php` and `inc/post-meta-text.php`. Their editor half (field lists, live values, in-place editing of post meta) is `assets/bindings-editor.js`.
- **Abilities** are in `inc/abilities/`, one file per ability (`get-catalog.php`, `find-posts.php`, `get-post-blocks.php`, `insert.php`, `configure.php`, `bind.php`, `validate.php`), each holding its registration and its callbacks. `shared.php` holds what several use: the permission callbacks, the input schemas, `check_attributes()`, `validation()`, `locate()` and `finish()`, and `register()`, which registers the abilities in order. `inc/abilities.php` only loads them. They are a thin layer over `inc/block-tree.php` (read and write a post's blocks by path) and `inc/catalog.php` (what the pack offers, and which binding args are real).
- **Change notes** for `readme.txt`'s changelog are files in `unreleased/`, one per pull request, folded in by the release pull request. The format is in [unreleased/README.md](../unreleased/README.md).
- **The usage index** (`inc/freshness/usage.php`) records which posts and templates show each Site Data and bindable post meta field, as post meta `_voyager_open_blocks_uses`, written on save. `get-catalog`'s `used_on` and the "Shown on" line under each field on Settings > Site Data read it, and the purge (`inc/freshness/purge.php`) uses it to find the pages a change must refresh. Activation starts a WP-Cron backfill of every post, and `wp voyager-open-blocks reindex` rebuilds it at once. The shapes are contracts 1 and 2 in [v2-contracts.md](v2-contracts.md).
- **DESIGN.md** at the repo root describes the patterns' design in [Google's DESIGN.md format](https://github.com/google-labs-code/design.md). The entry skill tells agents to read it after the site's own DESIGN.md. It ships in the plugin zip, so the copy in the plugin's folder matches the version installed.
- **The v2 contracts**, the shapes the lanes of version 2 build against, are in [v2-contracts.md](v2-contracts.md). The lanes are in [AGENTS.md](../AGENTS.md#lanes).

## The offline gates

`npm run check` needs no WordPress. It fails unless:

1. every `patterns/*.html` matches its intent,
2. `block-runner validate --strict` passes on all pattern markup,
3. the same validator rejects `tests/invalid/`, so the gate is shown to bite,
4. `block-runner plugin inspect` recognizes the plugin as a `@wordpress/scripts` host. On Windows this step is skipped: Block Runner 1.0.0 compares `__DIR__ . '/build'` with a native path and never matches a backslash path.
5. `Stable tag` in `readme.txt` matches `Version` in `voyager-open-blocks.php`.
6. the entry skill's `pack-version` (`skills/voyager-open-blocks/SKILL.md`) matches `Version` in `voyager-open-blocks.php`.
7. `tests/fixtures/site-data-keys.json` matches the hash in `tests/fixtures/site-data-keys.sha256`.
8. `readme.txt` has a `= Source code =` section with the GitHub link and `npm run build`. The plugin directory asks a plugin that ships built files to say where their source is, and Plugin Check does not test for it.
9. `readme.txt`'s `== Changelog ==` has an entry, `= <version> =`, for `Version` in `voyager-open-blocks.php`. A release pull request bumps `Version`, so it stays red until `npm run changelog:fold` writes its notes in.
10. every change note in `unreleased/` is well-formed (`npm run changelog:fold -- --check`),
11. `scripts/fold-unreleased.mjs` passes its known-answer test, `scripts/test-fold-unreleased.mjs` (`npm run test:fold`), all 12 of its cases: the notes in `tests/fixtures/fold-unreleased/` folded into its `readme.in.txt` must give `readme.expected.txt`, and malformed notes must stop the fold with nothing changed,
12. `DESIGN.md` lints with `@google/design.md` (`npm run lint:design`) with no errors and no warnings. Contrast under 4.5:1 between a component's colours, an unused colour and a section out of order are all warnings,
13. the same linter rejects `tests/invalid/design-broken-ref.md`, a component pointing at a colour token that does not exist, so that gate is shown to bite,
14. the gate in 12 rejects `tests/invalid/design-low-contrast.md`, which the linter passes with one contrast warning and exit 0, so the rule that fails on any warning is shown to bite,
15. the logic of `npm run cache-check` passes its known-answer test, `scripts/test-cache-check.mjs` (`npm run test:cache-check`), all 21 of its cases, including crashes planted in each step, which must exit 2 and never pass a row,
16. the guard on 11 and 15 rejects `tests/invalid/no-tests.mjs`. `node --test` reports a file with no tests as one passing test, so the guard counts each file's known cases rather than "more than none".
17. no tracked file but `CHANGELOG.md` and `bench/runs/` holds a tracker issue ID. The same `git grep` must find one planted in a temporary file, so a search that cannot match fails too.

The rest of what CI runs:

```bash
npm run lint:js
npm run lint:css
composer install           # once, for the two PHP checks below
composer phpstan           # PHPStan at level max over the plugin file, inc/ and src/, no baseline
composer test              # PHPUnit unit suite, no WordPress needed
npm run plugin-check       # Plugin Check, activation and uninstall on the zip in a fresh WordPress (needs wordpress.org)
```

## Local site

A throwaway WordPress on SQLite that WP-CLI, Playground and Wesper all read from the same files. Use it when you need a site that lasts between runs, or WP-CLI against it. It needs `wp` and `php` on `PATH`.

```bash
npm run playground -- setup --site ../voyager-open-blocks-site   # install WordPress + SQLite, link this checkout, seed demo pages
npm run playground -- serve --site ../voyager-open-blocks-site   # serve it with Playground at http://127.0.0.1:9400
npm run context -- --site ../voyager-open-blocks-site            # wesper collect, then check every item is listed
npm run playground -- blueprint cta-band                         # boot blueprints/cta-band.json with this checkout mounted
```

Two setup details that matter: the SQLite journal mode is set to `DELETE`, because Playground's WebAssembly PHP cannot share WAL's index with host PHP. And the site lives outside the checkout, because the checkout is linked into it.

`npm run context` fails if Wesper's manifest is missing any block, binding source or pattern in this repo, or carries a warning that names one. Other warnings are printed. WordPress 7.1.3 itself produces three (`core/navigation` and `core/query` provide context from attributes they no longer register), with or without this plugin. Run it when registration changes.

### End to end over REST

With a site served, `npm run e2e -- --context site.context.json` runs the abilities end to end over REST, the way an agent with only MCP or REST access would. It finds pages, inserts the CTA band (as shipped, then with copy), places an icon inside the header beside its call button, builds a proof strip with more credentials than the pattern ships, binds post meta in a template, and configures and binds an icon. It checks that bad input and a contributor without permission are refused, runs `block-runner validate --strict` on every markup returned and on the saved pages, checks the bound values render, and deletes what it created.

It reads an application password from `VOYAGER_OPEN_APP_PASSWORD` (`wp user application-password create admin e2e --porcelain`). `setup` sets `WP_ENVIRONMENT_TYPE` to `local` so application passwords work over plain HTTP.

The quickest way to get a site for it needs no `wp` or `php`: `npm run dev -- --no-login --ready-file site.json` boots the dev site in the background and writes its URL and a fresh application password to `site.json`. That is what CI does.

### End to end in the block editor

`npm run e2e:editor` boots its own fresh dev site and drives headless Chrome, logged in as admin. It checks the first-run notice, the "Used by" and "Shown on" lines on Settings > Site Data, the editor's empty-value warning, the placeholder hints (including copies from another host or folder), that each pattern's markup pastes into the editor as valid blocks, and that no editor-only hint reaches the front end. Its check g covers theme files: a template part that is only a file in the active theme, with the phone bound in it, is counted in `get-catalog`'s `used_on` once `switch_theme` rebuilds the theme index, and not before. It needs Chrome or Chromium; set `CHROME_PATH` if it isn't found. `--only a,c` runs some checks; `--help` lists the options.

### Usage index check

The dev site activates the plugin before it seeds its pages, so every page there is indexed on save, and neither of the index's whole-site paths runs in the checks above. `npm run check:usage-index` boots its own dev site with WP-Cron off, adds 60 pages bound to the phone, and records what the index holds after those saves. Then it deletes the index and checks that each path puts every row back, and nothing else:

- `wp voyager-open-blocks reindex`, run through WP-CLI, over more than one batch of 50 posts;
- the backfill that activation starts: the plugin is deactivated, a page is added while it is off, the plugin is activated again, and the backfill event runs as WP-Cron would. The plugin version is unchanged, so the check on `init` starts nothing, and activation is the only way in.

It also checks the "Shown on" line's order: titles in the database's order, so "Usage check 10" before "Usage check 2". `--plant activation` takes the backfill off the activation hook and `--plant reindex` replaces the command with one that reports success and indexes nothing; each run then passes only when the check fails that path and passes the rest. CI runs all three ([`e2e.yml`](../.github/workflows/e2e.yml)). It exits 0 on a pass, 1 when a path fails, and 2 when it could not tell.

### Site checks

Each of these runs against the local site and exits non-zero on the first mismatch.

- **`npm run check:site-data -- --site ../voyager-open-blocks-site`** writes the untidy options in `tests/fixtures/site-data-keys.json` to the site, runs `values()` through `wp eval-file`, compares all 18 `voyager/site-data` keys to the fixture's `expected` values and prints `ok: 18 site-data keys match the fixture`. It puts the previous option values back, pass or fail. `{{year}}` in `expected` stands for the current UTC year. The `voyager_site_*` options are written with their sanitize filters removed, so the check pins `values()` and not the settings page.
- **`npm run check:resolution`** (`node scripts/binding-resolution-check.mjs --site <dir>`) binds one paragraph to `phone` and one to a key the source does not have, then checks that `get-post-blocks` reports the first as `resolved` with a value preview and the second as `fell_back` with reason `unknown_key`. It deletes the page and restores the option, pass or fail.
- **`npm run check:bindable-meta`** (`node scripts/bindable-meta-check.mjs --site <dir>`) checks a stored Bindable post meta row: `get-catalog` lists it, `resolve()` reads it, a `_private` key is refused, and removing the row removes the field.
- **`node scripts/foreign-owner-check.mjs --site <dir>`** checks the voyager-blocks case below, with `tests/fixtures/foreign-site-data.php` standing in for the owner.

## Cache check

`npm run cache-check` asks whether a Site Data change reaches a page that a cache has already stored. It boots Playground with one cache, publishes a page whose paragraph is bound to `phone`, changes the phone over REST, and looks for the new number. It exits 0 when the change reached the page (fresh), 1 when it did not (stale), and 2 when it could not tell. It runs in CI, one check per cache and mode ([`cache.yml`](../.github/workflows/cache.yml)). Each row passes on the outcome in the harness's result file, not on the exit code alone: Node also exits 1 when it crashes. Its logic has a known-answer test that `npm run check` runs.

- **`--real`** is for caches that serve pages from PHP inside Playground: `npm run cache-check -- --plugin wp-super-cache --real`. It loads the page twice as a visitor, checks that the second load came from the cache, changes the phone and loads it again. A footer comment unique to each render tells a cached copy from a fresh one.
- **`--spy`** is for caches in a server or a CDN, and for plugins that cannot be installed here: `npm run cache-check -- --plugin dummy --spy`. A test mu-plugin records the cache's purge entry points and its outgoing HTTP (`pre_http_request`) during the change, and the run passes when one of them names the page, or purges everything. `dummy` is a pretend cache with a CDN API, offered to the pack as a cache adapter.
- **`--no-purge`** is the control: it empties the pack's purge through contract 2's filters (`voyager_open_blocks_cache_adapters` and `voyager_open_blocks_purge_urls`), so the page must go stale. **`--stand-in`** adds the harness's own hand-run of contract 2 beside the pack's purge, the positive control for `--spy`.
- **`--pages <n>`** publishes n pages bound to `phone`, one at a time, and measures the last, which must have the highest ID: `npm run cache-check -- --plugin wp-super-cache --real --pages 51`. The purge cleans at most 50 pages one by one, oldest ID first, so the 51st is one only `purge_all` reaches. The default is 1.
- **`--lockdown`** turns on WP Super Cache's Lock Down before the run, as its settings page does (`WPLOCKDOWN` `'1'` in `wp-cache-config.php`): `npm run cache-check -- --plugin wp-super-cache --real --lockdown`. Lock Down keeps the cached pages through a traffic spike, so the page must go stale.

The pack purges when Site Data changes ([contract 2](v2-contracts.md#2-purge), `inc/freshness/purge.php`), so `dummy --spy`, `wp-super-cache --real` and `wp-super-cache --real --pages 51` expect fresh. `dummy` hears it through its adapter. WP Super Cache hears it two ways. The purge calls `clean_post_cache()` for each page that shows the field, up to 50, and WP Super Cache purges that page on that hook. The pack's WP Super Cache adapter (`inc/cache/wp-super-cache.php`) hears the rest: it deletes each URL on a page purge, and clears the site's cache on `purge_all`, which is what refreshes a page past the first 50. The three `--no-purge` rows are controls and stay stale for good. On a one-page row the empty URL list switches the whole purge off. On the 51-page row the purge still cleans the first 50 pages and sets `purge_all`, and with no adapter left nothing reaches the 51st. `wp-super-cache --real --lockdown` expects stale too: in Lock Down WP Super Cache hooks none of its own purges, and the adapter is inactive, so nothing deletes the page. It goes fresh if the adapter purges through Lock Down. The PLUGINS table in `scripts/lib/cache-check.mjs` and the fixtures in `tests/fixtures/cache-check/` hold the details of each cache.

WP Super Cache is pinned at 3.1.5, read from its source. The harness turns it on with its own `wp_cache_enable()` after activation, and leaves it in "simple" mode, where `wp-cache-phase1.php` serves stored pages with `wp_cache_serve_cache_file()`. Its purge entry points are `wpsc_delete_url_cache()` and `wp_cache_clear_cache()` in `wp-cache-phase2.php` and `wpsc_delete_post_cache()` in `inc/cache-files.php`. Playground runs PHP with the SAPI name `cli` and sets `PHP_SELF` to the request path, which makes `wpsc_is_backend()` refuse to cache any page but the front page. A WP Super Cache plugin file in the fixtures sets `PHP_SELF` to `/index.php`, as Apache or nginx would.

## Sites running voyager-blocks

The two binding sources keep the names they have in the voyager-blocks plugin, so content written for either resolves on both. When voyager-blocks is active it registers them first (at `init` priority 5), and this plugin stands aside: it registers neither source, the options nor Settings > Site Data. `get-catalog` and `bind` then use the owning plugin's fields, read from its `voyager_binding_sources` definition. When the owner does not describe its fields, `fields` is `null` and `bind` refuses the source.

## Screenshots

`npm run screenshots` renders the patterns. The WordPress.org listing shows them in this order: 1 CTA band, 2 Proof strip, 3 Logo marquee, 4 Closing CTA, 5 Contact section, 6 Icon block, 7 Settings > Site Data. The captions are under `== Screenshots ==` in `readme.txt`.

## Releases

release-please opens the release pull request and writes `CHANGELOG.md`. You don't tag by hand. On the release pull request, `npm run changelog:fold` writes the notes in `unreleased/` into `readme.txt`'s changelog and deletes them. The steps are in [CONTRIBUTING.md](../CONTRIBUTING.md#how-a-release-happens).

`npm run zip` writes `voyager-open-blocks.zip` from the `files` list in `package.json`. Pushing a `v*` tag runs [`release.yml`](../.github/workflows/release.yml): it builds, zips, checks the zip and attaches it to the tag's GitHub release, creating the release if it does not exist. A tag with a suffix (`v0.2.0-rc.1`) makes a pre-release. The tag's version, without the suffix, must match `Version` in `voyager-open-blocks.php`, and a version bump moves `Stable tag` in `readme.txt` with it.

`readme.txt` is the WordPress.org listing. The zip ships it, and Plugin Check must report no errors on the plugin installed from the zip: `npm run plugin-check` runs it, and CI runs it on every pull request. Run against this checkout linked into a site, Plugin Check also reports files the zip leaves out (`.git`, dotfiles, `playground/`).
