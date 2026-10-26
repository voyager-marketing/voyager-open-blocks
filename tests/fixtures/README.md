# Fixtures

- `foreign-site-data.php` stands in for another plugin that owns `voyager/site-data`. `scripts/foreign-owner-check.mjs` installs it for one run.
- `site-data-keys.json` pins what each of the 18 `voyager/site-data` keys resolves to for a set of untidy option values. `scripts/site-data-fixture-check.mjs` runs it against the local site. voyager-blocks carries a byte-identical copy, so both repos must change together. `site-data-keys.sha256` is the pin: `npm run check` fails when the fixture no longer matches it, and voyager-blocks compares its copy against the same hash. Write the options with their `sanitize_option_*` filters removed, as the script does, so the fixture exercises `values()` and not the settings sanitizers.
