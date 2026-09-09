# Contributing

Thanks for helping to improve the Gatekeeper bundle. Issues and pull requests are welcome.

## Branches

| Branch | Purpose |
|---|---|
| `master` | Latest stable, released code. Every release is a tag on this branch. |
| `develop` | Integration branch. All features land here first. |
| `feature/<name>` | New work, branched from `develop`, merged back into `develop` by pull request. |
| `fix/<name>` | Fixes for a released version, branched from `master`, merged into `master` by pull request; `master` is then merged back into `develop`. |

Releases are cut by merging `develop` into `master` (pull request, or `git merge --no-ff develop`),
tagging the merge commit (`vX.Y.Z`), pushing the tag and publishing a GitHub release. Update
`CHANGELOG.md` in the same change.

## Pull requests

- Branch from the right base (see above) and keep one topic per pull request.
- Keep the code compatible with PHP 8.1 and Pimcore 11.x, 12.x and 2026.x. No PHP 8.2+ only syntax.
- Add or adjust unit tests under `tests/Unit`; the suite must stay runnable without a database.
- Run the suite before pushing:

  ```bash
  composer install
  vendor/bin/codecept run
  ```

  From inside a Pimcore project that consumes the bundle as a path package, run it with the
  project's vendor directory: `cd bundles/Tsf/GatekeeperBundle && ../../../vendor/bin/codecept run`.
- CI runs the suite against Pimcore 11.x (PHP 8.1), 12.x (PHP 8.3) and 2026.x (PHP 8.5); all
  three must pass.
- Describe behaviour changes in `CHANGELOG.md` under *Unreleased* and in `README.md` when the
  configuration or a command changes.

## Reporting bugs

Please include the Pimcore and PHP versions, the relevant part of `tsf_gatekeeper.yaml`, the
output of `bin/console tsf:gatekeeper:validate`, and what you expected to happen.
