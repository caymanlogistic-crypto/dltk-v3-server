"""Root-owned forced-command release broker; receives no shell commands.

stdin: one JSON line (commit, sha256, size, runNumber), then exactly size gzip-tar bytes,
then EOF. Install this reviewed file outside releases; repository copies do not
self-install or gain privilege. Runtime configuration and the database are read
only boundaries for this broker.
"""
import datetime as dt
import hashlib
import gzip
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.request

ROOT=Path('/srv/dltk-v3-test')
MAX_ARCHIVE=20*1024*1024
MAX_EXTRACT=50*1024*1024
MAX_MEMBERS=2000
MAX_TAR_STREAM=MAX_EXTRACT+MAX_MEMBERS*2048
PUBLIC_URL='https://89.208.213.14/dltk-v3'
REQUIRED_FILES=('app/local-admin/public/index.php','app/local-admin/public/assets/workspace-paths.js','tools/workspace_realtime.py','requirements.txt')


class ReleaseError(Exception):
    """Only fixed, nonsecret messages may cross the SSH boundary."""


def parse_header(line):
    if len(line)>1024 or not line.endswith(b'\n'):
        raise ReleaseError('Invalid protocol header')
    def unique(pairs):
        result={}
        for key,value in pairs:
            if key in result:raise ReleaseError('Duplicate protocol field')
            result[key]=value
        return result
    try:header=json.loads(line.decode('utf-8'),object_pairs_hook=unique)
    except (UnicodeError,ValueError):raise ReleaseError('Invalid protocol JSON') from None
    if not isinstance(header,dict) or set(header)!= {'commit','sha256','size','runNumber'}:
        raise ReleaseError('Invalid protocol fields')
    if not isinstance(header['commit'],str) or not re.fullmatch('[0-9a-f]{40}',header['commit']):
        raise ReleaseError('Invalid commit')
    if not isinstance(header['sha256'],str) or not re.fullmatch('[0-9a-f]{64}',header['sha256']):
        raise ReleaseError('Invalid archive checksum')
    if type(header['size']) is not int or not 0<header['size']<=MAX_ARCHIVE:
        raise ReleaseError('Invalid archive size')
    if type(header['runNumber']) is not int or not 0<header['runNumber']<2**63:
        raise ReleaseError('Invalid workflow run number')
    return header


def receive(stream,target,header):
    remaining=header['size'];digest=hashlib.sha256()
    while remaining:
        chunk=stream.read(min(remaining,1024*1024))
        if not chunk:raise ReleaseError('Truncated archive')
        target.write(chunk);digest.update(chunk);remaining-=len(chunk)
    if stream.read(1):raise ReleaseError('Extra protocol bytes')
    if digest.hexdigest()!=header['sha256']:raise ReleaseError('Archive checksum mismatch')
    target.flush();target.seek(0)


def expand_bounded(artifact,output):
    """Bound even gzip-expanded PAX/long-name metadata before tarfile parses it."""
    total=0
    try:
        with gzip.GzipFile(fileobj=artifact,mode='rb') as source:
            while True:
                block=source.read(1024*1024)
                if not block:break
                total+=len(block)
                if total>MAX_TAR_STREAM:raise ReleaseError('Expanded tar stream too large')
                output.write(block)
    except (OSError,EOFError):raise ReleaseError('Invalid compressed archive') from None
    output.flush();output.seek(0)


def validate_sequence(header,latest):
    previous_run=latest.get('runNumber')
    if type(previous_run) is not int or previous_run<=0:raise ReleaseError('Invalid deployment sequence receipt')
    if header['runNumber']<previous_run:raise ReleaseError('Stale workflow run refused')
    if header['runNumber']==previous_run and latest.get('commit')!=header['commit']:
        raise ReleaseError('Workflow run already belongs to another commit')


def member_path(member):
    raw=member.name
    if '\\' in raw or '\x00' in raw or raw.startswith('/') or '..' in raw.split('/'):
        raise ReleaseError('Unsafe archive path')
    if any(ord(char)<32 for char in raw):raise ReleaseError('Unsafe archive filename')
    path=PurePosixPath(raw)
    if path==PurePosixPath('.'):
        if member.isdir():return None
        raise ReleaseError('Invalid archive root')
    if not (member.isdir() or member.isfile()):raise ReleaseError('Archive links or special files refused')
    if any(part in ('.git','private') for part in path.parts) or str(path)=='config/runtime.ini':
        raise ReleaseError('Private/runtime material refused')
    return path


