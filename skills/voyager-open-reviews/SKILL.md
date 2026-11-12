---
name: voyager-open-reviews
description: Insert or customize the reviews section, which shows what customers said, in either of its two layouts. voyager-open/reviews puts a section heading and three cards in a row, each a star row with the rating in words, the customer's quote, their name and one detail. voyager-open/reviews-single puts one review, large, at content width. Use only with real reviews the site owner gives you, in the customer's own words and with their permission.
license: GPL-2.0-or-later
---

# Reviews (`voyager-open/reviews`, `voyager-open/reviews-single`)

The reviews section is one section in two layouts. Each layout is its own pattern:

| Layout | Pattern | What it is |
|---|---|---|
| `grid` (the first) | `voyager-open/reviews` | Cards at wide width, three across at most, each a star row, a quote, a name and one detail. The row wraps where a card would get too narrow, and on a phone the cards stack. |
| `single` | `voyager-open/reviews-single` | One review at content width, centred, with the quote at the theme's `large` size. For a page with one strong review. |

Each intent names its section and layout in its `section` and `layout` keys. The first layout keeps the section's slug; the other is the slug, a hyphen and the layout's name.

## Only real reviews

Every part of a review is a claim: the quote, the name, the detail and the rating. The pack ships none of them. It ships placeholders that say what goes there, so a page built from it never shows an invented review.

- **Use only reviews the site owner gives you**, or that they point you to and confirm, such as their own Google reviews. Never write a review, never stitch one together from several, and never guess a name or a rating.
- **The customer's own words.** Copy the quote exactly. You may cut it, at a sentence or with an ellipsis, only where the meaning stays the same. Never reword it, fix its grammar into something the customer did not say, or make it warmer.
- **With their permission.** The owner says the customer agreed to be quoted, by the name you show. A first name and last initial ("Sam P.") is the usual choice. If the owner is not sure, ask before you place it.
- **Each star row is that review's own rating**, the one the customer gave. Not the business's average, and not five stars because the quote sounds happy. A review with no rating is not for this section.
- **No reviews from the owner: do not place the section on a live page.** On a demo or a draft the placeholders can stay, and you say they need replacing.

The pack calls no review service. Reviews are typed in, or pasted, by whoever builds the page.

## Tell your agent

> Add the Voyager Open Blocks reviews section to my home page, above the closing CTA. Use these reviews, exactly as written: [each review's quote, the customer's name as they agreed to show it, the service or the month and year, and the star rating they gave]. Use only what I give you.

For one review, say "as one large quote" instead.

## Structure

```
voyager-open/reviews (grid)
core/group (section, full width, constrained)          "Reviews"
  core/group (wide, text at content width, left)       "Lead"
    core/heading h2                                    "Heading"
  core/group (wide, grid: 3 across, at least 18rem, gap 40), voyager-open-reveal  "Cards"
    core/group (1px border, padding 40)                "Review 1"
      core/group (row, wraps)                          "Review 1 rating row"
        core/group (row, no gap)                       "Review 1 stars"
          voyager-open/icon  star, 24px  x5            "Review 1 star 1" to "Review 1 star 5"
        core/paragraph  small                          "Review 1 rating"
      core/quote  Plain style                          "Review 1 quote block"
        core/paragraph                                 "Review 1 quote"
      core/paragraph                                   "Review 1 name"
      core/paragraph  small                            "Review 1 detail"
    core/group (card)                                  "Review 2"
    core/group (card)                                  "Review 3"

voyager-open/reviews-single (single)
core/group (section, full width, constrained)          "Reviews"
  core/heading h2, centred                             "Heading"
  core/group                                           "Review 1"
    core/group (row, wraps, centred)                   "Review 1 rating row"
      core/group (row, no gap)                         "Review 1 stars"
        voyager-open/icon  star, 24px  x5              "Review 1 star 1" to "Review 1 star 5"
      core/paragraph  small                            "Review 1 rating"
    core/quote  Plain style, centred, large            "Review 1 quote block"
      core/paragraph                                   "Review 1 quote"
    core/group                                         "Review 1 byline"
      core/paragraph, centred                          "Review 1 name"
      core/paragraph  small, centred                   "Review 1 detail"
```

