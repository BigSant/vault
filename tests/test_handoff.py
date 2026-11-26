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


class HandoffDirectoryTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self.tmp.name)/'handoff'
        self.dir.mkdir(mode=0o750)
        os.chmod(self.dir, 0o2750)
        self.env = patch.dict(os.environ, {'VAULT_HANDOFF_DIR': str(self.dir), 'VAULT_HANDOFF_GID': ''})
        self.env.start()
        self.oid = str(uuid.uuid4())

    def tearDown(self):
        self.env.stop()
        self.tmp.cleanup()

    def test_file_has_mode_0640_and_the_directory_group(self):
        handoff.write(self.oid, b'{"cookies":[]}')
        info = os.lstat(self.dir/(self.oid+'.json'))
        self.assertTrue(stat.S_ISREG(info.st_mode))
        self.assertEqual(0o640, stat.S_IMODE(info.st_mode))
        self.assertEqual(os.stat(self.dir).st_gid, info.st_gid)
        self.assertEqual(b'{"cookies":[]}', (self.dir/(self.oid+'.json')).read_bytes())

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
        with patch.dict(os.environ, {'VAULT_HANDOFF_DIR': str(link)}):
            for call in (lambda: handoff.write(self.oid, b'{}'), lambda: handoff.remove(self.oid), handoff.clean):
                with self.assertRaises(handoff.HandoffError):
                    call()
        self.assertEqual([], list(self.dir.iterdir()))

    def test_directory_open_to_others_or_group_writable_is_refused(self):
        for mode in (0o2755, 0o2770, 0o2751, 0o777):
            os.chmod(self.dir, mode)
            with self.assertRaises(handoff.HandoffError, msg=oct(mode)):
                handoff.write(self.oid, b'{}')
        os.chmod(self.dir, 0o2750)
        self.assertEqual([], list(self.dir.iterdir()))

    def test_configured_group_must_match(self):
        with patch.dict(os.environ, {'VAULT_HANDOFF_GID': str(os.stat(self.dir).st_gid + 1)}):
            with self.assertRaises(handoff.HandoffError):
                handoff.write(self.oid, b'{}')
        with patch.dict(os.environ, {'VAULT_HANDOFF_GID': str(os.stat(self.dir).st_gid)}):
            handoff.write(self.oid, b'{}')

    def test_only_a_canonical_operation_uuid_names_a_file(self):
        for name in (self.oid.upper(), '../'+self.oid, self.oid+'/../x', 'x', '', self.oid.replace('-', ''), '{'+self.oid+'}'):
            with self.assertRaises(handoff.HandoffError, msg=name):
                handoff.write(name, b'{}')
            with self.assertRaises(handoff.HandoffError, msg=name):
                handoff.remove(name)
        self.assertEqual([], list(self.dir.iterdir()))

    def test_oversized_content_is_refused(self):
        with self.assertRaises(handoff.HandoffError):
            handoff.write(self.oid, b'x'*(handoff.MAX_BYTES+1))
        self.assertEqual([], list(self.dir.iterdir()))

    def test_removed_file_is_gone_and_a_second_removal_is_enoent(self):
        handoff.write(self.oid, b'{}')
        self.assertTrue(handoff.remove(self.oid))
        self.assertFalse((self.dir/(self.oid+'.json')).exists())
        with self.assertRaises(FileNotFoundError):
            open(self.dir/(self.oid+'.json'))
        self.assertFalse(handoff.remove(self.oid))

    def test_clean_removes_every_file_and_names_the_operations(self):
        other = str(uuid.uuid4())
        handoff.write(self.oid, b'{}')
        handoff.write(other, b'{}')
        (self.dir/'stray.tmp').write_bytes(b'x')
        (self.dir/'dangling.json').symlink_to(Path(self.tmp.name)/'missing')
        (self.dir/'sub').mkdir()
        self.assertEqual(sorted([self.oid, other]), sorted(handoff.clean()))
        self.assertEqual(['sub'], [p.name for p in self.dir.iterdir()])


if __name__ == '__main__':
    unittest.main()
