"""Import one approved SQL dump only into an EMPTY isolated test schema."""
import argparse,configparser,hashlib,json,os,re,subprocess
from pathlib import Path
ROOT=Path('/srv/dltk-v3-test');REPO=ROOT/'repository';SHARED=ROOT/'shared'
def compose(*args,stdin=None):
 return subprocess.run(['docker','compose','--project-name','dltk-v3-test','-f',str(REPO/'compose.yaml'),*args],cwd=REPO,stdin=stdin,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,check=True)
def sql(statement):
 return compose('exec','-T','db','mysql','--defaults-extra-file=/run/dltk/admin.cnf','--batch','--skip-column-names','-e',statement).stdout.decode().strip()
def main():
 p=argparse.ArgumentParser();p.add_argument('--file',required=True,type=Path);p.add_argument('--sha256',required=True);a=p.parse_args()
 if not (ROOT/'.dltk-test-project').is_file():raise SystemExit('Missing project ownership marker')
 digest=hashlib.sha256()
 with a.file.open('rb') as source:
  for block in iter(lambda:source.read(1024*1024),b''):digest.update(block)
 if not re.fullmatch('[0-9a-f]{64}',a.sha256) or digest.hexdigest()!=a.sha256:raise SystemExit('SQL checksum mismatch')
 cfg=configparser.ConfigParser(interpolation=None);cfg.read(SHARED/'runtime.ini');schema=cfg['workspace']['db_name'].strip('\"\'');user=cfg['workspace']['db_user'].strip('\"\'')
 if schema!='dltk_v3_test' or not re.fullmatch('[a-zA-Z0-9_]{1,32}',user):raise SystemExit('Unexpected isolated schema/user')
 count=int(sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='dltk_v3_test'"))
 if count:raise SystemExit('Database is not empty: import refused; existing data preserved')
 with a.file.open('r',encoding='utf-8') as source:
  for line in source:
   if re.match(r'(?i)^\s*(?:USE\s|(?:CREATE|DROP|ALTER)\s+DATABASE|(?:CREATE|ALTER|DROP)\s+USER|GRANT\s|REVOKE\s)',line):raise SystemExit('Dump contains schema/account routing, refused')
 with a.file.open('rb') as source:compose('exec','-T','db','mysql','--defaults-extra-file=/run/dltk/admin.cnf','--database=dltk_v3_test',stdin=source)
 tables=sql("SELECT table_name FROM information_schema.tables WHERE table_schema='dltk_v3_test'").splitlines()
 if not tables or any(not re.fullmatch('[a-z_]+',t) for t in tables):raise SystemExit('Unexpected imported schema')
 statements=[f"REVOKE ALL PRIVILEGES ON `dltk_v3_test`.* FROM '{user}'@'%'"]
 for table in tables:
  privileges='SELECT,INSERT' if table in ('change_history','outbox_events') else 'SELECT,INSERT,UPDATE,DELETE'
  statements.append(f"GRANT {privileges} ON `dltk_v3_test`.`{table}` TO '{user}'@'%'")
 sql(';'.join(statements))
 print(json.dumps({'status':'imported','tables':len(tables),'sha256':digest.hexdigest(),'appendOnlyAuditGrant':True}))
if __name__=='__main__':
 try:main()
 except subprocess.CalledProcessError:raise SystemExit('Isolated import failed; inspect private database state before retrying')
