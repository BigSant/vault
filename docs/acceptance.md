# Vault acceptance review

2026-11-10. Tested Vault `2992078` and ai-os `9523ca6a`.

## Independent Codex reviews

| Area | Initial | Reassessment | Final |
|---|---:|---:|---:|
| Security | 6.8 | 8.2 | **8.7/10** |
| Functional fit | 8.2 | 8.7 | **9.0/10** |
| Code quality | 7.3 | 8.6 | **8.7/10** |

Three independent agents reviewed the final changes with fresh context. The final security and quality reviews covered `3cb568a...2992078` and related code, building on the earlier review of the whole system. All three areas met the requested 8.5 threshold. Reviewers found no new P1/P2 blockers.

Fixes covered a race between recovery and MFA sessions, old grants reactivating after a new grant, late overwrites of terminal states, broker concurrency limits, policy checks at every browser redirect, backup failures on short writes, full disks, flush and fsync, and non-network download, srcdoc and popup attempts. The entry page gained grants, history, operation approvals and MFA.

## Test evidence

- PHPUnit passed **18 tests with 132 assertions**. Python broker, egress and CLI suites passed **10 tests**.
- Encrypted backup tests passed for round trips, wrong keys, modified and truncated files, short writes, and flush and fsync failures.
- Tests using HTTPS, CSRF, TOTP, OpenBao and the broker exercised API, CLI, browser with MFA, and dynamic DB actions. All safe-result checks passed.
- Negative browser cases covered external origins, disallowed same-origin POST, redirect chains, iframes, popups, HTTP attachments, blob downloads, srcdoc and about:blank. Blocked network paths never reached the target server. Violations never reported success.
- The ai-os PHP client → HTTPS MCP → broker test passed. A separate AgentQueue / AgentTools / journal test passed in an isolated MySQL environment. All **26 native transport tests** passed.
- Owner recovery revoked pending MFA and existing authenticated sessions. The new password allowed fresh MFA enrollment.
- Encrypted backup, isolated verification and full restore into an empty environment passed. Restore recovered 36 entry versions and MFA, revoked restored machine access and refused to overwrite a populated environment.
- Local containers were healthy. `https://vault.local/health.php` returned HTTP 200 and `ready`.

The README and `docs/operations.md` give the commands and restore procedure. Local run logs were `/tmp/vault-phpunit-final.log`, `/tmp/vault-python-final.log`, `/tmp/vault-aios-integration.log`, `/tmp/vault-crypto-final.log`, `/tmp/vault-recovery-final.log`, `/tmp/aios-native.log` and `/tmp/aios-guarded-integration.log`. The repositories contain the repeatable tests. The temporary logs are kept outside the repositories.

## Limitations and non-blocking observations

API/CLI offer fixed Bearer GET/status recipes, the DB action runs a short-lived SELECT 1, and the browser runs a registered login/MFA/status recipe. New systems and operations require an operator-configured connector. General browsing, CAPTCHA/WebAuthn, arbitrary shell and automatic third-party password changes are unsupported. No real accounts were configured. The ai-os integration is opt-in. The full production native agent workflow was not tested end to end.

The security boundary assumes a trusted backend and host with a separate agent sandbox. A host, root or Docker administrator can access secrets. Agent status requests synchronize state in the web app. The operations table identifies requests by operation UUID. A separate column for the requesting agent's name would help. Blade formatting and broker operation terminology could be easier to maintain. A deterministic test for repeated cancellation during Chromium teardown remains a regression test candidate. The review found no confirmed vulnerability there.
