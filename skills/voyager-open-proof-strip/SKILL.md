---
name: voyager-open-proof-strip
description: Insert or customize the voyager-open/proof-strip pattern, one ruled row of three to six checkmarked credentials, usually directly under a hero. Use when a site has verifiable trust facts (licenses, years in business, guarantees) to show at a glance.
license: GPL-2.0-or-later
---

# Proof strip (`voyager-open/proof-strip`)

## When to use

- Directly under a hero, to back the headline with facts.
- Only with real, verifiable facts. If the owner has not given you any, do not place the strip on a live page: ask for them. On a demo or draft page the shipped placeholders can stay, and you say they need replacing. Never invent a license, an award, a rating or a number of years.

## Tell your agent

> Add the Voyager Open Blocks proof strip under the hero on my home page with these credentials: [three to six short facts]. Use only what I give you.

The shipped items read like real claims ("Licensed and insured"). On a live page, an item the owner gave no fact for must go, not stay as a placeholder.

## Structure

```
core/group (section, full width, ruled top and bottom)   "Proof strip"
  core/group (flex, wrap, centered)                      "Row"
    core/list  className is-style-voyager-open-ticks     "Credentials"
      core/list-item x4                                  "Credential 1" to "Credential 4"
```

`is-style-voyager-open-ticks` is a `core/list` block style registered by the pack. It draws a checkmark in the text colour and lays the items out as one wrapping row. On WordPress 7.1, core also ships a "Checkmark" list style (`is-style-checkmark-list`). This pack's style also gives the single-row layout and works from 6.9.

The Row group centres a short list in the strip; a long one fills the row and wraps. A flex group holding a Ticks list is also a size container (`voyager-open-ticks`), so when the Row is narrower than 32em the ticks stack as one column. That follows the strip's width, not the screen's. Keep the list inside the Row group: on its own the list keeps the wrapping row at every width.

The intent tree is `patterns/intents/proof-strip.json`. The markup is `patterns/proof-strip.html`.

## Customizing

On route 2, copy the intent and replace the list items' `text` with three to six short facts, under about 40 characters each so the row holds. Remove items you have no fact for, or add items for more. Then `npx block-runner assemble intent.json --json`. A list you author can use the `items` shorthand instead:

```json
{ "block": "core/list", "attrs": { "className": "is-style-voyager-open-ticks" }, "items": [ "Licensed and insured", "Family owned since 1998" ] }
```

On route 1, `insert` rewrites the four shipped items with `copy`, drops items with `omit` and adds a fifth or sixth with `repeat` (see Abilities).

## Bindings

None by default. A fact kept in post meta can drive an item when the site allows it. `core/list-item` takes a `content` binding on WordPress 7.1, and older versions may not. On route 2, check `bindings.supportedAttributes["core/list-item"]`. On route 1, `bind` refuses the attribute if the site does not support it. Then bind that item:

```json
{ "block": "core/list-item", "text": "Family owned", "attrs": { "metadata": { "bindings": {
  "content": { "source": "voyager/post-meta-text", "args": { "key": "years_in_business_line" } }
} } } }
```

The shipped intent already gives the list `children` of `core/list-item` nodes, which this needs. See the `voyager-post-meta-text` skill for making the field bindable.

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/proof-strip` is in `patterns.items` and the `voyager-open-ticks` style is in the `core/list` block's `styles`.
2. **Author.** Assemble with the owner's facts, one item per fact.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`. Do not save markup that fails.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

- **Which page:** for "my home page", call `find-posts` with `{"front_page": true}` and use the row's `id`. If its `note` says the home page lists the latest posts, the row is a template: that works the same way when its `id` is a number. When `id` is `null`, ask the user to save that template once in the Site Editor.
- **Where:** "under the hero" is usually `position` 1. Read `get-post-blocks` first and count the top-level blocks (paths with no `/`): the hero is normally the one at path `0`, and the `text` of the heading inside it is the page's main headline.
- **Three or four facts:** `insert` with `copy` for one item per fact, and `omit` for the items left over:

  ```json
  {"post_id": 123, "item": "voyager-open/proof-strip", "position": 1, "copy": {"Credential 1": "Licensed and insured", "Credential 2": "Family owned since 1998", "Credential 3": "Written quotes"}, "omit": ["Credential 4"]}
  ```

  Labels: `Credential 1` to `Credential 4`. Plain text only, escaped on the way in. Add `"dry_run": true` first to check without saving. An unknown label is refused with `voyager_open_blocks_invalid_copy`, `voyager_open_blocks_invalid_omit` or `voyager_open_blocks_invalid_repeat`, which list the valid labels.
- **Five or six facts:** `repeat` adds items after `Credential 4`, each with a new label, and `copy` fills them:

  ```json
  {"post_id": 123, "item": "voyager-open/proof-strip", "position": 1, "repeat": {"Credential 4": ["Credential 5", "Credential 6"]}, "copy": {"Credential 1": "Licensed and insured", "Credential 2": "Family owned since 1998", "Credential 3": "Written quotes", "Credential 4": "Same-week scheduling", "Credential 5": "Free estimates", "Credential 6": "Two-year guarantee"}}
  ```

  Give each new item a label no block has yet. Every item you add needs a fact in `copy`, or it shows the fourth item's placeholder text. More than six makes a crowded row: say so and ask which to keep.
- **No facts:** do not insert the strip. Ask the owner for them.
- **As shipped:** `"mode": "reference"` inserts a `core/pattern` that tracks plugin updates. It shows the four shipped claims, so use it only on a demo page.
- **bind:** optional. Bind a list item's `content` to an allowlisted post meta field by its path and the field's args from `get-catalog`.
- **validate** with `{"post_id": 123}` checks the saved page.

On route 2, also pipe the returned `markup` through `npx block-runner validate --strict --json --context site.context.json`.

`get-catalog` lists this pattern's named blocks and their bindings before you insert it.

## Try it

`blueprints/proof-strip.json`.
