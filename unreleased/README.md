# Change notes

`readme.txt`'s changelog is what WordPress.org shows. Pull requests do not edit it. A pull request that changes something a site owner would notice adds one note here instead, and the release pull request folds every note into the changelog at once. That keeps parallel pull requests from all editing the same lines of `readme.txt`.

## One note per pull request

Name it after your branch, with the slashes turned into hyphens and any tracker ID left out: branch `feat/compose` writes `unreleased/feat-compose.md`. Lowercase letters, digits and hyphens only. Two pull requests never share a branch, so they never share a note.

It holds two lines:

```
type: feat
`compose` builds a draft page from a recipe or a list of sections.
```

- The first line is the type: `feat` (something new), `fix` (something that was wrong now works) or `docs` (the readme or the skills say something new).
- The second line is the one line you want under `== Changelog ==`, written for a site owner, without the leading `* `.

Nothing else goes in the file. A change nobody using the plugin would notice, such as a refactor, a test or CI, adds no note.

`npm run check` reads every note and fails on one that is not in this shape. To check the notes alone, run `npm run changelog:fold -- --check`.

## At release

On the release pull request, run:

```
npm run changelog:fold
```

It reads the version from `voyager-open-blocks.php`, which the release pull request has already bumped, writes each note's line under `= <version> =` in `readme.txt` (feat first, then fix, then docs), and deletes the notes. This file stays. Commit both, then merge. Only the release pull request edits `readme.txt`'s changelog.

release-please rebuilds the release pull request from `main` whenever something merges, which drops your fold commit and brings the notes back. Fold last, just before you merge, and fold again if the pull request changed after you did.
