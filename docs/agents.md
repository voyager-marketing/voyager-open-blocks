# Agents: the long guide

An agent starts at the entry skill, [`skills/voyager-open-blocks/SKILL.md`](../skills/voyager-open-blocks/SKILL.md): a card under 500 words with the one path to a page and the five rules. The detail of each call is in that ability's own description, which an agent reads anyway as the tool definition. Each item's skill, such as [`voyager-open-hero`](../skills/voyager-open-hero/SKILL.md), has its structure, copy rules and prompt. This page holds the rest: the second route, the limits of the first, what each ability checks, and what to do on an older plugin. It is for people, and for an agent with a shell that wants the whole picture.

## The two routes

Every task reads the site, places blocks, binds them and checks them. Check your tools, then follow one route for the whole task.

- **Route 1: abilities only.** The agent calls the site's abilities over MCP or REST and cannot run shell commands. Most chat assistants are here.
- **Route 2: shell and Node.** The agent can run `npx` with Node 22 or later and reach the site with WP-CLI, locally or over SSH. This route adds Wesper, which reads everything the site registers, and Block Runner, which checks saved HTML.

The binding rules and the five rules in the entry skill hold on both.

## Design rules

Read them before you choose a section style, write copy or change a layout. If the site has its own `DESIGN.md`, read it first: it is often at the active theme's root, and on route 1 you ask the user for it. Where the site's file and the pack's disagree, the site's wins. Then read the pack's [DESIGN.md](../DESIGN.md), which covers what the site's leaves open. It ships with the plugin, at the root of its folder (`wp-content/plugins/voyager-open-blocks/DESIGN.md`), so it matches the version installed.

A section's style is one of Plain, Panel, Inverted and Accent. `get-catalog` lists the three that are block styles under `styles`, each with its class; Plain is no style. Two sections next to each other never share a style other than Plain.

## Route 1 over REST

Each ability runs at `/wp-json/wp-abilities/v1/abilities/<name>/run`. The read-only abilities (`get-catalog`, `find-posts`, `get-post-blocks`, `validate`) take GET, with input in the query string, such as `?input[post_id]=123`. Long markup can pass the server's URL limit, often 8 KB: validate one section at a time, or save first and pass `post_id`. The other abilities take POST with a JSON body, `{"input": {...}}`. Over MCP there is no URL limit. Inputs, permissions and return values are in the [API reference](use-in-your-theme.md#abilities).

[`scripts/e2e.mjs`](../scripts/e2e.mjs) runs route 1 over REST and then checks the result with Block Runner. It is the worked example.

## What route 1 cannot do

Say so, and tell the user what to do in the editor, or use route 2.

- Add a block other than the pack's. `insert` places the pack's patterns and its icon, facts and contact details blocks only, so a plain paragraph or heading to bind must already be in the post.
- Put a block inside a container that holds no blocks yet, or one that is locked. Where an empty container's HTML holds its inner blocks cannot be known without its JavaScript.
- Change an image, add a block type a pattern does not have, or change a static block's link, such as a primary button's `/contact/`.
- Reach a header, footer or template that was never saved in the Site Editor. `find-posts` lists it with `"id": null`. Ask the user to save it once.
- Read a post meta binding's value in a template. A template has no post of its own, so `get-post-blocks` there reports it as `fell_back`. Check it with `validate` and `post_type`, then on a post that uses the template.
- Make a post meta field bindable. The user does that under Settings > Site Data.

## What no ability checks

No ability compares a block's saved HTML with what its `save()` function would write. That needs the block's JavaScript, which PHP cannot run. A heading whose comment says level 3 while its HTML is an `<h2>` passes every ability and still breaks in the editor.

The pack's patterns were checked with Block Runner when they were built. `copy` changes only escaped text, `omit` removes whole blocks, and `repeat` and `parent` add whole blocks beside ones already there, in containers whose HTML does not depend on how many blocks they hold. So what `insert` and `compose` save stays safe. Use `validate` for markup they did not write: a section the user pasted, or a page another tool saved. A pass means the PHP checks hold, not that the saved HTML is right, and on route 1 the agent tells the user so.

## What the abilities check

These checks run before anything is saved. When one fails, nothing is saved, and the error names the input to fix: `voyager_open_blocks_invalid_<field>`, where the field is `post_id`, `post_type`, `path`, `parent`, `item`, `attributes`, `attribute`, `source`, `args`, `copy`, `omit`, `repeat`, `markup`, `revision`, `style`, `layout`, `recipe`, `sections` or `task`. Read the message, fix that input and retry.

