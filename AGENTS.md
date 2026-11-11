# Agent notes

Using the pack on a site: start at `skills/voyager-open-blocks/SKILL.md`. It gives two routes: the abilities alone (MCP or REST, no shell), or a shell with Wesper and Block Runner.

Working on this repo:

- A block is a folder in `src/` with a `block.json`. Run `npm run build` and commit `build/`.
- A pattern is an intent in `patterns/intents/<name>.json`. Run `npm run patterns:build` and commit the generated `.html`. Never edit the `.html`.
- An ability is a file in `inc/abilities/`: its registration (`register_<name>()`) and its callbacks. What several abilities use (permission callbacks, input schemas, `check_attributes()`, `validation()`, `locate()`, `finish()`) is in `inc/abilities/shared.php`, and `register()` there sets the order the abilities are registered in. A new ability is a new file, a `require_once` in `inc/abilities.php` and a line in `register()`.
- Every block and pattern ships a `skills/<name>/SKILL.md`. Update it when its attributes, structure or bindings change.
- Run `npm run check` before a commit. Run `npm run context` against a local site (README, "Local site") when registration changes.
- Run `composer phpstan` and `composer test` too when you touch PHP (`composer install` first). PHPStan is at level max with no baseline: fix what it reports.
- No client names, client content or client-specific bindings. Placeholder data uses example.com and the 555-01xx range.
- Patterns use core blocks and theme presets only. No `core/html`, and no inline colours.

## Changelog notes, and the other shared spots

- **Never edit `readme.txt`'s changelog in a feature pull request.** A change a site owner would notice adds one note, `unreleased/<branch-slug>.md`, in the format in [unreleased/README.md](unreleased/README.md). The release pull request runs `npm run changelog:fold`, which moves every note into `readme.txt` and deletes them. Only the release pull request edits the changelog.
- **The counts in `README.md` and `files` in `package.json` are shared too.** A pull request that changes either says so in its body, so those pull requests merge one at a time: the second updates from `main` after the first lands, and fixes the count or the list there.

## Lanes

Version 2 is built in lanes that run at the same time. Each lane owns some files and changes them freely; it changes another lane's files only when its issue says so. The shapes lanes build against each other are in [docs/v2-contracts.md](docs/v2-contracts.md), each with its owner.

| Lane | Owns |
| -- | -- |
| Runtime | `inc/freshness/`, `inc/cache/`, structured data, build-time cache scripts |
| Abilities | `inc/abilities/`; reads `recipes/` |
| Design | `DESIGN.md`, section styles, `patterns/` and intents, `recipes/` |
| Blocks and data | `src/`, `inc/site-data.php` |
| CI and evals | `scripts/`, `.github/workflows/`, `bench/`, test fixtures |
| Skills and docs | `skills/`, `docs/`, `README.md` |

A file no lane owns, such as the rest of `inc/`, `readme.txt`, `package.json` or `voyager-open-blocks.php`, is shared: say in the pull request body which of them you change and why.