The intent trees are `patterns/intents/reviews.json` and `patterns/intents/reviews-single.json` (`intent` key). The markup is `patterns/reviews.html` and `patterns/reviews-single.html`. The star is the icon block's default icon, so its block comment carries no `icon` attribute: `<!-- wp:voyager-open/icon {"size":24,...} /-->` is a star.

Every review has the same structure, and every block in a review carries the review's label: "Review 2" holds "Review 2 rating", "Review 2 quote", "Review 2 star 5" and the rest. That is what lets `repeat` copy a whole card and `omit` drop one star (see Abilities). A card is DESIGN.md's Card: a 1px border, `40` padding, no fill and no shadow, and no card holds a card. The borders take the text colour, so they follow the section style.

The grid's "Cards" group carries the class `voyager-open-reveal`: the row of cards fades and lifts into place as it scrolls into view, with motion on only, and shows in place otherwise (DESIGN.md, Motion). Keep the class on the group and off the cards. The single layout has no reveal.

**The stars and the rating.** The five stars are decorative: they have no label, so the icon block renders each one `aria-hidden`. The words beside them, "Rated 5 out of 5", are the row's one accessible name, and they say the rating to a sighted reader too: the pack's star is an outline, which on its own cannot tell five out of five from none. Keep the number of stars and the words in step.

**The quote.** The quote holds the customer's words and nothing else. Their name and the detail follow it as two paragraphs, outside the quotation, as HTML keeps an attribution. The quote takes the theme's Plain quote style, so a theme's quote box (a fill, a radius) never sits inside a card or keeps a colour a section style cannot repaint. The theme still sets the quote's type, and some themes add a rule or a quotation mark beside it.

## Copy rules

Each intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text. Both layouts take the same labels and rules; the grid has three reviews, the single layout one.

| Label | What goes there | Min | Max | Required | Claim |
|---|---|---|---|---|---|
| `Heading` | The section's `h2`: what the reviews are, in a few words. No count or score. | 3 | 60 | yes | no |
| `Review 1 rating` to `Review 3 rating` | This review's own rating, as the customer gave it: "Rated 4 out of 5". | 16 | 24 | yes | yes |
| `Review 1 quote` to `Review 3 quote` | The customer's own words, with their permission. Cut only where the meaning stays the same; never reword. | 20 | 300 | yes | yes |
| `Review 1 name` to `Review 3 name` | The name the customer agreed to show, such as a first name and last initial. | 2 | 40 | yes | yes |
| `Review 1 detail` to `Review 3 detail` | One detail: the service the review was for, or the month and year it was given. | 3 | 40 | no | yes |

A review added with `repeat` takes the same rules as the one it copies.

`claim: true` means the text comes from the site owner, never from you. The shipped text says what goes there: "A real customer's words go here, as they wrote them, with their permission.", "Customer name", "The service, or the month and year", and "Rated 5 out of 5" beside five stars. Replace all of it, or leave the section off a live page. In the heading, say what the section is ("What customers say", "Reviews from homeowners"), never a count or a score ("200 five-star reviews"): those are claims too, and they change.

## Customizing

- **As shipped:** `<!-- wp:pattern {"slug":"voyager-open/reviews"} /-->` (or `voyager-open/reviews-single`), or `insert` with `"mode": "reference"`. It shows the placeholders, so use it only on a demo page.
- **Edited, route 1:** `insert` with `copy`, `repeat` and `omit` (see Abilities).
- **Edited, route 2:** copy the intent and change the heading and each review's rating, quote, name and detail. To add a review, copy a whole "Review <n>" node and renumber every label in it. For a four-star review, remove the fifth star node. Assemble with `npm run patterns:build` in this repository, which knows the pack's blocks. A plain `npx block-runner assemble` refuses the icon block, so outside this repository write the icon's self-closing comment yourself and validate with the site's context.
- **How many:** one review, use the single layout. Two to six, use the grid: it shows three across at most and wraps the rest onto a second row. More than six makes a long section: ask which to keep.
- **The detail:** pass `"omit": ["Review 2 detail"]` for a review the owner gave no detail for.

