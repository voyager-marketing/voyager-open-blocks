---
name: voyager-open-hero
description: Insert or customize the hero, a page's opening section, in either of its two layouts. voyager-open/hero puts an eyebrow, the page's h1, one line of copy, a primary button and a click-to-call button bound to voyager/site-data beside a 4:3 image slot. voyager-open/hero-cover puts the same text over a background image on a core/cover. Use as the first section of a page.
license: GPL-2.0-or-later
---

# Hero (`voyager-open/hero`, `voyager-open/hero-cover`)

The hero is one section in two layouts. Each layout is its own pattern:

| Layout | Pattern | What it is |
|---|---|---|
| `split` (the first) | `voyager-open/hero` | The text beside a 4:3 image. On a phone the image stacks below the text. |
| `cover` | `voyager-open/hero-cover` | The text over a background image, on a dark overlay. |

Each intent names its section and layout in its `section` and `layout` keys. The first layout keeps the section's slug; the other is the slug, a hyphen and the layout's name.

## When to use

- The first section of a page, and only there. Its heading is the page's `h1`, so a page holds one hero.
- **Split** when the site has a photo that should be seen whole: the team, the work, the shop front. It is the default.
- **Cover** when the photo is a backdrop and the words carry the section. Its overlay shows only a trace of the image, so do not choose it to show the photo off.
- Not mid-page. Ask the reader to act there with `voyager-open/cta-band`.

## Tell your agent

> Open my home page with the Voyager Open Blocks hero, image beside text. Write the heading and the line for my business, keep the call button bound to my Site Data phone number, and tell me which image to put in the slot.

For the other layout, say "text over a background" instead of "image beside text".

## Structure

```
voyager-open/hero (split)
core/group (section, full width, constrained)          "Hero"
  core/columns (wide, centered)                        "Columns"
    core/column                                        "Text"
      core/group                                       "Lead"
        core/paragraph  small, uppercase               "Eyebrow"
        core/heading h1  xx-large                      "Heading"
        core/paragraph                                 "Body"
      core/buttons
        core/button  "Get a quote" -> /contact/        "Primary button"
        core/button  outline                           "Click to call"
            text <- voyager/site-data { key: phone }
            url  <- voyager/site-data { key: phone_link }
    core/column                                        "Image column"
      core/image  4:3, cover, empty alt                "Image"

voyager-open/hero-cover (cover)
core/group (section, full width, constrained)          "Hero"
  core/cover (wide, overlay contrast at 90%, text base) "Background"
    core/group                                         "Lead"
      core/paragraph  small, uppercase                 "Eyebrow"
      core/heading h1  xx-large, text base             "Heading"
      core/paragraph                                   "Body"
    core/buttons
      core/button  "Get a quote" -> /contact/          "Primary button"
          base fill, contrast text
      core/button  outline, text base                  "Click to call"
          text <- voyager/site-data { key: phone }
          url  <- voyager/site-data { key: phone_link }
```

The intent trees are `patterns/intents/hero.json` and `patterns/intents/hero-cover.json` (`intent` key). The markup is `patterns/hero.html` and `patterns/hero-cover.html`.

The heading is the page's only `h1`, at the theme's `xx-large` size, DESIGN.md's `display` role. Keep it a level 1 heading, and do not add another `h1` to the page. A theme that has no `xx-large` size, such as Ipsum, shows it at its own `h1` size.

**The cover's colours.** The overlay is the `contrast` preset at 90% and the text is `base`: on Twenty Twenty-Five, Twenty Twenty-Four and Ipsum, those are the role map's `on-surface` and `surface`, the theme's own text and background. The text reads at 4.5:1 or more over any image there, which the design check measures with the overlay over black and over white. Twenty Twenty-Five's Afternoon style variation is the exception: its two colours read 5.29:1 even with nothing showing through, so at 90% they fall to 4.13:1 over a white image. The primary button is `base` with `contrast` text, so it stands out on the overlay in every section style. A section style changes only the band around the cover; the text and buttons on it keep their own colours. On a theme with no `contrast` or `base` preset, set the overlay and the text colour in the editor.

## Copy rules

Each intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text.

| Label | What goes there | Min | Max | Required |
|---|---|---|---|---|
| `Eyebrow` | What the business does or where it works, in a few words. Leave it out when the heading says it already. | 2 | 40 | no |
| `Heading` | The page's one `h1`: what the business offers, and for whom or where. | 10 | 60 | yes |
| `Body` | One line on what the visitor gets or what happens next. | 20 | 120 | yes |
| `Primary button` | The page's main action, as what happens when it is pressed, such as "Get a quote". | 2 | 24 | yes |
| `Click to call` | Bound to the Site Data phone number. Its own text shows only while the number is empty. | 2 | 24 | yes |

