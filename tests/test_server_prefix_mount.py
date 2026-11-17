"""Routing safety for mounting the new application on an existing HTTPS vhost."""
import sys
from pathlib import Path
import unittest

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'deploy'))
from mount_shared_prefix import patch_vhost, prefix_configuration, DIRECTIVE


class PrefixMountTests(unittest.TestCase):
    SOURCE='''<VirtualHost *:80>
    ProxyPass / http://127.0.0.1:8001/
</VirtualHost>
<VirtualHost *:443>
    SSLEngine On
    Include /etc/apache2/conf-available/lk-dltk-v2-prefix.conf
    ProxyPass / http://127.0.0.1:8001/
    ProxyPassReverse / http://127.0.0.1:8001/
</VirtualHost>
'''

    def test_only_adds_include_before_https_root(self):
        result=patch_vhost(self.SOURCE)
        self.assertEqual(result.replace('    '+DIRECTIVE+'\n',''),self.SOURCE)
        self.assertGreater(result.index(DIRECTIVE),result.index('lk-dltk-v2-prefix.conf'))
        self.assertLess(result.index(DIRECTIVE),result.rindex('    ProxyPass / '))

    def test_repeated_patch_is_idempotent(self):
        result=patch_vhost(self.SOURCE)
        self.assertEqual(patch_vhost(result),result)

    def test_ambiguous_or_missing_vhost_refused(self):
        for source in [self.SOURCE.replace('*:443','*:444'),self.SOURCE+self.SOURCE]:
            with self.assertRaises(ValueError):patch_vhost(source)

    def test_include_after_root_refused(self):
        source=self.SOURCE.replace('    ProxyPassReverse /','    '+DIRECTIVE+'\n    ProxyPassReverse /')
        with self.assertRaises(ValueError):patch_vhost(source)

    def test_ws_route_precedes_nonproxy_prefix(self):
        text=prefix_configuration()
        self.assertLess(text.index('ProxyPass /dltk-v3/ws'),text.index('ProxyPass /dltk-v3/ !'))
        self.assertIn('ProxyPassMatch "^/dltk-v3$" "!"',text)
        self.assertIn('FallbackResource /dltk-v3/index.php',text)
        self.assertIn('Alias /dltk-v3/ ',text)
        self.assertNotIn('Listen ',text)
        self.assertNotIn('ProxyPass / ',text)

    def test_crlf_preserved(self):
        source=self.SOURCE.replace('\n','\r\n')
        result=patch_vhost(source)
        self.assertEqual(result.replace('    '+DIRECTIVE+'\r\n',''),source)


if __name__=='__main__':unittest.main()
