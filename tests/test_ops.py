"""The systemd units in ops/ and bin/vault's handoff directory, without systemd, Docker or a network."""
import grp
import importlib.machinery
import importlib.util
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]


def exec_start(unit):
    """The unit's ExecStart command line as the shell sees it ($$ is systemd's escape for $)."""
    line = next(l for l in (ROOT/'ops'/unit).read_text().splitlines() if l.startswith('ExecStart='))
    return line[len('ExecStart='):].replace('$$', '$')


def load_vault():
    loader = importlib.machinery.SourceFileLoader('vault_operator', str(ROOT/'bin/vault'))
    spec = importlib.util.spec_from_loader('vault_operator', loader)
    module = importlib.util.module_from_spec(spec)
    with patch.dict(os.environ, {'VAULT_ENV': 'test'}):
        loader.exec_module(module)
    return module


class UnitTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.checkout = Path(self.tmp.name)/'checkout'
        (self.checkout/'bin').mkdir(parents=True)
        self.bin = Path(self.tmp.name)/'path'
        self.bin.mkdir()
        self.log = Path(self.tmp.name)/'calls'
        # bin/vault stub: exits with the codes listed in "codes", one per call, and logs each call.
        stub = self.checkout/'bin/vault'
        stub.write_text('#!/bin/sh\necho "$@" >> "%s"\nn=$(wc -l < "%s")\ncode=$(sed -n "${n}p" "%s/codes")\nexit "${code:-0}"\n'
                        % (self.log, self.log, self.tmp.name))
        stub.chmod(0o755)
        sleep = self.bin/'sleep'
        sleep.write_text('#!/bin/sh\necho "sleep $1" >> "%s/sleeps"\n' % self.tmp.name)
        sleep.chmod(0o755)

    def tearDown(self):
        self.tmp.cleanup()

    def run_unit(self, unit, codes, env=None):
        Path(self.tmp.name, 'codes').write_text('\n'.join(str(c) for c in codes) + '\n')
        base = {'PATH': str(self.bin) + ':/usr/bin:/bin', 'VAULT_ENV': 'prod', 'VAULT_CHECKOUT': str(self.checkout)}
        environment = {k: v for k, v in (base | (env or {})).items() if v is not None}
        result = subprocess.run(['/bin/sh', '-c', exec_start(unit)], env=environment, capture_output=True)
        calls = self.log.read_text().splitlines() if self.log.exists() else []
        sleeps = Path(self.tmp.name, 'sleeps').read_text().splitlines() if Path(self.tmp.name, 'sleeps').exists() else []
        return result.returncode, calls, sleeps

    def test_no_unit_uses_restart_with_oneshot(self):
        # systemd before 244/254 refuses Restart= on Type=oneshot; the production host runs 249.
        for unit in (ROOT/'ops').glob('*.service'):
            text = unit.read_text()
            self.assertIn('Type=oneshot', text, unit.name)
            self.assertNotRegex(text, r'(?m)^Restart=', unit.name)
            self.assertNotRegex(text, r'(?m)^StartLimit', unit.name)

    def test_every_unit_fails_without_vault_env(self):
        for unit, command in (('vault-backup.service', 'backup'), ('vault-links.service', 'links'), ('vault-maintenance.service', 'maintenance')):
            self.log.unlink(missing_ok=True)
            code, calls, _ = self.run_unit(unit, [0], {'VAULT_ENV': None})
            self.assertNotEqual(0, code, unit)
            self.assertEqual([], calls, unit)
            self.log.unlink(missing_ok=True)
            code, calls, _ = self.run_unit(unit, [0])
            self.assertEqual((0, [command]), (code, calls), unit)

    def test_backup_retries_only_the_busy_exit_code(self):
        code, calls, sleeps = self.run_unit('vault-backup.service', [75, 75, 0])
        self.assertEqual((0, 3, ['sleep 1200', 'sleep 1200']), (code, len(calls), sleeps))

    def test_backup_stops_at_once_on_another_failure(self):
        code, calls, sleeps = self.run_unit('vault-backup.service', [1, 0])
        self.assertEqual((1, 1, []), (code, len(calls), sleeps))

    def test_backup_gives_up_after_twelve_attempts(self):
        code, calls, sleeps = self.run_unit('vault-backup.service', [75] * 20)
        self.assertEqual((75, 12, 11), (code, len(calls), len(sleeps)))
        comment = (ROOT/'ops/vault-backup.service').read_text()
        self.assertIn('12 attempts', comment)


class HandoffDirectoryTests(unittest.TestCase):
    def setUp(self):
        self.vault = load_vault()
        self.tmp = tempfile.TemporaryDirectory()
        self.vault.DATA = Path(self.tmp.name)
        self.setting = patch.object(self.vault, 'env_setting', lambda name: None)
        self.setting.start()

    def tearDown(self):
        self.setting.stop()
        self.tmp.cleanup()

    def test_without_the_group_the_default_directory_is_private_and_marked(self):
        with patch.object(grp, 'getgrnam', side_effect=KeyError):
            self.vault.handoff_directory()
        path = Path(self.tmp.name)/'handoff'
        self.assertEqual(0o700, stat.S_IMODE(os.lstat(path).st_mode))
        self.assertTrue(stat.S_ISREG(os.lstat(path/'.vault-handoff').st_mode))
        # A directory made 0750 by an earlier version is tightened.
        os.chmod(path, 0o750)
        with patch.object(grp, 'getgrnam', side_effect=KeyError):
            self.vault.handoff_directory()
        self.assertEqual(0o700, stat.S_IMODE(os.lstat(path).st_mode))

    def test_with_the_group_the_directory_is_search_only(self):
        groups = os.getgroups()
        if not groups:
            self.skipTest('no group to stand in for vault-handoff')
        with patch.object(grp, 'getgrnam', return_value=grp.struct_group(('vault-handoff', 'x', groups[0], []))):
            self.vault.handoff_directory()
        info = os.lstat(Path(self.tmp.name)/'handoff')
        self.assertEqual(0o2710, stat.S_IMODE(info.st_mode))
        self.assertEqual(groups[0], info.st_gid)

    def test_a_listable_configured_directory_is_refused(self):
        configured = Path(self.tmp.name)/'run'
        configured.mkdir(mode=0o700)
        os.chmod(configured, 0o2750)
        with patch.object(self.vault, 'env_setting', lambda name: str(configured) if name == 'VAULT_HANDOFF_DIRECTORY' else None):
            with self.assertRaisesRegex(RuntimeError, '2710'):
                self.vault.handoff_directory()
            os.chmod(configured, 0o2710)
            self.vault.handoff_directory()
        self.assertTrue((configured/'.vault-handoff').exists())

    def test_tmpfiles_makes_a_search_only_marked_directory(self):
        lines = [l.split() for l in (ROOT/'ops/vault-handoff.tmpfiles.conf').read_text().splitlines() if l and not l.startswith('#')]
        directory = next(l for l in lines if l[0] == 'd' and l[1].endswith('/prod'))
        self.assertEqual('2710', directory[2])
        self.assertIn(['f', directory[1]+'/.vault-handoff', '0600', 'VAULTUSER', 'vault-handoff', '-'], lines)
        self.assertIn(['x', directory[1]+'/.vault-handoff'], lines)


if __name__ == '__main__':
    unittest.main()
