# Voyager Open Blocks

WordPress blocks, binding sources and patterns that an AI agent can use correctly. Each one has a `SKILL.md` that teaches one loop: read the site with [Wesper](https://github.com/humanmade/wesper), author native blocks with [Block Runner](https://github.com/humanmade/block-runner), then bind them to real data.

| Item | Kind | Skill |
|---|---|---|
| `voyager-open/icon` | Dynamic block: 35 outline icons, optional link, CSS-only animation | [skills/voyager-open-icon](skills/voyager-open-icon/SKILL.md) |
| `voyager/site-data` | Binding source: company, phone, email, address, hours, social links | [skills/voyager-site-data](skills/voyager-site-data/SKILL.md) |
| `voyager/post-meta-text` | Binding source: allowlisted post meta, with JSON dot paths | [skills/voyager-post-meta-text](skills/voyager-post-meta-text/SKILL.md) |
| `voyager-open/cta-band` | Pattern | [skills/voyager-open-cta-band](skills/voyager-open-cta-band/SKILL.md) |
| `voyager-open/proof-strip` | Pattern | [skills/voyager-open-proof-strip](skills/voyager-open-proof-strip/SKILL.md) |
| `voyager-open/logo-marquee` | Pattern | [skills/voyager-open-logo-marquee](skills/voyager-open-logo-marquee/SKILL.md) |
| `voyager-open/closing-cta` | Pattern | [skills/voyager-open-closing-cta](skills/voyager-open-closing-cta/SKILL.md) |
| `voyager-open/contact-section` | Pattern | [skills/voyager-open-contact-section](skills/voyager-open-contact-section/SKILL.md) |

Start an agent at [skills/voyager-open-blocks](skills/voyager-open-blocks/SKILL.md). The skills follow the open Agent Skills layout (`skills/<name>/SKILL.md`), so any harness that reads that layout can load them.

Requires WordPress 6.9+ and PHP 8.1+. Patterns use core blocks and the active theme's presets only, so the theme decides how they look.

## Try it

[WordPress Playground](https://playground.wordpress.net/) blueprints load the pack with demo content: [`blueprint.json`](blueprint.json) for everything, or one per item in [`blueprints/`](blueprints/). They install the plugin from this repository's `main` branch.

## How it is built

- **Blocks** live in `src/<name>/` and build with `@wordpress/scripts` (`npm run build`, which writes `build/blocks-manifest.php`). The plugin registers everything in that manifest. `build/` is committed so a Git install works without a build step.
- **Patterns** start as Block Runner intent trees in `patterns/intents/<name>.json`, with the pattern's title, description and categories beside the tree. `npm run patterns:build` assembles each one into `patterns/<name>.html` and fails on any invalid block or dropped attribute. Do not edit the `.html` files by hand.
- **Binding sources** are in `inc/site-data.php` and `inc/post-meta-text.php`. Their editor half (field lists, live values, in-place editing of post meta) is `assets/bindings-editor.js`.

The two binding sources keep the names they have in the voyager-blocks plugin, so content written for either resolves on both. When voyager-blocks is active it registers them first, and this plugin stands aside.

## Development

Node `^22.23.4 || ^24.22.1 || >=26.12.1` (Block Runner 1.0.0's range; `.nvmrc` pins 24.22.1).

```bash
npm install
npm run build              # blocks
npm run patterns:build     # intents -> patterns/*.html
npm run check              # every offline gate, below
```

`npm run check` fails unless:

1. every `patterns/*.html` matches its intent,
2. `block-runner validate --strict` passes on all pattern markup,
3. the same validator rejects `tests/invalid/`, so the gate is shown to bite,
4. `block-runner plugin inspect` recognizes the plugin as a `@wordpress/scripts` host. On Windows this step is skipped: Block Runner 1.0.0 compares `__DIR__ . '/build'` with a native path and never matches a backslash path.

### Local site

A throwaway WordPress on SQLite that WP-CLI, Playground and Wesper all read from the same files. It needs `wp` and `php` on `PATH`.

```bash
npm run playground -- setup --site ../voyager-open-blocks-site   # install WordPress + SQLite, link this checkout, seed demo pages
npm run playground -- serve --site ../voyager-open-blocks-site   # serve it with Playground at http://127.0.0.1:9400
npm run context -- --site ../voyager-open-blocks-site            # wesper collect, then check every item is listed
npm run playground -- blueprint cta-band                         # boot blueprints/cta-band.json with this checkout mounted
```

`npm run context` fails if Wesper's manifest is missing any block, binding source or pattern in this repo, or carries a warning that names one. Other warnings are printed. WordPress 7.1.3 itself produces three (`core/navigation` and `core/query` provide context from attributes they no longer register), with or without this plugin.

Two setup details that matter: the SQLite journal mode is set to `DELETE`, because Playground's WebAssembly PHP cannot share WAL's index with host PHP. And the site lives outside the checkout, because the checkout is linked into it.

## License

GPL-2.0-or-later. Icons are from [Heroicons](https://heroicons.com) (MIT, `assets/LICENSE-heroicons.txt`).
