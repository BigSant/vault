"""The systemd units in ops/ and bin/vault's handoff directory, without systemd, Docker or a network."""
import grp
import importlib.machinery
import importlib.util
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
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
        # The backup retry is the loop in ExecStart (one start, a clear end), not Restart=.
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

    def test_backup_gives_up_after_twelve_attempts_and_says_so(self):
        Path(self.tmp.name, 'codes').write_text('75\n' * 20)
        base = {'PATH': str(self.bin) + ':/usr/bin:/bin', 'VAULT_ENV': 'prod', 'VAULT_CHECKOUT': str(self.checkout)}
        result = subprocess.run(['/bin/sh', '-c', exec_start('vault-backup.service')], env=base, capture_output=True)
        self.assertEqual(75, result.returncode)
        self.assertEqual(12, len(self.log.read_text().splitlines()))
        self.assertIn(b'still busy after 12 attempts', result.stderr)
        self.assertIn('12 attempts', (ROOT/'ops/vault-backup.service').read_text())

    def test_backup_has_no_start_timeout_and_resumes_services_after_a_kill(self):
        text = (ROOT/'ops/vault-backup.service').read_text()
        self.assertRegex(text, r'(?m)^TimeoutStartSec=infinity$')
        line = next(l for l in text.splitlines() if l.startswith('ExecStopPost='))
        self.log.unlink(missing_ok=True)
        Path(self.tmp.name, 'codes').write_text('0\n')
        base = {'PATH': str(self.bin) + ':/usr/bin:/bin', 'VAULT_ENV': 'prod', 'VAULT_CHECKOUT': str(self.checkout)}
        subprocess.run(['/bin/sh', '-c', line[len('ExecStopPost='):].replace('$$', '$')], env=base, check=True)
        self.assertEqual(['backup-resume'], self.log.read_text().splitlines())


class ExitCodeTests(unittest.TestCase):
    """The real bin/vault __main__ block: Busy exits 75, any other failure 1, SIGTERM unwinds (finally runs)."""
    def run_vault(self, body):
        script = ('import os,runpy,sys\nsys.path.insert(0,%r)\nimport recovery\n' % str(ROOT/'scripts') + body +
                  '\nsys.argv=["vault","backup"]\nrunpy.run_path(%r,run_name="__main__")\n' % str(ROOT/'bin/vault'))
        return subprocess.run([sys.executable, '-c', script], env={'PATH': '/usr/bin:/bin', 'VAULT_ENV': 'test', 'HOME': tempfile.gettempdir()},
                              capture_output=True, timeout=30)

    def test_busy_is_75_and_other_errors_are_1(self):
        busy = self.run_vault('def backup(self):raise recovery.Busy("Active operations must finish before backup")\nrecovery.Recovery.backup=backup')
        self.assertEqual(75, busy.returncode)
        self.assertIn(b'Active operations', busy.stderr)
        other = self.run_vault('def backup(self):raise RuntimeError("Recovery subprocess failed")\nrecovery.Recovery.backup=backup')
        self.assertEqual(1, other.returncode)
        crash = self.run_vault('def backup(self):raise KeyError("x")\nrecovery.Recovery.backup=backup')
        self.assertEqual(1, crash.returncode)
        self.assertNotIn(b"'x'", crash.stderr)

    def test_sigterm_runs_the_finally(self):
        marker = Path(tempfile.mkdtemp())/'finally'
        body = ('import signal,time\ndef backup(self):\n    try:\n        os.kill(os.getpid(),signal.SIGTERM);time.sleep(5)\n'
                '    finally:\n        open(%r,"w").write("ran")\nrecovery.Recovery.backup=backup' % str(marker))
        result = self.run_vault(body)
        self.assertEqual(143, result.returncode)
        self.assertEqual('ran', marker.read_text())


