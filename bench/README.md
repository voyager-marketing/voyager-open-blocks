# With/without benchmark

Does an agent build a correct, editable WordPress page more often with this pack's loop than without it? This folder holds the briefs, the run protocol, the runner and the scorer.

There are two versions. Version 1 is published: its protocol is below, its runs are in `runs/` and its result is in [RESULTS.md](RESULTS.md). Version 2 is the one `run.mjs` runs now. Its runs go to `runs-v2/`, so version 1's stay as published. To run version 1 again, check out `bench/` from the commit RESULTS.md names.

## Version 2

Version 2 asks harder questions than version 1. It separates the pack's data from the agent knowing how to use it, varies the number of credentials, runs three models and repeats each run, puts a page cache in front of every site, and scores the page a visitor gets as well as the markup that was stored.

### The three groups

| | `baseline` | `plugin` | `pack` |
|---|---|---|---|
| Site | Fresh Playground site, block theme (Twenty Twenty-Five), no pack | The same, plus this pack, installed and active | The same as `plugin` |
| The pack's abilities | none | none: `harness/plugin-group.php` unregisters every `voyager-open-blocks/*` ability after the pack registers it | all of them |
| The agent gets | The brief and the WordPress MCP Adapter | The same | The brief, the MCP Adapter, the pack's skills, a Wesper manifest of the site, and Block Runner and Wesper through `npx` |

`plugin` has the pack's Site Data, bindings and blocks, so a page can be bound, but nothing tells the agent how. It separates "the source exists" from "the agent knows how to use it".

Every group gets the same six site abilities through MCP (list, get, create and update a page; get and update the business details), from `harness/site-tools.php`. Core and the MCP Adapter register none that create a page. They take raw block markup and check nothing. In `baseline` and `plugin` they are the agent's only tools.

Each site template records the abilities the MCP Adapter lists (`harness/offered-abilities.php` runs its discover ability), and so does every run, in `run.json` as `abilities_offered`. `prepare` and `run` stop when `plugin` or `baseline` lists a pack ability, or `pack` lists none.

### Briefs

`briefs-v2.json` holds 12 invented businesses across the five kinds in the pack's copy examples: home services, legal, health and vet, hospitality and restaurants. Four ask for 3 credentials, four for 5 and four for 6, so none fits the proof strip's four slots by chance. The first 6 are the `derive` split and the last 6 are `held-out`, with two briefs of each credential count in each. Each brief also carries the year it was founded, a rating, a review count, three services and three places it serves, so the stats and service area sections have facts.

Phones are in the 555-0100 to 555-0199 range and emails are at `example.com` subdomains. Every business, person and place is invented, so none is real or reachable.

Before the build, every group gets the brief's business details and business profile in Site Data's options (`blogname` and the `voyager_site_*` options): phone, email, address, hours, services, service area, credentials, year founded, rating, review count and the main call to action. A list is one item per line. `site/get-business-details` returns them all, in every group.

### Models and repeats

`--models` takes a list of Claude Code model IDs. The default is the current model of each family: `claude-haiku-4-5`, `claude-sonnet-5` and `claude-opus-5-5`. `--repeats` runs each brief that many times. Each run records the model it asked for and every model the transcript shows answering.

A run lands in `runs-v2/<group>/<model>/<brief-id>/<repeat>/`. A complete run, one whose `run.json` finished with no harness failure and with the site's MCP server connected for every step, is skipped on a rerun. `--force` runs it again.

### The page cache

WP Super Cache sits in front of every site, at the version `npm run cache-check` pins and with its setup (`tests/fixtures/cache-check/wp-super-cache/setup.php`, run by `harness/setup-wp-super-cache.php`). `harness/page-cache.php` prints a marker unique to each render at the end of every page, the one the cache check prints.

