"""Deploy only /srv/dltk-v3-test from one exact Git commit. No unrelated services."""
import argparse,datetime as dt,fcntl,json,os,re,shutil,subprocess,time,urllib.request
from pathlib import Path
ROOT=Path('/srv/dltk-v3-test');REPO=ROOT/'repository';SHARED=ROOT/'shared'
def run(*args,cwd=None,capture=False):
 return subprocess.run(list(args),cwd=cwd,check=True,stdout=subprocess.PIPE if capture else None,text=True)
def compose(tls,*args,capture=False):
 command=['docker','compose','--project-name','dltk-v3-test','-f',str(REPO/'compose.yaml')]
 if tls:command+=['-f',str(REPO/'deploy/compose.tls.yaml')]
 return run(*command,*args,cwd=REPO,capture=capture)
def preflight():
 if os.name!='posix' or os.geteuid()!=0:raise SystemExit('Run on Linux as the designated server operator')
 if not (ROOT/'.dltk-test-project').is_file():raise SystemExit('Prepare the isolated owned root first; see docs/DEPLOYMENT.md')
 for binary in ('git','docker'):
  if not shutil.which(binary):raise SystemExit(binary+' missing; no packages were installed')
 run('docker','compose','version');run('docker','info','--format','{{.ServerVersion}}')
 memory={}
 for line in Path('/proc/meminfo').read_text().splitlines():
  k,v=line.split(':',1)
  if k in ('MemAvailable','MemTotal'):memory[k]=int(v.strip().split()[0])//1024
 print(json.dumps({'cpuCount':os.cpu_count(),'memoryMiB':memory,'root':str(ROOT)}))
 if memory.get('MemAvailable',0)<1800:raise SystemExit('Less than 1800 MiB currently free: review coexistence resources before deployment')
 for name in ('runtime.ini','compose.env','db-admin.cnf'):
  if not (SHARED/name).is_file():raise SystemExit('Private runtime configuration missing')
def main():
 p=argparse.ArgumentParser();p.add_argument('--repository',required=True);p.add_argument('--revision',required=True);p.add_argument('--tls',action='store_true');p.add_argument('--initial-data',type=Path);p.add_argument('--sha256');p.add_argument('--preflight-only',action='store_true');a=p.parse_args()
 if not re.fullmatch('[0-9a-f]{40}',a.revision):raise SystemExit('Use one exact full Git commit SHA')
 if not re.fullmatch(r'(?:https://github\.com/|git@github\.com:)[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?',a.repository):raise SystemExit('Use a credential-free GitHub repository URL')
 preflight()
 if a.preflight_only:return
 lock=(ROOT/'.deploy.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
 if a.tls and not all((SHARED/'tls'/name).is_file() for name in ('fullchain.pem','privkey.pem')):raise SystemExit('Trusted TLS certificate/key not installed in the isolated shared/tls directory')
 previous=None
 if not REPO.exists():run('git','clone','--no-checkout',a.repository,str(REPO))
 elif not (REPO/'.git').is_dir():raise SystemExit('Repository path already belongs to another tree')
 else:
  if run('git','status','--porcelain',cwd=REPO,capture=True).stdout.strip():raise SystemExit('Repository contains uncommitted files; not overwritten')
  previous=run('git','rev-parse','HEAD',cwd=REPO,capture=True).stdout.strip()
 remote=run('git','remote','get-url','origin',cwd=REPO,capture=True).stdout.strip()
 if remote.removesuffix('.git')!=a.repository.removesuffix('.git'):raise SystemExit('Configured origin does not match requested repository')
 run('git','fetch','origin',cwd=REPO)
 run('git','cat-file','-e',a.revision+'^{commit}',cwd=REPO)
 run('git','checkout','--detach',a.revision,cwd=REPO)
 compose(a.tls,'config','--quiet')
 compose(a.tls,'build','php','realtime')
 compose(a.tls,'up','-d','db')
 for attempt in range(60):
  result=subprocess.run(['docker','compose','--project-name','dltk-v3-test','-f',str(REPO/'compose.yaml'),'exec','-T','db','mysql','--defaults-extra-file=/run/dltk/admin.cnf','--batch','--skip-column-names','-e','SELECT 1'],cwd=REPO,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  if result.returncode==0:break
  time.sleep(2)
 else:raise SystemExit('Dedicated MySQL did not become ready; no unrelated service was changed')
 if a.initial_data:
  if not a.sha256:raise SystemExit('Initial SQL data requires --sha256')
  run('python3',str(REPO/'deploy/import_database.py'),'--file',str(a.initial_data),'--sha256',a.sha256)
 marker=compose(False,'exec','-T','db','mysql','--defaults-extra-file=/run/dltk/admin.cnf','--batch','--skip-column-names','--database=dltk_v3_test','-e',"SELECT JSON_UNQUOTE(value) FROM workspace_meta WHERE `key`='marker'",capture=True).stdout.strip()
 if marker!='dltk-workspace-v1':raise SystemExit('Working data is not initialized; app was not started')
 compose(a.tls,'up','-d','--remove-orphans')
 import configparser
 cfg=configparser.ConfigParser(interpolation=None);cfg.read(SHARED/'runtime.ini');url=cfg['workspace']['app_url'].strip('\"\'').rstrip('/')
 opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
 for attempt in range(30):
  try:
   with opener.open(url+'/api/health',timeout=3) as response:
    if response.status==200 and json.load(response).get('marker')=='dltk-v3-server-v1':break
  except Exception:pass
  time.sleep(1)
 else:raise SystemExit('Application readiness/TLS check failed; project remains isolated, inspect its own logs before retrying')
 receipt={'utc':dt.datetime.now(dt.timezone.utc).isoformat(),'revision':a.revision,'previousRevision':previous,'url':url,'tls':a.tls,'isolatedRoot':str(ROOT),'project':'dltk-v3-test','result':'ready'}
 path=SHARED/'deployments'/('deploy-'+dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')+'.json');path.write_text(json.dumps(receipt,indent=2),encoding='utf-8');print(json.dumps(receipt))
if __name__=='__main__':main()
