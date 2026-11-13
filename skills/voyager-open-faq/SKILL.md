---
name: voyager-open-faq
description: Insert or customize the FAQ section, which answers the questions visitors ask before they get in touch, in either of its two layouts. voyager-open/faq puts a section heading, an optional line of intro and the questions one under another at content width. voyager-open/faq-split puts the heading beside the questions at wide width. Each question is a core/details block that opens to show its answer with no script. Use on a service or location page, with three to eight questions.
license: GPL-2.0-or-later
---

# FAQ (`voyager-open/faq`, `voyager-open/faq-split`)

The FAQ is one section in two layouts. Each layout is its own pattern:

| Layout | Pattern | What it is |
|---|---|---|
| `list` (the first) | `voyager-open/faq` | The heading, an optional line of intro, then the questions one under another, all at content width. |
| `split` | `voyager-open/faq-split` | The heading and intro in a column beside the questions, at wide width. On a phone the questions stack under the heading. |

Each intent names its section and layout in its `section` and `layout` keys. The first layout keeps the section's slug; the other is the slug, a hyphen and the layout's name.

## When to use

- On a service or location page, near the end, before the closing CTA or the contact section, for the few questions every visitor asks: where you work, what it costs to find out, how soon you can come.
- **List** on most pages. It reads like the page around it, at the same width.
- **Split** on a long page that needs a change of shape, or with five or more questions: the heading stays beside them instead of above a long column.
- Three to eight questions. Fewer than three is a paragraph, not a section. More than eight is a page of its own.
- Not for an answer longer than a short paragraph. Link to a page that answers it instead.
- Not for claims dressed as questions ("Why are we the best?"). Each question is one a visitor really asks.

## Tell your agent

> Add the Voyager Open Blocks FAQ to my [service] page, before the closing CTA. Use these questions and answers: [three to eight, each a question as a customer asks it and my answer]. Use only the facts I give you.

For the other layout, say "with the heading beside the questions".

## Structure

```
voyager-open/faq (list)
core/group (section, full width, constrained)          "FAQ"
  core/group                                           "Lead"
    core/heading h2                                    "Heading"
    core/paragraph                                     "Intro"
  core/group (no gap)                                  "Questions"
    core/details  Question style, open                 "Question 1"   (its summary is the question)
      core/paragraph                                   "Question 1 answer"
    core/details  Question style                       "Question 2"
      core/paragraph                                   "Question 2 answer"
    core/details  Question style                       "Question 3"
      core/paragraph                                   "Question 3 answer"

voyager-open/faq-split (split)
core/group (section, full width, constrained)          "FAQ"
  core/columns (wide, stacks on a phone)
    core/column 40%                                    "Lead"
      core/heading h2                                  "Heading"
      core/paragraph                                   "Intro"
    core/column                                        "Questions column"
      core/group (no gap)                              "Questions"
        core/details ... as above                      "Question 1" to "Question 3"
```

The intent trees are `patterns/intents/faq.json` and `patterns/intents/faq-split.json` (`intent` key). The markup is `patterns/faq.html` and `patterns/faq-split.html`.

Each question is a `core/details` block. Its summary, the line a visitor taps, is the question, and the paragraph inside it is the answer. The browser opens and closes it, with no script. The first question ships open, so a visitor sees how an answer reads; the others ship closed. In the editor, "Open by default" in the block's settings changes that. No ability can, since the details block saves its HTML.

Every question carries the **Question** block style (`is-style-voyager-open-question`). It makes each summary a tap target at least 44px tall on any theme, and draws a 1px rule in the text colour above each question and below the last. Keep the style on every question, a repeated one included: without it a summary is one line of body text, under 44px on the themes the pack is checked on.

Every answer carries its question's label and a space: "Question 2" holds "Question 2 answer". That is what lets `repeat` add a question with its answer, and `omit` drop one with its answer (see Abilities).

## Copy rules

Each intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text. Both layouts take the same labels and rules.

| Label | What goes there | Min | Max | Required |
|---|---|---|---|---|
| `Heading` | The section's `h2`: what the questions are about, in a few words. | 3 | 60 | yes |
| `Intro` | One line on what the questions cover. Leave it out when the heading says enough. | 20 | 120 | no |
| `Question 1` to `Question 3` | One question a visitor asks, in their own words, ending in a question mark. | 10 | 80 | no |
| `Question 1 answer` to `Question 3 answer` | One short paragraph that answers the question plainly. A fact in it comes from the site owner or from Site Data, never from you. | 20 | 300 | yes |

A question is not required because leaving it out is how you drop it: `omit` takes its answer with it. An answer is required, so `omit` refuses an answer without its question. A question added with `repeat` takes the rules of the one it copies.

No slot is a claim slot, and the shipped copy makes no claim. The questions are three every visitor asks, and each shipped answer says what goes there, such as "Say whether a quote or a first visit costs anything, and how to ask for one." Replace every answer before the page goes live.

## Writing the questions

- **As a visitor asks it.** "Do you work on weekends?", not "Weekend availability". Start with the words a customer would type into a search box or say on the phone.
- **One question each.** Split "How much is it and how soon can you come?" in two.
- **Short.** One line on a phone where you can. The summary is the whole tap target.
- **Ask the site owner which questions they hear.** The shipped three are a start, not a list to keep.

