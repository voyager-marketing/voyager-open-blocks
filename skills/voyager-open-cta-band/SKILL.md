---
name: voyager-open-cta-band
description: Insert or customize the voyager-open/cta-band pattern, a full-width band with an eyebrow, heading and one line of copy beside a primary button and a click-to-call button bound to voyager/site-data. Use mid-page or after a services section when the reader should be asked to act.
license: GPL-2.0-or-later
---

# CTA band (`voyager-open/cta-band`)

## When to use

- Mid-page, after the reader has seen what is offered.
- Not as the last section of a page. That is `voyager-open/closing-cta`.

## Tell your agent

> Add the Voyager Open Blocks CTA band near the end of my Services page. Write the heading and the line of copy for that page, keep the click-to-call button bound to my Site Data phone number, and check the blocks before you save.

## Structure

```
core/group (section, full width, constrained)          "CTA band"
  core/group (wide, flex, wrap, space-between, centered) "Row"
    core/group                                         "Text"
      core/paragraph  small, uppercase                 "Eyebrow"
      core/heading h2                                  "Heading"
      core/paragraph                                   "Body"
    core/buttons
      core/button  "Contact us" -> /contact/           "Primary button"
      core/button  outline                             "Click to call"
          text <- voyager/site-data { key: phone }
          url  <- voyager/site-data { key: phone_link }
```

When the text and the buttons fit on one row, the buttons sit at the right. When they do not, the row wraps and the buttons start at the left edge under the text. That follows the content, not a breakpoint, so it holds on any theme. Do not give the Buttons a `justifyContent`: it applies at every width, so wrapped buttons would stay pushed right.

The intent tree is `patterns/intents/cta-band.json` (`intent` key). The markup is `patterns/cta-band.html`.

## Customizing

- **As shipped:** `<!-- wp:pattern {"slug":"voyager-open/cta-band"} /-->`, or `insert` with `"mode": "reference"`. It renders the current pattern and picks up plugin updates, but its copy cannot change.
- **Edited, route 1:** `insert` with `copy` (see Abilities). Keep the call button's bindings. Its authored "Call us" and `/contact/` are what visitors see until the phone number is set.
- **Edited, route 2:** copy the intent, change the eyebrow, heading, line and the primary button's `text` and `url`, then `npx block-runner assemble intent.json --json`.
- Point the primary button at a page that exists. On route 2, check the manifest or the site before using `/contact/`. On route 1, the button keeps `/contact/`: no ability changes a static button's link. If the site has no `/contact/` page, tell the user to change the link in the editor.

## Bindings

| Block | Attribute | Source | Args |
|---|---|---|---|
| Click to call button | `text` | `voyager/site-data` | `{ "key": "phone" }` |
| Click to call button | `url` | `voyager/site-data` | `{ "key": "phone_link" }` |

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/cta-band` is in `patterns.items` and `voyager/site-data` is in `bindings.sources`.
2. **Author.** Insert by reference, or assemble an edited intent.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`. Do not save markup that fails.
4. **Bind.** Already bound. If Settings > Site Data has no phone, the button keeps its placeholder. Say so instead of inventing a number.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write. Run them in this order.

1. **get-catalog.** Confirm `voyager-open/cta-band` is in `patterns`, and that the `voyager/site-data` source lists a field with args `{"key": "phone"}` and one with `{"key": "phone_link"}`.
2. **get-post-blocks** on the page, `{"post_id": 123}`. Count the top-level blocks (paths with no `/`). To place the band before the last section, pass that count minus one as `position`. On an empty page, leave `position` out.
3. **insert with dry_run.** Write the eyebrow, heading and line for the page:

   ```json
   {"post_id": 123, "item": "voyager-open/cta-band", "position": 4, "dry_run": true, "copy": {"Eyebrow": "Free estimates", "Heading": "Need a plumber today?", "Body": "Call before noon for a same-day visit.", "Primary button": "Book a visit"}}
   ```

   Labels: `Eyebrow`, `Heading`, `Body`, `Primary button`, `Click to call`. Plain text only, escaped on the way in. Read `validation`: `registered_and_attributes` and `round_trip` should be `true`. Then call `insert` again without `dry_run` to save. An unknown label, or a label on a block that is not text, is refused with `voyager_open_blocks_invalid_copy`, which lists the valid labels. To drop the eyebrow, pass `"omit": ["Eyebrow"]` instead of copy for it.
4. **Keep the call button bound.** It arrives bound: `text` to `{"key": "phone"}` and `url` to `{"key": "phone_link"}`. Text you give `Click to call` in `copy` is only the fallback shown until the phone number is set. Do not `bind` it again unless you want a different field. To re-point it, take its path from the insert response (label "Click to call") and pass args copied from `get-catalog`:

   ```json
   {"post_id": 123, "path": "4/0/1/1", "attribute": "url", "source": "voyager/site-data", "args": {"key": "phone_link"}}
   ```

5. **get-post-blocks** again. Find the block labelled "Click to call". Both of its bindings should read `resolution.status: "resolved"`, with the number in `value_preview`. `fell_back` with `reason: "empty_value"` means Settings > Site Data > Phone number is empty: tell the user to fill it in, and do not type a number into the copy instead.
6. **validate** with `{"post_id": 123}` checks the whole saved page. `valid` should be `true`.
7. **Report** the heading and line you wrote, the phone binding's status, the primary button's link (`/contact/`), and that the saved HTML was not checked with Block Runner on this route.

On route 2, also pipe the returned `markup` through `npx block-runner validate --strict --json --context site.context.json`.

`get-catalog` lists this pattern's named blocks and their bindings before you insert it.

## Try it

`blueprints/cta-band.json`.