- **find-posts** (read only)
  - Lists only posts the user can both edit and read, with no content: `id`, `title`, `slug`, `post_type`, `status`, `link`, `front_page`, and for templates `source` and, for template parts, `area`. At most 20 at a time, 10 by default.
  - Searches titles only, plus slugs for templates. A post type must be shown in REST and edited in the block editor. Templates and template parts need the right to edit the theme.
- **insert**
  - `item` is a registered `voyager-open/*` block or pattern, the post exists, and you may edit it.
  - `copy`, `omit` and `repeat` work only on a pattern inserted as a copy (`mode: "copy"`, the default). Each label must name a block in the pattern. A `copy` label must be on a heading, paragraph, button, list item or details block, whose text is its summary (an FAQ question). Its text is escaped and replaces everything between that element's tags. An `omit` label removes that block and everything inside it, but never the whole pattern. A `repeat` label must be on one block inside the pattern, and every labelled block inside it must have a label that starts with its own and a space (`Service 3 heading` inside `Service 3`). In each copy that start becomes the new label (`Service 4 heading`). The new labels, and the ones they give the blocks inside, must be unused, and nothing in the block may have an HTML anchor. `repeat` runs first, then `omit`, then `copy`.
  - A `copy` value is plain text, or `{"text": "...", "source": "user"}` for text the site owner gave you. The object takes those two keys and no other, and `source` takes only `user`. Empty text is refused.
  - **Copy rules.** `get-catalog` gives each pattern's `copy_rules`: each label's `purpose`, `min` and `max` characters, and whether it is `required` or a `claim`. `insert` checks the `copy` and `omit` you give against them, and refuses the whole call when any slot breaks one. The error lists every refused slot in `data.refused`, not only the first, each with `slot`, `reason` and a `message` that says how to fix it:
    - `too_long`: the text, trimmed, is longer than `max` characters (with `max` and `length`). Shorten it. Text under `min` is accepted: `min` is guidance.
    - `claim`: a claim slot got a plain string. Its text comes from the site owner, never from you. Pass what they gave you as the object form, or leave the slot out of `copy` and bind the block to the Site Data field that holds it. A plain string is refused even when it equals a Site Data value, because typed-in text goes stale when the value changes.
    - `required`: `omit` names a required slot, or a block that holds one (with `omitted`, the label you omitted). Leaving out a whole item, such as `Service 3` with its heading and text, is not refused.
    - `bound`: copy for a slot whose text comes from a binding (with `block`, `path`, `attribute`, `source` and `key`). Change the value in Settings > Site Data instead.
  - The code is `voyager_open_blocks_bound_slot` when every refused slot is bound, and `voyager_open_blocks_copy_rules` otherwise. A slot with no rule is not checked, and neither is the text a pattern ships with, so a pattern inserted with no `copy` keeps its placeholders.
  - With `parent`, the block at that path must already hold at least one block, must allow the new block (its block.json `allowedBlocks`, or its own `allowedBlocks` setting), and must not be locked (`templateLock` `all`, `insert` or `contentOnly`, its own or inherited). Each new block's block.json `parent` and `ancestor` rules must hold there. A block whose `parent` rule names a container cannot go at the top level.
  - Inside a parent, the section that holds it must still survive a parse and serialize round trip (`validation.section_round_trip`). The response's `section_markup` is that whole section.
  - `attributes`, for the icon, the facts and the contact details block: each must exist in its block.json and match its type and enum. `metadata` is refused. Use `bind`.
  - The new blocks pass three checks: every block type is registered, every comment attribute with a schema matches it, and the markup survives a parse and serialize round trip.
- **compose** builds each section as `insert` builds a pattern copy, with the same code, and checks it the same way: a section's `copy`, `omit` and `repeat` work and are refused as `insert`'s are. A recipe section may carry `omit`, the blocks its page type does without, such as the contact page's hero button: pass it on. It returns the refused rows in `refused`, each with its `section`, and saves nothing. It refuses a page that is not a draft, both `post_id` and `title`, `recipe` with `sections`, a slug no recipe has, and a layout no intent of the section carries. Over REST or MCP the input schema refuses an unknown slug first, with `ability_invalid_input`.
- **configure**
  - The block at `path` must be dynamic and save no HTML, like the icon, the facts and the contact details block. Any other block is refused.
  - Each attribute must exist and match its type and enum. `null` removes one, so its default applies. `metadata` is refused.
  - With `style` in place of `attributes`, the block at `path` must be a `core/group`, and the style one of `plain`, `panel`, `inverted` and `accent`. Anything else is refused with `voyager_open_blocks_invalid_style`. The style's class replaces any other `is-style-*` class, in the block comment and on the group's saved wrapper, as the editor's Styles panel does.
  - The changed block passes the same three checks.
