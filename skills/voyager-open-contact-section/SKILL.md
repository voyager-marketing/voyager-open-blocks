---
name: voyager-open-contact-section
description: Insert or customize the contact section, a contact details block bound to voyager/site-data (address, phone, email, hours) whose phone and email dial and open a new message when tapped, and a form slot that holds an email fallback until a form plugin's block replaces it, in either of its two layouts. voyager-open/contact-section puts the details beside the form slot. voyager-open/contact-section-stacked puts them in one column, the form slot below. Use on a contact page or as the contact block of a landing page.
license: GPL-2.0-or-later
---

# Contact section (`voyager-open/contact-section`, `voyager-open/contact-section-stacked`)

The contact section is one section in two layouts. Each layout is its own pattern:

| Layout | Pattern | What it is |
|---|---|---|
| `split` (the first) | `voyager-open/contact-section` | The heading, the details and the call button in a column beside the form slot, at wide width. On a phone the form slot stacks below. |
| `stacked` | `voyager-open/contact-section-stacked` | The same blocks in one column at content width: the heading and line, the details and the call button, then the form slot below them. |

Each intent names its section and layout in its `section` and `layout` keys. The first layout keeps the section's slug; the other is the slug, a hyphen and the layout's name.

## When to use

- The main block of a contact page.
- A contact block near the end of a landing page.
- **Split** on a contact page, where the details and the form are the page's main job and each gets its own column.
- **Stacked** where the section sits in a narrow content area, such as a sidebar-width column or a theme whose content is narrow, or at the end of a page whose sections read in one column, such as after the FAQ. Two columns there would squeeze both halves.

The pack ships no form engine. WordPress core has no stable form block, so the form slot is a place for whichever form plugin the site uses.

## Tell your agent

> Add the Voyager Open Blocks contact section to my Contact page. Keep the phone, email, address and hours bound to Site Data, and tell me which Site Data fields are still empty.

For the other layout, say "in one column".

## Structure

```
voyager-open/contact-section (split)
core/group (section, full width, constrained)      "Contact section"
  core/columns (wide)
    core/column 40%                                "Contact details"
      core/heading h2                              "Heading"
      core/paragraph                               "Body"
      voyager-open/contact-details                 "Details"
        address <- address, addressLocality <- address_locality
        phone <- phone, phoneLink <- phone_link
        email <- email, emailLink <- email_link
        hours <- hours
      core/buttons
        core/button outline "Call us"  url <- phone_link "Click to call"
    core/column 60%                                "Form slot"
      core/group (bordered, min-height 100%)       "Form"
        core/heading h3                            "Form heading"
        core/paragraph                             "Form body"
        core/buttons
          core/button "Email us"  url <- email_link "Email button"

voyager-open/contact-section-stacked (stacked)
core/group (section, full width, constrained)      "Contact section"
  core/group (content width)                       "Contact details"
    core/heading h2                                "Heading"
    core/paragraph                                 "Body"
    voyager-open/contact-details                   "Details"
      (the same seven bindings)
    core/buttons
      core/button outline "Call us"  url <- phone_link "Click to call"
  core/group (content width)                       "Form slot"
    core/group (bordered)                          "Form"
      core/heading h3                              "Form heading"
      core/paragraph                               "Form body"
      core/buttons
        core/button "Email us"  url <- email_link  "Email button"
```

The details are one contact details block (see `voyager-open-contact-details`): an `address` with the street, the city line, the phone as a `tel:` link and the email as a `mailto:` link, then the hours. On a phone, a visitor taps the number to call and the address to write. Each link is alone on its line and at least 44px tall and wide.

"Call us" is an outline button, so the form card's "Email us" is the section's one primary action. In the split layout the Form group has a min-height of 100%, so the card fills its column and both halves end together. In one column the card is as tall as what it holds, under the call button.

Every binding uses the `voyager/site-data` source, and both layouts bind the same blocks the same way. The intent trees are `patterns/intents/contact-section.json` and `patterns/intents/contact-section-stacked.json`. The markup is `patterns/contact-section.html` and `patterns/contact-section-stacked.html`.

## Copy rules

Each intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text. Both layouts take the same labels, rules and default copy, so copy written for one fits the other.

| Label | What goes there | Min | Max | Required |
|---|---|---|---|---|
| `Heading` | The section's `h2`: an invitation to get in touch, in a few words. | 3 | 60 | yes |
| `Body` | One line on the ways to reach the business, or when. Leave it out when the details say enough. | 20 | 120 | no |
| `Click to call` | The call button's label. Its link is bound to the Site Data phone number, and its text is its own, so name the action, such as "Call us", never the number. Leave it out when the business takes no calls. | 2 | 24 | no |
| `Form heading` | The form slot's `h3`: what the visitor can send, in a few words. | 3 | 40 | yes |
| `Form body` | One line on what to put in the message, until a form plugin's block replaces the slot. | 20 | 120 | yes |
| `Email button` | The form slot's one fill button, until a form plugin's block replaces it. Its link is bound to the Site Data email address, and its text is its own, so name the action, such as "Email us", never the address. | 2 | 24 | yes |

