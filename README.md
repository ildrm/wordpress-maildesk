# MailDesk for WordPress

MailDesk provides a multi-account IMAP/SMTP email client in WordPress Admin. Version 1.1.0 repairs the original mail parsing, authorization, queue, persistence and interface defects and connects the previously incomplete workflows.

## Features

- Account creation, editing, connection tests, removal and explicit shared access.
- Selectable folder discovery, recent-message synchronization, search, pagination and an all-accounts view.
- Plain text and HTML message reading, decoded MIME headers/bodies, protected attachment downloads and responsive desktop/mobile navigation.
- Read/unread and starred flags written to IMAP before the local cache changes.
- Move messages between folders, including Trash, using UID MOVE on supporting servers. No blanket EXPUNGE or permanent deletion operation is used.
- Plain-text composition, reply with Reply-To/thread headers, forwarding, Cc/Bcc, attachments and scheduling in the user's local timezone.
- Saved drafts that can be reopened and updated, with version checks to prevent conflicting saves and sends.
- Durable Outbox status, cancellation of queued deliveries, duplicate-request detection, retry/backoff and explicit uncertain-delivery handling.
- Contacts, signatures, templates and rules with create/edit/delete interfaces. Templates and signatures can be inserted into the composer.
- Rules match subject/from text and set read/star state on newly cached messages in accounts the rule owner owns. All conditions must match; lower priorities run first and later matching actions override earlier ones.
- WordPress personal-data export/erasure, local account cleanup, Site Health, diagnostics and opt-in uninstall cleanup.

## Installation and requirements

1. Install the `wp-maildesk` directory or release ZIP through WordPress Plugins and activate it.
2. Open **MailDesk → Accounts**, add the account's IMAP and SMTP settings, and choose TLS on connect or STARTTLS as required by the provider.
3. Test both connections, then choose Sync. Synchronization and delivery run in the background; refresh the mailbox or Outbox after the worker runs.

Requires WordPress 6.6+, PHP 8.1+, OpenSSL for TLS, and Sodium or OpenSSL AES-256-GCM for credential encryption. Plugin tables use InnoDB for transactions. IMAP does not require `ext/imap`. Iconv is used when available for legacy MIME charsets; mbstring enables decoded display names for modified UTF-7 mailboxes. Keep both extensions enabled for international mail.

Activation and upgrades create/update the schema. Legacy nontransactional plugin tables are converted to InnoDB, and legacy folder indexes are replaced without discarding existing rows. Back up the database before a production upgrade. Network activation initializes existing multisite sites and initialization hooks handle new sites.

## Credentials and access

Define a private, stable encryption key outside the database in `wp-config.php`:

```php
define( 'WPMD_ENCRYPTION_KEY', 'a-long-random-secret-kept-outside-the-database' );
```

Without this constant, the key is derived from WordPress salts. Preserve the key/salts in backups: changing them makes existing credentials unreadable, and those accounts need to be reconnected. Credentials are authenticated-encrypted and never included in REST responses or personal-data exports.

Administrators receive MailDesk capabilities on activation. Other WordPress roles/users require explicit capabilities. Each REST operation also requires `wpmd_access_mail`. Shared-account grants do not grant WordPress capabilities: both are required. Shared permissions are `read`, `write` (flags), `compose`, `send`, and `delete` (move). A shared grant must include `read`; a read-only grant cannot send, edit credentials, save drafts or change mail state. Existing ACL data without explicit recognized permissions fails closed and should be re-granted through Accounts → Shared access.

Hostnames/IP addresses are validated and resolved before every connection, and the connection is pinned to the checked address while TLS verifies the configured hostname. Private/reserved destinations and plaintext transports are disabled by default. Deliberate internal-server installations can use the `wpmd_allow_private_mail_hosts` and `wpmd_allow_insecure_mail_transport` filters; scope them to the intended account/host.

Incoming HTML is sanitized and displayed in a sandboxed iframe with a restrictive content security policy. Images and external styling are not loaded automatically, including inline images; available MIME attachments can be downloaded separately. Attachment bytes are kept in the database and served only to authorized users, never through public upload paths. Attachments are not malware-scanned.

## Background work and delivery semantics

