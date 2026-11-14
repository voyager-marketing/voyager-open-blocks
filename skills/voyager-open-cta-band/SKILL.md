---
name: voyager-open-cta-band
description: Insert or customize the CTA band, a full-width band that asks the reader to act mid-page, in either of its two layouts. voyager-open/cta-band puts an eyebrow, heading and one line of copy beside a primary button and a click-to-call button bound to voyager/site-data. voyager-open/cta-band-cover puts the same row over a background image on a core/cover. Use mid-page or after a services section when the reader should be asked to act.
license: GPL-2.0-or-later
---

# CTA band (`voyager-open/cta-band`, `voyager-open/cta-band-cover`)

The CTA band is one section in two layouts. Each layout is its own pattern:

| Layout | Pattern | What it is |
|---|---|---|
| `row` (the first) | `voyager-open/cta-band` | The text beside the buttons, on the section's own background. |
| `cover` | `voyager-open/cta-band-cover` | The same row over a background image, on a dark overlay. |

Each intent names its section and layout in its `section` and `layout` keys. The first layout keeps the section's slug; the other is the slug, a hyphen and the layout's name.

## When to use

- Mid-page, after the reader has seen what is offered.
- Not as the last section of a page. That is `voyager-open/closing-cta`.
- **Row** on most pages. It is the plain band, coloured by its section style.
- **Cover** when the site has a photo that sets the scene, such as the work in progress or the team on a job, and the page has no other cover. Its overlay shows only a trace of the image, so do not choose it to show the photo off, and do not put it on a page that opens with the hero's cover: two covers read as one repeated band.

## Tell your agent

> Add the Voyager Open Blocks CTA band near the end of my Services page. Write the heading and the line of copy for that page, keep the click-to-call button bound to my Site Data phone number, and check the blocks before you save.

For the other layout, say "over a background image".

## Structure

```
voyager-open/cta-band (row)
core/group (section, full width, constrained)          "CTA band"
  core/group (wide, flex, wrap, space-between, centered) "Row"
    core/group                                         "Text"
      core/paragraph  small, uppercase                 "Eyebrow"
      core/heading h2                                  "Heading"
      core/paragraph                                   "Body"
    core/buttons
      core/button  "Contact us" -> /contact/           "Primary button"
      core/button  outline                             "Click to call"
          text <- voyager/site-data { key: phone }
          url  <- voyager/site-data { key: phone_link }

voyager-open/cta-band-cover (cover)
core/group (section, full width, constrained)          "CTA band"
  core/cover (wide, overlay contrast at 90%, text base) "Background"
    core/group (wide, flex, as above)                  "Row"
      core/group                                       "Text"
        core/paragraph  small, uppercase               "Eyebrow"
        core/heading h2, text base                     "Heading"
        core/paragraph                                 "Body"
      core/buttons
        core/button  "Contact us" -> /contact/         "Primary button"
            base fill, contrast text
        core/button  outline, text base                "Click to call"
            text <- voyager/site-data { key: phone }
            url  <- voyager/site-data { key: phone_link }
```

When the text and the buttons fit on one row, the buttons sit at the right. When they do not, the row wraps and the buttons start at the left edge under the text. That follows the content, not a breakpoint, so it holds on any theme. Do not give the Buttons a `justifyContent`: it applies at every width, so wrapped buttons would stay pushed right.

The intent trees are `patterns/intents/cta-band.json` and `patterns/intents/cta-band-cover.json` (`intent` key). The markup is `patterns/cta-band.html` and `patterns/cta-band-cover.html`.

**The cover's colours** are the hero cover's. The overlay is the `contrast` preset at 90% and the text is `base`: on Twenty Twenty-Five, Twenty Twenty-Four and Ipsum, those are the theme's own text and background, and the text reads at 4.5:1 or more over any image there, which the design check measures with the overlay over black and over white. Twenty Twenty-Five's Afternoon style variation is the exception, as on the hero: its two colours read 5.29:1 even with nothing showing through, so at 90% they fall to 4.13:1 over a white image. The primary button is `base` with `contrast` text. A section style changes only the band around the cover; the row on it keeps its own colours. On a theme with no `contrast` or `base` preset, set the overlay and the text colour in the editor.

## Copy rules

Each intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text. Both layouts take the same labels, rules and default copy, so copy written for one fits the other.

| Label | What goes there | Min | Max | Required |
|---|---|---|---|---|
| `Eyebrow` | A few words that lead into the heading, such as what the reader does next. Leave it out when the heading says it already. | 2 | 40 | no |
| `Heading` | The band's `h2`: one short question or invitation that asks the reader to act. | 10 | 60 | yes |
| `Body` | One line on what happens when the reader acts. It shares a wide row with the buttons, so keep it short. | 20 | 100 | yes |
| `Primary button` | The band's main action, as what happens when it is pressed, such as "Get a quote". | 2 | 24 | yes |
| `Click to call` | Bound to the Site Data phone number. Its own text shows only while the number is empty. | 2 | 24 | yes |