Before the agent starts, the runner loads the front page twice as an anonymous visitor. After the build and after the edit, it loads the built page twice. Each time the second load must carry the first one's marker, which means the cache served it. Otherwise the run is a harness failure. `run.json` records every load under `cache`: its status, its marker, WP Super Cache's `X-WP-Super-Cache` header, and `from_cache`. After the edit it also records `stale`: the first load showed the copy the cache stored after the build.

The second load is what a visitor gets, and the runner saves it: `page.rendered.html` after the build and `after-edit.rendered.html` after the edit. After the build it also saves `page.png`, the rendered page at 1440 px wide and full height, through the pack's Chrome helper (`scripts/lib/chrome.mjs`), for the design score.

### The two tasks per brief

**Build v2.** Send this prompt with the brief's fields filled in:

> Build the home page for {business}, a {industry} business in {address_city}, {address_state}. It needs a call to action ("{primary_cta.label}" linking to {primary_cta.url}) with a click-to-call button, a row with these credentials: {credentials}, its services and the area it serves, a row of figures (the year it was founded, its rating and how many reviews that rating comes from), the contact details (phone, email, address, hours), and a closing call to action. Use the site's existing business details, which also hold the services, the service area and those figures. Save it as a published page.

**Edit v2.** In the same session, send:

> The business phone number is now {new_phone}. Update the site so every place that shows or dials the phone uses the new number.

### Measures

`node bench/score.mjs --briefs bench/briefs-v2.json` scores what is in `runs-v2/` and writes `results-v2/`: results.json and .csv with one row per run, plus summary.json and summary.md. The summary is by group and model, for all briefs and for each split, and then lists every run.

1. **First-try valid**, **contact data bound** and **edit survival** on the stored markup, as in version 1 below. Block Runner validates against the site's own blocks, from the `wesper-manifest.json` the runner saves in every run, so a pack block on a site with the pack is registered and one on a site without it warns.
2. **Edit survival on the rendered page.** In `after-edit.rendered.html`, the page through the cache after the edit, the old phone is gone from the text and from every `tel:` link, and the new one shows. A page whose stored markup is right but whose cached copy still shows the old number fails here.
3. **Turns and cost**, for the build and for the edit, from each transcript's `result` event: `num_turns` and `total_cost_usd`. The edit resumes the build's session, and a resumed session's `total_cost_usd` is its running total, the build's cost included, so the edit's own cost is its total less the build's when the two `session_id`s match. Cost per brief is the build plus the edit's own cost. A running total below the build's is reported as unknown, never as a guess.
4. **Claims traced.** In the main content of `page.rendered.html`, every claim word in a sentence must be traced to Site Data (`claims()` in `measures.mjs`):
   - a credential or service holds the same claim word and shares another content word with the sentence, so "free inspections" traces to "Free storm damage inspections" and "free estimate" does not, or the credential is nothing but claim words, as "Licensed and insured" is. A credential whose other words are all words for the people who do the work (`TRADE_NOUNS` in `claims.php`) traces the claim word when one of those words sits within two words of it, in the same clause, so "Licensed roofers" traces to "Licensed contractors" and "Licensed to operate, and our roofers are proud" and "We are licensed; roofers are proud" do not. Words of the business name, its industry and its town share nothing;
   - a Site Data figure in the sentence speaks to it: the year founded for "since" and "established", the rating and the review count for "rated", "rating", "review" and "stars", the licence for "licensed";
   - an age in years or decades is no more than the years since the year founded, counted from the year in `run.json`'s `started`: "over 25 years" or "two decades" in 2026 for a business founded in 1999.

   A label alone on its line, such as "Average rating", is traced when the figure beside it is. Each untraced sentence is listed per page with its untraced claim words. The claim words are the pack's own, `CLAIM_WORDS` in `inc/abilities/claims.php`, the list `insert` and `compose` check copy with: no bare digit and no `#`, which phone numbers, addresses and opening hours would trip, and `rating` and `review` only at the start of a word, so "operating" and "preview" make no claim. Both take out the same phrases first, `NOT_CLAIMS` in `claims.php`: "feel free", and "top", "best" and "leading" in an idiom, such as "top to bottom", "the best way" or "feel your best". On an agent's page the scorer reads the words with two differences: "years" and "decades" only next to a number or "since", so "built to last for years" and "Year founded" make no claim; and a word of the business's own name or industry, such as "insurance" for an insurance agency, is not a claim.
