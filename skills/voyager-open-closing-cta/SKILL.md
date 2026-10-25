---
name: voyager-open-closing-cta
description: Insert or customize the voyager-open/closing-cta pattern, a centered band at the end of a page with a heading, one line of copy, a primary button and an email button bound to voyager/site-data. Use as the last section before the footer.
license: GPL-2.0-or-later
---

# Closing CTA (`voyager-open/closing-cta`)

## When to use

- As the last section of a page, right before the footer.
- Once per page. For a call to action mid-page, use `voyager-open/cta-band`.

## Structure

```
core/group (section, full width, constrained 720px)   "Closing CTA"
  core/heading h2, centered                          "Heading"
  core/paragraph, centered                           "Body"
  core/buttons (centered)
    core/button  "Get in touch" -> /contact/         "Primary button"
    core/button  outline, "Email us"                  "Email button"
        url <- voyager/site-data { key: email_link }
```

The intent tree is `patterns/intents/closing-cta.json`. The markup is `patterns/closing-cta.html`.

## Customizing

Change the heading, the line and the primary button. Keep the heading short, one sentence that reads as an invitation. Then `npx block-runner assemble intent.json --json`.

To call instead of email, bind the second button's `url` to `phone_link`, and its `text` to `phone` or a label like "Call us".

## Bindings

| Block | Attribute | Source | Args |
|---|---|---|---|
| Email button | `url` | `voyager/site-data` | `{ "key": "email_link" }` |

If the site email is not set, the button keeps its authored `/contact/` link.

## The loop

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/closing-cta` is in `patterns.items`.
2. **Author.** Insert by reference, or assemble an edited intent.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`.

## Abilities

With the plugin active, an agent places this pattern through the `voyager-open-blocks` abilities (WordPress Abilities API, `wp-abilities/v1`, also exposed to MCP) without writing markup:

- **insert**: `{"post_id": 123, "item": "voyager-open/closing-cta"}`. Add `"position": 0` to put it first, or `"mode": "reference"` to insert a `core/pattern` that tracks plugin updates instead of a copy. The response returns the inserted `markup`, plus each block's `path` and `label` (`metadata.name`).
- **insert with copy**: `{"post_id": 123, "item": "voyager-open/closing-cta", "copy": {"Heading": "Ready when you are.", "Body": "Book a free estimate this week.", "Primary button": "Book an estimate", "Email button": "Email the office"}}` replaces the text of those named blocks. Labels: Heading, Body, Primary button, Email button. The email button keeps its `url` binding. Plain text only, escaped on the way in. Copy mode only. An unknown label, or a label on a block that is not text, is refused with `voyager_open_blocks_invalid_copy`, which lists the valid labels.
- **bind**: it arrives with the email button bound. To make that button call instead, bind its `url` to `{"key": "phone_link"}` on `voyager/site-data`, using the path labelled "Email button".
- Pipe the returned `markup` through `npx block-runner validate --strict --json`. WordPress cannot check saved HTML from PHP.

`get-catalog` lists this pattern's named blocks and their bindings before you insert it.

## Try it

`blueprints/closing-cta.json`.
