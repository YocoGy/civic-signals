# Фаза 3 — Database, project skeleton и общинска география

## Резултат

Създадена е основата на новата платформа в `D:\xampp\htdocs\project_2`, без промени в `D:\xampp\htdocs\project_2\project`.

Реализирани са versioned migration, idempotent seed, local GeoPackage import за 265 общини, MariaDB spatial storage, конфигурационен template, базови reusable security primitives и CLI diagnostic. Изпълнението е съобразено с `PHASE_1_ANALYSIS.md` и `PHASE_2_SPECIFICATION.md`.

Проверената development среда е:

* PHP 8.2.12 с `pdo_mysql`, `pdo_sqlite`, `fileinfo` и `exif`;
* MariaDB 10.4.32;
* source GeoPackage layer `Obshtini_2024_1`, 265 features, EPSG:9391.

## Създадена структура

```text
app/
  Controllers/ Models/ Middleware/ Helpers/ Mail/ Geography/ Validation/  # reserved extension points
  Database/Connection.php                                                  # PDO factory
  Security/{CSRF,Session,RateLimiter,Authorization}.php                    # safe base primitives
  Services/AuditService.php
  autoload.php
bin/
  migrate.php
  seed.php
  import-municipality-boundaries.php
  diagnose.php
config/bootstrap.php                                                        # environment reader + bootstrap
database/
  migrations/001_initial_schema.sql
  seed/001_reference_data.sql
  geography/SU_BG_NSI_LAU_2024_1.metadata.json
data/geography/
  SU_BG_NSI_LAU_2024_1.gpkg                                                 # provided non-public source
  SU_BG_NSI_LAU_2024_Description.txt
public/index.php                                                            # Phase-3-only 503 placeholder
resources/{views,emails}/
routes/
storage/{logs,uploads,cache}/
tests/README.md
.env.example
```

`data/geography/` is deliberately the canonical non-public geography-resource directory. The same source file was already provided there; it was not copied into `public/` or modified. `database/geography/*.metadata.json` is the versioned import manifest that binds the dataset name/version to the source resource and SHA-256.

## Configuration

Copy `.env.example` to a local `.env` only on the deployment machine and replace `APP_KEY` with a random secret of at least 32 characters. `.env` is ignored by `.gitignore`; no production credential is committed.

Configuration keys cover application environment/URL/key, MariaDB host/port/database/user/password/database creation, storage root, geography source and logging. Environment variables take precedence over `.env` values. `DB_DATABASE` is restricted to letters, digits and underscores before the migration command uses it in `CREATE DATABASE`.

## Database schema

`database/migrations/001_initial_schema.sql` creates the normalized schema and all foreign keys/indexes:

* accounts/RBAC: `users`, `roles`, `permissions`, `user_roles`, `role_permissions`;
* signal domain: `signals`, `signal_photos`, `signal_email_validations`, `signal_events`, `signal_statuses`, `signal_categories`;
* municipality/geography: `municipalities`, `municipality_contacts`, `geography_datasets`, `municipality_boundaries`;
* future delivery/audit operations: `email_queue`, `audit_log`, `login_attempts`, `settings`.

`signals` has no persisted `DRAFT` state. Its FK points to seeded state `PENDING_EMAIL_VALIDATION`; the status table has stable codes for all Phase-2 transitions. It contains EXIF-derived `latitude`, `longitude`, fixed `gps_source`, optional accuracy, `captured_at_local`/timezone uncertainty and a separate server-side `submitted_at` field. No browser geolocation/IP-geolocation field is present.

`signal_photos` is separate from `signals` and has a unique `signal_id`, enforcing exactly one photo per signal for the MVP while keeping a table that can be relaxed later for multi-photo functionality. It contains storage key, safe display name, MIME, bytes, SHA-256, dimensions, original EXIF date string and metadata. No upload/extraction behavior is implemented yet.

`signal_email_validations` is necessary state for the specified future email-validation workflow: it stores only a hash, expiry, consumed timestamp and resend metadata. It does not store a raw token. `email_queue` is only the transactional-outbox table structure; no worker or email sender exists in this phase.

Contacts are normalized with `contact_type` (`signals`, `registry`, `official`, `fallback`), priority, activity, verification/source fields and optional category. No actual municipal email addresses were seeded.

Soft-delete fields are used for user/category/municipality/contact reference entities. Signals, original-photo metadata, events, outbox records, audit and geography records are retained by normal application operations and require an explicit future retention/anonymization policy.

## Migration and seed order

Fresh install is reproducible:

```powershell
Copy-Item .env.example .env
# Edit .env with local development DB details and a real APP_KEY.

D:\xampp\php\php.exe bin\migrate.php
D:\xampp\php\php.exe bin\seed.php
D:\xampp\php\php.exe bin\import-municipality-boundaries.php
D:\xampp\php\php.exe bin\diagnose.php
```

