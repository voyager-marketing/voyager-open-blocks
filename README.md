# Voyager Open Blocks

[![CI](https://github.com/voyager-marketing/voyager-open-blocks/actions/workflows/ci.yml/badge.svg)](https://github.com/voyager-marketing/voyager-open-blocks/actions/workflows/ci.yml)
[![Block Check](https://github.com/voyager-marketing/voyager-open-blocks/actions/workflows/block-check.yml/badge.svg)](https://github.com/voyager-marketing/voyager-open-blocks/actions/workflows/block-check.yml)
[![Latest release](https://img.shields.io/github/v/release/voyager-marketing/voyager-open-blocks)](https://github.com/voyager-marketing/voyager-open-blocks/releases/latest)

Voyager Open Blocks adds twelve ready-made page sections (the hero, the services section, the reviews section, the process steps, the stats section, the FAQ and the service area come in two layouts each), an icon block, a facts block and a contact details block to WordPress. The sections that show your business details, such as your phone number, email, address and hours, read them from one settings screen. Change your phone number there and every page that shows it changes too. Everything is also built so an AI agent can place it, fill it in and check it correctly.

**See it first:** browse the [live gallery](https://demo.voyagermark.com/open-blocks/), or [open it in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprint.json) with demo content and nothing to install.

Requires WordPress 6.9+ and PHP 8.1+. Free under the GPL.

Pick the door that fits you:

- [Use it in the editor](#use-it-in-the-editor): you build pages in WordPress and write no code.
- [Build with it](#build-with-it): you make themes, patterns or plugins.
- [Give it to your agent](#give-it-to-your-agent): an AI agent edits your site.

## Use it in the editor

Install the plugin, fill in one settings screen, then insert sections like any other pattern.

1. **Install.** Download `voyager-open-blocks.zip` from the [latest release](https://github.com/voyager-marketing/voyager-open-blocks/releases/latest). In wp-admin, go to Plugins > Add New Plugin > Upload Plugin, choose the zip, then activate it.
2. **Add your details.** Go to Settings > Site Data. Fill in your phone, email, address, hours and social links. Your company name and tagline come from Site Title and Tagline under Settings > General.
3. **Insert a section.** In the block editor, open the inserter, choose Patterns, then the "Business sections (Open Blocks)" category. The icon, facts and contact details blocks are in the block list as "Icon", "Facts" and "Contact details".

| Section | Use it for | What you replace |
|---|---|---|
| Hero | The top of a page. The page heading, one line, your main button and a click-to-call button, beside an image ("Hero") or over a background image ("Hero over a background"). | The small label above the heading, the heading, the line, the main button's text and link, and the grey placeholder image, with your own photo. On the background, set the focal point on the photo's subject. The call button follows your phone number. |
| Services | What you do, under your hero: a heading, one line, and three services, each an icon, a name and one line, as cards in a row ("Services") or as rows ("Services as a list"). | The heading, the line, and each service's name, line and icon. Add a fourth service, or more in the list, or drop one. |
| Reviews | What customers say: a heading and three reviews as cards ("Reviews"), or one review as a large quote ("Reviews as one large quote"). Each shows its star rating in words, the quote, the customer's name and one detail. | Every placeholder: each quote, name, detail and rating, with real reviews your customers agreed to share, in their own words. For a four-star review, delete the fifth star. Add up to six reviews, or drop one. |
| Process steps | What happens once a visitor gets in touch, in order: a heading, one line, and three numbered steps, each a heading that starts with its number and one line, side by side ("Process steps") or one under another ("Process steps as a list"). | The heading, the line, and each step's heading and line. Keep the number at the start of each step's heading. Add a fourth or fifth step, or drop one, then renumber the headings so they count up from 1. |
| Stats | Three figures from Site Data: the year you started, your average rating and your review count, in a row ("Stats") or beside a heading and one line ("Stats beside a heading"). Each figure follows Settings > Site Data, so a page never shows a number you did not enter. | The labels under the figures, and the band's heading and line. The figures come from Year founded, Rating and Review count under Settings > Site Data: until a field is filled in, its figure shows its own text, which names the field, so fill it in or delete that figure before the page goes live. |
| FAQ | The questions visitors ask before they get in touch, each opening to show its answer: a heading and three questions one under another ("FAQ"), or beside the heading ("FAQ beside its heading"). | The heading, and each question and its answer, with what your customers really ask and your own answers. Add up to eight questions, or drop one. |
| Service area | Where you work: a heading, one line, the towns you serve as a checkmarked list and one button, on its own ("Service area") or beside an image ("Service area beside an image"). The towns come from the Service area field on Settings > Site Data. | The heading, the line, and the button's text and link. Beside an image, the grey placeholder image, with your own photo, and set its focal point on the subject. The towns follow your Site Data service area, one per line. |
| CTA band | Asking the reader to act mid-page, after your services. A heading and one line beside your main button and a click-to-call button. | The small label above the heading, the heading, the line, and the main button's text and link. The call button follows your phone number. |
| Proof strip | A row of three to six checkmarked facts under your hero, such as "Licensed and insured". | The four placeholder facts. Use only true ones, and delete the items you don't need. |
| Logo marquee | A row of client, partner or certification logos. It drifts sideways when it is wider than the screen. | The six grey placeholder logos, with your own images. Set each image's alt text to the company's name. |
| Closing CTA | The last section before your footer. A heading, one line, your main button and an email button. | The heading, the line, and the main button's text and link. The email button follows your email address. |
| Contact section | Your contact page. Phone, email, address and hours beside a form slot. | The form slot: put your form plugin's block inside the group named "Form". Until you do, it holds an email button. |
| Icon block | One of 35 outline icons, with an optional link and animation. | The icon. Give it a label when it means something or is a link. |
| Facts block | A list of short facts, one per line. Connected to your services, service area or credentials, it shows that list from Site Data. | Its items, which visitors see while the connected list is empty. |
| Contact details block | Your address, phone, email and hours. Connected to Site Data, the phone dials and the email opens a new message when a visitor taps them. | Its own labels, which visitors see while a connected field is empty. |

The main buttons link to `/contact/` as shipped. Point them at a page your site has.

**When a Site Data field is empty**, a block bound to it shows the section's own placeholder text instead, such as "Call us" or a 555 number, and the editor warns you that the value is empty. Visitors see that placeholder too. Fill in the field, or delete the line you don't need, before you publish.

**Your own blocks can follow Site Data too.** Select a paragraph, heading or button, then connect it to a Site data field in the Attributes panel of the block settings.

## Build with it

Bind core blocks to Site Data or to a page's own fields from your theme, and drive the pack from code.

- **Recipes and API reference:** [docs/use-in-your-theme.md](docs/use-in-your-theme.md) has three copyable recipes (bind a block in a template, make a post meta field bindable, ship your own pattern) and a reference of every function, option, filter, binding source, ability and REST setting.
- **Quick start:** `npm run dev` boots WordPress Playground with your checkout mounted, the plugin active, demo Site Data and the demo pages.

  ```bash
  git clone https://github.com/voyager-marketing/voyager-open-blocks.git
  cd voyager-open-blocks
  npm ci
  npm run dev
  ```

- **Abilities:** nine WordPress abilities (`get-catalog`, `find-posts`, `get-post-blocks`, `insert`, `compose`, `configure`, `bind`, `undo`, `validate`) read the pack, find the post to change, place, configure and bind its blocks, build a whole draft page in one call, and check markup over REST at `wp-abilities/v1`. `bind` accepts only args that `get-catalog` lists, so nothing can invent a field.

To work on the pack itself, read [CONTRIBUTING.md](CONTRIBUTING.md), [docs/development.md](docs/development.md) and the starter tasks in [docs/good-first-issues.md](docs/good-first-issues.md).

## Give it to your agent

Install the skills, then give your agent a prompt. It reads your site first, checks every block before it saves, and tells you which values are still empty.

```bash
npx skills add voyager-marketing/voyager-open-blocks
```

That adds an entry skill, which teaches the read, check and bind loop, and one skill per item. The skills follow the open Agent Skills layout (`skills/<name>/SKILL.md`), so any harness that reads that layout can load them. Start an agent at [skills/voyager-open-blocks](skills/voyager-open-blocks/SKILL.md).

The general prompt, with your task in the brackets:

> Use the Voyager Open Blocks skills on my site. Read the site first, then [task]. Check every block before you save, and tell me which values come from Site Data and which are still empty.

One prompt per item:

| Item | Prompt |
|---|---|
| Hero | Open my home page with the Voyager Open Blocks hero, image beside text. Write the heading and the line for my business, keep the call button bound to my Site Data phone number, and tell me which image to put in the slot. |
| Services | Add the Voyager Open Blocks services section to my home page under the proof strip, as cards. Use these services: [three to six services, each with one line]. Pick an icon for each from the pack's set. |
| Reviews | Add the Voyager Open Blocks reviews section to my home page, above the closing CTA. Use these reviews, exactly as written: [each review's quote, the customer's name as they agreed to show it, the service or the month and year, and the star rating they gave]. Use only what I give you. |
| Process steps | Add the Voyager Open Blocks process steps to my Services page, before the call to action. Use these steps, in this order: [three to five steps, each what happens and one line on it]. Number them in order, and make no promise I did not give you. |
| Stats | Add the Voyager Open Blocks stats section to my home page, above the closing CTA. Use only the year founded, rating and review count I have under Settings > Site Data, and leave out any that are empty. Tell me which ones are. |
| FAQ | Add the Voyager Open Blocks FAQ to my [service] page, before the closing CTA. Use these questions and answers: [three to eight, each a question as a customer asks it and my answer]. Use only the facts I give you. |
| Service area | Add the Voyager Open Blocks service area section to my home page, as a list. Keep the places bound to my Site Data service area, and tell me if it is still empty. |
| CTA band | Add the Voyager Open Blocks CTA band near the end of my Services page. Write the heading and the line of copy for that page, keep the click-to-call button bound to my Site Data phone number, and check the blocks before you save. |
| Proof strip | Add the Voyager Open Blocks proof strip under the hero on my home page with these credentials: [three to six short facts]. Use only what I give you. |
| Logo marquee | Add the Voyager Open Blocks logo marquee to my home page and replace the placeholder logos with these images from my media library: [image names]. |
| Closing CTA | End my About page with the Voyager Open Blocks closing CTA. Rewrite the heading and the line for that page, and keep the email button bound to my Site Data email. |
| Contact section | Add the Voyager Open Blocks contact section to my Contact page. Keep the phone, email, address and hours bound to Site Data, and tell me which Site Data fields are still empty. |
| Icon | Add a Voyager Open Blocks phone icon next to the call button in my header, linked to my Site Data phone number. |
| Facts | Add a Voyager Open Blocks facts list of my services to my Services page, bound to my Site Data services. Tell me if the services are still empty. |
| Contact details | Add a Voyager Open Blocks contact details block to my Contact page, bound to my Site Data phone, email, address and hours. Tell me which of those fields are still empty. |
| Site data source | Bind the phone number in my footer to Voyager Open Blocks Site Data so it follows Settings > Site Data. |
| Post meta source | Show the [field] of each [post type] on its page with the Voyager Open Blocks post meta source. Make the field bindable under Settings > Site Data first if it isn't yet. |

**MCP access is enough.** The nine abilities are also served through the WordPress MCP Adapter. With the adapter on your site, an agent that reaches it only over MCP, with no shell and no command-line tools, can still find the page or header you name, read the catalog, insert a section or an icon inside one, bind it and check that each value resolves.

## Reference

### What is in the pack

| Item | Kind | Skill | Try it |
|---|---|---|---|
| `voyager-open/icon` | Dynamic block: 35 outline icons, optional link, CSS-only animation | [skills/voyager-open-icon](skills/voyager-open-icon/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/icon.json) |
| `voyager-open/facts` | Dynamic block: a list, one item per line, that binds to the Site Data lists (services, service area, credentials) | [skills/voyager-open-facts](skills/voyager-open-facts/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/facts.json) |
| `voyager-open/contact-details` | Dynamic block: one address with the street, the city line, the phone as a tel: link and the email as a mailto: link, then the hours, each part bindable to Site Data | [skills/voyager-open-contact-details](skills/voyager-open-contact-details/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/contact-details.json) |
| `voyager/site-data` | Binding source: company, phone, email, address, hours, social links | [skills/voyager-site-data](skills/voyager-site-data/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/site-data.json) |
| `voyager/post-meta-text` | Binding source: allowlisted post meta, with JSON dot paths | [skills/voyager-post-meta-text](skills/voyager-post-meta-text/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/post-meta-text.json) |
| `voyager-open/hero` | Pattern: the hero, image beside text | [skills/voyager-open-hero](skills/voyager-open-hero/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/hero.json) |
| `voyager-open/hero-cover` | Pattern: the hero, text over a background | [skills/voyager-open-hero](skills/voyager-open-hero/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/hero.json) |
| `voyager-open/services` | Pattern: services as cards in a row | [skills/voyager-open-services](skills/voyager-open-services/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/services.json) |
| `voyager-open/services-list` | Pattern: services as rows, each icon beside its text | [skills/voyager-open-services](skills/voyager-open-services/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/services.json) |
| `voyager-open/reviews` | Pattern: reviews as cards, each a star row, a quote, a name and one detail | [skills/voyager-open-reviews](skills/voyager-open-reviews/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/reviews.json) |
| `voyager-open/reviews-single` | Pattern: one review as a large quote | [skills/voyager-open-reviews](skills/voyager-open-reviews/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/reviews.json) |
| `voyager-open/process-steps` | Pattern: numbered steps side by side, each a heading that starts with its number and one line | [skills/voyager-open-process-steps](skills/voyager-open-process-steps/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/process-steps.json) |
| `voyager-open/process-steps-list` | Pattern: the same numbered steps one under another | [skills/voyager-open-process-steps](skills/voyager-open-process-steps/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/process-steps.json) |
| `voyager-open/stats` | Pattern: three figures from Site Data in a row, the year founded, the rating and the review count | [skills/voyager-open-stats](skills/voyager-open-stats/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/stats.json) |
| `voyager-open/stats-band` | Pattern: the same figures beside a heading and one line | [skills/voyager-open-stats](skills/voyager-open-stats/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/stats.json) |
| `voyager-open/faq` | Pattern: the FAQ, questions one under another, each a details block that opens to show its answer | [skills/voyager-open-faq](skills/voyager-open-faq/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/faq.json) |
| `voyager-open/faq-split` | Pattern: the FAQ, its heading beside the questions | [skills/voyager-open-faq](skills/voyager-open-faq/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/faq.json) |
| `voyager-open/service-area` | Pattern: the service area, its towns as a checkmarked list from Site Data | [skills/voyager-open-service-area](skills/voyager-open-service-area/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/service-area.json) |
| `voyager-open/service-area-split` | Pattern: the service area beside an image | [skills/voyager-open-service-area](skills/voyager-open-service-area/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/service-area.json) |
| `voyager-open/cta-band` | Pattern | [skills/voyager-open-cta-band](skills/voyager-open-cta-band/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/cta-band.json) |
| `voyager-open/proof-strip` | Pattern | [skills/voyager-open-proof-strip](skills/voyager-open-proof-strip/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/proof-strip.json) |
| `voyager-open/logo-marquee` | Pattern | [skills/voyager-open-logo-marquee](skills/voyager-open-logo-marquee/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/logo-marquee.json) |
| `voyager-open/closing-cta` | Pattern | [skills/voyager-open-closing-cta](skills/voyager-open-closing-cta/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/closing-cta.json) |
| `voyager-open/contact-section` | Pattern | [skills/voyager-open-contact-section](skills/voyager-open-contact-section/SKILL.md) | [Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/voyager-marketing/voyager-open-blocks/main/blueprints/contact-section.json) |

The Playground links run [`blueprint.json`](blueprint.json) (everything) and the per-item blueprints in [`blueprints/`](blueprints/). They install the plugin from this repository's `main` branch and add demo content.

Patterns use core blocks and the active theme's presets only, so the theme decides how they look. The pack also registers three block styles the patterns use: Ticks on `core/list` and the facts block, Marquee on `core/group`, and Question on `core/details`. Three section styles on `core/group`, Panel, Inverted and Accent, colour a whole section from the theme's palette; [DESIGN.md](DESIGN.md) says how, and `npm run contrast` measures them.

**Without the CLIs.** On a plain WordPress 6.9+ site the pack gives you the icon, facts and contact details blocks, nineteen patterns, the Ticks, Marquee and Question block styles, the Panel, Inverted and Accent section styles, the two binding sources, Settings > Site Data and nine abilities. [Wesper](https://github.com/humanmade/wesper) and [Block Runner](https://github.com/humanmade/block-runner) only add the agent loop that reads the site and checks the markup it writes. Each skill teaches that loop: read the site with Wesper, author native blocks with Block Runner, then bind them to real data.

### Other ways to install

With WP-CLI, `wp plugin install voyager-open-blocks.zip --activate` installs the downloaded zip. The zip holds one `voyager-open-blocks/` folder with the blocks already built.

With Composer, add this repository and require the package (`build/` is committed, so no build step is needed):

```bash
composer config repositories.voyager-open-blocks vcs https://github.com/voyager-marketing/voyager-open-blocks
composer require voyager-marketing/voyager-open-blocks
```

### Abilities

Nine WordPress abilities (category `voyager-open-blocks`, REST at `wp-abilities/v1`, MCP through the WordPress MCP adapter) let an agent use the pack on a live site without writing markup: `get-catalog`, `find-posts`, `get-post-blocks`, `insert`, `compose`, `configure`, `bind`, `undo` and `validate`. `find-posts` turns "my Services page", "my home page" or "my header" into a post ID, and lists only what the user can edit. `insert` places at the top level or, with `parent`, inside a block, following each block's `allowedBlocks`, `parent` and `ancestor` rules and any lock. It takes `copy` for a pattern's named headings, paragraphs, buttons, list items and FAQ questions, `omit` for the named blocks to leave out, such as a fourth credential, and `repeat` for more of a named block, such as a fifth and sixth. `compose` builds a whole draft page in one call from a list of sections, each a pattern by its section and layout with its `copy` and section `style`, checked as `insert` checks one, and returns the preview link, the revision ID, the validation and the Site Data the page shows that is still empty. It writes drafts only. `bind` takes `post_type` in a template, so a single template can bind the post meta of the posts it shows. `undo` reverses a save made by `insert`, `compose`, `configure` or `bind`, and refuses when the post was saved since, unless told to reverse those saves too. `validate` checks markup, or a saved post, without saving anything. `get-post-blocks` reports `resolution` for every binding (`resolved` or `fell_back`, with a reason and a value preview), so a bound value can be proved without rendering the page. `bind` accepts only args that match a listed field exactly. `configure` touches only blocks that save no HTML.

Inputs, permissions and return values are in the [API reference](docs/use-in-your-theme.md#abilities). The agent's view is in the [entry skill](skills/voyager-open-blocks/SKILL.md#abilities).

### How it is built

- **Blocks** live in `src/<name>/` and build with `@wordpress/scripts` (`npm run build`, which writes `build/blocks-manifest.php`). The plugin registers everything in that manifest. `build/` is committed so a Git install works without a build step.
- **Patterns** start as Block Runner intent trees in `patterns/intents/<name>.json`, with the pattern's title, description and categories beside the tree. `npm run patterns:build` assembles each one into `patterns/<name>.html` and fails on any invalid block or dropped attribute. The pack's own blocks, such as the icon, can sit in an intent too: their attributes are checked against their `block.json`. Do not edit the `.html` files by hand.
- **Binding sources** are in `inc/site-data.php` and `inc/post-meta-text.php`. Their editor half (field lists, live values, in-place editing of post meta) is `assets/bindings-editor.js`.

A post meta field becomes bindable under Settings > Site Data > Bindable post meta: pick a post type, enter the meta key and a label. The rows live in the `voyager_open_blocks_bindable_meta` option and register on `init` with the same key rules as `voyager_open_blocks_register_bindable_meta()`, which stays for themes and plugins and wins on a key clash.

On a site that also runs the voyager-blocks plugin, the two binding sources belong to that plugin. See [docs/development.md](docs/development.md#sites-running-voyager-blocks).

### Does it help?

The same model built the same 20 home pages twice, with and without the pack. With it, every page bound its phone, email and address to Site Data. Without it, none did: all 20 typed them in. With the pack, 20 of 20 pages also passed strict validation on the first try, against 17 of 20 without it, but a gap that small could be chance. The method, the raw runs and what failed are in [bench/RESULTS.md](bench/RESULTS.md).

### Development

Node `^22.23.4 || ^24.22.1 || >=26.12.1` (Block Runner 1.0.0's range; `.nvmrc` pins 24.22.1).

```bash
npm install
npm run build              # blocks
npm run patterns:build     # intents -> patterns/*.html
npm run check              # every offline gate
```

What each gate checks, the PHP checks, and the site checks are in [docs/development.md](docs/development.md).

### Local site

`npm run dev` is the quickest way to a running site. For a throwaway WordPress on SQLite that lasts between runs and that WP-CLI, Playground and Wesper all read, use `npm run playground -- setup --site ../voyager-open-blocks-site`, then `npm run playground -- serve --site ../voyager-open-blocks-site`. It needs `wp` and `php` on `PATH`. The other commands, the end to end run over REST and the site checks are in [docs/development.md](docs/development.md#local-site).

### Releases

release-please keeps one open release pull request on `main`. Merging it tags the release and attaches `voyager-open-blocks.zip`. The steps are in [CONTRIBUTING.md](CONTRIBUTING.md#how-a-release-happens), and the zip, tag and Plugin Check details are in [docs/development.md](docs/development.md#releases).

## Support

Issues are answered within two business days. Support hours are Monday to Friday, 9am to 5pm US Eastern time, outside US public holidays. Releases ship in batches every two weeks. Questions go to [Discussions](https://github.com/voyager-marketing/voyager-open-blocks/discussions), bugs and feature requests to [issues](https://github.com/voyager-marketing/voyager-open-blocks/issues/new/choose), and security problems to private reporting ([SECURITY.md](SECURITY.md)). How to contribute is in [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GPL-2.0-or-later. "Voyager" and "Voyager Open Blocks" are Voyager Marketing's names; the license covers the code, not the names. Icons are from [Heroicons](https://heroicons.com) (MIT, `assets/LICENSE-heroicons.txt`).
