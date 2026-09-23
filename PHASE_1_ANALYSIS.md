# Фаза 1 — технически анализ на reference проекта „Докторант“

**Обхват:** `D:\xampp\htdocs\project_2\project`  
**Дата на анализа:** 14.08.2026  
**Режим:** само read-only анализ. Не са изпълнявани SQL операции и не са променяни файлове в `project`.

## Executive summary

Старият проект е custom PHP 8+/PDO/MariaDB приложение без външен framework, Composer или централен router. То има полезна разделеност на `app` services/security/auth и `public` endpoint-и/views, но архитектурата е частично MVC: бизнес логиката е предимно в services, а HTTP control и rendering често са в директни PHP файлове. Най-ценните за новата платформа са RBAC моделът, `Auth`, login rate limiting, audit моделът, PDO настройката, settings helper, защитеното файлово хранилище и download pattern. Те не бива да се копират буквално: има непълен глобален CSRF middleware, upload модулът приема единствено PDF и има открито несъответствие в API извикването му.

Email/SMTP/PHPMailer/`mail()`/queue инфраструктура не съществува. GPS/geocoding, municipality contacts, PWA и upload на фотографии също не съществуват и са нови компоненти.

## 1. Архитектура

### Директории

```text
project/
├── app/
│   ├── Auth/              # login, session и потребителски контекст
│   ├── Config/            # декларация на менюто
│   ├── Controllers/       # само PhdController
│   ├── Core/              # ErrorHandler
│   ├── Exceptions/        # ValidationException
│   ├── Helpers/           # Settings, Layout, audit helper
│   ├── Middleware/        # CSRF/Audit wrappers
│   ├── Security/          # CSRF, RBAC, Audit, LoginRateLimiter
│   ├── Services/          # домейн и persistence логика
│   ├── Validation/        # базов Validator
│   ├── autoload.php
│   ├── bootstrap.php
│   └── helpers.php
├── config/                # database.php; runtime config.php се създава от installer
├── database/              # schema.sql, seed.sql
├── docs/                  # README, installation manual, user manual
├── install/               # 3-стъпков installer
├── public/
│   ├── assets/            # локални Bootstrap, Bootstrap Icons, CSS
│   ├── layout/            # header, footer, main, menu
│   ├── pages/             # content partials/views
│   ├── ajax/              # JSON/HTML AJAX endpoint-и
│   ├── users/, roles/, phd/, reports/, lookups/, help*/ audit/
│   └── *.php              # front pages и role dashboards
└── storage/phd/           # файлове извън document root
```

### MVC, bootstrap и execution flow

* **Bootstrap:** почти всеки endpoint извиква `app/bootstrap.php`. Той задава `BASE_PATH`, зарежда `app/autoload.php`, runtime `config/config.php`, `config/database.php`, стартира PHP session, регенерира ID при първата сесия, включва helpers, създава PDO и извиква `CSRFMiddleware::handle()`.
* **Autoload:** `app/autoload.php` използва `spl_autoload_register` за `App\...` → `app/...php`; няма Composer/PSR dependency manager.
* **Configuration:** `config/database.php` изгражда PDO с `ERRMODE_EXCEPTION`, associative fetch и native prepared statements. `install/process.php` създава runtime `config/config.php` с DB данни, `APP_KEY` и `BASE_URL`; след инсталацията `install/step3.php` пише `config/installed.lock`. В копието за анализ `config/config.php` и `installed.lock` не присъстват, т.е. то не е готово за runtime без инсталация. Не е модифицирано.
* **Routing:** няма route table, front controller или rewrite rules. Apache достъпва директно файлове като `public/phd/upload.php`; URL и файл са силно свързани. `public/index.php` и `public/dashboard.php` само пренасочват според role.
* **Controllers:** `app/Controllers/PhdController.php` има list/upload операции, но основната част от HTTP handling се намира направо в `public/*/*.php`. Затова това е hybrid MVC, не последователна MVC реализация.
* **Services:** `UserService`, `LookupService`, `PhdService`, `PhdUploadService`, `PhdAccessService`, `ReportService`, `AuditService`; те съдържат SQL и домейн правила. Няма отделна `Models/` директория/ORM: масивите от PDO са de facto models.
* **Views:** content partials са в `public/pages/**`, а endpoint-ите задават `$content` и включват `public/layout/main.php`. `header.php`, `footer.php`, `menu.php` са shared layout. Views и control code са смесени.
* **Helpers:** `app/helpers.php` предоставя `csrf()`, `url()`, `asset()`, layout loaders и `safe_layout_path()`; `Helpers/Settings.php` държи процесен cache и upsert за настройки; `Layout.php` чете `layout_mode`.
* **Middleware:** `CSRFMiddleware` се стартира глобално, но само разпознава write methods и връща без `CSRF::validate()` — не налага защита. `AuditMiddleware` е helper wrapper, не pipeline middleware.

