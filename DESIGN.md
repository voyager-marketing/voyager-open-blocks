---
version: alpha
name: Voyager Open Blocks
description: How a page built from the pack's patterns should look on any block theme. The pack names colour roles and theme presets, never a palette or a font of its own; the values below are stand-ins for a theme the pack knows nothing about.
colors:
  primary: "{colors.accent}"
  surface: "#ffffff"
  on-surface: "#111111"
  muted: "color-mix(in srgb, #111111 65%, #ffffff)"
  accent: "#111111"
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
    padding: "{spacing.50}"
  eyebrow:
    typography: "{typography.label}"
  tick-list:
    typography: "{typography.body}"
    size: 1.1em
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

- **One bold move per section.** A strong style (Inverted or Accent), a display-size heading, a large image or motion: pick one, and keep the rest of the section quiet.
- **Rhythm comes from alternating quiet and strong sections,** not from decoration.
- **Core blocks and theme presets only.** No colour, font size, font family or shadow outside the theme's presets, and no `core/html`.

## Colors

Every colour is one of six roles. A role map turns each role into one of the theme's palette slugs. The pack ships maps for Twenty Twenty-Five, Twenty Twenty-Four and the Voyager design system, and a theme can replace them with the `voyager_open_blocks_style_roles` filter. On any other theme the roles are built from the theme's text colour with `currentColor` and `color-mix()`, which gives the monochrome values in the front matter.

| Role | Use |
| -- | -- |
| `surface` | page and Plain section background |
| `on-surface` | text, headings, links and icons on `surface` |
| `muted` | secondary text on `surface` or Panel: hours, captions, fine print |
| `accent` | the brand colour: the Accent section's background, the primary button's fill |
| `on-accent` | text and icons on `accent` |
| `line` | 1px rules and borders |

`primary` in the front matter is the format's required name for `accent`; write `accent`. `panel` is not a role: it is `surface` with 5% of `on-surface` mixed in, so it follows the map.

A section's style is a block style on its `core/group`. Use a style only when `get-catalog` lists it: older versions of the pack have none.

| Style | Class | Background | Text | Primary button |
| -- | -- | -- | -- | -- |
| Plain | none | `surface` | `on-surface` | `accent` fill, `on-accent` text |
| Panel | `is-style-voyager-open-panel` | `panel` | `on-surface` | `accent` fill, `on-accent` text |
| Inverted | `is-style-voyager-open-inverted` | `on-surface` | `surface` | `surface` fill, `on-surface` text |
| Accent | `is-style-voyager-open-accent` | `accent` | `on-accent` | `on-accent` fill, `accent` text |

Plain and Panel are quiet; Inverted and Accent are strong. In a strong section, secondary text takes the section's text colour too: `muted` is for `surface` and Panel only. On a theme with no map, Accent looks the same as Inverted.

## Typography

The pack sets no font family, weight or line height. The theme's do. Each type role maps to a theme font-size preset:

| Role | Preset | Used for |
| -- | -- | -- |
| `display` | `xx-large` | a page's first heading, and only that |
| `heading` | `x-large`, through the theme's `h2` style | a section heading (`h2`) |
| `title` | `large`, through the theme's `h3` style | card and form headings (`h3`) |
| `body` | the theme's body size | paragraphs, list items, buttons |
| `label` | `small` | eyebrows and fine print |

Headings and body text carry no `fontSize`: the theme's element styles size them. A pattern sets `fontSize` only to `small` or `xx-large`, and only when the theme defines that slug. Core's own defaults stop at `x-large`.

One heading level per job: one `h1` per page, an `h2` for each section, `h3` inside a section.

## Layout

Every section is a full-width `core/group` (`tagName: section`, `align: full`, constrained layout), so its background reaches both edges. Text sits at the theme's content width or near it (the closing CTA sets 720px). Rows of items (columns, cards, logos, ticks, the CTA band's row) go `align: wide`. Body text never runs at wide width.

Spacing uses the theme's spacing presets:

