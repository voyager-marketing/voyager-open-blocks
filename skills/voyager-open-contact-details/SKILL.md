---
name: voyager-open-contact-details
description: Place and bind the voyager-open/contact-details block, one address with the street, the city line, the phone as a tel: link and the email as a mailto: link, then the hours, so a visitor on a phone can tap the number to call and the address to write. Use when a page shows the business's contact details and the site runs the Voyager Open Blocks pack. Covers its seven attributes, binding them to Site Data, the fallback values and the stale_fallback warning.
license: GPL-2.0-or-later
---

# Contact details (`voyager-open/contact-details`)

A dynamic block that shows a business's contact details. Its seven attributes hold the street, the city line, the phone and its link, the email and its link, and the hours. `render.php` prints one `address` with the street, the city line, the phone as a link and the email as a link, then the hours as a paragraph after it. The saved markup is the comment delimiter with attributes and nothing else.

```html
<!-- wp:voyager-open/contact-details {"phone":"Phone number","email":"Email address","metadata":{"bindings":{"phone":{"source":"voyager/site-data","args":{"key":"phone"}},"phoneLink":{"source":"voyager/site-data","args":{"key":"phone_link"}},"email":{"source":"voyager/site-data","args":{"key":"email"}},"emailLink":{"source":"voyager/site-data","args":{"key":"email_link"}}}}} /-->
```

A binding fills a paragraph's whole text, so it cannot put a tel: link around the phone number inside it, and a bound paragraph shows the number as text a visitor cannot tap. Bind this block's attributes instead: the phone dials and the email opens a new message.

## When to use

- A contact page, a footer or any section that lists the phone, email, address and hours.
- Wherever a visitor on a phone should be able to tap the number or the email.
- Not for a phone number inside a sentence. Use a paragraph, and a button bound to `phone_link` for the call.

## Tell your agent

> Add a Voyager Open Blocks contact details block to my Contact page, bound to my Site Data phone, email, address and hours. Tell me which of those fields are still empty.

## Attributes

| Attribute | Type | Default | Shows |
|---|---|---|---|
| `address` | string | `""` | The street line, inside the `address`. |
| `addressLocality` | string | `""` | The city line, inside the `address`. |
| `phone` | string | `""` | The phone number, inside the `address`. A link when `phoneLink` is set, else text. |
| `phoneLink` | string | `""` | Where the number goes when tapped, such as `tel:5550100100`. Shows nothing on its own. |
| `email` | string | `""` | The email address, inside the `address`. A link when `emailLink` is set, else text. |
| `emailLink` | string | `""` | Where the address goes when tapped, such as `mailto:hello@example.com`. Shows nothing on its own. |
| `hours` | string | `""` | The hours, as a paragraph after the `address`, since hours are not contact information. |

- A part whose text is empty prints nothing, its link included. With no text in any part, the block prints nothing.
- Plain text: HTML in a value is shown as text, not rendered. Each value is decoded once and escaped once, so a value that arrives escaped, as the voyager-blocks plugin returns Site Data, prints the same as one that arrives as typed.
- A link goes through `esc_url()`, which keeps `tel:`, `mailto:` and `sms:` and drops a scheme WordPress does not allow, such as `javascript:`. A dropped link leaves the text alone.
- Each link is alone on its line and at least 44px tall and wide, so it is easy to tap. The block's stylesheet sets that and the line layout, and nothing else: colour and type come from the theme and the block's supports.

Supports: text and background colour, font size, line height, margin, padding and anchor.

## Binding

Every attribute is bindable (`bindings.supportedAttributes["voyager-open/contact-details"]`). Bind each to its Site Data key:

| Attribute | Key | Filled in at |
|---|---|---|
| `address` | `address` | Settings > Site Data > Street address |
| `addressLocality` | `address_locality` | Settings > Site Data > City, State or region, Postal code |
| `phone` | `phone` | Settings > Site Data > Phone number |
| `phoneLink` | `phone_link` | Settings > Site Data > Phone number: the digits and `+`, as `tel:` |
| `email` | `email` | Settings > Site Data > Email address |
| `emailLink` | `email_link` | Settings > Site Data > Email address, once it is a valid address, as `mailto:` |
| `hours` | `hours` | Settings > Site Data > Business hours |

- **Bind the link with its text.** `phone` without `phoneLink` shows the number as text that does not dial.
- **Keep labels, never numbers, in the block's own values.** When a field is empty, the source returns nothing and WordPress shows the block's own value, so visitors see it. Write labels that read as labels, such as "Phone number" and "Email address", or leave them empty. A phone number or an email address there is a stale copy waiting to show: `validate` warns `stale_fallback:` on it, and `configure` with `null` for that attribute removes it.
- Leave out a part the business does not show: leave both its binding and its own value empty, and the line does not print.
- Pages that show the block are in `get-catalog`'s `used_on` under each key it binds, such as `voyager/site-data:phone`, and a change to the field refreshes them, as for any Site Data field.

## The loop

This is route 2, with a shell and Node. In the Wesper manifest, confirm `voyager-open/contact-details` in `blocks.types` and `voyager/site-data` in `bindings.sources`. Block Runner cannot assemble a custom block, so write the self-closing comment above with attributes only, inside native blocks you assemble around it. Check it with `npx block-runner validate`, then bind each attribute. The steps are in [docs/agents.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/agents.md).

## Abilities

This is route 1: the abilities, over MCP or REST, with no markup to write.

- **insert**: `{"post_id": 123, "item": "voyager-open/contact-details"}`. Add `"attributes": {"phone": "Phone number", "email": "Email address"}` for labels that show while a field is empty. With `parent`, the path of a group, it goes inside that block, at `position`.
- **bind**: once per attribute, such as `{"post_id": 123, "path": "4", "attribute": "phoneLink", "source": "voyager/site-data", "args": {"key": "phone_link"}}`.
- **configure**: `{"post_id": 123, "path": "4", "attributes": {"hours": "By appointment"}}` changes a part that is not bound. A bound part is refused with `voyager_open_blocks_bound_slot`, because its value comes from the binding: change it in Settings > Site Data instead. `null` resets a part, bound or not.
- **get-post-blocks**: a binding that falls back names an empty field. Tell the user which one, from the table above.
- **validate** warns `stale_fallback:` on a bound part whose own value looks like a phone number (`phone`, `phoneLink`) or an email address (`email`, `emailLink`).

## In the editor

Each part is a text field under Contact details, on the block settings' Content tab, and the canvas shows the block as visitors see it. When a part is bound and its field has a value, the field shows that value and cannot be edited there. When the field is empty, the text field edits the block's own value, which visitors then see.

## Try it

`blueprints/contact-details.json` opens a Playground page with the block bound to Site Data and a block with details of its own.
