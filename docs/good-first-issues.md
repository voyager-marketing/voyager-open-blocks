# Good first issues

Five small starter tasks. Each names the files to start in. Every one is done when `npm run check` exits 0 and the pull request says how it was checked. The rules are in [CONTRIBUTING.md](../CONTRIBUTING.md), and the commands are explained in [development.md](development.md).

## 1. Add a pattern from an intent

**Files:** [`patterns/intents/`](../patterns/intents/), [`skills/`](../skills/), [`blueprints/`](../blueprints/), [`playground/seed.php`](../playground/seed.php)

A pattern is a JSON intent that Block Runner assembles into block markup. You never write the markup by hand. Open an issue first with the section you have in mind, such as a footer line with the copyright, phone and email bound to Site Data, so a maintainer can agree it fits.

1. Copy the closest intent, for example `patterns/intents/closing-cta.json`, to `patterns/intents/<name>.json`. The file name becomes the slug `voyager-open/<name>`.
2. Change `title`, `description`, `keywords` and the `intent` tree. Keep `voyager-open` in `categories`, so the pattern lists under "Business sections (Open Blocks)". Without a `title` the plugin skips the file.
3. Give every block an agent may rewrite a `metadata.name`. Names must be unique within the pattern: the `insert` ability's `copy` input finds blocks by them.
4. Bind business details to `voyager/site-data` with args copied from [the key list](use-in-your-theme.md#site-data-keys), and author real placeholder text beside each binding. Placeholder data uses example.com and the 555-01xx range.
5. Run `npm run patterns:build`. It writes `patterns/<name>.html` and fails on an invalid block, a dropped attribute or a repeated name. Commit both files.
6. Add `skills/voyager-open-<name>/SKILL.md` in the shape of [the CTA band's](../skills/voyager-open-cta-band/SKILL.md), and add the item to the [README](../README.md#what-is-in-the-pack). The [entry skill](../skills/voyager-open-blocks/SKILL.md) is a quick card with no list of items, so it needs no row.
7. Add a demo page for it to `$demos` in `playground/seed.php`, and `blueprints/<name>.json` landing on `/voyager-open/<name>/`. Copy an existing blueprint and change its `meta` and `landingPage`.

It must pass:

- `npm run check`: the `.html` matches its intent, and `block-runner validate --strict` finds 0 invalid blocks and 0 warnings.
- The pack's pattern rules: core blocks and theme presets only, no `core/html`, no inline colours, and no client names or client content.
- `npm run context` against a local site, if you can run one: Wesper must list the new pattern. It reads `patterns/intents/` on its own, so there is nothing to register.

## 2. Add an outline icon to the icon block

**Files:** [`assets/icons.json`](../assets/icons.json), [`src/icon/block.json`](../src/icon/block.json)

Add one Heroicons outline icon to `assets/icons.json`, in the same shape as its neighbours. Heroicons is MIT licensed, so keep the source name in the pull request. Then add the icon's key to the `icon` enum in `src/icon/block.json`.

Run `npm run build` and commit the changed `build/` files with the source. CI fails when `build/` is stale.

## 3. Add a markup fixture the validator must reject

**Files:** [`tests/invalid/`](../tests/invalid/README.md), [`scripts/check.mjs`](../scripts/check.mjs)

Add one markup file to `tests/invalid/` that `block-runner validate --strict` rejects. A good example is a block with an attribute the validator drops, or a heading that skips a level. `scripts/check.mjs` runs the validator over `tests/invalid/*.html` and fails unless it exits non-zero, so a validator that stops rejecting anything turns the check red.

## 4. Make the plugin inspect step run on Windows

**Files:** [`scripts/check.mjs`](../scripts/check.mjs), [`docs/development.md`](development.md#the-offline-gates)

On Windows, `scripts/check.mjs` skips `block-runner plugin inspect`. Block Runner 1.0.0 compares `__DIR__ . '/build'` with a native path and never matches a backslash path. Find a way to run the step on Windows without changing Block Runner, for example by passing it a path in the form it expects. Keep the skip for any platform where the step still fails, and say which.

Done when the step runs and passes on Windows, or the pull request shows why it cannot.

## 5. Name the pattern a block style belongs to

**Files:** [`inc/block-styles.php`](../inc/block-styles.php), [`patterns/intents/proof-strip.json`](../patterns/intents/proof-strip.json)

`inc/block-styles.php` registers the Ticks style that the proof strip uses. Read the proof strip intent and check that its markup asks for the style by name. Add a PHP unit or e2e check that fails when the style is renamed or removed, or document in the file header which patterns use each style.

Done when the check fails on a renamed style and passes on the current tree.
