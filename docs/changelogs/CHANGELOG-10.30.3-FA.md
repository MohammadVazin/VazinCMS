# VazinCMS 10.30.3 — QA candidate (not published)

- Source: cryptographically verified 10.30.2 release plus 2026-10-13 approved Authfix overlay for exactly four src PHP files.
- OAuth: renew session on entry, handle invalid state and IdP downtime; defensive configuration and bootstrap parsing.
- Authentication policy unchanged: existing owner emergency password login and Vazin ID sign-in retained; normal username/password login for all approved users remains a Phase 2 product requirement. No forced Vazin ID-only policy.
- No database schema migrations in this patch.
- Rollback must preserve mutable runtime and follow existing release contracts. Not for customer deployment before independent restore and acceptance.
- Remove redundant global PDO import from CLI migration script to prevent PHP warning preceding secure session initialization.
