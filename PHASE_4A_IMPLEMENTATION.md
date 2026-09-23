# Фаза 4A — Public Photo Upload + EXIF Validation

## Реализирано

`public/index.php` е минималната публична страница „Подай сигнал“. Тя приема само една JPEG/JPG снимка и email. Полето няма `multiple`; изборът на нов файл заменя текущия. След избор JavaScript показва preview, filename/size и изпраща файла към `public/validate-photo.php` за server-side preflight. Бутонът е disabled, докато server-side photo/EXIF check и browser email syntax check не са успешни.

Публичният submit endpoint е `public/submit.php`. Той не се доверява на preview-а: отново извършва всички проверки и създава сигнал само след успех. Публичните endpoint-и започват anonymous session само за CSRF; няма user account, login или browser/device geolocation.

## Photo и EXIF validation

`app/Services/PhotoInspector.php` допуска единствено JPEG/JPG с максимум 10 MiB и 24 MP. Проверява extension, JPEG signature (`FF D8 FF`), `finfo` MIME, `getimagesize`/JPEG decoder, dimensions и pixel count. PDF, SVG, PHP, HTML, ZIP и преименувани не-JPEG файлове се отхвърлят.

От неизменения оригинален JPEG се изискват `GPSLatitude`, `GPSLongitude`, `GPSLatitudeRef`, `GPSLongitudeRef` и `DateTimeOriginal`. DMS rational values се преобразуват в decimal coordinates; N/S/E/W sign, latitude/longitude ranges, malformed/zero rational values и future capture time се валидират. `DateTimeOriginal` се съхранява като local `captured_at_local`; не се подменя със server time. `submitted_at` се поставя отделно от MariaDB при действителното подаване. `gps_source` е фиксиран на `exif_original`.

## Persistence и сигурност

След валидация файлът първо се мести в non-public `storage/uploads/tmp`, после в random server-generated key под `storage/uploads/originals/`. Оригиналното име никога не е storage path; display name се sanitize-ва. SHA-256, MIME, bytes, dimensions и EXIF datetime се записват в `signal_photos`.

`PublicSignalService` използва transaction за:

1. създаване на `signals` със статус `PENDING_EMAIL_VALIDATION` — няма persisted `DRAFT`;
2. запис на точно една `signal_photos` row;
3. запис на `signal_email_validations` с HMAC-SHA-256 hash на CSPRNG token и `expires_at = NOW() + 24 hours`;
4. `signal.created` event.

Raw validation token не се пази в база, log или HTTP response и в тази фаза не се изпраща email. Това е само подготвеното validation state за следващата фаза.

Email syntax се валидира client- и server-side; server code отхвърля CR/LF за защита от header injection. Public form има honeypot. Migration `002_public_submission_rate_limit.sql` добавя `public_submission_attempts`; endpoint-ът прилага максимум 3 опита/email/час и 10/IP/час, като пази email SHA-256 hash. Basic duplicate detection отказва еднакъв photo hash за същия email през последните 24 часа.

## Променени/добавени файлове

* `app/Services/{PhotoInspector,PublicSignalService,PublicSubmissionRateLimiter}.php`
* `app/Validation/EmailValidator.php`
* `public/{bootstrap,index,validate-photo,submit}.php`
* `database/migrations/002_public_submission_rate_limit.sql`
* `tests/phase4a.php`
* `bin/diagnose.php` — включва anti-abuse таблицата в required schema check.

## Тестове

Изпълнени върху изолирана база `civic_signals_phase4a_test` след migration/seed:

```text
PASS valid JPEG with EXIF
PASS JPEG without GPS
PASS JPEG without DateTimeOriginal
PASS renamed invalid JPEG
PASS invalid email
PASS successful signal creation
PASS honeypot policy
PASS rate limit
Result: 8 passed, 0 failed
```

Тестът генерира временни JPEG fixtures с реално parseваем EXIF и изтрива test signal/photo след successful creation assertion. Всички нови PHP файлове минават `php -l`. Migration 002 е приложена и Phase 3 diagnostic остава успешен.

## Не е имплементирано

В тази фаза няма municipality resolver/point-in-polygon, email delivery/worker, email validation endpoint, email към община или гражданин, CAPTCHA, PWA, admin UI, AI или public map. Старият reference проект `project/` не е променян.
