---
name: voyager-post-meta-text
description: Bind block attributes to an allowlisted post meta field of the post being rendered, including a dot path into a JSON value, through the voyager/post-meta-text block bindings source. Use when per-page data (an eyebrow, a summary, one stat out of a JSON list) should drive a block, and the field was made bindable under Settings > Site Data or with voyager_open_blocks_register_bindable_meta().
license: GPL-2.0-or-later
---

# Post meta text (`voyager/post-meta-text`)

A Block Bindings source that reads one post meta field of the current post. Only allowlisted fields resolve. Any other key returns nothing, so a binding cannot read arbitrary meta.

## When to use

- Per-page values on a template or pattern used by many pages: a service page's eyebrow or summary.
- One value out of a JSON field: `stats.0.value`.

For a plain registered field without a JSON path, `core/post-meta` works too, with the same args. Use this source when you need a dot path, or for content that must also resolve on sites running voyager-blocks.

## Making a field bindable

On the site, under **Settings > Site Data > Bindable post meta**: pick a public post type, enter the meta key and a label, and save. Remove a field with its Remove box. No code is needed. The rows are stored in the `voyager_open_blocks_bindable_meta` option, which an agent with WP-CLI can also write:

```bash
echo '[{"post_type":"page","key":"service_eyebrow","label":"Service eyebrow"}]' | wp option update voyager_open_blocks_bindable_meta --format=json
```

Each row registers single, string, REST-visible meta that only users who can edit the post may change, and adds it to the allowlist. Keys may not start with `_` or contain a dot, and the post type must exist. The screen refuses any other row with a message, and a write from WP-CLI drops it silently, so read the option back.

A theme or plugin can do the same in PHP, on `init`:

```php
voyager_open_blocks_register_bindable_meta( 'page', 'service_eyebrow', 'Service eyebrow' );
```

`''` as the post type means every type. The `voyager_post_meta_text_fields` filter can allowlist more fields, as `post type => [ key => label ]`. A key registered in PHP wins over a screen row for the same post type: the screen shows it read-only and will not save a row for it.

The screen exists only where this plugin owns the source. When voyager-blocks owns it, rows are not registered and that plugin's own allowlist applies.

## Finding fields

Registered fields appear in the Wesper manifest under `contentModel.postTypes[].fields[]` with `source: "core/post-meta"` and `args: { "key": "<key>" }`. This source takes the same args. Copy `args` verbatim and change only `source`. A dot path extends `key`:

```json
{ "source": "voyager/post-meta-text", "args": { "key": "demo_stats.0.value" } }
```

Wesper cannot tell you which registered fields are allowlisted for this source. A field added on the screen or through the helper always is. `get-catalog` lists exactly the allowlisted ones for a post type.

## Binding example

```json
{ "block": "core/paragraph", "text": "Eyebrow", "attrs": { "metadata": { "bindings": {
  "content": { "source": "voyager/post-meta-text", "args": { "key": "service_eyebrow" } }
} } } }
```

## Behaviour to rely on

- It reads the post in block context (`postId`), so it works inside a Query Loop, or the main post otherwise.
- An empty value, a missing JSON path or a non-scalar resolves to nothing. WordPress then keeps the authored text.
- Password-protected posts resolve nothing for visitors who cannot edit them.
- In the editor, a field without a dot path is editable in place: typing in the bound block writes to the post's meta. Dot paths are read-only.
- voyager-blocks registers a source with the same name and its own allowlist. When it is active, it owns the source, and fields registered here do not resolve through it. `get-catalog` then lists the owner's allowlist, as that plugin describes it, and `bind` accepts only those args.

## The loop

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Find the field under the target post type's `fields`, and confirm `voyager/post-meta-text` is in `bindings.sources`.
2. **Author.** Add the binding in `attrs.metadata.bindings` and assemble.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`.
4. **Check.** Open the rendered page. If the placeholder still shows, the field is empty or not allowlisted.

## Abilities

**bind** writes this source into a block's `metadata.bindings`:

```json
{ "post_id": 123, "path": "2", "attribute": "content", "source": "voyager/post-meta-text", "args": { "key": "demo_stats.0.value" } }
```

The attribute must be bindable for that block, and `args` must exactly match a field `get-catalog` lists for the post's type. Invented keys are refused with `voyager_open_blocks_invalid_args`. Paths come from `insert` or `get-post-blocks`.

A dot path is accepted when the part before the first dot is a listed field.

## Try it

`blueprints/post-meta-text.json` has a page bound to its own demo fields, including a JSON dot path.
