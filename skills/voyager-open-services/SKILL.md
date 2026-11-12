---
name: voyager-open-services
description: Insert or customize the services section, which says what a business does, in either of its two layouts. voyager-open/services puts a section heading, an optional line of intro and three cards in a row, each an icon, a heading and one short line. voyager-open/services-list puts the same items in rows at content width, each icon beside its text. Use on a home or services page, with three to six services.
license: GPL-2.0-or-later
---

# Services (`voyager-open/services`, `voyager-open/services-list`)

The services section is one section in two layouts. Each layout is its own pattern:

| Layout | Pattern | What it is |
|---|---|---|
| `grid` (the first) | `voyager-open/services` | Cards in a row at wide width, each an icon, a heading and one short line. On a phone the cards stack. |
| `list` | `voyager-open/services-list` | The same items as rows at content width, each icon beside its heading and line. |

Each intent names its section and layout in its `section` and `layout` keys. The first layout keeps the section's slug; the other is the slug, a hyphen and the layout's name.

## When to use

- On a home page, after the hero and the proof strip, to say what the business does. On a services page, near the top.
- **Grid** for three or four services. A row of five or six cards is too narrow for a heading and a line at desktop width.
- **List** for five or six services, or when each line runs long. Rows read well at any count from three to six.
- Not for a single service. Write a paragraph, or use the hero.
- Not for prices, ratings or credentials. Those are claims: the proof strip holds facts the owner gives you.

## Tell your agent

> Add the Voyager Open Blocks services section to my home page under the proof strip, as cards. Use these services: [three to six services, each with one line]. Pick an icon for each from the pack's set.

For the other layout, say "as a list" instead of "as cards".

## Structure

```
voyager-open/services (grid)
core/group (section, full width, constrained)          "Services"
  core/group (wide, text at content width, left)       "Lead"
    core/heading h2                                    "Heading"
    core/paragraph                                     "Intro"
  core/columns (wide)                                  "Cards"
    core/column                                        "Service 1"
      core/group (1px border, padding 50)              "Service 1 card"
        voyager-open/icon  wrench, 40px                "Service 1 icon"
        core/heading h3                                "Service 1 heading"
        core/paragraph                                 "Service 1 text"
    core/column                                        "Service 2"   (home)
    core/column                                        "Service 3"   (cog)

voyager-open/services-list (list)
core/group (section, full width, constrained)          "Services"
  core/group                                           "Lead"
    core/heading h2                                    "Heading"
    core/paragraph                                     "Intro"
  core/group                                           "Items"
    core/group (row, icon beside text, top)            "Service 1"
      voyager-open/icon  wrench, 40px                  "Service 1 icon"
      core/group (fills the row)
        core/heading h3                                "Service 1 heading"
        core/paragraph                                 "Service 1 text"
    core/group (row)                                   "Service 2"   (home)
    core/group (row)                                   "Service 3"   (cog)
```

The intent trees are `patterns/intents/services.json` and `patterns/intents/services-list.json` (`intent` key). The markup is `patterns/services.html` and `patterns/services-list.html`.

Every item has the same structure, and every block in an item carries the item's label: "Service 2" holds "Service 2 card", "Service 2 icon", "Service 2 heading" and "Service 2 text". That is what lets `repeat` copy a whole item (see Abilities). A card is DESIGN.md's Card: a 1px border, `50` padding, no fill and no shadow. No card holds a card. The cards' borders take the text colour, so they follow the section style.

## Copy rules

Each intent carries `copy_rules`, keyed by the same labels as `insert`'s `copy` (contract 3). Lengths count characters of plain text. Both layouts take the same labels and rules.

| Label | What goes there | Min | Max | Required |
|---|---|---|---|---|
| `Heading` | The section's `h2`: what the business does, in a few words. | 3 | 60 | yes |
| `Intro` | One line on the kind of work the business takes on, or where. Leave it out when the heading says enough. | 20 | 120 | no |
| `Service 1 heading` to `Service 3 heading` | The name of one service, in the words a customer would use. | 3 | 40 | yes |
| `Service 1 text` to `Service 3 text` | One plain line on what the service covers. | 20 | 120 | yes |

An item added with `repeat` takes the same rules as the one it copies: `Service 4 heading` is a service name, `Service 4 text` one plain line.

No slot is a claim slot, and the shipped copy makes no claim: "Repairs", "Installation" and "Maintenance", each with one plain line about the work. Do not put a price, a rating, a licence, a guarantee or a number of years in a card unless the site owner gave it to you. Those belong in the proof strip, from Site Data. Keep each line short: in a card it wraps at about 30 characters, and two or three lines is plenty.

## Choosing icons

Each item shows one `voyager-open/icon`, decorative: no label, no link and no animation, so screen readers skip it and read the heading. All the icons are 40px. Keep them the same size, and keep them unlinked: the card has no link of its own as shipped.

Pick one icon per item from the 35 the pack ships, for what the service is, not what it promises. The shipped items use `wrench` (repairs), `home` (installation) and `cog` (maintenance). Others that suit trade work: `bolt` (electrical), `shield-check` (inspections, safety), `clock` (scheduled or emergency visits), `light-bulb` (advice, design), `building-office` (commercial work), `map-pin` (local or on-site), `users` (team, training), `sparkles` (cleaning, finishing), `document` (plans, permits), `chat-bubble` (consultations). Do not pick `star` or `check-circle` to mean quality or approval. The full list is in the icon block's `enum`, in `get-catalog` or the manifest.

