## Issue

Fixes #<!-- issue number -->

## What changed

<!-- One or two sentences on the change and why. -->

## How it was checked

- [ ] `npm run check` exits 0
- [ ] `npm run lint:js` and `npm run lint:css` (if JS or CSS changed)
- [ ] `npm run e2e -- --context site.context.json` against a served local site (if an ability, pattern or block changed)
- [ ] A change note in `unreleased/` if a site owner would notice the change, and no edit to `readme.txt`'s changelog (format in `unreleased/README.md`)
- [ ] Says above if it changes the counts in `README.md` or `files` in `package.json`

Fitness: fails before (<how it was observed failing>), passes after (<how it now passes>)
