# Фаза 2 — Техническа спецификация на MVP за граждански сигнали

**Обхват:** проектът в `D:\xampp\htdocs\project_2`; старият `project/` е само reference.  
**Статус:** design/specification only. Не са създавани PHP класове, database schema, migrations или source code.

## 1. Architecture

MVP остава custom PHP 8+, MariaDB, PDO, Bootstrap 5 и plain JavaScript. Не се въвежда framework. Публичният flow е без акаунт и без authenticated PHP session; административната част използва отделна login session и RBAC, адаптирани от reference проекта.

```text
public report form
  → request validation + public rate limit + CSRF
  → PhotoInspectionService (original photo, MIME/image/EXIF validation)
  → SignalService transaction (signal + photo metadata + validation token hash + outbox email)
  → commit → worker sends validation email

validation link
  → token verification / single-use consume
  → MunicipalityResolver → ContactSelector
  → create municipal outbox message → dispatch worker
  → delivery result + signal event + final confirmation outbox message

admin session/RBAC
  → signals, municipality contacts, categories, statuses, audit views
```

Recommended future directory structure (no files are created now):

```text
app/
  Auth/ Security/ Middleware/ Validation/ Core/
  Controllers/       # PublicSignalController, ValidationController, Admin/*
  Services/          # Signal, PhotoInspection, MunicipalityResolver, ContactSelector,
                     # Email, Outbox, Audit
  Repositories/      # PDO query boundaries
config/              # non-secret defaults; secrets supplied outside VCS
database/            # later approved schema/seed
public/              # dispatcher, pages, assets, protected-response endpoints
resources/email/     # HTML/text email templates
storage/
  originals/         # non-public immutable original photo
  derived/           # optional thumbnail/optimized copy
  quarantine/        # failed/pending scan files, if enabled
  logs/
routes/              # explicit custom route map/dispatcher
```

The application must use explicit route dispatch rather than expose arbitrary PHP files. Every unsafe browser request must pass centralized CSRF validation; public endpoints must instead use an anonymous CSRF token plus rate limits and must not require an authenticated account/session.

### MVP invariants

1. One `signal` has exactly one original photo (`signal_photos`: cardinality 1:1).
2. A signal is persisted before its email address is validated.
3. No municipal email is queued or sent before successful email validation.
4. GPS and capture time come only from the original photo EXIF, not browser geolocation, IP geolocation or server time.
5. Valid EXIF GPS and valid `DateTimeOriginal` are mandatory; failure means no signal is created/submitted.
6. `submitted_at` is server-generated and distinct from EXIF `captured_at`.
7. Only an outbox worker sends email. HTTP requests never send municipal email synchronously.

## 2. Signal lifecycle / state machine

`signal_statuses` is a configurable lookup with immutable codes below. The application, not a free-form admin UI, enforces transitions.

| Status | Meaning / when set | Writer |
|---|---|---|
| `DRAFT` | Optional short-lived pre-persistence/form-upload preparation state; never eligible for email or dispatch. | Public form/controller |
| `PENDING_EMAIL_VALIDATION` | Signal/photo and hashed validation token are committed; validation email is queued. | SignalService |
| `EMAIL_VALIDATED` | Token is valid and atomically consumed; address ownership is confirmed. | ValidationController/Service |
| `READY_FOR_DISPATCH` | EXIF/location is valid, municipality and active contact are resolved and municipal message is queued. | DispatchPreparationService |
| `DISPATCHING` | A worker has atomically leased the municipal outbox message. | Outbox worker |
| `SENT` | Municipal message has an accepted/success result; final confirmation is queued/sent separately. | Outbox worker |
| `FAILED` | Municipal dispatch exhausted retry policy or has a non-retryable failure. | Outbox worker/admin retry action |
| `CANCELLED` | Deliberately cancelled by authorized admin before sending; no further delivery. | Admin service |
| `EXPIRED` | Email-validation token expired before use. | token check/expiry job |

### Allowed transitions

