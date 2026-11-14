---
name: voyager-open-closing-cta
description: Insert or customize the closing CTA, the band at the end of a page, in either of its two layouts. voyager-open/closing-cta centers a heading, one line of copy, a primary button and an email button bound to voyager/site-data. voyager-open/closing-cta-split puts the same text and buttons beside a 4:3 image slot. Use as the last section before the footer.
license: GPL-2.0-or-later
---

# Closing CTA (`voyager-open/closing-cta`, `voyager-open/closing-cta-split`)

The closing CTA is one section in two layouts. Each layout is its own pattern:

| Layout | Pattern | What it is |
|---|---|---|
| `centered` (the first) | `voyager-open/closing-cta` | The heading, the line and the buttons, centered at 720px. |
| `split` | `voyager-open/closing-cta-split` | The same text and buttons beside a 4:3 image, at wide width. On a phone the image stacks below the text. |

Each intent names its section and layout in its `section` and `layout` keys. The first layout keeps the section's slug; the other is the slug, a hyphen and the layout's name.

## When to use

- As the last section of a page, right before the footer.
- Once per page. For a call to action mid-page, use `voyager-open/cta-band`.
- **Centered** on most pages. It is the quiet ending, and it needs no photo.
- **Split** when the site has a photo of the people the reader would meet, such as the owner or the team, and the page should end on a face. The image sits on the side away from where the text starts, right on a left-to-right page, so the eye reads the invitation first.

## Tell your agent

> End my About page with the Voyager Open Blocks closing CTA. Rewrite the heading and the line for that page, and keep the email button bound to my Site Data email.

For the other layout, say "beside an image".

## Structure

```
voyager-open/closing-cta (centered)
core/group (section, full width, constrained 720px)   "Closing CTA"
  core/heading h2, centered                          "Heading"
  core/paragraph, centered                           "Body"
  core/buttons (centered)
    core/button  "Get in touch" -> /contact/         "Primary button"
    core/button  outline, "Email us"                  "Email button"
        url <- voyager/site-data { key: email_link }

voyager-open/closing-cta-split (split)
core/group (section, full width, constrained)         "Closing CTA"
  core/columns (wide, centered, stacks on a phone)    "Columns"
    core/column                                       "Text"
      core/heading h2                                 "Heading"
      core/paragraph                                  "Body"
      core/buttons
        core/button  "Get in touch" -> /contact/      "Primary button"
        core/button  outline, "Email us"               "Email button"
            url <- voyager/site-data { key: email_link }
    core/column                                       "Image column"
      core/image  4:3, cover, empty alt               "Image"
```

The intent trees are `patterns/intents/closing-cta.json` and `patterns/intents/closing-cta-split.json`. The markup is `patterns/closing-cta.html` and `patterns/closing-cta-split.html`. In the split layout the text starts at the column's edge, not centered: centered text beside an image leaves a ragged gap on both sides of it.

## Copy rules

Each intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text. Both layouts take the same labels, rules and default copy, so copy written for one fits the other.

| Label | What goes there | Min | Max | Required |
|---|---|---|---|---|
| `Heading` | The page's last `h2`: one short sentence that invites the reader to get in touch. | 10 | 60 | yes |
| `Body` | One line on how to reach the business and what happens next. | 20 | 120 | yes |
| `Primary button` | The page's last main action, as what happens when it is pressed, such as "Book an estimate". | 2 | 24 | yes |
| `Email button` | The second button's label. Its link is bound to the Site Data email address, and its text is its own, so name the action, such as "Email us", never the address. | 2 | 24 | yes |

No slot is a claim slot, and the shipped copy makes no claim. Do not put a licence, a rating, a guarantee or a number of years in the closing CTA unless the site owner gave it to you.

## The image slot

The split layout ships one image slot, the core/image "Image", with the pack's 4:3 placeholder and empty alt text, so `validate` warns `placeholder:` once on a page that holds it as shipped. The centered layout has none.

- The image is cropped to 4:3 (`aspectRatio`, `scale: cover`), from its centre. Choose a photo whose subject sits near the middle, or crop it with the image toolbar's Crop button.
- Give the replaced image alt text that says what it shows, such as who is in it. `validate` warns `missing_alt:` on it until it has some.

No ability changes an image. Tell the user to replace the placeholder in the editor: select the image, choose Replace, pick the photo from the Media Library, then add the alt text. Until then the page ends on the grey placeholder, so say so before you save to a published page.

## Customizing

Change the heading, the line and the primary button. Keep the heading short, one sentence that reads as an invitation. On route 2, edit the intent, then `npx block-runner assemble intent.json --json`. On route 1, use `insert` with `copy` (see Abilities). The primary button keeps `/contact/` on route 1, because no ability changes a static button's link.

To call instead of email, bind the second button's `url` to `{"key": "phone_link"}`. For its text, either bind `text` to `{"key": "phone"}` to show the number, or set the label with `copy` when you insert it, such as "Call us".

## Bindings

| Block | Attribute | Source | Args |
|---|---|---|---|
| Email button | `url` | `voyager/site-data` | `{ "key": "email_link" }` |

If the site email is not set, the button keeps its authored `/contact/` link.

## The loop

This is route 2, with a shell and Node. In the Wesper manifest, confirm `voyager-open/closing-cta` and `voyager-open/closing-cta-split` in `patterns.items` and `voyager/site-data` in `bindings.sources`. Insert one by reference, or assemble an edited intent with the site's own image, then check it with `npx block-runner validate`. The steps are in [docs/agents.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/agents.md).

## Abilities

This is route 1: the abilities, over MCP or REST, with no markup to write.

- **insert** at the end of the page: leave `position` out, or pass `-1`. If the page already ends with a closing CTA, do not add a second one. Read `get-post-blocks` first to check.
- **insert with copy**: `{"post_id": 123, "item": "voyager-open/closing-cta", "copy": {"Heading": "Ready when you are.", "Body": "Book a free estimate this week.", "Primary button": "Book an estimate", "Email button": "Email the office"}}`. Use `"item": "voyager-open/closing-cta-split"` for the split layout, with the same copy. In `compose`, name the section `voyager-open/closing-cta` and pass `"layout": "split"`. The email button keeps its `url` binding, so its text is a label, not the address. Never pass copy for a bound slot, such as a button whose `text` is bound: `insert` refuses it with `voyager_open_blocks_bound_slot` and saves nothing.
- **"As shipped"**: `"mode": "reference"` inserts a `core/pattern` that tracks plugin updates instead of a copy. Its text cannot change.
- **bind**: the email button arrives bound. To make it call instead, bind its `url` to `{"key": "phone_link"}` on `voyager/site-data`, using the path labelled "Email button".
- Then check it with **get-post-blocks** and **validate**. The "Email button" binding should resolve to a `mailto:` link. If it falls back, Settings > Site Data > Email address is empty or not a valid address. Say so. On the split layout, expect one `placeholder:` warning, for the image, and tell the user which image to put there.

## Try it

`blueprints/closing-cta.json` opens a page with both layouts.
