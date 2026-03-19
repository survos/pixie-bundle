# Session Summary — pixie-bundle

## Key Changes

### Owner → Inst rename
- `src/Entity/Inst.php` (was Owner.php) — pixie identity inside each SQLite DB
- `src/Repository/InstRepository.php`
- Old `Owner.php` moved to `deprecated/Entity/Owner.php`
- All references updated bundle-wide

### Deprecated entities moved to `/deprecated`
- Moved: AttributeType, Category, CustomField, CustomFieldType, FieldMap, FieldSet
- Moved: File, Instance, InstanceCategory, InstanceText, InstanceTextType
- Moved: KeyValue, Lst, Project, Reference, Relation
- Stub classes left in `src/Entity/` for PHP class resolution (no ORM mapping)
- Stub `FieldMap` kept with `slugify()` method (still used by LarcoService)

### Table name cleanup
- Removed `pixie_` prefix from table names: `row`, `term`, `term_set`, `event`, etc.
- Index names updated: `IDX_CORE_OWNER` → `IDX_CORE_INST`

### Schema cleanup
- `Core.php` — removed deprecated relations (customFields, fieldSets, categories, etc.)
- `FieldDefinition` — new fields: `fillRate`, `distinctCount`, `label`, `prompt`, `hidden`, `facet`, `searchable`, `sortable`
- `Inst` — new field: `readonly` (for imported/external collections)

### New: `PixieCommand` abstract base
- `src/Command/PixieCommand.php`
- `#[Required] setPixieService()` — injects `PixieService` via setter

### Modernized commands (zenstruck → Symfony 8)
- `PixieMakeCommand`, `PixieSyncCommand` — full rewrite
- All commands: `parent::__construct()` removed, `Command::SUCCESS` not `self::SUCCESS`

### `PixieMigrateCommand` — major update
- New `--provider` option: reads datasets from `DatasetInfo` registry or 00_meta/
- Creates cores from profile files (not YAML tables)
- `requireConfig: false` path for provider-sourced pixies with no YAML config

### `PixieService::getReference()` — updated
- `requireConfig: false` default — no longer throws when YAML config missing
- `buildConfigSnapshot()` reads from CoreDefinition/FieldDefinition DB only
- No more YAML config fallback
- `buildYamlConfig()` removed

### `PixieServiceBase`
- `getPixieDbDir()` resolves `%env(VAR)%/suffix` patterns at runtime
- `dbName()` now uses `getPixieFilename()` (respects APP_DATA_DIR)
- `tablesExist(['owner'])` → `tablesExist(['inst'])`
- `FROM owner` → `FROM inst` in raw SQL

### `PixieConfigRegistry`
- Falls back to `DatasetInfo` when YAML registry is empty
- `buildConfigFromDatasetInfo()` builds Config from DatasetInfo entity

### New: `PixieInfoCommand`
- `bin/console pixie:info` — list all pixie DBs or show stats for one
- Shows cores, row counts, sample rows

### New routes fix
- `config/routes.yaml` `prefix: /pixie` removed — controllers own their prefix
- Fixed triple-prefix `/pixie/pixie/pixie` bug

## TODO
- `pixie:migrate` should write `DatasetInfo.pixieRowCount` after ingest
- `pixie:compile-schema` command: read `#[Map]` attrs from DTO → write FieldDefinition rows
- Fix dashboard template to use FieldDefinition instead of YAML tables
- RowRelation entity for typed connections between rows (planned but not built)
