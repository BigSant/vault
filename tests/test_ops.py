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
import time
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


class StopTrapTests(unittest.TestCase):
    """systemctl stop sends SIGTERM to the whole cgroup. The unit's shell must outlive bin/vault (so that systemd does
    not run ExecStopPost while bin/vault still restores), then exit 143 without another attempt."""
    def start(self, vault_body, sleep_body):
        self.tmp = Path(tempfile.mkdtemp())
        checkout, bin_ = self.tmp/'checkout', self.tmp/'path'
        (checkout/'bin').mkdir(parents=True)
        bin_.mkdir()
        self.log = self.tmp/'log'
        for path, body in ((checkout/'bin/vault', vault_body), (bin_/'sleep', sleep_body)):
            path.write_text('#!%s\nimport signal,sys,time\nlog=open(%r,"a",buffering=1)\n%s' % (sys.executable, str(self.log), body))
            path.chmod(0o755)
        env = {'PATH': str(bin_) + ':/usr/bin:/bin', 'VAULT_ENV': 'prod', 'VAULT_CHECKOUT': str(checkout)}
        # As systemd runs it: the unit's own /bin/sh is the main process (no shell around it).
        import shlex
        return subprocess.Popen(shlex.split(exec_start('vault-backup.service')), env=env, start_new_session=True)

    def wait_for(self, text):
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            if self.log.exists() and text in self.log.read_text():
                return
            time.sleep(0.02)
        self.fail('never logged ' + text)

    def test_the_shell_waits_for_bin_vault_to_finish_its_restore(self):
        import signal
        vault = ('def term(*_):\n    log.write("restoring\\n");time.sleep(1);log.write("restored\\n");sys.exit(143)\n'
                 'signal.signal(signal.SIGTERM,term)\nlog.write("backup\\n");time.sleep(30)\n')
        unit = self.start(vault, 'time.sleep(30)\n')
        self.wait_for('backup')
        os.killpg(unit.pid, signal.SIGTERM)
        self.assertEqual(143, unit.wait(10))
        # The shell ended only after bin/vault had restored, and did not try again.
        self.assertEqual(['backup', 'restoring', 'restored'], self.log.read_text().split())

    def test_a_stop_during_the_wait_for_a_retry_ends_without_another_attempt(self):
        import signal
        unit = self.start('log.write("backup\\n");sys.exit(75)\n', 'log.write("sleeping\\n");time.sleep(30)\n')
        self.wait_for('sleeping')
        os.killpg(unit.pid, signal.SIGTERM)
        self.assertEqual(143, unit.wait(10))
        self.assertEqual(['backup', 'sleeping'], self.log.read_text().split())

    def test_the_stop_timeout_leaves_room_for_resume(self):
        text = (ROOT/'ops/vault-backup.service').read_text()
        self.assertRegex(text, r'(?m)^TimeoutStopSec=10min$')
        sys.path.insert(0, str(ROOT/'scripts'))
        import recovery
        self.assertLess(recovery.RESUME_WAIT, 600 - 60)


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


