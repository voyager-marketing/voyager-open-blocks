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

## Tell your agent

> Bind the phone number in my footer to Voyager Open Blocks Site Data so it follows Settings > Site Data.

## Keys

Args are always `{ "key": "<key>" }`. Wesper lists this source under `bindings.sources` but cannot list its keys. `get-catalog` lists them, and this table is the contract.

| Key | Value | Filled in at | Typical attribute |
|---|---|---|---|
| `company` | Site title | Settings > General > Site Title | `content`, `label` |
| `tagline` | Site tagline | Settings > General > Tagline | `content` |
| `phone` | Phone as entered | Settings > Site Data > Phone number | `content`, button `text` |
| `phone_link` | `tel:` plus the digits and `+` of the phone | Settings > Site Data > Phone number | button `url`, icon `linkUrl` |
| `email` | Email address | Settings > Site Data > Email address | `content` |
| `email_link` | `mailto:` plus the email, when it is a valid address | Settings > Site Data > Email address | button `url`, icon `linkUrl` |
| `address` | Street address | Settings > Site Data > Street address | `content` |
| `address_city`, `address_state`, `address_zip` | Address parts | Settings > Site Data > City, State or region, Postal code | `content` |
| `address_locality` | "City, ST 12345", with no stray commas when a part is empty | Settings > Site Data > City, State or region, Postal code | `content` |
| `hours` | Business hours | Settings > Site Data > Business hours | `content` |
| `year` | Current year | Always set | `content` |
| `copyright` | "© 2026 Company" | Settings > General > Site Title | `content` |
| `social_facebook`, `social_instagram`, `social_linkedin`, `social_x` | Profile URLs | Settings > Site Data > Facebook URL, Instagram URL, LinkedIn URL, X URL | button `url`, icon `linkUrl` |

When a binding falls back with `reason: "empty_value"`, the "Filled in at" column names the setting to fill. Tell the user that setting, not the key.

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
- In the block editor, a block bound to this source shows the field and its value in the sidebar, and warns when the value is empty. Tell the site owner which fields are empty instead of typing values into the block.
- Values come back raw. WordPress escapes them where they land (`wp_kses_post` for rich text, the HTML API for attributes).
- `voyager_site_data_value` filters every resolved value.
- The voyager-blocks plugin registers the same source with the same option names. When it is active, it owns the source and this pack stands aside. voyager-blocks has `phone_link` and `email_link` from 2.28.6. An older one lacks them.
- `get-catalog` lists the fields of whichever plugin owns the source, read from that plugin's `voyager_binding_sources` definition, with the owner's labels and args. On a voyager-blocks site that is its key list, not the table above, so check the catalog before binding a key. When the owner does not describe its fields, `fields` is `null` and `bind` refuses the source.

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Check that `bindings.sources` has `voyager/site-data`. Check that `bindings.supportedAttributes` allows the attribute on your block.
2. **Author.** Add the binding in the intent's `attrs.metadata.bindings` and assemble.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`. Do not save markup that fails.
4. **Check the data.** If a value is empty, the placeholder shows. Tell the user which settings to fill in rather than inventing a phone number or address.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

**bind** writes this source into a block's `metadata.bindings`:

```json
{ "post_id": 123, "path": "0/0/1/0/1", "attribute": "url", "source": "voyager/site-data", "args": { "key": "phone_link" } }
```

The attribute must be bindable for that block, and `args` must exactly match a field `get-catalog` lists for the post's type. Invented keys are refused with `voyager_open_blocks_invalid_args`. Paths come from `insert` or `get-post-blocks`.

`get-catalog` also lists every key of this source with its args. Use it where the Wesper manifest cannot.

**A phone number in the footer.** The footer is a template part, so `bind` needs that part's post ID.

1. Find it with `find-posts`: `{"post_type": "wp_template_part", "search": "footer"}`, and take `id` from the row whose `area` is `footer`. A template part has a post ID only once it has been saved in the Site Editor, so `id` is `null` for one that is still the theme's file. Then tell the user to save the footer once in the Site Editor, or to bind it there.
2. Call `get-post-blocks` with that ID and find the paragraph that shows the number: its `text` holds it.
3. If the paragraph holds only the number, bind its `content` to `{"key": "phone"}`. If the number sits in a longer line, such as "Call us at 555-0142", binding would replace the whole line. Tell the user to move the number into its own paragraph first.
4. If the number is also a link, bind that block's `url` (a button) or `linkUrl` (an icon) to `{"key": "phone_link"}`. A link inside a paragraph's text cannot be bound on its own.
5. Call `get-post-blocks` again and read the binding's `resolution`.

**get-post-blocks** says whether each binding returns a value now. Every entry in a block's `bindings` carries `resolution`:

```json
{ "source": "voyager/site-data", "args": { "key": "phone" }, "resolution": { "status": "resolved", "reason": null, "value_preview": "555-0142" } }
```

`status` is `resolved` when the source returned a value, or `fell_back` when it returned nothing and the block keeps its authored text. `reason` is `null` when resolved, else `source_unregistered` (no plugin registers the source), `unknown_key` (the key is not a listed field) or `empty_value` (a real key with nothing set: tell the user which setting to fill in, from the Keys table). `value_preview` is the first 80 characters of the value. Read it after `bind` to prove the binding works instead of assuming it.

## Try it

`blueprints/site-data.json`.
