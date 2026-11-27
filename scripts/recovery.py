"""Consistent encrypted bundles, isolated restore verification, fail-closed empty-target restore."""
from contextlib import contextmanager
import fcntl
import json
import os
import signal
import threading
from pathlib import Path
import secrets
import subprocess
import tarfile
import tempfile
import time


class Busy(RuntimeError):
    """Try again later: operations are active or another backup runs. bin/vault exits 75 (EX_TEMPFAIL)."""
    exit_code=75


# How long backup-resume (the unit's ExecStopPost) waits for the backup lock: well under the unit's TimeoutStopSec.
RESUME_WAIT = 300
ACTIVE = "SELECT COUNT(*) FROM operations WHERE state IN ('pending','approved','running','requires_user_action') AND expires_at>UTC_TIMESTAMP()"
# Laravel's file maintenance driver: `down` writes framework/down, then maintenance.php; `up` removes them in that order.
MAINTENANCE_FILES = ('storage/framework/down', 'storage/framework/maintenance.php')


@contextmanager
def sigterm_held():
    """While the services are restored, a SIGTERM (systemd's stop, a second one) is held, not acted on: the restore
    finishes, then the process exits 143. Only in the main thread (where bin/vault set its handler)."""
    if threading.current_thread() is not threading.main_thread():
        yield
        return
    received = []
    previous = signal.signal(signal.SIGTERM, lambda *_: received.append(1))
    try:
        yield
    finally:
        signal.signal(signal.SIGTERM, previous)
        if received:
            raise SystemExit(143)


