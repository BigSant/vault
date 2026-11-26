# Vault

A credential store with an English web UI and action tools for LLM clients, primarily **ai-os**. A human views values after authentication; an agent gets only the aliases, action names and states it is allowed. The password is used by a separate executor.

It provides workspaces with projects and a shared list of environments, entry types and tags, search, versions, import, archive, destruction, multiple owners, MFA, reveal audit, temporary agent permissions, human approvals, an API, a fixed CLI recipe and isolated browser login with MFA. OpenBao stores the encrypted values; MySQL stores the metadata. Backups are encrypted and restored in a separate environment.

## Getting started

Requires Docker, Python 3.12+ and the shared Docker development setup (DockerDevelopmentEnvironment), prepared as in the other `~/Projects` projects. The setup selects PHP 8.5, MySQL 8.4, Apache and nginx-proxy; the additional services are OpenBao, the broker and the destination proxy.

```bash
cd ~/Projects/vault
bin/vault install
```

`install` prepares the private files, Docker, Composer dependencies, OpenBao identities and Laravel migrations. Running it again does not change existing secrets. `SETUP_DIRECTORY` can point to a different setup directory. `install` is meant for local/test; the server procedure is in the [runbook](docs/operations.md).

Open **https://vault.local/**. The first owner needs the activation code from the private `data/security/app/bootstrap.code` file. Enter its contents directly in the web UI, not in a chat. Create a password and register a TOTP authenticator. Readiness address: `https://vault.local/health.php` (200 only when the DB is reachable and OpenBao is unsealed).

1. Create a project. The workspace starts with the environments local, test, stage and prod (Setup › Environments); every project uses that one list.
2. Add an entry and choose its entry type. The alias is the name visible to the agent; do not keep sensitive data in it.
3. In "Access", create an agent identity and grants for specific entries and actions. The default connector configuration is empty – the agent has no actions.
4. Connect [ai-os or MCP/CLI](docs/integrations.md). The operator describes the specific external addresses and safe recipes in the private configuration.

## Checks

```bash
docker exec -w /var/www/html vault-local-php-fpm php vendor/bin/phpunit
docker run --rm --network none --user 1000:1000 -v "$PWD:/workspace:ro" -w /workspace --entrypoint python3 vault-broker:1 -m unittest discover -s tests -p 'test_*.py' -v
VAULT_ENV=test bin/vault install
VAULT_ENV=test bin/vault database
python3 tests/integration.py
VAULT_ENV=test bin/vault backup
VAULT_ENV=test bin/vault restore-verify backups/<test-backup>.vault
python3 tests/restore_empty.py backups/<test-backup>.vault
```

In the unit test Docker command, adjust the UID/GID to your user. The integration tests use only `vault.test.local`, synthetic credentials and an isolated fixture. They check real HTTPS, CSRF, TOTP, OpenBao, API/CLI, short-lived DB access, browser MFA, a disallowed destination, password echo and revocation. Separate checks cover corruption of the encrypted backup, DNS address checking, permissions and client responses. After a code change, refresh the test environment with `make -C app test-init refresh=1`, then `make -C app up ENV=test`; broker changes also need `make -C app build`.

## Trust boundaries

**An LLM that has host/root, the Docker socket or the ability to change the executor code can reach the secrets.** In production, Vault and the trusted ai-os backend must run on a host/VM the agent does not administer; the agent sandbox must not get these disks, the configuration or the owner web session. Local Docker is a development environment, not proof of this boundary.

There is no `get_secret`, arbitrary shell, HTTP proxy, DOM, cookies, CDP or screenshots for the agent. The API and CLI recipe check for a 200 status from a registered HTTPS address; the browser logs in and checks the account state defined by the operator. These are specific useful actions, not general browsing. A new action needs an operator definition and a check of its results. CAPTCHA, special WebAuthn/SSO flows, SSH execution and automatic password change for arbitrary external accounts are not supported. SSH keys and secret notes can be stored and viewed.

Automatic rotation applies to the managed AppRole and OpenBao encryption keys through the operator's maintenance timer; dynamic DB access is valid for up to 60 s. Values of other systems are changed by a human in that system and then in Vault. `rotate_at` is a reminder, not a guarantee that the external password was changed.

- [Architecture and acceptance contract](docs/architecture.md)
- [Deployment, backups, restore, rotation](docs/operations.md)
- [ai-os, MCP/CLI and connector contract](docs/integrations.md)
- [Research on the official 1Password, Bitwarden, Infisical and OpenBao systems](research/secret-management.md)