Нормалният поток е: HTTP request → endpoint включва bootstrap → сесия/PDO → `Auth::require...` → endpoint валидира входа → service с prepared SQL → audit → `$content` + layout или redirect/JSON. `PhdAccessService` добавя record-level правила върху RBAC за supervisor/student.

### Dependency management

Няма `composer.json`, `package.json` или vendor dependency manager. Bootstrap 5 и Bootstrap Icons са vendored в `public/assets/vendor`; JavaScript е inline във views и има един `fetch()` за audit table. Това опростява deployment, но прави обновяване на security dependencies и проследяване на версии ръчно.

## 2. Database

`database/schema.sql` е InnoDB/utf8mb4 schema. `seed.sql` създава system roles, permissions, role-permission mapping, doctoral lookup values, settings и help articles. Не съдържа реални потребители: първият admin се създава през installer.

### Логическа схема

```text
users ──< user_roles >── roles ──< role_permissions >── permissions
  │  ├──< audit_log
  │  ├──< phd_students.user_id                 (1:0..1, SET NULL)
  │  ├──< phd_students.supervisor_id            (1:N, SET NULL)
  │  └──< phd_uploads.uploaded_by/hidden_by     (1:N, SET NULL)
  │
phd_students ──< phd_uploads
  ├── programs
  ├── directions
  ├── departments
  ├── education_forms
  └── phd_statuses                              (all optional, SET NULL)

settings, help_articles, login_attempts         (independent tables)
```

### Таблици и оценка за reuse

| Таблица | Предназначение, ключови полета и зависимости | Оценка |
|---|---|---|
| `users` | PK `id`; имена, unique `email`, `password_hash`, active/forced password change, timestamps/soft delete. Участва във всички user relations. Index `idx_users_active`. | **ADAPT** — добра account основа; опростяване/нови profile полета според гражданин/администратор. |
| `roles` | PK `id`, unique `name`, description, `is_system_role`. M:N с users и permissions. | **KEEP/ADAPT** — generic RBAC. |
| `permissions` | PK `id`, unique permission name/description. | **KEEP/ADAPT** — нов namespace (`reports.*`, `municipalities.*`, `email.*`). |
| `user_roles` | Composite PK (`user_id`,`role_id`), cascade FK към `users`, `roles`. | **KEEP**. |
| `role_permissions` | Composite PK (`role_id`,`permission_id`), cascade FK към `roles`, `permissions`. | **KEEP**. |
| `programs` | Academic lookup: id, name, is_active. | **REMOVE** — doctoral-only. |
| `directions` | Academic professional direction lookup. | **REMOVE**. |
| `departments` | University department lookup. | **REMOVE**; не е municipality table. |
| `education_forms` | Full/part-time education lookup. | **REMOVE**. |
| `phd_statuses` | Academic student lifecycle state. | **REPLACE** с `report_statuses`. |
| `phd_students` | PK id; identity, thesis topic, academic lookups, supervisor/user and lifecycle FKs. Unique faculty/EAN/user indexes. | **REMOVE** — core academic aggregate. |
| `phd_uploads` | PK id; FK doctoral student, uploader/hider; original/stored name, MIME, size, hash, type, version, visibility, timestamps. Indexes on parent/uploader/hidden/date/type/hash. | **REFACTOR** в `report_photos`; reusable metadata/security pattern, но parent, file types and domain behavior must change. |
| `audit_log` | PK bigint; optional user FK, action/entity fields, IP, user agent, method, route, login identifier, enum `severity` (`info`,`warning`,`critical`), enum `status` (`success`,`failed`), session id/time. Indexes user, time, entity pair, status/action/login. | **ADAPT** — strongest reusable audit base; current writer does not populate every column. |
| `settings` | PK id; unique `name`, text `value`, timestamps. | **KEEP/ADAPT** — use for safe non-secret application options; SMTP credentials should not be stored plain here. |
| `help_articles` | Help CMS: title, slug, category, content, active, timestamps; category/active indexes. | **OPTIONAL ADAPT** — not core Phase 1. |
| `login_attempts` | PK bigint; email/IP/success/created time, email/IP/created indexes. | **KEEP/ADAPT** — retain but improve limiting strategy. |

