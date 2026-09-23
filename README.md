# Civic Signals / Граждански сигнали

Civic Signals is a lightweight PHP/MariaDB web platform for submitting a civic signal using **one original JPEG photo**.

The project was created to solve a simple practical problem: a person can see an illegal dump, polluted area, dirty field, roadside pull-off or another problem on a public place, take a photo, but then not know exactly where they are, which municipality is responsible, or where to send the signal.

The purpose of the platform is to remove that unnecessary work.

**photo → EXIF GPS → municipality → email confirmation → automatic dispatch**

## Current MVP

The current MVP is intentionally simple.

A citizen provides:

- one JPEG/JPG photo;
- an email address;
- email confirmation;
- acknowledgement that they have read how the system works and how data are processed.

The current public form does **not** have a free-text description field, user accounts or a registration process.

The original photo must contain:

- valid EXIF GPS latitude/longitude;
- EXIF `DateTimeOriginal`.

The system does not use browser/device Geolocation API or IP geolocation as a substitute for GPS from the photo.

After email confirmation, the system determines the municipality from the photo coordinates and sends the signal to the configured municipal recipient.

The platform is independent. It is not a municipal or state administration and does not decide whether a reported case constitutes a violation or what action an administration should take.

## Initial use case

The initial purpose is to make it easy to report visible problems on public places, including:

- illegal dumps;
- polluted areas;
- polluted fields and open land;
- dirty roadside pull-offs and stopping places;
- other visible irregularities that can be documented with a photograph.

The platform is deliberately limited in the MVP. The goal is not to create a complex citizen portal, but to make the basic path as short as possible:

> **See a problem → take a photo → submit it.**

## Requirements

- Apache or another PHP-capable web server
- PHP 8.x
- PHP extensions used by the application, including PDO MySQL, SQLite, Fileinfo and EXIF
- MariaDB 10.x
- SMTP account for outgoing email
- HTTPS in production

No Composer, Node.js, cron, Windows Task Scheduler or permanently running worker is required for the current production workflow.

## Installation

The repository is intended to allow the system to be rebuilt on a new server without access to the original production server.

### 1. Get the source

Clone or copy the repository and enter the application directory.

### 2. Create the database

Create a MariaDB database and a dedicated MariaDB user for the application.

For production, do not use the MariaDB root account from the application.

Configure the database name, username and password in `.env`.

### 3. Create the environment file

Copy:

```text
.env.example
```

to:

```text
.env
```

Then configure the values for the new installation.

At minimum this includes:

- application URL;
- a newly generated random `APP_KEY`;
- MariaDB host, database, username and password;
- SMTP host, port, username and password;
- SMTP sender address/name;
- privacy/platform contact settings, where applicable.

**The `.env` file is local configuration and must never be committed to Git.**

The repository does not contain production credentials. Every installation must create and configure its own credentials.

### 4. Configure the web server

Make:

```text
public/
```

the web root, or configure an Apache Alias/VirtualHost so that only the `public` directory is exposed to the web.

Do not expose:

```text
app/
database/
storage/
bin/
.env
```

directly through the web server.

### 5. Run the database migrations

```text
php bin/migrate.php
```

### 6. Seed reference data

```text
php bin/seed.php
```

### 7. Import municipality boundaries

```text
php bin/import-municipality-boundaries.php
```

### 8. Run diagnostics

```text
php bin/diagnose.php
```

### 9. Configure HTTPS and SMTP

Before public use:

- configure HTTPS;
- configure the SMTP sender;
- verify that outgoing email is delivered correctly;
- verify the production upload limits;
- verify that uploaded originals are not publicly accessible;
- verify the municipality recipient configuration.

For a fresh local installation, `DB_CREATE_IF_MISSING=true` can be used if supported by the installation workflow.

For production, it should remain `false` and the database should be created and configured explicitly.

## Geography data

The application uses the municipality boundary resource:

```text
SU_BG_NSI_LAU_2024_1
```

Version:

```text
v2024.1
```

Reference date:

```text
2024-12-31
```

Number of municipalities:

```text
265
```

Coordinate reference system:

