# Vault operations

## Environments and data

`VAULT_ENV=local|test|stage|prod` selects identities and containers. Local data lives in `data/`, test data in `app/.generated/test/data/`. Stage/prod operator commands expect `data/<env>/`. The server's DDE `PROJECT_DATA_DIRECTORY` must match this path. `bin/vault install` sets up local/test automatically. For stage/prod, prepare a private DDE environment for the server, then run `VAULT_ENV=prod bin/vault prepare`, `make -C app build ENV=prod`, `make -C app up ENV=prod`, Composer install in the container, `VAULT_ENV=prod bin/vault initialize` and `php artisan migrate --force` in the PHP container. Set the document root to `/var/www/html/public`, below the Laravel project root.

Keep private environment files, runtime data, recovery keys and backups out of version control. `prepare` creates writable Laravel directories. Containers use the host UID/GID. The user running the container must be able to read files with mode 0600. External endpoints require a valid HTTPS certificate. Services communicate over a private Docker network. OpenBao and the broker have no published ports. OpenBao may be sealed after a restart. `bin/vault initialize` unseals it with the original shares without printing them.

OpenBao AppRole tokens have a 5-minute TTL. The web app reads and writes entries and TOTP data. The broker can only read entries and managed DB roles. The root token and unseal shares are stored in `~/.config/vault/<env>/openbao.json`, **outside container mounts**. In production, keep this material in a secure operator channel or offline backup. Anyone who holds it can take control of Vault. Automated maintenance requires access from the operator host. Give the agent full administrative rights only on a separate host.

On existing installations, the OpenBao KV v2 limit `max_versions=1000` takes effect after updating `secret/config`. For local/test, rerun `bin/vault install` with the selected `VAULT_ENV`. For stage/prod, run `bin/vault initialize` with the appropriate `VAULT_ENV`. OpenBao backups grow as entry versions accumulate.

**One-time links (Onetime Secret).** The application's OpenBao policy also covers `secret/data/links/*`,
`secret/metadata/links/*` (read, delete) and `secret/data/settings/*`. On an existing installation, rerun
`bin/vault install` (local/test) or `bin/vault initialize` (stage/prod) once, so the policy is rewritten, then run
the migrations. The PHP container calls `https://REGION.onetimesecret.com` directly and needs outbound HTTPS to that
one host. `bin/vault links` (artisan `vault:links`; install `ops/vault-links.timer`, every 10 minutes) ends links
whose lifetime is over (asking the receipt first, so a link read before its end is recorded as read), checks the open
ones and records `link.read` / `link.previewed` in the activity log as they happen; `vault:maintenance` also retries
destroying what OpenBao still holds for ended links. Removing the account (Setup › Onetime Secret) deletes the token
with all its versions; a new token replaces the old one the same way.

## Backup and full restore

```bash
VAULT_ENV=local bin/vault backup
VAULT_ENV=local bin/vault restore-verify backups/<backup>.vault
```

Backup refuses to start while operations are active. It enables maintenance mode, stops PHP and the broker, rechecks for active operations, then collects SQL, an OpenBao Raft snapshot, the application key, AppRole and connector configuration. Sodium secretstream authenticates the entire stream, including its end. Backup checks every write, flush and fsync, and publishes the final filename atomically only on success. Regression tests cover full disks, short writes and flush failures. PHP and the broker restart after the attempt. This causes a short planned web outage. If the command fails, check service status and `artisan up`.

Store **both** files separately from the `.vault` backup:

- `~/.config/vault/<env>/backup.key` — backup encryption key
- `~/.config/vault/<env>/openbao.json` — original OpenBao recovery shares

Restore requires both files. `restore-verify` uses a separate OpenBao instance without network access and a temporary SQL database. It checks entry versions, MFA and revocation of restored agent access, then removes the fixture. Live data stays intact.