No slot is a claim slot, and the shipped copy makes no claim. Do not put a licence, insurance, years in business, a rating or an award in the hero unless the site owner gave it to you. Those belong in the proof strip, from Site Data.

## The image slot

Each layout ships one image slot with the pack's placeholder and empty alt text, so `validate` warns `placeholder:` once on a page that holds it as shipped: the core/image "Image" in the split layout, the core/cover "Background" in the cover layout.

- **Split:** the image is cropped to 4:3 (`aspectRatio`, `scale: cover`), from its centre. Choose a photo whose subject sits near the middle, or crop it with the image toolbar's Crop button. Give it alt text that says what it shows.
- **Cover:** the image fills the cover behind the overlay. After you replace it, set the focal point on the subject (the cover's Media settings, Focal point picker), so the crop keeps the subject in view at every width. Leave its alt text empty: a cover's background is decoration.
- Keep the cover's overlay colour and opacity. A lower opacity can leave the text unreadable over a light or a dark part of the photo.

No ability changes an image. Tell the user to replace the placeholder in the editor: select the image or the cover, choose Replace, pick the photo from the Media Library, then add the alt text (split) or set the focal point (cover). Until then the page shows the grey placeholder, so say so before you save to a published page.

## Customizing

- **As shipped:** `<!-- wp:pattern {"slug":"voyager-open/hero"} /-->` (or `voyager-open/hero-cover`), or `insert` with `"mode": "reference"`. It renders the current pattern and picks up plugin updates, but its copy and image cannot change. Use a copy for a real page.
- **Edited, route 1:** `insert` with `copy` (see Abilities). Keep the call button's bindings. Its authored "Call us" and `/contact/` are what visitors see until the phone number is set.
- **Edited, route 2:** copy the intent, change the eyebrow, heading, line, the primary button's `text` and `url`, and the image's `url` and `alt`, then `npx block-runner assemble intent.json --json`.
- Point the primary button at a page that exists. On route 2, check the manifest or the site before using `/contact/`. On route 1, the button keeps `/contact/`: no ability changes a static button's link. If the site has no `/contact/` page, tell the user to change the link in the editor.
- To drop the eyebrow, pass `"omit": ["Eyebrow"]`.

## Bindings

| Block | Attribute | Source | Args |
|---|---|---|---|
| Click to call button | `text` | `voyager/site-data` | `{ "key": "phone" }` |
| Click to call button | `url` | `voyager/site-data` | `{ "key": "phone_link" }` |

## The loop

This is route 2, with a shell and Node. In the Wesper manifest, confirm `voyager-open/hero` and `voyager-open/hero-cover` in `patterns.items` and `voyager/site-data` in `bindings.sources`. Insert one by reference, or assemble an edited intent with the site's own image, then check it with `npx block-runner validate`. The steps are in [docs/agents.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/agents.md).

## Abilities

This is route 1: the abilities, over MCP or REST, with no markup to write. Run them in this order.

1. **get-catalog.** Confirm both patterns are in `patterns`, and that the `voyager/site-data` source lists a field with args `{"key": "phone"}` and one with `{"key": "phone_link"}`.
2. **Where.** Find the page with `find-posts`, say 123, and read it with `get-post-blocks`. If the page already has a hero or another `h1` section at the top, ask the user before adding a second one.
3. **insert with dry_run,** at the top of the page:

   ```json
   {"post_id": 123, "item": "voyager-open/hero", "position": 0, "dry_run": true, "copy": {"Eyebrow": "Plumbing in Springfield", "Heading": "Plumbers for leaks, drains and water heaters", "Body": "Tell us what is wrong and we will give you a price before we start.", "Primary button": "Book a visit"}}
   ```

   Then save without `dry_run`. Use `"item": "voyager-open/hero-cover"` for the cover layout.
4. **Keep the call button bound.** Never pass copy for `Click to call`, a bound slot: `insert` refuses the whole call with `voyager_open_blocks_bound_slot` and saves nothing. Change the number in Settings > Site Data instead.
5. Then check it with **get-post-blocks** and **validate**. Both bindings of "Click to call" should resolve. If they fall back, Settings > Site Data > Phone number is empty: tell the user. Expect one `placeholder:` warning, for the image slot.
6. **Report** the copy you wrote, the phone binding's status, the primary button's link (`/contact/`), and the placeholder image the user must replace in the editor (and, on the cover, the focal point to set).

## Try it

`blueprints/hero.json` opens a page with both layouts.