FAKE_DOCKER = r'''
import json, time
from pathlib import Path


class DockerState:
    """PHP, the broker and Laravel's two maintenance files, as `docker` and `artisan` change them. A `docker stop`
    whose client is killed keeps stopping the broker until the next command has run, as the daemon does. The state
    may live in a JSON file, so that several processes (bin/vault, backup-resume) share it."""
    def __init__(self, path=None):
        self.path = path
        self.state = {'php': 'running', 'broker': 'running', 'down': False, 'page': False, 'calls': []}
        self.options = {'interrupt': None, 'fail': {}, 'broken': [], 'stuck': False, 'cut_up': False, 'exec_fails': False, 'slow_stop': 0}
        if path:
            self.save()

    def load(self):
        if self.path:
            self.state, self.options = json.loads(Path(self.path).read_text())

    def save(self):
        if self.path:
            Path(self.path).write_text(json.dumps([self.state, self.options]))

    def run(self, args, marker=None):
        self.load()
        st, op = self.state, self.options
        joined = ' '.join(args)
        if args[:2] == ['docker', 'start']:
            name = 'start' if len(args) == 4 else 'start-' + ('php' if 'php' in args[2] else 'broker')
        elif args[:2] == ['docker', 'stop']:
            name = 'stop'
        elif args[:2] == ['docker', 'inspect']:
            name = 'inspect'
        elif args[-2:] in (['artisan', 'down'], ['artisan', 'up']):
            name = args[-1]
        elif args[-3:] == ['artisan', 'migrate', '--force']:
            name = 'migrate'
        elif 'for f in' in joined:
            name = 'maint'
        elif args[-3:-1] == ['rm', '-f']:
            name = 'rm'
        elif 'mysqldump' in joined:
            name = 'dump'
        else:
            name = 'other'
        st['calls'].append(name)
        due = [c for c in ('php', 'broker') if st[c] == 'stopping']
        result = b''
        try:
            if name == 'down':
                st['marker_at_down'] = bool(marker and Path(marker).exists())
                # Even when its client is killed, the command in the container completes.
                st['down'] = st['page'] = True
                if op['interrupt'] == 'down':
                    op['interrupt'] = None
                    raise SystemExit(143)
            if name in op['fail']:
                kind, text = op['fail'][name]
                raise SystemExit(143) if kind == 'exit' else RuntimeError(text)
            if name == 'stop':
                if op['interrupt'] == 'stop':
                    op['interrupt'] = None
                    st.update(php='stopped', broker='stopping')
                    raise SystemExit(143)
                if op['slow_stop']:
                    st['phase'] = 'restoring'
                    self.save()
                    time.sleep(op['slow_stop'])
                    self.load()
                    st, op = self.state, self.options
                    st['calls'][-1:] = ['stop']
                st.update(php='stopped', broker='stopped')
            elif name.startswith('start'):
                for c in ('php', 'broker'):
                    if (name == 'start' or name == 'start-' + c) and st[c] == 'stopped' and c not in op['broken']:
                        st[c] = 'running'
            elif name == 'up':
                if st['php'] != 'running':
                    raise RuntimeError('Recovery subprocess failed')
                # artisan up: nothing when framework/down is gone ("already up"), else down first, then the page.
                if st['down'] and not op['stuck']:
                    st['down'] = False
                    if not op['cut_up']:
                        st['page'] = False
            elif name == 'rm':
                st['page'] = False
            elif name == 'maint':
                if op['exec_fails'] or st['php'] != 'running':
                    raise RuntimeError('Recovery subprocess failed')
                result = ('%d\n%d\n' % (st['down'], st['page'])).encode()
            elif name == 'inspect':
                result = ''.join(('true' if st[c] != 'stopped' else 'false') + '\n' for c in ('php', 'broker')).encode()
            elif name == 'dump' and op.get('dump_wait'):
                st['phase'] = 'dumping'
                self.save()
                time.sleep(op['dump_wait'])
            return result
        finally:
            for c in due:
                if st[c] == 'stopping':
                    st[c] = 'stopped'
            self.save()
'''


def fake_recovery(recovery, docker, data, statements, answers):
    """A Recovery whose Docker and SQL calls go to the stand-in."""
    class Fake(recovery.Recovery):
        def run(self, args, data_=None):
            return docker.run(args, data/'.backup-quiesced')

        def sql(self, statement, database=None):
            statements.append(statement)
            for key, value in answers.items():
                if key in statement:
                    return str(value).encode()
            return b'0'

        def dbname(self):
            return 'vault'
    return Fake({'ROOT': ROOT, 'DATA': data, 'ENV': 'test', 'RECOVERY': data, 'bao': None, 'CONTAINER': 'openbao'})


