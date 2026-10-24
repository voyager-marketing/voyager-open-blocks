# Agent notes

Using the pack on a site: start at `skills/voyager-open-blocks/SKILL.md`.

Working on this repo:

- A block is a folder in `src/` with a `block.json`. Run `npm run build` and commit `build/`.
- A pattern is an intent in `patterns/intents/<name>.json`. Run `npm run patterns:build` and commit the generated `.html`. Never edit the `.html`.
- Every block and pattern ships a `skills/<name>/SKILL.md`. Update it when its attributes, structure or bindings change.
- Run `npm run check` before a commit. Run `npm run context` against a local site (README, "Local site") when registration changes.
- No client names, client content or client-specific bindings. Placeholder data uses example.com and the 555-01xx range.
- Patterns use core blocks and theme presets only. No `core/html`, and no inline colours.
