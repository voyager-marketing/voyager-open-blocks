---
name: voyager-open-facts
description: Place and bind the voyager-open/facts block, a plain list with one item per line, so a Site Data list (services, service area, credentials) shows on a page as a list and follows Settings > Site Data. Use when a section lists what a business does, where it works or what backs it up, and the site runs the Voyager Open Blocks pack. Covers its one attribute, binding it, the fallback items and the Ticks style.
license: GPL-2.0-or-later
---

# Facts (`voyager-open/facts`)

A dynamic block that shows one list. Its one attribute, `items`, holds one item per line, and `render.php` prints a `ul` with one `li` per line. The saved markup is the comment delimiter with attributes and nothing else.

```html
<!-- wp:voyager-open/facts {"items":"Drain cleaning\nWater heater repair\nLeak detection"} /-->
```

A block binding fills an attribute with one string, so a core list cannot follow the Site Data lists: each list item would need a binding of its own, and the number of items is not known. Bind this block's `items` to the list instead.

## When to use

- A page's services, service area or credentials, kept in Settings > Site Data.
- Any short list of plain facts that should change everywhere when it changes in one place.
- Not for items with links, bold text or nested lists. Use `core/list`. This block prints plain text.

## Tell your agent

> Add a Voyager Open Blocks facts list of my services to my Services page, bound to my Site Data services. Tell me if the services are still empty.

## Attributes

| Attribute | Type | Default | Notes |
|---|---|---|---|
| `items` | string | `""` | One item per line. Each line is trimmed and empty lines are dropped. Plain text: HTML in an item is shown as text, not rendered. With no items, the block prints nothing. |

Supports: text and background colour, font size, line height, margin, padding and anchor.

The **Ticks** block style (`is-style-voyager-open-ticks`) shows the items as a wrapping row of checkmarked facts, the same style the proof strip's `core/list` uses. A section can swap a Ticks `core/list` for a Ticks facts block and look the same. In a flex group narrower than 32em, the items stack. Put it on with `insert` or `configure`: `"attributes": {"className": "is-style-voyager-open-ticks"}`.

## Binding

`items` is bindable (`bindings.supportedAttributes["voyager-open/facts"]`). Bind it to one of the three Site Data lists:

| Key | Shows | Filled in at |
|---|---|---|
| `services` | One service per line | Settings > Site Data > Business profile > Services |
| `service_area` | One town or area per line | Settings > Site Data > Business profile > Service area |
| `credentials` | One license, certification or membership per line | Settings > Site Data > Business profile > Credentials |

```html
<!-- wp:voyager-open/facts {"items":"Your first service\nYour second service","metadata":{"bindings":{"items":{"source":"voyager/site-data","args":{"key":"services"}}}}} /-->
```

- **Keep placeholder items in `items`.** When the field is empty, the source returns nothing and WordPress keeps the block's own items, so visitors see those. Write placeholders that read as placeholders, such as "Your first service". Never type a credential the owner has not given you (see `voyager-site-data`, "Never state a business profile fact the site does not hold").
- A text key, such as `phone`, binds too, and shows as a one-item list. Bind text keys to core blocks instead.
- An ampersand prints once whichever plugin owns `voyager/site-data`. This pack returns values as typed. The voyager-blocks plugin returns them escaped. The block decodes each item once and escapes it once, so "Heating & cooling" reads the same on both.
- Pages that show a list are in `get-catalog`'s `used_on` under `voyager/site-data:services` (and the other two keys), and a change to the list refreshes them, as for any Site Data field.

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/facts` is in `blocks.types` and `bindings.supportedAttributes["voyager-open/facts"]` lists `items`.
2. **Author.** Block Runner cannot assemble a custom block, so write the self-closing comment above with attributes only, inside native blocks you assemble around it. In the comment's JSON, a line break between items is `\n`.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`.
4. **Bind.** `items` only, with the args from `get-catalog`.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

- **insert**: `{"post_id": 123, "item": "voyager-open/facts", "attributes": {"items": "Your first service\nYour second service"}}`. In the JSON input too, the line break is `\n`. With `parent`, the path of a group, it goes inside that block, at `position`.
- **bind**: `{"post_id": 123, "path": "4", "attribute": "items", "source": "voyager/site-data", "args": {"key": "services"}}`. Copy `args` from `get-catalog`.
- **configure**: `{"post_id": 123, "path": "4", "attributes": {"items": "Free estimates\nWeekend appointments"}}` changes the items of a block that is not bound. A bound `items` is refused with `voyager_open_blocks_bound_slot`, because its value comes from the binding: change the list in Settings > Site Data instead.
- **get-post-blocks** proves the binding: `items` should read `resolution.status: "resolved"`, with the start of the list in `value_preview`. `fell_back` with `reason: "empty_value"` means the list is empty in Settings > Site Data: tell the user which one, from the table above.

Paths come from the insert response or `get-post-blocks`.

## In the editor

The items are edited in a textarea under Items, on the block settings' Content tab, one per line, and the canvas shows them as a list. When `items` is bound and the field has a value, the textarea shows that value and cannot be edited there. When the field is empty, the textarea edits the block's own items, which visitors then see.

## Try it

`blueprints/facts.json` opens a Playground page with the services, service area and credentials as lists, one in the Ticks style, and a list with items of its own.
