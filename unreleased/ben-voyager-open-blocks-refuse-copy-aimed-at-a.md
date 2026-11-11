type: fix
`insert` and `configure` refuse text for a slot bound to Site Data or post meta, such as the CTA band's call button, and save nothing; `validate` warns `stale_fallback` when a bound block's fallback looks like a phone number or an email address.