- **bind**
  - The attribute must be bindable on that block. The error lists the ones that are.
  - The source must be registered, and must be `voyager/site-data`, `voyager/post-meta-text` or `core/post-meta`.
  - `args` must equal the args of a field `get-catalog` lists for the post's own post type, or for `post_type` when you pass it. `post_type` is accepted only in a template, a template part, a synced pattern or a Query Loop, because a block anywhere else reads its own post's fields. For `voyager/post-meta-text`, a dot path is accepted when the part before the first dot is listed.
  - The changed block passes the same three checks. Its authored text stays as the fallback.
- **insert, compose, configure and bind with `dry_run`**
  - Every check above runs, and a refusal is returned exactly as without `dry_run`. Nothing is saved, `saved` is `false` and `revision_id` is `null`.
- **undo**
  - `revision_id` must be a revision of `post_id`, and not an autosave, and you may edit the post. A revision of another post is refused.
  - It is refused with `voyager_open_blocks_undo_conflict` when the post was saved after `revision_id`, by anyone, and nothing changes. The error's data lists those saves in `later`, oldest first, each with `revision_id`, `author_id`, `author` and `date_gmt`. `"force": true` reverses them too, and `reversed` lists every save the undo reversed. Force it only when every save in `later` is one your own calls returned. When one is not yours, someone else edited the page: stop and tell the user.
  - It restores the content from the revision just before `revision_id`, not the title or excerpt. As every revision restore in WordPress does, it also replaces the post meta WordPress keeps in revisions (core's footnotes, and meta a plugin registers with `revisions_enabled`) with that revision's copy. Nothing is deleted: the content it replaces is stored as a revision first, and the `revision_id` undo returns reverses the undo.
  - `revision_id: null` from a save means it made no revision, because nothing changed, the post is an auto-draft, or the site keeps no revisions for it. `revision_note` says which, and that save cannot be undone.
  - `matches` is `true` when the content now equals that revision byte for byte. WordPress filters the HTML of a user without the `unfiltered_html` capability, which can make it `false`.
- **validate** (read only, saves nothing)
  - Takes `markup`, or `post_id` for a saved post. Runs the three checks on every block, and bind's checks on every binding to the three sources above. Reports each block by path, with its `errors` and `warnings`.
  - The round trip allows whitespace between top-level blocks, and spellings WordPress reads the same way, such as `&` for `&` or a `core/` prefix. A missing closing comment, JSON that does not parse, or a block that nests differently fails it. The response's `markup` is what WordPress would save. Compare it with yours at the block that failed.
  - HTML outside any block is an error. A binding to another registered source, such as `core/pattern-overrides`, is a warning, because its args cannot be checked. Warnings do not make `valid` false.
  - `stale_fallback:` warns on a block bound to a phone field (a key with a whole part such as `phone`, `tel`, `mobile`, `cell`, `fax`, `whatsapp` or `telefono`) whose own text looks like a phone number, or to an email field (`email`, `mail` or `correo`) whose own text looks like an email address. Visitors see that text whenever the source returns nothing.
  - `placeholder:` warns on each of the pack's placeholder images still on the page, in a `core/image` or a `core/media-text` block or as a `core/cover`'s background, matched by a URL that ends in `assets/placeholders/` and a file the pack ships, on any host, and on the contact section's stand-in for a form while it is still in its Form slot column. It also warns on each text slot of a numbered item, such as the proof strip's "Credential 4" or a review's "Review 2 quote", that still holds the whole stand-in text the pack ships there, such as "Your fourth credential". An item's title, its first and short slot such as a service's heading or a review's rating, is let off when every other slot of the item holds the site's own text, since a real item can share its title with the pack's. A line, an answer or a quote left at the pack's text always warns. `missing_alt:` warns on every other `core/image` or `core/media-text` image with empty or missing alt text. A cover's background is decoration, so a cover never gets `missing_alt:`.

## Binding rules

A binding lives in the block's `metadata.bindings`:

```json
{ "metadata": { "bindings": { "content": { "source": "voyager/site-data", "args": { "key": "phone" } } } } }
```

- The attribute must be bindable for that block. `core/paragraph` and `core/heading` take `content`, `core/button` takes `text` and `url`, `voyager-open/icon` takes `label` and `linkUrl`, `voyager-open/facts` takes `items`, for the Site Data lists (`services`, `service_area`, `credentials`), and `voyager-open/contact-details` takes all seven of its attributes, for the contact fields (`phone`, `phone_link`, `email`, `email_link`, `address`, `address_locality`, `hours`). On route 2, check `bindings.supportedAttributes["<block>"]` in the Wesper manifest.
- Copy `args` verbatim from `get-catalog`, called with the post's type, such as `{"post_type": "post"}`. The default is `page`. In a template or template part, call it with the post type the template shows, and pass that same `post_type` to `bind`. It lists every field, including the `voyager/site-data` keys Wesper cannot see. On route 2, the manifest's `contentModel.postTypes[].fields[]` also gives post meta args ready to use.
- Leave the authored text in place. An empty value resolves to nothing, and WordPress then keeps the authored text, so placeholder copy shows until the site has real data.
- A binding replaces the whole attribute. To bind a phone number that sits inside a longer line, give it a block of its own first.

## Route 2: shell and Node

This route adds two tools. Wesper reads everything the site registers. Block Runner runs each block's `save()` and checks the saved HTML, which route 1 cannot. When the plugin is active, you can still place and bind with the abilities. Check every markup they return with Block Runner.

### 1. Read the site (Wesper)

```bash
npx wesper collect --wp-path ./public --out site.context.json   # local install, WP-CLI collector
npx wesper summarize site.context.json
```

Use `--ssh <target>` for a remote install over WP-CLI. The REST collector (`--rest`) cannot see binding sources or registered meta, so it is not enough for binding work.

Confirm the pack is active before using it:

- `blocks.types[]` has `voyager-open/icon`, `voyager-open/facts` and `voyager-open/contact-details`.
- `bindings.sources[]` has `voyager/site-data` and `voyager/post-meta-text`.
- `patterns.items[]` has the `voyager-open/*` patterns.

Missing means the plugin is inactive or older. Stop and say so. Do not write markup for blocks the site does not register.

### 2. Author native blocks (Block Runner)

Never hand-write block markup. The one exception is the self-closing comment of the icon, the facts or the contact details block, because Block Runner cannot assemble a custom block (see their skills). Two ways:

- **Insert a pattern by reference.** `<!-- wp:pattern {"slug":"voyager-open/cta-band"} /-->` renders the current pattern and picks up plugin updates. Use it when the copy can stay as shipped or will be edited in the editor.
- **Assemble an edited copy.** Each pattern's intent tree is in `patterns/intents/<name>.json` under the `intent` key, in the plugin folder and in the repository. Copy it, change text and attributes, then:

```bash
npx block-runner assemble intent.json --json            # read .output, check .items
npx block-runner validate page.html --strict --json --context site.context.json
```

Pass `--context site.context.json` so Block Runner knows the pack's blocks are registered on the target. Without it, strict mode warns that each one is unregistered.

Patterns use theme presets only (`var:preset|spacing|*`, `fontSize: small`). Check the manifest's `theme.tokens` before adding a colour or size preset, and prefer a slug the theme defines. Do not edit `patterns/*.html`: they are generated from the intents.

### 3. Bind

Add the binding to the block's `attrs.metadata.bindings` in the intent before you assemble, or call `bind` after the block is saved. Follow the binding rules above.

### 4. Validate every markup

Run `npx block-runner validate <file> --strict --json --context site.context.json` on every markup you wrote and every `markup` an ability returned. After an `insert` with `parent`, check its `section_markup`, the whole section the new block went into. Exit `0` is pass. Exit `1` is invalid: run `npx block-runner fix`, then validate again. Never save markup that still fails. This is the only check of saved HTML, so do it every time on this route.

### 5. Verify the bindings

Call `get-post-blocks` and read each binding's `resolution`, or open the rendered page.

## On an older plugin

The entry skill's `pack-version` names the pack it describes. On a site whose plugin is older:

- No `voyager-open-blocks/*` ability at all: the plugin is inactive or too old. Stop and say so.
- No `find-posts`: ask the user for the post ID, the number after `post=` in the address bar while the page is open in the editor.
- No `recipes` in `get-catalog`, or no `compose`: take the sections and styles from the entry skill's recipe table. On an empty page, `insert` each pattern at the end (`position` `-1`), top to bottom, then `configure` each section's style. A plain section needs no `configure` call.
- No `validate` or no `undo`: skip that step, and tell the user the page was not checked or cannot be undone from here.

## Try it

WordPress Playground with demo content: [`blueprint.json`](../blueprint.json) at the repository root, or one blueprint per item in [`blueprints/`](../blueprints/). The links are in the [README](../README.md#what-is-in-the-pack).
