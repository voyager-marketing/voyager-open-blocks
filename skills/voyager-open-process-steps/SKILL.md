---
name: voyager-open-process-steps
description: Insert or customize the process steps section, which tells a visitor what happens once they get in touch, in order, in either of its two layouts. voyager-open/process-steps puts a section heading, an optional line of intro and three numbered steps side by side at wide width, each a heading that starts with its number and one short line. voyager-open/process-steps-list puts the same steps one under another at content width. Use on a services or service page, before the call to act, with three to five steps.
license: GPL-2.0-or-later
---

# Process steps (`voyager-open/process-steps`, `voyager-open/process-steps-list`)

The process steps section is one section in two layouts. Each layout is its own pattern:

| Layout | Pattern | What it is |
|---|---|---|
| `row` (the first) | `voyager-open/process-steps` | The steps side by side in a `core/columns` at wide width, one column per step. On a phone the columns stack, in order. |
| `list` | `voyager-open/process-steps-list` | The same steps one under another at content width. |

Each intent names its section and layout in its `section` and `layout` keys. The first layout keeps the section's slug; the other is the slug, a hyphen and the layout's name.

## When to use

- When a visitor is ready to call and wants to know what happens next: how they book, what the visit or the quote is, when the work starts. On a services or service page, after the services and before the CTA band or the closing CTA.
- **Three to five steps.** Fewer than three is a sentence, not a section: put it in the CTA band's line. More than five is a manual: keep the steps a customer goes through, not the business's own.
- **Row** for three or four steps. Four columns still fit a short heading at desktop width; five squeeze it.
- **List** for five steps, or when a step's line runs long.
- Not for services (use the services section), dates, prices or a timetable. A step says what happens, never how fast, how much or how well.

## Tell your agent

> Add the Voyager Open Blocks process steps to my Services page, before the call to action. Use these steps, in this order: [three to five steps, each what happens and one line on it]. Number them in order, and make no promise I did not give you.

For the other layout, say "as a list".

## Numbering

**A step's number is the start of its heading's text: "1. Get in touch".** The number, a full stop and a space, then what happens. It is in the `h3` itself, so a screen reader announces it with the heading, a sighted reader sees it, and a reader who jumps from heading to heading hears the order. Both layouts number the steps this way (DESIGN.md, Step).

- The number is not an eyebrow. DESIGN.md allows one eyebrow per section, and a step is not a section.
- The steps are not an ordered list. A `core/list-item` holds text and a nested list only, never an `h3` and a paragraph, so the order lives in the headings.
- **Renumber after `repeat` or `omit`, through `copy`.** The labels keep the numbers they were given, but the headings must count up from 1, in order, with no gap. Drop `Step 2` and the step labelled `Step 3` is now second: its heading is `"Step 3 heading": "2. ..."`. Add `Step 4` with `repeat` and give it `"Step 4 heading": "4. ..."`. Pass every step's heading in `copy` whenever the count changes.

## Structure

```
voyager-open/process-steps (row)
core/group (section, full width, constrained)          "Process steps"
  core/group (wide, text at content width, left)       "Lead"
    core/heading h2                                    "Heading"
    core/paragraph                                     "Intro"
  core/columns (wide, gap 60, 40 when stacked)         "Steps"
    core/column                                        "Step 1"
      core/heading h3  "1. ..."                        "Step 1 heading"
      core/paragraph                                   "Step 1 text"
    core/column                                        "Step 2"
    core/column                                        "Step 3"

voyager-open/process-steps-list (list)
core/group (section, full width, constrained)          "Process steps"
  core/group                                           "Lead"
    core/heading h2                                    "Heading"
    core/paragraph                                     "Intro"
  core/group (gap 50)                                  "Steps"
    core/group                                         "Step 1"
      core/heading h3  "1. ..."                        "Step 1 heading"
      core/paragraph                                   "Step 1 text"
    core/group                                         "Step 2"
    core/group                                         "Step 3"
```

The intent trees are `patterns/intents/process-steps.json` and `patterns/intents/process-steps-list.json` (`intent` key). The markup is `patterns/process-steps.html` and `patterns/process-steps-list.html`.

Every step has the same structure, an `h3` and one short paragraph, in both layouts, and every block in a step carries the step's label: "Step 2" holds "Step 2 heading" and "Step 2 text". That is what lets `repeat` copy a whole step and `omit` drop one (see Abilities). In the row, a step is a column, so a repeated step is a new column. A step is not a card: no border, no fill, no icon and no button, so the section stays quiet. The section has no motion.

## Copy rules

Each intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text. Both layouts take the same labels and rules.

| Label | What goes there | Min | Max | Required |
|---|---|---|---|---|
| `Heading` | The section's `h2`: what the steps are, in a few words, such as "How it works". | 3 | 60 | yes |
| `Intro` | One line on where the steps lead, such as from the first call to the finished job. Leave it out when the heading says enough. | 20 | 120 | no |
| `Step 1 heading` to `Step 3 heading` | The step's `h3`: its number, a full stop and a space, then what happens, in a few words. | 4 | 40 | yes |
| `Step 1 text` to `Step 3 text` | One plain line on what happens in this step, or what the customer does. | 20 | 120 | yes |