class RecoveryResumeTests(unittest.TestCase):
    """scripts/recovery.py's resume, the backup's marker and its restore, against the stand-in above."""
    def setUp(self):
        sys.path.insert(0, str(ROOT/'scripts'))
        import recovery
        self.recovery = recovery
        namespace = {}
        exec(FAKE_DOCKER, namespace)
        self.tmp = tempfile.TemporaryDirectory()
        self.data = Path(self.tmp.name)/'data'
        self.data.mkdir()
        self.docker = namespace['DockerState']()
        self.statements = []
        self.answers = {'information_schema': 1}
        self.marker = self.data/'.backup-quiesced'
        self.vault = fake_recovery(recovery, self.docker, self.data, self.statements, self.answers)

    def tearDown(self):
        self.tmp.cleanup()

    @property
    def calls(self):
        return self.docker.state['calls']

    def assert_restored(self):
        st = self.docker.state
        self.assertEqual(('running', 'running', False, False), (st['php'], st['broker'], st['down'], st['page']))
        self.assertFalse(self.marker.exists())

    RESTORE = ['stop', 'start', 'up', 'maint', 'inspect', 'maint']

    def test_without_a_marker_resume_does_nothing(self):
        self.assertFalse(self.vault.resume())
        self.assertEqual([], self.calls)

    def test_with_a_marker_resume_restores_verifies_and_then_removes_it(self):
        self.marker.write_text('1')
        self.docker.state.update(php='stopped', broker='stopped', down=True, page=True)
        self.assertTrue(self.vault.resume())
        self.assertEqual(self.RESTORE, self.calls)
        self.assert_restored()

    def test_resume_waits_a_bounded_time_for_another_backup(self):
        import fcntl
        self.marker.write_text('1')
        with open(self.data/'.backup.lock', 'a') as held:
            fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with patch.object(self.recovery, 'RESUME_WAIT', 1):
                started = time.monotonic()
                self.assertFalse(self.vault.resume())
                self.assertGreaterEqual(time.monotonic() - started, 1)
        self.assertEqual([], self.calls)
        self.assertTrue(self.marker.exists())

    def test_a_failed_or_unverified_resume_keeps_the_marker(self):
        self.marker.write_text('1')
        self.docker.options['fail'] = {'up': ['error', 'Recovery subprocess failed']}
        with self.assertRaises(RuntimeError):
            self.vault.resume()
        self.assertTrue(self.marker.exists())
        # The commands succeed, but the broker does not come back: not verified, the marker stays.
        self.docker.options['fail'] = {}
        self.docker.state['broker'] = 'stopped'
        self.docker.options['broken'] = ['broker']
        with self.assertRaisesRegex(RuntimeError, 'not running'):
            self.vault.resume()
        self.assertTrue(self.marker.exists())
        # Both run, `artisan up` exits 0, but framework/down is still there: not verified either.
        self.docker.options['broken'] = []
        self.docker.state['down'] = True
        self.docker.options['stuck'] = True
        with self.assertRaisesRegex(RuntimeError, 'maintenance is still on'):
            self.vault.resume()
        self.assertTrue(self.marker.exists())

    def test_a_leftover_framework_down_counts_as_maintenance(self):
        self.docker.state['down'] = True
        self.assertTrue(self.vault.in_maintenance())
        with self.assertRaisesRegex(RuntimeError, 'already in maintenance'):
            self.vault.backup()
        self.assertNotIn('stop', self.calls)

    def test_an_interrupted_artisan_up_is_finished_not_left_for_ever(self):
        # `up` removed framework/down and was cut before maintenance.php: a repeated `up` leaves it ("already up").
        self.marker.write_text('1')
        self.docker.state.update(down=False, page=True)
        self.assertTrue(self.vault.resume())
        self.assertIn('rm', self.calls)
        self.assert_restored()

    def test_a_maintenance_state_that_cannot_be_read_keeps_the_marker(self):
        self.marker.write_text('1')
        self.docker.options['exec_fails'] = True
        with self.assertRaises(RuntimeError):
            self.vault.resume()
        self.assertTrue(self.marker.exists())
        with self.assertRaises(RuntimeError):
            self.vault.in_maintenance()

    def test_backup_writes_the_marker_before_it_quiesces_and_restores_after_a_failure(self):
        self.docker.options['fail'] = {'dump': ['error', 'Recovery subprocess failed']}
        with self.assertRaises(RuntimeError):
            self.vault.backup()
        self.assertTrue(self.docker.state['marker_at_down'])
        self.assertEqual(['maint', 'down', 'stop', 'dump', *self.RESTORE], self.calls)
        self.assert_restored()

    def test_sigterm_inside_artisan_down_still_lifts_maintenance(self):
        self.docker.options['interrupt'] = 'down'
        with self.assertRaises(SystemExit):
            self.vault.backup()
        self.assert_restored()

    def test_sigterm_inside_docker_stop_waits_for_it_and_brings_both_back(self):
        # The broker is still stopping when the finally runs: a `docker start` alone would be a no-op for it, and it
        # would stop a moment later with the marker already gone.
        self.docker.options['interrupt'] = 'stop'
        with self.assertRaises(SystemExit):
            self.vault.backup()
        self.assertEqual(['maint', 'down', 'stop', *self.RESTORE], self.calls)
        self.assert_restored()

    def test_a_backup_whose_restore_fails_leaves_the_marker_for_resume(self):
        self.docker.options['fail'] = {'dump': ['error', 'Recovery subprocess failed'], 'start': ['exit', '']}
        with self.assertRaises(SystemExit):
            self.vault.backup()
        self.assertTrue(self.marker.exists())
        self.docker.options['fail'] = {}
        self.assertTrue(self.vault.resume())
        self.assert_restored()

    def test_the_next_backup_finishes_an_interrupted_ones_restore_first(self):
        # Backup A died after quiescing; its resume did not get the lock from backup B. B, holding the lock, restores
        # A's quiesce before it checks for maintenance, and then runs normally.
        self.marker.write_text('1')
        self.docker.state.update(php='stopped', broker='stopped', down=True, page=True)
        self.docker.options['fail'] = {'dump': ['error', 'Recovery subprocess failed']}
        with self.assertRaises(RuntimeError):
            self.vault.backup()
        self.assertEqual([*self.RESTORE, 'maint', 'down', 'stop', 'dump', *self.RESTORE], self.calls)
        self.assert_restored()

    def test_a_forgotten_marker_never_cuts_a_live_lease(self):
        # Services brought up by hand, marker left behind, a lease running: the backup stops nothing.
        self.marker.write_text('1')
        self.answers['state IN'] = 1
        with self.assertRaises(self.recovery.Busy):
            self.vault.backup()
        self.assertEqual([], self.calls)
        self.assertTrue(self.marker.exists())

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
        self.answers['information_schema'] = 0
        self.vault.invalidate_restored_access('vault_restore')
        self.assertFalse(any('handoff=' in statement for statement in self.statements))
        self.assertIn("UPDATE operations SET state='revoked'", self.statements[0])

    def test_restore_migrates_before_the_application_opens_and_checks_the_identities_query(self):
        import json
        saved = []

        def unpack(source, target):
            for name in ('database.sql', 'openbao.snap', 'app.key', 'app-bao.json', 'broker-bao.json', 'broker.key'):
                (target/name).write_text('synthetic')
            (target/'connectors.json').write_text(json.dumps({'actions': {'old.login': {'kind': 'browser', 'url': 'https://shop.invalid/login'}}}))
            return {'unseal_keys_b64': ['synthetic-a', 'synthetic-b'], 'root_token': 'synthetic-root'}

        def bao(args, payload=None, token='', acceptable=(0,)):
            return {'status': '{"initialized": false}', 'operator': '{"unseal_keys_b64": ["synthetic-k"], "root_token": "synthetic-t"}'}.get(args[0], '{}') \
                if args[:2] != ['operator', 'raft'] else ''
        self.vault.c.update(bao=bao, save=lambda path, text: saved.append(path.name))
        self.answers['SHOW TABLES'] = ''
        import contextlib
        import io
        out = io.StringIO()
        with patch.object(self.vault, 'unpack', unpack), contextlib.redirect_stdout(out):
            self.vault.restore(self.data/'old.vault')
        calls = self.calls
        self.assertLess(calls.index('start-php'), calls.index('migrate'))
        self.assertLess(calls.index('down'), calls.index('migrate'))
        self.assertLess(calls.index('migrate'), calls.index('start-broker'))
        self.assertLess(calls.index('start-broker'), calls.index('up'))
        checks = [i for i, s in enumerate(self.statements) if 'executor FROM machines' in s]
        self.assertEqual(1, len(checks))
        # A restored connectors.json of an older version is named for updating (its key only, nothing of its URL).
        self.assertIn('old.login: name the login request', out.getvalue())
        self.assertNotIn('shop.invalid', out.getvalue())