```text
DRAFT → PENDING_EMAIL_VALIDATION
PENDING_EMAIL_VALIDATION → EMAIL_VALIDATED | EXPIRED | CANCELLED
EMAIL_VALIDATED → READY_FOR_DISPATCH | FAILED | CANCELLED
READY_FOR_DISPATCH → DISPATCHING | FAILED | CANCELLED
DISPATCHING → SENT | READY_FOR_DISPATCH | FAILED
FAILED → READY_FOR_DISPATCH | CANCELLED
```

`SENT`, `CANCELLED` and `EXPIRED` are terminal for the MVP. `FAILED → READY_FOR_DISPATCH` is an explicit, authorized retry/requeue only, and must retain all previous event/audit history. `DISPATCHING → READY_FOR_DISPATCH` occurs only after lease expiry/recovery when it is provably safe to retry; see outbox rules.

### Edge cases

* **Expired validation link:** verification marks the still-pending signal `EXPIRED`, consumes/invalidates the token and presents an expiry page. A new signal is required; this avoids accepting a report whose original intent/recipient may be stale.
* **Repeated valid click:** the first request atomically sets `validated_at` and `consumed_at`. A later click returns an idempotent “already validated” response with reference/status; it must not re-resolve or re-send to the municipality.
* **Dispatch error:** transient failures retry through `email_queue`; the signal returns/retains `READY_FOR_DISPATCH` between attempts. Non-retryable errors or exhausted attempts set `FAILED` and create events/audit records.
* **Successful submission:** a signal is successfully submitted only when the municipal email delivery provider accepts the message and the dispatch outbox record is `sent`; then signal status is `SENT`. The final citizen email is an additional notification and does not change the fact of municipal submission.

## 3. Email validation flow

### Token design

For each signal create a cryptographically random token of at least 32 random bytes, encoded URL-safe (e.g. base64url or hex). Store only a keyed/hash representation: `validation_token_hash = HMAC-SHA-256(token, application_pepper)` or `hash('sha256', token)` where the application pepper is secret. The raw token exists only in process memory and in the HTTPS email URL; never in database, logs, audit metadata or error pages.

The validation record is linked by `signal_id`, carries `issued_at`, `expires_at`, `consumed_at`, `resend_count`, `last_sent_at` and an optional token version. Only one current unconsumed token is valid. Recommended MVP expiry: **24 hours**. A token is single-use, high entropy, constant-time compared, and never enumerable by sequential IDs.

### Exact flow

```text
1. User submits one valid original image + email (+ optional category/description).
2. Server validates image, extracts and validates mandatory EXIF data.
3. One DB transaction creates signal=PENDING_EMAIL_VALIDATION,
   signal_photo metadata, validation-token hash/expiry, events and validation-email outbox item.
4. Commit; worker sends the validation email.
5. User opens HTTPS link containing opaque token.
6. Verification transaction hashes received token, locks matching unconsumed/non-expired record,
   records consumed_at/validated_at and changes status to EMAIL_VALIDATED.
7. The service resolves municipality and contact; on success it creates municipal outbox item,
   event and status READY_FOR_DISPATCH. It never sends inline.
8. Worker dispatches to municipality, records outcome, then queues citizen final confirmation after SENT.
```

### Resend and abuse limits

* A resend is permitted only for `PENDING_EMAIL_VALIDATION`, current token unexpired and unsent/undelivered failure as appropriate. Minimum cooldown: 60 seconds; maximum: 3 validation sends per signal per 24 hours.
* Prefer sending the same still-valid token on a normal resend, which prevents multiple active links. If security policy requires rotation, atomically invalidate old hash/version before queueing the new token; never permit both.
* Public creation: limit by IP-prefix and normalized email hash (e.g. 3/hour per email, 10/hour per IP, subject to operational tuning), add request-body size limits and optional CAPTCHA only when abuse evidence warrants it.
* Validation endpoint: rate-limit attempts by IP and token prefix; always return a neutral invalid/expired result for absent, malformed or guessed tokens. Store only a hash of email in public rate-limit logs where feasible.
* A valid token consumes exactly once under transaction/row lock. Any email-send retry reuses one queue record/idempotency key; it does not create a second signal.

## 4. Signal data classification

