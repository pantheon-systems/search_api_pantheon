# Contributing

This guide is for the Pantheon team that maintains the
[search_api_pantheon](https://github.com/pantheon-systems/search_api_pantheon)
module. The module is mirrored to
[drupal.org](https://www.drupal.org/project/search_api_pantheon).

## Making a change

1. Create a feature branch from `8.x`, the default branch.
2. Open a PR against `8.x`. Put the Jira ticket in the title, for example
   `[SITE-6063] Surface gateway error response body in schema post errors`.
3. Wait for CI to pass. It runs on every push:
   - `linting` and `phpcompatibility`: coding standards (`phpcs.xml`) and PHP
     compatibility.
   - `deploy_multidev`: integration tests on Pantheon multidevs, for each
     Drupal, PHP and Solr combination.
   - `mirror_do`: pushes the branch to drupal.org after the tests pass.

### Exception: a new pre-release alongside the stable release

Use this when you have to maintain two releases at the same time: the stable
release on the main branch (`8.x`), and a new minor release that is not stable
yet, for example `8.6.0-beta1`, on its own `8.N.x` branch (`8.6.x`). This is
useful when you want customers to test a large change, such as a major rewrite
or Solr 9 support, before it becomes the stable release.

Normally an `8.N.x` branch does not exist until you push a tag, and then the
`create-branch-on-tag` workflow creates it. Only if you need a pre-release, such
as an alpha or beta version, create `8.6.x` from `8.x` yourself before you start
work, and develop the new release there. This was done for `8.4.x` and `8.5.x`.

1. If you need a pre-release (an alpha or beta), create `8.6.x` from `8.x` and
   push it.
2. Create the feature branch for the new release from `8.6.x`, and merge it into
   `8.6.x`: open the PR against `8.6.x`, not `8.x`.
3. When the release is ready to be stable, merge `8.6.x` into `8.x` and tag the
   stable release from `8.x`. This is how `8.4.x` and `8.5.x` were released,
   because they needed beta releases. For all other releases, follow the
   standard pattern in [Making a new release](#making-a-new-release).

## Making a new release

First, tag the release. Tag a stable release from `8.x`. Tag a pre-release from
its `8.N.x` branch (see the exception above).

```bash
git tag <x.y.z>
git push origin <x.y.z>
```

For example, to release `8.5.2` from `8.x`:

```bash
git checkout 8.x
git pull
git tag 8.5.2
git push origin 8.5.2
```

For a pre-release, use a suffix such as `8.6.0-beta1`, and tag from the `8.N.x`
branch. For example, to release `8.6.0-beta1` from `8.6.x`:

```bash
git checkout 8.6.x
git pull
git tag 8.6.0-beta1
git push origin 8.6.0-beta1
```

Pushing the tag runs the `create-branch-on-tag` workflow. It takes the major and
minor numbers of the tag as the branch name, so `8.5.0`, `8.5.1` and
`8.5.0-beta1` all map to `8.5.x`. It creates that `8.N.x` branch from `8.x` if
it does not exist yet, or merges `8.x` into it if it does, then pushes that
branch to drupal.org.

Next, create a release on GitHub.

- Visit the [new release page on
  GitHub](https://github.com/pantheon-systems/search_api_pantheon/releases/new).
- Select the tag you just pushed.
- Click "Generate release notes" and edit to suit.
- For a pre-release, check "Set as a pre-release". Otherwise leave it unchecked
  and check "Set as the latest release".
- Click "Publish release".

Automation does not create a release on drupal.org, so repeat the process there.
The project page is
[drupal.org/project/search_api_pantheon](https://www.drupal.org/project/search_api_pantheon).

- Visit the [create release page on
  drupal.org](https://www.drupal.org/node/add/project-release/2759743).
- Select the release version you just pushed and click "Next".
- Convert the release notes from Markdown to HTML and fix them up to suit.
- Check any release types that apply: "Security update", "Bug fixes" and/or "New
  features", then click "Save".
- If you created a new minor release, open the project's "Administer releases"
  page and make it the recommended release.

### If CI is broken and the mirror to drupal.org does not work

CI mirrors branches to drupal.org: the `mirror_do` job pushes the branch you
pushed, and the `create-branch-on-tag` workflow pushes the `8.N.x` branch. If CI
is broken, push the branch to drupal.org yourself. The drupal.org repository is
on GitLab at
[git.drupalcode.org/project/search_api_pantheon](https://git.drupalcode.org/project/search_api_pantheon).

You need a drupal.org account with write access to the project, and your SSH
public key added to your drupal.org account.

1. Add the drupal.org remote once:

   ```bash
   git remote add drupal git@git.drupal.org:project/search_api_pantheon.git
   ```

2. Push the branch that has not been mirrored, for example `8.x` or `8.6.x`:

   ```bash
   git push drupal 8.x
   ```

3. If you are making a release, push the tag as well, for example `8.6.0`:

   ```bash
   git push drupal 8.6.0
   ```

If the `create-branch-on-tag` workflow failed after you pushed a tag, do its
steps by hand. Replace `8.6.x` with the branch for your tag:

```bash
git checkout 8.6.x
git merge origin/8.x
git push origin 8.6.x
git push drupal 8.6.x
git push drupal 8.6.0
```

If `8.6.x` does not exist yet, create it from `8.x` first with `git checkout -b
8.6.x origin/8.x`, and skip the merge.

The workflows push branches only, so push the tag to drupal.org yourself. The
tag has to be there before you can create the release on drupal.org (see above).