Configure a real system cron to invoke WordPress cron regularly, for example every minute. WP-Cron remains the fallback on sites with visitor traffic. The main event is `wpmd_queue_tick`; `wpmd_queue_continue` drains pending work. Diagnostics and Site Health distinguish scheduled work from a recent worker run.

Synchronization uses separate discovery/folder jobs, account locks, UIDVALIDITY validation and bounded fetches. Messages keep their mailbox-local UID identity; sender-controlled Message-ID values do not merge unrelated messages. Removed folders, old UID generations and messages outside the current cache window are removed locally after successful reconciliation.

Outbox rows are claimed atomically. API clients should send a UUID `request_id` to `/wpmd/v1/send` and reuse it only when retrying the same request. Changed content under an existing request ID is rejected. The browser supplies this ID automatically. Sending permission is checked again when the worker processes the row.

Failures before SMTP submission retry up to five attempts with exponential backoff starting at 60 seconds. A disconnect or partial-recipient failure during submission is marked **uncertain** because the server may already have accepted mail. Abandoned in-flight rows also become uncertain rather than being resent automatically. Check with the provider/recipient before manually sending again. **Sent** means SMTP acceptance, not guaranteed inbox delivery. SMTP cannot provide an exactly-once delivery guarantee.

The plugin does not append a separate copy into the provider's Sent folder; providers that do not save submitted mail themselves will only have the local Outbox record. Delivery attempts and cached mailbox messages are different records.

## Scope and resource limits

- Each folder caches the latest 250 UIDs within the selected history window (1–3650 days). This is a recent-mail cache, not a full mailbox backup.
- Messages up to 10 MiB are decoded completely. Larger messages show headers and an explicit notice to open the full message in the provider client. Malformed MIME or excessive recursive parsing complexity also produces a notice without blocking the mailbox.
- Outgoing bodies are limited to 1 MiB; attachments to 20 files and 10 MiB total; recipients to 100 per message. The default sending rate is 100 queued messages per user/account in a rolling hour and is filterable with `wpmd_send_rate_limit_per_hour`.
- Personal collection views return up to 1,000 items; Outbox shows the latest 100 records. Message lists paginate in batches of 50.
- Activity logs, completed/failed job history and sent/cancelled Outbox records expire after 30 days. Failed/uncertain delivery records remain for investigation until local account removal or personal-data erasure.
- The composer edits plain text. Existing HTML messages and HTML supplied through the API are supported; the UI is not a rich HTML designer.
- OAuth IMAP and SMTP accept access tokens through the `wpmd_oauth_access_token` filter. Provider app registration, consent, token acquisition/refresh and revocation belong to a provider adapter and are not bundled. Password/app-password accounts work without an OAuth adapter.
- QRESYNC/CONDSTORE, IDLE, provider-native Gmail/Microsoft APIs, server-side draft synchronization, conversation/thread views, contact-provider synchronization and permanent deletion are not implemented. Reserved schema structures are not advertised as working UI features.

## Privacy and removal

Removing an account deletes its local cache, attachments, drafts, Outbox records, associated writing tools, shares, jobs and logs. It does not delete the remote mailbox. Removal is blocked while the account is actively in use.

WordPress personal-data export includes a user's owned account metadata, cached message text and personal collections, excluding credentials and attachment file bytes. It is not a mailbox backup/export format. Erasure removes owned local accounts and personal records and revokes shared access; busy data is reported as retained so erasure can be retried.

Uninstall preserves stored data by default. To opt into table deletion, set the site option before uninstalling:

```bash
wp option update wpmd_delete_data_on_uninstall 1
```

On multisite, opt-in is per site. Deactivation/uninstall clears both queue hooks; uninstall removes installed role capabilities.

## Review and verification

The 16 review roles and their responsibilities are listed in [ROLES.md](ROLES.md). Reproducible regression commands and test-environment requirements are in [tests/README.md](tests/README.md). Tests use synthetic localhost mail servers and a disposable database; they never relay real email.

Live Gmail/Microsoft/provider behavior, actual production TLS certificates, hosting cron reliability and production multisite operation must still be checked in the deployment environment. Passing local tests is not a claim that every provider or deployment works perfectly.

GPL-2.0-or-later.
