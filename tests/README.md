# MailDesk regression tests

Run these only against a disposable local WordPress installation with MailDesk activated, a real MySQL database, PHP CLI and `WP_ENVIRONMENT_TYPE` set to `local`. The fixtures require Python 3 and the concurrency suite requires PHP `proc_open`. The tests create synthetic users/accounts and modify test data; lifecycle tests temporarily change role capabilities and cron options before restoring activation.

The installation must contain an administrator named `review`. It must be a dedicated test site, not a production site with `WP_ENVIRONMENT_TYPE` relabeled.

Start the localhost-only protocol fixtures in a separate terminal:

```bash
python3 tests/fixtures/mail-server.py
```

The servers listen only on `127.0.0.1:11430` (IMAP) and `127.0.0.1:11025` (SMTP). They do not relay messages. Synthetic messages and delivery captures are written to `/private/tmp/wpmd-mail-fixtures`; override `WPMD_FIXTURE_DIR` only if you also adjust the fixture paths in the test scripts.

Add these filters only to the disposable test site's mu-plugin:

```php
add_filter( 'wpmd_allow_private_mail_hosts', static fn( $allow, $host ) => $host === '127.0.0.1', 10, 2 );
add_filter( 'wpmd_allow_insecure_mail_transport', static fn( $allow, $a ) =>
    ( $a['imap_host'] ?? '' ) === '127.0.0.1' && ( $a['smtp_host'] ?? '' ) === '127.0.0.1', 10, 2 );
```

Run the suites in this order, substituting the installation path:

```bash
WPMD_TEST_WP=/path/to/disposable-wordpress php tests/lifecycle.php
WPMD_TEST_WP=/path/to/disposable-wordpress php tests/integration.php
WPMD_TEST_WP=/path/to/disposable-wordpress php tests/protocol.php
WPMD_TEST_WP=/path/to/disposable-wordpress php tests/concurrency.php
WPMD_TEST_WP=/path/to/disposable-wordpress php tests/privacy-rules.php
```

The integration suite writes `/private/tmp/wpmd-test-account.json` for subsequent tests. Run the concurrency and privacy suites after integration. The fixture has an explicit reset command used only by the integration suite, so reruns start with the same synthetic mailbox state.

Latest review result: **121 passing assertions** (90 integration, 14 protocol, 10 lifecycle, 2 concurrency and 5 privacy/rules), plus desktop/mobile browser workflows and PHP 8.3/8.5 syntax validation.

Coverage:

- Encryption, malformed credentials, host/transport policy and HTML tracking protection.
- MIME multipart, transfer encodings, character sets, headers, attachments and size/depth limits.
- Real IMAP/SMTP exchanges, including UID after a literal, OAuth challenges, remote flags, UID MOVE, partial recipients and disconnection after DATA.
- REST capabilities, account/attachment isolation, shared ACLs, draft version conflicts, validation and idempotency conflicts.
- Eight competing PHP processes claiming the same delivery, retry exhaustion and stale in-flight recovery.
- UIDVALIDITY changes, expunge/cache reconciliation, all selectable folders, rules and privacy cleanup.
- Legacy folder index migration, InnoDB conversion, repeat activation, cron cleanup and both uninstall policies.

Syntax checks:

```bash
php -l wp-maildesk.php
node --check assets/js/admin.js
# Also lint every PHP file under src/ and tests/.
```

Browser verification should cover desktop and mobile WordPress Admin: open mail, change flags without losing the body, switch folders/accounts, save/reopen/update drafts, create/edit contacts/accounts, insert signatures/templates, configure rules, upload attachments, queue/cancel delivery, verify REST nonce enforcement and inspect the browser console. Screenshots and temporary browser scripts belong outside the plugin directory.

The review used a disposable MySQL 8.4 container, PHP 8.5, WordPress 7.1 and Chromium through Playwright at 1440×1000 and 390×844. Local fixture tests do not validate real provider certificates, OAuth consent/refresh services, production cron, delivery to real inboxes, production multisite or every supported WordPress/PHP combination.
