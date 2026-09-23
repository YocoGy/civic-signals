# Changelog

## 1.0.0 - Initial public release candidate

- Public one-photo civic signal submission flow.
- JPEG/JPG validation with EXIF GPS and `DateTimeOriginal` requirements.
- Municipality detection from WGS84 coordinates against the NSI municipality dataset.
- Email confirmation flow with expiring hashed tokens.
- Automatic municipality email dispatch through a transactional queue.
- Queue leasing, retry and failure handling.
- Public submission rate limiting and basic duplicate protection.
- Android help for image-picker paths that remove EXIF GPS metadata.
- Public `how-it-works.php` and `privacy.php` pages.
- Reproducible migrations, seed and municipality-boundary import.
