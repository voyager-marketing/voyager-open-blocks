---
name: voyager-open-stats
description: Insert or customize the stats section, which shows three figures from Site Data, the year founded, the average rating and the review count, in either of its two layouts. voyager-open/stats puts the three in a row at wide width; voyager-open/stats-band puts them beside a section heading and one line. Every figure is bound, so use it only where the site owner has filled in the figures under Settings > Site Data, and never type a figure in.
license: GPL-2.0-or-later
---

# Stats (`voyager-open/stats`, `voyager-open/stats-band`)

The stats section is one section in two layouts. Each layout is its own pattern:

| Layout | Pattern | What it is |
|---|---|---|
| `row` (the first) | `voyager-open/stats` | Three figures in a row at wide width, centred, each its value with a short label under it. On a phone the figures stack. |
| `band` | `voyager-open/stats-band` | A section heading (`h2`) and one line beside the same three figures, which sit in a row that wraps. On a phone the text and the figures stack. |

Each intent names its section and layout in its `section` and `layout` keys. The first layout keeps the section's slug; the other is the slug, a hyphen and the layout's name.

## Every figure comes from Site Data

A figure is a claim about a real business. The pack ships none. Each value is bound to the business profile in Site Data, so the page shows only what the site owner typed in, and changes when they change it.

| Figure | Key | Shows | Filled in at | Option over REST |
|---|---|---|---|---|
| `Founded` | `founded` | The year the business started, only when it is four digits, such as `1998` | Settings > Site Data > Business profile > Year founded | `voyager_site_founded` |
| `Rating` | `rating` | The average rating from 0 to 5 with one decimal, such as `4.8` | Settings > Site Data > Business profile > Rating | `voyager_site_rating` |
| `Review count` | `review_count` | How many reviews that rating comes from, as digits, such as `127` | Settings > Site Data > Business profile > Review count | `voyager_site_review_count` |

- **Never type a figure in.** Not as copy, not as a block's own text, not "about right". A year, a rating or a count typed into the page goes stale the day the real one changes, and one you guessed was never true. `insert` refuses copy for a value (reason `bound`).
- **The site owner types them.** The rating and the review count come from their own review profile, by hand: the pack calls no review service, so never fetch, estimate or round them. If the owner gives you the figures, ask them to enter them under Settings > Site Data. An administrator's REST client can set the options at `POST /wp/v2/settings`, and should read them back, since a value that does not resolve is refused there and the option keeps its previous value.
- **The values are tidied.** A year that is not four digits, a rating that is not a number from 0 to 5, or a count that is not digits resolves to nothing. `4.80` shows as `4.8`, and `0127` as `127`.
- **One figure, one field.** A figure Site Data does not hold, such as jobs done, has no place in this section yet.

## Leave out a figure whose field is empty

While a field is empty, its value shows the block's own text, which names the field and holds no number: "Founded", "Score" or "Count". That keeps a page from ever printing a figure nobody gave, and it also reads as unfinished. So a figure whose field is empty does not go on a live page.

1. **Find which are empty.** Over REST, an administrator reads `GET /wp/v2/settings` and checks the three options. With the abilities alone, insert into a draft and read `get-post-blocks`: an empty field's value reads `resolution.status: "fell_back"` with `reason: "empty_value"`.
2. **Leave it out with `omit`.** Name the whole figure, `"omit": ["Rating"]`, which takes its value and its label with it. Omitting a value alone is refused (reason `required`): a label with no figure says nothing.
3. **All three empty: do not place the section.** Ask the owner to fill in the figures first.

`get-catalog`'s `used_on` counts the pages that show each figure, under `voyager/site-data:founded`, `voyager/site-data:rating` and `voyager/site-data:review_count`. Check it before you change a value: every page it counts changes too, and a change to a field refreshes them.

## Tell your agent

> Add the Voyager Open Blocks stats section to my home page, above the closing CTA. Use only the year founded, rating and review count I have under Settings > Site Data, and leave out any that are empty. Tell me which ones are.

For the figures beside a heading, say "with a heading" instead.

## Structure

```
voyager-open/stats (row)
core/group (section, full width, constrained)          "Stats"
  core/columns (wide, gap 40 / 60)                     "Figures"
    core/column (gap 20)                               "Founded"
      core/paragraph  x-large, centred, bound          "Founded value"
      core/paragraph  centred                          "Founded label"
    core/column (gap 20)                               "Rating"
      core/paragraph  x-large, centred, bound          "Rating value"
      core/paragraph  centred                          "Rating label"
    core/column (gap 20)                               "Review count"
      core/paragraph  x-large, centred, bound          "Review count value"
      core/paragraph  centred                          "Review count label"

voyager-open/stats-band (band)
core/group (section, full width, constrained)          "Stats"
  core/columns (wide, centred, gap 50 / 60)            "Columns"
    core/column 40% (gap 40)                           "Text"
      core/heading h2                                  "Heading"
      core/paragraph                                   "Body"
    core/column 60%                                    "Figures column"
      core/group (row, wraps, gap 40)                  "Figures"
        core/group (gap 20)                            "Founded"
          core/paragraph  x-large, bound               "Founded value"
          core/paragraph                               "Founded label"
        core/group (gap 20)                            "Rating"
        core/group (gap 20)                            "Review count"
```

