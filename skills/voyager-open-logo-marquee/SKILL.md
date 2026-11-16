---
name: voyager-open-logo-marquee
description: Insert or customize the logo marquee, an eyebrow over the site's client, partner or certification logos, in either of its two layouts. voyager-open/logo-marquee is a row that drifts sideways when it is wider than the screen. voyager-open/logo-marquee-grid holds the same logos still, in a grid three across. Use for a logo strip. Ships placeholder logos that must be replaced with the site's own media.
license: GPL-2.0-or-later
---

# Logo marquee (`voyager-open/logo-marquee`, `voyager-open/logo-marquee-grid`)

The logo marquee is one section in two layouts. Each layout is its own pattern:

| Layout | Pattern | What it is |
|---|---|---|
| `marquee` (the first) | `voyager-open/logo-marquee` | One row of logos that drifts sideways when it is wider than the screen, and stands still when it fits. |
| `grid` | `voyager-open/logo-marquee-grid` | The same logos still, in a grid three across at wide width. It wraps where a logo would get too narrow, and on a phone the logos stack one per line. |

Each intent names its section and layout in its `section` and `layout` keys. The first layout keeps the section's slug; the other is the slug, a hyphen and the layout's name.

## When to use

- A strip of client, partner, supplier or certification logos.
- Only logos the site owner has the right to show. Do not add a brand's logo because the business mentions it.
- **Marquee** for five to ten logos, where one compact row suits the page, a phone included: the row drifts there instead of growing taller.
- **Grid** when the page should not move at all, or for three, six or nine logos, which fill its rows of three. It is still at every width, with motion on or off. On a phone it is one logo per line, so it grows taller with each logo.

## Tell your agent

> Add the Voyager Open Blocks logo marquee to my home page and replace the placeholder logos with these images from my media library: [image names].

For the other layout, say "as a still grid".

Route 2 does all of it. Route 1 places the section and its eyebrow, and the user swaps the images in the editor (see Abilities).

## Structure

```
voyager-open/logo-marquee (marquee)
core/group (section, full width, constrained)          "Logo marquee"
  core/heading h2  small, uppercase, centered          "Eyebrow"
  core/group  className is-style-voyager-open-marquee  "Marquee"
    core/group  flex, nowrap                           "Logos"
      core/image x6  height 40px, contain, empty alt

voyager-open/logo-marquee-grid (grid)
core/group (section, full width, constrained)          "Logo marquee"
  core/heading h2  small, uppercase, centered          "Eyebrow"
  core/group  wide, grid: 3 columns, at least 12rem    "Logos"
    core/image x6  width 100%, height 40px, contain, empty alt
```

The eyebrow is a level 2 heading at the small font size, so heading navigation reaches the section. Keep it a heading when you change its text.

In the marquee layout, `is-style-voyager-open-marquee` is a `core/group` block style registered by the pack. When the inner row is wider than the screen, it loops left at a steady speed with no visible join: when the page renders, the plugin follows the row with a copy of itself, hidden from screen readers and inert, so the saved page still holds each logo once. The copy is only in the page a visitor loads: `content.rendered` over REST, feeds and the editor hold the row once. Edit the one row; never add a second. A row that fits stays still. It is CSS only, with no script. It pauses on hover and on keyboard focus. Under reduced motion the copy is hidden and the row stops and wraps. The time one row takes to pass is the `--voyager-open-marquee-duration` custom property (default `24s`).

The grid layout has no Marquee style and nothing in it moves. Its `core/group` uses the grid layout with `columnCount: 3` and `minimumColumnWidth: 12rem`, and `40` between cells. Keep the gap one value: given one gap for rows and another for columns, WordPress 7.1 writes a `grid-template-columns` the browser drops, and the grid falls back to one column. Each logo is the full width of its cell and 40px tall, and `scale: contain` draws it in the middle, so a wide logo and a square one line up. A fourth or fifth logo starts a new row from the left.

The shipped images are grey placeholder SVGs in the plugin's `assets/placeholders/`, the same six in both layouts. In `patterns/logo-marquee.html` and `patterns/logo-marquee-grid.html` their URLs begin with `/voyager-open-blocks-assets/`, a token the plugin swaps for its real assets URL at registration. They carry an empty `alt`, so a screen reader skips them instead of reading the same phrase six times.

## Copy rules

Each intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text. The eyebrow is the pattern's only text: the logos are images. Both layouts take the same label, rule and default copy, so copy written for one fits the other.

| Label | What goes there | Min | Max | Required |
|---|---|---|---|---|
| `Eyebrow` | The section's only heading, an `h2` at the small size: whose logos these are, such as clients, partners or suppliers. | 10 | 50 | yes |

The eyebrow is not a claim slot, and the shipped text makes no claim. Say whose logos they are, not how many customers the business has ("Trusted by 500 businesses") or what the logos do not show: a count is a claim, and it changes.

## Customizing

On route 2, replace each `core/image` in the intent with the site's own media. Upload first, or find the existing attachment, then use its URL and ID:

```json
{ "block": "core/image", "url": "https://example.com/wp-content/uploads/acme.svg", "alt": "Acme Corp", "attrs": { "id": 123, "height": "40px", "scale": "contain" } }
```

In the grid layout, keep each image's `"width": "100%"` as well, so the logo stays in the middle of its cell.

- When a real logo goes in, set its `alt` to the company's name. Only a placeholder keeps the empty `alt`. Do not use "logo" on its own.
- Keep every image the same height so the row reads evenly.
- Five to ten logos in the marquee. With fewer, the row fits the screen and does not move. That is fine.
- In the grid, three, six or nine logos fill its rows. Any other count leaves the last row short, its logos from the left.

Then `npx block-runner assemble intent.json --json`. To turn attachment IDs into real media items during assembly, pass Block Runner's media resolver (`--resolver rest` with credentials, or `wpcli`).

## Bindings

None. Logos are media, not site data.

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead. Confirm `voyager-open/logo-marquee` and `voyager-open/logo-marquee-grid` are in the Wesper manifest's `patterns.items`, and, for the marquee, that `voyager-open-marquee` is in the `core/group` block's `styles`. Assemble `patterns/intents/logo-marquee.json` or `logo-marquee-grid.json` with real media, then validate it with Block Runner. The steps are in [docs/agents.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/agents.md).

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

- **insert**: `{"post_id": 123, "item": "voyager-open/logo-marquee"}`.
- **insert with copy**: `{"post_id": 123, "item": "voyager-open/logo-marquee", "copy": {"Eyebrow": "Trusted by teams across the county"}}` replaces the eyebrow heading, the only text in this pattern. Use `"item": "voyager-open/logo-marquee-grid"` for the grid, with the same copy. In `compose`, name the section `voyager-open/logo-marquee` and pass `"layout": "grid"`. Images are not copy, and they have no labels, so `omit` cannot remove one either.
- **The logos:** no ability changes an image. `configure` only changes blocks that save no HTML, and `core/image` saves HTML. After the insert, tell the user to replace each placeholder in the editor: select the image, choose Replace, pick the logo from the Media Library, and set its alternative text to the company's name. List which image goes where, and ask the user to delete any placeholder left over. Until then the page shows grey placeholder logos, so say so before you save to a published page.
- Then `validate` the page. In either layout, expect one `placeholder:` warning per logo still in place, six as shipped.

## Try it

`blueprints/logo-marquee.json` opens a page with both layouts.
