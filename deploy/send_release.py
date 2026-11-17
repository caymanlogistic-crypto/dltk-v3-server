"""GitHub runner: send a checked archive through the restricted deployment key."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import urllib.request


def main():
    commit = os.environ['GITHUB_SHA']
    if not re.fullmatch(r'[0-9a-f]{40}', commit):
        raise RuntimeError('Invalid commit identity')
    archive = Path('artifacts') / ('dltk-v3-' + commit + '.tar.gz')
    payload = archive.read_bytes()
    if not 1 <= len(payload) <= 20 * 1024 * 1024:
        raise RuntimeError('Archive exceeds deployment limit')
    checksum = hashlib.sha256(payload).hexdigest()
    expected = archive.with_suffix('').with_suffix('.sha256').read_text().split()[0]
    if checksum != expected:
        raise RuntimeError('Artifact checksum mismatch')
    run_number = int(os.environ['GITHUB_RUN_NUMBER'])
    if run_number < 1:
        raise RuntimeError('Invalid workflow sequence')
    envelope = json.dumps({'commit': commit, 'sha256': checksum, 'size': len(payload),
                           'runNumber': run_number}).encode() + b'\n'
    with tempfile.TemporaryDirectory(prefix='dltk-deploy-') as folder:
        private = Path(folder) / 'id_ed25519'
        hosts = Path(folder) / 'known_hosts'
        private.write_text(os.environ['DLTK_DEPLOY_SSH_KEY'].strip() + '\n')
        hosts.write_text(os.environ['DLTK_DEPLOY_KNOWN_HOSTS'].strip() + '\n')
        private.chmod(0o600)
        hosts.chmod(0o600)
        result = subprocess.run([
            'ssh', '-T', '-i', str(private), '-o', 'BatchMode=yes',
            '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile=' + str(hosts), '-o', 'ConnectTimeout=15',
            '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=2',
            'dltk-v3-deploy@89.208.213.14', 'deploy',
        ], input=envelope + payload, capture_output=True, timeout=360)
    if result.returncode:
        # Do not print arbitrary remote stderr or credential material.
        try:
            status = json.loads(result.stdout)
        except (ValueError, UnicodeError):
            status = {'status': 'failed', 'message': 'Restricted SSH deployment failed'}
        print(json.dumps(status))
        raise RuntimeError('Deployment receiver rejected release')
    status = json.loads(result.stdout)
    if status.get('commit') != commit or status.get('status') not in ('deployed', 'unchanged'):
        raise RuntimeError('Unexpected deployment receipt')
    request = urllib.request.Request(
        'https://89.208.213.14/dltk-v3/assets/deployment-version.json',
        headers={'Cache-Control': 'no-cache'},
    )
    with urllib.request.urlopen(request, timeout=20) as response:
        published = json.load(response)
    if published.get('commit') != commit:
        raise RuntimeError('Public release does not match GitHub commit')
    print(json.dumps({'status': status['status'], 'commit': commit,
                      'url': 'https://89.208.213.14/dltk-v3/'}))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'status': 'failed', 'error': type(error).__name__,
                          'message': 'Automatic deployment did not complete; inspect server receipt.'}))
        raise SystemExit(1)
