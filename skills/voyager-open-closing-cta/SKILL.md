---
name: voyager-open-closing-cta
description: Insert or customize the voyager-open/closing-cta pattern, a centered band at the end of a page with a heading, one line of copy, a primary button and an email button bound to voyager/site-data. Use as the last section before the footer.
license: GPL-2.0-or-later
---

# Closing CTA (`voyager-open/closing-cta`)

## When to use

- As the last section of a page, right before the footer.
- Once per page. For a call to action mid-page, use `voyager-open/cta-band`.

## Tell your agent

> End my About page with the Voyager Open Blocks closing CTA. Rewrite the heading and the line for that page, and keep the email button bound to my Site Data email.

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

Change the heading, the line and the primary button. Keep the heading short, one sentence that reads as an invitation. On route 2, edit the intent, then `npx block-runner assemble intent.json --json`. On route 1, use `insert` with `copy` (see Abilities). The primary button keeps `/contact/` on route 1, because no ability changes a static button's link.

To call instead of email, bind the second button's `url` to `{"key": "phone_link"}`. For its text, either bind `text` to `{"key": "phone"}` to show the number, or set the label with `copy` when you insert it, such as "Call us".

## Bindings

| Block | Attribute | Source | Args |
|---|---|---|---|
| Email button | `url` | `voyager/site-data` | `{ "key": "email_link" }` |

If the site email is not set, the button keeps its authored `/contact/` link.

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/closing-cta` is in `patterns.items`.
2. **Author.** Insert by reference, or assemble an edited intent.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`. Do not save markup that fails.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

- **insert** at the end of the page: leave `position` out, or pass `-1`. If the page already ends with a closing CTA, do not add a second one. Read `get-post-blocks` first to check.
- **insert with copy**: `{"post_id": 123, "item": "voyager-open/closing-cta", "copy": {"Heading": "Ready when you are.", "Body": "Book a free estimate this week.", "Primary button": "Book an estimate", "Email button": "Email the office"}}` replaces the text of those named blocks. Labels: `Heading`, `Body`, `Primary button`, `Email button`. The email button keeps its `url` binding, so its text is a label, not the address. Never pass copy for a bound slot, such as a button whose `text` is bound: `insert` refuses it with `voyager_open_blocks_bound_slot` and saves nothing. Plain text only, escaped on the way in. Add `"dry_run": true` first to check without saving. An unknown label, or a label on a block that is not text, is refused with `voyager_open_blocks_invalid_copy`, which lists the valid labels.
- **"As shipped"**: `"mode": "reference"` inserts a `core/pattern` that tracks plugin updates instead of a copy. Its text cannot change.
- **bind**: the email button arrives bound. To make it call instead, bind its `url` to `{"key": "phone_link"}` on `voyager/site-data`, using the path labelled "Email button".
- **get-post-blocks** again: the "Email button" binding should read `resolution.status: "resolved"`, with a `mailto:` link in `value_preview`. `fell_back` with `reason: "empty_value"` means Settings > Site Data > Email address is empty or not a valid address. Say so.
- **validate** with `{"post_id": 123}` checks the saved page.

On route 2, also pipe the returned `markup` through `npx block-runner validate --strict --json --context site.context.json`.

`get-catalog` lists this pattern's named blocks and their bindings before you insert it.

## Try it

`blueprints/closing-cta.json`.