**Primary and foreign keys:** all entity IDs are unsigned auto-increment integers except audit/login attempts (`BIGINT`); junction tables use composite primary keys. FKs with cascade are used for RBAC join rows and `phd_uploads` under `phd_students`; user references are normally `SET NULL` to retain historic records. `phd_students` lookup references are optional `SET NULL`.

**ENUM-и:** само `audit_log.severity` и `audit_log.status`; no status enum in student tables. For the new system, status should be lookup-driven (`report_statuses`) rather than a hardcoded DB enum. **Settings:** seed contains layout/theme/student login/custom header/footer/favicon keys, which are UI/project specific, not suitable as-is. **Security/audit tables:** `users`, RBAC tables, `login_attempts`, and `audit_log` are the relevant set.

## 3. Authentication и security

### Налични механизми

* `app/Auth/Login.php` търси active, non-deleted user и използва `password_verify`.
* `app/Services/UserService.php` и installer използват `password_hash(..., PASSWORD_ARGON2ID)`.
* `app/Auth/Auth.php` пази `user_id`, user profile, roles и permissions в session; регенерира session ID на login; logout изтрива cookie и session.
* RBAC е данни-driven с `roles`, `permissions`, M:N join tables. `Auth::requireLogin()`, `requireRole()` и `requirePermission()` правят route guards; `PhdAccessService` е record-level authorization за admin/operator/supervisor/student.
* `LoginRateLimiter`: 5 неуспешни опита за 15 минути за комбинация email+IP; записва успех/неуспех; cleanup на записи над 1 ден, извикван probabilistically при login.
* `CSRF` генерира 32-byte random token, сравнява с `hash_equals`, token се регенерира при login. Има скрити inputs чрез `csrf()`.
* `Audit` записва действия; logout не се audit-ва въпреки документацията.
* SQL предимно използва PDO prepared statements; table names в `LookupService` са allowlist-нати.

### Рискове и решение за директен reuse

