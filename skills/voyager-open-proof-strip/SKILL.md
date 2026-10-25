---
name: voyager-open-proof-strip
description: Insert or customize the voyager-open/proof-strip pattern, one ruled row of three to six checkmarked credentials, usually directly under a hero. Use when a site has verifiable trust facts (licenses, years in business, guarantees) to show at a glance.
license: GPL-2.0-or-later
---

# Proof strip (`voyager-open/proof-strip`)

## When to use

- Directly under a hero, to back the headline with facts.
- Only with real, verifiable facts. If the owner has not given you any, leave the shipped placeholders and say they need replacing. Never invent a license, an award, a rating or a number of years.

## Structure

```
core/group (section, full width, ruled top and bottom)   "Proof strip"
  core/list  className is-style-voyager-open-ticks       "Credentials"
    core/list-item x4                                    "Credential 1" to "Credential 4"
```

`is-style-voyager-open-ticks` is a `core/list` block style registered by the pack. It draws a checkmark in the text colour and lays the items out as one wrapping row. On WordPress 7.1, core also ships a "Checkmark" list style (`is-style-checkmark-list`). This pack's style also gives the single-row layout and works from 6.9.

The intent tree is `patterns/intents/proof-strip.json`. The markup is `patterns/proof-strip.html`.

## Customizing

Copy the intent and replace the list items' `text` (three to six short facts, under about 40 characters each so the row holds), then `npx block-runner assemble intent.json --json`. The shipped intent names each item, so `insert` can rewrite it with `copy`. A list you author can use the `items` shorthand instead:

```json
{ "block": "core/list", "attrs": { "className": "is-style-voyager-open-ticks" }, "items": [ "Licensed and insured", "Family owned since 1998" ] }
```

## Bindings

None by default. A fact kept in post meta can drive an item when the site allows it. Check `bindings.supportedAttributes["core/list-item"]`: WordPress 7.1 lists `content`, and older versions may not. Then bind that item:

```json
{ "block": "core/list-item", "text": "Family owned", "attrs": { "metadata": { "bindings": {
  "content": { "source": "voyager/post-meta-text", "args": { "key": "years_in_business_line" } }
} } } }
```

The shipped intent already gives the list `children` of `core/list-item` nodes, which this needs. See the `voyager-post-meta-text` skill for making the field bindable.

## The loop

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/proof-strip` is in `patterns.items` and the `voyager-open-ticks` style is in the `core/list` block's `styles`.
2. **Author.** Assemble with the owner's facts.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`.

## Abilities

With the plugin active, an agent places this pattern through the `voyager-open-blocks` abilities (WordPress Abilities API, `wp-abilities/v1`, also exposed to MCP) without writing markup:

- **insert**: `{"post_id": 123, "item": "voyager-open/proof-strip"}`. Add `"position": 0` to put it first, or `"mode": "reference"` to insert a `core/pattern` that tracks plugin updates instead of a copy. The response returns the inserted `markup`, plus each block's `path` and `label` (`metadata.name`).
- **insert with copy**: `{"post_id": 123, "item": "voyager-open/proof-strip", "copy": {"Credential 1": "Licensed and insured", "Credential 2": "Family owned since 1998", "Credential 3": "Written quotes", "Credential 4": "Same-week visits"}}` replaces the text of those list items. Labels: Credential 1 to Credential 4. copy cannot add or remove items, so a strip with fewer than four facts needs the extra items removed in the editor, or the section re-authored. Plain text only, escaped on the way in. Copy mode only. An unknown label, or a label on a block that is not text, is refused with `voyager_open_blocks_invalid_copy`, which lists the valid labels.
- **bind**: optional. Bind a list item's `content` to an allowlisted post meta field by its path and the field's args from `get-catalog`.
- Pipe the returned `markup` through `npx block-runner validate --strict --json`. WordPress cannot check saved HTML from PHP.

`get-catalog` lists this pattern's named blocks and their bindings before you insert it.

## Try it

`blueprints/proof-strip.json`.
