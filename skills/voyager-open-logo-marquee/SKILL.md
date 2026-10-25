---
name: voyager-open-logo-marquee
description: Insert or customize the voyager-open/logo-marquee pattern, an eyebrow over a row of logos that drifts sideways when it is wider than the screen. Use for a client, partner or certification logo strip. Ships placeholder logos that must be replaced with the site's own media.
license: GPL-2.0-or-later
---

# Logo marquee (`voyager-open/logo-marquee`)

## When to use

- A strip of client, partner, supplier or certification logos.
- Only logos the site owner has the right to show. Do not add a brand's logo because the business mentions it.

## Structure

```
core/group (section, full width, constrained)          "Logo marquee"
  core/paragraph  small, uppercase, centered           "Eyebrow"
  core/group  className is-style-voyager-open-marquee  "Marquee"
    core/group  flex, nowrap                           "Logos"
      core/image x6  height 40px, contain
```

`is-style-voyager-open-marquee` is a `core/group` block style registered by the pack. When the inner row is wider than the screen, it drifts to the end and back. It is CSS only, with no script. It pauses on hover and on keyboard focus. Under reduced motion it stops and wraps. The speed is the `--voyager-open-marquee-duration` custom property (default `24s`).

The shipped images are grey placeholder SVGs in the plugin's `assets/placeholders/`. In `patterns/logo-marquee.html` their URLs begin with `/voyager-open-blocks-assets/`, a token the plugin swaps for its real assets URL at registration.

## Customizing

Replace each `core/image` with the site's own media. Upload first, then use the attachment's URL and ID:

```json
{ "block": "core/image", "url": "https://example.com/wp-content/uploads/acme.svg", "alt": "Acme Corp", "attrs": { "id": 123, "height": "40px", "scale": "contain" } }
```

- `alt` is the company name. Do not use "logo" on its own.
- Keep every image the same height so the row reads evenly.
- Five to ten logos. With fewer, the row fits the screen and does not move. That is fine.

Then `npx block-runner assemble intent.json --json`. To turn attachment IDs into real media items during assembly, pass Block Runner's media resolver (`--resolver rest` with credentials, or `wpcli`).

## Bindings

None. Logos are media, not site data.

## The loop

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/logo-marquee` is in `patterns.items` and `voyager-open-marquee` is in the `core/group` block's `styles`.
2. **Author.** Assemble with real media.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`.

## Abilities

With the plugin active, an agent places this pattern through the `voyager-open-blocks` abilities (WordPress Abilities API, `wp-abilities/v1`, also exposed to MCP) without writing markup:

- **insert**: `{"post_id": 123, "item": "voyager-open/logo-marquee"}`. Add `"position": 0` to put it first, or `"mode": "reference"` to insert a `core/pattern` that tracks plugin updates instead of a copy. The response returns the inserted `markup`, plus each block's `path` and `label` (`metadata.name`).
- **insert with copy**: `{"post_id": 123, "item": "voyager-open/logo-marquee", "copy": {"Eyebrow": "Trusted by teams across the county"}}` replaces the eyebrow, the only text in this pattern. Images are not copy. Plain text only, escaped on the way in. Copy mode only. An unknown label, or a label on a block that is not text, is refused with `voyager_open_blocks_invalid_copy`, which lists the valid labels.
- Replacing the placeholder logos is media work. Upload the images, then edit the inserted blocks in the editor, or re-author the section with `block-runner assemble`. `configure` only changes blocks that save no HTML, and `core/image` saves HTML.
- Pipe the returned `markup` through `npx block-runner validate --strict --json`. WordPress cannot check saved HTML from PHP.

`get-catalog` lists this pattern's named blocks and their bindings before you insert it.

## Try it

`blueprints/logo-marquee.json`.