| Механизъм | Оценка | Действие за новия проект |
|---|---|---|
| Argon2id hashing + `password_verify` | Добър | **KEEP**. Добавят се minimum password policy и rehash check. |
| Session ID regeneration | Добър, но cookie flags не се настройват централизирано | **ADAPT**: `Secure`, `HttpOnly`, `SameSite=Lax/Strict`, timeout, cookie params преди `session_start`. |
| RBAC schema/Auth guard | Добра reusable основа | **ADAPT** за `admin`, `operator`, евентуално `citizen`; permissions по новия домейн. |
| Record-level access service | Концептуално полезен | **REFACTOR** като `ReportAccessService`: owner/assigned municipal operator/admin, не supervisor/student. |
| CSRF class | Добра primitive | **KEEP**, но middleware задължително да извиква validate за всички unsafe methods и API/PWA policy да е ясна. |
| Глобален CSRF middleware | Не защитава — `handle()` няма validate | **REPLACE/FIX before reuse**. Не е безопасно да се приема, че всички POST са защитени. |
| Ръчна CSRF защита | Присъства само в upload/hide/show; много write endpoint-и не я извикват | **REFACTOR** до една централизирана политика. |
| Login rate limiter | Добра минимална основа | **ADAPT**: нормализиране на identifier, separate IP/global limits, deterministic cleanup/cron, generic error response. |
| Validator | Само `required`, `min`, `numeric`, `year` | **REPLACE/EXTEND** — недостатъчен за report/GPS/photo input. |
| Error handler | Една HTML error view | **ADAPT**: structured log, безопасно production messaging and JSON errors for AJAX/PWA. |

Важно: PHP code във `public` има permission checks в множество endpoint-и, но защитата е неравномерно разпределена. Новото приложение трябва да не доверява UI visibility или `Auth` session cache за критични data changes без server-side checks.

## 4. File upload

### Реализация

* Основен service: `app/Services/PhdUploadService.php`.
* HTTP handler: `public/phd/upload.php`; view: `public/pages/phd/uploads-content.php`.
* Record authorization: `app/Services/PhdAccessService.php`.
* Controlled download: `public/phd/download.php`.
* Visibility: `public/phd/hide_upload.php`, `public/phd/show_upload.php`.
* Metadata: `phd_uploads` и physical storage `storage/phd`, извън `public`.

### Текущ workflow и controls

1. Endpoint requires permission `phd.upload`, POST and explicit `CSRF::validate()`; проверява student ID/type/file и `canManageUploads()`.
2. Service допуска максимум 20 MiB; изисква `UPLOAD_ERR_OK`; с `finfo(FILEINFO_MIME_TYPE)` проверява реалното съдържание и допуска само `application/pdf`; проверява `.pdf` extension.
3. Създава `storage/phd` с mode 0755 при нужда; генерира непредвидимо име `bin2hex(random_bytes(32)).'.pdf'`; използва `move_uploaded_file`.
4. След записване измерва MIME от destination, пресмята SHA-256 и вкарва оригинално име, stored name, MIME, size, type, version и author в `phd_uploads`; записва audit event.
5. Download endpoint изисква permission + record-level check, отказва hidden/deleted record, canonicalizes base/path с `realpath`, проверява че пътят е под `storage/phd`, подава MIME/length/content disposition, `nosniff`, restrictive CSP и private cache. Файлът не се раздава директно от web root.

### Подходящо ли е за снимки?

Да — **като security pattern**, не като готов компонент. Задължителните промени са: multiple uploads (масив `$_FILES` или отделни requests), allowlist `image/jpeg`, `image/png`, `image/webp` (само ако поддържани), maximum photo count/size/total report size, `getimagesize()`/GD or Imagick decode verification, strip/re-encode or EXIF extraction policy, orientation normalization, dimensions/pixel-bomb limits, individual storage subdirectories, DB transaction/compensation ако DB insert се провали след move, malware scanning policy и quarantined state. GPS и capture time трябва да се извлекат от EXIF само като **untrusted source**, а client-provided coordinates/date да се валидират и да се пази source/accuracy.

Открити ограничения: `PhdUploadService::upload()` приема само 4 аргумента, но `public/phd/upload.php` я извиква със 7 (`versionNo`, `isHidden`, `description` допълнително); в PHP 8 това е runtime incompatibility/грешка. Endpoint allowlist (`report`, `other`) не съвпада със service allowlist (`annual_report`, `protocol`, `annex`). `original_name` се пази непроменено и се подава в header чрез `basename`, но трябва допълнително да се премахват control characters/quotes при нова реализация. Няма DB transaction/cleanup при failure след физическо записване.

