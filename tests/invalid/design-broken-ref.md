---
name: Broken token reference
description: A DESIGN.md whose component points at a colour token nobody defines. npm run check asserts the linter rejects it.
colors:
  surface: "#ffffff"
components:
  section-panel:
    backgroundColor: "{colors.panel}"
---

# Broken token reference

## Colors

`surface` is the only colour. `section-panel` asks for `colors.panel`, which does not exist.

## Components

`section-panel` uses the missing token.
