# Implementation Roles Used for MailDesk

1. **Product Architect / Product Manager** — scope, priorities, workflows, provider behavior, failure states, roadmap and acceptance criteria.
2. **WordPress Plugin Architect** — hooks, capabilities, activation/migrations, REST integration, Site Health, privacy and plugin lifecycle.
3. **Email Protocol Engineer** — IMAP, SMTP, MIME, mailbox flags, UID/UIDVALIDITY semantics, retries and provider quirks.
4. **Backend PHP Engineer** — domain/application services, repositories, transport abstractions, validation and error handling.
5. **Database Architect** — custom schema, indexes, mailbox-local identities, message/folder relations, outbox and audit records.
6. **Security Engineer / AppSec Reviewer** — XSS, CSRF/REST authorization, IDOR, SSRF, TLS, secrets encryption, credential leakage, abuse/rate limiting and secure rendering.
7. **OAuth / Identity Engineer** — Google/Microsoft OAuth2/XOAUTH2 strategy, token lifecycle and account authorization boundaries.
8. **Queue / Reliability Engineer** — durable outbox, retries/backoff, cron/worker strategy, idempotency and failure recovery.
9. **Frontend Engineer** — responsive admin application, API state handling, mailbox navigation, compose/read workflows and error UX.
10. **UI/UX Product Designer** — three-pane information architecture, visual hierarchy, empty/loading/error states, account onboarding and responsive behavior.
11. **Accessibility Specialist** — keyboard interaction, semantic controls, focus states, contrast and assistive-technology compatibility.
12. **Privacy Engineer** — retention, cache deletion, privacy policy integration, account removal and uninstall behavior.
13. **QA / Test Engineer** — syntax, protocol edge cases, account isolation, send/sync scenarios, retries and regression review.
14. **Performance Engineer** — metadata-first sync, bounded fetches, indexing, local caching and avoidance of long web requests.
15. **DevOps / Site Reliability Engineer** — production cron/worker guidance, TLS/runtime prerequisites, diagnostics and operational observability.
16. **Documentation / Release Engineer** — README, deployment notes, secure-key setup, version/package integrity and release artifacts.

## Highest-risk roles

Security Engineer, Email Protocol Engineer, Database Architect, Queue/Reliability Engineer and UI/UX Designer are treated as first-class roles because failures in these areas can cause credential compromise, mail duplication/loss, cross-account exposure, synchronization corruption or an unusable client.
