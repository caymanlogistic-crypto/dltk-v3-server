import hashlib
from pathlib import Path
import re
import unittest


APP = Path(__file__).resolve().parents[1] / 'app' / 'local-admin'
ASSETS = APP / 'public' / 'assets'


class VitrineStyleContract(unittest.TestCase):
    def test_one_approved_stylesheet(self):
        stylesheet = ASSETS / 'vitrine.css'
        self.assertEqual({stylesheet}, set((APP / 'public').rglob('*.css')))
        css = stylesheet.read_text(encoding='utf-8')
        self.assertNotRegex(css, r'(?i)@import\b')
        expected = (APP / 'vitrine-approved.sha256').read_text(encoding='utf-8').strip()
        self.assertEqual(
            expected, hashlib.sha256(css.encode('utf-8')).hexdigest(),
            'Изменение согласованного CSS требует явного согласия владельца.',
        )

    def test_views_use_only_the_shared_stylesheet(self):
        entry_points = {'login.php', 'vitrine.php', 'workspace.php'}
        for view in (APP / 'views').rglob('*.php'):
            with self.subTest(view=view.name):
                source = view.read_text(encoding='utf-8')
                links = re.findall(r'<link\s+rel=["\x27]stylesheet["\x27][^\n]*', source, re.I)
                self.assertEqual(len(links), int(view.name in entry_points))
                for link in links:
                    self.assertIn('/assets/vitrine.css', link)
                self.assertNotRegex(source, r'(?i)<style[\s>]')
                if view.name == 'vitrine-rows.php':
                    source = source.replace("' style=\"' . $escape($cellStyle) . '\"'", '')
                self.assertNotRegex(source, r'(?i)\sstyle\s*=')

    def test_scripts_pass_custom_property_values_only(self):
        for script in ASSETS.rglob('*.js'):
            with self.subTest(script=script.name):
                source = script.read_text(encoding='utf-8')
                self.assertNotRegex(source, r'(?i)<style[\s>]|createElement\(\s*["\x27]style["\x27]')
                self.assertNotRegex(source, r'\.cssText\b|\.insertRule\(|\.adoptedStyleSheets\b|\.animate\(')
                self.assertNotRegex(source, r'\.style\.[\w$]+\s*=(?!=)|setAttribute\(\s*["\x27]style["\x27]')
                for name in re.findall(r'\bstyle\.setProperty\(\s*(["\x27][^"\x27]+["\x27])', source):
                    self.assertTrue(name[1:].startswith('--'), name)
