---
name: voyager-site-data
description: Bind block attributes to site-wide business details (company name, phone, email, address, hours, social links, copyright line) through the voyager/site-data block bindings source. Use when a page shows contact details or a click-to-call or email button that should follow the site's settings instead of hard-coded text.
license: GPL-2.0-or-later
---

# Site data (`voyager/site-data`)

A Block Bindings source. Values are set once under **Settings > Site Data** and every bound block follows them.

## When to use

- Contact details in a section, footer or CTA.
- A button whose link must dial or email the business.
- The copyright line.

Not for per-page content. That is `voyager/post-meta-text`.

## Keys

Args are always `{ "key": "<key>" }`. Wesper lists this source under `bindings.sources` but cannot list its keys, so this table is the contract.

| Key | Value | Typical attribute |
|---|---|---|
| `company` | Site title | `content`, `label` |
| `tagline` | Site tagline | `content` |
| `phone` | Phone as entered | `content`, button `text` |
| `phone_link` | `tel:` plus the digits and `+` of the phone | button `url`, icon `linkUrl` |
| `email` | Email address | `content` |
| `email_link` | `mailto:` plus the email, when it is a valid address | button `url`, icon `linkUrl` |
| `address` | Street address | `content` |
| `address_city`, `address_state`, `address_zip` | Address parts | `content` |
| `address_locality` | "City, ST 12345", with no stray commas when a part is empty | `content` |
| `hours` | Business hours | `content` |
| `year` | Current year | `content` |
| `copyright` | "© 2026 Company" | `content` |
| `social_facebook`, `social_instagram`, `social_linkedin`, `social_x` | Profile URLs | button `url`, icon `linkUrl` |

The stored options are `voyager_site_phone`, `voyager_site_email`, `voyager_site_address`, `voyager_site_address_city`, `voyager_site_address_state`, `voyager_site_address_zip`, `voyager_site_hours` and `voyager_site_social_*`. They are registered with `show_in_rest`, so an administrator's REST client can set them at `/wp/v2/settings`.

## Binding examples

A paragraph:

```json
{ "block": "core/paragraph", "text": "(555) 010-0100", "attrs": { "metadata": { "bindings": { "content": { "source": "voyager/site-data", "args": { "key": "phone" } } } } } }
```

A click-to-call button (text and link both bound):

```json
{ "block": "core/button", "text": "Call us", "url": "/contact/", "attrs": { "metadata": { "bindings": {
  "text": { "source": "voyager/site-data", "args": { "key": "phone" } },
  "url":  { "source": "voyager/site-data", "args": { "key": "phone_link" } }
} } } }
```

Those are Block Runner intent nodes. `npx block-runner assemble` turns them into markup.

## Behaviour to rely on

- An unknown key or an empty value resolves to nothing, and WordPress keeps the authored text. Always author sensible placeholder text.
- Values come back raw. WordPress escapes them where they land (`wp_kses_post` for rich text, the HTML API for attributes).
- `voyager_site_data_value` filters every resolved value.
- The voyager-blocks plugin registers the same source with the same option names. When it is active, it owns the source and this pack stands aside. `phone_link` and `email_link` exist only in this pack.

## The loop

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Check that `bindings.sources` has `voyager/site-data`. Check that `bindings.supportedAttributes` allows the attribute on your block.
2. **Author.** Add the binding in the intent's `attrs.metadata.bindings` and assemble.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`.
4. **Check the data.** If a value is empty, the placeholder shows. Tell the user which settings to fill in rather than inventing a phone number or address.

## Abilities

**bind** writes this source into a block's `metadata.bindings`:

```json
{ "post_id": 123, "path": "0/0/1/0/1", "attribute": "url", "source": "voyager/site-data", "args": { "key": "phone_link" } }
```

The attribute must be bindable for that block, and `args` must exactly match a field `get-catalog` lists for the post's type. Invented keys are refused with `voyager_open_blocks_invalid_args`. Paths come from `insert` or `get-post-blocks`.

`get-catalog` also lists every key of this source with its args. Use it where the Wesper manifest cannot.

## Try it

`blueprints/site-data.json`.
