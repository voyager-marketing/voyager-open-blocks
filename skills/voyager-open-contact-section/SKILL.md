---
name: voyager-open-contact-section
description: Insert or customize the voyager-open/contact-section pattern, contact details bound to voyager/site-data (phone, email, address, hours) beside a form slot that holds an email fallback until a form plugin's block replaces it. Use on a contact page or as the contact block of a landing page.
license: GPL-2.0-or-later
---

# Contact section (`voyager-open/contact-section`)

## When to use

- The main block of a contact page.
- A contact block near the end of a landing page.

The pack ships no form engine. WordPress core has no stable form block, so the right column is a slot for whichever form plugin the site uses.

## Tell your agent

> Add the Voyager Open Blocks contact section to my Contact page. Keep the phone, email, address and hours bound to Site Data, and tell me which Site Data fields are still empty.

## Structure

```
core/group (section, full width, constrained)      "Contact section"
  core/columns (wide)
    core/column 40%                                "Contact details"
      core/heading h2                              "Heading"
      core/paragraph                               "Body"
      core/group (tight gap)                       "Details"
        core/paragraph  content <- phone           "Phone"
        core/paragraph  content <- email           "Email"
        core/group (no gap)                        "Address"
          core/paragraph  content <- address       "Street address"
          core/paragraph  content <- address_locality "City, state, postal code"
        core/paragraph  content <- hours           "Hours"
      core/buttons
        core/button outline "Call us"  url <- phone_link "Click to call"
    core/column 60%                                "Form slot"
      core/group (bordered, min-height 100%)       "Form"
        core/heading h3                            "Form heading"
        core/paragraph                             "Form body"
        core/buttons
          core/button "Email us"  url <- email_link "Email button"
```

"Call us" is an outline button, so the form card's "Email us" is the section's one primary action. The Form group has a min-height of 100%, so the card fills its column and both halves end together. The street and city lines sit in the Address group with no gap, so the address reads as one unit.

Every binding uses the `voyager/site-data` source. The intent tree is `patterns/intents/contact-section.json`. The markup is `patterns/contact-section.html`.

## Copy rules

The intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text.

| Label | What goes there | Min | Max | Required |
|---|---|---|---|---|
| `Heading` | The section's `h2`: an invitation to get in touch, in a few words. | 3 | 60 | yes |
| `Body` | One line on the ways to reach the business, or when. Leave it out when the details say enough. | 20 | 120 | no |
| `Phone` | Bound to the Site Data phone number. Its own text, a sample 555 number, shows only while the number is empty. | 2 | 40 | no |
| `Email` | Bound to the Site Data email address. Its own text, an example.com address, shows only while the address is empty. | 2 | 60 | no |
| `Street address` | Bound to the Site Data street address. Its own text, a sample street, shows only while the street address is empty. | 2 | 80 | no |
| `City, state, postal code` | Bound to the Site Data city, state or region and postal code, shown as one line. Its own text, a sample town, shows only while all three are empty. | 2 | 80 | no |
| `Hours` | Bound to the Site Data business hours. Its own text, sample weekday hours, shows only while the hours are empty. | 2 | 80 | no |
| `Click to call` | The call button's label. Its link is bound to the Site Data phone number, and its text is its own, so name the action, such as "Call us", never the number. Leave it out with the Phone line. | 2 | 24 | no |
| `Form heading` | The form slot's `h3`: what the visitor can send, in a few words. | 3 | 40 | yes |
| `Form body` | One line on what to put in the message, until a form plugin's block replaces the slot. | 20 | 120 | yes |
| `Email button` | The form slot's one fill button, until a form plugin's block replaces it. Its link is bound to the Site Data email address, and its text is its own, so name the action, such as "Email us", never the address. | 2 | 24 | yes |

The five detail lines are bound slots: `insert` refuses copy for them, and their rules describe the fallback text the pattern ships. Any of them can be left out with `omit`. No slot is a claim slot, and the shipped copy makes no claim.

## Customizing

- **Add the site's form.** Replace the contents of the group named "Form" with the form plugin's block. On route 2, read the block's name and attributes from the manifest's `blocks.types`. Do not guess them. Keep the heading if it still fits. On route 1, no ability places a third-party block: tell the user to add the form in the editor.
- **Hide a line.** Remove a detail paragraph the business does not want shown, or the whole Address group of a home business. On route 1, use `omit` with its label, such as `["Hours"]` or `["Address"]`.
- **Keep one primary action.** If the form plugin's block brings its own submit button, leave "Call us" as an outline button.
- The authored text in each detail paragraph is a placeholder (example.com, a 555 number). It shows only while that setting is empty.

On route 2, assemble with `npx block-runner assemble intent.json --json`. A third-party form block cannot be assembled, so insert it into the markup after assembly, the same way as `voyager-open/icon` (see `voyager-open-icon`), then validate the whole section.

In the block editor, the form slot's email fallback carries a "Replace this with your form block" hint, and empty Site Data lines carry a warning. Neither shows on the front end.

## Bindings

| Block | Attribute | Args | Filled in at |
|---|---|---|---|
| Phone | `content` | `{ "key": "phone" }` | Settings > Site Data > Phone number |
| Email | `content` | `{ "key": "email" }` | Settings > Site Data > Email address |
| Street address | `content` | `{ "key": "address" }` | Settings > Site Data > Street address |
| City, state, postal code | `content` | `{ "key": "address_locality" }` | Settings > Site Data > City, State or region, Postal code |
| Hours | `content` | `{ "key": "hours" }` | Settings > Site Data > Business hours |
| Click to call | `url` | `{ "key": "phone_link" }` | Settings > Site Data > Phone number |
| Email button | `url` | `{ "key": "email_link" }` | Settings > Site Data > Email address, when it is a valid address |

## The loop

This is route 2, with a shell and Node. In the Wesper manifest, confirm `voyager-open/contact-section` in `patterns.items` and `voyager/site-data` in `bindings.sources`, and find the form plugin's block in `blocks.types`. Assemble an edited intent, put the form block into the slot, then check it with `npx block-runner validate`. The steps are in [docs/agents.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/agents.md).

## Abilities

This is route 1: the abilities, over MCP or REST, with no markup to write.

1. **Where.** On an empty Contact page, leave `position` out. Otherwise pick it from `get-post-blocks`.
2. **insert** with copy: `{"post_id": 123, "item": "voyager-open/contact-section", "copy": {"Heading": "Visit or call", "Body": "We answer the phone during business hours.", "Form heading": "Ask a question"}}`. Never pass copy for a bound slot: the detail lines take their text from Site Data, so `insert` refuses copy for them with `voyager_open_blocks_bound_slot` and saves nothing; change them in Settings > Site Data instead. Only the buttons' links are bound, so `Click to call` and `Email button` take copy.
3. **omit**, only when the owner asks: add `"omit": ["Hours"]` to the same insert to leave a line out. `["Address"]` drops the street and city lines together.
4. **Check the data** with `get-post-blocks` and `validate`. Every detail line and both buttons are bound: list each one that falls back, with its setting from the Bindings table. Do not fill them with guesses.
5. **The form.** The form plugin's block goes in by hand or through that plugin's own tools. This pack does not insert third-party blocks.

Every detail line arrives bound, so `bind` is rarely needed.

## Try it

`blueprints/contact-section.json`.
