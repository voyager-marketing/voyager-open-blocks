---
name: voyager-open-cta-band
description: Insert or customize the voyager-open/cta-band pattern, a full-width band with an eyebrow, heading and one line of copy beside a primary button and a click-to-call button bound to voyager/site-data. Use mid-page or after a services section when the reader should be asked to act.
license: GPL-2.0-or-later
---

# CTA band (`voyager-open/cta-band`)

## When to use

- Mid-page, after the reader has seen what is offered.
- Not at the very end of a page. That is `voyager-open/closing-cta`.

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

- **As shipped:** `<!-- wp:pattern {"slug":"voyager-open/cta-band"} /-->`.
- **Edited:** copy the intent, change the eyebrow, heading, line and the primary button's `text` and `url`, then `npx block-runner assemble intent.json --json`. Keep the call button's bindings. Its authored "Call us" and `/contact/` are what visitors see until the phone number is set.
- Point the primary button at a page that exists. Check the manifest or the site before using `/contact/`.

## Bindings

| Block | Attribute | Source | Args |
|---|---|---|---|
| Click to call button | `text` | `voyager/site-data` | `{ "key": "phone" }` |
| Click to call button | `url` | `voyager/site-data` | `{ "key": "phone_link" }` |

## The loop

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/cta-band` is in `patterns.items` and `voyager/site-data` is in `bindings.sources`.
2. **Author.** Insert by reference, or assemble an edited intent.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`.
4. **Bind.** Already bound. If Settings > Site Data has no phone, the button keeps its placeholder. Say so instead of inventing a number.

## Abilities

With the plugin active, an agent places this pattern through the `voyager-open-blocks` abilities (WordPress Abilities API, `wp-abilities/v1`, also exposed to MCP) without writing markup:

- **insert**: `{"post_id": 123, "item": "voyager-open/cta-band"}`. Add `"position": 0` to put it first, or `"mode": "reference"` to insert a `core/pattern` that tracks plugin updates instead of a copy. The response returns the inserted `markup`, plus each block's `path` and `label` (`metadata.name`).
- **insert with copy**: `{"post_id": 123, "item": "voyager-open/cta-band", "copy": {"Eyebrow": "Free estimates", "Heading": "Need a plumber today?", "Body": "Call before noon for a same-day visit.", "Primary button": "Book a visit"}}` replaces the text of those named blocks. Labels: Eyebrow, Heading, Body, Primary button, Click to call. "Click to call" keeps its bindings, so text given for it is only the fallback shown until the phone number is set. Plain text only, escaped on the way in. Copy mode only. An unknown label, or a label on a block that is not text, is refused with `voyager_open_blocks_invalid_copy`, which lists the valid labels. The primary button's `url` is not copy: point it at a page that exists by re-authoring, or keep `/contact/`.
- **bind**: it arrives bound. To re-point the call button, take its path from the insert response (label "Click to call") and pass args copied from `get-catalog`, for example `{"attribute": "url", "source": "voyager/site-data", "args": {"key": "phone_link"}}`.
- Pipe the returned `markup` through `npx block-runner validate --strict --json`. WordPress cannot check saved HTML from PHP.

`get-catalog` lists this pattern's named blocks and their bindings before you insert it.

## Try it

`blueprints/cta-band.json`.
