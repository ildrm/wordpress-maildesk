# MailDesk for WordPress

MailDesk is a secure, multi-account email client inside WordPress Admin. It provides an Outlook/Gmail-style mailbox UI, IMAP synchronization, SMTP delivery, drafts, contacts, signatures, templates, filtering-rule storage, diagnostics, shared-account data structures, encryption of credentials, and an extensible provider/transport architecture.

## Implemented in 1.0.0

- Dedicated custom tables for accounts, shared account ACLs, folders, messages, message-folder UID mappings, threads, attachments, drafts, outbox, contacts, signatures, templates, rules, queue jobs and activity logs.
- Modern WordPress Admin SPA-style interface with mailbox navigation, three-pane reading layout, responsive design, compose modal, contacts, account settings and diagnostics.
- Pure-PHP socket IMAP client: TLS validation, authentication, folder discovery, UID-based recent synchronization and basic flag ingestion. Does not require PHP `ext/imap`.
- SMTP delivery using WordPress-bundled PHPMailer, durable outbox, retry/backoff and stable Message-ID generation.
- Account credentials encrypted at rest using Sodium secretbox or AES-256-GCM.
- REST API with WordPress capability checks and account ownership checks.
- Sandboxed message rendering and server-side HTML sanitization; remote HTTP(S) images are stripped/blocked from auto-loading.
- Multi-account foundation and shared-account ACL schema.
- Contacts, signatures, templates and rules persistence APIs.
- WP-Cron queue fallback, Site Health checks and diagnostics.
- Privacy policy helper and opt-in uninstall cleanup.

## Important production notes

### Encryption key
For stronger separation between database data and encryption material, define a private key in `wp-config.php`:

```php
define( 'WPMD_ENCRYPTION_KEY', 'a-long-random-secret-kept-outside-the-database' );
```

If omitted, the plugin derives key material from WordPress salts.

### Reliable background processing
WP-Cron is supported as a fallback. For production sites, configure a real system cron to invoke WordPress cron regularly.

### OAuth
The account model and IMAP transport support XOAUTH2 tokens, but this standalone package does not ship a centralized Google/Microsoft OAuth broker or provider app credentials. OAuth access/refresh token acquisition should be added through a provider-specific adapter or hosted OAuth service. Generic/password and provider app-password workflows are usable now.

### Mail protocol scope
The bundled IMAP implementation intentionally focuses on secure mailbox discovery and recent UID synchronization. Advanced protocol optimizations such as QRESYNC/CONDSTORE, full MIME part/attachment retrieval, IDLE workers and provider-native Gmail/Microsoft API semantics are extension points for a larger production deployment.

## Security

- WordPress capabilities are used for mail access, compose, send, deletion, account management, shared accounts, contacts, rules, templates, settings, diagnostics and logs.
- Secrets are never returned by REST endpoints.
- Incoming HTML is sanitized and rendered inside a sandboxed iframe.
- SMTP/IMAP TLS certificate verification is enabled by default.
- Attachments are represented by protected metadata structures; production attachment download handlers should enforce account authorization and stream files through authenticated endpoints.

## License

GPL-2.0-or-later.