## Writing the answers

- **Answer first.** Start with yes, no or the fact, then one or two sentences of detail.
- **Facts come from the site owner or from Site Data.** A price, a time, an area, a licence or a guarantee in an answer is the owner's to give. If you do not have it, ask, or leave the shipped answer and say it needs replacing. Never write "We are licensed and insured" or "Since 1998" because it sounds right.
- **Do not type in a phone number, an address or hours.** Typed copy goes stale when Site Data changes. Say "Call us" or "See our hours below", and let the contact section or the CTA band show the value.
- **No marketing.** An FAQ answer that sells reads as a dodge. Say the plain thing.

## Customizing

- **As shipped:** `<!-- wp:pattern {"slug":"voyager-open/faq"} /-->` (or `voyager-open/faq-split`), or `insert` with `"mode": "reference"`. Its questions cannot change, so use it only for a demo. Use a copy for a real page.
- **Edited, route 1:** `insert` with `copy`, `repeat` and `omit` (see Abilities).
- **Edited, route 2:** copy the intent and change the heading, the intro and each question's `text` (the summary) and answer. A details block's `text` in an intent is its summary. To add a question, copy a whole "Question <n>" node and renumber both labels in it, and keep its `className`. Assemble with `npx block-runner assemble` and validate with the site's context.
- To drop the intro, pass `"omit": ["Intro"]`.
- To make one question open by default, set `showContent` on it in the intent, or "Open by default" in the editor. Keep at most one open.
- Do not give the questions a shared `name`: the pack's FAQ is not an exclusive accordion, so a visitor can open several at once.

## Bindings

None. The questions and answers are copy. Site Data reaches a page through the sections bound to it, such as the contact section.

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/faq` and `voyager-open/faq-split` are in `patterns.items`.
2. **Author.** Insert by reference, or an edited intent assembled as Customizing says.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`. Do not save markup that fails.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

1. **get-catalog.** Confirm both patterns are in `patterns`. Each lists `Question 1` to `Question 3` and their answers with `copy: true`, and its `copy_rules`.
2. **find-posts, then get-post-blocks.** For "my Drain cleaning page", call `find-posts` with `{"search": "Drain cleaning"}` and take `id`, say 123. Read `get-post-blocks` and count the top-level blocks (paths with no `/`) to pick `position`: before the closing CTA is usually the second to last.
3. **Three questions:** `insert` with `copy` for the heading and each question and answer:

   ```json
   {"post_id": 123, "item": "voyager-open/faq", "position": 5, "dry_run": true, "copy": {"Heading": "Drain cleaning questions", "Question 1": "Do you work on weekends?", "Question 1 answer": "Yes, on Saturdays. Call us to book a time.", "Question 2": "Do you charge for a quote?", "Question 2 answer": "No. We look at the drain and give you a price before we start.", "Question 3": "How soon can you come out?", "Question 3 answer": "Most visits are booked within a day or two. Call us when it cannot wait."}}
   ```

   Every answer above is the site owner's, given to you. Use `"item": "voyager-open/faq-split"` for the other layout. Plain text only, escaped on the way in: the question replaces the summary, the answer the paragraph. Read `validation`, then call `insert` again without `dry_run` to save.
4. **Four to eight questions:** `repeat` copies "Question 3", with its answer, after itself, one copy per new label. The answer in each copy takes its label: `Question 4` holds `Question 4 answer`, which `copy` then fills:

   ```json
   {"post_id": 123, "item": "voyager-open/faq", "position": 5, "repeat": {"Question 3": ["Question 4", "Question 5"]}, "copy": {"Heading": "Drain cleaning questions", "Question 1": "…", "Question 1 answer": "…", "Question 2": "…", "Question 2 answer": "…", "Question 3": "…", "Question 3 answer": "…", "Question 4": "Can I be there while you work?", "Question 4 answer": "…", "Question 5": "Do you clean up afterwards?", "Question 5 answer": "…"}}
   ```

   Every question you add needs its question and answer in `copy`, or it repeats the third one's. More than eight makes a long section: say so and ask which to keep. A new label must be one no block has yet, and so must the label it gives the answer, or `insert` refuses with `voyager_open_blocks_invalid_repeat`.
5. **Fewer than three:** `omit` drops a question with its answer: `"omit": ["Question 3"]`. Omitting an answer alone (`"Question 3 answer"`) is refused as `required`, and so is omitting the heading. Two questions is not much of a section; ask whether a paragraph would do.
6. **validate** with `{"post_id": 123}`. `valid` should be `true`, with no warnings.
7. **Report** the questions you placed, which answers came from the site owner, any shipped answer still in place, the layout, and that the saved HTML was not checked with Block Runner on this route.

On route 2, also pipe the returned `markup` through `npx block-runner validate --strict --json --context site.context.json`.

## Not in this section

- **FAQPage structured data.** The section writes no schema markup.
- **An exclusive accordion**, where opening one question closes the others. Leave the details blocks' `name` unset.
- **Motion.** The questions open and close as the browser draws them, with no animation.

## Try it

`blueprints/faq.json` opens a page with both layouts.
