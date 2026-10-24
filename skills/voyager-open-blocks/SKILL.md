---
name: voyager-open-blocks
description: Entry point for the Voyager Open Blocks pack on a WordPress site. Use when an agent needs to place, configure or bind any voyager-open/* block or pattern, or the voyager/site-data and voyager/post-meta-text binding sources. Teaches the read (Wesper), author (Block Runner), bind loop and routes to the per-item skills.
license: GPL-2.0-or-later
metadata:
  pack-version: "0.1.0"
  requires: "WordPress 6.9+, PHP 8.1+, wesper 0.5.0, block-runner 1.0.0"
---

# Voyager Open Blocks

One block, two binding sources and five patterns, built so an agent can use them correctly without guessing.

| Item | Kind | Skill |
|---|---|---|
| `voyager-open/icon` | block (dynamic) | `voyager-open-icon` |
| `voyager/site-data` | binding source | `voyager-site-data` |
| `voyager/post-meta-text` | binding source | `voyager-post-meta-text` |
| `voyager-open/cta-band` | pattern | `voyager-open-cta-band` |
| `voyager-open/proof-strip` | pattern | `voyager-open-proof-strip` |
| `voyager-open/logo-marquee` | pattern | `voyager-open-logo-marquee` |
| `voyager-open/closing-cta` | pattern | `voyager-open-closing-cta` |
| `voyager-open/contact-section` | pattern | `voyager-open-contact-section` |

## The loop

Every task runs the same three steps. Do not skip the first.

### 1. Read the site (Wesper)

```bash
npx wesper collect --wp-path ./public --out site.context.json   # local install, WP-CLI collector
npx wesper summarize site.context.json
```

Use `--ssh <target>` for a remote install over WP-CLI. The REST collector (`--rest`) cannot see binding sources or registered meta, so it is not enough for binding work.

Confirm the pack is active before using it:

- `blocks.types[]` has `voyager-open/icon`.
- `bindings.sources[]` has `voyager/site-data` and `voyager/post-meta-text`.
- `patterns.items[]` has the `voyager-open/*` patterns.

Missing means the plugin is inactive or older. Stop and say so. Do not write markup for blocks the site does not register.

### 2. Author native blocks (Block Runner)

Never hand-write block markup. Two routes:

- **Insert a pattern by reference.** `<!-- wp:pattern {"slug":"voyager-open/cta-band"} /-->` renders the current pattern and picks up plugin updates. Use it when the copy can stay as shipped or will be edited in the editor.
- **Assemble an edited copy.** Each pattern's intent tree is in `patterns/intents/<name>.json` under the `intent` key. Copy it, change text and attributes, then:

```bash
npx block-runner assemble intent.json --json            # read .output, check .items
npx block-runner validate page.html --strict --json --context site.context.json
```

Pass `--context site.context.json` so Block Runner knows `voyager-open/icon` is registered on the target. Without it, strict mode warns that the block is unregistered.

Exit `0` is pass. Exit `1` is invalid: run `block-runner fix`, then validate again, and never write markup that still fails.

### 3. Bind to real data

A binding lives in the block's `metadata.bindings`:

```json
{ "metadata": { "bindings": { "content": { "source": "voyager/site-data", "args": { "key": "phone" } } } } }
```

Rules:

- The attribute must be bindable for that block. Check `bindings.supportedAttributes["<block>"]` in the manifest. `core/paragraph` takes `content`, `core/button` takes `text` and `url`, `voyager-open/icon` takes `label` and `linkUrl`.
- Copy `args` verbatim. For post meta, the manifest's `contentModel.postTypes[].fields[]` gives them ready to use. For `voyager/site-data`, the keys are fixed and listed in its skill. Wesper reports that source but not its keys, because custom sources expose no field list on the server.
- Leave the authored text in place. An empty value resolves to nothing, and WordPress then keeps the authored text, so placeholder copy shows until the site has real data.

## Hard rules

- Do not invent credentials, testimonials, statistics or client names to fill a pattern. Use facts the site owner gave you, or leave the shipped placeholder and say it still needs replacing.
- Do not edit `patterns/*.html`. They are generated from the intents.
- Patterns use theme presets only (`var:preset|spacing|*`, `fontSize: small`). Check the manifest's `theme.tokens` before adding a colour or size preset, and prefer a slug the theme defines.

## Try it

WordPress Playground with demo content: `blueprint.json` at the repo root, or one blueprint per item in `blueprints/`.