class StopSequenceTests(unittest.TestCase):
    """The sequence a real `systemctl --user stop` makes, with processes instead of systemd: SIGTERM to bin/vault
    during the backup, ExecStopPost (backup-resume) while bin/vault still holds the lock, a second SIGTERM during the
    restore. The services must end up running and maintenance off."""
    SCRIPT = r"""
import json, os, signal, sys
from pathlib import Path
sys.path.insert(0, %(scripts)r)
import recovery
namespace = {}
exec(%(fake)r, namespace)
docker = namespace['DockerState']()
docker.path = %(state)r
docker.load()


class Fake(recovery.Recovery):
    def run(self, args, data_=None):
        return docker.run(args, %(marker)r)

    def sql(self, statement, database=None):
        return b'0'

    def dbname(self):
        return 'vault'


vault = Fake({'ROOT': Path(%(root)r), 'DATA': Path(%(data)r), 'ENV': 'test', 'RECOVERY': Path(%(data)r), 'bao': None, 'CONTAINER': 'openbao'})
signal.signal(signal.SIGTERM, lambda *_: sys.exit(143))
recovery.RESUME_WAIT = 30
print('resumed' if (vault.backup() if sys.argv[1] == 'backup' else vault.resume()) else 'nothing', flush=True)
"""

    def test_execstoppost_while_the_lock_is_held_then_a_second_sigterm(self):
        import signal
        namespace = {}
        exec(FAKE_DOCKER, namespace)
        tmp = Path(tempfile.mkdtemp())
        data = tmp/'data'
        data.mkdir()
        state = tmp/'docker.json'
        docker = namespace['DockerState'](str(state))
        docker.options.update(dump_wait=30, slow_stop=1.5)
        docker.save()
        script = tmp/'run.py'
        script.write_text(self.SCRIPT % {'scripts': str(ROOT/'scripts'), 'fake': FAKE_DOCKER, 'state': str(state), 'marker': str(data/'.backup-quiesced'),
                                         'root': str(tmp), 'data': str(data)})

        def phase(name, seconds=20):
            deadline = time.monotonic() + seconds
            while time.monotonic() < deadline:
                docker.load()
                if docker.state.get('phase') == name:
                    return
                time.sleep(0.05)
            self.fail('never reached ' + name)
        backup = subprocess.Popen([sys.executable, str(script), 'backup'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        phase('dumping')
        backup.send_signal(signal.SIGTERM)
        phase('restoring')
        # ExecStopPost runs now, while bin/vault restores and holds the lock; then systemd's second SIGTERM.
        resume = subprocess.Popen([sys.executable, str(script), 'resume'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        time.sleep(0.3)
        backup.send_signal(signal.SIGTERM)
        self.assertEqual(143, backup.wait(30))
        out, _ = resume.communicate(timeout=60)
        self.assertEqual(0, resume.returncode)
        # It waited for the lock instead of giving up, and found the restore done.
        self.assertEqual(b'nothing\n', out)
        docker.load()
        self.assertEqual(('running', 'running', False, False), tuple(docker.state[k] for k in ('php', 'broker', 'down', 'page')))
        self.assertFalse((data/'.backup-quiesced').exists())
        self.assertEqual(['stop', 'start', 'up', 'maint', 'inspect', 'maint'], docker.state['calls'][-6:])


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

    def test_prepare_names_connectors_this_broker_refuses(self):
        import json
        (Path(self.tmp.name)/'connectors').mkdir()
        path = Path(self.tmp.name)/'connectors/connectors.json'
        login = {'kind': 'storage_state', 'url': 'https://shop.invalid/login', 'allowed_requests': [{'method': 'POST', 'url': 'https://shop.invalid/login'}]}
        path.write_text(json.dumps({'actions': {
            'shop.session': login, 'old.static': {'kind': 'http', 'url': 'https://a.invalid/', 'static_subresources': True},
            'one': {'kind': 'http', 'url': 'https://b.invalid/', 'allow_cidrs': ['10.0.0.1/32']}, 'two': {'kind': 'http', 'url': 'https://b.invalid/x'}}}))
        with self.assertRaises(RuntimeError) as refused:
            self.vault.check_connectors()
        text = str(refused.exception)
        for part in ('shop.session: name the login request', 'old.static: "static_subresources"', 'different "allow_cidrs"'):
            self.assertIn(part, text)
        self.assertNotIn('shop.invalid', text)
        self.assertNotIn('10.0.0.1', text)
        login['allowed_requests'][0]['credential_fields'] = ['password']
        path.write_text(json.dumps({'actions': {'shop.session': login}}))
        self.vault.check_connectors()

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
