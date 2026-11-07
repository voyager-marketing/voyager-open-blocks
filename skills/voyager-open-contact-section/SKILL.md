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

## Customizing

- **Add the site's form.** Replace the contents of the group named "Form" with the form plugin's block. On route 2, read the block's name and attributes from the manifest's `blocks.types`. Do not guess them. Keep the heading if it still fits. On route 1, no ability places a third-party block: tell the user to add the form in the editor.
- **Hide a line.** Remove a detail paragraph the business does not want shown, or the whole Address group of a home business. On route 1, use `omit` with its label, such as `["Hours"]` or `["Address"]`.
- **Keep one primary action.** If the form plugin's block brings its own submit button, leave "Call us" as an outline button.
- The authored text in each detail paragraph is a placeholder (example.com, a 555 number). It shows only while that setting is empty.

On route 2, assemble with `npx block-runner assemble intent.json --json`. A third-party form block cannot be assembled, so insert it into the markup after assembly, the same way as `voyager-open/icon` (see `voyager-open-icon`), then validate the whole section.

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

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm the pattern and `voyager/site-data`. Find the form plugin's block in `blocks.types`.
2. **Author.** Assemble, then put the form block into the slot.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`. Do not save markup that fails.
4. **Check the data.** List any empty Site Data settings for the owner instead of filling them with guesses.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

1. **Where.** Read `get-post-blocks` on the page to pick `position` among the top-level blocks (paths with no `/`). On an empty Contact page, leave `position` out.
2. **insert** with copy: `{"post_id": 123, "item": "voyager-open/contact-section", "copy": {"Heading": "Visit or call", "Body": "We answer the phone during business hours.", "Form heading": "Ask a question"}}`. Add `"dry_run": true` first to check without saving, then call it again without `dry_run`. Labels: `Heading`, `Body`, `Phone`, `Email`, `Street address`, `City, state, postal code`, `Hours`, `Click to call`, `Form heading`, `Form body`, `Email button`. The detail lines and both buttons stay bound, so text given for them is only the fallback shown while that Site Data setting is empty. Do not type the business details into `copy`. Plain text only, escaped on the way in. An unknown label, or a label on a block that is not text, is refused with `voyager_open_blocks_invalid_copy`, which lists the valid labels.
3. **omit**, only when the owner asks: add `"omit": ["Hours"]` to the same insert to leave a line out. `["Address"]` drops the street and city lines together.
4. **Check the data.** Call `get-post-blocks`. Each detail line and button carries `resolution` for its binding. `resolved` shows the value in `value_preview`. `fell_back` with `reason: "empty_value"` means the setting in the Bindings table is empty: list those settings for the user. Do not fill them with guesses.
5. **validate** with `{"post_id": 123}` checks the saved page.
6. **The form.** The form plugin's block goes in by hand or through that plugin's own tools. This pack does not insert third-party blocks.

`bind` can add or change a binding: its path, and args from `get-catalog`. Every detail line arrives bound, so it is rarely needed.

On route 2, also pipe the returned `markup` through `npx block-runner validate --strict --json --context site.context.json`.

`get-catalog` lists this pattern's named blocks and their bindings before you insert it.

## Try it

`blueprints/contact-section.json`.
