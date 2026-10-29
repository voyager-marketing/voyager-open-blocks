# With/without benchmark

Does an agent build a correct, editable WordPress page more often with this pack's loop than without it? This folder holds the briefs, the run protocol, the runner and the scorer. The result is in [RESULTS.md](RESULTS.md) and on the tracking issue below.

Tracking issue: VOY-1774.

## The two arms

| | `baseline` | `loop` |
|---|---|---|
| Site | Fresh Playground site, block theme (Twenty Twenty-Five), no pack | The same, plus this pack |
| The agent gets | The brief and the WordPress MCP Adapter | The brief, the pack's skills, a Wesper manifest of the site, and the five pack abilities |
| It writes through | Raw block markup | The abilities, or markup it checked with Block Runner |

Same model, same settings and same brief text in both arms. One fresh site per brief per arm, so nothing carries over between runs.

## Briefs

`briefs.json` holds 20 fake businesses across home services, legal, healthcare, nonprofit and professional services. The first 10 are the `derive` split and the last 10 are `held-out`. Phones use the 555-01xx range and emails use `example.com` subdomains, so no real business is named or reachable.

## The two tasks per brief

Before the build task, set the site's business details (`voyager_site_*` options, or the theme's equivalent in `baseline`) to the brief's values. Both arms get the same data in the same place.

**Build.** Send this prompt with the brief's fields filled in:

> Build the home page for {business}, a {industry} business in {address_city}, {address_state}. It needs a call to action ("{primary_cta.label}" linking to {primary_cta.url}) with a click-to-call button, a row with these credentials: {credentials}, the contact details (phone, email, address, hours), and a closing call to action. Use the site's existing business details. Save it as a published page.

Save the page's `post_content` as `runs/<arm>/<brief-id>/page.html`.

**Edit.** In the same session, send:

> The business phone number is now {new_phone}. Update the site so every place that shows or dials the phone uses the new number.

Save `post_content` again as `after-edit.html`. Save the site's business details after the edit as `site-data.json`, for example `{"phone": "...", "email": "..."}`.

## Measures

`node bench/score.mjs` scores what is in `runs/` and writes `results/` (results.json and .csv with one row per arm and brief, plus summary.json and summary.md).

1. **First-try valid.** `block-runner validate --strict` finds no invalid blocks and no warnings in `page.html`. A missing page is a failure, not a skip.
2. **Contact data bound.** For phone, email and address, does the page bind them to `voyager/site-data`, type them in as text, or leave them out? The score is bound fields divided by present fields, averaged over pages that show any contact data.
3. **Edit survival.** After the edit, the page is still valid, the old number is gone from both text and `tel:` links, and the new number shows, either typed or through a binding whose site value is now the new number.
4. **Design quality (optional, costs money).** A pairwise judge preference using the UIG harness and its known-answer controls. It is not in this scorer. Cap: $30.

`node bench/test-score.mjs` checks the scorer against known answers in `fixtures/`. Run it after any change to `score.mjs`.

`node bench/run.mjs` builds the two site templates and runs the briefs, one site at a time. Its header lists the options and what each run saves.

## Reading the result

Publish the summary table, the raw `runs/` and `results/`, and what failed, whichever arm wins. Report the derive and held-out splits separately; a lead that shows only on derive is not a result. Name the model, the pack commit and the Block Runner version.
