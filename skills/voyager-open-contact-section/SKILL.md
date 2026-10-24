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
      core/heading h2
      core/paragraph
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
        core/heading h3, core/paragraph
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

## Try it

`blueprints/contact-section.json`.