| Field | Classification | Notes |
|---|---|---|
| `signal_id` | System generated | Internal unsigned/bigint primary key. |
| `public_reference` | System generated | Opaque, non-sequential public reference, unique. |
| reporter email | Required | Validate/sanitize and store encrypted at rest if supported; never publish. |
| original photo | Required | Exactly one accepted image. |
| latitude/longitude | Required, derived | Derived only from validated EXIF GPS; DECIMAL precision, not float. |
| GPS refs/source | Required/derived | `GPSLatitudeRef`, `GPSLongitudeRef`; source is fixed `exif_original`. |
| GPS accuracy | Optional/derived | Standard EXIF GPS accuracy is not universally available; store only if reliable/vendor-specific. |
| `captured_at` | Required, derived | Validated EXIF `DateTimeOriginal`; preserve original timezone limitation/source. |
| `submitted_at` | System generated | UTC server timestamp at accepted persistence. |
| category | Optional | Default `uncategorized` or user-selected active category. |
| description | Optional | Size-limited plain text; escaped on output. |
| municipality/contact | Derived | Snapshot selected municipality/contact ID and recipient email/name at dispatch preparation. |
| MIME, size, SHA-256, dimensions | Derived | From original accepted upload. |
| status/validation/dispatch dates | System generated | State and event history; no client control. |
| created_at/updated_at | System generated | UTC timestamps. |

The municipality email must contain the citizen email only when justified as reply contact and disclosed in the privacy notice. It must never expose raw validation token or internal storage paths.

## 5. GPS and location flow

### Source and validation

Only EXIF read from the original uploaded file is acceptable. The browser/device Geolocation API is not called, no location permission is requested, IP geolocation is prohibited, and current server time cannot substitute missing `DateTimeOriginal`.

1. Validate MIME/decode the original image before trusting its EXIF.
2. Extract `GPSLatitude`, `GPSLongitude`, `GPSLatitudeRef`, `GPSLongitudeRef`, `DateTimeOriginal` from the unmodified original.
3. Convert DMS rationals to decimal degrees; reject zero denominators, malformed rationals, missing/invalid refs, NaN and out-of-range latitude `[-90,90]`/longitude `[-180,180]`.
4. Convert N/S and E/W signs only from valid refs. Validate `DateTimeOriginal` strictly against `YYYY:MM:DD HH:MM:SS`; reject impossible dates and a capture time materially in the future (allow small clock skew, e.g. 10 minutes).
5. Confirm point is within a versioned Bulgaria boundary polygon. Reject coordinate outside Bulgaria, sea/outside coverage and unresolved points. A point on municipality boundary or within a configured small boundary tolerance is **ambiguous**, not auto-dispatched.
6. Persist normalized coordinates, original EXIF date string, normalized capture time, extractor/parser version and validation result. `submitted_at` is set separately by the server.

### Required rejection messages

The form must stop before creating a signal if GPS is missing/invalid, `DateTimeOriginal` is missing/invalid, or the point cannot be resolved unambiguously in Bulgaria. User-facing text should say which required metadata is missing and instruct that the photo must be taken with camera location and date/time metadata enabled; do not reveal polygon internals.

### Timezone caveat

EXIF `DateTimeOriginal` has no mandatory timezone. Store it as `captured_at_local` plus `captured_timezone_unknown=true` unless a trustworthy offset is explicitly present. Do not silently label it UTC. All server timestamps use UTC.

## 6. Municipality resolution

### `MunicipalityResolver` contract

**Input:** normalized latitude, longitude.  
**Output:** `municipality_id`, `municipality_name`, `resolution_source`, `resolution_version`, `certainty`, and optional `ambiguity_reason`.

### Recommendation for MVP

Use a **local, versioned authoritative municipality-boundary dataset** loaded into MariaDB (or a locally queried GIS-capable data store) and point-in-polygon containment. Store the dataset source, publication date/checksum and version with each result. This makes resolution deterministic, auditable, available when external services fail and avoids external API dependency.

Reverse geocoding may enrich a human-readable address later but must not determine legal recipient in MVP. An external API is a fallback/enrichment candidate only, not the source of truth. Simple bounding boxes are insufficient except as a pre-filter before exact polygon test.

