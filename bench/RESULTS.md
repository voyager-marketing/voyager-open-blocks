# With/without benchmark: results

Tracking issue: VOY-1774. Protocol: [README.md](README.md). Raw runs: [runs/](runs/). Scores: [results/](results/).

## Summary

The same model built the same 20 home pages twice: once on a plain WordPress site (`baseline`), once with this pack installed and its skills loaded (`loop`). Then it changed the phone number on each.

| Arm | Split | Pages | First-try valid | Contact data bound | Edit survived | core/html blocks |
|---|---|---:|---:|---:|---:|---:|
| baseline | all | 20 | 17/20 | 0% (20 pages) | 17/20 | 0 |
| baseline | derive | 10 | 8/10 | 0% (10 pages) | 8/10 | 0 |
| baseline | held-out | 10 | 9/10 | 0% (10 pages) | 9/10 | 0 |
| loop | all | 20 | 20/20 | 100% (20 pages) | 19/20 | 0 |
| loop | derive | 10 | 10/10 | 100% (10 pages) | 10/10 | 0 |
| loop | held-out | 10 | 10/10 | 100% (10 pages) | 9/10 | 0 |

What it says, plainly:

- **Binding is the real difference.** Every loop page bound phone, email and address to the site's business details. Every baseline page typed all three in. That held on both splits, 20 of 20 against 0 of 20.
- **Validity and edit survival lean to the loop, but the sample is small.** The loop built 3 more valid pages and kept 2 more pages correct after the edit, out of 20. At this size that could be chance: Fisher's exact test (two-sided) gives p = 0.23 for validity and p = 0.61 for edit survival. Read them as a lead, not a result. The binding gap (p < 0.0001) is a result.
- **The lead holds on held-out briefs.** Derive and held-out tell the same story. The loop's one loss is on held-out.
- **Neither arm used `core/html`.** The baseline model wrote real core blocks every time.
- **The loop costs more to build and less to edit.** See "Time and cost".

## What was measured, on what

