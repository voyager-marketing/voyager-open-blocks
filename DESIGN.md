---
version: alpha
name: Voyager Open Blocks
description: How a page built from the pack's patterns should look on any block theme. The pack names colour roles and theme presets, never a palette or a font of its own; the values below are stand-ins for a theme the pack knows nothing about.
colors:
  primary: "{colors.accent}"
  surface: "#ffffff"
  on-surface: "#111111"
  muted: "color-mix(in srgb, #111111 65%, #ffffff)"
  accent: "color-mix(in srgb, #111111 75%, #ffffff)"
  on-accent: "#ffffff"
  line: "color-mix(in srgb, #111111 20%, #ffffff)"
  panel: "color-mix(in srgb, #111111 5%, #ffffff)"
typography:
  display:
    fontSize: 3rem
  heading:
    fontSize: 2rem
  title:
    fontSize: 1.375rem
  figure:
    fontSize: 2rem
  body:
    fontSize: 1.125rem
  label:
    fontSize: 0.875rem
    letterSpacing: 0.08em
spacing:
  "20": 10px
  "40": 30px
  "50": 50px
  "60": 70px
rounded:
  button: 9999px
  box: 0px
components:
  section-plain:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    typography: "{typography.body}"
    padding: "{spacing.60}"
  section-panel:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.on-surface}"
    typography: "{typography.body}"
    padding: "{spacing.60}"
  section-inverted:
    backgroundColor: "{colors.on-surface}"
    textColor: "{colors.surface}"
    typography: "{typography.body}"
    padding: "{spacing.60}"
  section-accent:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
    typography: "{typography.body}"
    padding: "{spacing.60}"
  section-strip:
    padding: "{spacing.40}"
  heading-display:
    typography: "{typography.display}"
  heading-section:
    typography: "{typography.heading}"
  heading-card:
    typography: "{typography.title}"
  figure-value:
    typography: "{typography.figure}"
  text-muted:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.muted}"
  text-muted-on-panel:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.muted}"
  rule:
    backgroundColor: "{colors.line}"
    height: 1px
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.button}"
  button-primary-on-inverted:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    rounded: "{rounded.button}"
  button-primary-on-accent:
    backgroundColor: "{colors.on-accent}"
    textColor: "{colors.accent}"
    rounded: "{rounded.button}"
  button-outline:
    textColor: "{colors.on-surface}"
    rounded: "{rounded.button}"
  card:
    textColor: "{colors.on-surface}"
    rounded: "{rounded.box}"
    padding: "{spacing.40}"
  eyebrow:
    typography: "{typography.label}"
  tick-list:
    typography: "{typography.body}"
    size: 1.1em
  star-row:
    typography: "{typography.label}"
    size: 24px
  quote-single:
    typography: "{typography.title}"
  logo:
    height: 40px
  form-slot:
    rounded: "{rounded.box}"
    padding: "{spacing.50}"
---

# Voyager Open Blocks

This file follows Google's [DESIGN.md format](https://github.com/google-labs-code/design.md), and `npm run check` lints it. It says how a page built from the pack's patterns should look on any block theme.

**When the site has its own DESIGN.md, that one wins.** This file fills only what the site's leaves open.

The theme supplies every real value. The front matter holds stand-ins: the colours are the monochrome fallback the pack uses on a theme it has no colour map for, with `#111111` and `#ffffff` standing in for the theme's text and background, and the sizes and spacing are Twenty Twenty-Five's presets at full width.

## Overview

A page built from the pack should read like a well-made local business site in the theme's own clothes: plain, confident and easy to scan, with one clear next step. The theme brings colour, type and radius. The pack brings structure and rhythm.

- **One bold move per section.** A strong section style, a display-size heading, a large image or motion: pick one, and keep the rest of the section quiet.
- **Rhythm comes from alternating quiet and strong sections,** not from decoration.
- **Core blocks and theme presets only.** No colour, font size, font family or shadow outside the theme's presets, and no `core/html`.

## Colors

Every colour is one of six roles. The pack's patterns set no colour of their own: a Plain section takes the theme's background and text colour, and the three section styles paint from the roles. The one exception is text on a cover, whose overlay and text take two roles' slugs (Image slot, under Components).

