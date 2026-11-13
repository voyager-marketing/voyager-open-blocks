---
name: voyager-open-service-area
description: Insert or customize the service area section, which names the towns and areas a business serves, in either of its two layouts. voyager-open/service-area puts a section heading, one line of intro, the places as a checkmarked voyager-open/facts list bound to the Site Data service area, and one button. voyager-open/service-area-split puts the same text beside a 4:3 image slot. Use on a location or home page. The places come from Settings > Site Data, never from copy.
license: GPL-2.0-or-later
---

# Service area (`voyager-open/service-area`, `voyager-open/service-area-split`)

The service area section is one section in two layouts. Each layout is its own pattern:

| Layout | Pattern | What it is |
|---|---|---|
| `list` (the first) | `voyager-open/service-area` | The heading, the intro, the places and the button, at content width. |
| `split` | `voyager-open/service-area-split` | The same text beside a 4:3 image. On a phone the image stacks below the text. |

Each intent names its section and layout in its `section` and `layout` keys. The first layout keeps the section's slug; the other is the slug, a hyphen and the layout's name.

The places are a `voyager-open/facts` block, "Places", bound to the Site Data list `service_area`, in the Ticks style. A binding fills an attribute with one string, so a core list cannot follow a Site Data list; the facts block prints one checkmarked item per line of it (see `voyager-open-facts`). Change the places once in Settings > Site Data and every page that shows them changes too.

## When to use

- On a location page, to name the places around it the business also covers. On a home page, to say where the business works.
- **List** when the section sits between other text-led sections, or the page has no photo of the area.
- **Split** when the site has a photo that belongs to the area: the team at work there, a local landmark, a van on a local street.
- Not for one place. Say it in the hero.
- Not for a map. The section has a static image slot only; an embedded map is a third-party script.
- Not for pages per place. Each location page is its own page, built from the location recipe.

## Tell your agent

> Add the Voyager Open Blocks service area section to my home page, as a list. Keep the places bound to my Site Data service area, and tell me if it is still empty.

For the other layout, say "beside a photo" instead of "as a list".

## Structure

```
voyager-open/service-area (list)
core/group (section, full width, constrained)          "Service area"
  core/group                                           "Lead"
    core/heading h2                                    "Heading"
    core/paragraph                                     "Intro"
  core/group (flex, wrap)                              "Places row"
    voyager-open/facts  Ticks                          "Places"
        items <- voyager/site-data { key: service_area }
  core/buttons                                         "Buttons"
    core/button  "Ask about your area" -> /contact/    "Primary button"

voyager-open/service-area-split (split)
core/group (section, full width, constrained)          "Service area"
  core/columns (wide, centered)                        "Columns"
    core/column                                        "Text"
      core/group                                       "Lead"
        core/heading h2                                "Heading"
        core/paragraph                                 "Intro"
      core/group (flex, wrap)                          "Places row"
        voyager-open/facts  Ticks                      "Places"
            items <- voyager/site-data { key: service_area }
      core/buttons                                     "Buttons"
        core/button  "Ask about your area" -> /contact/ "Primary button"
    core/column                                        "Image column"
      core/image  4:3, cover, empty alt                "Image"
```

The intent trees are `patterns/intents/service-area.json` and `patterns/intents/service-area-split.json` (`intent` key). The markup is `patterns/service-area.html` and `patterns/service-area-split.html`.

The places sit directly inside a flex group, "Places row", because the Ticks style stacks into a column only there: in a container narrower than 32em the places read one under another, and wider they wrap in a row. Keep the facts block inside that group.

## The places

The places are Site Data's service area, one town or area per line, in Settings > Site Data > Business profile > Service area. The section shows them in the order they are typed. Each line is trimmed and empty lines are dropped. An ampersand prints once, whichever plugin owns `voyager/site-data`.

- **Never through `copy`.** "Places" is not a copy label, and `configure` refuses its bound `items` with `voyager_open_blocks_bound_slot`. Change the list in Settings > Site Data.
- **Over REST, as an administrator:** `POST /wp/v2/settings` with `{"voyager_site_service_area": "Springfield\nChatham\nRochester"}`, one string with a line break between places. Read it back with `GET /wp/v2/settings`. On route 1 with no REST client, ask the user to fill it in on the settings screen.
- **Only places the site owner gave you.** Where a business works is a fact about it. Never guess the towns from an address, and never add "and the whole county".
- **While the list is empty,** the block shows its own items, "Your first town", "Your second town" and "Your third town", and visitors see them too. Fill the list before the page goes live, and say so when it is empty.
- Every page that shows the section counts in `get-catalog`'s `used_on` under `voyager/site-data:service_area`, and a change to the list refreshes them.