For a full restore, prepare containers with **an empty database and an uninitialized OpenBao instance**, using the same environment name, code and Composer dependencies. Run `prepare` and DDE build/up, but **skip `initialize`, `install` and migrations**. Transfer both recovery files separately to their original private operator location, then run:

```bash
VAULT_ENV=local bin/vault restore /private/path/backup.vault
```

The command refuses to overwrite an existing DB schema or initialized OpenBao instance. It restores SQL, the snapshot and configuration, clears sessions and cache, and revokes all machine tokens and grants. Owner passwords and TOTP survive. Issue new identities and grants to agents. On failure, the target stays stopped to prevent use of a partial restore. Repair the empty target or start with a fresh one. There is no `--force` option to overwrite an existing Vault. `tests/restore_empty.py` exercises this procedure in disposable containers and a temporary DB.

Every table in the DDE table registry uses `empty skip`. General dataset and SQL exports intentionally omit identities, the catalog and audit data. **Use only a full encrypted Vault backup to restore Vault.** A standalone SQL dump is insufficient. The schema snapshot is versioned in `app/database/schema/`. Laravel migrations change the schema. `DATABASE_MIGRATIONS=off` disables DDE migrations only.

## Rotation, access and maintenance

```bash
bin/vault rotate-service-credentials
bin/vault rotate-encryption-key
bin/vault maintenance
```

The first command issues new AppRole SecretIDs and revokes the old ones. Existing tokens expire within 5 minutes. The second rotates the OpenBao encryption key while keeping old entries readable. `maintenance` removes expired session and cache state, marks expired operations and runs both rotations every 30 days. To use the example systemd timer in `ops/`, set the absolute checkout path and `VAULT_ENV` in the copy and enable it in the operator's user systemd instance. Cloning the repository leaves the timer disabled.

`VAULT_ENV=test bin/vault database` provisions synthetic dynamic MySQL credentials for `vault-probe` (SELECT only on `vault_probe`, maximum 60 s). These credentials have no read access to the Vault catalog DB. The broker revokes the lease token after the action. If it crashes, the OpenBao TTL applies. An external DB requires an operator-configured OpenBao connection, role and network policy. Keep `vault_lease_admin` privileges off the catalog.

If an owner loses access, a trusted operator can run `bin/vault recover-user owner@example.com`. The command writes a new random password only to `~/.config/vault/<env>/recovered-user.json`. An authentication revision invalidates existing web sessions, including those awaiting MFA. The next login requires fresh MFA enrollment. The user reads the file directly, signs in, changes the password in the web app and removes the temporary recovery file. Never send it to an LLM. Only the operator can perform this recovery. Users cannot bypass MFA.

Archiving an entry in the web app revokes its grants. Restoring it from the archive leaves those grants revoked. Only an owner can permanently destroy an archived entry, after typing DELETE. Destruction removes all versions from the live backend. Existing backups follow their own retention policy. Metadata and audit history remain. Blank secret fields in the web edit form retain their current values, as do imported fields omitted from the form. Users can enter an SSH passphrase or explicitly select its removal. Restoring a historical version creates a new CAS version. External API keys and website passwords do not change automatically.

## Updates and incident response

Before updating, create and verify a backup. Install the locked Composer dependencies, rebuild the broker image, apply Laravel migrations and run health checks and tests. Recreate the broker container after changing the seccomp file contents. The Compose path alone stays the same. `broker/seccomp.json` uses the default Moby profile at commit `6fe7deb1b9fb7c0397a4593480d7d22b9ee8caef`, with clone/setns/unshare/chroot added for Chromium's user namespace sandbox. Keep the sandbox enabled.

If you suspect a leak, revoke machine access and grants in the web app, stop the broker, rotate credentials on target systems and the AppRole, and review the audit log and host access. SQL stores the audit log independently of secret values. A host or DB administrator can modify it. Server-side sessions on external sites may last until their TTL expires, even after the local Chromium context closes.
