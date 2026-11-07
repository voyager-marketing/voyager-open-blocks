---
name: voyager-open-blocks
description: Entry point for the Voyager Open Blocks pack on a WordPress site. Use when an agent needs to place, configure or bind any voyager-open/* block or pattern, or the voyager/site-data and voyager/post-meta-text binding sources. Two routes: abilities only over MCP or REST, or Wesper and Block Runner with a shell. Routes to the per-item skills.
license: GPL-2.0-or-later
metadata:
  pack-version: "0.3.0"
  requires: "WordPress 6.9+, PHP 8.1+, wesper 0.5.0, block-runner 1.0.0"
---

# Voyager Open Blocks

One block, two binding sources and five patterns, built so an agent can use them correctly without guessing.

## Tell your agent

Give your agent this prompt, with `[task]` filled in:

> Use the Voyager Open Blocks skills on my site. Read the site first, then [task]. Check every block before you save, and tell me which values come from Site Data and which are still empty.

Each item skill has its own prompt under "Tell your agent".

## Items

| Item | Kind | Skill | Copy labels |
|---|---|---|---|
| `voyager-open/icon` | block (dynamic) | `voyager-open-icon` | None. Set its attributes with `insert` or `configure`. |
| `voyager/site-data` | binding source | `voyager-site-data` | None |
| `voyager/post-meta-text` | binding source | `voyager-post-meta-text` | None |
| `voyager-open/cta-band` | pattern | `voyager-open-cta-band` | `Eyebrow`, `Heading`, `Body`, `Primary button`, `Click to call` |
| `voyager-open/proof-strip` | pattern | `voyager-open-proof-strip` | `Credential 1`, `Credential 2`, `Credential 3`, `Credential 4` |
| `voyager-open/logo-marquee` | pattern | `voyager-open-logo-marquee` | `Eyebrow` |
| `voyager-open/closing-cta` | pattern | `voyager-open-closing-cta` | `Heading`, `Body`, `Primary button`, `Email button` |
| `voyager-open/contact-section` | pattern | `voyager-open-contact-section` | `Heading`, `Body`, `Phone`, `Email`, `Street address`, `City, state, postal code`, `Hours`, `Click to call`, `Form heading`, `Form body`, `Email button` |

Copy labels are the keys of `insert`'s `copy` input. Match them exactly, including case and commas. A bound block keeps its binding, so its copy is only the fallback text shown while that Site Data field is empty. That applies to `Click to call`, `Email button` and the contact section's detail lines. `get-catalog` lists the same labels: the pattern's blocks with `copy: true`.

## The loop

Every task reads the site, places blocks, binds them and checks them. There are two routes through that loop. Check your tools, then follow one route for the whole task.

- **Route 1: abilities only.** You can call the site's abilities through MCP or REST, and you cannot run shell commands. Most chat assistants are here.
- **Route 2: shell and Node.** You can run `npx` with Node 22 or later, and reach the site with WP-CLI, locally or over SSH. This route adds Wesper, which reads everything the site registers, and Block Runner, which checks saved HTML.

The binding rules and hard rules further down apply to both routes.

## Route 1: abilities only

Every step is an ability call. Place blocks with `insert`, and change them with `bind` and `configure`. Do not write block markup to save.

1. **Read the site.** Call `voyager-open-blocks/get-catalog`. It lists the pack's block, the patterns with their labels, and every bindable field with its exact `args`. If no `voyager-open-blocks/*` ability exists, the plugin is inactive or too old. Stop and say so.
2. **Find the post.** Every write takes a `post_id`. Use any tool you have that lists posts. Over REST, `GET /wp-json/wp/v2/pages?search=services` does it. If you have none, ask the user for the ID. It is the number after `post=` in the address bar while the page is open in the editor.
3. **Read the post.** Call `get-post-blocks` with the `post_id`. Paths with no `/` are the page's top-level sections. Pick `position` for `insert` from them: `0` puts the new section first, `-1` (the default) adds it at the end, and `n` puts it before the section at path `n`.
4. **Check, then place.** Call `insert` with `item`, `position`, `copy` and, if a pattern has blocks the user has no content for, `omit`. Add `"dry_run": true` first: it runs every check and returns the `markup` without saving. Read `validation`, then call `insert` again without `dry_run`.
5. **Bind or configure.** Take paths from the `blocks` that `insert` returned. Call `bind` with args copied from `get-catalog`, or `configure` for the icon's attributes.
6. **Verify.** Call `get-post-blocks` again. Every binding you meant to land should read `resolution.status: "resolved"`. Then call `validate` with the `post_id` to check the whole saved page. A site whose plugin is older than this skill has no `validate`. Skip it there.
7. **Report.** For each binding, give its `value_preview` when it resolved, or the Site Data field to fill in when it fell back (`reason: "empty_value"`). Say that the saved HTML was not compared with each block's save(), because this route cannot run Block Runner.

### What the abilities check in PHP

These checks run before anything is saved. When one fails, nothing is saved, and the error code names the input to fix (see Abilities, below).

- **insert**
  - `item` is a registered `voyager-open/*` block or pattern, the post exists, and you may edit it.
  - `copy` and `omit` work only on a pattern inserted as a copy (`mode: "copy"`, the default). Each label must name a block in the pattern. A `copy` label must be on a heading, paragraph, button or list item. Its text is escaped and replaces everything between that element's tags. An `omit` label removes that block and everything inside it, but never the whole pattern.
  - `attributes`, for the icon: each must exist in its block.json and match its type and enum. `metadata` is refused. Use `bind`.
  - The new blocks pass three checks: every block type is registered, every comment attribute with a schema matches it, and the markup survives a parse and serialize round trip.
- **configure**
  - The block at `path` must be dynamic and save no HTML, like the icon. Any other block is refused.
  - Each attribute must exist and match its type and enum. `null` removes one, so its default applies. `metadata` is refused.
  - The changed block passes the same three checks.
- **bind**
  - The attribute must be bindable on that block. The error lists the ones that are.
  - The source must be registered, and must be `voyager/site-data`, `voyager/post-meta-text` or `core/post-meta`.
  - `args` must equal the args of a field `get-catalog` lists for the post's own post type. For `voyager/post-meta-text`, a dot path is accepted when the part before the first dot is listed.
  - The changed block passes the same three checks. Its authored text stays as the fallback.
- **validate** (read only, saves nothing)
  - Takes `markup`, or `post_id` for a saved post. Runs the three checks on every block, and bind's checks on every binding to the three sources above. Reports each block by path, with its `errors` and `warnings`.
  - The round trip allows whitespace between top-level blocks, and spellings WordPress reads the same way, such as `\u0026` for `&` or a `core/` prefix. A missing closing comment, JSON that does not parse, or a block that nests differently fails it. The response's `markup` is what WordPress would save. Compare it with yours at the block that failed.
  - HTML outside any block is an error. A binding to another registered source, such as `core/pattern-overrides`, is a warning, because its args cannot be checked. Warnings do not make `valid` false.

### What no ability checks

No ability compares a block's saved HTML with what its save() function would write. That needs the block's JavaScript, which PHP cannot run. A heading whose comment says level 3 while its HTML is an `<h2>` passes every ability and still breaks in the editor.

The pack's patterns were checked with Block Runner when they were built. `copy` changes only escaped text, and `omit` removes whole blocks from containers whose HTML does not depend on them, so what `insert` saves stays safe. Use `validate` for markup `insert` did not write: a section the user pasted, or a page another tool saved. A pass means the PHP checks hold, not that the saved HTML is right. Tell the user that.

### What route 1 cannot do

Say so, and tell the user what to do in the editor, or use route 2.

- Put a block inside another block. `insert` places at the top level of a post only.
- Change an image, add a block to a pattern, or change a static block's link, such as a primary button's `/contact/`.
- Reach a header or footer that was never saved in the Site Editor. A template part has a post ID only after it is saved. Over REST, `GET /wp-json/wp/v2/template-parts` gives it as `wp_id`.
- Make a post meta field bindable. The user does that under Settings > Site Data.

### Over REST

Each ability runs at `/wp-json/wp-abilities/v1/abilities/<name>/run`. Read-only abilities (`get-catalog`, `get-post-blocks`, `validate`) take GET, with input in the query string, such as `?input[post_id]=123`. Long markup can pass the server's URL limit, often 8 KB. Validate one section at a time, or save first and pass `post_id`. The other abilities take POST with a JSON body: `{"input": {...}}`. Over MCP there is no URL limit.

## Route 2: shell and Node

This route adds two tools. Wesper reads everything the site registers. Block Runner runs each block's save() and checks the saved HTML, which route 1 cannot. When the plugin is active, you can still place and bind with the abilities. Check every markup they return with Block Runner.

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

Never hand-write block markup. The one exception is the icon's self-closing comment, because Block Runner cannot assemble a custom block (see `voyager-open-icon`). Two ways:

- **Insert a pattern by reference.** `<!-- wp:pattern {"slug":"voyager-open/cta-band"} /-->` renders the current pattern and picks up plugin updates. Use it when the copy can stay as shipped or will be edited in the editor.
- **Assemble an edited copy.** Each pattern's intent tree is in `patterns/intents/<name>.json` under the `intent` key, in the plugin folder and in the repository. Copy it, change text and attributes, then:

```bash
npx block-runner assemble intent.json --json            # read .output, check .items
npx block-runner validate page.html --strict --json --context site.context.json
```

Pass `--context site.context.json` so Block Runner knows `voyager-open/icon` is registered on the target. Without it, strict mode warns that the block is unregistered.

### 3. Bind

Add the binding to the block's `attrs.metadata.bindings` in the intent before you assemble, or call `bind` after the block is saved. Follow the binding rules below.

### 4. Validate every markup

Run `npx block-runner validate <file> --strict --json --context site.context.json` on every markup you wrote and every `markup` an ability returned. Exit `0` is pass. Exit `1` is invalid: run `npx block-runner fix`, then validate again. Never save markup that still fails. This is the only check of saved HTML, so do it every time on this route.

### 5. Verify the bindings

Call `get-post-blocks` and read each binding's `resolution`, as in route 1 step 6, or open the rendered page.

## Binding rules

A binding lives in the block's `metadata.bindings`:

```json
{ "metadata": { "bindings": { "content": { "source": "voyager/site-data", "args": { "key": "phone" } } } } }
```

- The attribute must be bindable for that block. `core/paragraph` and `core/heading` take `content`, `core/button` takes `text` and `url`, and `voyager-open/icon` takes `label` and `linkUrl`. On route 2, check `bindings.supportedAttributes["<block>"]` in the manifest. On route 1, `bind` refuses any other attribute and lists the bindable ones.
- Copy `args` verbatim from `get-catalog`, called with the post's type, such as `{"post_type": "post"}`. The default is `page`. It lists every field, including the `voyager/site-data` keys Wesper cannot see. On route 2, the manifest's `contentModel.postTypes[].fields[]` also gives post meta args ready to use.
- Leave the authored text in place. An empty value resolves to nothing, and WordPress then keeps the authored text, so placeholder copy shows until the site has real data.
- A binding replaces the whole attribute. To bind a phone number that sits inside a longer line, give it a block of its own first.

## Abilities

The plugin registers six WordPress abilities in the `voyager-open-blocks` category. They are visible at `/wp-json/wp-abilities/v1/abilities` and, through the WordPress MCP adapter, as MCP tools.

| Ability | Method | Use it to |
|---|---|---|
| `voyager-open-blocks/get-catalog` | GET | List the pack's block (attribute schema, bindable attributes), patterns (named blocks, bindings, `copy: true` on the ones `insert` can rewrite) and every bindable field with its exact `args`, including the `voyager/site-data` keys Wesper cannot see. Takes `post_type` (default `page`). |
| `voyager-open-blocks/get-post-blocks` | GET | Read a post's blocks with their paths, labels and bindings. Each binding carries `resolution` (`status` `resolved` or `fell_back`, a `reason`, a `value_preview`), so you can prove a bound value arrives. |
| `voyager-open-blocks/insert` | POST | Place a pattern (a copy, or a `core/pattern` reference with `mode: "reference"`) or the icon block at a top-level `position`. A copy takes `copy`, plain text by label, such as `{"Heading": "Need a plumber today?"}`, and `omit`, labels to leave out, such as `["Credential 4"]`. Returns the inserted `markup`, each block's `path` and `label`, and `validation`. `dry_run` previews without saving. |
| `voyager-open-blocks/configure` | POST | Set attributes on a block that saves no HTML (the icon), validated against block.json. |
| `voyager-open-blocks/bind` | POST | Write `metadata.bindings`. The attribute must be bindable for that block, and `args` must match a listed field exactly. |
| `voyager-open-blocks/validate` | GET | Check `markup`, or a saved post by `post_id`, without saving. Reports per block: registered type, attributes against block.json, bindings against the catalog, and the round trip. Not saved HTML. Takes `post_type` to check binding args for a template. |

Errors use `voyager_open_blocks_invalid_<field>` codes (`post_id`, `path`, `item`, `attributes`, `attribute`, `source`, `args`, `copy`, `omit`, `markup`). Read the message, fix that input and retry.

[`scripts/e2e.mjs`](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/scripts/e2e.mjs) in the repository runs route 1 over REST and then checks the result with Block Runner. It is the worked example.

## Hard rules

- Do not invent credentials, testimonials, statistics or client names to fill a pattern. Use facts the site owner gave you. Otherwise leave the shipped placeholder, or `omit` its block, and say which.
- Do not edit `patterns/*.html`. They are generated from the intents.
- Patterns use theme presets only (`var:preset|spacing|*`, `fontSize: small`). On route 2, check the manifest's `theme.tokens` before adding a colour or size preset, and prefer a slug the theme defines. On route 1, keep the presets the pattern ships with.

## Try it

WordPress Playground with demo content: `blueprint.json` at the repository root, or one blueprint per item in `blueprints/`.