The intent trees are `patterns/intents/stats.json` and `patterns/intents/stats-band.json` (`intent` key). The markup is `patterns/stats.html` and `patterns/stats-band.html`.

Every figure has the same structure in both layouts, and its blocks carry its label: "Rating" holds "Rating value" and "Rating label". That is what lets `omit` drop a whole figure. The value takes DESIGN.md's `figure` type role, `x-large`, and the label is body text. A figure is not a heading, so a screen reader reads it as two lines of text, the value and then what it is. Figures do not count up or move.

## Copy rules

Each intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text. Both layouts take the same figure labels and rules; the band adds its heading and line.

| Label | What goes there | Min | Max | Required | Claim |
|---|---|---|---|---|---|
| `Heading` (band) | The section's `h2`: what the figures are about, in a few words. No figure in it. | 3 | 60 | yes | no |
| `Body` (band) | One line on what the figures show. No figure or claim in it: the figures say it. Leave it out when the heading says enough. | 20 | 120 | no | no |
| `Founded value`, `Rating value`, `Review count value` | Bound to the Site Data year founded, rating or review count. Its own text names the field and shows only while the field is empty. Never typed. | 1 | 12 | yes | yes |
| `Founded label`, `Rating label`, `Review count label` | What the figure is, in a few words, such as "Year founded", "Average rating" or "Reviews". It names the figure and adds no claim of its own. | 3 | 24 | yes | no |

The values are claim slots and bound slots both: their text comes from the site owner, through Site Data, so `insert` refuses copy for them as bound, even in the object form. The labels, the heading and the line are not claims, and take plain copy. Keep a claim out of them too: "Rated best in town" is not a label, and neither is "Since 1998", which types the year in. Name a review site in a label ("Google rating") only when the site owner says that is where the rating comes from.

The text each layout ships: the values "Founded", "Score" and "Count", the labels "Year founded", "Average rating" and "Reviews", and in the band the heading "At a glance" and the line "One line on what these figures say about the business."

## Customizing

- **As shipped:** `<!-- wp:pattern {"slug":"voyager-open/stats"} /-->` (or `voyager-open/stats-band`), or `insert` with `"mode": "reference"`. It shows every figure the site has, and the fallback text for any field that is empty, so check the three fields first.
- **Edited, route 1:** `insert` with `omit` for a figure whose field is empty, and `copy` for the labels and, in the band, the heading and line (see Abilities).
- **Edited, route 2:** copy the intent, delete the figure nodes whose fields are empty and change the labels or the band's text. Never replace a value's binding with typed text. Assemble with `npm run patterns:build` in this repository, or write the markup and validate it with the site's context.
- **A different order** is a different section. Keep the three in the order shipped.

## Bindings

| Block | Attribute | Source | Args |
|---|---|---|---|
| Founded value | `content` | `voyager/site-data` | `{ "key": "founded" }` |
| Rating value | `content` | `voyager/site-data` | `{ "key": "rating" }` |
| Review count value | `content` | `voyager/site-data` | `{ "key": "review_count" }` |

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/stats` and `voyager-open/stats-band` are in `patterns.items`, and `voyager/site-data` is in `bindings.sources`. Wesper cannot list the source's keys: take them from the Bindings table, or from `get-catalog`.
2. **Author.** An edited intent assembled as Customizing says, with every value still bound.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`. Do not save markup that fails.
4. **Verify.** After saving, `get-post-blocks` shows each value's `resolution`. Every figure on the page should read `resolved`.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

1. **get-catalog.** Confirm both patterns are in `patterns`, with their copy labels and each value's binding.
2. **find-posts, then get-post-blocks.** For "my home page", call `find-posts` with `{"front_page": true}` and take `id`, say 123. Read `get-post-blocks` and count the top-level blocks (paths with no `/`) to pick `position`.
3. **The row, as shipped:** `{"post_id": 123, "item": "voyager-open/stats", "position": 5, "dry_run": true}`. Read `validation`, then call it again without `dry_run` to save, and keep the `revision_id`.
4. **Check the figures.** Call `get-post-blocks`. Each value's binding should read `resolved`, with the figure in `value_preview`. One that reads `fell_back` with `reason: "empty_value"` has an empty field: `undo` the save with its `revision_id`, and insert again with that figure left out, `"omit": ["Review count"]`. Tell the user which field to fill in, from the table above.
5. **The band:** copy for its heading and line, and for a label when the owner names the rating's source:

   ```json
   {"post_id": 123, "item": "voyager-open/stats-band", "position": 5, "dry_run": true, "omit": ["Review count"], "copy": {"Heading": "About us at a glance", "Body": "A few figures about the business, from its own records.", "Rating label": "Google rating"}}
   ```

   Never pass copy for a value, such as `"Rating value"`: `insert` refuses the whole call with `voyager_open_blocks_bound_slot` and saves nothing. Change the figure in Settings > Site Data instead.
6. **validate** with `{"post_id": 123}`. `valid` should be `true`.
7. **Report** the figures the page shows and which, if any, you left out because their field was empty, and that the saved HTML was not checked with Block Runner on this route.

On route 2, also pipe the returned `markup` through `npx block-runner validate --strict --json --context site.context.json`.

## Try it

`blueprints/stats.json` opens a page with both layouts. Fill in the three figures under Settings > Site Data to see them.
