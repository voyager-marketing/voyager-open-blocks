# voyager-open-blocks

Read AGENTS.md first: how the pack is built, checked and documented.

Project notes and Discoveries live in the maintainers' own tracker, not in this public repo.
**Queue key:** label `component/open-blocks`, Linear team VOY, project Open Agent-Ready Blocks.

A PR body names the issue it completes as `Fixes VOY-NNN`. Write no other `VOY-NNN` in a PR's title, branch or body: Linear attaches the PR to every issue ID it finds and moves each one to In Review, which takes an unclaimed issue out of the dispatch queue (VOY-1788). Name other issues by title instead.

`.claude/settings.json` is tracked and holds the public mission grants (VOY-1790, VOY-1807). The MCP grants (Linear, Notion, Slack) live in the user-level `~/.claude/settings.json` of the machine or environment that runs missions, so they stay out of this public repo. `.claude/session-summary.md` is gitignored; the mission Stop Protocol writes it locally.

Tool bugs found here (Block Runner, Wesper, Playground) are ours to work around and record in Discoveries. Do not file issues or PRs on Human Made's or anyone else's repos.
