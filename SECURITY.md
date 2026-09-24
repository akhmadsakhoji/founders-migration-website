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

Only the latest release receives security fixes. The project is in early development (0.x); do not use it on production sites yet.

## Scope

In scope: this plugin's code and the `.fmw` format handling, for example path traversal during extraction, unauthenticated access to backups or endpoints, SQL injection, object injection, privilege escalation, and weaknesses in backup encryption.

Out of scope: vulnerabilities in WordPress core, other plugins, or server configuration, unless FMW makes them exploitable.
