"""Handoff directory: one file per operation, read by a trusted executor on the same host.

Shared by every connector kind that leaves something for the executor (today only storage_state). This module never
looks at what the bytes mean; the caller decides what may be written. The directory is mounted only into the broker;
the executor reads it through its group. Nothing here logs or returns a path or a value.
"""
import os
import stat
import uuid

MODE = 0o640
MAX_BYTES = 262144


class HandoffError(Exception):
    """The directory or the file is not what it must be. Carries no path or value."""


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


def _directory_fd(path=None):
    """Opens the directory without following a symlink and checks it: a real directory owned by this user, nobody
    but this user may write it, others have no access, and (when configured) its group is the executor's."""
    path = path or directory()
    try:
        before = os.lstat(path)
        if not stat.S_ISDIR(before.st_mode):
            raise HandoffError()
        fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except OSError:
        raise HandoffError() from None
    try:
        info = os.fstat(fd)
        expected = os.environ.get('VAULT_HANDOFF_GID', '')
        if ((info.st_dev, info.st_ino) != (before.st_dev, before.st_ino) or not stat.S_ISDIR(info.st_mode)
                or info.st_uid != os.getuid() or info.st_mode & 0o027
                or (expected != '' and info.st_gid != int(expected))):
            raise HandoffError()
    except (HandoffError, ValueError):
        os.close(fd)
        raise HandoffError() from None
    return fd, info


def write(oid, data, path=None):
    """Creates `<oid>.json` with mode 0640 and the directory's group. Never replaces anything: an existing name or a
    symlink in its place fails (O_CREAT|O_EXCL|O_NOFOLLOW). A failed write removes what it created."""
    name = filename(oid)
    if not isinstance(data, bytes) or len(data) > MAX_BYTES:
        raise HandoffError()
    fd, info = _directory_fd(path)
    try:
        try:
            out = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, MODE, dir_fd=fd)
        except OSError:
            raise HandoffError() from None
        complete = False
        try:
            os.fchmod(out, MODE)
            if os.fstat(out).st_gid != info.st_gid:
                os.fchown(out, -1, info.st_gid)
            created = os.fstat(out)
            if not stat.S_ISREG(created.st_mode) or stat.S_IMODE(created.st_mode) != MODE or created.st_gid != info.st_gid or created.st_nlink != 1:
                raise HandoffError()
            view = memoryview(data)
            while view:
                view = view[os.write(out, view):]
            os.fsync(out)
            complete = True
        except OSError:
            raise HandoffError() from None
        finally:
            os.close(out)
            if not complete:
                try:
                    os.unlink(name, dir_fd=fd)
                except OSError:
                    pass
        os.fsync(fd)
    finally:
        os.close(fd)


def remove(oid, path=None):
    """Removes `<oid>.json`. True when it was there; False when it was already gone (ENOENT)."""
    name = filename(oid)
    fd, _ = _directory_fd(path)
    try:
        os.unlink(name, dir_fd=fd)
        os.fsync(fd)
        return True
    except FileNotFoundError:
        return False
    except OSError:
        raise HandoffError() from None
    finally:
        os.close(fd)


def clean(path=None):
    """At broker start: removes every non-directory entry (no operation survives a restart). Returns the operation
    IDs whose files were removed, so their owners can be told."""
    fd, _ = _directory_fd(path)
    removed = []
    try:
        with os.scandir(fd) as entries:
            names = [entry.name for entry in entries if not entry.is_dir(follow_symlinks=False)]
        for name in names:
            try:
                os.unlink(name, dir_fd=fd)
            except FileNotFoundError:
                continue
            stem = name[:-5] if name.endswith('.json') else ''
            try:
                filename(stem)
                removed.append(stem)
            except HandoffError:
                pass
        os.fsync(fd)
    finally:
        os.close(fd)
    return removed