The roles come from a role map that turns each role into one of the slugs of the palette the site paints with. That is the theme's palette, unless a style variation chosen in the Site Editor brings its own, which replaces it. The pack has maps for Twenty Twenty-Five, Twenty Twenty-Four and the Voyager design system. It picks the one whose slugs that palette declares, or, when it matches none, the one whose slugs the theme declares, so a variation that drops a slug keeps its theme's map. Each role then keeps its slug only where that palette has it, and in pairs: `surface` with `on-surface`, `accent` with `on-accent`. `on-accent` is the map's slug when it reads at 4.6:1 (the 4.5:1 floor and a margin) on `accent`, else the map's `surface` slug, else its `on-surface` slug, so Twenty Twenty-Five's dark variations, where `accent-3` is dark too, put `contrast` on it. When none reads, `accent` and `on-accent` fall back together. A theme replaces the map with the `voyager_open_blocks_style_roles` filter, which returns role => slug and is used as it returns it. On any other theme, and for any role the map leaves out, the roles are built from the theme's own text and background colours (its theme.json `styles.color`, or the Site Editor's): `surface` and `on-accent` are the background, `on-surface` is the text, `accent` mixes 75% of the text into the background with `color-mix()`, or more in steps of 5% until the background reads at 4.6:1 on it, and `muted` and `line` mix `currentColor` into transparent at 65% and 20%. That gives the monochrome values in the front matter.

| Role | Use |
| -- | -- |
| `surface` | page and Plain section background |
| `on-surface` | text, headings, links and icons on `surface` |
| `muted` | secondary text on `surface` or Panel: hours, captions, fine print |
| `accent` | the brand colour: the Accent section's background, the primary button's fill in Panel |
| `on-accent` | text and icons on `accent` |
| `line` | 1px rules and borders. The patterns' rules set no colour yet, so they take the text colour |

`primary` in the front matter is the format's required name for `accent`; write `accent`. `panel` is not a role: it is `surface` with 5% of `on-surface` mixed in, so it follows the map.

**Three section styles: Panel, Inverted and Accent.** Each is a block style on the section's `core/group`. `get-catalog` lists them under `styles`, `configure` sets one with its `style` input (`plain` takes it off), and the editor shows them in the group's Styles panel. Plain is no style at all. Each style sets the section's background and text colour, and the colour of the headings, links, quotes and fill buttons inside it:

| Style | Class | Background | Text | Primary button |
| -- | -- | -- | -- | -- |
| Plain | none | `surface` | `on-surface` | the theme's own |
| Panel | `is-style-voyager-open-panel` | `panel` | `on-surface` | `accent` fill, `on-accent` text |
| Inverted | `is-style-voyager-open-inverted` | `on-surface` | `surface` | `surface` fill, `on-surface` text |
| Accent | `is-style-voyager-open-accent` | `accent` | `on-accent` | `on-accent` fill, `accent` text |

An outline button takes the section's text colour. Both buttons keep their colours on hover and focus, and hover underlines the label: a theme's own button hover is made for its own background, not a section's. A theme can restyle a section in its own theme.json, under `styles.blocks.core/group.variations.voyager-open-panel` and so on, and that wins over the pack's.

Plain and Panel are quiet; Inverted and Accent are strong. In a strong section, secondary text takes the section's text colour too: `muted` is for `surface` and Panel only.

## Typography

The pack sets no font family, weight or line height. The theme's do. Each type role maps to a theme font-size preset:

| Role | Preset | Used for |
| -- | -- | -- |
| `display` | `xx-large` | a page's first heading, the hero's `h1`, and only that |
| `heading` | `x-large`, through the theme's `h2` style | a section heading (`h2`) |
| `title` | `large`, through the theme's `h3` style | card and form headings (`h3`), and the one quote of the reviews section's single layout |
| `figure` | `x-large` | the value of a figure in the stats section, and only that |
| `body` | the theme's body size | paragraphs, list items, buttons |
| `label` | `small` | eyebrows and fine print |

Headings and body text carry no `fontSize`: the theme's element styles size them. A pattern sets `fontSize` only to `small` or `xx-large`, `large` on the single review's quote and `x-large` on a figure's value, and only when the theme defines that slug. Core's own defaults stop at `x-large`, and the three themes the checks run each define it, so a figure keeps its size where the hero's `xx-large` may not.

A figure is not a heading. It is a value from Site Data, a year, a rating or a count, that a visitor reads at a glance, so it is set large without becoming a heading a screen reader lists. `display` stays the hero's alone: a page has one `h1`, and a figure as large as it would compete with the page's first heading. At `x-large` a figure matches a section heading's size and stays below the page's.

One heading level per job: one `h1` per page, an `h2` for each section, `h3` inside a section.

## Layout

Every section is a full-width `core/group` (`tagName: section`, `align: full`, constrained layout), so its background reaches both edges. Text sits at the theme's content width or near it (the closing CTA sets 720px). Rows of items (columns, cards, logos, ticks, the CTA band's row, the stats section's figures) go `align: wide`. A list of items one under another, such as the services list, the process steps list or the FAQ's questions, stays at content width, and so does the reviews section's single quote. So do the service area's places, a tick list that sits under the section's heading and lines up with it. Keep body copy short enough to stay under about 75 characters per line wherever it sits: the CTA band's body shares a wide row with its buttons.

Spacing uses the theme's spacing presets:

| Where | Preset |
| -- | -- |
| top and bottom of a section | `60` |
| top and bottom of a strip (proof strip, logo row) | `40` or `50` |
| left and right of every section | `50` |
| between a section's heading, text and buttons | `40` |
| between columns, and between logos | `60` |
| between cards, and inside a card | `40` |
| between the lines of a list of details | `20` |

Use slugs `20` to `60` only. Twenty Twenty-Four stops at `60`, and a slug the theme does not define renders as no space at all. Twenty Twenty-Five defines `20` to `80` and turns off WordPress's default sizes (`defaultSpacingSizes: false`), so `10` renders as no space there.

**Two adjacent sections never share a style other than Plain.**

**How the page recipes were built.** The six recipes in `recipes/`, one per page type, follow the rules above. Each opens with the hero in Plain, because it holds the page's one `h1`. The second section is Panel, so the hero's edge shows. Quiet sections carry the rest, and a strong one, Accent or Inverted, breaks each run of them, never two strong ones in a row. Each strong section is a call to act or a single review, the sections built to carry a bold move. Every page but the contact page ends on a way to get in touch, the closing CTA or the contact section. The contact page puts its contact section second and closes on one customer's words. `tests/Unit/RecipesTest.php` checks the one hero and where it sits, the adjacent styles and that no two strong sections touch. `rhythm` in `npm run check` checks the adjacent styles too, and `npm run design-check` renders each recipe as a page. The rest is judge only.

## Elevation & Depth

Flat. Nothing has a shadow. Hierarchy comes from the section's background, from type size and from 1px solid rules. The proof strip's top and bottom rules and the form slot's border set no colour, so today they render in the text colour (`currentColor`). Every line is 1px.

## Shapes

Radius follows the theme, and the pack sets none. Buttons take the theme's button radius (core's default is a pill, `9999px`). Boxes the pack draws, cards and the form slot, are square. A theme that wants rounded boxes styles `core/group` itself.