```text
EPSG:9391 / BGS2005/UTM zone 35N
```

Municipality identifier:

```text
EKATTE
```

**Source: National Statistical Institute (NSI), Bulgaria.**

The source data are freely available for use provided that the source, NSI, is cited.

The repository contains the source GeoPackage and its description/metadata so that a fresh installation can reproduce the municipality import.

Relevant files include:

```text
data/geography/SU_BG_NSI_LAU_2024_1.gpkg
data/geography/SU_BG_NSI_LAU_2024_Description.txt
database/geography/SU_BG_NSI_LAU_2024_1.metadata.json
```

More information about the source and its terms of use is available from the National Statistical Institute:

- https://www.nsi.bg/nrnm/pages/za-nrnm
- https://nsi.bg/pages/licenz-za-izpolzvaneto-na-statisticheskata-informaciya-proizvejdana-i-razprostranyavana-ot-nacionalniya-statisticheski-institut-485

## Email processing

The application uses a transactional email queue with leasing and retry handling.

The current deployment does not require a permanent worker or scheduler. Pending validation and municipality-dispatch messages are given an opportunity to be processed by normal public requests, while queue processing remains centralized in `EmailQueueService`.

For production, SMTP delivery should preferably use a domain-controlled sender with appropriate SPF/DKIM/DMARC configuration.

## Storage and privacy

Uploaded original photos are stored outside the public web root. Runtime uploads, logs and cache files are ignored by Git.

Never commit:

- `.env`;
- SMTP passwords or app passwords;
- database passwords;
- application secrets;
- real citizen photos;
- production logs;
- private database dumps;
- other production data.

The public information pages are:

```text
public/privacy.php
public/how-it-works.php
```

The privacy page describes the current MVP data flow. If the system is extended with additional fields or functionality, the public information should be updated accordingly.

## Security

The project includes measures such as:

- CSRF protection;
- prepared SQL statements;
- upload and EXIF validation;
- email validation;
- rate limiting;
- hashed validation tokens;
- audit/event records;
- controlled file storage;
- role/permission controls where applicable.

Before a public production deployment:

1. review `SECURITY.md`;
2. create new credentials for the installation;
3. generate a unique `APP_KEY`;
4. verify the production configuration independently of development;
5. verify file permissions and web-server configuration;
6. verify backups and recovery procedures.

## Repository and reproducibility

The purpose of publishing the source is also to make the project recoverable if the original installation is ever discontinued.

A future maintainer should be able to:

1. obtain the source from the repository;
2. create a new MariaDB database and application user;
3. create a new `.env`;
4. create their own application and SMTP credentials;
5. run the migrations and seed;
6. import the municipality geography;
7. configure Apache/PHP and HTTPS;
8. configure municipality recipients;
9. bring the system online under a new domain.

The production server, domain, database, SMTP account and credentials are not dependencies of the source code.

The repository does not contain production citizen data.

## Repository contents

The repository contains:

- application source code;
- database migrations;
- reference seed data;
- municipality geography and import tooling;
- installation/deployment documentation;
- security documentation;
- development documentation.

It does not contain:

- production credentials;
- production uploads;
- production logs;
- private database dumps;
- the production `.env`.

## Development documentation

Additional documentation is included in the repository, including:

```text
PHASE_1_ANALYSIS.md
PHASE_2_SPECIFICATION.md
PHASE_3_IMPLEMENTATION.md
PHASE_4A_IMPLEMENTATION.md
CHANGELOG.md
SECURITY.md
```

These documents describe the development history, architecture decisions and implemented phases.

## License

Civic Signals is released under the **MIT License**.

The software may be freely used, copied, modified, published, distributed, sublicensed and used for any purpose, subject to the terms of the MIT License.

The software is provided **"AS IS"**, without warranty of any kind.

Anyone who installs, modifies, deploys or operates their own instance is responsible for their own installation, configuration, security, legal compliance, data processing, recipients and use of the software.

The original author does not provide a guarantee that another person's installation will be secure, legally compliant, suitable for a particular purpose or operated correctly.

See the `LICENSE` file for the complete license text.