For points intersecting exactly one polygon with no tolerance conflict: `certainty=high`. For overlap, boundary tolerance, missing polygon coverage or unexpected multiple results: `certainty=ambiguous`; do not queue municipal dispatch, set/keep a non-dispatchable failure for admin review. The MVP should not guess the nearest municipality.

## 7. Municipality contacts

`municipalities` holds canonical identity/code, name, active state and boundary dataset relation. `municipality_contacts` has `municipality_id`, display name/department, `email`, type/category (`reports`, `registry`, `official`, `backup`), optional `signal_category_id`, `priority`, `is_active`, `verified_at`, `last_verified_at`, `source_url`, created/updated/soft-delete data.

### Recipient selection

```text
signal category + resolved municipality
  → active category-specific `reports` contact, lowest priority number
  → active general `reports` contact
  → active `registry` contact
  → active `official` contact
  → no contact: do not send; signal becomes FAILED/admin action required
```

The selected contact and recipient email must be snapshotted on the signal/outbox record. Subsequent contact edits must not rewrite history or alter a message already queued. Contacts require verified source and periodic review; only admins with dedicated permissions may edit them. Do not silently send to a backup if a higher priority address was attempted and returned a provider acceptance — delivery bounces are a separate operational process.

## 8. Email dispatch

### Municipal email

Recommended MVP output: multipart HTML + plain-text email with the original image attached once and a concise structured body. HTML permits readable layout; plain text supports receivers that block HTML. A PDF is **not required** and should be post-MVP unless an official recipient mandates it.

Required content:

* public reference number and unique internal/correlation identifier;
* submitted date/time and EXIF capture date/time (with timezone caveat if applicable);
* normalized GPS coordinates and a map link as convenience, not authority;
* resolved municipality and selected recipient role;
* optional human-readable address only if independently available and marked enrichment;
* category and user description, both safely encoded;
* reporter reply email, subject to privacy notice;
* one original photo attachment with original name sanitized, content type and hash/reference;
* statement that the location/time were extracted from image metadata and validated under MVP rules.

Use deterministic `Message-ID`/idempotency correlation per signal + recipient + message purpose. Set a controlled `Reply-To`; never use raw user values for SMTP headers. Validate addresses with an email parser and prevent CR/LF header injection.

### Citizen emails

**Validation email:** purpose explanation, public reference, expiry time, single HTTPS validation link, no photo attachment, and a support/privacy note.  
**Final confirmation:** public reference; submitted date/time; municipality; recipient email; `SENT` status; short category/description; statement that the report was sent. It must be queued only after municipal `SENT`, and it should not disclose SMTP/provider internals.

## 9. Email queue / transactional outbox

`email_queue` is both an outbox and delivery record. Creating a signal and validation-email queue record happens in **the same database transaction**. Creating a municipal queue record happens in the same transaction as `READY_FOR_DISPATCH`. This prevents a committed domain state with no intended message.

Core attributes: purpose (`validation`, `municipality_dispatch`, `citizen_final`), signal FK, contact FK nullable, recipient snapshot, template/payload snapshot, state (`pending`, `leased`, `sent`, `failed`, `cancelled`), `attempt_count`, `available_at`, `lease_until`, `sent_at`, `provider_message_id`, `last_error_code`, sanitized `last_error_summary`, `idempotency_key`, timestamps.

### Worker protocol

1. Atomically claim one due `pending` record using row lock/skip-locked equivalent or an atomic update; set `leased`, lease owner and short `lease_until`.
2. Before actual provider call, enforce unique idempotency key. The same signal/purpose/recipient combination has one logical record.
3. Send through configured SMTP/provider with a deterministic message correlation ID.
4. On provider acceptance, transactionally mark the row `sent`, save provider message ID, create event/audit and transition signal as appropriate.
5. On transient failure, increment attempts and return row to `pending` with exponential backoff: suggested 1, 5, 15, 60, 240 minutes; after 5 attempts mark `failed`.
6. On permanent failure (invalid configured recipient, provider-declared permanent failure), mark failed immediately; an admin may fix contact and explicitly create a new, traceable requeue record.
7. A recovery job examines expired leases. It must not blindly resend an email after a crash between provider acceptance and DB update. Preferred protection is provider-side idempotency/deterministic `Message-ID` plus an explicit unknown-delivery state/reconciliation policy. If provider API has no idempotency/reconciliation, the safe choice after an unknown result is `FAILED` for manual review, not automatic resend.

