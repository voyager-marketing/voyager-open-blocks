# Copy examples

A home page for each of five kinds of business: home services, a law firm, a vet clinic, an inn and a restaurant. Every business, person and place in them is invented. Phone numbers are in the 555-0100 to 555-0199 range, and email addresses are at example.com.

Each file holds two keys:

- `business`: one paragraph on what the business does, where and for whom, and where each claim on its page comes from: the facts the owner gave for the proof strip, the reviews they shared with their customers' permission, and the phone and email they keep in Site Data.
- `compose`: the input the `compose` ability takes. Its sections are those of `recipes/home.json`, in order, with each section's copy filled.

Read the copy beside the paragraph. A string is copy you write. `{"text": "...", "source": "user"}` is text the site owner gave, and only a claim slot holds it: the proof strip's credentials and every part of a review. The call buttons take no copy: they show the Site Data phone, and the email button links to the Site Data email.

To try one, pass its `compose` with `"dry_run": true` added. It returns `refused: []` and saves nothing.

`compose` takes no `omit` or `repeat`, so each example fills all four credentials, three service cards and three reviews of five stars. For a business with three credentials, a fourth service or a four-star review, leave that section out of `compose` and place it with `insert`, which takes both.
