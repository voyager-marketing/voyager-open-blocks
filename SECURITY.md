# Security policy

## Report a vulnerability privately

Please don't open a public issue, discussion or pull request for a security problem.

Report it through GitHub's private vulnerability reporting: go to the repository's **Security** tab and choose **Report a vulnerability** ([direct link](https://github.com/voyager-marketing/voyager-open-blocks/security/advisories/new)). Only the maintainers can see the report.

Include what you can of:

- the version of Voyager Open Blocks and of WordPress
- what an attacker can do, and what they need first (for example a logged-in role)
- steps or a proof of concept to reproduce it

## What happens next

- **Within two business days:** a maintainer acknowledges your report, during the support hours in the [README](README.md#support).
- **Within ten business days:** we tell you whether we can reproduce it and how serious we think it is, or ask you for more detail.
- **Fix:** a confirmed vulnerability is fixed in a security release as soon as it's ready. It doesn't wait for the two-weekly release batch. We agree a disclosure date with you, publish a GitHub security advisory, and credit you unless you'd rather stay anonymous.

## Supported versions

The pack is pre-1.0. Only the latest release gets security fixes. Please check you can reproduce on it before you report.

## What counts

The pack adds blocks, patterns, two binding sources and five abilities that agents call over REST or MCP. These are in scope:

- an ability (`voyager-open-blocks/*`) that reads or changes a post without the `edit_post` capability for it, or lists the catalog without `edit_posts`
- `bind` writing a binding whose args were not listed by `get-catalog`, or `configure` changing a block that saves HTML
- the `voyager/site-data` source returning anything other than its `voyager_site_*` fields
- the `voyager/post-meta-text` source reading a key outside its allowlist, or any protected (underscore) meta key
- unescaped output in a block render, a bound value, a pattern or text passed to `insert` as `copy`
- injection, XSS, CSRF or SQL injection anywhere in the plugin

Out of scope: problems in WordPress core, in other plugins (including voyager-blocks, which owns the binding sources when it is active), in the benchmark under `bench/` or the scripts under `scripts/` (neither ships in the plugin zip), and reports that need an attacker who is already an administrator.