This rule is essential: an interrupted worker must never cause automatic duplicate municipal submission merely to obtain eventual success.

## 10. Photo handling

MVP accepts exactly one image part and rejects arrays/multiple file fields. Recommended accepted types: JPEG only for the strict first version, because required EXIF interoperability is strongest. PNG/WebP should be deferred unless their metadata handling is explicitly specified; most screenshots/processed images will fail required EXIF anyway.

Suggested MVP limits: maximum **10 MiB**, decoded dimensions max 24 megapixels, min sensible dimensions (e.g. 640×480), request body limit slightly above file limit. Exact limits remain operational settings, not client-enforced rules.

Required process:

1. Check upload error, size and single-file cardinality.
2. Use `finfo` plus actual image decode (`getimagesize`/image library) to reject MIME spoofing/corrupt files; verify JPEG signature/decoder behavior.
3. Extract EXIF from original before any resize/re-encode; apply the GPS/date rules in section 5.
4. Generate random storage key; sanitize and retain a display-safe original filename separately; calculate SHA-256 after move.
5. Store immutable original outside document root, e.g. `storage/originals/<opaque-prefix>/<random>.jpg`; never use original filename as path.
6. Store MIME, bytes, dimensions, hash, EXIF validation metadata and storage key in `signal_photos`.
7. Optionally create a derived thumbnail for admin listing. Strip EXIF from derived/display images by default; retain original only for evidence/dispatch attachment. Do not mutate the original.
8. Serve original/derived files only through an authorized controller after admin RBAC check, canonical path containment and security headers. Public reference alone must never grant photo access.

EXIF contains personal/location data; it is evidence for this workflow and must be access-limited, absent from public logs and deleted/retained according to policy. Add malware scanning/quarantine when operationally available; it is desirable but does not replace image decoding and EXIF validation.

## 11. Database design (conceptual only)

### Core entities and cardinality

```text
users ──< user_roles >── roles ──< role_permissions >── permissions
users ──< audit_log, signal_events (optional admin actor)

signals ── 1:1 ── signal_photos
signals ──< signal_events
signals ──< email_queue
signals >── signal_statuses
signals >── signal_categories (nullable/default)
signals >── municipalities (nullable until resolved)
signals >── municipality_contacts (selected snapshot reference, nullable until selected)

municipalities ──< municipality_contacts
signal_categories ──< municipality_contacts (optional category-specific route)
```

