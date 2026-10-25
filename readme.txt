=== Voyager Open Blocks ===
Contributors: voyagerben
Tags: ai, mcp, blocks, patterns, block bindings
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agent-ready blocks, binding sources and patterns. Each ships a SKILL.md, so an AI agent can read the site, author native blocks and bind real data.

== Description ==

Voyager Open Blocks is a small pack of WordPress blocks, block binding sources and patterns that an AI agent can use correctly. Each item ships a `SKILL.md` that tells an agent what the item is, which attributes it takes and how to bind it. Without an agent, the pack works like any other: everything shows up in the block editor.

= What is in the pack =

* **Icon** (`voyager-open/icon`): a dynamic block with 35 outline icons, an optional link and CSS-only animation.
* **Site data** (`voyager/site-data`): a binding source for the company name, phone, email, address, hours and social links. The values are set under Settings > Site Data.
* **Post meta text** (`voyager/post-meta-text`): a binding source for allowlisted post meta, with JSON dot paths.
* **Five patterns:** CTA band, proof strip, logo marquee, closing CTA and contact section. They use core blocks and the active theme's presets only, so the theme decides how they look.

= The read, check and bind loop =

Every skill teaches an agent the same three steps:

1. **Read** the site with Wesper. Its manifest lists the blocks, binding sources, patterns, post meta and theme presets the site has, so the agent writes nothing the site cannot render.
2. **Check** every piece of block markup with Block Runner. It assembles native blocks from an intent tree and validates them strictly, so invalid markup never reaches a post.
3. **Bind** the blocks to real data through `metadata.bindings`, with the exact field arguments the pack lists.

= Abilities over REST and MCP =

Five WordPress abilities in the `voyager-open-blocks` category let an agent use the pack on a live site without writing markup:

* `get-catalog`: the pack's block, its patterns and every bindable field with its exact arguments.
* `get-post-blocks`: a post's blocks with their paths, labels and bindings.
* `insert`: place a pattern or the icon block in a post. A pattern copy takes plain text for its named headings, paragraphs, buttons and list items.
* `configure`: set attributes on a block that saves no HTML.
* `bind`: write a block binding. The arguments must match a listed field exactly.

They are served over REST at `wp-abilities/v1`, and to MCP clients through the WordPress MCP Adapter. Reading the catalog needs a user who can edit posts. Every other ability needs a user who can edit the post it names.

= Credits =

The loop is built on two open tools from Human Made: [Wesper](https://github.com/humanmade/wesper), which reads a WordPress site into a manifest, and [Block Runner](https://github.com/humanmade/block-runner), which assembles and validates block markup. Icons are from [Heroicons](https://heroicons.com) (MIT).

== Installation ==

1. In wp-admin, go to Plugins > Add New Plugin, search for Voyager Open Blocks, then install and activate it. Or download `voyager-open-blocks.zip` from the [latest GitHub release](https://github.com/voyager-marketing/voyager-open-blocks/releases/latest) and upload it under Plugins > Add New Plugin > Upload Plugin.
2. Optional: enter the business details under Settings > Site Data, so the site data bindings have values to show.

With WP-CLI, `wp plugin install voyager-open-blocks.zip --activate` installs the downloaded zip.

With Composer, add the GitHub repository and require the package. The repository commits `build/`, so there is no build step:

`composer config repositories.voyager-open-blocks vcs https://github.com/voyager-marketing/voyager-open-blocks`

`composer require voyager-marketing/voyager-open-blocks`

To give a coding agent the pack's skills (the loop, plus one skill per item), run `npx skills add voyager-marketing/voyager-open-blocks`.

== Frequently Asked Questions ==

= Does it need the voyager-blocks plugin? =

No. The pack registers its own block, binding sources and patterns, and works on any site running WordPress 6.9 or later.

= What happens when voyager-blocks is active? =

The two binding sources keep the names they have in voyager-blocks, so content written for either plugin resolves on both. voyager-blocks registers them first, and this plugin stands aside. `get-catalog` and `bind` then use the owning plugin's fields, read from its `voyager_binding_sources` definition.

= Does it call an AI service? =

No. The plugin makes no AI calls and no external requests. An agent you run calls its abilities, with the permissions of the user it signs in as.

= Who can use the abilities? =

Users who can edit posts can read the catalog. Reading a post's blocks, inserting, configuring and binding all need permission to edit that post.

= Where are the skills? =

In the plugin's `skills/` folder, one `SKILL.md` per item in the open Agent Skills layout. Start an agent at `skills/voyager-open-blocks/SKILL.md`.

== Changelog ==

= 0.3.0 =
* `insert` takes `copy`: plain text by label for a pattern's named headings, paragraphs, buttons and list items. `get-catalog` marks those labels `copy: true`.

= 0.2.0 =
* `get-catalog` and `bind` use the owning plugin's fields for the binding sources voyager-blocks owns, read from its `voyager_binding_sources` definition. Before, `bind` refused site data on sites running voyager-blocks.

= 0.1.0 =
* First release: the icon block, the site data and post meta text binding sources, five patterns, a `SKILL.md` for each, Playground blueprints, and the five abilities over REST and MCP.