## 5. Email

Целенасоченото търсене в `app`, `config`, `public`, database files и installer не откри SMTP настройки, PHPMailer, `mail()`, mailer service, template, queue, retry, delivery log или email error handling. `users.email` се използва за login/account identity, не за изпращане.

**Извод:** email подсистемата е изцяло **NEW**. Не следва да се използва `mail()` като основа. Предлага се `EmailService` с SMTP transport (напр. PHPMailer или поддържан SMTP client), templates, immutable `email_queue`/delivery attempt записи, retry/backoff, idempotency key, timeout и audit/event correlation. SMTP secret-и трябва да са извън `settings`/Git и да не се отпечатват в log-а. Генерираният report може да е HTML email и/или PDF attachment; това е отделна future implementation decision.

## 6. Audit / logging

`audit_log` е подходяща основа. Writer chain е `AuditMiddleware::record()` → `Security/Audit::log()`. Реално се записват user, action, entity type/id, login identifier, status, IP и timestamp; writer **не попълва** наличните schema колони `user_agent`, `request_method`, `route`, `severity`, `session_id`. `AuditService::getLatest()` чете само ограничен subset за UI.

За новата платформа да се пазят минимум следните action codes:

| Събитие | `entity_type` / action | Статус/детайл |
|---|---|---|
| Създаване на сигнал | `report` / `report.created` | report ID, actor/anonymous context |
| Статус | `report` / `report.status_changed` | стар/нов status в structured metadata/event table |
| Снимка | `report_photo` / `report.photo_uploaded` | hash, MIME, size; без чувствителен EXIF в audit text |
| Избор/промяна на община | `report` или `municipality_contact` | source of municipality resolution/contact revision |
| Email enqueue | `email_queue` / `email.queued` | target contact ID, no raw confidential body |
| Success/failure/retry | `email_queue` / `email.sent|failed|retry_scheduled` | provider message ID/error class/retry count |
| Административни действия | `municipality`, `municipality_contact`, `settings`, `user` | before/after or reference to domain event |

Препоръка: `report_events` да е домейн историята (timestamp, report, actor, event type, structured payload), а `audit_log` — cross-cutting security/admin ledger. Това избягва претоварване на audit със state machine details. Добавя се JSON `metadata`, populated request/session fields и индекс `(entity_type, entity_id, created_at)`.

## 7. UI

* Локално bundled Bootstrap 5/Bootstrap Icons: `public/assets/vendor/...`; CSS `app.css`, `layout.css`, `dashboard.css`, практически празните `forms.css`, `tables.css`.
* Shared layout: `public/layout/header.php`, `main.php`, `menu.php`, `footer.php`; header има viewport meta, footer зарежда Bootstrap bundle.
* Менюто идва от `app/Config/menu.php` и филтрира по permission. Role dashboard-и са в `public/dashboards` и `public/pages/{admin,operator,student,supervisor}`.
* AJAX е минимален: `public/pages/audit/index-content.php` използва `fetch()` към `public/ajax/audit_table.php`; има още dashboard/phd AJAX endpoint-и. Няма централен JS build, SPA API или service worker.
* Responsive: Bootstrap grid дава базова responsive behavior; `layout.css` при max 992px прави sidebar full-width/normal flow. Това не е mobile-first navigation и не е достатъчно за удобен camera-first PWA. Няма manifest/service worker/offline strategy.

**Запазване:** Bootstrap distribution, design tokens/общ CSS като начален style base, generic layout pattern, permission-filtered menu и reusable table/form conventions.  
**Замяна/сериозна адаптация:** hardcoded „Докторант“ branding, desktop sidebar, dashboard-и, role labels, academic menu icons/labels, inline scripts, custom embedded header/footer settings. За новата система UI трябва да започва с mobile-first report capture, camera/file input, geolocation consent/status, upload progress, accessible errors and an offline/retry strategy before adding desktop admin screens.

