import errno
import os
from pathlib import Path
import stat
import sys
import tempfile
import unittest
from unittest.mock import patch
import uuid

sys.path.insert(0, str(Path(__file__).parents[1]/'broker'))
import handoff


def failing(name, at=1, code=errno.ENOSPC):
    """Wraps os.<name> so that its at-th call raises OSError(code)."""
    real = getattr(os, name)
    calls = {'n': 0}

    def wrapper(*args, **kwargs):
        calls['n'] += 1
        if calls['n'] == at:
            raise OSError(code, os.strerror(code))
        return real(*args, **kwargs)
    return patch.object(handoff.os, name, wrapper)


class HandoffDirectoryTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self.tmp.name)/'handoff'
        self.dir.mkdir(mode=0o700)
        os.chmod(self.dir, 0o2710)
        (self.dir/handoff.MARKER).write_bytes(b'')
        self.env = patch.dict(os.environ, {'VAULT_HANDOFF_DIR': str(self.dir), 'VAULT_HANDOFF_GID': ''})
        self.env.start()
        self.oid = str(uuid.uuid4())

    def tearDown(self):
        self.env.stop()
        for path in self.dir.iterdir() if self.dir.exists() else []:
            os.chmod(path, 0o600) if not path.is_symlink() and not path.is_dir() else None
        os.chmod(self.dir, 0o700) if self.dir.exists() else None
        self.tmp.cleanup()

    def files(self):
        return sorted(p.name for p in self.dir.iterdir() if p.name != handoff.MARKER)

    def test_file_has_mode_0640_and_the_directory_group(self):
        handoff.write(self.oid, b'{"cookies":[]}')
        info = os.lstat(self.dir/(self.oid+'.json'))
        self.assertTrue(stat.S_ISREG(info.st_mode))
        self.assertEqual(0o640, stat.S_IMODE(info.st_mode))
        self.assertEqual(os.stat(self.dir).st_gid, info.st_gid)
        self.assertEqual(b'{"cookies":[]}', (self.dir/(self.oid+'.json')).read_bytes())

    def test_file_is_private_and_complete_until_published(self):
        pending = handoff.create(self.oid, b'{"cookies":[1]}')
        info = os.lstat(self.dir/(self.oid+'.json'))
        self.assertEqual(0o600, stat.S_IMODE(info.st_mode), 'group-readable before the application recorded it')
        self.assertEqual(b'{"cookies":[1]}', (self.dir/(self.oid+'.json')).read_bytes())
        pending.publish()
        self.assertEqual(0o640, stat.S_IMODE(os.lstat(self.dir/(self.oid+'.json')).st_mode))

    def test_discarded_pending_file_was_never_group_readable(self):
        modes = []
        real = os.fchmod
        with patch.object(handoff.os, 'fchmod', lambda fd, mode: (modes.append(mode), real(fd, mode))[1]):
            handoff.create(self.oid, b'{}').discard()
        self.assertEqual([0o600], modes)
        self.assertEqual([], self.files())

    def test_umask_does_not_weaken_or_widen_the_mode(self):
        old = os.umask(0o077)
        try:
            handoff.write(self.oid, b'{}')
        finally:
            os.umask(old)
        self.assertEqual(0o640, stat.S_IMODE(os.lstat(self.dir/(self.oid+'.json')).st_mode))

    def test_existing_file_is_never_replaced(self):
        target = self.dir/(self.oid+'.json')
        target.write_bytes(b'planted')
        with self.assertRaises(handoff.HandoffError):
            handoff.write(self.oid, b'{}')
        self.assertEqual(b'planted', target.read_bytes())

    def test_symlink_in_place_of_the_file_is_refused_and_its_target_untouched(self):
        outside = Path(self.tmp.name)/'outside'
        outside.write_bytes(b'keep')
        (self.dir/(self.oid+'.json')).symlink_to(outside)
        with self.assertRaises(handoff.HandoffError):
            handoff.write(self.oid, b'{}')
        self.assertEqual(b'keep', outside.read_bytes())

    def test_symlink_in_place_of_the_directory_is_refused(self):
        link = Path(self.tmp.name)/'link'
        link.symlink_to(self.dir)
        handoff.write(self.oid, b'{}')
        with patch.dict(os.environ, {'VAULT_HANDOFF_DIR': str(link)}):
            for call in (lambda: handoff.write(str(uuid.uuid4()), b'{}'), lambda: handoff.remove(self.oid), handoff.clean):
                with self.assertRaises(handoff.HandoffError):
                    call()
        self.assertEqual([self.oid+'.json'], self.files())

    def test_directory_listable_by_the_group_open_to_others_or_group_writable_is_refused(self):
        for mode in (0o2750, 0o2755, 0o2770, 0o2730, 0o2711, 0o777):
            os.chmod(self.dir, mode)
            with self.assertRaises(handoff.HandoffError, msg=oct(mode)):
                handoff.write(self.oid, b'{}')
        for mode in (0o2710, 0o700, 0o710):
            os.chmod(self.dir, mode)
            handoff.write(str(uuid.uuid4()), b'{}')

    def test_directory_without_the_marker_is_never_written_or_cleaned(self):
        (self.dir/handoff.MARKER).unlink()
        keep = self.dir/(self.oid+'.json')
        keep.write_bytes(b'key material of another program')
        with self.assertRaises(handoff.HandoffError):
            handoff.write(str(uuid.uuid4()), b'{}')
        with self.assertRaises(handoff.HandoffError):
            handoff.clean()
        self.assertTrue(keep.exists())
        # A marker that is a symlink does not count.
        (self.dir/handoff.MARKER).symlink_to(Path(self.tmp.name)/'elsewhere')
        with self.assertRaises(handoff.HandoffError):
            handoff.clean()

    def test_configured_group_must_match(self):
        with patch.dict(os.environ, {'VAULT_HANDOFF_GID': str(os.stat(self.dir).st_gid + 1)}):
            with self.assertRaises(handoff.HandoffError):
                handoff.write(self.oid, b'{}')
        with patch.dict(os.environ, {'VAULT_HANDOFF_GID': str(os.stat(self.dir).st_gid)}):
            handoff.write(self.oid, b'{}')

    def test_file_gets_the_directory_group_through_fchown(self):
        others = [g for g in os.getgroups() if g != os.getgid()]
        if not others:
            self.skipTest('needs a supplementary group (docker run --group-add)')
        os.chmod(self.dir, 0o710)  # no setgid bit: the file would get the primary group without fchown
        os.chown(self.dir, -1, others[0])
        with patch.dict(os.environ, {'VAULT_HANDOFF_GID': str(others[0])}):
            handoff.write(self.oid, b'{}')
        self.assertEqual(others[0], os.lstat(self.dir/(self.oid+'.json')).st_gid)

    def test_failed_fchown_leaves_no_file(self):
        real = os.fstat

        def other_group(fd):
            info = real(fd)
            if not stat.S_ISREG(info.st_mode):
                return info
            fields = list(info)
            fields[stat.ST_GID] = info.st_gid + 1
            return os.stat_result(fields)
        with patch.object(handoff.os, 'fstat', other_group), failing('fchown', code=errno.EPERM):
            with self.assertRaises(handoff.HandoffError):
                handoff.write(self.oid, b'{}')
        self.assertEqual([], self.files())

    def test_enospc_fsync_and_short_writes_leave_no_file(self):
        for name, at in (('write', 1), ('fsync', 1), ('fsync', 2)):
            with failing(name, at), self.assertRaises(handoff.HandoffError, msg=(name, at)):
                handoff.write(self.oid, b'x' * 70000)
            self.assertEqual([], self.files(), (name, at))
        real = os.write
        with patch.object(handoff.os, 'write', lambda fd, data: real(fd, bytes(data[:1000]))):
            handoff.write(self.oid, b'y' * 70000)
        self.assertEqual(b'y' * 70000, (self.dir/(self.oid+'.json')).read_bytes())

    def test_failed_publish_removes_the_private_file(self):
        pending = handoff.create(self.oid, b'{}')
        with failing('fchmod', code=errno.EIO), self.assertRaises(handoff.HandoffError):
            pending.publish()
        self.assertEqual([], self.files())

    def test_only_a_canonical_operation_uuid_names_a_file(self):
        for name in (self.oid.upper(), '../'+self.oid, self.oid+'/../x', 'x', '', self.oid.replace('-', ''), '{'+self.oid+'}'):
            with self.assertRaises(handoff.HandoffError, msg=name):
                handoff.write(name, b'{}')
            with self.assertRaises(handoff.HandoffError, msg=name):
                handoff.remove(name)
        self.assertEqual([], self.files())

    def test_oversized_content_is_refused(self):
        with self.assertRaises(handoff.HandoffError):
            handoff.write(self.oid, b'x'*(handoff.MAX_BYTES+1))
        self.assertEqual([], self.files())

    def test_removed_file_is_gone_and_a_second_removal_is_enoent(self):
        handoff.write(self.oid, b'{}')
        self.assertTrue(handoff.remove(self.oid))
        with self.assertRaises(FileNotFoundError):
            open(self.dir/(self.oid+'.json'))
        self.assertTrue(handoff.remove(self.oid))

    def test_removal_works_after_the_directory_was_widened_or_regrouped(self):
        # The file must go exactly when the directory became more readable.
        handoff.write(self.oid, b'{}')
        os.chmod(self.dir, 0o2755)
        self.assertTrue(handoff.remove(self.oid))
        self.assertEqual([], self.files())

    def test_failed_unlink_is_not_a_removal(self):
        handoff.write(self.oid, b'{}')
        with failing('unlink', code=errno.EIO), self.assertRaises(handoff.HandoffError):
            handoff.remove(self.oid)
        self.assertEqual([self.oid+'.json'], self.files())

    def test_clean_removes_only_operation_files_and_names_the_operations(self):
        other = str(uuid.uuid4())
        handoff.write(self.oid, b'{}')
        handoff.write(other, b'{}')
        for name in ('stray.tmp', 'broker.key', 'bao.json', self.oid.upper()+'.json'):
            (self.dir/name).write_bytes(b'x')
        (self.dir/(str(uuid.uuid4())+'.json')).symlink_to(Path(self.tmp.name)/'missing')
        (self.dir/(str(uuid.uuid4())+'.json')).mkdir()
        left, complete = handoff.clean()
        self.assertTrue(complete)
        self.assertLessEqual({self.oid, other}, set(left))
        remaining = self.files()
        for name in ('stray.tmp', 'broker.key', 'bao.json', self.oid.upper()+'.json'):
            self.assertIn(name, remaining)
        self.assertNotIn(self.oid+'.json', remaining)
        self.assertNotIn(other+'.json', remaining)

    def test_a_file_clean_cannot_remove_is_made_unreadable(self):
        handoff.write(self.oid, b'{}')
        self.assertEqual(handoff.MODE, stat.S_IMODE(os.lstat(self.dir/(self.oid+'.json')).st_mode))
        # A symlink of that name is never followed (its target is left alone).
        target = Path(self.tmp.name)/'outside'
        target.write_text('x')
        os.chmod(target, 0o644)
        other = str(uuid.uuid4())
        os.symlink(target, self.dir/(other+'.json'))
        real = os.unlink

        def unlink(name, *a, **k):
            if str(name).endswith('.json'):
                raise PermissionError()
            return real(name, *a, **k)
        with patch.object(handoff.os, 'unlink', unlink):
            left, complete = handoff.clean()
        self.assertFalse(complete)
        self.assertEqual(0, stat.S_IMODE(os.lstat(self.dir/(self.oid+'.json')).st_mode))
        self.assertEqual(0o644, stat.S_IMODE(os.stat(target).st_mode))

    def test_clean_reports_an_incomplete_cleanup(self):
        handoff.write(self.oid, b'{}')
        with failing('unlink', code=errno.EIO):
            left, complete = handoff.clean()
        self.assertFalse(complete)
        self.assertEqual([], left)


if __name__ == '__main__':
    unittest.main()
