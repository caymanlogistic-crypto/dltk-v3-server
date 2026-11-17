"""Offline protocol/archive safety checks; never touch SSH or server services."""
import hashlib
import gzip
import io
import json
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'deploy'))
import release_receiver as receiver


def archive_bytes(extra=()):
    output=io.BytesIO()
    with tarfile.open(fileobj=output,mode='w:gz') as archive:
        for name in receiver.REQUIRED_FILES:
            item=tarfile.TarInfo(name);data=b'example\n';item.size=len(data)
            archive.addfile(item,io.BytesIO(data))
        for item,data in extra:archive.addfile(item,io.BytesIO(data) if data is not None else None)
    return output.getvalue()


class ProtocolTests(unittest.TestCase):
    def header(self,data=b'archive'):
        return {'commit':'a'*40,'sha256':hashlib.sha256(data).hexdigest(),'size':len(data),'runNumber':42}

    def test_exact_header(self):
        expected=self.header()
        self.assertEqual(receiver.parse_header(json.dumps(expected).encode()+b'\n'),expected)

    def test_invalid_fields_sizes_and_hex(self):
        for mutation in [{'size':True},{'size':0},{'size':receiver.MAX_ARCHIVE+1},{'commit':'../bad'},{'sha256':'x'*64},{'extra':'bad'},{'runNumber':True},{'runNumber':0},{'runNumber':-1}]:
            with self.subTest(mutation=mutation),self.assertRaises(receiver.ReleaseError):
                receiver.parse_header(json.dumps({**self.header(),**mutation}).encode()+b'\n')

    def test_duplicate_fields_no_newline_and_oversize(self):
        for data in [b'{"size":1,"size":2}\n',b'{}',b'x'*1025+b'\n',b'\xff\n']:
            with self.assertRaises(receiver.ReleaseError):receiver.parse_header(data)

    def test_receive_exact_bytes_and_checksum(self):
        target=io.BytesIO();receiver.receive(io.BytesIO(b'archive'),target,self.header())
        self.assertEqual(target.read(),b'archive')

    def test_receive_rejects_truncation_extra_and_wrong_checksum(self):
        for data in [b'archiv',b'archiveX',b'ARCHIVE']:
            with self.assertRaises(receiver.ReleaseError):receiver.receive(io.BytesIO(data),io.BytesIO(),self.header())

    def test_stale_and_conflicting_workflow_runs_rejected(self):
        latest={'runNumber':42,'commit':'a'*40}
        receiver.validate_sequence(self.header(),latest)
        receiver.validate_sequence({**self.header(),'runNumber':43},latest)
        for value in [{**self.header(),'runNumber':41},{**self.header(),'commit':'b'*40}]:
            with self.assertRaises(receiver.ReleaseError):receiver.validate_sequence(value,latest)

    def test_expansion_bounds_metadata_before_tar_parser(self):
        data=gzip.compress(b'x'*1024)
        with patch.object(receiver,'MAX_TAR_STREAM',10),self.assertRaises(receiver.ReleaseError):
            receiver.expand_bounded(io.BytesIO(data),io.BytesIO())
        output=io.BytesIO();receiver.expand_bounded(io.BytesIO(data),output)
        self.assertEqual(output.read(),b'x'*1024)


