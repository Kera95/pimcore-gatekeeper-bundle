# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
