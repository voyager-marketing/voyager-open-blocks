Markup that must fail `block-runner validate --strict`. `npm run check` asserts it does, so a validator that has stopped rejecting anything turns the check red.

`design-broken-ref.md` is a DESIGN.md whose component points at a colour token that does not exist. `npm run check` asserts `@google/design.md lint` rejects it with a `broken-ref` error, so the DESIGN.md gate is shown to bite too.

`no-tests.mjs` is a test file with no tests in it, which `node --test` reports as one passing test. `npm run check` runs it through the guard it puts on the known-answer tests, at the fold test's floor of 12 cases, and asserts the guard refuses it, so a known-answer test that lost its cases turns the check red.
