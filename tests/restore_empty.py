#!/usr/bin/env python3
"""Exercise real empty-target restoration using disposable containers/SQL, never live targets."""
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
from recovery import Recovery
loader=importlib.machinery.SourceFileLoader('vault_operator',str(ROOT/'bin/vault'))
spec=importlib.util.spec_from_loader(loader.name,loader);operator=importlib.util.module_from_spec(spec);loader.exec_module(operator)

def run(args):
    result=subprocess.run(args,capture_output=True)
    if result.returncode:raise RuntimeError('Disposable recovery fixture failed')
    return result.stdout.decode().strip()


def main():
    os.umask(0o077)
    suffix=secrets.token_hex(5);prefix='vault-recovery-test-'+suffix
    database='vault_restore_'+suffix
    names=[prefix+'-php',prefix+'-broker',prefix+'-bao'];created=[]
    image=run(['docker','inspect','vault-test-php-fpm','--format','{{.Image}}'])
    recovery=None
    with tempfile.TemporaryDirectory(prefix=prefix)as tmp:
        tmp=Path(tmp);(tmp/'openbao').mkdir();(tmp/'security/app').mkdir(parents=True);(tmp/'security/broker').mkdir(parents=True);(tmp/'connectors').mkdir()
        config=(ROOT/'app/config/openbao/server.hcl').read_text().replace('http://openbao:','http://127.0.0.1:')
        (tmp/'server.hcl').write_text(config)
        context=dict(vars(operator));context.update(ROOT=ROOT,ENV='test',DATA=tmp,RECOVERY=Path.home()/'.config/vault/test',CONTAINER=names[2])
        context['bao']=lambda args,payload=None,token='',acceptable=(0,),container=None:operator.bao(args,payload,token,acceptable,container or names[2])
        recovery=Recovery(context);recovery.php=names[0];recovery.broker=names[1];recovery.mysql='vault-test-mysql-8.4';recovery.dbname=lambda:database
        try:
            recovery.sql('CREATE DATABASE `'+database+'`')
            for name in names[:2]:
                args=['docker','run','-d','--name',name,'--network','none','--user',f'{os.getuid()}:{os.getgid()}',
                      '-v',str(ROOT/'app/.generated/test/app')+':/var/www/html:ro','--entrypoint','sleep',image,'infinity']
                run(args);created.append(name)
            run(['docker','run','-d','--name',names[2],'--network','none','--cap-drop','ALL','--read-only','--tmpfs','/tmp',
                 '--user',f'{os.getuid()}:{os.getgid()}','-e','BAO_ADDR=http://127.0.0.1:8200','-v',str(tmp/'openbao')+':/bao/data',
                 '-v',str(tmp/'server.hcl')+':/bao/config/server.hcl:ro','--entrypoint','bao','openbao/openbao:2.7.2','server','-config=/bao/config/server.hcl']);created.append(names[2])
            import time
            for _ in range(30):
                try:context['bao'](['status','-format=json'],acceptable=(0,2));break
                except RuntimeError:time.sleep(.2)
            recovery.restore(sys.argv[1])
            assert int(recovery.sql('SELECT COUNT(*) FROM users',database))>0
            assert int(recovery.sql('SELECT COUNT(*) FROM machines WHERE revoked_at IS NULL',database))==0
            credentials=json.loads((tmp/'security/app/bao.json').read_text())
            login=json.loads(context['bao'](['write','-format=json','auth/approle/login','-'],credentials))
            entry=recovery.sql("SELECT id FROM entries WHERE state='active' LIMIT 1",database).decode().strip()
            context['bao'](['kv','get','-format=json','-mount=secret','entries/'+entry],token=login['auth']['client_token'])
            try:recovery.restore(sys.argv[1])
            except RuntimeError:pass
            else:raise AssertionError('Nonempty target was not refused')
            print('PASS: full empty-target restore, scoped backend credentials, restored SQL, revoked machine access, nonempty-target refusal.')
        finally:
            for name in created:subprocess.run(['docker','rm','-f',name],capture_output=True)
            if recovery:recovery.sql('DROP DATABASE IF EXISTS `'+database+'`')

if __name__=='__main__':main()
