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
  core/columns (wide, centered)
    core/column 60%
      core/paragraph  small, uppercase                 "Eyebrow"
      core/heading h2
      core/paragraph
    core/column 40%
      core/buttons (right)
        core/button  "Contact us" -> /contact/
        core/button  outline                           "Click to call"
            text <- voyager/site-data { key: phone }
            url  <- voyager/site-data { key: phone_link }
```

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

## Try it

`blueprints/cta-band.json`.
