"""Prepare NEW isolated runtime files, never print secrets or reset existing config."""
import argparse,configparser,json,os,secrets
from pathlib import Path
from urllib.parse import urlsplit
ROOT=Path('/srv/dltk-v3-test')
def main():
 if os.name!='posix' or os.geteuid()!=0:raise SystemExit('Run on Linux as the designated server operator')
 p=argparse.ArgumentParser();p.add_argument('--app-url',required=True);a=p.parse_args()
 url=urlsplit(a.app_url)
 if url.scheme not in ('http','https') or not url.netloc or url.username or url.password or url.path not in ('','/') or url.query or url.fragment:raise SystemExit('Use one absolute root HTTP(S) URL without credentials')
 origin=url.scheme+'://'+url.netloc
 if ROOT.exists() and not (ROOT/'.dltk-test-project').exists() and any(ROOT.iterdir()):raise SystemExit('Refusing nonempty unowned project root')
 ROOT.mkdir(parents=True,exist_ok=True);(ROOT/'.dltk-test-project').write_text('dltk-v3-test\n');os.chmod(ROOT,0o750)
 shared=ROOT/'shared';shared.mkdir(exist_ok=True);os.chmod(shared,0o750)
 for name in ('mysql','tls','private','deployments'):(shared/name).mkdir(exist_ok=True)
 os.chmod(shared/'private',0o700)
 if os.geteuid()==0:os.chown(shared/'private',33,33)
 cfg=shared/'runtime.ini';env=shared/'compose.env';admin=shared/'db-admin.cnf'
 if any(f.exists() for f in (cfg,env,admin)):
  if not all(f.exists() for f in (cfg,env,admin)):raise SystemExit('Partial existing configuration: review manually, not overwritten')
  print(json.dumps({'status':'existing_config_preserved','root':str(ROOT)}));return
 password=secrets.token_urlsafe(36);root_password=secrets.token_urlsafe(48)
 text='[workspace]\nAPP_URL='+origin+'\nWS_URL='+('wss' if url.scheme=='https' else 'ws')+'://'+url.netloc+'/ws\nDB_HOST=db\nDB_PORT=3306\nDB_NAME=dltk_v3_test\nDB_USER=dltk_app\nDB_PASSWORD='+password+'\nPRIVATE_DIR=/var/lib/dltk-private\n'
 cfg.write_text(text,encoding='utf-8');os.chmod(cfg,0o640)
 if os.geteuid()==0:os.chown(cfg,0,33)
 env.write_text('MYSQL_DATABASE=dltk_v3_test\nMYSQL_USER=dltk_app\nMYSQL_PASSWORD='+password+'\nMYSQL_ROOT_PASSWORD='+root_password+'\n',encoding='utf-8');os.chmod(env,0o600)
 admin.write_text('[client]\nuser=root\npassword='+root_password+'\n',encoding='utf-8');os.chmod(admin,0o600)
 print(json.dumps({'status':'created','root':str(ROOT),'appUrl':origin,'secretsPrinted':False}))
if __name__=='__main__':main()
