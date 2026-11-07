---
name: voyager-open-icon
description: Place and configure the voyager-open/icon block, an outline SVG icon from a fixed set of 35 with an optional link and a CSS-only animation. Use when a section needs a small pictogram (a feature row, a contact line, a social link) and the site runs the Voyager Open Blocks pack. Covers attributes, accessible labels and binding the label or link to site data.
license: GPL-2.0-or-later
---

# Icon (`voyager-open/icon`)

A dynamic block. The saved markup is the comment delimiter with attributes and nothing else. `render.php` draws the SVG, so there is no saved HTML to go stale or invalid.

```html
<!-- wp:voyager-open/icon {"icon":"phone","size":32} /-->
```

## When to use

- A pictogram beside a heading or a line of contact details.
- A linked icon, such as a social profile.
- Not for logos or photos. Use `core/image`.

## Tell your agent

> Add a Voyager Open Blocks phone icon next to the call button in my header, linked to my Site Data phone number.

A header is a template part, and "next to the call button" is inside it. Route 2 can author that. Route 1 cannot nest a block (see Abilities).

## Attributes

| Attribute | Type | Default | Notes |
|---|---|---|---|
| `icon` | string, enum | `star` | One of the 35 names. Read the list from the manifest: `blocks.types[name=voyager-open/icon].attributes.icon.enum`. WordPress drops a value outside the enum, so an unknown name renders the default star. |
| `size` | number | `48` | Pixels, clamped to 8 to 512. |
| `label` | string | `""` | Accessible name. Empty means decorative (`aria-hidden`). Set it whenever the icon carries meaning or is a link. |
| `linkUrl` | string | `""` | Wraps the icon in a link. A link with no label falls back to the icon's name as its label. |
| `linkTarget` | boolean | `false` | Opens the link in a new tab. |
| `animation` | string, enum | `""` | `bounce`, `pulse`, `spin`, `float`, `wiggle`, `fade-in`, `zoom-in`. CSS only. Off under reduced motion. |
| `animationTrigger` | string, enum | `hover` | `hover`, `scroll` (plays as the icon enters the viewport where the browser supports scroll-driven animation, once on load elsewhere), `always`. |

Supports: text and background colour, margin, padding, anchor, and `align` (`left`, `center`, `right`). Colour comes from the text colour, because the icon strokes with `currentColor`.

Icon names: arrow-down, arrow-left, arrow-right, arrow-up, bolt, building-office, chart-bar, chat-bubble, check-circle, chevron-down, chevron-right, clock, cog, cursor-arrow-click, document, envelope, exclamation-triangle, globe, heart, home, information-circle, light-bulb, link, map-pin, phone, photo, play-circle, rocket-launch, shield-check, sparkles, star, users, video-camera, wrench, x-circle.

## Binding

`label` and `linkUrl` are bindable (`bindings.supportedAttributes["voyager-open/icon"]`).

A social icon that follows the site's X profile:

```html
<!-- wp:voyager-open/icon {"icon":"globe","size":32,"label":"Follow us","linkUrl":"#","metadata":{"bindings":{"linkUrl":{"source":"voyager/site-data","args":{"key":"social_x"}}}}} /-->
```

A click-to-call icon:

```html
<!-- wp:voyager-open/icon {"icon":"phone","size":32,"label":"Call us","metadata":{"bindings":{"linkUrl":{"source":"voyager/site-data","args":{"key":"phone_link"}}}}} /-->
```

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/icon` is in `blocks.types`, and pick `icon` from its enum.
2. **Author.** Block Runner cannot assemble a custom block, so write the self-closing comment above with attributes only. Put it inside native blocks that you assemble around it. For a header, edit the header template part's markup and place the icon beside the button. Inside a `core/buttons` block only buttons belong, so put the icon in the row that holds the buttons.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`. With the manifest, Block Runner knows the block is registered and checks everything around it. It does not check the icon's attribute values, so take them from the enum, or run the markup through the `validate` ability, which does.
4. **Bind.** Only `label` and `linkUrl`, with the args shown in the source's skill.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

- **insert**: `{"post_id": 123, "item": "voyager-open/icon", "attributes": {"icon": "phone", "size": 32, "label": "Call us"}}`. The attributes are validated against block.json, so an icon outside the enum is refused. `insert` places the icon at the top level of the post only, at `position`. It cannot put the icon inside a group, a row or a header next to a button. For that, tell the user to add the icon in the editor, or use route 2.
- **configure**: `{"post_id": 123, "path": "4", "attributes": {"animation": "pulse", "animationTrigger": "always"}}`. A `null` value resets an attribute. This works because the icon saves no HTML. `configure` refuses blocks that do.
- **bind**: `{"post_id": 123, "path": "4", "attribute": "linkUrl", "source": "voyager/site-data", "args": {"key": "phone_link"}}`. Only `label` and `linkUrl` are bindable, and args must match a field from `get-catalog` exactly.
- **get-post-blocks** proves the link: the `linkUrl` binding should read `resolution.status: "resolved"` with a `tel:` link in `value_preview`. `fell_back` with `reason: "empty_value"` means Settings > Site Data > Phone number is empty.
- An icon already in a header can be configured and bound when the header has a post ID: it was saved in the Site Editor (over REST, `wp_id` in `GET /wp-json/wp/v2/template-parts`). Read its paths with `get-post-blocks` on that ID.

Paths come from the insert response or `get-post-blocks`.

## Try it

`blueprints/icon.json` opens a Playground page with decorative, animated, linked and bound icons.