## 8. Код, специфичен за „Докторант“ — да не се пренася

* Data model and services: `phd_students`, `phd_uploads` като doctoral entity, `programs`, `directions`, `departments`, `education_forms`, `phd_statuses`; `PhdService.php`, `PhdAccessService.php`, `PhdController.php`, `ReportService.php` (старите справки).
* Public modules: `public/phd/**`, `public/pages/phd/**`, `public/ajax/phd.php`, `public/dashboards/student.php`, `supervisor.php`, `operator.php` and the corresponding page content.
* Academic roles/permissions/seed: `teacher`, `student`, their `phd.*` permission names, `student_login_enabled` setting and `toggle_student_login.php`.
* Supervisor and student ownership rules: `supervisor_id`, `user_id` association as doctoral identity, operator restriction after uploads.
* Academic fields/workflow: faculty/EAN numbers, dissertation topic, enrolment/graduation/order data, program/direction/department/form, dissertation/annual report/protocol/annex version categories.
* Academic presentation/branding: mortarboard icons, title/footer, doctoral reports and all text/content that refers to doctoral students.
* Optional non-core components to postpone rather than migrate: legacy help CMS (`help_articles`, `public/help*`, `public/help_admin*`) and custom header/footer embedding. They may be reconsidered later independently.

## 9. Reusable components

| Категория | Компонент | Предназначение и зависимости |
|---|---|---|
| **A. Директно преизползваеми** | `app/autoload.php` | Namespace autoloader; depends on `BASE_PATH`. |
| A | `config/database.php` | PDO connection defaults; depends on new secret/config source. |
| A | `Auth/Auth.php` core methods | Login/session/RBAC cache and guards; depends on users/RBAC schema. Apply cookie hardening in bootstrap. |
| A | `Security/CSRF.php` | Token primitive; session dependency. |
| A | RBAC tables | Generic role/permission many-to-many data model. |
| A | `Security/Audit.php`, `Middleware/AuditMiddleware.php` concept | Central audit writer; depends on adapted audit table. |
| A | `Helpers/Settings.php` | Generic settings cache/upsert; depends on settings. |
| A | Local Bootstrap/Icons | No external runtime dependency. |
| **B. Малки промени** | `Security/LoginRateLimiter.php` + `login_attempts` | Same mechanism; adjust policy and cleanup. |
| B | `Services/UserService.php` | Argon2id user management; remove academic methods and strengthen validation/transactions. |
| B | `helpers.php` `url/asset/csrf` | Useful helpers; replace custom layout portions. |
| B | `layout/header.php`, `footer.php`, base CSS | Bootstrap shell and responsive baseline; rebrand/new navigation. |
| B | `audit_log` / `AuditService.php` | Add metadata/full column population/new queries. |
| **C. Сериозен refactoring** | `app/bootstrap.php` | Solid start but must centralize CSRF, secure session options, config validation/error logging. |
| C | `PhdUploadService.php` + `public/phd/download.php` pattern | Keep protected storage/hash/download ideas; redesign for photos, multiple files, EXIF, transactions/quarantine. |
| C | `LookupService.php` | Safe allowlist CRUD idea; domain-specific lists must become separate typed municipality/category/status services. |
| C | `PhdAccessService.php` | Replace academic ownership with report ownership/assignment rules. |
| C | `public/layout/main.php`, menu config | Refactor from include/layout and desktop sidebar to mobile/PWA-friendly shell. |
| C | `Validation/Validator.php` | Extend or replace with complete request/domain validation. |
| **D. Изхвърляне** | `PhdService.php`, `PhdController.php`, old `ReportService.php` | Entirely doctoral queries/workflows. |
| D | `public/phd/**`, `public/pages/phd/**`, `public/dashboards/{student,supervisor,operator}.php` | Academic use cases/views. |
| D | Academic lookup tables/seeds and `phd.*` permissions | Not meaningful in citizen-report domain. |
| D | custom embedded header/footer and doctoral help content | Scope/branding-specific; no Phase-1 business value. |

