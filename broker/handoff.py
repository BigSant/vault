"""Handoff directory: one file per operation, read by a trusted executor on the same host.

Shared by every connector kind that leaves something for the executor (today only storage_state). This module never
looks at what the bytes mean; the caller decides what may be written. The directory is mounted only into the broker;
the executor reads it through its group. The directory is 2710 (the group may only search, not list), so a reader
must know the operation ID to open a file. Nothing here logs or returns a path or a value.

A file is created 0600 and becomes group-readable (0640) only through Pending.publish(), which the caller runs after
the application has recorded it and while the lease has time left. Until then nobody but the broker can open it; a
failure before publish removes it. A failure inside publish after the fchmod (the fsync) removes a file that was
group-readable for that moment.
"""
import json
import os
import re
import stat
import uuid

PRIVATE = 0o600
MODE = 0o640
MAX_BYTES = 262144
# A regular file of this name, owned by the broker, marks a directory as the handoff directory. Without it nothing
# is written and nothing is removed: a wrongly configured path never loses a file.
MARKER = '.vault-handoff'
NAME = re.compile(r'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.json')


class HandoffError(Exception):
    """The directory or the file is not what it must be. Carries no path or value."""


class Leftover(HandoffError):
    """create() failed and could not remove what it had created: the file may still be there (0600)."""


def directory():
    return os.environ.get('VAULT_HANDOFF_DIR', '/handoff')


def filename(oid):
    """`<operation-uuid>.json`, only for a canonical lowercase UUID: no other name is ever opened or removed."""
    try:
        canonical = str(uuid.UUID(str(oid)))
    except ValueError:
        raise HandoffError() from None
    if canonical != oid:
        raise HandoffError()
    return canonical + '.json'


def _open_directory(path=None):
    """Opens the directory itself (never a symlink in its place). Returns (fd, fstat)."""
    path = path or directory()
    try:
        before = os.lstat(path)
        if not stat.S_ISDIR(before.st_mode):
            raise HandoffError()
        fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except OSError:
        raise HandoffError() from None
    info = os.fstat(fd)
    if (info.st_dev, info.st_ino) != (before.st_dev, before.st_ino):
        os.close(fd)
        raise HandoffError()
    return fd, info


def _marked(fd):
    try:
        info = os.stat(MARKER, dir_fd=fd, follow_symlinks=False)
    except OSError:
        return False
    return stat.S_ISREG(info.st_mode) and info.st_uid == os.getuid()


def _directory_fd(path=None):
    """The directory for writing: a real directory owned by this user with the marker, nobody else may write or list
    it (group: search only, others: nothing), and (when configured) its group is the executor's."""
    fd, info = _open_directory(path)
    try:
        expected = os.environ.get('VAULT_HANDOFF_GID', '')
        if (info.st_uid != os.getuid() or info.st_mode & 0o067 or not _marked(fd)
                or (expected != '' and info.st_gid != int(expected))):
            raise HandoffError()
    except (HandoffError, ValueError):
        os.close(fd)
        raise HandoffError() from None
    return fd, info


class Pending:
    """A complete, fsynced file only the broker can read. publish() makes it 0640; discard() removes it."""

    def __init__(self, fd, out, name):
        self._fd, self._out, self._name = fd, out, name

    def publish(self):
        """0640 and fsync, then the directory is done with. On failure the file is removed (never left readable)."""
        try:
            os.fchmod(self._out, MODE)
            os.fsync(self._out)
            if stat.S_IMODE(os.fstat(self._out).st_mode) != MODE:
                raise HandoffError()
        except (OSError, HandoffError):
            self.discard()
            raise HandoffError() from None
        self._close()

    def discard(self):
        """Removes the unpublished file. True when it is gone (or never was), False when unlink failed: it is then
        made unreadable (mode 0) at once, also after a publish that had made it 0640."""
        try:
            try:
                os.unlink(self._name, dir_fd=self._fd)
            except FileNotFoundError:
                pass
            return True
        except OSError:
            try:
                os.fchmod(self._out, 0)
            except (OSError, TypeError):
                pass
            return False
        finally:
            self._close()

    def _close(self):
        for attr in ('_out', '_fd'):
            fd = getattr(self, attr)
            if fd is not None:
                setattr(self, attr, None)
                try:
                    os.close(fd)
                except OSError:
                    pass


def create(oid, data, path=None):
    """Creates `<oid>.json` with mode 0600 and the directory's group, writes and fsyncs it and the directory. Never
    replaces anything: an existing name or a symlink in its place fails (O_CREAT|O_EXCL|O_NOFOLLOW). Any failure
    removes what it created. Returns a Pending: the caller publishes it once the file is recorded."""
    name = filename(oid)
    if not isinstance(data, bytes) or len(data) > MAX_BYTES:
        raise HandoffError()
    fd, info = _directory_fd(path)
    try:
        out = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, PRIVATE, dir_fd=fd)
    except OSError:
        os.close(fd)
        raise HandoffError() from None
    pending = Pending(fd, out, name)
    try:
        os.fchmod(out, PRIVATE)
        if os.fstat(out).st_gid != info.st_gid:
            os.fchown(out, -1, info.st_gid)
        created = os.fstat(out)
        if (not stat.S_ISREG(created.st_mode) or stat.S_IMODE(created.st_mode) != PRIVATE or created.st_gid != info.st_gid
                or created.st_nlink != 1):
            raise HandoffError()
        view = memoryview(data)
        while view:
            view = view[os.write(out, view):]
        os.fsync(out)
        os.fsync(fd)
    except (OSError, HandoffError):
        if not pending.discard():
            raise Leftover() from None
        raise HandoffError() from None
    return pending