class RecoveryResumeTests(unittest.TestCase):
    """scripts/recovery.py's resume, the backup's marker and its restore, against a `docker` stand-in that keeps the
    containers' states: a `docker stop` whose client is killed keeps stopping the broker until the next command
    has run, as the real daemon does."""
    def setUp(self):
        sys.path.insert(0, str(ROOT/'scripts'))
        import recovery
        self.recovery = recovery
        self.tmp = tempfile.TemporaryDirectory()
        self.data = Path(self.tmp.name)/'data'
        self.data.mkdir()
        self.calls, self.fail, self.statements = [], {}, []
        self.state = {'php': 'running', 'broker': 'running'}
        self.maintenance = False
        self.interrupt = None
        self.broken = set()
        self.has_handoff = 1
        self.stuck = False
        self.marker = self.data/'.backup-quiesced'
        test = self

        class Fake(recovery.Recovery):
            def run(self, args, data=None):
                name = ('start' if args[:2] == ['docker', 'start'] else 'stop' if args[:2] == ['docker', 'stop'] else
                        'inspect' if args[:2] == ['docker', 'inspect'] else args[-1] if args[-2:] in (['artisan', 'down'], ['artisan', 'up']) else
                        'dump' if 'mysqldump' in ' '.join(args) else 'other')
                test.calls.append(name)
                due = [c for c, v in test.state.items() if v == 'stopping']
                try:
                    if name == 'down':
                        test.marker_at_down = test.marker.exists()
                        # Even when its client is killed, the command in the container completes.
                        test.maintenance = True
                        if test.interrupt == 'down':
                            raise SystemExit(143)
                    if name in test.fail:
                        raise test.fail[name]
                    if name == 'stop':
                        if test.interrupt == 'stop':
                            test.interrupt = None
                            test.state.update(php='stopped', broker='stopping')
                            raise SystemExit(143)
                        test.state.update(php='stopped', broker='stopped')
                    elif name == 'start':
                        for c in test.state:
                            if test.state[c] == 'stopped' and c not in test.broken:
                                test.state[c] = 'running'
                    elif name == 'up':
                        if test.state['php'] != 'running':
                            raise RuntimeError('Recovery subprocess failed')
                        test.maintenance = test.stuck
                    elif name == 'inspect':
                        return ''.join(('true' if test.state[c] != 'stopped' else 'false') + '\n' for c in ('php', 'broker')).encode()
                    return b''
                finally:
                    for c in due:
                        if test.state[c] == 'stopping':
                            test.state[c] = 'stopped'

            def in_maintenance(self):
                return test.maintenance

            def sql(self, statement, database=None):
                test.statements.append(statement)
                return str(test.has_handoff).encode() if 'information_schema' in statement else b'0'

            def dbname(self):
                return 'vault'
        self.vault = Fake({'ROOT': Path(self.tmp.name), 'DATA': self.data, 'ENV': 'test', 'RECOVERY': self.data, 'bao': None, 'CONTAINER': 'openbao'})

    def tearDown(self):
        self.tmp.cleanup()

    def assert_restored(self):
        self.assertEqual({'php': 'running', 'broker': 'running'}, self.state)
        self.assertFalse(self.maintenance)
        self.assertFalse(self.marker.exists())

    def test_without_a_marker_resume_does_nothing(self):
        self.assertFalse(self.vault.resume())
        self.assertEqual([], self.calls)

    def test_with_a_marker_resume_restores_verifies_and_then_removes_it(self):
        self.marker.write_text('1')
        self.state.update(php='stopped', broker='stopped')
        self.maintenance = True
        self.assertTrue(self.vault.resume())
        self.assertEqual(['stop', 'start', 'up', 'inspect'], self.calls)
        self.assert_restored()

    def test_resume_leaves_another_running_backup_alone(self):
        import fcntl
        self.marker.write_text('1')
        with open(self.data/'.backup.lock', 'a') as held:
            fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertFalse(self.vault.resume())
        self.assertEqual([], self.calls)
        self.assertTrue(self.marker.exists())

    def test_a_failed_or_unverified_resume_keeps_the_marker(self):
        self.marker.write_text('1')
        self.fail['up'] = RuntimeError('Recovery subprocess failed')
        with self.assertRaises(RuntimeError):
            self.vault.resume()
        self.assertTrue(self.marker.exists())
        # The commands succeed, but the broker does not come back: not verified, the marker stays.
        self.fail.clear()
        self.state['broker'] = 'stopped'
        self.broken = {'broker'}
        with self.assertRaisesRegex(RuntimeError, 'not running'):
            self.vault.resume()
        self.assertTrue(self.marker.exists())
        # Both run, `artisan up` exits 0, but the application is still in maintenance: not verified either.
        self.broken = set()
        self.stuck = True
        with self.assertRaisesRegex(RuntimeError, 'maintenance is still on'):
            self.vault.resume()
        self.assertTrue(self.marker.exists())

    def test_backup_writes_the_marker_before_it_quiesces_and_restores_after_a_failure(self):
        self.fail['dump'] = RuntimeError('Recovery subprocess failed')
        with self.assertRaises(RuntimeError):
            self.vault.backup()
        self.assertTrue(self.marker_at_down)
        self.assertEqual(['down', 'stop', 'dump', 'stop', 'start', 'up', 'inspect'], self.calls)
        self.assert_restored()

    def test_sigterm_inside_artisan_down_still_lifts_maintenance(self):
        self.interrupt = 'down'
        with self.assertRaises(SystemExit):
            self.vault.backup()
        self.assert_restored()

    def test_sigterm_inside_docker_stop_waits_for_it_and_brings_both_back(self):
        # The broker is still stopping when the finally runs: a `docker start` alone would be a no-op for it, and it
        # would stop a moment later with the marker already gone.
        self.interrupt = 'stop'
        with self.assertRaises(SystemExit):
            self.vault.backup()
        self.assertEqual(['down', 'stop', 'stop', 'start', 'up', 'inspect'], self.calls)
        self.assert_restored()

    def test_a_backup_whose_restore_fails_leaves_the_marker_for_resume(self):
        self.fail['dump'] = RuntimeError('Recovery subprocess failed')
        self.fail['start'] = SystemExit(143)
        with self.assertRaises(SystemExit):
            self.vault.backup()
        self.assertTrue(self.marker.exists())
        self.fail.clear()
        self.calls.clear()
        self.assertTrue(self.vault.resume())
        self.assert_restored()

    def test_the_next_backup_finishes_an_interrupted_ones_restore_first(self):
        # Backup A died after quiescing; its resume found the lock held by backup B (it did nothing). B, holding the
        # lock, restores A's quiesce before it checks for maintenance, and then runs normally.
        self.marker.write_text('1')
        self.state.update(php='stopped', broker='stopped')
        self.maintenance = True
        self.fail['dump'] = RuntimeError('Recovery subprocess failed')
        with self.assertRaises(RuntimeError):
            self.vault.backup()
        self.assertEqual(['stop', 'start', 'up', 'inspect', 'down', 'stop', 'dump', 'stop', 'start', 'up', 'inspect'], self.calls)
        self.assert_restored()

    def test_a_second_backup_or_a_restore_is_busy_while_one_holds_the_lock(self):
        import fcntl
        with open(self.data/'.backup.lock', 'a') as held:
            fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaises(self.recovery.Busy):
                self.vault.backup()
            with self.assertRaises(self.recovery.Busy):
                self.vault.restore(self.data/'any.vault')
        self.assertEqual([], self.calls)

    def test_a_restored_database_holds_no_entry_for_an_old_handoff_file(self):
        self.vault.invalidate_restored_access('vault_restore')
        self.assertIn("UPDATE operations SET handoff='removed' WHERE handoff='written';", self.statements[-1])

    def test_a_backup_from_before_handoffs_restores_without_the_column(self):
        # master's schema: operations has no handoff column; the statement would fail (ERROR 1054) and stop restore.
        self.has_handoff = 0
        self.vault.invalidate_restored_access('vault_restore')
        self.assertFalse(any('handoff=' in statement for statement in self.statements))
        self.assertIn("UPDATE operations SET state='revoked'", self.statements[0])


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
        (configured/'.vault-handoff').write_bytes(b'')
        with patch.object(self.vault, 'env_setting', lambda name: str(configured) if name == 'VAULT_HANDOFF_DIRECTORY' else None):
            with self.assertRaisesRegex(RuntimeError, '2710'):
                self.vault.handoff_directory()
            os.chmod(configured, 0o2710)
            self.vault.handoff_directory()

    def test_prepare_never_marks_a_directory_it_did_not_just_make(self):
        # Another directory of the Vault user (0700, the right group) named by mistake: no marker is made, so the
        # broker never removes the <uuid>.json files in it.
        configured = Path(self.tmp.name)/'other'
        configured.mkdir(mode=0o700)
        keep = configured/'0f8fad5b-d9cb-469f-a165-70867728950e.json'
        keep.write_text('{}')
        with patch.object(self.vault, 'env_setting', lambda name: str(configured) if name == 'VAULT_HANDOFF_DIRECTORY' else None):
            with self.assertRaisesRegex(RuntimeError, 'marker'):
                self.vault.handoff_directory()
        self.assertFalse((configured/'.vault-handoff').exists())
        # The same for a default directory that already existed without one.
        default = Path(self.tmp.name)/'handoff'
        default.mkdir(mode=0o700)
        with patch.object(grp, 'getgrnam', side_effect=KeyError), self.assertRaisesRegex(RuntimeError, 'marker'):
            self.vault.handoff_directory()
        self.assertFalse((default/'.vault-handoff').exists())
        self.assertTrue(keep.exists())

    def test_tmpfiles_makes_a_search_only_marked_directory(self):
        lines = [l.split() for l in (ROOT/'ops/vault-handoff.tmpfiles.conf').read_text().splitlines() if l and not l.startswith('#')]
        directory = next(l for l in lines if l[0] == 'd' and l[1].endswith('/prod'))
        self.assertEqual('2710', directory[2])
        self.assertIn(['f', directory[1]+'/.vault-handoff', '0600', 'VAULTUSER', 'vault-handoff', '-'], lines)
        self.assertIn(['x', directory[1]+'/.vault-handoff'], lines)
        # The broker's journal of undelivered final events is not a session file: never aged out.
        self.assertIn(['x', directory[1]+'/.vault-undelivered'], lines)
        self.assertIn(['x', directory[1]+'/.vault-undelivered.tmp'], lines)


if __name__ == '__main__':
    unittest.main()