`migrate.php` creates the configured database when `DB_CREATE_IF_MISSING=true`, maintains `schema_migrations`, and runs each new SQL migration once. DDL causes implicit commits in MariaDB, so migration files are applied as individual versioned units rather than inside a false transaction wrapper.

`seed.php` executes seed files using natural keys and `ON DUPLICATE KEY UPDATE`, making the reference seed repeatable. It seeds roles (`admin`, `operator`), role permissions, eight statuses, three deliberately small MVP categories and non-secret operational settings. It does not create an administrator account or production credentials.

## Official geography source and import

The authoritative source is the provided read-only `data/geography/SU_BG_NSI_LAU_2024_1.gpkg`, documented by `data/geography/SU_BG_NSI_LAU_2024_Description.txt`.

| Metadata | Value |
|---|---|
| Dataset | `SU_BG_NSI_LAU_2024_1` |
| Version | `2024.1` |
| Reference date | 2024-12-31 |
| Source | National Statistical Institute (NSI) |
| Identifier | EKATTE (`Identifier`) |
| Entities | 265 |
| CRS | EPSG:9391, BGS2005/UTM zone35N |
| Geometry | `MULTIPOLYGON` |
| Source SHA-256 | `edf3ae6060f6e5cad1daedfcef5febbd21d9809112b6e26a87cf44ae0f17deab` |

Run the import after migration/seed:

```powershell
D:\xampp\php\php.exe bin\import-municipality-boundaries.php
```

The command uses PDO SQLite to read the GeoPackage directly; no manually authored municipality INSERT statements are used. It verifies the expected layer, EPSG:9391 and exactly 265 features before any MariaDB import. It checks unique/non-empty EKATTE identifiers, reads the municipality attributes and strips only the GeoPackage header to feed the original OGC WKB payload to MariaDB `ST_GeomFromWKB`.

The importer creates/updates the `geography_datasets` record, upserts municipalities by unique EKATTE and replaces boundary rows for that dataset atomically. Boundaries are stored as `MULTIPOLYGON` with SRID 9391 and spatial index. A future resolver must transform EXIF WGS84 latitude/longitude to EPSG:9391 and perform point-in-multipolygon; that resolver is intentionally not implemented.

MariaDB 10.4 does not provide MySQL 8's `ST_IsValid`. Therefore Phase 3 validates that MariaDB can parse the WKB and that every stored boundary has the required `MULTIPOLYGON` type and EPSG:9391. This is a compatible structural integrity check, used together with the authoritative official source; no claim is made that a MariaDB 10.4 topology predicate was run.

## Security foundation

The old project was not copied wholesale. The reusable parts were redesigned to avoid the Phase-1 issues:

* `CSRF` has a CSPRNG 256-bit token and `hash_equals` validation; it is a primitive for the future centralized unsafe-request middleware, not the old inert middleware.
* `Session` sets `HttpOnly`, `SameSite=Lax`, HTTPS-sensitive `Secure` and regenerates ID at session start. It is reserved for future admin authentication only; public submission must remain anonymous.
* `RateLimiter` stores a normalized identifier hash, not plaintext account identifier, for future login policy.
* PDO uses exceptions, UTF-8 and native prepared statements.
* `AuditService` records structured metadata with request/session context; future callers must never include raw tokens, unredacted email content or storage paths.
* `.env` secrets and storage contents are excluded from version control; `storage/` and GeoPackage data are outside `public/`.

## Diagnostics and validation

Run:

```powershell
D:\xampp\php\php.exe bin\diagnose.php
```

It exits `0` only if application/database connectivity, applied migration, seeded statuses, foreign keys, 265 municipalities, EKATTE uniqueness, 265 boundaries, dataset name/version, EPSG:9391 and structural geometry integrity pass. It exits non-zero on any failure. It also requires a configured application key of at least 32 characters.

The actual Phase-3 local run used the isolated development database `civic_signals_phase3` and produced:

```text
Application: OK
Database: OK
Migrations: OK (applied=1)
Seed: OK
Foreign keys: OK
Municipalities: OK (265)
Unique EKATTE: OK (duplicates=0)
Boundary geometries: OK (265)
Dataset: OK (SU_BG_NSI_LAU_2024_1)
Dataset version: OK (2024.1)
CRS: OK (EPSG:9391)
Invalid geometries: OK (0)
Security configuration: OK
```

All new PHP scripts were also linted with `D:\xampp\php\php.exe -l`.

## Explicitly not implemented

This phase does **not** implement public signal submission, photo upload/storage workflow, EXIF extraction workflow, email token generation/validation, outbox worker/retry, municipality resolver, point-in-polygon application service, municipal email dispatch, citizen final email, PWA, AI or public map. The schema and extension points merely prepare those future phases.

## Legacy project preservation

All writes in this phase target new root-level directories/files and the isolated new development database. `D:\xampp\htdocs\project_2\project` was used only for the prior read-only analysis; no file, configuration, schema, seed or source code in that directory was modified.