def write(oid, data, path=None):
    """create() and publish() at once (tests and tools; the broker publishes only after the application's record)."""
    create(oid, data, path).publish()


def remove(oid, path=None):
    """Removes `<oid>.json`. True when it was removed or already gone (ENOENT); raises when unlink failed. Needs only
    the directory itself (no symlink): a directory whose mode or group changed during a lease is still cleaned."""
    name = filename(oid)
    fd, _ = _open_directory(path)
    try:
        try:
            os.unlink(name, dir_fd=fd)
        except FileNotFoundError:
            return True
        except OSError:
            # Kept for the next attempt, but nobody may read it meanwhile.
            try:
                os.chmod(name, 0, dir_fd=fd)
            except OSError:
                pass
            raise HandoffError() from None
        try:
            os.fsync(fd)
        except OSError:
            pass
        return True
    finally:
        os.close(fd)


def clean(path=None):
    """At broker start: removes every `<operation-uuid>.json` (no operation survives a restart), nothing else. Refuses
    a directory without the marker (HandoffError). Returns (operation IDs whose files were removed, complete):
    complete is False when a file could not be removed; the caller retries and writes nothing until then."""
    fd, info = _open_directory(path)
    try:
        if info.st_uid != os.getuid() or not _marked(fd):
            raise HandoffError()
        with os.scandir(fd) as entries:
            names = [entry.name for entry in entries if NAME.fullmatch(entry.name) and not entry.is_dir(follow_symlinks=False)]
        removed, failed = [], False
        for name in names:
            try:
                os.unlink(name, dir_fd=fd)
            except FileNotFoundError:
                continue
            except OSError:
                failed = True
                # A session file of an earlier run must not stay readable until a later attempt removes it.
                try:
                    if stat.S_ISREG(os.stat(name, dir_fd=fd, follow_symlinks=False).st_mode):
                        os.chmod(name, 0, dir_fd=fd)
                except OSError:
                    pass
                continue
            removed.append(name[:-5])
        try:
            os.fsync(fd)
        except OSError:
            pass
        return removed, not failed
    finally:
        os.close(fd)


# The broker's undelivered final events (operation IDs, reasons, failure codes, when): never a value or session
# data. In the handoff directory, so it survives a broker restart; 0600 and not a <uuid>.json name, so clean() keeps
# it and the executor's group cannot open it. On the server the directory is under /run: a host reboot empties it, and
# the application settles those operations itself (vault:links).
JOURNAL = '.vault-undelivered'
JOURNAL_REASONS = frozenset({'release', 'ttl', 'revoke', 'restart', 'error'})


def save_journal(entries, path=None):
    """Replaces the journal with {operation id: {reason, since[, failure]}}: a new file, fsynced, renamed over the old."""
    data = json.dumps(entries, separators=(',', ':'), sort_keys=True).encode()
    fd, _ = _directory_fd(path)
    temporary = JOURNAL + '.tmp'
    try:
        try:
            os.unlink(temporary, dir_fd=fd)
        except FileNotFoundError:
            pass
        out = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, PRIVATE, dir_fd=fd)
        try:
            view = memoryview(data)
            while view:
                view = view[os.write(out, view):]
            os.fsync(out)
        finally:
            os.close(out)
        os.rename(temporary, JOURNAL, src_dir_fd=fd, dst_dir_fd=fd)
        os.fsync(fd)
    except OSError:
        raise HandoffError() from None
    finally:
        os.close(fd)


def load_journal(path=None):
    """The journal's valid entries ({} when there is none). An entry that is not a canonical operation ID with a
    known reason is skipped."""
    fd, _ = _directory_fd(path)
    try:
        try:
            handle = os.open(JOURNAL, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
        except FileNotFoundError:
            return {}
        except OSError:
            raise HandoffError() from None
        with os.fdopen(handle, 'rb') as stream:
            raw = stream.read(1 << 20)
    finally:
        os.close(fd)
    try:
        entries = json.loads(raw)
    except ValueError:
        return {}
    valid = {}
    for oid, entry in entries.items() if isinstance(entries, dict) else ():
        try:
            filename(oid)
        except HandoffError:
            continue
        if isinstance(entry, dict) and entry.get('reason') in JOURNAL_REASONS and isinstance(entry.get('since'), (int, float)):
            valid[oid] = {k: entry[k] for k in ('reason', 'since', 'failure') if k in entry and isinstance(entry[k], (str, int, float))}
    return valid