## 10. Предложена начална архитектура за новия проект

Остава PHP + MariaDB + Bootstrap + plain JavaScript, без нов framework. Препоръчителна е еволюция на настоящата структура, не механично копиране:

```text
app/
  Auth/ Security/ Middleware/ Core/ Exceptions/ Validation/
  Controllers/                 # Report, Municipality, Admin, Auth
  Services/                    # Report, Photo, MunicipalityResolver, Email, Queue, Audit
  Repositories/                # optional boundary for PDO queries (reports, contacts, etc.)
  Helpers/
config/                         # non-secret defaults; secret runtime config outside VCS
database/                       # future schema/seed only after approved phase
public/
  index.php                     # front controller / small explicit dispatcher
  assets/                       # Bootstrap, app CSS/JS, manifest/icons later
  pages/ layout/ api/           # server views and narrow JSON endpoints
routes/                         # explicit route map/dispatcher, no framework required
storage/
  reports/{report-id-or-date}/  # outside public: originals, derivatives, quarantine
  logs/
resources/
  views/email/                  # email templates
  templates/                    # report templates if PDF is introduced
```

Suggested request flow: public/mobile form → central dispatcher/CSRF/auth/rate-limit middleware → `ReportController` → `ReportService` transaction creates report and photo metadata → `MunicipalityResolver` resolves municipality/contact with deterministic stored result → render report payload → enqueue email in same DB transaction → worker/cron invokes `EmailService` → delivery update + `report_events` + audit. The queue must make send retryable and avoid sending the same report twice.

For public citizen submissions decide explicitly in the next phase whether account is required. A no-account report needs a generated public tracking token stored hashed, submission/IP abuse throttling and no reliance on `Auth::userId()`; administration still uses the reusable RBAC foundation.

## 11. Нова database концепция (само proposal; няма SQL)

| Таблица | Основни полета/relations | Цел |
|---|---|---|
| `users` | id, name/display fields, email unique, password_hash, active, timestamps, soft delete | Администратори/оператори и optional registered citizens. |
| `reports` | id, public reference/token hash, reporter user nullable, category FK, status FK, municipality FK nullable, title/description, latitude/longitude DECIMAL, GPS accuracy/source, occurred_at/captured_at, submitted_at | Централният aggregate на сигнала. Coordinates should use DECIMAL, not float. |
| `report_photos` | id, report FK cascade, storage key, original filename, MIME, size, SHA-256, width/height, captured_at, EXIF GPS optional/controlled, ordering, processing state | 1:N images, originals outside public. |
| `municipalities` | id, official name/code, active, geometry/bounds or resolver source/version | Municipality catalogue and spatial-resolution target. Geometry strategy is an explicit later choice. |
| `municipality_contacts` | id, municipality FK, name/department, email, category FK nullable, priority, active, verified_at, timestamps | Recipient selection; preserve historical contact snapshot on report/email. |
| `report_categories` | id, code unique, name, description, active | Configurable classification. |
| `report_statuses` | id, code unique, name, sort order, terminal flag, active | State machine lookup. Allowed transitions may be separate table/service rule. |
| `report_events` | bigint id, report FK, actor user nullable, event type, old/new status nullable, payload JSON, created_at | Immutable business history. |
| `email_queue` | bigint id, report FK, contact FK nullable, recipient snapshot, subject/body or template+payload, status, attempts, available_at, sent_at, provider_message_id, last_error, idempotency key | Transactional outbox/queue and retry record. |
| `audit_log` | Adapted old table plus metadata JSON, request/session fields | Security/admin audit across all modules. |
| `settings` | name unique/value/timestamps | Non-secret runtime options, e.g. max photo size, public submission enablement. |