5. **Cached loads**: the runs whose `run.json` shows the second load came from the cache after the build and after the edit.
6. **Pack abilities listed**: the `voyager-open-blocks/*` abilities the MCP Adapter's discover tool listed in the run's transcripts, which is 0 in `baseline` and `plugin`.
7. **Design quality.** A judge reads `page.png`. It is not in this scorer.

`node bench/test-score.mjs` checks the scorer against known answers in `fixtures/`, for both versions, and each claim and cost rule alone. `fixtures/runs-v2-smoke/` holds the main content and result events of the first three smoke runs. `npm run check` runs it.

`node bench/run.mjs prepare` builds the three site templates, and `node bench/run.mjs run` runs the briefs, one site at a time. Its header lists the options and what each run saves. The runner needs `wp`, `php` and `claude` on PATH, Chrome, and Node 24.22.1 or a later 24.x.

## Version 1

### The two arms

| | `baseline` | `loop` |
|---|---|---|
| Site | Fresh Playground site, block theme (Twenty Twenty-Five), no pack | The same, plus this pack |
| The agent gets | The brief and the WordPress MCP Adapter | The brief, the pack's skills, a Wesper manifest of the site, and the five pack abilities |
| It writes through | Raw block markup | The abilities, or markup it checked with Block Runner |

Same model, same settings and same brief text in both arms. One fresh site per brief per arm, so nothing carries over between runs.

### Briefs

`briefs.json` holds 20 fake businesses across home services, legal, healthcare, nonprofit and professional services. The first 10 are the `derive` split and the last 10 are `held-out`. Phones use the 555-01xx range and emails use `example.com` subdomains, so no real business is named or reachable.

### The two tasks per brief

Before the build task, set the site's business details (`voyager_site_*` options, or the theme's equivalent in `baseline`) to the brief's values. Both arms get the same data in the same place.

**Build.** Send this prompt with the brief's fields filled in:

> Build the home page for {business}, a {industry} business in {address_city}, {address_state}. It needs a call to action ("{primary_cta.label}" linking to {primary_cta.url}) with a click-to-call button, a row with these credentials: {credentials}, the contact details (phone, email, address, hours), and a closing call to action. Use the site's existing business details. Save it as a published page.

Save the page's `post_content` as `runs/<arm>/<brief-id>/page.html`.

**Edit.** In the same session, send:

> The business phone number is now {new_phone}. Update the site so every place that shows or dials the phone uses the new number.

Save `post_content` again as `after-edit.html`. Save the site's business details after the edit as `site-data.json`, for example `{"phone": "...", "email": "..."}`.

### Measures

`node bench/score.mjs` scores what is in `runs/` and writes `results/` (results.json and .csv with one row per arm and brief, plus summary.json and summary.md).

1. **First-try valid.** `block-runner validate --strict` finds no invalid blocks and no warnings in `page.html`. A missing page is a failure, not a skip.
2. **Contact data bound.** For phone, email and address, does the page bind them to `voyager/site-data`, type them in as text, or leave them out? The score is bound fields divided by present fields, averaged over pages that show any contact data.
3. **Edit survival.** After the edit, the page is still valid, the old number is gone from both text and `tel:` links, and the new number shows, either typed or through a binding whose site value is now the new number.
4. **Design quality (optional, costs money).** A pairwise judge preference using the UIG harness and its known-answer controls. It is not in this scorer. Cap: $30.

## Reading the result

Publish the summary table, the raw runs and results, and what failed, whichever group wins. Report the derive and held-out splits separately; a lead that shows only on derive is not a result. Name the models, the pack commit and the Block Runner version.
