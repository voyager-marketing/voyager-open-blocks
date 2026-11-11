---
name: Low contrast
description: A DESIGN.md that is clean but for one component with grey text on white, under 4.5:1. The linter reports that as a warning and exits 0. npm run check asserts its DESIGN.md gate rejects the file anyway.
colors:
  primary: "#111111"
  surface: "#ffffff"
  faint: "#aaaaaa"
typography:
  body:
    fontSize: 1rem
spacing:
  "40": 30px
rounded:
  box: 0px
components:
  section-plain:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    typography: "{typography.body}"
    padding: "{spacing.40}"
    rounded: "{rounded.box}"
  section-faint:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.faint}"
---

# Low contrast

## Colors

`primary` is near black, `surface` is white and `faint` is a light grey, about 2.3:1 against white.

## Typography

`body` is the only size.

## Components

`section-plain` is near black on white. `section-faint` puts `faint` text on `surface`, which is the one finding.
