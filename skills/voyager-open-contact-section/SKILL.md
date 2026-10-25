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
        core/paragraph  content <- address         "Street address"
        core/paragraph  content <- address_locality "City, state, postal code"
        core/paragraph  content <- hours           "Hours"
      core/buttons
        core/button "Call us"  url <- phone_link   "Click to call"
    core/column 60%                                "Form slot"
      core/group (bordered)                        "Form"
        core/heading h3                            "Form heading"
        core/paragraph                             "Form body"
        core/buttons
          core/button "Email us"  url <- email_link "Email button"
```

Every binding uses the `voyager/site-data` source. The intent tree is `patterns/intents/contact-section.json`. The markup is `patterns/contact-section.html`.

## Customizing

- **Add the site's form.** Replace the contents of the group named "Form" with the form plugin's block. Read the block's name and attributes from the manifest's `blocks.types`. Do not guess them. Keep the heading if it still fits.
- **Hide a line.** Remove a detail paragraph the business does not want shown, for example the street address of a home business.
- The authored text in each detail paragraph is a placeholder (example.com, a 555 number). It shows only while that setting is empty.

Then `npx block-runner assemble intent.json --json`. A third-party form block cannot be assembled, so insert it into the markup after assembly, the same way as `voyager-open/icon` (see `voyager-open-icon`).

## Bindings

| Block | Attribute | Args |
|---|---|---|
| Phone | `content` | `{ "key": "phone" }` |
| Email | `content` | `{ "key": "email" }` |
| Street address | `content` | `{ "key": "address" }` |
| City, state, postal code | `content` | `{ "key": "address_locality" }` |
| Hours | `content` | `{ "key": "hours" }` |
| Click to call | `url` | `{ "key": "phone_link" }` |
| Email button | `url` | `{ "key": "email_link" }` |

## The loop

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm the pattern and `voyager/site-data`. Find the form plugin's block in `blocks.types`.
2. **Author.** Assemble, then put the form block into the slot.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`.
4. **Check the data.** List any empty Site Data settings for the owner instead of filling them with guesses.

## Abilities

With the plugin active, an agent places this pattern through the `voyager-open-blocks` abilities (WordPress Abilities API, `wp-abilities/v1`, also exposed to MCP) without writing markup:

- **insert**: `{"post_id": 123, "item": "voyager-open/contact-section"}`. Add `"position": 0` to put it first, or `"mode": "reference"` to insert a `core/pattern` that tracks plugin updates instead of a copy. The response returns the inserted `markup`, plus each block's `path` and `label` (`metadata.name`).
- **insert with copy**: `{"post_id": 123, "item": "voyager-open/contact-section", "copy": {"Heading": "Visit or call", "Body": "We answer the phone during business hours.", "Form heading": "Ask a question"}}` replaces the text of those named blocks. Labels: Heading, Body, Phone, Email, Street address, "City, state, postal code", Hours, Click to call, Form heading, Form body, Email button. The detail lines and both buttons stay bound, so text given for them is only the fallback shown while that Site Data setting is empty. Set the business details in Site Data rather than typing them here. Plain text only, escaped on the way in. Copy mode only. An unknown label, or a label on a block that is not text, is refused with `voyager_open_blocks_invalid_copy`, which lists the valid labels.
- **bind**: every detail line arrives bound. Add or change one with its path and args from `get-catalog`. The form plugin's block goes in by hand or through that plugin's own tools. This pack does not insert third-party blocks.
- Pipe the returned `markup` through `npx block-runner validate --strict --json`. WordPress cannot check saved HTML from PHP.

`get-catalog` lists this pattern's named blocks and their bindings before you insert it.

## Try it

`blueprints/contact-section.json`.