## Components

- **Button** (`core/button`). The fill style for the section's one main action, `is-style-outline` for a second. At most two buttons in a section, and one fill. The label says what happens ("Call us", "Get a quote"), in sentence case, with no arrow. The target is at least 44px tall, which the `impeccable` check measures. Never shrink the theme's padding. Some themes miss the target on their own: Twenty Twenty-Four's button measures 41.5px, listed as that theme's known gap.
- **Card.** A `core/group` with a 1px border, `40` padding, no fill and no shadow, holding an optional icon, an `h3`, one or two short paragraphs and at most one link or button. A review card holds a star row, a quote, and the customer's name and one detail instead. A card never holds a card. The border sets no colour, so it takes the text colour and follows the section style. The services section's cards are the pack's first.
  - Cards in a row sit in a `core/group` with the grid layout at wide width, one card to a cell, and share one structure: three columns at most, each at least `18rem`, with a `40` gap. The row wraps rather than squeeze a card, so a heading never breaks inside a word, and the cards stack on a phone. Three fit at 1280px on all three themes the checks run, Twenty Twenty-Four included, whose `40` and `50` are about 64px and 100px there. Check: `layout`.
  - The gap is one value, never a row gap and a column gap. WordPress 7.1 writes the gap into the grid's column sizes, so a pair (`50 60`) makes core's whole `grid-template-columns` invalid, and the row falls to one column (`wp_get_layout_style()` in wp-includes/block-supports/layout.php, 7.1.4; 7.2 takes the column gap alone).
