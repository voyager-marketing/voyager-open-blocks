# voyager-open-blocks

Read AGENTS.md first: how the pack is built, checked and documented.

Project notes and Discoveries live in the maintainers' own tracker, not in this public repo.
**Queue key:** label `component/open-blocks`, Linear team VOY, project Open Agent-Ready Blocks.

A PR body names the issue it completes as `Fixes VOY-NNN`. Write no other issue ID in a PR's title, branch or body: Linear attaches the PR to every issue ID it finds and moves each one to In Review, which takes an unclaimed issue out of the dispatch queue. Name other issues by title instead.

Files in this repo hold no issue IDs at all, in code, comments or docs: name the issue by what it is. `npm run check` fails on an ID in any tracked file but `CHANGELOG.md` and `bench/runs/`.

`.claude/settings.json` is tracked and holds the public mission grants. The MCP grants (Linear, Notion, Slack) live in the user-level `~/.claude/settings.json` of the machine or environment that runs missions, so they stay out of this public repo. `.claude/session-summary.md` is gitignored; the mission Stop Protocol writes it locally.

Tool bugs found here (Block Runner, Wesper, Playground) are ours to work around and record in Discoveries. Do not file issues or PRs on Human Made's or anyone else's repos.
