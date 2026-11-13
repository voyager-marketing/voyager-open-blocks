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

## Copy rules

The intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text.

| Label | What goes there | Min | Max | Required |
|---|---|---|---|---|
| `Heading` | The page's last `h2`: one short sentence that invites the reader to get in touch. | 10 | 60 | yes |
| `Body` | One line on how to reach the business and what happens next. | 20 | 120 | yes |
| `Primary button` | The page's last main action, as what happens when it is pressed, such as "Book an estimate". | 2 | 24 | yes |
| `Email button` | The second button's label. Its link is bound to the Site Data email address, and its text is its own, so name the action, such as "Email us", never the address. | 2 | 24 | yes |

No slot is a claim slot, and the shipped copy makes no claim. Do not put a licence, a rating, a guarantee or a number of years in the closing CTA unless the site owner gave it to you.

## Customizing

Change the heading, the line and the primary button. Keep the heading short, one sentence that reads as an invitation. On route 2, edit the intent, then `npx block-runner assemble intent.json --json`. On route 1, use `insert` with `copy` (see Abilities). The primary button keeps `/contact/` on route 1, because no ability changes a static button's link.

To call instead of email, bind the second button's `url` to `{"key": "phone_link"}`. For its text, either bind `text` to `{"key": "phone"}` to show the number, or set the label with `copy` when you insert it, such as "Call us".

## Bindings

| Block | Attribute | Source | Args |
|---|---|---|---|
| Email button | `url` | `voyager/site-data` | `{ "key": "email_link" }` |

If the site email is not set, the button keeps its authored `/contact/` link.

## The loop

This is route 2, with a shell and Node. In the Wesper manifest, confirm `voyager-open/closing-cta` in `patterns.items` and `voyager/site-data` in `bindings.sources`. Insert it by reference, or assemble an edited intent, then check it with `npx block-runner validate`. The steps are in [docs/agents.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/agents.md).

## Abilities

This is route 1: the abilities, over MCP or REST, with no markup to write.

- **insert** at the end of the page: leave `position` out, or pass `-1`. If the page already ends with a closing CTA, do not add a second one. Read `get-post-blocks` first to check.
- **insert with copy**: `{"post_id": 123, "item": "voyager-open/closing-cta", "copy": {"Heading": "Ready when you are.", "Body": "Book a free estimate this week.", "Primary button": "Book an estimate", "Email button": "Email the office"}}`. The email button keeps its `url` binding, so its text is a label, not the address. Never pass copy for a bound slot, such as a button whose `text` is bound: `insert` refuses it with `voyager_open_blocks_bound_slot` and saves nothing.
- **"As shipped"**: `"mode": "reference"` inserts a `core/pattern` that tracks plugin updates instead of a copy. Its text cannot change.
- **bind**: the email button arrives bound. To make it call instead, bind its `url` to `{"key": "phone_link"}` on `voyager/site-data`, using the path labelled "Email button".
- Then check it with **get-post-blocks** and **validate**. The "Email button" binding should resolve to a `mailto:` link. If it falls back, Settings > Site Data > Email address is empty or not a valid address. Say so.

## Try it

`blueprints/closing-cta.json`.
