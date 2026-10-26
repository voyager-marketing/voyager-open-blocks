# Good first issues

Four small starter tasks. Each names the file to start in. Every one is done when `npm run check` exits 0 and the pull request says how it was checked. The rules are in [CONTRIBUTING.md](../CONTRIBUTING.md).

## 1. Add an outline icon to the icon block

**Files:** [`assets/icons.json`](../assets/icons.json), [`src/icon/block.json`](../src/icon/block.json)

Add one Heroicons outline icon to `assets/icons.json`, in the same shape as its neighbours. Heroicons is MIT licensed, so keep the source name in the pull request. Then add the icon's key to the `icon` enum in `src/icon/block.json`.

Run `npm run build` and commit the changed `build/` files with the source. CI fails when `build/` is stale.

## 2. Add a markup fixture the validator must reject

**Files:** [`tests/invalid/`](../tests/invalid/README.md), [`scripts/check.mjs`](../scripts/check.mjs)

Add one markup file to `tests/invalid/` that `block-runner validate --strict` rejects. A good example is a block with an attribute the validator drops, or a heading that skips a level. `scripts/check.mjs` runs the validator over `tests/invalid/*.html` and fails unless it exits non-zero, so a validator that stops rejecting anything turns the check red.

## 3. Make the plugin inspect step run on Windows

**Files:** [`scripts/check.mjs`](../scripts/check.mjs), [`README.md`](../README.md#development)

On Windows, `scripts/check.mjs` skips `block-runner plugin inspect`. Block Runner 1.0.0 compares `__DIR__ . '/build'` with a native path and never matches a backslash path. Find a way to run the step on Windows without changing Block Runner, for example by passing it a path in the form it expects. Keep the skip for any platform where the step still fails, and say which.

Done when the step runs and passes on Windows, or the pull request shows why it cannot.

## 4. Name the pattern a block style belongs to

**Files:** [`inc/block-styles.php`](../inc/block-styles.php), [`patterns/intents/proof-strip.json`](../patterns/intents/proof-strip.json)

`inc/block-styles.php` registers the Ticks style that the proof strip uses. Read the proof strip intent and check that its markup asks for the style by name. Add a PHP unit or e2e check that fails when the style is renamed or removed, or document in the file header which patterns use each style.

Done when the check fails on a renamed style and passes on the current tree.