| Table | PK / main fields | Relations, constraints and indexes |
|---|---|---|
| `users` | bigint/int ID, admin identity, unique email, Argon2id hash, active, timestamps, soft delete | Administrative accounts only in MVP. Unique normalized email; active/deleted indexes. |
| `roles`, `permissions`, `user_roles`, `role_permissions` | generic RBAC | Composite PKs on joins; cascade only on join rows. |
| `signals` | bigint ID, unique `public_reference`, reporter email, category/status FKs, EXIF coordinates/time, submitted time, municipality/contact FKs, validation times, dispatch state/timestamps, description, version/timestamps | Index status+created, municipality+created, category+created, `public_reference` unique, pending-expiry index. Coordinates DECIMAL; never indexed as only floating values. |
| `signal_photos` | bigint ID, unique `signal_id`, storage key unique, safe original name, MIME, byte size, hash, width/height, EXIF metadata/validation fields, timestamps | 1:1 enforced by unique `signal_id`; unique SHA-256 may be index only, not global uniqueness (different signals may legitimately use same photo). |
| `signal_events` | bigint ID, signal FK, actor user nullable, event code, old/new status, structured JSON payload, time | Index signal+time; append-only. |
| `signal_statuses` | ID, unique immutable code, label, sort, terminal/active flags | Seeded system values; do not delete codes already referenced. |
| `signal_categories` | ID, unique code, display data, active/order | Deactivate rather than hard delete once used. |
| `municipalities` | ID, unique official code/name, active, boundary source/version/checksum, geometry reference | Boundary/geometry indexes depend on selected local spatial implementation. |
| `municipality_contacts` | ID, municipality FK, category FK nullable, recipient/type, email, priority, active, verification/source fields, timestamps/soft delete | Index municipality/category/active/priority; validated email; preserve historical snapshot in signal/email queue. |
| `email_queue` | bigint ID, signal FK, purpose, recipient/template/payload snapshots, status, idempotency key, attempt/lease/delivery/error data | Unique idempotency key; unique logical key `(signal_id,purpose,recipient_hash,version)`; index state+available_at, lease_until, signal+purpose. |
| `audit_log` | bigint ID, actor nullable, action/entity, status/severity, IP/user agent/request route/session, metadata, time | Index entity+time, actor+time, action/time. Never log raw token/content/EXIF unnecessarily. |
| `login_attempts` | ID, normalized email/IP hash/success/time | Index identifier/IP/time for admin auth throttle. |
| `settings` | ID, unique key, non-secret value, timestamps | No passwords/tokens/SMTP secrets. |

Use soft delete for users, categories, contacts and municipalities; use active/deactivation for status/reference values. Signals, photos, events, outbox and audit records should not be hard-deleted by normal administration. Retention periods for original photos, reporter email and logs are an explicit legal/privacy decision; implement deletion/anonymization through audited scheduled process only after policy approval.

## 12. Security requirements

* **Public endpoints:** anonymous CSRF protection, strict POST/content-type/size policy, IP and email rate limits, request correlation IDs, generic failure replies, no account/session assumption.
* **Admin:** Argon2id passwords, secure session cookie (`Secure`, `HttpOnly`, `SameSite`), session rotation on login, inactivity timeout, central RBAC + record authorization, login rate limiting and audit.
* **Tokens:** CSPRNG, at least 256 bits entropy, stored hashed/peppered, expiry, single-use atomic consume, HTTPS only, never logged/reflected unsafely.
* **SQL:** PDO native prepared statements everywhere; no dynamic SQL identifiers outside fixed allowlists.
* **XSS:** server escape all text/attributes; safe HTML email templates; category/contact/description data not interpolated into HTML without encoding; Content Security Policy for admin views.
* **Upload:** single file, body/file/pixel caps; `finfo` plus decode; EXIF parser defensive errors; MIME/extension allowlist; random storage keys outside public; SHA-256; no direct public directory listing; controlled download and `nosniff`.
* **Path traversal:** storage key from server only; canonicalized base-path containment; never concatenate raw filename to storage path.
* **Spam/duplicates:** throttling by IP/email, optional duplicate heuristic (same reporter email + photo hash + short time window) that warns/reuses existing pending signal rather than silently discarding; no CAPTCHA in baseline unless required by abuse telemetry.
* **Email:** SMTP credentials in protected runtime secrets, TLS, timeout, sanitized errors, header-injection prevention, outbox idempotency, no plaintext token in DB/logs.
* **Data protection:** access control to originals/GPS, minimal audit metadata, no publicly searchable reference-to-photo endpoint, privacy notice and retention policy before production.
* **Observability:** audit creation/status/contact edits and email outcomes; application log redaction for token/email/photo paths; alerts for failing queue/expired leases.

## 13. Minimal admin requirements

No public user dashboard is required. Authenticated admin/operator screens need only:

* signal list with filters by status, municipality, category, submitted/captured date and validation/dispatch state;
* signal detail: public reference, sanitized reporter email, one controlled photo view/download, EXIF GPS/captured time, municipality/contact snapshot, state/event history and email history;
* read-only audit view linked by signal/entity;
* municipality CRUD/activation and boundary dataset version visibility (geometry import is controlled operational workflow);
* municipality-contact CRUD, priority/type/category, active state and verification/source fields;
* category management and status visibility (system state codes/transitions not freely editable);
* controlled action to cancel an unsent signal or explicitly requeue a failed municipal dispatch, with required reason and audit.