Do not give two items the same icon unless the services really are the same kind of work.

## Customizing

- **As shipped:** `<!-- wp:pattern {"slug":"voyager-open/services"} /-->` (or `voyager-open/services-list`), or `insert` with `"mode": "reference"`. It renders the current pattern and picks up plugin updates, but its copy cannot change. Use a copy for a real page.
- **Edited, route 1:** `insert` with `copy`, `repeat` and `omit` (see Abilities), then `configure` for each icon you change.
- **Edited, route 2:** copy the intent, change the heading, the intro and each item's heading, line and icon. To add an item, copy a whole "Service <n>" node and renumber every label in it. Assemble with `npm run patterns:build` in this repository, which knows the pack's blocks. A plain `npx block-runner assemble` refuses the icon block, so outside this repository write the icon's self-closing comment yourself (the icon skill shows it) and validate with the site's context.
- To drop the intro, pass `"omit": ["Intro"]`.
- A card may hold one link, such as a text link to that service's page, added in the editor. The shipped cards hold none. Put the page's main action in a CTA band after the section, not in every card.

## Bindings

None. The services are the pack's copy until you replace them. A Site Data list of services can drive the facts block, not these cards.

## The loop

This is route 2, with a shell and Node. On route 1, follow Abilities below instead.

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Confirm `voyager-open/services` and `voyager-open/services-list` are in `patterns.items`, and `voyager-open/icon` is in `blocks.types`.
2. **Author.** Insert by reference, or an edited intent assembled as Customizing says.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`. With the context, Block Runner knows the icon block is registered. Without it, each icon warns `unregistered-block` and `--strict` fails. Do not save markup that fails.

## Abilities

This is route 1: the `voyager-open-blocks` abilities, over MCP or REST, with no markup to write.

1. **get-catalog.** Confirm both patterns are in `patterns`, and read the icon block's `icon` enum.
2. **find-posts, then get-post-blocks.** For "my home page", call `find-posts` with `{"front_page": true}` and take `id`, say 123. Read `get-post-blocks` and count the top-level blocks (paths with no `/`) to pick `position`: under the hero and the proof strip is usually `2`.
3. **Three services:** `insert` with `copy` for each heading and line:

   ```json
   {"post_id": 123, "item": "voyager-open/services", "position": 2, "dry_run": true, "copy": {"Heading": "Plumbing services", "Intro": "Homes and small businesses across the county.", "Service 1 heading": "Leak repairs", "Service 1 text": "Dripping taps, burst pipes and running toilets, found and fixed.", "Service 2 heading": "Water heaters", "Service 2 text": "New tank and tankless heaters fitted, and old ones taken away.", "Service 3 heading": "Drain cleaning", "Service 3 text": "Slow and blocked drains cleared, with a camera look where needed."}}
   ```

   Use `"item": "voyager-open/services-list"` for the list. Plain text only, escaped on the way in. Read `validation`: `registered_and_attributes` and `round_trip` should be `true`. Then call `insert` again without `dry_run` to save.
4. **Four to six services:** `repeat` copies "Service 3", with everything in it, after itself, one copy per new label. The blocks in each copy take its label: `Service 4` holds `Service 4 heading` and `Service 4 text`, which `copy` then fills:

   ```json
   {"post_id": 123, "item": "voyager-open/services", "position": 2, "repeat": {"Service 3": ["Service 4"]}, "copy": {"Heading": "Plumbing services", "Service 1 heading": "Leak repairs", "Service 1 text": "…", "Service 2 heading": "Water heaters", "Service 2 text": "…", "Service 3 heading": "Drain cleaning", "Service 3 text": "…", "Service 4 heading": "Bathroom refits", "Service 4 text": "New fixtures, pipework and fittings for a bathroom you are redoing."}}
   ```

   Every item you add needs its heading and line in `copy`, or it repeats the third item's text. For five or six, use the list layout: `{"repeat": {"Service 3": ["Service 4", "Service 5", "Service 6"]}}`. More than six makes a long section: say so and ask which to keep. A new label must be one no block has yet, and so must the labels it gives the blocks inside it, or `insert` refuses with `voyager_open_blocks_invalid_repeat` and lists the labels it can repeat.
5. **Two services:** `omit` drops an item with everything in it: `"omit": ["Service 3"]`. Fewer than three is not a services section; ask whether a paragraph would do.
6. **Icons.** A copied item shows the icon of the one it copies. Set each icon to fit its service with `configure`, using the path of the "Service <n> icon" block from the insert response's `blocks` (or `get-post-blocks`):

   ```json
   {"post_id": 123, "path": "2/1/3/0/0", "attributes": {"icon": "bolt"}}
   ```

   Only names in the icon block's enum are accepted. Do not set `label`, `linkUrl` or `animation`: the icons are decorative and still.
7. **validate** with `{"post_id": 123}`. `valid` should be `true`, with no warnings.
8. **Report** the services you placed, the icon you chose for each, the layout, and that the saved HTML was not checked with Block Runner on this route.

On route 2, also pipe the returned `markup` through `npx block-runner validate --strict --json --context site.context.json`.

`get-catalog` lists both patterns' named blocks before you insert them.

## Try it

`blueprints/services.json` opens a page with both layouts.
