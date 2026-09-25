# Security policy

A backup plugin holds a complete copy of a website, including its database and credentials. We treat security reports as the highest priority.

## Reporting a vulnerability

**Do not open a public issue.** Report privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**.

Please include:

- the affected version or commit,
- the steps to reproduce, or a proof of concept,
- the impact as you understand it.

## What to expect

| Step | Target |
|---|---|
| First response | within 72 hours |
| Assessment and severity | within 7 days |
| Fix for critical issues | as fast as possible, usually within 14 days |

We will keep you informed, credit you in the release notes unless you prefer otherwise, and coordinate the disclosure date with you.

## Supported versions

Only the latest release receives security fixes. Please update before reporting, and check whether the issue is still present on `main`.

## Scope

In scope: this plugin's code, for example:

- reading `.fmw` and `.wpress` backups: path traversal during extraction, crafted SQL, object injection, resource exhaustion;
- access to backups, jobs and the REST routes (`fmw/v1`), including the key-authenticated pull routes and job tokens;
- pull keys, the Google OAuth callback, and cloud storage credentials and passwords stored sealed on the server;
- SQL injection, privilege escalation, and weaknesses in backup encryption.

FMW Tools has its own policy in its repository.

Out of scope: vulnerabilities in WordPress core, other plugins, or server configuration, unless FMW makes them exploitable.
