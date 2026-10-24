---
name: voyager-post-meta-text
description: Bind block attributes to an allowlisted post meta field of the post being rendered, including a dot path into a JSON value, through the voyager/post-meta-text block bindings source. Use when per-page data (an eyebrow, a summary, one stat out of a JSON list) should drive a block, and the field was made bindable with voyager_open_blocks_register_bindable_meta().
license: GPL-2.0-or-later
---

# Post meta text (`voyager/post-meta-text`)

A Block Bindings source that reads one post meta field of the current post. Only allowlisted fields resolve. Any other key returns nothing, so a binding cannot read arbitrary meta.

## When to use

- Per-page values on a template or pattern used by many pages: a service page's eyebrow or summary.
- One value out of a JSON field: `stats.0.value`.

For a plain registered field without a JSON path, `core/post-meta` works too, with the same args. Use this source when you need a dot path, or for content that must also resolve on sites running voyager-blocks.

## Making a field bindable

A developer does this in PHP, on `init`:

```php
voyager_open_blocks_register_bindable_meta( 'page', 'service_eyebrow', 'Service eyebrow' );
```

That registers single, string, REST-visible meta that only users who can edit the post may change, and adds it to the allowlist. `''` as the post type means every type. Keys may not start with `_` or contain a dot. The `voyager_post_meta_text_fields` filter can allowlist more fields, as `post type => [ key => label ]`.

## Finding fields

Registered fields appear in the Wesper manifest under `contentModel.postTypes[].fields[]` with `source: "core/post-meta"` and `args: { "key": "<key>" }`. This source takes the same args. Copy `args` verbatim and change only `source`. A dot path extends `key`:

```json
{ "source": "voyager/post-meta-text", "args": { "key": "demo_stats.0.value" } }
```

Wesper cannot tell you which registered fields are allowlisted for this source. A field registered through the helper always is. If you are unsure, bind it and check the rendered page.

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
- voyager-blocks registers a source with the same name and its own allowlist. When it is active, it owns the source, and fields registered here do not resolve through it.

## The loop

1. **Read.** `npx wesper collect --wp-path <path> --out site.context.json`. Find the field under the target post type's `fields`, and confirm `voyager/post-meta-text` is in `bindings.sources`.
2. **Author.** Add the binding in `attrs.metadata.bindings` and assemble.
3. **Validate.** `npx block-runner validate page.html --strict --json --context site.context.json`.
4. **Check.** Open the rendered page. If the placeholder still shows, the field is empty or not allowlisted.

## Try it

`blueprints/post-meta-text.json` has a page bound to its own demo fields, including a JSON dot path.
