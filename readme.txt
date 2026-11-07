=== Voyager Open Blocks ===
Contributors: voyagerben
Tags: ai, mcp, blocks, patterns, block bindings
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Release marker: x-release-please-start-version
Stable tag: 0.6.0
Release marker: x-release-please-end
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ready-made business sections and an icon block that show your phone, email and address from one settings screen. AI agents can place them too.

== Description ==

Voyager Open Blocks adds five ready-made page sections and an icon block to the block editor. The sections that show your business details read them from one screen, Settings > Site Data. Enter your phone number, email, address, hours and social links there once. When your phone number changes, change it there, and every section that shows it follows.

You need no code and no AI agent. Insert the sections from the "Business sections (Open Blocks)" category in the pattern inserter, like any other pattern. See every section live at [demo.voyagermark.com/open-blocks](https://demo.voyagermark.com/open-blocks/).

= What you get =

* **CTA band**: a heading and one line beside your main button and a click-to-call button that dials your phone number. Use it mid-page.
* **Proof strip**: a row of three to six checkmarked facts, such as "Licensed and insured", under your hero.
* **Logo marquee**: a row of client or partner logos that drifts sideways when it is wider than the screen. You replace the placeholder logos with your own.
* **Closing CTA**: the last section before your footer, with your main button and an email button.
* **Contact section**: your phone, email, address and hours beside a slot for your form plugin's form.
* **Icon block**: 35 outline icons, with an optional link and a CSS-only animation.
* **Settings > Site Data**: one screen for the details every section reads.

The sections use core blocks and your theme's presets, with no colours of their own, so they take your theme's look.

= Built for AI agents too =

Every item ships a `SKILL.md` that tells an agent what the item is, which attributes it takes and how to bind it. An agent reads your site, places a section, binds it to your Site Data and checks every block before it saves. Without an agent, the pack works like any other plugin: everything shows up in the block editor.

= The read, check and bind loop =

Every skill teaches an agent the same three steps:

1. **Read** the site with Wesper. Its manifest lists the blocks, binding sources, patterns, post meta and theme presets the site has, so the agent writes nothing the site cannot render.
2. **Check** every piece of block markup with Block Runner. It assembles native blocks from an intent tree and validates them strictly, so invalid markup never reaches a post.
3. **Bind** the blocks to real data through `metadata.bindings`, with the exact field arguments the pack lists.

= Abilities over REST and MCP =

Seven WordPress abilities in the `voyager-open-blocks` category let an agent use the pack on a live site without writing markup:

* `get-catalog`: the pack's block, its patterns and every bindable field with its exact arguments.
* `find-posts`: find a page, post, template or template part by words in its title, its slug, or as the home page, and get its ID. It lists only what the user can edit, and no content.
* `get-post-blocks`: a post's blocks with their paths, labels, text and bindings, and whether each binding has a value now.
* `insert`: place a pattern or the icon block in a post, at the top level or inside another block, such as an icon beside a header's call button. A pattern copy takes plain text for its named headings, paragraphs, buttons and list items, can leave named blocks out, and can repeat one, such as a fifth credential.
* `configure`: set attributes on a block that saves no HTML.
* `bind`: write a block binding. The arguments must match a listed field exactly. In a template, it checks them against the post type the template shows.
* `validate`: check block markup, or a saved post, without saving anything.

They are served over REST at `wp-abilities/v1`, and to MCP clients through the WordPress MCP Adapter, so an agent with only MCP access to your site can use the pack. Reading the catalog and finding posts need a user who can edit posts, and find-posts lists only the posts that user can edit. Every other ability needs a user who can edit the post it names.

= For theme and plugin developers =

* **Site data** (`voyager/site-data`): a block binding source for the company name, phone, email, address, hours and social links. The values are set under Settings > Site Data.
* **Post meta text** (`voyager/post-meta-text`): a block binding source for allowlisted post meta, with JSON dot paths. Fields are added under Settings > Site Data, or from a theme with `voyager_open_blocks_register_bindable_meta()`.

Recipes and the full API reference are in [docs/use-in-your-theme.md](https://github.com/voyager-marketing/voyager-open-blocks/blob/main/docs/use-in-your-theme.md) on GitHub.

= Source code =

The files in `build/` are generated from [`src/`](https://github.com/voyager-marketing/voyager-open-blocks/tree/main/src) with `@wordpress/scripts`: the icon block's minified script and styles, and the blocks manifest. No other file in the plugin is minified. The full source is on [GitHub](https://github.com/voyager-marketing/voyager-open-blocks). To rebuild `build/`, run `npm ci`, then `npm run build`.

= Credits =

The loop is built on two open tools from Human Made: [Wesper](https://github.com/humanmade/wesper), which reads a WordPress site into a manifest, and [Block Runner](https://github.com/humanmade/block-runner), which assembles and validates block markup. Icons are from [Heroicons](https://heroicons.com) (MIT).

== Installation ==

1. In wp-admin, go to Plugins > Add New Plugin, search for Voyager Open Blocks, then install and activate it. Or download `voyager-open-blocks.zip` from the [latest GitHub release](https://github.com/voyager-marketing/voyager-open-blocks/releases/latest) and upload it under Plugins > Add New Plugin > Upload Plugin.
2. Enter your business details under Settings > Site Data, so the sections have values to show. Until you do, they show placeholder text.
3. In the block editor, open the inserter, choose Patterns, then the "Business sections (Open Blocks)" category.

With WP-CLI, `wp plugin install voyager-open-blocks.zip --activate` installs the downloaded zip.

With Composer, add the GitHub repository and require the package. The repository commits `build/`, so there is no build step:

`composer config repositories.voyager-open-blocks vcs https://github.com/voyager-marketing/voyager-open-blocks`

`composer require voyager-marketing/voyager-open-blocks`

To give a coding agent the pack's skills (the loop, plus one skill per item), run `npx skills add voyager-marketing/voyager-open-blocks`.

== Frequently Asked Questions ==

= Do I need an AI agent? =

No. The sections, the icon block and Settings > Site Data work in the block editor like any other plugin. The skills and abilities are there for when you want an agent to do the work.

= Where do I enter my phone number? =

Under Settings > Site Data, with your email, street address, city, state or region, postal code, business hours and social profile URLs. Your company name and tagline come from Site Title and Tagline under Settings > General. Every block bound to a field shows the new value once you save.

= Will this work with my theme? =

Yes, if your site uses the block editor. The sections are built from core blocks (group, columns, heading, paragraph, buttons, list and image) and use your theme's spacing and font size presets. They set no colours of their own, so they take your theme's look.

= What happens when a Site Data field is empty? =

The block shows the section's own placeholder text instead, such as "Call us" or a 555 phone number, and the editor warns you that the value is empty. Visitors see that placeholder too. Fill in the field, or delete the line you don't need, before you publish.

= How do I add my form to the contact section? =

The pack ships no form. Replace what is inside the group named "Form" with your form plugin's block, and keep its heading if it still fits. Until you do, the slot holds an email button that follows your Site Data email.

= Can an agent use it with only MCP access? =

Yes. With the WordPress MCP Adapter on your site, the seven abilities appear as MCP tools. An agent with no shell and no command-line tools can find the page or header you name, read the catalog, insert a section or an icon inside one, bind it and check that each value resolves.

= Does it call an AI service? =

No. The plugin makes no AI calls and no external requests. An agent you run calls its abilities, with the permissions of the user it signs in as.

= Does it need the voyager-blocks plugin? =

No. The pack registers its own block, binding sources and patterns, and works on any site running WordPress 6.9 or later.

= What happens when voyager-blocks is active? =

The two binding sources keep the names they have in voyager-blocks, so content written for either plugin resolves on both. voyager-blocks registers them first, and this plugin stands aside. `get-catalog` and `bind` then use the owning plugin's fields, read from its `voyager_binding_sources` definition.

= Who can use the abilities? =

Users who can edit posts can read the catalog and find posts, and find-posts shows them only the posts they can edit. Templates and template parts need permission to edit the theme. Reading a post's blocks, inserting, configuring and binding all need permission to edit that post.

= Where are the skills? =

In the plugin's `skills/` folder, one `SKILL.md` per item in the open Agent Skills layout. Start an agent at `skills/voyager-open-blocks/SKILL.md`.

== Screenshots ==

1. CTA band: a heading and one line beside a main button and a click-to-call button that follows your Site Data phone number.
2. Proof strip: a ruled row of checkmarked credentials under a hero.
3. Logo marquee: a row of logos that drifts sideways when it is wider than the screen.
4. Closing CTA: a centred closing section with a main button and an email button.
5. Contact section: phone, email, address and hours from Site Data beside a form slot.
6. Icon block: outline icons, plain, linked and animated.
7. Settings > Site Data: one screen for the business details every section reads.

== Changelog ==

= 0.6.1 =
* WordPress.org's Plugin Check reports no errors on the plugin: the abilities read a block's text with `wp_strip_all_tags()`.
* The readme says where the source of the built files is, and how to rebuild them.

= 0.6.0 =
* Settings > Site Data says which sections use each field, and after activation a notice points you there until you fill one in. The Plugins screen has a Settings link.
* The block editor shows where a bound value comes from, warns when it is empty, and marks the placeholder logos and the form slot to replace.
* The patterns sit in the "Business sections (Open Blocks)" category.
* Seven abilities: new `find-posts` and `validate`. `insert` takes `omit`, `repeat` and `parent`, and `bind` takes `post_type` in templates, so an agent with only MCP access can find a page, place and fill a section, and check it.
* Uninstalling removes the notice state and keeps your Site Data.

= 0.3.0 =
* `insert` takes `copy`: plain text by label for a pattern's named headings, paragraphs, buttons and list items. `get-catalog` marks those labels `copy: true`.

= 0.2.0 =
* `get-catalog` and `bind` use the owning plugin's fields for the binding sources voyager-blocks owns, read from its `voyager_binding_sources` definition. Before, `bind` refused site data on sites running voyager-blocks.

= 0.1.0 =
* First release: the icon block, the site data and post meta text binding sources, five patterns, a `SKILL.md` for each, Playground blueprints, and the five abilities over REST and MCP.
