Markup that must fail `block-runner validate --strict`. `npm run check` asserts it does, so a validator that has stopped rejecting anything turns the check red.

`design-broken-ref.md` is a DESIGN.md whose component points at a colour token that does not exist. `npm run check` asserts `@google/design.md lint` rejects it with a `broken-ref` error, so the DESIGN.md gate is shown to bite too.