A step added with `repeat` takes the same rules as the one it copies: `Step 4 heading` is a numbered step heading, `Step 4 text` one plain line. A step's heading and text are required, so `omit` takes a whole step (`"Step 2"`), never its heading or its text alone.

No slot is a claim slot, and the shipped copy makes no claim or promise: "1. Get in touch", "2. We visit and quote" and "3. We do the work", each with one plain line. A step promises nothing the site owner did not: no "free", "same-day", "within 24 hours", "guaranteed" or price, and no time unless the owner gave it to you. Keep each line short: in a column it wraps at about 30 to 40 characters, and two or three lines is plenty.

## Customizing

- **As shipped:** `<!-- wp:pattern {"slug":"voyager-open/process-steps"} /-->` (or `voyager-open/process-steps-list`), or `insert` with `"mode": "reference"`. It renders the current pattern and picks up plugin updates, but its copy cannot change. Use a copy for a real page.
- **Edited, route 1:** `insert` with `copy`, `repeat` and `omit` (see Abilities).
- **Edited, route 2:** copy the intent, change the heading, the intro and each step's heading and line. To add a step, copy a whole "Step <n>" node, renumber every label in it, and number its heading. Assemble with `npm run patterns:build` in this repository, or `npx block-runner assemble` outside it: the section holds core blocks only.
- To drop the intro, pass `"omit": ["Intro"]`.

## Bindings

None. The steps are the pack's copy until you replace them.

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/process-steps` and `voyager-open/process-steps-list` are in `patterns.items`.
2. **Author.** Insert by reference, or an edited intent assembled as Customizing says.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`. Do not save markup that fails.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

1. **get-catalog.** Confirm both patterns are in `patterns`, with their copy labels and `copy_rules`.
2. **find-posts, then get-post-blocks.** For "my Services page", call `find-posts` with `{"search": "Services"}` and take `id` from the row whose `title` is the page's name, say 123. Read `get-post-blocks` and count the top-level blocks (paths with no `/`) to pick `position`: before the CTA band or the closing CTA.
3. **Three steps:** `insert` with `copy` for each heading and line:

   ```json
   {"post_id": 123, "item": "voyager-open/process-steps", "position": 2, "dry_run": true, "copy": {"Heading": "How a job goes", "Step 1 heading": "1. Book a visit", "Step 1 text": "Call or write, and we find a time that suits you.", "Step 2 heading": "2. We look at the job", "Step 2 text": "We see the work for ourselves and answer your questions.", "Step 3 heading": "3. We do the work", "Step 3 text": "On the day we agreed, and we tidy up after ourselves."}}
   ```

   Use `"item": "voyager-open/process-steps-list"` for the list. Plain text only, escaped on the way in. `insert` refuses text longer than its rule's `max` (reason `too_long`) and saves nothing; `data.refused` lists every slot to fix. Read `validation`: `registered_and_attributes` and `round_trip` should be `true`. Then call `insert` again without `dry_run` to save.
4. **Four or five steps:** `repeat` copies "Step 3", with everything in it, after itself, one copy per new label. The blocks in each copy take its label: `Step 4` holds `Step 4 heading` and `Step 4 text`, which `copy` then fills and numbers:

   ```json
   {"post_id": 123, "item": "voyager-open/process-steps", "position": 2, "repeat": {"Step 3": ["Step 4"]}, "copy": {"Heading": "How a job goes", "Step 1 heading": "1. Book a visit", "Step 1 text": "…", "Step 2 heading": "2. We look at the job", "Step 2 text": "…", "Step 3 heading": "3. You get a quote", "Step 3 text": "…", "Step 4 heading": "4. We do the work", "Step 4 text": "…"}}
   ```

   Every step you add needs its heading and line in `copy`, or it repeats the third step's text and number. For five, use the list: `{"repeat": {"Step 3": ["Step 4", "Step 5"]}}`. A new label must be one no block has yet, and so must the labels it gives the blocks inside it, or `insert` refuses with `voyager_open_blocks_invalid_repeat` and lists the labels it can repeat.
5. **Fewer steps, or one in the middle dropped:** `omit` drops a step with everything in it, and `copy` renumbers the ones left. Without the second step:

   ```json
   {"post_id": 123, "item": "voyager-open/process-steps", "position": 2, "omit": ["Step 2"], "copy": {"Step 1 heading": "1. Book a visit", "Step 1 text": "…", "Step 3 heading": "2. We do the work", "Step 3 text": "…"}}
   ```

   Two steps is not a process: ask whether a line in the CTA band would do.
6. **validate** with `{"post_id": 123}`. `valid` should be `true`, with no warnings.
7. **Report** the steps you placed, in order, the layout, and that the saved HTML was not checked with Block Runner on this route.

On route 2, also pipe the returned `markup` through `npx block-runner validate --strict --json --context site.context.json`.

## Try it

`blueprints/process-steps.json` opens a page with both layouts.