class Recovery:
    MEMBERS = {'manifest.json','database.sql','openbao.snap','app.key','app-bao.json','broker-bao.json','broker.key','connectors.json'}

    def __init__(self, context):
        self.c=context
        self.root=context['ROOT']; self.data=context['DATA']; self.env=context['ENV']; self.recovery=context['RECOVERY']
        self.php=f'vault-{self.env}-php-fpm'; self.mysql=f'vault-{self.env}-mysql-8.4'; self.broker=f'vault-{self.env}-broker'

    def run(self, args, data=None):
        result=subprocess.run(args,input=data,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if result.returncode: raise RuntimeError('Recovery subprocess failed: '+' '.join(args[:9]))
        return result.stdout

    def sql(self, statement, database=None):
        args=['docker','exec','-i','-w','/',self.mysql,'sh','-c',
              'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -u root --batch --skip-column-names "$@"','sh']
        if database:args.append(database)
        return self.run(args,statement.encode())

    def dbname(self):
        # Read only the database NAME; no operator credential leaves its existing container.
        return self.run(['docker','exec','-w','/',self.mysql,'sh','-c','printf %s "$MYSQL_DATABASE"']).decode()

    def crypt(self, mode, source, target):
        key=self.recovery/'backup.key'
        if not key.exists():
            if mode!='encrypt':raise RuntimeError('Original backup key required')
            fd=os.open(key,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
            with os.fdopen(fd,'wb') as f:f.write(secrets.token_bytes(32));f.flush();os.fsync(f.fileno())
        image=self.run(['docker','inspect',self.php,'--format','{{.Image}}']).decode().strip()
        target=Path(target).resolve();partial=target.with_name(target.name+'.partial-'+secrets.token_hex(6))
        try:
            self.run(['docker','run','--rm','--network','none','--read-only','--cap-drop','ALL',
                '--security-opt','no-new-privileges:true','--user',f'{os.getuid()}:{os.getgid()}',
                '-v',str(self.root/'scripts/crypt.php')+':/crypt.php:ro','-v',str(key.resolve())+':/key:ro',
                '-v',str(Path(source).resolve())+':/input:ro','-v',str(Path(target).resolve().parent)+':/output',
                '--entrypoint','php',image,'/crypt.php',mode,'/key','/input','/output/'+partial.name])
            os.link(partial,target)  # atomically publish without overwriting an existing result
            directory=os.open(target.parent,os.O_DIRECTORY)
            try:os.fsync(directory)
            finally:os.close(directory)
        finally:
            partial.unlink(missing_ok=True)

    def lock(self, wait=0):
        """The backup lock (`.backup.lock`), waiting at most `wait` seconds. Busy when another backup or restore still
        holds it then."""
        lock=open(self.data/'.backup.lock','a')
        deadline=time.monotonic()+wait
        while True:
            try:
                fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
                return lock
            except BlockingIOError:
                if time.monotonic()>=deadline:
                    lock.close()
                    raise Busy('Another backup is running') from None
                time.sleep(1)

    def maintenance_files(self):
        """(down, maintenance.php) as the PHP container sees them. Raises when it cannot look (a `docker exec` error is
        never read as "not in maintenance")."""
        out=self.run(['docker','exec','-w','/var/www/html',self.php,'sh','-c',
                      'for f in "$@"; do if [ -e "$f" ]; then echo 1; else echo 0; fi; done','sh',*MAINTENANCE_FILES]).decode().split()
        if len(out)!=2 or not set(out)<={'0','1'}:
            raise RuntimeError('Could not read the maintenance state')
        return out[0]=='1',out[1]=='1'

    def in_maintenance(self):
        return any(self.maintenance_files())

    def active_operations(self):
        return int(self.sql(ACTIVE,self.dbname()))

    def running(self):
        """Whether PHP and the broker both run (docker inspect)."""
        try:states=self.run(['docker','inspect','-f','{{.State.Running}}',self.php,self.broker]).decode().split()
        except RuntimeError:return False
        return states==['true','true']

    def restore_services(self, marker=None):
        """Undoes a quiesce, whatever step it was interrupted at: `docker stop` first (it waits for a stop still under
        way, and an `artisan down` still running in PHP ends with it), then `docker start`, `artisan up`, and a check
        that both containers run and maintenance is off. Only then is `marker` removed (also under the held SIGTERM, so
        that a second one cannot fall between the two); raises when the check fails, and the marker stays."""
        with sigterm_held():
            self.run(['docker','stop',self.php,self.broker])
            self.run(['docker','start',self.php,self.broker])
            self.run(['docker','exec','-w','/var/www/html',self.php,'php','artisan','up'])
            down,page=self.maintenance_files()
            if page and not down:
                # An `artisan up` cut between its two steps leaves maintenance.php; a repeated `up` sees the
                # application up and leaves it too, and every request would still get the maintenance page.
                self.run(['docker','exec','-w','/var/www/html',self.php,'rm','-f',MAINTENANCE_FILES[1]])
            if not self.running() or self.in_maintenance():
                raise RuntimeError('PHP or the broker is not running, or maintenance is still on, after restoring a backup; .backup-quiesced is kept for backup-resume')
            if marker is not None:
                marker.unlink(missing_ok=True)

    def resume(self):
        """After a backup that was killed (systemd timeout, SIGKILL): PHP and the broker run again and maintenance is
        lifted. Does nothing unless a backup left its marker, so it never lifts a maintenance someone else set, and
        nothing while another backup holds the lock (that backup finishes the marker's restore first, below). The
        marker goes only after the restore was verified."""
        try:lock=self.lock(RESUME_WAIT)
        except Busy:
            print('Another backup is still running after %d s; it restores the services of the interrupted one before its own.'%RESUME_WAIT)
            return False
        try:
            quiesced=self.data/'.backup-quiesced'
            if not quiesced.exists():
                return False
            self.restore_services(quiesced)
            print('Services resumed after an interrupted backup.')
            return True
        finally:
            lock.close()

    def backup(self):
        lock=self.lock()
        try:
            quiesced=self.data/'.backup-quiesced'
            # Before anything is stopped: services brought up by hand with the marker forgotten may hold a live lease.
            if self.active_operations():raise Busy('Active operations must finish before backup')
            if quiesced.exists():
                # An earlier backup was interrupted and its resume did not get the lock (this one had it): finish its
                # restore under this lock before anything else.
                self.restore_services(quiesced)
                print('Services of an interrupted backup restored first.')
            return self.quiesced_backup()
        finally:lock.close()

    def quiesced_backup(self):
        if self.active_operations():raise Busy('Active operations must finish before backup')
        if self.in_maintenance():
            raise RuntimeError('Application is already in maintenance')
        directory=self.root/'backups';directory.mkdir(mode=0o700,exist_ok=True)
        target=directory/(self.env+'-'+time.strftime('%Y%m%dT%H%M%SZ',time.gmtime())+'-'+secrets.token_hex(3)+'.vault')
        # A marker for `bin/vault backup-resume` (the unit's ExecStopPost) and the next backup: if this process is
        # killed before its finally has restored and verified the services, they finish it.
        quiesced=self.data/'.backup-quiesced'
        marked=False
        try:
            quiesced.write_text('1')
            # From here on the finally restores, whichever step a SIGTERM interrupts (restore_services starts with
            # `docker stop`, which also waits for an interrupted one).
            marked=True
            self.run(['docker','exec','-w','/var/www/html',self.php,'php','artisan','down'])
            self.run(['docker','stop',self.php,self.broker])
            if self.active_operations():raise Busy('An operation started while quiescing; retry after it finishes')
            with tempfile.TemporaryDirectory(prefix='vault-backup-') as tmp:
                tmp=Path(tmp)
                sql=self.run(['docker','exec','-w','/',self.mysql,'sh','-c',
                    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -u root --single-transaction --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF "$MYSQL_DATABASE"'])
                (tmp/'database.sql').write_bytes(sql)
                token=json.loads((self.recovery/'openbao.json').read_text())['root_token']
                self.c['bao'](['operator','raft','snapshot','save','/tmp/vault-backup.snap'],token=token)
                (tmp/'openbao.snap').write_bytes(self.run(['docker','exec','-w','/',self.c['CONTAINER'],'cat','/tmp/vault-backup.snap']))
                self.run(['docker','exec','-w','/',self.c['CONTAINER'],'rm','/tmp/vault-backup.snap'])
                (tmp/'manifest.json').write_text(json.dumps({'format':1,'environment':self.env,'created_at':time.time()}))
                files={'app.key':'security/app/app.key','app-bao.json':'security/app/bao.json','broker-bao.json':'security/broker/bao.json','broker.key':'security/app/broker.key','connectors.json':'connectors/connectors.json'}
                for name,path in files.items():(tmp/name).write_bytes((self.data/path).read_bytes())
                archive=tmp/'bundle.tar'
                with tarfile.open(archive,'w') as tar:
                    for name in sorted(self.MEMBERS):tar.add(tmp/name,arcname=name,recursive=False)
                self.crypt('encrypt',archive,target)
        finally:
            if marked:
                # The marker goes only after a verified restore: otherwise it stays for backup-resume.
                self.restore_services(quiesced)
        print('Encrypted consistent backup:',target)
        print('Keep backup.key AND OpenBao recovery shares separately from the backup.')
        return target

    def unpack(self, source, target):
        archive=target/'bundle.tar';self.crypt('decrypt',source,archive)
        with tarfile.open(archive,'r') as tar:
            members=tar.getmembers()
            if {m.name for m in members}!=self.MEMBERS or len(members)!=len(self.MEMBERS):raise RuntimeError('Unexpected backup members')
            if any(not m.isfile() or m.size>1024*1024*1024 for m in members):raise RuntimeError('Invalid backup member')
            tar.extractall(target,filter='data')
        manifest=json.loads((target/'manifest.json').read_text())
        if manifest.get('format')!=1 or manifest.get('environment')!=self.env:raise RuntimeError('Backup belongs to a different environment')
        return json.loads((self.recovery/'openbao.json').read_text())

    def verify(self, source):
        name='vault-restore-'+secrets.token_hex(5); database='vault_restore_'+secrets.token_hex(5);started=False
        with tempfile.TemporaryDirectory(prefix='vault-restore-') as tmp:
            tmp=Path(tmp);material=self.unpack(Path(source),tmp)
            (tmp/'raft').mkdir();(tmp/'config').mkdir()
            config=(self.root/'app/config/openbao/server.hcl').read_text().replace('http://openbao:','http://127.0.0.1:')
            (tmp/'config/server.hcl').write_text(config)
            try:
                self.sql('CREATE DATABASE `'+database+'`')
                self.sql((tmp/'database.sql').read_text(),database)
                self.invalidate_restored_access(database)
                if int(self.sql('SELECT COUNT(*) FROM machines WHERE revoked_at IS NULL',database)):raise RuntimeError('Restored access was not revoked')
                self.run(['docker','run','-d','--name',name,'--network','none','--read-only','--cap-drop','ALL',
                    '--security-opt','no-new-privileges:true','--tmpfs','/tmp','--user',f'{os.getuid()}:{os.getgid()}',
                    '-e','BAO_ADDR=http://127.0.0.1:8200','-v',str(tmp/'raft')+':/bao/data','-v',str(tmp/'config')+':/bao/config:ro',
                    '--entrypoint','bao','openbao/openbao:2.7.2','server','-config=/bao/config/server.hcl']);started=True
                def call(args,payload=None,token='',acceptable=(0,)):
                    return self.c['bao'](args,payload,token,acceptable,container=name)
                for attempt in range(30):
                    try:call(['status','-format=json'],acceptable=(0,2));break
                    except RuntimeError:time.sleep(.2)
                initial=json.loads(call(['operator','init','-format=json','-key-shares=1','-key-threshold=1']))
                call(['write','sys/unseal','-'],{'key':initial['unseal_keys_b64'][0]})
                self.run(['docker','exec','-i','-w','/',name,'sh','-c','umask 077; cat > /tmp/restore.snap'],(tmp/'openbao.snap').read_bytes())
                call(['operator','raft','snapshot','restore','-force','/tmp/restore.snap'],token=initial['root_token'])
                for share in material['unseal_keys_b64'][:2]:call(['write','sys/unseal','-'],{'key':share})
                rows=self.sql("SELECT id,version FROM entries WHERE state!='destroyed'",database).decode().splitlines()
                for row in rows:
                    entry,version=row.split('\t')
                    reply=json.loads(call(['kv','get','-format=json','-mount=secret','-version='+str(int(version)),'entries/'+entry],token=material['root_token']))
                    if reply['data']['metadata']['version']!=int(version):raise RuntimeError('Version mismatch')
                users=self.sql('SELECT id FROM users',database).decode().splitlines()
                for user in users:call(['kv','get','-format=json','-mount=secret','auth/'+user],token=material['root_token'])
                print('Restore verified in isolated OpenBao and temporary SQL database;',len(rows),'entry versions and',len(users),'MFA records readable. Live data unchanged.')
            finally:
                if started:subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
                self.sql('DROP DATABASE IF EXISTS `'+database+'`')

    def restore(self, source):
        # Never overwrite a live installation, even with a force flag. Provision empty
        # containers first and copy ORIGINAL offline recovery material separately.
        lock=self.lock()
        with tempfile.TemporaryDirectory(prefix='vault-import-') as tmp:
            tmp=Path(tmp);material=self.unpack(Path(source),tmp)
            tables=self.sql('SHOW TABLES',self.dbname())
            status=json.loads(self.c['bao'](['status','-format=json'],acceptable=(0,2)))
            if tables.strip() or status['initialized']:
                raise RuntimeError('Restore requires an empty SQL database and uninitialized OpenBao; existing installation refused')
            self.run(['docker','stop',self.php,self.broker])
            # A failure leaves the target stopped for inspection, never partially live.
            self.sql((tmp/'database.sql').read_text(),self.dbname())
            self.invalidate_restored_access(self.dbname())
            initial=json.loads(self.c['bao'](['operator','init','-format=json','-key-shares=1','-key-threshold=1']))
            self.c['bao'](['write','sys/unseal','-'],{'key':initial['unseal_keys_b64'][0]})
            self.run(['docker','exec','-i','-w','/',self.c['CONTAINER'],'sh','-c','umask 077; cat > /tmp/restore.snap'],(tmp/'openbao.snap').read_bytes())
            self.c['bao'](['operator','raft','snapshot','restore','-force','/tmp/restore.snap'],token=initial['root_token'])
            for share in material['unseal_keys_b64'][:2]:self.c['bao'](['write','sys/unseal','-'],{'key':share})
            self.run(['docker','exec','-w','/',self.c['CONTAINER'],'rm','/tmp/restore.snap'])
            files={'app.key':'security/app/app.key','app-bao.json':'security/app/bao.json','broker-bao.json':'security/broker/bao.json',
                   'broker.key':'security/app/broker.key','connectors.json':'connectors/connectors.json'}
            for name,path in files.items():self.c['save'](self.data/path,(tmp/name).read_text())
            self.c['save'](self.data/'security/broker/broker.key',(tmp/'broker.key').read_text())
            # A backup of an older version has an older schema: this checkout's migrations bring it up to date before
            # the application is opened and before the broker starts (the identities page and vault:links read columns
            # an old schema lacks). PHP starts in maintenance; nobody is signed in (sessions were cleared above).
            self.run(['docker','start',self.php])
            self.run(['docker','exec','-w','/var/www/html',self.php,'php','artisan','down'])
            self.run(['docker','exec','-w','/var/www/html',self.php,'php','artisan','migrate','--force'])
            self.check_schema(self.dbname())
            self.run(['docker','start',self.broker])
            self.run(['docker','exec','-w','/var/www/html',self.php,'php','artisan','up'])
            self.warn_connectors(tmp/'connectors.json')
        lock.close()
        print('Backup restored. Owner login retained; sessions and machine grants revoked. Reissue machine access in WEB.')

    def check_schema(self, database):
        """The queries of the identities page and of vault:links work on the restored schema (raises otherwise)."""
        self.sql('SELECT id,name,expires_at,revoked_at,links,executor FROM machines LIMIT 1; '
                 'SELECT id,handoff,lease_expires_at,dispatched_at FROM operations LIMIT 1;',database)

    def warn_connectors(self, path):
        """A restored connectors.json of an older version may lack what this broker requires: said, never fatal."""
        import sys
        sys.path.insert(0,str(self.root/'broker'))
        try:
            import connector_check
            found=connector_check.problems(json.loads(Path(path).read_text()).get('actions',{}),('http','https') if self.env=='test' else ('https',))
        except Exception:
            found=['connectors.json could not be checked']
        for line in found:print('Update connectors.json:',line)

    def invalidate_restored_access(self, database):
        self.sql("DELETE FROM sessions; DELETE FROM cache; DELETE FROM cache_locks; "
                 "UPDATE machines SET revoked_at=UTC_TIMESTAMP(); UPDATE grants SET revoked_at=UTC_TIMESTAMP(); "
                 "UPDATE operations SET state='revoked' WHERE state IN ('pending','approved','running','requires_user_action');",database)
        # A restored host has no handoff file of the old one: nothing may keep an entry reserved for it. A backup made
        # before handoffs existed has no such column (and no such file): nothing to do.
        if int(self.sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='operations' AND COLUMN_NAME='handoff'",database) or 0):
            self.sql("UPDATE operations SET handoff='removed' WHERE handoff='written';",database)