"Details" takes no copy: it is not a text block, and every part of it is bound to Site Data. No slot is a claim slot, and the shipped copy makes no claim.

## While Site Data is empty

A bound part whose field is empty shows the block's own value: the source returns nothing, and WordPress keeps the stored one. The section ships labels there, never sample details:

| Part | Shows while its field is empty |
|---|---|
| Street | "Street address" |
| City line | "City, state, postal code" |
| Phone | "Phone", as text that does not dial |
| Email | "Email", as text that opens nothing |
| Hours | "Hours" |

The phone and email links ship empty, so no `tel:` or `mailto:` link renders in the details until the field has a value. `compose` lists each field the page binds that is empty in its `empty`, and `get-post-blocks` names the empty field for each binding that falls back. Tell the user which ones, with the setting from the Bindings table.

## Customizing

- **Add the site's form.** Replace the contents of the group named "Form" with the form plugin's block. On route 2, read the block's name and attributes from the manifest's `blocks.types`. Do not guess them. Keep the heading if it still fits. On route 1, no ability places a third-party block: tell the user to add the form in the editor.
- **Hide a part.** A part the business does not show, such as the street of a home business or the hours, is hidden by leaving its Site Data field empty, not by `omit`: `omit` takes the whole "Details" block. With the field empty the part shows its label, so also reset the block's own value with `configure`, `{"attributes": {"hours": null}}` on the "Details" block's path. A part with no field value and no own value prints nothing.
- **Keep one primary action.** If the form plugin's block brings its own submit button, leave "Call us" as an outline button.

On route 2, assemble with `npx block-runner assemble intent.json --json`. Block Runner cannot assemble a custom block or a third-party form block, so put the contact details block's comment and the form block into the markup after assembly, the same way as `voyager-open/icon` (see `voyager-open-icon`), then validate the whole section.

In the block editor, the form slot's email fallback carries a "Replace this with your form block" hint, in either layout. It does not show on the front end.

## Bindings

| Block | Attribute | Args | Filled in at |
|---|---|---|---|
| Details | `address` | `{ "key": "address" }` | Settings > Site Data > Street address |
| Details | `addressLocality` | `{ "key": "address_locality" }` | Settings > Site Data > City, State or region, Postal code |
| Details | `phone` | `{ "key": "phone" }` | Settings > Site Data > Phone number |
| Details | `phoneLink` | `{ "key": "phone_link" }` | Settings > Site Data > Phone number |
| Details | `email` | `{ "key": "email" }` | Settings > Site Data > Email address |
| Details | `emailLink` | `{ "key": "email_link" }` | Settings > Site Data > Email address, when it is a valid address |
| Details | `hours` | `{ "key": "hours" }` | Settings > Site Data > Business hours |
| Click to call | `url` | `{ "key": "phone_link" }` | Settings > Site Data > Phone number |
| Email button | `url` | `{ "key": "email_link" }` | Settings > Site Data > Email address, when it is a valid address |

## The loop

This is route 2, with a shell and Node. In the Wesper manifest, confirm `voyager-open/contact-section` and `voyager-open/contact-section-stacked` in `patterns.items`, `voyager-open/contact-details` in `blocks.types` and `voyager/site-data` in `bindings.sources`, and find the form plugin's block in `blocks.types`. Assemble an edited intent, put the form block into the slot, then check it with `npx block-runner validate`. The steps are in [docs/agents.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/agents.md).

## Abilities

This is route 1: the abilities, over MCP or REST, with no markup to write.

1. **Where.** On an empty Contact page, leave `position` out. Otherwise pick it from `get-post-blocks`.
2. **insert** with copy: `{"post_id": 123, "item": "voyager-open/contact-section", "copy": {"Heading": "Visit or call", "Body": "We answer the phone during business hours.", "Form heading": "Ask a question"}}`. The details take their text from Site Data, never from copy: change them in Settings > Site Data. Only the buttons' links are bound, so `Click to call` and `Email button` take copy. Use `"item": "voyager-open/contact-section-stacked"` for one column, with the same copy. In `compose`, name the section `voyager-open/contact-section` and pass `"layout": "stacked"`.
3. **omit**, only when the owner asks: `"omit": ["Click to call"]` leaves out the call button. To hide one part of the details, see "Hide a part" above.
4. **Check the data** with `get-post-blocks` and `validate`. Every part of the details and both buttons' links are bound: list each one that falls back, with its setting from the Bindings table. Do not fill them with guesses. In either layout, expect one `placeholder:` warning, for the form's stand-in, until a form block replaces it.
5. **The form.** The form plugin's block goes in by hand or through that plugin's own tools. This pack does not insert third-party blocks.

Every part of the details arrives bound, so `bind` is rarely needed.

`validate` warns `stale_fallback:` on a contact section saved by an earlier release, whose phone and email paragraphs still hold a sample number and address that visitors see while the field is empty. Replace the section, or each paragraph's text with a label such as "Phone".

## Try it

`blueprints/contact-section.json` opens a page with both layouts.
