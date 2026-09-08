# TsfGatekeeperBundle

[![Codeception](https://github.com/Kera95/pimcore-gatekeeper-bundle/actions/workflows/codeception.yml/badge.svg)](https://github.com/Kera95/pimcore-gatekeeper-bundle/actions/workflows/codeception.yml)

A completeness gate for [Pimcore](https://pimcore.com/) DataObjects, for Pimcore 11.x, 12.x and
2026.x. You declare per class which fields have to be filled, optionally per language and per
profile (channel, market, print, ...). The bundle scores every object on save, keeps the result in
its own table, shows it as a report in the admin UI and on the console, can export it into the
asset tree, and can warn or block when an incomplete object is published.

- Composer package: `kerimkaralic/pimcore-gatekeeper-bundle`
- Namespace: `Tsf\GatekeeperBundle`
- Bundle class: `Tsf\GatekeeperBundle\TsfGatekeeperBundle`
- Config alias: `tsf_gatekeeper`
- License: MIT

Pimcore's own Data Quality bundle is an Enterprise feature; this is the Community Edition answer
to "which products are not ready to go out, and what exactly is missing".

## Requirements

| | |
|---|---|
| PHP | >=8.1, <8.6 |
| Pimcore | ^11.0, ^12.0, or ^2026.1 |
| Symfony | ^6.2 or ^7.3 |

The bundle uses Pimcore model events and its own database table only. It works with the Classic
Admin and with Pimcore Studio; the admin report needs `PimcoreCustomReportsBundle`, which ships
with Pimcore since 11 and only has to be enabled.

## Installation

```bash
composer require kerimkaralic/pimcore-gatekeeper-bundle
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Tsf\GatekeeperBundle\TsfGatekeeperBundle::class => ['all' => true],
];
```

Install it (creates the table `tsf_gatekeeper_result`) and clear the cache:

```bash
bin/console pimcore:bundle:install TsfGatekeeperBundle
bin/console cache:clear
```

## Configuration

Create `config/packages/tsf_gatekeeper.yaml`. A complete, commented example is in
[`docs/configuration.example.yaml`](docs/configuration.example.yaml).

```yaml
tsf_gatekeeper:
    classes:
        Product:
            required: [sku, name, title, description, main_image, price]
            languages: [en, de]          # optional, default: all valid system languages
            threshold: 100               # optional, default 100
            gate: warn                   # off | warn | block, default warn
            score_field: completeness    # optional Numeric field on the class
            profiles:                    # optional additional rule sets
                print:
                    required: [name, ean, long_description]
                    languages: [de]
                    threshold: 80
    report:
        studio: true
        asset:
            enabled: false
            folder: /reports/completeness
            formats: [csv, md]
            on_save: false
```

Check the configuration against the installed classes, fields and languages at any time:

```bash
bin/console tsf:gatekeeper:validate
```

### Reference

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Master switch for the save listener. Commands work regardless. |
| `classes.<Class>.required` | `[]` | Field names that must be filled. Top-level fields and children of `localizedfields` by their plain name. Becomes the profile named `default`. |
| `classes.<Class>.languages` | `[]` | Languages evaluated for localized fields. Empty means every valid system language. |
| `classes.<Class>.threshold` | `100` | Score from which an object counts as complete, 0-100. |
| `classes.<Class>.gate` | `warn` | `off`, `warn` or `block`, see below. |
| `classes.<Class>.score_field` | `null` | Numeric field that receives the aggregate score on every save. |
| `classes.<Class>.enabled` | `true` | Disable a class without deleting its rules. |
| `classes.<Class>.profiles.<name>.required` | required | Fields of this profile. |
| `classes.<Class>.profiles.<name>.languages` | class value | Override the class languages for this profile. |
| `classes.<Class>.profiles.<name>.threshold` | class value | Override the class threshold for this profile. |
| `report.studio` | `true` | Register the two Custom Reports when `PimcoreCustomReportsBundle` is enabled. |
| `report.asset.enabled` | `false` | Allow `tsf:gatekeeper:report --asset` to write into the asset tree. |
| `report.asset.folder` | `/reports/completeness` | Target asset folder, created on demand. |
| `report.asset.formats` | `[csv]` | `csv` (one file per class, every row) and/or `md` (one `summary.md`). |
| `report.asset.on_save` | `false` | Also rewrite the files after every save of a tracked object. |

A class needs a non-empty `required` list or at least one profile. `default` is reserved as a
profile name. Thresholds, gate values, language codes and duplicate field names are validated when
the container is built; classes, fields and system languages are validated by
`tsf:gatekeeper:validate` because they only exist at runtime.

## How the score works

- Every profile is scored separately: `score = round(100 × filled / required)`.
- If a profile contains at least one localized field it produces **one row per language**;
  non-localized fields count the same in every language row. Profiles without localized fields
  produce a single row with an empty language.
- Values are read **without language fallback**, so a German title that only exists through the
  English fallback is missing in German.
- A row **passes** when its score reaches the profile threshold.
- The **aggregate score** of an object is the lowest row score. That is what goes into the
  `score_field` and what the gate looks at.
- Variants are evaluated like any other object. Folders are ignored.
- Fields inside object bricks, field collections or classification stores cannot be listed
  individually; list the container field instead (an empty container counts as missing).

### What counts as empty

The bundle starts from Pimcore's own `isEmpty()` per data type and corrects the cases where that
is wrong for completeness:

| Type | Filled when |
|---|---|
| Input, Textarea, Email, Password | not only whitespace (`"0"` is a value) |
| WYSIWYG | text remains after stripping tags and `&nbsp;` |
| Numeric, Slider | a number, including `0` |
| Checkbox | `true` or `false` (`null` is empty) |
| QuantityValue, InputQuantityValue | a value is set, `0` included; a unit alone is empty |
| Hotspot image | an image is assigned |
| Video | the video data (asset or external id) is set |
| Link | a direct URL or an internal target exists |
| Table | at least one non-blank cell |
| Field collection | at least one item |
| Object bricks | at least one brick set and not marked for deletion |
| Everything else | Pimcore's `isEmpty()` (relations: at least one element, select: non-empty value, ...) |

To change or extend this, implement `Tsf\GatekeeperBundle\Service\Emptiness\EmptinessResolverInterface`
in a service; it is tagged `tsf_gatekeeper.emptiness_resolver` automatically and the first
resolver that `supports()` a field definition wins.

## The gate

The gate only concerns **published** objects; unpublished saves and drafts always go through.

| `gate` | Behaviour when a published object is saved with a failing row |
|---|---|
| `off` | Nothing. |
| `warn` | A warning is logged: `Completeness gate: Product "ABC-123" is incomplete. default/de (71% < 100%): title, short_description` |
| `block` | The save is rejected with a `ValidationException` carrying the same message. Studio shows it as the error of the save (HTTP 422), the Classic Admin as its validation dialog. |

## Reports

### Admin UI

With `PimcoreCustomReportsBundle` enabled the bundle registers two reports in the group
**Completeness** (Studio: *Reporting*; Classic: *Marketing > Custom Reports*):

- **Completeness: objects** — one row per object, profile and language with score, threshold,
  passed, missing count and the missing field names. Sortable and filterable, the ID column opens
  the object, CSV export included.
- **Completeness: summary** — per class, profile and language: number of objects, average score,
  complete and failing counts.

### Console

```bash
bin/console tsf:gatekeeper:report                    # every class, grouped per class and profile
bin/console tsf:gatekeeper:report -c Product -f      # only failing rows of one class
bin/console tsf:gatekeeper:report --below 50         # rows scoring below 50
bin/console tsf:gatekeeper:report -p print --language de
bin/console tsf:gatekeeper:report --format=csv > completeness.csv
bin/console tsf:gatekeeper:report --format=md        # Markdown tables, paste into a ticket
```

```
 Product · profile default · 4 objects · avg 64 % · 1 complete · 3 failing (threshold 100)
id key         lang pub score  missing
2  ABC-123     de   yes  100 %
6  PCSA-001    de   yes ! 71 % title,short_description
6  PCSA-001    en   yes  100 %
8  HPHC-001    en   yes ! 57 % short_description,price,categories
```

### Asset tree

With `report.asset.enabled: true`, `tsf:gatekeeper:report --asset` writes the configured formats
into `report.asset.folder`:

- `<Class>.csv` per class with every row (`object_id, key, path, class, published, profile,
  language, score, threshold, passed, missing_count, missing_fields, calculated_at`)
- `summary.md` with totals per class/profile/language, the ten most frequently missing fields and
  the failing objects

Files are overwritten in place, so the asset versions keep the history. `--timestamp` appends
`-YYYYmmdd-HHMM` to the filenames instead. This works on every supported Pimcore line and without
the Custom Reports bundle, and gives people without admin access a file they can open or download.

## Commands

| Command | Purpose |
|---|---|
| `tsf:gatekeeper:validate` | Checks every class rule against the installed classes, fields, score field type and system languages. Exit code 1 on problems. |
| `tsf:gatekeeper:recalculate [-c Class] [--limit N] [--dry-run] [--save]` | Evaluates all objects of the configured classes and rewrites the result table. Objects are not saved unless `--save` is given, which also refreshes the score field, versions and the search index (slow). |
| `tsf:gatekeeper:report [...]` | Prints the report, see above. |
| `tsf:gatekeeper:add-score-field <Class> [--name=completeness] [--panel=<layout name>]` | Adds a Numeric 0-100 field to a class so `score_field` can be used. |

Run `recalculate` once after installing or after changing the rules; from then on every save keeps
the table current.

## The score field

`score_field` is optional. The result table is always the source of truth; the field is a mirror of
the aggregate score written through the generated setter during the same save, so it is versioned
and indexed like any other value and can be used in grids, listings and code:

```php
$product->getCompleteness(); // 71
```

If the configured field is missing or not Numeric the bundle logs one warning per class, skips the
mirror and never blocks the save; `tsf:gatekeeper:validate` reports the exact problem.

## How it works

- `pimcore.dataobject.preAdd` / `preUpdate`: the object is evaluated, the score field is set, the
  gate is applied. An exception in the evaluation is logged and never blocks the save.
- `pimcore.dataobject.postAdd` / `postUpdate`: the rows are written to `tsf_gatekeeper_result`
  (one per profile and language; rows of removed profiles are deleted).
- `pimcore.dataobject.postDelete`: the rows of the object are removed.
- Nothing is saved twice, no flags, no queue. Studio autosave and "save version" do not dispatch
  `preUpdate`, so drafts are scored on the next real save.

## Testing

```bash
composer install
vendor/bin/codecept run
```

The unit suite runs without a database or a Pimcore kernel; it covers the configuration, rule
normalisation, the emptiness rules per data type, the evaluator, the listener with every gate
mode, the report rendering and the report definitions.

## License

MIT, see [LICENSE](LICENSE).