| Where | Preset |
| -- | -- |
| top and bottom of a section | `60` |
| top and bottom of a strip (proof strip, logo row) | `40` or `50` |
| left and right of every section | `50` |
| between a section's heading, text and buttons | `40` |
| between columns, and between logos | `60` |
| between the lines of a list of details | `20` |

Use slugs `20` to `60` only. Twenty Twenty-Four stops at `60`, and a slug the theme does not define renders as no space at all.

**Two adjacent sections never share a style other than Plain.** Inverted and Accent never touch either, because on a theme with no map they look the same: put a Plain or Panel section between them.

## Elevation & Depth

Flat. Nothing has a shadow. Hierarchy comes from the section's background, from type size and from 1px `line` rules: the proof strip's top and bottom rules, the form slot's border, a card's border. Every line is 1px.

## Shapes

Radius follows the theme, and the pack sets none. Buttons take the theme's button radius (core's default is a pill, `9999px`). Boxes the pack draws, cards and the form slot, are square. A theme that wants rounded boxes styles `core/group` itself.

## Components

- **Button** (`core/button`). The fill style for the section's one main action, `is-style-outline` for a second. At most two buttons in a section, and one fill. The label says what happens ("Call us", "Get a quote"), in sentence case, with no arrow. Never shrink the theme's padding: a button needs to be at least 44px tall.
- **Card.** A `core/group` with a 1px `line` border, `50` padding, no fill and no shadow, holding an `h3`, one or two short paragraphs and at most one link or button. Cards in a row sit in `core/columns` at wide width and share one structure. A card never holds a card.
- **Eyebrow.** One short line at `small`, uppercase with 0.08em letter spacing: above the section heading in the CTA band, and as the section's only heading in the logo marquee. At most one per section, and only when it tells the reader something the heading does not.
- **Tick list.** `core/list` with the Ticks style: a wrapping row that stacks into a column in a narrow container. Three to six items, each one short claim of about 40 characters or fewer, with no full stop. The ticks take the text colour. Every claim comes from the site owner or Site Data, never from the agent.
- **Logo row.** `core/group` with the Marquee style. Four to eight logos, each 40px tall with `scale: contain`, with the company's name as alt text (the placeholders ship with empty alt text until replaced). The row drifts only when it is wider than the screen, pauses on hover and focus, and stays still when the visitor turns motion off.
- **Form slot.** The contact section's second column: a `core/group` with a 1px border and `50` padding that holds an `h3`, one line of copy and an email button until a form plugin's block replaces it. The form plugin owns the fields. Choose one that shows a visible label on every field.

## Do's and Don'ts

Each rule names the check that enforces it, or says judge only. `lint` runs in `npm run check` today. The rest are planned and not in CI yet. The rendered ones (`contrast`, `overflow`, `a11y`, `impeccable`, `motion`) render every pattern and style at 320, 390, 768 and 1280 px on public themes.

| Check | Fails when |
| -- | -- |
| `lint` | this file has a lint error or a section out of order |
| `contrast` | rendered text measures under 4.5:1 against its background |
| `overflow` | a render scrolls sideways |
| `a11y` | an accessibility scan of a render reports a violation |
| `impeccable` | Impeccable's rules report an error on a render |
| `motion` | anything moves in a reduced-motion render |
| `presets` | pattern markup sets a colour, font size, font family, radius or shadow that is not a theme preset, uses a spacing slug above `60`, or uses `core/html` |
| `rhythm` | a recipe or a composed page breaks the Layout rule on adjacent styles |
| `validate` | the `validate` ability reports an image with no alt text or a placeholder left in place |

- Do give each section one bold move and keep the rest quiet. Judge only.
- Do put a quiet section between two strong ones, and never two Panels side by side. Check: `rhythm`.
- Do keep every text colour at 4.5:1 or more against its background, in every style. Check: `contrast`.
- Do take every colour and font size from the theme's presets. Check: `presets`.
- Do use spacing slugs `20` to `60` only. Check: `presets`.
- Do keep body lines under 75 characters: text at content width, never wide. Check: `impeccable`.
- Do make every tap target at least 44px tall and wide. Check: `impeccable`.
- Do make every page work at 320px wide. Check: `overflow`.
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
