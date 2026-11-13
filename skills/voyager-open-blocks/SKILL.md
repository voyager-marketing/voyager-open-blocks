---
name: voyager-open-blocks
description: Entry point for the Voyager Open Blocks pack on a WordPress site. Use to build a page with the pack, or to place, configure or bind any of its voyager-open/* blocks and patterns or its voyager/site-data and voyager/post-meta-text binding sources. Routes to the per-item skills.
license: GPL-2.0-or-later
metadata:
  pack-version: "0.8.0" # x-release-please-version
  requires: "WordPress 6.9+, PHP 8.1+, wesper 0.5.0, block-runner 1.0.0"
---

# Voyager Open Blocks

Blocks, section patterns and binding sources for business sites, placed by abilities that check every write. Each ability's description holds its detail.

## Tell your agent

> Use the Voyager Open Blocks skills on my site. Read the site first, then [task]. Check every block before you save, and tell me which values come from Site Data and which are still empty.

## Build a page

1. `voyager-open-blocks/get-catalog`. If it is missing, the plugin is inactive: say so.
2. `compose` with a `title` and a recipe's `sections`, each with `copy` written from its `copy_guidance`. A section takes `omit` and `repeat` as `insert` does: omit what you have no facts for. Try `dry_run` first.
3. `validate` the draft's `post_id`.
4. Give the user the `preview_url`, and name each key in `empty` and each `placeholder:` left.

On a live page: `find-posts`, `get-post-blocks`, then `insert`, `configure` or `bind`. `undo` reverses a save.

## Recipes

`compose` takes `voyager-open/hero-cover` as `pattern` `voyager-open/hero`, `layout` `cover`.

| Page | Recipe | Sections, with style |
|---|---|---|
| `home` | `recipes/home.json` | `voyager-open/hero` (plain), `voyager-open/proof-strip` (panel), `voyager-open/services` (plain), `voyager-open/cta-band` (accent), `voyager-open/reviews` (plain), `voyager-open/stats` (panel), `voyager-open/closing-cta` (inverted) |
| `services` | `recipes/services.json` | `voyager-open/hero` (plain), `voyager-open/services-list` (panel), `voyager-open/cta-band` (accent), `voyager-open/reviews` (plain), `voyager-open/process-steps-list` (panel), `voyager-open/closing-cta` (inverted) |
| `service` | `recipes/service.json` | `voyager-open/hero` (plain), `voyager-open/proof-strip` (panel), `voyager-open/services-list` (plain), `voyager-open/process-steps` (panel), `voyager-open/cta-band` (accent), `voyager-open/reviews-single` (plain), `voyager-open/faq` (panel), `voyager-open/closing-cta` (inverted) |
| `location` | `recipes/location.json` | `voyager-open/hero-cover` (plain), `voyager-open/services` (panel), `voyager-open/reviews` (plain), `voyager-open/cta-band` (accent), `voyager-open/faq` (panel), `voyager-open/contact-section` (plain) |
| `contact` | `recipes/contact.json` | `voyager-open/hero` (plain), `voyager-open/contact-section` (panel), `voyager-open/reviews-single` (accent) |
| `about` | `recipes/about.json` | `voyager-open/hero` (plain), `voyager-open/proof-strip` (panel), `voyager-open/services` (plain), `voyager-open/reviews-single` (inverted), `voyager-open/logo-marquee` (plain), `voyager-open/stats-band` (panel), `voyager-open/closing-cta` (accent) |

## Five rules

1. **Bind contact details, never type them.** Phone, email, address and hours come from Site Data.
2. **Claims need a source.** Reviews, credentials and figures come from the site owner, as `{"text": "...", "source": "user"}`.
3. **Core blocks and theme presets only.** No `core/html`, inline colours or hand-written markup.
4. **Placeholders get replaced.** Say which stand-ins are left.
5. **Check before saving.** `dry_run`, then `validate`.

## Two routes

- **Abilities only**, over MCP or REST: the above.
- **A shell with Node**: also `npx block-runner validate --strict` on every markup ([docs/agents.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/agents.md)).

## More

Each item has a skill, such as `voyager-open-hero`. `examples/` holds a home page for five kinds of business, each a `compose` call. Read the site's `DESIGN.md`, then the pack's, in the plugin folder.
