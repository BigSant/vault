"""Consistent encrypted bundles, isolated restore verification, fail-closed empty-target restore."""
import fcntl
import json
import os
from pathlib import Path
import secrets
import subprocess
import tarfile
import tempfile
import time


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
        self.run(['docker','run','--rm','--network','none','--read-only','--cap-drop','ALL',
            '--security-opt','no-new-privileges:true','--user',f'{os.getuid()}:{os.getgid()}',
            '-v',str(self.root/'scripts/crypt.php')+':/crypt.php:ro','-v',str(key.resolve())+':/key:ro',
            '-v',str(Path(source).resolve())+':/input:ro','-v',str(Path(target).resolve().parent)+':/output',
            '--entrypoint','php',image,'/crypt.php',mode,'/key','/input','/output/'+Path(target).name])

    def backup(self):
        lock=open(self.data/'.backup.lock','a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        active=self.sql("SELECT COUNT(*) FROM operations WHERE state IN ('pending','approved','running','requires_user_action') AND expires_at>UTC_TIMESTAMP()",self.dbname())
        if int(active):raise RuntimeError('Active operations must finish before backup')
        if subprocess.run(['docker','exec','-w','/',self.php,'test','-f','/var/www/html/storage/framework/maintenance.php'],capture_output=True).returncode==0:
            raise RuntimeError('Application is already in maintenance')
        directory=self.root/'backups';directory.mkdir(mode=0o700,exist_ok=True)
        target=directory/(self.env+'-'+time.strftime('%Y%m%dT%H%M%SZ',time.gmtime())+'-'+secrets.token_hex(3)+'.vault')
        down=False;stopped=False
        try:
            self.run(['docker','exec','-w','/var/www/html',self.php,'php','artisan','down']);down=True
            stopped=True;self.run(['docker','stop',self.php,self.broker])
            active=self.sql("SELECT COUNT(*) FROM operations WHERE state IN ('pending','approved','running','requires_user_action') AND expires_at>UTC_TIMESTAMP()",self.dbname())
            if int(active):raise RuntimeError('An operation started while quiescing; retry after it finishes')
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
            if stopped:self.run(['docker','start',self.php,self.broker])
            if down:self.run(['docker','exec','-w','/var/www/html',self.php,'php','artisan','up'])
            lock.close()
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
        lock=open(self.data/'.backup.lock','a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
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
            self.run(['docker','start',self.php,self.broker])
            self.run(['docker','exec','-w','/var/www/html',self.php,'php','artisan','up'])
        lock.close()
        print('Backup restored. Owner login retained; sessions and machine grants revoked. Reissue machine access in WEB.')

    def invalidate_restored_access(self, database):
        self.sql("DELETE FROM sessions; DELETE FROM cache; DELETE FROM cache_locks; "
                 "UPDATE machines SET revoked_at=UTC_TIMESTAMP(); UPDATE grants SET revoked_at=UTC_TIMESTAMP(); "
                 "UPDATE operations SET state='revoked' WHERE state IN ('pending','approved','running','requires_user_action');",database)
