type: feat
`configure` and `bind` take `dry_run` like `insert`, every save returns `revision_id`, and the new `undo` ability restores a post's content as it was before a save. It refuses when someone saved the post after that save, unless asked to reverse their saves too.