class ArchiveTests(unittest.TestCase):
    def validate(self,data):
        with tarfile.open(fileobj=io.BytesIO(data),mode='r:gz') as archive:
            return receiver.validate_archive(archive)

    def test_valid_archive_extracts_with_safe_modes(self):
        data=archive_bytes()
        with tempfile.TemporaryDirectory() as directory,tarfile.open(fileobj=io.BytesIO(data),mode='r:gz') as archive:
            root=Path(directory)
            receiver.extract_archive(archive,receiver.validate_archive(archive),root)
            for filename in receiver.REQUIRED_FILES:self.assertEqual((root/filename).read_bytes(),b'example\n')

    def test_path_traversal_absolute_control_and_private_refused(self):
        names=['../evil','app/../../evil','/etc/cron.d/evil','app\\evil','app/line\nname','private/data','app/private/data','.git/config','config/runtime.ini']
        for name in names:
            with self.subTest(name=name),self.assertRaises(receiver.ReleaseError):
                self.validate(archive_bytes([(tarfile.TarInfo(name),b'')]))

    def test_links_and_devices_refused(self):
        for kind in [tarfile.SYMTYPE,tarfile.LNKTYPE,tarfile.CHRTYPE,tarfile.BLKTYPE,tarfile.FIFOTYPE]:
            item=tarfile.TarInfo('link');item.type=kind;item.linkname='/etc/passwd'
            with self.subTest(kind=kind),self.assertRaises(receiver.ReleaseError):
                self.validate(archive_bytes([(item,None)]))

    def test_duplicate_file_refused(self):
        item=tarfile.TarInfo(receiver.REQUIRED_FILES[0])
        with self.assertRaises(receiver.ReleaseError):self.validate(archive_bytes([(item,b'')]))

    def test_missing_required_files_refused(self):
        output=io.BytesIO()
        with tarfile.open(fileobj=output,mode='w:gz'):pass
        with self.assertRaises(receiver.ReleaseError):self.validate(output.getvalue())

    def test_member_limit_checked_during_iteration(self):
        with patch.object(receiver,'MAX_MEMBERS',2),self.assertRaises(receiver.ReleaseError):
            self.validate(archive_bytes())

    def test_expanded_size_limit(self):
        with patch.object(receiver,'MAX_EXTRACT',1),self.assertRaises(receiver.ReleaseError):
            self.validate(archive_bytes())

    def test_file_directory_collision(self):
        item=tarfile.TarInfo('app')
        with self.assertRaises(receiver.ReleaseError):self.validate(archive_bytes([(item,b'')]))

    def test_root_dot_directory_allowed(self):
        item=tarfile.TarInfo('.');item.type=tarfile.DIRTYPE
        self.assertEqual(len(self.validate(archive_bytes([(item,None)]))),len(receiver.REQUIRED_FILES))

    def test_dependency_change_requires_manual_setup(self):
        with tempfile.TemporaryDirectory() as directory:
            old=Path(directory)/'old';new=Path(directory)/'new';old.mkdir();new.mkdir()
            (old/'requirements.txt').write_text('old');(new/'requirements.txt').write_text('new')
            with self.assertRaisesRegex(receiver.ReleaseError,'manual preparation'):receiver.verify_release(new,old)


class RollbackTests(unittest.TestCase):
    def test_failed_refresh_restores_previous_current(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);releases=root/'releases';releases.mkdir()
            old=releases/('b'*40);old.mkdir()
            private=root/'shared/private';private.mkdir(parents=True)
            class Current:
                def is_symlink(self):return True
                def resolve(self,strict=True):return old
            class Root:
                def __truediv__(self,part):return Current() if part=='current' else root/part
            artifact=archive_bytes()
            header={'commit':'a'*40,'sha256':hashlib.sha256(artifact).hexdigest(),'size':len(artifact),'runNumber':42}
            with patch.object(receiver,'ROOT',Root()),patch.object(receiver,'check_owned'),patch.object(receiver,'verify_release'),patch.object(receiver,'activate') as activate,patch.object(receiver,'refresh',side_effect=[receiver.ReleaseError('Service action failed'),None]) as refresh,patch.object(receiver,'health') as health:
                with self.assertRaisesRegex(receiver.ReleaseError,'previous release retained'):
                    receiver.install_verified(io.BytesIO(artifact),header,private)
                self.assertEqual(activate.call_args_list[0].args,(releases/('a'*40),))
                self.assertEqual(activate.call_args_list[-1].args,(old,))
                self.assertEqual(refresh.call_count,2)
                health.assert_called_once_with()
            events=list((private/'autodeploy').glob('*.json'))
            self.assertEqual(len(events),1)
            self.assertEqual(json.loads(events[0].read_text())['status'],'rolled-back')
            self.assertTrue(old.is_dir())

    def test_deadline_during_health_is_not_swallowed(self):
        with patch.object(receiver.urllib.request,'urlopen',side_effect=receiver.ReleaseError('Deployment deadline exceeded')),patch.object(receiver.time,'sleep') as sleep:
            with self.assertRaisesRegex(receiver.ReleaseError,'deadline'):receiver.health()
            sleep.assert_not_called()


if __name__=='__main__':unittest.main()
