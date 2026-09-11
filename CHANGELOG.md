# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-09-11

First stable release, for Pimcore 11.x, 12.x and 2026.x on PHP 8.1 to 8.5.

### Added

- Per-class completeness rules (`required`, `languages`, `threshold`, `gate`, `score_field`) with
  optional named profiles that inherit from the class.
- Evaluation on every save through the DataObject model events; results stored per object,
  profile and language in `tsf_gatekeeper_result`.
- Optional score field mirror written through the generated setter during the same save.
- Publish gate with `off`, `warn` and `block` modes.
- Custom Reports definitions "Completeness: objects" and "Completeness: summary" for Studio and
  the Classic Admin.
- Console commands `tsf:gatekeeper:validate`, `tsf:gatekeeper:recalculate`,
  `tsf:gatekeeper:report` (table, csv, md) and `tsf:gatekeeper:add-score-field`.
- Report export into the asset tree as CSV per class and a Markdown summary.
- Nested field paths `container.Type.field` for object brick and field collection fields.
- `tsf:gatekeeper:report --summary` for the per class/profile/language totals on the console.
- Emptiness rules per data type with an `EmptinessResolverInterface` extension point.
- Codeception unit suite that runs without a database or a Pimcore kernel.
- Codeception functional suite that boots a minimal Pimcore kernel (`tests/Support/App`) against a
  real database and covers the installer, the gate, the score field and result rows on save, the
  delete hook, the console commands, the asset export and the Custom Reports definitions.

### Fixed

- The Custom Reports definitions no longer set `pagination`, which the Pimcore 11 configuration
  tree rejects (12 and 2026 default it to true anyway).
- `tsf:gatekeeper:add-score-field` no longer saves the class with an empty field definition list.
  It cleared the cached definitions before saving, and outside the admin Pimcore does not rebuild
  them from the layout, so the save dropped every data column of `object_store_<class>` and
  `object_query_<class>` and removed the matching relation rows. The command now re-assigns the
  layout, which rebuilds the definitions. **Anyone who ran this command on a real installation
  should check the affected class tables and restore from a backup if columns are missing.**

[Unreleased]: https://github.com/Kera95/pimcore-gatekeeper-bundle/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Kera95/pimcore-gatekeeper-bundle/releases/tag/v1.0.0
