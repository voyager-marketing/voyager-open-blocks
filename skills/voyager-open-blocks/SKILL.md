---
name: voyager-open-blocks
description: Entry point for the Voyager Open Blocks WordPress pack. Use to build a page, or to place, configure or bind its voyager-open/* blocks and patterns or its voyager/site-data and voyager/post-meta-text sources.
license: GPL-2.0-or-later
metadata:
  pack-version: "0.9.0" # x-release-please-version
  requires: "WordPress 6.9+, PHP 8.1+, wesper 0.5.0, block-runner 1.0.0"
---

# Voyager Open Blocks

Abilities place and check the pack's blocks and patterns.

## Tell your agent

> Use the Voyager Open Blocks skills on my site. Read the site first, then [task]. Check every block before you save, and tell me which values come from Site Data and which are still empty.

## Build a page

1. `voyager-open-blocks/get-catalog` with `recipe`, such as `home`: each section's pattern, layout, copy rules and Site Data `filled`, so fetch no pattern. If missing, the plugin is inactive: say so.
2. One `compose` call with a `title` and those `sections`, each with `copy` from its `copy_guidance`, none where a rule says `copy: false`. `omit` what you lack facts for; `repeat` adds items. Example: `{"title":"Home","sections":[{"pattern":"voyager-open/hero","layout":"split","style":"plain","copy":{"Heading":"Springfield roofers"},"omit":["Eyebrow"]}]}`
3. `audit` the draft's `post_id`.
4. Report the `preview_url` and each `audit` finding. `compose` saves a draft, published afterwards.

On a live page: `find-posts`, `get-post-blocks`, then `insert`, `configure` or `bind`. `undo` reverses a save.

## Recipes

`compose` takes each name below as `pattern`.

| Page | Recipe | Sections, with style |
|---|---|---|
| `home` | `recipes/home.json` | `voyager-open/hero` (plain), `voyager-open/proof-strip` (panel), `voyager-open/services` (plain), `voyager-open/cta-band` (accent), `voyager-open/reviews` (plain), `voyager-open/stats` (panel), `voyager-open/service-area` (plain), `voyager-open/closing-cta` (inverted) |
| `services` | `recipes/services.json` | `voyager-open/hero` (plain), `voyager-open/services-list` (panel), `voyager-open/cta-band-cover` (accent), `voyager-open/reviews` (plain), `voyager-open/process-steps-list` (panel), `voyager-open/closing-cta` (inverted) |
| `service` | `recipes/service.json` | `voyager-open/hero` (plain), `voyager-open/proof-strip` (panel), `voyager-open/services-list` (plain), `voyager-open/process-steps` (panel), `voyager-open/cta-band` (accent), `voyager-open/reviews-single` (plain), `voyager-open/faq` (panel), `voyager-open/closing-cta` (inverted) |
| `location` | `recipes/location.json` | `voyager-open/hero-cover` (plain), `voyager-open/services` (panel), `voyager-open/reviews` (plain), `voyager-open/service-area-split` (panel), `voyager-open/cta-band` (accent), `voyager-open/faq` (panel), `voyager-open/contact-section-stacked` (plain) |
| `contact` | `recipes/contact.json` | `voyager-open/hero` (plain), `voyager-open/contact-section` (panel), `voyager-open/reviews-single` (accent) |
| `about` | `recipes/about.json` | `voyager-open/hero` (plain), `voyager-open/proof-strip` (panel), `voyager-open/services` (plain), `voyager-open/reviews-single` (inverted), `voyager-open/logo-marquee` (plain), `voyager-open/stats-band` (panel), `voyager-open/closing-cta-split` (accent) |

## Five rules

1. **Bind contact details, never type them**: phone, email, address and hours come from Site Data.
2. **Claims need a source**: reviews, credentials and figures come from the owner, as `{"text": "...", "source": "user"}`.
3. **Core blocks and theme presets only**: no `core/html`, inline colours or hand-written markup.
4. **Placeholders get replaced**, or say which are left.
5. **One `compose` call**, checked before saving.

## Two routes

- **Abilities only**, over MCP or REST.
- **A shell with Node**: also `npx block-runner validate --strict` ([docs/agents.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/agents.md)).

## More

Each item has a skill, such as `voyager-open-hero`. Read the site's `DESIGN.md`, then the pack's, in the plugin folder.
