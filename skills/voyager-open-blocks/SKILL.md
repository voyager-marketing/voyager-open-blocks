---
name: voyager-open-blocks
description: Entry point for the Voyager Open Blocks pack on a WordPress site. Use when an agent needs to place, configure or bind any voyager-open/* block or pattern, or the voyager/site-data and voyager/post-meta-text binding sources. Two routes: abilities only over MCP or REST, or Wesper and Block Runner with a shell. Routes to the per-item skills.
license: GPL-2.0-or-later
metadata:
  pack-version: "0.7.0" # x-release-please-version
  requires: "WordPress 6.9+, PHP 8.1+, wesper 0.5.0, block-runner 1.0.0"
---

# Voyager Open Blocks

Two blocks, two binding sources and five patterns, built so an agent can use them correctly without guessing.

## Tell your agent

Give your agent this prompt, with `[task]` filled in:

> Use the Voyager Open Blocks skills on my site. Read the site first, then [task]. Check every block before you save, and tell me which values come from Site Data and which are still empty.

Each item skill has its own prompt under "Tell your agent".

## Items

| Item | Kind | Skill | Copy labels |
|---|---|---|---|
| `voyager-open/icon` | block (dynamic) | `voyager-open-icon` | None. Set its attributes with `insert` or `configure`. |
| `voyager-open/facts` | block (dynamic) | `voyager-open-facts` | None. Set `items` with `insert` or `configure`, or bind it to a Site Data list. |
| `voyager/site-data` | binding source | `voyager-site-data` | None |
| `voyager/post-meta-text` | binding source | `voyager-post-meta-text` | None |
| `voyager-open/cta-band` | pattern | `voyager-open-cta-band` | `Eyebrow`, `Heading`, `Body`, `Primary button`, `Click to call` |
| `voyager-open/proof-strip` | pattern | `voyager-open-proof-strip` | `Credential 1`, `Credential 2`, `Credential 3`, `Credential 4` |
| `voyager-open/logo-marquee` | pattern | `voyager-open-logo-marquee` | `Eyebrow` |
| `voyager-open/closing-cta` | pattern | `voyager-open-closing-cta` | `Heading`, `Body`, `Primary button`, `Email button` |
| `voyager-open/contact-section` | pattern | `voyager-open-contact-section` | `Heading`, `Body`, `Phone`, `Email`, `Street address`, `City, state, postal code`, `Hours`, `Click to call`, `Form heading`, `Form body`, `Email button` |

Copy labels are the keys of `insert`'s `copy` input. Match them exactly, including case and commas. `repeat` adds a copy of a labelled block under a new label, such as `Credential 5` after `Credential 4`, and `copy` then fills it. Never pass copy for a bound slot: the CTA band's `Click to call` and the contact section's detail lines (`Phone`, `Email`, `Street address`, `City, state, postal code`, `Hours`) take their text from Site Data, so `insert` refuses the whole call with `voyager_open_blocks_bound_slot` and saves nothing; change the value in Settings > Site Data instead. `get-catalog` lists the same labels: the pattern's blocks with `copy: true`.

## Design rules

Read them before you choose a section style, write copy or change a layout. If the site has its own `DESIGN.md`, read it first: it is often at the active theme's root, and on route 1 you ask the user for it. Where the site's file and the pack's disagree, the site's wins. Then read the pack's [DESIGN.md](../../DESIGN.md), which covers what the site's leaves open. It ships with the plugin, at the root of its folder (`wp-content/plugins/voyager-open-blocks/DESIGN.md`), so it matches the version installed. Without file access, read it [on GitHub](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/DESIGN.md).

A section's style is one of Plain, Panel, Inverted and Accent. `get-catalog` lists the three that are block styles under `styles`, each with its class; Plain is no style. Put one on a section with `configure`: the section's `path` and `"style": "accent"`, or `"style": "plain"` to take it off. Two sections next to each other never share a style other than Plain.

## The loop

Every task reads the site, places blocks, binds them and checks them. There are two routes through that loop. Check your tools, then follow one route for the whole task.

- **Route 1: abilities only.** You can call the site's abilities through MCP or REST, and you cannot run shell commands. Most chat assistants are here.
- **Route 2: shell and Node.** You can run `npx` with Node 22 or later, and reach the site with WP-CLI, locally or over SSH. This route adds Wesper, which reads everything the site registers, and Block Runner, which checks saved HTML.

The binding rules and hard rules further down apply to both routes.

## Route 1: abilities only

Every step is an ability call. Place blocks with `insert`, and change them with `bind` and `configure`. Do not write block markup to save.

1. **Read the site.** Call `voyager-open-blocks/get-catalog`. It lists the pack's block, the patterns with their labels, and every bindable field with its exact `args`. If no `voyager-open-blocks/*` ability exists, the plugin is inactive or too old. Stop and say so.
2. **Find the post.** Every write takes a `post_id`. Call `find-posts`, which lists only what the user can edit, and take `id` from the row that matches:
   - "my Services page": `{"search": "Services"}`. Pages are the default. Pass `"post_type": "post"` for a blog post.
   - "my home page": `{"front_page": true}`. When the home page lists the latest posts, the row is the template that shows them (`front-page`, `home` or `index`), and `note` says so.
   - "my header" or "my footer": `{"post_type": "wp_template_part", "search": "header"}`. Pick the row whose `area` is `header`.
   - A row with `"id": null` is a template or template part that is still only a theme file. No ability can change it until it is saved once. Tell the user to open it in the Site Editor and save it, then call `find-posts` again.
   - No match: try one word of the name, or `slug`. If `has_more` is true, narrow the search. On a plugin older than this skill there is no `find-posts`: ask the user for the ID, the number after `post=` in the address bar while the page is open in the editor.
3. **Read the post.** Call `get-post-blocks` with the `post_id`. Paths with no `/` are the page's top-level sections. Each block also carries `text`, the start of its own text, so you can tell blocks of one type apart, such as the call button among a header's buttons. Pick where the new blocks go:
   - **A new section.** Leave `parent` out. `position` is a top-level index: `0` puts the section first, `-1` (the default) adds it at the end, and `n` puts it before the section at path `n`.
   - **Inside a block.** Pass `parent`, the path of the block to go in, and `position`, an index among its inner blocks. To put an icon next to a button, the parent is the block that holds the button's `core/buttons`, not `core/buttons` itself, which takes only buttons. For a button at `0/2/1`, the buttons block is `0/2`, so pass `"parent": "0"` and `"position": 2` to go before it, or `3` to go after it.
4. **Try, then place.** Call `insert` with `item`, `position`, `parent` when nesting, `copy` and, if a pattern has blocks the user has no content for, `omit`, or more items than it ships, `repeat`. Add `"dry_run": true` first: it runs every check, refusals included, and returns the `markup` without saving. Read `validation`, then call `insert` again without `dry_run`. Keep the `revision_id` it returns.
5. **Bind or configure.** Take paths from the `blocks` that `insert` returned. Call `bind` with args copied from `get-catalog`, or `configure` for the attributes of the icon or the facts block. In a template or template part, add `post_type` to `bind` for post meta (see Binding rules). Both take `dry_run` too, and each save returns its own `revision_id`.
6. **Verify, and undo what fails.** Call `get-post-blocks` again. Every binding you meant to land should read `resolution.status: "resolved"`. A post meta binding in a template is the exception: it has a value only when a post is shown. Then call `validate` with the `post_id`, and for a template its `post_type`, to check the whole saved page. A site whose plugin is older than this skill has no `validate`. Skip it there. If a check fails, call `undo` with the `post_id` and the `revision_id` of the save that broke it: the content goes back to how it was before that save. If the post was saved after it, undo is refused with `voyager_open_blocks_undo_conflict`, changes nothing, and lists those saves in `later`. When every `revision_id` listed there is one your own saves returned, call `undo` again with `"force": true` to reverse them all. When one is not yours, someone else edited the page: stop and tell the user, and do not force it. Then fix the input and go back to step 4. `revision_id: null` means a save made no revision, because nothing changed, the post is an auto-draft, or the site keeps no revisions for it; `revision_note` says which, and that save cannot be undone. A site whose plugin is older than this skill has no `undo`.
7. **Report.** For each binding, give its `value_preview` when it resolved, or the Site Data field to fill in when it fell back (`reason: "empty_value"`). Say that the saved HTML was not compared with each block's save(), because this route cannot run Block Runner.

### What the abilities check in PHP

These checks run before anything is saved. When one fails, nothing is saved, and the error code names the input to fix (see Abilities, below).

- **find-posts** (read only)
  - Lists only posts the user can both edit and read, with no content: `id`, `title`, `slug`, `post_type`, `status`, `link`, `front_page`, and for templates `source` and, for template parts, `area`. At most 20 at a time.
  - Searches titles only, plus slugs for templates. A post type must be shown in REST and edited in the block editor. Templates and template parts need the right to edit the theme.
- **insert**
  - `item` is a registered `voyager-open/*` block or pattern, the post exists, and you may edit it.
  - `copy`, `omit` and `repeat` work only on a pattern inserted as a copy (`mode: "copy"`, the default). Each label must name a block in the pattern. A `copy` label must be on a heading, paragraph, button or list item. Its text is escaped and replaces everything between that element's tags. An `omit` label removes that block and everything inside it, but never the whole pattern. A `repeat` label must be on one block inside the pattern that holds no labelled blocks, and its new labels must be unused. `repeat` runs first, then `omit`, then `copy`.
  - With `parent`, the block at that path must already hold at least one block, must allow the new block (its block.json `allowedBlocks`, or its own `allowedBlocks` setting), and must not be locked (`templateLock` `all`, `insert` or `contentOnly`, its own or inherited). Each new block's block.json `parent` and `ancestor` rules must hold there. A block whose `parent` rule names a container cannot go at the top level.
  - Inside a parent, the section that holds it must still survive a parse and serialize round trip (`validation.section_round_trip`). The response's `section_markup` is that whole section.
  - `attributes`, for the icon and the facts block: each must exist in its block.json and match its type and enum. `metadata` is refused. Use `bind`.
  - The new blocks pass three checks: every block type is registered, every comment attribute with a schema matches it, and the markup survives a parse and serialize round trip.
- **configure**
  - The block at `path` must be dynamic and save no HTML, like the icon and the facts block. Any other block is refused.
  - Each attribute must exist and match its type and enum. `null` removes one, so its default applies. `metadata` is refused.
  - With `style` in place of `attributes`, the block at `path` must be a `core/group`, and the style one of `plain`, `panel`, `inverted` and `accent`. Anything else is refused with `voyager_open_blocks_invalid_style`. The style's class replaces any other `is-style-*` class, in the block comment and on the group's saved wrapper, as the editor's Styles panel does.
  - The changed block passes the same three checks.
- **bind**
  - The attribute must be bindable on that block. The error lists the ones that are.
  - The source must be registered, and must be `voyager/site-data`, `voyager/post-meta-text` or `core/post-meta`.
  - `args` must equal the args of a field `get-catalog` lists for the post's own post type, or for `post_type` when you pass it. `post_type` is accepted only in a template, a template part, a synced pattern or a Query Loop, because a block anywhere else reads its own post's fields. For `voyager/post-meta-text`, a dot path is accepted when the part before the first dot is listed.
  - The changed block passes the same three checks. Its authored text stays as the fallback.
- **insert, configure and bind with `dry_run`**
  - Every check above runs, and a refusal is returned exactly as without `dry_run`. Nothing is saved, `saved` is `false` and `revision_id` is `null`.
- **undo**
  - `revision_id` must be a revision of `post_id`, and not an autosave, and you may edit the post. A revision of another post is refused.
  - It is refused with `voyager_open_blocks_undo_conflict` when the post was saved after `revision_id`, by anyone, and nothing changes. The error's data lists those saves in `later`, oldest first, each with `revision_id`, `author_id`, `author` and `date_gmt`. `"force": true` reverses them too, and `reversed` lists every save the undo reversed.
  - It restores the content from the revision just before `revision_id`, not the title or excerpt. As every revision restore in WordPress does, it also replaces the post meta WordPress keeps in revisions (core's footnotes, and meta a plugin registers with `revisions_enabled`) with that revision's copy. Nothing is deleted: the content it replaces is stored as a revision first, and the `revision_id` undo returns reverses the undo.
  - `matches` is `true` when the content now equals that revision byte for byte. WordPress filters the HTML of a user without the `unfiltered_html` capability, which can make it `false`.
- **validate** (read only, saves nothing)
  - Takes `markup`, or `post_id` for a saved post. Runs the three checks on every block, and bind's checks on every binding to the three sources above. Reports each block by path, with its `errors` and `warnings`.
  - The round trip allows whitespace between top-level blocks, and spellings WordPress reads the same way, such as `\u0026` for `&` or a `core/` prefix. A missing closing comment, JSON that does not parse, or a block that nests differently fails it. The response's `markup` is what WordPress would save. Compare it with yours at the block that failed.
  - HTML outside any block is an error. A binding to another registered source, such as `core/pattern-overrides`, is a warning, because its args cannot be checked. Warnings do not make `valid` false.

### What no ability checks

No ability compares a block's saved HTML with what its save() function would write. That needs the block's JavaScript, which PHP cannot run. A heading whose comment says level 3 while its HTML is an `<h2>` passes every ability and still breaks in the editor.

The pack's patterns were checked with Block Runner when they were built. `copy` changes only escaped text, `omit` removes whole blocks, and `repeat` and `parent` add whole blocks beside ones already there, in containers whose HTML does not depend on how many blocks they hold. So what `insert` saves stays safe. Use `validate` for markup `insert` did not write: a section the user pasted, or a page another tool saved. A pass means the PHP checks hold, not that the saved HTML is right. Tell the user that.

### What route 1 cannot do

Say so, and tell the user what to do in the editor, or use route 2.

- Add a block other than the pack's. `insert` places the pack's patterns, icon and facts block only, so a plain paragraph or heading to bind must already be in the post.
- Put a block inside a container that holds no blocks yet, or one that is locked. Where an empty container's HTML holds its inner blocks cannot be known without its JavaScript.
- Change an image, add a block type a pattern does not have, or change a static block's link, such as a primary button's `/contact/`.
- Reach a header, footer or template that was never saved in the Site Editor. `find-posts` lists it with `"id": null`. Ask the user to save it once.
- Read a post meta binding's value in a template. A template has no post of its own, so `get-post-blocks` there reports it as `fell_back`. Check it with `validate` and `post_type`, then on a post that uses the template.
- Make a post meta field bindable. The user does that under Settings > Site Data.

### Over REST

Each ability runs at `/wp-json/wp-abilities/v1/abilities/<name>/run`. Read-only abilities (`get-catalog`, `find-posts`, `get-post-blocks`, `validate`) take GET, with input in the query string, such as `?input[post_id]=123`. Long markup can pass the server's URL limit, often 8 KB. Validate one section at a time, or save first and pass `post_id`. The other abilities take POST with a JSON body: `{"input": {...}}`. Over MCP there is no URL limit.

## Route 2: shell and Node

This route adds two tools. Wesper reads everything the site registers. Block Runner runs each block's save() and checks the saved HTML, which route 1 cannot. When the plugin is active, you can still place and bind with the abilities. Check every markup they return with Block Runner.

### 1. Read the site (Wesper)

```bash
npx wesper collect --wp-path ./public --out site.context.json   # local install, WP-CLI collector
npx wesper summarize site.context.json
```

Use `--ssh <target>` for a remote install over WP-CLI. The REST collector (`--rest`) cannot see binding sources or registered meta, so it is not enough for binding work.

Confirm the pack is active before using it:

- `blocks.types[]` has `voyager-open/icon` and `voyager-open/facts`.
- `bindings.sources[]` has `voyager/site-data` and `voyager/post-meta-text`.
- `patterns.items[]` has the `voyager-open/*` patterns.

Missing means the plugin is inactive or older. Stop and say so. Do not write markup for blocks the site does not register.

### 2. Author native blocks (Block Runner)

Never hand-write block markup. The one exception is the self-closing comment of the icon or the facts block, because Block Runner cannot assemble a custom block (see `voyager-open-icon` and `voyager-open-facts`). Two ways:

- **Insert a pattern by reference.** `<!-- wp:pattern {"slug":"voyager-open/cta-band"} /-->` renders the current pattern and picks up plugin updates. Use it when the copy can stay as shipped or will be edited in the editor.
- **Assemble an edited copy.** Each pattern's intent tree is in `patterns/intents/<name>.json` under the `intent` key, in the plugin folder and in the repository. Copy it, change text and attributes, then:

```bash
npx block-runner assemble intent.json --json            # read .output, check .items
npx block-runner validate page.html --strict --json --context site.context.json
```

Pass `--context site.context.json` so Block Runner knows `voyager-open/icon` and `voyager-open/facts` are registered on the target. Without it, strict mode warns that the block is unregistered.

### 3. Bind

Add the binding to the block's `attrs.metadata.bindings` in the intent before you assemble, or call `bind` after the block is saved. Follow the binding rules below.

### 4. Validate every markup

Run `npx block-runner validate <file> --strict --json --context site.context.json` on every markup you wrote and every `markup` an ability returned. After an `insert` with `parent`, check its `section_markup`, the whole section the new block went into. Exit `0` is pass. Exit `1` is invalid: run `npx block-runner fix`, then validate again. Never save markup that still fails. This is the only check of saved HTML, so do it every time on this route.

### 5. Verify the bindings

Call `get-post-blocks` and read each binding's `resolution`, as in route 1 step 6, or open the rendered page.

## Binding rules

A binding lives in the block's `metadata.bindings`:

```json
{ "metadata": { "bindings": { "content": { "source": "voyager/site-data", "args": { "key": "phone" } } } } }
```

- The attribute must be bindable for that block. `core/paragraph` and `core/heading` take `content`, `core/button` takes `text` and `url`, `voyager-open/icon` takes `label` and `linkUrl`, and `voyager-open/facts` takes `items`, for the Site Data lists (`services`, `service_area`, `credentials`). On route 2, check `bindings.supportedAttributes["<block>"]` in the manifest. On route 1, `bind` refuses any other attribute and lists the bindable ones.
- Copy `args` verbatim from `get-catalog`, called with the post's type, such as `{"post_type": "post"}`. The default is `page`. In a template or template part, call it with the post type the template shows, and pass that same `post_type` to `bind`. A template has no fields of its own, so without it `bind` refuses post meta. It lists every field, including the `voyager/site-data` keys Wesper cannot see. On route 2, the manifest's `contentModel.postTypes[].fields[]` also gives post meta args ready to use.
- Leave the authored text in place. An empty value resolves to nothing, and WordPress then keeps the authored text, so placeholder copy shows until the site has real data.
- A binding replaces the whole attribute. To bind a phone number that sits inside a longer line, give it a block of its own first.

## Abilities

The plugin registers eight WordPress abilities in the `voyager-open-blocks` category. They are visible at `/wp-json/wp-abilities/v1/abilities` and, through the WordPress MCP adapter, as MCP tools.

| Ability | Method | Use it to |
|---|---|---|
| `voyager-open-blocks/get-catalog` | GET | List the pack's blocks (attribute schema, bindable attributes), patterns (named blocks, bindings, `copy: true` on the ones `insert` can rewrite) and every bindable field with its exact `args`, including the `voyager/site-data` keys Wesper cannot see. `styles` lists the section styles and their classes. `used_on` counts, for each Site Data and bindable post meta field, the published posts, templates and template parts that show it, keyed `<source>:<key>`, such as `voyager/site-data:phone`: check it before you change a value, since every page it counts changes too. Takes `post_type` (default `page`). |
| `voyager-open-blocks/find-posts` | GET | Find the `post_id` for "my Services page", "my home page" or "my header": pages, posts, templates and template parts the user can edit, by words in the title (`search`), `slug` or `front_page`. Takes `post_type` (default `page`) and `limit` (default 10, at most 20). |
| `voyager-open-blocks/get-post-blocks` | GET | Read a post's blocks with their paths, labels, own `text` and bindings. Each binding carries `resolution` (`status` `resolved` or `fell_back`, a `reason`, a `value_preview`), so you can prove a bound value arrives. |
| `voyager-open-blocks/insert` | POST | Place a pattern (a copy, or a `core/pattern` reference with `mode: "reference"`) or the icon or facts block at a top-level `position`, or inside the block at `parent`. A copy takes `copy`, plain text by label, such as `{"Heading": "Need a plumber today?"}`, `omit`, labels to leave out, such as `["Credential 4"]`, and `repeat`, new labels for copies of a block, such as `{"Credential 4": ["Credential 5"]}`. Returns the inserted `markup`, each block's `path` and `label`, `validation`, and with `parent` the whole `section_markup`. `dry_run` previews without saving. A save returns `revision_id`. |
| `voyager-open-blocks/configure` | POST | Set attributes on a block that saves no HTML (the icon, the facts block), validated against block.json, or pass `style` instead (`plain`, `panel`, `inverted` or `accent`) to put a section style on a `core/group`. `dry_run` previews without saving. A save returns `revision_id`. |
| `voyager-open-blocks/bind` | POST | Write `metadata.bindings`. The attribute must be bindable for that block, and `args` must match a listed field exactly. In a template, pass `post_type`, the post type it shows. `dry_run` previews without saving. A save returns `revision_id`. |
| `voyager-open-blocks/undo` | POST | Reverse a save: pass `post_id` and the `revision_id` that `insert`, `configure` or `bind` returned. Restores the content as it was before that save, and returns `revision_id` for the undo itself, which undo takes to put the change back. Refused when the post was saved after it, unless `force` is `true`. |
| `voyager-open-blocks/validate` | GET | Check `markup`, or a saved post by `post_id`, without saving. Reports per block: registered type, attributes against block.json, bindings against the catalog, and the round trip. Not saved HTML. Takes `post_type` to check binding args for a template. Warns `placeholder:` for each of the pack's placeholders still on the page (the logo marquee's logos, the contact section's form slot) and `missing_alt:` for any other image with no alt text. Tell the user to fix those in the editor before the page goes live. Warnings do not change `valid`. |

Errors use `voyager_open_blocks_invalid_<field>` codes (`post_id`, `post_type`, `path`, `parent`, `item`, `attributes`, `attribute`, `source`, `args`, `copy`, `omit`, `repeat`, `markup`, `revision`, `style`). `undo` also refuses with `voyager_open_blocks_undo_conflict` when the post was saved after the revision. Read the message, fix that input and retry.

[`scripts/e2e.mjs`](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/scripts/e2e.mjs) in the repository runs route 1 over REST and then checks the result with Block Runner. It is the worked example.

## Hard rules

- Do not invent credentials, testimonials, statistics or client names to fill a pattern. Use facts the site owner gave you. Otherwise leave the shipped placeholder, or `omit` its block, and say which.
- Do not edit `patterns/*.html`. They are generated from the intents.
- Patterns use theme presets only (`var:preset|spacing|*`, `fontSize: small`). On route 2, check the manifest's `theme.tokens` before adding a colour or size preset, and prefer a slug the theme defines. On route 1, keep the presets the pattern ships with.

## Try it

WordPress Playground with demo content: `blueprint.json` at the repository root, or one blueprint per item in `blueprints/`.