## Bindings

None. Reviews are typed in. A later layout may show an overall rating from Site Data.

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/reviews` and `voyager-open/reviews-single` are in `patterns.items`, and `voyager-open/icon` is in `blocks.types`.
2. **Author.** An edited intent assembled as Customizing says, with the owner's reviews.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`. With the context, Block Runner knows the icon block is registered. Without it, each star warns `unregistered-block` and `--strict` fails. Do not save markup that fails.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

1. **get-catalog.** Confirm both patterns are in `patterns`, with their copy labels.
2. **find-posts, then get-post-blocks.** For "my home page", call `find-posts` with `{"front_page": true}` and take `id`, say 123. Read `get-post-blocks` and count the top-level blocks (paths with no `/`) to pick `position`.
3. **Three reviews:** `insert` with `copy` for every slot:

   ```json
   {"post_id": 123, "item": "voyager-open/reviews", "position": 4, "dry_run": true, "copy": {"Heading": "What customers say", "Review 1 rating": "Rated 5 out of 5", "Review 1 quote": "…", "Review 1 name": "…", "Review 1 detail": "…", "Review 2 rating": "…", "Review 2 quote": "…", "Review 2 name": "…", "Review 2 detail": "…", "Review 3 rating": "…", "Review 3 quote": "…", "Review 3 name": "…", "Review 3 detail": "…"}}
   ```

   Every `…` is the owner's text, never yours. Plain text only, escaped on the way in. Read `validation`: `registered_and_attributes` and `round_trip` should be `true`. Then call `insert` again without `dry_run` to save.
4. **A rating under five:** drop the stars above it with `omit`, and say the same number in `copy`. A four-star second review: `"omit": ["Review 2 star 5"]` with `"Review 2 rating": "Rated 4 out of 5"`. Three stars: omit `Review 2 star 4` and `Review 2 star 5`. The pack has no half star: for 4.5, show four stars and say "Rated 4.5 out of 5".
5. **Four to six reviews:** `repeat` copies "Review 3", with everything in it, after itself, one copy per new label. The blocks in each copy take its label (`Review 4 quote`, `Review 4 star 5`), which `copy` and `omit` then use:

   ```json
   {"post_id": 123, "item": "voyager-open/reviews", "position": 4, "repeat": {"Review 3": ["Review 4"]}, "omit": ["Review 2 star 5"], "copy": {"Heading": "What customers say", "Review 1 rating": "…", "Review 1 quote": "…", "Review 1 name": "…", "Review 1 detail": "…", "Review 2 rating": "Rated 4 out of 5", "Review 2 quote": "…", "Review 2 name": "…", "Review 2 detail": "…", "Review 3 rating": "…", "Review 3 quote": "…", "Review 3 name": "…", "Review 3 detail": "…", "Review 4 rating": "…", "Review 4 quote": "…", "Review 4 name": "…", "Review 4 detail": "…"}}
   ```

   Every review you add needs its rating, quote, name and detail in `copy`, or it shows the placeholders. A new label must be one no block has yet, and so must the labels it gives the blocks inside it, or `insert` refuses with `voyager_open_blocks_invalid_repeat` and lists the labels it can repeat.
6. **Two reviews:** `"omit": ["Review 3"]` drops a card with everything in it. **One review:** use `voyager-open/reviews-single`, with `copy` for `Heading` and `Review 1 rating`, `quote`, `name` and `detail`.
7. **validate** with `{"post_id": 123}`. `valid` should be `true`, with no warnings.
8. **Report** the reviews you placed, who gave each one and the rating, the layout, and that the saved HTML was not checked with Block Runner on this route.

On route 2, also pipe the returned `markup` through `npx block-runner validate --strict --json --context site.context.json`.

## Try it

`blueprints/reviews.json` opens a page with both layouts.