## Copy rules

Each intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text. Both layouts share them.

| Label | What goes there | Min | Max | Required |
|---|---|---|---|---|
| `Heading` | The section's `h2`: where the business works, in a few words, such as "Areas we serve". | 3 | 60 | yes |
| `Intro` | One line on the region the places sit in, or how to ask about a place the list leaves out. No travel time, response time or promise unless the site owner gave it. | 20 | 120 | no |
| `Primary button` | The section's one action, as what happens when it is pressed, such as "Ask about your area". | 2 | 24 | no |

No slot is a claim slot, and the shipped copy makes no claim. Do not write a travel radius, a response time, "the whole county" or "same day" unless the site owner gave it to you.

The section holds one button and no more. To drop it, pass `"omit": ["Buttons"]`, which takes the button with its row. To drop the intro, pass `"omit": ["Intro"]`.

## The image slot

The split layout ships one image slot, the core/image "Image", with the pack's 4:3 placeholder and empty alt text, so `validate` warns `placeholder:` once on a page that holds it as shipped. The list layout has no image and no placeholder.

- The image is cropped to 4:3 (`aspectRatio`, `scale: cover`). After you replace it, set the focal point on the subject: select the image, then in its block settings open Dimensions and use the Focal point picker, which WordPress shows for an image with a scale. The crop then keeps the subject in view at every width.
- Give it alt text that says what it shows, such as "Our van outside a house in Chatham".

No ability changes an image. Tell the user to replace the placeholder in the editor: select the image, choose Replace, pick the photo from the Media Library, add the alt text and set the focal point. Until then the page shows the grey placeholder, so say so before you save to a published page.

## Customizing

- **As shipped:** `<!-- wp:pattern {"slug":"voyager-open/service-area"} /-->` (or `voyager-open/service-area-split`), or `insert` with `"mode": "reference"`. It renders the current pattern and its places follow Site Data, but its copy and image cannot change. Use a copy for a real page.
- **Edited, route 1:** `insert` with `copy` (see Abilities). Keep the facts block's binding.
- **Edited, route 2:** copy the intent, change the heading, intro, the button's `text` and `url`, and on the split layout the image's `url` and `alt`, then `npx block-runner assemble intent.json --json`. The facts block is the pack's own: write its self-closing comment back in place, with its binding (`voyager-open-facts`).
- Point the button at a page that exists. On route 1, the button keeps `/contact/`: no ability changes a static button's link. If the site has no `/contact/` page, tell the user to change the link in the editor.

## Bindings

| Block | Attribute | Source | Args |
|---|---|---|---|
| Places (`voyager-open/facts`) | `items` | `voyager/site-data` | `{ "key": "service_area" }` |

## The loop

This is route 2, with a shell and Node. In the Wesper manifest, confirm both patterns in `patterns.items` and `voyager-open/facts` in `blocks.types`. Insert one by reference, or an edited intent assembled as Customizing says, then check it with `npx block-runner validate`. The steps are in [docs/agents.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/agents.md).

## Abilities

This is route 1: the abilities, over MCP or REST, with no markup to write.

1. **get-catalog.** Confirm both patterns are in `patterns`, each with a block "Places" whose `bindings.items` is `voyager/site-data` with `{"key": "service_area"}`. `used_on["voyager/site-data:service_area"]` counts the pages that show the list already.
2. **Where.** Find the page with `find-posts`, say 123, and pick `position` from `get-post-blocks`.
3. **insert** with `copy` for the heading and intro:

   ```json
   {"post_id": 123, "item": "voyager-open/service-area", "position": -1, "dry_run": true, "copy": {"Heading": "Where we work", "Intro": "Ask us about a town or an area that is not on this list."}}
   ```

   Use `"item": "voyager-open/service-area-split"` for the layout with an image. Then save without `dry_run`.
4. **Keep the places bound.** No copy and no `configure` for "Places". Change the list in Settings > Site Data, or at `/wp/v2/settings` as above, with places the site owner gave you.
5. **get-post-blocks** again: "Places" should read `resolution.status: "resolved"` for `items`. `fell_back` with `reason: "empty_value"` means the service area is empty: tell the user to fill it in.
6. **validate** the saved page: expect no warning for the list, and one `placeholder:` warning, for the image, for the split.
7. **Report** the copy you wrote, the places' value preview, the button's link (`/contact/`), and on the split layout the image to replace and the focal point to set.

## Try it

`blueprints/service-area.json` opens a page with both layouts, the places filled from the demo Site Data.
