"""Mount the existing DLTK deployment below /dltk-v3 on shared HTTPS443.

Does not install packages, change ports/firewall, import data or alter PHP config.
The one reviewed vhost receives an IncludeOptional before its root ProxyPass.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

ROOT=Path('/srv/dltk-v3-test')
INCLUDE=Path('/etc/apache2/conf-available/dltk-v3-prefix.conf')
DIRECTIVE='IncludeOptional /etc/apache2/conf-available/dltk-v3-prefix.conf'


def prefix_configuration():
    public=str(ROOT/'current/app/local-admin/public')
    return f'''# Owned by /srv/dltk-v3-test; included inside the existing HTTPS vhost.
# Specific WS route precedes the exclusion and existing catch-all proxy.
ProxyPass /dltk-v3/ws ws://127.0.0.1:33486 retry=0 timeout=75
ProxyPassReverse /dltk-v3/ws ws://127.0.0.1:33486
ProxyPassMatch "^/dltk-v3$" "!"
ProxyPass /dltk-v3/ !
RedirectMatch 302 ^/dltk-v3$ /dltk-v3/
Alias /dltk-v3/ "{public}/"
<Directory "{public}">
    Options -Indexes -ExecCGI
    AllowOverride None
    Require all granted
    DirectoryIndex index.php
    FallbackResource /dltk-v3/index.php
    <FilesMatch "\\.php$">
        SetHandler "proxy:fcgi://127.0.0.1:33487"
    </FilesMatch>
</Directory>
'''


def patch_vhost(source):
    """Refuse ambiguous routing instead of guessing a vhost or proxy location."""
    blocks=list(re.finditer(r'(?ims)^\s*<VirtualHost\s+([^>]+)>.*?^\s*</VirtualHost\s*>',source))
    candidates=[block for block in blocks if re.search(r'(?:^|\s)\S*:443(?:\s|$)',block.group(1))]
    if len(candidates)!=1:raise ValueError('Expected exactly one HTTPS443 VirtualHost in the reviewed file')
    block=candidates[0];text=block.group()
    roots=list(re.finditer(r'(?m)^(?P<indent>[ \t]*)ProxyPass[ \t]+(?:"/"|/)[ \t]+[^!\s][^\r\n]*',text))
    if len(roots)!=1:raise ValueError('Expected exactly one root ProxyPass in HTTPS443 vhost')
    occurrences=list(re.finditer(re.escape(DIRECTIVE),source))
    if occurrences:
        if len(occurrences)!=1 or not(block.start()<=occurrences[0].start()<block.start()+roots[0].start()):
            raise ValueError('Existing prefix include is ambiguous or follows the root proxy')
        return source
    if re.search(r'(?mi)^\s*[^#\r\n]*dltk-v3',text):
        raise ValueError('Existing unrecognized DLTK vhost routing; refused')
    point=block.start()+roots[0].start()
    newline='\r\n' if '\r\n' in source else '\n'
    return source[:point]+roots[0].group('indent')+DIRECTIVE+newline+source[point:]


def sha(data):return hashlib.sha256(data).hexdigest()


def atomic_write(path,data,mode=0o644):
    fd,name=tempfile.mkstemp(prefix='.dltk-v3-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as target:
            target.write(data);target.flush();os.fsync(target.fileno())
        os.chmod(name,mode);os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)


def command(args):
    result=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=45)
    if result.returncode:raise RuntimeError('Command failed: '+args[0]+'; configuration retained in private backup')


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--vhost-file',required=True,type=Path)
    parser.add_argument('--expected-vhost-sha256',required=True)
    parser.add_argument('--reload',action='store_true',help='Apply a graceful Apache reload after successful configtest')
    a=parser.parse_args()
    if os.geteuid()!=0:raise RuntimeError('Root required')
    marker=ROOT/'.dltk-test-project'
    if not marker.is_file() or marker.read_text().strip()!='dltk-v3-test-shared-v1':
        raise RuntimeError('Expected owned shared-stack deployment')
    if not (ROOT/'current/app/local-admin/public/index.php').is_file():raise RuntimeError('App front controller absent')
    vhost=a.vhost_file.resolve(strict=True)
    allowed_roots=[Path('/etc/apache2/sites-available'),Path('/etc/apache2/sites-enabled')]
    if not any(root in vhost.parents for root in allowed_roots) or not vhost.is_file():
        raise RuntimeError('Vhost must be a regular sites-available/sites-enabled configuration')
    original=vhost.read_bytes()
    if not re.fullmatch('[0-9a-f]{64}',a.expected_vhost_sha256) or sha(original)!=a.expected_vhost_sha256:
        raise RuntimeError('Reviewed vhost checksum mismatch')
    desired=prefix_configuration().encode()
    if INCLUDE.is_symlink() or (INCLUDE.exists() and INCLUDE.read_bytes()!=desired):
        raise RuntimeError('Unrecognized existing prefix include; refused')
    patched=patch_vhost(original.decode()).encode()
    backup=ROOT/'shared/private'/('prefix-mount-'+dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ'))
    backup.mkdir(mode=0o700)
    shutil.copy2(vhost,backup/'vhost.original.conf')
    include_existed=INCLUDE.exists()
    if include_existed:shutil.copy2(INCLUDE,backup/'prefix.original.conf')
    changed=patched!=original or not include_existed
    receipt={'vhost':str(vhost),'originalSha256':sha(original),'patchedSha256':sha(patched),'include':str(INCLUDE),'prefix':'/dltk-v3','changed':changed,'reloaded':False}
    try:
        atomic_write(INCLUDE,desired)
        atomic_write(vhost,patched,vhost.stat().st_mode & 0o777)
        command(['apache2ctl','configtest'])
        if a.reload:
            command(['systemctl','reload','apache2'])
            receipt['reloaded']=True
    except Exception:
        atomic_write(vhost,original,(backup/'vhost.original.conf').stat().st_mode & 0o777)
        if include_existed:atomic_write(INCLUDE,(backup/'prefix.original.conf').read_bytes())
        else:INCLUDE.unlink(missing_ok=True)
        # A failed reload may have partially signalled Apache; revalidate restored
        # configuration and explicitly attempt its graceful reload as recovery.
        command(['apache2ctl','configtest'])
        if a.reload:command(['systemctl','reload','apache2'])
        raise
    atomic_write(backup/'receipt.json',json.dumps(receipt,indent=2).encode(),0o600)
    print(json.dumps({'status':'mounted' if a.reload else 'config-tested','prefix':'/dltk-v3','changed':changed,'backup':str(backup)}))


if __name__=='__main__':
    try:main()
    except Exception as error:
        print(json.dumps({'status':'failed','error':type(error).__name__,'message':str(error)[:240]}))
        raise SystemExit(1)