Recommended indexes: `reports(status_id, created_at)`, `reports(municipality_id, created_at)`, `reports(category_id, created_at)`, coordinate/spatial index appropriate to selected geometry design, `report_photos(report_id, sort_order)`, `municipality_contacts(municipality_id, category_id, is_active, priority)`, `report_events(report_id, created_at)`, `email_queue(status, available_at)`, unique `email_queue.idempotency_key`, and existing audit entity/time indexes. Use FK behavior intentionally: cascade for photos/events/queue under a report only if hard deletes are allowed; for a legally important platform prefer soft delete/retention policy and immutable history.

## 12. Migration Map

| Стар компонент | Файл/директория | Нов компонент | Действие | Приоритет |
|---|---|---|---|---|
| Namespace autoload | `project/app/autoload.php` | application autoload | KEEP | P1 |
| Bootstrap/PDO/session | `project/app/bootstrap.php`, `config/database.php` | secure bootstrap + DB factory | REFACTOR | P1 |
| Runtime installer config | `project/install/process.php` | environment/secret configuration | REPLACE | P1 |
| Auth/session | `project/app/Auth/Auth.php`, `Login.php` | AuthService/Session | ADAPT | P1 |
| Password hashing | `UserService.php`, `install/step3.php` | user credential handling | KEEP | P1 |
| RBAC schema and guards | schema/seed + `Security/RBAC.php` | admin/operator RBAC | ADAPT | P1 |
| CSRF primitive | `app/Security/CSRF.php` | CSRF service | KEEP | P1 |
| Inert CSRF middleware | `app/Middleware/CSRFMiddleware.php` | global unsafe-request verifier | REPLACE | P1 |
| Login throttling | `Security/LoginRateLimiter.php`, `login_attempts` | authentication rate limit | ADAPT | P1 |
| Audit writer/table | `Security/Audit.php`, `audit_log` | AuditService/AuditLog | ADAPT | P1 |
| Settings | `Helpers/Settings.php`, `settings` | SettingsService | ADAPT | P2 |
| File upload metadata/storage | `PhdUploadService.php`, `storage/phd`, `phd_uploads` | PhotoStorageService/report_photos | REFACTOR | P1 |
| Protected file download | `public/phd/download.php` | protected photo delivery | ADAPT | P1 |
| Record authorization | `PhdAccessService.php` | ReportAccessService | REFACTOR | P1 |
| Academic entity/services | `PhdService.php`, `PhdController.php`, `phd_students` | Report aggregate | REMOVE / NEW | P1 |
| Academic statuses/lookups | programs/directions/etc. | categories/statuses/municipalities | REPLACE | P1 |
| Old reports/dashboard | `ReportService.php`, `public/reports`, dashboards | report operations dashboard | REMOVE / NEW | P2 |
| `LookupService` pattern | `app/Services/LookupService.php` | typed admin CRUD services | REFACTOR | P2 |
| Email | absent | EmailService + outbox worker/templates | NEW | P1 |
| GPS/municipality resolution | absent | MunicipalityResolver/geo data | NEW | P1 |
| Domain history | only generic audit | `report_events` | NEW | P1 |
| Bootstrap/assets | `public/assets`, layout/CSS | responsive mobile-first UI | ADAPT | P2 |
| PWA | absent | manifest/service worker/offline queue UX | NEW | P3 |
| Help CMS/custom embedded layout | `help*`, `custom/*`, layout settings | optional support content | REMOVE / NEW later | P3 |

## Conclusion and Phase-2 entry criteria

The old repository is a viable reference implementation, especially for custom-PHP bootstrap, accounts/RBAC, protected storage and audit. It is not a safe drop-in base: first implementation work should establish the new report/photo/municipality/email domain and correct the inherited CSRF/upload/session shortcomings. Before code begins, confirm: public vs account-only submission; municipality boundary data/resolution provider; supported image formats/limits and EXIF privacy policy; SMTP provider/credentials/worker execution method; report status lifecycle and retention/privacy rules.

No implementation, migration, schema or source files have been created or altered in `project` during this phase.