| | |
|---|---|
| Model | `claude-sonnet-5`, every turn of all 40 sessions (checked in each transcript) |
| Agent | Claude Code 2.1.280, headless (`claude -p`), build and edit in one session |
| Pack | voyager-open-blocks 0.4.3, commit 2e0898f. The runs record 3319419, the bench commit the sites were built from; its pack files are identical to 2e0898f. |
| Block Runner | 1.0.0 (scoring, and the loop agent's own checks) |
| Wesper | 0.5.0 (the loop arm's site manifest) |
| Site | WordPress 7.1.4 on SQLite, Twenty Twenty-Five 1.5, WordPress MCP Adapter 0.7.1, served by Playground CLI 3.1.60 |
| Runs | 40: 20 briefs times 2 arms, one fresh site each, one at a time |

Both arms got the same six site abilities through MCP (list, get, create and update a page; get and update the business details), from `harness/site-tools.php`. Core and the MCP Adapter register none that create a page, so without them neither arm could build anything. They take raw block markup and check nothing. The loop arm also got the pack's skills, its five abilities, a Wesper manifest of its own site, and Block Runner through `npx`.

The prompts are the README's, filled per brief. For example (willow-vet):

> Build the home page for Willow Creek Veterinary, a healthcare business in Cedar Point, NC. It needs a call to action ("Book an appointment" linking to /contact/) with a click-to-call button, a row with these credentials: AAHA-accredited hospital, Fear Free certified staff, Same-day sick visits, In-house lab, the contact details (phone, email, address, hours), and a closing call to action. Use the site's existing business details. Save it as a published page.

> The business phone number is now (555) 555-0163. Update the site so every place that shows or dials the phone uses the new number.

## What failed

### baseline: 3 invalid pages, and the edit kept every error

Each failure is the model hand-writing a block's saved HTML so it no longer matches the block's attributes. WordPress shows that block as "invalid content" in the editor and offers to recover it.

- **second-harvest-pantry** (derive). One button. Its comment says white text; its HTML says white text and a white border: `Expected attribute style of value color:#ffffff, saw color:#ffffff;border-color:#ffffff`.
- **tally-accounting** (derive). The cover block. The overlay span carries `has-background-dim-50` that the attributes do not produce.
- **everett-injury-law** (held-out). Four paragraphs. The attributes ask for `font-style:normal;font-weight:600`; the HTML has `font-weight:600` and an alignment class the attributes do not set.

All three edits changed the phone correctly and left the broken blocks as they were, so each scores "invalid after edit". Every baseline page that started valid stayed valid and correct after the edit: 17 of 17. The baseline edit works by rewriting the page: in all 20 runs it updated the business details and then saved the whole page again with the new number typed in.

### loop: 1 edit that left the old number in the stored page

- **willow-vet** (held-out). The page is valid and binds all three contact fields. When it placed the CTA band, the agent passed the phone number as copy for the "Click to call" button, whose text is already bound to the site's phone. So the saved markup holds `(555) 555-0113` as that button's fallback text. The edit changed the site's phone, and the page renders `(555) 555-0163` with `tel:5555550163` everywhere: a render of `after-edit.html` against the site's data after the edit shows the old number 0 times. But the stored page still contains it, and the scorer reads stored markup, so this is a loss. It matters beyond the score: if the binding source ever stops resolving, WordPress shows the fallback, and that is the old number. The `insert` ability accepted copy for a bound attribute without a word.

Every loop edit was the same two calls: update the business details, then read the page's blocks back to check the bindings. No loop edit touched the page itself.

## Time and cost

| Arm | Build, mean (median) | Edit, mean | Turns, build / edit | Per brief |
|---|---:|---:|---:|---:|
| baseline | 48 s (43 s) | 32 s | 6.8 / 4.2 | $0.29 |
| loop | 61 s (54 s) | 11 s | 17.6 / 3.0 | $0.55 |

Costs are Claude Code's list-price figures from each transcript. The runs used a claude.ai login, so no API money was spent. The loop build reads skills and places four patterns one call at a time, which is where its extra turns go. Every loop build loaded the pack skill and used `insert`. One (brightpath-hvac) also ran Block Runner on a draft; two others only used the shell to look for pattern files.

## Limits

- **20 briefs per arm, one run each.** No brief was repeated, so run-to-run variance is not measured. The validity and edit gaps are within what chance could produce at this size.
- **One model.** Results for another model, or a weaker one, may differ.
- **The briefs suit the pack.** All 20 ask for exactly four credentials, which is the proof strip's four named items, so the loop arm fills that row with copy. A brief with three or five credentials would test it harder.
- **The scorer reads stored markup, not the rendered page.** That is deliberate (a stale fallback is a real risk), and it is why willow-vet scores as a loss although visitors see the right number.
- **Measure 4 (design quality, a paid judge) was not run.** This says nothing about how the pages look.

## How the run was kept fair

The first full pass was thrown away. The cached WordPress download was 7.1.3, 7.1.4 had shipped, and cron ran WordPress's background updater on every fresh site while the agent worked. During the update every request answered "Briefly unavailable for scheduled maintenance" (503). All 7 loop builds in that pass met it mid-session, 13 times in all (`grep -c "scheduled maintenance"` per transcript: 2, 2, 1, 2, 2, 2, 2). None of the 7 baseline builds did, since they finish sooner, so the noise fell on the loop arm. The sites are now frozen (`AUTOMATIC_UPDATER_DISABLED`, `DISABLE_WP_CRON`, `WP_HTTP_BLOCK_EXTERNAL`), and a run whose WordPress version changes is a harness failure. All 40 runs here kept 7.1.4 from start to finish, and none of their files contain the maintenance message. The discarded runs are in the git history (removed in 3319419).

The runner also refuses to score a step whose session started without the site's MCP server, gets three MCP answers in a row from the site before each step, and runs one site at a time: four Playground sites booting at once crashed the machine.

## Reproduce

```bash
# Node 24.22.1 or later 24.x (Block Runner fails on 25), plus wp, php and claude on PATH
node bench/run.mjs prepare --work ../vob-bench
node bench/run.mjs run --use-login --work ../vob-bench    # skips any run already complete
node bench/test-score.mjs                                 # 4/4
node bench/score.mjs
```