def validate_archive(archive):
    entries=[];seen={};total=0
    # Iteration is bounded rather than getmembers(), so a tiny archive cannot
    # allocate an unbounded list of metadata entries before limits are checked.
    for index,member in enumerate(archive):
        if index>=MAX_MEMBERS:raise ReleaseError('Too many archive members')
        path=member_path(member)
        if path is None:continue
        if path in seen:raise ReleaseError('Duplicate archive path')
        if member.size<0:raise ReleaseError('Invalid member size')
        total+=member.size
        if total>MAX_EXTRACT:raise ReleaseError('Expanded archive too large')
        seen[path]=member.isdir();entries.append((member,path))
    for path,is_dir in seen.items():
        if any(parent in seen and not seen[parent] for parent in path.parents):
            raise ReleaseError('Archive file/directory collision')
    if any(PurePosixPath(name) not in seen or seen[PurePosixPath(name)] for name in REQUIRED_FILES):
        raise ReleaseError('Required application files absent')
    return entries


def extract_archive(archive,entries,target):
    """Manual extraction never preserves owners, links, mode bits or devices."""
    for member,path in entries:
        destination=target.joinpath(*path.parts)
        destination.parent.mkdir(mode=0o755,parents=True,exist_ok=True)
        if member.isdir():destination.mkdir(mode=0o755,exist_ok=True);continue
        with archive.extractfile(member) as source, destination.open('xb') as output:
            while True:
                block=source.read(1024*1024)
                if not block:break
                output.write(block)
        destination.chmod(0o644)
    for path in target.rglob('*'):
        if path.is_dir():path.chmod(0o755)