There is no need in MVP for citizen accounts, messaging/comments, manual GPS editing, map browsing, analytics dashboards or arbitrary email composition.

## 14. MUST HAVE FOR MVP

* Mobile-responsive public form without account.
* Exactly one JPEG photo, validated by file, decoded image and required original EXIF GPS + `DateTimeOriginal`.
* Mandatory email and single-use/expiring validation link.
* Persisted signal before validation, with no municipal dispatch before validation.
* Local versioned municipality boundary resolver with ambiguity rejection.
* Active contact selection with reports → registry → official fallback.
* Transactional outbox, retry/backoff, idempotency and safe unknown-delivery handling.
* Municipal HTML+plain-text email with original photo attachment; final citizen confirmation after municipal send acceptance.
* Protected non-public original storage, event history, audit and minimal admin/RBAC.
* CSRF, request/login throttling, secure sessions for admin, PDO prepared queries, XSS/path/upload protections.

## 15. LATER / post-MVP

* PWA/service worker, offline photo queue and push notifications.
* AI image classification, OCR, automatic category prediction or duplicate-image similarity.
* Public map, public tracking portal, comments, ratings, social features.
* Citizen accounts, passwords, saved report history, profile/preferences.
* Browser geolocation/manual pin/location correction (explicitly excluded from MVP).
* Native mobile application.
* PDF report generation, digital signatures, electronic delivery integrations such as ССЕВ/СЕОС.
* External reverse geocoding/address enrichment and external GIS API fallback.
* Multi-photo/multi-media reports, video/audio.
* Municipality staff portal, bidirectional status synchronization and SLA reporting.

## 16. Open decisions

1. Exact authoritative municipality boundary dataset, licensing, update owner/frequency and local spatial implementation (MariaDB spatial capabilities versus imported preprocessed polygons).
2. Legal/privacy basis, retention durations, whether citizen email may be included to municipality, and deletion/anonymization process.
3. SMTP/provider selection, delivery API/idempotency/reconciliation guarantees, sender domain and operational worker/cron host.
4. Exact JPEG size/pixel limits after real-device testing; malware scanning availability.
5. Category set and whether category is optional or mandatory on first form.
6. Admin roles: whether `admin` and `operator` are sufficient; who can requeue/cancel/send test mail.
7. Boundary ambiguity tolerance and manual-review policy.
8. Validation expiry and public rate-limit values, to be tuned by usability/abuse evidence.
9. Whether `DRAFT` is actually persisted in MVP or only a client/UI state; the recommended simplification is client-only and first persisted state `PENDING_EMAIL_VALIDATION`.

## 17. Recommended implementation order

1. Establish new project skeleton, secure bootstrap/dispatcher, PDO configuration, error/audit foundations, and admin RBAC. Fix the inherited global-CSRF and session-cookie shortcomings rather than copying them.
2. Approve municipality boundary dataset and contact data model; build/import the local resolver test data before public dispatch work.
3. Define conceptual schema in a separate approved phase: core statuses/categories/signals/photo/events/contact/outbox/audit indexes and constraints.
4. Implement/test `PhotoInspectionService`: exactly-one JPEG, safe move/storage, decode, EXIF extraction and all required rejection cases.
5. Implement public create flow as one transaction: persist pending signal + original metadata + hashed token + validation outbox.
6. Implement SMTP transport and outbox worker with validation mail, lease/retry/idempotency and observability; test crash/recovery cases.
7. Implement token validation as atomic/idempotent action, then municipality resolution/contact snapshot and municipal dispatch outbox creation.
8. Implement municipal send outcome, final confirmation email and complete state/event/audit transitions.
9. Implement the minimal responsive admin screens and controlled photo access/requeue/cancel/contact management.
10. Security review and end-to-end tests: malformed EXIF, out-of-Bulgaria/border points, token replay/expiry/guessing, duplicate requests, oversized/spoofed image, worker crash before/after provider acceptance, RBAC/CSRF/access paths.

No source code, migrations or database schema were created or modified as part of this phase.