No slot is a claim slot, and the shipped copy makes no claim. Do not put a licence, a rating, a guarantee or a number of years in the band unless the site owner gave it to you. Those belong in the proof strip.

## The image slot

The cover layout ships one image slot, the core/cover "Background", with the pack's 16:9 placeholder, so `validate` warns `placeholder:` once on a page that holds it as shipped. The row layout has none.

- The image fills the cover behind the overlay. After you replace it, set the focal point on the subject (the cover's Media settings, Focal point picker), so the crop keeps the subject in view at every width. Leave its alt text empty: a cover's background is decoration.
- Keep the cover's overlay colour and opacity. A lower opacity can leave the text unreadable over a light or a dark part of the photo.

No ability changes an image. Tell the user to replace the placeholder in the editor: select the cover, choose Replace, pick the photo from the Media Library, then set the focal point. Until then the band shows the grey placeholder under its overlay, so say so before you save to a published page.

## Customizing

- **As shipped:** `<!-- wp:pattern {"slug":"voyager-open/cta-band"} /-->` (or `voyager-open/cta-band-cover`), or `insert` with `"mode": "reference"`. It renders the current pattern and picks up plugin updates, but its copy cannot change.
- **Edited, route 1:** `insert` with `copy` (see Abilities). Keep the call button's bindings. Its authored "Call us" and `/contact/` are what visitors see until the phone number is set.
- **Edited, route 2:** copy the intent, change the eyebrow, heading, line and the primary button's `text` and `url`, and on the cover the background's `url`, then `npx block-runner assemble intent.json --json`.
- Point the primary button at a page that exists. On route 2, check the manifest or the site before using `/contact/`. On route 1, the button keeps `/contact/`: no ability changes a static button's link. If the site has no `/contact/` page, tell the user to change the link in the editor.

## Bindings

| Block | Attribute | Source | Args |
|---|---|---|---|
| Click to call button | `text` | `voyager/site-data` | `{ "key": "phone" }` |
| Click to call button | `url` | `voyager/site-data` | `{ "key": "phone_link" }` |

## The loop

This is route 2, with a shell and Node. In the Wesper manifest, confirm `voyager-open/cta-band` and `voyager-open/cta-band-cover` in `patterns.items` and `voyager/site-data` in `bindings.sources`. Insert one by reference, or assemble an edited intent, then check it with `npx block-runner validate`. The steps are in [docs/agents.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/agents.md).

## Abilities

This is route 1: the abilities, over MCP or REST, with no markup to write. Run them in this order.

1. **get-catalog.** Confirm both patterns are in `patterns`, and that the `voyager/site-data` source lists a field with args `{"key": "phone"}` and one with `{"key": "phone_link"}`.
2. **Where.** Find the page with `find-posts`, say 123. To place the band before the last section, count the top-level blocks in `get-post-blocks` and pass that count minus one as `position`. On an empty page, leave `position` out.
3. **insert with dry_run.** Write the eyebrow, heading and line for the page:

   ```json
   {"post_id": 123, "item": "voyager-open/cta-band", "position": 4, "dry_run": true, "copy": {"Eyebrow": "Free estimates", "Heading": "Need a plumber today?", "Body": "Call before noon for a same-day visit.", "Primary button": "Book a visit"}}
   ```

   Then save without `dry_run`. Use `"item": "voyager-open/cta-band-cover"` for the cover layout, with the same copy. In `compose`, name the section `voyager-open/cta-band` and pass `"layout": "cover"`. To drop the eyebrow, pass `"omit": ["Eyebrow"]` instead of copy for it.
4. **Keep the call button bound.** It arrives bound: `text` to `{"key": "phone"}` and `url` to `{"key": "phone_link"}`. Never pass copy for `Click to call`, a bound slot: `insert` refuses the whole call with `voyager_open_blocks_bound_slot` and saves nothing, so change the number in Settings > Site Data instead. Do not `bind` it again unless you want a different field. To re-point it, use the path labelled "Click to call" in `get-post-blocks`: `4/0/1/1` in the row layout, `4/0/0/1/1` in the cover, one level deeper.

   ```json
   {"post_id": 123, "path": "4/0/1/1", "attribute": "url", "source": "voyager/site-data", "args": {"key": "phone_link"}}
   ```

5. Then check it with **get-post-blocks** and **validate**. Both bindings of "Click to call" should resolve to the number. If they fall back, Settings > Site Data > Phone number is empty: tell the user. On the cover, expect one `placeholder:` warning, for the background.
6. **Report** the heading and line you wrote, the phone binding's status and the primary button's link (`/contact/`), and on the cover the placeholder image the user must replace in the editor and the focal point to set.

## Try it

`blueprints/cta-band.json` opens a page with both layouts.
