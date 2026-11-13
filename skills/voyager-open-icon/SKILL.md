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

A header is a template part, and "next to the call button" is inside it. Both routes can do it. On route 1, `find-posts` finds the header and `insert` with `parent` puts the icon beside the button (see Abilities).

## Attributes

| Attribute | Type | Default | Notes |
|---|---|---|---|
| `icon` | string, enum | `star` | One of the 35 names. Read the list from the manifest: `blocks.types[name=voyager-open/icon].attributes.icon.enum`. WordPress drops a value outside the enum, so an unknown name renders the default star. |
| `size` | number | `48` | Pixels, clamped to 8 to 512. |
| `label` | string | `""` | Accessible name. Empty means decorative (`aria-hidden`). Set it whenever the icon carries meaning or is a link. |
| `linkUrl` | string | `""` | Wraps the icon in a link. A link with no label falls back to the icon's name as its label. |
| `linkTarget` | boolean | `false` | Opens the link in a new tab. |
| `animation` | string, enum | `""` | `bounce`, `pulse`, `spin`, `float`, `wiggle`, `fade-in`, `zoom-in`. CSS only, loaded only on a page whose icon uses it (see Stylesheets). Off under reduced motion. |
| `animationTrigger` | string, enum | `hover` | `hover`, `scroll` (plays as the icon enters the viewport where the browser supports scroll-driven animation, once on load elsewhere), `always`. |

Supports: text and background colour, margin, padding, anchor, and `align` (`left`, `center`, `right`). Colour comes from the text colour, because the icon strokes with `currentColor`.

Icon names: arrow-down, arrow-left, arrow-right, arrow-up, bolt, building-office, chart-bar, chat-bubble, check-circle, chevron-down, chevron-right, clock, cog, cursor-arrow-click, document, envelope, exclamation-triangle, globe, heart, home, information-circle, light-bulb, link, map-pin, phone, photo, play-circle, rocket-launch, shield-check, sparkles, star, users, video-camera, wrench, x-circle.

## Stylesheets

An icon with no animation loads one small stylesheet. Each animation adds its own, and only where an icon uses it:

| Handle | Holds | Loads |
|---|---|---|
| `voyager-open-icon-style` | Layout, alignment, the link and the SVG box: what every icon needs. Under 1 KB. | Wherever an icon renders. A site that does not load block assets on demand loads it on every page, as it does every block's style. |
| `voyager-open-icon-animation-<name>`, one per animation, such as `voyager-open-icon-animation-pulse` | That animation's keyframes, its three trigger rules, the rule that ties the scroll trigger to the icon's view timeline, and the reduced-motion rule that stops it. Inline CSS, no file. | Only on a page that renders an icon with that animation. A block theme prints it in the head. A classic theme prints it at the footer, and WordPress 6.9 or later moves it into the head when it loads block styles on demand. The editor loads all seven. |

So a page whose icons are all still, such as one holding the services section as shipped, loads no animation CSS. Setting `animation` with `configure` is all it takes for that page to load the one it now needs.

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

This is route 2, with a shell and Node. In the Wesper manifest, confirm `voyager-open/icon` in `blocks.types`, pick `icon` from its enum, and confirm `voyager/site-data` in `bindings.sources`. Block Runner cannot assemble a custom block, so write the self-closing comment above with attributes only, inside native blocks you assemble around it. For a header, edit the header template part's markup and place the icon beside the button. Inside a `core/buttons` block only buttons belong, so put the icon in the row that holds the buttons. Check it with `npx block-runner validate`. It does not check the icon's attribute values, so take them from the enum, or run the markup through the `validate` ability, which does. The steps are in [docs/agents.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/agents.md).

## Abilities

This is route 1: the abilities, over MCP or REST, with no markup to write.

- **insert**: `{"post_id": 123, "item": "voyager-open/icon", "attributes": {"icon": "phone", "size": 32, "label": "Call us"}}`. An icon outside the enum is refused. With `parent`, the path of a group or row, it goes among that block's inner blocks, at `position`.
- **configure**: `{"post_id": 123, "path": "4", "attributes": {"animation": "pulse", "animationTrigger": "always"}}`.
- **bind**: `{"post_id": 123, "path": "4", "attribute": "linkUrl", "source": "voyager/site-data", "args": {"key": "phone_link"}}`. Only `label` and `linkUrl` are bindable.
- **get-post-blocks**: the `linkUrl` binding should resolve to a `tel:` link. If it falls back, Settings > Site Data > Phone number is empty.

### Next to the call button in the header

1. **Find the header** with `find-posts`: the template part row whose `area` is `header`.
2. **Find the button.** `get-post-blocks` with `{"post_id": <id>}`. The call button is the `core/button` whose `text` reads like a call ("Call us", a phone number) or whose `url` binding has args `{"key": "phone_link"}`. Say its path is `0/2/1`.
3. **Pick the parent.** Its parent, `0/2`, is a `core/buttons`, which takes only buttons, so the icon goes beside the buttons block, in the block that holds it: `parent` `"0"`. `position` `2` puts the icon before the buttons, `3` after them.
4. **Insert.** `{"post_id": <id>, "item": "voyager-open/icon", "parent": "0", "position": 2, "attributes": {"icon": "phone", "size": 24, "label": "Call us"}, "dry_run": true}`, then again without `dry_run`. The response's `blocks[0].path` is the icon's path, here `0/2`. A refusal with `voyager_open_blocks_invalid_parent` says why: the parent does not allow the icon, holds no blocks, or is locked.
5. **Bind** that path's `linkUrl` to `voyager/site-data` with the `phone_link` args.
6. **Check** it with `get-post-blocks` and `validate`: the icon's `linkUrl` should resolve to a `tel:` link.
7. **Report** the icon's place and the link's status. On route 2, check the response's `section_markup` with Block Runner.

## Try it

`blueprints/icon.json` opens a Playground page with decorative, animated, linked and bound icons.
