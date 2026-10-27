# Contributing to Voyager Open Blocks

Thanks for helping. This file covers who runs the project, what it will and won't take, how fast you can expect a reply, and how to set up, test and send a change.

## Who maintains it

Voyager Marketing maintains the pack. Ben Whitlock ([@benwhit](https://github.com/benwhit)) is the lead maintainer and makes the calls on scope, design and releases. Decisions are made in public GitHub issues, so the reasoning stays where anyone can read it.

"Voyager" and "Voyager Open Blocks" are Voyager Marketing's names. The GPL covers the code, not the names, so a fork needs a name of its own.

## Scope: a small pack an agent can use

The pack is deliberately small: blocks, patterns and binding sources that an AI agent can place, configure and bind without hand-writing markup. A change has to keep that true. Read [AGENTS.md](AGENTS.md) first; the rules every change keeps are there. The short version:

- **Core blocks and theme presets first.** Patterns use core blocks and the active theme's presets, so the theme decides how they look. A new custom block needs a reason a pattern of core blocks can't meet.
- **Every item is checked.** Patterns start as intent trees in `patterns/intents/` and are assembled by `npm run patterns:build`. Never edit `patterns/*.html` by hand. Block Runner validates everything at `--strict` with 0 warnings.
- **Agents never guess binding args.** `get-catalog` lists the exact args, and `bind` accepts nothing else.
- **No client names, client content or client-specific bindings**, anywhere in the repo or its history.

If you're unsure whether something fits, open a discussion before you write code.

## Support window and releases

Issues are answered within two business days. Support hours are in the [README](README.md#support). "Answered" means a maintainer replies; a fix may take longer. Merged changes ship in the next two-weekly release. Security fixes follow [SECURITY.md](SECURITY.md) and don't wait for the batch.

## Set up

You need Node `^22.23.4 || ^24.22.1 || >=26.12.1` (`.nvmrc` pins 24.22.1), and for the local site, PHP 8.1+ and WP-CLI on `PATH`.

```
npm install
npm run build              blocks
npm run patterns:build     intents -> patterns/*.html
```

The local site is a throwaway WordPress on SQLite that WP-CLI, Playground and Wesper all read. It lives outside the checkout, because the checkout is linked into it.

```
npm run playground -- setup --site ../voyager-open-blocks-site
npm run playground -- serve --site ../voyager-open-blocks-site
```

## Test

```
npm run check              every offline gate: build drift, patterns, Block Runner, plugin inspect
npm run lint:js
npm run lint:css
composer install           once, for the two PHP checks below
composer phpstan           PHPStan at level max over the plugin file, inc/ and src/, no baseline
composer test              PHPUnit unit suite, no WordPress needed
npm run e2e -- --context site.context.json     the abilities over REST, against the served local site
```

CI runs the build-drift check, `npm run check`, both linters, PHP lint, `composer phpstan`, `composer test` and Block Check on every pull request, and a pull request needs them green to merge.

`composer phpstan` takes PHP 8.1 or later. Fix what it reports; don't add a baseline. The unit tests stub the few core functions the modules call in `tests/wp-stubs.php`, so a test that needs a real WordPress belongs in the e2e script.

## Send a change

1. Fork, branch from `main`, and keep the change to one thing.
2. Run the gates above. Add or update the pattern intent, skill or e2e step your change touches.
3. Open a pull request that says what changed and why, and how you checked it. Give it a conventional commit title (`feat: add the icon block`). Merges are squashes, so the title becomes the commit that release-please reads, and CI fails a title that is not one. Types: feat, fix, docs, style, refactor, perf, test, chore, revert. Keep the header to 72 characters.

## How a release happens

You don't tag by hand. release-please keeps one open release PR on `main` that bumps the version in `voyager-open-blocks.php` (the header and `VOYAGER_OPEN_BLOCKS_VERSION`), the `readme.txt` Stable tag and `package.json`, and writes `CHANGELOG.md` from the commit titles. Feat bumps the minor version and fix the patch. A breaking change (`feat!:` or a `BREAKING CHANGE:` footer) bumps the major.

To release, add one line for the version under `== Changelog ==` in `readme.txt` on the release PR (WordPress.org shows that section), then merge the PR. Merging creates the tag and the GitHub Release, and the same run builds `voyager-open-blocks.zip` and attaches it. Nothing else to do.

If the zip fails to attach, run the Release workflow by hand from the Actions tab with the tag (for example `v0.4.0`). It rebuilds the zip and uploads it.

When release-please opens or updates the release PR with the built-in token, GitHub holds that PR's CI and Block Check runs until someone approves them. The Release Please workflow approves them itself, so the required checks run with nobody closing, reopening or approving anything. If the checks are still missing, look at the "Approve the release PR's held checks" step in the Release Please run.
