# Security Policy

## About this project

Civic Signals is free and open-source software.

The repository contains the application source code and documentation needed
to deploy an independent installation of the system.

Every installation is a separate deployment. The person or organization
that installs, configures, modifies or operates an instance is responsible
for the security and correct configuration of that installation.

The original author does not control or administer installations created
by third parties.

## Reporting a vulnerability

Please do not publish an exploitable security vulnerability in a public
issue before it has been reviewed.

Use the project contact published in `public/privacy.php` or the repository's
private security reporting mechanism, once configured.

When reporting a vulnerability, include:

- affected URL, file or component;
- reproducible steps;
- expected and actual behaviour;
- relevant logs or screenshots with secrets and personal data removed.

Do not include passwords, API keys, SMTP credentials, database credentials,
citizen data or other sensitive information in a public issue.

## Secrets and credentials

Never commit or publish:

- `.env`;
- database passwords;
- SMTP passwords or app passwords;
- `APP_KEY`;
- API keys or other application secrets;
- private certificates or private keys;
- citizen email addresses;
- uploaded citizen photos;
- production database dumps;
- production logs containing personal or sensitive information.

Every installation must create and use its own credentials.

The repository intentionally contains `.env.example` instead of a production
`.env` file.

The administrator of each installation is responsible for creating:

- a MariaDB database;
- a dedicated MariaDB application user;
- a database password;
- an SMTP account or SMTP credential;
- a unique random `APP_KEY`;
- any other credentials required by the local deployment.

## If a secret is exposed

If a production secret is accidentally committed or otherwise exposed:

1. revoke or rotate the affected credential immediately;
2. replace the credential in the production environment;
3. investigate whether the credential was used;
4. remove the secret from repository history where appropriate;
5. review logs and access records for possible misuse.

Removing a secret from the latest commit is not sufficient if the secret
has already been pushed to a public repository. Assume that an exposed
credential may already have been copied.

## Production security recommendations

For a public production installation:

- use HTTPS;
- keep PHP and MariaDB supported and patched;
- keep the application `.env` outside version control;
- keep uploaded originals outside the public web root;
- use a dedicated database account with only the required privileges;
- use a dedicated SMTP credential;
- configure SPF/DKIM/DMARC for a production sending domain where possible;
- use appropriate file and directory permissions;
- disable unnecessary PHP/web-server features;
- keep regular backups of the database and non-public uploaded data;
- test restoration of backups periodically;
- monitor application and web-server logs;
- protect administrative functionality with appropriate authentication and authorization;
- do not use production citizen data for development or testing.

## Uploaded files and citizen data

Uploaded photos may contain EXIF metadata, including GPS coordinates and
date/time information.

Installations must therefore protect uploaded files and prevent direct
public access to storage locations.

Do not copy real citizen submissions into development environments unless
there is a documented and lawful reason to do so and appropriate protection
is in place.

## Municipality and email configuration

Each deployment is responsible for its own municipality recipient configuration
and email infrastructure.

Before public operation, verify:

- that municipality recipient addresses are current;
- that automated messages are delivered correctly;
- that the SMTP sender is correctly configured;
- that SPF/DKIM/DMARC are configured where applicable;
- that failed email delivery is monitored.

The software does not guarantee that a third-party municipality will accept,
process or act upon a message.

## Updates

Keep the following components updated:

- operating system;
- Apache or the selected web server;
- PHP;
- MariaDB;
- TLS certificates;
- other software used by the deployment.

Security updates should be evaluated and applied promptly.

## License and responsibility

Civic Signals is distributed under the MIT License and is provided
"AS IS", without warranty of any kind.

Anyone who deploys or modifies the software is responsible for their own
installation, configuration, security, legal compliance, data processing,
email infrastructure and use of the system.

The original author does not control third-party deployments and cannot
guarantee their security, availability, configuration, legal compliance or
operation.