- **Icon** (`voyager-open/icon`). A pictogram above a card's heading, or beside the text of a row in a list: one per item, from the pack's set, chosen for what the item is, never for a promise (no `star` for quality: the star appears only in a review's star row). Every icon in a section is the same size (40px in the services section). An icon that sits beside a heading saying the same thing is decorative: no label, no link and no animation, so a screen reader reads the heading. It strokes in the text colour, so it follows the section style too.
- **Star row.** A review's own rating: five `star` icons in a row with no gap between them, 24px, then the rating in words at `small` ("Rated 5 out of 5"). The stars have no label, so each renders hidden from screen readers, and the words are the row's one accessible name. They say the rating to a sighted reader too, since the outline star cannot show a part star or tell five from none. A lower rating drops the stars above it and says the same number. The rating, like the quote, comes from the site owner, never from the agent.
- **Quote** (`core/quote`). A customer's words and nothing else, in the theme's Plain quote style, so a theme's quote box (a fill and a radius on Twenty Twenty-Four and Ipsum) never sits inside a card or keeps a colour a section style cannot repaint. The theme still sets its type, and may add a rule or a quotation mark. The name and one detail follow it as paragraphs, outside the quotation, the detail at `small`. The single review's quote is centred at `large`.
- **Figure.** A value from Site Data with a short label under it, as two paragraphs in one block: the value at `x-large` (the `figure` role) and the label in body text, `20` between them. The value is bound to the business profile, the year founded, the rating or the review count, and never typed: a figure is a claim, and it comes from the site owner through Settings > Site Data, never from the agent. While its field is empty the value shows its own text, which names the field and holds no number, so a figure whose field is empty is left out of the page rather than shown. The label says what the figure is ("Average rating") and adds no claim. The stats section shows three in a row at wide width, `60` apart, centred, which stack on a phone, or the same three beside its heading and one line, in a row that wraps. Figures do not count up or move: Motion has nothing for them. Check: `layout`.
- **Eyebrow.** One short line at `small`, uppercase with 0.08em letter spacing: above the section heading in the CTA band, and as the section's only heading in the logo marquee. At most one per section, and only when it tells the reader something the heading does not.
- **Step.** One stage of what happens once a visitor gets in touch, in the process steps section: an `h3` that starts with the step's number, a full stop and a space ("1. Get in touch"), then one short paragraph. The number is in the heading's own text, so a screen reader announces it with the heading and a sighted reader sees it. It is not an eyebrow, which a section has at most one of, and the steps are not an ordered list: a `core/list-item` holds only a nested `core/list`, never a heading and a paragraph. A step is not a card: no border, fill, icon or button.
  - Every step in a section shares one structure. Three to five steps, numbered from 1 in order with no gap, so a step added or dropped means the headings are renumbered.
  - In a row the steps sit in `core/columns` at wide width, one column per step with a `60` gap, and stack in order on a phone. Check: `layout`. In a list they sit one under another at content width, `50` apart.
- **Tick list.** `core/list` with the Ticks style: a wrapping row that stacks into a column in a narrow container. Three to six items, each one short claim of about 40 characters or fewer, with no full stop. The ticks take the text colour. Every claim comes from the site owner or Site Data, never from the agent. The service area's places are a tick list on the facts block, in a flex group so they stack in a narrow column: one place per line of Site Data's service area, as many as the owner lists. The proof strip's facts layout is a tick list on the facts block too, in its flex Row: one credential per line of Site Data's credentials.
- **Logo row.** `core/group` with the Marquee style. Five to ten logos, each 40px tall with `scale: contain`, with the company's name as alt text (the placeholders ship with empty alt text until replaced).
  - When the row is wider than the section, it loops left at a steady linear speed with no visible join. At render the row is followed by a copy of itself, hidden from screen readers (`aria-hidden="true"`) and `inert`, with any `id` dropped, and the copy starts one gap after the row's last logo. Both move left by one row's width, then start again where the copy has taken the row's place. A right-to-left page loops right.
  - A row that fits the section stays still, and its copy is clipped away. CSS tells the two apart with no script: in the row's transform, `clamp(0px, (100% - 100cqw) * 10000000, 100%)` is the row's width when it is wider than the marquee and 0 when it fits.
  - The stored page holds each logo once, so an editor edits one row and `validate` reports each placeholder logo once. The copy is made only for a page a visitor sees: a REST response (`content.rendered`), a feed and an admin request carry each logo once.
  - It pauses on hover and focus. With motion off, the copy is not displayed and the row wraps and stays still. A row with no copy beside it, as the block editor shows it, wraps and stays still too, so every logo can be reached.
- **Question** (`core/details` with the Question style). One question of the FAQ: its summary is the question, asked as a visitor asks it, and the one paragraph inside is the answer. The browser opens and closes it, with no script and no animation. A summary on its own is one line of body text, 22.4 to 30.5px tall on the three themes the checks run, so the Question style gives it a 44px minimum height, with half a line of padding above and below. A 1px rule sits above each question and below the last, and sets no colour, so it takes the text colour and follows the section style. The FAQ ships its first question open and the rest closed, and is never an exclusive accordion: the details blocks share no `name`. Check: `impeccable`, which measures each summary as a tap target.
- **Contact details** (`voyager-open/contact-details`). A business's address, phone, email and hours, each bound to Site Data and never typed: one `address` holding the street, the city line, then the phone and the email as links, each on a line of its own, and the hours after it, since hours are not contact information. The phone dials (`tel:`) and the email opens a new message (`mailto:`). Each link is alone on its line and at least 44px tall and wide, which the block's stylesheet sets and the `impeccable` check measures. The links take the section's text colour: each section style sets its links to it, and on a Plain section all three themes the checks run paint links in their text colour. While a field is empty its part shows its own text, a label that names it ("Phone", "Email", "Street address"), never a sample number or address, and the phone and email show as text that does not dial. The contact section's first column holds one, under its heading and body. Check: `impeccable`.
- **Form slot.** The contact section's second column: a `core/group` with a 1px border and `50` padding that holds an `h3`, one line of copy and an email button until a form plugin's block replaces it. The form plugin owns the fields. Choose one that shows a visible label on every field.
- **Image slot.** Where a section shows a photo, beside its text or behind it.
  - Beside text: a `core/image` with `aspectRatio` set (`4/3` unless the section needs another) and `scale: cover`, or a `core/media-text` with `imageFill` and a `focalPoint`.
  - Behind text: a `core/cover` with a `focalPoint`, an `overlayColor` from the theme's palette, and a `dimRatio` that keeps its text at 4.5:1 over any image. The `contrast` check measures the text against the overlay at that opacity composited over black and over white, the two extremes an image can reach, and the lower ratio counts.
  - The hero's cover paints the overlay in `on-surface`'s slug and its text in `surface`'s, `contrast` and `base` on all three themes the checks run, so the pair is the theme's own text and background. The overlay is at 90%, the lowest of core's 10% steps where that pair reads at 4.5:1 over black and over white on all three themes and on every colour style variation they ship but one (see Do's and Don'ts). The text colour is set on the cover, its heading and its outline button, and the fill button is `surface` with `on-surface` text, as Inverted's is, so neither a theme's heading or button colours nor a section style repaints them: a section style colours only the band around the cover.
  - Every slot is named in `metadata.name` and ships the pack's placeholder with empty alt text: `assets/placeholders/image-4x3.svg` beside text, `image-16x9.svg` behind it. `validate` warns `placeholder:` on each one still in place.
  - A replaced image needs alt text that says what it shows, except a cover's background, which is decoration.
  - Whoever replaces the image, an agent or a person, sets the focal point on its subject, so the crop keeps the subject in view at every width. Each section's skill says so again.

## Motion

Motion is a section's one bold move, never decoration, and no content depends on it to be seen. There are two: the logo row's loop (Logo row, under Components) and the reveal. Both are CSS alone, with no script, and with motion off nothing moves.

- **Reveal** (the `voyager-open-reveal` class). A block fades in and lifts 2rem into place as it enters the viewport: from no opacity to its own, over the first 20vh the page scrolls once the block's top edge is on screen (`animation-timeline: view()`, `animation-range: entry 0% entry 20vh`).
  - **Where it goes:** the card row of each services layout (the grid's group of cards, and the list's group of rows) and the reviews grid's group of cards. One per section, on the row, never on each card. Nothing above the fold: never the hero, and not the CTA bands, the closing CTA or the contact section.
  - **No content depends on it.** Outside its range the block shows as it is, with no fill before or after, so a block below the first screen is at full opacity in a full-page capture, which does not scroll. A second animation, on the page's own scroll and added on top, holds every revealed block at full opacity at the top of the page and hands over to the reveal over the same first 20vh, so a block half on the first screen never shows faded at rest at the top, and nothing on screen fades as the visitor starts to scroll.
  - Only with motion on (`@media (prefers-reduced-motion: no-preference)`) in a browser with view timelines and `animation-composition` (`@supports`). Anywhere else the block shows in place with no animation. The stylesheet loads only on a page that renders a block with the class.

## Do's and Don'ts

Each rule names the check that enforces it, or says judge only. `lint`, `presets` and `rhythm` run in `npm run check`. `contrast`, `overflow`, `motion`, `layout`, `a11y` and `impeccable` run in CI (`npm run design-check`): every pattern in each section style, Plain included, and each recipe in `recipes/` rendered as a page, its sections in order in their recipe styles, at 320, 390, 768 and 1280 px on Twenty Twenty-Five and Twenty Twenty-Four, and on Ipsum, WordPress's proposed default theme, on the fallback roles. `contrast` also measures each colour style variation the three themes ship on every pattern in each section style, at 390 and 1280 px. The only pairs still under 4.5:1 are a theme's own outline button hover in a Plain section, which the pack does not paint, on Twenty Twenty-Five's Morning and Twenty Twenty-Four's Maelstrom, and the hero cover's text on Twenty Twenty-Five's Afternoon, at 4.13:1 over a white image: Afternoon's `base` and `contrast` read only 5.29:1 with nothing showing through, so no overlay that lets an image show keeps them at 4.5:1 over both extremes. They are listed in `tests/fixtures/contrast.json` as known failures, and any other pair under 4.5:1 fails. Twenty Twenty-Four's own buttons, at 41.5px tall, are listed there as the one known tap-target gap. The rest are planned and not in CI yet.

| Check | Fails when |
| -- | -- |
| `lint` | this file has any lint error or warning, which includes contrast under 4.5:1 between a component's colours |
| `contrast` | rendered text measures under 4.5:1 against its background. On a cover with an image, the background is the overlay over black and over white, and the lower ratio counts |
| `overflow` | a render scrolls sideways |
| `layout` | a row of cards or columns renders other than the columns listed for its pattern at 1280px, or more than one at 320 or 390px, or a word in a heading breaks across lines or spills out of its box |
| `a11y` | axe-core reports a violation in a rendered section: the WCAG 2.0 and 2.1 A and AA rules, and `heading-order`, which on a recipe page reads the headings in page order, across its sections |
| `impeccable` | Impeccable reports line length, gradient text, nested cards or grey text on colour in a rendered section, or a tap target in it is under 44px |
| `motion` | anything moves in a reduced-motion render, the logo loop and the reveal included: an animation or transition is running in a section, or a box in it moves between two samples 400ms apart. With motion on, the run checks it sees the logo marquee and the revealed rows of the services and reviews sections animate in every style, so a still render with motion off is their reduced-motion rule at work |
| `presets` | pattern markup sets a colour, font size, font family, radius or shadow that is not a theme preset, uses a spacing slug outside `20` to `60`, or uses `core/html` or HTML outside a block |
| `rhythm` | a recipe or a composed page breaks the Layout rule on adjacent styles, or a recipe section names a pattern and layout no intent carries. `npm run check` reads every recipe in `recipes/`, and `compose` refuses a page that does either |
| `validate` | the `validate` ability reports an image with no alt text or a placeholder left in place |

- Do give each section one bold move and keep the rest quiet. Judge only.
- Do give two adjacent sections different styles, unless both are Plain. Check: `rhythm`.
- Do keep every text colour at 4.5:1 or more against its background, in every style. Check: `contrast`.
- Do take every colour and font size from the theme's presets. Check: `presets`.
- Do use spacing slugs `20` to `60` only. Check: `presets`.
- Do keep body copy short enough to stay under about 75 characters per line. Check: `impeccable`.
- Do aim for tap targets at least 44px tall and wide. Check: `impeccable`, which measures the rendered size. Known gap: Twenty Twenty-Four's own button is 41.5px tall.
- Do make every page work at 320px wide. Check: `overflow`.
- Do give every card room for its heading: three cards across at most, never a word broken inside itself. Check: `layout`.
- Do give every meaningful image alt text, and replace placeholders before a page goes live. Check: `validate`.
- Do write button labels that say what happens. Judge only.
- Don't use gradient text, or a gradient as decoration. Check: `impeccable`.
- Don't put a card inside a card. Check: `impeccable`.
- Don't put grey text on a coloured background: no `muted` in Inverted or Accent. Check: `impeccable`.
- Don't add a shadow, or a radius the theme did not set. Check: `presets`.
- Don't animate anything for visitors who turn motion off. Check: `motion`.
- Don't put more than one fill button in a section. Judge only.
- Don't put an eyebrow above every heading on a page. Judge only.
- Don't skip a heading level. Check: `a11y`.