def atomic_json(path,value):
    atomic_bytes(path,(json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode(),0o600)


def atomic_bytes(path,data,mode):
    fd,name=tempfile.mkstemp(prefix='.receiver-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as output:
            output.write(data);output.flush();os.fsync(output.fileno())
        os.chmod(name,mode);os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)


def check_owned(path,directory=False):
    info=path.lstat()
    if info.st_uid!=0 or info.st_mode & 0o022 or stat.S_ISLNK(info.st_mode):
        raise ReleaseError('Deployment ownership invalid')
    if directory and not stat.S_ISDIR(info.st_mode):raise ReleaseError('Deployment directory invalid')


def command(args,timeout=60):
    try:result=subprocess.run(args,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=timeout)
    except (OSError,subprocess.TimeoutExpired):raise ReleaseError('Release validation or service action failed') from None
    if result.returncode:raise ReleaseError('Release validation or service action failed')


def verify_release(release,current):
    if (release/'requirements.txt').read_bytes()!=(current/'requirements.txt').read_bytes():
        raise ReleaseError('Dependency changes require manual preparation')
    for filename in REQUIRED_FILES:
        if not (release/filename).is_file():raise ReleaseError('Required application file absent')
    for path in release.rglob('*.php'):
        command(['/usr/bin/php','-n','-l',str(path)],timeout=10)


def activate(release):
    candidate=ROOT/('.current-'+str(os.getpid()))
    try:
        candidate.symlink_to(release,target_is_directory=True)
        os.replace(candidate,ROOT/'current')
    finally:
        candidate.unlink(missing_ok=True)


def refresh():
    command(['systemctl','reload','php8.4-fpm'])
    command(['systemctl','restart','dltk-v3-realtime.service'])


def health(expected_commit=None):
    """Strict public TLS and actual front-controller/static mapping checks."""
    for attempt in range(10):
        try:
            with urllib.request.urlopen(PUBLIC_URL+'/api/health',timeout=5) as response:
                data=json.load(response)
            if data.get('marker')!='dltk-v3-server-v1' or data.get('database')!='ready':
                raise ValueError('Wrong application')
            if expected_commit:
                request=urllib.request.Request(PUBLIC_URL+'/assets/deployment-version.json?t='+str(time.time_ns()),headers={'Cache-Control':'no-cache'})
                with urllib.request.urlopen(request,timeout=5) as response:
                    version=json.load(response)
                if version!={'commit':expected_commit}:raise ValueError('Wrong deployed commit')
            for path in ['/login','/assets/workspace-paths.js']:
                with urllib.request.urlopen(PUBLIC_URL+path,timeout=5) as response:
                    if response.status!=200:raise ValueError('Unhealthy route')
                    body=response.read(1024*1024)
                    if not body:raise ValueError('Empty route')
                    if path.endswith('.js') and b'text/html' in response.headers.get('Content-Type','').encode():
                        raise ValueError('Static route returned HTML')
            return
        except ReleaseError:raise
        except Exception:
            if attempt<9:time.sleep(1)
    raise ReleaseError('Public HTTPS readiness failed')


def deployment(stream):
    import fcntl
    if os.geteuid()!=0:raise ReleaseError('Root broker required')
    check_owned(ROOT,directory=True)
    check_owned(ROOT/'shared',directory=True)
    marker=ROOT/'.dltk-test-project';check_owned(marker)
    if marker.read_text().strip()!='dltk-v3-test-shared-v1':raise ReleaseError('Wrong deployment marker')
    private=ROOT/'shared/private';check_owned(private,directory=True)
    lock=private/'release-receiver.lock'
    fd=os.open(lock,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'r+b') as locked:
        if os.fstat(locked.fileno()).st_uid!=0:raise ReleaseError('Invalid deployment lock')
        try:fcntl.flock(locked,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:raise ReleaseError('Another deployment is active') from None
        header=parse_header(stream.readline(1025))
        with tempfile.TemporaryFile(dir=private) as artifact:
            receive(stream,artifact,header)
            return install_verified(artifact,header,private)


def install_verified(artifact,header,private):
    current_link=ROOT/'current'
    if not current_link.is_symlink():raise ReleaseError('Current release pointer absent')
    current=current_link.resolve(strict=True)
    releases=ROOT/'releases';check_owned(releases,directory=True)
    if current.parent!=releases:raise ReleaseError('Current pointer escapes releases')
    check_owned(current,directory=True)
    releases.mkdir(mode=0o755,exist_ok=True)
    final=releases/header['commit']
    records=private/'autodeploy'
    records.mkdir(mode=0o700,exist_ok=True);check_owned(records,directory=True)
    latest_path=records/'latest.json'
    if latest_path.exists() or latest_path.is_symlink():
        check_owned(latest_path)
        latest=json.loads(latest_path.read_text())
        validate_sequence(header,latest)
    existing_receipt=records/(header['commit']+'.json')
    if final.exists() or final.is_symlink():
        if not existing_receipt.is_file():raise ReleaseError('Unverified existing release refused')
        check_owned(existing_receipt)
        recorded=json.loads(existing_receipt.read_text())
        if recorded.get('sha256')!=header['sha256'] or recorded.get('status')!='success' or current!=final:
            raise ReleaseError('Existing release does not match current verified deployment')
        health(header['commit'])
        updated={**recorded,'runNumber':header['runNumber']}
        atomic_json(latest_path,updated)
        atomic_json(private/'deployment-latest.json',updated)
        return {'status':'unchanged','commit':header['commit'],'sha256':header['sha256']}
    # Partial staging is retained on failure for inspection; never recursively
    # delete or overwrite releases, runtime configuration, users or history.
    staging=Path(tempfile.mkdtemp(prefix='.incoming-'+header['commit']+'-',dir=releases))
    staging.chmod(0o755)
    receipt={'commit':header['commit'],'sha256':header['sha256'],'size':header['size'],'runNumber':header['runNumber'],'previousCommit':current.name,'utc':dt.datetime.now(dt.timezone.utc).isoformat(),'status':'failed-before-activation'}
    timestamp=dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    event_path=records/(timestamp+'.json')
    switched=False
    try:
        try:
            with tempfile.TemporaryFile(dir=private) as expanded:
                expand_bounded(artifact,expanded)
                with tarfile.open(fileobj=expanded,mode='r:') as archive:
                    entries=validate_archive(archive)
                    extract_archive(archive,entries,staging)
        except (tarfile.TarError,EOFError,OSError):raise ReleaseError('Invalid release archive') from None
        verify_release(staging,current)
        atomic_bytes(staging/'DEPLOYED_COMMIT',(header['commit']+'\n').encode(),0o644)
        atomic_bytes(staging/'app/local-admin/public/assets/deployment-version.json',(json.dumps({'commit':header['commit']})+'\n').encode(),0o644)
        os.rename(staging,final)
        activate(final);switched=True
        refresh();health(header['commit'])
        receipt['status']='success'
        atomic_json(event_path,receipt)
        atomic_json(existing_receipt,receipt)
        atomic_json(private/'deployment-latest.json',receipt)
        atomic_json(latest_path,receipt)
        return {'status':'deployed','commit':header['commit'],'sha256':header['sha256']}
    except Exception as error:
        if switched:
            receipt['status']='rolled-back'
            try:activate(current);refresh();health()
            except Exception:receipt['status']='rollback-needs-inspection'
        receipt['error']=str(error) if isinstance(error,ReleaseError) else 'Release operation failed'
        atomic_json(event_path,receipt)
        if receipt['status']=='rollback-needs-inspection':raise ReleaseError('Release failed; rollback requires operator inspection') from None
        raise ReleaseError('Release failed; previous release retained') from None


if __name__=='__main__':
    # Ignore any SSH_ORIGINAL_COMMAND and all client-controlled command text.
    def deadline(signum,frame):raise ReleaseError('Deployment deadline exceeded')
    signal.signal(signal.SIGALRM,deadline)
    signal.alarm(300)
    try:print(json.dumps(deployment(sys.stdin.buffer)),flush=True)
    except Exception as error:
        message=str(error) if isinstance(error,ReleaseError) else 'Release receiver failed'
        print(json.dumps({'status':'failed','message':message}),flush=True)
        raise SystemExit(1)
    finally:signal.alarm(0)
