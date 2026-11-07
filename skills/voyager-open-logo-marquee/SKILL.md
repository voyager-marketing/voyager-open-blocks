---
name: voyager-open-logo-marquee
description: Insert or customize the voyager-open/logo-marquee pattern, an eyebrow over a row of logos that drifts sideways when it is wider than the screen. Use for a client, partner or certification logo strip. Ships placeholder logos that must be replaced with the site's own media.
license: GPL-2.0-or-later
---

# Logo marquee (`voyager-open/logo-marquee`)

## When to use

- A strip of client, partner, supplier or certification logos.
- Only logos the site owner has the right to show. Do not add a brand's logo because the business mentions it.

## Tell your agent

> Add the Voyager Open Blocks logo marquee to my home page and replace the placeholder logos with these images from my media library: [image names].

Route 2 does all of it. Route 1 places the marquee and its eyebrow, and the user swaps the images in the editor (see Abilities).

## Structure

```
core/group (section, full width, constrained)          "Logo marquee"
  core/heading h2  small, uppercase, centered          "Eyebrow"
  core/group  className is-style-voyager-open-marquee  "Marquee"
    core/group  flex, nowrap                           "Logos"
      core/image x6  height 40px, contain, empty alt
```

The eyebrow is a level 2 heading at the small font size, so heading navigation reaches the section. Keep it a heading when you change its text.

`is-style-voyager-open-marquee` is a `core/group` block style registered by the pack. When the inner row is wider than the screen, it drifts to the end and back. It is CSS only, with no script. It pauses on hover and on keyboard focus. Under reduced motion it stops and wraps. The speed is the `--voyager-open-marquee-duration` custom property (default `24s`).

The shipped images are grey placeholder SVGs in the plugin's `assets/placeholders/`. In `patterns/logo-marquee.html` their URLs begin with `/voyager-open-blocks-assets/`, a token the plugin swaps for its real assets URL at registration. They carry an empty `alt`, so a screen reader skips them instead of reading the same phrase six times.

## Customizing

On route 2, replace each `core/image` in the intent with the site's own media. Upload first, or find the existing attachment, then use its URL and ID:

```json
{ "block": "core/image", "url": "https://example.com/wp-content/uploads/acme.svg", "alt": "Acme Corp", "attrs": { "id": 123, "height": "40px", "scale": "contain" } }
```

- When a real logo goes in, set its `alt` to the company's name. Only a placeholder keeps the empty `alt`. Do not use "logo" on its own.
- Keep every image the same height so the row reads evenly.
- Five to ten logos. With fewer, the row fits the screen and does not move. That is fine.

Then `npx block-runner assemble intent.json --json`. To turn attachment IDs into real media items during assembly, pass Block Runner's media resolver (`--resolver rest` with credentials, or `wpcli`).

## Bindings

None. Logos are media, not site data.

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/logo-marquee` is in `patterns.items` and `voyager-open-marquee` is in the `core/group` block's `styles`.
2. **Author.** Assemble with real media.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`. Do not save markup that fails.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

- **insert**: `{"post_id": 123, "item": "voyager-open/logo-marquee"}`. Read `get-post-blocks` first to pick `position` among the top-level blocks. The response returns the inserted `markup`, plus each block's `path` and `label` (`metadata.name`).
- **insert with copy**: `{"post_id": 123, "item": "voyager-open/logo-marquee", "copy": {"Eyebrow": "Trusted by teams across the county"}}` replaces the eyebrow heading, the only text in this pattern. Images are not copy, and they have no labels, so `omit` cannot remove one either. Plain text only, escaped on the way in. Add `"dry_run": true` first to check without saving.
- **The logos:** no ability changes an image. `configure` only changes blocks that save no HTML, and `core/image` saves HTML. After the insert, tell the user to replace each placeholder in the editor: select the image, choose Replace, pick the logo from the Media Library, and set its alternative text to the company's name. List which image goes where, and ask the user to delete any placeholder left over. Until then the page shows grey placeholder logos, so say so before you save to a published page.
- **validate** with `{"post_id": 123}` checks the saved page.

On route 2, also pipe the returned `markup` through `npx block-runner validate --strict --json --context site.context.json`.

`get-catalog` lists this pattern's named blocks before you insert it.

## Try it

`blueprints/logo-marquee.json`.
